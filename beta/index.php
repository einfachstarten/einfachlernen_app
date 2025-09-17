<?php
// beta/index.php - CORRECTED BETA CUSTOMER APP
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// Function to get PDO connection
function getPDO() {
    $config = require __DIR__ . '/../admin/config.php';
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

// Create beta_messages table if not exists
try {
    $pdo = getPDO();
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
} catch (Exception $e) {
    die('Beta table creation failed: ' . htmlspecialchars($e->getMessage()));
}

// Check if user is logged in first
if (empty($_SESSION['customer_id']) || empty($_SESSION['session_authenticated'])) {
    echo '<!DOCTYPE html>
    <html><head><meta charset="UTF-8"><title>Beta Login Required</title></head>
    <body style="font-family:Arial;text-align:center;padding:3rem;background:#f8fafc">
        <h1>🧪 Beta App</h1>
        <p>Bitte zuerst <a href="../login.php">einloggen</a> um die Beta-Funktionen zu nutzen.</p>
        <div style="margin:2rem;padding:1rem;background:#fff3cd;border-radius:8px;color:#856404">
            <strong>Debug Info:</strong><br>
            Session ID: ' . session_id() . '<br>
            Customer ID: ' . ($_SESSION['customer_id'] ?? 'not set') . '<br>
            Authenticated: ' . ($_SESSION['session_authenticated'] ?? 'false') . '
        </div>
    </body></html>';
    exit;
}

// Get customer from database
$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$_SESSION['customer_id']]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    session_destroy();
    die('Customer not found in database. Please login again.');
}

// BETA ACCESS CHECK - Only for test user
if ($customer['email'] !== 'marcus@einfachstarten.jetzt') {
    echo '<!DOCTYPE html>
    <html><head><meta charset="UTF-8"><title>Beta Access</title></head>
    <body style="font-family:Arial;text-align:center;padding:3rem;background:#f8fafc">
        <h1>🧪 Beta Access</h1>
        <p>Diese Beta-App ist nur für autorisierte Testuser zugänglich.</p>
        <p>Aktueller User: <strong>' . htmlspecialchars($customer['email']) . '</strong></p>
        <p>Benötigt: <strong>marcus@einfachstarten.jetzt</strong></p>
        <p><a href="../customer/index.php">← Zur normalen Customer App</a></p>
    </body></html>';
    exit;
}

// Get unread messages for this user
$stmt = $pdo->prepare('SELECT * FROM beta_messages WHERE to_customer_email = ? AND is_read = 0 ORDER BY created_at DESC');
$stmt->execute([$customer['email']]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
$icons = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'question' => '❓'];
$hasMessages = !empty($messages);
$messageListHtml = '';

if ($hasMessages) {
    foreach ($messages as $msg) {
        $icon = $icons[$msg['message_type']] ?? 'ℹ️';
        $messageListHtml .= '<div class="message ' . htmlspecialchars($msg['message_type']) . '">';
        $messageTypeLabel = htmlspecialchars(ucfirst($msg['message_type']), ENT_QUOTES, 'UTF-8');
        $messageListHtml .= '<div class="message-meta">📅 ' . date('d.m.Y H:i', strtotime($msg['created_at'])) . ' • ' . $icon . ' ' . $messageTypeLabel . '</div>';
        $messageListHtml .= '<div class="message-text">' . nl2br(htmlspecialchars($msg['message_text'])) . '</div>';
        $messageListHtml .= '<form method="post" style="margin:0;display:inline">';
        $messageListHtml .= '<input type="hidden" name="message_id" value="' . (int) $msg['id'] . '">';
        $messageListHtml .= '<input type="hidden" name="mark_read" value="1">';
        $messageListHtml .= '<button type="submit" class="mark-read-btn">✓ Als gelesen markieren</button>';
        $messageListHtml .= '</form>';
        $messageListHtml .= '</div>';
    }
}

// Handle mark as read
if ($_POST['mark_read'] ?? false) {
    $messageId = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;
    if ($messageId > 0) {
        $stmt = $pdo->prepare('UPDATE beta_messages SET is_read = 1 WHERE id = ? AND to_customer_email = ?');
        $stmt->execute([$messageId, $customer['email']]);
    }
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🧪 Beta Customer Dashboard</title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;background:#f8fafc}
        .beta-banner{background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:0.75rem;text-align:center;font-weight:bold;animation:pulse 2s infinite}
        @keyframes pulse{0%{opacity:1}50%{opacity:0.8}100%{opacity:1}}
        .container{max-width:800px;margin:2rem auto;padding:0 1rem}
        .welcome{background:white;padding:2rem;border-radius:12px;margin-bottom:2rem;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
        .debug-info{background:#fff3cd;color:#856404;padding:1rem;border-radius:8px;margin-bottom:2rem;font-size:0.875rem}
        .messages-section{background:white;padding:2rem;border-radius:12px;margin-bottom:2rem;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
        .message{border-left:4px solid #4a90b8;padding:1rem;margin:1rem 0;background:#f8f9fa;border-radius:0 8px 8px 0}
        .message.success{border-left-color:#28a745}
        .message.warning{border-left-color:#ffc107}
        .message.question{border-left-color:#6f42c1}
        .message-meta{font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem}
        .message-text{color:#1f2937;line-height:1.6}
        .mark-read-btn{background:#28a745;color:white;border:none;padding:0.25rem 0.75rem;border-radius:4px;cursor:pointer;font-size:0.75rem;margin-top:0.5rem}
        .nav-links{text-align:center;margin:2rem 0}
        .nav-links a{color:#4a90b8;text-decoration:none;margin:0 1rem;padding:0.5rem 1rem;border:1px solid #4a90b8;border-radius:6px;transition:all 0.2s;display:inline-block}
        .nav-links a:hover{background:#4a90b8;color:white}
        .beta-features{background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:2rem;border-radius:12px;margin:2rem 0}
    </style>
</head>
<body>
    <div class="beta-banner">
        🧪 BETA VERSION - Experimentelle Features für <?=htmlspecialchars($customer['email'])?>
    </div>
    
    <div class="container">
        <div class="debug-info">
            <strong>🔍 Debug Info:</strong><br>
            User: <?=htmlspecialchars($customer['first_name'])?> (<?=htmlspecialchars($customer['email'])?>)<br>
            Session: <?=session_id()?><br>
            Ungelesene Nachrichten: <?=count($messages)?><br>
            Timestamp: <?=date('d.m.Y H:i:s')?>
        </div>
        
        <div class="welcome">
            <h1>Willkommen in der Beta, <?=htmlspecialchars($customer['first_name'])?>! 👋</h1>
            <p>Du nutzt die Beta-Version mit experimentellen Features. Alles hier ist Work-in-Progress!</p>
        </div>
        
        <?php if ($hasMessages): ?>
        <div class="messages-section">
            <h2>📨 Neue Beta-Nachrichten (<?=count($messages)?>)</h2>
            <p style="color:#6b7280;font-size:0.875rem;margin-bottom:1rem">
                ℹ️ Diese Nachrichten kommen direkt vom Admin und sind nur in der Beta-Version sichtbar.
            </p>
            <?=$messageListHtml?>
        </div>
        <?php else: ?>
        <div class="messages-section">
            <h2>📨 Beta-Nachrichten</h2>
            <p style="color:#6b7280;font-style:italic">Keine ungelesenen Nachrichten. Der Admin kann dir hier Updates und Feedback-Anfragen senden.</p>
        </div>
        <?php endif; ?>
        
        <div class="beta-features">
            <h3>🚀 Beta Features in Entwicklung</h3>
            <ul style="margin:1rem 0;padding-left:2rem">
                <li>✅ Admin-Messaging System</li>
                <li>🔄 Push Notifications (coming soon)</li>
                <li>🔄 Erweiterte Booking Features</li>
                <li>🔄 Dark Mode</li>
                <li>🔄 Offline Capabilities</li>
            </ul>
        </div>
        
        <div class="nav-links">
            <a href="../customer/index.php">📊 Normal Dashboard</a>
            <a href="../customer/booking.php">📅 Termine buchen</a>
            <a href="../customer/appointments.php">📋 Meine Termine</a>
            <a href="../login.php?logout=1">🚪 Abmelden</a>
        </div>
        
        <div style="text-align:center;margin:2rem 0;color:#6b7280;font-size:0.875rem">
            <p>🧪 Du hilfst beim Testen neuer Features - Vielen Dank!</p>
        </div>
    </div>
</body>
</html>
