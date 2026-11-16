import { isPushOn } from '../chat/push'
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { NotificationsApi, type AppNotification, type NotificationChannel, type NotificationCounts } from './api'
import { loadSoundPrefs, playSound, saveSoundPrefs, unlockSounds, type SoundKind, type SoundPrefs } from './sounds'

// Round 18 — live in-app notifications. r35 — split into two places:
//
//   ইনবক্স (inbox)  — the editorial queue and everything people send to the
//                     desk: new articles/videos to review, approvals,
//                     applications, edit requests, withdrawals, tasks, live
//                     updates waiting… Its own sound. (The chat box comes later.)
//   বেল (alerts)    — পোস্ট/ভিডিও এলার্ট from the প্রোফাইল ডেস্ক: a watched
//                     profile posted. Pops up like a push notification, with
//                     one sound for posts and another for videos.
//
// While the app is open and visible it asks the server for anything new
// every POLL_MS (only rows newer than the last one it has, so a poll is a
// tiny request). In the background polling slows to HIDDEN_POLL_MS, and
// coming back to the app polls immediately. A failed poll is silent and
// simply tried again on the next tick.

const POLL_MS = 10_000
const HIDDEN_POLL_MS = 60_000
const KEEP = 200

interface Toast {
  key: number
  n: AppNotification
}

interface Ctx {
  /** Every notification (both places), newest first. */
  items: AppNotification[]
  inboxItems: AppNotification[]
  alertItems: AppNotification[]
  unread: number
  unreadInbox: number
  unreadAlerts: number
  counts: NotificationCounts | null
  loaded: boolean
  markRead: (ids: number[]) => void
  /** Without a channel: both places. */
  markAllRead: (channel?: NotificationChannel) => void
  refresh: () => void
  /** Bumped whenever new notifications arrive — pages can watch it to reload their own data. */
  version: number
  toasts: Toast[]
  dismissToast: (key: number) => void
  systemPermission: NotificationPermission | 'unsupported'
  askSystemPermission: () => void
  sound: SoundPrefs
  setSound: (p: Partial<SoundPrefs>) => void
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

/** Which place a notification belongs to (works with servers older than 1.18.0 too). */
export function channelOf(n: Pick<AppNotification, 'type' | 'channel'>): NotificationChannel {
  if (n.channel === 'alerts' || n.channel === 'inbox') return n.channel
  return n.type.startsWith('pd_alert') ? 'alerts' : 'inbox'
}

/** Post or video alert (older rows were all 'pd_alert' with "ভিডিও" in the title). */
export function alertKind(n: Pick<AppNotification, 'type' | 'title'>): 'post' | 'video' {
  if (n.type === 'pd_alert_video') return 'video'
  if (n.type === 'pd_alert' && /টি নতুন ভিডিও/.test(n.title)) return 'video'
  return 'post'
}

export function soundFor(n: AppNotification): SoundKind {
  return channelOf(n) === 'alerts' ? alertKind(n) : 'inbox'
}

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
  const itemsRef = useRef(items)
  itemsRef.current = items
  const [unreadInbox, setUnreadInbox] = useState(0)
  const [unreadAlerts, setUnreadAlerts] = useState(0)
  const [counts, setCounts] = useState<NotificationCounts | null>(null)
  const [loaded, setLoaded] = useState(false)
  const [version, setVersion] = useState(0)
  const [toasts, setToasts] = useState<Toast[]>([])
  const [sound, setSoundState] = useState<SoundPrefs>(() => loadSoundPrefs())
  const soundRef = useRef(sound)
  soundRef.current = sound
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

  // Browsers allow sound only after one click/tap on the page.
  useEffect(() => {
    const once = () => {
      unlockSounds()
      window.removeEventListener('pointerdown', once)
      window.removeEventListener('keydown', once)
    }
    window.addEventListener('pointerdown', once)
    window.addEventListener('keydown', once)
    return () => {
      window.removeEventListener('pointerdown', once)
      window.removeEventListener('keydown', once)
    }
  }, [])

  const setCountsFrom = useCallback((res: { unread: number; unread_by?: { inbox: number; alerts: number } }, list?: AppNotification[]) => {
    if (res.unread_by) {
      setUnreadInbox(res.unread_by.inbox)
      setUnreadAlerts(res.unread_by.alerts)
    } else if (list) {
      // Older server: split the total by what we have loaded.
      const a = list.filter((n) => !n.read && channelOf(n) === 'alerts').length
      setUnreadAlerts(Math.min(a, res.unread))
      setUnreadInbox(Math.max(0, res.unread - a))
    }
  }, [])

  const showSystem = useCallback((fresh: AppNotification[]) => {
    if (!systemSupported() || Notification.permission !== 'granted' || document.visibilityState === 'visible' || isPushOn()) return
    for (const n of fresh.slice(0, 3)) {
      try {
        const where = channelOf(n) === 'alerts' ? (alertKind(n) === 'video' ? '▶ ভিডিও এলার্ট' : '🔔 পোস্ট এলার্ট') : '📥 ইনবক্স'
        const sys = new Notification(n.title, { body: `${where}${n.body ? ' · ' + n.body : ''}`, tag: `nros-${n.id}`, icon: '/favicon.svg' })
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

  /** One sound per kind that arrived (video first, then post, then inbox), a little apart. */
  const ring = useCallback((fresh: AppNotification[]) => {
    const p = soundRef.current
    const kinds = new Set(fresh.map(soundFor))
    let delay = 0
    for (const k of ['video', 'post', 'inbox'] as SoundKind[]) {
      if (!kinds.has(k) || !p[k]) continue
      window.setTimeout(() => playSound(k, p.volume), delay)
      delay += 1400
    }
  }, [])

  const merge = useCallback((list: AppNotification[]) => {
    setItems((prev) => {
      const seen = new Set(prev.map((p) => p.id))
      return [...list.filter((n) => !seen.has(n.id)), ...prev].sort((a, b) => b.id - a.id).slice(0, KEEP)
    })
  }, [])

  const poll = useCallback(async () => {
    if (inFlight.current) return
    inFlight.current = true
    try {
      if (!initialized.current) {
        // First load: each place gets its own recent list, so a busy bell
        // can never push the inbox out (or the other way round).
        const [inbox, alerts] = await Promise.all([
          NotificationsApi.poll(undefined, 'inbox', 50),
          NotificationsApi.poll(undefined, 'alerts', 50),
        ])
        const list = [...inbox.items, ...alerts.items]
        // An older server ignores `channel` and sends the same rows twice: merge() drops repeats.
        merge(list)
        setCountsFrom(alerts, list)
        if (alerts.counts) setCounts(alerts.counts)
        latestId.current = Math.max(inbox.latest_id || 0, alerts.latest_id || 0, ...list.map((n) => n.id))
        initialized.current = true
        setLoaded(true)
        return
      }
      const res = await NotificationsApi.poll(latestId.current || undefined, undefined, 100)
      if (res.counts) setCounts(res.counts)
      const fresh = res.items.filter((n) => n.id > latestId.current)
      if (fresh.length) {
        merge(fresh)
        setVersion((v) => v + 1)
        setToasts((prev) => [...fresh.slice(0, 3).map((n) => ({ key: ++toastKey.current, n })), ...prev].slice(0, 3))
        showSystem(fresh)
        ring(fresh)
      }
      setCountsFrom(res, [...fresh, ...itemsRef.current])
      latestId.current = Math.max(latestId.current, res.latest_id || 0, ...res.items.map((n) => n.id))
    } catch {
      // Silent: the next tick tries again.
    } finally {
      inFlight.current = false
    }
  }, [showSystem, ring, merge, setCountsFrom])

  useEffect(() => {
    if (!enabled) {
      latestId.current = 0
      initialized.current = false
      setItems([])
      setUnreadInbox(0)
      setUnreadAlerts(0)
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

  const markRead = useCallback(
    (ids: number[]) => {
      const rows = items.filter((n) => ids.includes(n.id) && !n.read)
      if (!rows.length) return
      const unreadIds = rows.map((n) => n.id)
      const a = rows.filter((n) => channelOf(n) === 'alerts').length
      setItems((prev) => prev.map((n) => (unreadIds.includes(n.id) ? { ...n, read: true } : n)))
      setUnreadAlerts((u) => Math.max(0, u - a))
      setUnreadInbox((u) => Math.max(0, u - (rows.length - a)))
      NotificationsApi.markRead({ ids: unreadIds })
        .then((r) => r.unread_by && setCountsFrom(r))
        .catch(() => {})
    },
    [items, setCountsFrom],
  )

  const markAllRead = useCallback(
    (channel?: NotificationChannel) => {
      setItems((prev) => prev.map((n) => (!channel || channelOf(n) === channel ? { ...n, read: true } : n)))
      if (!channel || channel === 'alerts') setUnreadAlerts(0)
      if (!channel || channel === 'inbox') setUnreadInbox(0)
      NotificationsApi.markRead(channel ? { all: true, channel } : { all: true })
        .then((r) => r.unread_by && setCountsFrom(r))
        .catch(() => {})
    },
    [setCountsFrom],
  )

  const askSystemPermission = useCallback(() => {
    if (!systemSupported()) return
    Notification.requestPermission()
      .then((p) => setSystemPermission(p))
      .catch(() => {})
  }, [])

  const setSound = useCallback((p: Partial<SoundPrefs>) => {
    setSoundState((prev) => {
      const next = { ...prev, ...p }
      saveSoundPrefs(next)
      return next
    })
  }, [])

  const inboxItems = useMemo(() => items.filter((n) => channelOf(n) === 'inbox'), [items])
  const alertItems = useMemo(() => items.filter((n) => channelOf(n) === 'alerts'), [items])

  const value = useMemo<Ctx>(
    () => ({
      items,
      inboxItems,
      alertItems,
      unread: unreadInbox + unreadAlerts,
      unreadInbox,
      unreadAlerts,
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
      sound,
      setSound,
    }),
    [items, inboxItems, alertItems, unreadInbox, unreadAlerts, counts, loaded, markRead, markAllRead, poll, version, toasts, systemPermission, askSystemPermission, sound, setSound],
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
