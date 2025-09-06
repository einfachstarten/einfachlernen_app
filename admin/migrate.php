<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}

function getPDO() {
    $config = require __DIR__ . '/config.php';
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

echo "<h1>Database Migration</h1>";
echo "<p>Adding missing columns for PIN system...</p>";

try {
    $pdo = getPDO();
    
    // Check which columns exist
    $stmt = $pdo->query("SHOW COLUMNS FROM customers");
    $existing_columns = [];
    while ($row = $stmt->fetch()) {
        $existing_columns[] = $row['Field'];
    }
    
    echo "<h3>Current Columns:</h3>";
    echo "<ul>";
    foreach ($existing_columns as $col) {
        echo "<li>" . htmlspecialchars($col) . "</li>";
    }
    echo "</ul>";
    
    // Add missing columns
    $migrations = [
        'pin' => "ALTER TABLE customers ADD COLUMN pin VARCHAR(255) NULL",
        'pin_expires' => "ALTER TABLE customers ADD COLUMN pin_expires DATETIME NULL", 
        'last_login' => "ALTER TABLE customers ADD COLUMN last_login DATETIME NULL"
    ];
    
    echo "<h3>Migration Results:</h3>";
    foreach ($migrations as $column => $sql) {
        if (in_array($column, $existing_columns)) {
            echo "<p style='color:orange'>⚠️ Column '$column' already exists - skipping</p>";
        } else {
            try {
                $pdo->exec($sql);
                echo "<p style='color:green'>✅ Added column '$column'</p>";
            } catch (PDOException $e) {
                echo "<p style='color:red'>❌ Failed to add '$column': " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
    }
    
    // Create customer_sessions table if not exists
    echo "<h3>Creating customer_sessions table:</h3>";
    try {
        $create_sessions = "CREATE TABLE IF NOT EXISTS customer_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            session_token VARCHAR(64) UNIQUE NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )";
        $pdo->exec($create_sessions);
        echo "<p style='color:green'>✅ customer_sessions table ready</p>";
    } catch (PDOException $e) {
        echo "<p style='color:red'>❌ customer_sessions creation failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // Verify final schema
    echo "<h3>Final Schema Verification:</h3>";
    $stmt = $pdo->query("SHOW COLUMNS FROM customers");
    $final_columns = [];
    while ($row = $stmt->fetch()) {
        $final_columns[] = $row['Field'];
    }
    
    $required_columns = ['id', 'email', 'first_name', 'last_name', 'phone', 'status', 'created_at', 'pin', 'pin_expires', 'last_login'];
    
    foreach ($required_columns as $req_col) {
        if (in_array($req_col, $final_columns)) {
            echo "<p style='color:green'>✅ $req_col</p>";
        } else {
            echo "<p style='color:red'>❌ $req_col MISSING</p>";
        }
    }
    
    echo "<h3>Migration Complete!</h3>";
    echo "<p><a href='dashboard.php'>← Back to Dashboard</a></p>";
    echo "<p><a href='send_pin.php' onclick='return confirm(\"Test PIN sending now?\")'>Test PIN System</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Migration failed: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
