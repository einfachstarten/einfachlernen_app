<?php
/**
 * Calendly Email Scanner with Progress Reporting
 *
 * Extends the base CalendlyEmailScanner to provide real-time progress updates
 * during async scanning operations. Progress is tracked in the database
 * and can be polled by frontend clients for live UI updates.
 *
 * Usage:
 *   $scanner = new CalendlyEmailScannerWithProgress($token, $org_uri, $pdo, $scan_id);
 *   $result = $scanner->scanAndSaveEmails();
 */

require_once __DIR__ . '/calendly_email_scanner.php';

class CalendlyEmailScannerWithProgress extends CalendlyEmailScanner {
    private $scan_id;

    /**
     * Constructor
     *
     * @param string $token Calendly API personal access token
     * @param string $org_uri Calendly organization URI
     * @param PDO $pdo Database connection
     * @param string $scan_id Unique identifier for this scan session
     */
    public function __construct($token, $org_uri, PDO $pdo, $scan_id) {
        parent::__construct($token, $org_uri, $pdo);
        $this->scan_id = $scan_id;

        error_log("[CalendlyProgressScanner] Initialized with scan_id: {$scan_id}");
    }

    /**
     * Update progress in database
     * Called throughout the scan to report status
     *
     * @param int $progress Progress percentage (0-100)
     * @param string $step Current step description
     * @param array $data Additional data (events_scanned, emails_found, etc.)
     */
    protected function updateProgress($progress, $step, $data = []) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE scan_progress
                SET progress = ?,
                    current_step = ?,
                    events_scanned = ?,
                    emails_found = ?
                WHERE scan_id = ?
            ");

            $stmt->execute([
                (int)$progress,
                $step,
                $data['events_scanned'] ?? 0,
                $data['emails_found'] ?? 0,
                $this->scan_id
            ]);

            error_log("[CalendlyProgressScanner] Progress: {$progress}% - {$step}");

        } catch (PDOException $e) {
            error_log("[CalendlyProgressScanner] Failed to update progress: " . $e->getMessage());
            // Don't throw - progress updates are non-critical
        }
    }

    /**
     * Add log entry to database
     * Creates real-time log entries visible to frontend
     *
     * @param string $message Log message
     */
    protected function addLog($message) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO scan_logs (scan_id, message, created_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$this->scan_id, $message]);

            error_log("[SCAN {$this->scan_id}] {$message}");

        } catch (PDOException $e) {
            error_log("[CalendlyProgressScanner] Failed to add log: " . $e->getMessage());
            // Don't throw - logging is non-critical
        }
    }

    /**
     * Main scanner method with progress tracking
     * Overrides parent to add progress updates at each step
     *
     * @return array Result with success status and counts
     */
    public function scanAndSaveEmails(): array {
        try {
            $start_time = microtime(true);

            // Step 1: Initialize
            $this->updateProgress(0, 'Initialisierung...');
            $this->addLog('🚀 Calendly Scan gestartet');

            // Step 2: Load events from Calendly API
            $this->updateProgress(10, 'Lade Events von Calendly API...');
            $this->addLog('📡 Verbinde mit Calendly API...');

            $events = $this->getAllScheduledEvents();

            $this->updateProgress(30, 'Events geladen', [
                'events_scanned' => count($events)
            ]);
            $this->addLog("✅ " . count($events) . " Events von Calendly geladen");

            // Step 3: Extract emails from invitees
            $this->updateProgress(40, 'Extrahiere Email-Adressen...');
            $this->addLog('🔍 Extrahiere Invitee-Daten...');

            $emails_data = $this->extractInviteeEmails($events);

            $this->updateProgress(70, 'Emails extrahiert', [
                'events_scanned' => count($events),
                'emails_found' => count($emails_data)
            ]);
            $this->addLog("✅ " . count($emails_data) . " eindeutige Email-Adressen gefunden");

            // Step 4: Save to database
            $this->updateProgress(80, 'Speichere in Datenbank...');
            $this->addLog('💾 Speichere Kunden in Datenbank...');

            $result = $this->saveEmailsToDatabase($emails_data);

            $this->updateProgress(95, 'Daten gespeichert', [
                'events_scanned' => count($events),
                'emails_found' => count($emails_data)
            ]);
            $this->addLog("✅ {$result['new']} neue Kunden gespeichert");
            $this->addLog("ℹ️  {$result['existing']} bereits vorhanden");

            // Step 5: Complete
            $duration = round(microtime(true) - $start_time, 2);
            $this->updateProgress(100, 'Abgeschlossen');
            $this->addLog("🎉 Scan erfolgreich abgeschlossen in {$duration}s");

            error_log("[CalendlyProgressScanner] Scan completed successfully");

            return [
                'success' => true,
                'events_scanned' => count($events),
                'emails_found' => count($emails_data),
                'new_count' => $result['new'],
                'existing_count' => $result['existing'],
                'duration_seconds' => $duration
            ];

        } catch (Exception $e) {
            error_log("[CalendlyProgressScanner] Scan failed: " . $e->getMessage());

            $this->updateProgress(0, 'Fehler aufgetreten');
            $this->addLog("❌ Fehler: " . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
