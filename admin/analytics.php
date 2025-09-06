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

$activity_descriptions = [
    'login' => 'Login (PIN)',
    'login_failed' => 'Login Failed',
    'logout' => 'Logout',
    'pin_request' => 'PIN Requested',
    'session_timeout' => 'Session Timeout',
    'dashboard_accessed' => 'Dashboard Accessed',
    'profile_refreshed' => 'Profile Refreshed',
    'page_view' => 'Page View',
    'slots_api_called' => 'Slot Search API',
    'service_viewed' => 'Service Viewed',
    'availability_checked' => 'Availability Checked',
    'slots_found' => 'Slots Found',
    'slots_not_found' => 'No Slots Available',
    'slot_search_failed' => 'Slot Search Error',
    'booking_initiated' => 'Real Booking Started',
    'booking_completed' => 'Booking Confirmed',
    'booking_failed' => 'Booking Failed'
];

echo "<h1>Customer Activity Analytics</h1>";
echo "<nav><a href='dashboard.php'>← Back to Dashboard</a></nav>";

echo "<div style='margin: 20px 0;'>";
echo "<label>Time Period: </label>";
echo "<select onchange='window.location.href=\"?days=\" + this.value'>";
$periods = [7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'];
foreach ($periods as $value => $label) {
    $selected = ($days == $value) ? 'selected' : '';
    echo "<option value='$value' $selected>$label</option>";
}
echo "</select>";
echo "</div>";

echo "<h2>Activity Overview (Last $days days)</h2>";
if (empty($stats)) {
    echo "<p>No activity data found.</p>";
} else {
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Activity Type</th><th>Total Events</th><th>Unique Customers</th><th>Avg per Day</th></tr>";

    $activity_totals = [];
    foreach ($stats as $stat) {
        $type = $stat['activity_type'];
        if (!isset($activity_totals[$type])) {
            $activity_totals[$type] = ['count' => 0, 'customers' => []];
        }
        $activity_totals[$type]['count'] += $stat['count'];
        $activity_totals[$type]['customers'][] = $stat['unique_customers'];
    }

    foreach ($activity_totals as $type => $data) {
        $avg_per_day = round($data['count'] / $days, 1);
        $unique_customers = max($data['customers']);
        $display_name = $activity_descriptions[$type] ?? ucfirst(str_replace('_', ' ', $type));
        echo "<tr>";
        echo "<td>{$display_name}</td>";
        echo "<td>{$data['count']}</td>";
        echo "<td>$unique_customers</td>";
        echo "<td>$avg_per_day</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<h2>Most Active Customers (Last $days days)</h2>";
if (empty($top_customers)) {
    echo "<p>No customer activity found.</p>";
} else {
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Customer</th><th>Email</th><th>Activities</th><th>Last Activity</th></tr>";
    foreach ($top_customers as $customer) {
        echo "<tr>";
        echo "<td>{$customer['first_name']} {$customer['last_name']}</td>";
        echo "<td>{$customer['email']}</td>";
        echo "<td>{$customer['activity_count']}</td>";
        echo "<td>" . date('Y-m-d H:i', strtotime($customer['last_activity'])) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

?>

