<?php
session_start();
if (empty($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$event_id = $_POST['event_id'] ?? '';
$customer_id = $_POST['customer_id'] ?? '';

if (!$action || !$event_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

// Log admin actions
require_once 'config.php';
function getPDO() {
    $config = require __DIR__ . '/config.php';
    return new PDO(
        "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'], $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

$pdo = getPDO();

header('Content-Type: application/json; charset=utf-8');

try {
    switch ($action) {
        case 'open_cancel':
            // Open Calendly cancellation page
            $invitee_uuid = $_POST['invitee_uuid'] ?? '';
            if (!$invitee_uuid) {
                throw new Exception('Invitee UUID required for cancellation');
            }

            $cancel_url = "https://calendly.com/cancellations/{$invitee_uuid}";

            // Log admin action
            if ($customer_id) {
                $stmt = $pdo->prepare("INSERT INTO customer_activities (customer_id, activity_type, activity_data, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$customer_id, 'admin_cancel_initiated', json_encode([
                    'event_id' => $event_id,
                    'admin_user' => $_SESSION['admin']['email'] ?? 'admin',
                    'action_type' => 'cancellation_link_opened'
                ])]);
            }

            echo json_encode([
                'success' => true,
                'action' => 'redirect',
                'url' => $cancel_url,
                'message' => 'Stornierungsseite wird geöffnet...'
            ]);
            break;

        case 'open_reschedule':
            // Open Calendly reschedule page
            $invitee_uuid = $_POST['invitee_uuid'] ?? '';
            if (!$invitee_uuid) {
                throw new Exception('Invitee UUID required for rescheduling');
            }

            $reschedule_url = "https://calendly.com/reschedulings/{$invitee_uuid}";

            // Log admin action
            if ($customer_id) {
                $stmt = $pdo->prepare("INSERT INTO customer_activities (customer_id, activity_type, activity_data, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$customer_id, 'admin_reschedule_initiated', json_encode([
                    'event_id' => $event_id,
                    'admin_user' => $_SESSION['admin']['email'] ?? 'admin',
                    'action_type' => 'reschedule_link_opened'
                ])]);
            }

            echo json_encode([
                'success' => true,
                'action' => 'redirect',
                'url' => $reschedule_url,
                'message' => 'Terminverschiebung wird geöffnet...'
            ]);
            break;

        case 'add_note':
            // Add admin note to customer
            $note = $_POST['note'] ?? '';
            if (!$note || !$customer_id) {
                throw new Exception('Note and customer ID required');
            }

            $stmt = $pdo->prepare("INSERT INTO customer_activities (customer_id, activity_type, activity_data, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$customer_id, 'admin_note_added', json_encode([
                'event_id' => $event_id,
                'admin_user' => $_SESSION['admin']['email'] ?? 'admin',
                'note' => $note,
                'note_type' => 'booking_related'
            ])]);

            echo json_encode([
                'success' => true,
                'message' => 'Notiz wurde hinzugefügt'
            ]);
            break;

        case 'send_reminder':
            // Send email reminder (using existing email system)
            if (!$customer_id) {
                throw new Exception('Customer ID required');
            }

            // Get customer email
            $stmt = $pdo->prepare("SELECT email, first_name FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer) {
                throw new Exception('Customer not found');
            }

            // Log reminder action
            $stmt = $pdo->prepare("INSERT INTO customer_activities (customer_id, activity_type, activity_data, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$customer_id, 'admin_reminder_sent', json_encode([
                'event_id' => $event_id,
                'admin_user' => $_SESSION['admin']['email'] ?? 'admin',
                'reminder_type' => 'manual_admin',
                'customer_email' => $customer['email']
            ])]);

            echo json_encode([
                'success' => true,
                'message' => "Erinnerung an {$customer['first_name']} wurde versendet"
            ]);
            break;

        default:
            throw new Exception('Unknown action');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
