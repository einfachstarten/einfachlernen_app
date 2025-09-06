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
    
    // Use standard PHP session instead of custom cookie
    $_SESSION['customer_id'] = $customer_id;
    $_SESSION['customer_login_time'] = time();
    $_SESSION['customer_last_activity'] = time();
    $_SESSION['session_authenticated'] = true;
    
    // Still store in database for security tracking
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    $stmt = $pdo->prepare('INSERT INTO customer_sessions (customer_id, session_token, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$customer_id, $token, $expires]);
    
    $_SESSION['db_session_token'] = $token;
    
    error_log("PHP Session created for customer $customer_id: session_id=" . session_id());
}
function get_current_customer(){
    // Check if authenticated via PHP session
    if (empty($_SESSION['customer_id']) || empty($_SESSION['session_authenticated'])) {
        return null;
    }
    
    $customer_id = $_SESSION['customer_id'];
    
    $pdo = getPDO();
    
    // Get fresh customer data from database
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        // Customer not found in database - clear session
        session_destroy();
        return null;
    }
    
    // Update last activity
    $_SESSION['customer_last_activity'] = time();
    
    return $customer;
}
function require_customer_login(){
    $cust = get_current_customer();
    if(!$cust){
        // Clear any partial session data
        session_destroy();
        header('Location: ../login.php?message=' . urlencode('Bitte melden Sie sich an.'));
        exit;
    }
    return $cust;
}
function destroy_customer_session(){
    // Clean up database session if exists
    if (!empty($_SESSION['db_session_token'])) {
        $pdo = getPDO();
        $stmt = $pdo->prepare('DELETE FROM customer_sessions WHERE session_token = ?');
        $stmt->execute([$_SESSION['db_session_token']]);
    }
    
    // Clear PHP session
    $_SESSION = [];
    
    // Destroy session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    
    session_destroy();
    
    error_log("Session destroyed successfully");
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
