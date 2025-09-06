<?php
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}
function getPDO(){
    $config = require __DIR__ . '/config.php';
    try{
        return new PDO(
            "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }catch(PDOException $e){
        die('DB connection failed: '.htmlspecialchars($e->getMessage()));
    }
}
if($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['customer_id'])){
    $cid = (int)$_POST['customer_id'];
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT email, first_name FROM customers WHERE id = ?');
    $stmt->execute([$cid]);
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$cust){
        echo "<h2 style='color:red'>❌ Customer not found</h2>";
        echo "<p><a href='dashboard.php?error=".urlencode('Customer not found')."'>← Back to Dashboard</a></p>";
        exit;
    }

    $pin = sprintf('%06d', random_int(100000, 999999));
    $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $upd = $pdo->prepare('UPDATE customers SET pin = ?, pin_expires = ? WHERE id = ?');
    $upd->execute([$pin_hash, $expires, $cid]);

    // TODO: Implement rate limiting - max 3 PIN requests per hour per email
    // TODO: Log PIN generation attempts for security monitoring

    $subject = 'Ihr Login-Code für Anna Braun Lerncoaching';

    $message = "Liebe/r {$cust['first_name']},\n\n";
    $message .= "Sie haben einen Login-Code für Ihr Kundenkonto angefordert.\n\n";
    $message .= "🔐 Ihr Login-Code: $pin\n";
    $message .= "⏰ Gültig bis: " . date('d.m.Y um H:i', strtotime($expires)) . " Uhr\n\n";
    $message .= "► Zum Login: https://einfachstarten.jetzt/einfachlernen/login.php\n\n";
    $message .= "Aus Sicherheitsgründen ist dieser Code nur 15 Minuten gültig.\n";
    $message .= "Falls Sie diesen Code nicht angefordert haben, können Sie diese E-Mail ignorieren.\n\n";
    $message .= "Bei Fragen stehen wir Ihnen gerne zur Verfügung.\n\n";
    $message .= "Mit freundlichen Grüßen\n";
    $message .= "Anna Braun\n";
    $message .= "Ganzheitliches Lerncoaching\n\n";
    $message .= "---\n";
    $message .= "Anna Braun Lerncoaching\n";
    $message .= "E-Mail: info@einfachlernen.jetzt\n";
    $message .= "Web: www.einfachlernen.jetzt\n";
    $message .= "Diese E-Mail wurde automatisch generiert.";

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

    $mail_result = mail($cust['email'], $subject, $message, $headers);

    if($mail_result){
        echo "<h2 style='color:green'>✅ E-Mail erfolgreich versendet</h2>";
        echo "<p><strong>Empfänger:</strong> " . htmlspecialchars($cust['email']) . "</p>";
        echo "<p><strong>Login-Code:</strong> <code style='background:#f0f0f0;padding:5px;'>$pin</code></p>";
        echo "<p><strong>Gültig bis:</strong> " . date('d.m.Y um H:i', strtotime($expires)) . " Uhr</p>";
        echo "<div style='background:#d4edda;padding:15px;border-radius:5px;margin:15px 0;'>";
        echo "<strong>Nächste Schritte:</strong><br>";
        echo "1. E-Mail im Posteingang prüfen (auch Spam-Ordner)<br>";
        echo "2. Login-Code verwenden: <a href='/einfachlernen/login.php' target='_blank'>Zum Login</a><br>";
        echo "3. Bei Problemen: info@einfachlernen.jetzt kontaktieren";
        echo "</div>";
    } else {
        echo "<h2 style='color:red'>❌ E-Mail Versand fehlgeschlagen</h2>";
        echo "<p><strong>Empfänger:</strong> " . htmlspecialchars($cust['email']) . "</p>";
        $error = error_get_last();
        if($error){
            echo "<p><strong>Fehlermeldung:</strong> " . htmlspecialchars($error['message']) . "</p>";
        }
        echo "<p><strong>Server:</strong> " . $_SERVER['SERVER_NAME'] . "</p>";
        echo "<div style='background:#f8d7da;padding:15px;border-radius:5px;margin:15px 0;'>";
        echo "Mögliche Ursachen:<br>";
        echo "1. Server blockiert den Mailversand<br>";
        echo "2. Ungültige Empfängeradresse<br>";
        echo "3. Spamfilter hat die E-Mail abgelehnt";
        echo "</div>";
        echo "<p><a href='dashboard.php?error=" . urlencode('Email sending failed') . "'>← Back to Dashboard</a></p>";
    }
    exit;
}
echo "<h2 style='color:red'>❌ Invalid request</h2>";
echo "<p><a href='dashboard.php?error=" . urlencode('Invalid request') . "'>← Back to Dashboard</a></p>";
exit;
?>
