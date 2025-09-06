<?php
require __DIR__.'/auth.php';

header('Content-Type: application/json');

$debug_info = [
    'timestamp' => date('Y-m-d H:i:s'),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'is_ios' => strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'iPhone') !== false || strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'iPad') !== false,
    'cookies' => $_COOKIE,
    'session_data' => [
        'customer_id' => $_SESSION['customer_id'] ?? null,
        'customer_token' => isset($_SESSION['customer_token']) ? substr($_SESSION['customer_token'], 0, 10) . '...' : null,
        'customer' => isset($_SESSION['customer']) ? 'set' : 'not set',
        'session_id' => session_id()
    ],
    'current_customer' => get_current_customer() ? 'found' : 'not found'
];

echo json_encode($debug_info, JSON_PRETTY_PRINT);
?>
