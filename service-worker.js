/* LDB-FRAS Service Worker - offline-first attendance kiosk.
 * Cache version: ldb-fras-v1
 * Strategy:
 *   - Install: pre-cache core CSS/JS, face-api.js, TF.js, model weights, icons.
 *   - Fetch: cache-first + stale-while-revalidate for GET static assets;
 *            network-first for navigations (pages);
 *            never cache API requests (POST sync + GET roster bypass cache).
 */
const CACHE_NAME = 'ldb-fras-v1';
const MODEL_PREFIX = 'assets/models/';
const PRECACHE_ASSETS = [
    'manifest.json',
    'assets/vendor/css/bootstrap.min.css',
    'assets/vendor/bootstrap-icons/bootstrap-icons.css',
    'assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
    'assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff',
    'assets/vendor/dataTables/css/dataTables.bootstrap5.min.css',
    'assets/vendor/fonts/fonts.css',
    'assets/css/style.css',
    'assets/css/pages-theme.css',
    'assets/css/pages-navbar.css',
    'assets/vendor/js/chart.umd.min.js',
    'assets/vendor/js/jquery-3.7.0.min.js',
    'assets/vendor/js/bootstrap.bundle.min.js',
    'assets/vendor/dataTables/css/jquery.dataTables.min.js',
    'assets/vendor/dataTables/js/dataTables.bootstrap5.min.js',
    'assets/js/app.js',
    'assets/js/navbar.js',
    'assets/js/liveness.js',
    // Offline attendance stack (must be cached or the kiosk has no offline path)
    'assets/js/offline-db.js',
    'assets/js/face-scan-offline.js',
    // MediaPipe FaceMesh (liveness / anti-spoofing). The .data + wasm binaries
    // are fetched at runtime via locateFile(), so they MUST be pre-cached for
    // liveness to work with no network - otherwise autoScan() is blocked.
    'assets/vendor/face_mesh/face_mesh.js',
    'assets/vendor/face_mesh/face_mesh_solution_packed_assets_loader.js',
    'assets/vendor/face_mesh/face_mesh_solution_packed_assets.data',
    'assets/vendor/face_mesh/face_mesh_solution_wasm_bin.js',
    'assets/vendor/face_mesh/face_mesh_solution_wasm_bin.wasm',
    'assets/vendor/face_mesh/face_mesh_solution_simd_wasm_bin.js',
    'assets/vendor/face_mesh/face_mesh_solution_simd_wasm_bin.wasm',
    'assets/vendor/tfjs/tf.min.js',
    'assets/vendor/face-api/face-api.min.js',
    MODEL_PREFIX + 'ssd_mobilenetv1/ssd_mobilenetv1_model-weights_manifest.json',
    MODEL_PREFIX + 'ssd_mobilenetv1/ssd_mobilenetv1_model.bin',
    MODEL_PREFIX + 'face_landmark_68/face_landmark_68_model-weights_manifest.json',
    MODEL_PREFIX + 'face_landmark_68/face_landmark_68_model.bin',
    MODEL_PREFIX + 'face_recognition/face_recognition_model-weights_manifest.json',
    MODEL_PREFIX + 'face_recognition/face_recognition_model.bin',
    'assets/images/icon.png',
    'assets/images/icon-192.png',
    'assets/images/icon-512.png'
];

function scopeURL(relative) {
    return new URL(relative, self.registration.scope).href;
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) =>
            Promise.all(
                PRECACHE_ASSETS.map((rel) =>
                    fetch(scopeURL(rel), { cache: 'no-store' })
                        .then((res) => {
                            if (res && res.ok) cache.put(scopeURL(rel), res);
                            return;
                        })
                        .catch(() => { /* skip missing file */ })
                )
            )
        ).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Only handle GET requests.
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    // Never interfere with browser-internal requests.
    if (url.origin !== self.location.origin) return;

    // API requests: network-only (POST sync + live roster must stay fresh).
    if (url.pathname.indexOf('/api/') !== -1) {
        return;
    }

    // Skip service-worker/manifest self-references handled by navigation.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req)
                .then((res) => {
                    const copy = res.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(req, copy)).catch(() => {});
                    return res;
                })
                .catch(() =>
                    caches.match(req, { ignoreSearch: true }).then((cached) => {
                        if (cached) return cached;
                        // Last resort: a cached page in the same app root.
                        return caches.match('./', { ignoreSearch: true });
                    })
                )
        );
        return;
    }

    // Static assets: cache-first with stale-while-revalidate.
    event.respondWith(
        caches.match(req).then((cached) => {
            const network = fetch(req)
                .then((res) => {
                    if (res && res.ok && (req.headers.get('accept') || '').indexOf('text/html') === -1) {
                        const copy = res.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(req, copy)).catch(() => {});
                    }
                    return res;
                })
                .catch(() => cached);
            return cached || network;
        })
    );
});

// Allow the page to force a cache version bump (not used at runtime by default).
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
