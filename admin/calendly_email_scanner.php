<?php
/**
 * Calendly Email Scanner
 *
 * Scans Calendly scheduled events and extracts invitee emails
 * to populate the customer database with booking information.
 *
 * Usage: Instantiate with token, org_uri, and PDO, then call scanAndSaveEmails()
 */

class CalendlyEmailScanner {
    private $token;
    private $org_uri;
    private $pdo;
    private $rate_limit_delay = 200000; // 200ms in microseconds
    private $rate_limit_batch = 5; // Apply delay every N requests

    private $max_pages = 10; // Safety limit for pagination
    private $max_invitee_calls = 100; // Safety limit for invitee API calls

    /**
     * Constructor
     *
     * @param string $token Calendly API personal access token
     * @param string $org_uri Calendly organization URI
     * @param PDO $pdo Database connection
     */
    public function __construct($token, $org_uri, PDO $pdo) {
        error_log("[CalendlyEmailScanner] Constructor aufgerufen");
        error_log("[DEBUG] Token length: " . strlen($token));
        error_log("[DEBUG] Org URI: $org_uri");
        error_log("[DEBUG] PDO instance: " . get_class($pdo));

        $this->token = $token;
        $this->org_uri = $org_uri;
        $this->pdo = $pdo;

        error_log("[CalendlyEmailScanner] Constructor abgeschlossen - Instanz bereit");
    }

    /**
     * Main scanner method
     * Orchestrates the full scan and save process
     *
     * @return array Result with success status and counts
     */
    public function scanAndSaveEmails(): array {
        try {
            $start_time = microtime(true);
            error_log("=== Calendly Email Scanner Started ===");

            // Step 1: Get all scheduled events (paginated)
            $events = $this->getAllScheduledEvents();
            error_log("Retrieved " . count($events) . " scheduled events");

            // Step 2: Extract emails from invitees
            $emails_data = $this->extractInviteeEmails($events);
            error_log("Extracted " . count($emails_data) . " unique email addresses");

            // Step 3: Save new ones to database
            $result = $this->saveEmailsToDatabase($emails_data);

            $duration = round(microtime(true) - $start_time, 2);
            error_log("=== Scanner Completed in {$duration}s ===");

            return [
                'success' => true,
                'events_scanned' => count($events),
                'emails_found' => count($emails_data),
                'new_count' => $result['new'],
                'existing_count' => $result['existing'],
                'duration_seconds' => $duration
            ];

        } catch (Exception $e) {
            error_log("Scanner Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch all scheduled events from Calendly API
     * Handles pagination with safety limits
     *
     * @return array Array of event objects
     * @throws Exception on API errors
     */
    private function getAllScheduledEvents(): array {
        error_log("[getAllScheduledEvents] Starting event retrieval");

        $all_events = [];
        $page_token = null;
        $page_count = 0;

        // CRITICAL FIX: Zeit-Range korrekt setzen (UTC mit Z-Suffix)
        $utc = new DateTimeZone('UTC');
        $min_start = (new DateTimeImmutable('-12 months', $utc))->setTime(0, 0, 0)->format('Y-m-d\TH:i:s\Z');
        $max_start = (new DateTimeImmutable('+6 months', $utc))->setTime(23, 59, 59)->format('Y-m-d\TH:i:s\Z');

        error_log("[getAllScheduledEvents] Date range: $min_start to $max_start");
        error_log("[getAllScheduledEvents] Organization URI: {$this->org_uri}");

        do {
            $page_count++;
            if ($page_count > $this->max_pages) {
                error_log("[getAllScheduledEvents] Warning: Reached max pages limit ({$this->max_pages})");
                break;
            }

            // Build URL with parameters
            $url = 'https://api.calendly.com/scheduled_events';
            $params = [
                'organization' => $this->org_uri,
                'status' => 'active',
                'min_start_time' => $min_start,
                'max_start_time' => $max_start,
                'count' => 100 // Max per page
            ];

            if ($page_token) {
                $params['page_token'] = $page_token;
                error_log("[getAllScheduledEvents] Using page_token for pagination");
            }

            $url .= '?' . http_build_query($params);

            error_log("[getAllScheduledEvents] Fetching events page $page_count");

            $response = $this->apiGet($url);

            if (isset($response['collection'])) {
                $events = $response['collection'];
                $all_events = array_merge($all_events, $events);
                error_log("[getAllScheduledEvents] Page $page_count: Retrieved " . count($events) . " events (total: " . count($all_events) . ")");
            } else {
                error_log("[getAllScheduledEvents] Page $page_count: No collection in response");
            }

            // Get next page token
            $page_token = $response['pagination']['next_page_token'] ?? null;
            error_log("[getAllScheduledEvents] Next page token: " . ($page_token ? "present" : "null"));

            // Rate limiting between pagination requests
            if ($page_token) {
                usleep($this->rate_limit_delay);
                error_log("[getAllScheduledEvents] Applied rate limit delay before next page");
            }

        } while ($page_token !== null);

        error_log("[getAllScheduledEvents] Completed: Retrieved " . count($all_events) . " total events from $page_count pages");

        return $all_events;
    }

    /**
     * Extract invitee emails from events
     * Makes API calls for each event's invitees with rate limiting
     *
     * @param array $events Array of event objects
     * @return array Array of email data [email, first_name, last_name]
     * @throws Exception on API errors
     */
    private function extractInviteeEmails(array $events): array {
        error_log("[extractInviteeEmails] Starting extraction from " . count($events) . " events");

        $emails_map = []; // Use map to deduplicate by email
        $invitee_call_count = 0;

        foreach ($events as $event) {
            $invitee_call_count++;

            if ($invitee_call_count > $this->max_invitee_calls) {
                error_log("Warning: Reached max invitee calls limit ({$this->max_invitee_calls})");
                break;
            }

            $event_uri = $event['uri'] ?? null;
            if (!$event_uri) {
                error_log("[extractInviteeEmails] Event #$invitee_call_count: No URI, skipping");
                continue;
            }

            error_log("[extractInviteeEmails] Event #$invitee_call_count: Processing $event_uri");

            // Get invitees for this event
            $invitees_url = $event_uri . '/invitees?count=100';

            try {
                $invitees_response = $this->apiGet($invitees_url);

                if (isset($invitees_response['collection'])) {
                    $invitee_count = count($invitees_response['collection']);
                    error_log("[extractInviteeEmails] Event #$invitee_call_count: Found $invitee_count invitees");

                    foreach ($invitees_response['collection'] as $invitee) {
                        $email = $invitee['email'] ?? null;

                        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            // Use email as key to deduplicate
                            if (!isset($emails_map[$email])) {
                                $emails_map[$email] = [
                                    'email' => strtolower(trim($email)),
                                    'first_name' => $invitee['first_name'] ?? null,
                                    'last_name' => $invitee['last_name'] ?? null
                                ];
                                error_log("[extractInviteeEmails] New email added: $email");
                            } else {
                                error_log("[extractInviteeEmails] Duplicate email skipped: $email");
                            }
                        } else {
                            error_log("[extractInviteeEmails] Invalid email skipped: " . ($email ?? 'null'));
                        }
                    }
                } else {
                    error_log("[extractInviteeEmails] Event #$invitee_call_count: No collection in response");
                }

            } catch (Exception $e) {
                error_log("[extractInviteeEmails] Error fetching invitees for event {$event_uri}: " . $e->getMessage());
                error_log("[extractInviteeEmails] Exception stack: " . $e->getTraceAsString());
                // Continue with other events
            }

            // Rate limiting: Apply delay every N requests
            if ($invitee_call_count % $this->rate_limit_batch === 0) {
                usleep($this->rate_limit_delay);
                error_log("[extractInviteeEmails] Rate limit delay applied after $invitee_call_count calls");
            }
        }

        error_log("[extractInviteeEmails] Extraction complete: " . count($emails_map) . " unique emails found");

        return array_values($emails_map);
    }

    /**
     * Save emails to database
     * Checks for existing customers and inserts new ones
     *
     * @param array $emails_data Array of email data objects
     * @return array Result with counts [new, existing]
     */
    private function saveEmailsToDatabase(array $emails_data): array {
        $new_count = 0;
        $existing_count = 0;

        // Prepare statements once
        $check_stmt = $this->pdo->prepare(
            "SELECT id FROM customers WHERE email = ?"
        );

        $insert_stmt = $this->pdo->prepare(
            "INSERT INTO customers (email, first_name, last_name, status)
             VALUES (?, ?, ?, 'active')"
        );

        foreach ($emails_data as $data) {
            $email = $data['email'];

            try {
                // Check if email already exists
                $check_stmt->execute([$email]);
                $exists = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if ($exists) {
                    $existing_count++;
                    error_log("Skipped existing: $email");
                } else {
                    // Insert new customer
                    $insert_stmt->execute([
                        $email,
                        $data['first_name'],
                        $data['last_name']
                    ]);
                    $new_count++;
                    error_log("Inserted new customer: $email");
                }

            } catch (PDOException $e) {
                // Handle duplicate key errors gracefully (race condition)
                if ($e->getCode() == 23000) { // Duplicate entry
                    $existing_count++;
                    error_log("Duplicate key caught for: $email");
                } else {
                    error_log("Database error for $email: " . $e->getMessage());
                }
            }
        }

        error_log("Database save complete: $new_count new, $existing_count existing");

        return [
            'new' => $new_count,
            'existing' => $existing_count
        ];
    }

    /**
     * Make API GET request to Calendly
     *
     * @param string $url Full API URL
     * @return array Decoded JSON response
     * @throws Exception on API errors
     */
    private function apiGet($url): array {
        error_log("[apiGet] Request URL: $url");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->token}",
                "Content-Type: application/json"
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        error_log("[apiGet] Executing cURL request...");
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        error_log("[apiGet] HTTP Code: $http_code");
        error_log("[apiGet] Response length: " . strlen($response));
        error_log("[apiGet] Total time: " . ($info['total_time'] ?? 'unknown') . "s");

        // Handle curl errors
        if ($curl_error) {
            error_log("[apiGet] cURL Error: $curl_error");
            throw new Exception("cURL error: $curl_error");
        }

        // Handle HTTP errors
        if ($http_code < 200 || $http_code >= 300) {
            error_log("[apiGet] HTTP Error Response: " . substr($response, 0, 500));
            $error_data = json_decode($response, true);
            $error_message = $error_data['message'] ?? "HTTP $http_code";
            error_log("[apiGet] Error message: $error_message");
            throw new Exception("API error: $error_message (HTTP $http_code)");
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[apiGet] JSON Decode Error: " . json_last_error_msg());
            error_log("[apiGet] Raw Response (first 500 chars): " . substr($response, 0, 500));
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        error_log("[apiGet] Request successful - decoded " . count($data) . " top-level keys");

        return $data;
    }
}

/**
 * Helper function to get PDO instance
 * Follows the standard pattern used across the codebase
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
        die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
    }
}

// ============================================================
// STANDALONE EXECUTION (for testing)
// ============================================================

// Only execute if called directly (not included)
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: application/json');

    // Get credentials from environment
    $token = getenv('CALENDLY_TOKEN');
    $org_uri = getenv('CALENDLY_ORG_URI');

    if (!$token || !$org_uri) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Missing CALENDLY_TOKEN or CALENDLY_ORG_URI environment variables'
        ]);
        exit;
    }

    // Execute scanner
    try {
        $pdo = getPDO();
        $scanner = new CalendlyEmailScanner($token, $org_uri, $pdo);
        $result = $scanner->scanAndSaveEmails();

        http_response_code($result['success'] ? 200 : 500);
        echo json_encode($result, JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT);
    }
}
