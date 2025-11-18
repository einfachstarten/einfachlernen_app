<?php
/**
 * 🧪 BETA: Test Helper for Last-Minute Notifications
 *
 * This script helps test the Last-Minute feature without waiting for cron.
 * Admin-only access.
 */

session_start();

if (empty($_SESSION['admin'])) {
    die('❌ Admin access required. <a href="../admin/login.php">Login</a>');
}

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

$action = $_GET['action'] ?? '';
$message = '';
$messageType = '';

if ($action === 'run_checker') {
    $output = [];
    $returnCode = 0;

    exec('cd ' . escapeshellarg(__DIR__ . '/..') . ' && php admin/last_minute_checker.php 2>&1', $output, $returnCode);

    if ($returnCode === 0) {
        $message = '✅ Checker erfolgreich ausgeführt!';
        $messageType = 'success';
    } else {
        $message = '❌ Checker ist fehlgeschlagen (Exit Code: ' . $returnCode . ')';
        $messageType = 'error';
    }

    $output = implode("\n", $output);
}

$pdo = getPDO();

// Get statistics
$stats = [
    'beta_users' => 0,
    'subscriptions' => 0,
    'active_subscriptions' => 0,
    'notifications_sent' => 0,
    'notifications_today' => 0,
];

try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM customers WHERE beta_access = 1');
    $stats['beta_users'] = $stmt->fetchColumn();

    $stmt = $pdo->query('SELECT COUNT(*) FROM last_minute_subscriptions');
    $stats['subscriptions'] = $stmt->fetchColumn();

    $stmt = $pdo->query('SELECT COUNT(*) FROM last_minute_subscriptions WHERE is_active = 1');
    $stats['active_subscriptions'] = $stmt->fetchColumn();

    $stmt = $pdo->query('SELECT COUNT(*) FROM last_minute_notifications');
    $stats['notifications_sent'] = $stmt->fetchColumn();

    $stmt = $pdo->query('SELECT COUNT(*) FROM last_minute_notifications WHERE DATE(sent_at) = CURDATE()');
    $stats['notifications_today'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    // Tables might not exist yet
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>🧪 Last-Minute Test Helper</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            max-width: 1000px;
            margin: 40px auto;
            padding: 20px;
            background: #f7fafc;
        }
        .container {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2b6cb0;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-card {
            background: #f8fafc;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #2b6cb0;
        }
        .stat-label {
            font-size: 0.85rem;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #2b6cb0;
            margin-top: 5px;
        }
        .btn {
            display: inline-block;
            background: #2b6cb0;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin: 10px 5px;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #2c5282;
        }
        .btn-danger {
            background: #e53e3e;
        }
        .btn-danger:hover {
            background: #c53030;
        }
        .message {
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .message.success {
            background: #e6f4ea;
            color: #276749;
            border-left: 4px solid #48bb78;
        }
        .message.error {
            background: #fee;
            color: #9b2c2c;
            border-left: 4px solid #f56565;
        }
        pre {
            background: #1a202c;
            color: #48bb78;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 0.9rem;
        }
        .section {
            margin: 30px 0;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
        }
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f8fafc;
            font-weight: 600;
            color: #2b6cb0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Last-Minute Test Helper</h1>
        <p>Teste das Last-Minute Feature ohne auf den Cron zu warten.</p>

        <?php if ($message): ?>
            <div class="message <?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Beta Users</div>
                <div class="stat-value"><?= $stats['beta_users'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Subscriptions</div>
                <div class="stat-value"><?= $stats['active_subscriptions'] ?> / <?= $stats['subscriptions'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Notifications (Total)</div>
                <div class="stat-value"><?= $stats['notifications_sent'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Today</div>
                <div class="stat-value"><?= $stats['notifications_today'] ?></div>
            </div>
        </div>

        <div class="section">
            <h3>⚡ Quick Actions</h3>
            <a href="?action=run_checker" class="btn">▶️ Checker jetzt ausführen</a>
            <a href="setup_last_minute.php" class="btn">🔧 DB Setup</a>
            <a href="check_calendly_token.php" class="btn">🔑 Token prüfen</a>
            <a href="../admin/dashboard.php" class="btn">← Dashboard</a>
        </div>

        <?php if (isset($output)): ?>
            <div class="section">
                <h3>📋 Checker Output</h3>
                <pre><?= htmlspecialchars($output) ?></pre>
            </div>
        <?php endif; ?>

        <div class="section">
            <h3>📊 Recent Notifications</h3>
            <?php
            try {
                $stmt = $pdo->query('
                    SELECT
                        n.*,
                        c.email,
                        c.first_name
                    FROM last_minute_notifications n
                    INNER JOIN customers c ON c.id = n.customer_id
                    ORDER BY n.sent_at DESC
                    LIMIT 10
                ');
                $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($recent)) {
                    echo '<p>Noch keine Benachrichtigungen versendet.</p>';
                } else {
                    echo '<table>';
                    echo '<tr><th>Zeit</th><th>User</th><th>Slots</th><th>Services</th><th>Status</th></tr>';
                    foreach ($recent as $row) {
                        $statusIcon = $row['email_sent'] ? '✅' : '❌';
                        $services = json_decode($row['services_checked'], true);
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($row['sent_at']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['email']) . '</td>';
                        echo '<td>' . $row['slots_found'] . '</td>';
                        echo '<td>' . htmlspecialchars(implode(', ', $services)) . '</td>';
                        echo '<td>' . $statusIcon . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                }
            } catch (PDOException $e) {
                echo '<p>❌ Tabelle existiert noch nicht. Führe <a href="setup_last_minute.php">DB Setup</a> aus.</p>';
            }
            ?>
        </div>

        <div class="section">
            <h3>📁 Logs</h3>
            <?php
            $logFile = __DIR__ . '/../admin/logs/last_minute_checker.log';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                $logLines = explode("\n", $logContent);
                $lastLines = array_slice($logLines, -50);
                echo '<pre>' . htmlspecialchars(implode("\n", $lastLines)) . '</pre>';
            } else {
                echo '<p>Noch keine Logs vorhanden.</p>';
            }
            ?>
        </div>

        <div class="warning">
            <h3>⚠️ Hinweise</h3>
            <ul>
                <li>Dieser Test führt das Script manuell aus - genau wie der Cron-Job</li>
                <li>Beta-User müssen das Feature unter <code>/beta/last_minute_settings.php</code> aktivieren</li>
                <li>Rate-Limiting gilt: Max 3 E-Mails pro User pro Tag</li>
                <li>Prüfe Logs für Details: <code>admin/logs/last_minute_checker.log</code></li>
            </ul>
        </div>
    </div>
</body>
</html>
