<?php
/**
 * 🧪 BETA FEATURE SETUP: Last-Minute Notifications
 *
 * This script creates database tables ONLY for the beta last-minute feature.
 * Safe to run multiple times (uses CREATE TABLE IF NOT EXISTS).
 * Only affects beta users - no impact on normal operations.
 */

session_start();

// Admin authentication required
if (empty($_SESSION['admin'])) {
    die('❌ Admin access required. <a href="../admin/login.php">Login</a>');
}

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
        die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>🧪 Beta Setup: Last-Minute Notifications</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 900px;
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
            margin-bottom: 10px;
        }
        .beta-badge {
            display: inline-block;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .step {
            margin: 20px 0;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 4px solid #2b6cb0;
        }
        .success {
            color: #276749;
            background: #e6f4ea;
            border-left-color: #48bb78;
        }
        .error {
            color: #9b2c2c;
            background: #fee;
            border-left-color: #f56565;
        }
        .warning {
            color: #7c2d12;
            background: #fef3c7;
            border-left-color: #f59e0b;
        }
        pre {
            background: #1a202c;
            color: #48bb78;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
        }
        .btn {
            display: inline-block;
            background: #2b6cb0;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 20px;
        }
        .btn:hover {
            background: #2c5282;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Beta Feature Setup</h1>
        <span class="beta-badge">BETA ONLY - Safe for Production</span>

        <p><strong>Last-Minute Slot Notifications</strong></p>
        <p>Dieses Script erstellt die Datenbank-Tabellen für das Beta-Feature. Es ist sicher und beeinflusst keine normalen User.</p>

        <?php
        try {
            $pdo = getPDO();

            echo '<div class="step">';
            echo '<h3>📊 Schritt 1: Datenbank-Tabellen erstellen</h3>';

            // Create last_minute_subscriptions table
            echo '<p>Erstelle <code>last_minute_subscriptions</code> Tabelle...</p>';
            $pdo->exec("CREATE TABLE IF NOT EXISTS last_minute_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                service_slugs JSON NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_notification_sent TIMESTAMP NULL,
                notification_count_today INT DEFAULT 0,
                UNIQUE KEY uniq_customer (customer_id),
                INDEX idx_active_subscriptions (is_active, customer_id),
                INDEX idx_notification_tracking (last_notification_sent, is_active),
                CONSTRAINT fk_last_minute_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            echo '<p style="color: green;">✅ <code>last_minute_subscriptions</code> Tabelle bereit</p>';

            // Create last_minute_notifications table
            echo '<p>Erstelle <code>last_minute_notifications</code> Tabelle...</p>';
            $pdo->exec("CREATE TABLE IF NOT EXISTS last_minute_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                slots_found INT NOT NULL,
                services_checked JSON NOT NULL,
                email_sent TINYINT(1) DEFAULT 0,
                email_error TEXT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_lm_sent_at (sent_at, email_sent),
                CONSTRAINT fk_last_minute_notifications_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            echo '<p style="color: green;">✅ <code>last_minute_notifications</code> Tabelle bereit</p>';
            echo '</div>';

            // Check beta users
            echo '<div class="step">';
            echo '<h3>👥 Schritt 2: Beta-User Statistik</h3>';
            $stmt = $pdo->query('SELECT COUNT(*) as count FROM customers WHERE beta_access = 1');
            $betaCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            echo "<p>Aktuell <strong>{$betaCount} Beta-User</strong> in der Datenbank</p>";

            if ($betaCount > 0) {
                echo '<p style="color: green;">✅ Beta-User vorhanden - Feature kann genutzt werden</p>';
            } else {
                echo '<p style="color: orange;">⚠️ Keine Beta-User - aktiviere zuerst User im Admin-Dashboard</p>';
            }
            echo '</div>';

            // Security check
            echo '<div class="step success">';
            echo '<h3>🔒 Sicherheits-Check</h3>';
            echo '<ul>';
            echo '<li>✅ Tabellen nutzen <code>CREATE TABLE IF NOT EXISTS</code> - keine Fehler bei erneutem Ausführen</li>';
            echo '<li>✅ Foreign Keys mit <code>ON DELETE CASCADE</code> - automatisches Cleanup</li>';
            echo '<li>✅ Script prüft nur Beta-User (<code>beta_access = 1</code>)</li>';
            echo '<li>✅ Keine Änderungen an bestehenden Tabellen</li>';
            echo '<li>✅ Isoliert vom normalen Betrieb</li>';
            echo '</ul>';
            echo '</div>';

            // Next steps
            echo '<div class="step warning">';
            echo '<h3>⚡ Nächste Schritte</h3>';
            echo '<ol>';
            echo '<li><strong>CALENDLY_TOKEN setzen</strong> - Environment Variable für Calendly API</li>';
            echo '<li><strong>Logs-Verzeichnis erstellen</strong> - <code>mkdir admin/logs && chmod 775 admin/logs</code></li>';
            echo '<li><strong>Cron-Job einrichten</strong> - 3x täglich: 7:00, 12:00, 20:00 Uhr</li>';
            echo '<li><strong>Manuell testen</strong> - Script einmal ausführen</li>';
            echo '</ol>';
            echo '</div>';

        } catch (PDOException $e) {
            echo '<div class="step error">';
            echo '<h3>❌ Fehler</h3>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
        }
        ?>

        <a href="../admin/dashboard.php" class="btn">← Zurück zum Admin-Dashboard</a>
    </div>
</body>
</html>
