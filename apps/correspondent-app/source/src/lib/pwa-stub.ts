// The Android app build has no service worker: this stands in for
// virtual:pwa-register/react there (see vite.config.ts).
export function useRegisterSW() {
  return { updateServiceWorker: () => Promise.resolve(), needRefresh: [false, () => {}], offlineReady: [false, () => {}] }
}
