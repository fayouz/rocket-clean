/* Rocket service worker (hand-written, no dependency).
 * - /_nuxt/* (hashed build assets): cache first.
 * - page navigations: network first, then the cached page, then the cached app shell, then /offline.html.
 * - icons, manifest and other same-origin static files: stale while revalidate.
 * - /api/*, the icon endpoint, other origins and non-GET requests: never touched, never cached (they carry the
 *   credentials). Offline data (public cleaning page, shopping cart) is kept by the app itself, not here.
 * Registered as /sw.js?v=<version>: a new deployed version installs a new worker, which waits until the page asks it
 * to take over ("Nouvelle version disponible" → SKIP_WAITING).
 */
const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev'
const STATIC = `rocket-static-${VERSION}`
const PAGES = `rocket-pages-${VERSION}`
const OFFLINE = '/offline.html'
const PRECACHE = [OFFLINE, '/icon.svg']

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(STATIC).then(cache => cache.addAll(PRECACHE)))
})

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    for (const key of await caches.keys()) {
      if (key.startsWith('rocket-') && key !== STATIC && key !== PAGES) await caches.delete(key)
    }
    await self.clients.claim()
  })())
})

self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting()
})

function bypass(url, request) {
  return request.method !== 'GET'
    || url.origin !== self.location.origin
    || url.pathname.startsWith('/api/')
    || url.pathname.startsWith('/_nuxt_icon')
    || url.pathname === '/sw.js'
    || request.headers.has('Authorization')
}

async function cacheFirst(request) {
  const cached = await caches.match(request)
  if (cached) return cached
  const response = await fetch(request)
  if (response.ok) (await caches.open(STATIC)).put(request, response.clone())
  return response
}

async function networkFirstPage(request) {
  const cache = await caches.open(PAGES)
  try {
    const response = await fetch(request)
    // The app is a client-rendered shell (no personal data in the HTML): safe to keep for offline use.
    if (response.ok && response.type === 'basic') {
      cache.put(request, response.clone())
      cache.put('/__shell', response.clone())
    }
    return response
  }
  catch {
    return (await cache.match(request)) || (await cache.match('/__shell')) || (await caches.match(OFFLINE)) || Response.error()
  }
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(STATIC)
  const cached = await cache.match(request)
  const network = fetch(request).then((response) => {
    if (response.ok) cache.put(request, response.clone())
    return response
  }).catch(() => cached || Response.error())
  return cached || network
}

self.addEventListener('fetch', (event) => {
  const request = event.request
  const url = new URL(request.url)
  if (bypass(url, request)) return
  if (request.mode === 'navigate') event.respondWith(networkFirstPage(request))
  else if (url.pathname.startsWith('/_nuxt/')) event.respondWith(cacheFirst(request))
  else event.respondWith(staleWhileRevalidate(request))
})
