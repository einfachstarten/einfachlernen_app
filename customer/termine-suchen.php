<?php
require __DIR__.'/auth.php';
$customer = require_customer_login();

// Session timeout: 4 hours
if(isset($_SESSION['customer_last_activity']) && (time() - $_SESSION['customer_last_activity'] > 14400)){
    destroy_customer_session();
    header('Location: ../login.php?message=' . urlencode('Sitzung abgelaufen. Bitte melden Sie sich erneut an.'));
    exit;
}

// Update activity timestamp
$_SESSION['customer_last_activity'] = time();

// Get customer email from authenticated session (SECURE)
$customer_email = $customer['email'];

// Log page view
logPageView($customer['id'], 'termine_suchen', [
    'page_section' => 'search'
]);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4a90b8">
    <title>Termine suchen - Anna Braun Lerncoaching</title>
    
    <style>
        :root {
            --primary: #4a90b8;
            --secondary: #52b3a4;
            --accent-green: #7cb342;
            --accent-teal: #26a69a;
            --light-blue: #e3f2fd;
            --white: #ffffff;
            --gray-light: #f8f9fa;
            --gray-medium: #6c757d;
            --gray-dark: #343a40;
            --shadow: rgba(0, 0, 0, 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: linear-gradient(135deg, var(--light-blue) 0%, var(--white) 100%);
            min-height: 100vh;
            color: var(--gray-dark);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.4;
        }

        .app-container {
            max-width: 900px;
            margin: 0 auto;
            min-height: 100vh;
            background: white;
            box-shadow: 0 0 30px var(--shadow);
            display: flex;
            flex-direction: column;
        }

        .app-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.5rem;
            border-radius: 10px;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            text-decoration: none;
        }

        .header-content h1 {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .header-content p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .app-content {
            flex: 1;
            padding: 1.5rem;
        }

        .service-selection {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px var(--shadow);
            border: 1px solid #f0f0f0;
        }

        .service-selection h2 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }

        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .service-card {
            background: var(--gray-light);
            border: 2px solid transparent;
            border-radius: 12px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .service-card:hover {
            border-color: var(--primary);
            background: var(--light-blue);
        }

        .service-card.active {
            border-color: var(--primary);
            background: var(--light-blue);
        }

        .service-card h3 {
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .service-card p {
            color: var(--gray-medium);
            font-size: 0.9rem;
        }

        .search-controls {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px var(--shadow);
            border: 1px solid #f0f0f0;
        }

        .control-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .control-group label {
            font-weight: 600;
            color: var(--gray-dark);
            min-width: 120px;
        }

        .control-group select,
        .control-group input {
            padding: 0.5rem;
            border: 2px solid var(--gray-light);
            border-radius: 8px;
            font-size: 1rem;
        }

        .search-btn {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(74, 144, 184, 0.3);
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 144, 184, 0.4);
        }

        .search-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .results-section {
            display: none;
        }

        .progress-section {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px var(--shadow);
            border: 1px solid #f0f0f0;
            text-align: center;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--gray-light);
            border-radius: 4px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transition: width 0.3s ease;
        }

        .slots-container {
            display: grid;
            gap: 1rem;
        }

        .slot-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px var(--shadow);
            border: 1px solid #f0f0f0;
            transition: all 0.3s ease;
        }

        .slot-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px var(--shadow);
        }

        .slot-date {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .slot-info {
            color: var(--gray-medium);
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .slot-times {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .time-button {
            background: linear-gradient(135deg, var(--accent-green) 0%, var(--accent-teal) 100%);
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .time-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(124, 179, 66, 0.3);
            color: white;
            text-decoration: none;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gray-light);
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }

        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }

        @media (max-width: 768px) {
            .control-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .control-group label {
                min-width: auto;
            }
            
            .service-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <a href="index.php" class="back-btn">←</a>
            <div class="header-content">
                <h1>Termine suchen</h1>
                <p>Verfügbare Termine für <?= htmlspecialchars($customer['first_name']) ?></p>
            </div>
        </header>

        <main class="app-content">
            <!-- Service Selection -->
            <div class="service-selection">
                <h2>🎯 Service auswählen</h2>
                <div class="service-grid">
                    <div class="service-card" data-service="lerntraining">
                        <h3>Lerntraining</h3>
                        <p>50 Minuten</p>
                    </div>
                    <div class="service-card" data-service="neurofeedback-20">
                        <h3>Neurofeedback</h3>
                        <p>20 Minuten</p>
                    </div>
                    <div class="service-card" data-service="neurofeedback-40">
                        <h3>Neurofeedback</h3>
                        <p>40 Minuten</p>
                    </div>
                </div>
            </div>

            <!-- Search Controls -->
            <div class="search-controls">
                <div class="control-group">
                    <label for="termineAnzahl">Anzahl gewünschter Termine:</label>
                    <select id="termineAnzahl">
                        <?php for($i = 1; $i <= 15; $i++): ?>
                            <option value="<?= $i ?>" <?= $i === 5 ? 'selected' : '' ?>>
                                <?= $i ?> Tag<?= $i > 1 ? 'e' : '' ?> mit freien Terminen
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <button id="searchBtn" class="search-btn" disabled>
                    🔍 Termine suchen
                </button>
            </div>

            <!-- Progress Section -->
            <div id="progressSection" class="progress-section" style="display: none;">
                <div class="loading-spinner"></div>
                <p id="progressText">Termine werden gesucht...</p>
                <div class="progress-bar">
                    <div id="progressFill" class="progress-fill" style="width: 0%"></div>
                </div>
            </div>

            <!-- Results Section -->
            <div id="resultsSection" class="results-section">
                <div id="slotsContainer"></div>
            </div>
        </main>
    </div>

    <script>
        let selectedService = null;
        let searchActive = false;

        // Service selection
        document.querySelectorAll('.service-card').forEach(card => {
            card.addEventListener('click', () => {
                // Remove active from all cards
                document.querySelectorAll('.service-card').forEach(c => c.classList.remove('active'));
                
                // Add active to clicked card
                card.classList.add('active');
                selectedService = card.dataset.service;
                
                // Enable search button
                document.getElementById('searchBtn').disabled = false;
            });
        });

        // Search function
        document.getElementById('searchBtn').addEventListener('click', async () => {
            if (!selectedService || searchActive) return;
            
            searchActive = true;
            const count = parseInt(document.getElementById('termineAnzahl').value);
            
            // Show progress
            document.getElementById('progressSection').style.display = 'block';
            document.getElementById('resultsSection').style.display = 'none';
            document.getElementById('searchBtn').disabled = true;
            
            try {
                await searchSlots(selectedService, count);
            } catch (error) {
                showError('Fehler beim Laden der Termine: ' + error.message);
            } finally {
                searchActive = false;
                document.getElementById('progressSection').style.display = 'none';
                document.getElementById('searchBtn').disabled = false;
            }
        });

        async function searchSlots(service, count) {
            let allSlots = [];
            let foundDates = new Set();
            let week = 0;
            const maxWeeks = 26;

            while (foundDates.size < count && week < maxWeeks) {
                updateProgress(week, maxWeeks, `Durchsuche Woche ${week + 1}...`);

                try {
                    const response = await fetch(`slots_api.php?service=${service}&count=${count}&week=${week}`);

                    if (!response.ok) {
                        console.error(`HTTP ${response.status}: ${response.statusText}`);
                        week++;
                        continue;
                    }

                    const data = await response.json();

                    if (data.error) {
                        console.error('API Error:', data.error);
                        week++;
                        continue;
                    }

                    if (data.slots && data.slots.length > 0) {
                        // Add new unique dates (bis count erreicht)
                        data.slots.forEach(slot => {
                            if (!foundDates.has(slot.date) && foundDates.size < count) {
                                foundDates.add(slot.date);
                                allSlots.push(slot);
                            }
                        });
                    }

                    if (foundDates.size >= count) {
                        break;
                    }
                } catch (error) {
                    console.error('Week fetch error:', error);
                }

                week++;

                // Small delay for better UX
                await new Promise(resolve => setTimeout(resolve, 300));
            }

            updateProgress(100, 100, `${foundDates.size} Termine gefunden!`);
            setTimeout(() => showResults(allSlots, count, service), 500);
        }

        function updateProgress(current, max, text) {
            const percentage = Math.round((current / max) * 100);
            document.getElementById('progressFill').style.width = percentage + '%';
            document.getElementById('progressText').textContent = text;
        }

        function showResults(slots, targetCount, serviceSlug) {
            const container = document.getElementById('slotsContainer');
            const resultsSection = document.getElementById('resultsSection');
            
            if (slots.length === 0) {
                container.innerHTML = `
                    <div class="error-message">
                        <h3>Keine Termine gefunden</h3>
                        <p>Leider konnten keine verfügbaren Termine gefunden werden. Bitte versuche es später erneut oder wähle einen anderen Service.</p>
                    </div>
                `;
            } else {
                const statusText = slots.length >= targetCount ? 
                    `✅ Alle ${targetCount} gewünschten Termine gefunden!` : 
                    `⚠️ ${slots.length} von ${targetCount} Terminen gefunden`;
                
                container.innerHTML = `
                    <div class="success-message">
                        <h3>${statusText}</h3>
                        <p>Klicke auf eine Uhrzeit, um den Termin zu buchen.</p>
                    </div>
                ` + slots.map(daySlot => {
                    const timeButtons = daySlot.slots.map(slot => {
                        return `<a href="#" onclick="trackBookingClick('${serviceSlug}', '${slot.booking_url}')" class="time-button">
                            ${slot.time_only} Uhr
                        </a>`;
                    }).join('');
                    
                    return `
                        <div class="slot-card">
                            <div class="slot-date">${daySlot.date_formatted}</div>
                            <div class="slot-info">${daySlot.slots.length} Termin${daySlot.slots.length > 1 ? 'e' : ''} verfügbar</div>
                            <div class="slot-times">
                                ${timeButtons}
                            </div>
                        </div>
                    `;
                }).join('');
            }
            
            resultsSection.style.display = 'block';
        }

        function trackBookingClick(serviceSlug, calendlyUrl) {
            fetch('track_booking.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'booking_initiated',
                    service_slug: serviceSlug,
                    calendly_url: calendlyUrl
                })
            });

            window.open(calendlyUrl, '_blank');
        }

        function showError(message) {
            const container = document.getElementById('slotsContainer');
            container.innerHTML = `
                <div class="error-message">
                    <h3>Fehler</h3>
                    <p>${message}</p>
                </div>
            `;
            document.getElementById('resultsSection').style.display = 'block';
        }
    </script>
</body>
</html>
