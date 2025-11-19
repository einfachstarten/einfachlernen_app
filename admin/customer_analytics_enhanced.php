<?php
/**
 * Enhanced Customer Analytics Dashboard
 * Comprehensive analytics with advanced metrics, device tracking, cohort analysis, and export capabilities
 */
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

function getPDO() {
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

require_once 'ActivityLogger.php';

$pdo = getPDO();
$logger = new ActivityLogger($pdo);

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $days = $_GET['days'] ?? 30;
    $data = getExportData($pdo, $days);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=customer_analytics_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Customer Analytics Report - Last ' . $days . ' Days', '', '', '']);
    fputcsv($output, ['Generated', date('Y-m-d H:i:s'), '', '']);
    fputcsv($output, ['', '', '', '']);

    // KPIs Section
    fputcsv($output, ['KEY PERFORMANCE INDICATORS', '', '', '']);
    foreach ($data['kpis'] as $key => $value) {
        fputcsv($output, [ucwords(str_replace('_', ' ', $key)), $value, '', '']);
    }
    fputcsv($output, ['', '', '', '']);

    // Top Customers
    fputcsv($output, ['TOP CUSTOMERS', '', '', '']);
    fputcsv($output, ['Email', 'Name', 'Activities', 'Last Active']);
    foreach ($data['top_customers'] as $customer) {
        fputcsv($output, [
            $customer['email'],
            $customer['first_name'] . ' ' . $customer['last_name'],
            $customer['activity_count'],
            $customer['last_activity']
        ]);
    }

    fclose($output);
    exit;
}

function getExportData($pdo, $days) {
    $kpis = getKPIMetrics($pdo, $days);
    $logger = new ActivityLogger($pdo);
    $top_customers = $logger->getTopActiveCustomers($days, 20);

    return [
        'kpis' => $kpis,
        'top_customers' => $top_customers
    ];
}

// Parse User Agent to get Device & Browser Info
function parseUserAgent($user_agent) {
    $device = 'Desktop';
    $browser = 'Unknown';
    $os = 'Unknown';

    // Detect Device
    if (preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile/i', $user_agent)) {
        if (preg_match('/iPad/i', $user_agent)) {
            $device = 'Tablet';
        } else {
            $device = 'Mobile';
        }
    } elseif (preg_match('/Tablet/i', $user_agent)) {
        $device = 'Tablet';
    }

    // Detect Browser
    if (preg_match('/Firefox\/([\d.]+)/i', $user_agent, $matches)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Chrome\/([\d.]+)/i', $user_agent, $matches)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Safari\/([\d.]+)/i', $user_agent, $matches) && !preg_match('/Chrome/i', $user_agent)) {
        $browser = 'Safari';
    } elseif (preg_match('/Edge\/([\d.]+)/i', $user_agent, $matches)) {
        $browser = 'Edge';
    } elseif (preg_match('/MSIE|Trident/i', $user_agent)) {
        $browser = 'IE';
    } elseif (preg_match('/Opera|OPR/i', $user_agent)) {
        $browser = 'Opera';
    }

    // Detect OS
    if (preg_match('/Windows/i', $user_agent)) {
        $os = 'Windows';
    } elseif (preg_match('/Mac OS X/i', $user_agent)) {
        $os = 'macOS';
    } elseif (preg_match('/Linux/i', $user_agent)) {
        $os = 'Linux';
    } elseif (preg_match('/Android/i', $user_agent)) {
        $os = 'Android';
    } elseif (preg_match('/iOS|iPhone|iPad/i', $user_agent)) {
        $os = 'iOS';
    }

    return ['device' => $device, 'browser' => $browser, 'os' => $os];
}

// Enhanced KPI Metrics
function getKPIMetrics($pdo, $days) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(DISTINCT customer_id) as active_users,
                COUNT(DISTINCT CASE WHEN activity_type = 'login' THEN customer_id END) as logged_in_users,
                COUNT(CASE WHEN activity_type = 'login' THEN 1 END) as total_logins,
                COUNT(CASE WHEN activity_type = 'slots_found' THEN 1 END) as successful_searches,
                COUNT(CASE WHEN activity_type = 'slot_search_failed' THEN 1 END) as failed_searches,
                COUNT(CASE WHEN activity_type = 'booking_initiated' THEN 1 END) as booking_attempts,
                COUNT(CASE WHEN activity_type = 'booking_completed' THEN 1 END) as bookings_completed,
                COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_activities,
                COUNT(*) as total_activities
            FROM customer_activities
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        // Calculate conversion rate
        $data['conversion_rate'] = $data['booking_attempts'] > 0
            ? round(($data['bookings_completed'] / $data['booking_attempts']) * 100, 1)
            : 0;

        // Calculate search success rate
        $data['search_success_rate'] = ($data['successful_searches'] + $data['failed_searches']) > 0
            ? round(($data['successful_searches'] / ($data['successful_searches'] + $data['failed_searches'])) * 100, 1)
            : 0;

        return $data;
    } catch (Exception $e) {
        return [];
    }
}

// Device & Browser Statistics
function getDeviceStats($pdo, $days) {
    try {
        $stmt = $pdo->prepare("
            SELECT user_agent, COUNT(*) as count
            FROM customer_activities
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND user_agent IS NOT NULL
            AND user_agent != 'unknown'
            GROUP BY user_agent
        ");
        $stmt->execute([$days]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = [
            'devices' => ['Mobile' => 0, 'Desktop' => 0, 'Tablet' => 0],
            'browsers' => [],
            'os' => []
        ];

        foreach ($data as $row) {
            $parsed = parseUserAgent($row['user_agent']);
            $stats['devices'][$parsed['device']] += $row['count'];

            if (!isset($stats['browsers'][$parsed['browser']])) {
                $stats['browsers'][$parsed['browser']] = 0;
            }
            $stats['browsers'][$parsed['browser']] += $row['count'];

            if (!isset($stats['os'][$parsed['os']])) {
                $stats['os'][$parsed['os']] = 0;
            }
            $stats['os'][$parsed['os']] += $row['count'];
        }

        // Sort by count
        arsort($stats['browsers']);
        arsort($stats['os']);

        return $stats;
    } catch (Exception $e) {
        return ['devices' => [], 'browsers' => [], 'os' => []];
    }
}

// Retention Analysis
function getRetentionMetrics($pdo, $days) {
    try {
        // Get customers who logged in
        $stmt = $pdo->prepare("
            SELECT
                customer_id,
                MIN(DATE(created_at)) as first_login,
                MAX(DATE(created_at)) as last_login,
                COUNT(DISTINCT DATE(created_at)) as active_days
            FROM customer_activities
            WHERE activity_type = 'login'
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY customer_id
        ");
        $stmt->execute([$days]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $new_users = 0;
        $returning_users = 0;
        $total_active_days = 0;

        foreach ($data as $customer) {
            $total_active_days += $customer['active_days'];
            if ($customer['active_days'] == 1) {
                $new_users++;
            } else {
                $returning_users++;
            }
        }

        $total_users = count($data);
        $avg_active_days = $total_users > 0 ? round($total_active_days / $total_users, 1) : 0;
        $retention_rate = $total_users > 0 ? round(($returning_users / $total_users) * 100, 1) : 0;

        return [
            'new_users' => $new_users,
            'returning_users' => $returning_users,
            'retention_rate' => $retention_rate,
            'avg_active_days' => $avg_active_days
        ];
    } catch (Exception $e) {
        return ['new_users' => 0, 'returning_users' => 0, 'retention_rate' => 0, 'avg_active_days' => 0];
    }
}

// Peak Usage Times
function getPeakUsageTimes($pdo, $days) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                HOUR(created_at) as hour,
                COUNT(*) as activity_count
            FROM customer_activities
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY HOUR(created_at)
            ORDER BY hour
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// Day of Week Analysis
function getDayOfWeekStats($pdo, $days) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                DAYOFWEEK(created_at) as day_num,
                COUNT(*) as activity_count,
                COUNT(DISTINCT customer_id) as unique_users
            FROM customer_activities
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DAYOFWEEK(created_at)
            ORDER BY day_num
        ");
        $stmt->execute([$days]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $days_map = [1 => 'Sonntag', 2 => 'Montag', 3 => 'Dienstag', 4 => 'Mittwoch', 5 => 'Donnerstag', 6 => 'Freitag', 7 => 'Samstag'];

        foreach ($data as &$row) {
            $row['day_name'] = $days_map[$row['day_num']] ?? 'Unknown';
        }

        return $data;
    } catch (Exception $e) {
        return [];
    }
}

// Customer Segmentation
function getCustomerSegmentation($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT
                c.id,
                c.email,
                c.first_name,
                c.last_name,
                COUNT(ca.id) as total_activities,
                MAX(ca.created_at) as last_activity,
                MIN(ca.created_at) as first_activity,
                COUNT(DISTINCT CASE WHEN ca.activity_type = 'booking_completed' THEN ca.id END) as bookings_count
            FROM customers c
            LEFT JOIN customer_activities ca ON c.id = ca.customer_id
            GROUP BY c.id
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $segments = [
            'vip' => [], // 5+ bookings
            'active' => [], // Active in last 7 days
            'at_risk' => [], // No activity in 30-60 days
            'churned' => [] // No activity in 60+ days
        ];

        foreach ($data as $customer) {
            // VIP Customers
            if ($customer['bookings_count'] >= 5) {
                $segments['vip'][] = $customer;
            }

            // Check last activity
            if ($customer['last_activity']) {
                $days_since = (time() - strtotime($customer['last_activity'])) / 86400;

                if ($days_since <= 7) {
                    $segments['active'][] = $customer;
                } elseif ($days_since > 30 && $days_since <= 60) {
                    $segments['at_risk'][] = $customer;
                } elseif ($days_since > 60) {
                    $segments['churned'][] = $customer;
                }
            }
        }

        return [
            'vip' => count($segments['vip']),
            'active' => count($segments['active']),
            'at_risk' => count($segments['at_risk']),
            'churned' => count($segments['churned']),
            'details' => $segments
        ];
    } catch (Exception $e) {
        return ['vip' => 0, 'active' => 0, 'at_risk' => 0, 'churned' => 0, 'details' => []];
    }
}

// Booking Lead Time Analysis
function getBookingLeadTime($pdo, $days) {
    try {
        // Calculate average time from login to booking completion
        $stmt = $pdo->prepare("
            SELECT
                customer_id,
                MIN(CASE WHEN activity_type = 'login' THEN created_at END) as first_login,
                MIN(CASE WHEN activity_type = 'booking_completed' THEN created_at END) as first_booking
            FROM customer_activities
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND activity_type IN ('login', 'booking_completed')
            GROUP BY customer_id
            HAVING first_login IS NOT NULL AND first_booking IS NOT NULL
        ");
        $stmt->execute([$days]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_time = 0;
        $count = 0;

        foreach ($data as $row) {
            $time_diff = strtotime($row['first_booking']) - strtotime($row['first_login']);
            if ($time_diff > 0) {
                $total_time += $time_diff;
                $count++;
            }
        }

        $avg_seconds = $count > 0 ? $total_time / $count : 0;
        $avg_minutes = round($avg_seconds / 60, 1);

        return [
            'avg_minutes' => $avg_minutes,
            'conversions' => $count
        ];
    } catch (Exception $e) {
        return ['avg_minutes' => 0, 'conversions' => 0];
    }
}

// Service Performance with Calendly Integration
function getServicePerformance($pdo, $days) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                JSON_UNQUOTE(JSON_EXTRACT(activity_data, '$.service_slug')) as service,
                COUNT(*) as views,
                COUNT(DISTINCT customer_id) as unique_customers
            FROM customer_activities
            WHERE activity_type = 'service_viewed'
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND JSON_EXTRACT(activity_data, '$.service_slug') IS NOT NULL
            GROUP BY service
            ORDER BY views DESC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// Get Activity Trends
function getActivityTrends($pdo, $days) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                DATE(created_at) as date,
                COUNT(*) as total_activities,
                COUNT(DISTINCT customer_id) as unique_users,
                COUNT(CASE WHEN activity_type IN ('login', 'logout', 'pin_request') THEN 1 END) as auth_activities,
                COUNT(CASE WHEN activity_type IN ('slots_api_called', 'booking_initiated', 'booking_completed') THEN 1 END) as booking_activities
            FROM customer_activities
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// Conversion Funnel
function getConversionFunnel($pdo, $days) {
    try {
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
    } catch (Exception $e) {
        return [];
    }
}

// Load all data
$days = $_GET['days'] ?? 30;
$kpis = getKPIMetrics($pdo, $days);
$device_stats = getDeviceStats($pdo, $days);
$retention = getRetentionMetrics($pdo, $days);
$peak_times = getPeakUsageTimes($pdo, $days);
$day_stats = getDayOfWeekStats($pdo, $days);
$segmentation = getCustomerSegmentation($pdo);
$lead_time = getBookingLeadTime($pdo, $days);
$service_performance = getServicePerformance($pdo, $days);
$trends = getActivityTrends($pdo, $days);
$funnel = getConversionFunnel($pdo, $days);
$top_customers = $logger->getTopActiveCustomers($days, 10);

// Calculate conversion rates
$conversion_rates = [
    'login_to_service' => $funnel['users_logged_in'] > 0 ? round(($funnel['users_viewed_services'] / $funnel['users_logged_in']) * 100, 1) : 0,
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
    <title>Enhanced Customer Analytics</title>
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
            padding: 24px 32px;
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
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .title-content h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 4px;
        }

        .title-content p {
            color: #718096;
            font-size: 14px;
            font-weight: 500;
        }

        .header-controls {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .time-selector {
            padding: 12px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #2d3748;
        }

        .time-selector:hover {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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

        .btn-primary:hover {
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
            font-size: 40px;
            font-weight: 800;
            color: #2d3748;
            margin-bottom: 8px;
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
        }

        .kpi-change.positive {
            background: #c6f6d5;
            color: #22543d;
        }

        .kpi-change.neutral {
            background: #e2e8f0;
            color: #4a5568;
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
        }

        .card-header {
            padding: 24px 28px;
            border-bottom: 2px solid #f7fafc;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
        }

        .card-body {
            padding: 28px;
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        .stats-grid {
            display: grid;
            gap: 16px;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 4px solid #667eea;
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
            font-size: 20px;
            font-weight: 800;
            color: #667eea;
        }

        .funnel-step {
            display: flex;
            align-items: center;
            padding: 20px;
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.05), rgba(118, 75, 162, 0.05));
            border-radius: 12px;
            border-left: 5px solid #667eea;
            margin-bottom: 12px;
            transition: all 0.3s ease;
        }

        .funnel-step:hover {
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            transform: translateX(6px);
        }

        .funnel-number {
            font-size: 28px;
            font-weight: 800;
            color: #667eea;
            margin-right: 20px;
            min-width: 70px;
        }

        .funnel-label {
            flex: 1;
            font-weight: 600;
            color: #2d3748;
            font-size: 15px;
        }

        .funnel-rate {
            font-size: 16px;
            font-weight: 700;
            color: #718096;
            background: white;
            padding: 8px 16px;
            border-radius: 20px;
        }

        .segment-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            margin: 8px;
        }

        .segment-vip {
            background: linear-gradient(135deg, #ffd700, #ffed4e);
            color: #744210;
        }

        .segment-active {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
        }

        .segment-risk {
            background: linear-gradient(135deg, #f6ad55, #ed8936);
            color: white;
        }

        .segment-churned {
            background: linear-gradient(135deg, #fc8181, #f56565);
            color: white;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .table th {
            background: #f7fafc;
            font-weight: 700;
            color: #4a5568;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tr:hover {
            background: #f7fafc;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
        }

        .badge-primary {
            background: #667eea;
            color: white;
        }

        .auto-refresh-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #c6f6d5;
            color: #22543d;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .pulse {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #22543d;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        @media (max-width: 1200px) {
            .section-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
            }

            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .title-section {
                flex-direction: column;
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
                    <h1>Enhanced Customer Analytics</h1>
                    <p>Comprehensive insights into customer behavior & business performance</p>
                </div>
            </div>
            <div class="header-controls">
                <select class="time-selector" onchange="window.location.href='?days=' + this.value">
                    <?php
                    $periods = [7 => '7 Tage', 14 => '14 Tage', 30 => '30 Tage', 60 => '60 Tage', 90 => '90 Tage', 180 => '180 Tage', 365 => '1 Jahr'];
                    foreach ($periods as $value => $label):
                        $selected = ($days == $value) ? 'selected' : '';
                    ?>
                        <option value="<?= $value ?>" <?= $selected ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <a href="?export=csv&days=<?= $days ?>" class="btn btn-secondary">📥 Export CSV</a>
                <div class="auto-refresh-indicator">
                    <div class="pulse"></div>
                    Live Dashboard
                </div>
                <a href="dashboard.php" class="btn btn-primary">← Dashboard</a>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon">👥</div>
                <div class="kpi-number"><?= $kpis['active_users'] ?? 0 ?></div>
                <div class="kpi-label">Active Users</div>
                <div class="kpi-change neutral"><?= $kpis['logged_in_users'] ?? 0 ?> logged in</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon">🔐</div>
                <div class="kpi-number"><?= $kpis['total_logins'] ?? 0 ?></div>
                <div class="kpi-label">Total Logins</div>
                <div class="kpi-change neutral"><?= $days ?> days</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon">🎯</div>
                <div class="kpi-number"><?= $kpis['successful_searches'] ?? 0 ?></div>
                <div class="kpi-label">Successful Searches</div>
                <div class="kpi-change <?= ($kpis['search_success_rate'] ?? 0) > 70 ? 'positive' : 'neutral' ?>">
                    <?= $kpis['search_success_rate'] ?? 0 ?>% success rate
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon">📅</div>
                <div class="kpi-number"><?= $kpis['bookings_completed'] ?? 0 ?></div>
                <div class="kpi-label">Completed Bookings</div>
                <div class="kpi-change <?= ($kpis['conversion_rate'] ?? 0) > 50 ? 'positive' : 'neutral' ?>">
                    <?= $kpis['conversion_rate'] ?? 0 ?>% conversion
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon">🔄</div>
                <div class="kpi-number"><?= $retention['retention_rate'] ?? 0 ?>%</div>
                <div class="kpi-label">Retention Rate</div>
                <div class="kpi-change <?= ($retention['retention_rate'] ?? 0) > 40 ? 'positive' : 'neutral' ?>">
                    <?= $retention['returning_users'] ?? 0 ?> returning
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon">⚡</div>
                <div class="kpi-number"><?= $lead_time['avg_minutes'] ?? 0 ?></div>
                <div class="kpi-label">Avg. Lead Time (min)</div>
                <div class="kpi-change neutral">to booking</div>
            </div>
        </div>

        <!-- Activity Trends & Conversion Funnel -->
        <div class="section-grid">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">📈</div>
                    <h3 class="card-title">Activity Trends</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="trendsChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-icon">🔄</div>
                    <h3 class="card-title">Conversion Funnel</h3>
                </div>
                <div class="card-body">
                    <div class="funnel-step">
                        <div class="funnel-number"><?= $funnel['users_logged_in'] ?? 0 ?></div>
                        <div class="funnel-label">Users Logged In</div>
                        <div class="funnel-rate">100%</div>
                    </div>
                    <div class="funnel-step">
                        <div class="funnel-number"><?= $funnel['users_viewed_services'] ?? 0 ?></div>
                        <div class="funnel-label">Viewed Services</div>
                        <div class="funnel-rate"><?= $conversion_rates['login_to_service'] ?>%</div>
                    </div>
                    <div class="funnel-step">
                        <div class="funnel-number"><?= $funnel['users_found_slots'] ?? 0 ?></div>
                        <div class="funnel-label">Found Available Slots</div>
                        <div class="funnel-rate"><?= $conversion_rates['service_to_slots'] ?>%</div>
                    </div>
                    <div class="funnel-step">
                        <div class="funnel-number"><?= $funnel['users_started_booking'] ?? 0 ?></div>
                        <div class="funnel-label">Started Booking</div>
                        <div class="funnel-rate"><?= $conversion_rates['slots_to_booking'] ?>%</div>
                    </div>
                    <div class="funnel-step">
                        <div class="funnel-number"><?= $funnel['users_completed_booking'] ?? 0 ?></div>
                        <div class="funnel-label">Completed Booking</div>
                        <div class="funnel-rate"><?= $conversion_rates['booking_completion'] ?>%</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Device Stats & Peak Times -->
        <div class="section-grid">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">📱</div>
                    <h3 class="card-title">Device & Browser Statistics</h3>
                </div>
                <div class="card-body">
                    <div class="stats-grid">
                        <h4 style="color: #2d3748; font-weight: 700; margin-bottom: 8px;">Devices</h4>
                        <?php foreach ($device_stats['devices'] as $device => $count): ?>
                            <div class="stat-item">
                                <span class="stat-label"><?= htmlspecialchars($device) ?></span>
                                <span class="stat-value"><?= $count ?></span>
                            </div>
                        <?php endforeach; ?>

                        <h4 style="color: #2d3748; font-weight: 700; margin: 16px 0 8px;">Browsers</h4>
                        <?php foreach (array_slice($device_stats['browsers'], 0, 5) as $browser => $count): ?>
                            <div class="stat-item">
                                <span class="stat-label"><?= htmlspecialchars($browser) ?></span>
                                <span class="stat-value"><?= $count ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-icon">🕐</div>
                    <h3 class="card-title">Peak Usage Times</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="peakTimesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer Segmentation -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <div class="card-icon">👤</div>
                <h3 class="card-title">Customer Segmentation</h3>
            </div>
            <div class="card-body">
                <div style="display: flex; flex-wrap: wrap; gap: 12px; justify-content: center;">
                    <div class="segment-badge segment-vip">
                        <span style="font-size: 20px;">👑</span>
                        <div>
                            <div style="font-size: 24px; font-weight: 800;"><?= $segmentation['vip'] ?></div>
                            <div style="font-size: 12px; opacity: 0.9;">VIP Customers (5+ bookings)</div>
                        </div>
                    </div>
                    <div class="segment-badge segment-active">
                        <span style="font-size: 20px;">✅</span>
                        <div>
                            <div style="font-size: 24px; font-weight: 800;"><?= $segmentation['active'] ?></div>
                            <div style="font-size: 12px; opacity: 0.9;">Active (last 7 days)</div>
                        </div>
                    </div>
                    <div class="segment-badge segment-risk">
                        <span style="font-size: 20px;">⚠️</span>
                        <div>
                            <div style="font-size: 24px; font-weight: 800;"><?= $segmentation['at_risk'] ?></div>
                            <div style="font-size: 12px; opacity: 0.9;">At Risk (30-60 days)</div>
                        </div>
                    </div>
                    <div class="segment-badge segment-churned">
                        <span style="font-size: 20px;">❌</span>
                        <div>
                            <div style="font-size: 24px; font-weight: 800;"><?= $segmentation['churned'] ?></div>
                            <div style="font-size: 12px; opacity: 0.9;">Churned (60+ days)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Service Performance & Day of Week -->
        <div class="section-grid">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">⭐</div>
                    <h3 class="card-title">Service Performance</h3>
                </div>
                <div class="card-body">
                    <div class="stats-grid">
                        <?php if (empty($service_performance)): ?>
                            <p style="color: #718096; text-align: center; padding: 40px;">No service data available</p>
                        <?php else: ?>
                            <?php foreach ($service_performance as $service): ?>
                                <div class="stat-item">
                                    <span class="stat-label"><?= htmlspecialchars(ucfirst(str_replace('-', ' ', $service['service']))) ?></span>
                                    <div style="display: flex; gap: 16px; align-items: center;">
                                        <span class="stat-value"><?= $service['views'] ?></span>
                                        <span class="badge badge-primary"><?= $service['unique_customers'] ?> customers</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-icon">📅</div>
                    <h3 class="card-title">Activity by Day of Week</h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="dayOfWeekChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Customers -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">👑</div>
                <h3 class="card-title">Most Active Customers</h3>
            </div>
            <div class="card-body">
                <?php if (empty($top_customers)): ?>
                    <p style="color: #718096; text-align: center; padding: 40px;">No customer activity data</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Customer</th>
                                <th>Email</th>
                                <th>Activities</th>
                                <th>Last Active</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; foreach ($top_customers as $customer): ?>
                                <tr>
                                    <td><strong style="color: #667eea;">#<?= $rank++ ?></strong></td>
                                    <td><strong><?= htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($customer['email']) ?></td>
                                    <td><span class="badge badge-primary"><?= $customer['activity_count'] ?></span></td>
                                    <td style="color: #718096;"><?= date('d.m.Y H:i', strtotime($customer['last_activity'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Activity Trends Chart
        const trendsData = <?= json_encode($trends) ?>;
        if (trendsData && trendsData.length > 0) {
            const trendsCtx = document.getElementById('trendsChart');
            new Chart(trendsCtx, {
                type: 'line',
                data: {
                    labels: trendsData.map(item => new Date(item.date).toLocaleDateString('de-DE', { month: 'short', day: 'numeric' })),
                    datasets: [
                        {
                            label: 'Total Activities',
                            data: trendsData.map(item => parseInt(item.total_activities) || 0),
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 3
                        },
                        {
                            label: 'Unique Users',
                            data: trendsData.map(item => parseInt(item.unique_users) || 0),
                            borderColor: '#764ba2',
                            backgroundColor: 'rgba(118, 75, 162, 0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }

        // Peak Times Chart
        const peakData = <?= json_encode($peak_times) ?>;
        if (peakData && peakData.length > 0) {
            const peakCtx = document.getElementById('peakTimesChart');
            new Chart(peakCtx, {
                type: 'bar',
                data: {
                    labels: peakData.map(item => item.hour + ':00'),
                    datasets: [{
                        label: 'Activities',
                        data: peakData.map(item => parseInt(item.activity_count) || 0),
                        backgroundColor: 'rgba(102, 126, 234, 0.8)',
                        borderColor: '#667eea',
                        borderWidth: 2,
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }

        // Day of Week Chart
        const dayData = <?= json_encode($day_stats) ?>;
        if (dayData && dayData.length > 0) {
            const dayCtx = document.getElementById('dayOfWeekChart');
            new Chart(dayCtx, {
                type: 'doughnut',
                data: {
                    labels: dayData.map(item => item.day_name),
                    datasets: [{
                        data: dayData.map(item => parseInt(item.activity_count) || 0),
                        backgroundColor: [
                            '#667eea',
                            '#764ba2',
                            '#f093fb',
                            '#4facfe',
                            '#00f2fe',
                            '#43e97b',
                            '#38f9d7'
                        ],
                        borderWidth: 3,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right' }
                    }
                }
            });
        }

        // Auto-refresh every 5 minutes
        setTimeout(() => {
            window.location.reload();
        }, 300000);
    </script>
</body>
</html>
