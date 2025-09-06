<?php
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

$log_file = __DIR__ . '/logs/email_delivery.log';
$entries = [];

if (file_exists($log_file)) {
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines); // Neueste zuerst

    foreach (array_slice($lines, 0, 100) as $line) { // Letzte 100 Einträge
        $entry = json_decode($line, true);
        if ($entry) {
            $entries[] = $entry;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Email Delivery Log</title>
    <style>
        body{font-family:Arial;margin:2em}
        table{width:100%;border-collapse:collapse}
        th,td{border:1px solid #ccc;padding:0.5em;text-align:left}
        th{background:#4a90b8;color:#fff}
        .success{color:#28a745;font-weight:bold}
        .failed{color:#dc3545;font-weight:bold}
    </style>
</head>
<body>
    <h2 style="color:#4a90b8">📧 Email Delivery Log</h2>
    <p><a href="dashboard.php">← Zurück zum Dashboard</a></p>

    <table>
        <tr>
            <th>Timestamp</th>
            <th>Email</th>
            <th>Status</th>
            <th>Details</th>
            <th>IP</th>
        </tr>
        <?php foreach ($entries as $entry): ?>
        <tr>
            <td><?= htmlspecialchars($entry['timestamp']) ?></td>
            <td><?= htmlspecialchars($entry['email']) ?></td>
            <td class="<?= $entry['success'] ? 'success' : 'failed' ?>">
                <?= $entry['success'] ? '✅ Erfolg' : '❌ Fehler' ?>
            </td>
            <td><?= htmlspecialchars($entry['details']) ?></td>
            <td><?= htmlspecialchars($entry['ip']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <?php if (empty($entries)): ?>
        <p>Keine Email-Logs gefunden.</p>
    <?php endif; ?>
</body>
</html>
