<?php
require __DIR__.'/auth.php';

// Ensure user is authenticated
$customer = require_customer_login();

// Session timeout check
if(isset($_SESSION['customer_last_activity']) && (time() - $_SESSION['customer_last_activity'] > 14400)){
    destroy_customer_session();
    http_response_code(401);
    echo json_encode(['error' => 'Session expired']);
    exit;
}

// Update activity timestamp
$_SESSION['customer_last_activity'] = time();

// Get email from authenticated session (SECURE)
$email = $customer['email'];

// Include existing calendly_api.php logic but with session authentication
$CALENDLY_TOKEN = getenv('CALENDLY_TOKEN') ?: 'PASTE_YOUR_TOKEN_HERE';
$ORG_URI = getenv('CALENDLY_ORG_URI') ?: 'https://api.calendly.com/organizations/PASTE_ORG_ID';
$DAYS_AHEAD = 365;
$TIMEZONE_OUT = 'Europe/Vienna';
$INCLUDE_INVITEE_STATUS = true;

// Enhanced configuration validation
function validate_calendly_config($token, $org_uri, $email) {
    $errors = [];

    // Token validation
    if (!$token || $token === 'PASTE_YOUR_TOKEN_HERE') {
        $errors[] = 'Calendly Token nicht konfiguriert';
    }

    // Organization URI validation
    if (!$org_uri || $org_uri === 'https://api.calendly.com/organizations/PASTE_ORG_ID') {
        $errors[] = 'Calendly Organization URI nicht konfiguriert';
    } elseif (!filter_var($org_uri, FILTER_VALIDATE_URL)) {
        $errors[] = 'Calendly Organization URI ist keine gültige URL';
    } elseif (!preg_match('/^https:\/\/api\.calendly\.com\/organizations\/[a-zA-Z0-9_-]+$/', $org_uri)) {
        $errors[] = 'Calendly Organization URI hat falsches Format';
    }

    // Email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ungültige E-Mail-Adresse: ' . $email;
    }

    return $errors;
}

header('Content-Type: application/json; charset=utf-8');

function json_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function api_get($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        $curl_error = curl_error($ch);
        curl_close($ch);
        return [null, 'Netzwerk-Fehler: ' . $curl_error];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        $error_data = json_decode($res, true);

        // Enhanced error logging
        $detailed_error = [
            'http_code' => $code,
            'response_body' => $res,
            'calendly_message' => $error_data['message'] ?? null,
            'calendly_details' => $error_data['details'] ?? null,
            'request_url' => $url
        ];
        error_log("Calendly API Error Details: " . json_encode($detailed_error));

        // Return user-friendly error with Calendly's specific message
        $user_error = $error_data['message'] ?? "HTTP $code";
        return [null, "Calendly API-Fehler: $user_error"];
    }

    $json = json_decode($res, true);
    if ($json === null) {
        return [null, 'Ungültige API-Antwort'];
    }
    return [$json, null];
}

// Validate configuration before API calls
$config_errors = validate_calendly_config($CALENDLY_TOKEN, $ORG_URI, $email);
if (!empty($config_errors)) {
    error_log("Calendly Config Errors for user $email: " . implode(', ', $config_errors));
    json_error('Server-Konfigurationsfehler: ' . implode(', ', $config_errors), 500);
}

try {
    // Calculate time range
    $todayUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(0,0,0);
    $toUtc = $todayUtc->modify("+{$DAYS_AHEAD} days")->setTime(23,59,59);
    
    $min_start = $todayUtc->format('Y-m-d\TH:i:s\Z');
    $max_start = $toUtc->format('Y-m-d\TH:i:s\Z');
    
    // Debug logging for problematic requests
    $debug_info = [
        'customer_email' => $email,
        'org_uri' => $ORG_URI,
        'min_start_time' => $min_start,
        'max_start_time' => $max_start,
        'token_prefix' => substr((string) $CALENDLY_TOKEN, 0, 8) . '...',
        'timezone' => $TIMEZONE_OUT
    ];
    error_log("Calendly API Request Debug Info: " . json_encode($debug_info));

    // Fetch events from Calendly
    $base = 'https://api.calendly.com';
    $count = 50;
    $page_token = '';
    $events = [];
    
    do {
        $url = $base.'/scheduled_events?' . http_build_query([
            'organization' => $ORG_URI,
            'status' => 'active',
            'invitee_email' => $email, // SECURE: Email from session
            'min_start_time' => $min_start,
            'max_start_time' => $max_start,
            'count' => $count,
            'page_token' => $page_token ?: null
        ]);
        $url = preg_replace('/(&page_token=)$/','',$url);
        
        list($data, $err) = api_get($url, $CALENDLY_TOKEN);
        if ($err) {
            json_error($err, 502);
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
                        'name' => $i['name'] ?? '',
                        'email' => $i['email'] ?? '',
                        'status' => $i['status'] ?? '',
                        'is_reschedule' => $i['is_reschedule'] ?? ($i['rescheduled'] ?? false),
                        'invitee_uuid' => $invitee_uuid,
                        'cancel_url' => $invitee_uuid ? "https://calendly.com/cancellations/{$invitee_uuid}" : null,
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
        
        $formatted_events[] = [
            'id' => $uuid,
            'name' => $ev['name'] ?? '',
            'start_time' => $ev['start_time'] ?? '',
            'end_time' => $ev['end_time'] ?? '',
            'created_at' => $ev['created_at'] ?? '',
            'status' => $ev['status'] ?? 'active',
            'location' => $ev['location'] ?? [],
            'cancel_url' => $inv['cancel_url'] ?? null,
            'reschedule_url' => $inv['reschedule_url'] ?? null,
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
        'authenticated' => true
    ]);
    
} catch (Throwable $e) {
    error_log("Secure Calendly API Error: " . $e->getMessage());
    json_error('Interner Server-Fehler', 500);
}
?>
