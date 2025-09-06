<?php
session_start();
if (empty($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Calendly Configuration
$CALENDLY_TOKEN = getenv('CALENDLY_TOKEN') ?: 'PASTE_YOUR_TOKEN_HERE';
$ORG_URI = getenv('CALENDLY_ORG_URI') ?: 'https://api.calendly.com/organizations/PASTE_ORG_ID';

header('Content-Type: application/json; charset=utf-8');

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
        return [null, 'Network error: '.curl_error($ch)];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code < 200 || $code >= 300) {
        return [null, "HTTP $code"];
    }
    
    $json = json_decode($res, true);
    return [$json, null];
}

try {
    // Get today's events (Vienna timezone)
    $today = new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna'));
    $startOfDay = $today->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'));
    $endOfDay = $today->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'));
    
    $url = 'https://api.calendly.com/scheduled_events?' . http_build_query([
        'organization' => $ORG_URI,
        'status' => 'active',
        'min_start_time' => $startOfDay->format('Y-m-d\TH:i:s\Z'),
        'max_start_time' => $endOfDay->format('Y-m-d\TH:i:s\Z'),
        'count' => 50
    ]);
    
    list($data, $err) = api_get($url, $CALENDLY_TOKEN);
    if ($err) {
        throw new Exception("Calendly API Error: $err");
    }
    
    $events = $data['collection'] ?? [];
    
    // Get invitee details for each event
    $formatted_events = [];
    foreach ($events as $event) {
        $event_uuid = basename($event['uri']);
        
        // Get invitee info
        $invitee_url = "https://api.calendly.com/scheduled_events/{$event_uuid}/invitees";
        list($invitee_data, $invitee_err) = api_get($invitee_url, $CALENDLY_TOKEN);
        
        $invitee_name = 'Unbekannt';
        $invitee_email = '';
        if (!$invitee_err && isset($invitee_data['collection'][0])) {
            $invitee = $invitee_data['collection'][0];
            $invitee_name = $invitee['name'] ?? 'Unbekannt';
            $invitee_email = $invitee['email'] ?? '';
        }
        
        $start_dt = new DateTimeImmutable($event['start_time']);
        $end_dt = new DateTimeImmutable($event['end_time']);
        
        $formatted_events[] = [
            'id' => $event_uuid,
            'name' => $event['name'],
            'start_time' => $start_dt->setTimezone(new DateTimeZone('Europe/Vienna'))->format('H:i'),
            'end_time' => $end_dt->setTimezone(new DateTimeZone('Europe/Vienna'))->format('H:i'),
            'duration' => $start_dt->diff($end_dt)->format('%h:%I'),
            'invitee_name' => $invitee_name,
            'invitee_email' => $invitee_email,
            'status' => $event['status'],
            'location' => $event['location']['location'] ?? 'Online',
            'calendly_url' => $event['uri'],
            'is_soon' => $start_dt->getTimestamp() < (time() + 3600), // Within 1 hour
            'is_now' => $start_dt->getTimestamp() < time() && $end_dt->getTimestamp() > time()
        ];
    }
    
    // Sort by start time
    usort($formatted_events, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
    
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna'));
    
    echo json_encode([
        'success' => true,
        'today' => $today->format('d.m.Y'),
        'current_time' => $now->format('H:i'),
        'events' => $formatted_events,
        'total_events' => count($formatted_events),
        'upcoming_today' => count(array_filter($formatted_events, fn($e) => strtotime($e['start_time']) > strtotime($now->format('H:i'))))
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
