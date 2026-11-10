/** r30 — which app this is, and the service worker that shows push notices. */
export const PUSH_APP = 'correspondent' as const
/** null: the PWA worker (sw.js) already pulls push-sw.js in (vite.config.ts → workbox.importScripts). */
export const PUSH_SW: string | null = null
