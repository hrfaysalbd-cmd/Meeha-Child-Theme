/** r30 — which app this is, and the service worker that shows push notices. */
import { nativePush } from './native'

export const PUSH_APP = 'correspondent' as const
/** null: the PWA worker (sw.js) already pulls push-sw.js in (vite.config.ts → workbox.importScripts). */
export const PUSH_SW: string | null = null
/** r31 — in the Android app, notices come through Firebase instead of web push. */
export const NATIVE_PUSH: typeof nativePush | null = __NATIVE__ ? nativePush : null
