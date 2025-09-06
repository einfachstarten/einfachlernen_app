<?php
session_start();
if (empty($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Calendly Configuration
$CALENDLY_TOKEN = getenv('CALENDLY_TOKEN') ?: 'PASTE_YOUR_TOKEN_HERE';
$ORG_URI = getenv('CALENDLY_ORG_URI') ?: 'https://api.calendly.com/organizations/PASTE_ORG_ID';

header('Content-Type: application/json; charset=utf-8');

function api_get($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        return [null, 'Network error: '.curl_error($ch)];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        return [null, "HTTP $code"];
    }

    $json = json_decode($res, true);
    return [$json, null];
}

$analytics_type = $_GET['type'] ?? 'service_stats';
$days = intval($_GET['days'] ?? 30);

try {
    switch ($analytics_type) {
        case 'service_stats':
            // Service Analytics für die letzten X Tage
            $start_date = (new DateTimeImmutable("-{$days} days", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            $end_date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

            $all_events = [];
            $page_token = null;

            do {
                $params = [
                    'organization' => $ORG_URI,
                    'min_start_time' => $start_date,
                    'max_start_time' => $end_date,
                    'count' => 100
                ];
                if ($page_token) $params['page_token'] = $page_token;

                $url = 'https://api.calendly.com/scheduled_events?' . http_build_query($params);
                list($data, $err) = api_get($url, $CALENDLY_TOKEN);

                if ($err) throw new Exception("Calendly API Error: $err");

                $events = $data['collection'] ?? [];
                $all_events = array_merge($all_events, $events);
                $page_token = $data['pagination']['next_page_token'] ?? null;

            } while ($page_token);

            // Analyze by service type
            $service_stats = [];
            $total_bookings = count($all_events);
            $total_revenue = 0;

            // Service pricing (real prices from Anna's overview)
            $service_prices = [
                'Beratungsgespräch' => 45,          // 50 Minuten
                'Lerntraining' => 45,               // 50 Minuten
                'Hörwahrnehmungstest' => 65,        // 50 Minuten
                'Neurofeedback 20' => 45,           // bis 20 Min (halbe Stunde Zeit)
                'Neurofeedback 40' => 65,           // bis 40 Min (eine Stunde Zeit)
                'Neurofeedback + Lerntraining' => 65 // kombiniert 50 Minuten
            ];

            foreach ($all_events as $event) {
                $service_name = $event['name'] ?? 'Unknown';

                // Normalize service names
                if (strpos($service_name, 'Lerntraining') !== false && strpos($service_name, 'Neurofeedback') !== false) {
                    $service_key = 'Neurofeedback + Lerntraining';
                } elseif (strpos($service_name, 'Lerntraining') !== false) {
                    $service_key = 'Lerntraining';
                } elseif (strpos($service_name, 'Beratung') !== false || strpos($service_name, 'Gespräch') !== false) {
                    $service_key = 'Beratungsgespräch';
                } elseif (strpos($service_name, 'Hörwahrnehmung') !== false || strpos($service_name, 'Test') !== false) {
                    $service_key = 'Hörwahrnehmungstest';
                } elseif (strpos($service_name, '20') !== false || strpos($service_name, 'bis 20') !== false) {
                    $service_key = 'Neurofeedback 20';
                } elseif (strpos($service_name, '40') !== false || strpos($service_name, 'bis 40') !== false) {
                    $service_key = 'Neurofeedback 40';
                } else {
                    // Fallback for unknown services
                    $service_key = 'Neurofeedback 40';
                }

                if (!isset($service_stats[$service_key])) {
                    $service_stats[$service_key] = [
                        'count' => 0,
                        'revenue' => 0,
                        'percentage' => 0
                    ];
                }

                $service_stats[$service_key]['count']++;
                $service_stats[$service_key]['revenue'] += $service_prices[$service_key] ?? 0;
                $total_revenue += $service_prices[$service_key] ?? 0;
            }

            // Calculate percentages
            foreach ($service_stats as $key => $stats) {
                $service_stats[$key]['percentage'] = $total_bookings > 0 ?
                    round(($stats['count'] / $total_bookings) * 100, 1) : 0;
            }

            // Sort by count
            uasort($service_stats, fn($a, $b) => $b['count'] <=> $a['count']);

            echo json_encode([
                'success' => true,
                'type' => 'service_stats',
                'period_days' => $days,
                'total_bookings' => $total_bookings,
                'total_revenue' => $total_revenue,
                'avg_booking_value' => $total_bookings > 0 ? round($total_revenue / $total_bookings, 2) : 0,
                'services' => $service_stats
            ]);
            break;

        case 'weekly_capacity':
            // Weekly Capacity Analysis für nächste 4 Wochen
            $weeks_data = [];

            for ($week = 0; $week < 4; $week++) {
                $week_start = (new DateTimeImmutable("next monday +{$week} weeks", new DateTimeZone('Europe/Vienna')))
                    ->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'));
                $week_end = $week_start->modify('+6 days 23 hours 59 minutes');

                $url = 'https://api.calendly.com/scheduled_events?' . http_build_query([
                    'organization' => $ORG_URI,
                    'status' => 'active',
                    'min_start_time' => $week_start->format('Y-m-d\TH:i:s\Z'),
                    'max_start_time' => $week_end->format('Y-m-d\TH:i:s\Z'),
                    'count' => 100
                ]);

                list($data, $err) = api_get($url, $CALENDLY_TOKEN);
                if ($err) throw new Exception("Calendly API Error: $err");

                $events = $data['collection'] ?? [];
                $booked_slots = count($events);

                // Geschätzte Kapazität (Anna's Arbeitszeiten)
                // Mo-Fr: 9-18 Uhr (9h), Sa: 9-14 Uhr (5h) = 50h/Woche
                // Bei 1h Slots = 50 Slots max, bei Mix eher 40 Slots realistisch
                $max_capacity = 40;
                $capacity_percentage = round(($booked_slots / $max_capacity) * 100, 1);

                // Revenue für diese Woche basierend auf realen Preisen
                $week_revenue = 0;
                foreach ($events as $event) {
                    $service_name = $event['name'] ?? '';

                    if (strpos($service_name, 'Lerntraining') !== false && strpos($service_name, 'Neurofeedback') !== false) {
                        $week_revenue += 65; // kombiniert
                    } elseif (strpos($service_name, 'Lerntraining') !== false) {
                        $week_revenue += 45; // Lerntraining
                    } elseif (strpos($service_name, 'Beratung') !== false || strpos($service_name, 'Gespräch') !== false) {
                        $week_revenue += 45; // Beratungsgespräch
                    } elseif (strpos($service_name, 'Hörwahrnehmung') !== false || strpos($service_name, 'Test') !== false) {
                        $week_revenue += 65; // Hörwahrnehmungstest
                    } elseif (strpos($service_name, '20') !== false) {
                        $week_revenue += 45; // Neurofeedback 20 Min
                    } elseif (strpos($service_name, '40') !== false) {
                        $week_revenue += 65; // Neurofeedback 40 Min
                    } else {
                        $week_revenue += 65; // Fallback: häufigster Preis
                    }
                }

                $weeks_data[] = [
                    'week_number' => $week + 1,
                    'week_start' => $week_start->setTimezone(new DateTimeZone('Europe/Vienna'))->format('d.m.Y'),
                    'week_end' => $week_end->setTimezone(new DateTimeZone('Europe/Vienna'))->format('d.m.Y'),
                    'booked_slots' => $booked_slots,
                    'max_capacity' => $max_capacity,
                    'capacity_percentage' => $capacity_percentage,
                    'revenue' => $week_revenue,
                    'available_slots' => $max_capacity - $booked_slots,
                    'status' => $capacity_percentage >= 80 ? 'full' :
                               ($capacity_percentage >= 50 ? 'medium' : 'low')
                ];
            }

            echo json_encode([
                'success' => true,
                'type' => 'weekly_capacity',
                'weeks' => $weeks_data,
                'total_upcoming_revenue' => array_sum(array_column($weeks_data, 'revenue')),
                'average_capacity' => round(array_sum(array_column($weeks_data, 'capacity_percentage')) / 4, 1)
            ]);
            break;

        default:
            throw new Exception('Unknown analytics type');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
