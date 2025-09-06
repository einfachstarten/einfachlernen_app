<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}
if(isset($_SESSION['LAST_ACTIVITY']) && time() - $_SESSION['LAST_ACTIVITY'] > 1800){session_unset();session_destroy();header('Location: login.php');exit;}
$_SESSION['LAST_ACTIVITY'] = time();
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
        die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
    }
}
$pdo = getPDO();
if(isset($_GET['logout'])){session_destroy();header('Location: login.php');exit;}
$config = require __DIR__ . '/config.php';
if(!empty($config['PIN_CLEANUP_EXPIRED'])) {
    $cleanup = $pdo->prepare("UPDATE customers SET pin = NULL, pin_expires = NULL WHERE pin_expires < NOW() AND pin IS NOT NULL");
    $cleanup->execute();
}
$countStmt = $pdo->query('SELECT COUNT(*) FROM customers');
$total = $countStmt->fetchColumn();
$customers = $pdo->query('SELECT * FROM customers ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
foreach($customers as &$c) {
    if(!empty($c['pin']) && !empty($c['pin_expires'])){
        $expires_timestamp = strtotime($c['pin_expires']);
        $now = time();
        if($expires_timestamp < $now){
            $c['pin_status'] = '<span style="color:#dc3545">🔴 PIN Expired (' . date('H:i', $expires_timestamp) . ')</span>';
            $c['pin_status_raw'] = 'expired';
        } else {
            $remaining_minutes = round(($expires_timestamp - $now) / 60);
            if($remaining_minutes > 0) {
                $c['pin_status'] = '<span style="color:#28a745">🟢 PIN Active (' . $remaining_minutes . ' min left)</span>';
            } else {
                $c['pin_status'] = '<span style="color:#ffc107">🟡 PIN Expiring (&lt;1 min)</span>';
            }
            $c['pin_status_raw'] = 'active';
        }
    } else {
        $c['pin_status'] = '<span style="color:#6c757d">⚪ No PIN sent</span>';
        $c['pin_status_raw'] = 'none';
    }
}
unset($c);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
    <style>
        body{font-family:Arial;margin:2em}
        nav a{margin-right:1em;color:#52b3a4}
        table{width:100%;border-collapse:collapse}
        th,td{border:1px solid #ccc;padding:.4em;text-align:left}
        th{background:#4a90b8;color:#fff}
    </style>
</head>
<body>
<header><h2 style="color:#4a90b8">Dashboard</h2></header>
<nav>
    <a href="add_customer.php">Add Customer</a>
    <a href="test_mail.php">Test Email</a>
    <a href="analytics.php">Customer Analytics</a>
    <a href="migrate.php">Database Migration</a>
    <a href="?logout=1">Logout</a>
</nav>
<?php
$app_version = '2.1.0'; // Keep in sync with SW
$manifest_path = __DIR__ . '/../manifest.json';
$sw_path = __DIR__ . '/../sw.js';

echo "<div style='background:#e7f3ff;padding:1rem;margin:1rem 0;border:1px solid #0066cc;'>";
echo "<h4>PWA Version Info</h4>";
echo "<p><strong>App Version:</strong> $app_version</p>";
echo "<p><strong>Manifest Last Modified:</strong> " . (file_exists($manifest_path) ? date('Y-m-d H:i:s', filemtime($manifest_path)) : 'Not found') . "</p>";
echo "<p><strong>Service Worker Last Modified:</strong> " . (file_exists($sw_path) ? date('Y-m-d H:i:s', filemtime($sw_path)) : 'Not found') . "</p>";
echo "<button onclick='forceClientUpdates()'>🚨 Force Update</button> ";
echo "<button onclick='clearAllCaches()'>🗑️ Force Clear All Caches</button>";
echo "</div>";
?>
<?php if(!empty($_GET['success'])): ?>
    <div style="background:#d4edda;color:#155724;padding:1rem;border-radius:5px;margin-bottom:1rem;">
        ✅ <?=htmlspecialchars($_GET['success'])?>
    </div>
<?php elseif(!empty($_GET['error'])): ?>
    <div style="background:#f8d7da;color:#721c24;padding:1rem;border-radius:5px;margin-bottom:1rem;">
        ❌ <?=htmlspecialchars($_GET['error'])?>
    </div>
<?php endif; ?>
<p>Total customers: <?=$total?></p>
<table>
    <tr><th>Email</th><th>Name</th><th>Phone</th><th>Status</th><th>Created</th><th>PIN Status</th><th>Action</th><th>Activity</th></tr>
    <?php foreach($customers as $c): ?>
    <tr>
        <td><?=htmlspecialchars($c['email'])?></td>
        <td><?=htmlspecialchars(trim($c['first_name'].' '.$c['last_name']))?></td>
        <td><?=htmlspecialchars($c['phone'])?></td>
        <td><?=htmlspecialchars($c['status'])?></td>
        <td><?=htmlspecialchars($c['created_at'])?></td>
        <td><?=$c['pin_status']?></td>
        <td>
            <form method="post" action="send_pin.php" style="margin:0;">
                <input type="hidden" name="customer_id" value="<?=$c['id']?>">
                <button type="submit">Send PIN</button>
            </form>
        </td>
        <td><a href='?view_activity=<?=$c['id']?>'>View Activity</a></td>
    </tr>
    <?php endforeach; ?>
</table>

<?php
// ORIGINAL VIEW_ACTIVITY CODE (keep existing but add error handling)
if (isset($_GET['view_activity']) && is_numeric($_GET['view_activity'])) {
    echo "<div style='margin-top:2rem;'>";
    echo "<h3>Recent Activity for Customer ID: " . (int)$_GET['view_activity'] . "</h3>";

    try {
        require_once 'ActivityLogger.php';
        $customer_id = (int)$_GET['view_activity'];
        $logger = new ActivityLogger($pdo);
        $activities = $logger->getCustomerActivities($customer_id, 20);

        if (empty($activities)) {
            echo "<div style='background:#fff3cd;color:#856404;padding:1rem;border-radius:5px;'>";
            echo "<p>⚠️ No activities found for this customer.</p>";
            echo "<p>This could mean:</p>";
            echo "<ul>";
            echo "<li>Customer hasn't logged in yet</li>";
            echo "<li>No PINs have been sent to this customer</li>";
            echo "<li>Activities were logged before the tracking system was implemented</li>";
            echo "</ul>";
            echo "</div>";
        } else {
            echo "<table border='1' cellpadding='8' style='border-collapse: collapse; width: 100%; margin: 1rem 0;'>";
            echo "<tr style='background: #4a90b8; color: white;'>";
            echo "<th>Date/Time</th><th>Activity Type</th><th>Details</th><th>IP Address</th><th>Session ID</th>";
            echo "</tr>";

            foreach ($activities as $activity) {
                $data = json_decode($activity['activity_data'], true);
                $details = [];
                if ($data) {
                    foreach ($data as $key => $value) {
                        if (is_bool($value)) {
                            $value = $value ? 'true' : 'false';
                        }
                        $details[] = "$key: $value";
                    }
                }

                echo "<tr>";
                echo "<td>" . date('Y-m-d H:i:s', strtotime($activity['created_at'])) . "</td>";
                echo "<td><strong>" . ucfirst(str_replace('_', ' ', $activity['activity_type'])) . "</strong></td>";
                echo "<td>" . (empty($details) ? '—' : implode('<br>', $details)) . "</td>";
                echo "<td>" . htmlspecialchars($activity['ip_address']) . "</td>";
                echo "<td style='font-size:0.8em;'>" . htmlspecialchars(substr($activity['session_id'], 0, 10)) . "...</td>";
                echo "</tr>";
            }
            echo "</table>";
        }

    } catch (Exception $e) {
        echo "<div style='background:#f8d7da;color:#721c24;padding:1rem;border-radius:5px;'>";
        echo "<h4>Error Loading Activities</h4>";
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p>Please check the error logs or contact support.</p>";
        echo "</div>";
    }

    echo "<p style='margin-top:1rem;'>";
    echo "<a href='dashboard.php' style='background:#52b3a4;color:white;padding:0.5rem 1rem;text-decoration:none;border-radius:3px;'>← Back to Dashboard</a>";
    echo "</p>";
    echo "</div>";
}
?>
<script src="../pwa-update.js"></script>
</body>
</html>
