<?php
/**
 * 🧪 BETA: HTTP Endpoint für Last-Minute Checker
 *
 * Dieser Endpoint wird vom Cron-Job aufgerufen, wenn CLI-PHP kein PDO hat.
 * Geschützt durch Secret-Key oder IP-Whitelist.
 *
 * Usage: curl https://domain.de/admin/last_minute_checker_cron.php
 */

// Security: Allow localhost, same server, or with secret key
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
$isLocalhost = in_array($remoteAddr, ['127.0.0.1', '::1', 'localhost'], true);
$isSameServer = ($remoteAddr === $serverAddr); // Allow calls from same server
$hasValidSecret = !empty($_GET['secret']) && getenv('CRON_SECRET') && $_GET['secret'] === getenv('CRON_SECRET');

if (!$isLocalhost && !$isSameServer && !$hasValidSecret) {
    http_response_code(403);
    die('❌ Access denied. This endpoint is for cron jobs only.');
}

// Prevent browser caching
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/plain; charset=utf-8');

// Execute the checker
echo "🧪 Last-Minute Checker - HTTP Execution\n";
echo "========================================\n\n";

// Include the main checker script
require_once __DIR__ . '/last_minute_checker.php';

echo "\n✅ Execution completed.\n";
