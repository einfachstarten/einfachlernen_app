<?php
session_start();
if(empty($_SESSION['admin'])){header('Location: login.php');exit;}

function sendEmailJS($to_email, $to_name, $subject, $message) {
    echo "<h4>EmailJS Debug Information</h4>";

    // Test different API endpoints (EmailJS has changed URLs)
    $endpoints = [
        'https://api.emailjs.com/api/v1.0/email/send',
        'https://api.emailjs.com/api/v1.0/email/send-form'
    ];

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

    echo "<p><strong>Request Data:</strong></p>";
    echo "<pre>" . htmlspecialchars(json_encode($emailjs_data, JSON_PRETTY_PRINT)) . "</pre>";

    $json_data = json_encode($emailjs_data);
    echo "<p><strong>JSON Payload Size:</strong> " . strlen($json_data) . " bytes</p>";

    // Test 1: Using file_get_contents with enhanced error reporting
    echo "<h5>Test 1: file_get_contents method</h5>";

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json_data),
                'User-Agent: Anna-Braun-CMS/1.0'
            ],
            'content' => $json_data,
            'timeout' => 30,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    foreach ($endpoints as $endpoint) {
        echo "<p>Testing endpoint: <code>" . htmlspecialchars($endpoint) . "</code></p>";

        $response = file_get_contents($endpoint, false, $context);

        if ($response === false) {
            echo "<p style='color:red'>❌ file_get_contents failed</p>";
            $error = error_get_last();
            echo "<p>Last error: " . htmlspecialchars($error['message'] ?? 'Unknown') . "</p>";
        } else {
            echo "<p style='color:green'>✅ Got response (" . strlen($response) . " bytes)</p>";
            echo "<p><strong>Response:</strong> " . htmlspecialchars($response) . "</p>";

            // Check HTTP response headers
            if (isset($http_response_header)) {
                echo "<p><strong>Response Headers:</strong></p>";
                echo "<pre>" . htmlspecialchars(implode("\n", $http_response_header)) . "</pre>";

                // Extract status code
                foreach ($http_response_header as $header) {
                    if (strpos($header, 'HTTP/') === 0) {
                        echo "<p><strong>Status:</strong> " . htmlspecialchars($header) . "</p>";
                        break;
                    }
                }
            }

            return [true, $response];
        }
    }

    // Test 2: Using cURL if available
    if (function_exists('curl_init')) {
        echo "<h5>Test 2: cURL method</h5>";

        foreach ($endpoints as $endpoint) {
            echo "<p>Testing endpoint with cURL: <code>" . htmlspecialchars($endpoint) . "</code></p>";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json_data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($json_data),
                    'User-Agent: Anna-Braun-CMS/1.0'
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_VERBOSE => false
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                echo "<p style='color:red'>❌ cURL failed: " . htmlspecialchars($curl_error) . "</p>";
            } else {
                echo "<p style='color:green'>✅ cURL response (HTTP $http_code, " . strlen($response) . " bytes)</p>";
                echo "<p><strong>Response:</strong> " . htmlspecialchars($response) . "</p>";

                if ($http_code === 200) {
                    return [true, $response];
                } else {
                    echo "<p style='color:orange'>⚠️ HTTP $http_code - not success</p>";
                }
            }
        }
    } else {
        echo "<p style='color:orange'>⚠️ cURL not available</p>";
    }

    // Test 3: Basic connectivity test
    echo "<h5>Test 3: Basic connectivity</h5>";
    $test_url = 'https://httpbin.org/post';
    $test_data = json_encode(['test' => 'connectivity']);

    $test_context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $test_data,
            'timeout' => 10
        ]
    ]);

    $test_response = file_get_contents($test_url, false, $test_context);
    if ($test_response !== false) {
        echo "<p style='color:green'>✅ Server can make HTTPS POST requests</p>";
    } else {
        echo "<p style='color:red'>❌ Server cannot make HTTPS POST requests</p>";
        echo "<p>This indicates a server configuration issue</p>";
    }

    return [false, 'All EmailJS endpoints failed - see debug output above'];
}

function sendEmailJSAlternative($to_email, $to_name, $subject, $message) {
    echo "<h4>Alternative EmailJS Method</h4>";

    // Try the form-based endpoint (sometimes more reliable)
    $form_data = http_build_query([
        'service_id' => 'service_mskznyd',
        'template_id' => 'template_c8obahd',
        'user_id' => 'E7m0JpVn9GC6WNcvF',
        'template_params' => json_encode([
            'to_email' => $to_email,
            'to_name' => $to_name,
            'subject' => $subject,
            'message' => $message
        ])
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $form_data,
            'timeout' => 30
        ]
    ]);

    $response = file_get_contents('https://api.emailjs.com/api/v1.0/email/send-form', false, $context);

    if ($response !== false) {
        echo "<p style='color:green'>✅ Alternative method succeeded</p>";
        return [true, $response];
    } else {
        echo "<p style='color:red'>❌ Alternative method also failed</p>";
        return [false, 'Form-based approach failed'];
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
    $use_emailjs = isset($_POST['use_emailjs']);

    if ($use_emailjs) {
        echo "<h3>Testing EmailJS...</h3>";

        echo "<h3>EmailJS Configuration Verification</h3>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Parameter</th><th>Value</th><th>Status</th></tr>";

        $config = [
            'Service ID' => 'service_mskznyd',
            'Template ID' => 'template_c8obahd',
            'Public Key' => 'E7m0JpVn9GC6WNcvF'
        ];

        foreach ($config as $name => $value) {
            $status = strlen($value) > 10 ? '✅ OK' : '❌ Too short';
            echo "<tr><td>$name</td><td>" . htmlspecialchars($value) . "</td><td>$status</td></tr>";
        }
        echo "</table>";

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
            echo "<h3>Trying alternative EmailJS method...</h3>";
            list($alt_success, $alt_response) = sendEmailJSAlternative($test_email, 'Test User', $subject, $message);
            if ($alt_success) {
                echo "<p style='color:green'>✅ Alternative EmailJS method succeeded!</p>";
            } else {
                echo "<p style='color:red'>❌ Alternative method failed: " . htmlspecialchars($alt_response) . "</p>";
            }
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
