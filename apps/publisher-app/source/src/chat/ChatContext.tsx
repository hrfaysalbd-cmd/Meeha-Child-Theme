import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { ChatApi, type ChatSummary } from './api'
import { loadSoundPrefs, playSound, unlockSounds } from '../lib/sounds'
import { isPushOn, syncPush } from './push'

/**
 * r36 — keeps the chat badge fresh (every ~10 s while the app is open, ~30 s
 * in a background tab), rings when a new message arrives and shows a small
 * pop-up. A জরুরি message plays the urgent sound even in a muted room.
 */

interface ChatCtx {
  summary: ChatSummary | null
  unread: number
  refresh: () => void
  /** The room open on screen right now (no pop-up / sound for it). */
  setActiveRoom: (id: number | null) => void
  toast: ChatSummary['latest'] | null
  dismissToast: () => void
}

const Ctx = createContext<ChatCtx>({
  summary: null,
  unread: 0,
  refresh: () => {},
  setActiveRoom: () => {},
  toast: null,
  dismissToast: () => {},
})

export const useChat = () => useContext(Ctx)

export function ChatProvider({ children, enabled = true }: { children: ReactNode; enabled?: boolean }) {
  const [summary, setSummary] = useState<ChatSummary | null>(null)
  const [toast, setToast] = useState<ChatSummary['latest'] | null>(null)
  const active = useRef<number | null>(null)
  const seenLatest = useRef<number>(-1)
  const busy = useRef(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const load = useCallback(async () => {
    if (busy.current) return
    busy.current = true
    try {
      const s = await ChatApi.summary()
      setSummary(s)
      const l = s.latest
      if (seenLatest.current === -1) {
        // first look: no sound for what was already waiting
        seenLatest.current = l?.id ?? 0
      } else if (l && l.id > seenLatest.current) {
        seenLatest.current = l.id
        if (l.room_id !== active.current || document.hidden) {
          const prefs = loadSoundPrefs()
          const kind = l.label === 'urgent' ? 'urgent' : 'chat'
          if (prefs[kind]) playSound(kind, prefs.volume)
          setToast(l)
          if (document.hidden && !isPushOn() && 'Notification' in window && Notification.permission === 'granted') {
            try {
              const n = new Notification(`${l.icon} ${l.room}`, { body: `${l.author}: ${l.text}`, tag: `chat-${l.room_id}` })
              n.onclick = () => {
                window.focus()
                window.dispatchEvent(new CustomEvent('nros-chat-open', { detail: l.room_id }))
                n.close()
              }
            } catch {
              // some phones only allow notices from a service worker
            }
          }
        }
      }
    } catch {
      // offline or signed out: try again on the next tick
    } finally {
      busy.current = false
    }
  }, [])

  useEffect(() => {
    if (!enabled) return
    let stop = false
    const tick = async () => {
      await load()
      if (!stop) timer.current = setTimeout(tick, document.hidden ? 30_000 : 10_000)
    }
    void tick()
    const onVis = () => {
      if (!document.hidden) {
        if (timer.current) clearTimeout(timer.current)
        void tick()
      }
    }
    // ওয়েব-পুশ: keep this device registered; the worker may ask us to
    // re-send a changed address, or to open a screen after a tap
    void syncPush()
    const onSw = (e: MessageEvent) => {
      const t = e.data?.type
      if (t === 'nros-push-resubscribe') void syncPush()
      if (t === 'nros-push') void load()
      if (t === 'nros-open' && typeof e.data.url === 'string') {
        const u = new URL(e.data.url, location.href)
        if (u.origin === location.origin) location.assign(u.pathname + u.search)
      }
    }
    navigator.serviceWorker?.addEventListener('message', onSw)
    // Android app: a Firebase notice arrived while the app is open
    const onNative = () => void load()
    window.addEventListener('nros-native-push', onNative)
    // Android app: notices were just allowed on first start → register this phone
    const onPerms = () => void syncPush()
    window.addEventListener('nros-native-perms', onPerms)
    const unlock = () => unlockSounds()
    document.addEventListener('visibilitychange', onVis)
    window.addEventListener('pointerdown', unlock, { once: true })
    return () => {
      stop = true
      if (timer.current) clearTimeout(timer.current)
      document.removeEventListener('visibilitychange', onVis)
      window.removeEventListener('pointerdown', unlock)
      navigator.serviceWorker?.removeEventListener('message', onSw)
      window.removeEventListener('nros-native-push', onNative)
      window.removeEventListener('nros-native-perms', onPerms)
    }
  }, [enabled, load])

  useEffect(() => {
    if (!toast) return
    const t = setTimeout(() => setToast(null), 7000)
    return () => clearTimeout(t)
  }, [toast])

  const value = useMemo<ChatCtx>(
    () => ({
      summary,
      unread: summary?.unread ?? 0,
      refresh: () => void load(),
      setActiveRoom: (id) => {
        active.current = id
      },
      toast,
      dismissToast: () => setToast(null),
    }),
    [summary, toast, load],
  )
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

/** The pop-up for a new chat message (bottom corner). */
export function ChatToast({ onOpen }: { onOpen: (roomId: number) => void }) {
  const { toast, dismissToast } = useChat()
  if (!toast) return null
  const urgent = toast.label === 'urgent'
  return (
    <div className="fixed z-[60] left-3 right-3 bottom-[calc(84px+env(safe-area-inset-bottom))] sm:left-auto sm:right-5 sm:bottom-5 sm:w-[360px]" data-testid="chat-toast">
      <div className={`flex items-start gap-3 rounded-2xl bg-white shadow-xl ring-1 px-4 py-3 ${urgent ? 'ring-red-300' : 'ring-black/5'}`}>
        <button
          type="button"
          className="flex-1 min-w-0 text-left"
          onClick={() => {
            dismissToast()
            onOpen(toast.room_id)
          }}
        >
          <span className="flex items-center gap-1.5 text-[12px] text-neutral-500">
            <span>{toast.icon || '💬'}</span>
            <span className="truncate">{toast.room}</span>
            {urgent && <span className="ml-auto shrink-0 rounded-full bg-red-600 text-white px-2 py-[1px] text-[10.5px] font-bold">জরুরি</span>}
            {!urgent && toast.mention && <span className="ml-auto shrink-0 rounded-full bg-brand-600 text-white px-2 py-[1px] text-[10.5px] font-bold">@আপনাকে</span>}
          </span>
          <span className="block mt-0.5 text-[14px] text-neutral-900 leading-snug line-clamp-2">
            <b className="font-semibold">{toast.author}:</b> {toast.text}
          </span>
        </button>
        <button type="button" onClick={dismissToast} className="text-neutral-400 text-[18px] leading-none -mr-1" aria-label="বন্ধ">
          ×
        </button>
      </div>
    </div>
  )
}
