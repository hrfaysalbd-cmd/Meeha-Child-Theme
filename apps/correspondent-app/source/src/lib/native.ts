/**
 * r31 — the Android app (Capacitor). The same screens as the website, but the
 * files are inside the APK, Android asks for the mic/camera/notification
 * permissions itself, and notices come through Firebase Cloud Messaging.
 *
 * Everything here is a no-op on the website (`__NATIVE__` is false there and
 * the Capacitor code is never loaded).
 */
import type { PushState } from '../chat/push'

export const IS_NATIVE = __NATIVE__
/** FCM is wired into this APK (the build had google-services.json). */
const HAS_FCM = import.meta.env.VITE_FCM === '1'

const OWN_HOSTS = ['correspondent.meeha.news', 'localhost']

/** A notice's link → an in-app path, or null when it belongs elsewhere. */
export function inAppPath(url: string): string | null {
  try {
    const u = new URL(url, 'https://correspondent.meeha.news')
    if (!OWN_HOSTS.includes(u.hostname)) return null
    return u.pathname + u.search + u.hash
  } catch {
    return null
  }
}

let pendingPath: string | null = null
/** Opens a screen; before the app shell is up, it is kept for it. */
function openPath(path: string) {
  pendingPath = path
  window.dispatchEvent(new CustomEvent('nros-navigate', { detail: path }))
}
/** The app shell takes a screen asked for before it was mounted (cold start from a notice). */
export function takePendingPath() {
  const p = pendingPath
  pendingPath = null
  return p
}

export async function openExternal(url: string) {
  if (!IS_NATIVE) {
    window.open(url, '_blank', 'noopener')
    return
  }
  const { Browser } = await import('@capacitor/browser')
  await Browser.open({ url })
}

// ---------------------------------------------------------------- push (FCM)

type Send = (body: Record<string, unknown>) => Promise<unknown>
let fcmToken: string | null = null
let tokenWaiters: ((t: string | null) => void)[] = []
let listening = false

/**
 * The plugin is handed back inside an object on purpose: a Capacitor plugin
 * answers to every property, also `then`, so returning it straight from an
 * async function makes the promise wait for a native "then" call that never
 * answers (r31 bug: nothing was asked on first start, the notification
 * screen spun forever).
 */
async function pushPlugin(): Promise<{ P: typeof import('@capacitor/push-notifications').PushNotifications }> {
  const { PushNotifications } = await import('@capacitor/push-notifications')
  return { P: PushNotifications }
}

/** A native call that can't hang the screen: gives up after `ms`. */
function withTimeout<T>(p: Promise<T>, ms: number, what: string): Promise<T> {
  return Promise.race([p, new Promise<T>((_, no) => setTimeout(() => no(new Error(`${what}: no answer`)), ms))])
}

async function listen() {
  if (listening) return
  listening = true
  const { P } = await pushPlugin()
  await P.addListener('registration', (t) => {
    fcmToken = t.value
    tokenWaiters.forEach((w) => w(t.value))
    tokenWaiters = []
  })
  await P.addListener('registrationError', () => {
    tokenWaiters.forEach((w) => w(null))
    tokenWaiters = []
  })
  // a notice was tapped (also when it started the app)
  await P.addListener('pushNotificationActionPerformed', (a) => {
    const url = (a.notification.data as { url?: string } | undefined)?.url
    if (!url) return
    const path = inAppPath(url)
    if (path) openPath(path)
    else void openExternal(url)
  })
  // arrived while the app is open: the app's own sound + pop-up handle it
  await P.addListener('pushNotificationReceived', () => {
    window.dispatchEvent(new CustomEvent('nros-native-push'))
  })
  // Android 8+: the channels the server names (chat / urgent / general)
  await P.createChannel({ id: 'chat', name: 'চ্যাট', description: 'ডেস্ক ও রুমের মেসেজ', importance: 4, visibility: 1, vibration: true })
  await P.createChannel({ id: 'urgent', name: 'জরুরি', description: 'জরুরি মেসেজ ও নির্দেশনা', importance: 5, visibility: 1, vibration: true, lights: true, lightColor: '#b91c1c' })
  await P.createChannel({ id: 'general', name: 'অনুমোদন ও অন্যান্য', description: 'সংবাদ অনুমোদন, ফেরত, পেমেন্ট', importance: 4, visibility: 1 })
}

async function registerToken(): Promise<string | null> {
  const { P } = await pushPlugin()
  await listen()
  if (fcmToken) return fcmToken
  const got = new Promise<string | null>((ok) => {
    tokenWaiters.push(ok)
    setTimeout(() => ok(null), 15000)
  })
  await P.register()
  return got
}

export const nativePush = {
  available: IS_NATIVE && HAS_FCM,

  async state(): Promise<PushState> {
    if (!HAS_FCM) return 'unsupported'
    const { P } = await pushPlugin()
    const p = await withTimeout(P.checkPermissions(), 5000, 'checkPermissions')
    if (p.receive === 'denied') return 'denied'
    if (p.receive !== 'granted') return 'off'
    return fcmToken ? 'on' : 'off'
  },

  async enable(send: Send): Promise<PushState | 'error'> {
    if (!HAS_FCM) return 'unsupported'
    const { P } = await pushPlugin()
    let p = await P.checkPermissions()
    if (p.receive === 'prompt' || p.receive === 'prompt-with-rationale') p = await P.requestPermissions()
    if (p.receive !== 'granted') return p.receive === 'denied' ? 'denied' : 'off'
    const token = await registerToken()
    if (!token) return 'error'
    await send({ fcm: token })
    return 'on'
  },

  async disable(send: Send) {
    if (!HAS_FCM) return
    const { P } = await pushPlugin()
    if (fcmToken) await send({ fcm: fcmToken }).catch(() => {})
    fcmToken = null
    await P.unregister().catch(() => {})
  },

  /** On start: when allowed, (re)register quietly and tell the server. */
  async sync(send: Send): Promise<boolean> {
    if (!HAS_FCM) return false
    const { P } = await pushPlugin()
    await listen()
    const p = await P.checkPermissions()
    if (p.receive !== 'granted') return false
    const token = await registerToken()
    if (!token) return false
    await send({ fcm: token })
    return true
  },
}

// ---------------------------------------------------------------- app start

let started = false
/** Hardware back button, links to other sites, notice taps from a cold start. */
export async function startNative() {
  if (!IS_NATIVE || started) return
  started = true
  document.documentElement.classList.add('native-app')
  const { App } = await import('@capacitor/app')
  await App.addListener('backButton', ({ canGoBack }) => {
    // an open sheet / dialog closes first
    const ev = new CustomEvent('nros-back', { cancelable: true })
    if (!window.dispatchEvent(ev)) return
    const p = location.pathname
    if (canGoBack && p !== '/app' && p !== '/landing' && p !== '/login') history.back()
    else void App.minimizeApp()
  })
  // links to other sites open in the phone's browser, not inside the app
  document.addEventListener(
    'click',
    (e) => {
      const a = (e.target as Element | null)?.closest?.('a[href]') as HTMLAnchorElement | null
      if (!a || a.hasAttribute('download')) return
      const href = a.getAttribute('href') || ''
      if (!/^https?:/i.test(href)) return
      if (inAppPath(href) && !a.target) return
      e.preventDefault()
      void openExternal(a.href)
    },
    true,
  )
  if (HAS_FCM) void listen()
  void askOnFirstStart()
}

/**
 * The very first start of the app: ask for notifications, microphone and
 * camera one after another (Android shows its own dialogs). Afterwards
 * nothing is asked here again: Android remembers the answers, and anything
 * still missing is asked at the moment it is needed (mic → voice note,
 * camera → photo). Someone updating from an earlier build has the flag
 * unset too, but Android simply skips what was already allowed.
 */
// v2: the v1 build stalled before asking (see pushPlugin), so ask once more
const FIRST_START = 'nros_native_perms_v2'
async function askOnFirstStart() {
  if (localStorage.getItem(FIRST_START)) return
  localStorage.setItem(FIRST_START, String(Date.now()))
  await new Promise((ok) => setTimeout(ok, 1200)) // let the first screen draw
  try {
    const { P } = await pushPlugin()
    const p = await withTimeout(P.checkPermissions(), 5000, 'checkPermissions')
    if (p.receive === 'prompt' || p.receive === 'prompt-with-rationale') await P.requestPermissions()
  } catch {
    // older Android: notices are allowed without asking
  }
  for (const c of [{ audio: true }, { video: true }] as MediaStreamConstraints[]) {
    try {
      const st = await navigator.mediaDevices?.getUserMedia(c)
      st?.getTracks().forEach((t) => t.stop())
    } catch {
      // "Don't allow": it is asked again when that feature is used
    }
  }
  // with notices now allowed, register this phone (the chat list does it too once signed in)
  window.dispatchEvent(new CustomEvent('nros-native-perms'))
}

// ---------------------------------------------------------------- updates

export interface AndroidRelease {
  version_code: number
  version_name?: string
  url?: string
  notes?: string
  min_code?: number
}

/** The installed APK's build number (versionCode). */
export async function installedBuild(): Promise<{ code: number; name: string }> {
  const { App } = await import('@capacitor/app')
  const i = await App.getInfo()
  return { code: Number(i.build) || 0, name: i.version }
}

// ------------------------------------------------- r35: in-app update

interface ApkInstallerPlugin {
  canInstall(): Promise<{ allowed: boolean }>
  openSettings(): Promise<void>
  download(o: { url: string; versionCode: number }): Promise<{ path: string; versionCode: number }>
  install(): Promise<{ started?: boolean; needPermission?: boolean }>
  addListener(ev: 'progress', cb: (p: { loaded: number; total: number }) => void): Promise<{ remove: () => Promise<void> }>
}

/** Kept inside an object: a Capacitor plugin answers to `then` too (see pushPlugin). */
async function apkPlugin(): Promise<{ P: ApkInstallerPlugin }> {
  const { registerPlugin } = await import('@capacitor/core')
  return { P: registerPlugin<ApkInstallerPlugin>('ApkInstaller') }
}

/**
 * Downloads the new APK inside the app and opens Android's installer.
 * Returns 'permission' when Android first needs "allow from this app";
 * throws when this APK has no installer (older build) — then the caller
 * falls back to the browser.
 */
export async function updateInApp(rel: AndroidRelease, onProgress: (ratio: number) => void): Promise<'started' | 'permission'> {
  const { P } = await apkPlugin()
  const h = await P.addListener('progress', (p) => onProgress(p.total > 0 ? p.loaded / p.total : 0))
  try {
    await P.download({ url: rel.url!, versionCode: rel.version_code })
  } finally {
    void h.remove()
  }
  return installDownloaded()
}

/** Opens Android's installer for the APK already downloaded. */
export async function installDownloaded(): Promise<'started' | 'permission'> {
  const { P } = await apkPlugin()
  const r = await P.install()
  return r.needPermission ? 'permission' : 'started'
}

/** Android's "allow installs from this app" screen. */
export async function openInstallSettings() {
  const { P } = await apkPlugin()
  await P.openSettings()
}
