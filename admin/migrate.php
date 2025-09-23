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
echo "<p>Adding missing columns for PIN system & avatars...</p>";

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
        'last_login' => "ALTER TABLE customers ADD COLUMN last_login DATETIME NULL",
        'avatar_style' => "ALTER TABLE customers ADD COLUMN avatar_style VARCHAR(50) DEFAULT 'avataaars'",
        'avatar_seed' => "ALTER TABLE customers ADD COLUMN avatar_seed VARCHAR(100) DEFAULT NULL"
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

    echo "<h3>Adding beta_access column:</h3>";
    try {
        $pdo->exec("ALTER TABLE customers ADD COLUMN beta_access TINYINT(1) DEFAULT 0");
        echo "<p style='color:green'>✅ beta_access column added</p>";
    } catch (PDOException $e) {
        if ($e->getCode() === '42S21') {
            echo "<p style='color:orange'>⚠️ beta_access column already exists</p>";
        } else {
            echo "<p style='color:red'>❌ Failed to add beta_access: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }

    try {
        $stmt = $pdo->prepare("UPDATE customers SET beta_access = 1 WHERE email IN (?, ?)");
        $stmt->execute(['marcus@einfachstarten.jetzt', 'annabraun@outlook.com']);
        echo "<p style='color:green'>✅ Beta access enabled for existing beta users</p>";
    } catch (PDOException $e) {
        echo "<p style='color:red'>❌ Failed to update beta users: " . htmlspecialchars($e->getMessage()) . "</p>";
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

    // Create customer_activities table if not exists
    echo "<h3>Creating customer_activities table:</h3>";
    try {
        $create_activities = "CREATE TABLE IF NOT EXISTS customer_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            activity_type VARCHAR(50) NOT NULL,
            activity_data JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            session_id VARCHAR(64) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )";
        $pdo->exec($create_activities);

        // Indexes for performance
        $pdo->exec("CREATE INDEX idx_customer_activities_customer ON customer_activities(customer_id)");
        $pdo->exec("CREATE INDEX idx_customer_activities_type ON customer_activities(activity_type)");
        $pdo->exec("CREATE INDEX idx_customer_activities_date ON customer_activities(created_at)");
        $pdo->exec("CREATE INDEX idx_customer_activities_customer_date ON customer_activities(customer_id, created_at)");

        echo "<p style='color:green'>✅ customer_activities table ready</p>";
    } catch (PDOException $e) {
        echo "<p style='color:red'>❌ customer_activities creation failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    // Create beta messaging table (beta features)
    echo "<h3>Creating beta_messages table (beta features):</h3>";
    try {
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
        echo "<p style='color:green'>✅ beta_messages table ready</p>";
    } catch (PDOException $e) {
        echo "<p style='color:red'>❌ beta_messages creation failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    // Create analytics views
    echo "<h3>Creating analytics views:</h3>";
    try {
        $pdo->exec("CREATE OR REPLACE VIEW service_performance_monthly AS
            SELECT 
                s.service_name,
                YEAR(b.booking_date) as year,
                MONTH(b.booking_date) as month,
                COUNT(b.id) as booking_count,
                SUM(s.price) as revenue,
                ROUND((COUNT(b.id) * 100.0 / (SELECT COUNT(*) FROM bookings WHERE YEAR(booking_date) = YEAR(b.booking_date) AND MONTH(booking_date) = MONTH(b.booking_date))), 1) as percentage
            FROM bookings b
            JOIN services s ON b.service_id = s.id 
            WHERE b.status = 'confirmed'
            GROUP BY s.service_name, YEAR(b.booking_date), MONTH(b.booking_date)");
        echo "<p style='color:green'>✅ service_performance_monthly view ready</p>";
    } catch (PDOException $e) {
        echo "<p style='color:red'>❌ service_performance_monthly failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    try {
        $pdo->exec("CREATE OR REPLACE VIEW weekly_capacity AS
            SELECT 
                YEAR(booking_date) as year,
                WEEK(booking_date, 1) as week_number,
                DATE(DATE_SUB(booking_date, INTERVAL WEEKDAY(booking_date) DAY)) as week_start,
                DATE(DATE_ADD(DATE_SUB(booking_date, INTERVAL WEEKDAY(booking_date) DAY), INTERVAL 6 DAY)) as week_end,
                COUNT(*) as booked_slots,
                SUM(s.price) as revenue,
                ROUND((COUNT(*) * 100.0 / 40), 1) as capacity_percentage
            FROM bookings b
            JOIN services s ON b.service_id = s.id
            WHERE b.status = 'confirmed'
            GROUP BY YEAR(booking_date), WEEK(booking_date, 1)");
        echo "<p style='color:green'>✅ weekly_capacity view ready</p>";
    } catch (PDOException $e) {
        echo "<p style='color:red'>❌ weekly_capacity view failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // Verify final schema
    echo "<h3>Final Schema Verification:</h3>";
    $stmt = $pdo->query("SHOW COLUMNS FROM customers");
    $final_columns = [];
    while ($row = $stmt->fetch()) {
        $final_columns[] = $row['Field'];
    }
    
    $required_columns = ['id', 'email', 'first_name', 'last_name', 'phone', 'status', 'created_at', 'pin', 'pin_expires', 'last_login', 'beta_access', 'avatar_style', 'avatar_seed'];
    
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
