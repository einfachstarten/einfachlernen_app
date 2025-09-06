<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}

function sendEmailJS($to_email, $to_name, $subject, $message) {
    $emailjs_data = [
        'service_id' => 'service_mskznyd',
        'template_id' => 'template_c8obahd',
        'user_id' => 'E7m0JpVn9GC6WNcvF',
        'template_params' => [
            'to_email' => $to_email,
            'to_name' => $to_name,
            'subject' => $subject,
            'message' => $message
        ]
    ];

    $json_data = json_encode($emailjs_data);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json_data)
            ],
            'content' => $json_data,
            'timeout' => 30
        ]
    ]);

    $response = file_get_contents('https://api.emailjs.com/api/v1.0/email/send', false, $context);

    if ($response === false) {
        return [false, 'EmailJS API request failed'];
    }

    $http_code = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (strpos($header, 'HTTP/') === 0) {
                $parts = explode(' ', $header);
                $http_code = intval($parts[1]);
                break;
            }
        }
    }

    return [$http_code === 200, $response];
}

echo "<h2>Email Test Utility</h2>";

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_email = $_POST['test_email'] ?? 'marcus@einfachstarten.jetzt';
    $use_emailjs = isset($_POST['use_emailjs']);

    if ($use_emailjs) {
        echo "<h3>Testing EmailJS...</h3>";
        $subject = 'Test-E-Mail von Anna Braun Lerncoaching (EmailJS)';
        $message = "Liebe/r Tester/in,\n\n";
        $message .= "diese Test-E-Mail wurde erfolgreich über EmailJS versendet.\n\n";
        $message .= "📧 Zeitpunkt: " . date('d.m.Y um H:i:s') . " Uhr\n";
        $message .= "🖥️ Server: " . $_SERVER['SERVER_NAME'] . "\n";
        $message .= "📧 Methode: EmailJS → Anna's Outlook\n\n";
        $message .= "Falls Sie diese E-Mail erhalten, funktioniert EmailJS korrekt.\n\n";
        $message .= "Mit freundlichen Grüßen\n";
        $message .= "Anna Braun Lerncoaching System";

        list($success, $response) = sendEmailJS($test_email, 'Test User', $subject, $message);

        if($success) {
            echo "<p style='color:green'>✅ EmailJS test successful!</p>";
        } else {
            echo "<p style='color:red'>❌ EmailJS test failed: " . htmlspecialchars($response) . "</p>";
        }
    } else {
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
?>

<form method="post">
    <label>Test Email Address:</label><br>
    <input type="email" name="test_email" value="marcus@einfachstarten.jetzt" required><br><br>
    <label><input type="checkbox" name="use_emailjs" checked> Use EmailJS (Recommended)</label><br><br>
    <button type="submit">Send Test Email</button>
</form>

<p><a href="dashboard.php">← Back to Dashboard</a></p>
