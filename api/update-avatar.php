<?php
require __DIR__ . '/../customer/auth.php';
$customer = require_customer_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

$style = $input['style'] ?? '';
$seed = $input['seed'] ?? '';

$allowed_styles = ['avataaars', 'pixel-art', 'lorelei', 'adventurer', 'bottts', 'identicon'];
if (!in_array($style, $allowed_styles, true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid style']);
    exit;
}

$seed = preg_replace('/[^a-zA-Z0-9@._-]/', '', (string) $seed);
if ($seed === '') {
    $seed = $customer['email'];
}
if (strlen($seed) > 100) {
    $seed = substr($seed, 0, 100);
}

try {
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE customers SET avatar_style = ?, avatar_seed = ? WHERE id = ?');
    $updated = $stmt->execute([$style, $seed, $customer['id']]);

    header('Content-Type: application/json');

    if ($updated) {
        $_SESSION['customer']['avatar_style'] = $style;
        $_SESSION['customer']['avatar_seed'] = $seed;

        require_once __DIR__ . '/../admin/ActivityLogger.php';
        $logger = new ActivityLogger($pdo);
        $logger->logActivity($customer['id'], 'avatar_updated', [
            'style' => $style,
            'seed' => $seed,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);

        echo json_encode([
            'success' => true,
            'style' => $style,
            'seed' => $seed,
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Update failed']);
    }
} catch (Throwable $e) {
    error_log('Avatar update error: ' . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
