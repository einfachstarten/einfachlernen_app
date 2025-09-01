<?php
// slots.php
// Freie Calendly-Termine suchen mit Anna Braun Design

// Config aus .htaccess
$token = getenv('CALENDLY_TOKEN');
$org   = getenv('CALENDLY_ORG_URI');

// Service-Mapping (Slug → URI + Buchungslink + Dauer)
$services = [
    "lerntraining" => [
        "uri" => "https://api.calendly.com/event_types/ADE2NXSJ5RCEO3YV",
        "url" => "https://calendly.com/einfachlernen/lerntraining",
        "dur" => 50,
        "name" => "Lerntraining"
    ],
    "neurofeedback-20" => [
        "uri" => "https://api.calendly.com/event_types/ec567e31-b98b-4ed4-9beb-b01c32649b9b",
        "url" => "https://calendly.com/einfachlernen/neurofeedback-training-20-min",
        "dur" => 20,
        "name" => "Neurofeedback 20 Min"
    ],
    "neurofeedback-40" => [
        "uri" => "https://api.calendly.com/event_types/2ad6fc6d-7a65-42dd-ba6e-0135945ebb9a",
        "url" => "https://calendly.com/einfachlernen/neurofeedback-training-40-minuten",
        "dur" => 40,
        "name" => "Neurofeedback 40 Min"
    ],
];

function call_api($url, $token, $method = 'GET', $data = null) {
    $opts = [
        "http" => [
            "method" => $method,
            "header" => "Authorization: Bearer $token\r\n",
            "timeout" => 15
        ]
    ];
    
    if ($method === 'POST' && $data) {
        $opts["http"]["header"] .= "Content-Type: application/json\r\n";
        $opts["http"]["content"] = json_encode($data);
    }
    
    $ctx = stream_context_create($opts);
    $resp = file_get_contents($url, false, $ctx);
    return json_decode($resp, true);
}

// Build Calendly link with month parameter
function build_calendly_link($baseUrl, $startIso) {
    if (empty($baseUrl) || empty($startIso)) return '#';
    
    $month = substr($startIso, 0, 7); // YYYY-MM
    $sep = (strpos($baseUrl, '?') !== false) ? '&' : '?';
    
    return $baseUrl . $sep . 'month=' . rawurlencode($month);
}

// Ajax-Mode: wenn Parameter gesetzt
if (isset($_GET['service']) && isset($_GET['count']) && isset($_GET['week'])) {
    header('Content-Type: application/json');
    
    $slug = $_GET['service'];
    $target = min(10, max(1, intval($_GET['count'])));
    $current_week = intval($_GET['week']);
    
    if (!isset($services[$slug])) {
        http_response_code(400);
        echo json_encode(["error" => "Unbekannter Service"]);
        exit;
    }
    
    $service = $services[$slug];
    $slots_by_date = []; // Group all slots by date
    $found_dates = []; // Track which dates we already have
    
    $base = new DateTimeImmutable("tomorrow midnight", new DateTimeZone("UTC"));
    $start = $base->modify("+$current_week week");
    $end   = $start->modify("+6 days 23 hours 59 minutes 59 seconds");

    $url = "https://api.calendly.com/event_type_available_times"
         . "?event_type=" . urlencode($service["uri"])
         . "&start_time=" . $start->format("Y-m-d\TH:i:s\Z")
         . "&end_time="   . $end->format("Y-m-d\TH:i:s\Z")
         . "&timezone=Europe/Vienna";

    $data = call_api($url, $token);
    
    foreach ($data["collection"] ?? [] as $slot) {
        if (!isset($slot["start_time"])) continue;
        $startIso = $slot["start_time"];
        $startDt = new DateTimeImmutable($startIso);
        $endDt = $startDt->modify("+{$service['dur']} minutes");
        
        // Get date in Vienna timezone for grouping
        $slotDate = $startDt->setTimezone(new DateTimeZone("Europe/Vienna"))->format("Y-m-d");
        
        // Initialize date group if not exists
        if (!isset($slots_by_date[$slotDate])) {
            $slots_by_date[$slotDate] = [];
            $found_dates[] = $slotDate;
        }
        
        // Build direct link with month parameter
        $booking_link = build_calendly_link($service["url"], $startIso);

        $slots_by_date[$slotDate][] = [
            "start" => $startDt->setTimezone(new DateTimeZone("Europe/Vienna"))->format("D, d.m.Y H:i"),
            "end"   => $endDt->setTimezone(new DateTimeZone("Europe/Vienna"))->format("H:i"),
            "time_only" => $startDt->setTimezone(new DateTimeZone("Europe/Vienna"))->format("H:i"),
            "start_iso" => $startIso,
            "end_iso" => $endDt->format("Y-m-d\TH:i:s\Z"),
            "booking_url" => $booking_link,
            "weeks_from_now" => $current_week + 1,
            "slot_date" => $slotDate
        ];
    }
    
    // Convert grouped data back to flat structure for frontend
    $found = [];
    foreach ($slots_by_date as $date => $slots) {
        $found[] = [
            "date" => $date,
            "date_formatted" => (new DateTime($date))->format("D, d.m.Y"),
            "slots" => $slots,
            "weeks_from_now" => $slots[0]["weeks_from_now"]
        ];
    }

    // Datum-Range für diese Woche
    $week_start = $start->setTimezone(new DateTimeZone("Europe/Vienna"));
    $week_end = $end->setTimezone(new DateTimeZone("Europe/Vienna"));
    
    echo json_encode([
        "current_week" => $current_week + 1,
        "week_range" => $week_start->format("d.m") . " - " . $week_end->format("d.m.Y"),
        "slots_this_week" => count($found),
        "slots" => $found,
        "has_more_weeks" => $current_week < 25
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Freie Termine suchen – Anna Braun Lerncoaching</title>
    <style>
        :root {
            --primary: #4a90b8;
            --secondary: #52b3a4;
            --accent-green: #7cb342;
            --accent-teal: #26a69a;
            --accent-blue: #42a5f5;
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
            line-height: 1.4;
        }

        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 1rem 0;
            text-align: center;
            box-shadow: 0 2px 15px var(--shadow);
        }

        .logo {
            width: 45px;
            height: 45px;
            margin: 0 auto 0.5rem;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .header h1 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 300;
        }

        .header .subtitle {
            margin: 0.2rem 0 0;
            opacity: 0.9;
            font-size: 0.85rem;
        }

        /* Hide header when in iframe */
        .embedded .header {
            display: none;
        }

        .embedded .container {
            padding-top: 1rem;
        }

        /* Ensure iframe compatibility */
        .embedded {
            pointer-events: auto !important;
            touch-action: auto !important;
        }

        .embedded * {
            pointer-events: auto !important;
        }

        .embedded select,
        .embedded button,
        .embedded input {
            pointer-events: auto !important;
            z-index: 999999 !important;
            position: relative !important;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 1.5rem 1rem;
        }

        .search-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 3px 15px var(--shadow);
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--gray-dark);
        }

        .form-select {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            font-size: 1rem;
            color: var(--gray-dark);
            transition: border-color 0.2s ease;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .search-button {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .search-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        .search-button:disabled {
            background: var(--gray-medium);
            cursor: not-allowed;
            transform: none;
        }

        .progress-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 3px 15px var(--shadow);
            display: none;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .progress-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-dark);
        }

        .week-info {
            color: var(--gray-medium);
            font-size: 0.9rem;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--gray-light);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--accent-teal));
            width: 0%;
            transition: width 0.3s ease;
        }

        .progress-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 0.8rem;
            background: var(--light-blue);
            border-radius: 8px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            display: block;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--gray-medium);
            margin-top: 0.2rem;
        }

        .current-week-indicator {
            background: var(--accent-teal);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.9rem;
            text-align: center;
            margin-bottom: 1rem;
        }

        .results-section {
            display: none;
        }

        .results-header {
            background: white;
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1rem;
            box-shadow: 0 3px 15px var(--shadow);
            text-align: center;
        }

        .results-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--gray-dark);
            margin: 0 0 0.5rem;
        }

        .results-subtitle {
            color: var(--gray-medium);
            font-size: 0.9rem;
        }

        .slot-card {
            background: white;
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 0.8rem;
            box-shadow: 0 2px 12px var(--shadow);
            border-left: 4px solid var(--primary);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .slot-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }

        .slot-info {
            margin-bottom: 1rem;
        }

        .slot-date {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 0.3rem;
        }

        .slot-week-info {
            color: var(--gray-medium);
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .slot-times {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .time-button {
            background: linear-gradient(45deg, var(--accent-green), var(--accent-teal));
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
        }

        .time-button:hover {
            transform: scale(1.05);
            color: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid var(--gray-light);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
            margin-right: 0.5rem;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .no-slots {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 3px 15px var(--shadow);
        }

        .no-slots-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .progress-stats {
                grid-template-columns: 1fr 1fr;
            }

            .slot-card {
                padding: 1rem;
            }

            .slot-times {
                gap: 0.4rem;
            }

            .time-button {
                padding: 0.6rem 0.8rem;
                font-size: 0.8rem;
                min-width: 80px;
                text-align: center;
            }

            .slot-date {
                font-size: 1rem;
            }

            .slot-week-info {
                font-size: 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 1.2rem;
            }
            
            .logo {
                width: 35px;
                height: 35px;
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">🔍</div>
        <h1>Anna Braun</h1>
        <p class="subtitle">Freie Termine suchen</p>
    </div>

    <div class="container">
        <div class="search-card">
            <h2 style="margin-top: 0; color: var(--gray-dark);">Terminsuche starten</h2>
            <form id="slotForm">
                <div class="form-group">
                    <label for="service">Service auswählen:</label>
                    <select name="service" id="service" class="form-select">
                        <?php foreach ($services as $slug => $info): ?>
                            <option value="<?= htmlspecialchars($slug) ?>">
                                <?= htmlspecialchars($info['name']) ?> (<?= $info['dur'] ?> Min)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="count">Anzahl gewünschter Termine:</label>
                    <select name="count" id="count" class="form-select">
                        <?php for($i = 1; $i <= 15; $i++): ?>
                            <option value="<?= $i ?>" <?= $i === 5 ? 'selected' : '' ?>>
                                <?= $i ?> Tag<?= $i > 1 ? 'e' : '' ?> mit freien Terminen
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="search-button" id="searchBtn">
                    🔍 Terminsuche starten
                </button>
            </form>
        </div>

        <div class="progress-section" id="progressSection">
            <div class="progress-header">
                <div class="progress-title">Suche läuft...</div>
                <div class="week-info" id="weekInfo">Initialisierung...</div>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <div class="current-week-indicator" id="currentWeek">
                Durchsuche Woche...
            </div>
            <div class="progress-stats">
                <div class="stat-item">
                    <span class="stat-value" id="slotsFound">0</span>
                    <div class="stat-label">Tage gefunden</div>
                </div>
                <div class="stat-item">
                    <span class="stat-value" id="slotsTarget">0</span>
                    <div class="stat-label">Tage gesucht</div>
                </div>
                <div class="stat-item">
                    <span class="stat-value" id="weeksSearched">0</span>
                    <div class="stat-label">Wochen durchsucht</div>
                </div>
                <div class="stat-item">
                    <span class="stat-value" id="currentWeekSlots">0</span>
                    <div class="stat-label">Diese Woche</div>
                </div>
            </div>
        </div>

        <div class="results-section" id="resultsSection">
            <div class="results-header">
                <div class="results-title" id="resultsTitle">Gefundene Termine</div>
                <div class="results-subtitle" id="resultsSubtitle"></div>
            </div>
            <div id="slotsContainer"></div>
        </div>
    </div>

    <script>
        let searchActive = false;
        let allSlots = [];

        // Check if in iframe and hide header
        function checkIfEmbedded() {
            if (window.self !== window.top) {
                document.body.classList.add('embedded');
                console.log('Running in iframe mode');
            } else {
                console.log('Running standalone');
            }
        }

        // Debug click events
        function addClickDebugger() {
            // Add click listeners to all interactive elements
            const selects = document.querySelectorAll('select');
            const buttons = document.querySelectorAll('button');
            
            console.log(`Found ${selects.length} selects and ${buttons.length} buttons`);
            
            // Test if elements are really clickable
            selects.forEach((select, index) => {
                console.log(`Select ${index}:`, {
                    id: select.id,
                    disabled: select.disabled,
                    pointerEvents: getComputedStyle(select).pointerEvents,
                    zIndex: getComputedStyle(select).zIndex,
                    position: getComputedStyle(select).position
                });
                
                // Force click handler
                select.addEventListener('mousedown', (e) => {
                    console.log('Select mousedown:', select.id);
                }, true);
                
                select.addEventListener('click', (e) => {
                    console.log('Select clicked:', select.id);
                }, true);
                
                select.addEventListener('change', (e) => {
                    console.log('Select changed:', select.id, select.value);
                }, true);
            });
            
            buttons.forEach((button, index) => {
                console.log(`Button ${index}:`, {
                    id: button.id,
                    disabled: button.disabled,
                    pointerEvents: getComputedStyle(button).pointerEvents,
                    zIndex: getComputedStyle(button).zIndex,
                    position: getComputedStyle(button).position
                });
                
                button.addEventListener('mousedown', (e) => {
                    console.log('Button mousedown:', button.id);
                }, true);
                
                button.addEventListener('click', (e) => {
                    console.log('Button clicked:', button.id);
                }, true);
            });
        }

        // Ensure all interactive elements work in iframe
        function initializeInteractivity() {
            // Force re-enable pointer events
            document.body.style.pointerEvents = 'auto';
            
            // Add aggressive CSS fixes
            const style = document.createElement('style');
            style.textContent = `
                .embedded * {
                    pointer-events: auto !important;
                    user-select: auto !important;
                }
                
                .embedded select,
                .embedded button,
                .embedded input {
                    pointer-events: auto !important;
                    z-index: 999999 !important;
                    position: relative !important;
                }
            `;
            document.head.appendChild(style);
            
            console.log('Interactivity initialized');
            
            // Add debug logging
            setTimeout(addClickDebugger, 500);
        }

        document.getElementById('slotForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            if (searchActive) return;
            
            const service = e.target.service.value;
            const count = parseInt(e.target.count.value);
            
            // Reset UI
            searchActive = true;
            document.getElementById('searchBtn').disabled = true;
            document.getElementById('searchBtn').innerHTML = '<span class="loading-spinner"></span>Suche läuft...';
            document.getElementById('progressSection').style.display = 'block';
            document.getElementById('resultsSection').style.display = 'none';
            
            // Initialize progress
            allSlots = [];
            document.getElementById('slotsFound').textContent = '0';
            document.getElementById('slotsTarget').textContent = count;
            document.getElementById('weeksSearched').textContent = '0';
            document.getElementById('currentWeekSlots').textContent = '0';
            document.getElementById('progressFill').style.width = '0%';
            
            let week = 0;
            let totalFound = 0;
            
            while (totalFound < count && week < 26) {
                try {
                    const response = await fetch(`slots.php?service=${service}&count=${count}&week=${week}`);
                    
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    
                    const contentType = response.headers.get("content-type");
                    if (!contentType || !contentType.includes("application/json")) {
                        throw new Error("Server returned HTML instead of JSON. Check if slots.php is handling the AJAX request correctly.");
                    }
                    
                    const data = await response.json();
                    
                    if (data.error) {
                        alert('Fehler: ' + data.error);
                        break;
                    }
                    
                    // Update UI
                    const weeksText = data.current_week === 1 ? 'nächste Woche' : `in ${data.current_week} Wochen`;
                    document.getElementById('currentWeek').textContent = 
                        `Durchsuche ${weeksText}: ${data.week_range}`;
                    document.getElementById('weekInfo').textContent = data.week_range;
                    document.getElementById('weeksSearched').textContent = week + 1;
                    document.getElementById('currentWeekSlots').textContent = data.slots_this_week;
                    
                    // Add new slots
                    if (data.slots && data.slots.length > 0) {
                        allSlots.push(...data.slots);
                        totalFound = allSlots.length;
                        
                        // Limit to target count
                        if (totalFound > count) {
                            allSlots = allSlots.slice(0, count);
                            totalFound = count;
                        }
                    }
                    
                    document.getElementById('slotsFound').textContent = totalFound;
                    
                    // Update progress bar
                    const progress = Math.min((totalFound / count) * 100, 100);
                    document.getElementById('progressFill').style.width = progress + '%';
                    
                    // Stop if we found enough or no more weeks
                    if (totalFound >= count || !data.has_more_weeks) {
                        break;
                    }
                    
                    week++;
                    
                    // Small delay for better UX
                    await new Promise(resolve => setTimeout(resolve, 300));
                    
                } catch (error) {
                    console.error('Search error:', error);
                    alert('Fehler bei der Suche: ' + error.message);
                    break;
                }
            }
            
            // Show results
            showResults(allSlots, count);
            
            // Reset search button
            searchActive = false;
            document.getElementById('searchBtn').disabled = false;
            document.getElementById('searchBtn').innerHTML = '🔍 Neue Suche starten';
            document.getElementById('progressSection').style.display = 'none';
        });

        function showResults(slots, targetCount) {
            const resultsSection = document.getElementById('resultsSection');
            const slotsContainer = document.getElementById('slotsContainer');
            const resultsTitle = document.getElementById('resultsTitle');
            const resultsSubtitle = document.getElementById('resultsSubtitle');
            
            if (slots.length === 0) {
                slotsContainer.innerHTML = `
                    <div class="no-slots">
                        <div class="no-slots-icon">😔</div>
                        <h3>Keine freien Termine gefunden</h3>
                        <p>In den nächsten 26 Wochen wurden keine verfügbaren Termine gefunden. Versuchen Sie es später erneut oder kontaktieren Sie uns direkt.</p>
                    </div>
                `;
                resultsTitle.textContent = 'Keine Termine verfügbar';
                resultsSubtitle.textContent = '';
            } else {
                resultsTitle.textContent = `${slots.length} Tage mit freien Terminen gefunden`;
                resultsSubtitle.textContent = slots.length >= targetCount ? 
                    'Alle gewünschten Tage verfügbar!' : 
                    `${targetCount - slots.length} weitere Tage nicht verfügbar`;
                
                slotsContainer.innerHTML = slots.map(daySlot => {
                    const weeksFromNow = daySlot.weeks_from_now || 1;
                    const weeksText = weeksFromNow === 1 ? 'nächste Woche' : `in ${weeksFromNow} Wochen`;
                    
                    // Create time buttons for each slot on this day
                    const timeButtons = daySlot.slots.map(slot => {
                        return `<a href="${slot.booking_url}" target="_blank" class="time-button">
                            ${slot.time_only} Uhr
                        </a>`;
                    }).join('');
                    
                    return `
                        <div class="slot-card">
                            <div class="slot-info">
                                <div class="slot-date">${daySlot.date_formatted}</div>
                                <div class="slot-week-info">${weeksText} • ${daySlot.slots.length} Termin${daySlot.slots.length > 1 ? 'e' : ''} verfügbar</div>
                            </div>
                            <div class="slot-times">
                                ${timeButtons}
                            </div>
                        </div>
                    `;
                }).join('');
            }
            
            resultsSection.style.display = 'block';
        }

        // Initialize
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                checkIfEmbedded();
                initializeInteractivity();
            });
        } else {
            checkIfEmbedded();
            initializeInteractivity();
        }
    </script>
</body>
</html>