<?php
// calendly_api.php - Separate API endpoint for Calendly data
// ========================= CONFIG =========================
$CALENDLY_TOKEN = getenv('CALENDLY_TOKEN') ?: 'PASTE_YOUR_TOKEN_HERE';
$ORG_URI        = getenv('CALENDLY_ORG_URI') ?: 'https://api.calendly.com/organizations/PASTE_ORG_ID';
$DAYS_AHEAD     = 365;
$TIMEZONE_OUT   = 'Europe/Vienna';
$INCLUDE_INVITEE_STATUS = true;
// ==========================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

function json_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function api_get($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 25,
    ]);
    $res = curl_exec($ch);
    if ($res === false) { 
        return [null, 'Netzwerk-Fehler: '.curl_error($ch)]; 
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code < 200 || $code >= 300) { 
        $error_data = json_decode($res, true);
        $error_msg = $error_data['message'] ?? "HTTP $code";
        return [null, $error_msg]; 
    }
    
    $json = json_decode($res, true);
    if ($json === null) { 
        return [null, 'Ungültige API-Antwort']; 
    }
    return [$json, null];
}

function iso_to_local($iso, $tzOut) {
    try {
        $dt = new DateTimeImmutable(str_replace('Z','+00:00',$iso));
        return $dt->setTimezone(new DateTimeZone($tzOut));
    } catch(Throwable $e) {
        return null;
    }
}

// Validate input
$email = isset($_GET['email']) ? trim($_GET['email']) : '';
if ($email === '') {
    json_error('Fehlender Parameter: email');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Ungültige E-Mail-Adresse');
}
if (!$CALENDLY_TOKEN || !$ORG_URI) {
    json_error('Server-Konfigurationsfehler', 500);
}

try {
    // Calculate time range
    $todayUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(0,0,0);
    $toUtc    = $todayUtc->modify("+{$DAYS_AHEAD} days")->setTime(23,59,59);
    
    $min_start = $todayUtc->format('Y-m-d\TH:i:s\Z');
    $max_start = $toUtc->format('Y-m-d\TH:i:s\Z');
    
    // Fetch events from Calendly
    $base = 'https://api.calendly.com';
    $count = 50;
    $page_token = '';
    $events = [];
    
    do {
        $url = $base.'/scheduled_events?'
             . http_build_query([
                'organization'   => $ORG_URI,
                'status'         => 'active',
                'invitee_email'  => $email,
                'min_start_time' => $min_start,
                'max_start_time' => $max_start,
                'count'          => $count,
                'page_token'     => $page_token ?: null
              ]);
        $url = preg_replace('/(&page_token=)$/','',$url);
        
        list($data, $err) = api_get($url, $CALENDLY_TOKEN);
        if ($err) {
            json_error("Calendly API-Fehler: $err", 502);
        }
        
        $collection = $data['collection'] ?? [];
        foreach ($collection as $ev) {
            $events[] = $ev;
        }
        $page_token = $data['pagination']['next_page_token'] ?? '';
        
    } while ($page_token);
    
    // Sort events by start time
    usort($events, fn($a,$b)=>strcmp($a['start_time']??'', $b['start_time']??''));
    
    // Load invitee status if requested
    $inviteeStatusMap = [];
    if ($INCLUDE_INVITEE_STATUS && $events) {
        foreach ($events as $ev) {
            $uuid = basename($ev['uri']);
            $u = "$base/scheduled_events/$uuid/invitees";
            list($inv, $err2) = api_get($u, $CALENDLY_TOKEN);
            if ($err2 || !isset($inv['collection'])) { 
                continue; 
            }
            foreach ($inv['collection'] as $i) {
                $em = strtolower($i['email'] ?? '');
                if ($em === strtolower($email)) {
                    $invitee_uuid = basename($i['uri'] ?? '');
                    $inviteeStatusMap[$uuid] = [
                        'name'          => $i['name'] ?? '',
                        'email'         => $i['email'] ?? '',
                        'status'        => $i['status'] ?? '',
                        'is_reschedule' => $i['is_reschedule'] ?? ($i['rescheduled'] ?? false),
                        'invitee_uuid'  => $invitee_uuid,
                        'cancel_url'    => $invitee_uuid ? "https://calendly.com/cancellations/{$invitee_uuid}" : null,
                        'reschedule_url' => $invitee_uuid ? "https://calendly.com/reschedulings/{$invitee_uuid}" : null,
                    ];
                    break;
                }
            }
        }
    }
    
    // Format events for frontend
    $formatted_events = [];
    foreach ($events as $ev) {
        $uuid = basename($ev['uri']);
        $inv = $inviteeStatusMap[$uuid] ?? null;
        
        // Construct cancel/reschedule URLs based on Calendly patterns
        $cancel_url = null;
        $reschedule_url = null;
        
        if ($inv && isset($inv['cancel_url'])) {
            $cancel_url = $inv['cancel_url'];
        } else {
            // Fallback: construct URL based on event UUID
            $cancel_url = "https://calendly.com/cancellations/{$uuid}";
        }
        
        if ($inv && isset($inv['reschedule_url'])) {
            $reschedule_url = $inv['reschedule_url'];
        } else {
            // Fallback: construct URL based on event UUID  
            $reschedule_url = "https://calendly.com/reschedulings/{$uuid}";
        }
        
        $formatted_events[] = [
            'id' => $uuid,
            'name' => $ev['name'] ?? '',
            'start_time' => $ev['start_time'] ?? '',
            'end_time' => $ev['end_time'] ?? '',
            'created_at' => $ev['created_at'] ?? '',
            'status' => $ev['status'] ?? 'active',
            'location' => $ev['location'] ?? [],
            'cancel_url' => $cancel_url,
            'reschedule_url' => $reschedule_url,
            'invitee_name' => $inv['name'] ?? '',
            'invitee_status' => $inv['status'] ?? '',
            'is_reschedule' => $inv['is_reschedule'] ?? false,
        ];
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'events' => $formatted_events,
        'total' => count($formatted_events),
        'email' => $email,
        'date_range' => [
            'from' => $todayUtc->format('Y-m-d'),
            'to' => $toUtc->format('Y-m-d')
        ]
    ]);
    
} catch (Throwable $e) {
    error_log("Calendly API Error: " . $e->getMessage());
    json_error('Interner Server-Fehler', 500);
}