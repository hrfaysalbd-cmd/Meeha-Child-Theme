import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { alertKind, channelOf, notificationTone, timeAgo, useNotifications } from '../lib/notifications'
import { playSound, SOUND_LABEL, type SoundKind } from '../lib/sounds'
import type { AppNotification } from '../lib/api'

// Round 18 — bell button with unread badge, its panel, and the toasts.
// r35 — the bell is now only for পোস্ট/ভিডিও এলার্ট (প্রোফাইল ডেস্ক); the
// editorial queue and the rest moved to the ইনবক্স (InboxBox.tsx).

const BN = '০১২৩৪৫৬৭৮৯'
export const bn = (n: number) => String(n).replace(/[0-9]/g, (d) => BN[Number(d)])

const toneDot = { good: 'bg-emerald-500', bad: 'bg-red-500', info: 'bg-blue-500' }
const toneIcon = { good: '✓', bad: '↩', info: '•' }
const toneIconBg = { good: 'bg-emerald-50 text-emerald-600', bad: 'bg-red-50 text-red-600', info: 'bg-blue-50 text-blue-600' }

/** Icon chip for any notification: post/video alerts get their own look. */
export function NoteIcon({ n }: { n: AppNotification }) {
  if (channelOf(n) === 'alerts') {
    const video = alertKind(n) === 'video'
    return (
      <span className={`shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-[13px] font-bold ${video ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-700'}`}>
        {video ? '▶' : '✎'}
      </span>
    )
  }
  const tone = notificationTone(n.type)
  return <span className={`shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-[14px] font-bold ${toneIconBg[tone]}`}>{toneIcon[tone]}</span>
}

function BellIcon({ className = '' }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
      <path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
      <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
    </svg>
  )
}

export function NoteRow({ n, onOpen }: { n: AppNotification; onOpen: (n: AppNotification) => void }) {
  const tone = notificationTone(n.type)
  return (
    <button
      type="button"
      onClick={() => onOpen(n)}
      className={`w-full text-left flex gap-3 px-4 py-3 active:bg-neutral-50 hover:bg-neutral-50 ${n.read ? '' : 'bg-brand-50/40'}`}
    >
      <NoteIcon n={n} />
      <span className="min-w-0 flex-1">
        <span className="flex items-start gap-2">
          <span className={`flex-1 text-[14px] leading-snug ${n.read ? 'text-neutral-700' : 'font-semibold text-neutral-900'}`}>{n.title}</span>
          {!n.read && <span className={`mt-1.5 w-2 h-2 rounded-full shrink-0 ${toneDot[tone]}`} />}
        </span>
        {n.body && <span className="block text-[12.5px] text-neutral-500 leading-snug mt-0.5 whitespace-pre-line line-clamp-3">{n.body}</span>}
        <span className="block text-[11.5px] text-neutral-400 mt-1">{timeAgo(n.created_at)}</span>
      </span>
    </button>
  )
}

/** On/off + "শুনুন" for the given sounds. */
export function SoundSettings({ kinds }: { kinds: SoundKind[] }) {
  const { sound, setSound } = useNotifications()
  return (
    <div className="px-4 py-2.5 border-t border-neutral-100 bg-neutral-50/70 space-y-1.5" data-testid="sound-settings">
      {kinds.map((k) => (
        <div key={k} className="flex items-center gap-2 text-[12.5px]">
          <label className="flex-1 flex items-center gap-2 text-neutral-700 cursor-pointer">
            <input type="checkbox" checked={sound[k]} onChange={(e) => setSound({ [k]: e.target.checked })} data-testid={`sound-${k}`} />
            🔊 {SOUND_LABEL[k]} সাউন্ড
          </label>
          <button type="button" onClick={() => playSound(k, sound.volume)} className="text-brand-600 font-medium px-1.5" data-testid={`sound-test-${k}`}>
            ▶ শুনুন
          </button>
        </div>
      ))}
      <label className="flex items-center gap-2 text-[12px] text-neutral-500">
        আওয়াজ
        <input type="range" min={0} max={1} step={0.1} value={sound.volume} onChange={(e) => setSound({ volume: Number(e.target.value) })} className="flex-1 accent-brand-600" />
      </label>
    </div>
  )
}

export function SystemPermissionHint() {
  const { systemPermission, askSystemPermission } = useNotifications()
  if (systemPermission !== 'default') return null
  return (
    <button type="button" onClick={askSystemPermission} className="mx-3 mt-3 rounded-lg bg-blue-50 px-3 py-2 text-left text-[12.5px] text-blue-700">
      🔔 অ্যাপ পেছনে থাকলেও (অন্য ট্যাবে কাজ করলেও) স্ক্রিনে পপ-আপ পেতে এখানে চাপুন ও “Allow” দিন।
    </button>
  )
}

type AlertFilter = 'all' | 'post' | 'video'

export function NotificationPanel({ onClose }: { onClose?: () => void }) {
  const { alertItems, unreadAlerts, loaded, markRead, markAllRead } = useNotifications()
  const navigate = useNavigate()
  const [filter, setFilter] = useState<AlertFilter>('all')
  const [showSound, setShowSound] = useState(false)

  const open = (n: AppNotification) => {
    markRead([n.id])
    onClose?.()
    if (n.link) navigate(n.link)
  }
  const list = filter === 'all' ? alertItems : alertItems.filter((n) => alertKind(n) === filter)

  return (
    <div className="flex flex-col max-h-[inherit]" data-testid="alerts-panel">
      <div className="flex items-center gap-2 px-4 py-3 border-b border-neutral-100">
        <h2 className="flex-1 text-[15.5px] font-bold text-neutral-900">পোস্ট / ভিডিও এলার্ট</h2>
        <button type="button" onClick={() => setShowSound((v) => !v)} className="text-[12.5px] text-neutral-500" aria-label="সাউন্ড">
          🔊
        </button>
        {unreadAlerts > 0 && (
          <button type="button" onClick={() => markAllRead('alerts')} className="text-[12.5px] font-medium text-brand-600">
            সব পড়া হয়েছে
          </button>
        )}
      </div>
      {showSound && <SoundSettings kinds={['post', 'video']} />}
      <div className="flex gap-1.5 px-3 pt-2.5 pb-1">
        {(
          [
            ['all', 'সব'],
            ['post', '✎ পোস্ট'],
            ['video', '▶ ভিডিও'],
          ] as [AlertFilter, string][]
        ).map(([k, l]) => (
          <button
            key={k}
            type="button"
            onClick={() => setFilter(k)}
            className={`px-2.5 py-1 rounded-full text-[12px] font-medium ${filter === k ? 'bg-neutral-900 text-white' : 'bg-neutral-100 text-neutral-600'}`}
          >
            {l}
          </button>
        ))}
      </div>
      <SystemPermissionHint />
      <div className="overflow-y-auto divide-y divide-neutral-100">
        {!loaded ? (
          <p className="px-4 py-8 text-center text-[13px] text-neutral-400">লোড হচ্ছে…</p>
        ) : list.length === 0 ? (
          <p className="px-4 py-10 text-center text-[13px] text-neutral-500 leading-relaxed">
            এখনো কোনো এলার্ট নেই।
            <br />
            প্রোফাইল ডেস্কে কোনো প্রোফাইলের লিংকে 🔔 পোস্ট বা ▶ ভিডিও টিক দিলে নতুন পোস্ট/ভিডিও এলে এখানে আসবে।
          </p>
        ) : (
          list.map((n) => <NoteRow key={n.id} n={n} onOpen={open} />)
        )}
      </div>
    </div>
  )
}

/** A header button that opens a panel: full-width sheet (phone) or dropdown (desktop). */
export function HeaderPopover({
  placement,
  className = '',
  button,
  children,
}: {
  placement: 'sheet' | 'dropdown'
  className?: string
  button: (open: boolean, toggle: () => void) => ReactNode
  children: (close: () => void) => ReactNode
}) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const onDoc = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const close = () => setOpen(false)
  return (
    <div ref={ref} className={`relative ${className}`}>
      {button(open, () => setOpen((o) => !o))}
      {open &&
        (placement === 'sheet' ? (
          <div className="fixed inset-0 z-50 flex flex-col">
            <div className="absolute inset-0 bg-black/30" onClick={close} />
            <div className="relative mx-auto mt-0 w-full max-w-[480px] max-h-[80vh] bg-white rounded-b-2xl shadow-xl overflow-hidden flex flex-col">{children(close)}</div>
          </div>
        ) : (
          <div className="absolute right-0 top-full mt-2 z-50 w-[380px] max-w-[calc(100vw-24px)] max-h-[72vh] bg-white rounded-xl shadow-xl ring-1 ring-black/5 overflow-hidden flex flex-col">
            {children(close)}
          </div>
        ))}
    </div>
  )
}

export function Badge({ n, tone = 'bg-red-600' }: { n: number; tone?: string }) {
  if (!n) return null
  return (
    <span className={`absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full ${tone} text-white text-[10.5px] font-bold leading-[18px] text-center ring-2 ring-white`}>
      {n > 99 ? '৯৯+' : bn(n)}
    </span>
  )
}

/** The bell: পোস্ট/ভিডিও এলার্ট only. */
export default function NotificationBell({ placement = 'dropdown', className = '' }: { placement?: 'sheet' | 'dropdown'; className?: string }) {
  const { unreadAlerts } = useNotifications()
  return (
    <HeaderPopover
      placement={placement}
      className={className}
      button={(_, toggle) => (
        <button
          type="button"
          onClick={toggle}
          data-testid="alerts-bell"
          title="পোস্ট / ভিডিও এলার্ট"
          aria-label={unreadAlerts ? `পোস্ট/ভিডিও এলার্ট, ${bn(unreadAlerts)}টি নতুন` : 'পোস্ট/ভিডিও এলার্ট'}
          className="relative p-2 rounded-full text-neutral-600 hover:bg-neutral-100 active:scale-95"
        >
          <BellIcon className="w-[22px] h-[22px]" />
          <Badge n={unreadAlerts} />
        </button>
      )}
    >
      {(close) => <NotificationPanel onClose={close} />}
    </HeaderPopover>
  )
}

/** Toasts for notifications that arrived while the app is open (both places, each marked). */
export function NotificationToasts({ position = 'top' }: { position?: 'top' | 'bottom-right' }) {
  const { toasts, dismissToast, markRead } = useNotifications()
  const navigate = useNavigate()
  if (!toasts.length) return null
  return (
    <div
      className={`fixed z-[60] flex flex-col gap-2 pointer-events-none ${
        position === 'top' ? 'top-3 inset-x-0 items-center px-3' : 'bottom-5 right-5 items-end'
      }`}
    >
      {toasts.map(({ key, n }) => {
        const alert = channelOf(n) === 'alerts'
        const tag = alert ? (alertKind(n) === 'video' ? '▶ ভিডিও এলার্ট' : '🔔 পোস্ট এলার্ট') : '📥 ইনবক্স'
        return (
          <div
            key={key}
            role="status"
            data-testid="nros-toast"
            data-channel={channelOf(n)}
            className={`pointer-events-auto w-full max-w-[400px] rounded-xl bg-white shadow-lg ring-1 flex items-start gap-3 px-3.5 py-3 animate-[nros-toast-in_.25s_ease-out] ${
              alert ? 'ring-amber-200 border-l-4 border-l-amber-500' : 'ring-black/5 border-l-4 border-l-brand-600'
            }`}
          >
            <NoteIcon n={n} />
            <button
              type="button"
              className="flex-1 min-w-0 text-left"
              onClick={() => {
                markRead([n.id])
                dismissToast(key)
                if (n.link) navigate(n.link)
              }}
            >
              <span className="block text-[10.5px] font-semibold text-neutral-400 leading-none mb-1">{tag}</span>
              <span className="block text-[14px] font-semibold text-neutral-900 leading-snug">{n.title}</span>
              {n.body && <span className="block text-[12.5px] text-neutral-500 leading-snug line-clamp-2 whitespace-pre-line">{n.body}</span>}
            </button>
            <button type="button" aria-label="বন্ধ করুন" onClick={() => dismissToast(key)} className="text-neutral-400 px-1 text-[18px] leading-none">
              ×
            </button>
          </div>
        )
      })}
    </div>
  )
}
