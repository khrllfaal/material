/* Offline shell for the app. Bump VERSION on every deploy so phones pick up
   new frontend files. API responses are deliberately not cached here: stock
   and transaction data stay out of the browser cache, and offline writes go
   through the app's own outbox instead. */
const VERSION = 'v18-2026-10-31';
const SHELL = 'ms-shell-' + VERSION;
const SHELL_FILES = [
  './', './index.html', './backend-config.js',
  './vendor/tailwind.css', './vendor/icons.js',
  './manifest.webmanifest', './logo.png', './icon-192.png'
];

self.addEventListener('install', function (e) {
  // cache:'reload' = fetch straight from the server, never from the browser's HTTP cache,
  // so a new release can never be pre-cached from an old copy.
  e.waitUntil(caches.open(SHELL).then(function (c) {
    return Promise.all(SHELL_FILES.map(function (u) {
      return fetch(new Request(u, { cache: 'reload' })).then(function (res) { if (res.ok) return c.put(u, res); });
    }));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== SHELL; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  // API calls are never cached, whatever folder the backend lives in (e.g. /backend/api, /coba/api).
  if (url.pathname.indexOf('/backend/') !== -1 || url.pathname.indexOf('/api/') !== -1 || /\.php$/.test(url.pathname)) return;

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).then(function (res) {
      var copy = res.clone();
      caches.open(SHELL).then(function (c) { c.put('./index.html', copy); });
      return res;
    }).catch(function () { return caches.match('./index.html'); }));
    return;
  }

  // Code and styles (css, js, manifest): always ask the server first (revalidated, so a 304 is cheap),
  // and only fall back to the saved copy when there is no signal or the server is slow.
  if (/\.(css|js|webmanifest)$/.test(url.pathname)) {
    e.respondWith(new Promise(function (resolve) {
      var done = false;
      var fromCache = function () { return caches.match(req, { ignoreSearch: true }); };
      var timer = setTimeout(function () { fromCache().then(function (hit) { if (hit && !done) { done = true; resolve(hit); } }); }, 5000);
      fetch(new Request(req, { cache: 'no-cache' })).then(function (res) {
        clearTimeout(timer);
        if (res.ok) { var copy = res.clone(); caches.open(SHELL).then(function (c) { c.put(req, copy); }); }
        if (!done) { done = true; resolve(res); }
      }).catch(function () {
        clearTimeout(timer);
        fromCache().then(function (hit) { if (!done) { done = true; resolve(hit || Response.error()); } });
      });
    }));
    return;
  }

  // Images and the rest: saved copy first, refreshed in the background.
  e.respondWith(caches.match(req).then(function (hit) {
    var net = fetch(req).then(function (res) {
      if (res.ok) { var copy = res.clone(); caches.open(SHELL).then(function (c) { c.put(req, copy); }); }
      return res;
    }).catch(function () { return hit; });
    return hit || net;
  }));
});
