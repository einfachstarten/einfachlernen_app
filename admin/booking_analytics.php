<?php
/**
 * Enhanced Booking Analytics Dashboard
 * Comprehensive booking analytics with Calendly integration, conversion tracking,
 * beta/production comparison, and advanced business metrics
 */
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

function getPDO()
{
    $config = require __DIR__ . '/config.php';
    try {
        return new PDO(
            "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        die('Database connection failed');
    }
}

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=booking_analytics_' . date('Y-m-d') . '.csv');

    $pdo = getPDO();
    $month_year = $_GET['month'] ?? 'current';

    // Get export data
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Booking Analytics Report', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Period', $month_year]);
    fputcsv($output, []);

    // You can add export logic here based on the data
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Report Generated', 'Successfully']);

    fclose($output);
    exit;
}

// Handle API requests
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');

    $CALENDLY_TOKEN = getenv('CALENDLY_TOKEN');
    $ORG_URI = getenv('CALENDLY_ORG_URI');

    // Better validation
    if (empty($CALENDLY_TOKEN) || $CALENDLY_TOKEN === 'PASTE_YOUR_TOKEN_HERE') {
        echo json_encode(['success' => false, 'error' => 'Calendly Token nicht konfiguriert']);
        exit;
    }

    if (empty($ORG_URI) || strpos($ORG_URI, 'PASTE_ORG_ID') !== false) {
        echo json_encode(['success' => false, 'error' => 'Calendly Organization URI nicht konfiguriert']);
        exit;
    }

    function api_get($url, $token, $debug = false) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                "Content-Type: application/json"
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'EinfachLernen-BookingAnalytics/1.0'
        ]);

        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($debug) {
            error_log("Calendly API Call: $url");
            error_log("HTTP Code: $http_code");
            if ($res !== false) {
                error_log("Response: " . substr($res, 0, 500));
            }
        }

        if ($res === false) {
            return [null, 'Network error: ' . $error];
        }

        if ($http_code < 200 || $http_code >= 300) {
            $error_data = json_decode($res, true);
            $error_msg = $error_data['message'] ?? $error_data['title'] ?? "HTTP $http_code";

            // More detailed error for debugging
            if (isset($error_data['details'])) {
                $error_msg .= ' - Details: ' . json_encode($error_data['details']);
            }

            return [null, $error_msg];
        }

        $json = json_decode($res, true);
        if ($json === null) {
            return [null, 'Invalid JSON response'];
        }

        return [$json, null];
    }

    function getEventsForMonth($token, $org_uri, $month_year) {
        // Parse month_year
        if ($month_year === 'current') {
            $target_date = new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna'));
        } else {
            try {
                $target_date = DateTimeImmutable::createFromFormat('Y-m', $month_year, new DateTimeZone('Europe/Vienna'));
                if (!$target_date) {
                    throw new Exception('Invalid month format');
                }
            } catch (Exception $e) {
                throw new Exception('Invalid month format. Use YYYY-MM or "current"');
            }
        }

        // Month boundaries (Vienna timezone)
        $month_start = $target_date->modify('first day of this month')->setTime(0, 0, 0);
        $month_end = $target_date->modify('last day of this month')->setTime(23, 59, 59);

        // Convert to UTC for API (Calendly expects UTC in ISO 8601 format)
        $start_utc = $month_start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
        $end_utc = $month_end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.999\Z');

        // Fetch all events (WITHOUT invitee details first!)
        $all_events = [];
        $page_token = null;
        $max_pages = 20;
        $page_count = 0;

        do {
            // Build query parameters
            $params = [
                'organization' => $org_uri,
                'min_start_time' => $start_utc,
                'max_start_time' => $end_utc,
                'count' => 100,
                'status' => 'active' // Only get active events initially
            ];

            if ($page_token) {
                $params['page_token'] = $page_token;
            }

            $url = 'https://api.calendly.com/scheduled_events?' . http_build_query($params);
            list($data, $err) = api_get($url, $token, $page_count === 0); // Debug first page

            if ($err) {
                error_log("Calendly API Error on page $page_count: $err");
                throw new Exception("Calendly API Error: $err");
            }

            $events = $data['collection'] ?? [];
            $all_events = array_merge($all_events, $events);

            $page_token = $data['pagination']['next_page_token'] ?? null;
            $page_count++;

            // Safety break
            if ($page_count >= $max_pages) {
                error_log("Hit max pages limit ($max_pages) for event fetching");
                break;
            }

        } while ($page_token);

        // Also fetch canceled events for analytics
        $params_canceled = [
            'organization' => $org_uri,
            'min_start_time' => $start_utc,
            'max_start_time' => $end_utc,
            'count' => 100,
            'status' => 'canceled'
        ];

        $url_canceled = 'https://api.calendly.com/scheduled_events?' . http_build_query($params_canceled);
        list($canceled_data, $canceled_err) = api_get($url_canceled, $token);

        if (!$canceled_err && isset($canceled_data['collection'])) {
            $all_events = array_merge($all_events, $canceled_data['collection']);
        }

        return $all_events;
    }

    // API Endpoint: Basic Analytics
    if ($_GET['api'] === 'basic') {
        try {
            $month_year = $_GET['month'] ?? 'current';
            $events = getEventsForMonth($CALENDLY_TOKEN, $ORG_URI, $month_year);

            $analytics = [
                'total_bookings' => 0,
                'active_bookings' => 0,
                'canceled_bookings' => 0,
                'services' => [],
                'status_breakdown' => [],
                'avg_duration' => 0,
                'revenue_estimate' => 0,
                'cancellation_rate' => 0
            ];

            // Service pricing (extended)
            $service_prices = [
                'lerntraining' => 80,
                'neurofeedback-20' => 45,
                'neurofeedback-40' => 70,
                'neurofeedback training 20 min' => 45,
                'neurofeedback training 40 minuten' => 70,
                'neurofeedback training 40 min' => 70,
                'erstgespräch' => 0, // Free initial consultation
                'beratung' => 60
            ];

            $total_duration = 0;
            $active_count = 0;
            $canceled_count = 0;

            foreach ($events as $event) {
                $analytics['total_bookings']++;

                $status = $event['status'] ?? 'unknown';
                $analytics['status_breakdown'][$status] = ($analytics['status_breakdown'][$status] ?? 0) + 1;

                if ($status === 'active') {
                    $active_count++;
                    $analytics['active_bookings']++;
                } elseif ($status === 'canceled') {
                    $canceled_count++;
                    $analytics['canceled_bookings']++;
                }

                // Duration calculation
                $start_time = new DateTimeImmutable($event['start_time'], new DateTimeZone('UTC'));
                $end_time = new DateTimeImmutable($event['end_time'], new DateTimeZone('UTC'));
                $duration = ($end_time->getTimestamp() - $start_time->getTimestamp()) / 60;

                // Only count active bookings for duration average
                if ($status === 'active') {
                    $total_duration += $duration;
                }

                // Service analysis
                $service_name = $event['event_type']['name'] ?? 'Unknown';
                if (!isset($analytics['services'][$service_name])) {
                    $analytics['services'][$service_name] = [
                        'count' => 0,
                        'active' => 0,
                        'canceled' => 0,
                        'duration_total' => 0,
                        'revenue' => 0
                    ];
                }

                $analytics['services'][$service_name]['count']++;

                if ($status === 'active') {
                    $analytics['services'][$service_name]['active']++;
                    $analytics['services'][$service_name]['duration_total'] += $duration;

                    // Revenue estimation (only for active bookings)
                    $service_key = strtolower($service_name);
                    foreach ($service_prices as $key => $price) {
                        if (strpos($service_key, $key) !== false || strpos($key, $service_key) !== false) {
                            $analytics['services'][$service_name]['revenue'] += $price;
                            break;
                        }
                    }
                } elseif ($status === 'canceled') {
                    $analytics['services'][$service_name]['canceled']++;
                }
            }

            $analytics['avg_duration'] = $active_count > 0 ? round($total_duration / $active_count) : 0;
            $analytics['revenue_estimate'] = array_sum(array_column($analytics['services'], 'revenue'));
            $analytics['cancellation_rate'] = $analytics['total_bookings'] > 0
                ? round(($canceled_count / $analytics['total_bookings']) * 100, 1)
                : 0;

            // Add month info
            if ($month_year === 'current') {
                $display_month = (new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna')))->format('F Y');
            } else {
                $display_month = DateTimeImmutable::createFromFormat('Y-m', $month_year, new DateTimeZone('Europe/Vienna'))->format('F Y');
            }
            $analytics['display_month'] = $display_month;

            echo json_encode(['success' => true, 'data' => $analytics]);

        } catch (Exception $e) {
            error_log("Basic analytics error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API Endpoint: Customer Analytics
    if ($_GET['api'] === 'customers') {
        try {
            $month_year = $_GET['month'] ?? 'current';
            $events = getEventsForMonth($CALENDLY_TOKEN, $ORG_URI, $month_year);

            // Get unique event UUIDs
            $unique_events = [];
            foreach ($events as $event) {
                if (($event['status'] ?? '') === 'active') { // Only active events
                    $uuid = basename($event['uri']);
                    $unique_events[$uuid] = $event;
                }
            }

            $customer_emails = [];
            $customer_booking_count = [];
            $processed = 0;

            // Fetch invitee details (rate-limited)
            foreach ($unique_events as $uuid => $event) {
                $invitee_url = "https://api.calendly.com/scheduled_events/{$uuid}/invitees";
                list($invitee_data, $invitee_err) = api_get($invitee_url, $CALENDLY_TOKEN);

                if (!$invitee_err && isset($invitee_data['collection'])) {
                    foreach ($invitee_data['collection'] as $invitee) {
                        $email = strtolower($invitee['email'] ?? '');
                        if ($email) {
                            $customer_emails[$email] = $invitee['name'] ?? 'Unknown';
                            $customer_booking_count[$email] = ($customer_booking_count[$email] ?? 0) + 1;
                        }
                    }
                }

                $processed++;

                // Rate limiting
                if ($processed % 5 === 0) {
                    usleep(250000); // 250ms delay every 5 requests
                }

                // Safety limit
                if ($processed >= 100) break;
            }

            // Top customers
            arsort($customer_booking_count);
            $top_customers = array_slice($customer_booking_count, 0, 15, true);

            // New vs returning customers
            $new_customers = 0;
            $returning_customers = 0;
            foreach ($customer_booking_count as $email => $count) {
                if ($count == 1) {
                    $new_customers++;
                } else {
                    $returning_customers++;
                }
            }

            $analytics = [
                'unique_customers' => count($customer_emails),
                'new_customers' => $new_customers,
                'returning_customers' => $returning_customers,
                'top_customers' => $top_customers,
                'processed_events' => $processed,
                'total_events' => count($events),
                'repeat_rate' => count($customer_emails) > 0
                    ? round(($returning_customers / count($customer_emails)) * 100, 1)
                    : 0
            ];

            echo json_encode(['success' => true, 'data' => $analytics]);

        } catch (Exception $e) {
            error_log("Customer analytics error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API Endpoint: Database Analytics (customer_activities integration)
    if ($_GET['api'] === 'database') {
        try {
            $pdo = getPDO();
            $month_year = $_GET['month'] ?? 'current';

            // Calculate date range
            if ($month_year === 'current') {
                $target_date = new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna'));
            } else {
                $target_date = DateTimeImmutable::createFromFormat('Y-m', $month_year, new DateTimeZone('Europe/Vienna'));
            }

            $month_start = $target_date->modify('first day of this month')->setTime(0, 0, 0);
            $month_end = $target_date->modify('last day of this month')->setTime(23, 59, 59);

            // Booking funnel from customer_activities
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(DISTINCT CASE WHEN activity_type = 'service_viewed' THEN customer_id END) as viewed_services,
                    COUNT(DISTINCT CASE WHEN activity_type = 'slots_found' THEN customer_id END) as found_slots,
                    COUNT(DISTINCT CASE WHEN activity_type = 'booking_initiated' THEN customer_id END) as initiated_booking,
                    COUNT(DISTINCT CASE WHEN activity_type = 'booking_completed' THEN customer_id END) as completed_booking,
                    COUNT(CASE WHEN activity_type = 'booking_initiated' THEN 1 END) as total_attempts,
                    COUNT(CASE WHEN activity_type = 'booking_completed' THEN 1 END) as total_completions
                FROM customer_activities
                WHERE created_at >= ? AND created_at <= ?
            ");

            $stmt->execute([$month_start->format('Y-m-d H:i:s'), $month_end->format('Y-m-d H:i:s')]);
            $funnel = $stmt->fetch(PDO::FETCH_ASSOC);

            // Service performance from activities
            $stmt = $pdo->prepare("
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(activity_data, '$.service_slug')) as service,
                    COUNT(*) as views,
                    COUNT(DISTINCT customer_id) as unique_viewers
                FROM customer_activities
                WHERE activity_type = 'service_viewed'
                AND created_at >= ? AND created_at <= ?
                AND JSON_EXTRACT(activity_data, '$.service_slug') IS NOT NULL
                GROUP BY service
                ORDER BY views DESC
                LIMIT 10
            ");

            $stmt->execute([$month_start->format('Y-m-d H:i:s'), $month_end->format('Y-m-d H:i:s')]);
            $service_views = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Top active users (most engaged customers)
            $stmt = $pdo->prepare("
                SELECT
                    c.email,
                    c.first_name,
                    c.last_name,
                    c.status as customer_status,
                    COUNT(ca.id) as activity_count,
                    COUNT(DISTINCT CASE WHEN ca.activity_type = 'booking_completed' THEN ca.id END) as bookings,
                    COUNT(DISTINCT CASE WHEN ca.activity_type = 'service_viewed' THEN ca.id END) as service_views,
                    COUNT(DISTINCT CASE WHEN ca.activity_type = 'booking_initiated' THEN ca.id END) as booking_attempts,
                    MIN(ca.created_at) as first_activity,
                    MAX(ca.created_at) as last_activity
                FROM customers c
                INNER JOIN customer_activities ca ON c.id = ca.customer_id
                WHERE ca.created_at >= ? AND ca.created_at <= ?
                GROUP BY c.id, c.email, c.first_name, c.last_name, c.status
                HAVING activity_count > 0
                ORDER BY bookings DESC, activity_count DESC
                LIMIT 20
            ");

            $stmt->execute([$month_start->format('Y-m-d H:i:s'), $month_end->format('Y-m-d H:i:s')]);
            $top_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate conversion rates
            $conversion_rates = [
                'view_to_search' => $funnel['viewed_services'] > 0
                    ? round(($funnel['found_slots'] / $funnel['viewed_services']) * 100, 1)
                    : 0,
                'search_to_initiate' => $funnel['found_slots'] > 0
                    ? round(($funnel['initiated_booking'] / $funnel['found_slots']) * 100, 1)
                    : 0,
                'initiate_to_complete' => $funnel['total_attempts'] > 0
                    ? round(($funnel['total_completions'] / $funnel['total_attempts']) * 100, 1)
                    : 0,
                'overall_conversion' => $funnel['viewed_services'] > 0
                    ? round(($funnel['completed_booking'] / $funnel['viewed_services']) * 100, 1)
                    : 0
            ];

            // Additional activity statistics
            $stmt = $pdo->prepare("
                SELECT
                    activity_type,
                    COUNT(*) as count,
                    COUNT(DISTINCT customer_id) as unique_customers,
                    DATE(created_at) as activity_date
                FROM customer_activities
                WHERE created_at >= ? AND created_at <= ?
                GROUP BY activity_type
                ORDER BY count DESC
            ");
            $stmt->execute([$month_start->format('Y-m-d H:i:s'), $month_end->format('Y-m-d H:i:s')]);
            $activity_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Customer engagement metrics
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(DISTINCT customer_id) as total_active_customers,
                    AVG(activity_count) as avg_activities_per_customer,
                    MAX(activity_count) as max_activities
                FROM (
                    SELECT customer_id, COUNT(*) as activity_count
                    FROM customer_activities
                    WHERE created_at >= ? AND created_at <= ?
                    GROUP BY customer_id
                ) as customer_stats
            ");
            $stmt->execute([$month_start->format('Y-m-d H:i:s'), $month_end->format('Y-m-d H:i:s')]);
            $engagement_metrics = $stmt->fetch(PDO::FETCH_ASSOC);

            // Drop-off analysis
            $drop_off_analysis = [
                'after_view' => $funnel['viewed_services'] - $funnel['found_slots'],
                'after_search' => $funnel['found_slots'] - $funnel['initiated_booking'],
                'after_initiate' => $funnel['total_attempts'] - $funnel['total_completions']
            ];

            echo json_encode([
                'success' => true,
                'data' => [
                    'funnel' => $funnel,
                    'conversion_rates' => $conversion_rates,
                    'drop_off_analysis' => $drop_off_analysis,
                    'service_views' => $service_views,
                    'top_users' => $top_users,
                    'activity_breakdown' => $activity_breakdown,
                    'engagement_metrics' => $engagement_metrics
                ]
            ]);

        } catch (Exception $e) {
            error_log("Database analytics error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // Invalid API endpoint
    echo json_encode(['success' => false, 'error' => 'Invalid API endpoint']);
    exit;
}

// Generate month options
function getMonthOptions() {
    $options = [];
    $current = new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna'));

    $options['current'] = $current->format('F Y') . ' (Aktuell)';

    for ($i = 1; $i <= 12; $i++) {
        $month = $current->modify("-{$i} months");
        $key = $month->format('Y-m');
        $options[$key] = $month->format('F Y');
    }

    for ($i = 1; $i <= 3; $i++) {
        $month = $current->modify("+{$i} months");
        $key = $month->format('Y-m');
        $options[$key] = $month->format('F Y');
    }

    return $options;
}

$month_options = getMonthOptions();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buchungs-Analytics Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 28px 32px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .title-section {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .dashboard-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .title-content h1 {
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 6px;
        }

        .title-content p {
            color: #718096;
            font-size: 15px;
            font-weight: 500;
        }

        .header-controls {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .month-selector {
            padding: 12px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #2d3748;
            min-width: 200px;
        }

        .month-selector:hover {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .month-selector:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #f7fafc;
            color: #2d3748;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #edf2f7;
            border-color: #cbd5e0;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .kpi-card {
            background: white;
            padding: 28px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            min-height: 160px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 50px rgba(0,0,0,0.12);
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .kpi-card.loading {
            opacity: 0.6;
        }

        .kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 16px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
        }

        .kpi-number {
            font-size: 44px;
            font-weight: 800;
            color: #2d3748;
            margin-bottom: 10px;
            line-height: 1;
        }

        .kpi-label {
            font-size: 15px;
            color: #718096;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .kpi-change {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            background: #e2e8f0;
            color: #4a5568;
        }

        .kpi-change.positive {
            background: #c6f6d5;
            color: #22543d;
        }

        .kpi-change.negative {
            background: #fed7d7;
            color: #742a2a;
        }

        .kpi-change.neutral {
            background: #bee3f8;
            color: #2c5282;
        }

        .section-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 15px 50px rgba(0,0,0,0.12);
        }

        .card-header {
            padding: 24px 28px;
            border-bottom: 2px solid #f7fafc;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
        }

        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            flex: 1;
        }

        .card-body {
            padding: 28px;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 4px solid #667eea;
            margin-bottom: 12px;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            background: #edf2f7;
            transform: translateX(4px);
        }

        .stat-label {
            font-weight: 600;
            color: #2d3748;
            font-size: 15px;
        }

        .stat-value {
            font-size: 22px;
            font-weight: 800;
            color: #667eea;
        }

        .stat-secondary {
            font-size: 13px;
            color: #718096;
            margin-left: 12px;
        }

        .chart-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 250px;
            color: #a0aec0;
            flex-direction: column;
            gap: 16px;
            font-size: 15px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e2e8f0;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .error-state {
            background: white;
            padding: 60px 32px;
            border-radius: 20px;
            text-align: center;
            margin-bottom: 24px;
            border: 3px dashed #fed7d7;
            color: #c53030;
        }

        .error-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.8;
        }

        .error-state h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .error-state p {
            font-size: 15px;
            color: #742a2a;
            margin-bottom: 24px;
        }

        .no-data {
            text-align: center;
            color: #a0aec0;
            padding: 60px 20px;
            font-style: italic;
            font-size: 15px;
        }

        .badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 700;
        }

        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-danger {
            background: #fed7d7;
            color: #742a2a;
        }

        .badge-info {
            background: #bee3f8;
            color: #2c5282;
        }

        .customer-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 18px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.2s ease;
        }

        .customer-item:hover {
            background: #edf2f7;
            transform: translateX(4px);
        }

        .customer-email {
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }

        .customer-count {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 6px 14px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
        }

        .funnel-step {
            display: flex;
            align-items: center;
            padding: 20px 24px;
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.05), rgba(118, 75, 162, 0.05));
            border-radius: 12px;
            border-left: 5px solid #667eea;
            margin-bottom: 14px;
            transition: all 0.3s ease;
        }

        .funnel-step:hover {
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            transform: translateX(6px);
        }

        .funnel-number {
            font-size: 32px;
            font-weight: 800;
            color: #667eea;
            margin-right: 24px;
            min-width: 80px;
        }

        .funnel-label {
            flex: 1;
            font-weight: 600;
            color: #2d3748;
            font-size: 16px;
        }

        .funnel-rate {
            font-size: 18px;
            font-weight: 700;
            color: #718096;
            background: white;
            padding: 10px 18px;
            border-radius: 20px;
        }

        .alert {
            padding: 18px 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-info {
            background: #bee3f8;
            color: #2c5282;
            border-left: 4px solid #3182ce;
        }

        @media (max-width: 1200px) {
            .section-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 12px;
            }

            .header {
                flex-direction: column;
                text-align: center;
                padding: 20px;
            }

            .title-section {
                flex-direction: column;
            }

            .header-controls {
                flex-direction: column;
                width: 100%;
            }

            .month-selector,
            .btn {
                width: 100%;
            }

            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .kpi-card {
                padding: 20px;
                min-height: 140px;
            }

            .kpi-number {
                font-size: 32px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="title-section">
                <div class="dashboard-icon">📊</div>
                <div class="title-content">
                    <h1>Buchungs-Analytics</h1>
                    <p>Umfassende Analyse Ihrer Buchungen & Geschäftsprozesse</p>
                </div>
            </div>
            <div class="header-controls">
                <select id="monthSelector" class="month-selector">
                    <?php foreach ($month_options as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button id="refreshBtn" class="btn btn-primary">🔄 Aktualisieren</button>
                <a href="?export=csv&month=current" class="btn btn-secondary">📥 CSV Export</a>
                <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
            </div>
        </div>

        <!-- Error State -->
        <div id="errorState" class="error-state" style="display: none;">
            <div class="error-icon">⚠️</div>
            <h3>Analytics vorübergehend nicht verfügbar</h3>
            <p id="errorMessage">Fehler beim Laden der Buchungsdaten</p>
            <button class="btn btn-primary" onclick="loadAnalyticsData()">Erneut versuchen</button>
        </div>

        <!-- Info Alert -->
        <div class="alert alert-info">
            <span style="font-size: 20px;">💡</span>
            <div>
                <strong>Erweiterte Analytics:</strong> Dieses Dashboard zeigt Calendly-Buchungen, Kundenaktivitäten und Conversion-Metriken in Echtzeit.
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card loading">
                <div class="kpi-icon">📅</div>
                <div class="kpi-number" id="totalBookings">--</div>
                <div class="kpi-label">Gesamt-Buchungen</div>
                <div class="kpi-change" id="bookingsChange">Lädt...</div>
            </div>

            <div class="kpi-card loading">
                <div class="kpi-icon">✅</div>
                <div class="kpi-number" id="activeBookings">--</div>
                <div class="kpi-label">Aktive Buchungen</div>
                <div class="kpi-change positive" id="activeChange">Lädt...</div>
            </div>

            <div class="kpi-card loading">
                <div class="kpi-icon">❌</div>
                <div class="kpi-number" id="canceledBookings">--</div>
                <div class="kpi-label">Stornierte Buchungen</div>
                <div class="kpi-change negative" id="canceledChange">Lädt...</div>
            </div>

            <div class="kpi-card loading">
                <div class="kpi-icon">👥</div>
                <div class="kpi-number" id="uniqueCustomers">--</div>
                <div class="kpi-label">Einzigartige Kunden</div>
                <div class="kpi-change neutral" id="customersChange">Lädt...</div>
            </div>

            <div class="kpi-card loading">
                <div class="kpi-icon">💰</div>
                <div class="kpi-number" id="revenueEstimate">€--</div>
                <div class="kpi-label">Umsatzschätzung</div>
                <div class="kpi-change neutral" id="revenueChange">Lädt...</div>
            </div>

            <div class="kpi-card loading">
                <div class="kpi-icon">⏱️</div>
                <div class="kpi-number" id="avgDuration">--</div>
                <div class="kpi-label">Ø Dauer (Min.)</div>
                <div class="kpi-change neutral" id="durationChange">Lädt...</div>
            </div>

            <div class="kpi-card loading">
                <div class="kpi-icon">🔄</div>
                <div class="kpi-number" id="repeatRate">--%</div>
                <div class="kpi-label">Wiederholungsrate</div>
                <div class="kpi-change" id="repeatChange">Lädt...</div>
            </div>

            <div class="kpi-card loading">
                <div class="kpi-icon">📉</div>
                <div class="kpi-number" id="cancellationRate">--%</div>
                <div class="kpi-label">Stornierungsrate</div>
                <div class="kpi-change" id="cancellationRateChange">Lädt...</div>
            </div>
        </div>

        <!-- Main Analytics Grid -->
        <div class="section-grid">
            <!-- Service Performance -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">🎯</div>
                    <h3 class="card-title">Service Performance</h3>
                </div>
                <div class="card-body">
                    <div id="servicePerformance" class="chart-loading">
                        <div class="spinner"></div>
                        <div>Lade Service-Daten...</div>
                    </div>
                </div>
            </div>

            <!-- Top Customers -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">👑</div>
                    <h3 class="card-title">Top Kunden</h3>
                </div>
                <div class="card-body">
                    <div id="topCustomers" class="chart-loading">
                        <div class="spinner"></div>
                        <div>Lade Kundendaten...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status & Conversion -->
        <div class="section-grid">
            <!-- Status Breakdown -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">📈</div>
                    <h3 class="card-title">Buchungsstatus</h3>
                </div>
                <div class="card-body">
                    <div id="statusBreakdown" class="chart-loading">
                        <div class="spinner"></div>
                        <div>Lade Status-Daten...</div>
                    </div>
                </div>
            </div>

            <!-- Conversion Funnel -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">🔄</div>
                    <h3 class="card-title">Conversion Funnel</h3>
                </div>
                <div class="card-body">
                    <div id="conversionFunnel" class="chart-loading">
                        <div class="spinner"></div>
                        <div>Lade Conversion-Daten...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Service Views from Database -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <div class="card-icon">👀</div>
                <h3 class="card-title">Service-Aufrufe (aus Datenbank)</h3>
            </div>
            <div class="card-body">
                <div id="serviceViews" class="chart-loading">
                    <div class="spinner"></div>
                    <div>Lade Datenbank-Daten...</div>
                </div>
            </div>
        </div>

        <!-- Extended Analytics Grid -->
        <div class="section-grid">
            <!-- Activity Breakdown -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">📊</div>
                    <h3 class="card-title">Aktivitäts-Übersicht</h3>
                </div>
                <div class="card-body">
                    <div id="activityBreakdown" class="chart-loading">
                        <div class="spinner"></div>
                        <div>Lade Aktivitätsdaten...</div>
                    </div>
                </div>
            </div>

            <!-- Engagement Metrics -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">💪</div>
                    <h3 class="card-title">Engagement-Metriken</h3>
                </div>
                <div class="card-body">
                    <div id="engagementMetrics" class="chart-loading">
                        <div class="spinner"></div>
                        <div>Lade Engagement-Daten...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Active Users -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <div class="card-icon">⭐</div>
                <h3 class="card-title">Top Aktive Nutzer</h3>
            </div>
            <div class="card-body">
                <div id="topActiveUsers" class="chart-loading">
                    <div class="spinner"></div>
                    <div>Lade Nutzerdaten...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentBasicData = null;
        let currentCustomerData = null;
        let currentDatabaseData = null;
        let isLoading = false;

        function resetToLoadingState() {
            document.querySelectorAll('.kpi-card').forEach(card => card.classList.add('loading'));

            document.getElementById('totalBookings').textContent = '--';
            document.getElementById('activeBookings').textContent = '--';
            document.getElementById('canceledBookings').textContent = '--';
            document.getElementById('uniqueCustomers').textContent = '--';
            document.getElementById('revenueEstimate').textContent = '€--';
            document.getElementById('avgDuration').textContent = '--';
            document.getElementById('repeatRate').textContent = '--%';
            document.getElementById('cancellationRate').textContent = '--%';

            document.querySelectorAll('.kpi-change').forEach(change => {
                change.textContent = 'Lädt...';
            });

            const loadingHTML = '<div class="chart-loading"><div class="spinner"></div><div>Lädt...</div></div>';
            document.getElementById('servicePerformance').innerHTML = loadingHTML;
            document.getElementById('topCustomers').innerHTML = loadingHTML;
            document.getElementById('statusBreakdown').innerHTML = loadingHTML;
            document.getElementById('conversionFunnel').innerHTML = loadingHTML;
            document.getElementById('serviceViews').innerHTML = loadingHTML;
            document.getElementById('activityBreakdown').innerHTML = loadingHTML;
            document.getElementById('engagementMetrics').innerHTML = loadingHTML;
            document.getElementById('topActiveUsers').innerHTML = loadingHTML;
        }

        function showError(message) {
            isLoading = false;
            document.getElementById('errorState').style.display = 'block';
            document.getElementById('errorMessage').textContent = message;
            document.getElementById('refreshBtn').disabled = false;

            // Scroll to error
            document.getElementById('errorState').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function updateBasicKPIs(data) {
            document.getElementById('totalBookings').textContent = data.total_bookings;
            document.getElementById('activeBookings').textContent = data.active_bookings;
            document.getElementById('canceledBookings').textContent = data.canceled_bookings;
            document.getElementById('revenueEstimate').textContent = '€' + data.revenue_estimate.toLocaleString('de-DE');
            document.getElementById('avgDuration').textContent = data.avg_duration;
            document.getElementById('cancellationRate').textContent = data.cancellation_rate + '%';

            document.getElementById('bookingsChange').textContent = data.display_month;
            document.getElementById('activeChange').textContent = 'Bestätigt';
            document.getElementById('canceledChange').textContent = 'Storniert';
            document.getElementById('revenueChange').textContent = 'Geschätzt';
            document.getElementById('durationChange').textContent = 'Pro Session';
            document.getElementById('cancellationRateChange').textContent = data.canceled_bookings + ' von ' + data.total_bookings;

            // Update cards (except customers and repeat rate)
            document.querySelectorAll('.kpi-card').forEach((card, index) => {
                if (index !== 3 && index !== 6) { // Skip unique customers and repeat rate
                    card.classList.remove('loading');
                }
            });
        }

        function updateCustomerKPIs(data) {
            document.getElementById('uniqueCustomers').textContent = data.unique_customers;
            document.getElementById('repeatRate').textContent = data.repeat_rate + '%';

            document.getElementById('customersChange').textContent = data.new_customers + ' neu';
            document.getElementById('repeatChange').textContent = data.returning_customers + ' wiederkehrend';

            document.querySelectorAll('.kpi-card')[3].classList.remove('loading');
            document.querySelectorAll('.kpi-card')[6].classList.remove('loading');
        }

        function updateServicePerformance(services) {
            const container = document.getElementById('servicePerformance');

            if (Object.keys(services).length === 0) {
                container.innerHTML = '<div class="no-data">Keine Service-Daten verfügbar</div>';
                return;
            }

            let html = '';
            Object.entries(services).forEach(([serviceName, data]) => {
                const avgDuration = data.active > 0 ? Math.round(data.duration_total / data.active) : 0;
                const cancelRate = data.count > 0 ? Math.round((data.canceled / data.count) * 100) : 0;

                html += `
                    <div class="stat-item">
                        <div>
                            <div class="stat-label">${serviceName}</div>
                            <div class="stat-secondary">
                                ${data.active} aktiv • ${data.canceled} storniert • ${cancelRate}% Storno-Rate
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div class="stat-value">${data.count}</div>
                            <div class="stat-secondary">€${data.revenue.toLocaleString('de-DE')}</div>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        function updateTopCustomers(topCustomers, displayMonth) {
            const container = document.getElementById('topCustomers');

            if (Object.keys(topCustomers).length === 0) {
                container.innerHTML = '<div class="no-data">Keine Kundendaten verfügbar</div>';
                return;
            }

            let html = '';
            let rank = 1;
            Object.entries(topCustomers).forEach(([email, count]) => {
                html += `
                    <div class="customer-item">
                        <div>
                            <div style="font-size: 12px; color: #667eea; font-weight: 700; margin-bottom: 4px;">#${rank}</div>
                            <div class="customer-email">${email}</div>
                        </div>
                        <div class="customer-count">${count} Buchung${count > 1 ? 'en' : ''}</div>
                    </div>
                `;
                rank++;
            });

            container.innerHTML = html;
        }

        function updateStatusBreakdown(statusBreakdown, totalBookings) {
            const container = document.getElementById('statusBreakdown');

            if (Object.keys(statusBreakdown).length === 0) {
                container.innerHTML = '<div class="no-data">Keine Status-Daten verfügbar</div>';
                return;
            }

            let html = '';
            const statusLabels = {
                'active': 'Aktiv',
                'canceled': 'Storniert',
                'unknown': 'Unbekannt'
            };

            const statusIcons = {
                'active': '✅',
                'canceled': '❌',
                'unknown': '❓'
            };

            Object.entries(statusBreakdown).forEach(([status, count]) => {
                const percentage = totalBookings > 0 ? Math.round((count / totalBookings) * 100) : 0;
                const label = statusLabels[status] || status.charAt(0).toUpperCase() + status.slice(1);
                const icon = statusIcons[status] || '📊';

                html += `
                    <div class="stat-item">
                        <div class="stat-label">${icon} ${label}</div>
                        <div>
                            <span class="stat-value">${count}</span>
                            <span class="stat-secondary">(${percentage}%)</span>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        function updateConversionFunnel(funnelData, conversionRates, dropOffAnalysis) {
            const container = document.getElementById('conversionFunnel');

            if (!funnelData) {
                container.innerHTML = '<div class="no-data">Keine Conversion-Daten verfügbar</div>';
                return;
            }

            const dropOff1 = dropOffAnalysis?.after_view || 0;
            const dropOff2 = dropOffAnalysis?.after_search || 0;
            const dropOff3 = dropOffAnalysis?.after_initiate || 0;

            const html = `
                <div style="margin-bottom: 20px; padding: 16px; background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(118, 75, 162, 0.05)); border-radius: 12px;">
                    <div style="font-size: 14px; color: #718096; margin-bottom: 8px; font-weight: 600;">Gesamt-Conversion Rate</div>
                    <div style="font-size: 32px; font-weight: 800; color: #667eea;">${conversionRates.overall_conversion || 0}%</div>
                    <div style="font-size: 13px; color: #718096; margin-top: 4px;">Von Service-Ansicht bis Buchung</div>
                </div>
                <div class="funnel-step">
                    <div class="funnel-number">${funnelData.viewed_services || 0}</div>
                    <div class="funnel-label">Services angesehen</div>
                    <div class="funnel-rate">100%</div>
                </div>
                <div style="text-align: center; color: #e53e3e; font-size: 13px; font-weight: 600; margin: 8px 0;">↓ ${dropOff1} Absprünge</div>
                <div class="funnel-step">
                    <div class="funnel-number">${funnelData.found_slots || 0}</div>
                    <div class="funnel-label">Slots gefunden</div>
                    <div class="funnel-rate">${conversionRates.view_to_search}%</div>
                </div>
                <div style="text-align: center; color: #e53e3e; font-size: 13px; font-weight: 600; margin: 8px 0;">↓ ${dropOff2} Absprünge</div>
                <div class="funnel-step">
                    <div class="funnel-number">${funnelData.initiated_booking || 0}</div>
                    <div class="funnel-label">Buchung gestartet</div>
                    <div class="funnel-rate">${conversionRates.search_to_initiate}%</div>
                </div>
                <div style="text-align: center; color: #e53e3e; font-size: 13px; font-weight: 600; margin: 8px 0;">↓ ${dropOff3} Absprünge</div>
                <div class="funnel-step">
                    <div class="funnel-number">${funnelData.completed_booking || 0}</div>
                    <div class="funnel-label">Buchung abgeschlossen</div>
                    <div class="funnel-rate">${conversionRates.initiate_to_complete}%</div>
                </div>
            `;

            container.innerHTML = html;
        }

        function updateServiceViews(serviceViews) {
            const container = document.getElementById('serviceViews');

            if (!serviceViews || serviceViews.length === 0) {
                container.innerHTML = '<div class="no-data">Keine Service-Aufrufe in diesem Zeitraum</div>';
                return;
            }

            let html = '';
            serviceViews.forEach(service => {
                const serviceName = service.service.replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                html += `
                    <div class="stat-item">
                        <div class="stat-label">${serviceName}</div>
                        <div>
                            <span class="stat-value">${service.views}</span>
                            <span class="stat-secondary">${service.unique_viewers} Besucher</span>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        function updateActivityBreakdown(activities) {
            const container = document.getElementById('activityBreakdown');

            if (!activities || activities.length === 0) {
                container.innerHTML = '<div class="no-data">Keine Aktivitätsdaten verfügbar</div>';
                return;
            }

            const activityLabels = {
                'service_viewed': '👁️ Service angesehen',
                'slots_found': '🔍 Slots gefunden',
                'booking_initiated': '▶️ Buchung gestartet',
                'booking_completed': '✅ Buchung abgeschlossen',
                'profile_updated': '👤 Profil aktualisiert',
                'login': '🔐 Login'
            };

            let html = '';
            activities.forEach(activity => {
                const label = activityLabels[activity.activity_type] || activity.activity_type;
                html += `
                    <div class="stat-item">
                        <div class="stat-label">${label}</div>
                        <div>
                            <span class="stat-value">${activity.count}</span>
                            <span class="stat-secondary">${activity.unique_customers} Kunden</span>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        function updateEngagementMetrics(metrics) {
            const container = document.getElementById('engagementMetrics');

            if (!metrics) {
                container.innerHTML = '<div class="no-data">Keine Engagement-Daten verfügbar</div>';
                return;
            }

            const avgActivities = metrics.avg_activities_per_customer
                ? parseFloat(metrics.avg_activities_per_customer).toFixed(1)
                : 0;

            const html = `
                <div class="stat-item">
                    <div class="stat-label">👥 Aktive Kunden gesamt</div>
                    <div class="stat-value">${metrics.total_active_customers || 0}</div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">📊 Ø Aktivitäten pro Kunde</div>
                    <div class="stat-value">${avgActivities}</div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">🏆 Maximale Aktivitäten (1 Kunde)</div>
                    <div class="stat-value">${metrics.max_activities || 0}</div>
                </div>
            `;

            container.innerHTML = html;
        }

        function updateTopActiveUsers(users) {
            const container = document.getElementById('topActiveUsers');

            if (!users || users.length === 0) {
                container.innerHTML = '<div class="no-data">Keine Nutzerdaten verfügbar</div>';
                return;
            }

            let html = '<div style="display: grid; gap: 12px;">';
            users.forEach((user, index) => {
                const name = user.first_name && user.last_name
                    ? `${user.first_name} ${user.last_name}`
                    : user.email;
                const isTopThree = index < 3;
                const borderColor = isTopThree
                    ? (index === 0 ? '#ffd700' : index === 1 ? '#c0c0c0' : '#cd7f32')
                    : '#667eea';

                html += `
                    <div class="stat-item" style="border-left-color: ${borderColor};">
                        <div>
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                                <span style="font-size: 18px;">${index === 0 ? '🥇' : index === 1 ? '🥈' : index === 2 ? '🥉' : '⭐'}</span>
                                <div class="stat-label">${name}</div>
                            </div>
                            <div style="font-size: 12px; color: #718096;">
                                ${user.email}
                            </div>
                            <div style="font-size: 12px; color: #718096; margin-top: 4px;">
                                📅 ${user.service_views} Ansichten •
                                🎯 ${user.booking_attempts} Versuche •
                                ✅ ${user.bookings} Buchungen
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div class="stat-value">${user.activity_count}</div>
                            <div class="stat-secondary">Aktivitäten</div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';

            container.innerHTML = html;
        }

        async function loadBasicData() {
            const selectedMonth = document.getElementById('monthSelector').value;

            try {
                console.log('Loading basic data for month:', selectedMonth);

                const response = await fetch(`?api=basic&month=${encodeURIComponent(selectedMonth)}`);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const result = await response.json();
                console.log('Basic data result:', result);

                if (!result.success) {
                    throw new Error(result.error || 'Fehler beim Laden der Basis-Daten');
                }

                currentBasicData = result.data;
                updateBasicKPIs(currentBasicData);
                updateServicePerformance(currentBasicData.services);
                updateStatusBreakdown(currentBasicData.status_breakdown, currentBasicData.total_bookings);

                return true;

            } catch (error) {
                console.error('Error loading basic data:', error);
                throw error;
            }
        }

        async function loadCustomerData() {
            const selectedMonth = document.getElementById('monthSelector').value;

            try {
                console.log('Loading customer data for month:', selectedMonth);

                const response = await fetch(`?api=customers&month=${encodeURIComponent(selectedMonth)}`);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const result = await response.json();
                console.log('Customer data result:', result);

                if (!result.success) {
                    throw new Error(result.error || 'Fehler beim Laden der Kundendaten');
                }

                currentCustomerData = result.data;
                updateCustomerKPIs(currentCustomerData);
                updateTopCustomers(currentCustomerData.top_customers, currentBasicData.display_month);

                return true;

            } catch (error) {
                console.error('Error loading customer data:', error);
                document.getElementById('uniqueCustomers').textContent = '?';
                document.getElementById('repeatRate').textContent = '?';
                document.getElementById('customersChange').textContent = 'Fehler';
                document.getElementById('repeatChange').textContent = 'Fehler';
                document.getElementById('topCustomers').innerHTML = '<div class="no-data">Kundendaten nicht verfügbar</div>';
                return false;
            }
        }

        async function loadDatabaseData() {
            const selectedMonth = document.getElementById('monthSelector').value;

            try {
                console.log('Loading database data for month:', selectedMonth);

                const response = await fetch(`?api=database&month=${encodeURIComponent(selectedMonth)}`);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const result = await response.json();
                console.log('Database data result:', result);

                if (!result.success) {
                    throw new Error(result.error || 'Fehler beim Laden der Datenbank-Daten');
                }

                currentDatabaseData = result.data;
                updateConversionFunnel(currentDatabaseData.funnel, currentDatabaseData.conversion_rates, currentDatabaseData.drop_off_analysis);
                updateServiceViews(currentDatabaseData.service_views);
                updateActivityBreakdown(currentDatabaseData.activity_breakdown);
                updateEngagementMetrics(currentDatabaseData.engagement_metrics);
                updateTopActiveUsers(currentDatabaseData.top_users);

                return true;

            } catch (error) {
                console.error('Error loading database data:', error);
                document.getElementById('conversionFunnel').innerHTML = '<div class="no-data">Conversion-Daten nicht verfügbar</div>';
                document.getElementById('serviceViews').innerHTML = '<div class="no-data">Service-Aufrufe nicht verfügbar</div>';
                document.getElementById('activityBreakdown').innerHTML = '<div class="no-data">Aktivitätsdaten nicht verfügbar</div>';
                document.getElementById('engagementMetrics').innerHTML = '<div class="no-data">Engagement-Daten nicht verfügbar</div>';
                document.getElementById('topActiveUsers').innerHTML = '<div class="no-data">Nutzerdaten nicht verfügbar</div>';
                return false;
            }
        }

        async function loadAnalyticsData() {
            if (isLoading) return;

            isLoading = true;
            document.getElementById('refreshBtn').disabled = true;
            document.getElementById('errorState').style.display = 'none';

            resetToLoadingState();

            try {
                // Load all data in stages
                await loadBasicData();
                await loadCustomerData(); // Non-blocking
                await loadDatabaseData(); // Non-blocking

            } catch (error) {
                console.error('Error loading analytics:', error);
                showError(`${error.message} - Bitte Console für Details prüfen`);
            } finally {
                isLoading = false;
                document.getElementById('refreshBtn').disabled = false;
            }
        }

        // Event listeners
        document.getElementById('monthSelector').addEventListener('change', loadAnalyticsData);
        document.getElementById('refreshBtn').addEventListener('click', loadAnalyticsData);

        // Initial load
        window.addEventListener('load', loadAnalyticsData);
    </script>
</body>
</html>
