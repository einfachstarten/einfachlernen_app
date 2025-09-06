<?php
require __DIR__.'/auth.php';

// Customer session timeout: 4 hours
if(isset($_SESSION['customer_last_activity']) && (time() - $_SESSION['customer_last_activity'] > 14400)){
    destroy_customer_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
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

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    destroy_customer_session();
    session_destroy();
    header('Location: ../login.php?message=' . urlencode('Erfolgreich abgemeldet'));
    exit;
}

$customer = require_customer_login();

if(!empty($_SESSION['customer'])) {
    // Update last activity timestamp
    $_SESSION['customer_last_activity'] = time();

    // Refresh customer data from database
    $pdo = getPDO();
    $customer_id = $_SESSION['customer']['id'];
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $current_customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if($current_customer) {
        $_SESSION['customer'] = $current_customer;
        $customer = $current_customer;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo SITE_NAME; ?>">
    <link rel="apple-touch-icon" href="../icons/icon-192x192.png">
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

        .header-actions {
            margin-left: auto;
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .install-btn {
            background: transparent;
            border: 2px solid #2563eb;
            color: #2563eb;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .install-btn:hover {
            background: #2563eb;
            color: white;
        }

        .logout-btn {
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        /* Optimize for 4 cards */
        @media (min-width: 768px) {
            .action-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .action-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (max-width: 767px) {
            .action-grid {
                grid-template-columns: 1fr;
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

        .action-content p {
            margin: 0.25rem 0 0 0;
            font-size: 0.85rem;
            color: var(--gray-medium);
        }

        .main-features {
            margin-top: 2rem;
        }

        .feature-placeholder {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .placeholder-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px var(--shadow);
            border: 1px solid #f0f0f0;
            text-align: center;
            position: relative;
            opacity: 0.7;
            transition: all 0.3s ease;
        }

        .placeholder-card:hover {
            opacity: 0.8;
            transform: translateY(-2px);
        }

        .placeholder-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 1rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            opacity: 0.6;
        }

        .placeholder-card h3 {
            margin: 0 0 0.5rem 0;
            color: var(--gray-dark);
            font-size: 1.1rem;
        }

        .placeholder-card p {
            margin: 0 0 1rem 0;
            color: var(--gray-medium);
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .coming-soon {
            display: inline-block;
            background: #e3f2fd;
            color: var(--primary);
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
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
            padding: 1.5rem;
            text-align: center;
            background: var(--gray-light);
            border-top: 1px solid #e0e0e0;
        }

        .app-footer p {
            color: var(--gray-medium);
            font-size: 0.8rem;
            margin: 0;
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
            .logout-btn,
            .install-btn {
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

            .header-actions {
                width: 100%;
            }

            .logout-btn,
            .install-btn {
                flex: 1;
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

            .feature-placeholder {
                grid-template-columns: 1fr;
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
    </style>
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <div class="header-content">
                <div class="user-avatar clickable" onclick="toggleUserModal()">👤</div>
                <div class="user-info">
                    <h1>Willkommen, <?= htmlspecialchars($customer['first_name']) ?>!</h1>
                    <p>Ihr persönlicher Lernbereich</p>
                </div>
                <div class="header-actions">
                    <button id="install-app-btn" class="install-btn" style="display: none;">📱 App installieren</button>
                    <a href="?logout=1" class="logout-btn" onclick="return confirm('Möchten Sie sich wirklich abmelden?')">
                        <span>🚪</span> Abmelden
                    </a>
                </div>
            </div>
        </header>

        <main class="app-content">
            <section class="welcome-section">
                <h2>🎯 Herzlich willkommen in Ihrem Lernbereich</h2>
                <p>Hier finden Sie alle wichtigen Funktionen für Ihr Lerncoaching bei Anna Braun.</p>
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

                    <!-- 3. Nachricht senden -->
                    <a href="mailto:annabraun@outlook.com?subject=Nachricht%20von%20<?= urlencode($customer['first_name'] . ' ' . $customer['last_name']) ?>&body=Hallo%20Anna,%0A%0A" class="action-card">
                        <div class="action-icon">💬</div>
                        <div class="action-content">
                            <h3>Nachricht senden</h3>
                            <p>Direkte E-Mail an Anna Braun</p>
                        </div>
                    </a>

                    <!-- 4. Anrufen -->
                    <a href="tel:+436606440636" class="action-card">
                        <div class="action-icon">📞</div>
                        <div class="action-content">
                            <h3>Anrufen</h3>
                            <p>Direkt mit Anna sprechen</p>
                        </div>
                    </a>
                </div>
            </section>

            <section class="main-features">
                <h2 class="section-title">
                    <span>📚</span> Ihre Lernreise
                </h2>

                <div class="feature-placeholder">
                    <div class="placeholder-card">
                        <div class="placeholder-icon">🎯</div>
                        <h3>Lernfortschritt</h3>
                        <p>Verfolgen Sie Ihren Lernfortschritt und erreichte Meilensteine.</p>
                        <span class="coming-soon">Bald verfügbar</span>
                    </div>

                    <div class="placeholder-card">
                        <div class="placeholder-icon">📝</div>
                        <h3>Aufgaben</h3>
                        <p>Bearbeiten Sie Ihre individuellen Lernaufgaben und Übungen.</p>
                        <span class="coming-soon">Bald verfügbar</span>
                    </div>

                    <div class="placeholder-card">
                        <div class="placeholder-icon">📊</div>
                        <h3>Berichte</h3>
                        <p>Detaillierte Berichte über Ihre Lernerfolge und Entwicklung.</p>
                        <span class="coming-soon">Bald verfügbar</span>
                    </div>
                </div>
            </section>
        </main>

        <footer class="app-footer">
            <p>&copy; <?= date('Y') ?> Anna Braun Lerncoaching - Ihr Partner für ganzheitliche Lernunterstützung</p>
        </footer>
    </div>

    <!-- User Info Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-title">
                    <div class="modal-avatar">👤</div>
                    <div>
                        <h2>Ihre Kontoinformationen</h2>
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

    <script>
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
    // Register service worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('../sw.js')
                .then(reg => console.log('SW registered: ', reg))
                .catch(err => console.log('SW registration failed: ', err));
        });
    }

    let deferredPrompt;
    const installBtn = document.getElementById('install-app-btn');

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        if (installBtn) {
            installBtn.style.display = 'block';
        }
    });

    installBtn?.addEventListener('click', async () => {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted') {
                installBtn.style.display = 'none';
            }
            deferredPrompt = null;
        }
    });

    window.addEventListener('appinstalled', () => {
        if (installBtn) {
            installBtn.style.display = 'none';
        }
    });
    </script>
</body>
</html>
