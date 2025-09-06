<?php
require __DIR__.'/auth.php';
require __DIR__.'/booking_tracking.php';
$customer = require_customer_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if ($input['action'] === 'booking_initiated') {
    logBookingInitiated(
        $customer['id'],
        $input['service_slug'] ?? 'unknown',
        $input['calendly_url'] ?? ''
    );

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Invalid action']);
}
?>
