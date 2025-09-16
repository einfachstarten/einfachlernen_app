const VERSION = 'v2.4.0'; // INCREMENT THIS FOR EACH UPDATE
const CACHE_NAME = `dashboard-${VERSION}`;
const CRITICAL_CACHE = `critical-${VERSION}`;

// Files to cache immediately
const CRITICAL_FILES = [
    '/einfachlernen/index.html',
    '/einfachlernen/customer/index.php',
    '/einfachlernen/login.php',
    '/einfachlernen/manifest.json'
];

// Install new service worker
self.addEventListener('install', event => {
    console.log(`SW ${VERSION} installing...`);

    event.waitUntil(
        caches.open(CRITICAL_CACHE)
            .then(cache => cache.addAll(CRITICAL_FILES))
            .then(() => {
                console.log(`SW ${VERSION} installed successfully`);
                // Force immediate activation
                return self.skipWaiting();
            })
    );
});

// Activate new service worker and clean old caches
self.addEventListener('activate', event => {
    console.log(`SW ${VERSION} activating...`);

    event.waitUntil(
        Promise.all([
            // Clean old caches
            caches.keys().then(cacheNames => {
                return Promise.all(
                    cacheNames.map(cacheName => {
                        if (cacheName !== CACHE_NAME && cacheName !== CRITICAL_CACHE) {
                            console.log(`Deleting old cache: ${cacheName}`);
                            return caches.delete(cacheName);
                        }
                    })
                );
            }),
            // Take immediate control of all clients
            self.clients.claim()
        ]).then(() => {
            console.log(`SW ${VERSION} activated and cleaned old caches`);

            // Notify all clients about update
            return self.clients.matchAll().then(clients => {
                clients.forEach(client => {
                    client.postMessage({
                        type: 'SW_UPDATED',
                        version: VERSION,
                        message: 'App wurde aktualisiert!'
                    });
                });
            });
        })
    );
});

// Handle fetch with cache-first for critical files, network-first for dynamic content
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Handle navigation requests
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then(response => {
                    // Cache successful navigation responses
                    if (response.ok) {
                        const responseClone = response.clone();
                        caches.open(CACHE_NAME).then(cache => {
                            cache.put(event.request, responseClone);
                        });
                    }
                    return response;
                })
                .catch(() => {
                    // Fallback to cached version
                    return caches.match('/einfachlernen/index.html') ||
                           caches.match('/einfachlernen/login.php');
                })
        );
    }

    // Handle API requests with network-first
    else if (url.pathname.includes('api') || url.pathname.endsWith('.php')) {
        event.respondWith(
            fetch(event.request)
                .then(response => response)
                .catch(() => caches.match(event.request))
        );
    }

    // Handle static resources with cache-first
    else {
        event.respondWith(
            caches.match(event.request)
                .then(response => response || fetch(event.request))
        );
    }
});

// Handle messages from clients
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'CHECK_VERSION') {
        event.source.postMessage({
            type: 'VERSION_INFO',
            version: VERSION,
            cacheName: CACHE_NAME
        });
    }

    if (event.data && event.data.type === 'FORCE_UPDATE') {
        // Clear all caches and reload
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => caches.delete(cacheName))
            );
        }).then(() => {
            self.clients.matchAll().then(clients => {
                clients.forEach(client => client.navigate(client.url));
            });
        });
    }

    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

