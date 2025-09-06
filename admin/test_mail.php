<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}

// Include PHPMailer classes
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function sendSMTPEmail(string $to_email, string $to_name, string $pin, string $expires): array
{
    $config = require __DIR__ . '/config.php';
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['SMTP_USERNAME'];
        $mail->Password = $config['SMTP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $config['SMTP_PORT'];
        $mail->Timeout = $config['SMTP_TIMEOUT'];

        $mail->setFrom($config['SMTP_FROM_EMAIL'], $config['SMTP_FROM_NAME']);
        $mail->addAddress($to_email, $to_name);
        $mail->addReplyTo($config['SMTP_FROM_EMAIL'], $config['SMTP_FROM_NAME']);

        $mail->isHTML(false);
        $mail->Subject = 'Dein Login-Code für Anna Braun Lerncoaching';
        $mail->CharSet = 'UTF-8';

        $message = "Liebe/r {$to_name},\n\n";
        $message .= "du hast einen Login-Code für dein Kundenkonto angefordert.\n\n";
        $message .= "🔐 Dein Login-Code: {$pin}\n";
        $message .= "⏰ Gültig bis: " . date('d.m.Y \u\m H:i', strtotime($expires)) . " Uhr\n\n";
        $message .= "► Zum Login: https://einfachstarten.jetzt/einfachlernen/login.php\n\n";
        $message .= "Aus Sicherheitsgründen ist dieser Code nur 15 Minuten gültig.\n";
        $message .= "Falls du diesen Code nicht angefordert hast, kannst du diese E-Mail ignorieren.\n\n";
        $message .= "Bei Fragen stehe ich dir gerne zur Verfügung.\n\n";
        $message .= "Mit freundlichen Grüßen\n";
        $message .= "Anna Braun\n";
        $message .= "Ganzheitliches Lerncoaching\n\n";
        $message .= "---\n";
        $message .= "Anna Braun Lerncoaching\n";
        $message .= "E-Mail: termine@einfachstarten.jetzt\n";
        $message .= "Web: www.einfachlernen.jetzt\n";
        $message .= "Diese E-Mail wurde automatisch generiert.";

        $mail->Body = $message;

        $mail->send();
        return [true, ''];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}

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
    $use_smtp = isset($_POST['use_smtp']);

    if ($use_smtp) {
        echo "<h3>Testing World4you SMTP...</h3>";
        list($success, $message) = sendSMTPEmail(
            $test_email,
            'Test User',
            '123456',
            date('Y-m-d H:i:s', strtotime('+15 minutes'))
        );

        if($success) {
            echo "<p style='color:green; font-weight:bold;'>✅ SMTP test successful!</p>";
        } else {
            echo "<p style='color:red; font-weight:bold;'>❌ SMTP test failed: " . htmlspecialchars($message) . "</p>";
        }
    } else {
        // Keep existing mail() test
        $subject = 'Test-E-Mail von Anna Braun Lerncoaching';
        $message = "Liebe/r Tester/in,\n\n";
        $message .= "diese Test-E-Mail wurde erfolgreich versendet.\n\n";
        $message .= "📧 Zeitpunkt: " . date('d.m.Y um H:i:s') . " Uhr\n";
        $message .= "🖥️ Server: " . $_SERVER['SERVER_NAME'] . "\n";
        $message .= "🐘 PHP Version: " . phpversion() . "\n\n";
        $message .= "Falls du diese E-Mail erhältst, funktioniert das E-Mail-System korrekt.\n\n";
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
}

echo "<form method='post'>";
echo "<label>Test Email Address:</label><br>";
echo "<input type='email' name='test_email' value='marcus@einfachstarten.jetzt' required style='width:300px;padding:5px;'><br><br>";
echo "<label style='display:block;margin:10px 0;'>";
echo "<input type='checkbox' name='use_smtp' checked> Use World4you SMTP (Recommended)";
echo "</label>";
echo "<button type='submit' style='padding:10px 20px;'>Send Test Email</button>";
echo "</form>";

echo "<p><a href='dashboard.php'>← Back to Dashboard</a></p>";

