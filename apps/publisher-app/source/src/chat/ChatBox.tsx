import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { ChatApi, type ChatLabel, type Person, type Room, type RoomsResponse } from './api'
import { useChat } from './ChatContext'
import { Avatar, CountPill, LabelChip, RoomIcon, Sheet, Spinner } from './bits'
import PeoplePicker from './PeoplePicker'
import { PushBanner, PushSheet, usePush } from './PushSetup'
import RoomView from './RoomView'
import { MyTasks } from './TasksPanel'
import { bnNum, DEFAULT_LABELS, shortWhen } from './util'

/**
 * r36 — চ্যাট বক্স.
 *
 *   variant="compact" — the room list only (inside the ইনবক্স pop-up);
 *                        tapping a room calls onOpenRoom.
 *   variant="page"    — list + open room side by side on a computer,
 *                        one at a time on a phone.
 */

type Filter = 'all' | 'unread' | 'urgent' | 'desk'

const ICONS = ['👥', '📸', '🎥', '⚽', '🏛️', '💼', '📢', '🗳️', '🌾', '🏥', '🎓', '⚖️']

function NewRoom({ onClose, onCreated }: { onClose: () => void; onCreated: (id: number) => void }) {
  const [name, setName] = useState('')
  const [about, setAbout] = useState('')
  const [icon, setIcon] = useState('👥')
  const [posting, setPosting] = useState<'all' | 'admins'>('all')
  const [ids, setIds] = useState<number[]>([])
  const [pick, setPick] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const create = async () => {
    setBusy(true)
    setErr('')
    try {
      const r = await ChatApi.createRoom({ name: name.trim(), about: about.trim(), icon, posting, member_ids: ids })
      onCreated(r.id)
    } catch (e) {
      setErr((e as Error).message)
    } finally {
      setBusy(false)
    }
  }
  return (
    <>
      <Sheet open onClose={onClose} title="নতুন রুম" wide>
        <div className="px-5 pb-5 space-y-3" data-testid="new-room">
          <div className="flex flex-wrap gap-1">
            {ICONS.map((i) => (
              <button key={i} type="button" onClick={() => setIcon(i)} className={`w-10 h-10 rounded-xl text-[19px] ${icon === i ? 'bg-brand-50 ring-2 ring-brand-400' : 'hover:bg-neutral-100'}`}>
                {i}
              </button>
            ))}
          </div>
          <input autoFocus value={name} onChange={(e) => setName(e.target.value)} placeholder="রুমের নাম (যেমন: নির্বাচন ২০২৬ টিম)" className="w-full rounded-xl border border-neutral-300 px-3 py-2.5 text-[14px]" data-testid="new-room-name" />
          <input value={about} onChange={(e) => setAbout(e.target.value)} placeholder="কীসের জন্য (ইচ্ছে হলে)" className="w-full rounded-xl border border-neutral-300 px-3 py-2.5 text-[13.5px]" />
          <label className="flex items-center gap-2 text-[13.5px] text-neutral-700">
            <input type="checkbox" className="w-4 h-4 accent-brand-600" checked={posting === 'admins'} onChange={(e) => setPosting(e.target.checked ? 'admins' : 'all')} />
            শুধু অ্যাডমিন লিখবেন (ঘোষণার রুম)
          </label>
          <button type="button" onClick={() => setPick(true)} className="w-full rounded-xl border border-dashed border-neutral-300 px-3 py-2.5 text-[13.5px] text-neutral-700 text-left">
            👥 {ids.length ? `${bnNum(ids.length)} জন সদস্য বাছাই করা হয়েছে · বদলান` : 'সদস্য বাছাই করুন'}
          </button>
          {err && <p className="text-[12.5px] text-red-600">{err}</p>}
          <div className="flex justify-end">
            <button type="button" disabled={!name.trim() || busy} onClick={create} className="px-5 py-2.5 rounded-xl bg-brand-600 disabled:bg-neutral-300 text-white text-[14px] font-semibold" data-testid="new-room-create">
              {busy ? 'খোলা হচ্ছে…' : 'রুম খুলুন'}
            </button>
          </div>
        </div>
      </Sheet>
      {pick && (
        <PeoplePicker
          title="সদস্য বাছাই"
          multi
          onClose={() => setPick(false)}
          onPick={(x) => {
            setIds(x)
            setPick(false)
          }}
        />
      )}
    </>
  )
}

function RoomRow({ r, active, labels, onClick }: { r: Room; active: boolean; labels: ChatLabel[]; onClick: () => void }) {
  const unread = r.unread > 0
  const deskAvatar = r.kind === 'desk' && r.avatar ? { id: r.id, name: r.name, avatar: r.avatar } : undefined
  return (
    <button
      type="button"
      onClick={onClick}
      data-testid={`room-${r.id}`}
      className={`w-full flex items-center gap-3 px-3 py-2.5 text-left rounded-xl transition ${active ? 'bg-brand-50' : 'hover:bg-neutral-50'} ${r.archived ? 'opacity-60' : ''}`}
    >
      <RoomIcon icon={r.icon} size={46} online={r.online} avatar={deskAvatar} />
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-1.5">
          <span className={`truncate text-[14.5px] ${unread ? 'font-bold text-neutral-900' : 'font-semibold text-neutral-800'}`}>{r.name}</span>
          {r.muted && <span className="text-[11px]">🔕</span>}
          {r.posting === 'admins' && r.kind !== 'desk' && <span className="text-[11px]" title="শুধু অ্যাডমিন লেখেন">📢</span>}
          <span className={`ml-auto shrink-0 text-[11px] ${unread ? 'text-brand-600 font-semibold' : 'text-neutral-400'}`}>{shortWhen(r.last?.at ?? r.last_at)}</span>
        </span>
        <span className="flex items-center gap-1.5 mt-0.5">
          {r.last?.label && r.last.label !== 'general' && <LabelChip label={r.last.label} labels={labels} small />}
          <span className={`truncate text-[12.5px] ${unread ? 'text-neutral-800' : 'text-neutral-500'}`}>
            {r.last ? (r.last.author ? `${r.last.author}: ` : '') + r.last.text : r.about || 'এখনও কোনো মেসেজ নেই'}
          </span>
          <span className="ml-auto flex items-center gap-1 shrink-0">
            {r.urgent > 0 && <CountPill n={r.urgent} tone="bg-red-600" />}
            {r.mentions > 0 && <span className="w-5 h-5 rounded-full bg-amber-500 text-white text-[11px] font-bold flex items-center justify-center">@</span>}
            {unread && !r.urgent && <CountPill n={r.unread} tone={r.muted ? 'bg-neutral-400' : 'bg-brand-600'} />}
          </span>
        </span>
      </span>
    </button>
  )
}

export default function ChatBox({
  variant = 'page',
  roomId = null,
  onRoomChange,
  onOpenRoom,
}: {
  variant?: 'page' | 'compact'
  roomId?: number | null
  onRoomChange?: (id: number | null) => void
  onOpenRoom?: (id: number) => void
}) {
  const chat = useChat()
  const [data, setData] = useState<RoomsResponse | null>(null)
  const [err, setErr] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  const [q, setQ] = useState('')
  const [newMenu, setNewMenu] = useState(false)
  const [newRoom, setNewRoom] = useState(false)
  const [deskPick, setDeskPick] = useState(false)
  const [pushOpen, setPushOpen] = useState(false)
  const push = usePush()
  const [selected, setSelected] = useState<number | null>(roomId)
  // a reminder notice opens …?tasks=1 (with a room: that room's টু-ডু)
  const [tasksAsk] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('tasks') === '1')
  const [myTasks, setMyTasks] = useState(() => tasksAsk && !roomId)
  const [people, setPeople] = useState<Person[]>([])
  // side by side only when the box itself is wide (the correspondent app is a
  // narrow column even on a computer)
  const boxRef = useRef<HTMLDivElement>(null)
  const [wide, setWide] = useState(false)
  useEffect(() => {
    const el = boxRef.current
    if (!el) return
    const ro = new ResizeObserver(([e]) => setWide(e.contentRect.width >= 720))
    ro.observe(el)
    return () => ro.disconnect()
  }, [])

  useEffect(() => setSelected(roomId), [roomId])

  const load = useCallback(() => {
    ChatApi.rooms()
      .then((r) => {
        setData(r)
        setErr('')
      })
      .catch((e: Error) => setErr(e.message || 'চ্যাট লোড করা গেল না'))
  }, [])

  useEffect(() => {
    load()
    const t = setInterval(() => !document.hidden && load(), 20000)
    return () => clearInterval(t)
  }, [load])
  // a new message anywhere → fresh list
  const latest = chat.summary?.latest_id
  useEffect(() => {
    if (latest !== undefined) load()
  }, [latest, load])

  const open = (id: number | null) => {
    if (variant === 'compact' && id) return onOpenRoom?.(id)
    setSelected(id)
    onRoomChange?.(id)
  }

  useEffect(() => {
    const h = (e: Event) => open((e as CustomEvent<number>).detail)
    window.addEventListener('nros-chat-open', h)
    return () => window.removeEventListener('nros-chat-open', h)
  })

  const onRead = useCallback((id: number) => {
    setData((d) => (d ? { ...d, rooms: d.rooms.map((r) => (r.id === id ? { ...r, unread: 0, mentions: 0, urgent: 0 } : r)) } : d))
  }, [])

  const rooms = useMemo(() => {
    let l = data?.rooms ?? []
    if (q.trim()) l = l.filter((r) => r.name.toLowerCase().includes(q.trim().toLowerCase()))
    if (filter === 'unread') l = l.filter((r) => r.unread > 0)
    if (filter === 'urgent') l = l.filter((r) => r.urgent > 0 || r.last?.label === 'urgent')
    if (filter === 'desk') l = l.filter((r) => r.kind === 'desk')
    return l
  }, [data, q, filter])

  // staff: the search box finds people too (a desk line opens with one tap)
  const staff = !!data?.me.staff
  useEffect(() => {
    const term = q.trim()
    if (!staff || term.length < 2) return setPeople([])
    const t = setTimeout(() => {
      ChatApi.people(term)
        .then((r) => setPeople(r.people.slice(0, 8)))
        .catch(() => setPeople([]))
    }, 300)
    return () => clearTimeout(t)
  }, [q, staff])

  const openDesk = async (uid: number) => {
    try {
      const r = await ChatApi.openDesk(uid)
      setQ('')
      load()
      open(r.id)
    } catch (e) {
      alert((e as Error).message)
    }
  }

  const labels = data?.labels?.length ? data.labels : (DEFAULT_LABELS as unknown as ChatLabel[])
  const me = data?.me ?? { id: 0, staff: false, publisher: false }
  const current = data?.rooms.find((r) => r.id === selected)
  const unreadRooms = (data?.rooms ?? []).filter((r) => r.unread > 0).length

  const list = (
    <div className="flex flex-col h-full min-h-0" data-testid="chat-list">
      <div className="px-3 pt-3 pb-2 space-y-2">
        <div className="flex items-center gap-2">
          <div className="relative flex-1">
            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-[13px] text-neutral-400">🔍</span>
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="রুম বা নাম খুঁজুন" className="w-full rounded-xl bg-neutral-100 pl-8 pr-3 py-2 text-[13.5px] outline-none focus:bg-white focus:ring-2 focus:ring-brand-200" />
          </div>
          <button
            type="button"
            onClick={() => setPushOpen(true)}
            className={`relative h-9 w-9 shrink-0 rounded-xl text-[16px] ${push.state === 'on' ? 'bg-emerald-50' : 'bg-neutral-100'}`}
            title="ফোনে নোটিফিকেশন"
            aria-label="ফোনে নোটিফিকেশন"
            data-testid="push-btn"
          >
            🔔
            {push.state && push.state !== 'on' && <span className="absolute top-1 right-1 w-2 h-2 rounded-full bg-red-500" />}
          </button>
          {me.staff && (
            <div className="relative">
              <button type="button" onClick={() => setNewMenu((v) => !v)} className="h-9 px-3 rounded-xl bg-brand-600 text-white text-[13px] font-semibold whitespace-nowrap" data-testid="chat-new">
                + নতুন
              </button>
              {newMenu && (
                <>
                  <div className="fixed inset-0 z-10" onClick={() => setNewMenu(false)} />
                  <div className="absolute right-0 top-11 z-20 w-60 rounded-2xl bg-white shadow-xl ring-1 ring-black/5 p-1.5">
                    <button type="button" onClick={() => (setNewMenu(false), setDeskPick(true))} className="w-full text-left rounded-xl px-3 py-2.5 hover:bg-neutral-50" data-testid="chat-new-desk">
                      <span className="block text-[14px] font-medium">✉️ সংবাদদাতাকে লিখুন</span>
                      <span className="block text-[11.5px] text-neutral-500">তাঁর সঙ্গে ডেস্কের সরাসরি আলাপ</span>
                    </button>
                    <button type="button" onClick={() => (setNewMenu(false), setNewRoom(true))} className="w-full text-left rounded-xl px-3 py-2.5 hover:bg-neutral-50" data-testid="chat-new-room">
                      <span className="block text-[14px] font-medium">👥 নতুন রুম</span>
                      <span className="block text-[11.5px] text-neutral-500">টিম, বিট বা ঘটনার জন্য আলাদা রুম</span>
                    </button>
                  </div>
                </>
              )}
            </div>
          )}
        </div>
        <div className="flex gap-1.5 overflow-x-auto -mx-1 px-1 pb-0.5">
          {(
            [
              ['all', 'সব'],
              ['unread', unreadRooms ? `অপঠিত (${unreadRooms})` : 'অপঠিত'],
              ['urgent', '🚨 জরুরি'],
              ...(me.staff ? ([['desk', '✉️ সংবাদদাতা']] as [Filter, string][]) : []),
            ] as [Filter, string][]
          ).map(([k, l]) => (
            <button
              key={k}
              type="button"
              onClick={() => setFilter(k)}
              className={`shrink-0 rounded-full px-3 py-1 text-[12.5px] font-medium ${filter === k ? 'bg-neutral-900 text-white' : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'}`}
              data-testid={`chat-filter-${k}`}
            >
              {l.replace(/\d/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])}
            </button>
          ))}
        </div>
      </div>
      <PushBanner key={String(push.state)} onOpen={() => setPushOpen(true)} />
      {data && (
        <button
          type="button"
          onClick={() => setMyTasks(true)}
          className="mx-3 mb-1.5 flex items-center gap-2.5 rounded-xl bg-amber-50 ring-1 ring-amber-200/80 px-3 py-2 text-left"
          data-testid="my-tasks-btn"
        >
          <span className="text-[18px]">✅</span>
          <span className="flex-1 min-w-0">
            <span className="block text-[13.5px] font-semibold text-neutral-900">আমার কাজ</span>
            <span className="block truncate text-[11.5px] text-neutral-600">
              {data.my_tasks?.open
                ? `${bnNum(data.my_tasks.open)}টি বাকি${data.my_tasks.late ? ` · ${bnNum(data.my_tasks.late)}টির সময় পেরিয়েছে` : ''}`
                : 'সব রুমের টু-ডু এক জায়গায়'}
            </span>
          </span>
          {!!data.my_tasks?.open && <CountPill n={data.my_tasks.open} tone={data.my_tasks.late ? 'bg-red-600' : 'bg-amber-500'} />}
        </button>
      )}
      <div className="flex-1 min-h-0 overflow-y-auto px-1.5 pb-2">
        {!data && !err ? (
          <div className="p-8 flex justify-center">
            <Spinner className="w-6 h-6" />
          </div>
        ) : err && !data ? (
          <div className="p-6 text-center text-[13.5px] text-red-600">
            {err}
            <button type="button" onClick={load} className="block mx-auto mt-2 text-brand-600 font-medium">
              আবার চেষ্টা
            </button>
          </div>
        ) : rooms.length === 0 ? (
          people.length === 0 && <p className="p-8 text-center text-[13.5px] text-neutral-500">{filter === 'all' && !q ? 'কোনো রুম নেই' : 'এমন কোনো রুম নেই'}</p>
        ) : (
          rooms.map((r) => <RoomRow key={r.id} r={r} active={variant === 'page' && r.id === selected} labels={labels} onClick={() => open(r.id)} />)
        )}
        {people.length > 0 && (
          <div className="mt-2" data-testid="people-results">
            <div className="px-3 pb-1 text-[11.5px] font-semibold text-neutral-500">👤 মানুষ</div>
            {people.map((p) => (
              <button
                key={p.id}
                type="button"
                disabled={p.role !== 'সংবাদদাতা'}
                onClick={() => void openDesk(p.id)}
                className="w-full flex items-center gap-3 px-3 py-2 text-left rounded-xl hover:bg-neutral-50 disabled:hover:bg-transparent"
              >
                <Avatar p={p} size={38} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[14px] font-medium text-neutral-900">{p.name}</span>
                  <span className="block truncate text-[11.5px] text-neutral-500">
                    {p.role}
                    {p.area ? ` · ${p.area}` : ''}
                  </span>
                </span>
                {p.role === 'সংবাদদাতা' && <span className="shrink-0 text-[12px] font-semibold text-brand-600">✉️ লিখুন</span>}
              </button>
            ))}
          </div>
        )}
      </div>
    </div>
  )

  const dialogs = (
    <>
      {myTasks && (
        <MyTasks
          onClose={() => {
            setMyTasks(false)
            load()
          }}
          onOpenRoom={(id) => {
            setMyTasks(false)
            open(id)
          }}
        />
      )}
      <PushSheet
        open={pushOpen}
        onClose={() => {
          setPushOpen(false)
          push.refresh()
        }}
      />
      {newRoom && (
        <NewRoom
          onClose={() => setNewRoom(false)}
          onCreated={(id) => {
            setNewRoom(false)
            load()
            open(id)
          }}
        />
      )}
      {deskPick && (
        <PeoplePicker
          title="কোন সংবাদদাতাকে লিখবেন?"
          onlyCorrespondents
          onClose={() => setDeskPick(false)}
          onPick={async ([uid]) => {
            setDeskPick(false)
            try {
              const r = await ChatApi.openDesk(uid)
              load()
              open(r.id)
            } catch (e) {
              alert((e as Error).message)
            }
          }}
        />
      )}
    </>
  )

  if (variant === 'compact') {
    return (
      <div className="flex flex-col h-[min(60vh,520px)] min-h-0" data-testid="chat-box">
        {list}
        {dialogs}
      </div>
    )
  }

  return (
    <div ref={boxRef} className="flex h-full min-h-0 bg-white" data-testid="chat-box" data-wide={wide ? '1' : '0'}>
      <div className={`${selected && !wide ? 'hidden' : 'flex'} ${wide ? 'w-[340px] xl:w-[370px] border-r border-neutral-200/80' : 'w-full'} shrink-0 flex-col min-h-0`}>{list}</div>
      <div className={`${selected || wide ? 'flex' : 'hidden'} flex-1 min-w-0 flex-col min-h-0`}>
        {selected && data ? (
          <RoomView
            key={selected}
            roomId={selected}
            initial={current}
            labels={labels}
            reactions={data.reactions}
            me={me}
            onBack={wide ? undefined : () => open(null)}
            onRead={onRead}
            onRoomsChanged={load}
            openTasks={tasksAsk && selected === roomId}
            onLeft={() => {
              open(null)
              load()
            }}
          />
        ) : (
          <div className="h-full flex flex-col items-center justify-center text-center px-8 bg-[#f6f3ef]">
            <div className="text-[44px]">💬</div>
            <p className="mt-2 text-[15px] font-semibold text-neutral-800">একটা রুম বেছে নিন</p>
            <p className="mt-1 text-[12.5px] text-neutral-500 max-w-[320px]">মিহা পরিবারের সবাই এক রুমে, ডেস্ক আর বিভাগের আলাদা রুম। জরুরি, মিটিং, ঘোষণা লেবেল দিয়ে লিখুন।</p>
          </div>
        )}
      </div>
      {dialogs}
    </div>
  )
}
