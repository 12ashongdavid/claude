// Caches the app shell so the PWA still opens (login screen, cached
// screens) with no connection. API calls always go to the network —
// they're personal, changing data, never something to serve stale.
const CACHE_NAME = 'pk-mobile-shell-v1';
const SHELL_FILES = [
    './',
    './index.html',
    './css/app.css',
    './js/config.js',
    './js/api.js',
    './js/app.js',
    './manifest.json',
    './icons/icon-192.png',
    './icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_FILES)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((names) => Promise.all(
            names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n))
        )).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const url = event.request.url;
    if (url.includes('/mobile-api/') || event.request.method !== 'GET') {
        return; // let API calls hit the network untouched
    }
    event.respondWith(
        caches.match(event.request).then((cached) => cached || fetch(event.request))
    );
});
