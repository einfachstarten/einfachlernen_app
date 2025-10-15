<?php
require __DIR__.'/auth.php';

// Ensure user is authenticated
$customer = require_customer_login();

require_once __DIR__ . '/../admin/ActivityLogger.php';
$pdo = getPDO();
$logger = new ActivityLogger($pdo);

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
$customer_email = $customer['email'];

// Calendly Configuration
$CALENDLY_TOKEN = getenv('CALENDLY_TOKEN') ?: 'PASTE_YOUR_TOKEN_HERE';
$ORG_URI = getenv('CALENDLY_ORG_URI') ?: 'https://api.calendly.com/organizations/PASTE_ORG_ID';

// Service Mapping
$services = [
    "lerntraining" => [
        "uri" => "https://api.calendly.com/event_types/ADE2NXSJ5RCEO3YV",
        "url" => "https://calendly.com/einfachlernen/lerntraining",
        "dur" => 50,
        "name" => "Lerntraining"
    ],
    "neurofeedback-20" => [
        "uri" => "https://api.calendly.com/event_types/ec567e31-b98b-4ed4-9beb-b01c32649b9b",
        "url" => "https://calendly.com/einfachlernen/neurofeedback-training-20-min",
        "dur" => 20,
        "name" => "Neurofeedback 20 Min"
    ],
    "neurofeedback-40" => [
        "uri" => "https://api.calendly.com/event_types/2ad6fc6d-7a65-42dd-ba6e-0135945ebb9a",
        "url" => "https://calendly.com/einfachlernen/neurofeedback-training-40-minuten",
        "dur" => 40,
        "name" => "Neurofeedback 40 Min"
    ],
];

header('Content-Type: application/json; charset=utf-8');

function json_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function call_api($url, $token, $method = 'GET', $data = null) {
    $opts = [
        "http" => [
            "method" => $method,
            "header" => "Authorization: Bearer $token\r\n",
            "timeout" => 15
        ]
    ];
    
    if ($method === 'POST' && $data) {
        $opts["http"]["header"] .= "Content-Type: application/json\r\n";
        $opts["http"]["content"] = json_encode($data);
    }
    
    $ctx = stream_context_create($opts);
    $resp = file_get_contents($url, false, $ctx);
    return json_decode($resp, true);
}

function build_calendly_link($baseUrl, $startIso, $customer_email) {
    if (empty($baseUrl) || empty($startIso)) return '#';
    
    $month = substr($startIso, 0, 7); // YYYY-MM
    $sep = (strpos($baseUrl, '?') !== false) ? '&' : '?';
    
    // Add month and pre-fill email from session
    return $baseUrl . $sep . 'month=' . rawurlencode($month) . '&email=' . rawurlencode($customer_email);
}

function logSlotSearchError($customer_id, $service_slug, $error_type, $error_details) {
    require_once __DIR__ . '/../admin/ActivityLogger.php';
    $pdo = getPDO();
    $logger = new ActivityLogger($pdo);

    $logger->logActivity($customer_id, 'slot_search_failed', [
        'service_slug' => $service_slug,
        'failure_reason' => $error_type,
        'error_details' => $error_details,
        'api_error' => true,
        'search_type' => 'availability_check'
    ]);
}

// Validate input
$service_slug = $_GET['service'] ?? '';
$target_count = min(15, max(1, intval($_GET['count'] ?? 3)));
$current_week = intval($_GET['week'] ?? 0);
$start_from_date = isset($_GET['start_from']) ? trim($_GET['start_from']) : null;
$original_target_count = min(15, max(1, intval($_GET['original_count'] ?? $target_count)));

// Log API call
$logger->logActivity($customer['id'], 'slots_api_called', [
    'service_slug' => $service_slug,
    'target_count' => $target_count,
    'current_week' => $current_week,
    'api_endpoint' => $_SERVER['REQUEST_URI'] ?? '',
    'calendly_token_configured' => !empty($CALENDLY_TOKEN) && $CALENDLY_TOKEN !== 'PASTE_YOUR_TOKEN_HERE'
]);

if (isset($services[$service_slug])) {
    $logger->logActivity($customer['id'], 'service_viewed', [
        'service_slug' => $service_slug,
        'service_name' => $services[$service_slug]['name'],
        'service_duration' => $services[$service_slug]['dur'],
        'calendly_uri' => $services[$service_slug]['uri']
    ]);
}

if (!empty($service_slug) && isset($services[$service_slug])) {
    $logger->logActivity($customer['id'], 'availability_checked', [
        'service_slug' => $service_slug,
        'service_name' => $services[$service_slug]['name'],
        'week_offset' => $current_week,
        'slots_requested' => $target_count,
        'check_type' => 'slot_search'
    ]);
}

if (!isset($services[$service_slug])) {
    logSlotSearchError($customer['id'], $service_slug, 'unknown_service', 'Service not found');
    json_error('Unbekannter Service: ' . $service_slug);
}

if ($CALENDLY_TOKEN === 'PASTE_YOUR_TOKEN_HERE') {
    logSlotSearchError($customer['id'], $service_slug, 'api_not_configured', 'Calendly token not set');
    json_error('API not configured', 500);
}

if (!$ORG_URI) {
    logSlotSearchError($customer['id'], $service_slug, 'org_uri_missing', 'Organization URI not configured');
    json_error('Server-Konfigurationsfehler', 500);
}

try {
    $service = $services[$service_slug];
    $slots_by_date = [];
    $found_dates = [];
    $viennaTz = new DateTimeZone("Europe/Vienna");
    $utcTz = new DateTimeZone("UTC");

    $search_type = 'initial';
    $search_base = (new DateTimeImmutable("tomorrow midnight", $utcTz))->modify("+$current_week week");

    if (!empty($start_from_date)) {
        $startDateVienna = DateTimeImmutable::createFromFormat('Y-m-d', $start_from_date, $viennaTz);
        $parseErrors = DateTimeImmutable::getLastErrors();
        $hasParseErrors = !$startDateVienna;

        if (is_array($parseErrors)) {
            $hasParseErrors = $hasParseErrors || !empty($parseErrors['warning_count']) || !empty($parseErrors['error_count']);
        }

        if ($hasParseErrors) {
            json_error('Ungültiges Startdatum');
        }

        $startDateVienna = $startDateVienna->setTime(0, 0, 0);
        $todayVienna = (new DateTimeImmutable('today', $viennaTz))->setTime(0, 0, 0);

        if ($startDateVienna < $todayVienna) {
            json_error('Ungültiges Startdatum');
        }

        $search_base = $startDateVienna->setTimezone($utcTz)->modify('+1 day');
        $search_type = 'continuation';
    }

    $max_weeks = $search_type === 'continuation' ? 16 : 8;
    $api_calls = 0;
    $api_durations = [];
    $total_slots_returned = 0;
    $last_searched_date = null;
    $referenceMidnightUtc = new DateTimeImmutable("tomorrow midnight", $utcTz);

    for ($week_offset = 0; $week_offset < $max_weeks && count($found_dates) < $target_count; $week_offset++) {
        $week_start = $search_base->modify("+$week_offset week");
        $week_end = $week_start->modify("+6 days 23 hours 59 minutes 59 seconds");

        $current_searched_date = $week_end->setTimezone($viennaTz)->format('Y-m-d');
        if ($last_searched_date === null || $current_searched_date > $last_searched_date) {
            $last_searched_date = $current_searched_date;
        }

        $url = "https://api.calendly.com/event_type_available_times"
             . "?event_type=" . urlencode($service["uri"])
             . "&start_time=" . $week_start->format("Y-m-d\TH:i:s\Z")
             . "&end_time=" . $week_end->format("Y-m-d\TH:i:s\Z")
             . "&timezone=Europe/Vienna";

        $api_start = microtime(true);
        $api_response = call_api($url, $CALENDLY_TOKEN);
        $api_response_time = microtime(true) - $api_start;

        $api_calls++;
        $api_durations[] = $api_response_time;

        if (empty($api_response)) {
            logSlotSearchError($customer['id'], $service_slug, 'api_timeout', 'Calendly API timeout or connection error');
            json_error('Booking service temporarily unavailable');
        }

        $total_slots_returned += count($api_response['collection'] ?? []);

        foreach ($api_response["collection"] ?? [] as $slot) {
            if (!isset($slot["start_time"])) {
                continue;
            }

            $startIso = $slot["start_time"];
            $startDt = new DateTimeImmutable($startIso);
            $endDt = $startDt->modify("+{$service['dur']} minutes");

            $slotDateVienna = $startDt->setTimezone($viennaTz);
            $slotDate = $slotDateVienna->format("Y-m-d");

            $isExistingDate = isset($slots_by_date[$slotDate]);
            if (!$isExistingDate && count($found_dates) >= $target_count) {
                continue;
            }

            if (!$isExistingDate) {
                $slots_by_date[$slotDate] = [];
                $found_dates[] = $slotDate;
            }

            $booking_link = build_calendly_link($service["url"], $startIso, $customer_email);

            $weeks_from_now = intdiv(max(0, $startDt->getTimestamp() - $referenceMidnightUtc->getTimestamp()), 604800) + 1;

            $slots_by_date[$slotDate][] = [
                "start" => $slotDateVienna->format("D, d.m.Y H:i"),
                "end" => $endDt->setTimezone($viennaTz)->format("H:i"),
                "time_only" => $slotDateVienna->format("H:i"),
                "start_iso" => $startIso,
                "end_iso" => $endDt->format("Y-m-d\TH:i:s\Z"),
                "booking_url" => $booking_link,
                "weeks_from_now" => $weeks_from_now,
                "slot_date" => $slotDate
            ];
        }
    }

    if ($last_searched_date === null) {
        $last_searched_date = $search_base->setTimezone($viennaTz)->format('Y-m-d');
    }

    if (!empty($slots_by_date)) {
        $total_day_slots = array_map('count', $slots_by_date);
        $logger->logActivity($customer['id'], 'slots_found', [
            'service_slug' => $service_slug,
            'days_found' => count($slots_by_date),
            'slots_count' => array_sum($total_day_slots),
            'week_offset' => $current_week,
            'api_calls' => $api_calls,
            'search_type' => $search_type,
            'calendly_response_time_total' => array_sum($api_durations),
            'search_successful' => true,
            'start_from' => $start_from_date,
            'target_count' => $target_count,
            'original_target_count' => $original_target_count,
            'total_slots_returned' => $total_slots_returned,
            'searched_until' => $last_searched_date
        ]);
    } else {
        $logger->logActivity($customer['id'], 'slots_not_found', [
            'service_slug' => $service_slug,
            'week_offset' => $current_week,
            'reason' => 'no_slots_available',
            'api_response_empty' => $total_slots_returned === 0,
            'api_calls' => $api_calls,
            'search_type' => $search_type,
            'search_unsuccessful' => true,
            'start_from' => $start_from_date,
            'target_count' => $target_count,
            'original_target_count' => $original_target_count,
            'searched_until' => $last_searched_date
        ]);
    }

    ksort($slots_by_date);

    $slots = [];
    foreach ($slots_by_date as $date => $daySlots) {
        usort($daySlots, function ($a, $b) {
            return strcmp($a['time_only'], $b['time_only']);
        });

        $slots[] = [
            "date" => $date,
            "date_formatted" => (new DateTime($date))->format("D, d.m.Y"),
            "slots" => $daySlots,
            "weeks_from_now" => $daySlots[0]["weeks_from_now"]
        ];
    }

    $last_found_date = !empty($found_dates) ? max($found_dates) : null;
    if ($last_found_date === null && $last_searched_date !== null) {
        $last_found_date = $last_searched_date;
    }

    $can_search_more = $last_found_date !== null;

    echo json_encode([
        "success" => true,
        "current_week" => $current_week + 1,
        "slots" => $slots,
        "found_count" => count($slots),
        "target_count" => $target_count,
        "original_target_count" => $original_target_count,
        "service" => $service["name"],
        "customer_email" => $customer_email,
        "authenticated" => true,
        "last_found_date" => $last_found_date,
        "can_search_more" => $can_search_more,
        "search_type" => $search_type,
        "total_slots_returned" => $total_slots_returned,
        "searched_until" => $last_searched_date
    ]);

} catch (Throwable $e) {
    logSlotSearchError($customer['id'], $service_slug, 'exception', $e->getMessage());
    error_log("Secure Slots API Error: " . $e->getMessage());
    json_error('Interner Server-Fehler', 500);
}
?>
