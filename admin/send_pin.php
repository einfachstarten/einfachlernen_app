<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}
function getPDO(){
    $config = require __DIR__ . '/config.php';
    try{
        return new PDO(
            "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }catch(PDOException $e){
        die('DB connection failed: '.htmlspecialchars($e->getMessage()));
    }
}
if($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['customer_id'])){
    $cid = (int)$_POST['customer_id'];
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT email, first_name FROM customers WHERE id = ?');
    $stmt->execute([$cid]);
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$cust){
        header('Location: dashboard.php?error='.urlencode('Customer not found'));
        exit;
    }
    $pin = sprintf('%06d', random_int(100000, 999999));
    $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $upd = $pdo->prepare('UPDATE customers SET pin = ?, pin_expires = ? WHERE id = ?');
    $upd->execute([$pin_hash, $expires, $cid]);
    $subject = 'Ihr Zugangspin';
    $message = "Hallo {$cust['first_name']},\n\nIhr PIN lautet: $pin\nEr ist gültig bis $expires.\n\nViele Grüße\nAnna Braun Lerncoaching";
    $headers = 'From: Anna Braun Lerncoaching <no-reply@einfachlernen.app>';
    if(mail($cust['email'], $subject, $message, $headers)){
        header('Location: dashboard.php?success='.urlencode('PIN sent'));
    }else{
        header('Location: dashboard.php?error='.urlencode('Email sending failed'));
    }
    exit;
}
header('Location: dashboard.php?error='.urlencode('Invalid request'));
exit;
?>
