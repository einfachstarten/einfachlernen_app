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
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $CALENDLY_TOKEN = getenv('CALENDLY_TOKEN') ?: 'PASTE_YOUR_TOKEN_HERE';
    $ORG_URI = getenv('CALENDLY_ORG_URI') ?: 'https://api.calendly.com/organizations/PASTE_ORG_ID';
    
    if (!$CALENDLY_TOKEN || $CALENDLY_TOKEN === 'PASTE_YOUR_TOKEN_HERE' || !$ORG_URI || $ORG_URI === 'https://api.calendly.com/organizations/PASTE_ORG_ID') {
        echo json_encode(['success' => false, 'error' => 'Calendly API configuration missing']);
        exit;
    }
    
    function api_get($url, $token) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($res === false) {
            return [null, 'Network error: ' . $error];
        }
        
        if ($http_code < 200 || $http_code >= 300) {
            $error_data = json_decode($res, true);
            $error_msg = $error_data['message'] ?? "HTTP $http_code";
            return [null, $error_msg];
        }
        
        $json = json_decode($res, true);
        if ($json === null) {
            return [null, 'Invalid JSON response'];
        }
        
        return [$json, null];
    }
    
    function mapServiceName($calendly_name) {
        $mapping = [
            'lerntraining' => 'Lerntraining',
            'neurofeedback-training-20-min' => 'Neurofeedback 20 Min',
            'neurofeedback-training-40-minuten' => 'Neurofeedback 40 Min',
            'neurofeedback training 20 min' => 'Neurofeedback 20 Min', 
            'neurofeedback training 40 minuten' => 'Neurofeedback 40 Min',
            'neurofeedback-20' => 'Neurofeedback 20 Min',
            'neurofeedback-40' => 'Neurofeedback 40 Min'
        ];
        
        $lower = strtolower($calendly_name);
        
        foreach ($mapping as $key => $display_name) {
            if (strpos($lower, $key) !== false) {
                return $display_name;
            }
        }
        
        return $calendly_name; // Return original if no mapping found
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
        
        // Convert to UTC for API
        $start_utc = $month_start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $end_utc = $month_end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        
        // Fetch all events (WITHOUT invitee details first!)
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
        
        return $all_events;
    }
    
    // STAGE 1: Basic Analytics (from events only, no invitee calls)
    if ($_GET['api'] === 'basic') {
        try {
            $month_year = $_GET['month'] ?? 'current';
            $events = getEventsForMonth($CALENDLY_TOKEN, $ORG_URI, $month_year);
            
            $analytics = [
                'total_bookings' => count($events),
                'services' => [],
                'status_breakdown' => [],
                'avg_duration' => 0
            ];
            
            $service_prices = [
                'lerntraining' => 80,
                'neurofeedback-20' => 45,
                'neurofeedback-40' => 70,
                'neurofeedback training 20 min' => 45,
                'neurofeedback training 40 minuten' => 70,
                'neurofeedback 20 min' => 45,
                'neurofeedback 40 min' => 70
            ];
            
            $total_duration = 0;
            
            foreach ($events as $event) {
                $start_time = new DateTimeImmutable($event['start_time'], new DateTimeZone('UTC'));
                $end_time = new DateTimeImmutable($event['end_time'], new DateTimeZone('UTC'));
                $duration = ($end_time->getTimestamp() - $start_time->getTimestamp()) / 60;
                $total_duration += $duration;
                
                // Service analysis - VERBESSERTE SERVICE-NAME-EXTRAKTION
                $service_name = 'Unknown';
                
                // Versuch 1: event_type.name
                if (isset($event['event_type']['name']) && !empty($event['event_type']['name'])) {
                    $service_name = $event['event_type']['name'];
                }
                // Versuch 2: name direkt
                elseif (isset($event['name']) && !empty($event['name'])) {
                    $service_name = $event['name'];
                }
                // Versuch 3: event_type.slug oder andere Felder
                elseif (isset($event['event_type']['slug'])) {
                    $service_name = ucfirst(str_replace(['-', '_'], ' ', $event['event_type']['slug']));
                }
                // Versuch 4: URI-basiert (letzte Option)
                elseif (isset($event['event_type']['uri'])) {
                    $uri_parts = explode('/', $event['event_type']['uri']);
                    $service_name = 'Service ' . end($uri_parts);
                }
                
                // Debug-Ausgabe aktivieren (nur für Test)
                if ($service_name === 'Unknown') {
                    error_log("DEBUG Event Structure: " . json_encode($event));
                }
                
                // Clean up und mapping anwenden
                $service_name = mapServiceName($service_name);
                
                if (!isset($analytics['services'][$service_name])) {
                    $analytics['services'][$service_name] = [
                        'count' => 0,
                        'duration_total' => 0,
                        'revenue' => 0
                    ];
                }
                $analytics['services'][$service_name]['count']++;
                $analytics['services'][$service_name]['duration_total'] += $duration;
                
                // Revenue estimation - VERBESSERTE ZUORDNUNG
                $service_key = strtolower($service_name);
                foreach ($service_prices as $key => $price) {
                    if (strpos($service_key, $key) !== false || strpos($key, $service_key) !== false) {
                        $analytics['services'][$service_name]['revenue'] += $price;
                        break;
                    }
                }
                
                // Status breakdown
                $status = $event['status'] ?? 'unknown';
                $analytics['status_breakdown'][$status] = ($analytics['status_breakdown'][$status] ?? 0) + 1;
            }
            
            $analytics['avg_duration'] = count($events) > 0 ? round($total_duration / count($events)) : 0;
            $analytics['revenue_estimate'] = array_sum(array_column($analytics['services'], 'revenue'));
            
            // Add month info
            if ($month_year === 'current') {
                $display_month = (new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna')))->format('F Y');
            } else {
                $display_month = DateTimeImmutable::createFromFormat('Y-m', $month_year, new DateTimeZone('Europe/Vienna'))->format('F Y');
            }
            $analytics['display_month'] = $display_month;
            
            echo json_encode(['success' => true, 'data' => $analytics]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // STAGE 2: Customer Analytics (requires invitee details, but only for unique events)
    if ($_GET['api'] === 'customers') {
        try {
            // Start output buffering to catch any PHP errors
            ob_start();
            
            $month_year = $_GET['month'] ?? 'current';
            $events = getEventsForMonth($CALENDLY_TOKEN, $ORG_URI, $month_year);
            
            // Get unique event UUIDs (avoid duplicate invitee calls)
            $unique_events = [];
            foreach ($events as $event) {
                $uuid = basename($event['uri']);
                $unique_events[$uuid] = $event;
            }
            
            $customer_emails = [];
            $customer_booking_count = [];
            $customer_services = []; // Track services per customer
            $customer_details = []; // Full booking details per customer
            $processed = 0;
            
            // Only fetch invitee details for unique events
            foreach ($unique_events as $uuid => $event) {
                $invitee_url = "https://api.calendly.com/scheduled_events/{$uuid}/invitees";
                list($invitee_data, $invitee_err) = api_get($invitee_url, $CALENDLY_TOKEN);
                
                if (!$invitee_err && isset($invitee_data['collection'])) {
                    // VERBESSERTE Service-Name-Extraktion (same logic as basic)
                    $service_name = 'Unknown';
                    
                    if (isset($event['event_type']['name']) && !empty($event['event_type']['name'])) {
                        $service_name = $event['event_type']['name'];
                    } elseif (isset($event['name']) && !empty($event['name'])) {
                        $service_name = $event['name'];
                    } elseif (isset($event['event_type']['slug'])) {
                        $service_name = ucfirst(str_replace(['-', '_'], ' ', $event['event_type']['slug']));
                    } elseif (isset($event['event_type']['uri'])) {
                        $uri_parts = explode('/', $event['event_type']['uri']);
                        $service_name = 'Service ' . end($uri_parts);
                    }
                    
                    $service_name = mapServiceName($service_name);
                    
                    $event_date = $event['start_time'] ?? '';
                    $event_status = $event['status'] ?? 'unknown';
                    
                    foreach ($invitee_data['collection'] as $invitee) {
                        $email = strtolower($invitee['email'] ?? '');
                        if ($email) {
                            $customer_emails[$email] = $invitee['name'] ?? 'Unknown';
                            $customer_booking_count[$email] = ($customer_booking_count[$email] ?? 0) + 1;
                            
                            // Track services per customer
                            if (!isset($customer_services[$email])) {
                                $customer_services[$email] = [];
                            }
                            if (!in_array($service_name, $customer_services[$email])) {
                                $customer_services[$email][] = $service_name;
                            }
                            
                            // Track detailed bookings
                            if (!isset($customer_details[$email])) {
                                $customer_details[$email] = [];
                            }
                            $customer_details[$email][] = [
                                'service' => $service_name,
                                'date' => $event_date,
                                'status' => $event_status
                            ];
                        }
                    }
                }
                
                $processed++;
                // Rate limiting: delay after every 5 requests
                if ($processed % 5 === 0) {
                    usleep(200000); // 200ms delay
                }
                
                // Safety limit: max 50 invitee calls
                if ($processed >= 50) break;
            }
            
            // ALL customers - keine Limitierung mehr
            arsort($customer_booking_count);
            $all_customers = $customer_booking_count; // Alle Kunden ohne Limit
            
            // Calculate unique services used by customers
            $unique_services_by_customers = [];
            foreach ($customer_services as $services) {
                foreach ($services as $service) {
                    $unique_services_by_customers[$service] = true;
                }
            }
            
            $result_data = [
                'unique_customers' => count($customer_emails),
                'top_customers' => $all_customers, // Geändert: Alle statt nur Top 10
                'customer_services' => $customer_services,
                'customer_details' => $customer_details,
                'customer_names' => $customer_emails,
                'unique_services_by_customers' => count($unique_services_by_customers),
                'processed_events' => $processed,
                'total_events' => count($events)
            ];
            
            // Clean any output that might have been generated
            ob_clean();
            
            echo json_encode(['success' => true, 'data' => $result_data]);
            
        } catch (Exception $e) {
            // Clean any output that might have been generated
            if (ob_get_length()) ob_clean();
            
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Invalid API endpoint
    echo json_encode(['success' => false, 'error' => 'Invalid API endpoint']);
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #2d3748;
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
        
        .spinner {
            width: 32px;
            height: 32px;
            border: 3px solid #e2e8f0;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
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
            min-height: 350px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .chart-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 200px;
            color: #a0aec0;
            flex-direction: column;
            gap: 12px;
            font-size: 14px;
        }
        
        .chart-loading .spinner {
            margin-bottom: 8px;
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
        
        /* Chart Specific Styles */
        #statusChart {
            max-height: 280px;
            margin: 0 auto;
        }
        
        /* PROFESSIONAL CUSTOMER TABLE DESIGN */
        .customers-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .customers-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 24px;
            color: white;
        }
        
        .customers-title {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .customers-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .customers-table-wrapper {
            max-height: 700px;
            overflow-y: auto;
            overflow-x: auto;
        }
        
        .customers-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 14px;
        }
        
        .customers-table thead th {
            background: #f8fafc;
            padding: 16px 20px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 12px;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .customers-table tbody td {
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        
        .customers-table tbody tr {
            transition: background-color 0.2s ease;
        }
        
        .customers-table tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .customers-table tbody tr:nth-child(even) {
            background-color: #fafbfc;
        }
        
        .customers-table tbody tr:nth-child(even):hover {
            background-color: #f1f5f9;
        }
        
        .customer-info {
            min-width: 220px;
        }
        
        .customer-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
            font-size: 15px;
        }
        
        .customer-email {
            color: #718096;
            font-size: 13px;
            word-break: break-word;
        }
        
        .booking-stats-cell {
            text-align: center;
            min-width: 80px;
        }
        
        .booking-number {
            font-size: 18px;
            font-weight: 700;
            color: #667eea;
            display: block;
            margin-bottom: 2px;
        }
        
        .booking-subtitle {
            font-size: 11px;
            color: #a0aec0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .services-cell {
            min-width: 300px;
        }
        
        .service-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        
        .service-tag {
            background: #edf2f7;
            color: #4a5568;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .service-tag.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .last-booking-cell {
            min-width: 140px;
            text-align: center;
        }
        
        .booking-date {
            font-weight: 600;
            color: #2d3748;
            font-size: 13px;
            margin-bottom: 2px;
        }
        
        .booking-time {
            color: #718096;
            font-size: 11px;
            margin-bottom: 4px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .status-cancelled {
            background: #fed7d7;
            color: #c53030;
        }
        
        .no-customers {
            text-align: center;
            padding: 80px 40px;
            color: #a0aec0;
        }
        
        .no-customers-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.4;
        }
        
        .no-customers h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: #718096;
        }
        
        .no-customers p {
            font-size: 14px;
        }
        
        .no-data {
            text-align: center;
            color: #a0aec0;
            padding: 40px 20px;
            font-style: italic;
        }
        
        /* Mobile Responsive */
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
            
            .customers-table {
                font-size: 12px;
            }
            
            .customers-table thead th,
            .customers-table tbody td {
                padding: 12px 8px;
            }
            
            .customer-info {
                min-width: 180px;
            }
            
            .customer-name {
                font-size: 14px;
            }
            
            .customer-email {
                font-size: 12px;
            }
            
            .services-cell {
                min-width: 200px;
            }
            
            .service-tags {
                flex-direction: column;
                gap: 4px;
            }
            
            .last-booking-cell {
                min-width: 100px;
            }
        }
        
        /* Scrollbar Styling */
        .customers-table-wrapper::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        .customers-table-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .customers-table-wrapper::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .customers-table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
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

        <!-- Professional Customers Section -->
        <div class="customers-section">
            <div class="customers-header">
                <h3 class="customers-title">
                    👥 All Customers
                    <span id="customersCount" class="customers-count">Loading...</span>
                </h3>
            </div>
            <div id="customersTableContainer" class="customers-table-wrapper">
                <div class="chart-loading">
                    <div class="spinner"></div>
                    <div>Loading customer data...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentBasicData = null;
        let currentCustomerData = null;
        let isLoading = false;

        function resetToLoadingState() {
            // Reset KPI cards to loading state
            document.querySelectorAll('.kpi-card').forEach(card => {
                card.classList.add('loading');
            });
            
            document.getElementById('totalBookings').textContent = '--';
            document.getElementById('uniqueCustomers').textContent = '--';
            document.getElementById('revenueEstimate').textContent = '€--';
            document.getElementById('avgDuration').textContent = '--';
            
            document.querySelectorAll('.kpi-change').forEach(change => {
                change.classList.remove('loaded');
                change.textContent = 'Loading...';
            });

            // Reset chart areas to loading state
            document.getElementById('servicePerformance').innerHTML = `
                <div class="chart-loading">
                    <div class="spinner"></div>
                    <div>Loading service data...</div>
                </div>
            `;
            
            document.getElementById('customersTableContainer').innerHTML = `
                <div class="chart-loading">
                    <div class="spinner"></div>
                    <div>Loading customer data...</div>
                </div>
            `;
            
            document.getElementById('statusBreakdown').innerHTML = `
                <div class="chart-loading">
                    <div class="spinner"></div>
                    <div>Loading status data...</div>
                </div>
            `;
            
            document.getElementById('customersCount').textContent = 'Loading...';
        }

        function showError(message) {
            isLoading = false;
            document.getElementById('errorState').style.display = 'block';
            document.getElementById('errorMessage').textContent = message;
            document.getElementById('refreshBtn').disabled = false;
        }

        function updateBasicKPIs(data) {
            // Update KPI values that don't require customer data
            document.getElementById('totalBookings').textContent = data.total_bookings;
            document.getElementById('revenueEstimate').textContent = '€' + data.revenue_estimate.toLocaleString();
            document.getElementById('avgDuration').textContent = data.avg_duration;

            // Update change indicators
            document.getElementById('bookingsChange').textContent = data.display_month;
            document.getElementById('revenueChange').textContent = 'Estimated';
            document.getElementById('durationChange').textContent = 'Per Session';

            // Update KPI cards (except customers)
            document.querySelectorAll('.kpi-card').forEach((card, index) => {
                if (index !== 1) { // Skip unique customers card
                    card.classList.remove('loading');
                }
            });
            
            // Update change classes (except customers)
            document.querySelectorAll('.kpi-change').forEach((change, index) => {
                if (index !== 1) { // Skip unique customers change
                    change.classList.add('loaded');
                }
            });
        }

        function updateCustomerKPIs(data) {
            document.getElementById('uniqueCustomers').textContent = data.unique_customers;
            document.getElementById('customersChange').textContent = data.unique_services_by_customers + ' Different Services';
            
            // Remove loading state from customer card
            document.querySelectorAll('.kpi-card')[1].classList.remove('loading');
            document.querySelectorAll('.kpi-change')[1].classList.add('loaded');
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

        function updateStatusBreakdown(statusBreakdown, totalBookings) {
            const container = document.getElementById('statusBreakdown');
            
            if (Object.keys(statusBreakdown).length === 0) {
                container.innerHTML = '<div class="no-data">No status data available</div>';
                return;
            }

            // Create canvas for chart
            container.innerHTML = '<canvas id="statusChart" width="400" height="200"></canvas>';
            
            const ctx = document.getElementById('statusChart').getContext('2d');
            
            // Prepare data
            const labels = Object.keys(statusBreakdown).map(status => 
                status.charAt(0).toUpperCase() + status.slice(1)
            );
            const data = Object.values(statusBreakdown);
            const percentages = data.map(count => 
                totalBookings > 0 ? Math.round((count / totalBookings) * 100) : 0
            );
            
            // Corporate color scheme
            const colors = [
                'rgba(102, 126, 234, 0.8)',  // Primary blue
                'rgba(118, 75, 162, 0.8)',   // Purple
                'rgba(34, 197, 94, 0.8)',    // Green
                'rgba(239, 68, 68, 0.8)',    // Red
                'rgba(245, 158, 11, 0.8)',   // Orange
                'rgba(107, 114, 128, 0.8)'   // Gray
            ];
            
            const borderColors = [
                'rgba(102, 126, 234, 1)',
                'rgba(118, 75, 162, 1)', 
                'rgba(34, 197, 94, 1)',
                'rgba(239, 68, 68, 1)',
                'rgba(245, 158, 11, 1)',
                'rgba(107, 114, 128, 1)'
            ];

            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors.slice(0, labels.length),
                        borderColor: borderColors.slice(0, labels.length),
                        borderWidth: 2,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: {
                                    size: 13,
                                    family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                                },
                                generateLabels: function(chart) {
                                    const data = chart.data;
                                    return data.labels.map((label, i) => ({
                                        text: `${label}: ${data.datasets[0].data[i]} (${percentages[i]}%)`,
                                        fillStyle: data.datasets[0].backgroundColor[i],
                                        strokeStyle: data.datasets[0].borderColor[i],
                                        lineWidth: 2,
                                        hidden: false,
                                        index: i
                                    }));
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const percentage = percentages[context.dataIndex];
                                    return `${label}: ${value} bookings (${percentage}%)`;
                                }
                            },
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: 'white',
                            bodyColor: 'white',
                            borderColor: 'rgba(102, 126, 234, 1)',
                            borderWidth: 1
                        }
                    },
                    animation: {
                        animateRotate: true,
                        duration: 1000
                    }
                }
            });
        }

        function updateCustomersTable(customerData, displayMonth) {
            const container = document.getElementById('customersTableContainer');
            const countBadge = document.getElementById('customersCount');
            
            if (!customerData || !customerData.top_customers || Object.keys(customerData.top_customers).length === 0) {
                container.innerHTML = `
                    <div class="no-customers">
                        <div class="no-customers-icon">👥</div>
                        <h3>No customer data available</h3>
                        <p>Customer details are still loading or unavailable for this period.</p>
                    </div>
                `;
                countBadge.textContent = '0 customers';
                return;
            }

            const customerCount = Object.keys(customerData.top_customers).length;
            countBadge.textContent = `${customerCount} customer${customerCount !== 1 ? 's' : ''} - ${displayMonth}`;

            // Create professional table
            let tableHtml = `
                <table class="customers-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Bookings</th>
                            <th>Services</th>
                            <th>Service Breakdown</th>
                            <th>Last Booking</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            Object.entries(customerData.top_customers).forEach(([email, count]) => {
                const customerName = (customerData.customer_names && customerData.customer_names[email]) || 'Unknown';
                const services = (customerData.customer_services && customerData.customer_services[email]) || [];
                const details = (customerData.customer_details && customerData.customer_details[email]) || [];
                
                // Get service breakdown
                const serviceBreakdown = {};
                details.forEach(booking => {
                    serviceBreakdown[booking.service] = (serviceBreakdown[booking.service] || 0) + 1;
                });
                
                // Find last booking
                let lastBooking = null;
                if (details.length > 0) {
                    lastBooking = details.sort((a, b) => new Date(b.date) - new Date(a.date))[0];
                }
                
                // Format last booking date
                let lastBookingHtml = '<span style="color: #a0aec0;">No bookings</span>';
                if (lastBooking && lastBooking.date) {
                    try {
                        const date = new Date(lastBooking.date);
                        const dateStr = date.toLocaleDateString('de-DE', { 
                            day: '2-digit', 
                            month: '2-digit', 
                            year: 'numeric'
                        });
                        const timeStr = date.toLocaleTimeString('de-DE', { 
                            hour: '2-digit', 
                            minute: '2-digit'
                        });
                        const statusClass = lastBooking.status === 'active' ? 'status-active' : 'status-cancelled';
                        
                        lastBookingHtml = `
                            <div class="booking-date">${dateStr}</div>
                            <div class="booking-time">${timeStr}</div>
                            <div style="margin-top: 6px;">
                                <span class="status-badge ${statusClass}">${lastBooking.status}</span>
                            </div>
                        `;
                    } catch (e) {
                        lastBookingHtml = '<span style="color: #e53e3e;">Invalid date</span>';
                    }
                }
                
                // Create service tags
                const serviceTags = Object.entries(serviceBreakdown)
                    .map(([service, sCount], index) => {
                        const tagClass = index === 0 ? 'service-tag primary' : 'service-tag';
                        return `<span class="${tagClass}">${service} (${sCount})</span>`;
                    })
                    .join('') || '<span class="service-tag">No services</span>';
                
                tableHtml += `
                    <tr>
                        <td class="customer-info">
                            <div class="customer-name">${customerName}</div>
                            <div class="customer-email">${email}</div>
                        </td>
                        <td class="booking-stats-cell">
                            <span class="booking-number">${count}</span>
                            <span class="booking-subtitle">booking${count > 1 ? 's' : ''}</span>
                        </td>
                        <td class="booking-stats-cell">
                            <span class="booking-number">${services.length}</span>
                            <span class="booking-subtitle">service${services.length > 1 ? 's' : ''}</span>
                        </td>
                        <td class="services-cell">
                            <div class="service-tags">${serviceTags}</div>
                        </td>
                        <td class="last-booking-cell">
                            ${lastBookingHtml}
                        </td>
                    </tr>
                `;
            });

            tableHtml += `
                    </tbody>
                </table>
            `;

            container.innerHTML = tableHtml;
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
                    throw new Error(result.error || 'Failed to load basic data');
                }

                currentBasicData = result.data;
                
                // Update UI elements that don't need customer data
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
                console.log('Customer API response status:', response.status);
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const result = await response.json();
                console.log('Customer data result:', result);

                if (!result.success) {
                    throw new Error(result.error || 'Failed to load customer data');
                }

                currentCustomerData = result.data;
                console.log('Setting currentCustomerData to:', currentCustomerData);
                
                // Update customer-related UI
                updateCustomerKPIs(currentCustomerData);
                updateCustomersTable(currentCustomerData, currentBasicData.display_month);
                
                return true;
                
            } catch (error) {
                console.error('Error loading customer data:', error);
                // Don't fail the whole thing for customer data
                document.getElementById('uniqueCustomers').textContent = '?';
                document.getElementById('customersChange').textContent = 'Error loading';
                document.getElementById('customersTableContainer').innerHTML = '<div class="no-data">Customer data unavailable - ' + error.message + '</div>';
                document.getElementById('customersCount').textContent = 'Error loading';
                return false;
            }
        }

        async function loadAnalyticsData() {
            if (isLoading) return;
            
            isLoading = true;
            document.getElementById('refreshBtn').disabled = true;
            document.getElementById('errorState').style.display = 'none';
            
            // Reset all areas to loading state
            resetToLoadingState();
            
            try {
                // STAGE 1: Load basic data (fast, no invitee calls)
                await loadBasicData();
                
                // STAGE 2: Load customer data (slower, needs invitee calls)
                await loadCustomerData();
                
            } catch (error) {
                console.error('Error loading analytics:', error);
                showError(`${error.message} - Check console for details`);
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