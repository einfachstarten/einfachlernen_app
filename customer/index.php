<?php
require __DIR__.'/auth.php';
require_once __DIR__ . '/messaging_init.php';

// Customer session timeout: 4 hours
if(isset($_SESSION['customer_last_activity']) && (time() - $_SESSION['customer_last_activity'] > 14400)){
    // Log session timeout
    if(!empty($_SESSION['customer_id'])){
        require_once __DIR__ . '/../admin/ActivityLogger.php';
        $pdo = getPDO();
        $logger = new ActivityLogger($pdo);
        $logger->logActivity($_SESSION['customer_id'], 'session_timeout', [
            'timeout_duration' => 14400,
            'last_activity_ago' => time() - $_SESSION['customer_last_activity'],
            'auto_logout' => true
        ]);
    }

    destroy_customer_session();
    header('Location: ../login.php?message=' . urlencode('Sitzung abgelaufen. Bitte melden Sie sich erneut an.'));
    exit;
}

if(isset($_GET['logout']) && !empty($_SESSION['customer'])){
    require_once __DIR__ . '/../admin/ActivityLogger.php';
    $pdo = getPDO();
    $logger = new ActivityLogger($pdo);
    $logger->logActivity($_SESSION['customer']['id'], 'logout', [
        'logout_method' => 'manual',
        'session_duration' => time() - ($_SESSION['customer_login_time'] ?? time())
    ]);

    destroy_customer_session();
    header('Location: ../login.php?message=' . urlencode('Erfolgreich abgemeldet'));
    exit;
}

$customer = require_customer_login();


// Log dashboard access
require_once __DIR__ . '/../admin/ActivityLogger.php';
$pdo = getPDO();
ensureProductionMessagingTables($pdo);

if (!empty($_POST['respond'])) {
    header('Content-Type: application/json');
    $messageId = (int)($_POST['message_id'] ?? 0);
    $choice = $_POST['response'] ?? '';

    if ($messageId && in_array($choice, ['yes', 'no'], true)) {
        $check = $pdo->prepare('SELECT expects_response FROM customer_messages WHERE id = ? AND to_customer_email = ?');
        $check->execute([$messageId, $customer['email']]);
        $message = $check->fetch(PDO::FETCH_ASSOC);

        if ($message && !empty($message['expects_response'])) {
            $pdo->prepare('INSERT INTO customer_message_responses (message_id, response) VALUES (?, ?) ON DUPLICATE KEY UPDATE response = VALUES(response), created_at = CURRENT_TIMESTAMP')
                ->execute([$messageId, $choice]);
            $pdo->prepare('UPDATE customer_messages SET is_read = 1 WHERE id = ? AND to_customer_email = ?')
                ->execute([$messageId, $customer['email']]);

            $_SESSION['customer_last_activity'] = time();
            echo json_encode(['success' => true]);
            exit;
        }
    }

    $_SESSION['customer_last_activity'] = time();
    echo json_encode(['success' => false]);
    exit;
}

if (!empty($_POST['mark_read'])) {
    $messageId = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;

    if ($messageId > 0) {
        $stmt = $pdo->prepare('UPDATE customer_messages SET is_read = 1 WHERE id = ? AND to_customer_email = ?');
        $stmt->execute([$messageId, $customer['email']]);
    }

    $_SESSION['customer_last_activity'] = time();
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

if (!empty($_GET['ajax'])) {
    $tab = ($_GET['tab'] ?? '') === 'read' ? 1 : 0;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM customer_messages WHERE to_customer_email = ? AND is_read = 0');
    $stmt->execute([$customer['email']]);
    $unreadCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT m.id, m.message_text, m.message_type, m.created_at, m.expects_response, r.response FROM customer_messages m LEFT JOIN customer_message_responses r ON r.message_id = m.id WHERE m.to_customer_email = ? AND m.is_read = ? ORDER BY m.created_at DESC LIMIT 15');
    $stmt->execute([$customer['email'], $tab]);

    $messages = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $messageType = $row['message_type'] ?? 'info';
        if (!in_array($messageType, ['info', 'success', 'warning', 'question'], true)) {
            $messageType = 'info';
        }

        $responseValue = null;
        if ($row['response'] === 'yes' || $row['response'] === 'no') {
            $responseValue = $row['response'];
        }

        $messages[] = [
            'id' => (int) $row['id'],
            'message_text' => $row['message_text'] ?? '',
            'message_type' => $messageType,
            'created_at' => $row['created_at'],
            'expects_response' => !empty($row['expects_response']),
            'user_response' => $responseValue,
        ];
    }

    $_SESSION['customer_last_activity'] = time();
    header('Content-Type: application/json');
    echo json_encode([
        'unread_count' => $unreadCount,
        'messages' => $messages,
    ]);
    exit;
}

$logger = new ActivityLogger($pdo);
$logger->logActivity($customer['id'], 'dashboard_accessed', [
    'access_method' => 'direct_navigation',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'referrer' => $_SERVER['HTTP_REFERER'] ?? 'direct'
]);

// Page view tracking for dashboard
logPageView($customer['id'], 'dashboard', [
    'dashboard_section' => 'main',
    'features_available' => ['booking', 'profile', 'logout']
]);

// Update session activity
$_SESSION['customer_last_activity'] = time();

$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_messages WHERE to_customer_email = ? AND is_read = 0');
$unreadStmt->execute([$customer['email']]);
$initialUnreadCount = (int) $unreadStmt->fetchColumn();

if(!empty($_SESSION['customer'])) {
    // Refresh customer data from database
    $customer_id = $_SESSION['customer']['id'];
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $updated_customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if($updated_customer) {
        $_SESSION['customer'] = $updated_customer;
        $customer = $updated_customer;

        // Log profile refresh activity
        $logger->logActivity($customer_id, 'profile_refreshed', [
            'refresh_method' => 'auto_session_update',
            'data_updated' => true
        ]);
    }
}

$availableAvatarStyles = [
    'avataaars',
    'adventurer-neutral',
    'fun-emoji',
    'lorelei',
    'pixel-art',
    'thumbs',
];

$avatar_style = $customer['avatar_style'] ?? '';
if (!in_array($avatar_style, $availableAvatarStyles, true)) {
    $avatar_style = 'avataaars';

    try {
        $pdo->prepare('UPDATE customers SET avatar_style = ? WHERE id = ?')
            ->execute(['avataaars', $customer['id']]);
        $_SESSION['customer']['avatar_style'] = 'avataaars';
    } catch (Exception $e) {
        error_log('Failed to update default avatar style: ' . $e->getMessage());
    }
}

$avatar_seed = $customer['avatar_seed'] ?? ($customer['email'] ?? 'customer-user');
if ($avatar_seed === null || $avatar_seed === '') {
    $avatar_seed = $customer['email'] ?? 'customer-user';
}

$avatar_url = 'https://api.dicebear.com/9.x/' . rawurlencode($avatar_style) . '/svg?seed=' . rawurlencode($avatar_seed);

if (!isset($avatar_url) || !filter_var($avatar_url, FILTER_VALIDATE_URL)) {
    error_log('ERROR: avatar_url not set for customer ' . $customer['id'] . ' - using fallback avatar');

    $fallbackSeed = $customer['email'] ?? ('customer-' . ($customer['id'] ?? 'user'));
    $avatar_url = 'https://api.dicebear.com/9.x/avataaars/svg?seed=' . rawurlencode($fallbackSeed);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4a90b8">
    <!-- PWA Install - ADD THESE 4 LINES ONLY -->
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#2563eb">
    <link rel="apple-touch-icon" href="../icons/icon-192x192.png">
    <link rel="icon" href="../favicon.ico">
    <title>Mein Bereich - Anna Braun Lerncoaching</title>
    
    <style>
        :root {
            --primary: #4a90b8;
            --secondary: #52b3a4;
            --accent-green: #7cb342;
            --accent-teal: #26a69a;
            --light-blue: #e3f2fd;
            --white: #ffffff;
            --gray-light: #f8f9fa;
            --gray-medium: #6c757d;
            --gray-dark: #343a40;
            --shadow: rgba(0, 0, 0, 0.1);
            --success: #28a745;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: linear-gradient(135deg, var(--light-blue) 0%, var(--white) 100%);
            min-height: 100vh;
            color: var(--gray-dark);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.5;
        }

        .app-container {
            max-width: 800px;
            margin: 0 auto;
            min-height: 100vh;
            background: white;
            box-shadow: 0 0 30px var(--shadow);
            display: flex;
            flex-direction: column;
        }

        .app-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .app-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20px;
            width: 100px;
            height: 200%;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(15deg);
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
            z-index: 1;
        }

        .user-avatar-wrapper {
            position: relative;
            margin-right: 1rem;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.3);
            transition: transform 0.3s ease;
            display: block;
            object-fit: cover;
        }

        .user-avatar:hover {
            transform: scale(1.05);
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: linear-gradient(135deg, #ff4757, #ff3742);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(255, 71, 87, 0.3);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .message-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .message-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .message-panel {
            position: fixed;
            top: 0;
            right: -400px;
            width: 400px;
            height: 100%;
            background: white;
            z-index: 1000;
            transition: right 0.3s ease;
            box-shadow: -2px 0 10px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
        }

        .message-panel.active {
            right: 0;
        }

        .message-panel-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            position: relative;
        }

        .message-tabs {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .message-tab {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .message-tab.active {
            background: rgba(255, 255, 255, 0.3);
        }

        .close-btn {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }

        .close-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .message-content {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
        }

        .message-item {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 1rem;
            overflow: hidden;
            transition: all 0.2s;
        }

        .message-item:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .message-header {
            padding: 1rem;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .message-body {
            padding: 1rem;
        }

        .message-actions {
            padding: 1rem;
            background: #f8fafc;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 0.5rem;
        }

        .response-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .response-btn.yes {
            background: #10b981;
            color: white;
        }

        .response-btn.no {
            background: #ef4444;
            color: white;
        }

        .response-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .mark-read-btn {
            background: #6b7280;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .mark-read-btn:hover {
            background: #4b5563;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .toast {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 2000;
            transition: all 0.3s ease;
            border-left: 4px solid #10b981;
            font-weight: 500;
        }

        .user-info h1 {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .user-info p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .logout-btn {
            margin-left: auto;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
            text-decoration: none;
        }

        .app-content {
            flex: 1;
            padding: 1.5rem;
        }

        .welcome-section {
            text-align: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--light-blue);
            border-radius: 16px;
            border-left: 4px solid var(--primary);
        }

        .welcome-section h2 {
            color: var(--primary);
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
        }

        .welcome-section p {
            color: var(--gray-medium);
            font-size: 0.9rem;
        }

        .info-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px var(--shadow);
            border: 1px solid #f0f0f0;
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px var(--shadow);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .card-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .card-icon.profile {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .card-icon.contact {
            background: linear-gradient(135deg, var(--accent-green) 0%, var(--accent-teal) 100%);
        }

        .card-icon.status {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .card-title {
            font-weight: 600;
            color: var(--gray-dark);
            margin: 0;
            font-size: 1rem;
        }

        .card-content {
            color: var(--gray-medium);
        }

        .card-content .value {
            color: var(--gray-dark);
            font-weight: 500;
            font-size: 1.1rem;
        }

        .card-content .label {
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-top: 0.5rem;
        }

        .status-badge.active {
            background: #e8f5e8;
            color: var(--success);
        }

        .quick-actions {
            margin-top: 2rem;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--gray-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); /* Better for 3 items */
            gap: 1rem;
            margin-top: 1rem;
        }

        @media (max-width: 768px) {
            .action-grid {
                grid-template-columns: 1fr; /* Stack on mobile */
            }
        }

        .action-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 10px var(--shadow);
            border: 1px solid #f0f0f0;
            text-decoration: none;
            color: inherit;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px var(--shadow);
            text-decoration: none;
            color: inherit;
        }

        .action-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }

        /* Enhanced action icons for new functions */
        .action-card:nth-child(1) .action-icon {
            background: linear-gradient(135deg, #42a5f5 0%, #1e88e5 100%);
        }

        .action-card:nth-child(2) .action-icon {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }

        .action-card:nth-child(3) .action-icon {
            background: linear-gradient(135deg, #66bb6a 0%, #43a047 100%);
        }

        .action-card:nth-child(4) .action-icon {
            background: linear-gradient(135deg, #ff7043 0%, #f4511e 100%);
        }

        /* Hover effects for contact actions */
        .action-card[href^="mailto:"]:hover {
            border-left-color: #66bb6a;
        }

        .action-card[href^="tel:"]:hover {
            border-left-color: #ff7043;
        }

        /* Icon animations */
        .action-card:hover .action-icon {
            transform: scale(1.1) rotate(5deg);
            transition: all 0.3s ease;
        }

        .action-content h3 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-dark);
        }

        .action-content {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .action-content p {
            margin: 0.25rem 0 0 0;
            font-size: 0.85rem;
            color: var(--gray-medium);
        }


        /* Modal Overlay */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Modal Container */
        .modal-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 700px;
            max-height: 90vh;
            overflow: hidden;
            transform: translateY(30px) scale(0.95);
            transition: all 0.3s ease;
        }

        .modal-overlay.active .modal-container {
            transform: translateY(0) scale(1);
        }

        /* Modal Header */
        .modal-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .modal-avatar {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
        }

        .modal-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            display: block;
        }

        .modal-title h2 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .modal-title p {
            margin: 0.25rem 0 0 0;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1.2rem;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        /* Modal Content */
        .modal-content {
            padding: 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .modal-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        /* Modal Footer */
        .modal-footer {
            border-top: 1px solid #f0f0f0;
            padding: 1rem 1.5rem;
            text-align: center;
            background: var(--gray-light);
        }

        .modal-footer-btn {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(74, 144, 184, 0.3);
        }

        .modal-footer-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 144, 184, 0.4);
        }

        .app-footer {
            margin-top: auto;
            background: var(--gray-light);
            padding: 1.5rem;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }

        .app-footer p {
            margin: 0;
            color: var(--gray-medium);
            font-size: 0.85rem;
        }

        .app-version {
            margin-top: 0.5rem !important;
            opacity: 0.8;
        }

        .app-version small {
            font-size: 0.75rem;
        }

        #app-version {
            font-size: 0.7rem;
            opacity: 0.8;
            cursor: pointer;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            transition: all 0.3s ease;
            user-select: none;
        }

        #app-version:hover {
            background: rgba(74, 144, 184, 0.1);
            opacity: 1;
            transform: scale(1.05);
        }

        #app-version:active {
            transform: scale(0.95);
        }

        #appVersion {
            font-weight: 500;
            color: var(--primary);
        }

        @media (max-width: 768px) {
            .app-container {
                box-shadow: none;
            }
            
            .app-header {
                padding: 1rem;
            }
            
            .header-content {
                gap: 0.75rem;
            }
            
            .user-avatar {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }
            
            .user-info h1 {
                font-size: 1.2rem;
            }
            
            .app-content {
                padding: 1rem;
            }
            .logout-btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .logout-btn {
                align-self: stretch;
                justify-content: center;
            }
            
            .welcome-section {
                padding: 1rem;
            }
            
            .info-card {
                padding: 1rem;
            }
            
            .action-card {
                padding: 1rem;
                gap: 0.75rem;
            }

            .action-icon {
                width: 40px;
                height: 40px;
                font-size: 1.2rem;
            }

            .action-content h3 {
                font-size: 0.9rem;
            }

            .action-content p {
                font-size: 0.8rem;
            }
        }

        @media (max-width: 768px) {
            .modal-container {
                margin: 0.5rem;
                max-height: 95vh;
            }

            .modal-header {
                padding: 1rem;
            }

            .modal-title h2 {
                font-size: 1.1rem;
            }

            .modal-content {
                padding: 1rem;
            }

            .modal-info-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .modal-footer {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .modal-overlay {
                padding: 0.5rem;
            }

            .modal-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .modal-avatar {
                width: 40px;
                height: 40px;
                font-size: 1.2rem;
            }
        }

        .app-container {
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--gray-dark);
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.3s ease;
        }

        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .char-counter {
            text-align: right;
            font-size: 0.8rem;
            color: var(--gray-medium);
            margin-top: 0.25rem;
        }

        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #f0f0f0;
        }

        .btn-primary, .btn-secondary {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(82, 179, 164, 0.3);
        }

        .btn-secondary {
            background: #f8f9fa;
            color: var(--gray-dark);
            border: 1px solid #e0e0e0;
        }

        .btn-secondary:hover {
            background: #e9ecef;
        }

        @media (max-width: 768px) {
            .modal-footer {
                flex-direction: column;
            }
            
            .btn-primary, .btn-secondary {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <div class="header-content">
                <div class="user-avatar-wrapper">
                    <img src="<?= htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8') ?>"
                         alt="Avatar"
                         class="user-avatar"
                         onclick="openMessagePanel()"
                         style="cursor: pointer;"
                         title="Nachrichten anzeigen">
                    <div class="notification-badge"
                         id="messageNotificationBadge"
                         style="display: <?= $initialUnreadCount > 0 ? 'flex' : 'none' ?>;">
                        <?= $initialUnreadCount > 99 ? '99+' : $initialUnreadCount; ?>
                    </div>
                </div>
                <div class="user-info" onclick="openUserModal()" style="cursor: pointer;" title="Profil anzeigen">
                    <h1>Mein Bereich: <?= htmlspecialchars($customer['first_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>Willkommen bei Anna Braun Lerncoaching</p>
                </div>
                <a href="?logout=1" class="logout-btn" onclick="return confirm('Möchtest du dich wirklich abmelden?')">
                    🚪 Abmelden
                </a>
            </div>
        </header>

        <main class="app-content">
            <section class="welcome-section">
                <h2>🎯 Herzlich willkommen in deinem Lernbereich</h2>
                <p>Hier findest du alle wichtigen Funktionen für dein Lerncoaching bei Anna Braun.</p>
            </section>

            <section class="quick-actions">
                <h2 class="section-title">
                    <span>⚡</span> Schnellzugriff
                </h2>
                <div class="action-grid">
                    <!-- 1. Meine Termine -->
                    <a href="termine.php" class="action-card">
                        <div class="action-icon">📋</div>
                        <div class="action-content">
                            <h3>Meine Termine</h3>
                            <p>Übersicht gebuchter Termine</p>
                        </div>
                    </a>

                    <!-- 2. Termin buchen -->
                    <a href="termine-suchen.php" class="action-card">
                        <div class="action-icon">🔍</div>
                        <div class="action-content">
                            <h3>Termin buchen</h3>
                            <p>Neuen Coaching-Termin vereinbaren</p>
                        </div>
                    </a>

                    <!-- 3. Mitteilungen -->
                    <a href="#" class="action-card" onclick="openMessagePanel(); return false;">
                        <div class="action-icon">📨</div>
                        <div class="action-content">
                            <h3>Mitteilungen</h3>
                            <p>Neuigkeiten vom Coaching-Team</p>
                        </div>
                    </a>

                    <!-- 4. Nachricht senden -->
                    <div class="action-card" onclick="openContactModal()">
                        <div class="action-icon">💬</div>
                        <div class="action-content">
                            <h3>Nachricht senden</h3>
                            <p>Kontaktformular für Ihr Anliegen</p>
                        </div>
                    </div>
                </div>
            </section>

        </main>

        <!-- Message Panel -->
        <div id="messageOverlay" class="message-overlay" onclick="closeMessagePanel()"></div>
        <div id="messagePanel" class="message-panel">
            <div class="message-panel-header">
                <h3>📨 Nachrichten</h3>
                <div class="message-tabs">
                    <button class="message-tab active" onclick="switchMessageTab('unread')" id="unreadTab">
                        Ungelesen (<span id="unreadCount"><?= $initialUnreadCount ?></span>)
                    </button>
                    <button class="message-tab" onclick="switchMessageTab('read')" id="readTab">
                        Gelesen
                    </button>
                </div>
                <button class="close-btn" onclick="closeMessagePanel()">✕</button>
            </div>
            <div class="message-content" id="messageContent">
                <div class="loading">Nachrichten werden geladen...</div>
            </div>
        </div>

        <footer class="app-footer">
            <p>&copy; <?= date('Y') ?> Anna Braun Lerncoaching - Dein Partner für ganzheitliche Lernunterstützung</p>
            <p style="margin-top: 0.5rem;">
                <span id="app-version"
                    onclick="checkForUpdatesManually()"
                    title="Klicken um nach Updates zu suchen">
                    App Version: <span id="appVersion">Lädt...</span>
                </span>
            </p>
        </footer>
    </div>

    <!-- User Info Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-title">
                    <div class="modal-avatar">
                        <img src="<?= htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar">
                    </div>
                    <div>
                        <h2>Deine Kontoinformationen</h2>
                        <p>Persönliche Daten und Status-Übersicht</p>
                    </div>
                </div>
                <button class="modal-close" onclick="closeUserModal()">
                    <span>✕</span>
                </button>
            </div>

            <div class="modal-content">
                <section class="modal-info-grid">
                    <div class="info-card">
                        <div class="card-header">
                            <div class="card-icon profile">👤</div>
                            <h3 class="card-title">Persönliche Daten</h3>
                        </div>
                        <div class="card-content">
                            <div class="label">Name</div>
                            <div class="value"><?= htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['last_name'])) ?></div>

                            <div class="label" style="margin-top: 0.75rem;">E-Mail</div>
                            <div class="value"><?= htmlspecialchars($customer['email']) ?></div>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="card-header">
                            <div class="card-icon contact">📞</div>
                            <h3 class="card-title">Kontaktdaten</h3>
                        </div>
                        <div class="card-content">
                            <div class="label">Telefon</div>
                            <div class="value"><?= htmlspecialchars($customer['phone'] ?: 'Nicht hinterlegt') ?></div>

                            <div class="label" style="margin-top: 0.75rem;">Kunde seit</div>
                            <div class="value"><?= date('d.m.Y', strtotime($customer['created_at'])) ?></div>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="card-header">
                            <div class="card-icon status">⚡</div>
                            <h3 class="card-title">Status</h3>
                        </div>
                        <div class="card-content">
                            <div class="label">Account-Status</div>
                            <div class="status-badge active">
                                <span>✅</span> <?= ucfirst(htmlspecialchars($customer['status'])) ?>
                            </div>

                            <?php if(!empty($customer['last_login'])): ?>
                            <div class="label" style="margin-top: 0.75rem;">Letzter Login</div>
                            <div class="value"><?= date('d.m.Y, H:i', strtotime($customer['last_login'])) ?> Uhr</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>

            <div class="modal-footer">
                <button class="modal-footer-btn" onclick="closeUserModal()">
                    Schließen
                </button>
            </div>
    </div>
    </div>

    <!-- Contact Modal -->
    <div class="modal-overlay" id="contactModal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-title">
                    <div class="modal-avatar">💬</div>
                    <div>
                        <h2>Nachricht senden</h2>
                        <p>Wir sind für Sie da</p>
                    </div>
                </div>
                <button class="modal-close" onclick="closeContactModal()">
                    <span>✕</span>
                </button>
            </div>
            
            <form id="contactForm" class="modal-content">
                <div class="form-group">
                    <label for="contactCategory">Art des Anliegens</label>
                    <select id="contactCategory" name="category" required>
                        <option value="">Bitte wählen...</option>
                        <option value="lerncoaching">Frage zu Lerncoaching</option>
                        <option value="app">Frage zur App</option>
                        <option value="sonstiges">Sonstiges</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="contactMessage">Ihre Nachricht</label>
                    <textarea id="contactMessage" name="message" rows="6" 
                              placeholder="Beschreiben Sie Ihr Anliegen..." 
                              maxlength="2000" required></textarea>
                    <div class="char-counter">
                        <span id="charCount">0</span>/2000 Zeichen
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeContactModal()" class="btn-secondary">
                        Abbrechen
                    </button>
                    <button type="submit" class="btn-primary">
                        <span id="sendBtnText">Nachricht senden</span>
                        <span id="sendBtnLoader" style="display:none;">Wird gesendet...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let panelOpen = false;
        let currentPanelTab = 'messages';
        let currentMessageTab = 'unread';

        function openMessagePanel() {
            const overlay = document.getElementById('messageOverlay');
            const panel = document.getElementById('messagePanel');

            if (overlay) {
                overlay.classList.add('active');
            }

            if (panel) {
                panel.classList.add('active');
            }

            panelOpen = true;
            currentPanelTab = 'messages';

            loadMessages(currentMessageTab);
        }

        function closeMessagePanel() {
            const overlay = document.getElementById('messageOverlay');
            const panel = document.getElementById('messagePanel');

            if (overlay) {
                overlay.classList.remove('active');
            }

            if (panel) {
                panel.classList.remove('active');
            }

            panelOpen = false;
        }

        function switchMessageTab(tab) {
            currentMessageTab = tab === 'read' ? 'read' : 'unread';

            document.querySelectorAll('.message-tab').forEach((button) => {
                button.classList.remove('active');
            });

            const activeTab = document.getElementById(`${currentMessageTab}Tab`);
            if (activeTab) {
                activeTab.classList.add('active');
            }

            loadMessages(currentMessageTab);
        }

        function getCurrentMessageTab() {
            return currentMessageTab;
        }

        function loadMessages(tab = 'unread') {
            const safeTab = tab === 'read' ? 'read' : 'unread';
            const content = document.getElementById('messageContent');
            if (!content) {
                return;
            }

            content.innerHTML = '<div class="loading">Nachrichten werden geladen...</div>';

            fetch(`?ajax=1&tab=${encodeURIComponent(safeTab)}`)
                .then((response) => response.json())
                .then((data) => {
                    updateUnreadCount(typeof data.unread_count === 'number' ? data.unread_count : 0);

                    if (!Array.isArray(data.messages) || data.messages.length === 0) {
                        content.innerHTML = `<div style="text-align: center; color: #6b7280; padding: 2rem;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">📭</div>
                            <p>Keine ${safeTab === 'unread' ? 'ungelesenen' : 'gelesenen'} Nachrichten</p>
                        </div>`;
                        return;
                    }

                    content.innerHTML = data.messages.map((msg) => {
                        const typeIcons = {
                            info: 'ℹ️',
                            success: '✅',
                            warning: '⚠️',
                            question: '❓',
                        };

                        const typeColors = {
                            info: '#3b82f6',
                            success: '#10b981',
                            warning: '#f59e0b',
                            question: '#8b5cf6',
                        };

                        const icon = typeIcons[msg.message_type] || 'ℹ️';
                        const color = typeColors[msg.message_type] || '#3b82f6';
                        const isRead = safeTab === 'read';

                        let actions = '';
                        if (msg.expects_response && !msg.user_response && !isRead) {
                            actions = `
                                <div class="message-actions">
                                    <button class="response-btn yes" onclick="respondToMessage(${msg.id}, 'yes')">
                                        👍 Ja
                                    </button>
                                    <button class="response-btn no" onclick="respondToMessage(${msg.id}, 'no')">
                                        👎 Nein
                                    </button>
                                </div>
                            `;
                        } else if (!isRead) {
                            actions = `
                                <div class="message-actions">
                                    <button class="mark-read-btn" onclick="markAsRead(${msg.id})">
                                        ✓ Als gelesen markieren
                                    </button>
                                </div>
                            `;
                        }

                        if (msg.user_response) {
                            actions = `
                                <div class="message-actions">
                                    <div style="padding: 0.5rem; background: #e5e7eb; border-radius: 6px; color: #374151;">
                                        Deine Antwort: <strong>${msg.user_response === 'yes' ? '👍 Ja' : '👎 Nein'}</strong>
                                    </div>
                                </div>
                            `;
                        }

                        return `
                            <div class="message-item">
                                <div class="message-header">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <span style="font-size: 1.2rem;">${icon}</span>
                                        <span style="color: ${color}; font-weight: 500; text-transform: capitalize;">
                                            ${msg.message_type}
                                        </span>
                                    </div>
                                    <small style="color: #6b7280;">
                                        ${new Date(msg.created_at).toLocaleDateString('de-DE', {
                                            day: '2-digit',
                                            month: '2-digit',
                                            year: '2-digit',
                                            hour: '2-digit',
                                            minute: '2-digit'
                                        })}
                                    </small>
                                </div>
                                <div class="message-body">
                                    <p style="line-height: 1.6; margin: 0; white-space: pre-wrap;">${escapeHtml(msg.message_text)}</p>
                                </div>
                                ${actions}
                            </div>
                        `;
                    }).join('');
                })
                .catch((error) => {
                    console.error('Error loading messages:', error);
                    content.innerHTML = '<div style="color: #ef4444; text-align: center; padding: 2rem;">Fehler beim Laden der Nachrichten</div>';
                    showToast('Fehler beim Laden der Nachrichten', 'error');
                });
        }

        function respondToMessage(messageId, response) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `respond=1&message_id=${encodeURIComponent(messageId)}&response=${encodeURIComponent(response)}`
            })
            .then((res) => res.json())
            .then((data) => {
                if (data.success) {
                    showToast(`Antwort "${response === 'yes' ? 'Ja' : 'Nein'}" gesendet!`);
                    loadMessages(getCurrentMessageTab());
                } else {
                    showToast('Fehler beim Senden der Antwort', 'error');
                }
            })
            .catch((error) => {
                console.error('Error responding to message:', error);
                showToast('Fehler beim Senden der Antwort', 'error');
            });
        }

        function markAsRead(messageId) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `mark_read=1&message_id=${encodeURIComponent(messageId)}`
            })
            .then((res) => res.json())
            .then((data) => {
                if (data.success) {
                    showToast('Als gelesen markiert!');
                    loadMessages(getCurrentMessageTab());
                } else {
                    showToast('Fehler beim Markieren', 'error');
                }
            })
            .catch((error) => {
                console.error('Error marking as read:', error);
                showToast('Fehler beim Markieren', 'error');
            });
        }

        function updateUnreadCount(count) {
            const badge = document.getElementById('messageNotificationBadge');
            const unreadSpan = document.getElementById('unreadCount');

            if (unreadSpan) {
                unreadSpan.textContent = count;
            }

            if (badge) {
                if (count > 0) {
                    badge.style.display = 'flex';
                    badge.textContent = count > 99 ? '99+' : count;
                } else {
                    badge.style.display = 'none';
                }
            }
        }

        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = 'toast';
            if (type === 'error') {
                toast.style.borderLeftColor = '#ef4444';
            }
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateX(-50%) translateY(-5px)';
            }, 100);

            setTimeout(() => {
                toast.style.transform = 'translateX(-50%) translateY(0)';
            }, 200);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(-50%) translateY(10px)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text ?? '';
            return div.innerHTML;
        }

        document.addEventListener('DOMContentLoaded', () => {
            const notificationBadge = document.getElementById('messageNotificationBadge');
            if (notificationBadge && notificationBadge.style.display !== 'none') {
                notificationBadge.style.visibility = 'visible';
                notificationBadge.style.opacity = '1';
            }

            updateUnreadCount(<?= (int) $initialUnreadCount ?>);
        });

        setInterval(() => {
            if (panelOpen && currentPanelTab === 'messages') {
                loadMessages(getCurrentMessageTab());
            }
        }, 5000);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && panelOpen) {
                closeMessagePanel();
            }
        });

        // Modal Control Functions
        function toggleUserModal() {
            const modal = document.getElementById('userModal');
            if (modal.classList.contains('active')) {
                closeUserModal();
            } else {
                openUserModal();
            }
        }

        function openUserModal() {
            const modal = document.getElementById('userModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeUserModal() {
            const modal = document.getElementById('userModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        function openContactModal() {
            const modal = document.getElementById('contactModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            document.getElementById('contactCategory').focus();
        }

        function closeContactModal() {
            const modal = document.getElementById('contactModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
            document.getElementById('contactForm').reset();
            document.getElementById('charCount').textContent = '0';
        }

        // Zeichen-Counter
        document.getElementById('contactMessage').addEventListener('input', function() {
            const count = this.value.length;
            document.getElementById('charCount').textContent = count;
            
            if (count > 1800) {
                document.getElementById('charCount').style.color = '#ff6b6b';
            } else {
                document.getElementById('charCount').style.color = 'var(--gray-medium)';
            }
        });

        // Form-Submission
        document.getElementById('contactForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const btnText = document.getElementById('sendBtnText');
            const btnLoader = document.getElementById('sendBtnLoader');
            
            // Loading State
            submitBtn.disabled = true;
            btnText.style.display = 'none';
            btnLoader.style.display = 'inline';
            
            const formData = new FormData(this);
            
            try {
                const response = await fetch('contact_form.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ ' + result.message);
                    closeContactModal();
                } else {
                    alert('❌ ' + result.message);
                }
            } catch (error) {
                alert('❌ Verbindungsfehler. Bitte versuchen Sie es später erneut.');
            } finally {
                // Reset Loading State
                submitBtn.disabled = false;
                btnText.style.display = 'inline';
                btnLoader.style.display = 'none';
            }
        });

        // Modal außerhalb schließen
        document.getElementById('contactModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeContactModal();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.action-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(card);
            });

            document.querySelectorAll('.action-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    this.style.transform = 'scale(0.98)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                });
            });

            const modal = document.getElementById('userModal');

            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeUserModal();
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    closeUserModal();
                }
            });

            let lastActivity = Date.now();
            setInterval(() => {
                if (Date.now() - lastActivity > 600000) {
                    // Session warning after 10 minutes inactive
                }
            }, 60000);

            ['click', 'keypress', 'scroll', 'mousemove'].forEach(event => {
                document.addEventListener(event, () => {
                    lastActivity = Date.now();
                }, { passive: true });
            });
        });
    </script>

    <script>
    // Fetch app version from service worker
    async function loadAppVersion() {
        try {
            if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({ type: 'CHECK_VERSION' });

                navigator.serviceWorker.addEventListener('message', event => {
                    if (event.data.type === 'VERSION_INFO') {
                        document.getElementById('appVersion').textContent = event.data.version;
                    }
                });
            } else {
                const response = await fetch('../sw.js');
                const swContent = await response.text();
                const versionMatch = swContent.match(/VERSION\s*=\s*['"](.*?)['"]/);

                if (versionMatch) {
                    document.getElementById('appVersion').textContent = versionMatch[1];
                } else {
                    document.getElementById('appVersion').textContent = 'Unknown';
                }
            }
        } catch (error) {
            console.log('Could not load app version:', error);
            document.getElementById('appVersion').textContent = 'Unknown';
        }
    }

    document.addEventListener('DOMContentLoaded', loadAppVersion);
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const versionElement = document.getElementById('app-version');
        if (versionElement) {
            versionElement.addEventListener('mousedown', () => {
                versionElement.style.background = 'rgba(74, 144, 184, 0.2)';
            });

            versionElement.addEventListener('mouseup', () => {
                setTimeout(() => {
                    versionElement.style.background = '';
                }, 150);
            });

            versionElement.addEventListener('touchstart', () => {
                versionElement.style.background = 'rgba(74, 144, 184, 0.2)';
            });

            versionElement.addEventListener('touchend', () => {
                setTimeout(() => {
                    versionElement.style.background = '';
                }, 150);
            });
        }
    });
    </script>
    <!-- PWA Install Button - ADD THIS SCRIPT BLOCK ONLY -->
    <script>
let installPrompt = null;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    installPrompt = e;
    
    // Create simple install button
    const btn = document.createElement('button');
    btn.textContent = '📱 App installieren';
    btn.style.cssText = `
        position: fixed; top: 20px; right: 20px; z-index: 999;
        background: #2563eb; color: white; border: none;
        padding: 8px 12px; border-radius: 4px; cursor: pointer;
        font-size: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    `;
    
    btn.onclick = async () => {
        if (installPrompt) {
            installPrompt.prompt();
            const result = await installPrompt.userChoice;
            if (result.outcome === 'accepted') {
                btn.remove();
            }
            installPrompt = null;
        }
    };
    
    document.body.appendChild(btn);
});

// Hide if already installed
if (window.matchMedia('(display-mode: standalone)').matches) {
    // Already running as PWA, don't show button
}
</script>
<script src="../pwa-update.js"></script>
</body>
</html>
