let updateAvailable = false;
let registration = null;
const SW_PATH = window.location.pathname.includes('/einfachlernen/') ? '/einfachlernen/sw.js' : '/sw.js';

// Register service worker with update detection
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(SW_PATH)
        .then(reg => {
            registration = reg;
            console.log('SW registered:', reg);

            // Check for updates every 5 minutes
            setInterval(() => {
                reg.update();
            }, 5 * 60 * 1000);

            // Listen for waiting service worker
            reg.addEventListener('updatefound', () => {
                const newWorker = reg.installing;

                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        updateAvailable = true;
                        showUpdateNotification();
                    }
                });
            });
        })
        .catch(err => console.log('SW registration failed:', err));

    // Listen for messages from service worker
    navigator.serviceWorker.addEventListener('message', event => {
        if (event.data.type === 'SW_UPDATED') {
            console.log('App updated to version:', event.data.version);
            showUpdateSuccessMessage(event.data.version);
        }
    });
}

function showUpdateNotification() {
    // Create update notification
    const notification = document.createElement('div');
    notification.id = 'update-notification';
    notification.innerHTML = `
        <div style="
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #2563eb;
            color: white;
            padding: 12px;
            text-align: center;
            z-index: 10000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        ">
            <span>📱 App-Update verfügbar!</span>
            <button onclick="applyUpdate()" style="
                background: white;
                color: #2563eb;
                border: none;
                padding: 6px 12px;
                margin-left: 12px;
                border-radius: 4px;
                cursor: pointer;
                font-weight: bold;
            ">Jetzt aktualisieren</button>
            <button onclick="dismissUpdate()" style="
                background: transparent;
                color: white;
                border: 1px solid white;
                padding: 6px 12px;
                margin-left: 8px;
                border-radius: 4px;
                cursor: pointer;
            ">Später</button>
        </div>
    `;

    document.body.appendChild(notification);
}

function applyUpdate() {
    if (registration && registration.waiting) {
        // Tell waiting service worker to skip waiting
        registration.waiting.postMessage({ type: 'SKIP_WAITING' });

        // Reload page to get new version
        window.location.reload();
    }
}

function dismissUpdate() {
    const notification = document.getElementById('update-notification');
    if (notification) {
        notification.remove();
    }
}

function showUpdateSuccessMessage(version) {
    const success = document.createElement('div');
    success.innerHTML = `
        <div style="
            position: fixed;
            top: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            z-index: 10000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        ">
            ✅ App erfolgreich aktualisiert! (${version})
        </div>
    `;

    document.body.appendChild(success);

    setTimeout(() => {
        success.remove();
    }, 5000);
}

// ADD to admin dashboard for emergency updates
function forceClientUpdates() {
    // This would be called from admin interface
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({
            type: 'FORCE_UPDATE',
            reason: 'security_update'
        });
    }
}

// Emergency cache clear function
function clearAllCaches() {
    if ('caches' in window) {
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    console.log('Deleting cache:', cacheName);
                    return caches.delete(cacheName);
                })
            );
        }).then(() => {
            alert('Cache geleert! Seite wird neu geladen...');
            window.location.reload(true);
        });
    }
}

// Check current version on load
window.addEventListener('load', () => {
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
        // Ask service worker for current version
        navigator.serviceWorker.controller.postMessage({ type: 'CHECK_VERSION' });
    }
});

