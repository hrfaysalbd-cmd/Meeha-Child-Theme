import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useNotifications } from '../lib/notifications'
import type { AppNotification } from '../lib/api'
import { Badge, bn, HeaderPopover, NoteRow, SoundSettings, SystemPermissionHint } from './NotificationBell'
import { IconInbox } from './Icon'

// r35 — ইনবক্স: two boxes.
//   কিউ বক্স  — the editorial queue and everything sent to the desk
//               (new articles/videos to review, applications, edit
//               requests, withdrawals, tasks, live updates waiting …).
//   চ্যাট বক্স — conversations between correspondents and the desk. Coming
//               with the চ্যাট ও রুম module; for now it says so.

export type InboxTab = 'queue' | 'chat'

function QueueCounts({ onGo }: { onGo?: () => void }) {
  const { counts } = useNotifications()
  if (!counts) return null
  const cells: [string, number | undefined, string][] = [
    ['সংবাদ রিভিউ', counts.queue, '/app/queue'],
    ['ভিডিও রিভিউ', counts.videos, '/app/videos?tab=pending'],
    ['আবেদন', counts.applications, '/app/applications'],
  ]
  if (counts.withdrawals !== undefined) cells.push(['উইথড্র', counts.withdrawals, '/app/finance'])
  return (
    <div className={`grid gap-1.5 px-3 pt-3 ${cells.length === 4 ? 'grid-cols-4' : 'grid-cols-3'}`} data-testid="inbox-counts">
      {cells.map(([label, n, to]) => (
        <Link key={to} to={to} onClick={onGo} className={`rounded-lg px-2 py-2 text-center ${n ? 'bg-brand-50 text-brand-700' : 'bg-neutral-50 text-neutral-400'}`}>
          <span className="block text-[17px] font-bold leading-none">{bn(n ?? 0)}</span>
          <span className="block text-[10.5px] mt-1 leading-none">{label}</span>
        </Link>
      ))}
    </div>
  )
}

function ChatSoon() {
  return (
    <div className="px-5 py-8 text-center" data-testid="chat-box">
      <div className="mx-auto w-12 h-12 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center text-[22px]">💬</div>
      <p className="mt-3 text-[14.5px] font-semibold text-neutral-800">চ্যাট বক্স শীঘ্রই আসছে</p>
      <p className="mt-1.5 text-[12.5px] text-neutral-500 leading-relaxed">
        এখানে সাংবাদিকরা এডিটর ও পাবলিশারের সঙ্গে সরাসরি কথা বলতে ও মেসেজ দিতে পারবেন, ডেস্কও তাঁদের লিখতে পারবে। প্রতিটি সংবাদের নিজের আলাপ, জেলা/টিমের রুম আর জরুরি
        নিরাপত্তা রুম থাকবে।
      </p>
    </div>
  )
}

export function InboxPanel({ onClose, full = false, initialTab = 'queue' }: { onClose?: () => void; full?: boolean; initialTab?: InboxTab }) {
  const { inboxItems, unreadInbox, loaded, markRead, markAllRead } = useNotifications()
  const navigate = useNavigate()
  const [tab, setTab] = useState<InboxTab>(initialTab)
  const [onlyUnread, setOnlyUnread] = useState(false)
  const [showSound, setShowSound] = useState(false)

  const open = (n: AppNotification) => {
    markRead([n.id])
    onClose?.()
    if (n.link) navigate(n.link)
  }
  const list = onlyUnread ? inboxItems.filter((n) => !n.read) : inboxItems

  return (
    <div className="flex flex-col max-h-[inherit] min-h-0" data-testid="inbox-panel">
      <div className="flex items-center gap-2 px-4 pt-3 pb-2">
        <h2 className="flex-1 text-[15.5px] font-bold text-neutral-900">ইনবক্স</h2>
        <button type="button" onClick={() => setShowSound((v) => !v)} className="text-[12.5px] text-neutral-500" aria-label="সাউন্ড">
          🔊
        </button>
        {tab === 'queue' && unreadInbox > 0 && (
          <button type="button" onClick={() => markAllRead('inbox')} className="text-[12.5px] font-medium text-brand-600">
            সব পড়া হয়েছে
          </button>
        )}
      </div>
      <div className="flex border-b border-neutral-100 px-2">
        {(
          [
            ['queue', 'কিউ বক্স', unreadInbox],
            ['chat', 'চ্যাট বক্স', 0],
          ] as [InboxTab, string, number][]
        ).map(([k, l, n]) => (
          <button
            key={k}
            type="button"
            onClick={() => setTab(k)}
            data-testid={`inbox-tab-${k}`}
            className={`flex-1 py-2 text-[13.5px] font-semibold border-b-2 -mb-px ${tab === k ? 'border-brand-600 text-brand-700' : 'border-transparent text-neutral-500'}`}
          >
            {l}
            {n > 0 && <span className="ml-1.5 inline-block min-w-[18px] h-[18px] px-1 rounded-full bg-brand-600 text-white text-[10.5px] leading-[18px]">{n > 99 ? '৯৯+' : bn(n)}</span>}
          </button>
        ))}
      </div>
      {showSound && <SoundSettings kinds={['inbox']} />}
      {tab === 'chat' ? (
        <ChatSoon />
      ) : (
        <>
          <QueueCounts onGo={onClose} />
          <div className="flex items-center gap-2 px-4 pt-2.5 pb-1 text-[12px] text-neutral-500">
            <label className="flex items-center gap-1.5 cursor-pointer">
              <input type="checkbox" checked={onlyUnread} onChange={(e) => setOnlyUnread(e.target.checked)} /> শুধু অপঠিত
            </label>
            {!full && (
              <Link to="/app/inbox" onClick={onClose} className="ml-auto text-brand-600 font-medium">
                পুরো ইনবক্স →
              </Link>
            )}
          </div>
          <SystemPermissionHint />
          <div className={`overflow-y-auto divide-y divide-neutral-100 ${full ? '' : 'min-h-0'}`}>
            {!loaded ? (
              <p className="px-4 py-8 text-center text-[13px] text-neutral-400">লোড হচ্ছে…</p>
            ) : list.length === 0 ? (
              <p className="px-4 py-10 text-center text-[13.5px] text-neutral-500">{onlyUnread ? 'সব পড়া হয়ে গেছে।' : 'ইনবক্স খালি।'}</p>
            ) : (
              list.map((n) => <NoteRow key={n.id} n={n} onOpen={open} />)
            )}
          </div>
        </>
      )}
    </div>
  )
}

/** Header tray button with the inbox's unread count. */
export default function InboxButton({ placement = 'dropdown', className = '' }: { placement?: 'sheet' | 'dropdown'; className?: string }) {
  const { unreadInbox } = useNotifications()
  return (
    <HeaderPopover
      placement={placement}
      className={className}
      button={(_, toggle) => (
        <button
          type="button"
          onClick={toggle}
          data-testid="inbox-button"
          title="ইনবক্স"
          aria-label={unreadInbox ? `ইনবক্স, ${bn(unreadInbox)}টি নতুন` : 'ইনবক্স'}
          className="relative p-2 rounded-full text-neutral-600 hover:bg-neutral-100 active:scale-95"
        >
          <IconInbox className="w-[22px] h-[22px]" />
          <Badge n={unreadInbox} tone="bg-brand-600" />
        </button>
      )}
    >
      {(close) => <InboxPanel onClose={close} />}
    </HeaderPopover>
  )
}
