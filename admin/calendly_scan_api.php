<?php
/**
 * Calendly Scan API Endpoint
 *
 * Handles asynchronous Calendly scanning with real-time progress updates.
 * Provides two endpoints:
 *   - ?action=start  : Initiates a new scan and returns scan_id
 *   - ?action=status : Returns current scan status and progress
 *
 * Security: Session-based authentication (admin only)
 * Rate Limiting: Built into scanner implementation
 */

session_start();
header('Content-Type: application/json');

// Security: Admin authentication required
if (empty($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Admin session required']);
    exit;
}

$action = $_GET['action'] ?? '';

/**
 * Get PDO database connection
 * @return PDO
 */
function getPDO() {
    $config = require __DIR__ . '/config.php';
    try {
        return new PDO(
            "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
}

// ============================================================
// ACTION: STATUS CHECK
// ============================================================
if ($action === 'status') {
    $scan_id = $_GET['scan_id'] ?? '';

    if (!$scan_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing scan_id parameter']);
        exit;
    }

    $pdo = getPDO();

    // Get latest scan progress
    $stmt = $pdo->prepare("
        SELECT * FROM scan_progress
        WHERE scan_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$scan_id]);
    $scan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$scan) {
        http_response_code(404);
        echo json_encode(['error' => 'Scan not found']);
        exit;
    }

    // Get recent logs (last 10 entries)
    $logs_stmt = $pdo->prepare("
        SELECT message, created_at
        FROM scan_logs
        WHERE scan_id = ?
        ORDER BY id DESC
        LIMIT 10
    ");
    $logs_stmt->execute([$scan_id]);
    $logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate duration
    $duration = 0;
    if ($scan['started_at']) {
        $end_time = $scan['completed_at'] ? strtotime($scan['completed_at']) : time();
        $duration = round($end_time - strtotime($scan['started_at']), 1);
    }

    // Return status
    echo json_encode([
        'status' => $scan['status'],
        'progress' => (int)$scan['progress'],
        'current_step' => $scan['current_step'],
        'events_scanned' => (int)$scan['events_scanned'],
        'emails_found' => (int)$scan['emails_found'],
        'new_count' => (int)$scan['new_count'],
        'existing_count' => (int)$scan['existing_count'],
        'error_message' => $scan['error_message'],
        'duration' => $duration,
        'logs' => array_reverse($logs) // Reverse to show chronological order
    ]);
    exit;
}

// ============================================================
// ACTION: START SCAN
// ============================================================
if ($action === 'start') {
    // Extend timeout for long-running scan
    set_time_limit(300); // 5 minutes

    $TOKEN = getenv('CALENDLY_TOKEN');
    $ORG_URI = getenv('CALENDLY_ORG_URI');

    // Validate environment variables
    if (!$TOKEN || !$ORG_URI) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Calendly API nicht konfiguriert. Bitte CALENDLY_TOKEN und CALENDLY_ORG_URI setzen.'
        ]);
        exit;
    }

    $pdo = getPDO();
    $scan_id = uniqid('scan_', true);

    // Create scan record
    try {
        $stmt = $pdo->prepare("
            INSERT INTO scan_progress
            (scan_id, status, progress, current_step, started_at)
            VALUES (?, 'running', 0, 'Initialisierung...', NOW())
        ");
        $stmt->execute([$scan_id]);

        error_log("[CalendlyScanAPI] New scan started: {$scan_id}");

    } catch (PDOException $e) {
        error_log("[CalendlyScanAPI] Failed to create scan record: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to initialize scan']);
        exit;
    }

    // Return scan_id immediately so browser can start polling
    echo json_encode([
        'success' => true,
        'scan_id' => $scan_id,
        'message' => 'Scan gestartet - Polling kann beginnen'
    ]);

    // Flush output and close connection to allow browser to receive response
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // Fallback for non-FastCGI environments
        ob_end_flush();
        flush();
    }

    // ============================================================
    // Now run the actual scan in background
    // ============================================================
    require_once __DIR__ . '/calendly_email_scanner_with_progress.php';

    try {
        error_log("[CalendlyScanAPI] Starting background scan {$scan_id}");

        $scanner = new CalendlyEmailScannerWithProgress($TOKEN, $ORG_URI, $pdo, $scan_id);
        $result = $scanner->scanAndSaveEmails();

        // Update final status in database
        $update = $pdo->prepare("
            UPDATE scan_progress
            SET status = ?,
                progress = 100,
                current_step = ?,
                events_scanned = ?,
                emails_found = ?,
                new_count = ?,
                existing_count = ?,
                completed_at = NOW()
            WHERE scan_id = ?
        ");

        if ($result['success']) {
            $update->execute([
                'completed',
                'Abgeschlossen',
                $result['events_scanned'],
                $result['emails_found'],
                $result['new_count'],
                $result['existing_count'],
                $scan_id
            ]);

            error_log("[CalendlyScanAPI] Scan {$scan_id} completed successfully");

        } else {
            $update->execute([
                'error',
                'Fehler',
                0, 0, 0, 0,
                $scan_id
            ]);

            // Store error message
            $pdo->prepare("UPDATE scan_progress SET error_message = ? WHERE scan_id = ?")
                ->execute([$result['error'], $scan_id]);

            error_log("[CalendlyScanAPI] Scan {$scan_id} failed: " . $result['error']);
        }

    } catch (Exception $e) {
        error_log("[CalendlyScanAPI] Scan {$scan_id} crashed: " . $e->getMessage());

        $pdo->prepare("
            UPDATE scan_progress
            SET status = 'error',
                error_message = ?,
                completed_at = NOW()
            WHERE scan_id = ?
        ")->execute([$e->getMessage(), $scan_id]);
    }

    exit;
}

// ============================================================
// INVALID ACTION
// ============================================================
http_response_code(400);
echo json_encode([
    'error' => 'Invalid action. Use ?action=start or ?action=status&scan_id=XXX'
]);
