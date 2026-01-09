<?php
session_start();
set_time_limit(300); // 5 minutes for Calendly Scan (instead of default 30s)
if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}
if (isset($_SESSION['LAST_ACTIVITY']) && time() - $_SESSION['LAST_ACTIVITY'] > 1800) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

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

$pdo = getPDO();

// Handle Calendly Scan Request
$scan_status = null;
if (isset($_POST['calendly_scan'])) {
    error_log("=== ADMIN: Calendly Scan initiated by " . ($_SESSION['admin']['email'] ?? 'unknown') . " ===");

    $TOKEN = getenv('CALENDLY_TOKEN');
    $ORG_URI = getenv('CALENDLY_ORG_URI');

    if (!$TOKEN || !$ORG_URI) {
        $scan_status = 'error|Calendly API nicht konfiguriert. Bitte Environment Variables setzen.';
        error_log("ERROR: Missing CALENDLY_TOKEN or CALENDLY_ORG_URI");
    } else {
        require_once __DIR__ . '/calendly_email_scanner.php';
        try {
            error_log("Scanner starting with ORG: " . substr($ORG_URI, -12));

            $scanner = new CalendlyEmailScanner($TOKEN, $ORG_URI, $pdo);
            $result = $scanner->scanAndSaveEmails();

            if ($result['success']) {
                $duration = round($result['duration_seconds'] ?? 0, 1);
                $events_scanned = $result['events_scanned'] ?? 0;
                $scan_status = "success|✅ Scan erfolgreich: {$result['new_count']} neue, {$result['existing_count']} bekannt ({$events_scanned} Events gescannt in {$duration}s)";
                error_log("SUCCESS: Scanner completed - New: {$result['new_count']}, Existing: {$result['existing_count']}, Duration: {$duration}s");
            } else {
                $error_msg = $result['error'] ?? 'Unbekannter Fehler';
                $scan_status = "error|❌ Scan fehlgeschlagen: {$error_msg}";
                error_log("ERROR: Scanner failed - " . $error_msg);
            }
        } catch (Exception $e) {
            $scan_status = "error|❌ Exception: " . $e->getMessage();
            error_log("EXCEPTION: Scanner crashed - " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
    }
}

// Search logic
$search_query = $_GET['q'] ?? '';
$customers = [];

if (!empty($search_query)) {
    $stmt = $pdo->prepare("
        SELECT id, email, first_name, last_name, phone, created_at
        FROM customers
        WHERE email LIKE :query
           OR first_name LIKE :query
           OR last_name LIKE :query
           OR CONCAT(first_name, ' ', last_name) LIKE :query
        ORDER BY last_name ASC, first_name ASC
        LIMIT 50
    ");
    $stmt->execute(['query' => "%{$search_query}%"]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$total = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kundensuche - Admin</title>
    <style>
        /* Modern Dashboard Styles */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 2rem;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }

        header {
            margin-bottom: 2rem;
        }

        header h2 {
            color: #1e293b;
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Navigation */
        nav {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }

        nav a {
            color: #4a90b8;
            text-decoration: none;
            margin-right: 2rem;
            font-weight: 500;
            padding: 0.5rem 0;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }

        nav a:hover {
            color: #2563eb;
            border-bottom-color: #2563eb;
        }

        /* Search Container */
        .search-container {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }

        .search-box {
            position: relative;
            max-width: 600px;
        }

        .search-box input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            font-size: 1.1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            outline: none;
            transition: all 0.2s;
        }

        .search-box input:focus {
            border-color: #4a90b8;
            box-shadow: 0 0 0 3px rgba(74, 144, 184, 0.1);
        }

        .search-box::before {
            content: '🔍';
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2rem;
        }

        .search-help {
            margin-top: 1rem;
            color: #64748b;
            font-size: 0.9rem;
        }

        /* Stats */
        .stats-overview {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }

        .stats-overview p {
            margin: 0;
            font-size: 1.1rem;
            color: #4a5568;
        }

        /* Results Container */
        .results-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .result-item {
            padding: 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.2s;
        }

        .result-item:hover {
            background: #f8fafc;
        }

        .result-item:last-child {
            border-bottom: none;
        }

        .customer-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .customer-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.5rem;
            margin-bottom: 1rem;
            color: #64748b;
            font-size: 0.9rem;
        }

        .customer-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            background: #4a90b8;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .action-btn:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #64748b;
        }

        .empty-state h3 {
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin: 0;
        }

        .result-count {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 500;
            color: #475569;
        }

        /* Scan Button */
        .scan-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
            margin-left: 1rem;
        }

        .scan-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .scan-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: inline-block;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin: 1rem 0;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.95rem;
            line-height: 1.5;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert strong {
            font-weight: 600;
        }

        .search-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .search-box-wrapper {
            flex: 1;
            min-width: 300px;
        }

        /* Progress Container Styles */
        .progress-container {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin: 1rem 0;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #1e293b;
        }

        .progress-bar {
            height: 24px;
            background: #f1f5f9;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4a90b8, #52b3a4);
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .progress-stats {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #64748b;
            flex-wrap: wrap;
        }

        .progress-stats span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .progress-logs {
            max-height: 200px;
            overflow-y: auto;
            background: #f8fafc;
            padding: 0.75rem;
            border-radius: 6px;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 0.85rem;
            border: 1px solid #e2e8f0;
        }

        .progress-logs div {
            padding: 0.25rem 0;
            color: #475569;
            line-height: 1.4;
        }

        .progress-logs::-webkit-scrollbar {
            width: 8px;
        }

        .progress-logs::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .progress-logs::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            header h2 {
                font-size: 1.5rem;
            }

            nav {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }

            nav a {
                margin-right: 0;
                padding: 0.75rem;
                background: #f8fafc;
                border-radius: 6px;
                text-align: center;
            }

            .search-container {
                padding: 1rem;
            }

            .customer-details {
                grid-template-columns: 1fr;
            }

            .customer-actions {
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }

            .search-header {
                flex-direction: column;
                align-items: stretch;
            }

            .scan-btn {
                margin-left: 0;
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<header>
    <h2>🔍 Kundensuche</h2>
</header>

<nav>
    <a href="dashboard.php">← Zurück zum Dashboard</a>
</nav>

<div class="search-container">
    <div class="search-header">
        <div class="search-box-wrapper">
            <form method="GET" action="customer_search.php" class="search-box">
                <input
                    type="text"
                    name="q"
                    id="searchInput"
                    value="<?= htmlspecialchars($search_query, ENT_QUOTES) ?>"
                    placeholder="Name, E-Mail oder Telefon suchen..."
                    autocomplete="off"
                    autofocus
                >
            </form>
        </div>
        <button type="button" class="scan-btn" id="scanBtn">
            📡 Calendly Scan
        </button>
    </div>

    <!-- Progress Container (hidden by default) -->
    <div id="scanProgress" style="display:none;">
        <div class="progress-container">
            <div class="progress-header">
                <span id="progressText">Initialisierung...</span>
                <span id="progressPercent">0%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill" style="width: 0%"></div>
            </div>
            <div class="progress-stats">
                <span>📊 <strong id="eventsScanned">0</strong> Events</span>
                <span>📧 <strong id="emailsFound">0</strong> Emails</span>
                <span>⏱️ <strong id="duration">0</strong>s</span>
            </div>
            <div class="progress-logs" id="progressLogs">
                <div>Warte auf Scan-Start...</div>
            </div>
        </div>
    </div>

    <?php if ($scan_status):
        list($type, $msg) = explode('|', $scan_status, 2);
    ?>
        <div class="alert alert-<?= $type ?>">
            <?= $type === 'success' ? '✅' : '❌' ?> <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <div class="search-help">
        💡 Suche nach Vorname, Nachname, E-Mail oder Telefonnummer. Live-Suche mit 500ms Verzögerung.
    </div>
</div>

<div class="stats-overview">
    <p>📊 Gesamt: <strong><?= $total ?></strong> Kunden in der Datenbank</p>
</div>

<?php if (!empty($search_query)): ?>
    <div class="results-container">
        <?php if (count($customers) > 0): ?>
            <div class="result-count">
                Gefunden: <strong><?= count($customers) ?></strong> <?= count($customers) === 1 ? 'Kunde' : 'Kunden' ?>
                <?php if (count($customers) === 50): ?>
                    <span style="color: #f59e0b;"> (Limit erreicht - verfeinern Sie die Suche)</span>
                <?php endif; ?>
            </div>

            <?php foreach ($customers as $customer): ?>
                <div class="result-item">
                    <div class="customer-name">
                        <?= htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['last_name'])) ?>
                    </div>

                    <div class="customer-details">
                        <div>📧 <?= htmlspecialchars($customer['email']) ?></div>
                        <?php if (!empty($customer['phone'])): ?>
                            <div>📱 <?= htmlspecialchars($customer['phone']) ?></div>
                        <?php endif; ?>
                        <div>🗓️ Erstellt: <?= date('d.m.Y', strtotime($customer['created_at'])) ?></div>
                    </div>

                    <div class="customer-actions">
                        <button
                            onclick="showCustomerBookings(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['email'], ENT_QUOTES) ?>', '<?= htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['last_name']), ENT_QUOTES) ?>')"
                            class="action-btn">
                            📅 Termine anzeigen
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <h3>🔍 Keine Ergebnisse</h3>
                <p>Für "<?= htmlspecialchars($search_query) ?>" wurden keine Kunden gefunden.</p>
                <p style="margin-top: 0.5rem;">Versuchen Sie eine andere Suche.</p>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="results-container">
        <div class="empty-state">
            <h3>👋 Willkommen zur Kundensuche</h3>
            <p>Geben Sie einen Suchbegriff ein, um Kunden zu finden.</p>
            <p style="margin-top: 1rem; font-size: 0.9rem; color: #94a3b8;">
                Sie können nach Vorname, Nachname, E-Mail-Adresse oder vollständigem Namen suchen.
            </p>
        </div>
    </div>
<?php endif; ?>

<!-- Customer Bookings Modal (reusing from dashboard.php) -->
<div id="bookingsModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:2rem;border-radius:8px;max-width:800px;width:90%;max-height:80vh;overflow-y:auto;">
        <h3 id="bookingsTitle">Termine laden...</h3>
        <button onclick="closeBookingsModal()" style="position:absolute;top:10px;right:15px;background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>

        <div id="bookingsContent">
            <div style="text-align:center;padding:2rem;">
                <div style="width:30px;height:30px;border:3px solid #f3f3f3;border-top:3px solid #4a90b8;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto;"></div>
                <p style="margin-top:1rem;">Termine werden geladen...</p>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
.booking-item {
    border-bottom: 1px solid #eee;
    padding: 0.8rem 0;
}
.booking-item:last-child {
    border-bottom: none;
}
.booking-future {
    background: #f8f9fa;
    border-left: 4px solid #28a745;
    padding-left: 1rem;
}
.booking-past {
    opacity: 0.7;
}
</style>

<script>
// ============================================================
// ASYNC CALENDLY SCAN WITH LIVE PROGRESS
// ============================================================

const scanBtn = document.getElementById('scanBtn');
const scanProgress = document.getElementById('scanProgress');

if (scanBtn) {
    scanBtn.addEventListener('click', async function(e) {
        e.preventDefault();

        // Disable button and show progress container
        this.disabled = true;
        this.innerHTML = '🔄 Starte Scan...';
        scanProgress.style.display = 'block';

        // Reset progress UI
        document.getElementById('progressFill').style.width = '0%';
        document.getElementById('progressText').textContent = 'Initialisierung...';
        document.getElementById('progressPercent').textContent = '0%';
        document.getElementById('eventsScanned').textContent = '0';
        document.getElementById('emailsFound').textContent = '0';
        document.getElementById('duration').textContent = '0';
        document.getElementById('progressLogs').innerHTML = '<div>⏳ Starte Scan...</div>';

        try {
            // Start scan via API
            console.log('[Async Scan] Starting scan...');
            const startRes = await fetch('calendly_scan_api.php?action=start', {
                method: 'POST',
                credentials: 'same-origin'
            });

            if (!startRes.ok) {
                throw new Error(`HTTP ${startRes.status}: ${startRes.statusText}`);
            }

            const startData = await startRes.json();

            if (!startData.success) {
                throw new Error(startData.error || 'Scan konnte nicht gestartet werden');
            }

            const scanId = startData.scan_id;
            console.log('[Async Scan] Scan started with ID:', scanId);

            // Update button
            this.innerHTML = '⏳ Scan läuft...';

            // Poll for status every 2 seconds
            const pollInterval = setInterval(async () => {
                try {
                    const statusRes = await fetch(`calendly_scan_api.php?action=status&scan_id=${scanId}`, {
                        credentials: 'same-origin',
                        cache: 'no-store'
                    });

                    if (!statusRes.ok) {
                        throw new Error(`HTTP ${statusRes.status}`);
                    }

                    const status = await statusRes.json();
                    console.log('[Async Scan] Status:', status);

                    // Update progress bar
                    const progressWidth = Math.max(0, Math.min(100, status.progress));
                    document.getElementById('progressFill').style.width = progressWidth + '%';
                    document.getElementById('progressPercent').textContent = progressWidth + '%';

                    // Update step text
                    document.getElementById('progressText').textContent = status.current_step || 'Verarbeitung...';

                    // Update stats
                    document.getElementById('eventsScanned').textContent = status.events_scanned || 0;
                    document.getElementById('emailsFound').textContent = status.emails_found || 0;
                    document.getElementById('duration').textContent = Math.round(status.duration || 0);

                    // Update logs
                    const logsDiv = document.getElementById('progressLogs');
                    if (status.logs && status.logs.length > 0) {
                        logsDiv.innerHTML = status.logs.map(log =>
                            `<div>${escapeHtml(log.message)}</div>`
                        ).join('');

                        // Auto-scroll to bottom
                        logsDiv.scrollTop = logsDiv.scrollHeight;
                    }

                    // Check if completed
                    if (status.status === 'completed') {
                        clearInterval(pollInterval);
                        showSuccess(status);
                    } else if (status.status === 'error') {
                        clearInterval(pollInterval);
                        showError(status.error_message || 'Unbekannter Fehler');
                    }

                } catch (pollErr) {
                    console.error('[Async Scan] Poll error:', pollErr);
                    // Don't clear interval on poll errors - continue trying
                }
            }, 2000); // Poll every 2 seconds

            // Timeout after 5 minutes (safety)
            setTimeout(() => {
                clearInterval(pollInterval);
                console.warn('[Async Scan] Timeout reached');
            }, 300000);

        } catch (err) {
            console.error('[Async Scan] Start error:', err);
            showError(err.message);
            this.disabled = false;
            this.innerHTML = '📡 Calendly Scan';
            scanProgress.style.display = 'none';
        }
    });
}

function showSuccess(status) {
    const scanBtn = document.getElementById('scanBtn');
    scanBtn.disabled = false;
    scanBtn.innerHTML = '✅ Scan erfolgreich!';

    setTimeout(() => {
        scanBtn.innerHTML = '📡 Calendly Scan';
    }, 5000);

    // Show success message
    const summary = document.createElement('div');
    summary.className = 'alert alert-success';
    summary.style.marginTop = '1rem';
    summary.innerHTML = `
        <strong>✅ Scan erfolgreich abgeschlossen!</strong><br>
        ${status.new_count || 0} neue Kunden gespeichert,
        ${status.existing_count || 0} bereits vorhanden
        (${status.events_scanned || 0} Events gescannt in ${Math.round(status.duration || 0)}s)
    `;
    scanProgress.appendChild(summary);

    // Reload page after 3 seconds to show updated customer list
    setTimeout(() => {
        console.log('[Async Scan] Reloading page...');
        window.location.reload();
    }, 3000);
}

function showError(message) {
    const scanBtn = document.getElementById('scanBtn');
    scanBtn.disabled = false;
    scanBtn.innerHTML = '📡 Calendly Scan';

    const error = document.createElement('div');
    error.className = 'alert alert-error';
    error.style.marginTop = '1rem';
    error.innerHTML = `<strong>❌ Fehler:</strong> ${escapeHtml(message)}`;
    scanProgress.appendChild(error);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Live search with debounce
let searchTimeout;
const searchInput = document.getElementById('searchInput');

searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);

    searchTimeout = setTimeout(() => {
        const query = this.value.trim();
        if (query.length >= 2 || query.length === 0) {
            this.form.submit();
        }
    }, 500); // 500ms debounce
});

// Bookings modal functionality (copied from dashboard.php)
const BOOKINGS_CACHE_TTL = 2 * 60 * 1000;
const bookingsCache = new Map();
const bookingRequestQueue = [];
let bookingQueueProcessing = false;

function wait(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function getCachedBookings(customerId) {
    const entry = bookingsCache.get(customerId);
    if (!entry) {
        return null;
    }

    if (Date.now() - entry.timestamp > BOOKINGS_CACHE_TTL) {
        bookingsCache.delete(customerId);
        return null;
    }

    return entry;
}

function storeBookingsInCache(customerId, data) {
    bookingsCache.set(customerId, {
        data,
        timestamp: Date.now()
    });
}

function enqueueBookingRequest(task) {
    return new Promise((resolve, reject) => {
        bookingRequestQueue.push({ task, resolve, reject });
        processBookingQueue();
    });
}

async function processBookingQueue() {
    if (bookingQueueProcessing) {
        return;
    }

    bookingQueueProcessing = true;
    while (bookingRequestQueue.length > 0) {
        const { task, resolve, reject } = bookingRequestQueue.shift();
        try {
            const result = await task();
            resolve(result);
        } catch (error) {
            reject(error);
        }

        if (bookingRequestQueue.length > 0) {
            await wait(300);
        }
    }

    bookingQueueProcessing = false;
}

function showCacheNotice(timestamp) {
    const content = document.getElementById('bookingsContent');
    if (!content) {
        return;
    }

    const existingNotice = content.querySelector('[data-cache-notice="1"]');
    if (existingNotice) {
        existingNotice.remove();
    }

    const notice = document.createElement('div');
    notice.setAttribute('data-cache-notice', '1');
    notice.style.cssText = `
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:1rem;
        background:#fff8e1;
        border:1px solid #ffe0a3;
        color:#8a6d3b;
        padding:0.75rem 1rem;
        border-radius:6px;
        font-size:0.85rem;
        margin-bottom:0.75rem;
    `;

    const minutesAgo = Math.floor((Date.now() - timestamp) / 60000);
    const freshness = minutesAgo <= 0
        ? 'vor weniger als einer Minute'
        : `vor ${minutesAgo} Minute${minutesAgo === 1 ? '' : 'n'}`;

    const textWrapper = document.createElement('div');
    textWrapper.innerHTML = `
        <strong>⚡ Zwischengespeicherte Termine</strong><br>
        <small>Letzte Aktualisierung ${freshness}</small>
    `;

    const refreshBtn = document.createElement('button');
    refreshBtn.textContent = 'Neu laden';
    refreshBtn.style.cssText = `
        background:#4a90b8;
        color:white;
        border:none;
        padding:0.35rem 0.75rem;
        border-radius:4px;
        cursor:pointer;
        font-size:0.8rem;
    `;
    refreshBtn.addEventListener('click', () => {
        if (typeof window.currentBookingCustomerId !== 'undefined') {
            bookingsCache.delete(window.currentBookingCustomerId);
            showCustomerBookings(
                window.currentBookingCustomerId,
                window.currentBookingCustomerEmail || '',
                window.currentBookingCustomerName || ''
            );
        }
    });

    notice.appendChild(textWrapper);
    notice.appendChild(refreshBtn);
    content.prepend(notice);
}

async function showCustomerBookings(customerId, email, name) {
    window.currentBookingCustomerId = customerId;
    window.currentBookingCustomerEmail = email || '';
    window.currentBookingCustomerName = name || '';

    const modal = document.getElementById('bookingsModal');
    const title = document.getElementById('bookingsTitle');
    const content = document.getElementById('bookingsContent');

    modal.style.display = 'block';
    title.textContent = `Termine von ${name}`;

    const cachedEntry = getCachedBookings(customerId);
    if (cachedEntry) {
        displayBookings(cachedEntry.data);
        showCacheNotice(cachedEntry.timestamp);
        return;
    }

    const requestsAhead = bookingRequestQueue.length + (bookingQueueProcessing ? 1 : 0);
    const queueNotice = requestsAhead > 0
        ? `<div style="margin-top:0.35rem;color:#6c757d;font-size:0.8em;">⏳ Wartet auf ${requestsAhead} weitere Anfrage${requestsAhead === 1 ? '' : 'n'}</div>`
        : '';

    content.innerHTML = `
        <div style="text-align:center;padding:2rem;">
            <div style="width:30px;height:30px;border:3px solid #f3f3f3;border-top:3px solid #4a90b8;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto;"></div>
            <p style="margin-top:1rem;">Termine werden geladen...</p>
            <small style="color:#6c757d;">Einzelabruf zur Rate-Limit-Vermeidung</small>
            ${queueNotice}
        </div>
    `;

    try {
        await enqueueBookingRequest(async () => {
            await wait(500);

            const response = await fetch(`customer_bookings.php?customer_id=${customerId}&rate_limit_safe=1`, {
                credentials: 'same-origin',
                cache: 'no-store'
            });

            const responseText = await response.text();
            let data;

            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                throw new Error('Ungültige Antwort vom Server');
            }

            if (!response.ok) {
                throw new Error(data && data.error ? data.error : `HTTP ${response.status}`);
            }

            if (!data.success) {
                throw new Error(data.error || 'Unbekannter Fehler');
            }

            storeBookingsInCache(customerId, data);
            displayBookings(data);
            return data;
        });
    } catch (error) {
        content.innerHTML = `<div style="color:red;padding:1rem;">❌ Fehler: ${error.message}</div>`;
    }
}

function displayBookings(data) {
    const content = document.getElementById('bookingsContent');
    const events = data.events;

    if (events.length === 0) {
        content.innerHTML = `
            <div style="text-align:center;padding:2rem;color:#6c757d;">
                <p>📅 Keine Termine gefunden</p>
                <p style="font-size:0.9em;margin-top:0.5rem;">Kunde: ${data.customer.email}</p>
            </div>
        `;
        return;
    }

    const futureEvents = events.filter(e => e.is_future);
    const pastEvents = events.filter(e => !e.is_future);

    let html = `
        <div style="margin-bottom:1rem;color:#6c757d;font-size:0.9em;background:#f8f9fa;padding:0.8rem;border-radius:4px;">
            <strong>📊 Terminübersicht:</strong><br>
            <strong>Insgesamt:</strong> ${events.length} Termine |
            <strong>Kommend:</strong> ${futureEvents.length} |
            <strong>Vergangen:</strong> ${pastEvents.length}
            ${data.pages_fetched ? `<br><small>📄 ${data.pages_fetched} API-Seiten geladen</small>` : ''}
        </div>
    `;

    if (futureEvents.length > 0) {
        html += '<h4 style="color:#28a745;margin:1rem 0 0.5rem 0;">🔜 Kommende Termine</h4>';
        futureEvents.forEach(event => {
            html += createBookingHTML(event, true);
        });
    }

    if (pastEvents.length > 0) {
        const showFirst = 5;
        const shouldCollapse = pastEvents.length > showFirst;

        html += `
            <h4 style="color:#6c757d;margin:1.5rem 0 0.5rem 0;">
                📅 Vergangene Termine (${pastEvents.length})
            </h4>
        `;

        pastEvents.slice(0, showFirst).forEach(event => {
            html += createBookingHTML(event, false);
        });

        if (shouldCollapse) {
            html += `
                <div id="morePastEvents" style="display:none;">
                    ${pastEvents.slice(showFirst).map(event => createBookingHTML(event, false)).join('')}
                </div>
                <button onclick="toggleMorePastEvents()" id="toggleMoreBtn"
                        style="background:#6c757d;color:white;border:none;padding:0.5rem 1rem;border-radius:4px;cursor:pointer;margin-top:0.5rem;width:100%;">
                    📅 ${pastEvents.length - showFirst} weitere vergangene Termine anzeigen
                </button>
            `;
        }
    }

    content.innerHTML = html;
}

function toggleMorePastEvents() {
    const moreDiv = document.getElementById('morePastEvents');
    const toggleBtn = document.getElementById('toggleMoreBtn');

    if (moreDiv.style.display === 'none') {
        moreDiv.style.display = 'block';
        toggleBtn.textContent = '🔼 Vergangene Termine ausblenden';
        toggleBtn.style.background = '#dc3545';
    } else {
        moreDiv.style.display = 'none';
        toggleBtn.textContent = `📅 ${moreDiv.children.length} weitere vergangene Termine anzeigen`;
        toggleBtn.style.background = '#6c757d';
    }
}

function createBookingHTML(event, isFuture) {
    const statusColor = event.status === 'active' ? '#28a745' : '#6c757d';
    const containerClass = isFuture ? 'booking-future' : 'booking-past';

    return `
        <div class="booking-item ${containerClass}">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div style="flex:1;">
                    <strong style="color:#343a40;">${event.name}</strong>
                    <div style="color:#6c757d;font-size:0.9em;margin-top:0.3rem;">
                        📅 ${event.start_time} - ${event.end_time}<br>
                        📍 ${event.location}<br>
                        🗓️ Gebucht am: ${event.created_at}
                    </div>
                </div>
                <div style="text-align:right;">
                    <span style="color:${statusColor};font-weight:bold;font-size:0.8em;">
                        ${event.status.toUpperCase()}
                    </span>
                </div>
            </div>
        </div>
    `;
}

function closeBookingsModal() {
    document.getElementById('bookingsModal').style.display = 'none';
}

document.getElementById('bookingsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeBookingsModal();
    }
});
</script>
</body>
</html>
