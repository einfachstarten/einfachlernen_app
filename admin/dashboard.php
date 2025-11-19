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
$customers = $pdo->query("
    SELECT *,
           CASE
               WHEN beta_access = 1 THEN 1
               ELSE 0
           END AS beta_access,
           COALESCE(avatar_style, 'avataaars') AS avatar_style,
           COALESCE(avatar_seed, email) AS avatar_seed
    FROM customers
    ORDER BY created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach($customers as &$c) {
    $c['avatar_style'] = $c['avatar_style'] ?: 'avataaars';
    $c['avatar_seed'] = $c['avatar_seed'] ?: $c['email'];

    $c['avatar_url'] = 'https://api.dicebear.com/9.x/' .
        rawurlencode($c['avatar_style']) .
        '/svg?seed=' . rawurlencode($c['avatar_seed']);

    if(!empty($c['pin']) && !empty($c['pin_expires'])){
        $expires_timestamp = strtotime($c['pin_expires']);
        $now = time();
        if($expires_timestamp < $now){
            $c['pin_status_icon'] = '🔴';
            $c['pin_status_tooltip'] = 'PIN abgelaufen am ' . date('d.m.Y H:i', $expires_timestamp);
            $c['pin_status_raw'] = 'expired';
        } else {
            $remaining_seconds = $expires_timestamp - $now;
            $remaining_days = floor($remaining_seconds / (24 * 3600));
            $remaining_hours = floor(($remaining_seconds % (24 * 3600)) / 3600);
            $remaining_minutes = floor(($remaining_seconds % 3600) / 60);

            if($remaining_days > 0) {
                $c['pin_status_icon'] = '🟢';
                $c['pin_status_tooltip'] = "PIN aktiv - {$remaining_days} Tag(e) und {$remaining_hours} Stunde(n) verbleibend";
            } elseif($remaining_hours > 0) {
                $c['pin_status_icon'] = '🟡';
                $c['pin_status_tooltip'] = "PIN läuft bald ab - {$remaining_hours} Stunde(n) und {$remaining_minutes} Minute(n) verbleibend";
            } elseif($remaining_minutes > 5) {
                $c['pin_status_icon'] = '🟡';
                $c['pin_status_tooltip'] = "PIN läuft bald ab - {$remaining_minutes} Minute(n) verbleibend";
            } else {
                $c['pin_status_icon'] = '🟠';
                $c['pin_status_tooltip'] = 'PIN läuft sehr bald ab - weniger als 5 Minuten verbleibend';
            }
            $c['pin_status_raw'] = 'active';
        }
    } else {
        $c['pin_status_icon'] = '⚪';
        $c['pin_status_tooltip'] = 'Noch kein PIN versendet';
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

        /* Stats Section */
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

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }

        /* Enhanced table styling for better avatar display */
        .table-container table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .table-container th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 1rem 0.75rem;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .table-container td {
            padding: 0.875rem 0.75rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .table-container tr:hover {
            background-color: #f8fafc;
        }

        .table-container tr:last-child td {
            border-bottom: none;
        }

        /* Avatar styling */
        .table-container td img {
            transition: transform 0.2s ease;
        }

        .table-container td img:hover {
            transform: scale(1.1);
        }

        /* PIN status icon styling */
        .pin-status-icon {
            font-size: 1.2rem;
            cursor: help;
            transition: transform 0.2s ease;
            display: inline-block;
        }

        .pin-status-icon:hover {
            transform: scale(1.2);
        }

        /* Action buttons consistent styling */
        button {
            background: #4a90b8;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        button:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .action-btn {
            background: #4a90b8;
            color: white;
            border: none;
            padding: 0.3em 0.75em;
            font-size: 0.85em;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 0.125rem;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .action-btn.activity {
            background: #52b3a4;
        }

        .action-btn.danger {
            background: #dc3545;
        }

        .action-btn.primary {
            background: #4a90b8;
        }

        /* Alert Styles */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        /* Today's Schedule Styles */
        .schedule-item-now {
            animation: pulse 2s infinite;
            background: #fff5f5 !important;
        }

        .schedule-item-soon {
            background: #fffbf0 !important;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }

        .schedule-refresh-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
            margin-left: 0.5rem;
        }

        @media (max-width: 1200px) {
            .table-container {
                overflow-x: auto;
            }

            .table-container table {
                min-width: 1000px;
            }
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body { padding: 1rem; }

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

            .table-container table { font-size: 0.8rem; }
            .table-container th, .table-container td { padding: 0.5rem 0.25rem; }
            .table-container td img { width: 32px; height: 32px; }
            .action-btn { padding: 0.25em 0.5em; font-size: 0.75em; }
        }
    </style>
</head>
<body>
<header><h2>🛠️ Admin-Dashboard</h2></header>
<nav>
    <a href="add_customer.php">Kunde hinzufügen</a>
    <a href="test_mail.php">E-Mail testen</a>
    <a href="analytics.php">Kunden-Analytics</a>
    <a href="migrate.php">Datenbank-Migration</a>
    <a href="messaging.php">📨 Messaging Center</a>
    <a href="termin_alerts_management.php">📱 Termin-Alerts</a>
    <a href="?logout=1">Abmelden</a>
</nav>
<?php if(!empty($_GET['success'])): ?>
    <div class="alert alert-success">
        ✅ <?=htmlspecialchars($_GET['success'])?>
    </div>
<?php elseif(!empty($_GET['error'])): ?>
    <div class="alert alert-error">
        ❌ <?=htmlspecialchars($_GET['error'])?>
    </div>
<?php endif; ?>

<div class="stats-overview">
    <p>📊 Gesamt Kunden: <strong><?=$total?></strong></p>
</div>

<div class="table-container">
    <table>
        <tr>
            <th>Avatar</th>
            <th>E-Mail</th>
            <th>Name</th>
            <th>Telefon</th>
            <th>Erstellt</th>
            <th>PIN</th>
            <th>Beta</th>
            <th>Aktionen</th>
            <th>Termine</th>
            <th>Löschen</th>
        </tr>
        <?php foreach($customers as $c): ?>
        <tr>
            <td style="text-align: center;">
                <img src="<?= htmlspecialchars($c['avatar_url'], ENT_QUOTES) ?>"
                     alt="Avatar"
                     style="width: 40px; height: 40px; border-radius: 50%; border: 2px solid #e2e8f0;"
                     title="<?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name'], ENT_QUOTES) ?>">
            </td>
            <td><?=htmlspecialchars($c['email'])?></td>
            <td><?=htmlspecialchars(trim($c['first_name'].' '.$c['last_name']))?></td>
            <td><?=htmlspecialchars($c['phone'])?></td>
            <td title="<?= date('d.m.Y H:i', strtotime($c['created_at'])) ?>"><?= date('d.m.Y', strtotime($c['created_at'])) ?></td>
            <td style="text-align: center;">
                <span class="pin-status-icon"
                      title="<?= htmlspecialchars($c['pin_status_tooltip'], ENT_QUOTES) ?>">
                    <?= $c['pin_status_icon'] ?>
                </span>
            </td>
            <td>
                <form method="post" action="toggle_beta.php" style="margin:0;" onsubmit="return confirmBetaToggle('<?=htmlspecialchars($c['email'], ENT_QUOTES)?>', <?=$c['beta_access'] ? 'true' : 'false'?>)">
                    <input type="hidden" name="customer_id" value="<?=$c['id']?>">
                    <input type="hidden" name="current_status" value="<?=$c['beta_access']?>">
                    <button type="submit" style="background:<?=$c['beta_access'] ? '#28a745' : '#6c757d'?>;color:white;border:none;padding:0.4rem 0.8rem;border-radius:4px;cursor:pointer;font-size:0.8rem;">
                        <?=$c['beta_access'] ? '🧪 Beta ON' : '⚪ Beta OFF'?>
                    </button>
                </form>
            </td>
            <td style="white-space: nowrap;">
                <form method="post" action="send_pin.php" style="margin:0;display:inline-block;margin-right:0.5rem;" onsubmit="return confirmSendPin('<?=htmlspecialchars($c['email'], ENT_QUOTES)?>')">
                    <input type="hidden" name="customer_id" value="<?=$c['id']?>">
                    <button type="submit"
                            class="action-btn primary"
                            title="PIN per E-Mail senden">
                        📧 PIN
                    </button>
                </form>
                <a href='?view_activity=<?=$c['id']?>'
                   class="action-btn activity"
                   title="Kundenaktivitäten anzeigen">
                    📊 Aktivitäten
                </a>
            </td>
            <td>
                <button onclick="showCustomerBookings(<?=$c['id']?>, '<?=htmlspecialchars($c['email'], ENT_QUOTES)?>', '<?=htmlspecialchars(trim($c['first_name'].' '.$c['last_name']), ENT_QUOTES)?>')"
                        class="action-btn primary"
                        title="Termine verwalten">
                    📅 Termine
                </button>
            </td>
            <td>
                <form method="post" action="delete_customer.php" style="margin:0;display:inline;">
                    <input type="hidden" name="customer_id" value="<?=$c['id']?>">
                    <button type="submit"
                            class="action-btn danger"
                            onclick="return confirmDelete('<?=htmlspecialchars($c['email'], ENT_QUOTES)?>')"
                            title="Kunde löschen">
                        🗑️ Löschen
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php
$current_version = getCurrentVersionFromSW();
$version_parts = parseVersion($current_version);
$manifest_path = __DIR__ . '/../manifest.json';
$sw_path = __DIR__ . '/../sw.js';
?>

<div style='background:#f8f9fa;padding:1.5rem;margin:2rem 0;border:1px solid #dee2e6;border-radius:8px;'>
    <h3 style='color:#4a90b8;margin-top:0;'>🚀 PWA-Versionen verwalten</h3>

    <div style='display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:1rem 0;'>
        <div style='background:white;padding:1rem;border-radius:6px;border:1px solid #e9ecef;'>
            <h4 style='margin:0 0 0.5rem 0;color:#495057;'>Aktuelle Version</h4>
            <div style='font-size:1.5rem;font-weight:bold;color:#28a745;'>v<?= htmlspecialchars($current_version) ?></div>
            <div style='font-size:0.9rem;color:#6c757d;margin-top:0.5rem;'>
                Major: <?= $version_parts['major'] ?> •
                Minor: <?= $version_parts['minor'] ?> •
                Patch: <?= $version_parts['patch'] ?>
            </div>
        </div>

        <div style='background:white;padding:1rem;border-radius:6px;border:1px solid #e9ecef;'>
            <h4 style='margin:0 0 0.5rem 0;color:#495057;'>Dateistatus</h4>
            <div style='font-size:0.85rem;'>
                <div><?= file_exists($sw_path) ? '✅' : '❌' ?> Service Worker: <?= file_exists($sw_path) ? date('Y-m-d H:i', filemtime($sw_path)) : 'Fehlt' ?></div>
                <div><?= file_exists($manifest_path) ? '✅' : '❌' ?> Manifest: <?= file_exists($manifest_path) ? date('Y-m-d H:i', filemtime($manifest_path)) : 'Fehlt' ?></div>
            </div>
        </div>
    </div>

    <!-- Version Update Controls -->
    <form method="POST" style='margin:1rem 0;'>
        <input type="hidden" name="action" value="update_version">
        <h4 style='margin:0 0 1rem 0;color:#495057;'>Version aktualisieren:</h4>

        <div style='display:flex;gap:1rem;align-items:center;'>
            <button type="submit" name="version_type" value="major"
                    style='background:#dc3545;color:white;border:none;padding:0.75rem 1rem;border-radius:6px;cursor:pointer;font-weight:bold;'
                    onclick='return confirm("Major-Update (Breaking Changes)? Aktuell: v<?= $current_version ?> → v<?= incrementVersion($current_version, 'major') ?>")'>
                🔴 Major-Update<br>
                <small style='font-weight:normal;opacity:0.9;'>v<?= incrementVersion($current_version, 'major') ?></small>
            </button>

            <button type="submit" name="version_type" value="minor"
                    style='background:#ffc107;color:#212529;border:none;padding:0.75rem 1rem;border-radius:6px;cursor:pointer;font-weight:bold;'
                    onclick='return confirm("Minor-Update (Neue Features)? Aktuell: v<?= $current_version ?> → v<?= incrementVersion($current_version, 'minor') ?>")'>
                🟡 Minor-Update<br>
                <small style='font-weight:normal;opacity:0.8;'>v<?= incrementVersion($current_version, 'minor') ?></small>
            </button>

            <button type="submit" name="version_type" value="patch"
                    style='background:#28a745;color:white;border:none;padding:0.75rem 1rem;border-radius:6px;cursor:pointer;font-weight:bold;'
                    onclick='return confirm("Patch-Update (Bugfixes)? Aktuell: v<?= $current_version ?> → v<?= incrementVersion($current_version, 'patch') ?>")'>
                🟢 Patch-Update<br>
                <small style='font-weight:normal;opacity:0.9;'>v<?= incrementVersion($current_version, 'patch') ?></small>
            </button>
        </div>
    </form>

    <!-- Emergency Controls -->
    <div style='border-top:1px solid #dee2e6;padding-top:1rem;margin-top:1rem;'>
        <h5 style='margin:0 0 0.5rem 0;color:#495057;'>Notfall-Aktionen:</h5>
        <button onclick='forceClientUpdates()' style='background:#e74c3c;color:white;border:none;padding:0.5rem 1rem;border-radius:4px;cursor:pointer;margin-right:0.5rem;'>
            🚨 Clients zum Update zwingen
        </button>
        <button onclick='clearAllCaches()' style='background:#95a5a6;color:white;border:none;padding:0.5rem 1rem;border-radius:4px;cursor:pointer;'>
            🗑️ Alle Caches leeren
        </button>
    </div>

    <div style='margin-top:1rem;'>
        <button onclick='testManualUpdate()' style='background:#52b3a4;color:white;padding:0.5rem 1rem;text-decoration:none;border:none;border-radius:4px;cursor:pointer;'>
            🔧 Manuelles Update testen
        </button>
    </div>
</div>

<script>
function testManualUpdate() {
    const newWindow = window.open('/einfachlernen/customer/', '_blank');
    setTimeout(() => {
        if (newWindow) {
            newWindow.postMessage({ type: 'TEST_MANUAL_UPDATE' }, '*');
        }
    }, 2000);
}
</script>

<?php
// Today's Schedule Widget - HINZUFÜGEN
echo "<div style='background:#f8f9fa;padding:1rem;margin:1rem 0;border:1px solid #dee2e6;border-radius:5px;'>";
echo "<h4 style='color:#495057;margin-bottom:1rem;'>📅 Heutiger Terminplan <button class='schedule-refresh-btn' onclick='refreshTodaysSchedule()'>⟳ Aktualisieren</button></h4>";
echo "<div id='todaysSchedule'>Termine werden geladen...</div>";
echo "</div>";
?>

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
        echo "<div class='error'>Kunde nicht gefunden</div>";
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
            <div class="stat-label">Aktivitäten gesamt</div>
        </div>
        <div class="stat-card auth">
            <div class="stat-number"><?= $stats['auth'] ?></div>
            <div class="stat-label">Authentifizierung</div>
        </div>
        <div class="stat-card navigation">
            <div class="stat-number"><?= $stats['navigation'] ?></div>
            <div class="stat-label">Navigation</div>
        </div>
        <div class="stat-card booking">
            <div class="stat-number"><?= $stats['booking'] ?></div>
            <div class="stat-label">Buchungen</div>
        </div>
        <div class="stat-card system">
            <div class="stat-number"><?= $stats['system'] ?></div>
            <div class="stat-label">System</div>
        </div>
        <div class="stat-card today">
            <div class="stat-number"><?= $stats['today'] ?></div>
            <div class="stat-label">Heute</div>
        </div>
        <div class="stat-card week">
            <div class="stat-number"><?= $stats['week'] ?></div>
            <div class="stat-label">Diese Woche</div>
        </div>
    </div>

    <!-- Activity Filters -->
    <div class="activity-filters">
        <button class="filter-btn active" data-filter="all">Alle Aktivitäten</button>
        <button class="filter-btn" data-filter="auth">🔐 Authentifizierung</button>
        <button class="filter-btn" data-filter="navigation">🧭 Navigation</button>
        <button class="filter-btn" data-filter="booking">📅 Buchungen</button>
        <button class="filter-btn" data-filter="system">⚙️ System</button>
    </div>

    <?php if (empty($activities)): ?>
        <div class="no-activities">
            <h3>Keine Aktivitäten gefunden</h3>
            <p>Für diesen Kunden wurden noch keine Aktivitäten protokolliert.</p>
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
                    'login' => 'Erfolgreich angemeldet',
                    'login_failed' => 'Anmeldeversuch fehlgeschlagen',
                    'logout' => 'Abgemeldet',
                    'pin_request' => 'PIN durch Admin angefordert',
                    'session_timeout' => 'Sitzung abgelaufen',
                    'dashboard_accessed' => 'Dashboard aufgerufen',
                    'page_view' => 'Seite angesehen',
                    'profile_refreshed' => 'Profildaten aktualisiert',
                    'slots_api_called' => 'Terminslots angefragt',
                    'service_viewed' => 'Kursdetails angesehen',
                    'availability_checked' => 'Verfügbarkeit geprüft',
                    'slots_found' => 'Freie Slots gefunden',
                    'slots_not_found' => 'Keine Slots gefunden',
                    'booking_initiated' => 'Buchung gestartet',
                    'booking_completed' => 'Buchung abgeschlossen',
                    'booking_failed' => 'Buchung fehlgeschlagen'
                ];

                $title = $titles[$type] ?? ucwords(str_replace('_', ' ', $type));
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
                                    if (is_bool($value)) $value = $value ? 'ja' : 'nein';
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

<!-- Customer Bookings Modal -->
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
const BOOKINGS_CACHE_TTL = 2 * 60 * 1000; // 2 Minuten Cache, um API-Last zu reduzieren
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
            await wait(300); // kleine Pause zwischen Anfragen
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
    window.currentBookingCustomerId = customerId; // Store for quick actions
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
            await wait(500); // kurze Pause vor dem API-Aufruf

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
        content.innerHTML = `<div style="color:red;padding:1rem;">❌ Rate Limit oder Timeout: ${error.message}</div>`;
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

    // Separate future and past events
    const futureEvents = events.filter(e => e.is_future);
    const pastEvents = events.filter(e => !e.is_future);

    let html = `
        <div style="margin-bottom:1rem;color:#6c757d;font-size:0.9em;background:#f8f9fa;padding:0.8rem;border-radius:4px;">
            <strong>📊 Terminübersicht:</strong><br>
            <strong>Insgesamt:</strong> ${events.length} Termine |
            <strong>Kommend:</strong> ${futureEvents.length} |
            <strong>Vergangen:</strong> ${pastEvents.length}
            ${data.pages_fetched ? `<br><small>📄 ${data.pages_fetched} API-Seiten geladen</small>` : ''}
            ${data.has_more_potential ? '<br><small style="color:#dc3545;">⚠️ Möglicherweise weitere Termine verfügbar</small>' : ''}
        </div>
    `;

    // Show future events first
    if (futureEvents.length > 0) {
        html += '<h4 style="color:#28a745;margin:1rem 0 0.5rem 0;">🔜 Kommende Termine</h4>';
        futureEvents.forEach(event => {
            html += createBookingHTML(event, true);
        });
    }

    // Show past events - all, but collapsible
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

    // Quick Actions nur für zukünftige Termine
    let quickActions = '';
    if (isFuture && event.can_cancel) {
        quickActions = `
            <div style="margin-top:0.8rem;padding-top:0.8rem;border-top:1px solid #eee;">
                <strong style="font-size:0.8em;color:#495057;">🔧 Quick Actions:</strong><br>
                <div style="margin-top:0.5rem;">
                    ${event.can_cancel ? `
                        <button onclick="doQuickAction('open_cancel', '${event.id}', '${event.invitee_uuid}')" 
                                style="background:#dc3545;color:white;border:none;padding:3px 8px;border-radius:3px;cursor:pointer;font-size:11px;margin-right:5px;">
                            ❌ Stornieren
                        </button>
                    ` : ''}
                    ${event.can_reschedule ? `
                        <button onclick="doQuickAction('open_reschedule', '${event.id}', '${event.invitee_uuid}')" 
                                style="background:#ffc107;color:#212529;border:none;padding:3px 8px;border-radius:3px;cursor:pointer;font-size:11px;margin-right:5px;">
                            🔄 Verschieben
                        </button>
                    ` : ''}
                    <button onclick="showNoteDialog('${event.id}')" 
                            style="background:#6c757d;color:white;border:none;padding:3px 8px;border-radius:3px;cursor:pointer;font-size:11px;margin-right:5px;">
                        📝 Notiz
                    </button>
                    <button onclick="doQuickAction('send_reminder', '${event.id}')" 
                            style="background:#17a2b8;color:white;border:none;padding:3px 8px;border-radius:3px;cursor:pointer;font-size:11px;">
                        📧 Erinnerung
                    </button>
                </div>
            </div>
        `;
    }

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
                    ${quickActions}
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

// Quick Action Handler
async function doQuickAction(action, eventId, inviteeUuid = null) {
    const currentCustomerId = window.currentBookingCustomerId; // Set when modal opens

    try {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('event_id', eventId);
        formData.append('customer_id', currentCustomerId || '');
        if (inviteeUuid) formData.append('invitee_uuid', inviteeUuid);

        const response = await fetch('booking_actions.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            if (currentCustomerId) {
                bookingsCache.delete(currentCustomerId);
            }
            if (data.action === 'redirect') {
                window.open(data.url, '_blank');
                showQuickActionResult(data.message, 'success');
            } else {
                showQuickActionResult(data.message, 'success');
            }
        } else {
            showQuickActionResult(data.error, 'error');
        }
    } catch (error) {
        showQuickActionResult('Aktion fehlgeschlagen: ' + error.message, 'error');
    }
}

function showQuickActionResult(message, type) {
    const color = type === 'success' ? '#28a745' : '#dc3545';
    const result = document.createElement('div');
    result.style.cssText = `
        position:fixed;top:20px;right:20px;background:${color};color:white;
        padding:12px 16px;border-radius:8px;z-index:10001;
        box-shadow:0 4px 12px rgba(0,0,0,0.2);
    `;
    result.textContent = message;
    document.body.appendChild(result);

    setTimeout(() => result.remove(), 4000);
}

function showNoteDialog(eventId) {
    const note = prompt('Notiz zu diesem Termin hinzufügen:');
    if (note && note.trim()) {
        const formData = new FormData();
        formData.append('action', 'add_note');
        formData.append('event_id', eventId);
        formData.append('customer_id', window.currentBookingCustomerId || '');
        formData.append('note', note.trim());

        fetch('booking_actions.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showQuickActionResult(data.message, 'success');
                } else {
                    showQuickActionResult(data.error, 'error');
                }
            });
    }
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
<script>
// Today's Schedule functionality
async function loadTodaysSchedule() {
    try {
        const response = await fetch('todays_schedule.php');
        const data = await response.json();

        if (data.success) {
            displayTodaysSchedule(data);
        } else {
            document.getElementById('todaysSchedule').innerHTML =
                `<span style="color:#dc3545;">❌ ${data.error}</span>`;
        }
    } catch (error) {
        document.getElementById('todaysSchedule').innerHTML =
            `<span style="color:#dc3545;">❌ Fehler beim Laden der Termine</span>`;
    }
}

function displayTodaysSchedule(data) {
    const container = document.getElementById('todaysSchedule');

    if (data.events.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;color:#6c757d;padding:1rem;">
                🌅 Heute keine Termine geplant<br>
                <small>Zeit für Administrative Aufgaben!</small>
            </div>
        `;
        return;
    }

    let html = `
        <div style="margin-bottom:0.8rem;font-size:0.9em;color:#6c757d;">
            <strong>Heute (${data.today}):</strong> ${data.total_events} Termine | 
            <strong>Noch kommend:</strong> ${data.upcoming_today} | 
            <strong>Aktuelle Zeit:</strong> ${data.current_time}
        </div>
    `;

    data.events.forEach(event => {
        const statusClass = event.is_now ? 'border-left:4px solid #dc3545' : 
                           event.is_soon ? 'border-left:4px solid #ffc107' : 
                           'border-left:4px solid #28a745';

        const statusIcon = event.is_now ? '🔴 JETZT' : 
                          event.is_soon ? '🟡 BALD' : '🟢';

        html += `
            <div style="background:white;padding:0.8rem;margin-bottom:0.5rem;border-radius:4px;${statusClass};">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div style="flex:1;">
                        <strong style="color:#495057;">${event.start_time} - ${event.end_time}</strong>
                        <span style="margin-left:0.5rem;color:#6c757d;">(${event.duration})</span>
                        <div style="margin-top:0.3rem;">
                            <strong>${event.name}</strong><br>
                            <span style="color:#6c757d;">👤 ${event.invitee_name}</span>
                            ${event.invitee_email ? `<br><small style="color:#6c757d;">📧 ${event.invitee_email}</small>` : ''}
                        </div>
                    </div>
                    <div style="text-align:right;font-size:0.8em;">
                        <span>${statusIcon}</span><br>
                        <small style="color:#6c757d;">📍 ${event.location}</small>
                    </div>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

// Auto-load schedule when page loads
document.addEventListener('DOMContentLoaded', function() {
    loadTodaysSchedule();

    // Enhanced tooltips and hover effects
    document.querySelectorAll('td img[alt="Avatar"]').forEach(img => {
        img.addEventListener('mouseenter', function() {
            this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
        });
        img.addEventListener('mouseleave', function() {
            this.style.boxShadow = '';
        });
    });

    document.querySelectorAll('.pin-status-icon, td span[title]').forEach(element => {
        element.addEventListener('mouseenter', function() {
            if (this.title) {
                console.log('PIN Status:', this.title);
            }
        });
    });

    // Refresh every 5 minutes
    setInterval(loadTodaysSchedule, 5 * 60 * 1000);
});

// Refresh button for manual update
function refreshTodaysSchedule() {
    document.getElementById('todaysSchedule').innerHTML = 'Termine werden aktualisiert...';
    loadTodaysSchedule();
}
</script>
<script>
function confirmSendPin(email) {
    return confirm(
        `Wirklich einen neuen PIN an ${email} senden?\n\n` +
        `Ein neuer PIN überschreibt einen eventuell noch gültigen Code.`
    );
}

function confirmDelete(email) {
    return confirm(
        `⚠️ Kunde wirklich löschen?\n\n` +
        `E-Mail: ${email}\n\n` +
        `Diese Aktion löscht alle Kundendaten dauerhaft und kann nicht rückgängig gemacht werden.`
    );
}

function confirmBetaToggle(email, isActive) {
    const action = isActive ? 'deaktivieren' : 'aktivieren';
    return confirm(`Beta-Zugang für ${email} ${action}?`);
}
</script>
<script src="../pwa-update.js"></script>
</body>
</html>
