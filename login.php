<?php
require __DIR__.'/customer/auth.php';
$pdo = getPDO();
$error = '';
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $email = trim($_POST['email'] ?? '');
    $pin = trim($_POST['pin'] ?? '');
    if($email === '' || $pin === ''){
        $error = 'Please enter email and PIN.';
    }else{
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE email = ?');
        $stmt->execute([$email]);
        $cust = $stmt->fetch(PDO::FETCH_ASSOC);
        if(!$cust){
            $error = 'Invalid email or PIN.';
        }elseif(empty($cust['pin']) || empty($cust['pin_expires']) || strtotime($cust['pin_expires']) < time()){
            $error = 'PIN expired or invalid.';
        }elseif(!password_verify($pin, $cust['pin'])){
            $error = 'Invalid email or PIN.';
        }else{
            create_customer_session($cust['id']);
            $upd = $pdo->prepare('UPDATE customers SET pin = NULL, pin_expires = NULL, last_login = NOW() WHERE id = ?');
            $upd->execute([$cust['id']]);
            header('Location: customer/index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer Login</title>
    <style>
        body{font-family:Arial;margin:2em;color:#333}
        label{display:block;margin-top:1em}
        input{padding:.4em;width:100%;max-width:300px}
        button{margin-top:1em;padding:.5em 1em;background:#4a90b8;color:#fff;border:none;cursor:pointer}
        a{color:#52b3a4}
    </style>
</head>
<body>
<h2 style="color:#4a90b8">Customer Login</h2>
<?php if($error): ?><p style="color:red;"><?=$error?></p><?php endif; ?>
<?php if(!empty($_GET['message'])): ?>
<div style='background:#d4edda;color:#155724;padding:1rem;border-radius:5px;margin-bottom:1rem;'>
    ✅ <?=htmlspecialchars($_GET['message'])?>
</div>
<?php endif; ?>
<form method="post">
    <label>Email<br><input type="email" name="email" required></label>
    <label>PIN<br><input type="password" name="pin" required></label>
    <button type="submit">Login</button>
</form>
</body>
</html>
