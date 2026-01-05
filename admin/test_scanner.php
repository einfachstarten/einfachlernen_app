<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}
if(isset($_SESSION['LAST_ACTIVITY']) && time() - $_SESSION['LAST_ACTIVITY'] > 1800){session_unset();session_destroy();header('Location: login.php');exit;}
$_SESSION['LAST_ACTIVITY'] = time();

require_once __DIR__ . '/calendly_email_scanner.php';

$result = null;
$error = null;

// Handle scan request
if (isset($_POST['scan'])) {
    try {
        $token = getenv('CALENDLY_TOKEN');
        $org_uri = getenv('CALENDLY_ORG_URI');

        if (!$token || !$org_uri) {
            throw new Exception('Missing CALENDLY_TOKEN or CALENDLY_ORG_URI environment variables');
        }

        $pdo = getPDO();
        $scanner = new CalendlyEmailScanner($token, $org_uri, $pdo);
        $result = $scanner->scanAndSaveEmails();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get recent customers
function getRecentCustomers($limit = 10) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT email, first_name, last_name, created_at FROM customers ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$recent_customers = getRecentCustomers(10);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendly Email Scanner Test</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .section {
            margin-bottom: 30px;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 8px;
        }
        .section h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 15px;
        }
        .btn {
            background: #4CAF50;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #45a049;
        }
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 6px;
            font-size: 14px;
        }
        .result.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .result.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .stat {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .stat:last-child {
            border-bottom: none;
        }
        .stat-label {
            font-weight: 500;
            color: #666;
        }
        .stat-value {
            font-weight: 600;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        th {
            background: #f5f5f5;
            font-weight: 600;
            color: #333;
            font-size: 13px;
        }
        td {
            font-size: 14px;
            color: #666;
        }
        .env-check {
            margin-bottom: 20px;
            padding: 12px;
            border-radius: 6px;
            font-size: 14px;
        }
        .env-check.ok {
            background: #d4edda;
            color: #155724;
        }
        .env-check.missing {
            background: #fff3cd;
            color: #856404;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #666;
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover {
            color: #333;
        }
        code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>

        <h1>📧 Calendly Email Scanner</h1>
        <p class="subtitle">Test the Calendly email extraction and database sync</p>

        <!-- Environment Check -->
        <div class="section">
            <h2>Environment Configuration</h2>
            <?php
            $token_set = !empty(getenv('CALENDLY_TOKEN'));
            $org_uri_set = !empty(getenv('CALENDLY_ORG_URI'));
            $all_set = $token_set && $org_uri_set;
            ?>
            <div class="env-check <?php echo $all_set ? 'ok' : 'missing'; ?>">
                <?php if ($all_set): ?>
                    ✅ Environment variables configured correctly
                <?php else: ?>
                    ⚠️ Missing environment variables:
                    <?php if (!$token_set): ?>
                        <br>• <code>CALENDLY_TOKEN</code> not set
                    <?php endif; ?>
                    <?php if (!$org_uri_set): ?>
                        <br>• <code>CALENDLY_ORG_URI</code> not set
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Scanner Controls -->
        <div class="section">
            <h2>Run Scanner</h2>
            <form method="POST">
                <button type="submit" name="scan" class="btn" <?php echo !$all_set ? 'disabled' : ''; ?>>
                    🔄 Scan Calendly Events & Save Emails
                </button>
            </form>

            <?php if ($result): ?>
                <div class="result success">
                    <strong>✅ Scan Completed Successfully</strong>
                    <div style="margin-top: 10px;">
                        <div class="stat">
                            <span class="stat-label">Events Scanned:</span>
                            <span class="stat-value"><?php echo $result['events_scanned'] ?? 0; ?></span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Emails Found:</span>
                            <span class="stat-value"><?php echo $result['emails_found'] ?? 0; ?></span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">New Customers:</span>
                            <span class="stat-value"><?php echo $result['new_count'] ?? 0; ?></span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Already Existing:</span>
                            <span class="stat-value"><?php echo $result['existing_count'] ?? 0; ?></span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Duration:</span>
                            <span class="stat-value"><?php echo $result['duration_seconds'] ?? 0; ?>s</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="result error">
                    <strong>❌ Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Customers -->
        <div class="section">
            <h2>Recent Customers (Last 10)</h2>
            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_customers)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #999;">No customers found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_customers as $customer): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                <td><?php echo htmlspecialchars($customer['first_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($customer['last_name'] ?? '-'); ?></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($customer['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
