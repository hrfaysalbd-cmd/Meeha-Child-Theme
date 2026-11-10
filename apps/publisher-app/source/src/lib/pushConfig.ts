/** r37 — which app this is, and the service worker that shows push notices. */
export const PUSH_APP = 'publisher' as const
/** The publisher app has no PWA worker of its own, so it registers this small one. */
export const PUSH_SW: string | null = '/push-sw.js'
