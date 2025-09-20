<?php
// admin/beta_messaging.php - Korrigierter Admin Bereich
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}

function getPDO() {
    $config = require __DIR__ . '/config.php';
    return new PDO(
        "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

$pdo = getPDO();

// Create table automatically
$pdo->exec("CREATE TABLE IF NOT EXISTS beta_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_admin BOOLEAN DEFAULT TRUE,
    to_customer_email VARCHAR(100) NOT NULL,
    message_text TEXT NOT NULL,
    message_type ENUM('info', 'success', 'warning', 'question') DEFAULT 'info',
    expects_response TINYINT(1) DEFAULT 0,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_customer_email (to_customer_email),
    INDEX idx_unread (is_read, to_customer_email),
    INDEX idx_created (created_at)
)");
try { $pdo->exec("ALTER TABLE beta_messages ADD COLUMN expects_response TINYINT(1) DEFAULT 0"); } catch (PDOException $e) { if ($e->getCode() !== '42S21') { throw $e; } }
$pdo->exec("CREATE TABLE IF NOT EXISTS beta_responses (id INT AUTO_INCREMENT PRIMARY KEY, message_id INT NOT NULL UNIQUE, response ENUM('yes','no') NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (message_id) REFERENCES beta_messages(id) ON DELETE CASCADE)");

$success = '';

// Check if beta user exists
$beta_user_check = $pdo->prepare('SELECT * FROM customers WHERE email = ?');
$beta_user_check->execute(['marcus@einfachstarten.jetzt']);
$beta_user = $beta_user_check->fetch(PDO::FETCH_ASSOC);

if (!$beta_user) {
    echo '<div style="background:#f8d7da;color:#721c24;padding:1rem;border-radius:5px;margin:2rem;text-align:center">
        ❌ Beta-User marcus@einfachstarten.jetzt nicht in der Datenbank gefunden!<br>
        Bitte erst den User anlegen bevor das Messaging getestet wird.
    </div>';
    exit;
}

// Send message
if (($_POST['action'] ?? '') === 'send') {
    $message = trim($_POST['message'] ?? '');
    $type = $_POST['type'] ?? 'info';
    $allowedTypes = ['info', 'success', 'warning', 'question'];
    $expectsResponse = !empty($_POST['expects_response']) ? 1 : 0;
    if ($message !== '') {
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'info';
        }
        $stmt = $pdo->prepare('INSERT INTO beta_messages (to_customer_email, message_text, message_type, expects_response) VALUES (?, ?, ?, ?)');
        $stmt->execute(['marcus@einfachstarten.jetzt', $message, $type, $expectsResponse]);
        $success = "✅ Beta-Nachricht erfolgreich gesendet!";
    }
}

// Get message history
$messages = $pdo->prepare('SELECT * FROM beta_messages WHERE to_customer_email = ? ORDER BY created_at DESC LIMIT 20');
$messages->execute(['marcus@einfachstarten.jetzt']);
$message_history = $messages->fetchAll(PDO::FETCH_ASSOC);
$response_stats_stmt = $pdo->prepare('SELECT m.message_text, SUM(r.response = "yes") AS yes_count, SUM(r.response = "no") AS no_count FROM beta_messages m LEFT JOIN beta_responses r ON r.message_id = m.id WHERE m.to_customer_email = ? AND m.expects_response = 1 GROUP BY m.id, m.message_text ORDER BY m.created_at DESC LIMIT 20');
$response_stats_stmt->execute(['marcus@einfachstarten.jetzt']);
$response_stats = $response_stats_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>🧪 Beta Messaging</title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:2rem;background:#f8fafc}
        .container{max-width:900px;margin:0 auto}
        .header{background:linear-gradient(135deg,#667eea,#764ba2);color:white;padding:2rem;border-radius:12px;margin-bottom:2rem;text-align:center}
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
        .user-info{background:#e3f2fd;padding:1rem;border-radius:8px;margin:1rem 0}
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🧪 Beta Messaging System</h1>
        <p>Direkter Kommunikationskanal mit Beta-Usern</p>
    </div>
    
    <p><a href="dashboard.php">← Zurück zum Dashboard</a></p>
    
    <div class="user-info">
        <strong>Beta-User Status:</strong><br>
        Name: <?=htmlspecialchars($beta_user['first_name'] . ' ' . $beta_user['last_name'])?><br>
        Email: <?=htmlspecialchars($beta_user['email'])?><br>
        Status: <?=htmlspecialchars($beta_user['status'])?><br>
        Registriert: <?=htmlspecialchars($beta_user['created_at'])?>
    </div>
    
    <?php if(!empty($success)): ?>
        <div class="success"><?=$success?></div>
    <?php endif; ?>
    
    <div class="send-form">
        <h3>📤 Nachricht an Beta-User senden</h3>
        <form method="post">
            <input type="hidden" name="action" value="send">
            
            <p><strong>Empfänger:</strong> <?=htmlspecialchars($beta_user['first_name'])?> (marcus@einfachstarten.jetzt)</p>
            
            <select name="type" style="width:250px">
                <option value="info">ℹ️ Information/Update</option>
                <option value="success">✅ Erfolg/Bestätigung</option>
                <option value="warning">⚠️ Warnung/Wichtiger Hinweis</option>
                <option value="question">❓ Frage/Feedback benötigt</option>
            </select>
            <label style="display:block;margin:0.5rem 0 0.75rem 0;font-weight:500;"><input type="checkbox" name="expects_response" value="1"> Ja/Nein Frage?</label>

            <textarea name="message" placeholder="Beta-Nachricht eingeben...

Beispiele:
- Neues Feature xyz ist verfügbar zum Testen
- Bitte teste die neue Booking-Funktion und gib Feedback
- Warnung: Beta-System wird morgen um 14:00 neu gestartet" required></textarea>
            
            <button type="submit">📨 Beta-Nachricht senden</button>
        </form>
    </div>
    
    <div class="history">
        <h3>📋 Nachrichten-Verlauf (<?=count($message_history)?>)</h3>
        <?php if(empty($message_history)): ?>
            <p style="color:#6b7280;font-style:italic">Noch keine Nachrichten gesendet. Schicke die erste Beta-Nachricht!</p>
        <?php else: ?>
            <?php foreach($message_history as $msg): ?>
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
                    <span style="font-size:0.75rem;color:<?=$msg['is_read'] ? '#28a745' : '#dc3545'?>;font-weight:bold">
                        <?=$msg['is_read'] ? '✓ GELESEN' : '● UNGELESEN'?>
                    </span>
                </div>
                <div><?=nl2br(htmlspecialchars($msg['message_text']))?></div>
                <?php if(!empty($msg['expects_response'])): ?>
                    <div style="color:#3b82f6;font-size:0.875rem;margin-top:0.5rem;">
                        Antwort erwartet: Ja/Nein
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php if(!empty($response_stats)): ?>
    <div style="background:white;padding:2rem;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.1);margin-top:2rem;">
        <h3>📊 Ja/Nein Antworten</h3>
        <?php foreach($response_stats as $stat): ?>
            <?php
            $yesCount = (int)($stat['yes_count'] ?? 0);
            $noCount = (int)($stat['no_count'] ?? 0);
            if(($yesCount > 0) || ($noCount > 0)):
            ?>
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:1rem;margin:1rem 0;">
                <div style="font-weight:600;margin-bottom:1rem;">"<?=htmlspecialchars($stat['message_text'])?>"</div>
                <div style="display:flex;gap:1rem;text-align:center;">
                    <div style="background:#dcfce7;padding:1rem;border-radius:6px;flex:1;">
                        <div style="font-size:1.5rem;font-weight:bold;color:#15803d;"><?=$yesCount?></div>
                        <div style="color:#15803d;">✅ Ja</div>
                    </div>
                    <div style="background:#fef2f2;padding:1rem;border-radius:6px;flex:1;">
                        <div style="font-size:1.5rem;font-weight:bold;color:#dc2626;"><?=$noCount?></div>
                        <div style="color:#dc2626;">❌ Nein</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
