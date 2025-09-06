<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}

echo "<h2>Email Test Utility</h2>";

echo "<h3>Server Environment</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Setting</th><th>Value</th></tr>";
echo "<tr><td>PHP Version</td><td>" . phpversion() . "</td></tr>";
echo "<tr><td>allow_url_fopen</td><td>" . (ini_get('allow_url_fopen') ? 'Enabled' : 'Disabled') . "</td></tr>";
echo "<tr><td>cURL Available</td><td>" . (function_exists('curl_init') ? 'Yes' : 'No') . "</td></tr>";
echo "<tr><td>OpenSSL</td><td>" . (extension_loaded('openssl') ? 'Available' : 'Missing') . "</td></tr>";
echo "<tr><td>User Agent</td><td>" . ($_SERVER['HTTP_USER_AGENT'] ?? 'Not set') . "</td></tr>";
echo "</table>";

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_email = $_POST['test_email'] ?? 'marcus@einfachstarten.jetzt';

    // Temporary mail() implementation until SMTP/PHPMailer is integrated
    $subject = 'Test-E-Mail von Anna Braun Lerncoaching';
    $message = "Liebe/r Tester/in,\n\n";
    $message .= "diese Test-E-Mail wurde erfolgreich versendet.\n\n";
    $message .= "📧 Zeitpunkt: " . date('d.m.Y um H:i:s') . " Uhr\n";
    $message .= "🖥️ Server: " . $_SERVER['SERVER_NAME'] . "\n";
    $message .= "🐘 PHP Version: " . phpversion() . "\n\n";
    $message .= "Falls Sie diese E-Mail erhalten, funktioniert das E-Mail-System korrekt.\n\n";
    $message .= "Mit freundlichen Grüßen\n";
    $message .= "Anna Braun Lerncoaching System";

    $headers = 'From: Anna Braun Lerncoaching <info@einfachlernen.jetzt>' . "\r\n" .
               'Reply-To: info@einfachlernen.jetzt' . "\r\n" .
               'Return-Path: info@einfachlernen.jetzt' . "\r\n" .
               'Message-ID: <' . uniqid() . '.' . time() . '@einfachlernen.jetzt>' . "\r\n" .
               'Date: ' . date('r') . "\r\n" .
               'X-Mailer: Anna Braun CMS v1.0' . "\r\n" .
               'X-Priority: 3 (Normal)' . "\r\n" .
               'Importance: Normal' . "\r\n" .
               'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
               'Content-Transfer-Encoding: 8bit' . "\r\n" .
               'MIME-Version: 1.0' . "\r\n" .
               'Organization: Anna Braun Lerncoaching';

    $result = mail($test_email, $subject, $message, $headers);

    if($result) {
        echo "<p style='color:green'>✅ mail() returned TRUE - check email delivery</p>";
    } else {
        echo "<p style='color:red'>❌ mail() returned FALSE</p>";
        $error = error_get_last();
        echo "<p>Last error: " . ($error['message'] ?? 'No error details') . "</p>";
    }
}
?>

<form method="post">
    <label>Test Email Address:</label><br>
    <input type="email" name="test_email" value="marcus@einfachstarten.jetzt" required><br><br>
    <button type="submit">Send Test Email (mail() function)</button>
</form>

<p><a href="dashboard.php">← Back to Dashboard</a></p>

