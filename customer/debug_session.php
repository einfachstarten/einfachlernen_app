<?php
require __DIR__.'/auth.php';

header('Content-Type: application/json');

$debug_info = [
    'timestamp' => date('Y-m-d H:i:s'),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'is_ios' => strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'iPhone') !== false || strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'iPad') !== false,
    'session_id' => session_id(),
    'cookies' => $_COOKIE,
    'session_data' => [
        'customer_id' => $_SESSION['customer_id'] ?? null,
        'session_authenticated' => $_SESSION['session_authenticated'] ?? false,
        'customer_login_time' => $_SESSION['customer_login_time'] ?? null,
        'customer_last_activity' => $_SESSION['customer_last_activity'] ?? null,
        'customer_data_exists' => isset($_SESSION['customer']),
        'db_session_token' => isset($_SESSION['db_session_token']) ? 'set' : 'not set'
    ],
    'current_customer' => get_current_customer() ? 'found' : 'not found',
    'session_status' => session_status() === PHP_SESSION_ACTIVE ? 'active' : 'inactive'
];

echo json_encode($debug_info, JSON_PRETTY_PRINT);
?>
