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
    <meta name="theme-color" content="#4a90b8">
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

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
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
            
            .info-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .action-grid {
                grid-template-columns: 1fr;
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
                <div class="user-avatar">👤</div>
                <div class="user-info">
                    <h1>Willkommen, <?= htmlspecialchars($customer['first_name']) ?>!</h1>
                    <p>Ihr persönlicher Lernbereich</p>
                </div>
                <a href="?logout=1" class="logout-btn" onclick="return confirm('Möchten Sie sich wirklich abmelden?')">
                    <span>🚪</span> Abmelden
                </a>
            </div>
        </header>

        <main class="app-content">
            <section class="welcome-section">
                <h2>🎯 Herzlich willkommen in Ihrem Lernbereich</h2>
                <p>Hier finden Sie alle wichtigen Informationen zu Ihrem Lerncoaching bei Anna Braun.</p>
            </section>

            <section class="info-grid">
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

            <section class="quick-actions">
                <h2 class="section-title">
                    <span>⚡</span> Schnellzugriff
                </h2>
                
                <div class="action-grid">
                    <a href="mailto:info@einfachlernen.jetzt" class="action-card">
                        <div class="action-icon">📧</div>
                        <div class="action-content">
                            <h3>Nachricht senden</h3>
                            <p>Kontakt zu Anna Braun aufnehmen</p>
                        </div>
                    </a>
                    
                    <a href="tel:+4369912345678" class="action-card">
                        <div class="action-icon">📞</div>
                        <div class="action-content">
                            <h3>Anrufen</h3>
                            <p>Direkte telefonische Beratung</p>
                        </div>
                    </a>
                    
                    <a href="https://calendly.com/einfachlernen" target="_blank" class="action-card">
                        <div class="action-icon">📅</div>
                        <div class="action-content">
                            <h3>Termin buchen</h3>
                            <p>Neuen Coaching-Termin vereinbaren</p>
                        </div>
                    </a>
                    
                    <a href="../terme.html?email=<?= urlencode($customer['email']) ?>" class="action-card">
                        <div class="action-icon">📋</div>
                        <div class="action-content">
                            <h3>Meine Termine</h3>
                            <p>Übersicht gebuchter Termine</p>
                        </div>
                    </a>
                </div>
            </section>
        </main>

        <footer class="app-footer">
            <p>&copy; <?= date('Y') ?> Anna Braun Lerncoaching - Ihr Partner für ganzheitliche Lernunterstützung</p>
        </footer>
    </div>

    <script>
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

            document.querySelectorAll('.info-card, .action-card').forEach(card => {
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
</body>
</html>