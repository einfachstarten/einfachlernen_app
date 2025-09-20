<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}

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

function respond(bool $success, string $message): void {
    $param = $success ? 'success' : 'error';
    header('Location: dashboard.php?' . $param . '=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['customer_id'])) {
    respond(false, 'Invalid beta toggle request');
}

$customer_id = (int)$_POST['customer_id'];
$current_status = isset($_POST['current_status']) ? (int)$_POST['current_status'] : 0;

if ($customer_id <= 0) {
    respond(false, 'Invalid customer ID');
}

$pdo = getPDO();

try {
    $stmt = $pdo->prepare('SELECT email, first_name, last_name, beta_access FROM customers WHERE id = ?');
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        respond(false, 'Customer not found');
    }

    $new_status = $current_status ? 0 : 1;
    $stmt = $pdo->prepare('UPDATE customers SET beta_access = ? WHERE id = ?');
    $result = $stmt->execute([$new_status, $customer_id]);

    if (!$result || $stmt->rowCount() === 0) {
        respond(false, 'Failed to update beta access');
    }

    require_once 'ActivityLogger.php';
    $logger = new ActivityLogger($pdo);
    $logger->logActivity($customer_id, 'beta_access_changed', [
        'changed_by_admin' => $_SESSION['admin'],
        'old_status' => (bool)$current_status,
        'new_status' => (bool)$new_status,
        'customer_email' => $customer['email'],
        'timestamp' => date('Y-m-d H:i:s')
    ]);

    $status_text = $new_status ? 'aktiviert' : 'deaktiviert';
    respond(true, "Beta-Zugang für '{$customer['email']}' erfolgreich {$status_text}");

} catch (Exception $e) {
    error_log('Beta toggle error: ' . $e->getMessage());
    respond(false, 'Beta-Zugang konnte nicht geändert werden');
}
