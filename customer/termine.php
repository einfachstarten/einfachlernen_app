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
logPageView($customer['id'], 'termine', [
    'page_section' => 'overview'
]);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4a90b8">
    <title>Meine Termine - Anna Braun Lerncoaching</title>
    
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

        .header-content {
            flex: 1;
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

        .loading-overlay {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 300px;
            color: var(--gray-medium);
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gray-light);
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .appointment-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px var(--shadow);
            border: 1px solid #f0f0f0;
            transition: all 0.3s ease;
        }

        .appointment-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px var(--shadow);
        }

        .appointment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .appointment-date-time {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .date-badge {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 0.75rem;
            border-radius: 12px;
            text-align: center;
            min-width: 80px;
        }

        .date-day {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1;
        }

        .date-month {
            font-size: 0.8rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .time-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .time-badge {
            background: var(--light-blue);
            color: var(--primary);
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .date-info {
            color: var(--gray-medium);
            font-size: 0.85rem;
        }

        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-confirmed {
            background: #e8f5e8;
            color: var(--accent-green);
        }

        .appointment-main {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1.5rem;
            align-items: center;
        }

        .appointment-details h3 {
            color: var(--gray-dark);
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .appointment-details p {
            color: var(--gray-medium);
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .appointment-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .action-button {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-calendar {
            background: var(--accent-teal);
            color: white;
        }

        .btn-calendar:hover {
            background: #1e8e8e;
            color: white;
            text-decoration: none;
            transform: translateY(-1px);
        }

        .btn-reschedule {
            background: var(--accent-blue);
            color: white;
        }

        .btn-reschedule:hover {
            background: #1976d2;
            color: white;
            text-decoration: none;
            transform: translateY(-1px);
        }

        .btn-disabled {
            background: var(--gray-light);
            color: var(--gray-medium);
            cursor: not-allowed;
        }

        .no-appointments {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--gray-medium);
        }

        .no-appointments-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        .no-appointments h3 {
            margin-bottom: 0.5rem;
            color: var(--gray-dark);
        }

        .error-message {
            background: #fff5f5;
            border: 1px solid #fed7d7;
            color: #e53e3e;
            padding: 1rem;
            border-radius: 12px;
            margin: 1rem 0;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .app-container {
                box-shadow: none;
            }
            
            .app-header {
                padding: 1rem;
            }
            
            .app-content {
                padding: 1rem;
            }
            
            .appointment-card {
                padding: 1rem;
            }
            
            .appointment-date-time {
                flex-direction: column;
                gap: 0.5rem;
                align-items: flex-start;
            }
            
            .appointment-main {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .appointment-actions {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <a href="index.php" class="back-btn">←</a>
            <div class="header-content">
                <h1>Meine Termine</h1>
                <p>Übersicht deiner gebuchten Coaching-Termine</p>
            </div>
        </header>

        <main class="app-content">
            <div class="loading-overlay" id="loadingOverlay">
                <div class="loading-spinner"></div>
                <p>Termine werden geladen...</p>
            </div>

            <div id="appointmentsContainer" style="display: none;">
                <!-- Termine werden hier eingefügt -->
            </div>
        </main>
    </div>

    <script>
        // Secure API call (no email parameter in URL)
        async function loadAppointments() {
            try {
                const response = await fetch('/einfachlernen/customer/termine_api.php', {
                    method: 'GET',
                    credentials: 'same-origin', // Include session cookies
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();
                
                if (data.error) {
                    throw new Error(data.error);
                }

                renderAppointments(data.events || []);
                
            } catch (error) {
                console.error('Error loading appointments:', error);
                showError(error.message);
            }
        }

        function renderAppointments(events) {
            const container = document.getElementById('appointmentsContainer');
            const loading = document.getElementById('loadingOverlay');
            
            loading.style.display = 'none';
            container.style.display = 'block';

            if (events.length === 0) {
                container.innerHTML = `
                    <div class="no-appointments">
                        <div class="no-appointments-icon">📅</div>
                        <h3>Keine Termine gefunden</h3>
                        <p>Sie haben derzeit keine gebuchten Termine.</p>
                    </div>
                `;
                return;
            }

            // Group appointments by date
            const appointmentsByDate = {};
            events.forEach(event => {
                const date = new Date(event.start_time);
                const dateKey = date.toDateString();
                if (!appointmentsByDate[dateKey]) {
                    appointmentsByDate[dateKey] = [];
                }
                appointmentsByDate[dateKey].push(event);
            });

            let html = '';
            Object.keys(appointmentsByDate).forEach(dateKey => {
                const appointments = appointmentsByDate[dateKey];
                
                appointments.forEach(event => {
                    const startDate = new Date(event.start_time);
                    const endDate = new Date(event.end_time);
                    const now = new Date();
                    const canReschedule = startDate > new Date(now.getTime() + 24 * 60 * 60 * 1000);

                    html += `
                        <div class="appointment-card">
                            <div class="appointment-header">
                                <div class="appointment-date-time">
                                    <div class="date-badge">
                                        <div class="date-day">${startDate.getDate()}</div>
                                        <div class="date-month">${startDate.toLocaleDateString('de-DE', { month: 'short' })}</div>
                                    </div>
                                    <div class="time-info">
                                        <div class="time-badge">
                                            ${startDate.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })} - 
                                            ${endDate.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })} Uhr
                                        </div>
                                        <div class="date-info">
                                            ${startDate.toLocaleDateString('de-DE', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
                                        </div>
                                    </div>
                                </div>
                                ${event.invitee_status ? `<span class="status-badge status-confirmed">${event.invitee_status}</span>` : ''}
                            </div>

                            <div class="appointment-main">
                                <div class="appointment-details">
                                    <h3>${event.name || 'Coaching-Termin'}</h3>
                                    ${event.invitee_name ? `<p><strong>Teilnehmer:</strong> ${event.invitee_name}</p>` : ''}
                                    ${event.location ? `<p><strong>Ort:</strong> ${event.location.location || 'Online'}</p>` : ''}
                                </div>

                                <div class="appointment-actions">
                                    ${canReschedule && event.reschedule_url ? 
                                        `<a href="${event.reschedule_url}" target="_blank" class="action-button btn-reschedule">✏️ Verschieben</a>` : 
                                        ''
                                    }
                                    ${canReschedule && event.cancel_url ? 
                                        `<a href="${event.cancel_url}" target="_blank" class="action-button btn-reschedule">❌ Stornieren</a>` : 
                                        ''
                                    }
                                    ${!canReschedule ? '<span class="action-button btn-disabled">⏰ Änderungen nicht möglich</span>' : ''}
                                </div>
                            </div>
                        </div>
                    `;
                });
            });

            container.innerHTML = html;
        }

        function showError(message) {
            const container = document.getElementById('appointmentsContainer');
            const loading = document.getElementById('loadingOverlay');
            
            loading.style.display = 'none';
            container.style.display = 'block';
            container.innerHTML = `
                <div class="error-message">
                    <strong>Fehler beim Laden der Termine:</strong> ${message}
                </div>
            `;
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadAppointments();
        });
    </script>
</body>
</html>
