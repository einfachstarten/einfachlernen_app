<?php
require __DIR__ . '/../customer/auth.php';

$customer = require_customer_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || !isset($input['style'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$style = trim((string) $input['style']);
$seed = isset($input['seed']) ? trim((string) $input['seed']) : '';

$allowed_styles = [
    'avataaars',
    'adventurer-neutral',
    'fun-emoji',
    'lorelei',
    'pixel-art',
    'thumbs',
];
if (!in_array($style, $allowed_styles, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid avatar style']);
    exit;
}

if ($seed === '') {
    $seed = $customer['email'] ?? 'beta-user';
}

$seed = preg_replace('/[^a-zA-Z0-9@._-]/', '', $seed);

if ($seed === '') {
    $seed = $customer['email'] ?? 'beta-user';
}

if (strlen($seed) > 100) {
    $seed = substr($seed, 0, 100);
}

try {
    $pdo = getPDO();

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('UPDATE customers SET avatar_style = ?, avatar_seed = ? WHERE id = ?');
    $updated = $stmt->execute([$style, $seed, $customer['id']]);

    $previousStyle = $customer['avatar_style'] ?? null;
    $previousSeed = $customer['avatar_seed'] ?? null;

    if (!$updated) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update avatar']);
        exit;
    }

    if (!isset($_SESSION['customer']) || !is_array($_SESSION['customer'])) {
        $_SESSION['customer'] = [];
    }

    $_SESSION['customer']['avatar_style'] = $style;
    $_SESSION['customer']['avatar_seed'] = $seed;

    if ($previousStyle === $style && $previousSeed === $seed) {
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'style' => $style,
            'seed' => $seed,
            'avatar_url' => 'https://api.dicebear.com/9.x/' . rawurlencode($style) . '/svg?seed=' . rawurlencode($seed),
        ]);
        exit;
    }

    require_once __DIR__ . '/../admin/ActivityLogger.php';
    $logger = new ActivityLogger($pdo);
    $logger->logActivity($customer['id'], 'avatar_updated', [
        'style' => $style,
        'seed' => $seed,
        'previous_style' => $previousStyle,
        'previous_seed' => $previousSeed,
        'timestamp' => date('Y-m-d H:i:s'),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'style' => $style,
        'seed' => $seed,
        'avatar_url' => 'https://api.dicebear.com/9.x/' . rawurlencode($style) . '/svg?seed=' . rawurlencode($seed),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Avatar update error for customer ' . ($customer['id'] ?? 'unknown') . ': ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
