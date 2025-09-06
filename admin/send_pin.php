<?php
// Enable debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

session_start();

echo "<!DOCTYPE html><html><head><title>Send PIN Debug</title></head><body>";
echo "<h1>DEBUG: send_pin.php</h1>";
echo "<p>Script started at: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>Request method: " . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . "</p>";
echo "<p>POST data received:</p><pre>";
var_dump($_POST);
echo "</pre>";
echo "<h3>Session Check</h3>";
if (empty($_SESSION['admin'])) {
    echo "<p style='color:red'>❌ No admin session found</p>";
    echo "<p>Redirecting to login.php...</p>";
    echo "<p><a href='login.php'>Manual redirect if needed</a></p>";
    header('Location: login.php');
    exit;
} else {
    echo "<p style='color:green'>✅ Admin session OK: " . htmlspecialchars($_SESSION['admin']) . "</p>";
}

echo "<h3>Database Connection</h3>";
function getPDO() {
    echo "<p>Attempting database connection...</p>";
    $config = require __DIR__ . '/config.php';
    echo "<p>Config loaded successfully</p>";
    try {
        $pdo = new PDO(
            "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8mb4",
            $config['DB_USER'],
            $config['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "<p style='color:green'>✅ Database connected successfully</p>";
        return $pdo;
    } catch (PDOException $e) {
        echo "<p style='color:red'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        die('</body></html>');
    }
}

// Include PHPMailer classes
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function sendSMTPEmail($to_email, $to_name, $pin, $expires) {
    $config = require __DIR__ . '/config.php';

    echo "<h4>Professional SMTP Email Sending</h4>";
    echo "<p><strong>SMTP Server:</strong> " . $config['SMTP_HOST'] . ":" . $config['SMTP_PORT'] . "</p>";
    echo "<p><strong>From:</strong> " . $config['SMTP_FROM_EMAIL'] . "</p>";
    echo "<p><strong>To:</strong> " . htmlspecialchars($to_email) . "</p>";

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = $config['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['SMTP_USERNAME'];
        $mail->Password = $config['SMTP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $config['SMTP_PORT'];
        $mail->Timeout = $config['SMTP_TIMEOUT'];

        // Enable debug output for troubleshooting (remove in production)
        $mail->SMTPDebug = SMTP::DEBUG_CONNECTION;
        $mail->Debugoutput = function($str, $level) {
            echo "<p style='color: #666; font-size: 0.85em; font-family: monospace;'>SMTP: " . htmlspecialchars(trim($str)) . "</p>";
        };

        // Recipients
        $mail->setFrom($config['SMTP_FROM_EMAIL'], $config['SMTP_FROM_NAME']);
        $mail->addAddress($to_email, $to_name);
        $mail->addReplyTo($config['SMTP_FROM_EMAIL'], $config['SMTP_FROM_NAME']);

        // Content
        $mail->isHTML(false); // Send as plain text
        $mail->Subject = 'Ihr Login-Code für Anna Braun Lerncoaching';
        $mail->CharSet = 'UTF-8';

        // Professional email content
        $message = "Liebe/r {$to_name},\n\n";
        $message .= "Sie haben einen Login-Code für Ihr Kundenkonto angefordert.\n\n";
        $message .= "🔐 Ihr Login-Code: {$pin}\n";
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
        $message .= "E-Mail: termine@einfachstarten.jetzt\n";
        $message .= "Web: www.einfachlernen.jetzt\n";
        $message .= "Diese E-Mail wurde automatisch generiert.";

        $mail->Body = $message;

        echo "<p>Initiating SMTP connection...</p>";
        $result = $mail->send();

        echo "<p style='color: green; font-weight: bold;'>✅ Email sent successfully via World4you SMTP</p>";
        return [true, 'Email sent successfully via SMTP'];

    } catch (Exception $e) {
        echo "<p style='color: red; font-weight: bold;'>❌ SMTP Error: " . htmlspecialchars($mail->ErrorInfo) . "</p>";
        echo "<p style='color: red;'>Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
        return [false, $mail->ErrorInfo];
    }
}


echo "<h3>Request Processing</h3>";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['customer_id'])) {
    echo "<p style='color:green'>✅ Valid POST request with customer_id</p>";
    $cid = (int)$_POST['customer_id'];
    echo "<p>Processing customer ID: $cid</p>";

    $pdo = getPDO();

    echo "<h3>Database Schema Verification</h3>";
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM customers LIKE 'pin'");
        if ($stmt->rowCount() == 0) {
            echo "<p style='color:red'>❌ Database not migrated! PIN column missing.</p>";
            echo "<p><a href='migrate.php'>Run Database Migration</a></p>";
            echo "</body></html>";
            exit;
        } else {
            echo "<p style='color:green'>✅ Database schema OK</p>";
        }
    } catch (PDOException $e) {
        echo "<p style='color:red'>Schema check failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    echo "<h3>Customer Lookup</h3>";
    $stmt = $pdo->prepare('SELECT email, first_name FROM customers WHERE id = ?');
    $stmt->execute([$cid]);
    $cust = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cust) {
        echo "<p style='color:red'>❌ Customer not found with ID: $cid</p>";
        echo "<p><a href='dashboard.php?error=" . urlencode('Customer not found') . "'>← Back to Dashboard</a></p>";
        exit;
    }

    echo "<p style='color:green'>✅ Customer found: " . htmlspecialchars($cust['email']) . " (" . htmlspecialchars($cust['first_name']) . ")</p>";

    echo "<h3>PIN Generation</h3>";
    $pin = sprintf('%06d', random_int(100000, 999999));
    echo "<p>Generated PIN: <strong>$pin</strong></p>";

    $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    echo "<p>PIN expires at: $expires</p>";

    echo "<h3>Database Update</h3>";
    $upd = $pdo->prepare('UPDATE customers SET pin = ?, pin_expires = ? WHERE id = ?');
    $result = $upd->execute([$pin_hash, $expires, $cid]);
    echo "<p>Database update result: " . ($result ? '✅ SUCCESS' : '❌ FAILED') . "</p>";

    echo "<h3>Email Sending via World4you SMTP</h3>";

    // Try SMTP first
    list($smtp_success, $smtp_message) = sendSMTPEmail(
        $cust['email'],
        $cust['first_name'],
        $pin,
        $expires
    );

    if ($smtp_success) {
        echo "<div style='background:#d4edda;color:#155724;padding:1.5rem;border-radius:8px;margin:1rem 0;'>";
        echo "<h2>✅ PIN Successfully Sent via SMTP</h2>";
        echo "<p><strong>Recipient:</strong> " . htmlspecialchars($cust['email']) . "</p>";
        echo "<p><strong>PIN:</strong> <code style='background:#fff;padding:3px 6px;border-radius:3px;'>$pin</code> (valid for 15 minutes)</p>";
        echo "<p><strong>Expires:</strong> " . date('d.m.Y um H:i', strtotime($expires)) . " Uhr</p>";
        echo "<p><strong>Method:</strong> World4you SMTP Server</p>";
        echo "<p><strong>From:</strong> termine@einfachstarten.jetzt</p>";
        echo "<p><strong>Deliverability:</strong> High (professional SMTP)</p>";
        echo "</div>";
        echo "<p><a href='dashboard.php?success=" . urlencode('PIN sent via SMTP to ' . $cust['email']) . "' class='btn-success'>← Back to Dashboard</a></p>";

    } else {
        echo "<div style='background:#fff3cd;color:#856404;padding:1.5rem;border-radius:8px;margin:1rem 0;'>";
        echo "<h3>⚠️ SMTP Failed - Using Fallback</h3>";
        echo "<p><strong>SMTP Error:</strong> " . htmlspecialchars($smtp_message) . "</p>";
        echo "<p>Attempting fallback to basic mail() function...</p>";
        echo "</div>";

        // Fallback to mail()
        $subject = 'Ihr Login-Code für Anna Braun Lerncoaching';
        $fallback_message = "Liebe/r {$cust['first_name']},\n\nIhr Login-Code: {$pin}\nGültig bis: " . date('d.m.Y H:i', strtotime($expires)) . "\n\nAnna Braun Lerncoaching";
        $headers = 'From: Anna Braun Lerncoaching <termine@einfachstarten.jetzt>' . "\r\n" .
                   'Reply-To: termine@einfachstarten.jetzt' . "\r\n" .
                   'Content-Type: text/plain; charset=UTF-8';

        $fallback_result = mail($cust['email'], $subject, $fallback_message, $headers);

        if ($fallback_result) {
            echo "<div style='background:#d1ecf1;color:#0c5460;padding:1rem;border-radius:8px;margin:1rem 0;'>";
            echo "<h3>📧 PIN Sent via Fallback</h3>";
            echo "<p><strong>Method:</strong> PHP mail() function</p>";
            echo "<p><strong>Note:</strong> Lower deliverability than SMTP</p>";
            echo "</div>";
            echo "<p><a href='dashboard.php?success=" . urlencode('PIN sent via fallback to ' . $cust['email']) . "'>← Back to Dashboard</a></p>";
        } else {
            echo "<div style='background:#f8d7da;color:#721c24;padding:1.5rem;border-radius:8px;margin:1rem 0;'>";
            echo "<h2>❌ Both SMTP and Fallback Failed</h2>";
            echo "<p><strong>SMTP Error:</strong> " . htmlspecialchars($smtp_message) . "</p>";
            $mail_error = error_get_last();
            echo "<p><strong>mail() Error:</strong> " . ($mail_error['message'] ?? 'Unknown error') . "</p>";
            echo "<p><strong>Recommendation:</strong> Check SMTP credentials or contact World4you support</p>";
            echo "</div>";
            echo "<p><a href='dashboard.php?error=" . urlencode('All email methods failed for ' . $cust['email']) . "'>← Back to Dashboard</a></p>";
        }
    }

} else {
    echo "<h3 style='color:red'>Invalid Request</h3>";
    echo "<p>Request method: " . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . "</p>";
    echo "<p>customer_id present: " . (isset($_POST['customer_id']) ? 'YES (' . $_POST['customer_id'] . ')' : 'NO') . "</p>";
    echo "<p>All POST data:</p><pre>";
    var_dump($_POST);
    echo "</pre>";
    echo "<p><a href='dashboard.php?error=" . urlencode('Invalid request - missing customer_id') . "'>← Back to Dashboard</a></p>";
}

echo "</body></html>";
?>
