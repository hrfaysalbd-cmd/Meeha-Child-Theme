/* মিহা নিউজ — ওয়েব-পুশ (newsroom-os 1.20.0+).
 * Shows chat / ইনবক্স / এলার্ট notices when the app is closed or in the
 * background, and opens the right screen when one is tapped.
 * The publisher app registers this file itself; the correspondent app pulls
 * it into its PWA service worker (workbox importScripts). */
self.addEventListener('push', (event) => {
  let d = {}
  try {
    d = event.data ? event.data.json() : {}
  } catch (e) {
    d = { title: 'মিহা নিউজ', body: event.data ? event.data.text() : '' }
  }
  event.waitUntil(
    (async () => {
      const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      const open = wins.find((w) => w.focused && w.visibilityState === 'visible')
      if (open) {
        // the app is on screen: it rings and shows its own pop-up
        open.postMessage({ type: 'nros-push', data: d })
        return
      }
      await self.registration.showNotification(d.title || 'মিহা নিউজ', {
        body: d.body || '',
        tag: d.tag || undefined,
        renotify: !!d.tag,
        icon: '/apple-touch-icon.png',
        badge: '/apple-touch-icon.png',
        data: { url: d.url || '/' },
        requireInteraction: !!d.urgent,
        vibrate: d.urgent ? [400, 120, 400, 120, 700] : [120, 60, 120],
        lang: 'bn',
      })
    })(),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL((event.notification.data && event.notification.data.url) || '/', self.registration.scope).href
  event.waitUntil(
    (async () => {
      const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      for (const w of wins) {
        if (new URL(w.url).origin === new URL(url).origin) {
          await w.focus()
          try {
            await w.navigate(url)
          } catch (e) {
            w.postMessage({ type: 'nros-open', url })
          }
          return
        }
      }
      await self.clients.openWindow(url)
    })(),
  )
})

// the push service changed the subscription: tell the app to send the new one
self.addEventListener('pushsubscriptionchange', () => {
  self.clients.matchAll({ type: 'window' }).then((ws) => ws.forEach((w) => w.postMessage({ type: 'nros-push-resubscribe' })))
})
