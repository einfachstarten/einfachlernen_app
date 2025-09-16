<?php
session_start();
if (empty($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

function getPDO() {
    $config = require __DIR__ . '/config.php';
    return new PDO(
        "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

$customer_id = $_GET['customer_id'] ?? '';
if (!$customer_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Customer ID required']);
    exit;
}

$pdo = getPDO();
$stmt = $pdo->prepare("SELECT email, first_name, last_name FROM customers WHERE id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    http_response_code(404);
    echo json_encode(['error' => 'Customer not found']);
    exit;
}

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
        $curl_error = curl_error($ch);
        curl_close($ch);
        return [null, 'Network error: ' . $curl_error];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        $error_data = json_decode($res, true);

        // Enhanced error logging for admin
        $detailed_error = [
            'http_code' => $code,
            'response_body' => $res,
            'calendly_message' => $error_data['message'] ?? null,
            'calendly_details' => $error_data['details'] ?? null,
            'request_url' => $url,
            'admin_user' => $_SESSION['admin'] ?? 'unknown'
        ];
        error_log("Admin Calendly API Error: " . json_encode($detailed_error));

        $user_error = $error_data['message'] ?? "HTTP $code";
        return [null, "Calendly API Error: $user_error"];
    }

    $json = json_decode($res, true);
    return [$json, null];
}

try {
    // Get bookings for last 12 months and next 6 months
    $twelveMonthsAgo = (new DateTimeImmutable('-12 months', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $sixMonthsAhead = (new DateTimeImmutable('+6 months', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

    // Pagination through all events
    $all_events = [];
    $page_token = null;
    $max_pages = 10; // Safety limit: max 1000 events
    $page_count = 0;

    do {
        $params = [
            'organization' => $ORG_URI,
            'invitee_email' => $customer['email'],
            'min_start_time' => $twelveMonthsAgo,
            'max_start_time' => $sixMonthsAhead,
            'count' => 100
        ];

        if ($page_token) {
            $params['page_token'] = $page_token;
        }

        $url = 'https://api.calendly.com/scheduled_events?' . http_build_query($params);

        list($data, $err) = api_get($url, $CALENDLY_TOKEN);
        if ($err) {
            throw new Exception($err);
        }

        $events = $data['collection'] ?? [];
        $all_events = array_merge($all_events, $events);

        $page_token = $data['pagination']['next_page_token'] ?? null;
        $page_count++;

    } while ($page_token && $page_count < $max_pages);

    // Format events for admin view
    $formatted_events = [];
    foreach ($all_events as $event) {
        $start_dt = new DateTimeImmutable($event['start_time']);
        $end_dt = new DateTimeImmutable($event['end_time']);
        $event_uuid = basename($event['uri']);

        // Get invitee details for actions
        $invitee_url = "https://api.calendly.com/scheduled_events/{$event_uuid}/invitees";
        list($invitee_data, $invitee_err) = api_get($invitee_url, $CALENDLY_TOKEN);

        $invitee_uuid = null;
        $can_cancel = false;
        $can_reschedule = false;

        if (!$invitee_err && isset($invitee_data['collection'][0])) {
            $invitee = $invitee_data['collection'][0];
            $invitee_uuid = basename($invitee['uri']);
            $can_cancel = $start_dt > new DateTimeImmutable('+2 hours'); // Min 2h Vorlauf
            $can_reschedule = $start_dt > new DateTimeImmutable('+4 hours'); // Min 4h Vorlauf
        }

        $formatted_events[] = [
            'id' => $event_uuid,
            'name' => $event['name'],
            'start_time' => $start_dt->setTimezone(new DateTimeZone('Europe/Vienna'))->format('d.m.Y H:i'),
            'end_time' => $end_dt->setTimezone(new DateTimeZone('Europe/Vienna'))->format('H:i'),
            'status' => $event['status'],
            'location' => $event['location']['location'] ?? 'Online',
            'created_at' => (new DateTimeImmutable($event['created_at']))->format('d.m.Y'),
            'is_future' => $start_dt > new DateTimeImmutable(),
            'calendly_url' => $event['uri'],
            'start_timestamp' => $start_dt->getTimestamp(),
            // Quick Action Data
            'invitee_uuid' => $invitee_uuid,
            'can_cancel' => $can_cancel,
            'can_reschedule' => $can_reschedule,
            'cancel_url' => $invitee_uuid ? "https://calendly.com/cancellations/{$invitee_uuid}" : null,
            'reschedule_url' => $invitee_uuid ? "https://calendly.com/reschedulings/{$invitee_uuid}" : null,
            'admin_note' => '',
        ];
    }

    // Sort by start time (newest first)
    usort($formatted_events, fn($a, $b) => $b['start_timestamp'] <=> $a['start_timestamp']);

    echo json_encode([
        'success' => true,
        'customer' => $customer,
        'events' => $formatted_events,
        'total_events' => count($formatted_events),
        'pages_fetched' => $page_count,
        'has_more_potential' => $page_count >= $max_pages
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

