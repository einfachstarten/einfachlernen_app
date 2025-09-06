<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}
function getPDO(){
    $host=getenv('DB_HOST')?:'localhost';
    $db=getenv('DB_NAME')?:'app';
    $user=getenv('DB_USER')?:'root';
    $pass=getenv('DB_PASS')?:'';
    try{return new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
    catch(PDOException $e){die('DB connection failed: '.htmlspecialchars($e->getMessage()));}
}
$pdo=getPDO();
if(isset($_GET['logout'])){session_destroy();header('Location: login.php');exit;}
$customers=$pdo->query('SELECT * FROM customers ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Dashboard</title>
<style>body{font-family:Arial;margin:2em}nav a{margin-right:1em;color:#52b3a4}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:.4em}th{background:#4a90b8;color:#fff}</style>
</head>
<body>
<header><h2 style="color:#4a90b8">Dashboard</h2></header>
<nav>
<a href="add_customer.php">Add Customer</a>
<a href="?logout=1">Logout</a>
</nav>
<table>
<tr><th>Email</th><th>Name</th><th>Phone</th><th>Status</th></tr>
<?php foreach($customers as $c): ?>
<tr>
<td><?=htmlspecialchars($c['email'])?></td>
<td><?=htmlspecialchars($c['first_name'].' '.$c['last_name'])?></td>
<td><?=htmlspecialchars($c['phone'])?></td>
<td><?=htmlspecialchars($c['status'])?></td>
</tr>
<?php endforeach; ?>
</table>
</body></html>
