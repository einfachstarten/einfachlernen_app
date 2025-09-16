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

        .continue-search-section {
            text-align: center;
            margin: 2rem 0;
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--light-blue) 0%, rgba(74, 144, 184, 0.1) 100%);
            border-radius: 12px;
            border: 2px dashed var(--primary);
        }

        .continue-search-btn {
            background: linear-gradient(135deg, var(--secondary) 0%, var(--accent-teal) 100%);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(82, 179, 164, 0.3);
        }

        .continue-search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(82, 179, 164, 0.4);
        }

        .continue-search-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
            background: var(--gray-medium);
        }

        .continue-hint {
            margin-top: 0.8rem;
            color: var(--gray-medium);
            font-size: 0.9rem;
        }

        .search-continuation-separator {
            text-align: center;
            margin: 2rem 0 1rem 0;
            padding: 1rem;
            background: var(--gray-light);
            border-radius: 8px;
            border-left: 4px solid var(--secondary);
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

        .no-slots-message {
            background: #fff3cd;
            color: #856404;
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

            .continue-search-section {
                margin: 1.5rem 0;
                padding: 1rem;
            }

            .continue-search-btn {
                padding: 0.8rem 1.5rem;
                font-size: 0.9rem;
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
        let continuationData = null;
        let originalTargetCount = null;

        // Service selection
        document.querySelectorAll('.service-card').forEach(card => {
            card.addEventListener('click', () => {
                document.querySelectorAll('.service-card').forEach(c => c.classList.remove('active'));

                card.classList.add('active');
                selectedService = card.dataset.service;

                document.getElementById('searchBtn').disabled = false;
            });
        });

        document.getElementById('searchBtn').addEventListener('click', searchAppointments);

        async function searchAppointments() {
            if (!selectedService || searchActive) {
                return;
            }

            const searchBtn = document.getElementById('searchBtn');
            const targetCount = parseInt(document.getElementById('termineAnzahl').value, 10);

            continuationData = null;
            originalTargetCount = targetCount;
            searchActive = true;

            const container = document.getElementById('slotsContainer');
            if (container) {
                container.innerHTML = '';
            }
            document.querySelector('.continue-search-section')?.remove();

            showProgress('Termine werden gesucht...');
            searchBtn.disabled = true;

            try {
                const response = await fetch(`slots_api.php?service=${encodeURIComponent(selectedService)}&count=${targetCount}&week=0`);

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();

                if (data.error) {
                    showError(data.error);
                    return;
                }

                displaySlots(data, true, selectedService);
            } catch (error) {
                console.error('Initial search error:', error);
                showError('Fehler beim Laden der Termine');
            } finally {
                hideProgress();
                searchActive = false;
                searchBtn.disabled = false;
            }
        }

        function showProgress(message = 'Termine werden gesucht...') {
            const progressSection = document.getElementById('progressSection');
            const progressText = document.getElementById('progressText');
            const progressFill = document.getElementById('progressFill');
            const resultsSection = document.getElementById('resultsSection');

            if (progressText) {
                progressText.textContent = message;
            }

            if (progressFill) {
                progressFill.style.width = '10%';
            }

            if (progressSection) {
                progressSection.style.display = 'block';
            }

            if (resultsSection) {
                resultsSection.style.display = 'none';
            }
        }

        function hideProgress() {
            const progressSection = document.getElementById('progressSection');
            const progressFill = document.getElementById('progressFill');

            if (progressFill) {
                progressFill.style.width = '100%';
            }

            if (progressSection) {
                setTimeout(() => {
                    progressSection.style.display = 'none';
                    if (progressFill) {
                        progressFill.style.width = '0%';
                    }
                }, 200);
            }
        }

        function displaySlots(data, isInitialSearch = true, serviceSlug = selectedService) {
            const container = document.getElementById('slotsContainer');
            const resultsSection = document.getElementById('resultsSection');
            const progressText = document.getElementById('progressText');
            const progressFill = document.getElementById('progressFill');

            if (!container || !resultsSection) {
                return;
            }

            const foundCount = Number(data.found_count) || 0;
            const targetCount = Number(data.target_count) || 0;
            const serviceSlugToUse = serviceSlug || selectedService;

            if (progressText) {
                progressText.textContent = foundCount > 0 ? `${foundCount} Tage gefunden` : 'Keine Termine gefunden';
            }

            if (progressFill) {
                progressFill.style.width = '100%';
            }

            if (data.slots && data.slots.length > 0) {
                const slotsHTML = data.slots.map(daySlot => {
                    const timeButtons = daySlot.slots.map(slot => {
                        return `<a href="#" onclick="trackBookingClick('${serviceSlugToUse}', '${slot.booking_url}')" class="time-button">
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

                if (isInitialSearch) {
                    const statusText = foundCount >= targetCount ?
                        `✅ ${foundCount} Tage mit Terminen gefunden!` :
                        `⚠️ ${foundCount} von ${targetCount} Tagen gefunden`;

                    container.innerHTML = `
                        <div class="success-message">
                            <h3>${statusText}</h3>
                            <p>Klicke auf eine Uhrzeit, um den Termin zu buchen.</p>
                        </div>
                        ${slotsHTML}
                    `;

                    continuationData = (foundCount > 0 && data.last_found_date) ? {
                        service: serviceSlugToUse,
                        lastDate: data.last_found_date,
                        originalCount: originalTargetCount,
                        currentTotal: foundCount
                    } : null;
                } else {
                    document.querySelector('.continue-search-section')?.remove();
                    container.insertAdjacentHTML('beforeend', `
                        <div class="search-continuation-separator">
                            <span>Weitere ${foundCount} Tage gefunden:</span>
                        </div>
                        ${slotsHTML}
                    `);

                    if (continuationData) {
                        if (foundCount > 0 && data.last_found_date) {
                            continuationData.lastDate = data.last_found_date;
                            continuationData.currentTotal += foundCount;
                        }
                    }
                }

                if (data.can_search_more && continuationData && continuationData.lastDate) {
                    document.querySelector('.continue-search-section')?.remove();

                    container.insertAdjacentHTML('beforeend', `
                        <div class="continue-search-section">
                            <button id="continueSearchBtn" class="continue-search-btn">
                                🔍 Weitere ${continuationData.originalCount} Tage suchen
                            </button>
                            <p class="continue-hint">
                                Bisher ${continuationData.currentTotal} Tage mit freien Terminen gefunden
                            </p>
                        </div>
                    `);

                    const continueBtn = document.getElementById('continueSearchBtn');
                    if (continueBtn) {
                        continueBtn.addEventListener('click', continueSearch);
                    }
                } else {
                    document.querySelector('.continue-search-section')?.remove();
                }
            } else if (isInitialSearch) {
                container.innerHTML = `
                    <div class="no-slots-message">
                        <h3>Keine freien Termine gefunden</h3>
                        <p>Bitte versuche es später erneut oder wähle einen anderen Service.</p>
                    </div>
                `;
                continuationData = null;
                document.querySelector('.continue-search-section')?.remove();
            }

            resultsSection.style.display = 'block';
        }

        async function continueSearch() {
            if (!continuationData || searchActive || !continuationData.lastDate) {
                return;
            }

            const btn = document.getElementById('continueSearchBtn');
            if (!btn) {
                return;
            }

            btn.innerHTML = '⏳ Suche läuft...';
            btn.disabled = true;
            searchActive = true;

            try {
                const params = new URLSearchParams({
                    service: continuationData.service,
                    count: continuationData.originalCount,
                    start_from: continuationData.lastDate,
                    original_count: continuationData.originalCount
                });

                const response = await fetch(`slots_api.php?${params.toString()}`);

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();

                if (data.error) {
                    showError(data.error, { preserveExisting: true });
                    btn.innerHTML = `🔍 Weitere ${continuationData.originalCount} Tage suchen`;
                    btn.disabled = false;
                    return;
                }

                displaySlots(data, false, continuationData.service);
            } catch (error) {
                console.error('Continuation search error:', error);
                showError('Fehler beim Laden weiterer Termine', { preserveExisting: true });
                btn.innerHTML = `🔍 Weitere ${continuationData.originalCount} Tage suchen`;
                btn.disabled = false;
            } finally {
                searchActive = false;
            }
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

        function showError(message, options = {}) {
            const { preserveExisting = false } = options;
            const container = document.getElementById('slotsContainer');
            const resultsSection = document.getElementById('resultsSection');

            if (!container || !resultsSection) {
                return;
            }

            const errorHtml = `
                <div class="error-message">
                    <h3>Fehler</h3>
                    <p>${message}</p>
                </div>
            `;

            if (preserveExisting && container.innerHTML.trim() !== '') {
                const existingError = container.querySelector('.error-message');
                if (existingError) {
                    existingError.remove();
                }
                container.insertAdjacentHTML('afterbegin', errorHtml);
            } else {
                container.innerHTML = errorHtml;
                continuationData = null;
            }

            resultsSection.style.display = 'block';
        }
    </script>
</body>
</html>
