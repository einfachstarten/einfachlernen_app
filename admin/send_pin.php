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

function sendEmailJS($to_email, $to_name, $subject, $message) {
    echo "<h4>EmailJS Debug Information</h4>";

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

            if (isset($http_response_header)) {
                echo "<p><strong>Response Headers:</strong></p>";
                echo "<pre>" . htmlspecialchars(implode("\n", $http_response_header)) . "</pre>";
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
    echo "<h3>Email Preparation & Sending</h3>";

    $subject = 'Ihr Login-Code für Anna Braun Lerncoaching';

    // Professional email message with proper formatting
    $message = "Liebe/r {$cust['first_name']},\n\n";
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
    $message .= "E-Mail: info@einfachlernen.jetzt\n";
    $message .= "Web: www.einfachlernen.jetzt\n";
    $message .= "Diese E-Mail wurde automatisch generiert.";

    echo "<p>Email recipient: " . htmlspecialchars($cust['email']) . "</p>";
    echo "<p>Email subject: " . htmlspecialchars($subject) . "</p>";
    echo "<p>Sending via: EmailJS (Anna's Outlook account)</p>";

    // Try EmailJS first, fallback to mail() if needed
    list($emailjs_success, $emailjs_response) = sendEmailJS(
        $cust['email'],
        $cust['first_name'],
        $subject,
        $message
    );

    if ($emailjs_success) {
        echo "<div style='background:#d4edda;color:#155724;padding:1rem;border-radius:5px;margin:1rem 0;'>";
        echo "<h2>✅ PIN Successfully Sent via EmailJS</h2>";
        echo "<p><strong>Recipient:</strong> " . htmlspecialchars($cust['email']) . "</p>";
        echo "<p><strong>PIN:</strong> <code>{$pin}</code> (valid for 15 minutes)</p>";
        echo "<p><strong>Expires:</strong> {$expires}</p>";
        echo "<p><strong>Method:</strong> EmailJS → Anna's Outlook Account</p>";
        echo "<p><strong>Deliverability:</strong> High (professional email service)</p>";
        echo "</div>";
        echo "<p><a href='dashboard.php?success=" . urlencode('PIN sent via EmailJS to ' . $cust['email']) . "'>← Back to Dashboard</a></p>";
    } else {
        echo "<div style='background:#fff3cd;color:#856404;padding:1rem;border-radius:5px;margin:1rem 0;'>";
        echo "<h3>⚠️ EmailJS Failed - Trying Fallback</h3>";
        echo "<p>EmailJS Error: " . htmlspecialchars($emailjs_response) . "</p>";
        echo "</div>";

        echo "<h3>Trying alternative EmailJS method...</h3>";
        list($alt_success, $alt_response) = sendEmailJSAlternative(
            $cust['email'],
            $cust['first_name'],
            $subject,
            $message
        );

        if ($alt_success) {
            echo "<div style='background:#d4edda;color:#155724;padding:1rem;border-radius:5px;margin:1rem 0;'>";
            echo "<h2>✅ PIN Sent via EmailJS Alternative</h2>";
            echo "<p><strong>Recipient:</strong> " . htmlspecialchars($cust['email']) . "</p>";
            echo "<p><strong>PIN:</strong> <code>{$pin}</code> (valid for 15 minutes)</p>";
            echo "<p><strong>Expires:</strong> {$expires}</p>";
            echo "<p><strong>Method:</strong> EmailJS alternative endpoint</p>";
            echo "</div>";
            echo "<p><a href='dashboard.php?success=" . urlencode('PIN sent via EmailJS alternative to ' . $cust['email']) . "'>← Back to Dashboard</a></p>";
        } else {
            echo "<p style='color:red'>❌ Alternative method failed: " . htmlspecialchars($alt_response) . "</p>";

            // Fallback to mail()
            $headers = 'From: Anna Braun Lerncoaching <info@einfachlernen.jetzt>' . "\r\n" .
                       'Reply-To: info@einfachlernen.jetzt' . "\r\n" .
                       'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
                       'MIME-Version: 1.0';

            $mail_result = mail($cust['email'], $subject, $message, $headers);

            if ($mail_result) {
                echo "<div style='background:#d4edda;color:#155724;padding:1rem;border-radius:5px;margin:1rem 0;'>";
                echo "<h2>✅ PIN Sent via Fallback (PHP mail)</h2>";
                echo "<p><strong>Recipient:</strong> " . htmlspecialchars($cust['email']) . "</p>";
                echo "<p><strong>PIN:</strong> <code>{$pin}</code></p>";
                echo "<p><strong>Method:</strong> PHP mail() fallback</p>";
                echo "</div>";
                echo "<p><a href='dashboard.php?success=" . urlencode('PIN sent via fallback to ' . $cust['email']) . "'>← Back to Dashboard</a></p>";
            } else {
                echo "<div style='background:#f8d7da;color:#721c24;padding:1rem;border-radius:5px;margin:1rem 0;'>";
                echo "<h2>❌ Both EmailJS and mail() Failed</h2>";
                echo "<p><strong>Recipient:</strong> " . htmlspecialchars($cust['email']) . "</p>";
                echo "<p><strong>EmailJS Error:</strong> " . htmlspecialchars($emailjs_response) . "</p>";
                $error = error_get_last();
                echo "<p><strong>mail() Error:</strong> " . ($error['message'] ?? 'Unknown error') . "</p>";
                echo "</div>";
                echo "<p><a href='dashboard.php?error=" . urlencode('Email sending failed for ' . $cust['email']) . "'>← Back to Dashboard</a></p>";
            }
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
