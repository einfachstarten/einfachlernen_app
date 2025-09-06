<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}
if(isset($_SESSION['LAST_ACTIVITY']) && time() - $_SESSION['LAST_ACTIVITY'] > 1800){session_unset();session_destroy();header('Location: login.php');exit;}
$_SESSION['LAST_ACTIVITY'] = time();
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
$pdo = getPDO();
$error = '';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $email=trim($_POST['email']??'');
    $first=trim($_POST['first_name']??'');
    $last=trim($_POST['last_name']??'');
    $phone=trim($_POST['phone']??'');
    if($email && filter_var($email,FILTER_VALIDATE_EMAIL)){
        $stmt=$pdo->prepare('INSERT INTO customers (email,first_name,last_name,phone) VALUES (?,?,?,?)');
        try{$stmt->execute([$email,$first,$last,$phone]);header('Location: dashboard.php');exit;}
        catch(PDOException $e){
            if($e->getCode()==23000){$error='Email already exists';}
            else{$error='Error: '.htmlspecialchars($e->getMessage());}
        }
    }else $error='Valid email required';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Customer</title>
    <style>
        body{font-family:Arial;margin:2em}
        form{max-width:400px}
        input{width:100%;padding:.5em;margin:.3em 0}
        button{background:#52b3a4;color:#fff;border:none;padding:.5em 1em}
    </style>
</head>
<body>
<h2 style="color:#4a90b8">Add Customer</h2>
<?php if($error) echo "<p style='color:red'>".htmlspecialchars($error)."</p>"; ?>
<form method="post">
    <input name="email" placeholder="Email" required>
    <input name="first_name" placeholder="First Name">
    <input name="last_name" placeholder="Last Name">
    <input name="phone" placeholder="Phone">
    <button type="submit">Save</button>
</form>
<p><a href="dashboard.php" style="color:#52b3a4">Back</a></p>
</body></html>
