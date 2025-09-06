<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}
if(isset($_SESSION['LAST_ACTIVITY']) && time() - $_SESSION['LAST_ACTIVITY'] > 1800){session_unset();session_destroy();header('Location: login.php');exit;}
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
if(isset($_GET['logout'])){session_destroy();header('Location: login.php');exit;}
$config = require __DIR__ . '/config.php';
function getCurrentVersionFromSW() {
    $sw_path = __DIR__ . '/../sw.js';
    if (!file_exists($sw_path)) {
        return 'unknown';
    }
    $content = file_get_contents($sw_path);
    if (preg_match('/const VERSION = [\'\"]v?([0-9]+\.[0-9]+\.[0-9]+)[\'\"];/', $content, $matches)) {
        return $matches[1];
    }
    return 'unknown';
}

function parseVersion($version) {
    $parts = explode('.', $version);
    return [
        'major' => (int)($parts[0] ?? 0),
        'minor' => (int)($parts[1] ?? 0),
        'patch' => (int)($parts[2] ?? 0)
    ];
}

function incrementVersion($version, $type) {
    $parts = parseVersion($version);
    switch ($type) {
        case 'major':
            $parts['major']++;
            $parts['minor'] = 0;
            $parts['patch'] = 0;
            break;
        case 'minor':
            $parts['minor']++;
            $parts['patch'] = 0;
            break;
        case 'patch':
            $parts['patch']++;
            break;
    }
    return "{$parts['major']}.{$parts['minor']}.{$parts['patch']}";
}

function updateVersionInFiles($new_version) {
    $sw_path = __DIR__ . '/../sw.js';
    $manifest_path = __DIR__ . '/../manifest.json';

    try {
        $sw_content = file_get_contents($sw_path);
        $sw_content = preg_replace(
            '/const VERSION = [\'\"]v?[0-9]+\.[0-9]+\.[0-9]+[\'\"];/',
            "const VERSION = 'v{$new_version}';",
            $sw_content
        );
        file_put_contents($sw_path, $sw_content);

        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
            $manifest['version'] = $new_version;
            file_put_contents($manifest_path, json_encode($manifest, JSON_PRETTY_PRINT));
        }

        return true;
    } catch (Exception $e) {
        error_log('Version update failed: ' . $e->getMessage());
        return false;
    }
}


function getEmailDeliveryStats($days = 7) {
    $log_file = __DIR__ . '/logs/email_delivery.log';
    if (!file_exists($log_file)) {
        return ['total' => 0, 'success' => 0, 'failed' => 0, 'success_rate' => 0];
    }

    $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $total = 0;
    $success = 0;

    $handle = fopen($log_file, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode(trim($line), true);
            if ($entry && $entry['timestamp'] >= $cutoff) {
                $total++;
                if ($entry['success']) {
                    $success++;
                }
            }
        }
        fclose($handle);
    }

    $failed = $total - $success;
    $success_rate = $total > 0 ? round(($success / $total) * 100, 1) : 0;

    return [
        'total' => $total,
        'success' => $success,
        'failed' => $failed,
        'success_rate' => $success_rate
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_version') {
    $type = $_POST['version_type'] ?? '';
    if (in_array($type, ['major','minor','patch'], true)) {
        $current_version = getCurrentVersionFromSW();
        $new_version = incrementVersion($current_version, $type);
        if (updateVersionInFiles($new_version)) {
            header('Location: dashboard.php?success=' . urlencode("Version updated to v{$new_version}"));
        } else {
            header('Location: dashboard.php?error=' . urlencode('Version update failed'));
        }
    } else {
        header('Location: dashboard.php?error=' . urlencode('Invalid version type'));
    }
    exit;
}
if(!empty($config['PIN_CLEANUP_EXPIRED'])) {
    $cleanup = $pdo->prepare("UPDATE customers SET pin = NULL, pin_expires = NULL WHERE pin_expires < NOW() AND pin IS NOT NULL");
    $cleanup->execute();
}
$countStmt = $pdo->query('SELECT COUNT(*) FROM customers');
$total = $countStmt->fetchColumn();
$customers = $pdo->query('SELECT * FROM customers ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
foreach($customers as &$c) {
    if(!empty($c['pin']) && !empty($c['pin_expires'])){
        $expires_timestamp = strtotime($c['pin_expires']);
        $now = time();
        if($expires_timestamp < $now){
            $c['pin_status'] = '<span style="color:#dc3545">🔴 PIN Expired (' . date('H:i', $expires_timestamp) . ')</span>';
            $c['pin_status_raw'] = 'expired';
        } else {
            $remaining_minutes = round(($expires_timestamp - $now) / 60);
            if($remaining_minutes > 0) {
                $c['pin_status'] = '<span style="color:#28a745">🟢 PIN Active (' . $remaining_minutes . ' min left)</span>';
            } else {
                $c['pin_status'] = '<span style="color:#ffc107">🟡 PIN Expiring (&lt;1 min)</span>';
            }
            $c['pin_status_raw'] = 'active';
        }
    } else {
        $c['pin_status'] = '<span style="color:#6c757d">⚪ No PIN sent</span>';
        $c['pin_status_raw'] = 'none';
    }
}
unset($c);
$email_stats = getEmailDeliveryStats(7);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
    <style>
        body{font-family:Arial;margin:2em}
        nav a{margin-right:1em;color:#52b3a4}
        table{width:100%;border-collapse:collapse}
        th,td{border:1px solid #ccc;padding:.4em;text-align:left}
        th{background:#4a90b8;color:#fff}
    </style>
</head>
<body>
<header><h2 style="color:#4a90b8">Dashboard</h2></header>
<nav>
    <a href="add_customer.php">Add Customer</a>
    <a href="test_mail.php">Test Email</a>
    <a href="analytics.php">Customer Analytics</a>
    <a href="migrate.php">Database Migration</a>
    <a href="?logout=1">Logout</a>
</nav>
<?php if(!empty($_GET['success'])): ?>
    <div style="background:#d4edda;color:#155724;padding:1rem;border-radius:5px;margin-bottom:1rem;">
        ✅ <?=htmlspecialchars($_GET['success'])?>
    </div>
<?php elseif(!empty($_GET['error'])): ?>
    <div style="background:#f8d7da;color:#721c24;padding:1rem;border-radius:5px;margin-bottom:1rem;">
        ❌ <?=htmlspecialchars($_GET['error'])?>
    </div>
<?php endif; ?>
<p>Total customers: <?=$total?></p>
<table>
    <tr><th>Email</th><th>Name</th><th>Phone</th><th>Status</th><th>Created</th><th>PIN Status</th><th>Action</th><th>Activity</th></tr>
    <?php foreach($customers as $c): ?>
    <tr>
        <td><?=htmlspecialchars($c['email'])?></td>
        <td><?=htmlspecialchars(trim($c['first_name'].' '.$c['last_name']))?></td>
        <td><?=htmlspecialchars($c['phone'])?></td>
        <td><?=htmlspecialchars($c['status'])?></td>
        <td><?=htmlspecialchars($c['created_at'])?></td>
        <td><?=$c['pin_status']?></td>
        <td>
            <form method="post" action="send_pin.php" style="margin:0;">
                <input type="hidden" name="customer_id" value="<?=$c['id']?>">
                <button type="submit">Send PIN</button>
            </form>
        </td>
        <td><a href='?view_activity=<?=$c['id']?>'>View Activity</a></td>
    </tr>
    <?php endforeach; ?>
</table>

<?php
$current_version = getCurrentVersionFromSW();
$version_parts = parseVersion($current_version);
$manifest_path = __DIR__ . '/../manifest.json';
$sw_path = __DIR__ . '/../sw.js';
?>

<div style='background:#f8f9fa;padding:1.5rem;margin:2rem 0;border:1px solid #dee2e6;border-radius:8px;'>
    <h3 style='color:#4a90b8;margin-top:0;'>🚀 PWA Version Management</h3>
    
    <div style='display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:1rem 0;'>
        <div style='background:white;padding:1rem;border-radius:6px;border:1px solid #e9ecef;'>
            <h4 style='margin:0 0 0.5rem 0;color:#495057;'>Current Version</h4>
            <div style='font-size:1.5rem;font-weight:bold;color:#28a745;'>v<?= htmlspecialchars($current_version) ?></div>
            <div style='font-size:0.9rem;color:#6c757d;margin-top:0.5rem;'>
                Major: <?= $version_parts['major'] ?> • 
                Minor: <?= $version_parts['minor'] ?> • 
                Patch: <?= $version_parts['patch'] ?>
            </div>
        </div>
        
        <div style='background:white;padding:1rem;border-radius:6px;border:1px solid #e9ecef;'>
            <h4 style='margin:0 0 0.5rem 0;color:#495057;'>File Status</h4>
            <div style='font-size:0.85rem;'>
                <div><?= file_exists($sw_path) ? '✅' : '❌' ?> Service Worker: <?= file_exists($sw_path) ? date('Y-m-d H:i', filemtime($sw_path)) : 'Missing' ?></div>
                <div><?= file_exists($manifest_path) ? '✅' : '❌' ?> Manifest: <?= file_exists($manifest_path) ? date('Y-m-d H:i', filemtime($manifest_path)) : 'Missing' ?></div>
            </div>
        </div>
    </div>
    
    <!-- Version Update Controls -->
    <form method="POST" style='margin:1rem 0;'>
        <input type="hidden" name="action" value="update_version">
        <h4 style='margin:0 0 1rem 0;color:#495057;'>Update Version:</h4>
        
        <div style='display:flex;gap:1rem;align-items:center;'>
            <button type="submit" name="version_type" value="major" 
                    style='background:#dc3545;color:white;border:none;padding:0.75rem 1rem;border-radius:6px;cursor:pointer;font-weight:bold;'
                    onclick='return confirm("Major Update (breaking changes)? Current: v<?= $current_version ?> → v<?= incrementVersion($current_version, 'major') ?>")'>
                🔴 Major Update<br>
                <small style='font-weight:normal;opacity:0.9;'>v<?= incrementVersion($current_version, 'major') ?></small>
            </button>
            
            <button type="submit" name="version_type" value="minor" 
                    style='background:#ffc107;color:#212529;border:none;padding:0.75rem 1rem;border-radius:6px;cursor:pointer;font-weight:bold;'
                    onclick='return confirm("Minor Update (new features)? Current: v<?= $current_version ?> → v<?= incrementVersion($current_version, 'minor') ?>")'>
                🟡 Minor Update<br>
                <small style='font-weight:normal;opacity:0.8;'>v<?= incrementVersion($current_version, 'minor') ?></small>
            </button>
            
            <button type="submit" name="version_type" value="patch" 
                    style='background:#28a745;color:white;border:none;padding:0.75rem 1rem;border-radius:6px;cursor:pointer;font-weight:bold;'
                    onclick='return confirm("Patch Update (bugfixes)? Current: v<?= $current_version ?> → v<?= incrementVersion($current_version, 'patch') ?>")'>
                🟢 Patch Update<br>
                <small style='font-weight:normal;opacity:0.9;'>v<?= incrementVersion($current_version, 'patch') ?></small>
            </button>
        </div>
    </form>
    
    <!-- Emergency Controls -->
    <div style='border-top:1px solid #dee2e6;padding-top:1rem;margin-top:1rem;'>
        <h5 style='margin:0 0 0.5rem 0;color:#495057;'>Emergency Controls:</h5>
        <button onclick='forceClientUpdates()' style='background:#e74c3c;color:white;border:none;padding:0.5rem 1rem;border-radius:4px;cursor:pointer;margin-right:0.5rem;'>
            🚨 Force Client Update
        </button>
        <button onclick='clearAllCaches()' style='background:#95a5a6;color:white;border:none;padding:0.5rem 1rem;border-radius:4px;cursor:pointer;'>
            🗑️ Clear All Caches
        </button>
    </div>
</div>


<div style='background:#f8f9fa;padding:1.5rem;margin:2rem 0;border:1px solid #dee2e6;border-radius:8px;'>
    <h3 style='color:#4a90b8;margin-top:0;'>📧 Email Delivery Monitor</h3>
    <div style='display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin:1rem 0;'>
        <div style='background:white;padding:1rem;border-radius:6px;border:1px solid #e9ecef;text-align:center;'>
            <div style='font-size:1.5rem;font-weight:bold;color:#495057;'><?= $email_stats['total'] ?></div>
            <div style='font-size:0.9rem;color:#6c757d;'>Gesamt (7 Tage)</div>
        </div>
        <div style='background:white;padding:1rem;border-radius:6px;border:1px solid #e9ecef;text-align:center;'>
            <div style='font-size:1.5rem;font-weight:bold;color:#28a745;'><?= $email_stats['success'] ?></div>
            <div style='font-size:0.9rem;color:#6c757d;'>Erfolgreich</div>
        </div>
        <div style='background:white;padding:1rem;border-radius:6px;border:1px solid #e9ecef;text-align:center;'>
            <div style='font-size:1.5rem;font-weight:bold;color:#dc3545;'><?= $email_stats['failed'] ?></div>
            <div style='font-size:0.9rem;color:#6c757d;'>Fehlgeschlagen</div>
        </div>
        <div style='background:white;padding:1rem;border-radius:6px;border:1px solid #e9ecef;text-align:center;'>
            <div style='font-size:1.5rem;font-weight:bold;color:<?= $email_stats['success_rate'] >= 90 ? '#28a745' : ($email_stats['success_rate'] >= 70 ? '#ffc107' : '#dc3545') ?>;'>
                <?= $email_stats['success_rate'] ?>%
            </div>
            <div style='font-size:0.9rem;color:#6c757d;'>Erfolgsrate</div>
        </div>
    </div>
    <div style='margin-top:1rem;'>
        <a href='email_delivery_log.php' style='background:#52b3a4;color:white;padding:0.5rem 1rem;text-decoration:none;border-radius:4px;font-size:0.9rem;'>
            📊 Vollständiges Delivery-Log anzeigen
        </a>
    </div>
</div>
<!-- Activities Section bleibt danach -->

<?php
// ENHANCED ACTIVITY VIEW (replace existing view_activity section)
if (isset($_GET['view_activity']) && is_numeric($_GET['view_activity'])) {
    $customer_id = (int)$_GET['view_activity'];

    // Get customer info
    $customer_stmt = $pdo->prepare("SELECT first_name, last_name, email FROM customers WHERE id = ?");
    $customer_stmt->execute([$customer_id]);
    $customer_info = $customer_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer_info) {
        echo "<div class='error'>Customer not found</div>";
    } else {
        require_once 'ActivityLogger.php';
        $logger = new ActivityLogger($pdo);
        $activities = $logger->getCustomerActivities($customer_id, 100); // More activities

        // Activity categorization and stats
        $activity_categories = [
            'auth' => ['login', 'login_failed', 'logout', 'pin_request', 'session_timeout'],
            'navigation' => ['dashboard_accessed', 'page_view', 'profile_refreshed'],
            'booking' => ['slots_api_called', 'service_viewed', 'availability_checked', 'slots_found', 'slots_not_found', 'booking_initiated', 'booking_completed', 'booking_failed'],
            'system' => ['slot_search_failed', 'pwa_installed', 'pwa_launched']
        ];

        $stats = [
            'total' => count($activities),
            'auth' => 0,
            'navigation' => 0,
            'booking' => 0,
            'system' => 0,
            'today' => 0,
            'week' => 0
        ];

        $today = date('Y-m-d');
        $week_ago = date('Y-m-d', strtotime('-7 days'));

        foreach ($activities as $activity) {
            $type = $activity['activity_type'];
            $date = date('Y-m-d', strtotime($activity['created_at']));

            // Categorize
            foreach ($activity_categories as $category => $types) {
                if (in_array($type, $types)) {
                    $stats[$category]++;
                    break;
                }
            }

            // Time-based stats
            if ($date === $today) $stats['today']++;
            if ($date >= $week_ago) $stats['week']++;
        }
?>

<style>
.activity-dashboard {
    background: #f8fafc;
    border-radius: 12px;
    padding: 24px;
    margin: 24px 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.customer-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 2px solid #e2e8f0;
}

.customer-info h2 {
    margin: 0;
    color: #1e293b;
    font-size: 24px;
}

.customer-info .email {
    color: #64748b;
    font-size: 14px;
    margin-top: 4px;
}

.activity-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    padding: 16px;
    border-radius: 8px;
    text-align: center;
    border-left: 4px solid;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.stat-card.total { border-left-color: #3b82f6; }
.stat-card.auth { border-left-color: #10b981; }
.stat-card.navigation { border-left-color: #f59e0b; }
.stat-card.booking { border-left-color: #8b5cf6; }
.stat-card.system { border-left-color: #ef4444; }
.stat-card.today { border-left-color: #06b6d4; }
.stat-card.week { border-left-color: #84cc16; }

.stat-number {
    font-size: 24px;
    font-weight: bold;
    color: #1e293b;
    margin: 0;
}

.stat-label {
    font-size: 12px;
    color: #64748b;
    text-transform: uppercase;
    margin-top: 4px;
}

.activity-filters {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.filter-btn {
    padding: 8px 16px;
    border: 2px solid #e2e8f0;
    background: white;
    border-radius: 20px;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
}

.filter-btn:hover, .filter-btn.active {
    border-color: #3b82f6;
    background: #3b82f6;
    color: white;
}

.activity-timeline {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.timeline-item {
    display: flex;
    padding: 16px;
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.2s;
}

.timeline-item:hover {
    background: #f8fafc;
}

.timeline-item:last-child {
    border-bottom: none;
}

.timeline-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 16px;
    font-size: 16px;
    flex-shrink: 0;
}

.icon-auth { background: #dcfce7; color: #16a34a; }
.icon-navigation { background: #fef3c7; color: #d97706; }
.icon-booking { background: #ede9fe; color: #7c3aed; }
.icon-system { background: #fee2e2; color: #dc2626; }

.timeline-content {
    flex: 1;
}

.activity-title {
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 4px 0;
}

.activity-time {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 8px;
}

.activity-details {
    background: #f8fafc;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 13px;
    border-left: 3px solid #e2e8f0;
}

.detail-item {
    margin: 2px 0;
}

.detail-key {
    font-weight: 500;
    color: #374151;
}

.detail-value {
    color: #6b7280;
}

.no-activities {
    text-align: center;
    padding: 60px 20px;
    color: #64748b;
}

.back-btn {
    background: #3b82f6;
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
    transition: background-color 0.2s;
}

.back-btn:hover {
    background: #2563eb;
    color: white;
}

/* ADD mobile responsiveness */
@media (max-width: 768px) {
    .activity-stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .customer-header {
        flex-direction: column;
        gap: 16px;
        text-align: center;
    }

    .activity-filters {
        justify-content: center;
    }

    .filter-btn {
        font-size: 12px;
        padding: 6px 12px;
    }

    .timeline-item {
        padding: 12px;
    }

    .timeline-icon {
        width: 32px;
        height: 32px;
        font-size: 14px;
    }
}
</style>

<div class="activity-dashboard">
    <div class="customer-header">
        <div class="customer-info">
            <h2><?= htmlspecialchars($customer_info['first_name'] . ' ' . $customer_info['last_name']) ?></h2>
            <div class="email"><?= htmlspecialchars($customer_info['email']) ?></div>
        </div>
        <a href="dashboard.php" class="back-btn">
            ← Zurück zum Dashboard
        </a>
    </div>

    <!-- Activity Statistics -->
    <div class="activity-stats">
        <div class="stat-card total">
            <div class="stat-number"><?= $stats['total'] ?></div>
            <div class="stat-label">Total Activities</div>
        </div>
        <div class="stat-card auth">
            <div class="stat-number"><?= $stats['auth'] ?></div>
            <div class="stat-label">Authentication</div>
        </div>
        <div class="stat-card navigation">
            <div class="stat-number"><?= $stats['navigation'] ?></div>
            <div class="stat-label">Navigation</div>
        </div>
        <div class="stat-card booking">
            <div class="stat-number"><?= $stats['booking'] ?></div>
            <div class="stat-label">Booking</div>
        </div>
        <div class="stat-card system">
            <div class="stat-number"><?= $stats['system'] ?></div>
            <div class="stat-label">System</div>
        </div>
        <div class="stat-card today">
            <div class="stat-number"><?= $stats['today'] ?></div>
            <div class="stat-label">Today</div>
        </div>
        <div class="stat-card week">
            <div class="stat-number"><?= $stats['week'] ?></div>
            <div class="stat-label">This Week</div>
        </div>
    </div>

    <!-- Activity Filters -->
    <div class="activity-filters">
        <button class="filter-btn active" data-filter="all">All Activities</button>
        <button class="filter-btn" data-filter="auth">🔐 Authentication</button>
        <button class="filter-btn" data-filter="navigation">🧭 Navigation</button>
        <button class="filter-btn" data-filter="booking">📅 Booking</button>
        <button class="filter-btn" data-filter="system">⚙️ System</button>
    </div>

    <?php if (empty($activities)): ?>
        <div class="no-activities">
            <h3>Keine Activities gefunden</h3>
            <p>Dieser Customer hat noch keine getrackte Aktivitäten.</p>
        </div>
    <?php else: ?>
        <!-- Activity Timeline -->
        <div class="activity-timeline">
            <?php foreach ($activities as $activity):
                $data = json_decode($activity['activity_data'], true) ?: [];
                $type = $activity['activity_type'];

                // Determine category and icon
                $category = 'system';
                foreach ($activity_categories as $cat => $types) {
                    if (in_array($type, $types)) {
                        $category = $cat;
                        break;
                    }
                }

                // Activity icons
                $icons = [
                    'login' => '🔓', 'login_failed' => '❌', 'logout' => '🔒', 'pin_request' => '📧',
                    'session_timeout' => '⏰', 'dashboard_accessed' => '🏠', 'page_view' => '👁️',
                    'profile_refreshed' => '🔄', 'slots_api_called' => '🔌', 'service_viewed' => '👀',
                    'availability_checked' => '📅', 'slots_found' => '✅', 'slots_not_found' => '❌',
                    'booking_initiated' => '🚀', 'booking_completed' => '🎉', 'booking_failed' => '💥'
                ];

                $icon = $icons[$type] ?? '📝';

                // Format activity title
                $titles = [
                    'login' => 'Successfully logged in',
                    'login_failed' => 'Login attempt failed',
                    'logout' => 'Logged out',
                    'pin_request' => 'PIN requested by admin',
                    'session_timeout' => 'Session timed out',
                    'dashboard_accessed' => 'Accessed dashboard',
                    'page_view' => 'Viewed page',
                    'profile_refreshed' => 'Profile data refreshed',
                    'slots_api_called' => 'Searched for available slots',
                    'service_viewed' => 'Viewed service details',
                    'availability_checked' => 'Checked availability',
                    'slots_found' => 'Found available slots',
                    'slots_not_found' => 'No slots available',
                    'booking_initiated' => 'Started booking process',
                    'booking_completed' => 'Booking confirmed',
                    'booking_failed' => 'Booking failed'
                ];

                $title = $titles[$type] ?? ucfirst(str_replace('_', ' ', $type));
            ?>
                <div class="timeline-item" data-category="<?= $category ?>">
                    <div class="timeline-icon icon-<?= $category ?>">
                        <?= $icon ?>
                    </div>
                    <div class="timeline-content">
                        <h4 class="activity-title"><?= $title ?></h4>
                        <div class="activity-time">
                            <?= date('d.m.Y H:i:s', strtotime($activity['created_at'])) ?>
                            • <?= htmlspecialchars($activity['ip_address']) ?>
                        </div>

                        <?php if (!empty($data)): ?>
                            <div class="activity-details">
                                <?php foreach ($data as $key => $value):
                                    if (is_bool($value)) $value = $value ? 'true' : 'false';
                                    if (is_array($value)) $value = json_encode($value);

                                    // Format key names
                                    $formatted_key = ucfirst(str_replace('_', ' ', $key));
                                ?>
                                    <div class="detail-item">
                                        <span class="detail-key"><?= htmlspecialchars($formatted_key) ?>:</span>
                                        <span class="detail-value"><?= htmlspecialchars($value) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Activity filtering
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        // Update active button
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const filter = btn.dataset.filter;
        const items = document.querySelectorAll('.timeline-item');

        items.forEach(item => {
            if (filter === 'all' || item.dataset.category === filter) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    });
});

// Auto-refresh every 30 seconds for live updates
setInterval(() => {
    if (document.visibilityState === 'visible') {
        window.location.reload();
    }
}, 30000);
</script>

<?php
    } // End customer found check
} // End activity view section
?>
<script src="../pwa-update.js"></script>
</body>
</html>
