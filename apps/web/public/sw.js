/*
 * UI-9 · Service worker de GestiaHR: guarda el armazón para que la aplicación instalada abra sin conexión.
 *
 * - Nunca guarda respuestas de la API (/api/…): los datos de personas viajan siempre por la red, con permisos.
 * - Páginas: primero la red; sin conexión, la última versión guardada de esa dirección o la pantalla «Sin conexión».
 * - Recursos estáticos con huella (/_next/static, fuentes, íconos): primero la caché.
 * Cambiar VERSION obliga a descartar lo guardado en la siguiente visita.
 */
const VERSION = 'gestia-shell-v1'
const OFFLINE_URL = '/sin-conexion'
const PRECACHE = [OFFLINE_URL, '/manifest.webmanifest', '/icon-192.png', '/icon-512.png', '/favicon.svg']

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  )
})

function isStatic(url) {
  return url.pathname.startsWith('/_next/static/') || url.pathname.startsWith('/fonts/') || /\.(?:png|svg|webmanifest|woff2?)$/.test(url.pathname)
}

self.addEventListener('fetch', (event) => {
  const request = event.request
  if (request.method !== 'GET') return
  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/sanctum/')) return

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response.ok) {
            const copy = response.clone()
            caches.open(VERSION).then((cache) => cache.put(url.pathname, copy))
          }
          return response
        })
        .catch(async () => (await caches.match(url.pathname)) || (await caches.match(OFFLINE_URL)) || Response.error()),
    )
    return
  }

  if (isStatic(url)) {
    event.respondWith(
      caches.match(request).then((hit) => hit || fetch(request).then((response) => {
        if (response.ok) {
          const copy = response.clone()
          caches.open(VERSION).then((cache) => cache.put(request, copy))
        }
        return response
      })),
    )
  }
})

/*
 * NOT-4 · Avisos push: el contenido solo invita a abrir la aplicación (sin datos de personas); tocarlo
 * abre la pantalla del aviso en una pestaña ya abierta de GestiaHR o en una nueva.
 */
self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = {}
  }
  const title = typeof data.title === 'string' ? data.title : 'GestiaHR'
  event.waitUntil(self.registration.showNotification(title, {
    body: typeof data.body === 'string' ? data.body : '',
    tag: typeof data.tag === 'string' ? data.tag : undefined,
    icon: '/icon-192.png',
    badge: '/icon-192.png',
    data: { url: typeof data.url === 'string' && data.url.startsWith('/') ? data.url : '/' },
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL(event.notification.data && event.notification.data.url ? event.notification.data.url : '/', self.location.origin).href
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const client of list) {
        if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
          return client.navigate(target).then((c) => (c || client).focus())
        }
      }
      return self.clients.openWindow(target)
    }),
  )
})
