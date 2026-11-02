import { useRegisterSW } from 'virtual:pwa-register/react'

// Round 9 follow-up: the user reported needing to reload the app several
// times on their phone before a fresh deploy's fix actually seemed to take
// effect. That matches a known PWA pattern — the browser only checks a
// service worker for updates lazily (roughly once per navigation, subject
// to its own ~24h cache-revalidation throttle on the sw.js file itself), so
// a phone that doesn't fully close/reopen the app can keep running an old
// cached build for a while even though a new one has been deployed.
//
// This component forces the issue in code instead of waiting on that
// browser heuristic, which is the "ক্যাশিং ইস্যু থাকলে কোড দিয়ে ফোর্স করে
// আপডেট দেখানো" the user asked for:
//   1. `immediate: true` registers the service worker as soon as the app
//      boots, instead of waiting for the injected-script default.
//   2. `onRegisteredSW` grabs the registration and actively polls
//      `registration.update()` — on a short interval AND every time the
//      app regains visibility (the most common "reopen the app" moment on
//      a phone) — so a stale service worker is caught the moment the
//      correspondent comes back to the app, not after several blind
//      reloads.
//   3. Once an update IS found, `onNeedRefresh` applies it and reloads the
//      page immediately (vite.config.ts's `registerType: 'autoUpdate'`
//      already skips the "waiting" state) instead of silently leaving the
//      new version queued until some future natural navigation.
const UPDATE_CHECK_INTERVAL_MS = 60 * 1000 // at most once a minute

export function PwaUpdater() {
  const { updateServiceWorker } = useRegisterSW({
    immediate: true,
    onRegisteredSW(_swUrl, registration) {
      if (!registration) return

      const check = () => {
        registration.update().catch(() => {
          // Offline or the request was blocked — try again next tick.
        })
      }

      window.setInterval(check, UPDATE_CHECK_INTERVAL_MS)
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') check()
      })
    },
    onNeedRefresh() {
      updateServiceWorker(true)
    },
  })

  return null
}
