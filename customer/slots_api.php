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

function logBookingError($customer_id, $service_slug, $error_type, $error_details) {
    require_once __DIR__ . '/../admin/ActivityLogger.php';
    $pdo = getPDO();
    $logger = new ActivityLogger($pdo);

    $logger->logActivity($customer_id, 'booking_failed', [
        'service_slug' => $service_slug,
        'failure_reason' => $error_type,
        'error_details' => $error_details,
        'api_error' => true
    ]);
}

// Validate input
$service_slug = $_GET['service'] ?? '';
$target_count = min(10, max(1, intval($_GET['count'] ?? 3)));
$current_week = intval($_GET['week'] ?? 0);

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
    $logger->logActivity($customer['id'], 'booking_initiated', [
        'service_slug' => $service_slug,
        'service_name' => $services[$service_slug]['name'],
        'week_offset' => $current_week,
        'slots_requested' => $target_count
    ]);
}

if (!isset($services[$service_slug])) {
    logBookingError($customer['id'], $service_slug, 'unknown_service', 'Service not found');
    json_error('Unbekannter Service: ' . $service_slug);
}

if ($CALENDLY_TOKEN === 'PASTE_YOUR_TOKEN_HERE') {
    logBookingError($customer['id'], $service_slug, 'api_not_configured', 'Calendly token not set');
    json_error('API not configured', 500);
}

if (!$ORG_URI) {
    logBookingError($customer['id'], $service_slug, 'org_uri_missing', 'Organization URI not configured');
    json_error('Server-Konfigurationsfehler', 500);
}

try {
    $service = $services[$service_slug];
    $slots_by_date = [];
    $found_dates = [];

    // Calculate time range for current week
    $base = new DateTimeImmutable("tomorrow midnight", new DateTimeZone("UTC"));
    $start = $base->modify("+$current_week week");
    $end = $start->modify("+6 days 23 hours 59 minutes 59 seconds");

    $url = "https://api.calendly.com/event_type_available_times"
         . "?event_type=" . urlencode($service["uri"])
         . "&start_time=" . $start->format("Y-m-d\TH:i:s\Z")
         . "&end_time=" . $end->format("Y-m-d\TH:i:s\Z")
         . "&timezone=Europe/Vienna";

    $api_start = microtime(true);
    $api_response = call_api($url, $CALENDLY_TOKEN);
    $api_response_time = microtime(true) - $api_start;

    if (empty($api_response)) {
        logBookingError($customer['id'], $service_slug, 'api_timeout', 'Calendly API timeout or connection error');
        json_error('Booking service temporarily unavailable');
    }

    if ($api_response && !empty($api_response['collection'])) {
        $logger->logActivity($customer['id'], 'booking_completed', [
            'service_slug' => $service_slug,
            'slots_found' => count($api_response['collection']),
            'api_success' => true,
            'calendly_response_time' => $api_response_time
        ]);
    } else {
        $logger->logActivity($customer['id'], 'booking_failed', [
            'service_slug' => $service_slug,
            'failure_reason' => 'no_slots_available',
            'api_response_empty' => empty($api_response),
            'error_details' => $api_response['error'] ?? 'unknown'
        ]);
    }

    foreach ($api_response["collection"] ?? [] as $slot) {
        if (!isset($slot["start_time"])) continue;
        
        $startIso = $slot["start_time"];
        $startDt = new DateTimeImmutable($startIso);
        $endDt = $startDt->modify("+{$service['dur']} minutes");
        
        // Get date in Vienna timezone for grouping
        $slotDate = $startDt->setTimezone(new DateTimeZone("Europe/Vienna"))->format("Y-m-d");
        
        // Initialize date group if not exists
        if (!isset($slots_by_date[$slotDate])) {
            $slots_by_date[$slotDate] = [];
            $found_dates[] = $slotDate;
        }
        
        // Build booking link with customer email from session
        $booking_link = build_calendly_link($service["url"], $startIso, $customer_email);

        $slots_by_date[$slotDate][] = [
            "start" => $startDt->setTimezone(new DateTimeZone("Europe/Vienna"))->format("D, d.m.Y H:i"),
            "end" => $endDt->setTimezone(new DateTimeZone("Europe/Vienna"))->format("H:i"),
            "time_only" => $startDt->setTimezone(new DateTimeZone("Europe/Vienna"))->format("H:i"),
            "start_iso" => $startIso,
            "end_iso" => $endDt->format("Y-m-d\TH:i:s\Z"),
            "booking_url" => $booking_link,
            "weeks_from_now" => $current_week + 1,
            "slot_date" => $slotDate
        ];
    }
    
    // Convert grouped data to frontend format
    $slots = [];
    foreach ($slots_by_date as $date => $daySlots) {
        $slots[] = [
            "date" => $date,
            "date_formatted" => (new DateTime($date))->format("D, d.m.Y"),
            "slots" => $daySlots,
            "weeks_from_now" => $daySlots[0]["weeks_from_now"]
        ];
    }

    // Return response
    echo json_encode([
        "success" => true,
        "current_week" => $current_week + 1,
        "week_range" => $start->setTimezone(new DateTimeZone("Europe/Vienna"))->format("d.m") . " - " . 
                       $end->setTimezone(new DateTimeZone("Europe/Vienna"))->format("d.m.Y"),
        "slots" => $slots,
        "found_count" => count($slots),
        "target_count" => $target_count,
        "service" => $service["name"],
        "customer_email" => $customer_email, // For debugging
        "authenticated" => true
    ]);
    
} catch (Throwable $e) {
    logBookingError($customer['id'], $service_slug, 'exception', $e->getMessage());
    error_log("Secure Slots API Error: " . $e->getMessage());
    json_error('Interner Server-Fehler', 500);
}
?>
