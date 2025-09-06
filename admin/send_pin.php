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
        echo "<h2 style='color:red'>❌ Customer not found</h2>";
        echo "<p><a href='dashboard.php?error=".urlencode('Customer not found')."'>← Back to Dashboard</a></p>";
        exit;
    }

    $pin = sprintf('%06d', random_int(100000, 999999));
    $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $upd = $pdo->prepare('UPDATE customers SET pin = ?, pin_expires = ? WHERE id = ?');
    $upd->execute([$pin_hash, $expires, $cid]);

    $subject = 'Ihr Zugangspin';
    $message = "Hallo {$cust['first_name']},\n\nIhr PIN lautet: $pin\nEr ist gültig bis $expires.\n\nViele Grüße\nAnna Braun Lerncoaching";
    $headers = [
        'From: Anna Braun Lerncoaching <noreply@einfachstarten.jetzt>',
        'Reply-To: info@einfachlernen.jetzt',
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0'
    ];
    $headers_string = implode("\r\n", $headers);

    if(mail($cust['email'], $subject, $message, $headers_string)){
        echo "<h2 style='color:green'>✅ PIN Sent Successfully</h2>";
        echo "<p><strong>Recipient:</strong> " . htmlspecialchars($cust['email']) . "</p>";
        echo "<p><strong>PIN:</strong> $pin (for testing only)</p>";
        echo "<p><strong>Expires:</strong> $expires</p>";
        echo "<p><a href='dashboard.php?success=" . urlencode('PIN sent to ' . $cust['email']) . "'>← Back to Dashboard</a></p>";
    } else {
        echo "<h2 style='color:red'>❌ Email Sending Failed</h2>";
        echo "<p><strong>Recipient:</strong> " . htmlspecialchars($cust['email']) . "</p>";
        $error = error_get_last();
        echo "<p><strong>Error:</strong> " . ($error['message'] ?? 'Unknown mail error') . "</p>";
        echo "<p><strong>Server:</strong> " . $_SERVER['SERVER_NAME'] . "</p>";
        echo "<p><a href='dashboard.php?error=" . urlencode('Email sending failed') . "'>← Back to Dashboard</a></p>";
    }
    exit;
}
echo "<h2 style='color:red'>❌ Invalid request</h2>";
echo "<p><a href='dashboard.php?error=" . urlencode('Invalid request') . "'>← Back to Dashboard</a></p>";
exit;
?>
