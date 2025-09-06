<?php
if(session_status() === PHP_SESSION_NONE){
    session_start();
}
function getPDO(){
    $config = require __DIR__.'/../admin/config.php';
    try{
        return new PDO(
            "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }catch(PDOException $e){
        die('DB connection failed: '.htmlspecialchars($e->getMessage()));
    }
}
function cleanup_sessions($pdo){
    $pdo->exec('DELETE FROM customer_sessions WHERE expires_at < NOW()');
}
function create_customer_session($customer_id){
    $pdo = getPDO();
    cleanup_sessions($pdo);
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
    $stmt = $pdo->prepare('INSERT INTO customer_sessions (customer_id, session_token, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$customer_id, $token, $expires]);
    setcookie('customer_session', $token, time()+7*24*3600, '/einfachlernen/', '', true, true);
}
function get_current_customer(){
    if(empty($_COOKIE['customer_session'])){
        return null;
    }
    $pdo = getPDO();
    cleanup_sessions($pdo);
    $token = $_COOKIE['customer_session'];
    $stmt = $pdo->prepare('SELECT c.* FROM customer_sessions s JOIN customers c ON c.id = s.customer_id WHERE s.session_token = ? AND s.expires_at > NOW()');
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function require_customer_login(){
    $cust = get_current_customer();
    if(!$cust){
        header('Location: ../login.php');
        exit;
    }
    return $cust;
}
function destroy_customer_session(){
    if(!empty($_COOKIE['customer_session'])){
        $token = $_COOKIE['customer_session'];
        $pdo = getPDO();
        $stmt = $pdo->prepare('DELETE FROM customer_sessions WHERE session_token = ?');
        $stmt->execute([$token]);
        setcookie('customer_session','',time()-3600,'/einfachlernen/', '', true, true);
    }
}
// Handle auth check for PWA
if (isset($_GET['check']) && $_GET['check'] === '1') {
    header('Content-Type: application/json');

    $customer = get_current_customer();

    if ($customer) {
        echo json_encode([
            'loggedIn' => true,
            'customer' => [
                'email' => $customer['email'],
                'first_name' => $customer['first_name']
            ]
        ]);
    } else {
        echo json_encode(['loggedIn' => false]);
    }
    exit;
}
?>
