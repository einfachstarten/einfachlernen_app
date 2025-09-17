<?php
// admin/beta_messaging.php - ADMIN MESSAGING INTERFACE
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

function getPDO()
{
    $config = require __DIR__ . '/config.php';
    return new PDO(
        "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

$pdo = getPDO();

// Create table if not exists (beta feature safeguard)
$pdo->exec("CREATE TABLE IF NOT EXISTS beta_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_admin BOOLEAN DEFAULT TRUE,
    to_customer_email VARCHAR(100) NOT NULL,
    message_text TEXT NOT NULL,
    message_type ENUM('info', 'success', 'warning', 'question') DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_email (to_customer_email),
    INDEX idx_unread (is_read, to_customer_email),
    INDEX idx_created (created_at)
)");

$success = '';

// Send message
if (($_POST['action'] ?? '') === 'send' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    $allowed_types = ['info', 'success', 'warning', 'question'];
    $message_type = in_array($_POST['type'] ?? 'info', $allowed_types, true) ? $_POST['type'] : 'info';

    if ($message !== '') {
        $stmt = $pdo->prepare('INSERT INTO beta_messages (to_customer_email, message_text, message_type) VALUES (?, ?, ?)');
        $stmt->execute([
            'marcus@einfachstarten.jetzt',
            $message,
            $message_type
        ]);
        $success = '✅ Beta-Nachricht an marcus@einfachstarten.jetzt gesendet!';
    }
}

// Get message history
$messages = $pdo->prepare('SELECT * FROM beta_messages WHERE to_customer_email = ? ORDER BY created_at DESC LIMIT 20');
$messages->execute(['marcus@einfachstarten.jetzt']);
$message_history = $messages->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>🧪 Beta Messaging</title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:2rem;background:#f8fafc;color:#1f2937}
        .container{max-width:800px;margin:0 auto}
        .send-form{background:white;padding:2rem;border-radius:12px;margin-bottom:2rem;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
        .history{background:white;padding:2rem;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
        .message{border-left:4px solid #4a90b8;padding:1rem;margin:1rem 0;background:#f8f9fa;border-radius:0 8px 8px 0}
        .message.read{opacity:0.7}
        .success{background:#d4edda;color:#155724;padding:1rem;border-radius:5px;margin:1rem 0}
        select,textarea,button{padding:0.75rem;margin:0.5rem 0;border-radius:6px;border:1px solid #d1d5db;font-family:inherit}
        button{background:#4a90b8;color:white;border:none;cursor:pointer;font-weight:500;transition:all 0.2s}
        button:hover{background:#2563eb;transform:translateY(-1px)}
        textarea{width:100%;min-height:120px;resize:vertical}
        .beta-badge{background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:0.5rem 1rem;border-radius:20px;font-size:0.875rem;font-weight:bold}
        .back-link{color:#2563eb;text-decoration:none}
        .back-link:hover{text-decoration:underline}
    </style>
</head>
<body>
<div class="container">
    <h1>🧪 Beta Messaging System</h1>
    <p><a class="back-link" href="dashboard.php">← Zurück zum Dashboard</a></p>
    <p><span class="beta-badge">BETA FEATURE</span> Nur für marcus@einfachstarten.jetzt</p>

    <?php if ($success): ?>
        <div class="success"><?=$success?></div>
    <?php endif; ?>

    <div class="send-form">
        <h3>📤 Nachricht an Beta-User senden</h3>
        <form method="post">
            <input type="hidden" name="action" value="send">

            <p><strong>Empfänger:</strong> marcus@einfachstarten.jetzt</p>

            <select name="type" style="width:200px">
                <option value="info">ℹ️ Information</option>
                <option value="success">✅ Erfolg/Bestätigung</option>
                <option value="warning">⚠️ Warnung/Hinweis</option>
                <option value="question">❓ Frage/Feedback</option>
            </select>

            <textarea name="message" placeholder="Nachricht eingeben..." required></textarea>

            <button type="submit">📨 Beta-Nachricht senden</button>
        </form>
    </div>

    <div class="history">
        <h3>📋 Nachrichten-Verlauf (<?=count($message_history)?>)</h3>
        <?php if (empty($message_history)): ?>
            <p style="color:#6b7280;font-style:italic">Noch keine Nachrichten gesendet.</p>
        <?php else: ?>
            <?php foreach ($message_history as $msg): ?>
            <div class="message <?=$msg['is_read'] ? 'read' : ''?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem">
                    <span style="font-size:0.875rem;color:#6b7280">
                        <?=date('d.m.Y H:i', strtotime($msg['created_at']))?> •
                        <?php
                        $icons = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'question' => '❓'];
                        echo $icons[$msg['message_type']] ?? 'ℹ️';
                        ?>
                        <?=ucfirst($msg['message_type'])?>
                    </span>
                    <span style="font-size:0.75rem;color:<?=$msg['is_read'] ? '#28a745' : '#dc3545'?>">
                        <?=$msg['is_read'] ? '✓ Gelesen' : '● Ungelesen'?>
                    </span>
                </div>
                <div><?=nl2br(htmlspecialchars($msg['message_text']))?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
