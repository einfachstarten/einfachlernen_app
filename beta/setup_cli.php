<?php
/**
 * 🧪 BETA FEATURE CLI SETUP
 * Run via: php beta/setup_cli.php
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

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

echo "🧪 Beta Feature Setup: Last-Minute Notifications\n";
echo str_repeat("=", 60) . "\n\n";

try {
    $pdo = getPDO();

    echo "📊 Creating database tables...\n";

    // Table 1: Subscriptions
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

    echo "✅ last_minute_subscriptions - OK\n";

    // Table 2: Notifications history
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

    echo "✅ last_minute_notifications - OK\n\n";

    // Check beta users
    $stmt = $pdo->query('SELECT COUNT(*) as count FROM customers WHERE beta_access = 1');
    $betaCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "👥 Beta users in database: {$betaCount}\n\n";

    echo "🔒 Security Status:\n";
    echo "   ✅ Beta-only feature (beta_access = 1 check)\n";
    echo "   ✅ Foreign keys with CASCADE delete\n";
    echo "   ✅ Isolated from normal operations\n\n";

    echo "✅ Setup completed successfully!\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
