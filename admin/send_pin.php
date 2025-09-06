<?php
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

session_start();
$config = require __DIR__ . '/config.php';
$duration_minutes = $config['PIN_DURATION_MINUTES'] ?? 15;
require_once __DIR__ . '/ActivityLogger.php';

if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

function respond(bool $success, string $message, array $extra = []): void
{
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    } else {
        $param = $success ? 'success' : 'error';
        header('Location: dashboard.php?' . $param . '=' . urlencode($message));
    }
    exit;
}

function logPinSendError(string $customer_email, string $error_type, string $details): void
{
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] {$error_type} ({$customer_email}): {$details}\n";
    error_log($log_entry, 3, $log_dir . '/pin_send_errors.log');
}

function getPDO(): PDO
{
    $config = require __DIR__ . '/config.php';
    try {
        return new PDO(
            "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        error_log('DB Error in send_pin.php: ' . $e->getMessage());
        respond(false, 'Database connection failed');
    }
}

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
        return [true, ''];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['customer_id'])) {
    $cid = (int)$_POST['customer_id'];
    $pdo = getPDO();
    $logger = new ActivityLogger($pdo);

    $stmt = $pdo->prepare('SELECT email, first_name FROM customers WHERE id = ?');
    $stmt->execute([$cid]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        respond(false, 'Customer not found');
    }

    $pin = sprintf('%06d', random_int(100000, 999999));
    $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', strtotime("+{$duration_minutes} minutes"));

    $upd = $pdo->prepare('UPDATE customers SET pin = ?, pin_expires = ? WHERE id = ?');
    if (!$upd->execute([$pin_hash, $expires, $cid])) {
        logPinSendError($customer['email'], 'db_update_failed', json_encode($upd->errorInfo()));
        respond(false, 'Failed to store PIN');
    }

    list($mail_sent, $mail_message) = sendSMTPEmail(
        $customer['email'],
        $customer['first_name'],
        $pin,
        $expires
    );

    if ($mail_sent) {
        $logger->logActivity($cid, 'pin_email_sent', [
            'email_template' => 'premium_html',
            'email_format' => 'html_with_fallback',
            'pin_expires_at' => $expires,
            'email_subject' => '🔐 Dein Login-Code für Anna Braun Lerncoaching'
        ]);
    }

    $logger->logActivity($cid, 'pin_request', [
        'pin_generation_method' => 'admin_sent',
        'email_sent' => $mail_sent,
        'pin_expires_at' => $expires,
        'requested_by_admin' => $_SESSION['admin']
    ]);

    if (!$mail_sent) {
        logPinSendError($customer['email'], 'email_failed', $mail_message);
        respond(false, "Failed to send PIN to {$customer['email']}");
    }

    respond(true, "PIN sent to {$customer['email']}");
}

respond(false, 'Invalid request - missing customer_id');

