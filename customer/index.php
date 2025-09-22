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

        .user-avatar {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .user-avatar.clickable {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .user-avatar.clickable:hover {
            transform: scale(1.05);
            border-color: rgba(255, 255, 255, 0.6);
            box-shadow: 0 4px 15px rgba(255, 255, 255, 0.2);
        }

        .user-avatar.clickable:active {
            transform: scale(0.98);
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
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
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

        .unread-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            padding: 0.15rem 0.5rem;
            background: #ef4444;
            color: white;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.3);
        }

        .unread-badge.hidden {
            display: none;
        }

        .message-section {
            margin-top: 2.5rem;
            background: white;
            border-radius: 16px;
            padding: 1.75rem;
            box-shadow: 0 4px 20px var(--shadow);
            border: 1px solid #f0f0f0;
        }

        .message-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .message-header h2 {
            margin: 0;
            font-size: 1.1rem;
            color: var(--gray-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .message-tabs {
            display: inline-flex;
            background: #f3f4f6;
            border-radius: 999px;
            padding: 0.25rem;
            gap: 0.25rem;
        }

        .message-tabs button {
            border: none;
            background: transparent;
            color: #6b7280;
            font-weight: 600;
            padding: 0.4rem 0.9rem;
            border-radius: 999px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .message-tabs button.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 2px 12px rgba(74, 144, 184, 0.2);
        }

        .messages-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .message-card {
            background: #f9fafb;
            border-radius: 12px;
            padding: 1.25rem;
            border-left: 4px solid var(--primary);
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        }

        .message-card.success { border-left-color: #10b981; }
        .message-card.warning { border-left-color: #f59e0b; }
        .message-card.question { border-left-color: #6366f1; }

        .message-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
            color: #6b7280;
        }

        .message-text {
            color: var(--gray-dark);
            line-height: 1.6;
            font-size: 0.95rem;
            margin-bottom: 0.75rem;
            white-space: pre-line;
        }

        .message-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }

        .message-actions button {
            border: none;
            border-radius: 999px;
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .message-actions .mark-read {
            background: white;
            border: 1px solid #d1d5db;
            color: #4b5563;
        }

        .message-actions .mark-read:hover {
            background: #f3f4f6;
        }

        .message-actions .response-yes {
            background: #10b981;
            color: white;
        }

        .message-actions .response-no {
            background: #ef4444;
            color: white;
        }

        .message-actions .response-yes:hover {
            background: #0ea472;
        }

        .message-actions .response-no:hover {
            background: #dc2626;
        }

        .message-response-status {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .message-empty {
            text-align: center;
            padding: 2rem 1rem;
            color: #6b7280;
            border: 2px dashed #e5e7eb;
            border-radius: 12px;
            background: #f9fafb;
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
                <div class="user-avatar clickable" onclick="toggleUserModal()">👤</div>
                <div class="user-info">
                    <h1>Willkommen, <?= htmlspecialchars($customer['first_name']) ?>!</h1>
                    <p>Dein persönlicher Lernbereich</p>
                </div>
                <a href="?logout=1" class="logout-btn" onclick="return confirm('Möchtest du dich wirklich abmelden?')">
                    <span>🚪</span> Abmelden
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
                    <a href="#messageCenter" class="action-card" onclick="openMessageSection(event)">
                        <div class="action-icon">📨</div>
                        <div class="action-content">
                            <h3>Mitteilungen</h3>
                            <p>Neuigkeiten vom Coaching-Team</p>
                            <span class="unread-badge hidden" id="messageBadge"></span>
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

            <section class="message-section" id="messageCenter">
                <div class="message-header">
                    <h2>💬 Nachrichten vom Team</h2>
                    <div class="message-tabs" role="tablist">
                        <button type="button" class="active" data-tab="unread" id="tabUnread" role="tab" aria-selected="true">
                            Ungelesen
                        </button>
                        <button type="button" data-tab="read" id="tabRead" role="tab" aria-selected="false">
                            Gelesen
                        </button>
                    </div>
                </div>
                <div class="messages-container" id="messagesContainer">
                    <div class="message-empty">Nachrichten werden geladen...</div>
                </div>
            </section>

        </main>

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
                    <div class="modal-avatar">👤</div>
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
        const messageState = {
            currentTab: 'unread',
            unreadCount: <?= (int) $initialUnreadCount ?>,
            loading: false,
        };

        const messageElements = {
            container: document.getElementById('messagesContainer'),
            badge: document.getElementById('messageBadge'),
            tabButtons: document.querySelectorAll('.message-tabs button'),
            section: document.getElementById('messageCenter'),
            unreadButton: document.getElementById('tabUnread'),
        };

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDate(value) {
            if (!value) {
                return '';
            }

            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return value;
            }

            return date.toLocaleString('de-DE', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        }

        function updateUnreadBadge(count) {
            messageState.unreadCount = Math.max(0, Number(count) || 0);

            if (messageElements.badge) {
                if (messageState.unreadCount > 0) {
                    messageElements.badge.textContent = messageState.unreadCount;
                    messageElements.badge.classList.remove('hidden');
                } else {
                    messageElements.badge.textContent = '';
                    messageElements.badge.classList.add('hidden');
                }
            }

            if (messageElements.unreadButton) {
                messageElements.unreadButton.textContent = messageState.unreadCount > 0
                    ? `Ungelesen (${messageState.unreadCount})`
                    : 'Ungelesen';
            }
        }

        function setActiveTab(tab) {
            messageState.currentTab = tab === 'read' ? 'read' : 'unread';

            messageElements.tabButtons.forEach((button) => {
                const isActive = button.dataset.tab === messageState.currentTab;
                button.classList.toggle('active', isActive);
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
        }

        function openMessageSection(event) {
            if (event) {
                event.preventDefault();
            }

            if (messageElements.section) {
                messageElements.section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            fetchMessages('unread');
        }

        async function fetchMessages(tab) {
            const nextTab = tab === 'read' ? 'read' : 'unread';

            if (messageState.loading) {
                return;
            }

            messageState.loading = true;
            setActiveTab(nextTab);

            if (messageElements.container) {
                messageElements.container.innerHTML = '<div class="message-empty">Nachrichten werden geladen...</div>';
            }

            try {
                const response = await fetch(`index.php?ajax=1&tab=${encodeURIComponent(nextTab)}`);

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();

                if (typeof data.unread_count === 'number') {
                    updateUnreadBadge(data.unread_count);
                }

                renderMessages(Array.isArray(data.messages) ? data.messages : []);
            } catch (error) {
                console.error('Error loading messages:', error);
                if (messageElements.container) {
                    messageElements.container.innerHTML = '<div class="message-empty">Fehler beim Laden der Nachrichten.</div>';
                }
            } finally {
                messageState.loading = false;
            }
        }

        function renderMessages(messages) {
            if (!messageElements.container) {
                return;
            }

            if (!messages.length) {
                const emptyText = messageState.currentTab === 'read'
                    ? 'Keine gelesenen Nachrichten vorhanden.'
                    : 'Keine neuen Nachrichten verfügbar.';
                messageElements.container.innerHTML = `<div class="message-empty">${emptyText}</div>`;
                return;
            }

            const iconMap = {
                info: 'ℹ️',
                success: '✅',
                warning: '⚠️',
                question: '❓',
            };

            const html = messages.map((msg) => {
                const type = iconMap[msg.message_type] ? msg.message_type : 'info';
                const icon = iconMap[type];
                const messageText = escapeHtml(msg.message_text).replace(/\n/g, '<br>');
                const createdAt = escapeHtml(formatDate(msg.created_at));

                let responseSection = '';
                let responseActions = '';

                if (msg.expects_response) {
                    if (msg.user_response === 'yes') {
                        responseSection = '<div class="message-response-status" style="color:#10b981;">✅ Antwort: Ja</div>';
                    } else if (msg.user_response === 'no') {
                        responseSection = '<div class="message-response-status" style="color:#ef4444;">❌ Antwort: Nein</div>';
                    } else {
                        responseSection = '<div class="message-response-status" style="color:#6b7280;">⏳ Antwort ausstehend</div>';
                        responseActions = `
                            <button type="button" class="response-yes" onclick="sendMessageResponse(${msg.id}, 'yes')">Ja</button>
                            <button type="button" class="response-no" onclick="sendMessageResponse(${msg.id}, 'no')">Nein</button>
                        `;
                    }
                }

                let markReadButton = '';
                if (messageState.currentTab === 'unread') {
                    markReadButton = `<button type="button" class="mark-read" onclick="markAsRead(${msg.id})">Als gelesen markieren</button>`;
                }

                const actionsHtml = (responseActions || markReadButton)
                    ? `<div class="message-actions">${responseActions}${markReadButton}</div>`
                    : '';

                return `
                    <div class="message-card ${type}">
                        <div class="message-meta">
                            <span>${icon} ${type.charAt(0).toUpperCase() + type.slice(1)}</span>
                            <span>${createdAt}</span>
                        </div>
                        <div class="message-text">${messageText}</div>
                        ${responseSection ? `<div>${responseSection}</div>` : ''}
                        ${actionsHtml}
                    </div>
                `;
            }).join('');

            messageElements.container.innerHTML = html;
        }

        async function markAsRead(messageId) {
            if (!messageId) {
                return;
            }

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    },
                    body: `mark_read=1&message_id=${encodeURIComponent(messageId)}`,
                });

                const result = await response.json();

                if (result && result.success) {
                    await fetchMessages(messageState.currentTab);
                }
            } catch (error) {
                console.error('Error marking message as read:', error);
            }
        }

        async function sendMessageResponse(messageId, responseValue) {
            if (!messageId || (responseValue !== 'yes' && responseValue !== 'no')) {
                return;
            }

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    },
                    body: `respond=1&message_id=${encodeURIComponent(messageId)}&response=${encodeURIComponent(responseValue)}`,
                });

                const result = await response.json();

                if (result && result.success) {
                    await fetchMessages(messageState.currentTab);
                }
            } catch (error) {
                console.error('Error sending response:', error);
            }
        }

        messageElements.tabButtons.forEach((button) => {
            button.addEventListener('click', () => {
                fetchMessages(button.dataset.tab);
            });
        });

        window.openMessageSection = openMessageSection;
        window.markAsRead = markAsRead;
        window.sendMessageResponse = sendMessageResponse;

        updateUnreadBadge(messageState.unreadCount);
        fetchMessages(messageState.currentTab);

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
