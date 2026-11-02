import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { notificationTone, timeAgo, useNotifications } from '../lib/notifications'
import type { AppNotification } from '../lib/api'

// Round 18 — bell button with unread badge, the notification panel, and the
// toasts for newly arrived notifications.

const BN = '০১২৩৪৫৬৭৮৯'
const bn = (n: number) => String(n).replace(/[0-9]/g, (d) => BN[Number(d)])

const toneDot = { good: 'bg-emerald-500', bad: 'bg-red-500', info: 'bg-blue-500' }
const toneIcon = { good: '✓', bad: '↩', info: '•' }
const toneIconBg = { good: 'bg-emerald-50 text-emerald-600', bad: 'bg-red-50 text-red-600', info: 'bg-blue-50 text-blue-600' }

function BellIcon({ className = '' }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
      <path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
      <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
    </svg>
  )
}

function Row({ n, onOpen }: { n: AppNotification; onOpen: (n: AppNotification) => void }) {
  const tone = notificationTone(n.type)
  return (
    <button
      type="button"
      onClick={() => onOpen(n)}
      className={`w-full text-left flex gap-3 px-4 py-3 active:bg-neutral-50 hover:bg-neutral-50 ${n.read ? '' : 'bg-brand-50/40'}`}
    >
      <span className={`shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-[14px] font-bold ${toneIconBg[tone]}`}>{toneIcon[tone]}</span>
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

export function NotificationPanel({ onClose }: { onClose?: () => void }) {
  const { items, unread, loaded, markRead, markAllRead, systemPermission, askSystemPermission } = useNotifications()
  const navigate = useNavigate()

  const open = (n: AppNotification) => {
    markRead([n.id])
    onClose?.()
    if (n.link) navigate(n.link)
  }

  return (
    <div className="flex flex-col max-h-[inherit]">
      <div className="flex items-center justify-between px-4 py-3 border-b border-neutral-100">
        <h2 className="text-[15.5px] font-bold text-neutral-900">নোটিফিকেশন</h2>
        {unread > 0 && (
          <button type="button" onClick={markAllRead} className="text-[12.5px] font-medium text-brand-600">
            সব পড়া হয়েছে
          </button>
        )}
      </div>
      {systemPermission === 'default' && (
        <button
          type="button"
          onClick={askSystemPermission}
          className="mx-3 mt-3 rounded-lg bg-blue-50 px-3 py-2 text-left text-[12.5px] text-blue-700"
        >
          🔔 অ্যাপ খোলা না থাকলেও (ট্যাব পেছনে থাকলে) জানতে চাইলে এখানে চাপুন ও “Allow” দিন।
        </button>
      )}
      <div className="overflow-y-auto divide-y divide-neutral-100">
        {!loaded ? (
          <p className="px-4 py-8 text-center text-[13px] text-neutral-400">লোড হচ্ছে…</p>
        ) : items.length === 0 ? (
          <p className="px-4 py-10 text-center text-[13.5px] text-neutral-500">এখনো কোনো নোটিফিকেশন নেই।</p>
        ) : (
          items.map((n) => <Row key={n.id} n={n} onOpen={open} />)
        )}
      </div>
    </div>
  )
}

/**
 * Bell + dropdown. `placement="sheet"` opens a full-width panel from the top
 * (phone); `"dropdown"` anchors it under the bell (desktop).
 */
export default function NotificationBell({ placement = 'dropdown', className = '' }: { placement?: 'sheet' | 'dropdown'; className?: string }) {
  const { unread } = useNotifications()
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

  return (
    <div ref={ref} className={`relative ${className}`}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-label={unread ? `নোটিফিকেশন, ${bn(unread)}টি নতুন` : 'নোটিফিকেশন'}
        className="relative p-2 rounded-full text-neutral-600 hover:bg-neutral-100 active:scale-95"
      >
        <BellIcon className="w-[22px] h-[22px]" />
        {unread > 0 && (
          <span className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10.5px] font-bold leading-[18px] text-center ring-2 ring-white">
            {unread > 99 ? '৯৯+' : bn(unread)}
          </span>
        )}
      </button>
      {open &&
        (placement === 'sheet' ? (
          <div className="fixed inset-0 z-50 flex flex-col">
            <div className="absolute inset-0 bg-black/30" onClick={() => setOpen(false)} />
            <div className="relative mx-auto mt-0 w-full max-w-[480px] max-h-[80vh] bg-white rounded-b-2xl shadow-xl overflow-hidden flex flex-col">
              <NotificationPanel onClose={() => setOpen(false)} />
            </div>
          </div>
        ) : (
          <div className="absolute right-0 top-full mt-2 z-50 w-[360px] max-w-[calc(100vw-24px)] max-h-[70vh] bg-white rounded-xl shadow-xl ring-1 ring-black/5 overflow-hidden flex flex-col">
            <NotificationPanel onClose={() => setOpen(false)} />
          </div>
        ))}
    </div>
  )
}

/** Toasts for notifications that arrived while the app is open. */
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
        const tone = notificationTone(n.type)
        return (
          <div
            key={key}
            role="status"
            data-testid="nros-toast"
            className="pointer-events-auto w-full max-w-[400px] rounded-xl bg-white shadow-lg ring-1 ring-black/5 flex items-start gap-3 px-3.5 py-3 animate-[nros-toast-in_.25s_ease-out]"
          >
            <span className={`shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-[14px] font-bold ${toneIconBg[tone]}`}>{toneIcon[tone]}</span>
            <button
              type="button"
              className="flex-1 min-w-0 text-left"
              onClick={() => {
                markRead([n.id])
                dismissToast(key)
                if (n.link) navigate(n.link)
              }}
            >
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
