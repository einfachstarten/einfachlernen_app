// PWA Update Fix für macOS - pwa-update.js ERSETZT VERSION
let updateAvailable = false;
let registration = null;
const SW_PATH = window.location.pathname.includes('/einfachlernen/') ? '/einfachlernen/sw.js' : '/sw.js';

// Register service worker with update detection
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(SW_PATH)
        .then(reg => {
            registration = reg;
            console.log('✅ SW registered:', reg);

            // Check for updates every 5 minutes
            setInterval(() => {
                console.log('🔄 Checking for updates...');
                reg.update();
            }, 5 * 60 * 1000);

            // Listen for waiting service worker
            reg.addEventListener('updatefound', () => {
                console.log('🆕 Update found, installing...');
                const newWorker = reg.installing;

                newWorker.addEventListener('statechange', () => {
                    console.log('SW state changed to:', newWorker.state);
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        console.log('🎯 Update ready, showing notification');
                        updateAvailable = true;
                        showUpdateNotification();
                    }
                });
            });

            // Check for waiting SW immediately
            if (reg.waiting) {
                console.log('🎯 SW already waiting, showing update');
                updateAvailable = true;
                showUpdateNotification();
            }
        })
        .catch(err => {
            console.error('❌ SW registration failed:', err);
        });

    // Listen for messages from service worker
    navigator.serviceWorker.addEventListener('message', event => {
        console.log('📨 Message from SW:', event.data);
        if (event.data.type === 'SW_UPDATED') {
            console.log('✅ App updated to version:', event.data.version);
            showUpdateSuccessMessage(event.data.version);
        }
    });
}

function showUpdateNotification() {
    console.log('🔔 Creating update notification');
    
    // Remove existing notification
    const existing = document.getElementById('update-notification');
    if (existing) {
        existing.remove();
    }

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
            <button id="update-apply-btn" style="
                background: white;
                color: #2563eb;
                border: none;
                padding: 6px 12px;
                margin-left: 12px;
                border-radius: 4px;
                cursor: pointer;
                font-weight: bold;
            ">Jetzt aktualisieren</button>
            <button id="update-dismiss-btn" style="
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
    
    // Add event listeners (NOT onclick)
    const applyBtn = document.getElementById('update-apply-btn');
    const dismissBtn = document.getElementById('update-dismiss-btn');
    
    if (applyBtn) {
        applyBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('🔄 Apply update clicked');
            applyUpdate();
        });
        console.log('✅ Apply button listener added');
    }
    
    if (dismissBtn) {
        dismissBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('❌ Dismiss update clicked');
            dismissUpdate();
        });
        console.log('✅ Dismiss button listener added');
    }
}

function applyUpdate() {
    console.log('🚀 Starting update process...');
    console.log('Registration:', registration);
    console.log('Waiting worker:', registration?.waiting);
    
    if (registration && registration.waiting) {
        console.log('✅ Sending SKIP_WAITING message');
        
        // Tell waiting service worker to skip waiting
        registration.waiting.postMessage({ type: 'SKIP_WAITING' });
        
        // Show loading state
        const applyBtn = document.getElementById('update-apply-btn');
        if (applyBtn) {
            applyBtn.textContent = 'Wird aktualisiert...';
            applyBtn.disabled = true;
        }
        
        // Wait a moment then reload
        setTimeout(() => {
            console.log('🔄 Reloading page for update');
            window.location.reload();
        }, 1000);
        
    } else {
        console.warn('⚠️ No waiting worker found, trying manual reload');
        // Fallback: Force a cache refresh
        if ('caches' in window) {
            caches.keys().then(cacheNames => {
                console.log('🗑️ Clearing caches:', cacheNames);
                return Promise.all(
                    cacheNames.map(cacheName => caches.delete(cacheName))
                );
            }).then(() => {
                console.log('🔄 Cache cleared, reloading');
                window.location.reload(true);
            });
        } else {
            // Last resort
            window.location.reload(true);
        }
    }
}

function dismissUpdate() {
    console.log('❌ Dismissing update notification');
    const notification = document.getElementById('update-notification');
    if (notification) {
        notification.remove();
        updateAvailable = false;
    }
}

function showUpdateSuccessMessage(version) {
    console.log('🎉 Showing success message for version:', version);
    
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

// Enhanced admin functions with logging
function forceClientUpdates() {
    console.log('🚨 Force update triggered from admin');
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({
            type: 'FORCE_UPDATE',
            reason: 'admin_forced'
        });
    }
}

function clearAllCaches() {
    console.log('🗑️ Clearing all caches (admin)');
    if ('caches' in window) {
        caches.keys().then(cacheNames => {
            console.log('Found caches:', cacheNames);
            return Promise.all(
                cacheNames.map(cacheName => {
                    console.log('Deleting cache:', cacheName);
                    return caches.delete(cacheName);
                })
            );
        }).then(() => {
            alert('Cache geleert! Seite wird neu geladen...');
            window.location.reload(true);
        }).catch(err => {
            console.error('Cache clear failed:', err);
            alert('Fehler beim Cache löschen: ' + err.message);
        });
    }
}

// Enhanced version check
window.addEventListener('load', () => {
    console.log('🔍 Page loaded, checking SW status');
    
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
        console.log('✅ SW controller found');
        navigator.serviceWorker.controller.postMessage({ type: 'CHECK_VERSION' });
        
        // Also check if there's already an update waiting
        navigator.serviceWorker.getRegistration().then(reg => {
            if (reg && reg.waiting) {
                console.log('🎯 Found waiting SW on load');
                registration = reg;
                updateAvailable = true;
                showUpdateNotification();
            }
        });
    } else {
        console.log('⚠️ No SW controller found yet');
    }
});

// Debug helper: Manual update check
function manualUpdateCheck() {
    console.log('🔄 Manual update check triggered');
    if (registration) {
        registration.update().then(() => {
            console.log('✅ Manual update check completed');
        }).catch(err => {
            console.error('❌ Manual update check failed:', err);
        });
    } else {
        console.warn('⚠️ No registration found for manual check');
    }
}

// Expose debug functions globally
window.debugPWA = {
    manualUpdateCheck,
    forceClientUpdates,
    clearAllCaches,
    showUpdateNotification
};

console.log('🔧 PWA Debug functions available via window.debugPWA');
