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

function logEmailDelivery($customer_email, $success, $details = '') {
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0777, true);
    }

    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'email'     => $customer_email,
        'success'   => $success,
        'details'   => $details,
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent'=> $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];

    $log_file = $log_dir . '/email_delivery.log';
    $log_line = json_encode($log_entry) . PHP_EOL;

    file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
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

        // ENHANCED ANTI-SPAM HEADERS
        $mail->setFrom($config['SMTP_FROM_EMAIL'], $config['SMTP_FROM_NAME']);
        $mail->addAddress($to_email, $to_name);
        $mail->addReplyTo($config['SMTP_FROM_EMAIL'], $config['SMTP_FROM_NAME']);

        // Anti-Spam Headers
        $mail->addCustomHeader('Return-Path', $config['SMTP_FROM_EMAIL']);
        $mail->addCustomHeader('X-Mailer', 'Anna Braun Lerncoaching System v1.0');
        $mail->addCustomHeader('X-Priority', '3 (Normal)');
        $mail->addCustomHeader('Importance', 'Normal');
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
        $mail->addCustomHeader('X-Entity-ID', 'anna-braun-lerncoaching');
        $mail->addCustomHeader('X-Originating-IP', $_SERVER['SERVER_ADDR'] ?? 'unknown');
        $mail->addCustomHeader('List-Unsubscribe', '<mailto:termine@einfachstarten.jetzt?subject=Unsubscribe>');
        $mail->addCustomHeader('Organization', 'Anna Braun Lerncoaching');

        // Unique Message ID
        $mail->MessageID = '<ab_' . uniqid() . '.' . time() . '@einfachstarten.jetzt>';

        // HTML EMAIL AKTIVIEREN
        $mail->isHTML(true);
        $mail->Subject = 'Dein Login-Code für Anna Braun Lerncoaching';
        $mail->CharSet = 'UTF-8';

        // SCHÖNES HTML EMAIL DESIGN mit Dark/Light Mode Support
        $formatted_expires = date('d.m.Y \u\m H:i \U\h\r', strtotime($expires));

        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <meta name='color-scheme' content='light dark'>
            <style>
                :root {
                    color-scheme: light dark;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
                    line-height: 1.6;
                    margin: 0;
                    padding: 20px;
                    background-color: #f5f7fa;
                    color: #333;
                }
                
                @media (prefers-color-scheme: dark) {
                    body {
                        background-color: #1a1a1a !important;
                        color: #e0e0e0 !important;
                    }
                }
                
                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    background: white;
                    border-radius: 12px;
                    overflow: hidden;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                }
                
                @media (prefers-color-scheme: dark) {
                    .container {
                        background: #2a2a2a !important;
                        box-shadow: 0 4px 20px rgba(255,255,255,0.1);
                    }
                }
                
                .header {
                    background: linear-gradient(135deg, #4a90b8 0%, #52b3a4 100%);
                    padding: 40px 30px;
                    text-align: center;
                    color: white;
                }
                
                .brain-emoji {
                    font-size: 60px;
                    margin-bottom: 20px;
                    display: block;
                }
                
                .header h1 {
                    margin: 0;
                    font-size: 28px;
                    font-weight: 600;
                }
                
                .header p {
                    margin: 10px 0 0 0;
                    opacity: 0.9;
                    font-size: 16px;
                }
                
                .content {
                    padding: 40px 30px;
                }
                
                .greeting {
                    font-size: 18px;
                    margin-bottom: 20px;
                    color: #333;
                }
                
                @media (prefers-color-scheme: dark) {
                    .greeting {
                        color: #e0e0e0 !important;
                    }
                }
                
                .message {
                    font-size: 16px;
                    margin-bottom: 30px;
                    color: #666;
                    line-height: 1.6;
                }
                
                @media (prefers-color-scheme: dark) {
                    .message {
                        color: #b0b0b0 !important;
                    }
                }
                
                .code-container {
                    background: #f8f9fa;
                    border: 2px solid #e9ecef;
                    border-radius: 12px;
                    padding: 30px;
                    text-align: center;
                    margin: 30px 0;
                }
                
                @media (prefers-color-scheme: dark) {
                    .code-container {
                        background: #3a3a3a !important;
                        border-color: #555 !important;
                    }
                }
                
                .code-label {
                    font-size: 14px;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                    color: #6c757d;
                    margin-bottom: 15px;
                    font-weight: 600;
                }
                
                @media (prefers-color-scheme: dark) {
                    .code-label {
                        color: #b0b0b0 !important;
                    }
                }
                
                .code {
                    font-size: 36px;
                    font-weight: 700;
                    color: #4a90b8;
                    letter-spacing: 8px;
                    margin: 15px 0;
                    font-family: 'Monaco', 'Menlo', monospace;
                }
                
                .expiry {
                    color: #dc3545;
                    font-size: 14px;
                    margin-top: 15px;
                    font-weight: 500;
                }
                
                .login-link {
                    display: inline-block;
                    background: linear-gradient(135deg, #4a90b8 0%, #52b3a4 100%);
                    color: white;
                    text-decoration: none;
                    padding: 15px 30px;
                    border-radius: 8px;
                    font-weight: 600;
                    margin: 20px 0;
                    transition: transform 0.2s;
                }
                
                .login-link:hover {
                    transform: translateY(-2px);
                    color: white;
                    text-decoration: none;
                }
                
                .note {
                    font-size: 14px;
                    color: #6c757d;
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin: 25px 0;
                    border-left: 4px solid #4a90b8;
                }
                
                @media (prefers-color-scheme: dark) {
                    .note {
                        background: #3a3a3a !important;
                        color: #b0b0b0 !important;
                    }
                }
                
                .footer {
                    background: #f8f9fa;
                    padding: 30px;
                    text-align: center;
                    font-size: 14px;
                    color: #6c757d;
                    border-top: 1px solid #e9ecef;
                }
                
                @media (prefers-color-scheme: dark) {
                    .footer {
                        background: #2a2a2a !important;
                        color: #b0b0b0 !important;
                        border-top-color: #555 !important;
                    }
                }
                
                .footer-links {
                    margin-top: 15px;
                }
                
                .footer-links a {
                    color: #4a90b8;
                    text-decoration: none;
                    margin: 0 10px;
                }
                
                @media (max-width: 600px) {
                    .container {
                        margin: 10px;
                        border-radius: 8px;
                    }
                    
                    .header {
                        padding: 30px 20px;
                    }
                    
                    .content {
                        padding: 30px 20px;
                    }
                    
                    .code {
                        font-size: 28px;
                        letter-spacing: 4px;
                    }
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='brain-emoji'>🧠</div>
                    <h1>Anna Braun Lerncoaching</h1>
                    <p>Dein personalisierter Lernbereich</p>
                </div>
                
                <div class='content'>
                    <div class='greeting'>Liebe/r {$to_name},</div>
                    
                    <div class='message'>
                        hier ist dein Login-Code für das Kundenkonto und den personalisierten Lernbereich!
                    </div>
                    
                    <div class='code-container'>
                        <div class='code-label'>Dein Login-Code</div>
                        <div class='code'>{$pin}</div>
                        <div class='expiry'>Gültig bis {$formatted_expires}</div>
                    </div>
                    
                    <center>
                        <a href='https://einfachstarten.jetzt/einfachlernen/login.php' class='login-link'>
                            Jetzt anmelden →
                        </a>
                    </center>
                    
                    <div class='note'>
                        <strong>Hinweis:</strong> Falls du diesen Code nicht angefordert hast, kannst du diese E-Mail einfach ignorieren.
                    </div>
                    
                    <p style='color: #666; font-size: 14px; margin-top: 30px;'>
                        Bei Fragen stehe ich dir gerne zur Verfügung!
                    </p>
                </div>
                
                <div class='footer'>
                    <strong>Anna Braun</strong><br>
                    Ganzheitliches Lerncoaching
                    
                    <div class='footer-links'>
                        <a href='mailto:termine@einfachstarten.jetzt'>E-Mail</a>
                        <a href='https://www.einfachlernen.jetzt'>Website</a>
                    </div>
                    
                    <p style='margin-top: 20px; font-size: 12px; opacity: 0.7;'>
                        Diese E-Mail wurde automatisch generiert.
                    </p>
                </div>
            </div>
        </body>
        </html>";

        // Plain text version für alte Email-Clients
        $mail->AltBody = "Liebe/r {$to_name},\n\n";
        $mail->AltBody .= "du hast einen Login-Code für dein Kundenkonto angefordert.\n\n";
        $mail->AltBody .= "Dein Login-Code: {$pin}\n";
        $mail->AltBody .= "Gültig bis: {$formatted_expires}\n\n";
        $mail->AltBody .= "Zum Login: https://einfachstarten.jetzt/einfachlernen/login.php\n\n";
        $mail->AltBody .= "Falls du diesen Code nicht angefordert hast, kannst du diese E-Mail ignorieren.\n\n";
        $mail->AltBody .= "Bei Fragen stehe ich dir gerne zur Verfügung.\n\n";
        $mail->AltBody .= "Mit freundlichen Grüßen\nAnna Braun\nGanzheitliches Lerncoaching";

        $mail->send();
        
        // Log delivery success
        logEmailDelivery($to_email, true, 'Beautiful HTML email sent successfully');
        
        return [true, ''];
        
    } catch (Exception $e) {
        $error_message = $mail->ErrorInfo ?: $e->getMessage();
        
        // Log delivery failure
        logEmailDelivery($to_email, false, $error_message);
        
        return [false, $error_message];
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
            'email_template' => 'html_v2',
            'email_format' => 'html',
            'pin_expires_at' => $expires,
            'email_subject' => 'Dein Login-Code für Anna Braun Lerncoaching'
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
