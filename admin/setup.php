<?php
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

$error = '';
$success = '';

try {
    $pdo = getPDO();

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) UNIQUE NOT NULL,
        first_name VARCHAR(50),
        last_name VARCHAR(50),
        phone VARCHAR(30),
        status VARCHAR(20) DEFAULT 'active',
        avatar_style VARCHAR(50) DEFAULT 'avataaars',
        avatar_seed VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    try { $pdo->exec("ALTER TABLE customers ADD COLUMN pin VARCHAR(255) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE customers ADD COLUMN pin_expires DATETIME NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE customers ADD COLUMN last_login DATETIME NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE customers ADD COLUMN avatar_style VARCHAR(50) DEFAULT 'avataaars'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE customers ADD COLUMN avatar_seed VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        session_token VARCHAR(64) UNIQUE NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    )");

    $check = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE username = ?');
    $check->execute(['admin']);
    if (!$check->fetchColumn()) {
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $ins = $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)');
        $ins->execute(['admin', $hash]);
    }

    $success = 'Setup complete. <a href="login.php">Go to Login</a>';
} catch (Exception $e) {
    $error = 'Setup failed: ' . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup</title>
    <style>
        body{font-family:Arial;margin:2em;color:#333}
        a{color:#4a90b8}
    </style>
</head>
<body>
<h2 style="color:#4a90b8">Database Setup</h2>
<?php if($error): ?>
    <p style="color:red;"><?=$error?></p>
<?php else: ?>
    <p><?=$success?></p>
<?php endif; ?>
</body>
</html>
