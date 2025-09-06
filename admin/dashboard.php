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
$countStmt = $pdo->query('SELECT COUNT(*) FROM customers');
$total = $countStmt->fetchColumn();
$customers = $pdo->query('SELECT * FROM customers ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
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
    <a href="?logout=1">Logout</a>
</nav>
<?php if(!empty($_GET['success'])): ?>
    <p style="color:green;"><?=htmlspecialchars($_GET['success'])?></p>
<?php elseif(!empty($_GET['error'])): ?>
    <p style="color:red;"><?=htmlspecialchars($_GET['error'])?></p>
<?php endif; ?>
<p>Total customers: <?=$total?></p>
<table>
    <tr><th>Email</th><th>Name</th><th>Phone</th><th>Status</th><th>Created</th><th>PIN Status</th><th>Action</th></tr>
    <?php foreach($customers as $c): ?>
    <?php
        $pinStatus = 'No PIN';
        if(!empty($c['pin']) && !empty($c['pin_expires'])){
            if(strtotime($c['pin_expires']) < time()){
                $pinStatus = 'PIN expired';
            }else{
                $pinStatus = 'PIN sent (expires: '.htmlspecialchars($c['pin_expires']).')';
            }
        }
    ?>
    <tr>
        <td><?=htmlspecialchars($c['email'])?></td>
        <td><?=htmlspecialchars(trim($c['first_name'].' '.$c['last_name']))?></td>
        <td><?=htmlspecialchars($c['phone'])?></td>
        <td><?=htmlspecialchars($c['status'])?></td>
        <td><?=htmlspecialchars($c['created_at'])?></td>
        <td><?=$pinStatus?></td>
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
