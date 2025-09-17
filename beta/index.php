<?php
// beta/index.php - BETA CUSTOMER APP
session_start();

// BETA ACCESS GATE - Nur für Testuser
function checkBetaAccess() {
    require_once __DIR__ . '/../customer/auth.php';
    $customer = get_current_customer();

    if (!$customer || $customer['email'] !== 'marcus@einfachstarten.jetzt') {
        echo '<!DOCTYPE html>
        <html><head><meta charset="UTF-8"><title>Beta Access</title></head>
        <body style="font-family:Arial;text-align:center;padding:3rem;background:#f8fafc">
            <h1>🧪 Beta Access</h1>
            <p>Diese Beta-App ist nur für autorisierte Testuser zugänglich.</p>
            <p><a href="../login.php">← Zum normalen Login</a></p>
        </body></html>';
        exit;
    }
    return $customer;
}

$customer = checkBetaAccess();

function getPDO() {
    $config = require __DIR__ . '/../admin/config.php';
    return new PDO(
        "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

// Get unread messages
function getUnreadMessages($email) {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM beta_messages WHERE to_customer_email = ? AND is_read = 0 ORDER BY created_at DESC');
    $stmt->execute([$email]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Mark message as read
if (!empty($_POST['mark_read'])) {
    $messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
    if ($messageId <= 0) {
        header('Location: index.php');
        exit;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE beta_messages SET is_read = 1 WHERE id = ? AND to_customer_email = ?');
    $stmt->execute([$messageId, $customer['email']]);
    header('Location: index.php');
    exit;
}

$messages = getUnreadMessages($customer['email']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🧪 Beta Customer Dashboard</title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;background:#f8fafc}
        .beta-banner{background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:0.75rem;text-align:center;font-weight:bold}
        .container{max-width:800px;margin:2rem auto;padding:0 1rem}
        .welcome{background:white;padding:2rem;border-radius:12px;margin-bottom:2rem;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
        .messages-section{background:white;padding:2rem;border-radius:12px;margin-bottom:2rem;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
        .message{border-left:4px solid #4a90b8;padding:1rem;margin:1rem 0;background:#f8f9fa;border-radius:0 8px 8px 0}
        .message.success{border-left-color:#28a745}
        .message.warning{border-left-color:#ffc107}
        .message.question{border-left-color:#6f42c1}
        .message-meta{font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem}
        .message-text{color:#1f2937;line-height:1.6}
        .mark-read-btn{background:#28a745;color:white;border:none;padding:0.25rem 0.75rem;border-radius:4px;cursor:pointer;font-size:0.75rem;margin-top:0.5rem}
        .nav-links{text-align:center;margin:2rem 0}
        .nav-links a{color:#4a90b8;text-decoration:none;margin:0 1rem;padding:0.5rem 1rem;border:1px solid #4a90b8;border-radius:6px;transition:all 0.2s}
        .nav-links a:hover{background:#4a90b8;color:white}
    </style>
</head>
<body>
    <div class="beta-banner">
        🧪 BETA VERSION - Test Features für marcus@einfachstarten.jetzt
    </div>

    <div class="container">
        <div class="welcome">
            <h1>Willkommen, <?=htmlspecialchars($customer['first_name'])?>! 👋</h1>
            <p>Du nutzt die Beta-Version mit neuen experimentellen Features.</p>
        </div>

        <?php if (!empty($messages)): ?>
        <div class="messages-section">
            <h2>📨 Neue Nachrichten (<?=count($messages)?>)</h2>
            <?php foreach ($messages as $msg): ?>
            <div class="message <?=htmlspecialchars($msg['message_type'])?>">
                <div class="message-meta">
                    📅 <?=date('d.m.Y H:i', strtotime($msg['created_at']))?> •
                    <?php
                    $icons = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'question' => '❓'];
                    echo $icons[$msg['message_type']] ?? 'ℹ️';
                    ?>
                    <?=ucfirst($msg['message_type'])?>
                </div>
                <div class="message-text"><?=nl2br(htmlspecialchars($msg['message_text']))?></div>
                <form method="post" style="margin:0;display:inline">
                    <input type="hidden" name="message_id" value="<?=htmlspecialchars($msg['id'])?>">
                    <input type="hidden" name="mark_read" value="1">
                    <button type="submit" class="mark-read-btn">✓ Als gelesen markieren</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="messages-section" style="text-align:center;color:#6b7280">
            <h2>📨 Keine neuen Nachrichten</h2>
            <p>Alle Beta-Infos sind gelesen. Schau später nochmal vorbei!</p>
        </div>
        <?php endif; ?>

        <div class="nav-links">
            <a href="../customer/dashboard.php">📊 Dashboard</a>
            <a href="../customer/booking.php">📅 Termine buchen</a>
            <a href="../customer/appointments.php">📋 Meine Termine</a>
            <a href="../login.php?logout=1">🚪 Abmelden</a>
        </div>

        <div style="text-align:center;margin:2rem 0;color:#6b7280;font-size:0.875rem">
            <p>🧪 Beta Features werden kontinuierlich erweitert</p>
        </div>
    </div>
</body>
</html>
