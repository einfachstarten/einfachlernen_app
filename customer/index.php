<?php
require __DIR__.'/auth.php';

// Customer session timeout: 4 hours
if(isset($_SESSION['customer_last_activity']) && (time() - $_SESSION['customer_last_activity'] > 14400)){
    destroy_customer_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    header('Location: ../login.php?message=' . urlencode('Session expired. Please login again.'));
    exit;
}

if(isset($_GET['logout']) && !empty($_SESSION['customer'])){
    require_once __DIR__ . '/../admin/ActivityLogger.php';
    $pdo = getPDO();
    $logger = new ActivityLogger($pdo);
    $logger->logActivity($_SESSION['customer']['id'], 'logout', [
        'logout_method' => 'manual',
        'session_duration' => time() - ($_SESSION['customer_login_time'] ?? time())
    ]);

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    destroy_customer_session();
    session_destroy();
    header('Location: ../login.php?message=' . urlencode('Successfully logged out'));
    exit;
}
$customer = require_customer_login();

if(!empty($_SESSION['customer'])) {
    // Update last activity timestamp
    $_SESSION['customer_last_activity'] = time();

    // Refresh customer data from database
    $pdo = getPDO();
    $customer_id = $_SESSION['customer']['id'];
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $current_customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if($current_customer) {
        $_SESSION['customer'] = $current_customer;
        $customer = $current_customer;
    }
}
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
<nav><a href="?logout=1" onclick="return confirm('Are you sure you want to logout?')">Logout</a></nav>
<p>Email: <?=htmlspecialchars($customer['email'])?></p>
<p>Name: <?=htmlspecialchars(trim($customer['first_name'].' '.$customer['last_name']))?></p>
<p>Phone: <?=htmlspecialchars($customer['phone'])?></p>
<p>Status: <?=htmlspecialchars($customer['status'])?></p>
<?php if(!empty($customer['last_login'])): ?>
<p>Last login: <?=htmlspecialchars($customer['last_login'])?></p>
<?php endif; ?>
</body>
</html>
