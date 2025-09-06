<?php
function getPDO(){
    $host=getenv('DB_HOST')?:'localhost';
    $db=getenv('DB_NAME')?:'app';
    $user=getenv('DB_USER')?:'root';
    $pass=getenv('DB_PASS')?:'';
    try{
        return new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    }catch(PDOException $e){
        die('DB connection failed: '.htmlspecialchars($e->getMessage()));
    }
}
$pdo=getPDO();
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) UNIQUE NOT NULL,
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    phone VARCHAR(30),
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);");
$check=$pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE username=?');
$check->execute(['admin']);
if(!$check->fetchColumn()){
    $hash=password_hash('password123',PASSWORD_DEFAULT);
    $ins=$pdo->prepare('INSERT INTO admin_users (username,password_hash) VALUES (?,?)');
    $ins->execute(['admin',$hash]);
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Setup</title>
<style>body{font-family:Arial;margin:2em;color:#333}a{color:#4a90b8}</style>
</head>
<body>
<h2>Setup Complete</h2>
<p>Tables created and default admin user added.</p>
<p><a href="login.php">Go to Login</a></p>
</body></html>
