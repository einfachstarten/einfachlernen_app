<?php
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

function getPDO(): PDO
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

// Ensure beta messaging tables exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS beta_messages (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

try {
    $pdo->exec("ALTER TABLE beta_messages ADD COLUMN expects_response TINYINT(1) DEFAULT 0");
} catch (PDOException $e) {
    if ($e->getCode() !== '42S21') {
        throw $e;
    }
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS beta_responses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL UNIQUE,
        response ENUM('yes', 'no') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (message_id) REFERENCES beta_messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['send_message'])) {
    $message = trim($_POST['message'] ?? '');
    $type = $_POST['type'] ?? 'info';
    $recipients = $_POST['recipients'] ?? 'all';
    $expects_response = !empty($_POST['expects_response']) ? 1 : 0;

    $allowedTypes = ['info', 'success', 'warning', 'question'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'info';
    }

    if ($message === '') {
        $error_msg = 'Bitte gib eine Nachricht ein.';
    } else {
        if ($recipients === 'all') {
            $customerStmt = $pdo->query("SELECT email FROM customers WHERE status = 'active'");
            $customers = $customerStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($customers) {
                $stmt = $pdo->prepare('
                    INSERT INTO beta_messages (to_customer_email, message_text, message_type, expects_response)
                    VALUES (?, ?, ?, ?)
                ');

                foreach ($customers as $c) {
                    $stmt->execute([
                        $c['email'],
                        $message,
                        $type,
                        $expects_response
                    ]);
                }

                $success_msg = 'Nachricht an ' . count($customers) . ' Kunden gesendet!';
            } else {
                $error_msg = 'Keine aktiven Kunden gefunden.';
            }
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO beta_messages (to_customer_email, message_text, message_type, expects_response)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([
                $recipients,
                $message,
                $type,
                $expects_response
            ]);

            $success_msg = 'Nachricht an ' . htmlspecialchars($recipients, ENT_QUOTES, 'UTF-8') . ' gesendet!';
        }
    }
}

$statsStmt = $pdo->query('
    SELECT
        COUNT(DISTINCT to_customer_email) AS total_customers,
        COUNT(*) AS total_messages,
        SUM(is_read = 1) AS read_messages,
        SUM(is_read = 0) AS unread_messages
    FROM beta_messages
');
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalMessages = (int)($stats['total_messages'] ?? 0);
$readMessages = (int)($stats['read_messages'] ?? 0);
$unreadMessages = (int)($stats['unread_messages'] ?? 0);
$readPercentage = $totalMessages > 0 ? round(($readMessages / $totalMessages) * 100) : 0;

$customerQuery = $pdo->query('
    SELECT
        c.*,
        COUNT(m.id) AS total_messages,
        SUM(m.is_read = 0) AS unread_messages,
        MAX(m.created_at) AS last_message
    FROM customers c
    LEFT JOIN beta_messages m ON m.to_customer_email = c.email
    WHERE c.status = "active"
    GROUP BY c.id
    ORDER BY unread_messages DESC, c.last_name ASC
');
$customers = $customerQuery->fetchAll(PDO::FETCH_ASSOC);

foreach ($customers as &$customer) {
    $customer['total_messages'] = (int)($customer['total_messages'] ?? 0);
    $customer['unread_messages'] = (int)($customer['unread_messages'] ?? 0);
}
unset($customer);

$selected_customer = $_GET['customer'] ?? null;

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>📨 Messaging Center</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f8fafc;
            line-height: 1.5;
        }

        .container {
            display: flex;
            height: 100vh;
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 0 30px rgba(0,0,0,0.1);
        }

        .sidebar {
            width: 350px;
            background: white;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #4a90b8, #52b3a4);
            color: white;
        }

        .sidebar-header h2 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .stat-box {
            background: rgba(255,255,255,0.1);
            padding: 0.5rem;
            border-radius: 8px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .stat-label {
            font-size: 0.75rem;
            opacity: 0.9;
        }

        .customer-list-header {
            padding: 1rem;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
        }

        .search-box {
            width: 100%;
            padding: 0.5rem 1rem;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            font-size: 0.9rem;
            outline: none;
        }

        .customer-list {
            flex: 1;
            overflow-y: auto;
        }

        .customer-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .customer-item:hover {
            background: #f8fafc;
        }

        .customer-item.active {
            background: #e3f2fd;
            border-left: 3px solid #4a90b8;
        }

        .customer-info {
            flex: 1;
        }

        .customer-name {
            font-weight: 600;
            color: #1c1e21;
            margin-bottom: 0.25rem;
        }

        .customer-email {
            font-size: 0.85rem;
            color: #65676b;
        }

        .customer-stats {
            text-align: right;
        }

        .unread-badge {
            background: #ff4444;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
        }

        .last-seen {
            font-size: 0.75rem;
            color: #8a8d91;
            margin-top: 0.25rem;
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #f8fafc;
        }

        .compose-header {
            padding: 1.5rem;
            background: white;
            border-bottom: 1px solid #e5e7eb;
        }

        .compose-form {
            background: white;
            padding: 1.5rem;
            margin: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }

        .recipient-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            outline: none;
            background: white;
        }

        .message-types {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .type-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .type-btn.active {
            background: #4a90b8;
            color: white;
            border-color: #4a90b8;
        }

        .message-textarea {
            width: 100%;
            min-height: 150px;
            padding: 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-family: inherit;
            font-size: 1rem;
            resize: vertical;
            outline: none;
        }

        .message-textarea:focus {
            border-color: #4a90b8;
            box-shadow: 0 0 0 3px rgba(74, 144, 184, 0.1);
        }

        .form-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .send-btn {
            padding: 0.75rem 2rem;
            background: linear-gradient(135deg, #4a90b8, #52b3a4);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .send-btn:hover {
            transform: translateY(-2px);
        }

        .history-area {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
        }

        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .message-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .message-card.type-info { border-left-color: #3b82f6; }
        .message-card.type-success { border-left-color: #10b981; }
        .message-card.type-warning { border-left-color: #f59e0b; }
        .message-card.type-question { border-left-color: #8b5cf6; }

        .message-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
            color: #6b7280;
        }

        .message-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-read {
            background: #d4f4dd;
            color: #00875a;
        }

        .status-unread {
            background: #fee2e2;
            color: #dc3545;
        }

        .message-content {
            color: #374151;
            line-height: 1.5;
        }

        .response-stats {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #f3f4f6;
            display: flex;
            gap: 1rem;
        }

        .response-stat {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.85rem;
        }

        .alert-success {
            background: #d4f4dd;
            color: #00875a;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success::before {
            content: '✅';
        }

        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-error::before {
            content: '⚠️';
        }

        .broadcast-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: transform 0.2s;
        }

        .broadcast-btn:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>📨 Messaging Center</h2>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-value"><?= (int)($stats['total_customers'] ?? 0) ?></div>
                    <div class="stat-label">Kunden</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $totalMessages ?></div>
                    <div class="stat-label">Nachrichten</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $readPercentage ?>%</div>
                    <div class="stat-label">Gelesen</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $unreadMessages ?></div>
                    <div class="stat-label">Ungelesen</div>
                </div>
            </div>
        </div>

        <div class="customer-list-header">
            <input type="text"
                   class="search-box"
                   placeholder="🔍 Kunde suchen..."
                   onkeyup="filterCustomers(this.value)">
        </div>

        <div class="customer-list" id="customerList">
            <?php foreach ($customers as $c):
                $is_active = $selected_customer === $c['email'];
                $data_name = strtolower(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                ?>
                <div class="customer-item <?= $is_active ? 'active' : '' ?>"
                     onclick="selectCustomer('<?= htmlspecialchars($c['email'], ENT_QUOTES, 'UTF-8') ?>')"
                     data-name="<?= htmlspecialchars($data_name, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="customer-info">
                        <div class="customer-name">
                            <?= htmlspecialchars(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="customer-email">
                            <?= htmlspecialchars($c['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <div class="customer-stats">
                        <?php if (($c['unread_messages'] ?? 0) > 0): ?>
                            <div class="unread-badge"><?= (int)$c['unread_messages'] ?> neu</div>
                        <?php endif; ?>
                        <?php if (!empty($c['last_message'])): ?>
                            <div class="last-seen">
                                <?= date('d.m.y', strtotime($c['last_message'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="main-content">
        <div class="compose-header">
            <h2>Neue Nachricht versenden</h2>
            <p><a href="dashboard.php">← Zurück zum Dashboard</a></p>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert-success">
                <?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert-error">
                <?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="compose-form">
            <input type="hidden" name="send_message" value="1">

            <div class="form-group">
                <label class="form-label">Empfänger</label>
                <select name="recipients" class="recipient-select" id="recipientSelect">
                    <option value="all">📢 Alle aktiven Kunden (Broadcast)</option>
                    <optgroup label="Einzelne Kunden">
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= htmlspecialchars($c['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                <?= $selected_customer === ($c['email'] ?? '') ? 'selected' : '' ?>>
                                <?= htmlspecialchars(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                (<?= htmlspecialchars($c['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Nachrichtentyp</label>
                <div class="message-types">
                    <button type="button" class="type-btn active" onclick="setMessageType(this, 'info')">
                        ℹ️ Information
                    </button>
                    <button type="button" class="type-btn" onclick="setMessageType(this, 'success')">
                        ✅ Erfolg
                    </button>
                    <button type="button" class="type-btn" onclick="setMessageType(this, 'warning')">
                        ⚠️ Warnung
                    </button>
                    <button type="button" class="type-btn" onclick="setMessageType(this, 'question')">
                        ❓ Frage
                    </button>
                </div>
                <input type="hidden" name="type" value="info" id="messageType">
            </div>

            <div class="form-group">
                <label class="form-label">Nachricht</label>
                <textarea name="message"
                          class="message-textarea"
                          placeholder="Schreibe deine Nachricht hier..."
                          required></textarea>
            </div>

            <div class="form-footer">
                <label class="checkbox-label">
                    <input type="checkbox" name="expects_response" value="1">
                    <span>Ja/Nein Antwort erwarten</span>
                </label>
                <button type="submit" class="send-btn">
                    Nachricht senden →
                </button>
            </div>
        </form>

        <?php if ($selected_customer): ?>
            <div class="history-area">
                <div class="history-header">
                    <h3>Nachrichtenverlauf: <?= htmlspecialchars($selected_customer, ENT_QUOTES, 'UTF-8') ?></h3>
                    <span style="color:#6b7280;font-size:0.9rem;">
                        <?php
                        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM beta_messages WHERE to_customer_email = ?');
                        $countStmt->execute([$selected_customer]);
                        echo (int)$countStmt->fetchColumn() . ' Nachrichten';
                        ?>
                    </span>
                </div>

                <?php
                $historyStmt = $pdo->prepare('
                    SELECT m.*,
                           (SELECT response FROM beta_responses WHERE message_id = m.id) AS user_response
                    FROM beta_messages m
                    WHERE to_customer_email = ?
                    ORDER BY created_at DESC
                    LIMIT 20
                ');
                $historyStmt->execute([$selected_customer]);
                $messages = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <?php foreach ($messages as $msg):
                    $messageType = htmlspecialchars($msg['message_type'] ?? 'info', ENT_QUOTES, 'UTF-8');
                    $isRead = !empty($msg['is_read']);
                    $expectsResponse = !empty($msg['expects_response']);
                    $userResponse = $msg['user_response'] ?? null;
                    ?>
                    <div class="message-card type-<?= $messageType ?>">
                        <div class="message-meta">
                            <span><?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?></span>
                            <div class="message-status">
                                <span class="status-badge <?= $isRead ? 'status-read' : 'status-unread' ?>">
                                    <?= $isRead ? '✓ Gelesen' : '● Ungelesen' ?>
                                </span>
                            </div>
                        </div>
                        <div class="message-content">
                            <?= nl2br(htmlspecialchars($msg['message_text'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                        </div>
                        <?php if ($expectsResponse): ?>
                            <div class="response-stats">
                                <div class="response-stat">
                                    <?php if ($userResponse === 'yes'): ?>
                                        <span style="color:#10b981;">✅ Antwort: Ja</span>
                                    <?php elseif ($userResponse === 'no'): ?>
                                        <span style="color:#ef4444;">❌ Antwort: Nein</span>
                                    <?php else: ?>
                                        <span style="color:#6b7280;">⏳ Antwort ausstehend</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<button class="broadcast-btn" type="button" onclick="document.getElementById('recipientSelect').value='all'; window.scrollTo({top: 0, behavior: 'smooth'});">
    📢 Broadcast an alle
</button>

<script>
function selectCustomer(email) {
    const url = new URL(window.location.href);
    url.searchParams.set('customer', email);
    window.location.href = url.toString();
}

function filterCustomers(query) {
    const q = (query || '').toLowerCase();
    const items = document.querySelectorAll('.customer-item');

    items.forEach(item => {
        const name = item.dataset.name || '';
        const email = item.querySelector('.customer-email').textContent.toLowerCase();

        if (name.includes(q) || email.includes(q)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

function setMessageType(button, type) {
    document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('active'));
    button.classList.add('active');
    document.getElementById('messageType').value = type;
}
</script>

</body>
</html>
