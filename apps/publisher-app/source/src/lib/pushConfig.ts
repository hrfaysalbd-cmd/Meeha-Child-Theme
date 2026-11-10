/** r37 — which app this is, and the service worker that shows push notices. */
export const PUSH_APP = 'publisher' as const
/** The publisher app has no PWA worker of its own, so it registers this small one. */
export const PUSH_SW: string | null = '/push-sw.js'
/** Only the correspondent's Android app has native (Firebase) push. */
export const NATIVE_PUSH: {
  available: boolean
  state(): Promise<'on' | 'off' | 'denied' | 'unsupported'>
  enable(send: (b: Record<string, unknown>) => Promise<unknown>): Promise<'on' | 'off' | 'denied' | 'unsupported' | 'error'>
  disable(send: (b: Record<string, unknown>) => Promise<unknown>): Promise<void>
  sync(send: (b: Record<string, unknown>) => Promise<unknown>): Promise<boolean>
} | null = null
