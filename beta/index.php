<?php
// beta/index.php - Enhanced Beta Customer App with Live Messaging
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

function getPDO(): PDO
{
    $config = require __DIR__ . '/../admin/config.php';

    try {
        return new PDO(
            "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        die('DB connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
}

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

try {
    $pdo = getPDO();
    $pdo->exec("CREATE TABLE IF NOT EXISTS beta_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_admin BOOLEAN DEFAULT TRUE,
        to_customer_email VARCHAR(100) NOT NULL,
        message_text TEXT NOT NULL,
        message_type ENUM('info', 'success', 'warning', 'question') DEFAULT 'info',
        expects_response TINYINT(1) DEFAULT 0,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer_email (to_customer_email),
        INDEX idx_unread (is_read, to_customer_email),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    try { $pdo->exec("ALTER TABLE beta_messages ADD COLUMN expects_response TINYINT(1) DEFAULT 0"); } catch (PDOException $e) { if ($e->getCode() !== '42S21') { throw $e; } }
    $pdo->exec("CREATE TABLE IF NOT EXISTS beta_responses (id INT AUTO_INCREMENT PRIMARY KEY, message_id INT NOT NULL UNIQUE, response ENUM('yes','no') NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (message_id) REFERENCES beta_messages(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {
    die('Beta table creation failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

if (empty($_SESSION['customer_id']) || empty($_SESSION['session_authenticated'])) {
    header('Location: ../login.php?message=' . urlencode('Bitte zuerst einloggen für Beta-Zugang'));
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$_SESSION['customer_id']]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    session_destroy();
    die('Customer not found. Please login again.');
}

if (empty($customer['beta_access'])) {
    echo '<!DOCTYPE html>'
        . '<html><head><meta charset="UTF-8"><title>Beta Access</title></head>'
        . '<body style="font-family:Arial;text-align:center;padding:3rem;background:#f8fafc">'
        . '<h1>🧪 Beta Access</h1>'
        . '<p>Diese Beta-App ist nur für autorisierte Testuser zugänglich.</p>'
        . '<p>Kontaktiere den Administrator für Beta-Zugang.</p>'
        . '<p><a href="../customer/index.php">← Zur normalen Customer App</a></p>'
        . '</body></html>';
    exit;
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

if (!empty($_POST['respond'])) {
    header('Content-Type: application/json');
    $messageId = (int)($_POST['message_id'] ?? 0); $choice = $_POST['response'] ?? '';
    if ($messageId && in_array($choice, ['yes', 'no'], true)) {
        $check = $pdo->prepare('SELECT expects_response FROM beta_messages WHERE id = ? AND to_customer_email = ?');
        $check->execute([$messageId, $customer['email']]);
        $message = $check->fetch(PDO::FETCH_ASSOC);
        if ($message && !empty($message['expects_response'])) {
            $pdo->prepare('INSERT INTO beta_responses (message_id, response) VALUES (?, ?) ON DUPLICATE KEY UPDATE response = VALUES(response), created_at = CURRENT_TIMESTAMP')->execute([$messageId, $choice]);
            $pdo->prepare('UPDATE beta_messages SET is_read = 1 WHERE id = ? AND to_customer_email = ?')->execute([$messageId, $customer['email']]);
            echo json_encode(['success' => true]); exit;
        }
    }
    echo json_encode(['success' => false]); exit;
}

if (!empty($_POST['mark_read'])) {
    $messageId = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;

    if ($messageId > 0) {
        $stmt = $pdo->prepare('UPDATE beta_messages SET is_read = 1 WHERE id = ? AND to_customer_email = ?');
        $stmt->execute([$messageId, $customer['email']]);
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

if (!empty($_GET['ajax'])) {
    $tab = ($_GET['tab'] ?? '') === 'read' ? 1 : 0;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM beta_messages WHERE to_customer_email = ? AND is_read = 0');
    $stmt->execute([$customer['email']]);
    $unreadCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT m.id, m.message_text, m.message_type, m.created_at, m.expects_response, r.response FROM beta_messages m LEFT JOIN beta_responses r ON r.message_id = m.id WHERE m.to_customer_email = ? AND m.is_read = ? ORDER BY m.created_at DESC LIMIT 15');
    $stmt->execute([$customer['email'], $tab]);

    $messages = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $messages[] = [
            'id' => (int) $row['id'],
            'message_text' => htmlspecialchars($row['message_text'], ENT_QUOTES, 'UTF-8'),
            'message_type' => htmlspecialchars($row['message_type'], ENT_QUOTES, 'UTF-8'),
            'created_at' => $row['created_at'],
            'expects_response' => !empty($row['expects_response']),
            'user_response' => $row['response'] ? htmlspecialchars($row['response'], ENT_QUOTES, 'UTF-8') : null,
        ];
    }

    header('Content-Type: application/json');
    echo json_encode(['unread_count' => $unreadCount, 'messages' => $messages]);
    exit;
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM beta_messages WHERE to_customer_email = ? AND is_read = 0');
$stmt->execute([$customer['email']]);
$initialUnreadCount = (int) $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4a90b8">
    <title>🧪 Beta Dashboard - Anna Braun Lerncoaching</title>
    <style>
        :root {
            --primary: #4a90b8;
            --secondary: #52b3a4;
            --accent-teal: #26a69a;
            --light-blue: #e3f2fd;
            --white: #ffffff;
            --gray-light: #f8f9fa;
            --gray-medium: #6c757d;
            --gray-dark: #343a40;
            --shadow: rgba(0, 0, 0, 0.1);
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
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
            display: inline-block;
            padding: 8px;
            margin: -8px;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.25);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.35);
            position: relative;
            overflow: visible;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            max-width: 100%;
            max-height: 100%;
            display: block;
        }

        .user-avatar.clickable {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .user-avatar.clickable:hover {
            transform: scale(1.05);
            border-color: rgba(255, 255, 255, 0.6);
            box-shadow: 0 4px 15px rgba(255, 255, 255, 0.2);
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
            z-index: 25;
        }

        .avatar-tooltip {
            position: absolute;
            top: 78px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 30;
        }

        .user-avatar-wrapper:hover .avatar-tooltip {
            opacity: 1;
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: linear-gradient(135deg, #dc2626, #ef4444);
            color: white;
            border-radius: 999px;
            min-width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
            border: 2px solid white;
            z-index: 20;
            box-shadow: 0 2px 8px rgba(255, 68, 68, 0.3);
            padding: 0 4px;
            white-space: nowrap;
            transform: translateZ(0);
            visibility: hidden;
            opacity: 0;
            transition: transform 0.2s ease, opacity 0.2s ease;
        }

        .user-avatar:hover .notification-badge {
            transform: scale(1.1) translateZ(0);
        }

        .notification-badge[data-count="99+"] {
            font-size: 0.65rem;
            min-width: 24px;
            padding: 0 2px;
        }

        @media (prefers-high-contrast: active) {
            .notification-badge {
                background: red;
                border: 2px solid white;
                outline: 1px solid black;
            }
        }

        .user-avatar:focus-within .notification-badge {
            outline: 2px solid rgba(255, 255, 255, 0.8);
            outline-offset: 2px;
        }

        .notification-toast {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
        }

        .notification-toast.success {
            background: linear-gradient(135deg, #28a745, #20c997) !important;
        }

        .notification-toast.error {
            background: linear-gradient(135deg, #dc3545, #e74c3c) !important;
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

        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
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

        .action-content h3 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-dark);
        }

        .action-content p {
            margin: 0.25rem 0 0 0;
            font-size: 0.85rem;
            color: var(--gray-medium);
        }

        @media (max-width: 768px) {
            .user-avatar-wrapper {
                padding: 6px;
                margin: -6px;
            }

            .notification-badge {
                top: -6px;
                right: -6px;
                min-width: 20px;
                height: 20px;
                font-size: 0.65rem;
            }

            .avatar-tooltip {
                display: none;
            }

            .action-grid {
                grid-template-columns: 1fr;
            }

            .app-content {
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
        }

        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .notification-badge {
                border-width: 1px;
            }
        }

        /* Smart Panel Styles */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
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
            right: -420px;
            width: 420px;
            height: 100vh;
            background: white;
            box-shadow: -5px 0 25px rgba(0,0,0,0.15);
            transition: right 0.3s ease;
            z-index: 1001;
            display: flex;
            flex-direction: column;
        }

        .smart-panel.active {
            right: 0;
        }

        .smart-panel-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .smart-panel-header h3 {
            margin: 0;
            font-size: 1.1rem;
        }

        .close-panel {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 36px;
            height: 36px;
            border-radius: 50%;
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

        /* Profile Section Styles */
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

        .avatar-selection {
            margin-top: 2rem;
            padding: 1.25rem;
            background: #f8fafc;
            border-radius: 12px;
            display: none;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            max-height: none;
            overflow: visible;
        }

        .avatar-selection.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .avatar-selection.hiding {
            opacity: 0;
            transform: translateY(-10px);
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

        .avatar-change-link:active {
            transform: translateY(0);
        }

        .avatar-change-link:focus {
            outline: 3px solid var(--secondary);
            outline-offset: 2px;
        }

        .avatar-change-link.loading {
            opacity: 0.7;
            cursor: not-allowed;
            pointer-events: none;
        }

        .avatar-change-link.loading::after {
            content: '⏳';
            margin-left: 0.5rem;
        }

        @media (max-width: 768px) {
            .avatar-change-link {
                font-size: 0.75rem;
                padding: 0.3rem 0.6rem;
            }

            .avatar-selection {
                padding: 1rem;
                margin-top: 1rem;
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
            transition: transform 0.15s ease;
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
            pointer-events: auto;
            user-select: none;
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

        .avatar-option:focus-visible,
        .avatar-option:focus {
            outline: 3px solid var(--secondary);
            outline-offset: 2px;
        }

        .avatar-option img {
            width: 56px;
            height: 56px;
            border-radius: 50%;
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

        .avatar-grid .avatar-option img {
            transition: all 0.3s ease;
        }

        .avatar-grid .avatar-option img.updating {
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
        }

        .avatar-generate-btn:hover {
            background: #3d9b91;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(82, 179, 164, 0.3);
        }

        .avatar-generate-btn:focus-visible {
            outline: 3px solid rgba(82, 179, 164, 0.6);
            outline-offset: 2px;
        }

        .avatar-generate-btn:active {
            transform: translateY(0);
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
            color: var(--gray-dark);
            font-weight: 500;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            background: #e5f4ff;
            color: var(--primary);
        }

        .status-badge.active {
            background: #dcfce7;
            color: #166534;
        }

        .status-badge.beta {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .message-tabs {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
        }

        .message-tabs button {
            flex: 1;
            padding: 0.75rem;
            border: none;
            background: transparent;
            font-weight: 600;
            color: var(--gray-medium);
            cursor: pointer;
            border-bottom: 2px solid transparent;
        }

        .message-tabs button.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .messages-container {
            padding: 1rem;
        }

        .message-item {
            border-left: 4px solid var(--primary);
            background: #f8f9fa;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0 8px 8px 0;
            transition: all 0.3s ease;
        }

        .message-item.success { border-left-color: var(--success); }
        .message-item.warning { border-left-color: var(--warning); }
        .message-item.question { border-left-color: #6f42c1; }

        .message-meta {
            font-size: 0.875rem;
            color: var(--gray-medium);
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .message-text {
            color: var(--gray-dark);
            line-height: 1.6;
            margin-bottom: 0.75rem;
        }

        .mark-read-btn {
            background: var(--success);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .mark-read-btn:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        /* Action Grid Enhanced Styles */
        .action-icon.booking { background: linear-gradient(135deg, #42a5f5, #1e88e5); }
        .action-icon.appointments { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
        .action-icon.contact { background: linear-gradient(135deg, #66bb6a, #43a047); }
        .action-icon.normal { background: linear-gradient(135deg, #9c27b0, #673ab7); }
        .action-icon.future { background: linear-gradient(135deg, #667eea, #764ba2); }

        .beta-link {
            border: 2px solid var(--primary);
        }

        .coming-soon {
            border: 2px dashed #667eea;
            opacity: 0.7;
            cursor: default;
        }

        .coming-soon:hover {
            transform: none;
        }

        /* Contact Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-container {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow: hidden;
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal-container {
            transform: scale(1);
        }

        .modal-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .modal-icon {
            font-size: 1.5rem;
        }

        .modal-title h3 {
            margin: 0 0 0.25rem 0;
            font-size: 1.2rem;
        }

        .modal-title p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .modal-close {
            margin-left: auto;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2rem;
        }

        .modal-content {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.9rem;
        }

        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-family: inherit;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-select:focus,
        .form-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 144, 184, 0.1);
        }

        .form-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .btn-secondary,
        .btn-primary {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-secondary {
            background: #f3f4f6;
            color: var(--gray-dark);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .smart-panel {
                width: 100%;
                right: -100%;
            }

            .modal-container {
                width: 95%;
                margin: 0 2.5%;
            }

            .modal-content {
                padding: 1rem;
            }

            .form-footer {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <div class="app-header">
            <div class="header-content">
                <div class="user-avatar-wrapper">
                    <div class="user-avatar clickable" onclick="toggleSmartPanel()" data-unread="<?= (int) $initialUnreadCount ?>" data-avatar-style="<?= htmlspecialchars($avatar_style, ENT_QUOTES, 'UTF-8') ?>" data-avatar-seed="<?= htmlspecialchars($avatar_seed, ENT_QUOTES, 'UTF-8') ?>" data-has-chosen="<?= $hasChosenAvatar ? 'true' : 'false' ?>">
                        <img src="<?= htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8') ?>"
                             alt="Avatar">
                        <?php if (!$hasChosenAvatar): ?>
                            <div class="avatar-selection-hint">🎭</div>
                        <?php endif; ?>
                        <div
                            class="notification-badge"
                            id="notificationBadge"
                            data-count="<?= $initialUnreadCount > 99 ? '99+' : $initialUnreadCount ?>"
                            style="<?= $initialUnreadCount > 0 ? 'display: flex; visibility: visible; opacity: 1;' : 'display: none;' ?>">
                            <?= $initialUnreadCount > 99 ? '99+' : $initialUnreadCount; ?>
                        </div>
                    </div>
                    <?php if (!$hasChosenAvatar): ?>
                        <div class="avatar-tooltip">Klicke um deinen Avatar zu wählen</div>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <h1>🧪 Beta: <?= htmlspecialchars($customer['first_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p>Testing neue Features vor Production</p>
                </div>
                <a href="../login.php?logout=1" class="logout-btn">
                    🚪 Abmelden
                </a>
            </div>
        </div>

        <div class="app-content">
            <div class="welcome-section">
                <h2>Willkommen in der Beta-Umgebung!</h2>
                <p>Du testest neue Features bevor sie für alle verfügbar sind. Klicke auf dein Profilbild um Nachrichten zu sehen.</p>
            </div>

            <div class="action-grid">
                <a href="../customer/termine-suchen.php" class="action-card">
                    <div class="action-icon booking">📅</div>
                    <div class="action-content">
                        <h3>Termine buchen</h3>
                        <p>Verfügbare Termine finden und buchen</p>
                    </div>
                </a>

                <a href="../customer/termine.php" class="action-card">
                    <div class="action-icon appointments">📋</div>
                    <div class="action-content">
                        <h3>Meine Termine</h3>
                        <p>Gebuchte Termine verwalten</p>
                    </div>
                </a>

                <div class="action-card" onclick="openContactModal()">
                    <div class="action-icon contact">💬</div>
                    <div class="action-content">
                        <h3>Kontakt aufnehmen</h3>
                        <p>Fragen und Nachrichten senden</p>
                    </div>
                </div>

                <a href="last_minute_settings.php" class="action-card">
                    <div class="action-icon alerts">🚨</div>
                    <div class="action-content">
                        <h3>Last-Minute Slots</h3>
                        <p>Automatische Benachrichtigungen für Kurzentschlossene</p>
                    </div>
                </a>

                <a href="../customer/index.php?force_normal=1" class="action-card beta-link">
                    <div class="action-icon normal">🏠</div>
                    <div class="action-content">
                        <h3>Normal Dashboard</h3>
                        <p>Zur Standard Customer App</p>
                    </div>
                </a>

                <div class="action-card coming-soon">
                    <div class="action-icon future">🚀</div>
                    <div class="action-content">
                        <h3>Mehr Beta Features</h3>
                        <p>Push Notifications, Dark Mode, etc.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
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

                    <div class="detail-group">
                        <div class="detail-label">Beta-Status</div>
                        <div class="detail-value">
                            <span class="status-badge beta">🧪 Beta-Tester</span>
                        </div>
                    </div>
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
                        $avatarPreviewSeed = $avatar_seed !== '' ? $avatar_seed : ($customer['email'] ?? 'beta-user');
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
                                    onclick="selectAvatar('<?= htmlspecialchars($style, ENT_QUOTES, 'UTF-8') ?>')"
                                    title="<?= htmlspecialchars($styleLabel, ENT_QUOTES, 'UTF-8') ?> Avatar-Style">
                                <img src="<?= htmlspecialchars($styleUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($styleLabel, ENT_QUOTES, 'UTF-8') ?>">
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="avatar-generate-btn" onclick="generateNewSeed()">
                        🎲 Neues Set würfeln
                    </button>
                </div>
            </div>
        </div>

        <div class="tab-content" id="messagesContent">
            <div class="message-tabs">
                <button type="button" class="active" data-tab="new" onclick="loadMessages('new')">Neu</button>
                <button type="button" data-tab="read" onclick="loadMessages('read')">Gelesen</button>
            </div>
            <div class="messages-container" id="messagesContainer"></div>
        </div>
    </div>

    <div class="modal-overlay" id="contactModal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-icon">💬</div>
                <div class="modal-title">
                    <h3>Kontakt aufnehmen</h3>
                    <p>Stelle deine Frage oder sende Feedback</p>
                </div>
                <button class="modal-close" type="button" onclick="closeContactModal()">×</button>
            </div>

            <div class="modal-content">
                <form id="contactForm" onsubmit="submitContactForm(event)">
                    <div class="form-group">
                        <label class="form-label" for="contactCategory">Kategorie</label>
                        <select id="contactCategory" name="category" class="form-select" required>
                            <option value="">Bitte wählen...</option>
                            <option value="lerncoaching">💡 Lerncoaching</option>
                            <option value="app">📱 App &amp; Beta-Features</option>
                            <option value="sonstiges">💬 Sonstiges</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="contactMessage">Nachricht</label>
                        <textarea id="contactMessage" name="message" class="form-textarea" rows="5" placeholder="Schreibe deine Nachricht hier..." required></textarea>
                    </div>

                    <div class="form-footer">
                        <button type="button" class="btn-secondary" onclick="closeContactModal()">Abbrechen</button>
                        <button type="submit" class="btn-primary">📤 Nachricht senden</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const smartPanel = document.getElementById('smartPanel');
        const overlay = document.getElementById('overlay');
        const notificationBadge = document.getElementById('notificationBadge');
        const tabBadge = document.getElementById('tabBadge');
        const messagesContainer = document.getElementById('messagesContainer');
        const panelTabButtons = document.querySelectorAll('.panel-tabs .tab-btn');
        const messageFilterButtons = document.querySelectorAll('.message-tabs button');
        const panelTitle = document.getElementById('panelTitle');
        const userAvatar = document.querySelector('.user-avatar.clickable');
        const dicebearBaseUrl = 'https://api.dicebear.com/9.x';
        const avatarSelectionElement = document.querySelector('.avatar-selection');
        const avatarChangeBtn = document.getElementById('avatarChangeBtn');
        const avatarGridElement = document.querySelector('.avatar-grid');
        let avatarSelectionVisible = false;
        let currentAvatarStyle = <?= json_encode($avatar_style) ?>;
        let currentAvatarSeed = <?= json_encode($avatar_seed !== '' ? $avatar_seed : ($customer['email'] ?? 'beta-user')) ?>;
        let hasChosenAvatar = <?= $hasChosenAvatar ? 'true' : 'false' ?>;
        const defaultAvatarStyle = <?= json_encode($availableAvatarStyles[0]) ?>;

        if (avatarSelectionElement) {
            avatarSelectionElement.classList.remove('show', 'hiding');
            avatarSelectionElement.style.display = 'none';
        }

        if (avatarChangeBtn) {
            avatarChangeBtn.setAttribute('aria-expanded', 'false');
        }

        let currentPanelTab = 'profile';
        let currentMessageTab = 'new';
        let panelOpen = false;

        function toggleSmartPanel() {
            if (!smartPanel || !overlay) {
                return;
            }

            if (!panelOpen) {
                const unreadCount = parseInt(userAvatar?.dataset.unread || '0', 10);
                if (unreadCount > 0) {
                    switchTab('messages');
                } else {
                    switchTab('profile');
                }

                smartPanel.classList.add('active');
                overlay.classList.add('active');
                panelOpen = true;
            } else {
                closeSmartPanel();
            }
        }

        function closeSmartPanel() {
            if (!smartPanel || !overlay) {
                return;
            }
            smartPanel.classList.remove('active');
            overlay.classList.remove('active');
            panelOpen = false;
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
                panelTitle.innerHTML = currentPanelTab === 'profile' ? '👤 Mein Profil' : '📨 Beta Nachrichten';
            }

            if (currentPanelTab === 'messages') {
                loadMessages(getCurrentMessageTab());
            }
        }

        function getCurrentMessageTab() {
            return currentMessageTab;
        }

        function loadMessages(tab) {
            if (tab) {
                currentMessageTab = tab === 'read' ? 'read' : 'new';
            }

            messageFilterButtons.forEach((btn) => {
                btn.classList.toggle('active', btn.dataset.tab === currentMessageTab);
            });

            fetch(`?ajax=1&tab=${encodeURIComponent(currentMessageTab)}`, { credentials: 'same-origin' })
                .then((response) => response.json())
                .then((data) => {
                    updateUnreadCount(data.unread_count);
                    renderMessages(Array.isArray(data.messages) ? data.messages : []);
                })
                .catch((error) => {
                    console.error('Error loading messages:', error);
                });
        }

        function renderMessages(messages) {
            if (!messagesContainer) {
                return;
            }

            if (!messages.length) {
                const empty = currentMessageTab === 'read' ? '📁 Noch keine gelesenen Nachrichten' : '📭 Keine ungelesenen Nachrichten';
                messagesContainer.innerHTML = `<div style="text-align:center;padding:2rem;color:#6b7280;"><p>${empty}</p><p style="font-size:0.875rem;margin-top:0.5rem;">Der Admin kann dir hier Updates senden</p></div>`;
                return;
            }

            const icons = {
                info: 'ℹ️',
                success: '✅',
                warning: '⚠️',
                question: '❓',
            };

            messagesContainer.innerHTML = messages.map((msg) => {
                const type = msg.message_type || 'info';
                const icon = icons[type] || 'ℹ️';
                const createdAt = new Date(msg.created_at).toLocaleString('de-DE');
                const text = String(msg.message_text || '').replace(/\n/g, '<br>');
                const markReadButton = currentMessageTab === 'new' && !msg.expects_response
                    ? `<button class="mark-read-btn" type="button" onclick="markAsRead(${Number(msg.id)})">✓ Als gelesen markieren</button>`
                    : '';

                const responseSection = msg.expects_response
                    ? (msg.user_response
                        ? `<div style="font-size:0.85rem;color:#6b7280;margin-bottom:0.5rem;">Antwort gesendet: ${msg.user_response === 'yes' ? '✅ Ja' : '❌ Nein'}</div>`
                        : `<div style="display:flex;gap:0.5rem;margin-bottom:0.5rem;"><button type="button" style="flex:1;padding:0.5rem;border-radius:6px;border:1px solid #bbf7d0;background:#e6f4ea;color:#166534;font-weight:600;" onclick="sendResponse(${Number(msg.id)}, 'yes')">Ja</button><button type="button" style="flex:1;padding:0.5rem;border-radius:6px;border:1px solid #fecaca;background:#fee2e2;color:#b91c1c;font-weight:600;" onclick="sendResponse(${Number(msg.id)}, 'no')">Nein</button></div>`)
                    : '';

                return `<div class="message-item ${type}"><div class="message-meta"><span>${icon} ${type.charAt(0).toUpperCase() + type.slice(1)}</span><span>${createdAt}</span></div><div class="message-text">${text}</div>${responseSection}${markReadButton}</div>`;
            }).join('');
        }

        function markAsRead(messageId) {
            if (!messageId) {
                return;
            }

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `mark_read=1&message_id=${encodeURIComponent(messageId)}`,
                credentials: 'same-origin',
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data && data.success) {
                        loadMessages(getCurrentMessageTab());
                    }
                })
                .catch((error) => {
                    console.error('Error marking message as read:', error);
                });
        }

        function sendResponse(messageId, response) {
            if (!messageId || !response) {
                return;
            }

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `respond=1&message_id=${encodeURIComponent(messageId)}&response=${encodeURIComponent(response)}`,
                credentials: 'same-origin',
            })
                .then((res) => res.json())
                .then((data) => {
                    if (data && data.success) {
                        loadMessages(getCurrentMessageTab());
                    }
                })
                .catch((error) => {
                    console.error('Error sending response:', error);
                });
        }

        function updateUnreadCount(count) {
            const parsed = parseInt(count, 10);
            const safeCount = Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
            const displayValue = safeCount > 99 ? '99+' : safeCount;

            if (tabBadge) {
                if (safeCount > 0) {
                    tabBadge.style.display = 'inline';
                    tabBadge.textContent = displayValue;
                } else {
                    tabBadge.style.display = 'none';
                }
            }

            if (notificationBadge) {
                if (safeCount > 0) {
                    notificationBadge.style.display = 'flex';
                    notificationBadge.textContent = displayValue;
                    notificationBadge.setAttribute('data-count', String(displayValue));

                    setTimeout(() => {
                        notificationBadge.style.visibility = 'visible';
                        notificationBadge.style.opacity = '1';
                    }, 10);
                } else {
                    notificationBadge.style.display = 'none';
                    notificationBadge.style.visibility = 'hidden';
                    notificationBadge.style.opacity = '0';
                    notificationBadge.setAttribute('data-count', '0');
                }
            }

            if (userAvatar) {
                userAvatar.dataset.unread = safeCount;
            }
        }

        function toggleAvatarSelection() {
            const avatarSelection = avatarSelectionElement;
            const changeBtn = avatarChangeBtn;

            if (!avatarSelection || !changeBtn) {
                console.error('Avatar selection elements not found');
                return;
            }

            if (avatarSelectionVisible) {
                avatarSelection.classList.remove('show');
                avatarSelection.classList.add('hiding');

                setTimeout(() => {
                    avatarSelection.classList.remove('hiding');
                    avatarSelection.style.display = 'none';
                }, 300);

                changeBtn.innerHTML = '🎭 Avatar ändern';
                changeBtn.title = 'Avatar-Auswahl öffnen';
                changeBtn.setAttribute('aria-expanded', 'false');
                avatarSelectionVisible = false;
            } else {
                avatarSelection.classList.remove('hiding');
                avatarSelection.style.display = 'block';

                requestAnimationFrame(() => {
                    avatarSelection.classList.add('show');
                });

                changeBtn.innerHTML = '❌ Auswahl schließen';
                changeBtn.title = 'Avatar-Auswahl schließen';
                changeBtn.setAttribute('aria-expanded', 'true');
                avatarSelectionVisible = true;

                setTimeout(() => {
                    avatarSelection.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest',
                    });
                }, 150);
            }
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && avatarSelectionVisible) {
                toggleAvatarSelection();
            }
        });

        document.addEventListener('click', (event) => {
            if (!avatarSelectionVisible) {
                return;
            }

            const avatarSelection = avatarSelectionElement;
            const changeBtn = avatarChangeBtn;

            if (!avatarSelection || !changeBtn) {
                return;
            }

            if (avatarSelection.contains(event.target) || changeBtn.contains(event.target)) {
                return;
            }

            setTimeout(() => {
                if (avatarSelectionVisible) {
                    toggleAvatarSelection();
                }
            }, 100);
        });

        function selectAvatar(style) {
            console.log('selectAvatar called with style:', style);
            console.log('currentAvatarStyle:', currentAvatarStyle);

            if (!style) {
                console.error('No style provided to selectAvatar');
                showNotification('❌ Kein Avatar-Stil ausgewählt', 'error');
                return;
            }

            if (style === currentAvatarStyle && hasChosenAvatar) {
                console.log('Style already selected, skipping update');
                return;
            }

            console.log('Updating avatar to style:', style);
            const targetStyle = style || defaultAvatarStyle;

            return updateAvatar(targetStyle, currentAvatarSeed)
                .then(() => {
                    setTimeout(() => {
                        if (avatarSelectionVisible) {
                            toggleAvatarSelection();
                            showNotification('🎨 Neuer Avatar-Style aktiviert!', 'success');
                        }
                    }, 1500);
                })
                .catch((error) => {
                    console.error('Error updating avatar style:', error);
                });
        }

        function generateNewSeed() {
            const newSeed = 'avatar_' + Math.random().toString(36).substring(2, 12);
            console.log('Generating new avatar seed:', newSeed);
            const styleForSeed = currentAvatarStyle || defaultAvatarStyle;

            return updateAvatar(styleForSeed, newSeed)
                .then(() => {
                    showNotification('🎲 Neue Avatare gewürfelt!', 'success');

                    if (avatarGridElement) {
                        avatarGridElement.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            avatarGridElement.style.transform = 'scale(1)';
                        }, 150);
                    }
                })
                .catch((error) => {
                    console.error('Error generating new avatar seed:', error);
                });
        }

        async function updateAvatar(style, seed) {
            console.log('updateAvatar called:', { style, seed });

            const trimmedStyle = style && String(style).trim();
            const effectiveStyle = trimmedStyle || defaultAvatarStyle;

            if (!effectiveStyle) {
                showNotification('❌ Ungültiger Avatar-Stil', 'error');
                return Promise.reject(new Error('Invalid avatar style'));
            }

            const payload = {
                style: effectiveStyle,
                seed: seed && String(seed).trim() ? seed : currentAvatarSeed,
            };

            console.log('Sending payload:', payload);

            const avatarImages = document.querySelectorAll('.avatar-grid .avatar-option img');

            try {
                if (avatarChangeBtn) {
                    avatarChangeBtn.classList.add('loading');
                }

                avatarImages.forEach((img) => img.classList.add('updating'));

                const response = await fetch('../api/update-avatar.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });

                console.log('Response status:', response.status);

                let result = null;
                try {
                    result = await response.json();
                    console.log('Response data:', result);
                } catch (parseError) {
                    console.error('Failed to parse avatar response:', parseError);
                    throw new Error('Invalid JSON response from server');
                }

                if (!response.ok) {
                    const message = result?.error
                        ? `HTTP ${response.status}: ${result.error}`
                        : `HTTP ${response.status}: ${response.statusText}`;
                    throw new Error(message);
                }

                if (result?.success) {
                    const nextStyle = result.style || payload.style;
                    const nextSeed = result.seed || payload.seed;
                    console.log('Avatar update successful:', { nextStyle, nextSeed });
                    updateAvatarDisplay(nextStyle, nextSeed);
                    return result;
                }

                throw new Error(result?.error || 'Avatar konnte nicht gespeichert werden');
            } catch (error) {
                console.error('Avatar update error:', error);

                let errorMessage = '❌ Fehler beim Speichern des Avatars';
                if (error.message.includes('HTTP 404')) {
                    errorMessage = '❌ Avatar-API nicht gefunden';
                } else if (error.message.includes('HTTP 500')) {
                    errorMessage = '❌ Server-Fehler beim Speichern';
                } else if (error.message.includes('Invalid avatar style')) {
                    errorMessage = '❌ Ungültiger Avatar-Stil gewählt';
                } else if (error.message.includes('Invalid JSON')) {
                    errorMessage = '❌ Server-Antwort fehlerhaft';
                }

                showNotification(errorMessage, 'error');
                throw error;
            } finally {
                if (avatarChangeBtn) {
                    avatarChangeBtn.classList.remove('loading');
                }

                avatarImages.forEach((img) => img.classList.remove('updating'));
            }
        }

        function updateAvatarDisplay(style, seed) {
            const appliedStyle = style || defaultAvatarStyle;
            currentAvatarStyle = appliedStyle;
            currentAvatarSeed = seed;
            hasChosenAvatar = true;

            const avatarUrl = buildAvatarUrl(appliedStyle, seed);

            if (userAvatar) {
                userAvatar.dataset.avatarStyle = appliedStyle;
                userAvatar.dataset.avatarSeed = seed;
                userAvatar.dataset.hasChosen = 'true';
                const headerImg = userAvatar.querySelector('img');
                if (headerImg) {
                    headerImg.src = avatarUrl;
                    headerImg.alt = 'Avatar';
                }

                const hint = userAvatar.querySelector('.avatar-selection-hint');
                if (hint) {
                    hint.remove();
                }

                if (userAvatar.parentElement) {
                    const tooltip = userAvatar.parentElement.querySelector('.avatar-tooltip');
                    if (tooltip) {
                        tooltip.remove();
                    }
                }
            }

            const profileAvatarImg = document.querySelector('.profile-avatar img');
            if (profileAvatarImg) {
                profileAvatarImg.src = avatarUrl;
            }

            document.querySelectorAll('.avatar-option').forEach((option) => {
                const optionStyle = option?.dataset?.style;
                option.classList.toggle('selected', optionStyle === style);

                const optionImg = option.querySelector('img');
                if (optionImg && optionStyle) {
                    optionImg.src = buildAvatarUrl(optionStyle, seed);
                }
            });
        }

        function buildAvatarUrl(style, seed) {
            const safeStyle = encodeURIComponent(style || 'avataaars');
            const safeSeed = encodeURIComponent(seed || 'beta-user');
            return `${dicebearBaseUrl}/${safeStyle}/svg?seed=${safeSeed}`;
        }

        function openContactModal() {
            document.getElementById('contactModal')?.classList.add('active');
        }

        function closeContactModal() {
            document.getElementById('contactModal')?.classList.remove('active');
        }

        async function submitContactForm(event) {
            event.preventDefault();

            const form = event.target;
            const formData = new FormData(form);

            try {
                const response = await fetch('../customer/contact_form.php', {
                    method: 'POST',
                    body: formData,
                });

                const result = await response.json();

                if (result?.success) {
                    closeContactModal();
                    form.reset();
                    showNotification('✅ Nachricht erfolgreich gesendet!', 'success');
                } else {
                    showNotification(`❌ Fehler beim Senden: ${result?.message || 'Unbekannter Fehler'}`, 'error');
                }
            } catch (error) {
                console.error('Error submitting contact form:', error);
                showNotification('❌ Netzwerkfehler beim Senden', 'error');
            }
        }

        function showNotification(message, type = 'info') {
            const existing = document.querySelector('.notification-toast');
            if (existing) existing.remove();

            const toast = document.createElement('div');
            toast.className = `notification-toast ${type}`;
            toast.textContent = message;

            Object.assign(toast.style, {
                position: 'fixed',
                bottom: '2rem',
                left: '50%',
                transform: 'translateX(-50%)',
                padding: '0.75rem 1.5rem',
                borderRadius: '12px',
                fontWeight: '600',
                fontSize: '0.9rem',
                zIndex: '9999',
                opacity: '0',
                transition: 'all 0.3s ease',
                maxWidth: '90%',
                textAlign: 'center',
                backdropFilter: 'blur(10px)',
                boxShadow: '0 8px 25px rgba(0, 0, 0, 0.15)'
            });

            if (type === 'success') {
                toast.style.background = 'linear-gradient(135deg, #28a745, #20c997)';
                toast.style.color = 'white';
            } else if (type === 'error') {
                toast.style.background = 'linear-gradient(135deg, #dc3545, #e74c3c)';
                toast.style.color = 'white';
            } else {
                toast.style.background = 'linear-gradient(135deg, var(--primary, #4a90b8), var(--secondary, #52b3a4))';
                toast.style.color = 'white';
            }

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

        document.addEventListener('DOMContentLoaded', () => {
            if (notificationBadge && notificationBadge.style.display !== 'none') {
                notificationBadge.style.visibility = 'visible';
                notificationBadge.style.opacity = '1';
            }
        });

        setInterval(() => {
            if (panelOpen && currentPanelTab === 'messages') {
                loadMessages(getCurrentMessageTab());
            }
        }, 5000);

        updateUnreadCount(<?= (int) $initialUnreadCount ?>);
        loadMessages(getCurrentMessageTab());
    </script>
</body>
</html>
