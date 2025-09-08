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

// Calendly Configuration
$CALENDLY_TOKEN = getenv('CALENDLY_TOKEN') ?: 'PASTE_YOUR_TOKEN_HERE';
$ORG_URI = getenv('CALENDLY_ORG_URI') ?: 'https://api.calendly.com/organizations/PASTE_ORG_ID';

function api_get($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        return [null, 'Network error: '.curl_error($ch)];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code < 200 || $code >= 300) {
        $error_data = json_decode($res, true);
        $error_msg = $error_data['message'] ?? "HTTP $code";
        return [null, $error_msg];
    }
    
    $json = json_decode($res, true);
    return [$json, null];
}

function getBookingAnalytics($token, $org_uri) {
    // Current month boundaries (Vienna timezone)
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna'));
    $month_start = $now->modify('first day of this month')->setTime(0, 0, 0);
    $month_end = $now->modify('last day of this month')->setTime(23, 59, 59);
    
    // Convert to UTC for API
    $start_utc = $month_start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $end_utc = $month_end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    
    // Fetch all events for current month
    $all_events = [];
    $page_token = null;
    $max_pages = 10;
    $page_count = 0;
    
    do {
        $params = [
            'organization' => $org_uri,
            'min_start_time' => $start_utc,
            'max_start_time' => $end_utc,
            'count' => 100
        ];
        if ($page_token) $params['page_token'] = $page_token;
        
        $url = 'https://api.calendly.com/scheduled_events?' . http_build_query($params);
        list($data, $err) = api_get($url, $token);
        
        if ($err) throw new Exception("Calendly API Error: $err");
        
        $events = $data['collection'] ?? [];
        $all_events = array_merge($all_events, $events);
        $page_token = $data['pagination']['next_page_token'] ?? null;
        $page_count++;
        
    } while ($page_token && $page_count < $max_pages);
    
    // Enrich events with invitee data
    $enriched_events = [];
    foreach ($all_events as $event) {
        $event_uuid = basename($event['uri']);
        
        // Get invitees
        $invitee_url = "https://api.calendly.com/scheduled_events/{$event_uuid}/invitees";
        list($invitee_data, $invitee_err) = api_get($invitee_url, $token);
        
        $event['invitees'] = [];
        if (!$invitee_err && isset($invitee_data['collection'])) {
            $event['invitees'] = $invitee_data['collection'];
        }
        
        $enriched_events[] = $event;
    }
    
    return $enriched_events;
}

function analyzeBookingData($events) {
    $analytics = [
        'total_bookings' => count($events),
        'unique_customers' => 0,
        'services' => [],
        'daily_distribution' => [],
        'hourly_distribution' => [],
        'status_breakdown' => [],
        'top_customers' => [],
        'revenue_estimate' => 0,
        'avg_duration' => 0
    ];
    
    $customer_emails = [];
    $customer_booking_count = [];
    $service_prices = [
        'lerntraining' => 80,
        'neurofeedback-20' => 45,
        'neurofeedback-40' => 70
    ];
    
    foreach ($events as $event) {
        $vienna_tz = new DateTimeZone('Europe/Vienna');
        $start_time = new DateTimeImmutable($event['start_time'], new DateTimeZone('UTC'));
        $end_time = new DateTimeImmutable($event['end_time'], new DateTimeZone('UTC'));
        $start_vienna = $start_time->setTimezone($vienna_tz);
        
        // Service analysis
        $service_name = $event['event_type']['name'] ?? 'Unknown';
        if (!isset($analytics['services'][$service_name])) {
            $analytics['services'][$service_name] = [
                'count' => 0,
                'duration_total' => 0,
                'revenue' => 0
            ];
        }
        $analytics['services'][$service_name]['count']++;
        
        // Duration calculation
        $duration = ($end_time->getTimestamp() - $start_time->getTimestamp()) / 60; // minutes
        $analytics['services'][$service_name]['duration_total'] += $duration;
        
        // Revenue estimation
        $service_key = strtolower(str_replace([' ', '-'], ['', '-'], $service_name));
        if (isset($service_prices[$service_key])) {
            $analytics['services'][$service_name]['revenue'] += $service_prices[$service_key];
            $analytics['revenue_estimate'] += $service_prices[$service_key];
        }
        
        // Daily distribution
        $day_key = $start_vienna->format('Y-m-d');
        $analytics['daily_distribution'][$day_key] = ($analytics['daily_distribution'][$day_key] ?? 0) + 1;
        
        // Hourly distribution
        $hour_key = $start_vienna->format('H');
        $analytics['hourly_distribution'][$hour_key] = ($analytics['hourly_distribution'][$hour_key] ?? 0) + 1;
        
        // Status breakdown
        $status = $event['status'] ?? 'unknown';
        $analytics['status_breakdown'][$status] = ($analytics['status_breakdown'][$status] ?? 0) + 1;
        
        // Customer analysis
        foreach ($event['invitees'] as $invitee) {
            $email = strtolower($invitee['email'] ?? '');
            if ($email) {
                $customer_emails[$email] = $invitee['name'] ?? 'Unknown';
                $customer_booking_count[$email] = ($customer_booking_count[$email] ?? 0) + 1;
            }
        }
    }
    
    $analytics['unique_customers'] = count($customer_emails);
    
    // Top customers
    arsort($customer_booking_count);
    $analytics['top_customers'] = array_slice($customer_booking_count, 0, 10, true);
    
    // Average duration
    $total_duration = array_sum(array_column($analytics['services'], 'duration_total'));
    $analytics['avg_duration'] = $analytics['total_bookings'] > 0 ? 
        round($total_duration / $analytics['total_bookings']) : 0;
    
    return $analytics;
}

// Main execution
try {
    if (!$CALENDLY_TOKEN || !$ORG_URI) {
        throw new Exception('Calendly configuration missing');
    }
    
    $events = getBookingAnalytics($CALENDLY_TOKEN, $ORG_URI);
    $analytics = analyzeBookingData($events);
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    $analytics = null;
}

// Current month info
$current_month = (new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna')))->format('F Y');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking Analytics Dashboard - <?= $current_month ?></title>
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
        
        .month-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
        }
        
        .back-link {
            background: #6c757d;
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-left: 12px;
        }
        
        .back-link:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .kpi-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        
        .kpi-number {
            font-size: 48px;
            font-weight: 800;
            color: #2d3748;
            margin-bottom: 8px;
        }
        
        .kpi-label {
            font-size: 16px;
            color: #718096;
            font-weight: 500;
            margin-bottom: 12px;
        }
        
        .kpi-change {
            font-size: 14px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
        }
        
        .kpi-change.positive {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .chart-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        .chart-title {
            padding: 24px 24px 0 24px;
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .chart-icon {
            font-size: 24px;
        }
        
        .chart-content {
            padding: 24px;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }
        
        .service-grid {
            display: grid;
            gap: 16px;
        }
        
        .service-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .service-name {
            font-weight: 600;
            color: #2d3748;
        }
        
        .service-stats {
            display: flex;
            gap: 20px;
            font-size: 14px;
            color: #718096;
        }
        
        .error-state {
            background: white;
            padding: 48px 24px;
            border-radius: 16px;
            text-align: center;
            margin-bottom: 24px;
            border: 2px dashed #e2e8f0;
        }
        
        .error-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .refresh-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 16px;
            transition: all 0.3s ease;
        }
        
        .refresh-btn:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }
        
        .top-customers {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            padding: 24px;
        }
        
        .customer-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .customer-item:last-child {
            border-bottom: none;
        }
        
        .customer-email {
            font-weight: 500;
            color: #2d3748;
        }
        
        .customer-count {
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                gap: 16px;
                text-align: center;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <h1 class="dashboard-title">📅 Booking Analytics Dashboard</h1>
            <div style="display: flex; align-items: center;">
                <div class="month-badge"><?= htmlspecialchars($current_month) ?></div>
                <a href="dashboard.php" class="back-link">← Dashboard</a>
            </div>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="error-state">
                <div class="error-icon">⚠️</div>
                <h3>Analytics temporarily unavailable</h3>
                <p>Error: <?= htmlspecialchars($error_message) ?></p>
                <button class="refresh-btn" onclick="location.reload()">Retry</button>
            </div>
        <?php else: ?>
            
            <!-- KPI Cards -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-number"><?= $analytics['total_bookings'] ?></div>
                    <div class="kpi-label">Total Bookings</div>
                    <div class="kpi-change positive">This Month</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-number"><?= $analytics['unique_customers'] ?></div>
                    <div class="kpi-label">Unique Customers</div>
                    <div class="kpi-change positive"><?= count($analytics['services']) ?> Services</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-number">€<?= number_format($analytics['revenue_estimate'], 0) ?></div>
                    <div class="kpi-label">Revenue Estimate</div>
                    <div class="kpi-change positive">Estimated</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-number"><?= $analytics['avg_duration'] ?></div>
                    <div class="kpi-label">Avg. Duration (min)</div>
                    <div class="kpi-change positive">Per Session</div>
                </div>
            </div>

            <!-- Charts Grid -->
            <div class="charts-grid">
                <!-- Service Breakdown -->
                <div class="chart-card">
                    <h3 class="chart-title">
                        <div class="chart-icon">📊</div>
                        Service Performance
                    </h3>
                    <div class="chart-content">
                        <div class="service-grid">
                            <?php foreach ($analytics['services'] as $service => $data): ?>
                                <div class="service-item">
                                    <div class="service-name"><?= htmlspecialchars($service) ?></div>
                                    <div class="service-stats">
                                        <span><?= $data['count'] ?> bookings</span>
                                        <span>€<?= number_format($data['revenue'], 0) ?></span>
                                        <span><?= round($data['duration_total'] / max(1, $data['count'])) ?> min avg</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Customers -->
                <div class="chart-card">
                    <h3 class="chart-title">
                        <div class="chart-icon">👥</div>
                        Top Customers This Month
                    </h3>
                    <div class="chart-content">
                        <?php if (empty($analytics['top_customers'])): ?>
                            <p style="text-align: center; color: #718096; padding: 20px;">
                                No customer data available
                            </p>
                        <?php else: ?>
                            <?php foreach ($analytics['top_customers'] as $email => $count): ?>
                                <div class="customer-item">
                                    <div class="customer-email"><?= htmlspecialchars($email) ?></div>
                                    <div class="customer-count"><?= $count ?> booking<?= $count > 1 ? 's' : '' ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Status Breakdown -->
            <?php if (!empty($analytics['status_breakdown'])): ?>
                <div class="chart-card">
                    <h3 class="chart-title">
                        <div class="chart-icon">📈</div>
                        Booking Status Overview
                    </h3>
                    <div class="chart-content">
                        <div class="service-grid">
                            <?php foreach ($analytics['status_breakdown'] as $status => $count): ?>
                                <div class="service-item">
                                    <div class="service-name"><?= ucfirst(htmlspecialchars($status)) ?></div>
                                    <div class="service-stats">
                                        <span><?= $count ?> bookings</span>
                                        <span><?= round(($count / $analytics['total_bookings']) * 100, 1) ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</body>
</html>