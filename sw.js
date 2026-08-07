const CACHE_NAME = 'spool-v1';
const ASSETS = [
    './index.php',
    './assets/tailwindcss.js',
    './assets/sweetalert2.all.min.js',
    './assets/chart.js',
    './assets/jszip.min.js',
    './assets/fonts.css',
    './assets/inter_400.ttf',
    './assets/inter_700.ttf',
    './assets/pwa-icon-512.png',
    './manifest.json'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS);
        })
    );
});

self.addEventListener('fetch', (event) => {
    // Ignorar peticiones que no sean GET (como el login/logout en process.php)
    if (event.request.method !== 'GET') return;

    // Ignorar específicamente process.php para asegurar comunicación viva con AS400
    if (event.request.url.includes('process.php')) return;

    event.respondWith(
        caches.match(event.request).then((response) => {
            return response || fetch(event.request);
        })
    );
});
