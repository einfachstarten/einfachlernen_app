<?php
session_start();
if (empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once '../admin/phpmailer/Exception.php';
require_once '../admin/phpmailer/PHPMailer.php';
require_once '../admin/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function sendContactEmail($customer, $category, $message) {
    $config = require '../admin/config.php';
    
    // E-Mail-Empfänger basierend auf Kategorie
    $recipients = [
        'lerncoaching' => ['anna@einfachlernen.jetzt' => 'Anna Braun'],
        'app' => ['marcus@einfachstarten.jetzt' => 'Marcus'],
        'sonstiges' => [
            'anna@einfachlernen.jetzt' => 'Anna Braun',
            'marcus@einfachstarten.jetzt' => 'Marcus'
        ]
    ];
    
    $category_subjects = [
        'lerncoaching' => 'Frage zu Lerncoaching',
        'app' => 'Frage zur App',
        'sonstiges' => 'Sonstiges'
    ];
    
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
        $mail->addReplyTo($customer['email'], $customer['first_name'] . ' ' . $customer['last_name']);
        
        // Empfänger hinzufügen
        foreach ($recipients[$category] as $email => $name) {
            $mail->addAddress($email, $name);
        }
        
        $mail->isHTML(false);
        $mail->Subject = $category_subjects[$category] . ' - ' . $customer['first_name'] . ' ' . $customer['last_name'];
        $mail->CharSet = 'UTF-8';
        
        $body = "Neue Nachricht über das Kundendashboard\n\n";
        $body .= "Kategorie: " . $category_subjects[$category] . "\n";
        $body .= "Von: " . $customer['first_name'] . ' ' . $customer['last_name'] . "\n";
        $body .= "E-Mail: " . $customer['email'] . "\n";
        $body .= "Datum: " . date('d.m.Y H:i') . "\n\n";
        $body .= "Nachricht:\n" . $message . "\n\n";
        $body .= "---\n";
        $body .= "Gesendet über das Anna Braun Kundendashboard";
        
        $mail->Body = $body;
        $mail->send();
        
        return [true, ''];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = $_POST['category'] ?? '';
    $message = trim($_POST['message'] ?? '');
    
    if (empty($category) || empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Bitte füllen Sie alle Felder aus']);
        exit;
    }
    
    if (!in_array($category, ['lerncoaching', 'app', 'sonstiges'])) {
        echo json_encode(['success' => false, 'message' => 'Ungültige Kategorie']);
        exit;
    }
    
    if (strlen($message) > 2000) {
        echo json_encode(['success' => false, 'message' => 'Nachricht zu lang (max. 2000 Zeichen)']);
        exit;
    }
    
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare('SELECT id, email, first_name, last_name FROM customers WHERE id = ?');
        $stmt->execute([$_SESSION['customer_id']]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$customer) {
            echo json_encode(['success' => false, 'message' => 'Kunde nicht gefunden']);
            exit;
        }
        
        list($success, $error) = sendContactEmail($customer, $category, $message);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Nachricht erfolgreich gesendet']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Fehler beim Senden: ' . $error]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Systemfehler']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);
}
?>
