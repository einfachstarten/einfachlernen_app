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

$kpis = getKPIMetrics($pdo, $days);
$trends = getActivityTrends($pdo, $days);
$funnel = getConversionFunnel($pdo, $days);
$services = getPopularServices($pdo, $days);

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
        }
        
        .service-name {
            min-width: 120px;
            font-weight: 500;
            color: #2d3748;
        }
        
        .service-bar-bg {
            flex: 1;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .service-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 4px;
            transition: width 0.8s ease;
        }
        
        .service-count {
            font-weight: 600;
            color: #667eea;
            min-width: 30px;
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
        /* Analytics-specific Styles */
        .analytics-container {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin: 1rem 0;
            overflow: hidden;
            position: relative;
            isolation: isolate;
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

        .analytics-metric.revenue h3 {
            color: #28a745;
        }

        .analytics-metric.bookings h3 {
            color: #007bff;
        }

        .analytics-metric.average h3 {
            color: #ffc107;
        }

        .analytics-metric.capacity h3 {
            color: #17a2b8;
        }

        .analytics-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            position: relative;
            z-index: 10;
            table-layout: fixed;
        }

        .analytics-table th {
            background: #495057;
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .analytics-table th:last-child {
            text-align: right;
        }

        .analytics-table td {
            padding: 1rem;
            border-bottom: 1px solid #f8f9fa;
            background: white;
            position: relative;
            z-index: 11;
            vertical-align: top;
        }

        .analytics-table tr:hover td {
            background: #f8f9fa;
        }

        .analytics-table td:last-child {
            text-align: right;
            font-weight: 600;
        }

        .analytics-table td:first-child {
            width: 250px;
            min-width: 250px;
        }

        .service-bar-container {
            position: relative;
            margin-top: 0.5rem;
            background: #e9ecef;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            width: 100%;
            max-width: 200px;
        }

        .service-bar {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            border-radius: 4px;
            transition: width 0.3s ease;
            z-index: 1;
        }

        .capacity-bar-container {
            background: #e9ecef;
            height: 24px;
            border-radius: 12px;
            position: relative;
            overflow: hidden;
            width: 100%;
            max-width: 150px;
        }

        .capacity-bar {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            border-radius: 12px;
            transition: width 0.3s ease;
            z-index: 1;
        }

        .capacity-bar.low {
            background: linear-gradient(90deg, #28a745, #20c997);
        }

        .capacity-bar.medium {
            background: linear-gradient(90deg, #ffc107, #ffca2c);
        }

        .capacity-bar.high {
            background: linear-gradient(90deg, #dc3545, #e74c3c);
        }

        .capacity-percentage {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 11px;
            font-weight: bold;
            color: #333;
            z-index: 2;
            pointer-events: none;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.frei {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.mittel {
            background: #fff3cd;
            color: #856404;
        }

        .status-badge.voll {
            background: #f8d7da;
            color: #721c24;
        }

        .analytics-recommendation {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem 1.5rem;
            margin: 1rem;
            border-radius: 0 4px 4px 0;
        }

        .analytics-recommendation strong {
            color: #856404;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .analytics-summary {
                flex-direction: column;
                gap: 1rem;
            }

            .analytics-table {
                font-size: 0.9rem;
            }

            .analytics-table th,
            .analytics-table td {
                padding: 0.75rem 0.5rem;
            }
        }
    </style>
    <style>
        /* Override existing admin table styles for analytics */
        .analytics-container * {
            box-sizing: border-box !important;
        }

        .analytics-container table {
            background: white !important;
            position: relative !important;
            z-index: 10 !important;
        }

        .analytics-container th {
            background: #495057 !important;
            color: white !important;
            position: relative !important;
            z-index: 11 !important;
        }

        .analytics-container td {
            background: white !important;
            border-bottom: 1px solid #f8f9fa !important;
            position: relative !important;
            z-index: 11 !important;
        }

        .analytics-container .service-bar,
        .analytics-container .capacity-bar {
            max-width: 100% !important;
            contain: layout style !important;
        }
    </style>
    <style>
        /* Fallback styles for broken layouts */
        .service-bar-container,
        .capacity-bar-container {
            display: inline-block !important;
            vertical-align: top !important;
            position: relative !important;
            overflow: hidden !important;
        }

        .service-bar,
        .capacity-bar {
            position: relative !important;
            display: block !important;
            float: none !important;
            clear: none !important;
        }

        @media screen and (max-width: 768px) {
            .service-bar-container,
            .capacity-bar-container {
                display: none;
            }
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
        <div id="serviceAnalytics">Service Analytics werden geladen...</div>

        <div id="weeklyCapacity">Kapazitätsplanung wird geladen...</div>
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
    <script>
    // Calendly Analytics laden
    document.addEventListener('DOMContentLoaded', function() {
        loadServiceAnalytics(<?= $days ?>);
        loadWeeklyCapacity();
    });

    async function loadServiceAnalytics(days) {
        try {
            const response = await fetch(`calendly_analytics.php?type=service_stats&days=` + days);
            const data = await response.json();

            if (data.success) {
                displayServiceAnalytics(data);
            } else {
                document.getElementById('serviceAnalytics').innerHTML =
                    '<p style="color:red;">❌ ' + data.error + '</p>';
            }
        } catch (error) {
            document.getElementById('serviceAnalytics').innerHTML =
                '<p style="color:red;">❌ Fehler beim Laden der Service Analytics</p>';
        }
    }

    async function loadWeeklyCapacity() {
        try {
            const response = await fetch('calendly_analytics.php?type=weekly_capacity');
            const data = await response.json();

            if (data.success) {
                displayWeeklyCapacity(data);
            } else {
                document.getElementById('weeklyCapacity').innerHTML =
                    '<p style="color:red;">❌ ' + data.error + '</p>';
            }
        } catch (error) {
            document.getElementById('weeklyCapacity').innerHTML =
                '<p style="color:red;">❌ Fehler beim Laden der Kapazitätsplanung</p>';
        }
    }

    function displayServiceAnalytics(data) {
        let html = `
            <div class="analytics-container">
                <div class="analytics-header">
                    <h2>📊 Service Performance</h2>
                </div>
                
                <div class="analytics-summary">
                    <div class="analytics-metric bookings">
                        <h3>${data.total_bookings}</h3>
                        <small>Gesamtbuchungen</small>
                    </div>
                    <div class="analytics-metric revenue">
                        <h3>€${data.total_revenue}</h3>
                        <small>Gesamtumsatz</small>
                    </div>
                    <div class="analytics-metric average">
                        <h3>€${data.avg_booking_value}</h3>
                        <small>⌀ Buchungswert</small>
                    </div>
                </div>
                
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th style="text-align:center;">Buchungen</th>
                            <th style="text-align:center;">Anteil</th>
                            <th style="text-align:right;">Umsatz</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        Object.entries(data.services).forEach(([service, stats]) => {
            const percentage = Math.min(100, Math.max(0, stats.percentage));
            console.log(`Service: ${service}, Percentage: ${percentage}%`);

            html += `
                <tr>
                    <td style="width:250px;">
                        <strong style="color:#495057;">${service}</strong>
                        <div class="service-bar-container" style="max-width:200px;">
                            <div class="service-bar" style="width:${percentage}%;"></div>
                        </div>
                    </td>
                    <td style="text-align:center;">${stats.count}</td>
                    <td style="text-align:center;font-weight:600;">${percentage}%</td>
                    <td style="text-align:right;color:#28a745;font-weight:bold;">€${stats.revenue}</td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        document.getElementById('serviceAnalytics').innerHTML = html;
    }

    function displayWeeklyCapacity(data) {
        let html = `
            <div class="analytics-container">
                <div class="analytics-header">
                    <h2>📈 Kapazitätsplanung</h2>
                </div>
                
                <div class="analytics-summary">
                    <div class="analytics-metric revenue">
                        <h3>€${data.total_upcoming_revenue}</h3>
                        <small>Kommender Umsatz</small>
                    </div>
                    <div class="analytics-metric capacity">
                        <h3>${data.average_capacity}%</h3>
                        <small>⌀ Auslastung</small>
                    </div>
                </div>
                
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Woche</th>
                            <th style="text-align:center;">Auslastung</th>
                            <th style="text-align:center;">Gebucht/Max</th>
                            <th style="text-align:right;">Umsatz</th>
                            <th style="text-align:center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        data.weeks.forEach(week => {
            const capacityClass = week.capacity_percentage >= 70 ? 'high' :
                                 week.capacity_percentage >= 40 ? 'medium' : 'low';
            const statusText = week.status === 'full' ? 'Voll' :
                              week.status === 'medium' ? 'Mittel' : 'Frei';
            
            html += `
                <tr>
                    <td>
                        <strong style="color:#495057;">Woche ${week.week_number}</strong><br>
                        <small style="color:#6c757d;">${week.week_start} - ${week.week_end}</small>
                    </td>
                    <td style="text-align:center;">
                        <div class="capacity-bar-container">
                            <div class="capacity-bar ${capacityClass}" style="width:${week.capacity_percentage}%;"></div>
                            <span class="capacity-percentage">${week.capacity_percentage}%</span>
                        </div>
                    </td>
                    <td style="text-align:center;font-weight:600;">${week.booked_slots}/${week.max_capacity}</td>
                    <td style="text-align:right;color:#28a745;font-weight:bold;">€${week.revenue}</td>
                    <td style="text-align:center;">
                        <span class="status-badge ${statusText.toLowerCase()}">${statusText}</span>
                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        // Empfehlungen hinzufügen
        const lowWeeks = data.weeks.filter(w => w.status === 'low');
        if (lowWeeks.length > 0) {
            html += `
                <div class="analytics-recommendation">
                    <strong>💡 Marketing-Empfehlung:</strong><br>
                    Wochen mit niedriger Auslastung: ${lowWeeks.map(w => w.week_number).join(', ')}. 
                    Erwägen Sie gezielte Marketing-Aktionen oder Sonderangebote.
                </div>
            `;
        }
        
        document.getElementById('weeklyCapacity').innerHTML = html;
    }
    </script>
</body>
</html>
