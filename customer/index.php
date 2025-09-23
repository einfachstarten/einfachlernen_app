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

function getInitialsAvatarSVG(array $customer): string
{
    $initials = '';
    $firstName = $customer['first_name'] ?? '';
    $lastName = $customer['last_name'] ?? '';
    $email = $customer['email'] ?? '';

    if (!empty($firstName)) {
        $initials .= mb_strtoupper(mb_substr($firstName, 0, 1, 'UTF-8'), 'UTF-8');
    }

    if (!empty($lastName)) {
        $initials .= mb_strtoupper(mb_substr($lastName, 0, 1, 'UTF-8'), 'UTF-8');
    }

    if ($initials === '' && !empty($email)) {
        $initials = mb_strtoupper(mb_substr($email, 0, 1, 'UTF-8'), 'UTF-8');
    }

    if ($initials === '') {
        $initials = '?';
    }

    $colors = [
        ['#4a90b8', '#52b3a4'],
        ['#7cb342', '#26a69a'],
        ['#ff7043', '#ff5722'],
        ['#ab47bc', '#9c27b0'],
        ['#42a5f5', '#2196f3'],
        ['#66bb6a', '#4caf50'],
    ];

    $customerId = (int)($customer['id'] ?? 0);
    $colorIndex = $customerId % count($colors);
    $colorSet = $colors[$colorIndex];
    $gradientId = 'bg' . $customerId;

    return '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">'
        . '<defs>'
        . '<linearGradient id="' . htmlspecialchars($gradientId, ENT_QUOTES, 'UTF-8') . '" x1="0%" y1="0%" x2="100%" y2="100%">'
        . '<stop offset="0%" style="stop-color:' . $colorSet[0] . ';stop-opacity:1" />'
        . '<stop offset="100%" style="stop-color:' . $colorSet[1] . ';stop-opacity:1" />'
        . '</linearGradient>'
        . '</defs>'
        . '<circle cx="50" cy="50" r="50" fill="url(#' . htmlspecialchars($gradientId, ENT_QUOTES, 'UTF-8') . ')"/>'
        . '<text x="50" y="60" text-anchor="middle" fill="white" font-size="28" font-weight="bold" font-family="Arial, sans-serif">'
        . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8')
        . '</text>'
        . '</svg>';
}

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
$avatar_seed = $customer['avatar_seed'] ?? '';

$hasChosenAvatar = !empty($avatar_style)
    && !empty($avatar_seed)
    && in_array($avatar_style, $availableAvatarStyles, true);

if ($hasChosenAvatar) {
    $avatar_url = 'https://api.dicebear.com/9.x/' . rawurlencode($avatar_style) . '/svg?seed=' . rawurlencode($avatar_seed);
} else {
    $avatar_url = 'data:image/svg+xml;base64,' . base64_encode(getInitialsAvatarSVG($customer));
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
            display: inline-block;
        }

        .user-avatar {
            position: relative;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.3);
            transition: transform 0.3s ease;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.1);
            cursor: pointer;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .user-avatar:hover {
            transform: scale(1.05);
        }

        .avatar-selection-hint {
            position: absolute;
            bottom: -2px;
            right: -2px;
            background: #4a90b8;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            border: 2px solid white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .avatar-tooltip {
            position: absolute;
            top: 70px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 100;
        }

        .user-avatar-wrapper:hover .avatar-tooltip {
            opacity: 1;
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

        /* Smart Panel System */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            z-index: 998;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .smart-panel {
            position: fixed;
            top: 0;
            right: -400px;
            width: 400px;
            height: 100vh;
            background: white;
            box-shadow: -4px 0 20px rgba(0, 0, 0, 0.15);
            z-index: 999;
            display: flex;
            flex-direction: column;
            transition: right 0.3s ease;
            overflow: hidden;
        }

        .smart-panel.active {
            right: 0;
        }

        .smart-panel-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }

        .smart-panel-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .close-panel {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            font-size: 1.5rem;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .close-panel:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .panel-tabs {
            display: flex;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
        }

        .tab-btn {
            flex: 1;
            padding: 1rem;
            border: none;
            background: transparent;
            cursor: pointer;
            font-weight: 500;
            color: #6b7280;
            transition: all 0.2s;
            position: relative;
        }

        .tab-btn.active {
            color: var(--primary);
            background: white;
            border-bottom: 2px solid var(--primary);
        }

        .tab-badge {
            background: #ef4444;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 0.5rem;
        }

        .tab-content {
            flex: 1;
            overflow-y: auto;
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Profile Section */
        .profile-section {
            padding: 1.5rem;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 12px;
        }

        .profile-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid rgba(82, 179, 164, 0.25);
            background: white;
            overflow: hidden;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .profile-info h4 {
            margin: 0 0 0.25rem 0;
            color: var(--gray-dark);
            font-size: 1.1rem;
        }

        .profile-info p {
            margin: 0;
            color: var(--gray-medium);
            font-size: 0.9rem;
        }

        .avatar-change-link {
            background: linear-gradient(135deg, var(--secondary), var(--accent-teal));
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 0.5rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .avatar-change-link:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(82, 179, 164, 0.3);
        }

        .detail-group {
            margin-bottom: 1.5rem;
        }

        .detail-label {
            font-size: 0.85rem;
            color: var(--gray-medium);
            margin-bottom: 0.25rem;
            font-weight: 500;
        }

        .detail-value {
            font-size: 0.95rem;
            color: var(--gray-dark);
            font-weight: 500;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-badge.active {
            background: #dcfce7;
            color: #166534;
        }

        .status-badge.beta {
            background: #e0e7ff;
            color: #3730a3;
        }

        /* Avatar Selection */
        .avatar-selection {
            margin-top: 2rem;
            padding: 1.25rem;
            background: #f8fafc;
            border-radius: 12px;
            display: none;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .avatar-selection.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .avatar-selection h4 {
            font-size: 1rem;
            margin-bottom: 0.75rem;
            color: var(--gray-dark);
        }

        .avatar-hint {
            font-size: 0.85rem;
            color: #6b7280;
            margin-bottom: 1rem;
        }

        .avatar-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
            gap: 0.75rem;
        }

        .avatar-option {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 0.75rem;
            background: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--gray-dark);
            font-weight: 600;
            font-family: inherit;
            outline: none;
            min-height: 90px;
            position: relative;
        }

        .avatar-option:hover {
            border-color: var(--secondary);
            box-shadow: 0 6px 16px rgba(82, 179, 164, 0.25);
            transform: translateY(-2px);
        }

        .avatar-option.selected {
            border-color: var(--secondary);
            background: #ecfdf5;
            box-shadow: 0 8px 20px rgba(82, 179, 164, 0.3);
        }

        .avatar-option.selected::after {
            content: '✓';
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: var(--secondary);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
        }

        .avatar-option img {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .avatar-option img.updating {
            opacity: 0.7;
            transform: scale(0.9);
        }

        .avatar-generate-btn {
            margin-top: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--secondary);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 0.7rem 1.4rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.9rem;
            width: 100%;
            justify-content: center;
        }

        .avatar-generate-btn:hover {
            background: #3d9b91;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(82, 179, 164, 0.3);
        }

        /* Messages Section */
        .messages-section {
            padding: 1rem;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .message-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .message-tab-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .message-tab-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .manual-refresh-btn {
            background: var(--secondary);
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            margin-left: auto;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .manual-refresh-btn:hover {
            background: #3d9b91;
            transform: translateY(-1px);
        }

        .messages-content {
            flex: 1;
            overflow-y: auto;
        }

        .loading {
            text-align: center;
            color: #6b7280;
            padding: 2rem;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .smart-panel {
                width: 100%;
                right: -100%;
            }

            .avatar-grid {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
                gap: 0.5rem;
            }

            .avatar-option {
                padding: 0.5rem;
                min-height: 80px;
            }

            .avatar-option img {
                width: 48px;
                height: 48px;
            }
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
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
            text-decoration: none;
        }

        .logout-icon,
        .logout-text {
            display: inline;
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
                flex-wrap: nowrap;
                gap: 0.5rem;
            }

            .user-avatar {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }

            .avatar-tooltip {
                display: none;
            }

            .user-info {
                flex-shrink: 1;
                min-width: 0;
            }

            .user-info h1 {
                font-size: 1rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .user-info p {
                font-size: 0.8rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .app-content {
                padding: 1rem;
            }

            .logout-btn {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
                gap: 0.25rem;
                white-space: nowrap;
                flex-shrink: 0;
            }
        }

        @media (max-width: 480px) {
            .header-content {
                flex-wrap: nowrap;
                align-items: center;
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
                    <div class="user-avatar clickable"
                         onclick="toggleSmartPanel()"
                         data-unread="<?= $initialUnreadCount ?>"
                         data-has-chosen="<?= $hasChosenAvatar ? 'true' : 'false' ?>"
                         title="Profil & Nachrichten">
                        <img src="<?= htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8') ?>"
                             alt="Avatar">
                        <?php if (!$hasChosenAvatar): ?>
                            <div class="avatar-selection-hint">🎭</div>
                        <?php endif; ?>
                    </div>
                    <?php if (!$hasChosenAvatar): ?>
                        <div class="avatar-tooltip">Klicke um deinen Avatar zu wählen</div>
                    <?php endif; ?>
                    <div class="notification-badge"
                         id="messageNotificationBadge"
                         style="display: <?= $initialUnreadCount > 0 ? 'flex' : 'none' ?>;">
                        <?= $initialUnreadCount > 99 ? '99+' : $initialUnreadCount; ?>
                    </div>
                </div>
                <div class="user-info">
                    <h1>Mein Bereich: <?= htmlspecialchars($customer['first_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>Willkommen bei Anna Braun Lerncoaching</p>
                </div>
                <a href="?logout=1" class="logout-btn" onclick="return confirm('Möchtest du dich wirklich abmelden?')">
                    <span class="logout-icon">🚪</span><span class="logout-text">Abmelden</span>
                </a>
            </div>
        </header>

        <main class="app-content">
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
                    <a href="#" class="action-card" onclick="openMessagesTabFromAction(); return false;">
                        <div class="action-icon">📨</div>
                        <div class="action-content">
                            <h3>Mitteilungen</h3>
                            <p>Neuigkeiten von Anna</p>
                        </div>
                    </a>

                    <!-- 4. Nachricht senden -->
                    <div class="action-card" onclick="openContactModal()">
                        <div class="action-icon">💬</div>
                        <div class="action-content">
                            <h3>Nachricht senden</h3>
                            <p>Kontaktformular für deine Anliegen</p>
                        </div>
                    </div>
                </div>
            </section>

        </main>

        <!-- Smart Panel System (Profile & Messages) -->
        <div class="overlay" id="overlay" onclick="closeSmartPanel()"></div>
        <div class="smart-panel" id="smartPanel">
            <div class="smart-panel-header">
                <h3 id="panelTitle">👤 Mein Profil</h3>
                <button class="close-panel" type="button" onclick="closeSmartPanel()">×</button>
            </div>

            <div class="panel-tabs">
                <button type="button" class="tab-btn active" data-tab="profile" onclick="switchTab('profile')">👤 Profil</button>
                <button type="button" class="tab-btn" data-tab="messages" onclick="switchTab('messages')" id="messagesTab">
                    📨 Nachrichten <span class="tab-badge" id="tabBadge" style="display: <?= $initialUnreadCount > 0 ? 'inline' : 'none' ?>;">
                        <?= $initialUnreadCount > 99 ? '99+' : $initialUnreadCount; ?>
                    </span>
                </button>
            </div>

            <div class="tab-content active" id="profileContent">
                <div class="profile-section">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <img src="<?= htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar">
                        </div>
                        <div class="profile-info">
                            <h4><?= htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''))) ?></h4>
                            <p><?= htmlspecialchars($customer['email']) ?></p>
                            <button type="button"
                                    class="avatar-change-link"
                                    onclick="toggleAvatarSelection()"
                                    id="avatarChangeBtn"
                                    title="Avatar-Auswahl öffnen">
                                🎭 Avatar ändern
                            </button>
                        </div>
                    </div>

                    <div class="profile-details">
                        <div class="detail-group">
                            <div class="detail-label">Telefon</div>
                            <div class="detail-value"><?= htmlspecialchars($customer['phone'] ?: 'Nicht hinterlegt') ?></div>
                        </div>

                        <div class="detail-group">
                            <div class="detail-label">Account-Status</div>
                            <div class="detail-value">
                                <span class="status-badge active">✅ <?= ucfirst(htmlspecialchars($customer['status'])) ?></span>
                            </div>
                        </div>

                        <div class="detail-group">
                            <div class="detail-label">Kunde seit</div>
                            <div class="detail-value"><?= date('d.m.Y', strtotime($customer['created_at'])) ?></div>
                        </div>

                        <?php if(!empty($customer['last_login'])): ?>
                        <div class="detail-group">
                            <div class="detail-label">Letzter Login</div>
                            <div class="detail-value"><?= date('d.m.Y, H:i', strtotime($customer['last_login'])) ?> Uhr</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="avatar-selection">
                        <h4>🎭 Avatar auswählen</h4>
                        <p class="avatar-hint">Wähle deinen Style oder würfle ein neues Set von Avataren!</p>
                        <?php
                            $styleLabelMap = [
                                'avataaars' => 'Avataaars',
                                'adventurer-neutral' => 'Adventurer',
                                'fun-emoji' => 'Fun Emoji',
                                'lorelei' => 'Lorelei',
                                'pixel-art' => 'Pixel Art',
                                'thumbs' => 'Thumbs',
                            ];
                            $avatarPreviewSeed = $avatar_seed !== '' ? $avatar_seed : ($customer['email'] ?? 'customer-user');
                        ?>
                        <div class="avatar-grid">
                            <?php foreach ($availableAvatarStyles as $style):
                                $isSelected = $style === $avatar_style;
                                $styleLabel = $styleLabelMap[$style] ?? ucfirst(str_replace('-', ' ', $style));
                                $styleUrl = 'https://api.dicebear.com/9.x/' . rawurlencode($style) . '/svg?seed=' . rawurlencode($avatarPreviewSeed);
                            ?>
                                <button type="button"
                                        class="avatar-option <?= $isSelected ? 'selected' : '' ?>"
                                        data-style="<?= htmlspecialchars($style, ENT_QUOTES, 'UTF-8') ?>"
                                        onclick="selectAvatar('<?= htmlspecialchars($style, ENT_QUOTES, 'UTF-8') ?>', this)"
                                        title="<?= htmlspecialchars($styleLabel, ENT_QUOTES, 'UTF-8') ?>">
                                    <img src="<?= htmlspecialchars($styleUrl, ENT_QUOTES, 'UTF-8') ?>"
                                         alt="<?= htmlspecialchars($styleLabel, ENT_QUOTES, 'UTF-8') ?>">
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="avatar-generate-btn" onclick="generateNewAvatarSet()">
                            🎲 Neues Set würfeln
                        </button>
                    </div>
                </div>
            </div>

            <div class="tab-content" id="messagesContent">
                <div class="messages-section">
                    <div class="message-tabs">
                        <button class="message-tab-btn active" data-tab="unread" onclick="switchMessageTab('unread')">
                            Ungelesen (<span id="unreadCount"><?= $initialUnreadCount ?></span>)
                        </button>
                        <button class="message-tab-btn" data-tab="read" onclick="switchMessageTab('read')">
                            Gelesen
                        </button>
                    </div>
                    <div class="messages-content" id="messagesContentArea">
                        <div class="loading">Nachrichten werden geladen...</div>
                    </div>
                </div>
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
        const smartPanel = document.getElementById('smartPanel');
        const overlay = document.getElementById('overlay');
        const panelTitle = document.getElementById('panelTitle');
        const panelTabButtons = document.querySelectorAll('.tab-btn');
        const userAvatar = document.querySelector('.user-avatar.clickable');
        const avatarSelectionElement = document.querySelector('.avatar-selection');
        const avatarChangeBtn = document.getElementById('avatarChangeBtn');
        const avatarGridElement = document.querySelector('.avatar-grid');
        const tabBadge = document.getElementById('tabBadge');
        const availableAvatarStyles = <?= json_encode($availableAvatarStyles) ?>;

        let avatarSelectionVisible = false;
        let currentAvatarStyle = <?= json_encode($avatar_style) ?>;
        let currentAvatarSeed = <?= json_encode($avatar_seed !== '' ? $avatar_seed : ($customer['email'] ?? 'customer-user')) ?>;
        let hasChosenAvatar = <?= $hasChosenAvatar ? 'true' : 'false' ?>;
        const defaultAvatarStyle = <?= json_encode($availableAvatarStyles[0]) ?>;
        let panelOpen = false;
        let currentPanelTab = 'profile';
        let currentMessageTab = 'unread';

        function toggleSmartPanel(tabOverride = null) {
            if (!smartPanel || !overlay) {
                return;
            }

            if (!panelOpen) {
                const unreadCount = parseInt(userAvatar?.dataset.unread || '0', 10);
                const targetTab = tabOverride || (unreadCount > 0 ? 'messages' : 'profile');
                openSmartPanel(targetTab);
            } else {
                closeSmartPanel();
            }
        }

        function openSmartPanel(tab = 'profile') {
            if (!smartPanel || !overlay) {
                return;
            }

            switchTab(tab);
            smartPanel.classList.add('active');
            overlay.classList.add('active');
            panelOpen = true;
        }

        function closeSmartPanel() {
            if (!smartPanel || !overlay) {
                return;
            }

            smartPanel.classList.remove('active');
            overlay.classList.remove('active');
            panelOpen = false;
        }

        function addManualRefreshButton() {
            const messagesSection = document.querySelector('.messages-section');
            if (!messagesSection) {
                return;
            }

            const tabs = messagesSection.querySelector('.message-tabs');
            if (!tabs || document.getElementById('manualRefreshBtn')) {
                return;
            }

            const refreshBtn = document.createElement('button');
            refreshBtn.id = 'manualRefreshBtn';
            refreshBtn.type = 'button';
            refreshBtn.className = 'manual-refresh-btn';
            refreshBtn.innerHTML = '🔄 Aktualisieren';
            refreshBtn.addEventListener('click', () => loadMessages(getCurrentMessageTab()));

            tabs.appendChild(refreshBtn);
        }

        function switchTab(tab) {
            currentPanelTab = tab === 'messages' ? 'messages' : 'profile';

            panelTabButtons.forEach((btn) => {
                btn.classList.toggle('active', btn.dataset.tab === currentPanelTab);
            });

            document.querySelectorAll('.tab-content').forEach((content) => {
                content.classList.toggle('active', content.id === `${currentPanelTab}Content`);
            });

            if (panelTitle) {
                panelTitle.innerHTML = currentPanelTab === 'profile' ? '👤 Mein Profil' : '📨 Nachrichten';
            }

            if (currentPanelTab === 'messages') {
                addManualRefreshButton();
                loadMessages(getCurrentMessageTab());
            }
        }

        function switchMessageTab(tab) {
            const safeTab = tab === 'read' ? 'read' : 'unread';
            currentMessageTab = safeTab;

            document.querySelectorAll('.message-tab-btn').forEach((btn) => {
                btn.classList.toggle('active', btn.dataset.tab === safeTab);
            });

            loadMessages(safeTab);
        }

        function getCurrentMessageTab() {
            return currentMessageTab;
        }

        function openMessagesTabFromAction() {
            if (panelOpen) {
                switchTab('messages');
            } else {
                openSmartPanel('messages');
            }
        }

        function toggleAvatarSelection() {
            if (!avatarSelectionElement || !avatarChangeBtn) {
                return;
            }

            avatarSelectionVisible = !avatarSelectionVisible;

            if (avatarSelectionVisible) {
                avatarSelectionElement.style.display = 'block';
                requestAnimationFrame(() => {
                    avatarSelectionElement.classList.add('show');
                });
                avatarChangeBtn.textContent = '✖ Auswahl schließen';
                avatarChangeBtn.setAttribute('aria-expanded', 'true');
            } else {
                avatarSelectionElement.classList.remove('show');
                avatarSelectionElement.classList.add('hiding');
                setTimeout(() => {
                    avatarSelectionElement.style.display = 'none';
                    avatarSelectionElement.classList.remove('hiding');
                }, 300);
                avatarChangeBtn.textContent = '🎭 Avatar ändern';
                avatarChangeBtn.setAttribute('aria-expanded', 'false');
            }
        }

        function selectAvatar(style, element) {
            if (!element || !style) {
                return;
            }

            if (style === currentAvatarStyle && hasChosenAvatar) {
                showToast('Dieser Avatar ist bereits aktiv.');
                return;
            }

            document.querySelectorAll('.avatar-option').forEach((opt) => opt.classList.remove('selected'));
            element.classList.add('selected');

            updateAvatarAPI(style, currentAvatarSeed)
                .then((result) => {
                    if (result.success) {
                        currentAvatarStyle = result.style || style;
                        if (result.seed) {
                            currentAvatarSeed = result.seed;
                        }
                        hasChosenAvatar = true;
                        const updatedUrl = result.avatar_url || `https://api.dicebear.com/9.x/${encodeURIComponent(currentAvatarStyle || defaultAvatarStyle)}/svg?seed=${encodeURIComponent(currentAvatarSeed)}`;
                        refreshAvatarGrid(currentAvatarSeed);
                        updateAllAvatarImages(updatedUrl);
                        showToast('Avatar erfolgreich geändert!');
                    } else {
                        element.classList.remove('selected');
                        refreshAvatarGrid(currentAvatarSeed);
                        showToast('Fehler beim Ändern des Avatars', 'error');
                    }
                })
                .catch((error) => {
                    element.classList.remove('selected');
                    refreshAvatarGrid(currentAvatarSeed);
                    showToast('Verbindungsfehler beim Avatar-Update', 'error');
                    console.error('Avatar update failed:', error);
                });
        }

        function generateNewAvatarSet() {
            const button = document.querySelector('.avatar-generate-btn');
            if (!button) {
                return;
            }

            button.disabled = true;
            button.textContent = '🎲 Generiere...';

            const newSeed = 'set-' + Math.random().toString(36).substr(2, 9);
            const styleForSeed = currentAvatarStyle || defaultAvatarStyle;

            updateAvatarAPI(styleForSeed, newSeed)
                .then((result) => {
                    if (result.success) {
                        currentAvatarStyle = result.style || styleForSeed;
                        currentAvatarSeed = result.seed || newSeed;
                        hasChosenAvatar = true;
                        refreshAvatarGrid(currentAvatarSeed);
                        const updatedUrl = result.avatar_url || `https://api.dicebear.com/9.x/${encodeURIComponent(currentAvatarStyle || defaultAvatarStyle)}/svg?seed=${encodeURIComponent(currentAvatarSeed)}`;
                        updateAllAvatarImages(updatedUrl);
                        showToast('Neues Avatar-Set generiert!');
                    } else {
                        showToast('Fehler beim Generieren neuer Avatare', 'error');
                    }
                })
                .catch((error) => {
                    showToast('Verbindungsfehler beim Generieren', 'error');
                    console.error('Avatar generation failed:', error);
                })
                .finally(() => {
                    button.disabled = false;
                    button.textContent = '🎲 Neues Set würfeln';
                });
        }

        async function updateAvatarAPI(style, seed) {
            const payloadStyle = style && String(style).trim() ? style : defaultAvatarStyle;
            const payloadSeed = seed && String(seed).trim() ? seed : currentAvatarSeed;

            const response = await fetch('../api/update-avatar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    style: payloadStyle,
                    seed: payloadSeed
                })
            });

            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }

            return await response.json();
        }

        function updateAllAvatarImages(avatarUrl) {
            const headerAvatarImg = document.querySelector('.user-avatar img');
            if (headerAvatarImg) {
                headerAvatarImg.src = avatarUrl;
            }

            const avatarWrapper = document.querySelector('.user-avatar.clickable');
            if (avatarWrapper) {
                avatarWrapper.dataset.hasChosen = 'true';
                const hint = avatarWrapper.querySelector('.avatar-selection-hint');
                if (hint) {
                    hint.remove();
                }

                if (avatarWrapper.parentElement) {
                    const tooltip = avatarWrapper.parentElement.querySelector('.avatar-tooltip');
                    if (tooltip) {
                        tooltip.remove();
                    }
                }

                hasChosenAvatar = true;
            }

            const profileAvatar = document.querySelector('.profile-avatar img');
            if (profileAvatar) {
                profileAvatar.src = avatarUrl;
            }
        }

        function refreshAvatarGrid(newSeed) {
            if (!avatarGridElement) {
                return;
            }

            availableAvatarStyles.forEach((style) => {
                const option = avatarGridElement.querySelector(`.avatar-option[data-style="${style}"]`);
                if (option) {
                    option.classList.toggle('selected', style === currentAvatarStyle);
                    const img = option.querySelector('img');
                    if (img) {
                        const newUrl = `https://api.dicebear.com/9.x/${encodeURIComponent(style)}/svg?seed=${encodeURIComponent(newSeed)}`;
                        img.src = newUrl;
                    }
                }
            });
        }

        function loadMessages(tab = 'unread') {
            const safeTab = tab === 'read' ? 'read' : 'unread';
            const content = document.getElementById('messagesContentArea');
            if (!content) {
                return;
            }

            content.innerHTML = '<div class="loading">Nachrichten werden geladen...</div>';

            const queryTab = safeTab === 'read' ? 'read' : 'unread';

            fetch(`?ajax=1&tab=${encodeURIComponent(queryTab)}`)
                .then((response) => response.json())
                .then((data) => {
                    updateUnreadCount(typeof data.unread_count === 'number' ? data.unread_count : 0);

                    if (!Array.isArray(data.messages) || data.messages.length === 0) {
                        content.innerHTML = `<div style="text-align: center; color: #6b7280; padding: 2rem;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">📭</div>
                            <p>Keine ${queryTab === 'unread' ? 'ungelesenen' : 'gelesenen'} Nachrichten</p>
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
                        const isRead = queryTab === 'read';

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

            if (userAvatar) {
                userAvatar.dataset.unread = count;
            }

            if (tabBadge) {
                if (count > 0) {
                    tabBadge.style.display = 'inline';
                    tabBadge.textContent = count > 99 ? '99+' : count;
                } else {
                    tabBadge.style.display = 'none';
                }
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

        function openContactModal() {
            const modal = document.getElementById('contactModal');
            if (!modal) {
                return;
            }

            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            const category = document.getElementById('contactCategory');
            if (category) {
                category.focus();
            }
        }

        function closeContactModal() {
            const modal = document.getElementById('contactModal');
            if (!modal) {
                return;
            }

            modal.classList.remove('active');
            document.body.style.overflow = '';

            const form = document.getElementById('contactForm');
            if (form) {
                form.reset();
            }

            const counter = document.getElementById('charCount');
            if (counter) {
                counter.textContent = '0';
                counter.style.color = 'var(--gray-medium)';
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (avatarSelectionElement) {
                avatarSelectionElement.classList.remove('show', 'hiding');
                avatarSelectionElement.style.display = 'none';
            }

            if (avatarChangeBtn) {
                avatarChangeBtn.setAttribute('aria-expanded', 'false');
            }

            const notificationBadge = document.getElementById('messageNotificationBadge');
            if (notificationBadge && notificationBadge.style.display !== 'none') {
                notificationBadge.style.visibility = 'visible';
                notificationBadge.style.opacity = '1';
            }

            updateUnreadCount(<?= (int) $initialUnreadCount ?>);

            const contactMessage = document.getElementById('contactMessage');
            if (contactMessage) {
                contactMessage.addEventListener('input', function() {
                    const count = this.value.length;
                    const counter = document.getElementById('charCount');
                    if (counter) {
                        counter.textContent = count;
                        counter.style.color = count > 1800 ? '#ff6b6b' : 'var(--gray-medium)';
                    }
                });
            }

            const contactForm = document.getElementById('contactForm');
            if (contactForm) {
                contactForm.addEventListener('submit', async function(e) {
                    e.preventDefault();

                    const submitBtn = this.querySelector('button[type="submit"]');
                    const btnText = document.getElementById('sendBtnText');
                    const btnLoader = document.getElementById('sendBtnLoader');

                    if (submitBtn) {
                        submitBtn.disabled = true;
                    }
                    if (btnText) {
                        btnText.style.display = 'none';
                    }
                    if (btnLoader) {
                        btnLoader.style.display = 'inline';
                    }

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
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                        if (btnText) {
                            btnText.style.display = 'inline';
                        }
                        if (btnLoader) {
                            btnLoader.style.display = 'none';
                        }
                    }
                });
            }

            const contactModal = document.getElementById('contactModal');
            if (contactModal) {
                contactModal.addEventListener('click', function(e) {
                    if (e.target === contactModal) {
                        closeContactModal();
                    }
                });
            }

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

            document.querySelectorAll('.quick-actions, .action-card').forEach((element) => {
                element.style.opacity = '0';
                element.style.transform = 'translateY(20px)';
                element.style.transition = 'all 0.6s ease-out';
                observer.observe(element);
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

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (panelOpen) {
                    closeSmartPanel();
                }
                const contactModal = document.getElementById('contactModal');
                if (contactModal && contactModal.classList.contains('active')) {
                    closeContactModal();
                }
            }
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
