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
    $duration_minutes = $config['PIN_DURATION_MINUTES'] ?? 15;

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

        // Enable HTML and UTF-8
        $mail->isHTML(true);
        $mail->Subject = '🔐 Dein Login-Code für Anna Braun Lerncoaching';
        $mail->CharSet = 'UTF-8';

        // Additional headers for better deliverability
        $mail->addCustomHeader('X-Mailer', 'Anna Braun Lerncoaching System');
        $mail->addCustomHeader('X-Priority', '1');
        $mail->addCustomHeader('Importance', 'High');

        // Premium HTML email template
        $html_message = '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dein Login-Code</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            background-color: #f8fafc;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .email-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px 20px;
            text-align: center;
            color: white;
        }
        .email-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }
        .email-header .subtitle {
            margin: 8px 0 0 0;
            font-size: 16px;
            opacity: 0.9;
        }
        .email-body {
            padding: 40px 30px;
            color: #2d3748;
        }
        .greeting {
            font-size: 20px;
            margin: 0 0 20px 0;
            color: #2d3748;
        }
        .message {
            font-size: 16px;
            margin: 0 0 30px 0;
            color: #4a5568;
        }
        .pin-container {
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            margin: 30px 0;
        }
        .pin-label {
            font-size: 14px;
            color: #718096;
            margin: 0 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        .pin-code {
            font-size: 36px;
            font-weight: 800;
            color: #667eea;
            font-family: "Courier New", monospace;
            letter-spacing: 8px;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .pin-expiry {
            font-size: 14px;
            color: #e53e3e;
            margin: 15px 0 0 0;
            font-weight: 500;
        }
        .login-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            padding: 16px 32px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
        }
        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }
        .info-box {
            background: #fff5f5;
            border-left: 4px solid #fc8181;
            padding: 16px 20px;
            margin: 30px 0;
            border-radius: 0 8px 8px 0;
        }
        .info-box p {
            margin: 0;
            color: #744210;
            font-size: 14px;
        }
        .footer {
            background: #f7fafc;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        .footer .signature {
            color: #2d3748;
            font-size: 16px;
            margin-bottom: 20px;
        }
        .footer .contact {
            color: #718096;
            font-size: 14px;
            line-height: 1.8;
        }
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        .brain-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        @media (max-width: 600px) {
            .email-container {
                margin: 10px;
                border-radius: 8px;
            }
            .email-body {
                padding: 30px 20px;
            }
            .pin-code {
                font-size: 28px;
                letter-spacing: 4px;
            }
            .login-button {
                display: block;
                text-align: center;
                margin: 20px auto;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <div class="brain-icon">🧠</div>
            <h1>Anna Braun Lerncoaching</h1>
            <div class="subtitle">Dein personalisierter Lernbereich</div>
        </div>
        
        <div class="email-body">
            <h2 class="greeting">Lieber ' . htmlspecialchars($to_name) . ',</h2>
            
            <p class="message">
                hier ist dein Login-Code für das Kundenkonto und den personalisierten Lernbereich!
            </p>
            
            <div class="pin-container">
                <p class="pin-label">Dein Login-Code</p>
                <p class="pin-code">' . $pin . '</p>
                <p class="pin-expiry">⏰ Gültig bis ' . date('d.m.Y um H:i', strtotime($expires)) . ' Uhr</p>
            </div>
            
            <div style="text-align: center;">
                <a href="https://einfachstarten.jetzt/einfachlernen/login.php" class="login-button">
                    🚀 Jetzt einloggen
                </a>
            </div>
            
            <div class="info-box">
                <p><strong>Wichtiger Hinweis:</strong> Aus Sicherheitsgründen ist dieser Code nur ' . $duration_minutes . ' Minuten gültig. Falls du diesen Code nicht angefordert hast, kannst du diese E-Mail einfach ignorieren.</p>
            </div>
            
            <p style="color: #718096; font-size: 14px; margin-top: 30px;">
                Bei Fragen stehe ich dir gerne zur Verfügung! Einfach auf diese E-Mail antworten.
            </p>
        </div>
        
        <div class="footer">
            <div class="signature">
                <strong>Liebe Grüße</strong><br>
                Anna Braun<br>
                <em>Ganzheitliches Lerncoaching</em>
            </div>
            
            <div class="contact">
                <strong>Anna Braun Lerncoaching</strong><br>
                E-Mail: <a href="mailto:termine@einfachstarten.jetzt">termine@einfachstarten.jetzt</a><br>
                Web: <a href="https://www.einfachlernen.jetzt">www.einfachlernen.jetzt</a><br><br>
                <small>Diese E-Mail wurde automatisch generiert.</small>
            </div>
        </div>
    </div>
</body>
</html>';

        // Plain text version for email clients that don't support HTML
        $plain_message = "Lieber {$to_name},\n\n";
        $plain_message .= "hier ist dein Login-Code für das Kundenkonto und den personalisierten Lernbereich!\n\n";
        $plain_message .= "🔐 Dein Login-Code: {$pin}\n";
        $plain_message .= "⏰ Gültig bis: " . date('d.m.Y um H:i', strtotime($expires)) . " Uhr\n\n";
        $plain_message .= "► Zum Login: https://einfachstarten.jetzt/einfachlernen/login.php\n\n";
        $plain_message .= "Aus Sicherheitsgründen ist dieser Code nur {$duration_minutes} Minuten gültig.\n";
        $plain_message .= "Falls du diesen Code nicht angefordert hast, kannst du diese E-Mail ignorieren.\n\n";
        $plain_message .= "Bei Fragen stehe ich dir gerne zur Verfügung.\n\n";
        $plain_message .= "Liebe Grüße\n";
        $plain_message .= "Anna Braun\n";
        $plain_message .= "Ganzheitliches Lerncoaching\n\n";
        $plain_message .= "---\n";
        $plain_message .= "Anna Braun Lerncoaching\n";
        $plain_message .= "E-Mail: termine@einfachstarten.jetzt\n";
        $plain_message .= "Web: www.einfachlernen.jetzt\n";
        $plain_message .= "Diese E-Mail wurde automatisch generiert.";

        $mail->Body = $html_message;
        $mail->AltBody = $plain_message;

        $mail->send();
        return [true, 'Email sent successfully via SMTP'];

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

