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

// Handle API requests
if (isset($_GET['api']) && $_GET['api'] === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    
    $CALENDLY_TOKEN = getenv('CALENDLY_TOKEN') ?: 'PASTE_YOUR_TOKEN_HERE';
    $ORG_URI = getenv('CALENDLY_ORG_URI') ?: 'https://api.calendly.com/organizations/PASTE_ORG_ID';
    
    if (!$CALENDLY_TOKEN || !$ORG_URI) {
        echo json_encode(['error' => 'Calendly configuration missing']);
        exit;
    }
    
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
    
    function getBookingAnalytics($token, $org_uri, $month_year) {
        // Parse month_year (format: "2025-01" or "current")
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
        
        // Convert to UTC for API
        $start_utc = $month_start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $end_utc = $month_end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        
        // Fetch all events for specified month
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
        
        // Enrich events with invitee data (limit concurrent requests)
        $enriched_events = [];
        $batch_size = 5;
        $processed = 0;
        
        foreach (array_chunk($all_events, $batch_size) as $batch) {
            foreach ($batch as $event) {
                $event_uuid = basename($event['uri']);
                
                // Get invitees
                $invitee_url = "https://api.calendly.com/scheduled_events/{$event_uuid}/invitees";
                list($invitee_data, $invitee_err) = api_get($invitee_url, $token);
                
                $event['invitees'] = [];
                if (!$invitee_err && isset($invitee_data['collection'])) {
                    $event['invitees'] = $invitee_data['collection'];
                }
                
                $enriched_events[] = $event;
                $processed++;
                
                // Add small delay to prevent rate limiting
                if ($processed % $batch_size === 0) {
                    usleep(200000); // 200ms delay
                }
            }
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
            'neurofeedback-40' => 70,
            'neurofeedback training 20 min' => 45,
            'neurofeedback training 40 minuten' => 70
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
            $service_key = strtolower($service_name);
            $found_price = false;
            foreach ($service_prices as $key => $price) {
                if (strpos($service_key, $key) !== false || strpos($key, $service_key) !== false) {
                    $analytics['services'][$service_name]['revenue'] += $price;
                    $analytics['revenue_estimate'] += $price;
                    $found_price = true;
                    break;
                }
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
    
    try {
        $month_year = $_GET['month'] ?? 'current';
        $events = getBookingAnalytics($CALENDLY_TOKEN, $ORG_URI, $month_year);
        $analytics = analyzeBookingData($events);
        
        // Add month info
        if ($month_year === 'current') {
            $display_month = (new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna')))->format('F Y');
        } else {
            $display_month = DateTimeImmutable::createFromFormat('Y-m', $month_year, new DateTimeZone('Europe/Vienna'))->format('F Y');
        }
        
        $analytics['display_month'] = $display_month;
        $analytics['month_key'] = $month_year;
        
        echo json_encode(['success' => true, 'data' => $analytics]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit;
}

// Generate month options for selector
function getMonthOptions() {
    $options = [];
    $current = new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna'));
    
    // Add current month
    $options['current'] = $current->format('F Y') . ' (Current)';
    
    // Add last 6 months
    for ($i = 1; $i <= 6; $i++) {
        $month = $current->modify("-{$i} months");
        $key = $month->format('Y-m');
        $options[$key] = $month->format('F Y');
    }
    
    // Add next 3 months
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
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking Analytics Dashboard</title>
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
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .dashboard-title {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header-controls {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .month-selector {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            font-weight: 500;
            color: #2d3748;
            min-width: 180px;
        }
        
        .month-selector:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .refresh-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .refresh-btn:hover:not(:disabled) {
            background: #5a67d8;
            transform: translateY(-2px);
        }
        
        .refresh-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .back-link {
            background: #6c757d;
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(4px);
        }
        
        .loading-spinner {
            background: white;
            padding: 32px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e2e8f0;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            min-height: 140px;
            display: flex;
            flex-direction: column;
            justify-content: center;
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
        
        .kpi-card.loading {
            color: #a0aec0;
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
            background: #e2e8f0;
            color: #718096;
        }
        
        .kpi-change.loaded {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .chart-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-bottom: 24px;
            overflow: hidden;
            min-height: 300px;
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
        
        .chart-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 200px;
            color: #a0aec0;
            flex-direction: column;
            gap: 16px;
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
            border: 2px dashed #fed7d7;
            color: #e53e3e;
        }
        
        .error-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.7;
        }
        
        .customer-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        
        .no-data {
            text-align: center;
            color: #a0aec0;
            padding: 40px 20px;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                text-align: center;
            }
            
            .header-controls {
                flex-direction: column;
                width: 100%;
            }
            
            .month-selector {
                width: 100%;
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
            <div class="header-controls">
                <select id="monthSelector" class="month-selector">
                    <?php foreach ($month_options as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button id="refreshBtn" class="refresh-btn">🔄 Refresh</button>
                <a href="dashboard.php" class="back-link">← Dashboard</a>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div id="loadingOverlay" class="loading-overlay" style="display: none;">
            <div class="loading-spinner">
                <div class="spinner"></div>
                <div>Loading booking data...</div>
                <div style="font-size: 12px; color: #718096; margin-top: 8px;">This may take a moment</div>
            </div>
        </div>

        <!-- Error State -->
        <div id="errorState" class="error-state" style="display: none;">
            <div class="error-icon">⚠️</div>
            <h3>Analytics temporarily unavailable</h3>
            <p id="errorMessage">Failed to load booking data</p>
            <button class="refresh-btn" onclick="loadAnalyticsData()">Retry</button>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card loading">
                <div class="kpi-number" id="totalBookings">--</div>
                <div class="kpi-label">Total Bookings</div>
                <div class="kpi-change" id="bookingsChange">Loading...</div>
            </div>
            <div class="kpi-card loading">
                <div class="kpi-number" id="uniqueCustomers">--</div>
                <div class="kpi-label">Unique Customers</div>
                <div class="kpi-change" id="customersChange">Loading...</div>
            </div>
            <div class="kpi-card loading">
                <div class="kpi-number" id="revenueEstimate">€--</div>
                <div class="kpi-label">Revenue Estimate</div>
                <div class="kpi-change" id="revenueChange">Loading...</div>
            </div>
            <div class="kpi-card loading">
                <div class="kpi-number" id="avgDuration">--</div>
                <div class="kpi-label">Avg. Duration (min)</div>
                <div class="kpi-change" id="durationChange">Loading...</div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="charts-grid">
            <!-- Service Performance -->
            <div class="chart-card">
                <h3 class="chart-title">
                    <div class="chart-icon">📊</div>
                    Service Performance
                </h3>
                <div class="chart-content">
                    <div id="servicePerformance" class="chart-loading">
                        <div class="spinner"></div>
                        <div>Loading service data...</div>
                    </div>
                </div>
            </div>

            <!-- Top Customers -->
            <div class="chart-card">
                <h3 class="chart-title">
                    <div class="chart-icon">👥</div>
                    <span id="topCustomersTitle">Top Customers</span>
                </h3>
                <div class="chart-content">
                    <div id="topCustomers" class="chart-loading">
                        <div class="spinner"></div>
                        <div>Loading customer data...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Breakdown -->
        <div class="chart-card">
            <h3 class="chart-title">
                <div class="chart-icon">📈</div>
                Booking Status Overview
            </h3>
            <div class="chart-content">
                <div id="statusBreakdown" class="chart-loading">
                    <div class="spinner"></div>
                    <div>Loading status data...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentData = null;
        let isLoading = false;

        function showLoading() {
            if (isLoading) return;
            isLoading = true;
            document.getElementById('loadingOverlay').style.display = 'flex';
            document.getElementById('errorState').style.display = 'none';
            document.getElementById('refreshBtn').disabled = true;
        }

        function hideLoading() {
            isLoading = false;
            document.getElementById('loadingOverlay').style.display = 'none';
            document.getElementById('refreshBtn').disabled = false;
        }

        function showError(message) {
            hideLoading();
            document.getElementById('errorState').style.display = 'block';
            document.getElementById('errorMessage').textContent = message;
        }

        function updateKPIs(data) {
            // Update KPI values
            document.getElementById('totalBookings').textContent = data.total_bookings;
            document.getElementById('uniqueCustomers').textContent = data.unique_customers;
            document.getElementById('revenueEstimate').textContent = '€' + data.revenue_estimate.toLocaleString();
            document.getElementById('avgDuration').textContent = data.avg_duration;

            // Update change indicators
            document.getElementById('bookingsChange').textContent = data.display_month;
            document.getElementById('customersChange').textContent = Object.keys(data.services).length + ' Services';
            document.getElementById('revenueChange').textContent = 'Estimated';
            document.getElementById('durationChange').textContent = 'Per Session';

            // Remove loading state
            document.querySelectorAll('.kpi-card').forEach(card => {
                card.classList.remove('loading');
            });
            document.querySelectorAll('.kpi-change').forEach(change => {
                change.classList.add('loaded');
            });
        }

        function updateServicePerformance(services) {
            const container = document.getElementById('servicePerformance');
            
            if (Object.keys(services).length === 0) {
                container.innerHTML = '<div class="no-data">No service data available</div>';
                return;
            }

            const serviceGrid = document.createElement('div');
            serviceGrid.className = 'service-grid';

            Object.entries(services).forEach(([serviceName, data]) => {
                const avgDuration = data.count > 0 ? Math.round(data.duration_total / data.count) : 0;
                
                const serviceItem = document.createElement('div');
                serviceItem.className = 'service-item';
                serviceItem.innerHTML = `
                    <div class="service-name">${serviceName}</div>
                    <div class="service-stats">
                        <span>${data.count} bookings</span>
                        <span>€${data.revenue.toLocaleString()}</span>
                        <span>${avgDuration} min avg</span>
                    </div>
                `;
                serviceGrid.appendChild(serviceItem);
            });

            container.innerHTML = '';
            container.appendChild(serviceGrid);
        }

        function updateTopCustomers(topCustomers, displayMonth) {
            const container = document.getElementById('topCustomers');
            const title = document.getElementById('topCustomersTitle');
            title.textContent = `Top Customers - ${displayMonth}`;
            
            if (Object.keys(topCustomers).length === 0) {
                container.innerHTML = '<div class="no-data">No customer data available</div>';
                return;
            }

            const customersHtml = Object.entries(topCustomers).map(([email, count]) => `
                <div class="customer-item">
                    <div class="customer-email">${email}</div>
                    <div class="customer-count">${count} booking${count > 1 ? 's' : ''}</div>
                </div>
            `).join('');

            container.innerHTML = customersHtml;
        }

        function updateStatusBreakdown(statusBreakdown, totalBookings) {
            const container = document.getElementById('statusBreakdown');
            
            if (Object.keys(statusBreakdown).length === 0) {
                container.innerHTML = '<div class="no-data">No status data available</div>';
                return;
            }

            const statusGrid = document.createElement('div');
            statusGrid.className = 'service-grid';

            Object.entries(statusBreakdown).forEach(([status, count]) => {
                const percentage = totalBookings > 0 ? Math.round((count / totalBookings) * 100) : 0;
                
                const statusItem = document.createElement('div');
                statusItem.className = 'service-item';
                statusItem.innerHTML = `
                    <div class="service-name">${status.charAt(0).toUpperCase() + status.slice(1)}</div>
                    <div class="service-stats">
                        <span>${count} bookings</span>
                        <span>${percentage}%</span>
                    </div>
                `;
                statusGrid.appendChild(statusItem);
            });

            container.innerHTML = '';
            container.appendChild(statusGrid);
        }

        async function loadAnalyticsData() {
            if (isLoading) return;
            
            isLoading = true;
            document.getElementById('refreshBtn').disabled = true;
            document.getElementById('errorState').style.display = 'none';
            
            // Reset all areas to loading state
            resetToLoadingState();
            
            const selectedMonth = document.getElementById('monthSelector').value;
            
            try {
                const response = await fetch(`?api=data&month=${encodeURIComponent(selectedMonth)}`);
                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.error || 'Failed to load data');
                }

                currentData = result.data;
                
                // Update all UI elements
                updateKPIs(currentData);
                updateServicePerformance(currentData.services);
                updateTopCustomers(currentData.top_customers, currentData.display_month);
                updateStatusBreakdown(currentData.status_breakdown, currentData.total_bookings);
                
            } catch (error) {
                console.error('Error loading analytics:', error);
                showError(error.message);
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