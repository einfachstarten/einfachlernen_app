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

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_subscription') {
    $selected_services = array_values(array_filter($_POST['services'] ?? [], static function ($slug) use ($services) {
        return isset($services[$slug]);
    }));
    $is_active = !empty($_POST['is_active']);

    if ($is_active && empty($selected_services)) {
        $error = 'Bitte wählen Sie mindestens einen Service aus.';
    } else {
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
        $stmt->execute([
            $customer['id'],
            $encoded,
            $is_active ? 1 : 0,
        ]);

        $success = $is_active
            ? 'Last-Minute Benachrichtigungen aktiviert!'
            : 'Benachrichtigungen deaktiviert.';
    }
}

$stmt = $pdo->prepare('SELECT * FROM last_minute_subscriptions WHERE customer_id = ?');
$stmt->execute([$customer['id']]);
$subscription = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$current_services = $subscription ? (json_decode($subscription['service_slugs'], true) ?: []) : [];
$is_active = $subscription && !empty($subscription['is_active']);

function formatDateTime(?string $timestamp): string
{
    if (empty($timestamp)) {
        return '–';
    }

    $dt = new DateTimeImmutable($timestamp);
    $dt = $dt->setTimezone(new DateTimeZone('Europe/Vienna'));

    return $dt->format('d.m.Y H:i');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>🚨 Last-Minute Slots (Beta)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(180deg, #f7fafc 0%, #edf2f7 100%);
            color: #1a202c;
        }
        .beta-container {
            max-width: 820px;
            margin: 0 auto;
            padding: 32px 20px 64px;
        }
        h1 {
            margin-bottom: 0.5rem;
            font-size: clamp(1.8rem, 3vw, 2.4rem);
        }
        .beta-feature-box {
            background: #fff;
            border-radius: 16px;
            padding: 20px 24px;
            margin: 18px 0 28px;
            box-shadow: 0 20px 40px -24px rgba(45, 55, 72, 0.45);
        }
        .beta-feature-box p {
            margin: 0.25rem 0;
            font-size: 1rem;
        }
        form {
            background: #fff;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 24px 46px -28px rgba(45, 55, 72, 0.4);
        }
        .status-cards {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            margin-bottom: 24px;
        }
        .status-card {
            background: #f7fafc;
            border-radius: 14px;
            padding: 16px 18px;
            border: 1px solid rgba(66, 153, 225, 0.25);
        }
        .status-label {
            font-size: 0.85rem;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: #2b6cb0;
            margin-bottom: 8px;
        }
        .status-value {
            font-weight: 600;
            font-size: 1.1rem;
        }
        .toggle-switch {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            font-weight: 600;
        }
        .toggle-switch input {
            display: none;
        }
        .toggle-switch .slider {
            position: relative;
            width: 52px;
            height: 28px;
            background-color: #cbd5e0;
            border-radius: 999px;
            transition: background-color 0.2s ease;
        }
        .toggle-switch .slider::after {
            content: '';
            position: absolute;
            width: 24px;
            height: 24px;
            background: #fff;
            border-radius: 999px;
            top: 2px;
            left: 2px;
            transition: transform 0.2s ease;
            box-shadow: 0 4px 10px rgba(113, 128, 150, 0.35);
        }
        .toggle-switch input:checked + .slider {
            background: #48bb78;
        }
        .toggle-switch input:checked + .slider::after {
            transform: translateX(24px);
        }
        #service-selection {
            margin-top: 28px;
        }
        .service-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }
        .service-card {
            display: block;
            border: 1px solid rgba(74, 144, 226, 0.25);
            border-radius: 14px;
            padding: 18px;
            background: #f8fafc;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .service-card input {
            margin-right: 10px;
        }
        .service-card:hover {
            border-color: #4299e1;
            box-shadow: 0 12px 20px -18px rgba(66, 153, 225, 0.7);
        }
        .service-card span {
            display: block;
            font-weight: 600;
            font-size: 1.02rem;
            margin-bottom: 6px;
        }
        .service-card small {
            color: #4a5568;
            font-size: 0.85rem;
        }
        button[type="submit"] {
            margin-top: 28px;
            background: #2b6cb0;
            color: #fff;
            border: none;
            border-radius: 999px;
            padding: 14px 28px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease;
            box-shadow: 0 15px 35px -20px rgba(43, 108, 176, 0.65);
        }
        button[type="submit"]:hover {
            background: #2c5282;
            transform: translateY(-1px);
        }
        .error,
        .success {
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 18px;
            font-weight: 600;
        }
        .error {
            background: rgba(245, 101, 101, 0.12);
            color: #9b2c2c;
            border: 1px solid rgba(229, 62, 62, 0.35);
        }
        .success {
            background: rgba(72, 187, 120, 0.12);
            color: #276749;
            border: 1px solid rgba(56, 161, 105, 0.35);
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            margin-top: 30px;
            color: #2b6cb0;
            text-decoration: none;
            font-weight: 600;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        @media (max-width: 640px) {
            form {
                padding: 22px;
            }
            button[type="submit"] {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<div class="beta-container">
    <h1>🚨 Last-Minute Benachrichtigungen</h1>
    <p>Bleibe automatisch informiert, sobald kurzfristige Termine frei werden.</p>

    <div class="beta-feature-box">
        <p>📧 Automatische E-Mails bei kurzfristig verfügbaren Terminen</p>
        <p>⏰ Tägliche Checks: 7:00, 12:00 &amp; 20:00 Uhr</p>
        <p>📅 Beobachteter Zeitraum: Nächste 5 Tage</p>
    </div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="update_subscription">

        <div class="status-cards">
            <div class="status-card">
                <div class="status-label">Status</div>
                <div class="status-value">
                    <?= $is_active ? 'Aktiviert' : 'Deaktiviert' ?>
                </div>
            </div>
            <div class="status-card">
                <div class="status-label">Letzte E-Mail</div>
                <div class="status-value"><?= htmlspecialchars(formatDateTime($subscription['last_notification_sent'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="status-card">
                <div class="status-label">Benachrichtigungen heute</div>
                <div class="status-value"><?= (int)($subscription['notification_count_today'] ?? 0) ?> / 3</div>
            </div>
        </div>

        <label class="toggle-switch">
            <input type="checkbox" name="is_active" <?= $is_active ? 'checked' : '' ?>>
            <span class="slider"></span>
            Benachrichtigungen aktivieren
        </label>

        <div id="service-selection" style="<?= $is_active ? '' : 'display:none;' ?>">
            <h3>Services auswählen:</h3>
            <p>Wähle die Angebote aus, für die du informiert werden möchtest.</p>
            <div class="service-grid">
                <?php foreach ($services as $slug => $service): ?>
                    <label class="service-card">
                        <input type="checkbox"
                               name="services[]"
                               value="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
                            <?= in_array($slug, $current_services, true) ? 'checked' : '' ?>
                        >
                        <span><?= htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <small>Dauer: <?= (int)$service['dur'] ?> Minuten</small>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="submit">💾 Einstellungen speichern</button>
    </form>

    <a class="back-link" href="index.php">← Zurück zum Beta-Dashboard</a>
</div>

<script>
    const toggleInput = document.querySelector('input[name="is_active"]');
    const serviceSelection = document.getElementById('service-selection');

    if (toggleInput && serviceSelection) {
        toggleInput.addEventListener('change', () => {
            serviceSelection.style.display = toggleInput.checked ? 'block' : 'none';
        });
    }
</script>
</body>
</html>
