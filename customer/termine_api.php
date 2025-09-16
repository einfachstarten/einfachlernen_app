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

function api_get($url, $token, array $context = []) {
    $request_debug = array_merge([
        'url' => $url,
        'token_prefix' => substr((string) $token, 0, 8) . '...',
        'timestamp' => date('Y-m-d H:i:s'),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ], $context);
    error_log("CALENDLY REQUEST: " . json_encode($request_debug));

    $ch = curl_init($url);
    $curl_verbose = fopen('php://temp', 'w+');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_VERBOSE => true,
        CURLOPT_STDERR => $curl_verbose
    ]);

    $start_time = microtime(true);
    $res = curl_exec($ch);
    $end_time = microtime(true);
    $duration = round(($end_time - $start_time) * 1000, 2);

    $curl_info = curl_getinfo($ch);
    $code = $curl_info['http_code'] ?? 0;
    $curl_error = $res === false ? curl_error($ch) : null;
    curl_close($ch);

    $verbose_output = '';
    if (is_resource($curl_verbose)) {
        rewind($curl_verbose);
        $verbose_output = stream_get_contents($curl_verbose) ?: '';
        fclose($curl_verbose);
    }

    if ($res === false) {
        if ($verbose_output) {
            $sanitized_verbose = preg_replace('/(Authorization: Bearer )([A-Za-z0-9._-]+)/i', '$1***redacted***', $verbose_output);
            error_log("CALENDLY CURL VERBOSE ERROR: " . $sanitized_verbose);
        }
        error_log("CALENDLY CURL ERROR: $curl_error");
        return [null, 'Netzwerk-Fehler: ' . $curl_error];
    }

    $response_debug = array_merge([
        'http_code' => $code,
        'duration_ms' => $duration,
        'response_size' => strlen($res),
        'content_type' => $curl_info['content_type'] ?? 'unknown'
    ], $context);

    if ($code < 200 || $code >= 300) {
        if ($verbose_output) {
            $sanitized_verbose = preg_replace('/(Authorization: Bearer )([A-Za-z0-9._-]+)/i', '$1***redacted***', $verbose_output);
            error_log("CALENDLY CURL VERBOSE RESPONSE: " . $sanitized_verbose);
        }

        $error_data = json_decode($res, true);

        $full_error_details = array_merge([
            'request_url' => $url,
            'http_code' => $code,
            'duration_ms' => $duration,
            'response_body' => $res,
            'parsed_error' => $error_data,
            'calendly_message' => $error_data['message'] ?? null,
            'calendly_details' => $error_data['details'] ?? null,
            'calendly_errors' => $error_data['errors'] ?? null,
            'curl_info' => $curl_info,
            'timestamp' => date('Y-m-d H:i:s')
        ], $context);

        error_log("CALENDLY ERROR FULL DETAILS: " . json_encode($full_error_details, JSON_PRETTY_PRINT));

        $user_error = $error_data['message'] ?? "HTTP $code";
        return [null, "Calendly API-Fehler: $user_error"];
    }

    error_log("CALENDLY SUCCESS: " . json_encode($response_debug));

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
    $events = [];
    $page_token = '';
    $max_pages = 10; // Safety limit: max 500 events (50 * 10)
    $page_count = 0;

    $request_params = [
        'organization' => $ORG_URI,
        'status' => 'active',
        'invitee_email' => $email,
        'min_start_time' => $min_start,
        'max_start_time' => $max_start,
        'count' => 50
    ];

    $start_date = new DateTimeImmutable($request_params['min_start_time']);
    $end_date = new DateTimeImmutable($request_params['max_start_time']);
    $days_span = $start_date->diff($end_date)->days;

    if ($days_span > 400) {
        error_log("CALENDLY WARNING: Large date span requested - Customer: $email, Days: $days_span");
    }

    error_log("CALENDLY PARAMS: " . json_encode($request_params));

    do {
        $page_count++;

        $params = $request_params;

        if ($page_token) {
            $params['page_token'] = $page_token;
        }

        $log_params = $params;
        $log_params['page'] = $page_count;
        error_log("CALENDLY PARAMS: " . json_encode($log_params));

        $url = $base . '/scheduled_events?' . http_build_query($params);

        // Debug log for problematic pagination
        if ($page_count > 3) {
            error_log("Calendly Pagination Warning - Customer: $email, Page: $page_count, Token: " . substr($page_token, 0, 20) . "...");
        }

        list($data, $err) = api_get($url, $CALENDLY_TOKEN, [
            'endpoint' => 'scheduled_events',
            'page_token' => $page_token,
            'customer_email' => $email,
            'customer_session_id' => session_id()
        ]);
        if ($err) {
            error_log("Calendly Pagination Error - Customer: $email, Page: $page_count, Error: $err");
            break; // Stop pagination on error, return what we have
        }

        $collection = $data['collection'] ?? [];
        foreach ($collection as $ev) {
            $events[] = $ev;
        }

        $page_token = $data['pagination']['next_page_token'] ?? '';

        // Safety check
        if ($page_count >= $max_pages) {
            error_log("Calendly Pagination Limit Reached - Customer: $email, Events: " . count($events));
            break;
        }

    } while ($page_token && $page_count < $max_pages);
    
    // Sort events by start time
    usort($events, fn($a,$b)=>strcmp($a['start_time']??'', $b['start_time']??''));
    
    // Load invitee status if requested
    $inviteeStatusMap = [];
    if ($INCLUDE_INVITEE_STATUS && $events) {
        foreach ($events as $ev) {
            $uuid = basename($ev['uri']);
            $u = "$base/scheduled_events/$uuid/invitees";
            list($inv, $err2) = api_get($u, $CALENDLY_TOKEN, [
                'endpoint' => 'scheduled_event_invitees',
                'event_uuid' => $uuid,
                'customer_email' => $email,
                'customer_session_id' => session_id()
            ]);
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
