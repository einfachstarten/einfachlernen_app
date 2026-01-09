<?php
/**
 * Migration Runner for Scan Progress Tables
 *
 * Run this script once to create the required database tables
 * for the async Calendly scanner feature.
 *
 * Usage: php run_migration.php
 * Or access via browser (admin session required)
 */

// Check if running from CLI or browser
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    session_start();
    if (empty($_SESSION['admin'])) {
        die('❌ Unauthorized - Admin login required');
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre>';
}

echo "============================================\n";
echo "Async Calendly Scanner - Database Migration\n";
echo "============================================\n\n";

// Load database config
$config = require __DIR__ . '/../config.php';

try {
    // Connect to database
    echo "📡 Connecting to database...\n";
    $pdo = new PDO(
        "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✅ Connected to database: {$config['DB_NAME']}\n\n";

    // Read SQL file
    $sql_file = __DIR__ . '/add_scan_progress_tables.sql';
    if (!file_exists($sql_file)) {
        throw new Exception("Migration file not found: {$sql_file}");
    }

    echo "📄 Reading migration file...\n";
    $sql = file_get_contents($sql_file);

    // Split into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            // Filter out comments and empty statements
            return !empty($stmt)
                && strpos($stmt, '--') !== 0
                && strpos($stmt, '/*') !== 0;
        }
    );

    echo "🔄 Executing " . count($statements) . " SQL statements...\n\n";

    foreach ($statements as $index => $statement) {
        if (empty($statement)) continue;

        // Extract table name for better logging
        if (preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $statement, $matches)) {
            $table = $matches[1];
            echo "  Creating table: {$table}... ";

            try {
                $pdo->exec($statement . ';');
                echo "✅\n";
            } catch (PDOException $e) {
                if ($e->getCode() == '42S01') { // Table already exists
                    echo "⚠️  Already exists\n";
                } else {
                    throw $e;
                }
            }
        } else {
            echo "  Executing statement " . ($index + 1) . "... ";
            $pdo->exec($statement . ';');
            echo "✅\n";
        }
    }

    echo "\n============================================\n";
    echo "✅ Migration completed successfully!\n";
    echo "============================================\n\n";

    // Verify tables exist
    echo "🔍 Verifying tables...\n";
    $tables = ['scan_progress', 'scan_logs'];
    foreach ($tables as $table) {
        $result = $pdo->query("SHOW TABLES LIKE '{$table}'");
        if ($result->rowCount() > 0) {
            echo "  ✅ {$table} - OK\n";
        } else {
            echo "  ❌ {$table} - MISSING!\n";
        }
    }

    echo "\n📊 Current table status:\n";
    $scan_count = $pdo->query("SELECT COUNT(*) FROM scan_progress")->fetchColumn();
    $log_count = $pdo->query("SELECT COUNT(*) FROM scan_logs")->fetchColumn();
    echo "  - scan_progress: {$scan_count} records\n";
    echo "  - scan_logs: {$log_count} records\n";

    echo "\n✨ Ready to use async Calendly scanning!\n";
    echo "Navigate to customer_search.php and click 'Calendly Scan'\n";

} catch (PDOException $e) {
    echo "\n❌ Database Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

if (!$is_cli) {
    echo '</pre>';
}
