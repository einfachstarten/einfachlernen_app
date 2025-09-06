<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>Analytics Debug Mode</h1>";
echo "<p>Starting analytics page load...</p>";

session_start();
if (empty($_SESSION['admin'])) {
    echo "<p>Redirecting to login...</p>";
    header('Location: login.php');
    exit;
}
echo "<p>✅ Admin session verified</p>";

function getPDO()
{
    echo "<p>Debug: Connecting to database...</p>";
    $config = require __DIR__ . '/config.php';
    try {
        $pdo = new PDO(
            "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "<p>✅ Database connected successfully</p>";
        return $pdo;
    } catch (PDOException $e) {
        echo "<p style='color:red'>❌ DB connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        die('Database connection failed');
    }
}

$activityLoggerPath = __DIR__ . '/ActivityLogger.php';
if (!file_exists($activityLoggerPath)) {
    die("❌ ActivityLogger.php not found at: $activityLoggerPath");
}
if (!is_readable($activityLoggerPath)) {
    die("❌ ActivityLogger.php not readable at: $activityLoggerPath");
}
echo "<p>✅ ActivityLogger.php found and readable</p>";
echo "<p>Debug: Including ActivityLogger...</p>";
require_once 'ActivityLogger.php';

echo "<p>Debug: Creating PDO connection...</p>";
$pdo = getPDO();

echo "<p>Debug: Creating ActivityLogger instance...</p>";
$logger = new ActivityLogger($pdo);

// Add test logging capability
if (isset($_GET['test_activity'])) {
    echo "<h3>Testing Activity Logging</h3>";

    // Find a customer to test with
    $test_customer = $pdo->query("SELECT id FROM customers LIMIT 1")->fetchColumn();

    if ($test_customer) {
        $logger->logActivity($test_customer, 'test_activity', [
            'test_source' => 'analytics_debug',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        echo "<p style='color:green'>✅ Test activity logged for customer $test_customer</p>";
        echo "<p><a href='analytics.php'>Refresh Analytics</a></p>";
    } else {
        echo "<p style='color:red'>❌ No customers found to test with</p>";
    }
    exit;
}

$days = $_GET['days'] ?? 30;
echo "<p>Debug: Getting stats for $days days...</p>";

echo "<p>Debug: Calling getActivityStats...</p>";
$stats = $logger->getActivityStats($days);

echo "<p>Debug: Calling getTopActiveCustomers...</p>";
$top_customers = $logger->getTopActiveCustomers($days);

echo "<p>Debug: Stats count: " . count($stats) . "</p>";
echo "<p>Debug: Top customers count: " . count($top_customers) . "</p>";

// Add test query to verify data exists
echo "<h3>Debug: Raw Data Check</h3>";
try {
    $test_stmt = $pdo->query("SELECT COUNT(*) as total FROM customer_activities");
    $total_activities = $test_stmt->fetchColumn();
    echo "<p>Total activities in database: $total_activities</p>";

    $recent_stmt = $pdo->query("SELECT * FROM customer_activities ORDER BY created_at DESC LIMIT 5");
    $recent = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Recent activities:</p>";
    echo "<pre>" . print_r($recent, true) . "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Test query failed: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Add fallback for empty results
if (empty($stats) && empty($top_customers)) {
    echo "<div style='background:#fff3cd;padding:1rem;margin:1rem 0;'>";
    echo "<h3>⚠️ No Analytics Data Found</h3>";
    echo "<p>Possible causes:</p>";
    echo "<ul>";
    echo "<li>No customer activities in the selected time period</li>";
    echo "<li>Database query issues</li>";
    echo "<li>Date range too restrictive</li>";
    echo "</ul>";
    echo "<p>Try selecting a longer time period or check if activities are being logged.</p>";
    echo "</div>";
}

echo "<p><a href='?test_activity=1'>🧪 Test Activity Logging</a></p>";

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

// Activity Statistics
echo "<h2>Activity Overview (Last $days days)</h2>";
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
    echo "<tr>";
    echo "<td>" . ucfirst(str_replace('_', ' ', $type)) . "</td>";
    echo "<td>{$data['count']}</td>";
    echo "<td>$unique_customers</td>";
    echo "<td>$avg_per_day</td>";
    echo "</tr>";
}
echo "</table>";

// Top Active Customers
echo "<h2>Most Active Customers (Last $days days)</h2>";
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

?>

