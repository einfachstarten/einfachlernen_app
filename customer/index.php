<?php
require __DIR__.'/auth.php';
if(isset($_GET['logout'])){
    destroy_customer_session();
    header('Location: /login.php');
    exit;
}
$customer = require_customer_login();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Area</title>
    <style>
        body{font-family:Arial;margin:2em;color:#333}
        a{color:#52b3a4}
        header{color:#4a90b8}
    </style>
</head>
<body>
<header><h2>Welcome, <?=htmlspecialchars($customer['first_name'])?></h2></header>
<nav><a href="?logout=1">Logout</a></nav>
<p>Email: <?=htmlspecialchars($customer['email'])?></p>
<p>Name: <?=htmlspecialchars(trim($customer['first_name'].' '.$customer['last_name']))?></p>
<p>Phone: <?=htmlspecialchars($customer['phone'])?></p>
<p>Status: <?=htmlspecialchars($customer['status'])?></p>
<?php if(!empty($customer['last_login'])): ?>
<p>Last login: <?=htmlspecialchars($customer['last_login'])?></p>
<?php endif; ?>
</body>
</html>
