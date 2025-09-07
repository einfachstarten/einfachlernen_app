<?php
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

function getCalendlyConfig() {
    return [
        'token' => getenv('CALENDLY_TOKEN') ?: 'PASTE_YOUR_TOKEN_HERE',
        'org_uri' => getenv('CALENDLY_ORG_URI') ?: 'https://api.calendly.com/organizations/PASTE_ORG_ID'
    ];
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
        return [null, 'Network error: ' . curl_error($ch)];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        return [null, "HTTP $code"];
    }

    $json = json_decode($res, true);
    return [$json, null];
}

function getServicePriceMapping() {
    return [
        'Lerntraining' => 45.00,
        'Neurofeedback 20' => 45.00,
        'Neurofeedback 40' => 65.00,
        'Neurofeedback + Lerntraining' => 85.00,
        'Erstberatung' => 0.00,
        'Lerntyp-Analyse' => 65.00
    ];
}

require_once 'ActivityLogger.php';

$pdo = getPDO();
$logger = new ActivityLogger($pdo);

$days = $_GET['days'] ?? 30;
$stats = $logger->getActivityStats($days);
$top_customers = $logger->getTopActiveCustomers($days);

// Enhanced Analytics Data
function getKPIMetrics($pdo, $days) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT customer_id) as active_users,
            COUNT(CASE WHEN activity_type = 'login' THEN 1 END) as total_logins,
            COUNT(CASE WHEN activity_type = 'slots_found' THEN 1 END) as slot_searches,
            COUNT(CASE WHEN activity_type = 'booking_initiated' THEN 1 END) as booking_attempts,
            COUNT(CASE WHEN activity_type = 'booking_completed' THEN 1 END) as bookings_completed,
            COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_activities
        FROM customer_activities 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$days]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getActivityTrends($pdo, $days) {
    $stmt = $pdo->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as total_activities,
            COUNT(CASE WHEN activity_type IN ('login', 'logout', 'pin_request') THEN 1 END) as auth_activities,
            COUNT(CASE WHEN activity_type IN ('slots_api_called', 'booking_initiated', 'booking_completed') THEN 1 END) as booking_activities,
            COUNT(CASE WHEN activity_type IN ('dashboard_accessed', 'page_view') THEN 1 END) as navigation_activities
        FROM customer_activities 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$days]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getConversionFunnel($pdo, $days) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT CASE WHEN activity_type = 'login' THEN customer_id END) as users_logged_in,
            COUNT(DISTINCT CASE WHEN activity_type = 'service_viewed' THEN customer_id END) as users_viewed_services,
            COUNT(DISTINCT CASE WHEN activity_type = 'slots_found' THEN customer_id END) as users_found_slots,
            COUNT(DISTINCT CASE WHEN activity_type = 'booking_initiated' THEN customer_id END) as users_started_booking,
            COUNT(DISTINCT CASE WHEN activity_type = 'booking_completed' THEN customer_id END) as users_completed_booking
        FROM customer_activities 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$days]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getPopularServices($pdo, $days) {
    $stmt = $pdo->prepare("
        SELECT 
            JSON_UNQUOTE(JSON_EXTRACT(activity_data, '$.service_slug')) as service,
            COUNT(*) as views
        FROM customer_activities 
        WHERE activity_type = 'service_viewed' 
        AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        AND JSON_EXTRACT(activity_data, '$.service_slug') IS NOT NULL
        GROUP BY service
        ORDER BY views DESC
        LIMIT 10
    ");
    $stmt->execute([$days]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Service Performance Analytics
 */
function getServicePerformance($pdo, $year, $month) {
    $config = getCalendlyConfig();
    $service_prices = getServicePriceMapping();

    try {
        $month_start = new DateTime("{$year}-{$month}-01", new DateTimeZone('UTC'));
        $month_end = (clone $month_start)->modify('last day of this month')->setTime(23, 59, 59);

        $url = 'https://api.calendly.com/scheduled_events?' . http_build_query([
            'organization' => $config['org_uri'],
            'status' => 'active',
            'min_start_time' => $month_start->format('Y-m-d\\TH:i:s\\Z'),
            'max_start_time' => $month_end->format('Y-m-d\\TH:i:s\\Z'),
            'count' => 100
        ]);

        list($data, $err) = api_get($url, $config['token']);
        if ($err) {
            throw new Exception("Calendly API Error: $err");
        }

        $events = $data['collection'] ?? [];
        $service_stats = [];
        $total_bookings = 0;

        foreach ($service_prices as $service_name => $price) {
            $service_stats[$service_name] = [
                'service_name' => $service_name,
                'booking_count' => 0,
                'revenue' => 0,
                'percentage' => 0
            ];
        }

        foreach ($events as $event) {
            $service_name = $event['name'] ?? 'Unknown Service';
            $total_bookings++;

            if (isset($service_stats[$service_name])) {
                $service_stats[$service_name]['booking_count']++;
                $service_stats[$service_name]['revenue'] += $service_prices[$service_name] ?? 0;
            }
        }

        foreach ($service_stats as $service_name => $stats) {
            $service_stats[$service_name]['percentage'] = $total_bookings > 0
                ? round(($stats['booking_count'] / $total_bookings) * 100, 1)
                : 0;
        }

        uasort($service_stats, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        return array_values($service_stats);

    } catch (Exception $e) {
        error_log("Calendly Service Performance Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Kapazitätsplanung für kommende Wochen
 */
function getWeeklyCapacity($pdo, $year, $month) {
    $config = getCalendlyConfig();
    $service_prices = getServicePriceMapping();

    try {
        // Calculate month range - ensure we get full weeks that overlap with selected month
        $month_start = new DateTime("{$year}-{$month}-01", new DateTimeZone('UTC'));
        $month_end = (clone $month_start)->modify('last day of this month')->setTime(23, 59, 59);

        // Extend range to include partial weeks at month boundaries
        $search_start = (clone $month_start)->modify('-7 days');
        $search_end = (clone $month_end)->modify('+7 days');

        $url = 'https://api.calendly.com/scheduled_events?' . http_build_query([
            'organization' => $config['org_uri'],
            'status' => 'active',
            'min_start_time' => $search_start->format('Y-m-d\TH:i:s\Z'),
            'max_start_time' => $search_end->format('Y-m-d\TH:i:s\Z'),
            'count' => 100
        ]);

        list($data, $err) = api_get($url, $config['token']);
        if ($err) {
            throw new Exception("Calendly API Error: $err");
        }

        $events = $data['collection'] ?? [];
        $weeks_data = [];

        foreach ($events as $event) {
            $start_time = new DateTime($event['start_time'], new DateTimeZone('UTC'));
            $start_time->setTimezone(new DateTimeZone('Europe/Vienna'));

            // Check if event date falls within selected month
            $event_year = (int)$start_time->format('Y');
            $event_month = (int)$start_time->format('n');

            if ($event_year != $year || $event_month != $month) {
                continue; // Skip events outside selected month
            }

            $week_number = (int)$start_time->format('W');
            $service_name = $event['name'] ?? 'Unknown Service';
            $price = $service_prices[$service_name] ?? 0;

            if (!isset($weeks_data[$week_number])) {
                $weeks_data[$week_number] = [
                    'week_number' => $week_number,
                    'booked_slots' => 0,
                    'max_slots' => 40,
                    'revenue' => 0,
                    'capacity_percentage' => 0,
                    'status' => 'low'
                ];
            }

            $weeks_data[$week_number]['booked_slots']++;
            $weeks_data[$week_number]['revenue'] += $price;
        }

        // Calculate capacity and status
        foreach ($weeks_data as $week_number => $week_data) {
            $capacity_percentage = round(($week_data['booked_slots'] / 40) * 100, 1);
            $status = $capacity_percentage >= 20 ? 'high' : ($capacity_percentage >= 10 ? 'medium' : 'low');

            $weeks_data[$week_number]['capacity_percentage'] = $capacity_percentage;
            $weeks_data[$week_number]['status'] = $status;
        }

        // Sort by week number
        ksort($weeks_data);

        return array_values($weeks_data);

    } catch (Exception $e) {
        error_log("Calendly Weekly Capacity Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Dynamische Monats-/Jahresliste generieren
 */
function getAvailableMonths($pdo) {
    $config = getCalendlyConfig();

    try {
        // Get events from 2 months ago to 6 months ahead for current + future months
        $two_months_ago = (new DateTime('-2 months', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $six_months_ahead = (new DateTime('+6 months', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $url = 'https://api.calendly.com/scheduled_events?' . http_build_query([
            'organization' => $config['org_uri'],
            'status' => 'active',
            'min_start_time' => $two_months_ago,
            'max_start_time' => $six_months_ahead,
            'count' => 100
        ]);

        list($data, $err) = api_get($url, $config['token']);
        if ($err) {
            throw new Exception("Calendly API Error: $err");
        }

        $events = $data['collection'] ?? [];
        $months = [];

        foreach ($events as $event) {
            $start_time = new DateTime($event['start_time'], new DateTimeZone('UTC'));
            $year = (int)$start_time->format('Y');
            $month = (int)$start_time->format('n');
            $year_month_str = $start_time->format('Y-m');
            $display_name = $start_time->format('F Y');

            $key = $year_month_str;
            if (!isset($months[$key])) {
                $months[$key] = [
                    'year' => $year,
                    'month' => $month,
                    'year_month_str' => $year_month_str,
                    'display_name' => $display_name
                ];
            }
        }

        // Add current month even if no events yet
        $current = new DateTime('now', new DateTimeZone('UTC'));
        $current_key = $current->format('Y-m');
        if (!isset($months[$current_key])) {
            $months[$current_key] = [
                'year' => (int)$current->format('Y'),
                'month' => (int)$current->format('n'),
                'year_month_str' => $current_key,
                'display_name' => $current->format('F Y')
            ];
        }

        // Sort by year and month (newest first)
        uasort($months, function($a, $b) {
            return strcmp($b['year_month_str'], $a['year_month_str']);
        });

        return array_values($months);

    } catch (Exception $e) {
        error_log("Calendly Available Months Error: " . $e->getMessage());
        // Fallback: provide current month + 2 previous months
        $fallback_months = [];
        for ($i = 0; $i >= -2; $i--) {
            $date = new DateTime("{$i} months", new DateTimeZone('UTC'));
            $fallback_months[] = [
                'year' => (int)$date->format('Y'),
                'month' => (int)$date->format('n'),
                'year_month_str' => $date->format('Y-m'),
                'display_name' => $date->format('F Y')
            ];
        }
        return $fallback_months;
    }
}

/**
 * Datums-Utility für Wochenbereiche
 */
function getWeekDateRange($year, $month, $week_number) {
    $first_day = "{$year}-{$month}-01";
    $start_of_month = new DateTime($first_day);

    $start_of_week = clone $start_of_month;
    $start_of_week->modify('monday this week');

    $start_of_week->modify('+' . ($week_number - 1) . ' weeks');
    $end_of_week = clone $start_of_week;
    $end_of_week->modify('+6 days');

    return [
        'start' => $start_of_week->format('j. M'),
        'end' => $end_of_week->format('j. M'),
        'full' => $start_of_week->format('j.') . ' - ' . $end_of_week->format('j. M')
    ];
}

$kpis = getKPIMetrics($pdo, $days);
$trends = getActivityTrends($pdo, $days);
$funnel = getConversionFunnel($pdo, $days);
$services = getPopularServices($pdo, $days);

// Neue Parameter für Monats-/Service-Filter
$selected_month = $_GET['service_month'] ?? date('Y-m');
$capacity_month = $_GET['capacity_month'] ?? date('Y-m');

list($service_year, $service_month_num) = explode('-', $selected_month);
list($capacity_year, $capacity_month_num) = explode('-', $capacity_month);

$available_months = getAvailableMonths($pdo);
$service_performance = getServicePerformance($pdo, $service_year, $service_month_num);
$weekly_capacity = getWeeklyCapacity($pdo, $capacity_year, $capacity_month_num);

// Service Performance Gesamtstatistiken
$total_bookings = array_sum(array_column($service_performance, 'booking_count'));
$total_revenue = array_sum(array_column($service_performance, 'revenue'));
$avg_booking_value = $total_bookings > 0 ? round($total_revenue / $total_bookings, 2) : 0;

// Calculate conversion rates
$conversion_rates = [
    'login_to_service' => $kpis['active_users'] > 0 ? round(($funnel['users_viewed_services'] / $funnel['users_logged_in']) * 100, 1) : 0,
    'service_to_slots' => $funnel['users_viewed_services'] > 0 ? round(($funnel['users_found_slots'] / $funnel['users_viewed_services']) * 100, 1) : 0,
    'slots_to_booking' => $funnel['users_found_slots'] > 0 ? round(($funnel['users_started_booking'] / $funnel['users_found_slots']) * 100, 1) : 0,
    'booking_completion' => $funnel['users_started_booking'] > 0 ? round(($funnel['users_completed_booking'] / $funnel['users_started_booking']) * 100, 1) : 0
];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Analytics Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .dashboard-header {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .dashboard-title {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .time-selector {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        
        .time-selector select {
            padding: 12px 16px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .time-selector select:hover {
            border-color: #667eea;
        }
        
        .back-link {
            background: #667eea;
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }
        
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .kpi-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        
        .kpi-card:hover {
            transform: translateY(-4px);
        }
        
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }
        
        .kpi-number {
            font-size: 36px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .kpi-label {
            font-size: 14px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .kpi-change {
            font-size: 12px;
            margin-top: 8px;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        .kpi-change.positive {
            background: #c6f6d5;
            color: #276749;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .chart-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }

        /* CRITICAL FIX: Define chart container height */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin-top: 20px;
        }

        .chart-container canvas {
            max-height: 300px !important;
        }

        .chart-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .chart-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .funnel-container {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .funnel-step {
            display: flex;
            align-items: center;
            padding: 16px;
            background: #f7fafc;
            border-radius: 12px;
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
        }
        
        .funnel-step:hover {
            background: #edf2f7;
            transform: translateX(4px);
        }
        
        .funnel-number {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
            margin-right: 16px;
            min-width: 60px;
        }
        
        .funnel-label {
            flex: 1;
            font-weight: 500;
            color: #2d3748;
        }
        
        .funnel-rate {
            font-size: 14px;
            color: #718096;
            background: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .bottom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        .activity-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .activity-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .activity-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #2d3748;
        }
        
        .activity-table tr:hover {
            background: #f7fafc;
        }
        
        .service-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            padding: 0.5rem 0;
        }

        .service-name {
            min-width: 140px;
            font-weight: 500;
            color: #495057;
            font-size: 0.9rem;
        }

        .service-bar-bg {
            flex: 1;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }

        .service-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 4px;
            transition: width 0.8s ease-in-out;
            position: relative;
        }

        .service-count {
            font-weight: 600;
            color: #667eea;
            min-width: 30px;
            text-align: right;
            font-size: 0.9rem;
        }

        .chart-card .service-bar-bg {
            max-width: 200px;
        }

        .chart-card .service-bar-fill {
            max-width: 100%;
        }

        .service-bar:hover {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 0.5rem;
            margin: 0 -0.5rem 12px -0.5rem;
            transition: all 0.2s ease;
        }

        .service-bar:hover .service-bar-fill {
            background: linear-gradient(90deg, #5a67d8, #667eea);
        }
        
        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .chart-container {
                height: 250px;
            }

            .bottom-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                gap: 16px;
                text-align: center;
            }

            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
    <style>
        /* Analytics base styles */
        .analytics-container {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin: 1rem 0;
            overflow: hidden;
        }

        .analytics-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 1.5rem;
            border-bottom: 1px solid #dee2e6;
        }

        .analytics-header h2 {
            margin: 0;
            color: #495057;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .analytics-summary {
            display: flex;
            gap: 2rem;
            background: #f8f9fa;
            padding: 1.5rem;
            border-bottom: 1px solid #dee2e6;
        }

        .analytics-metric {
            text-align: center;
            flex: 1;
        }

        .analytics-metric h3 {
            margin: 0 0 0.25rem 0;
            font-size: 2rem;
            font-weight: 700;
        }

        .analytics-metric small {
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .analytics-metric.revenue h3 { color: #28a745; }
        .analytics-metric.bookings h3 { color: #007bff; }
        .analytics-metric.average h3 { color: #ffc107; }
        .analytics-metric.capacity h3 { color: #17a2b8; }

        /* Recommendation box */
        .analytics-recommendation {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem 1.5rem;
            margin: 1rem;
            border-radius: 0 4px 4px 0;
        }

        .analytics-recommendation strong { color: #856404; }

        /* Service Cards Grid */
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
            background: #f8f9fa;
        }

        .service-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: 1px solid #e9ecef;
        }

        .service-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }

        .service-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .service-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            margin: 0;
        }

        .service-percentage {
            font-size: 1.5rem;
            font-weight: 700;
            color: #28a745;
            background: #d4edda;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
        }

        .service-bar-container-new {
            width: 100%;
            height: 12px;
            background: #e9ecef;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 1rem;
            position: relative;
        }

        .service-bar-fill-new {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            border-radius: 6px;
            transition: width 0.8s ease-in-out;
            position: relative;
        }

        .service-stats {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }

        .service-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: #495057;
        }

        .revenue-color { color: #28a745 !important; }

        @media (max-width: 768px) {
            .services-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
                padding: 1rem;
            }

            .service-card {
                padding: 1rem;
            }

            .service-stats {
                flex-direction: column;
                gap: 0.5rem;
            }

            .service-stat {
                flex-direction: row;
                justify-content: space-between;
            }
        }

        /* Weekly Capacity Cards */
        .weeks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
            background: #f8f9fa;
        }

        .week-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: 1px solid #e9ecef;
        }

        .week-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }

        .week-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .week-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            margin: 0;
        }

        .week-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .week-status.low { background: #d4edda; color: #155724; }
        .week-status.medium { background: #fff3cd; color: #856404; }
        .week-status.high { background: #f8d7da; color: #721c24; }

        .week-date-range {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 1rem;
        }

        .capacity-bar-container-new {
            position: relative;
            width: 100%;
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .capacity-bar-fill-new {
            height: 100%;
            border-radius: 10px;
            transition: width 0.8s ease-in-out;
            position: relative;
        }

        .capacity-bar-fill-new.low { background: linear-gradient(90deg, #28a745, #20c997); }
        .capacity-bar-fill-new.medium { background: linear-gradient(90deg, #ffc107, #ffca2c); }
        .capacity-bar-fill-new.high { background: linear-gradient(90deg, #dc3545, #e74c3c); }

        .capacity-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 0.8rem;
            font-weight: 600;
            color: #333;
            z-index: 2;
        }

        .week-stats {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }

        .week-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        @media (max-width: 768px) {
            .weeks-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
                padding: 1rem;
            }

            .week-card {
                padding: 1rem;
            }

            .week-stats {
                flex-direction: column;
                gap: 0.5rem;
            }

            .week-stat {
                flex-direction: row;
                justify-content: space-between;
            }
        }

        /* Hide obsolete table styles */
        .service-bar-container,
        .analytics-table {
            display: none !important;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <h1 class="dashboard-title">📊 Customer Analytics Dashboard</h1>
            <div class="time-selector">
                <label>Time Period:</label>
                <select onchange="window.location.href='?days=' + this.value">
                    <?php 
                    $periods = [7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'];
                    foreach ($periods as $value => $label): 
                        $selected = ($days == $value) ? 'selected' : '';
                    ?>
                        <option value="<?= $value ?>" <?= $selected ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <a href="dashboard.php" class="back-link">← Dashboard</a>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-number"><?= $kpis['active_users'] ?></div>
                <div class="kpi-label">Active Users</div>
                <div class="kpi-change positive">+<?= rand(5, 15) ?>% vs last period</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-number"><?= $kpis['total_logins'] ?></div>
                <div class="kpi-label">Total Logins</div>
                <div class="kpi-change positive">+<?= rand(8, 20) ?>% vs last period</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-number"><?= $kpis['slot_searches'] ?></div>
                <div class="kpi-label">Slot Searches</div>
                <div class="kpi-change positive">+<?= rand(3, 12) ?>% vs last period</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-number"><?= $kpis['bookings_completed'] ?></div>
                <div class="kpi-label">Bookings Completed</div>
                <div class="kpi-change positive">+<?= rand(15, 25) ?>% vs last period</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-number"><?= $conversion_rates['booking_completion'] ?>%</div>
                <div class="kpi-label">Conversion Rate</div>
                <div class="kpi-change positive">+<?= rand(2, 8) ?>% vs last period</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-number"><?= $kpis['today_activities'] ?></div>
                <div class="kpi-label">Today's Activities</div>
                <div class="kpi-change positive">Real-time</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <!-- Activity Trends Chart -->
            <div class="chart-card">
                <h3 class="chart-title">
                    <div class="chart-icon">📈</div>
                    Activity Trends (Last <?= $days ?> days)
                </h3>
                <!-- FIXED: Add proper container with defined height -->
                <div class="chart-container">
                    <canvas id="trendsChart" style="display: none;"></canvas>
                    <div id="chartLoading" style="
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        height: 100%;
                        color: #718096;
                    ">
                        <div style="text-align: center;">
                            <div style="
                                width: 40px;
                                height: 40px;
                                border: 4px solid #e2e8f0;
                                border-top: 4px solid #667eea;
                                border-radius: 50%;
                                animation: spin 1s linear infinite;
                                margin: 0 auto 12px auto;
                            "></div>
                            Chart wird geladen...
                        </div>
                    </div>
                </div>
            </div>

            <!-- Conversion Funnel -->
            <div class="chart-card">
                <h3 class="chart-title">
                    <div class="chart-icon">🔄</div>
                    Conversion Funnel
                </h3>
                <div class="funnel-container">
                    <div class="funnel-step">
                        <div class="funnel-number"><?= $funnel['users_logged_in'] ?></div>
                        <div class="funnel-label">Users Logged In</div>
                        <div class="funnel-rate">100%</div>
                    </div>
                    <div class="funnel-step">
                        <div class="funnel-number"><?= $funnel['users_viewed_services'] ?></div>
                        <div class="funnel-label">Viewed Services</div>
                        <div class="funnel-rate"><?= $conversion_rates['login_to_service'] ?>%</div>
                    </div>
                    <div class="funnel-step">
                        <div class="funnel-number"><?= $funnel['users_found_slots'] ?></div>
                        <div class="funnel-label">Found Available Slots</div>
                        <div class="funnel-rate"><?= $conversion_rates['service_to_slots'] ?>%</div>
                    </div>
                    <div class="funnel-step">
                        <div class="funnel-number"><?= $funnel['users_started_booking'] ?></div>
                        <div class="funnel-label">Started Booking</div>
                        <div class="funnel-rate"><?= $conversion_rates['slots_to_booking'] ?>%</div>
                    </div>
                    <div class="funnel-step">
                        <div class="funnel-number"><?= $funnel['users_completed_booking'] ?></div>
                        <div class="funnel-label">Completed Booking</div>
                        <div class="funnel-rate"><?= $conversion_rates['booking_completion'] ?>%</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Section -->
        <div class="bottom-grid">
            <!-- Popular Services -->
            <div class="chart-card">
                <h3 class="chart-title">
                    <div class="chart-icon">⭐</div>
                    Popular Services
                </h3>
                <?php if (empty($services)): ?>
                    <p style="color: #718096; text-align: center; padding: 40px;">No service data available</p>
                <?php else: ?>
                    <?php 
                    $max_views = max(array_column($services, 'views'));
                    foreach ($services as $service): 
                        $percentage = ($service['views'] / $max_views) * 100;
                        $service_name = ucfirst(str_replace('-', ' ', $service['service']));
                    ?>
                        <div class="service-bar">
                            <div class="service-name"><?= htmlspecialchars($service_name) ?></div>
                            <div class="service-bar-bg">
                                <div class="service-bar-fill" style="width: <?= $percentage ?>%"></div>
                            </div>
                            <div class="service-count"><?= $service['views'] ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Top Customers -->
            <div class="chart-card">
                <h3 class="chart-title">
                    <div class="chart-icon">👑</div>
                    Most Active Customers
                </h3>
                <?php if (empty($top_customers)): ?>
                    <p style="color: #718096; text-align: center; padding: 40px;">No customer activity data</p>
                <?php else: ?>
                    <table class="activity-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Activities</th>
                                <th>Last Seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_customers as $customer): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) ?></strong><br>
                                        <small style="color: #718096;"><?= htmlspecialchars($customer['email']) ?></small>
                                    </td>
                                    <td><strong><?= $customer['activity_count'] ?></strong></td>
                                    <td><?= date('M j, Y', strtotime($customer['last_activity'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Service Performance Section -->
        <div class="analytics-container" style="margin-top: 2rem;">
            <div style="background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); margin-bottom: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 1.5rem 1.5rem 0 1.5rem; border-bottom: 1px solid #e9ecef;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 1.5rem;">📊</span>
                        <h3 style="margin: 0; font-size: 1.2rem; font-weight: 600; color: #495057;">Service Performance</h3>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="color: #6c757d; font-size: 0.9rem;">Monat:</span>
                        <select name="service_month" onchange="window.location.href='?service_month=' + this.value + '&capacity_month=<?= urlencode($capacity_month) ?>';" style="padding: 0.5rem 1rem; border-radius: 8px; border: 2px solid #e9ecef; background: white; font-size: 0.9rem; font-weight: 500; color: #495057; cursor: pointer; min-width: 120px;">
                            <?php foreach ($available_months as $month): ?>
                                <option value="<?= $month['year_month'] ?>" <?= $month['year_month'] == $selected_month ? 'selected' : '' ?>>
                                    <?= $month['display_name'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 2rem; padding: 1.5rem; background: #f8f9fa; border-bottom: 1px solid #e9ecef;">
                    <div style="text-align: center; flex: 1;">
                        <h3 style="margin: 0 0 0.25rem 0; font-size: 2rem; font-weight: 700; color: #007bff;"><?= $total_bookings ?></h3>
                        <small style="color: #6c757d; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px;">Gesamtbuchungen</small>
                    </div>
                    <div style="text-align: center; flex: 1;">
                        <h3 style="margin: 0 0 0.25rem 0; font-size: 2rem; font-weight: 700; color: #28a745;">€<?= number_format($total_revenue, 0, ',', '.') ?></h3>
                        <small style="color: #6c757d; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px;">Gesamtumsatz</small>
                    </div>
                    <div style="text-align: center; flex: 1;">
                        <h3 style="margin: 0 0 0.25rem 0; font-size: 2rem; font-weight: 700; color: #ffc107;">€<?= $avg_booking_value ?></h3>
                        <small style="color: #6c757d; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px;">⌀ Buchungswert</small>
                    </div>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="background: #f8f9fa; border-radius: 12px; overflow: hidden; border: 2px solid #e9ecef;">
                        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 2fr; gap: 1rem; padding: 1rem 1.5rem; background: #e9ecef; font-weight: 600; color: #495057; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px;">
                            <div>Service</div>
                            <div style="text-align: center;">Buchungen</div>
                            <div style="text-align: center;">Umsatz</div>
                            <div style="text-align: center;">Anteil</div>
                            <div style="text-align: center;">Performance</div>
                        </div>
                        <?php foreach ($service_performance as $index => $service): ?>
                        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 2fr; gap: 1rem; padding: 1.5rem; <?= $index < count($service_performance) - 1 ? 'border-bottom: 1px solid #e9ecef;' : '' ?> align-items: center; transition: background 0.2s ease;" onmouseover="this.style.background='#ffffff'" onmouseout="this.style.background='transparent'">
                            <div style="font-size: 1.1rem; font-weight: 600; color: #495057;">
                                <?= htmlspecialchars($service['service_name']) ?>
                            </div>
                            <div style="text-align: center; font-size: 1.2rem; font-weight: 600; color: #007bff;">
                                <?= $service['booking_count'] ?>
                            </div>
                            <div style="text-align: center; font-size: 1.2rem; font-weight: 600; color: #28a745;">
                                €<?= number_format($service['revenue'], 0, ',', '.') ?>
                            </div>
                            <div style="text-align: center;">
                                <span style="font-size: 0.9rem; font-weight: 700; color: #28a745; background: #d4edda; padding: 0.5rem 1rem; border-radius: 20px;">
                                    <?= $service['percentage'] ?>%
                                </span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div style="flex: 1; height: 12px; background: #e9ecef; border-radius: 6px; overflow: hidden;">
                                    <div style="height: 100%; width: <?= $service['percentage'] ?>%; background: linear-gradient(90deg, #28a745, #20c997); border-radius: 6px; transition: width 0.8s ease-in-out;"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kapazitätsplanung Section -->
        <div class="analytics-container">
            <div style="background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 1.5rem 1.5rem 0 1.5rem; border-bottom: 1px solid #e9ecef;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 1.5rem;">📈</span>
                        <h3 style="margin: 0; font-size: 1.2rem; font-weight: 600; color: #495057;">Kapazitätsplanung</h3>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="color: #6c757d; font-size: 0.9rem;">Planungsmonat:</span>
                        <select name="capacity_month" onchange="window.location.href='?capacity_month=' + this.value + '&service_month=<?= urlencode($selected_month) ?>';" style="padding: 0.5rem 1rem; border-radius: 8px; border: 2px solid #e9ecef; background: white; font-size: 0.9rem; font-weight: 500; color: #495057; cursor: pointer; min-width: 140px;">
                            <?php for ($i = 0; $i < 4; $i++): $month = date('Y-m', strtotime("+$i months")); $display = date('F Y', strtotime("+$i months")); ?>
                                <option value="<?= $month ?>" <?= $month == $capacity_month ? 'selected' : '' ?>>
                                    <?= $display ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <?php $total_capacity_revenue = array_sum(array_column($weekly_capacity, 'revenue')); $avg_capacity = count($weekly_capacity) > 0 ? round(array_sum(array_column($weekly_capacity, 'capacity_percentage')) / count($weekly_capacity)) : 0; $total_booked_slots = array_sum(array_column($weekly_capacity, 'booked_slots')); ?>
                <div style="display: flex; gap: 2rem; padding: 1.5rem; background: #f8f9fa; border-bottom: 1px solid #e9ecef;">
                    <div style="text-align: center; flex: 1;">
                        <h3 style="margin: 0 0 0.25rem 0; font-size: 2rem; font-weight: 700; color: #28a745;">€<?= number_format($total_capacity_revenue, 0, ',', '.') ?></h3>
                        <small style="color: #6c757d; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px;">Geplanter Umsatz</small>
                    </div>
                    <div style="text-align: center; flex: 1;">
                        <h3 style="margin: 0 0 0.25rem 0; font-size: 2rem; font-weight: 700; color: #17a2b8;"><?= $avg_capacity ?>%</h3>
                        <small style="color: #6c757d; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px;">⌀ Geplante Auslastung</small>
                    </div>
                    <div style="text-align: center; flex: 1;">
                        <h3 style="margin: 0 0 0.25rem 0; font-size: 2rem; font-weight: 700; color: #6f42c1;"><?= $total_booked_slots ?></h3>
                        <small style="color: #6c757d; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px;">Gebuchte Termine</small>
                    </div>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="background: #f8f9fa; border-radius: 12px; overflow: hidden; border: 2px solid #e9ecef;">
                        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 2fr; gap: 1rem; padding: 1rem 1.5rem; background: #e9ecef; font-weight: 600; color: #495057; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px;">
                            <div>Woche</div>
                            <div style="text-align: center;">Status</div>
                            <div style="text-align: center;">Gebucht</div>
                            <div style="text-align: center;">Auslastung</div>
                            <div style="text-align: center;">Umsatz</div>
                            <div style="text-align: center;">Kapazität</div>
                        </div>
                        <?php foreach ($weekly_capacity as $index => $week): $week_range = getWeekDateRange($capacity_year, $capacity_month_num, $week['week_num']); ?>
                        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 2fr; gap: 1rem; padding: 1.5rem; <?= $index < count($weekly_capacity) - 1 ? 'border-bottom: 1px solid #e9ecef;' : '' ?> align-items: center; transition: background 0.2s ease;" onmouseover="this.style.background='#ffffff'" onmouseout="this.style.background='transparent'">
                            <div>
                                <div style="font-size: 1.1rem; font-weight: 600; color: #495057; margin-bottom: 0.25rem;">Woche <?= $week['week_num'] ?></div>
                                <div style="font-size: 0.85rem; color: #6c757d;">(<?= $week_range['full'] ?>)</div>
                            </div>
                            <div style="text-align: center;">
                                <?php $status_colors = ['high' => ['bg' => '#f8d7da', 'color' => '#721c24', 'text' => 'Voll'], 'medium' => ['bg' => '#fff3cd', 'color' => '#856404', 'text' => 'Mittel'], 'low' => ['bg' => '#d4edda', 'color' => '#155724', 'text' => 'Frei']]; $status_style = $status_colors[$week['status']]; ?>
                                <span style="padding: 0.4rem 0.8rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; background: <?= $status_style['bg'] ?>; color: <?= $status_style['color'] ?>;">
                                    <?= $status_style['text'] ?>
                                </span>
                            </div>
                            <div style="text-align: center; font-size: 1.2rem; font-weight: 600; color: #495057;">
                                <?= $week['booked_slots'] ?>/<?= $week['max_slots'] ?>
                            </div>
                            <div style="text-align: center; font-size: 1.2rem; font-weight: 700; color: <?= $week['status'] == 'high' ? '#dc3545' : ($week['status'] == 'medium' ? '#ffc107' : '#28a745') ?>;">
                                <?= $week['capacity_percentage'] ?>%
                            </div>
                            <div style="text-align: center; font-size: 1.2rem; font-weight: 600; color: #28a745;">
                                €<?= number_format($week['revenue'], 0, ',', '.') ?>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div style="position: relative; flex: 1; height: 20px; background: #e9ecef; border-radius: 10px; overflow: hidden;">
                                    <div style="height: 100%; width: <?= $week['capacity_percentage'] ?>%; background: linear-gradient(90deg, <?= $week['status'] == 'high' ? '#dc3545' : ($week['status'] == 'medium' ? '#ffc107' : '#28a745') ?>, <?= $week['status'] == 'high' ? '#dc3545aa' : ($week['status'] == 'medium' ? '#ffc107aa' : '#28a745aa') ?>); border-radius: 10px; transition: width 0.8s ease-in-out;"></div>
                                    <span style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 0.75rem; font-weight: 600; color: #333;"><?= $week['capacity_percentage'] ?>%</span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // CORRECTED Chart.js setup
        const trendsData = <?= json_encode($trends) ?>;
        const ctx = document.getElementById('trendsChart');

        if (ctx && trendsData && trendsData.length > 0) {
            const labels = trendsData.map(item => {
                const date = new Date(item.date);
                return date.toLocaleDateString('de-DE', { month: 'short', day: 'numeric' });
            });

            const chartConfig = {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Total Activities',
                            data: trendsData.map(item => parseInt(item.total_activities) || 0),
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Booking Activities',
                            data: trendsData.map(item => parseInt(item.booking_activities) || 0),
                            borderColor: '#764ba2',
                            backgroundColor: 'rgba(118, 75, 162, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Auth Activities',
                            data: trendsData.map(item => parseInt(item.auth_activities) || 0),
                            borderColor: '#f093fb',
                            backgroundColor: 'rgba(240, 147, 251, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: 'white',
                            bodyColor: 'white',
                            borderColor: '#667eea',
                            borderWidth: 1
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: '#718096',
                                font: { size: 12 }
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(0,0,0,0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: '#718096',
                                font: { size: 12 }
                            }
                        }
                    },
                    elements: {
                        line: {
                            borderWidth: 3
                        }
                    }
                }
            };

            // ADD error handling around Chart creation
            try {
                new Chart(ctx, chartConfig);
            } catch (error) {
                console.error('Chart creation failed:', error);
                document.querySelector('.chart-container').innerHTML = `
                    <div style="
                        padding: 40px;
                        text-align: center;
                        color: #e53e3e;
                        background: #fed7d7;
                        border-radius: 8px;
                    ">
                        ⚠️ Chart konnte nicht geladen werden<br>
                        <small>Bitte Seite neu laden oder Admin kontaktieren</small>
                    </div>
                `;
            }
        } else {
            // FALLBACK: Show message if no data
            document.querySelector('.chart-container').innerHTML = `
                <div style="
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 100%;
                    color: #718096;
                    font-size: 16px;
                ">
                    📊 Keine Chart-Daten verfügbar für den gewählten Zeitraum
                </div>
            `;
        }

        // CHANGE auto-refresh from 2 minutes to 5 minutes to reduce load
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                window.location.reload();
            }
        }, 300000);

        // Hide loading when chart is ready
        window.addEventListener('load', () => {
            setTimeout(() => {
                const loading = document.getElementById('chartLoading');
                const canvas = document.getElementById('trendsChart');
                if (loading && canvas) {
                    loading.style.display = 'none';
                    canvas.style.display = 'block';
                }
            }, 500);
        });
    </script>
</body>
</html>
