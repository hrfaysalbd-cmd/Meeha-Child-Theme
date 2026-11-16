import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useNotifications } from '../lib/notifications'
import type { AppNotification } from '../lib/api'
import { Badge, bn, HeaderPopover, NoteRow, SoundSettings, SystemPermissionHint } from './NotificationBell'
import { IconInbox } from './Icon'
import ChatBox from '../chat/ChatBox'
import { useChat } from '../chat/ChatContext'

// r35 — ইনবক্স: two boxes.
//   কিউ বক্স  — the editorial queue and everything sent to the desk
//               (new articles/videos to review, applications, edit
//               requests, withdrawals, tasks, live updates waiting …).
//   চ্যাট বক্স — r36: rooms (মিহা পরিবার, ডেস্ক, বিভাগ, নিজের রুম) and
//               each correspondent's direct line to the desk. In the pop-up
//               it lists the rooms; a room opens on the full ইনবক্স page.

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

export function InboxPanel({
  onClose,
  full = false,
  initialTab = 'queue',
  roomId = null,
  onRoomChange,
}: {
  onClose?: () => void
  full?: boolean
  initialTab?: InboxTab
  roomId?: number | null
  onRoomChange?: (id: number | null) => void
}) {
  const { inboxItems, unreadInbox, loaded, markRead, markAllRead } = useNotifications()
  const chat = useChat()
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
        {tab === 'chat' && !full && (
          <Link to="/app/inbox?tab=chat" onClick={onClose} className="text-[12.5px] font-medium text-brand-600">
            পুরো চ্যাট →
          </Link>
        )}
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
            ['chat', 'চ্যাট বক্স', chat.unread],
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
      {showSound && <SoundSettings kinds={tab === 'chat' ? ['chat', 'urgent'] : ['inbox']} />}
      {tab === 'chat' ? (
        full ? (
          <div className="h-[calc(100dvh-250px)] lg:h-[calc(100dvh-190px)] min-h-[440px]">
            <ChatBox variant="page" roomId={roomId} onRoomChange={onRoomChange} />
          </div>
        ) : (
          <ChatBox
            variant="compact"
            onOpenRoom={(id) => {
              onClose?.()
              navigate(`/app/inbox?tab=chat&room=${id}`)
            }}
          />
        )
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
  const chat = useChat()
  const total = unreadInbox + chat.unread
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
          aria-label={total ? `ইনবক্স, ${bn(total)}টি নতুন` : 'ইনবক্স'}
          className="relative p-2 rounded-full text-neutral-600 hover:bg-neutral-100 active:scale-95"
        >
          <IconInbox className="w-[22px] h-[22px]" />
          <Badge n={total} tone={chat.summary?.urgent ? 'bg-red-600' : 'bg-brand-600'} />
        </button>
      )}
    >
      {(close) => <InboxPanel onClose={close} />}
    </HeaderPopover>
  )
}
