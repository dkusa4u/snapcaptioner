/**
 * SnapCaption AI — Service Worker
 * Minimal SW: makes the app installable as a PWA on Android/iOS.
 * Caches core static assets for fast offline fallback.
 */

const CACHE_VERSION = 'sc-v1';
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/app.html',
  '/pricing.html',
  '/login.html',
  '/signup.html',
  '/manifest.json',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
];

// ── Install: pre-cache static shell ────────────────────────────────────────
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => {
      return cache.addAll(STATIC_ASSETS).catch(() => {
        // Non-fatal: some assets may not exist yet
      });
    })
  );
  self.skipWaiting();
});

// ── Activate: clean up old caches ──────────────────────────────────────────
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

// ── Fetch: network-first with cache fallback ───────────────────────────────
self.addEventListener('fetch', (event) => {
  // Don't intercept API calls — always use network for those
  if (event.request.url.includes('/api/')) return;
  // Don't intercept non-GET requests
  if (event.request.method !== 'GET') return;

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // Cache successful HTML/CSS/JS responses
        if (response && response.status === 200) {
          const clone = response.clone();
          caches.open(CACHE_VERSION).then((cache) => cache.put(event.request, clone));
        }
        return response;
      })
      .catch(() => {
        // Network failed — try cache
        return caches.match(event.request).then((cached) => {
          if (cached) return cached;
          // Ultimate fallback for navigation requests
          if (event.request.mode === 'navigate') {
            return caches.match('/app.html');
          }
        });
      })
  );
});
