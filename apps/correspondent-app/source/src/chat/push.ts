/**
 * ওয়েব-পুশ (newsroom-os 1.20.0+): notices on the phone/computer even when
 * the app is closed. Works in Chrome/Edge/Firefox/Samsung on Android and on
 * computers; on iPhone only after "Add to Home Screen" (iOS 16.4+). In-app
 * web views (Facebook, simple "website to app" wrappers) don't support it.
 */
import { apiRequest } from '../lib/api'
import { NATIVE_PUSH, PUSH_APP, PUSH_SW } from '../lib/pushConfig'

let active = false
/** True once this device is registered for push: the page then skips its own system pop-ups (no doubles). */
export const isPushOn = () => active

export type PushState = 'on' | 'off' | 'denied' | 'unsupported' | 'ios-home' | 'webview'

const isIOS = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
const standalone = () => window.matchMedia?.('(display-mode: standalone)').matches || (navigator as unknown as { standalone?: boolean }).standalone === true
// the Android app (Capacitor) uses Firebase notices (NATIVE_PUSH) instead
const nativeSend = (body: Record<string, unknown>) => apiRequest('/push/subscribe', { method: 'POST', body: { ...body, app: PUSH_APP }, retry: true })
const nativeDrop = (body: Record<string, unknown>) => apiRequest('/push/unsubscribe', { method: 'POST', body })
const webView = () => /; wv\)/.test(navigator.userAgent) || /FBAN|FBAV|Instagram/.test(navigator.userAgent)

function supported() {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext
}

async function registration(): Promise<ServiceWorkerRegistration | null> {
  if (!('serviceWorker' in navigator)) return null
  if (PUSH_SW) {
    try {
      // same file and scope → the browser just returns the one it has
      await navigator.serviceWorker.register(PUSH_SW, { scope: '/' })
    } catch {
      return null
    }
  }
  // the app's own PWA worker (or the one just registered) once it is active
  return Promise.race([navigator.serviceWorker.ready, new Promise<null>((ok) => setTimeout(() => ok(null), 8000))])
}

function keyBytes(b64u: string) {
  const s = atob(b64u.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (b64u.length % 4)) % 4))
  const out = new Uint8Array(s.length)
  for (let i = 0; i < s.length; i++) out[i] = s.charCodeAt(i)
  return out
}

export async function pushState(): Promise<PushState> {
  if (NATIVE_PUSH) {
    const st = await NATIVE_PUSH.state()
    active = st === 'on'
    return st
  }
  if (webView()) return 'webview'
  if (isIOS() && !standalone()) return 'ios-home'
  if (!supported()) return 'unsupported'
  if (Notification.permission === 'denied') return 'denied'
  if (Notification.permission !== 'granted') return 'off'
  const reg = await registration()
  const sub = await reg?.pushManager.getSubscription()
  active = !!sub
  return sub ? 'on' : 'off'
}

async function send(sub: PushSubscription) {
  const j = sub.toJSON()
  await apiRequest('/push/subscribe', { method: 'POST', body: { endpoint: j.endpoint, keys: j.keys, app: PUSH_APP }, retry: true })
}

/** Ask for permission (needs a tap) and register this device. */
export async function enablePush(): Promise<PushState | 'error'> {
  if (NATIVE_PUSH) {
    try {
      const r = await NATIVE_PUSH.enable(nativeSend)
      active = r === 'on'
      return r
    } catch {
      return 'error'
    }
  }
  const st = await pushState()
  if (st === 'webview' || st === 'ios-home' || st === 'unsupported') return st
  const perm = await Notification.requestPermission()
  if (perm !== 'granted') return perm === 'denied' ? 'denied' : 'off'
  try {
    const reg = await registration()
    if (!reg) return 'error'
    const { key } = await apiRequest<{ key: string }>('/push/key', { auth: false })
    let sub = await reg.pushManager.getSubscription()
    const want = keyBytes(key)
    if (sub) {
      // a subscription made with another server key can't be used
      const have = sub.options.applicationServerKey ? new Uint8Array(sub.options.applicationServerKey) : null
      if (!have || have.length !== want.length || have.some((b, i) => b !== want[i])) {
        await sub.unsubscribe()
        sub = null
      }
    }
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: want })
    await send(sub)
    active = true
    return 'on'
  } catch {
    return 'error'
  }
}

export async function disablePush() {
  if (NATIVE_PUSH) {
    await NATIVE_PUSH.disable(nativeDrop)
    active = false
    return
  }
  const reg = await registration()
  const sub = await reg?.pushManager.getSubscription()
  if (!sub) return
  await apiRequest('/push/unsubscribe', { method: 'POST', body: { endpoint: sub.endpoint } }).catch(() => {})
  await sub.unsubscribe().catch(() => false)
  active = false
}

export function testPush() {
  return apiRequest<{ devices: number; sent: number }>('/push/test', { method: 'POST', body: {} })
}

/** On every start: if allowed, make sure the server has this device's current address. */
export async function syncPush() {
  try {
    if (NATIVE_PUSH) {
      active = await NATIVE_PUSH.sync(nativeSend)
      return
    }
    if (!supported() || Notification.permission !== 'granted') return
    const reg = await registration()
    const sub = await reg?.pushManager.getSubscription()
    if (sub) {
      await send(sub)
      active = true
    } else await enablePush()
  } catch {
    // quietly try again next time
  }
}
