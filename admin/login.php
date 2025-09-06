<?php
session_start();
function getPDO(){
    $host=getenv('DB_HOST')?:'localhost';
    $db=getenv('DB_NAME')?:'app';
    $user=getenv('DB_USER')?:'root';
    $pass=getenv('DB_PASS')?:'';
    try{return new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
    catch(PDOException $e){die('DB connection failed: '.htmlspecialchars($e->getMessage()));}
}
$pdo=getPDO();
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $stmt=$pdo->prepare('SELECT * FROM admin_users WHERE username=?');
    $stmt->execute([$_POST['username']??'']);
    $user=$stmt->fetch(PDO::FETCH_ASSOC);
    if($user && password_verify($_POST['password']??'', $user['password_hash'])){
        $_SESSION['admin']=$user['username'];
        header('Location: dashboard.php');
        exit;
    }
    $error='Invalid credentials';
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Login</title>
<style>body{font-family:Arial;margin:2em}form{max-width:300px;margin:auto}input,button{width:100%;padding:.5em;margin:.3em 0}button{background:#4a90b8;color:#fff;border:none}</style>
</head>
<body>
<h2 style="color:#4a90b8;text-align:center">Admin Login</h2>
<?php if($error) echo "<p style='color:red'>$error</p>";?>
<form method="post">
<input name="username" placeholder="Username" required>
<input type="password" name="password" placeholder="Password" required>
<button type="submit">Login</button>
</form>
</body></html>
