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

    // Build email
    echo "<h3>Email Preparation</h3>";
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

    echo "<p>Email recipient: " . htmlspecialchars($cust['email']) . "</p>";
    echo "<p>Email subject: " . htmlspecialchars($subject) . "</p>";

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

    echo "<h3>Email Sending</h3>";
    echo "<p>Calling mail() function...</p>";
    $mail_result = mail($cust['email'], $subject, $message, $headers);
    echo "<p>mail() returned: " . ($mail_result ? '✅ TRUE' : '❌ FALSE') . "</p>";

    if ($mail_result) {
        echo "<div style='background:#d4edda;color:#155724;padding:1rem;border-radius:5px;margin:1rem 0;'>";
        echo "<h2>✅ PIN Successfully Sent</h2>";
        echo "<p><strong>Recipient:</strong> " . htmlspecialchars($cust['email']) . "</p>";
        echo "<p><strong>PIN:</strong> <code>$pin</code> (valid for 15 minutes)</p>";
        echo "<p><strong>Expires:</strong> $expires</p>";
        echo "</div>";
        echo "<p><a href='dashboard.php?success=" . urlencode('PIN sent to ' . $cust['email']) . "'>← Back to Dashboard</a></p>";
    } else {
        echo "<div style='background:#f8d7da;color:#721c24;padding:1rem;border-radius:5px;margin:1rem 0;'>";
        echo "<h2>❌ Email Sending Failed</h2>";
        echo "<p><strong>Recipient:</strong> " . htmlspecialchars($cust['email']) . "</p>";
        $error = error_get_last();
        echo "<p><strong>Last PHP Error:</strong> " . ($error['message'] ?? 'No error details') . "</p>";
        echo "<p><strong>Server:</strong> " . $_SERVER['SERVER_NAME'] . "</p>";
        echo "</div>";
        echo "<p><a href='dashboard.php?error=" . urlencode('Email sending failed for ' . $cust['email']) . "'>← Back to Dashboard</a></p>";
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
