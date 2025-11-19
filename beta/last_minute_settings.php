<?php
session_start();
require_once __DIR__ . '/../customer/auth.php';

$customer = require_customer_login();

if (empty($customer['beta_access'])) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

$pdo = getPDO();
$services = require __DIR__ . '/../admin/services_catalog.php';

// AJAX Auto-Save Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
    header('Content-Type: application/json');

    $selected_services = array_values(array_filter($_POST['services'] ?? [], static function ($slug) use ($services) {
        return isset($services[$slug]);
    }));
    $is_active = !empty($_POST['is_active']);

    if ($is_active && empty($selected_services)) {
        echo json_encode(['success' => false, 'message' => 'Bitte wähle mindestens einen Service aus.']);
        exit;
    }

    $encoded = json_encode($selected_services, JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare('
        INSERT INTO last_minute_subscriptions (customer_id, service_slugs, is_active)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            service_slugs = VALUES(service_slugs),
            is_active = VALUES(is_active),
            updated_at = NOW(),
            notification_count_today = CASE
                WHEN DATE(IFNULL(last_notification_sent, DATE_SUB(CURDATE(), INTERVAL 1 DAY))) < CURDATE() THEN 0
                ELSE notification_count_today
            END
    ');
    $stmt->execute([$customer['id'], $encoded, $is_active ? 1 : 0]);

    $message = $is_active ? 'Termin-Alerts aktiviert!' : 'Termin-Alerts deaktiviert.';
    echo json_encode(['success' => true, 'message' => $message]);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM last_minute_subscriptions WHERE customer_id = ?');
$stmt->execute([$customer['id']]);
$subscription = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$current_services = $subscription ? (json_decode($subscription['service_slugs'], true) ?: []) : [];
$is_active = $subscription && !empty($subscription['is_active']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>📱 Termin-Alerts</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(180deg, #f7fafc 0%, #edf2f7 100%);
            color: #1a202c;
            min-height: 100vh;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 24px 20px 64px;
        }
        .header {
            text-align: center;
            margin-bottom: 32px;
        }
        .header h1 {
            margin: 0 0 8px 0;
            font-size: clamp(1.8rem, 4vw, 2.2rem);
            font-weight: 700;
            color: #4a90b8;
        }
        .subtitle {
            color: #718096;
            font-size: 1rem;
            margin: 0;
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.4;
        }
        .main-card {
            background: #fff;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 20px 40px -16px rgba(45, 55, 72, 0.15);
            margin-bottom: 24px;
        }
        .feature-intro {
            background: linear-gradient(135deg, #4a90b8, #52b3a4);
            color: white;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            margin-bottom: 32px;
        }
        .feature-intro h3 {
            margin: 0 0 12px 0;
            font-size: 1.3rem;
            font-weight: 600;
        }
        .feature-intro p {
            margin: 0;
            opacity: 0.95;
            line-height: 1.5;
        }
        .toggle-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            border: 2px solid transparent;
            transition: border-color 0.3s ease;
        }
        .toggle-section.active {
            border-color: rgba(74, 144, 184, 0.3);
            background: rgba(74, 144, 184, 0.05);
        }
        .toggle-info h4 {
            margin: 0 0 4px 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #2d3748;
        }
        .toggle-info p {
            margin: 0;
            color: #718096;
            font-size: 0.9rem;
        }
        .toggle-switch {
            position: relative;
            width: 64px;
            height: 36px;
            cursor: pointer;
        }
        .toggle-switch input {
            display: none;
        }
        .toggle-switch .slider {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e0;
            border-radius: 999px;
            transition: background-color 0.3s ease;
        }
        .toggle-switch .slider::after {
            content: '';
            position: absolute;
            width: 28px;
            height: 28px;
            background: #fff;
            border-radius: 999px;
            top: 4px;
            left: 4px;
            transition: transform 0.3s ease;
            box-shadow: 0 4px 12px rgba(113, 128, 150, 0.4);
        }
        .toggle-switch input:checked + .slider {
            background: linear-gradient(135deg, #4a90b8, #52b3a4);
        }
        .toggle-switch input:checked + .slider::after {
            transform: translateX(28px);
        }
        .services-section {
            margin-top: 24px;
            opacity: 0;
            max-height: 0;
            overflow: hidden;
            transition: all 0.4s ease;
        }
        .services-section.active {
            opacity: 1;
            max-height: 500px;
        }
        .services-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2d3748;
            margin: 0 0 16px 0;
        }
        .services-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }
        .service-option {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 2px solid transparent;
            border-radius: 12px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .service-option:hover {
            border-color: rgba(74, 144, 184, 0.3);
            background: rgba(74, 144, 184, 0.05);
        }
        .service-option.selected {
            border-color: #4a90b8;
            background: rgba(74, 144, 184, 0.1);
        }
        .service-option input {
            margin-right: 12px;
            transform: scale(1.2);
        }
        .service-info span {
            display: block;
            font-weight: 600;
            color: #2d3748;
        }
        .service-info small {
            color: #718096;
            font-size: 0.85rem;
        }
        .status-message {
            position: fixed;
            top: 24px;
            left: 50%;
            transform: translateX(-50%);
            background: #48bb78;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            box-shadow: 0 8px 24px rgba(72, 187, 120, 0.4);
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .status-message.show {
            opacity: 1;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            color: #4a90b8;
            text-decoration: none;
            font-weight: 600;
            margin-top: 32px;
            transition: color 0.2s ease;
        }
        .back-link:hover {
            color: #2d5a87;
        }
        @media (max-width: 640px) {
            .main-card {
                padding: 24px;
                margin: 0 16px 24px;
            }
            .toggle-section {
                padding: 20px;
            }
            .services-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📱 Termin-Alerts</h1>
            <p class="subtitle">Erhalte automatisch eine E-Mail, sobald kurzfristige Termine frei werden</p>
        </div>

        <div class="main-card">
            <div class="feature-intro">
                <h3>⚡ Nie wieder einen freien Termin verpassen</h3>
                <p>Wir prüfen 3x täglich nach verfügbaren Terminen in den nächsten 5 Tagen und informieren dich sofort per E-Mail.</p>
            </div>

            <form id="alertForm">
                <div class="toggle-section" id="toggleSection">
                    <div class="toggle-info">
                        <h4>Termin-Alerts</h4>
                        <p>Benachrichtigungen für kurzfristige Termine</p>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" id="alertToggle" <?= $is_active ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="services-section" id="servicesSection">
                    <h4 class="services-title">Für welche Termine möchtest du benachrichtigt werden?</h4>
                    <div class="services-grid">
                        <?php foreach ($services as $slug => $service): ?>
                            <label class="service-option" data-slug="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="checkbox"
                                       name="services[]"
                                       value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= in_array($slug, $current_services, true) ? 'checked' : '' ?>
                                >
                                <div class="service-info">
                                    <span><?= htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <small>Dauer: <?= (int)$service['dur'] ?> Minuten</small>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
        </div>

        <a class="back-link" href="index.php">← Zurück zum Beta-Dashboard</a>
    </div>

    <div class="status-message" id="statusMessage"></div>

    <script>
        const alertToggle = document.getElementById('alertToggle');
        const servicesSection = document.getElementById('servicesSection');
        const toggleSection = document.getElementById('toggleSection');
        const alertForm = document.getElementById('alertForm');
        const statusMessage = document.getElementById('statusMessage');

        function updateUI() {
            const isActive = alertToggle.checked;
            toggleSection.classList.toggle('active', isActive);
            servicesSection.classList.toggle('active', isActive);

            // Update service options visual state
            document.querySelectorAll('.service-option').forEach(option => {
                const checkbox = option.querySelector('input[type="checkbox"]');
                option.classList.toggle('selected', checkbox.checked);
            });
        }

        function showStatus(message, isSuccess = true) {
            statusMessage.textContent = message;
            statusMessage.style.background = isSuccess ? '#48bb78' : '#e53e3e';
            statusMessage.classList.add('show');
            setTimeout(() => {
                statusMessage.classList.remove('show');
            }, 3000);
        }

        function autoSave() {
            const formData = new FormData(alertForm);
            formData.append('is_active', alertToggle.checked ? '1' : '');

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showStatus(data.message, true);
                } else {
                    showStatus(data.message, false);
                    // Revert toggle if save failed
                    alertToggle.checked = !alertToggle.checked;
                    updateUI();
                }
            })
            .catch(error => {
                showStatus('Speichern fehlgeschlagen', false);
                alertToggle.checked = !alertToggle.checked;
                updateUI();
            });
        }

        // Event listeners
        alertToggle.addEventListener('change', () => {
            updateUI();
            autoSave();
        });

        // Service checkbox changes
        document.querySelectorAll('input[name="services[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                updateUI();
                if (alertToggle.checked) {
                    autoSave();
                }
            });
        });

        // Initial UI state
        updateUI();
    </script>
</body>
</html>
