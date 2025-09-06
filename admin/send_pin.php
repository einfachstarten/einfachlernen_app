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

        // Basic Settings
        $mail->setFrom($config['SMTP_FROM_EMAIL'], $config['SMTP_FROM_NAME']);
        $mail->addAddress($to_email, $to_name);
        $mail->addReplyTo($config['SMTP_FROM_EMAIL'], $config['SMTP_FROM_NAME']);

        // Enhanced Anti-Spam Headers
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

        $mail->isHTML(false);
        $mail->Subject = 'Dein Login-Code für Anna Braun Lerncoaching';
        $mail->CharSet = 'UTF-8';

        // Restored design in DU-Form
        $message = "Liebe/r {$to_name},\n\n";
        $message .= "du hast einen Login-Code für dein Kundenkonto angefordert.\n\n";
        $message .= "🔐 Dein Login-Code: {$pin}\n";
        $message .= "⏰ Gültig bis: " . date('d.m.Y \u\m H:i', strtotime($expires)) . " Uhr\n\n";
        $message .= "► Zum Login: https://einfachstarten.jetzt/einfachlernen/login.php\n\n";
        $message .= "Aus Sicherheitsgründen ist dieser Code nur {$duration_minutes} Minuten gültig.\n";
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
        logEmailDelivery($to_email, true, 'Email sent successfully');
        return [true, ''];
    } catch (Exception $e) {
        $error_message = $mail->ErrorInfo ?: $e->getMessage();
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
            'email_template' => 'plain_text_v1',
            'email_format' => 'plain_text',
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
