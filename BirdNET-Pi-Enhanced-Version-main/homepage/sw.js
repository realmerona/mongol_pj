/* BirdNET-Pi service worker (Phase 5).
   Strategy: cache-first for immutable-ish static assets (they carry
   cache-busting query strings), network-only for everything else - pages and
   the API must always be live, and the audio stream must never be touched. */

/* Bump the suffix whenever cached-asset behavior must reset on every
   client: activate deletes all older caches. v2: purges entries cached
   under day-granular ?v= stamps that let stale JS survive deploys. */
var CACHE_NAME = 'birdnet-static-v2';
var STATIC_PATTERN = /^\/(static|images)\//;

self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        if (key !== CACHE_NAME) {
          return caches.delete(key);
        }
      }));
    }).then(function () {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function (event) {
  var url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
    return; // pass through untouched
  }
  if (!STATIC_PATTERN.test(url.pathname)) {
    return; // pages, API, stream, clips: always network
  }
  event.respondWith(
    caches.match(event.request).then(function (cached) {
      if (cached) {
        return cached;
      }
      return fetch(event.request).then(function (response) {
        if (response.ok) {
          var copy = response.clone();
          caches.open(CACHE_NAME).then(function (cache) {
            cache.put(event.request, copy);
          });
        }
        return response;
      });
    })
  );
});
