<?php
// admin/beta_messaging.php - Beta Messaging Dashboard with Feedback Analytics
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

function getPDO(): PDO
{
    $config = require __DIR__ . '/config.php';

    return new PDO(
        "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function ensureBetaSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS beta_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_admin BOOLEAN DEFAULT TRUE,
        to_customer_email VARCHAR(100) NOT NULL,
        message_text TEXT NOT NULL,
        message_type ENUM('info', 'success', 'warning', 'question') DEFAULT 'info',
        expects_response TINYINT(1) DEFAULT 0,
        response_question TEXT NULL,
        is_read TINYINT(1) DEFAULT 0,
        read_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer_email (to_customer_email),
        INDEX idx_unread (is_read, to_customer_email),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS beta_message_responses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        customer_email VARCHAR(100) NOT NULL,
        response_type ENUM('yes','no') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_response (message_id, customer_email),
        INDEX idx_response_email (customer_email),
        CONSTRAINT fk_beta_message_response FOREIGN KEY (message_id)
            REFERENCES beta_messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $pdo->exec("ALTER TABLE beta_messages ADD COLUMN expects_response TINYINT(1) DEFAULT 0 AFTER message_type");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE beta_messages ADD COLUMN response_question TEXT NULL AFTER expects_response");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE beta_messages ADD COLUMN read_at DATETIME NULL AFTER is_read");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE beta_message_responses ADD UNIQUE INDEX uniq_response (message_id, customer_email)");
    } catch (Throwable $e) {
    }
}

function computeStats(PDO $pdo, string $email): array
{
    $stats = [
        'total' => 0,
        'unread' => 0,
        'needs_response' => 0,
        'responses' => ['yes' => 0, 'no' => 0],
    ];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM beta_messages WHERE to_customer_email = ?');
    $stmt->execute([$email]);
    $stats['total'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM beta_messages WHERE to_customer_email = ? AND is_read = 0');
    $stmt->execute([$email]);
    $stats['unread'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM beta_messages WHERE to_customer_email = ? AND expects_response = 1');
    $stmt->execute([$email]);
    $stats['needs_response'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT response_type, COUNT(*) AS total FROM beta_message_responses WHERE customer_email = ? GROUP BY response_type');
    $stmt->execute([$email]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = $row['response_type'] ?? '';
        $count = (int) ($row['total'] ?? 0);
        if ($type === 'yes' || $type === 'no') {
            $stats['responses'][$type] = $count;
        }
    }

    return $stats;
}

try {
    $pdo = getPDO();
    ensureBetaSchema($pdo);
} catch (Throwable $e) {
    die('DB error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

if (($_GET['export'] ?? '') === 'responses') {
    $email = 'marcus@einfachstarten.jetzt';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="beta_responses.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Message ID', 'Frage', 'Antwort', 'Antwortdatum']);

    $exportStmt = $pdo->prepare('SELECT m.id, m.response_question, r.response_type, r.created_at
        FROM beta_message_responses r
        JOIN beta_messages m ON m.id = r.message_id
        WHERE r.customer_email = ?
        ORDER BY r.created_at DESC');
    $exportStmt->execute([$email]);

    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['response_question'],
            strtoupper($row['response_type']),
            $row['created_at'],
        ]);
    }

    fclose($output);
    exit;
}

$betaUserStmt = $pdo->prepare('SELECT * FROM customers WHERE email = ?');
$betaUserStmt->execute(['marcus@einfachstarten.jetzt']);
$betaUser = $betaUserStmt->fetch(PDO::FETCH_ASSOC);

if (!$betaUser) {
    echo '<div style="background:#f8d7da;color:#721c24;padding:1rem;border-radius:5px;margin:2rem;text-align:center">'
        . '❌ Beta-User marcus@einfachstarten.jetzt nicht gefunden. Bitte zuerst anlegen.'
        . '</div>';
    exit;
}

$success = '';
$errors = [];
$messageTemplates = [
    'Feature Release' => "Wir haben ein neues Beta-Feature aktiviert. Kannst du es in dieser Woche testen?",
    'Bug Check' => "Wir haben einen Fix eingespielt. Funktioniert alles wieder wie erwartet?",
    'Usability Feedback' => "Wie intuitiv war der neue Ablauf für dich? Bitte gib uns ein Ja/Nein Feedback.",
];

if (($_POST['action'] ?? '') === 'send') {
    $message = trim($_POST['message'] ?? '');
    $type = $_POST['type'] ?? 'info';
    $expectsResponse = !empty($_POST['expects_response']);
    $responseQuestion = trim($_POST['response_question'] ?? '');

    $allowedTypes = ['info', 'success', 'warning', 'question'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'info';
    }

    if ($expectsResponse && $responseQuestion === '') {
        $errors[] = 'Bitte eine Ja/Nein-Frage formulieren, wenn eine Antwort erwartet wird.';
    }

    if ($message === '') {
        $errors[] = 'Nachrichtentext darf nicht leer sein.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO beta_messages (to_customer_email, message_text, message_type, expects_response, response_question)
            VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            $betaUser['email'],
            $message,
            $type,
            $expectsResponse ? 1 : 0,
            $expectsResponse ? $responseQuestion : null,
        ]);
        $success = '✅ Beta-Nachricht erfolgreich gesendet!';
    }
}

$stats = computeStats($pdo, $betaUser['email']);
$messagesStmt = $pdo->prepare('SELECT m.*, r.response_type, r.created_at AS responded_at
    FROM beta_messages m
    LEFT JOIN beta_message_responses r ON r.message_id = m.id AND r.customer_email = ?
    WHERE m.to_customer_email = ?
    ORDER BY m.created_at DESC
    LIMIT 50');
$messagesStmt->execute([$betaUser['email'], $betaUser['email']]);
$messageHistory = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);

$responseTimelineStmt = $pdo->prepare('SELECT r.*, m.message_text, m.response_question
    FROM beta_message_responses r
    JOIN beta_messages m ON m.id = r.message_id
    WHERE r.customer_email = ?
    ORDER BY r.created_at DESC
    LIMIT 20');
$responseTimelineStmt->execute([$betaUser['email']]);
$responseTimeline = $responseTimelineStmt->fetchAll(PDO::FETCH_ASSOC);

$totalResponses = array_sum($stats['responses']);
$yesPercent = $totalResponses > 0 ? round(($stats['responses']['yes'] / $totalResponses) * 100) : 0;
$noPercent = $totalResponses > 0 ? round(($stats['responses']['no'] / $totalResponses) * 100) : 0;
$engagementRate = $stats['needs_response'] > 0 ? round(($totalResponses / $stats['needs_response']) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>🧪 Beta Messaging Dashboard</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 2rem; background: #f8fafc; color: #1f2937; }
        a { color: #4a90b8; }
        .container { max-width: 1100px; margin: 0 auto; }
        .header { background: linear-gradient(135deg,#667eea,#764ba2); color: white; padding: 2rem; border-radius: 16px; margin-bottom: 2rem; }
        .grid { display: grid; gap: 1.25rem; }
        .grid-3 { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .grid-2 { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
        .card { background: white; border-radius: 14px; padding: 1.25rem; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); border: 1px solid rgba(148, 163, 184, 0.2); }
        .card h3 { margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; font-size: 1.05rem; }
        .badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
        .badge-info { background: rgba(59,130,246,0.1); color: #2563eb; }
        .badge-success { background: rgba(16,185,129,0.1); color: #059669; }
        .badge-warning { background: rgba(245,158,11,0.12); color: #d97706; }
        .badge-danger { background: rgba(239,68,68,0.12); color: #dc2626; }
        .stat { display: flex; align-items: baseline; gap: 0.5rem; }
        .stat strong { font-size: 1.75rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; font-weight: 600; margin-bottom: 0.35rem; }
        textarea { width: 100%; min-height: 140px; border-radius: 10px; border: 1px solid #cbd5f5; padding: 0.75rem; font: inherit; }
        select, input[type="text"] { width: 100%; border-radius: 10px; border: 1px solid #cbd5f5; padding: 0.65rem; font: inherit; }
        button { background: #4a90b8; color: white; border: none; border-radius: 10px; padding: 0.75rem 1.5rem; font-size: 1rem; cursor: pointer; box-shadow: 0 8px 16px rgba(74, 144, 184, 0.2); }
        button:hover { background: #2563eb; }
        .success { background: #dcfce7; color: #166534; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #b91c1c; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px solid #fecaca; }
        .history-item { border-left: 3px solid #4a90b8; padding-left: 1rem; margin-bottom: 1rem; }
        .history-item p { margin: 0.25rem 0; }
        .timeline { border-left: 2px solid #cbd5f5; padding-left: 1rem; }
        .timeline-entry { position: relative; margin-bottom: 1rem; padding-left: 0.5rem; }
        .timeline-entry::before { content: ''; position: absolute; left: -1.25rem; top: 0.5rem; width: 0.75rem; height: 0.75rem; border-radius: 50%; background: #4a90b8; }
        .template-select { display: flex; gap: 0.75rem; align-items: center; }
        .template-select select { flex: 1; }
        .actions { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .actions a { text-decoration: none; }
        .pill { padding: 0.4rem 0.9rem; border-radius: 999px; background: rgba(148, 163, 184, 0.15); font-size: 0.8rem; }
        @media (max-width: 768px) { body { margin: 1rem; } }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🧪 Beta Messaging System</h1>
        <p>Strukturiertes Feedback sammeln, Nachrichten verwalten und Tester-Engagement messen.</p>
    </div>

    <p><a href="dashboard.php">← Zurück zum Dashboard</a></p>
    <div class="actions">
        <span class="pill">Beta-Tester: <?= htmlspecialchars($betaUser['first_name'] . ' ' . $betaUser['last_name']) ?></span>
        <span class="pill">Status: <?= htmlspecialchars($betaUser['status']) ?></span>
        <span class="pill">Registriert am <?= htmlspecialchars($betaUser['created_at']) ?></span>
        <a class="pill" href="?export=responses">⬇️ Responses exportieren</a>
    </div>

    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="error">
            <strong>Bitte prüfen:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="grid grid-3" style="margin-bottom:1.5rem;">
        <div class="card">
            <h3>📬 Nachrichten gesamt</h3>
            <div class="stat"><strong><?= $stats['total'] ?></strong><span>gesendet</span></div>
            <span class="badge badge-info">📥 Davon <?= $stats['unread'] ?> ungelesen</span>
        </div>
        <div class="card">
            <h3>❓ Feedback erwartet</h3>
            <div class="stat"><strong><?= $stats['needs_response'] ?></strong><span>Fragen aktiv</span></div>
            <span class="badge badge-warning">Engagement: <?= $engagementRate ?>%</span>
        </div>
        <div class="card">
            <h3>✅ Response-Quote</h3>
            <div class="stat"><strong><?= $totalResponses ?></strong><span>Antworten</span></div>
            <span class="badge badge-success">Ja: <?= $yesPercent ?>% • Nein: <?= $noPercent ?>%</span>
        </div>
    </div>

    <div class="card" style="margin-bottom:2rem;">
        <h3>📤 Nachricht an Beta-User senden</h3>
        <form method="post">
            <input type="hidden" name="action" value="send">

            <div class="form-group">
                <label for="messageType">Nachrichten-Typ</label>
                <select name="type" id="messageType">
                    <option value="info">ℹ️ Information</option>
                    <option value="success">✅ Erfolg</option>
                    <option value="warning">⚠️ Hinweis/Warnung</option>
                    <option value="question">❓ Feedback-Frage</option>
                </select>
            </div>

            <div class="form-group template-select">
                <label for="templateSelect" style="margin-bottom:0;">Vorlage</label>
                <select id="templateSelect">
                    <option value="">— Vorlage auswählen —</option>
                    <?php foreach ($messageTemplates as $label => $template): ?>
                        <option value="<?= htmlspecialchars($template) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="applyTemplate" style="padding:0.6rem 1rem;">Übernehmen</button>
            </div>

            <div class="form-group">
                <label for="messageText">Nachricht</label>
                <textarea name="message" id="messageText" placeholder="Beta-Nachricht eingeben..."></textarea>
            </div>

            <div class="form-group">
                <label><input type="checkbox" name="expects_response" id="expectsResponse"> Ja/Nein Antwort einfordern</label>
                <input type="text" name="response_question" id="responseQuestion" placeholder="Welche Frage soll beantwortet werden?" style="display:none;margin-top:0.5rem;">
            </div>

            <button type="submit">📨 Nachricht senden</button>
        </form>
    </div>
    <div class="grid grid-2" style="margin-bottom:2rem;">
        <div class="card">
            <h3>📈 Response-Statistiken</h3>
            <p>Verteilung der Ja/Nein-Antworten inkl. Engagement-Quote.</p>
            <ul style="list-style:none;padding:0;margin:1rem 0;display:flex;flex-direction:column;gap:0.5rem;">
                <li><span class="badge badge-success">✅ Ja</span> <?= $stats['responses']['yes'] ?> Antworten</li>
                <li><span class="badge badge-danger">❌ Nein</span> <?= $stats['responses']['no'] ?> Antworten</li>
                <li><span class="badge badge-warning">📊 Engagement</span> <?= $engagementRate ?>% aller Fragen beantwortet</li>
            </ul>
            <p style="font-size:0.85rem;color:#6b7280;">Die Engagement-Quote berechnet sich aus gesendeten Antworten im Verhältnis zu allen Fragen, die eine Rückmeldung erwarten.</p>
        </div>

        <div class="card">
            <h3>🧾 Response-Zeitleiste</h3>
            <?php if (!$responseTimeline): ?>
                <p style="color:#6b7280;">Noch keine Antworten eingegangen.</p>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($responseTimeline as $entry): ?>
                        <div class="timeline-entry">
                            <div style="font-weight:600;">Antwort: <?= strtoupper(htmlspecialchars($entry['response_type'])) ?></div>
                            <div style="font-size:0.85rem; margin:0.25rem 0;">Frage: <?= htmlspecialchars($entry['response_question'] ?? '—') ?></div>
                            <div style="font-size:0.8rem;color:#64748b;"><?= htmlspecialchars($entry['created_at']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3>🗂️ Nachrichten-Historie</h3>
        <?php if (!$messageHistory): ?>
            <p style="color:#6b7280;">Es wurden noch keine Beta-Nachrichten verschickt.</p>
        <?php else: ?>
            <?php foreach ($messageHistory as $msg): ?>
                <div class="history-item">
                    <p style="font-size:0.8rem;color:#6b7280;">
                        <?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?> •
                        <?php
                        $icons = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'question' => '❓'];
                        echo $icons[$msg['message_type']] ?? 'ℹ️';
                        ?> <?= strtoupper(htmlspecialchars($msg['message_type'])) ?>
                        <?= $msg['is_read'] ? '• ✓ gelesen' : '• ● ungelesen' ?>
                    </p>
                    <p><?= nl2br(htmlspecialchars($msg['message_text'])) ?></p>
                    <?php if (!empty($msg['expects_response'])): ?>
                        <p><strong>Frage:</strong> <?= htmlspecialchars($msg['response_question'] ?? '') ?></p>
                        <p><strong>Antwort:</strong>
                            <?php if ($msg['response_type']): ?>
                                <?= strtoupper(htmlspecialchars($msg['response_type'])) ?> am <?= htmlspecialchars($msg['responded_at']) ?>
                            <?php else: ?>
                                <span style="color:#d97706;">Wartet auf Feedback</span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    const expectsResponseCheckbox = document.getElementById('expectsResponse');
    const responseQuestionInput = document.getElementById('responseQuestion');
    const templateSelect = document.getElementById('templateSelect');
    const applyTemplateButton = document.getElementById('applyTemplate');
    const messageText = document.getElementById('messageText');

    function toggleResponseQuestion() {
        if (expectsResponseCheckbox.checked) {
            responseQuestionInput.style.display = 'block';
            responseQuestionInput.required = true;
        } else {
            responseQuestionInput.style.display = 'none';
            responseQuestionInput.required = false;
            responseQuestionInput.value = '';
        }
    }

    expectsResponseCheckbox.addEventListener('change', toggleResponseQuestion);
    toggleResponseQuestion();

    applyTemplateButton.addEventListener('click', () => {
        const template = templateSelect.value;
        if (template) {
            messageText.value = template;
        }
    });
</script>
</body>
</html>
