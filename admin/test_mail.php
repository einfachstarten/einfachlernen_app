<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}

echo "<h2>World4you mail() Test</h2>";

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_email = $_POST['test_email'] ?? 'marcus@einfachstarten.jetzt';
    
    $subject = 'Test Email from Anna Braun CMS';
    $message = "This is a test email sent at " . date('Y-m-d H:i:s') . "\n\n";
    $message .= "If you receive this, mail() function is working correctly.\n\n";
    $message .= "Server: " . $_SERVER['SERVER_NAME'] . "\n";
    $message .= "PHP Version: " . phpversion();
    
    $headers = 'From: Anna Braun Test <noreply@einfachstarten.jetzt>' . "\r\n" .
               'Reply-To: info@einfachlernen.jetzt' . "\r\n" .
               'X-Mailer: PHP/' . phpversion();
    
    $result = mail($test_email, $subject, $message, $headers);
    
    if($result) {
        echo "<p style='color:green'>✅ mail() returned TRUE - check email delivery</p>";
    } else {
        echo "<p style='color:red'>❌ mail() returned FALSE</p>";
        $error = error_get_last();
        echo "<p>Last error: " . ($error['message'] ?? 'No error details') . "</p>";
    }
}
?>

<form method="post">
    <label>Test Email Address:</label><br>
    <input type="email" name="test_email" value="marcus@einfachstarten.jetzt" required><br><br>
    <button type="submit">Send Test Email</button>
</form>

<p><a href="dashboard.php">← Back to Dashboard</a></p>
