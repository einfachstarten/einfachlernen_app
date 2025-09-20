<?php
// beta/index.php - Enhanced Beta Customer App with Live Messaging
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

function getPDO(): PDO
{
    $config = require __DIR__ . '/../admin/config.php';

    try {
        return new PDO(
            "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        die('DB connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
}

try {
    $pdo = getPDO();
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    try { $pdo->exec("ALTER TABLE beta_messages ADD COLUMN expects_response TINYINT(1) DEFAULT 0"); } catch (PDOException $e) { if ($e->getCode() !== '42S21') { throw $e; } }
    $pdo->exec("CREATE TABLE IF NOT EXISTS beta_responses (id INT AUTO_INCREMENT PRIMARY KEY, message_id INT NOT NULL UNIQUE, response ENUM('yes','no') NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (message_id) REFERENCES beta_messages(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {
    die('Beta table creation failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
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

if (empty($customer['beta_access'])) {
    echo '<!DOCTYPE html>'
        . '<html><head><meta charset="UTF-8"><title>Beta Access</title></head>'
        . '<body style="font-family:Arial;text-align:center;padding:3rem;background:#f8fafc">'
        . '<h1>🧪 Beta Access</h1>'
        . '<p>Diese Beta-App ist nur für autorisierte Testuser zugänglich.</p>'
        . '<p>Kontaktiere den Administrator für Beta-Zugang.</p>'
        . '<p><a href="../customer/index.php">← Zur normalen Customer App</a></p>'
        . '</body></html>';
    exit;
}

if (!empty($_POST['respond'])) {
    header('Content-Type: application/json');
    $messageId = (int)($_POST['message_id'] ?? 0); $choice = $_POST['response'] ?? '';
    if ($messageId && in_array($choice, ['yes', 'no'], true)) {
        $check = $pdo->prepare('SELECT expects_response FROM beta_messages WHERE id = ? AND to_customer_email = ?');
        $check->execute([$messageId, $customer['email']]);
        $message = $check->fetch(PDO::FETCH_ASSOC);
        if ($message && !empty($message['expects_response'])) {
            $pdo->prepare('INSERT INTO beta_responses (message_id, response) VALUES (?, ?) ON DUPLICATE KEY UPDATE response = VALUES(response), created_at = CURRENT_TIMESTAMP')->execute([$messageId, $choice]);
            $pdo->prepare('UPDATE beta_messages SET is_read = 1 WHERE id = ? AND to_customer_email = ?')->execute([$messageId, $customer['email']]);
            echo json_encode(['success' => true]); exit;
        }
    }
    echo json_encode(['success' => false]); exit;
}

if (!empty($_POST['mark_read'])) {
    $messageId = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;

    if ($messageId > 0) {
        $stmt = $pdo->prepare('UPDATE beta_messages SET is_read = 1 WHERE id = ? AND to_customer_email = ?');
        $stmt->execute([$messageId, $customer['email']]);
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

if (!empty($_GET['ajax'])) {
    $tab = ($_GET['tab'] ?? '') === 'read' ? 1 : 0;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM beta_messages WHERE to_customer_email = ? AND is_read = 0');
    $stmt->execute([$customer['email']]);
    $unreadCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT m.id, m.message_text, m.message_type, m.created_at, m.expects_response, r.response FROM beta_messages m LEFT JOIN beta_responses r ON r.message_id = m.id WHERE m.to_customer_email = ? AND m.is_read = ? ORDER BY m.created_at DESC LIMIT 15');
    $stmt->execute([$customer['email'], $tab]);

    $messages = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $messages[] = [
            'id' => (int) $row['id'],
            'message_text' => htmlspecialchars($row['message_text'], ENT_QUOTES, 'UTF-8'),
            'message_type' => htmlspecialchars($row['message_type'], ENT_QUOTES, 'UTF-8'),
            'created_at' => $row['created_at'],
            'expects_response' => !empty($row['expects_response']),
            'user_response' => $row['response'] ? htmlspecialchars($row['response'], ENT_QUOTES, 'UTF-8') : null,
        ];
    }

    header('Content-Type: application/json');
    echo json_encode(['unread_count' => $unreadCount, 'messages' => $messages]);
    exit;
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM beta_messages WHERE to_customer_email = ? AND is_read = 0');
$stmt->execute([$customer['email']]);
$initialUnreadCount = (int) $stmt->fetchColumn();
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

        .app-container {
            max-width: 800px;
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
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            position: relative;
        }

        .user-avatar.clickable {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .user-avatar.clickable:hover {
            transform: scale(1.05);
            border-color: rgba(255, 255, 255, 0.6);
            box-shadow: 0 4px 15px rgba(255, 255, 255, 0.2);
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff4444;
            color: white;
            border-radius: 50%;
            min-width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
            border: 2px solid white;
            z-index: 10;
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

        .welcome-section p {
            color: var(--gray-medium);
            font-size: 0.9rem;
        }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
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

        @media (max-width: 768px) {
            .action-grid {
                grid-template-columns: 1fr;
            }

            .app-content {
                padding: 1rem;
            }

            .header-content {
                gap: 0.75rem;
            }

            .user-avatar {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }
        }

        /* Message Panel Styles bleiben identisch - nicht ändern! */
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

        .message-panel {
            position: fixed;
            top: 0;
            right: -400px;
            width: 400px;
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

        .message-panel-header {
            background: var(--primary);
            color: white;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .message-tabs { display: flex; border-bottom: 1px solid #e5e7eb; }
        .message-tabs button { flex: 1; padding: 0.75rem; border: none; background: transparent; font-weight: 600; color: var(--gray-medium); cursor: pointer; border-bottom: 2px solid transparent; }
        .message-tabs button.active { color: var(--primary); border-bottom-color: var(--primary); }

        .close-panel {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.25rem;
        }

        .message-list {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
        }

        .message-item {
            border-left: 4px solid var(--primary);
            background: #f8f9fa;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0 8px 8px 0;
            transition: all 0.3s ease;
        }

        .message-item.success { border-left-color: var(--success); }
        .message-item.warning { border-left-color: var(--warning); }
        .message-item.question { border-left-color: #6f42c1; }

        .message-meta {
            font-size: 0.875rem;
            color: var(--gray-medium);
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .message-text {
            color: var(--gray-dark);
            line-height: 1.6;
            margin-bottom: 0.75rem;
        }

        .mark-read-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .mark-read-btn:hover {
            background: #218838;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <div class="app-header">
            <div class="header-content">
                <div class="user-avatar clickable" onclick="toggleMessagePanel()">
                    <?= strtoupper(substr($customer['first_name'], 0, 1)) ?>
                    <div class="notification-badge" id="notificationBadge" style="display: <?= $initialUnreadCount > 0 ? 'flex' : 'none' ?>;">
                        <?= $initialUnreadCount > 99 ? '99+' : $initialUnreadCount; ?>
                    </div>
                </div>
                <div class="user-info">
                    <h1>🧪 Beta: <?= htmlspecialchars($customer['first_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>Testing neue Features vor Production</p>
                </div>
                <a href="../login.php?logout=1" class="logout-btn">
                    🚪 Abmelden
                </a>
            </div>
        </div>

        <div class="app-content">
            <div class="welcome-section">
                <h2>Willkommen in der Beta-Umgebung!</h2>
                <p>Du testest neue Features bevor sie für alle verfügbar sind. Klicke auf dein Profilbild um Nachrichten zu sehen.</p>
            </div>

            <div class="action-grid">
                <a href="../customer/booking.php" class="action-card">
                    <div class="action-icon">📅</div>
                    <div class="action-content">
                        <h3>Termine buchen</h3>
                        <p>Verfügbare Termine finden und buchen</p>
                    </div>
                </a>

                <a href="../customer/appointments.php" class="action-card">
                    <div class="action-icon">📋</div>
                    <div class="action-content">
                        <h3>Meine Termine</h3>
                        <p>Gebuchte Termine verwalten</p>
                    </div>
                </a>

                <a href="../customer/index.php" class="action-card">
                    <div class="action-icon">🏠</div>
                    <div class="action-content">
                        <h3>Normal Dashboard</h3>
                        <p>Zur Standard Customer App wechseln</p>
                    </div>
                </a>

                <div class="action-card" style="border: 2px dashed #667eea; opacity: 0.7; cursor: default;">
                    <div class="action-icon" style="background: #667eea;">🚀</div>
                    <div class="action-content">
                        <h3>Mehr Features Coming Soon</h3>
                        <p>Push Notifications, Dark Mode, etc.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="overlay" id="overlay" onclick="closeMessagePanel()"></div>
    <div class="message-panel" id="messagePanel">
        <div class="message-panel-header">
            <h3>📨 Beta Nachrichten</h3>
            <button class="close-panel" type="button" onclick="closeMessagePanel()">×</button>
        </div>
        <div class="message-tabs">
            <button type="button" class="active" data-tab="new" onclick="loadMessages('new')">Neu</button>
            <button type="button" data-tab="read" onclick="loadMessages('read')">Gelesen</button>
        </div>
        <div class="message-list" id="messageList"></div>
    </div>

    <script>
        const messagePanel = document.getElementById('messagePanel');
        const overlay = document.getElementById('overlay');
        const notificationBadge = document.getElementById('notificationBadge');
        const messageList = document.getElementById('messageList');
        const tabButtons = document.querySelectorAll('.message-tabs button');
        let currentTab = 'new';

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
            loadMessages();
        }

        function closeMessagePanel() {
            messagePanel.classList.remove('open');
            overlay.classList.remove('active');
        }

        function loadMessages(tab) {
            if (tab) {
                currentTab = tab === 'read' ? 'read' : 'new';
            }
            tabButtons.forEach((btn) => {
                btn.classList.toggle('active', btn.dataset.tab === currentTab);
            });
            fetch(`?ajax=1&tab=${encodeURIComponent(currentTab)}`, { credentials: 'same-origin' })
                .then((response) => response.json())
                .then((data) => {
                    updateMessageBadge(data.unread_count);
                    renderMessages(Array.isArray(data.messages) ? data.messages : []);
                })
                .catch((error) => {
                    console.error('Error loading messages:', error);
                });
        }

        function renderMessages(messages) {
            if (!messages.length) {
                const empty = currentTab === 'read' ? '📁 Noch keine gelesenen Nachrichten' : '📭 Keine ungelesenen Nachrichten';
                messageList.innerHTML = `<div style="text-align:center;padding:2rem;color:#6b7280;"><p>${empty}</p><p style="font-size:0.875rem;margin-top:0.5rem;">Der Admin kann dir hier Updates senden</p></div>`;
                return;
            }

            const icons = {
                info: 'ℹ️',
                success: '✅',
                warning: '⚠️',
                question: '❓'
            };

            messageList.innerHTML = messages.map((msg) => {
                const type = msg.message_type || 'info';
                const icon = icons[type] || 'ℹ️';
                const createdAt = new Date(msg.created_at).toLocaleString('de-DE');
                const text = String(msg.message_text || '')
                    .replace(/\n/g, '<br>');
                const markReadButton = currentTab === 'new' && !msg.expects_response
                    ? `<button class="mark-read-btn" type="button" onclick="markAsRead(${Number(msg.id)})">✓ Als gelesen markieren</button>`
                    : '';

                const responseSection = msg.expects_response
                    ? (msg.user_response
                        ? `<div style="font-size:0.85rem;color:#6b7280;margin-bottom:0.5rem;">Antwort gesendet: ${msg.user_response === 'yes' ? '✅ Ja' : '❌ Nein'}</div>`
                        : `<div style="display:flex;gap:0.5rem;margin-bottom:0.5rem;"><button type="button" style="flex:1;padding:0.5rem;border-radius:6px;border:1px solid #bbf7d0;background:#e6f4ea;color:#166534;font-weight:600;" onclick="sendResponse(${Number(msg.id)}, 'yes')">Ja</button><button type="button" style="flex:1;padding:0.5rem;border-radius:6px;border:1px solid #fecaca;background:#fee2e2;color:#b91c1c;font-weight:600;" onclick="sendResponse(${Number(msg.id)}, 'no')">Nein</button></div>`)
                    : '';

                return `<div class="message-item ${type}"><div class="message-meta"><span>${icon} ${type.charAt(0).toUpperCase() + type.slice(1)}</span><span>${createdAt}</span></div><div class="message-text">${text}</div>${responseSection}${markReadButton}</div>`;
            }).join('');
        }

        function markAsRead(messageId) {
            if (!messageId) {
                return;
            }

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `mark_read=1&message_id=${encodeURIComponent(messageId)}`,
                credentials: 'same-origin'
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data && data.success) {
                        loadMessages();
                    }
                })
                .catch((error) => {
                    console.error('Error marking message as read:', error);
                });
        }

        function sendResponse(messageId, response) {
            if (!messageId || !response) {
                return;
            }
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `respond=1&message_id=${encodeURIComponent(messageId)}&response=${encodeURIComponent(response)}`,
                credentials: 'same-origin'
            })
                .then((res) => res.json())
                .then((data) => {
                    if (data && data.success) {
                        loadMessages();
                    }
                })
                .catch((error) => {
                    console.error('Error sending response:', error);
                });
        }

        function updateMessageBadge(count) {
            if (!notificationBadge) {
                return;
            }
            const parsed = Number(count) || 0;

            if (parsed > 0) {
                notificationBadge.style.display = 'flex';
                notificationBadge.textContent = parsed > 99 ? '99+' : parsed;
            } else {
                notificationBadge.style.display = 'none';
            }
        }

        setInterval(() => loadMessages(), 30000);

        loadMessages();
    </script>
</body>
</html>
