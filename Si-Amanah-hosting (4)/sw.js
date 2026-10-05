/* SI-AMANAH Service Worker - Dikembangkan oleh Genov (c) 2026
   Naikkan VERSI setiap kali index.html diperbarui di hosting. */
var VERSI = 'si-amanah-v10';
var INTI = ['./', './index.html', './manifest.webmanifest',
  './icons/icon-192.png', './icons/icon-512.png', './icons/maskable-192.png',
  './icons/maskable-512.png', './icons/apple-touch-icon.png', './icons/favicon-48.png'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VERSI).then(function (c) {
    return Promise.all(INTI.map(function (u) {
      return c.add(new Request(u, { cache: 'reload' })).catch(function () {});
    }));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) {
    return Promise.all(ks.filter(function (k) { return k !== VERSI; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

function simpan(req, res) {
  if (res && (res.ok || res.type === 'opaque')) {
    var salin = res.clone();
    caches.open(VERSI).then(function (c) { c.put(req, salin); });
  }
  return res;
}

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);

  // Data & API tidak pernah di-cache: selalu langsung ke server.
  if (url.origin === location.origin && (/\/api\.php$/.test(url.pathname) || /\/data\//.test(url.pathname))) return;

  // Halaman aplikasi: utamakan jaringan (versi terbaru), cadangan dari cache saat offline.
  if (req.mode === 'navigate' || (url.origin === location.origin && /\/(index\.html)?$/.test(url.pathname))) {
    e.respondWith(fetch(req, { cache: 'no-store' }).then(function (res) {
      if (res.ok) { var s = res.clone(); caches.open(VERSI).then(function (c) { c.put('./index.html', s); }); }
      return res;
    }).catch(function () {
      return caches.match('./index.html').then(function (r) { return r || caches.match('./'); });
    }));
    return;
  }

  // Ikon, manifest & pustaka PDF (cdnjs): ambil dari cache, perbarui di latar belakang.
  if (url.origin === location.origin || url.hostname === 'cdnjs.cloudflare.com') {
    e.respondWith(caches.match(req).then(function (hit) {
      var net = fetch(req).then(function (res) { return simpan(req, res); }).catch(function () { return hit; });
      return hit || net;
    }));
  }
});
