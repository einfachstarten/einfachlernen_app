<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}
if(isset($_SESSION['LAST_ACTIVITY']) && time() - $_SESSION['LAST_ACTIVITY'] > 1800){session_unset();session_destroy();header('Location: login.php');exit;}
$_SESSION['LAST_ACTIVITY'] = time();

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
    respond(false, 'Invalid delete request');
}

$customer_id = (int)$_POST['customer_id'];
if ($customer_id <= 0) {
    respond(false, 'Invalid customer ID');
}

$pdo = getPDO();

try {
    // Get customer data before deletion for logging
    $stmt = $pdo->prepare('SELECT email, first_name, last_name FROM customers WHERE id = ?');
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        respond(false, 'Customer not found');
    }
    
    // Log deletion activity (before actual deletion)
    require_once 'ActivityLogger.php';
    $logger = new ActivityLogger($pdo);
    $logger->logActivity($customer_id, 'customer_deleted', [
        'deleted_by_admin' => $_SESSION['admin'],
        'customer_email' => $customer['email'],
        'customer_name' => trim($customer['first_name'] . ' ' . $customer['last_name']),
        'deletion_timestamp' => date('Y-m-d H:i:s')
    ]);
    
    // Start transaction for safe deletion
    $pdo->beginTransaction();
    
    // Delete customer (CASCADE will handle sessions + activities)
    $delete_stmt = $pdo->prepare('DELETE FROM customers WHERE id = ?');
    $result = $delete_stmt->execute([$customer_id]);
    
    if (!$result || $delete_stmt->rowCount() === 0) {
        $pdo->rollBack();
        respond(false, 'Failed to delete customer');
    }
    
    $pdo->commit();
    
    respond(true, "Customer '{$customer['email']}' successfully deleted");
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Customer deletion error: " . $e->getMessage());
    respond(false, 'Deletion failed - system error');
}
?>
