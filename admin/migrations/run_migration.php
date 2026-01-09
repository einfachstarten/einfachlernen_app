<?php
/**
 * Migration Runner - Async Scanner Tables
 * URL: /admin/migrations/run_migration.php
 */
session_start();
if (empty($_SESSION['admin'])) {
    die('❌ Admin login required');
}

echo "<pre>\n";
echo "===========================================\n";
echo "Async Calendly Scanner - Database Migration\n";
echo "===========================================\n\n";

function getPDO() {
    $config = require __DIR__ . '/../config.php';
    return new PDO(
        "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

try {
    echo "✓ Connecting to database...\n";
    $pdo = getPDO();
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    echo "✓ Connected to database: $dbName\n\n";

    // Read migration file
    $migrationFile = __DIR__ . '/migration.sql';
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }

    echo "✓ Reading migration file...\n";
    $sql = file_get_contents($migrationFile);

    // Split into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            // Remove comments and empty lines
            $stmt = preg_replace('/^--.*$/m', '', $stmt);
            $stmt = trim($stmt);
            return !empty($stmt);
        }
    );

    echo "✓ Found " . count($statements) . " SQL statements\n\n";
    echo "Executing statements...\n";

    $success = 0;
    $failed = 0;

    foreach ($statements as $index => $statement) {
        $stmtNum = $index + 1;

        // Get first line for display
        $firstLine = strtok($statement, "\n");
        $preview = substr($firstLine, 0, 60);
        if (strlen($firstLine) > 60) $preview .= '...';

        echo "\nExecuting statement $stmtNum...\n";
        echo "  $preview\n";

        try {
            $pdo->exec($statement);
            echo "  ✓ Success\n";
            $success++;
        } catch (PDOException $e) {
            echo "  ✗ Failed: " . $e->getMessage() . "\n";
            echo "  SQL: " . substr($statement, 0, 200) . "\n";
            $failed++;
        }
    }

    echo "\n===========================================\n";
    echo "MIGRATION SUMMARY\n";
    echo "===========================================\n";
    echo "Total statements: " . count($statements) . "\n";
    echo "✓ Successful: $success\n";
    echo "✗ Failed: $failed\n\n";

    if ($failed === 0) {
        echo "🎉 Migration completed successfully!\n\n";
        echo "Tables created:\n";
        echo "  - scan_progress (for tracking scan status)\n";
        echo "  - scan_logs (for real-time logging)\n\n";
        echo "Next: Test async scan at customer_search.php\n";
    } else {
        echo "⚠️  Migration completed with errors.\n";
        echo "Review the errors above and fix them.\n";
    }

} catch (Exception $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n<a href='../customer_search.php'>← Back to Customer Search</a>\n";
echo "</pre>";
