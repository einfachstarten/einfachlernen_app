<?php
// ========================= CONFIG =========================
// Empfohlen: in der Server-Umgebung als ENV setzen
// (z. B. im Hosting-Panel) und hier nur getenv() nutzen.
$CALENDLY_TOKEN = getenv('CALENDLY_TOKEN') ?: 'PASTE_YOUR_TOKEN_HERE';
$ORG_URI        = getenv('CALENDLY_ORG_URI') ?: 'https://api.calendly.com/organizations/PASTE_ORG_ID';
$DAYS_AHEAD     = 365;                              // Wie weit in die Zukunft suchen
$TIMEZONE_OUT   = 'Europe/Vienna';                  // Anzeige-Zeitzone
$INCLUDE_INVITEE_STATUS = true;                     // pro Event Invitee-Status nachladen (ein weiterer API-Call je Event)
// ==========================================================

header('Content-Type: text/html; charset=utf-8');

function bad_request($msg) { http_response_code(400); echo "<p style='color:#e74c3c;'>$msg</p>"; exit; }
function api_get($url, $token) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 25,
  ]);
  $res = curl_exec($ch);
  if ($res === false) { return [null, 'curl_error: '.curl_error($ch)]; }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) { return [null, "http_$code: $res"]; }
  $json = json_decode($res, true);
  if ($json === null) { return [null, 'json_decode_error']; }
  return [$json, null];
}

function iso_to_local($iso, $tzOut) {
  try {
    $dt = new DateTimeImmutable(str_replace('Z','+00:00',$iso));
    return $dt->setTimezone(new DateTimeZone($tzOut));
  } catch(Throwable $e) {
    return null;
  }
}

function minutes_between($startIso, $endIso) {
  try {
    $a = new DateTimeImmutable(str_replace('Z','+00:00',$startIso));
    $b = new DateTimeImmutable(str_replace('Z','+00:00',$endIso));
    return max(0, (int) round(($b->getTimestamp() - $a->getTimestamp())/60));
  } catch(Throwable $e) { return null; }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
if ($email === '') { bad_request('Fehlender Parameter: ?email=adresse@example.com'); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { bad_request('Ungültige E-Mail-Adresse.'); }
if (!$CALENDLY_TOKEN || !$ORG_URI) { bad_request('Fehlende Config: CALENDLY_TOKEN oder ORG_URI.'); }

// Zeitraum: heute bis +DAYS_AHEAD (UTC)
$todayUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(0,0,0);
$toUtc    = $todayUtc->modify("+{$DAYS_AHEAD} days")->setTime(23,59,59);

$min_start = $todayUtc->format('Y-m-d\TH:i:s\Z');
$max_start = $toUtc->format('Y-m-d\TH:i:s\Z');

$base = 'https://api.calendly.com';
$count = 50;
$page_token = '';
$events = [];

do {
  $url = $base.'/scheduled_events?'
       . http_build_query([
          'organization'   => $ORG_URI,
          'status'         => 'active',
          'invitee_email'  => $email,
          'min_start_time' => $min_start,
          'max_start_time' => $max_start,
          'count'          => $count,
          'page_token'     => $page_token ?: null
        ]);
  // Entferne leere page_token-Keys
  $url = preg_replace('/(&page_token=)$/','',$url);

  list($data, $err) = api_get($url, $CALENDLY_TOKEN);
  if ($err) { bad_request('API-Fehler (events): '.h($err)); }

  $collection = $data['collection'] ?? [];
  foreach ($collection as $ev) {
    $events[] = $ev;
  }
  $page_token = $data['pagination']['next_page_token'] ?? '';
} while ($page_token);

// Sortieren nach Startzeit (aufsteigend)
usort($events, fn($a,$b)=>strcmp($a['start_time']??'', $b['start_time']??''));

// Optional Invitee-Status nachladen (nur wenige Treffer → ok)
$inviteeStatusMap = []; // event_uuid => ['name'=>..., 'email'=>..., 'status'=>..., 'is_reschedule'=>...]
if ($INCLUDE_INVITEE_STATUS && $events) {
  foreach ($events as $ev) {
    $uuid = basename($ev['uri']);
    $u = "$base/scheduled_events/$uuid/invitees";
    list($inv, $err2) = api_get($u, $CALENDLY_TOKEN);
    if ($err2 || !isset($inv['collection'])) { continue; }
    foreach ($inv['collection'] as $i) {
      $em = strtolower($i['email'] ?? '');
      if ($em === strtolower($email)) {
        $inviteeStatusMap[$uuid] = [
          'name'          => $i['name'] ?? '',
          'email'         => $i['email'] ?? '',
          'status'        => $i['status'] ?? '',
          'is_reschedule' => $i['is_reschedule'] ?? ($i['rescheduled'] ?? false),
        ];
        break;
      }
    }
  }
}

// Gruppiere Events nach Datum
$eventsByDate = [];
foreach ($events as $ev) {
  $startIso = $ev['start_time'] ?? null;
  $startLocal = $startIso ? iso_to_local($startIso, $TIMEZONE_OUT) : null;
  $dateKey = $startLocal ? $startLocal->format('Y-m-d') : 'unknown';
  $eventsByDate[$dateKey][] = $ev;
}

// ---------- Render ----------
?><!doctype html>
<html lang="de">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Meine Termine – Anna Braun Lerncoaching</title>
<style>
  :root {
    --primary: #4a90b8;
    --secondary: #52b3a4;
    --accent-green: #7cb342;
    --accent-orange: #ff7043;
    --accent-pink: #f48fb1;
    --light-blue: #e3f2fd;
    --white: #ffffff;
    --gray-light: #f8f9fa;
    --gray-medium: #6c757d;
    --gray-dark: #343a40;
    --shadow: rgba(0, 0, 0, 0.1);
  }

  * {
    box-sizing: border-box;
  }

  body {
    margin: 0;
    background: linear-gradient(135deg, var(--light-blue) 0%, var(--white) 100%);
    min-height: 100vh;
    color: var(--gray-dark);
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    line-height: 1.5;
  }

  .header {
    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
    color: white;
    padding: 2rem 0;
    text-align: center;
    box-shadow: 0 4px 20px var(--shadow);
  }

  .logo {
    width: 80px;
    height: 80px;
    margin: 0 auto 1rem;
    background: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
  }

  .header h1 {
    margin: 0;
    font-size: 2.2rem;
    font-weight: 300;
  }

  .header .subtitle {
    margin: 0.5rem 0 0;
    opacity: 0.9;
    font-size: 1.1rem;
  }

  .container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 2rem 1rem;
  }

  .user-info {
    background: white;
    padding: 1.5rem;
    border-radius: 15px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 15px var(--shadow);
    text-align: center;
  }

  .user-info .email {
    color: var(--primary);
    font-weight: 600;
    font-size: 1.1rem;
  }

  .calendar-section {
    margin-bottom: 3rem;
  }

  .date-header {
    background: linear-gradient(90deg, var(--accent-green), var(--accent-orange));
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 12px;
    margin: 2rem 0 1rem;
    font-size: 1.2rem;
    font-weight: 600;
    box-shadow: 0 3px 10px var(--shadow);
    position: sticky;
    top: 20px;
    z-index: 10;
  }

  .appointment-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1rem;
    box-shadow: 0 3px 15px var(--shadow);
    border-left: 5px solid var(--primary);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }

  .appointment-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
  }

  .appointment-time {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
  }

  .time-badge {
    background: linear-gradient(45deg, var(--primary), var(--secondary));
    color: white;
    padding: 0.5rem 1rem;
    border-radius: 25px;
    font-weight: 600;
    font-size: 1.1rem;
    min-width: 140px;
    text-align: center;
  }

  .duration {
    background: var(--gray-light);
    color: var(--gray-medium);
    padding: 0.3rem 0.8rem;
    border-radius: 15px;
    font-size: 0.9rem;
  }

  .appointment-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: var(--gray-dark);
    margin-bottom: 0.8rem;
  }

  .appointment-details {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.5rem 1rem;
    align-items: center;
    color: var(--gray-medium);
  }

  .detail-icon {
    width: 20px;
    height: 20px;
    background: var(--light-blue);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
  }

  .status-badges {
    margin-top: 1rem;
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
  }

  .status-badge {
    padding: 0.3rem 0.8rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 500;
  }

  .status-active {
    background: #e8f5e8;
    color: #2e7d32;
  }

  .status-confirmed {
    background: #e3f2fd;
    color: #1565c0;
  }

  .no-appointments {
    text-align: center;
    padding: 3rem;
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 15px var(--shadow);
  }

  .no-appointments-icon {
    font-size: 4rem;
    color: var(--gray-medium);
    margin-bottom: 1rem;
  }

  .footer {
    text-align: center;
    color: var(--gray-medium);
    font-size: 0.9rem;
    margin-top: 3rem;
    padding: 2rem;
    border-top: 1px solid #e0e0e0;
  }

  /* Loading Animation */
  .loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, var(--light-blue) 0%, var(--white) 100%);
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    z-index: 9999;
    opacity: 1;
    transition: opacity 0.5s ease;
  }

  .loading-overlay.fade-out {
    opacity: 0;
    pointer-events: none;
  }

  .loading-logo {
    width: 100px;
    height: 100px;
    background: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    margin-bottom: 2rem;
    box-shadow: 0 8px 30px var(--shadow);
    animation: pulse 2s ease-in-out infinite;
  }

  .loading-text {
    color: var(--primary);
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 1rem;
    text-align: center;
  }

  .loading-subtext {
    color: var(--gray-medium);
    font-size: 1rem;
    text-align: center;
  }

  .loading-dots {
    display: flex;
    gap: 0.5rem;
    margin-top: 1.5rem;
  }

  .loading-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: linear-gradient(45deg, var(--primary), var(--secondary));
    animation: bounce 1.4s ease-in-out infinite both;
  }

  .loading-dot:nth-child(1) { animation-delay: -0.32s; }
  .loading-dot:nth-child(2) { animation-delay: -0.16s; }
  .loading-dot:nth-child(3) { animation-delay: 0s; }

  @keyframes pulse {
    0%, 100% {
      transform: scale(1);
      box-shadow: 0 8px 30px var(--shadow);
    }
    50% {
      transform: scale(1.05);
      box-shadow: 0 12px 40px rgba(0,0,0,0.2);
    }
  }

  @keyframes bounce {
    0%, 80%, 100% {
      transform: scale(0);
    }
    40% {
      transform: scale(1);
    }
  }

  .main-content {
    opacity: 0;
    transition: opacity 0.5s ease;
  }

  .main-content.show {
    opacity: 1;
  }

  @media (max-width: 768px) {
    .header {
      padding: 1.5rem 0;
    }
    
    .header h1 {
      font-size: 1.8rem;
    }
    
    .container {
      padding: 1rem;
    }
    
    .appointment-time {
      flex-direction: column;
      align-items: flex-start;
      gap: 0.5rem;
    }
    
    .time-badge {
      min-width: auto;
    }

    .loading-logo {
      width: 80px;
      height: 80px;
      font-size: 2.5rem;
    }

    .loading-text {
      font-size: 1.1rem;
    }
  }
</style>

<body>
  <!-- Loading Animation -->
  <div class="loading-overlay" id="loadingOverlay">
    <div class="loading-logo">🌳</div>
    <div class="loading-text">Termine werden geladen...</div>
    <div class="loading-subtext">Bitte haben Sie einen Moment Geduld</div>
    <div class="loading-dots">
      <div class="loading-dot"></div>
      <div class="loading-dot"></div>
      <div class="loading-dot"></div>
    </div>
  </div>

  <!-- Main Content -->
  <div class="main-content" id="mainContent">
  <div class="header">
    <div class="logo">🌳</div>
    <h1>Anna Braun</h1>
    <p class="subtitle">Ganzheitliches Lerncoaching</p>
  </div>

  <div class="container">
    <div class="user-info">
      <p>Ihre gebuchten Termine</p>
      <div class="email"><?=h($email)?></div>
    </div>

    <?php if (!$events): ?>
      <div class="no-appointments">
        <div class="no-appointments-icon">📅</div>
        <h2>Keine anstehenden Termine</h2>
        <p>Sie haben derzeit keine gebuchten Termine im gewählten Zeitraum.</p>
      </div>
    <?php else: ?>

      <?php foreach ($eventsByDate as $dateKey => $dayEvents): 
        $dateObj = DateTime::createFromFormat('Y-m-d', $dateKey);
        $isToday = $dateKey === date('Y-m-d');
        $dayName = $dateObj ? $dateObj->format('l') : '';
        $dayNames = [
          'Monday' => 'Montag', 'Tuesday' => 'Dienstag', 'Wednesday' => 'Mittwoch',
          'Thursday' => 'Donnerstag', 'Friday' => 'Freitag', 'Saturday' => 'Samstag', 'Sunday' => 'Sonntag'
        ];
        $dayName = $dayNames[$dayName] ?? $dayName;
      ?>
      
      <div class="calendar-section">
        <div class="date-header">
          <?= $isToday ? '🗓️ Heute' : '📅' ?> 
          <?= $dayName ?>, <?= $dateObj ? $dateObj->format('d.m.Y') : $dateKey ?>
        </div>

        <?php foreach ($dayEvents as $ev):
          $uuid = basename($ev['uri']);
          $startIso = $ev['start_time'] ?? null;
          $endIso   = $ev['end_time'] ?? null;
          $startLocal = $startIso ? iso_to_local($startIso, $TIMEZONE_OUT) : null;
          $endLocal   = $endIso   ? iso_to_local($endIso, $TIMEZONE_OUT) : null;
          $durMin = ($startIso && $endIso) ? minutes_between($startIso,$endIso) : null;
          $evName = $ev['name'] ?? '';
          $status = $ev['status'] ?? 'active';
          $loc    = $ev['location'] ?? [];
          $locStr = $loc['join_url'] ?? ($loc['location'] ?? ($loc['type'] ?? '–'));
          $inv    = $inviteeStatusMap[$uuid] ?? null;
        ?>

        <div class="appointment-card">
          <div class="appointment-time">
            <div class="time-badge">
              <?= $startLocal?->format('H:i') ?: '–' ?>–<?= $endLocal?->format('H:i') ?: '–' ?>
            </div>
            <?php if ($durMin): ?>
              <div class="duration"><?= $durMin ?> Min.</div>
            <?php endif; ?>
          </div>

          <div class="appointment-title"><?=h($evName)?></div>

          <div class="appointment-details">
            <div class="detail-icon">🏢</div>
            <div><?=h($locStr)?></div>
            
            <?php if ($inv && $inv['name']): ?>
            <div class="detail-icon">👤</div>
            <div><?=h($inv['name'])?></div>
            <?php endif; ?>
          </div>

          <div class="status-badges">
            <span class="status-badge status-active"><?=h($status)?></span>
            <?php if ($inv && $inv['status']): ?>
              <span class="status-badge status-confirmed">Status: <?=h($inv['status'])?></span>
            <?php endif; ?>
          </div>
        </div>

        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>

    <?php endif; ?>

    <div class="footer">
      <p>Anna Braun – Ganzheitliches Lerncoaching</p>
      <p>Termine werden über Calendly verwaltet</p>
    </div>
  </div>
  </div> <!-- End main-content -->

  <script>
    // Hide loading animation when page is fully loaded
    window.addEventListener('load', function() {
      const loadingOverlay = document.getElementById('loadingOverlay');
      const mainContent = document.getElementById('mainContent');
      
      // Fade out loading animation
      loadingOverlay.classList.add('fade-out');
      
      // Show main content
      mainContent.classList.add('show');
      
      // Remove loading overlay from DOM after animation
      setTimeout(() => {
        loadingOverlay.remove();
      }, 500);
    });

    // Fallback: Hide loading after 10 seconds maximum
    setTimeout(() => {
      const loadingOverlay = document.getElementById('loadingOverlay');
      const mainContent = document.getElementById('mainContent');
      
      if (loadingOverlay) {
        loadingOverlay.classList.add('fade-out');
        mainContent.classList.add('show');
        setTimeout(() => loadingOverlay.remove(), 500);
      }
    }, 10000);
  </script>
</body>
</html>