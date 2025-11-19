<?php
// Termin-Alerts Management Interface
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}

function getPDO() {
    $config = require __DIR__ . '/config.php';
    return new PDO(
        "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

$pdo = getPDO();
$services = require __DIR__ . '/services_catalog.php';
$config = require __DIR__ . '/config.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'send_test_email':
            $customer_id = (int)($_POST['customer_id'] ?? 0);
            if ($customer_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT c.email, c.first_name, lms.service_slugs FROM customers c JOIN last_minute_subscriptions lms ON c.id = lms.customer_id WHERE c.id = ?');
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer) {
                echo json_encode(['success' => false, 'message' => 'Customer not found']);
                exit;
            }

            // Generate fake slot data for testing
            $serviceSlugs = json_decode($customer['service_slugs'], true) ?: [];
            $fakeSlots = [];

            foreach ($serviceSlugs as $slug) {
                if (!isset($services[$slug])) continue;

                $service = $services[$slug];
                $slots = [];

                // Generate 2-3 fake slots
                for ($i = 1; $i <= rand(2, 3); $i++) {
                    $futureDate = new DateTime(sprintf('+%d days', $i));
                    $futureDate->setTime(rand(9, 17), rand(0, 1) * 30, 0);
                    $viennaTime = $futureDate->setTimezone(new DateTimeZone('Europe/Vienna'));

                    $slots[] = [
                        'start_time' => $viennaTime->format('Y-m-d\TH:i:s\Z'),
                        'formatted_time' => $viennaTime->format('D, d.m.Y H:i'),
                        'booking_url' => $service['url'] . '?month=' . $viennaTime->format('Y-m') . '&email=' . urlencode($customer['email']) . '&test=1'
                    ];
                }

                $fakeSlots[$slug] = [
                    'service' => $service,
                    'slots' => $slots
                ];
            }

            if (empty($fakeSlots)) {
                echo json_encode(['success' => false, 'message' => 'No services configured for customer']);
                exit;
            }

            // Send test email using existing function
            require_once 'last_minute_checker.php';
            $result = sendLastMinuteEmail($config, $customer['email'], $customer['first_name'], $fakeSlots);

            // Log test notification
            $totalSlots = array_sum(array_map(static fn($item) => count($item['slots']), $fakeSlots));
            $insert = $pdo->prepare('INSERT INTO last_minute_notifications (customer_id, slots_found, services_checked, email_sent, email_error, sent_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $insert->execute([
                $customer_id,
                $totalSlots,
                json_encode(['TEST_' . implode('_', array_keys($fakeSlots))], JSON_UNESCAPED_UNICODE),
                $result['success'] ? 1 : 0,
                $result['success'] ? null : ($result['error'] ?? 'unknown error')
            ]);

            echo json_encode([
                'success' => $result['success'],
                'message' => $result['success'] ? "Test-Email an {$customer['email']} gesendet!" : 'Email-Versand fehlgeschlagen: ' . ($result['error'] ?? 'unbekannter Fehler')
            ]);
            exit;

        case 'preview_email':
            $customer_id = (int)($_POST['customer_id'] ?? 0);
            if ($customer_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT first_name, service_slugs FROM last_minute_subscriptions lms JOIN customers c ON c.id = lms.customer_id WHERE c.id = ?');
            $stmt->execute([$customer_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$data) {
                echo json_encode(['success' => false, 'message' => 'Customer subscription not found']);
                exit;
            }

            // Generate preview with fake data
            $serviceSlugs = json_decode($data['service_slugs'], true) ?: [];
            $previewSlots = [];

            foreach (array_slice($serviceSlugs, 0, 2) as $slug) {  // Max 2 services for preview
                if (!isset($services[$slug])) continue;

                $previewSlots[$slug] = [
                    'service' => $services[$slug],
                    'slots' => [
                        [
                            'formatted_time' => 'Mor, 21.11.2024 14:00',
                            'booking_url' => '#preview'
                        ],
                        [
                            'formatted_time' => 'Mit, 22.11.2024 10:30',
                            'booking_url' => '#preview'
                        ]
                    ]
                ];
            }

            require_once 'last_minute_checker.php';
            $previewHtml = buildEmailBody($data['first_name'], $previewSlots);

            echo json_encode(['success' => true, 'html' => $previewHtml]);
            exit;

        case 'toggle_subscription':
            $customer_id = (int)($_POST['customer_id'] ?? 0);
            $new_status = !empty($_POST['is_active']) ? 1 : 0;

            if ($customer_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
                exit;
            }

            $stmt = $pdo->prepare('UPDATE last_minute_subscriptions SET is_active = ?, updated_at = NOW() WHERE customer_id = ?');
            $result = $stmt->execute([$new_status, $customer_id]);

            if ($result && $stmt->rowCount() > 0) {
                $status = $new_status ? 'aktiviert' : 'deaktiviert';
                echo json_encode(['success' => true, 'message' => "Subscription {$status}"]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Subscription nicht gefunden']);
            }
            exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// Get subscription statistics
$stats = [];

$stmt = $pdo->query('SELECT COUNT(*) as total FROM last_minute_subscriptions WHERE is_active = 1');
$stats['active'] = (int)$stmt->fetchColumn();

$stmt = $pdo->query('SELECT COUNT(*) as total FROM last_minute_subscriptions WHERE is_active = 0');
$stats['inactive'] = (int)$stmt->fetchColumn();

$stmt = $pdo->query('SELECT COUNT(*) as total FROM last_minute_notifications WHERE sent_at >= CURDATE()');
$stats['sent_today'] = (int)$stmt->fetchColumn();

$stmt = $pdo->query('SELECT COUNT(*) as total FROM last_minute_notifications WHERE email_sent = 1');
$stmt2 = $pdo->query('SELECT COUNT(*) as total FROM last_minute_notifications');
$sent = (int)$stmt->fetchColumn();
$total = (int)$stmt2->fetchColumn();
$stats['success_rate'] = $total > 0 ? round(($sent / $total) * 100, 1) : 0;

// Get active subscriptions with customer data
$stmt = $pdo->prepare('
    SELECT
        c.id,
        c.email,
        c.first_name,
        c.last_name,
        lms.service_slugs,
        lms.is_active,
        lms.created_at,
        lms.updated_at,
        lms.last_notification_sent,
        lms.notification_count_today,
        (SELECT COUNT(*) FROM last_minute_notifications WHERE customer_id = c.id) as total_notifications,
        (SELECT COUNT(*) FROM last_minute_notifications WHERE customer_id = c.id AND email_sent = 1) as successful_notifications
    FROM customers c
    JOIN last_minute_subscriptions lms ON c.id = lms.customer_id
    WHERE c.beta_access = 1
    ORDER BY lms.is_active DESC, lms.updated_at DESC
');
$stmt->execute();
$subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent notification history
$stmt = $pdo->prepare('
    SELECT
        ln.id,
        c.email,
        c.first_name,
        ln.slots_found,
        ln.services_checked,
        ln.email_sent,
        ln.email_error,
        ln.sent_at
    FROM last_minute_notifications ln
    JOIN customers c ON c.id = ln.customer_id
    ORDER BY ln.sent_at DESC
    LIMIT 20
');
$stmt->execute();
$recent_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Termin-Alerts Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f8fafc;
            color: #2d3748;
        }
        .header {
            background: linear-gradient(135deg, #4a90b8, #52b3a4);
            color: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
        }
        .header h1 {
            margin: 0 0 8px 0;
            font-size: 1.8rem;
            font-weight: 600;
        }
        .header p {
            margin: 0;
            opacity: 0.9;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #4a90b8;
            margin: 0;
        }
        .stat-label {
            color: #718096;
            font-size: 0.9rem;
            margin: 4px 0 0 0;
        }
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 24px;
        }
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        .main-content {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .sidebar {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            height: fit-content;
        }
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0 0 16px 0;
            color: #2d3748;
        }
        .subscription-item {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .subscription-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .customer-info h4 {
            margin: 0;
            color: #2d3748;
            font-size: 1rem;
        }
        .customer-info p {
            margin: 4px 0 0 0;
            color: #718096;
            font-size: 0.9rem;
        }
        .subscription-status {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }
        .status-inactive {
            background: #fed7d7;
            color: #742a2a;
        }
        .services-list {
            margin: 8px 0;
        }
        .service-tag {
            display: inline-block;
            background: #edf2f7;
            color: #4a5568;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            margin: 2px 4px 2px 0;
        }
        .action-buttons {
            margin-top: 12px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background-color 0.2s;
        }
        .btn-primary {
            background: #4a90b8;
            color: white;
        }
        .btn-primary:hover {
            background: #357a96;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        .btn-secondary:hover {
            background: #cbd5e0;
        }
        .btn-danger {
            background: #e53e3e;
            color: white;
        }
        .btn-danger:hover {
            background: #c53030;
        }
        .notification-item {
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 0;
        }
        .notification-item:last-child {
            border-bottom: none;
        }
        .notification-meta {
            font-size: 0.8rem;
            color: #718096;
        }
        .notification-status {
            font-weight: 600;
        }
        .status-success {
            color: #22543d;
        }
        .status-error {
            color: #742a2a;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 24px;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #718096;
        }
        .alert {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 4px;
            font-weight: 600;
            z-index: 1001;
            display: none;
        }
        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }
        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border: 1px solid #feb2b2;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📱 Termin-Alerts Management</h1>
        <p>Übersicht und Verwaltung aller Termin-Alert Subscriptions</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['active'] ?></div>
            <div class="stat-label">Aktive Subscriptions</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['inactive'] ?></div>
            <div class="stat-label">Inaktive Subscriptions</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['sent_today'] ?></div>
            <div class="stat-label">E-Mails heute</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['success_rate'] ?>%</div>
            <div class="stat-label">Erfolgsrate</div>
        </div>
    </div>

    <div class="content-grid">
        <div class="main-content">
            <h2 class="section-title">Aktive Subscriptions (<?= count($subscriptions) ?>)</h2>

            <?php if (empty($subscriptions)): ?>
                <p style="color: #718096; text-align: center; padding: 40px 0;">Keine Subscriptions vorhanden.</p>
            <?php else: ?>
                <?php foreach ($subscriptions as $sub): ?>
                    <?php
                    $serviceNames = [];
                    $serviceSlugs = json_decode($sub['service_slugs'], true) ?: [];
                    foreach ($serviceSlugs as $slug) {
                        if (isset($services[$slug])) {
                            $serviceNames[] = $services[$slug]['name'];
                        }
                    }
                    ?>
                    <div class="subscription-item">
                        <div class="subscription-header">
                            <div class="customer-info">
                                <h4><?= htmlspecialchars($sub['first_name'] . ' ' . $sub['last_name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                <p><?= htmlspecialchars($sub['email'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div class="subscription-status">
                                <span class="status-badge <?= $sub['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                    <?= $sub['is_active'] ? 'Aktiv' : 'Inaktiv' ?>
                                </span>
                            </div>
                        </div>

                        <div class="services-list">
                            <?php foreach ($serviceNames as $name): ?>
                                <span class="service-tag"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        </div>

                        <div style="font-size: 0.8rem; color: #718096; margin: 8px 0;">
                            Letzte E-Mail: <?= $sub['last_notification_sent'] ? date('d.m.Y H:i', strtotime($sub['last_notification_sent'])) : 'Noch keine' ?> |
                            Heute: <?= $sub['notification_count_today'] ?>/3 |
                            Gesamt: <?= $sub['successful_notifications'] ?>/<?= $sub['total_notifications'] ?>
                        </div>

                        <div class="action-buttons">
                            <button class="btn btn-primary" onclick="sendTestEmail(<?= $sub['id'] ?>)">Test E-Mail senden</button>
                            <button class="btn btn-secondary" onclick="previewEmail(<?= $sub['id'] ?>)">E-Mail Vorschau</button>
                            <button class="btn <?= $sub['is_active'] ? 'btn-danger' : 'btn-primary' ?>" onclick="toggleSubscription(<?= $sub['id'] ?>, <?= $sub['is_active'] ? 0 : 1 ?>)">
                                <?= $sub['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="sidebar">
            <h3 class="section-title">Letzte Benachrichtigungen</h3>

            <?php if (empty($recent_notifications)): ?>
                <p style="color: #718096; font-size: 0.9rem;">Noch keine Benachrichtigungen versendet.</p>
            <?php else: ?>
                <?php foreach ($recent_notifications as $notification): ?>
                    <div class="notification-item">
                        <div style="font-weight: 600; font-size: 0.9rem;">
                            <?= htmlspecialchars($notification['first_name'], ENT_QUOTES, 'UTF-8') ?>
                            <span class="notification-status <?= $notification['email_sent'] ? 'status-success' : 'status-error' ?>">
                                <?= $notification['email_sent'] ? '✓' : '✗' ?>
                            </span>
                        </div>
                        <div class="notification-meta">
                            <?= htmlspecialchars($notification['email'], ENT_QUOTES, 'UTF-8') ?> |
                            <?= $notification['slots_found'] ?> Slots |
                            <?= date('d.m.Y H:i', strtotime($notification['sent_at'])) ?>
                        </div>
                        <?php if (!$notification['email_sent'] && $notification['email_error']): ?>
                            <div style="color: #742a2a; font-size: 0.8rem; margin-top: 4px;">
                                <?= htmlspecialchars($notification['email_error'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                        <?php
                        $services_checked = json_decode($notification['services_checked'], true) ?: [];
                        if (!empty($services_checked)):
                        ?>
                            <div style="font-size: 0.8rem; color: #4a5568; margin-top: 4px;">
                                <?= htmlspecialchars(implode(', ', $services_checked), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <a href="dashboard.php" class="btn btn-secondary" style="width: 100%; text-align: center; margin-top: 16px; text-decoration: none; display: block; box-sizing: border-box;">
                ← Zurück zum Dashboard
            </a>
        </div>
    </div>

    <!-- Email Preview Modal -->
    <div id="emailPreviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>E-Mail Vorschau</h3>
                <button class="modal-close" onclick="closeModal('emailPreviewModal')">&times;</button>
            </div>
            <div id="emailPreviewContent">
                <!-- Email content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <div id="alertSuccess" class="alert alert-success"></div>
    <div id="alertError" class="alert alert-error"></div>

    <script>
        function showAlert(message, isSuccess = true) {
            const alertElement = document.getElementById(isSuccess ? 'alertSuccess' : 'alertError');
            alertElement.textContent = message;
            alertElement.style.display = 'block';

            setTimeout(() => {
                alertElement.style.display = 'none';
            }, 5000);
        }

        function sendTestEmail(customerId) {
            if (!confirm('Test-E-Mail mit Beispiel-Terminen an diesen Kunden senden?')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'send_test_email');
            formData.append('customer_id', customerId);

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showAlert(data.message, data.success);
                if (data.success) {
                    setTimeout(() => location.reload(), 2000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Fehler beim Senden der Test-E-Mail', false);
            });
        }

        function previewEmail(customerId) {
            const formData = new FormData();
            formData.append('action', 'preview_email');
            formData.append('customer_id', customerId);

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('emailPreviewContent').innerHTML = data.html;
                    document.getElementById('emailPreviewModal').style.display = 'block';
                } else {
                    showAlert(data.message, false);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Fehler beim Laden der E-Mail Vorschau', false);
            });
        }

        function toggleSubscription(customerId, newStatus) {
            const action = newStatus ? 'aktivieren' : 'deaktivieren';
            if (!confirm(`Subscription für diesen Kunden ${action}?`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'toggle_subscription');
            formData.append('customer_id', customerId);
            formData.append('is_active', newStatus);

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showAlert(data.message, data.success);
                if (data.success) {
                    setTimeout(() => location.reload(), 1500);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Fehler beim Ändern der Subscription', false);
            });
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('emailPreviewModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
