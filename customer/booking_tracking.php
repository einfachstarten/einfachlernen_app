<?php
// Helper functions for booking tracking activities

function logBookingInitiated($customer_id, $service_slug, $calendly_url) {
    require_once __DIR__ . '/../admin/ActivityLogger.php';
    $pdo = getPDO();
    $logger = new ActivityLogger($pdo);

    $logger->logActivity($customer_id, 'booking_initiated', [
        'service_slug' => $service_slug,
        'calendly_url' => $calendly_url,
        'booking_method' => 'calendly_redirect',
        'real_booking_start' => true
    ]);
}

function logBookingCompleted($customer_id, $service_slug, $booking_details) {
    require_once __DIR__ . '/../admin/ActivityLogger.php';
    $pdo = getPDO();
    $logger = new ActivityLogger($pdo);

    $logger->logActivity($customer_id, 'booking_completed', [
        'service_slug' => $service_slug,
        'booking_confirmed' => true,
        'calendly_event_id' => $booking_details['event_id'] ?? null,
        'scheduled_time' => $booking_details['scheduled_time'] ?? null,
        'real_booking_completion' => true
    ]);
}
?>
