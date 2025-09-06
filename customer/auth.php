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

    // Store in database
    $stmt = $pdo->prepare('INSERT INTO customer_sessions (customer_id, session_token, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$customer_id, $token, $expires]);

    // FIXED: iOS-compatible cookie settings
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $sameSite = 'Lax'; // iOS Safari compatible

    // Modern setcookie with all attributes
    if (PHP_VERSION_ID >= 70300) {
        // PHP 7.3+ supports SameSite attribute
        setcookie('customer_session', $token, [
            'expires' => time() + 7*24*3600,
            'path' => '/einfachlernen/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite
        ]);
    } else {
        // Fallback for older PHP versions
        setcookie('customer_session', $token, time() + 7*24*3600, '/einfachlernen/; SameSite=' . $sameSite, '', $secure, true);
    }

    // ADDITIONAL: Also store in PHP session as fallback
    $_SESSION['customer_token'] = $token;
    $_SESSION['customer_id'] = $customer_id;

    // Log cookie setting for debugging
    error_log("Cookie set for customer $customer_id: token=" . substr($token, 0, 10) . "... secure=$secure sameSite=$sameSite");
}

function get_current_customer(){
    $pdo = getPDO();
    cleanup_sessions($pdo);

    // Try to get token from cookie first
    $token = $_COOKIE['customer_session'] ?? null;

    // FALLBACK: If no cookie token, try session token
    if (!$token && isset($_SESSION['customer_token'])) {
        $token = $_SESSION['customer_token'];
        error_log("Using session token fallback for customer " . ($_SESSION['customer_id'] ?? 'unknown'));
    }

    if (!$token) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT c.* FROM customer_sessions s JOIN customers c ON c.id = s.customer_id WHERE s.session_token = ? AND s.expires_at > NOW()');
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    // If found via session token but cookie is missing, reset cookie
    if ($customer && !isset($_COOKIE['customer_session']) && isset($_SESSION['customer_token'])) {
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        if (PHP_VERSION_ID >= 70300) {
            setcookie('customer_session', $token, [
                'expires' => time() + 7*24*3600,
                'path' => '/einfachlernen/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } else {
            setcookie('customer_session', $token, time() + 7*24*3600, '/einfachlernen/; SameSite=Lax', '', $secure, true);
        }
        error_log("Cookie restored for customer " . $customer['id']);
    }

    return $customer;
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

function logPageView($customer_id, $page_name, $additional_data = []) {
    require_once __DIR__ . '/../admin/ActivityLogger.php';
    $pdo = getPDO();
    $logger = new ActivityLogger($pdo);

    $activity_data = array_merge([
        'page' => $page_name,
        'url' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'timestamp' => date('Y-m-d H:i:s')
    ], $additional_data);

    $logger->logActivity($customer_id, 'page_view', $activity_data);
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
