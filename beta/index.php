<?php
// beta/index.php - Enhanced Beta Customer App with Live Messaging and Feedback Panel
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

function getPDO(): PDO
{
    $config = require __DIR__ . '/../admin/config.php';

    return new PDO(
        "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function ensureBetaSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS beta_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_admin BOOLEAN DEFAULT TRUE,
        to_customer_email VARCHAR(100) NOT NULL,
        message_text TEXT NOT NULL,
        message_type ENUM('info', 'success', 'warning', 'question') DEFAULT 'info',
        expects_response TINYINT(1) DEFAULT 0,
        response_question TEXT NULL,
        is_read TINYINT(1) DEFAULT 0,
        read_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer_email (to_customer_email),
        INDEX idx_unread (is_read, to_customer_email),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS beta_message_responses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        customer_email VARCHAR(100) NOT NULL,
        response_type ENUM('yes','no') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_response (message_id, customer_email),
        INDEX idx_response_email (customer_email),
        CONSTRAINT fk_message_response_beta FOREIGN KEY (message_id)
            REFERENCES beta_messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $pdo->exec("ALTER TABLE beta_messages ADD COLUMN expects_response TINYINT(1) DEFAULT 0 AFTER message_type");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE beta_messages ADD COLUMN response_question TEXT NULL AFTER expects_response");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE beta_messages ADD COLUMN read_at DATETIME NULL AFTER is_read");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE beta_message_responses ADD UNIQUE INDEX uniq_response (message_id, customer_email)");
    } catch (Throwable $e) {
    }
}

function transformMessageRow(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'message_text' => (string) ($row['message_text'] ?? ''),
        'message_type' => (string) ($row['message_type'] ?? 'info'),
        'is_read' => !empty($row['is_read']),
        'created_at' => $row['created_at'] ?? null,
        'read_at' => $row['read_at'] ?? null,
        'expects_response' => !empty($row['expects_response']),
        'response_question' => (string) ($row['response_question'] ?? ''),
        'user_response' => $row['response_type'] ?? null,
        'responded_at' => $row['response_created_at'] ?? null,
    ];
}

function fetchMessages(PDO $pdo, string $email, string $mode = 'all', int $limit = 40): array
{
    $conditions = 'm.to_customer_email = ?';
    if ($mode === 'unread') {
        $conditions .= ' AND m.is_read = 0';
    } elseif ($mode === 'read') {
        $conditions .= ' AND m.is_read = 1';
    }

    $sql = "SELECT m.*, r.response_type, r.created_at AS response_created_at
            FROM beta_messages m
            LEFT JOIN beta_message_responses r
                ON r.message_id = m.id AND r.customer_email = ?
            WHERE {$conditions}
            ORDER BY m.created_at DESC";

    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email, $email]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map('transformMessageRow', $rows);
}

function computeMessageStats(PDO $pdo, string $email): array
{
    $stats = [
        'total' => 0,
        'unread' => 0,
        'read' => 0,
        'needs_response' => 0,
        'responses_submitted' => 0,
        'response_breakdown' => ['yes' => 0, 'no' => 0],
    ];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM beta_messages WHERE to_customer_email = ?');
    $stmt->execute([$email]);
    $stats['total'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM beta_messages WHERE to_customer_email = ? AND is_read = 0');
    $stmt->execute([$email]);
    $stats['unread'] = (int) $stmt->fetchColumn();

    $stats['read'] = max(0, $stats['total'] - $stats['unread']);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM beta_messages WHERE to_customer_email = ? AND expects_response = 1');
    $stmt->execute([$email]);
    $stats['needs_response'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT response_type, COUNT(*) AS total FROM beta_message_responses WHERE customer_email = ? GROUP BY response_type');
    $stmt->execute([$email]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = $row['response_type'] ?? '';
        $count = (int) ($row['total'] ?? 0);
        if ($type === 'yes' || $type === 'no') {
            $stats['response_breakdown'][$type] = $count;
        }
    }

    $stats['responses_submitted'] = array_sum($stats['response_breakdown']);

    return $stats;
}
function calculateTestingStreak(PDO $pdo, string $email): int
{
    $stmt = $pdo->prepare('SELECT DISTINCT DATE(created_at) AS activity_day FROM beta_message_responses WHERE customer_email = ? ORDER BY activity_day DESC');
    $stmt->execute([$email]);
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$dates) {
        return 0;
    }

    $today = new DateTime('today');
    $streak = 0;

    while (true) {
        $dayKey = $today->format('Y-m-d');
        if (in_array($dayKey, $dates, true)) {
            $streak++;
            $today->modify('-1 day');
        } else {
            break;
        }
    }

    return $streak;
}

function computeBetaStatus(PDO $pdo, string $email): array
{
    $stats = computeMessageStats($pdo, $email);
    $recentMessages = fetchMessages($pdo, $email, 'all', 15);

    $engagementRate = $stats['needs_response'] > 0
        ? round(($stats['responses_submitted'] / $stats['needs_response']) * 100, 1)
        : 0.0;

    $progress = $stats['total'] > 0
        ? (int) min(100, round(($stats['read'] / max(1, $stats['total'])) * 60 + ($engagementRate / 100) * 40))
        : 0;

    $featureUsage = [];
    $usageStmt = $pdo->prepare('SELECT message_type, COUNT(*) AS total FROM beta_messages WHERE to_customer_email = ? GROUP BY message_type');
    $usageStmt->execute([$email]);
    foreach ($usageStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $featureUsage[] = [
            'label' => ucfirst($row['message_type'] ?? 'Info'),
            'count' => (int) ($row['total'] ?? 0),
        ];
    }

    $timeline = [];
    foreach ($recentMessages as $message) {
        $timeline[] = [
            'id' => $message['id'],
            'title' => mb_strimwidth($message['message_text'], 0, 80, '…', 'UTF-8'),
            'status' => $message['expects_response']
                ? ($message['user_response'] ? 'Feedback gegeben' : 'Wartet auf Feedback')
                : 'Abgeschlossen',
            'response' => $message['user_response'],
            'created_at' => $message['created_at'],
            'read_at' => $message['read_at'],
        ];
    }

    return [
        'stats' => $stats,
        'progress' => $progress,
        'engagement_rate' => $engagementRate,
        'testing_streak' => calculateTestingStreak($pdo, $email),
        'feature_usage' => $featureUsage,
        'recent_features' => $timeline,
    ];
}

function fetchSessionHistory(PDO $pdo, int $customerId, int $limit = 6): array
{
    try {
        $stmt = $pdo->prepare('SELECT created_at, expires_at FROM customer_sessions WHERE customer_id = ? ORDER BY created_at DESC LIMIT ' . (int) $limit);
        $stmt->execute([$customerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function fetchActivityFeed(PDO $pdo, string $email, int $limit = 15): array
{
    $messages = fetchMessages($pdo, $email, 'all', $limit * 2);
    $feed = [];

    foreach ($messages as $message) {
        $feed[] = [
            'type' => 'message',
            'title' => 'Neue Beta-Nachricht',
            'details' => mb_strimwidth($message['message_text'], 0, 100, '…', 'UTF-8'),
            'timestamp' => $message['created_at'],
            'meta' => $message['message_type'],
        ];

        if (!empty($message['read_at'])) {
            $feed[] = [
                'type' => 'read',
                'title' => 'Nachricht gelesen',
                'details' => 'Status aktualisiert',
                'timestamp' => $message['read_at'],
                'meta' => $message['message_type'],
            ];
        }

        if (!empty($message['user_response'])) {
            $feed[] = [
                'type' => 'response',
                'title' => 'Feedback gesendet',
                'details' => strtoupper($message['user_response']) . ' Antwort übermittelt',
                'timestamp' => $message['responded_at'],
                'meta' => $message['message_type'],
            ];
        }
    }

    usort($feed, static function ($a, $b) {
        return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
    });

    return array_slice($feed, 0, $limit);
}

try {
    $pdo = getPDO();
    ensureBetaSchema($pdo);
} catch (Throwable $e) {
    die('Beta table creation failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

if (empty($_SESSION['session_start_time'])) {
    $_SESSION['session_start_time'] = time();
}

if (empty($_SESSION['customer_id']) || empty($_SESSION['session_authenticated'])) {
    header('Location: ../login.php?message=' . urlencode('Bitte zuerst einloggen für Beta-Zugang'));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$_SESSION['customer_id']]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    session_destroy();
    die('Customer not found. Please login again.');
}

if (($customer['email'] ?? '') !== 'marcus@einfachstarten.jetzt') {
    echo '<!DOCTYPE html>'
        . '<html><head><meta charset="UTF-8"><title>Beta Access</title></head>'
        . '<body style="font-family:Arial;text-align:center;padding:3rem;background:#f8fafc">'
        . '<h1>🧪 Beta Access</h1>'
        . '<p>Diese Beta-App ist nur für autorisierte Testuser zugänglich.</p>'
        . '<p><a href="../customer/index.php">← Zur normalen Customer App</a></p>'
        . '</body></html>';
    exit;
}

if (!empty($_POST['mark_read'])) {
    $messageId = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;

    if ($messageId > 0) {
        $stmt = $pdo->prepare('UPDATE beta_messages SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND to_customer_email = ?');
        $stmt->execute([$messageId, $customer['email']]);
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

if (!empty($_POST['respond_to_message'])) {
    $messageId = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;
    $responseType = isset($_POST['response_type']) ? strtolower((string) $_POST['response_type']) : '';

    header('Content-Type: application/json');

    if ($messageId <= 0 || !in_array($responseType, ['yes', 'no'], true)) {
        echo json_encode(['success' => false, 'error' => 'Ungültige Antwort.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM beta_messages WHERE id = ? AND to_customer_email = ?');
    $stmt->execute([$messageId, $customer['email']]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        echo json_encode(['success' => false, 'error' => 'Nachricht nicht gefunden.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $insert = $pdo->prepare('INSERT INTO beta_message_responses (message_id, customer_email, response_type)
            VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE response_type = VALUES(response_type), created_at = CURRENT_TIMESTAMP');
        $insert->execute([$messageId, $customer['email'], $responseType]);

        $update = $pdo->prepare('UPDATE beta_messages SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ?');
        $update->execute([$messageId]);

        $pdo->commit();

        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Antwort konnte nicht gespeichert werden.']);
    }

    exit;
}

if (!empty($_GET['ajax'])) {
    $action = $_GET['ajax'];

    if ($action === 'messages') {
        $stats = computeMessageStats($pdo, $customer['email']);
        $unread = fetchMessages($pdo, $customer['email'], 'unread', 50);
        $read = fetchMessages($pdo, $customer['email'], 'read', 60);
        $history = fetchMessages($pdo, $customer['email'], 'all', 50);

        header('Content-Type: application/json');
        echo json_encode([
            'stats' => $stats,
            'messages' => [
                'unread' => $unread,
                'read' => $read,
            ],
            'history' => $history,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'profile') {
        $profile = [
            'name' => trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')),
            'email' => $customer['email'] ?? '',
            'status' => $customer['status'] ?? 'active',
            'registered_at' => $customer['created_at'] ?? null,
            'last_login' => $customer['last_login'] ?? null,
            'beta_role' => 'Lead Tester',
            'session_started_at' => date('Y-m-d H:i:s', $_SESSION['session_start_time'] ?? time()),
        ];

        header('Content-Type: application/json');
        echo json_encode($profile, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'activity') {
        $sessions = fetchSessionHistory($pdo, (int) $customer['id'], 8);
        $feed = fetchActivityFeed($pdo, $customer['email'], 12);
        $stats = computeMessageStats($pdo, $customer['email']);

        header('Content-Type: application/json');
        echo json_encode([
            'last_login' => $customer['last_login'] ?? null,
            'session_started_at' => date('Y-m-d H:i:s', $_SESSION['session_start_time'] ?? time()),
            'sessions' => $sessions,
            'activity_feed' => $feed,
            'responses_submitted' => $stats['responses_submitted'],
            'unread_messages' => $stats['unread'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'beta_status') {
        $betaStatus = computeBetaStatus($pdo, $customer['email']);

        header('Content-Type: application/json');
        echo json_encode($betaStatus, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$initialStats = computeMessageStats($pdo, $customer['email']);
$initialUnreadCount = $initialStats['unread'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4a90b8">
    <title>🧪 Beta Dashboard - Anna Braun Lerncoaching</title>
    <style>
        :root {
            --primary: #4a90b8;
            --secondary: #52b3a4;
            --accent-green: #7cb342;
            --accent-teal: #26a69a;
            --accent-purple: #6f42c1;
            --light-blue: #e3f2fd;
            --white: #ffffff;
            --gray-light: #f8f9fa;
            --gray-medium: #6c757d;
            --gray-dark: #343a40;
            --shadow: rgba(0, 0, 0, 0.1);
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: linear-gradient(135deg, var(--light-blue) 0%, var(--white) 100%);
            min-height: 100vh;
            color: var(--gray-dark);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.5;
        }

        .beta-banner {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 0.5rem;
            text-align: center;
            font-weight: bold;
            font-size: 0.85rem;
            animation: betaPulse 3s ease-in-out infinite;
            position: relative;
            overflow: hidden;
        }

        @keyframes betaPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.9; }
        }

        .beta-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            animation: shimmer 4s linear infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .app-container {
            max-width: 900px;
            margin: 0 auto;
            min-height: 100vh;
            background: white;
            box-shadow: 0 0 30px var(--shadow);
            display: flex;
            flex-direction: column;
        }

        .app-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .app-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20px;
            width: 100px;
            height: 200%;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(15deg);
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
            z-index: 1;
        }

        .user-avatar {
            width: 64px;
            height: 64px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .user-avatar:hover {
            transform: scale(1.05);
            border-color: rgba(255, 255, 255, 0.6);
            box-shadow: 0 4px 15px rgba(255, 255, 255, 0.2);
        }

        .message-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff4757;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
            border: 2px solid white;
            animation: badgePulse 2s infinite;
            min-width: 24px;
        }

        @keyframes badgePulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .user-info h1 {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .user-info p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .logout-btn {
            margin-left: auto;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            text-decoration: none;
        }

        .app-content {
            flex: 1;
            padding: 1.5rem;
        }

        .welcome-section {
            text-align: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--light-blue);
            border-radius: 16px;
            border-left: 4px solid var(--primary);
        }

        .welcome-section h2 {
            color: var(--primary);
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .action-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 10px var(--shadow);
            border: 1px solid #f0f0f0;
            text-decoration: none;
            color: inherit;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px var(--shadow);
            text-decoration: none;
            color: inherit;
        }

        .action-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }

        .action-content h3 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-dark);
        }

        .action-content p {
            margin: 0.25rem 0 0 0;
            font-size: 0.85rem;
            color: var(--gray-medium);
        }

        .message-panel {
            position: fixed;
            top: 0;
            right: -470px;
            width: 450px;
            height: 100vh;
            background: white;
            box-shadow: -4px 0 20px rgba(0,0,0,0.1);
            z-index: 1001;
            transition: right 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .message-panel.open {
            right: 0;
        }

        .message-panel.large {
            width: 520px;
        }

        .message-panel.compact {
            width: 380px;
        }

        .message-panel-header {
            background: var(--primary);
            color: white;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .close-panel {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.25rem;
        }

        .panel-tabs {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: #f0f4f8;
            border-bottom: 1px solid #d9e2ec;
        }

        .panel-tab {
            flex: 1;
            background: transparent;
            border: none;
            padding: 0.5rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray-medium);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .panel-tab.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }

        .panel-content {
            flex: 1;
            overflow: hidden;
            display: none;
        }

        .panel-content.active {
            display: flex;
            flex-direction: column;
        }

        .panel-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 1.25rem 1.25rem 1.25rem;
        }
        .message-subtabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .message-subtab {
            flex: 1;
            background: #f3f4f6;
            border: none;
            padding: 0.5rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .message-subtab.active {
            background: var(--primary);
            color: white;
        }

        .message-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .message-card {
            border-left: 4px solid var(--primary);
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 0 12px 12px 0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            transition: transform 0.2s ease;
        }

        .message-card:hover {
            transform: translateY(-2px);
        }

        .message-card.success { border-left-color: var(--success); }
        .message-card.warning { border-left-color: var(--warning); }
        .message-card.question { border-left-color: var(--accent-purple); }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .message-header span {
            font-size: 0.8rem;
            color: var(--gray-medium);
        }

        .message-title {
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .message-body {
            color: var(--gray-dark);
            font-size: 0.9rem;
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .message-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #2b6cb0; }

        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #1e7e34; }

        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #b21f2d; }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--gray-medium);
            color: var(--gray-medium);
        }

        .btn-outline:hover {
            color: var(--gray-dark);
            border-color: var(--gray-dark);
        }

        .response-status {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--gray-medium);
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 0.75rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
        }

        .stat-card h4 {
            font-size: 0.85rem;
            color: var(--gray-medium);
            margin-bottom: 0.25rem;
        }

        .stat-card strong {
            font-size: 1.1rem;
            color: var(--gray-dark);
        }

        .history-list {
            margin-top: 1rem;
        }

        .history-item {
            border-left: 3px solid var(--primary);
            padding: 0.75rem 0.75rem 0.75rem 1rem;
            margin-bottom: 0.75rem;
            background: #f9fafb;
            border-radius: 0 10px 10px 0;
        }

        .history-item strong {
            display: block;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .profile-section,
        .activity-section,
        .beta-status-section {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .info-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
        }

        .info-card h3 {
            margin-bottom: 0.5rem;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .info-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .timeline {
            border-left: 2px solid #d1d5db;
            padding-left: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .timeline-item {
            position: relative;
            background: #f9fafb;
            border-radius: 10px;
            padding: 0.75rem;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.2rem;
            top: 0.8rem;
            width: 0.75rem;
            height: 0.75rem;
            background: var(--primary);
            border-radius: 50%;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-bar span {
            display: block;
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
        }

        .setting-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            font-size: 0.85rem;
        }

        .setting-card label {
            font-weight: 600;
            color: var(--gray-dark);
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .panel-theme-dark {
            background: #1f2937;
            color: #f9fafb;
        }

        .panel-theme-dark .panel-tabs {
            background: #111827;
        }

        .panel-theme-dark .panel-tab {
            color: #d1d5db;
        }

        .panel-theme-dark .panel-tab.active {
            background: #374151;
            color: #f9fafb;
        }

        .panel-theme-dark .panel-scroll {
            background: #111827;
        }

        .panel-theme-dark .message-card,
        .panel-theme-dark .info-card,
        .panel-theme-dark .stat-card,
        .panel-theme-dark .timeline-item,
        .panel-theme-dark .setting-card {
            background: #1f2937;
            color: #f9fafb;
            border-color: #374151;
        }

        .panel-theme-dark .message-subtab {
            background: #374151;
            color: #d1d5db;
        }

        .panel-theme-dark .message-subtab.active {
            background: var(--accent-purple);
        }

        @media (max-width: 768px) {
            .message-panel,
            .message-panel.large,
            .message-panel.compact {
                width: 100%;
                right: -100%;
            }

            .actions-grid {
                grid-template-columns: 1fr;
            }

            .header-content {
                gap: 0.75rem;
            }

            .user-avatar {
                width: 54px;
                height: 54px;
                font-size: 1.5rem;
            }

            .message-badge {
                width: 20px;
                height: 20px;
                font-size: 0.65rem;
            }
        }
    </style>
</head>
<body>
    <div class="beta-banner">
        🧪 BETA VERSION - Experimentelle Features in Entwicklung
    </div>

    <div class="app-container">
        <div class="app-header">
            <div class="header-content">
                <div class="user-avatar" onclick="toggleMessagePanel()" title="Beta Panel öffnen">
                    👤
                    <div class="message-badge" id="messageBadge" style="display: <?= $initialUnreadCount > 0 ? 'flex' : 'none' ?>;">
                        <?= $initialUnreadCount > 99 ? '99+' : $initialUnreadCount; ?>
                    </div>
                </div>
                <div class="user-info">
                    <h1>Beta-Modus: <?= htmlspecialchars($customer['first_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>🧪 Testing new features</p>
                </div>
                <a href="../login.php?logout=1" class="logout-btn">
                    🚪 Abmelden
                </a>
            </div>
        </div>

        <div class="app-content">
            <div class="welcome-section">
                <h2>Willkommen im neuen Beta-Dashboard</h2>
                <p>Teste brandneue Funktionen, gib direktes Feedback und verfolge deinen Fortschritt.</p>
            </div>

            <div class="actions-grid">
                <div class="action-card" onclick="toggleMessagePanel()" role="button">
                    <div class="action-icon">💬</div>
                    <div class="action-content">
                        <h3>Nachrichten & Feedback</h3>
                        <p>Sofortige Updates vom Admin-Team mit Ja/Nein Antworten.</p>
                    </div>
                </div>
                <div class="action-card" onclick="openPanelTab('profile')" role="button">
                    <div class="action-icon">👤</div>
                    <div class="action-content">
                        <h3>Profil & Einstellungen</h3>
                        <p>Kontaktdaten, Beta-Rolle und Panel-Anpassungen einsehen.</p>
                    </div>
                </div>
                <div class="action-card" onclick="openPanelTab('activity')" role="button">
                    <div class="action-icon">📈</div>
                    <div class="action-content">
                        <h3>Aktivitäts-Tracking</h3>
                        <p>Letzte Sessions, Tests und Antworten im Überblick.</p>
                    </div>
                </div>
                <div class="action-card" onclick="openPanelTab('beta-status')" role="button">
                    <div class="action-icon">🚀</div>
                    <div class="action-content">
                        <h3>Beta-Status</h3>
                        <p>Fortschritt, Engagement und getestete Features anzeigen.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="message-panel" id="messagePanel">
        <div class="message-panel-header">
            <div>
                <strong>Beta Control Center</strong>
                <div style="font-size:0.8rem;opacity:0.85;">Kommunikation • Feedback • Fortschritt</div>
            </div>
            <button class="close-panel" type="button" onclick="closeMessagePanel()" aria-label="Panel schließen">×</button>
        </div>

        <div class="panel-tabs">
            <button class="panel-tab active" data-tab="messages">💬 Nachrichten</button>
            <button class="panel-tab" data-tab="profile">👤 Profil</button>
            <button class="panel-tab" data-tab="activity">📊 Aktivität</button>
            <button class="panel-tab" data-tab="beta-status">🚀 Beta-Status</button>
        </div>

        <div class="panel-content active" data-content="messages">
            <div class="panel-scroll">
                <div class="stats-grid" id="messageStats"></div>

                <div class="message-subtabs">
                    <button class="message-subtab active" data-subtab="new">📬 Neu</button>
                    <button class="message-subtab" data-subtab="read">📁 Gelesen</button>
                </div>

                <div class="message-list" id="newMessages"></div>
                <div class="message-list" id="readMessages" style="display:none;"></div>

                <div class="info-card" id="messageHistoryCard" style="margin-top:1.5rem;">
                    <h3>🗂️ Nachrichten- und Response-Verlauf</h3>
                    <div class="history-list" id="messageHistory"></div>
                </div>
            </div>
        </div>

        <div class="panel-content" data-content="profile">
            <div class="panel-scroll">
                <div class="profile-section" id="profileContent"></div>
            </div>
        </div>

        <div class="panel-content" data-content="activity">
            <div class="panel-scroll">
                <div class="activity-section" id="activityContent"></div>
            </div>
        </div>

        <div class="panel-content" data-content="beta-status">
            <div class="panel-scroll">
                <div class="beta-status-section" id="betaStatusContent"></div>
            </div>
        </div>
    </div>

    <div class="overlay" id="overlay" onclick="closeMessagePanel()"></div>
    <script>
        const messagePanel = document.getElementById('messagePanel');
        const overlay = document.getElementById('overlay');
        const messageBadge = document.getElementById('messageBadge');
        const messageStatsContainer = document.getElementById('messageStats');
        const newMessagesContainer = document.getElementById('newMessages');
        const readMessagesContainer = document.getElementById('readMessages');
        const messageHistoryContainer = document.getElementById('messageHistory');
        const profileContent = document.getElementById('profileContent');
        const activityContent = document.getElementById('activityContent');
        const betaStatusContent = document.getElementById('betaStatusContent');
        const panelTabs = Array.from(document.querySelectorAll('.panel-tab'));
        const panelSections = Array.from(document.querySelectorAll('.panel-content'));
        const messageSubtabs = Array.from(document.querySelectorAll('.message-subtab'));

        let messageDataCache = null;
        let profileDataCache = null;
        let activityDataCache = null;
        let betaStatusCache = null;

        const settingsKey = 'betaPanelSettings';
        const settingsDefaults = {
            notifications: true,
            panelSize: 'standard',
            theme: 'light',
        };

        function loadSettings() {
            try {
                const stored = localStorage.getItem(settingsKey);
                if (!stored) {
                    return { ...settingsDefaults };
                }
                const parsed = JSON.parse(stored);
                return { ...settingsDefaults, ...parsed };
            } catch (error) {
                console.warn('Settings konnten nicht geladen werden.', error);
                return { ...settingsDefaults };
            }
        }

        let panelSettings = loadSettings();

        function saveSettings() {
            try {
                localStorage.setItem(settingsKey, JSON.stringify(panelSettings));
            } catch (error) {
                console.warn('Settings konnten nicht gespeichert werden.', error);
            }
        }

        function applyPanelPreferences() {
            messagePanel.classList.remove('large', 'compact', 'panel-theme-dark');

            if (panelSettings.panelSize === 'large') {
                messagePanel.classList.add('large');
            } else if (panelSettings.panelSize === 'compact') {
                messagePanel.classList.add('compact');
            }

            if (panelSettings.theme === 'dark') {
                messagePanel.classList.add('panel-theme-dark');
            }
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDate(value) {
            if (!value) {
                return '—';
            }
            const date = new Date(value.replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) {
                return escapeHtml(value);
            }
            return date.toLocaleString('de-DE');
        }

        function toggleMessagePanel() {
            if (messagePanel.classList.contains('open')) {
                closeMessagePanel();
            } else {
                openMessagePanel();
            }
        }

        function openMessagePanel() {
            messagePanel.classList.add('open');
            overlay.classList.add('active');
            applyPanelPreferences();
            loadMessages();
        }

        function closeMessagePanel() {
            messagePanel.classList.remove('open');
            overlay.classList.remove('active');
        }

        function openPanelTab(tabName) {
            if (!messagePanel.classList.contains('open')) {
                openMessagePanel();
            }
            activateMainTab(tabName);
        }

        function activateMainTab(tabName) {
            panelTabs.forEach((button) => {
                const isActive = button.dataset.tab === tabName;
                button.classList.toggle('active', isActive);
            });

            panelSections.forEach((section) => {
                const isActive = section.dataset.content === tabName;
                section.classList.toggle('active', isActive);
            });

            if (tabName === 'messages') {
                loadMessages();
            } else if (tabName === 'profile') {
                loadProfile();
            } else if (tabName === 'activity') {
                loadActivity();
            } else if (tabName === 'beta-status') {
                loadBetaStatus();
            }
        }

        panelTabs.forEach((button) => {
            button.addEventListener('click', () => {
                activateMainTab(button.dataset.tab);
            });
        });

        messageSubtabs.forEach((button) => {
            button.addEventListener('click', () => {
                const subtab = button.dataset.subtab;
                messageSubtabs.forEach((btn) => btn.classList.toggle('active', btn === button));
                if (subtab === 'new') {
                    newMessagesContainer.style.display = '';
                    readMessagesContainer.style.display = 'none';
                } else {
                    newMessagesContainer.style.display = 'none';
                    readMessagesContainer.style.display = '';
                }
            });
        });
        function updateMessageBadge(count) {
            const total = Number(count) || 0;
            if (total > 0) {
                messageBadge.style.display = 'flex';
                messageBadge.textContent = total > 99 ? '99+' : total;
            } else {
                messageBadge.style.display = 'none';
            }
        }

        function fetchJson(url, options = {}) {
            return fetch(url, { credentials: 'same-origin', ...options })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                });
        }

        function renderStats(stats) {
            if (!stats) {
                messageStatsContainer.innerHTML = '';
                return;
            }

            const responseTotal = stats.response_breakdown.yes + stats.response_breakdown.no;
            const yesPercent = responseTotal > 0 ? Math.round((stats.response_breakdown.yes / responseTotal) * 100) : 0;
            const noPercent = responseTotal > 0 ? Math.round((stats.response_breakdown.no / responseTotal) * 100) : 0;

            messageStatsContainer.innerHTML = `
                <div class="stat-card">
                    <h4>Gesamt</h4>
                    <strong>${stats.total}</strong>
                </div>
                <div class="stat-card">
                    <h4>Ungelesen</h4>
                    <strong>${stats.unread}</strong>
                </div>
                <div class="stat-card">
                    <h4>Antwort erforderlich</h4>
                    <strong>${stats.needs_response}</strong>
                </div>
                <div class="stat-card">
                    <h4>Antworten</h4>
                    <strong>${stats.responses_submitted}</strong>
                    <div style="font-size:0.75rem;color:var(--gray-medium);margin-top:0.25rem;">
                        ✅ ${yesPercent}% • ❌ ${noPercent}%
                    </div>
                </div>
            `;
        }

        function buildMessageCard(message, isUnread) {
            const typeIcons = { info: 'ℹ️', success: '✅', warning: '⚠️', question: '❓' };
            const icon = typeIcons[message.message_type] || 'ℹ️';
            const response = message.user_response;
            const responseStatus = response ? (response === 'yes' ? '✅ Ja' : '❌ Nein') : 'Keine Antwort';
            const buttons = [];

            if (message.expects_response && !response) {
                buttons.push(`
                    <button class="btn btn-success" data-action="respond" data-message-id="${message.id}" data-response="yes">
                        ✅ Ja
                    </button>
                `);
                buttons.push(`
                    <button class="btn btn-danger" data-action="respond" data-message-id="${message.id}" data-response="no">
                        ❌ Nein
                    </button>
                `);
            }

            if (isUnread && !message.expects_response) {
                buttons.push(`
                    <button class="btn btn-primary" data-action="mark-read" data-message-id="${message.id}">
                        ✓ Als gelesen markieren
                    </button>
                `);
            }

            const questionBlock = message.expects_response ? `
                <div style="background:#edf2ff;padding:0.75rem;border-radius:8px;border:1px solid #dbe4ff;">
                    <div style="font-weight:600;margin-bottom:0.25rem;">${icon} Admin-Frage:</div>
                    <div style="font-size:0.85rem;margin-bottom:0.75rem;">${escapeHtml(message.response_question || 'Bitte gib uns Feedback.')}</div>
                    ${response ? `<div class="response-status">${responseStatus} • ${formatDate(message.responded_at)}</div>` : ''}
                </div>
            ` : '';

            return `
                <div class="message-card ${escapeHtml(message.message_type)}">
                    <div class="message-header">
                        <div class="message-title">${icon} ${escapeHtml(message.message_type.charAt(0).toUpperCase() + message.message_type.slice(1))}</div>
                        <span>${formatDate(message.created_at)}</span>
                    </div>
                    <div class="message-body">${escapeHtml(message.message_text)}</div>
                    ${questionBlock}
                    ${buttons.length ? `<div class="message-actions">${buttons.join('')}</div>` : ''}
                    ${response && !message.expects_response ? `<div class="response-status">${responseStatus} • ${formatDate(message.responded_at)}</div>` : ''}
                    ${!isUnread && message.read_at ? `<div class="response-status">📁 Gelesen am ${formatDate(message.read_at)}</div>` : ''}
                </div>
            `;
        }

        function renderMessageHistory(history) {
            if (!history || history.length === 0) {
                messageHistoryContainer.innerHTML = '<p style="font-size:0.85rem;color:#6b7280;">Noch keine Historie vorhanden.</p>';
                return;
            }

            const items = history.slice(0, 10).map((item) => {
                const status = item.expects_response
                    ? (item.user_response ? `Antwort: ${item.user_response === 'yes' ? 'Ja' : 'Nein'}` : 'Wartet auf Antwort')
                    : (item.is_read ? 'Gelesen' : 'Offen');
                const readInfo = item.read_at ? ` • gelesen ${formatDate(item.read_at)}` : '';
                const responseInfo = item.responded_at ? ` • Antwort ${formatDate(item.responded_at)}` : '';
                return `
                    <div class="history-item">
                        <strong>${escapeHtml(item.message_text.substring(0, 80))}${item.message_text.length > 80 ? '…' : ''}</strong>
                        <div style="font-size:0.8rem;color:#6b7280;">
                            ${formatDate(item.created_at)} • ${escapeHtml(status)}${readInfo}${responseInfo}
                        </div>
                    </div>
                `;
            });

            messageHistoryContainer.innerHTML = items.join('');
        }

        function renderMessageLists(data) {
            const unreadMessages = Array.isArray(data.messages?.unread) ? data.messages.unread : [];
            const readMessages = Array.isArray(data.messages?.read) ? data.messages.read : [];

            if (unreadMessages.length === 0) {
                newMessagesContainer.innerHTML = `
                    <div style="text-align:center;padding:2rem;color:#6b7280;font-size:0.9rem;">
                        <p>📭 Keine neuen Nachrichten</p>
                        <p style="font-size:0.8rem;margin-top:0.5rem;">Wir benachrichtigen dich, sobald etwas Neues da ist.</p>
                    </div>
                `;
            } else {
                newMessagesContainer.innerHTML = unreadMessages.map((message) => buildMessageCard(message, true)).join('');
            }

            if (readMessages.length === 0) {
                readMessagesContainer.innerHTML = `
                    <div style="text-align:center;padding:2rem;color:#6b7280;font-size:0.9rem;">
                        <p>📂 Hier landen gelesene Nachrichten.</p>
                        <p style="font-size:0.8rem;margin-top:0.5rem;">Antworten und Zeitstempel bleiben erhalten.</p>
                    </div>
                `;
            } else {
                readMessagesContainer.innerHTML = readMessages.map((message) => buildMessageCard(message, false)).join('');
            }

            renderMessageHistory(data.history || []);
        }

        function loadMessages() {
            fetchJson('?ajax=messages')
                .then((data) => {
                    messageDataCache = data;
                    updateMessageBadge(data?.stats?.unread || 0);
                    renderStats(data.stats);
                    renderMessageLists(data);
                })
                .catch((error) => {
                    console.error('Nachrichten konnten nicht geladen werden:', error);
                });
        }
        document.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (!target) {
                return;
            }

            const messageId = target.dataset.messageId;
            if (!messageId) {
                return;
            }

            if (target.dataset.action === 'mark-read') {
                event.preventDefault();
                markMessageAsRead(messageId);
            } else if (target.dataset.action === 'respond') {
                event.preventDefault();
                const responseValue = target.dataset.response;
                respondToMessage(messageId, responseValue);
            }
        });

        function markMessageAsRead(messageId) {
            const body = new URLSearchParams({ mark_read: '1', message_id: messageId });
            fetchJson('', { method: 'POST', body })
                .then(() => {
                    loadMessages();
                })
                .catch((error) => {
                    console.error('Nachricht konnte nicht markiert werden:', error);
                });
        }

        function respondToMessage(messageId, responseType) {
            const body = new URLSearchParams({
                respond_to_message: '1',
                message_id: messageId,
                response_type: responseType,
            });

            fetchJson('', { method: 'POST', body })
                .then((result) => {
                    if (!result.success) {
                        throw new Error(result.error || 'Antwort nicht gespeichert');
                    }
                    loadMessages();
                    betaStatusCache = null;
                    activityDataCache = null;
                })
                .catch((error) => {
                    console.error('Antwort konnte nicht gesendet werden:', error);
                });
        }
        function renderProfile(profile) {
            profileContent.innerHTML = `
                <div class="info-card">
                    <h3>👤 Profilübersicht</h3>
                    <ul class="info-list">
                        <li><strong>Name:</strong> ${escapeHtml(profile.name || '—')}</li>
                        <li><strong>Email:</strong> ${escapeHtml(profile.email || '—')}</li>
                        <li><strong>Status:</strong> ${escapeHtml(profile.status || '—')}</li>
                        <li><strong>Beta-Rolle:</strong> ${escapeHtml(profile.beta_role || '—')}</li>
                        <li><strong>Registriert:</strong> ${formatDate(profile.registered_at)}</li>
                        <li><strong>Letzter Login:</strong> ${formatDate(profile.last_login)}</li>
                        <li><strong>Aktive Session seit:</strong> ${formatDate(profile.session_started_at)}</li>
                    </ul>
                </div>
                <div class="info-card">
                    <h3>⚙️ Panel Einstellungen</h3>
                    <div class="settings-grid">
                        <div class="setting-card">
                            <label for="settingNotifications">Benachrichtigungen</label>
                            <p>Badge-Updates und Auto-Refresh aktivieren.</p>
                            <input type="checkbox" id="settingNotifications">
                        </div>
                        <div class="setting-card">
                            <label for="settingPanelSize">Panel-Größe</label>
                            <select id="settingPanelSize">
                                <option value="standard">Standard</option>
                                <option value="large">Groß</option>
                                <option value="compact">Kompakt</option>
                            </select>
                        </div>
                        <div class="setting-card">
                            <label for="settingTheme">Panel-Theme</label>
                            <select id="settingTheme">
                                <option value="light">Hell</option>
                                <option value="dark">Dunkel</option>
                            </select>
                        </div>
                    </div>
                </div>
            `;
        }

        function setupSettingsControls() {
            const notificationToggle = document.getElementById('settingNotifications');
            const panelSizeSelect = document.getElementById('settingPanelSize');
            const themeSelect = document.getElementById('settingTheme');

            if (notificationToggle) {
                notificationToggle.checked = !!panelSettings.notifications;
                notificationToggle.addEventListener('change', () => {
                    panelSettings.notifications = notificationToggle.checked;
                    saveSettings();
                });
            }

            if (panelSizeSelect) {
                panelSizeSelect.value = panelSettings.panelSize;
                panelSizeSelect.addEventListener('change', () => {
                    panelSettings.panelSize = panelSizeSelect.value;
                    applyPanelPreferences();
                    saveSettings();
                });
            }

            if (themeSelect) {
                themeSelect.value = panelSettings.theme;
                themeSelect.addEventListener('change', () => {
                    panelSettings.theme = themeSelect.value;
                    applyPanelPreferences();
                    saveSettings();
                });
            }
        }

        function loadProfile() {
            if (profileDataCache) {
                renderProfile(profileDataCache);
                setupSettingsControls();
                applyPanelPreferences();
                return;
            }

            fetchJson('?ajax=profile')
                .then((data) => {
                    profileDataCache = data;
                    renderProfile(data);
                    setupSettingsControls();
                    applyPanelPreferences();
                })
                .catch((error) => {
                    console.error('Profil konnte nicht geladen werden:', error);
                });
        }
        function renderActivity(data) {
            const sessions = Array.isArray(data.sessions) ? data.sessions : [];
            const feed = Array.isArray(data.activity_feed) ? data.activity_feed : [];

            const sessionTimeline = sessions.length
                ? `<div class="timeline">${sessions.map((session) => `
                        <div class="timeline-item">
                            <div style="font-weight:600;">🔐 Session gestartet</div>
                            <div style="font-size:0.8rem;color:#9ca3af;">${formatDate(session.created_at)} • endet ${formatDate(session.expires_at)}</div>
                        </div>
                    `).join('')}</div>`
                : '<p style="font-size:0.85rem;color:#6b7280;">Noch keine Sessions aufgezeichnet.</p>';

            const feedTimeline = feed.length
                ? `<div class="timeline">${feed.map((item) => {
                        const iconMap = { message: '💡', read: '📖', response: '✅' };
                        const icon = iconMap[item.type] || '🧪';
                        return `
                            <div class="timeline-item">
                                <div style="font-weight:600;">${icon} ${escapeHtml(item.title || '')}</div>
                                <div style="font-size:0.85rem;margin:0.35rem 0;">${escapeHtml(item.details || '')}</div>
                                <div style="font-size:0.75rem;color:#9ca3af;">${formatDate(item.timestamp)}</div>
                            </div>
                        `;
                    }).join('')}</div>`
                : '<p style="font-size:0.85rem;color:#6b7280;">Noch keine Aktivität registriert.</p>';

            activityContent.innerHTML = `
                <div class="info-card">
                    <h3>📊 Aktivitätsübersicht</h3>
                    <ul class="info-list">
                        <li><strong>Letzter Login:</strong> ${formatDate(data.last_login)}</li>
                        <li><strong>Aktive Session:</strong> ${formatDate(data.session_started_at)}</li>
                        <li><strong>Ungelesene Nachrichten:</strong> ${data.unread_messages ?? 0}</li>
                        <li><strong>Gesendete Antworten:</strong> ${data.responses_submitted ?? 0}</li>
                    </ul>
                </div>
                <div class="info-card">
                    <h3>🕒 Session-Historie</h3>
                    ${sessionTimeline}
                </div>
                <div class="info-card">
                    <h3>🧪 Testing-Aktivität</h3>
                    ${feedTimeline}
                </div>
            `;
        }

        function loadActivity() {
            if (activityDataCache) {
                renderActivity(activityDataCache);
                return;
            }

            fetchJson('?ajax=activity')
                .then((data) => {
                    activityDataCache = data;
                    renderActivity(data);
                })
                .catch((error) => {
                    console.error('Aktivität konnte nicht geladen werden:', error);
                });
        }
        function renderBetaStatus(data) {
            const stats = data.stats || {};
            const progress = Number(data.progress || 0);
            const engagementRate = Number(data.engagement_rate || 0).toFixed(1);
            const streak = Number(data.testing_streak || 0);
            const featureUsage = Array.isArray(data.feature_usage) ? data.feature_usage : [];
            const recentFeatures = Array.isArray(data.recent_features) ? data.recent_features : [];

            const usageList = featureUsage.length
                ? `<ul class="info-list">${featureUsage.map((item) => `
                        <li>${escapeHtml(item.label || 'Feature')} • ${item.count} Interaktionen</li>
                    `).join('')}</ul>`
                : '<p style="font-size:0.85rem;color:#6b7280;">Noch keine Feature-Interaktionen aufgezeichnet.</p>';

            const recentTimeline = recentFeatures.length
                ? `<div class="timeline">${recentFeatures.map((feature) => {
                        const statusBadge = feature.status || '';
                        const responseInfo = feature.response ? `Antwort: ${feature.response === 'yes' ? 'Ja' : 'Nein'}` : 'Keine Antwort';
                        return `
                            <div class="timeline-item">
                                <div style="font-weight:600;">🚀 ${escapeHtml(feature.title || '')}</div>
                                <div style="font-size:0.85rem;margin:0.35rem 0;">${escapeHtml(statusBadge)} • ${escapeHtml(responseInfo)}</div>
                                <div style="font-size:0.75rem;color:#9ca3af;">${formatDate(feature.created_at)}${feature.read_at ? ' • gelesen ' + formatDate(feature.read_at) : ''}</div>
                            </div>
                        `;
                    }).join('')}</div>`
                : '<p style="font-size:0.85rem;color:#6b7280;">Noch keine getesteten Features verfügbar.</p>';

            betaStatusContent.innerHTML = `
                <div class="info-card">
                    <h3>🚀 Beta-Fortschritt</h3>
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div style="font-size:2rem;font-weight:700;color:var(--primary);">${progress}%</div>
                        <div style="text-align:right;font-size:0.85rem;color:#6b7280;">
                            Engagement: ${engagementRate}%<br>
                            Testing-Streak: ${streak} ${streak === 1 ? 'Tag' : 'Tage'}
                        </div>
                    </div>
                    <div class="progress-bar" aria-hidden="true"><span style="width:${Math.max(0, Math.min(progress, 100))}%;"></span></div>
                </div>
                <div class="info-card">
                    <h3>📊 Kommunikations-Stats</h3>
                    <ul class="info-list">
                        <li><strong>Nachrichten gesamt:</strong> ${stats.total ?? 0}</li>
                        <li><strong>Gelesen:</strong> ${stats.read ?? 0}</li>
                        <li><strong>Antwort benötigt:</strong> ${stats.needs_response ?? 0}</li>
                        <li><strong>Antworten gesendet:</strong> ${stats.responses_submitted ?? 0}</li>
                    </ul>
                </div>
                <div class="info-card">
                    <h3>🧩 Feature-Usage</h3>
                    ${usageList}
                </div>
                <div class="info-card">
                    <h3>🗂️ Letzte Features & Feedback</h3>
                    ${recentTimeline}
                </div>
            `;
        }

        function loadBetaStatus() {
            if (betaStatusCache) {
                renderBetaStatus(betaStatusCache);
                return;
            }

            fetchJson('?ajax=beta_status')
                .then((data) => {
                    betaStatusCache = data;
                    renderBetaStatus(data);
                })
                .catch((error) => {
                    console.error('Beta-Status konnte nicht geladen werden:', error);
                });
        }
        function getActiveTab() {
            const active = panelTabs.find((btn) => btn.classList.contains('active'));
            return active ? active.dataset.tab : 'messages';
        }

        setInterval(() => {
            if (!panelSettings.notifications) {
                return;
            }

            loadMessages();
            const activeTab = getActiveTab();
            if (activeTab === 'activity') {
                activityDataCache = null;
                loadActivity();
            } else if (activeTab === 'beta-status') {
                betaStatusCache = null;
                loadBetaStatus();
            }
        }, 30000);

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && panelSettings.notifications) {
                loadMessages();
            }
        });

        applyPanelPreferences();
        loadMessages();
    </script>
</body>
</html>
