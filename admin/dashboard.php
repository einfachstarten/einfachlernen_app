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
    <a href="migrate.php">Database Migration</a>
    <a href="?logout=1">Logout</a>
</nav>
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
    <tr><th>Email</th><th>Name</th><th>Phone</th><th>Status</th><th>Created</th><th>PIN Status</th><th>Action</th></tr>
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
    </tr>
    <?php endforeach; ?>
</table>
</body>
</html>
