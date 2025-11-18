<?php
/**
 * 🧪 BETA: Calendly Token Check
 *
 * Checks if CALENDLY_TOKEN is properly configured
 * Admin-only access
 */

session_start();

if (empty($_SESSION['admin'])) {
    die('❌ Admin access required. <a href="../admin/login.php">Login</a>');
}

$token = getenv('CALENDLY_TOKEN');
$isConfigured = !empty($token) && $token !== 'PASTE_YOUR_TOKEN_HERE';

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>🧪 Calendly Token Check</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
            background: #f7fafc;
        }
        .container {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .status {
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .success {
            background: #e6f4ea;
            color: #276749;
            border-left: 4px solid #48bb78;
        }
        .error {
            background: #fee;
            color: #9b2c2c;
            border-left: 4px solid #f56565;
        }
        pre {
            background: #1a202c;
            color: #48bb78;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 0.85rem;
        }
        .instructions {
            background: #fef3c7;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #f59e0b;
            margin: 20px 0;
        }
        .btn {
            display: inline-block;
            background: #2b6cb0;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            margin-top: 20px;
        }
        code {
            background: #e5e7eb;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Calendly Token Status</h1>

        <?php if ($isConfigured): ?>
            <div class="status success">
                <h3>✅ Token ist konfiguriert</h3>
                <p><strong>Token Prefix:</strong> <?= htmlspecialchars(substr($token, 0, 20)) ?>...</p>
                <p><strong>Token Length:</strong> <?= strlen($token) ?> Zeichen</p>
                <p>Das Last-Minute Feature kann jetzt Termine von Calendly abrufen.</p>
            </div>

            <div class="instructions">
                <h3>🧪 Test durchführen</h3>
                <p>Um zu testen, ob der Token funktioniert, führe aus:</p>
                <pre>php admin/last_minute_checker.php</pre>
                <p>Prüfe dann: <code>admin/logs/last_minute_checker.log</code></p>
            </div>
        <?php else: ?>
            <div class="status error">
                <h3>❌ Token ist NICHT konfiguriert</h3>
                <p>Der CALENDLY_TOKEN ist noch nicht gesetzt.</p>
            </div>

            <div class="instructions">
                <h3>📝 Setup-Anleitung</h3>

                <h4>Option 1: Via Server Environment (Empfohlen)</h4>
                <p>Setze die Environment Variable auf dem Server:</p>
                <pre>export CALENDLY_TOKEN="dein_token_hier"</pre>

                <h4>Option 2: Via .htaccess (Apache)</h4>
                <p>Füge in der .htaccess hinzu:</p>
                <pre>SetEnv CALENDLY_TOKEN "dein_token_hier"</pre>

                <h4>Option 3: Via PHP-FPM Pool Config</h4>
                <p>In der php-fpm pool config (<code>/etc/php/8.x/fpm/pool.d/www.conf</code>):</p>
                <pre>env[CALENDLY_TOKEN] = "dein_token_hier"</pre>

                <h4>Token holen:</h4>
                <ol>
                    <li>Gehe zu <a href="https://calendly.com/integrations/api_webhooks" target="_blank">Calendly API Settings</a></li>
                    <li>Erstelle einen "Personal Access Token"</li>
                    <li>Kopiere den Token</li>
                    <li>Setze ihn als Environment Variable</li>
                </ol>
            </div>
        <?php endif; ?>

        <h3>🔒 Sicherheit</h3>
        <ul>
            <li>✅ Token wird nur von Beta-Feature verwendet</li>
            <li>✅ Token wird nur für Beta-User abgerufen</li>
            <li>✅ Keine Auswirkungen auf normale User</li>
            <li>✅ Token ist nicht im Code sichtbar</li>
        </ul>

        <a href="../admin/dashboard.php" class="btn">← Zurück zum Dashboard</a>
    </div>
</body>
</html>
