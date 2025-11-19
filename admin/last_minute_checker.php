<?php
// Last-Minute Slot Checker - execute via cron (recommended: 7:00, 12:00, 20:00)

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

date_default_timezone_set('Europe/Vienna');
set_time_limit(300);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

/** @throws PDOException */
function getPDO(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';

    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_NAME']),
        $config['DB_USER'],
        $config['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    return $pdo;
}

function logMessage(string $message): void
{
    static $logFile = null;

    if ($logFile === null) {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/last_minute_checker.log';
    }

    $timestamp = date('Y-m-d H:i:s');
    $entry = sprintf('[%s] %s%s', $timestamp, $message, PHP_EOL);
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    echo $entry;
}

function getCalendlyToken(): string
{
    $token = getenv('CALENDLY_TOKEN') ?: '';
    if ($token === '' || $token === 'PASTE_YOUR_TOKEN_HERE') {
        throw new RuntimeException('Calendly token is not configured');
    }
    return $token;
}

function resetDailyNotificationCounters(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE last_minute_subscriptions
         SET notification_count_today = 0
         WHERE notification_count_today > 0
           AND (last_notification_sent IS NULL OR DATE(last_notification_sent) < CURDATE())"
    );
}

function fetchActiveSubscriptions(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT lms.*, c.email, c.first_name
         FROM last_minute_subscriptions lms
         INNER JOIN customers c ON c.id = lms.customer_id
         WHERE lms.is_active = 1
           AND c.beta_access = 1
           AND JSON_LENGTH(lms.service_slugs) > 0
           AND lms.notification_count_today < 3"
    );
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchSlotsForService(array $service, string $token, string $customerEmail): array
{
    $slots = [];
    $viennaTz = new DateTimeZone('Europe/Vienna');

    for ($offset = 0; $offset < 5; $offset++) {
        $start = new DateTimeImmutable(sprintf('+%d day', $offset), $viennaTz);
        $startUtc = $start->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'));
        $endUtc = $start->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'));

        $query = http_build_query([
            'event_type' => $service['uri'],
            'start_time' => $startUtc->format('Y-m-d\TH:i:s\Z'),
            'end_time' => $endUtc->format('Y-m-d\TH:i:s\Z'),
            'timezone' => 'Europe/Vienna',
        ], '', '&', PHP_QUERY_RFC3986);

        $url = 'https://api.calendly.com/event_type_available_times?' . $query;

        $response = calendlyRequest($url, $token);
        if (empty($response['collection'])) {
            continue;
        }

        foreach ($response['collection'] as $slot) {
            if (empty($slot['start_time'])) {
                continue;
            }

            try {
                $slotStart = new DateTimeImmutable($slot['start_time']);
            } catch (Exception $e) {
                continue;
            }

            $viennaSlot = $slotStart->setTimezone($viennaTz);
            $slots[] = [
                'start_time' => $slot['start_time'],
                'formatted_time' => $viennaSlot->format('D, d.m.Y H:i'),
                'booking_url' => buildBookingUrl($service['url'], $slot['start_time'], $customerEmail),
            ];
        }
    }

    return $slots;
}

function calendlyRequest(string $url, string $token): ?array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        $error = curl_error($ch);
    }
    curl_close($ch);

    if ($response === false) {
        logMessage('Calendly request failed: ' . $error);
        return null;
    }

    if ($httpCode !== 200) {
        logMessage('Calendly API returned HTTP ' . $httpCode . ' for url ' . $url);
        return null;
    }

    return json_decode($response, true);
}

function buildBookingUrl(string $baseUrl, string $startTime, string $email): string
{
    $month = (new DateTimeImmutable($startTime))->setTimezone(new DateTimeZone('Europe/Vienna'))->format('Y-m');
    $separator = strpos($baseUrl, '?') === false ? '?' : '&';

    return $baseUrl . $separator . http_build_query([
        'month' => $month,
        'email' => $email,
    ]);
}

function sendLastMinuteEmail(array $config, string $email, string $name, array $slotsByService): array
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['SMTP_USERNAME'];
        $mail->Password = $config['SMTP_PASSWORD'];
        $mail->SMTPSecure = ($config['SMTP_ENCRYPTION'] ?? 'tls') === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $config['SMTP_PORT'];
        $mail->Timeout = $config['SMTP_TIMEOUT'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($config['SMTP_FROM_EMAIL'], $config['SMTP_FROM_NAME']);
        $mail->addAddress($email, $name ?: $email);
        $mail->Subject = '🚨 Kurzfristig verfügbare Termine!';
        $mail->isHTML(true);
        $mail->Body = buildEmailBody($name, $slotsByService);

        $mail->send();
        return ['success' => true];
    } catch (MailException $exception) {
        return ['success' => false, 'error' => $exception->getMessage()];
    }
}

function buildEmailBody(string $name, array $slotsByService): string
{
    $totalSlots = 0;
    $servicesHtml = '';

    foreach ($slotsByService as $serviceSlug => $data) {
        $service = $data['service'];
        $slots = $data['slots'];
        $totalSlots += count($slots);

        $servicesHtml .= sprintf(
            '<div style="background:#f8fafc;border-radius:12px;padding:20px;margin:16px 0;">
                <h3 style="color:#4a90b8;margin:0 0 12px 0;font-size:18px;font-weight:600;">📚 %s</h3>
                <div style="display:flex;flex-direction:column;gap:8px;">',
            htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8')
        );

        foreach ($slots as $slot) {
            $servicesHtml .= sprintf(
                '<div style="background:#fff;border-radius:8px;padding:12px 16px;border-left:4px solid #52b3a4;">
                    <div style="font-weight:600;color:#2d3748;margin-bottom:4px;">%s</div>
                    <a href="%s" style="display:inline-block;background:linear-gradient(135deg,#4a90b8,#52b3a4);color:#fff;text-decoration:none;padding:8px 16px;border-radius:6px;font-weight:600;font-size:14px;">
                        → Jetzt buchen
                    </a>
                </div>',
                htmlspecialchars($slot['formatted_time'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($slot['booking_url'], ENT_QUOTES, 'UTF-8')
            );
        }
        $servicesHtml .= '</div></div>';
    }

    $greetingName = $name !== '' ? $name : 'Lerncoaching-Familie';

    return sprintf(
        '<!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Last-Minute Termine verfügbar</title>
            <!--[if mso]>
            <noscript>
                <xml>
                    <o:OfficeDocumentSettings>
                        <o:PixelsPerInch>96</o:PixelsPerInch>
                    </o:OfficeDocumentSettings>
                </xml>
            </noscript>
            <![endif]-->
            <style>
                @media only screen and (max-width: 600px) {
                    .email-container { width: 100%% !important; }
                    .email-content { padding: 20px 16px !important; }
                    .service-block { margin: 12px 0 !important; padding: 16px !important; }
                    .slot-item { padding: 10px 12px !important; }
                    .book-btn { width: 100%% !important; text-align: center !important; }
                }
            </style>
        </head>
        <body style="margin:0;padding:0;background-color:#f7fafc;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%%" style="background-color:#f7fafc;">
                <tr>
                    <td align="center" style="padding:20px 0;">
                        <table class="email-container" role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,0.1);">
                            <tr>
                                <td class="email-content" style="padding:32px 40px;">
                                    <!-- Header -->
                                    <div style="text-align:center;margin-bottom:32px;">
                                        <div style="background:linear-gradient(135deg,#4a90b8,#52b3a4);color:#fff;width:60px;height:60px;border-radius:50%%;display:inline-flex;align-items:center;justify-content:center;font-size:24px;margin-bottom:16px;">🌳</div>
                                        <h1 style="color:#4a90b8;margin:0;font-size:28px;font-weight:700;">Anna Braun Lerncoaching</h1>
                                        <div style="width:60px;height:3px;background:linear-gradient(135deg,#4a90b8,#52b3a4);margin:12px auto;border-radius:2px;"></div>
                                    </div>

                                    <!-- Alert Header -->
                                    <div style="background:linear-gradient(135deg,#4a90b8,#52b3a4);color:#fff;border-radius:12px;padding:20px;text-align:center;margin-bottom:24px;">
                                        <h2 style="margin:0;font-size:22px;font-weight:600;">🚨 Kurzfristig verfügbare Termine!</h2>
                                    </div>

                                    <!-- Content -->
                                    <p style="color:#2d3748;font-size:16px;line-height:1.6;margin:0 0 16px 0;">Liebe/r %s,</p>
                                    <p style="color:#2d3748;font-size:16px;line-height:1.6;margin:0 0 24px 0;">
                                        wir haben <strong style="color:#4a90b8;">%d kurzfristig verfügbare Termine</strong> in den nächsten 5 Tagen gefunden:
                                    </p>

                                    <!-- Services -->
                                    <div style="margin:24px 0;">
                                        %s
                                    </div>

                                    <!-- Call to Action -->
                                    <div style="background:#fff8e1;border-left:4px solid #ffa726;border-radius:8px;padding:16px;margin:24px 0;">
                                        <p style="color:#e65100;font-weight:600;margin:0;font-size:16px;">⚡ Schnell sein lohnt sich!</p>
                                        <p style="color:#bf360c;margin:8px 0 0 0;font-size:14px;">Die Termine werden nach dem Prinzip "Wer zuerst kommt, mahlt zuerst" vergeben.</p>
                                    </div>

                                    <!-- Footer -->
                                    <div style="margin-top:32px;padding-top:24px;border-top:2px solid #e2e8f0;">
                                        <p style="color:#2d3748;font-size:16px;margin:0 0 8px 0;">Herzliche Grüße,</p>
                                        <p style="color:#4a90b8;font-weight:600;font-size:18px;margin:0 0 16px 0;">Anna Braun</p>

                                        <div style="background:#f8fafc;border-radius:8px;padding:12px;text-align:center;">
                                            <p style="color:#718096;font-size:12px;margin:0;">📧 Du erhältst diese E-Mail, weil du Last-Minute Benachrichtigungen aktiviert hast.</p>
                                            <p style="color:#718096;font-size:12px;margin:4px 0 0 0;">Du kannst diese in der App jederzeit deaktivieren.</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>',
        htmlspecialchars($greetingName, ENT_QUOTES, 'UTF-8'),
        $totalSlots,
        $servicesHtml
    );
}

logMessage('=== Last-Minute Checker Started ===');

try {
    $pdo = getPDO();
    $config = require __DIR__ . '/config.php';
    $services = require __DIR__ . '/services_catalog.php';
    $token = getCalendlyToken();

    resetDailyNotificationCounters($pdo);

    $subscriptions = fetchActiveSubscriptions($pdo);
    logMessage('Found ' . count($subscriptions) . ' active subscriptions');

    foreach ($subscriptions as $subscription) {
        $serviceSlugs = json_decode($subscription['service_slugs'], true) ?: [];
        if (empty($serviceSlugs)) {
            continue;
        }

        $customerEmail = $subscription['email'];
        $customerName = $subscription['first_name'] ?? '';
        logMessage('Checking slots for ' . $customerEmail);

        $available = [];
        foreach ($serviceSlugs as $slug) {
            if (!isset($services[$slug])) {
                logMessage('Unknown service slug "' . $slug . '" for ' . $customerEmail);
                continue;
            }

            $serviceSlots = fetchSlotsForService($services[$slug], $token, $customerEmail);
            if (!empty($serviceSlots)) {
                $available[$slug] = [
                    'service' => $services[$slug],
                    'slots' => $serviceSlots,
                ];
                logMessage('Found ' . count($serviceSlots) . ' slots for ' . $services[$slug]['name']);
            }
        }

        if (empty($available)) {
            logMessage('No available slots for ' . $customerEmail);
            continue;
        }

        $result = sendLastMinuteEmail($config, $customerEmail, $customerName, $available);
        $totalSlots = array_sum(array_map(function($item) { return count($item['slots']); }, $available));

        $insert = $pdo->prepare(
            'INSERT INTO last_minute_notifications (customer_id, slots_found, services_checked, email_sent, email_error)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $subscription['customer_id'],
            $totalSlots,
            json_encode(array_keys($available), JSON_UNESCAPED_UNICODE),
            $result['success'] ? 1 : 0,
            $result['success'] ? null : ($result['error'] ?? 'unknown error'),
        ]);

        $update = $pdo->prepare(
            'UPDATE last_minute_subscriptions
             SET last_notification_sent = NOW(),
                 notification_count_today = notification_count_today + 1
             WHERE id = ?'
        );
        $update->execute([$subscription['id']]);

        logMessage('Notification for ' . $customerEmail . ': ' . ($result['success'] ? 'sent' : 'FAILED - ' . $result['error']));
    }
} catch (Throwable $throwable) {
    logMessage('ERROR: ' . $throwable->getMessage());
    exit(1);
}

logMessage('=== Last-Minute Checker Completed ===');
