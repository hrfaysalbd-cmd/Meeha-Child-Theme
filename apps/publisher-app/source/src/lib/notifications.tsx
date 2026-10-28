import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { NotificationsApi, type AppNotification, type NotificationCounts } from './api'

// Round 18 — live in-app notifications.
//
// While the app is open and visible it asks the server for anything new
// every POLL_MS (only rows newer than the last one it has, so a poll is a
// tiny request). When the tab/app goes to the background polling slows to
// HIDDEN_POLL_MS (browsers throttle background timers anyway), and coming
// back to the app polls immediately. A failed poll is silent and simply
// tried again on the next tick — it never shows an error or logs anyone out.
//
// New notifications appear as a toast; when the app is in the background
// and the person allowed it, also as a system notification.

const POLL_MS = 10_000
const HIDDEN_POLL_MS = 60_000

interface Toast {
  key: number
  n: AppNotification
}

interface Ctx {
  items: AppNotification[]
  unread: number
  counts: NotificationCounts | null
  loaded: boolean
  markRead: (ids: number[]) => void
  markAllRead: () => void
  refresh: () => void
  /** Bumped whenever new notifications arrive — pages can watch it to reload their own data. */
  version: number
  toasts: Toast[]
  dismissToast: (key: number) => void
  systemPermission: NotificationPermission | 'unsupported'
  askSystemPermission: () => void
}

const NotificationsContext = createContext<Ctx | null>(null)

export function useNotifications() {
  const ctx = useContext(NotificationsContext)
  if (!ctx) throw new Error('useNotifications outside NotificationsProvider')
  return ctx
}

/** Same as useNotifications() but null when there is no provider (logged out). */
export function useOptionalNotifications() {
  return useContext(NotificationsContext)
}

const systemSupported = () => typeof window !== 'undefined' && 'Notification' in window

export function NotificationsProvider({
  enabled,
  children,
  onOpen,
}: {
  enabled: boolean
  children: ReactNode
  /** Called when a system notification is clicked. */
  onOpen?: (n: AppNotification) => void
}) {
  const [items, setItems] = useState<AppNotification[]>([])
  const [unread, setUnread] = useState(0)
  const [counts, setCounts] = useState<NotificationCounts | null>(null)
  const [loaded, setLoaded] = useState(false)
  const [version, setVersion] = useState(0)
  const [toasts, setToasts] = useState<Toast[]>([])
  const [systemPermission, setSystemPermission] = useState<NotificationPermission | 'unsupported'>(() =>
    systemSupported() ? Notification.permission : 'unsupported',
  )

  const latestId = useRef(0)
  const initialized = useRef(false)
  const inFlight = useRef(false)
  const timer = useRef<number | null>(null)
  const toastKey = useRef(0)
  const onOpenRef = useRef(onOpen)
  onOpenRef.current = onOpen

  const showSystem = useCallback((fresh: AppNotification[]) => {
    if (!systemSupported() || Notification.permission !== 'granted' || document.visibilityState === 'visible') return
    for (const n of fresh.slice(0, 3)) {
      try {
        const sys = new Notification(n.title, { body: n.body, tag: `nros-${n.id}`, icon: '/favicon.svg' })
        sys.onclick = () => {
          window.focus()
          onOpenRef.current?.(n)
          sys.close()
        }
      } catch {
        // Some mobile browsers only allow notifications from a service worker — the in-app toast still shows.
      }
    }
  }, [])

  const poll = useCallback(async () => {
    if (inFlight.current) return
    inFlight.current = true
    try {
      const first = !initialized.current
      const res = await NotificationsApi.poll(latestId.current || undefined)
      setUnread(res.unread)
      if (res.counts) setCounts(res.counts)
      if (res.items.length) {
        const fresh = first ? [] : res.items.filter((n) => n.id > latestId.current)
        setItems((prev) => {
          const seen = new Set(prev.map((p) => p.id))
          return [...res.items.filter((n) => !seen.has(n.id)), ...prev].sort((a, b) => b.id - a.id).slice(0, 100)
        })
        if (fresh.length) {
          setVersion((v) => v + 1)
          setToasts((prev) => [...fresh.slice(0, 3).map((n) => ({ key: ++toastKey.current, n })), ...prev].slice(0, 3))
          showSystem(fresh)
        }
      }
      latestId.current = Math.max(latestId.current, res.latest_id || 0, ...res.items.map((n) => n.id))
      initialized.current = true
      setLoaded(true)
    } catch {
      // Silent: the next tick tries again.
    } finally {
      inFlight.current = false
    }
  }, [showSystem])

  useEffect(() => {
    if (!enabled) {
      latestId.current = 0
      initialized.current = false
      setItems([])
      setUnread(0)
      setCounts(null)
      setLoaded(false)
      return
    }
    let stopped = false
    const schedule = () => {
      if (stopped) return
      const delay = document.visibilityState === 'visible' ? POLL_MS : HIDDEN_POLL_MS
      timer.current = window.setTimeout(async () => {
        await poll()
        schedule()
      }, delay)
    }
    const onVisible = () => {
      if (document.visibilityState !== 'visible') return
      if (timer.current) window.clearTimeout(timer.current)
      void poll().then(schedule)
    }
    void poll().then(schedule)
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('online', onVisible)
    return () => {
      stopped = true
      if (timer.current) window.clearTimeout(timer.current)
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener('online', onVisible)
    }
  }, [enabled, poll])

  // Toasts disappear by themselves.
  useEffect(() => {
    if (!toasts.length) return
    const t = window.setTimeout(() => setToasts((prev) => prev.slice(0, -1)), 6000)
    return () => window.clearTimeout(t)
  }, [toasts])

  const markRead = useCallback((ids: number[]) => {
    const unreadIds = ids.filter((id) => items.some((n) => n.id === id && !n.read))
    if (!unreadIds.length) return
    setItems((prev) => prev.map((n) => (unreadIds.includes(n.id) ? { ...n, read: true } : n)))
    setUnread((u) => Math.max(0, u - unreadIds.length))
    NotificationsApi.markRead({ ids: unreadIds })
      .then((r) => setUnread(r.unread))
      .catch(() => {})
  }, [items])

  const markAllRead = useCallback(() => {
    setItems((prev) => prev.map((n) => ({ ...n, read: true })))
    setUnread(0)
    NotificationsApi.markRead({ all: true }).catch(() => {})
  }, [])

  const askSystemPermission = useCallback(() => {
    if (!systemSupported()) return
    Notification.requestPermission()
      .then((p) => setSystemPermission(p))
      .catch(() => {})
  }, [])

  const value = useMemo<Ctx>(
    () => ({
      items,
      unread,
      counts,
      loaded,
      markRead,
      markAllRead,
      refresh: () => void poll(),
      version,
      toasts,
      dismissToast: (key: number) => setToasts((prev) => prev.filter((t) => t.key !== key)),
      systemPermission,
      askSystemPermission,
    }),
    [items, unread, counts, loaded, markRead, markAllRead, poll, version, toasts, systemPermission, askSystemPermission],
  )

  return <NotificationsContext.Provider value={value}>{children}</NotificationsContext.Provider>
}

const BN = '০১২৩৪৫৬৭৮৯'
const bn = (n: number | string) => String(n).replace(/[0-9]/g, (d) => BN[Number(d)])

/** "এইমাত্র", "৫ মিনিট আগে", "৩ ঘণ্টা আগে", "২ দিন আগে" */
export function timeAgo(iso: string) {
  const t = new Date(iso).getTime()
  if (!t) return ''
  const s = Math.max(0, Math.round((Date.now() - t) / 1000))
  if (s < 60) return 'এইমাত্র'
  const m = Math.round(s / 60)
  if (m < 60) return `${bn(m)} মিনিট আগে`
  const h = Math.round(m / 60)
  if (h < 24) return `${bn(h)} ঘণ্টা আগে`
  const d = Math.round(h / 24)
  if (d < 30) return `${bn(d)} দিন আগে`
  return new Date(iso).toLocaleDateString('bn-BD', { day: 'numeric', month: 'long' })
}

/** Tone per notification type: green for good news, red for returned, blue otherwise. */
export function notificationTone(type: string): 'good' | 'bad' | 'info' {
  if (type.endsWith('_approved')) return 'good'
  if (type.endsWith('_rejected')) return 'bad'
  return 'info'
}
