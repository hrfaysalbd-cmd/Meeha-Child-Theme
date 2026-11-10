import { Fragment, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { ChatApi, type ChatFile, type ChatLabel, type LabelKey, type Member, type Message, type Room } from './api'
import { useChat } from './ChatContext'
import { Avatar, LabelChip, RoomIcon, Sheet, Spinner, useConfirm } from './bits'
import Composer from './Composer'
import RoomInfo from './RoomInfo'
import { bnNum, clock, dayKey, dayLabel, fileSize, fullWhen, duration as fmtDur, isTouch, LABEL_ICON, meetingIcs, until } from './util'

export type LocalMsg = Message & { tempId?: number; pending?: boolean; failed?: boolean; retry?: () => void }

interface Props {
  roomId: number
  initial?: Room
  labels: ChatLabel[]
  reactions: string[]
  me: { id: number; staff: boolean; publisher: boolean }
  onBack?: () => void
  onRead: (roomId: number) => void
  onRoomsChanged: () => void
  onLeft: () => void
}

const URL_RE = /(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"।])/g

function RichText({ text, mine }: { text: string; mine: boolean }) {
  const parts: ReactNode[] = []
  let i = 0
  for (const line of text.split(URL_RE)) {
    if (/^https?:\/\//.test(line)) {
      parts.push(
        <a key={i++} href={line} target="_blank" rel="noreferrer noopener" className={`underline break-all ${mine ? 'text-white' : 'text-blue-700'}`}>
          {line}
        </a>,
      )
    } else {
      // @নাম gets a soft highlight
      line.split(/(@[\p{L}\p{M}\p{N}_.]+)/u).forEach((seg) => {
        if (seg.startsWith('@') && seg.length > 1) parts.push(<span key={i++} className={`font-semibold ${mine ? 'text-white underline decoration-white/40' : 'text-brand-700'}`}>{seg}</span>)
        else if (seg) parts.push(<Fragment key={i++}>{seg}</Fragment>)
      })
    }
  }
  return <>{parts}</>
}

/** Voice note: big play button, progress, speed; a clear message if this phone can't play it. */
function VoicePlayer({ f, mine }: { f: ChatFile; mine: boolean }) {
  const a = useRef<HTMLAudioElement | null>(null)
  const [playing, setPlaying] = useState(false)
  const [t, setT] = useState(0)
  const [len, setLen] = useState(f.duration || 0)
  const [rate, setRate] = useState(1)
  const [err, setErr] = useState(false)
  const [busy, setBusy] = useState(false)
  useEffect(() => () => a.current?.pause(), [])
  const el = () => {
    if (!a.current) {
      const au = new Audio()
      au.preload = 'metadata'
      au.src = f.url
      au.ontimeupdate = () => setT(au.currentTime)
      au.onloadedmetadata = () => isFinite(au.duration) && au.duration > 0 && setLen(au.duration)
      au.onplaying = () => (setPlaying(true), setBusy(false))
      au.onpause = () => setPlaying(false)
      au.onended = () => (setPlaying(false), setT(0))
      au.onwaiting = () => setBusy(true)
      au.onerror = () => (setErr(true), setBusy(false), setPlaying(false))
      a.current = au
    }
    return a.current
  }
  const toggle = () => {
    const au = el()
    if (playing) return au.pause()
    // only one voice at a time
    document.dispatchEvent(new CustomEvent('nros-voice-play', { detail: f.id }))
    setBusy(true)
    au.playbackRate = rate
    au.play().catch(() => (setErr(true), setBusy(false)))
  }
  useEffect(() => {
    const h = (e: Event) => (e as CustomEvent<number>).detail !== f.id && a.current?.pause()
    document.addEventListener('nros-voice-play', h)
    return () => document.removeEventListener('nros-voice-play', h)
  }, [f.id])
  const pct = len ? Math.min(100, (t / len) * 100) : 0
  const seek = (e: React.MouseEvent<HTMLDivElement>) => {
    if (!len) return
    const r = e.currentTarget.getBoundingClientRect()
    el().currentTime = ((e.clientX - r.left) / r.width) * len
  }
  const fg = mine ? 'bg-white' : 'bg-brand-600'
  return (
    <div className={`flex items-center gap-2.5 rounded-2xl px-2 py-2 w-[250px] max-w-full ${mine ? 'bg-white/15' : 'bg-neutral-100'}`} data-testid="voice-player">
      <button
        type="button"
        onClick={toggle}
        className={`w-10 h-10 shrink-0 rounded-full flex items-center justify-center text-[15px] ${mine ? 'bg-white text-brand-700' : 'bg-brand-600 text-white'}`}
        aria-label={playing ? 'থামান' : 'শুনুন'}
      >
        {busy ? <Spinner className="w-4 h-4" /> : playing ? '❚❚' : '▶'}
      </button>
      <div className="min-w-0 flex-1">
        {err ? (
          <a href={f.download} download={f.name} className={`block text-[11.5px] leading-snug underline ${mine ? 'text-white' : 'text-red-600'}`}>
            এই অ্যাপে বাজানো গেল না। ডাউনলোড করে শুনুন
          </a>
        ) : (
          <>
            <div className={`h-1.5 rounded-full cursor-pointer ${mine ? 'bg-white/30' : 'bg-neutral-300'}`} onClick={seek}>
              <div className={`h-full rounded-full ${fg}`} style={{ width: `${pct}%` }} />
            </div>
            <div className={`mt-1 flex items-center text-[11px] ${mine ? 'text-white/85' : 'text-neutral-500'}`}>
              <span>🎙️ {fmtDur(playing || t ? t : len)}</span>
              <button
                type="button"
                className="ml-auto font-semibold"
                onClick={() => {
                  const r = rate === 1 ? 1.5 : rate === 1.5 ? 2 : 1
                  setRate(r)
                  if (a.current) a.current.playbackRate = r
                }}
              >
                {bnNum(rate)}x
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function Files({ files, mine, onImage }: { files: ChatFile[]; mine: boolean; onImage: (f: ChatFile) => void }) {
  const imgs = files.filter((f) => f.kind === 'image')
  const rest = files.filter((f) => f.kind !== 'image')
  return (
    <div className="space-y-1.5">
      {imgs.length > 0 && (
        <div className={`grid gap-1 ${imgs.length > 1 ? 'grid-cols-2' : 'grid-cols-1'}`}>
          {imgs.map((f) => (
            <button key={f.id} type="button" onClick={() => onImage(f)} className="block overflow-hidden rounded-xl bg-neutral-100">
              <img src={f.url} alt={f.name} loading="lazy" className={`w-full object-cover ${imgs.length > 1 ? 'h-32' : 'max-h-72'}`} />
            </button>
          ))}
        </div>
      )}
      {rest.map((f) =>
        f.kind === 'audio' ? (
          <VoicePlayer key={f.id} f={f} mine={mine} />
        ) : f.kind === 'video' ? (
          <video key={f.id} src={f.url} controls preload="metadata" playsInline className="w-full max-h-72 rounded-xl bg-black" />
        ) : (
          <a
            key={f.id}
            href={f.download}
            className={`flex items-center gap-2.5 rounded-xl px-3 py-2 ${mine ? 'bg-white/15 text-white' : 'bg-neutral-100 text-neutral-800'}`}
            download={f.name}
          >
            <span className="text-[22px]">{/pdf/.test(f.mime) ? '📕' : /sheet|excel/.test(f.mime) ? '📗' : /word/.test(f.mime) ? '📘' : '📄'}</span>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-[13px] font-medium">{f.name}</span>
              <span className={`block text-[11px] ${mine ? 'text-white/70' : 'text-neutral-500'}`}>{fileSize(f.size)} · ডাউনলোড</span>
            </span>
          </a>
        ),
      )}
    </div>
  )
}

function MeetingCard({ m, roomName, mine }: { m: Message; roomName: string; mine: boolean }) {
  if (!m.meeting_at) return null
  const left = until(m.meeting_at)
  return (
    <div className={`mt-1.5 rounded-xl px-3 py-2 ${mine ? 'bg-white/15' : 'bg-violet-50 ring-1 ring-violet-200'}`}>
      <div className={`flex items-center gap-2 text-[13px] font-semibold ${mine ? 'text-white' : 'text-violet-800'}`}>
        <span>📅</span>
        <span>{fullWhen(m.meeting_at)}</span>
        <span className={`ml-auto text-[11px] font-medium rounded-full px-2 py-[1px] ${mine ? 'bg-white/20' : 'bg-violet-100'}`}>{left}</span>
      </div>
      {left !== 'শেষ' && (
        <button type="button" onClick={() => meetingIcs(m.body || 'মিটিং', m.meeting_at!, roomName)} className={`mt-1 text-[12px] font-medium ${mine ? 'text-white/90' : 'text-violet-700'}`}>
          + ক্যালেন্ডারে রাখুন
        </button>
      )}
    </div>
  )
}

export default function RoomView({ roomId, initial, labels, reactions, me, onBack, onRead, onRoomsChanged, onLeft }: Props) {
  const chat = useChat()
  const [room, setRoom] = useState<Room | undefined>(initial)
  const [msgs, setMsgs] = useState<LocalMsg[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [hasMore, setHasMore] = useState(false)
  const [olderBusy, setOlderBusy] = useState(false)
  const [seen, setSeen] = useState<{ id: number; read: number }[]>([])
  const [typing, setTyping] = useState<string[]>([])
  const [pinnedCount, setPinnedCount] = useState(0)
  const [members, setMembers] = useState<Member[]>([])
  const [unreadFrom, setUnreadFrom] = useState<number | null>(null)
  const [newBelow, setNewBelow] = useState(0)
  const [replyTo, setReplyTo] = useState<LocalMsg | null>(null)
  const [editing, setEditing] = useState<LocalMsg | null>(null)
  const [menu, setMenu] = useState<LocalMsg | null>(null)
  const [picker, setPicker] = useState<number | null>(null)
  const [lightbox, setLightbox] = useState<ChatFile | null>(null)
  const [info, setInfo] = useState(false)
  const [find, setFind] = useState<{ open: boolean; q: string; label: string; pinned: boolean }>({ open: false, q: '', label: '', pinned: false })
  const [results, setResults] = useState<Message[] | null>(null)
  const [resBusy, setResBusy] = useState(false)
  const [flash, setFlash] = useState<number | null>(null)
  const [dropping, setDropping] = useState(false)
  const dropRef = useRef<(files: File[]) => void>(() => {})
  const { confirm, node: confirmNode } = useConfirm()

  const listRef = useRef<HTMLDivElement>(null)
  const lastId = useRef(0)
  const since = useRef(0)
  const atBottom = useRef(true)
  const stick = useRef<'bottom' | 'keep' | null>(null)
  const keepFrom = useRef(0)
  const readSent = useRef(0)
  const live = useRef(true)

  const memberName = useMemo(() => new Map(members.map((m) => [m.id, m.name])), [members])

  const computeLast = (list: LocalMsg[]) => {
    for (let i = list.length - 1; i >= 0; i--) if (list[i].id > 0) return list[i].id
    return 0
  }

  // ---- first load ----
  useEffect(() => {
    live.current = true
    setMsgs([])
    setLoading(true)
    setError('')
    setReplyTo(null)
    setEditing(null)
    setResults(null)
    setFind({ open: false, q: '', label: '', pinned: false })
    setInfo(false)
    lastId.current = 0
    readSent.current = 0
    chat.setActiveRoom(roomId)
    const unreadAtOpen = initial?.unread ?? 0
    ChatApi.messages(roomId)
      .then((r) => {
        if (!live.current) return
        setRoom(r.room)
        setMsgs(r.messages)
        setHasMore(r.has_more)
        setSeen(r.seen)
        setTyping(r.typing)
        setPinnedCount(r.pinned)
        since.current = r.now
        lastId.current = computeLast(r.messages)
        // "নতুন মেসেজ" line above the first one I haven't read
        if (unreadAtOpen > 0) {
          const others = r.messages.filter((m) => !m.mine && m.kind === 'text')
          const first = others[Math.max(0, others.length - unreadAtOpen)]
          setUnreadFrom(first ? first.id : null)
        } else setUnreadFrom(null)
        stick.current = 'bottom'
      })
      .catch((e: Error) => live.current && setError(e.message || 'লোড করা গেল না'))
      .finally(() => live.current && setLoading(false))
    ChatApi.members(roomId)
      .then((r) => live.current && setMembers(r.members))
      .catch(() => {})
    return () => {
      live.current = false
      chat.setActiveRoom(null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roomId])

  // ---- scroll keeping ----
  useLayoutEffect(() => {
    const el = listRef.current
    if (!el) return
    if (stick.current === 'bottom') {
      const line = unreadFrom ? el.querySelector<HTMLElement>('[data-unread-line]') : null
      if (line && line.offsetTop > el.clientHeight * 0.5) el.scrollTop = line.offsetTop - 60
      else el.scrollTop = el.scrollHeight
    } else if (stick.current === 'keep') {
      el.scrollTop = el.scrollHeight - keepFrom.current
    }
    stick.current = null
  }, [msgs, unreadFrom])

  const toBottom = (smooth = true) => {
    const el = listRef.current
    if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' })
    setNewBelow(0)
  }

  // ---- read marks ----
  const markRead = useCallback(() => {
    const id = lastId.current
    if (!id || id <= readSent.current || document.hidden || !atBottom.current) return
    readSent.current = id
    ChatApi.read(roomId, id)
      .then(() => {
        onRead(roomId)
        chat.refresh()
      })
      .catch(() => (readSent.current = 0))
  }, [roomId, onRead, chat])

  useEffect(() => {
    if (!loading) markRead()
  }, [loading, msgs.length, markRead])

  // ---- merging new / changed messages ----
  const merge = useCallback((add: Message[], changed: Message[] = [], gone: number[] = []) => {
    setMsgs((prev) => {
      let list = prev
      if (changed.length) {
        const map = new Map(changed.map((c) => [c.id, c]))
        list = list.map((x) => map.get(x.id) ?? x)
      }
      if (gone.length) list = list.filter((x) => !gone.includes(x.id))
      const have = new Set(list.map((x) => x.id))
      const fresh = add.filter((x) => !have.has(x.id))
      if (fresh.length) {
        // a reply from the server replaces my own "sending…" copy
        const mineIds = new Set(fresh.filter((f) => f.mine).map((f) => f.body + '|' + f.files.length))
        list = list.filter((x) => !(x.pending && !x.failed && mineIds.has(x.body + '|' + x.files.length)))
        list = [...list, ...fresh]
        if (atBottom.current) stick.current = 'bottom'
        else {
          const theirs = fresh.filter((f) => !f.mine).length
          if (theirs) setNewBelow((n) => n + theirs)
        }
      }
      lastId.current = Math.max(lastId.current, computeLast(list))
      return list
    })
  }, [])

  // ---- live updates (every 3 s while visible) ----
  useEffect(() => {
    if (loading || error) return
    let stop = false
    let t: ReturnType<typeof setTimeout>
    const tick = async () => {
      if (!document.hidden) {
        try {
          const r = await ChatApi.messages(roomId, lastId.current ? { after: lastId.current, changed_since: since.current } : {})
          if (stop) return
          since.current = r.now
          setRoom(r.room)
          setSeen(r.seen)
          setTyping(r.typing)
          setPinnedCount(r.pinned)
          if (lastId.current) merge(r.messages, r.changed, r.gone)
          else if (r.messages.length) merge(r.messages)
        } catch {
          // keep trying quietly
        }
      }
      if (!stop) t = setTimeout(tick, document.hidden ? 15000 : 3000)
    }
    t = setTimeout(tick, 3000)
    return () => {
      stop = true
      clearTimeout(t)
    }
  }, [roomId, loading, error, merge])

  // ---- older messages ----
  const loadOlder = useCallback(async () => {
    if (olderBusy || !hasMore) return false
    const first = msgs.find((m) => m.id > 0)
    if (!first) return false
    setOlderBusy(true)
    try {
      const r = await ChatApi.messages(roomId, { before: first.id })
      const el = listRef.current
      keepFrom.current = el ? el.scrollHeight - el.scrollTop : 0
      stick.current = 'keep'
      setMsgs((prev) => {
        const have = new Set(prev.map((x) => x.id))
        return [...r.messages.filter((m) => !have.has(m.id)), ...prev]
      })
      setHasMore(r.has_more)
      return r.has_more
    } catch {
      return false
    } finally {
      setOlderBusy(false)
    }
  }, [olderBusy, hasMore, msgs, roomId])

  const onScroll = () => {
    const el = listRef.current
    if (!el) return
    atBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 90
    if (atBottom.current) {
      if (newBelow) setNewBelow(0)
      markRead()
    }
    if (el.scrollTop < 80 && hasMore && !olderBusy) void loadOlder()
  }

  useEffect(() => {
    const v = () => !document.hidden && markRead()
    document.addEventListener('visibilitychange', v)
    return () => document.removeEventListener('visibilitychange', v)
  }, [markRead])

  // ---- jump to a message (reply quote, search result, pinned) ----
  const msgsRef = useRef(msgs)
  msgsRef.current = msgs
  const hasMoreRef = useRef(hasMore)
  hasMoreRef.current = hasMore
  const jumpTo = async (id: number) => {
    for (let i = 0; i < 20 && !msgsRef.current.some((m) => m.id === id); i++) {
      const first = msgsRef.current.find((m) => m.id > 0)
      if (!first || !hasMoreRef.current) break
      const r = await ChatApi.messages(roomId, { before: first.id }).catch(() => null)
      if (!r) break
      setMsgs((prev) => [...r.messages.filter((m) => !prev.some((p) => p.id === m.id)), ...prev])
      setHasMore(r.has_more)
      hasMoreRef.current = r.has_more
      msgsRef.current = [...r.messages, ...msgsRef.current]
      await new Promise((ok) => setTimeout(ok, 30))
    }
    setTimeout(() => {
      const el = listRef.current?.querySelector<HTMLElement>(`[data-mid="${id}"]`)
      if (el) {
        el.scrollIntoView({ block: 'center', behavior: 'smooth' })
        setFlash(id)
        setTimeout(() => setFlash(null), 1800)
      }
    }, 60)
  }

  // ---- sending ----
  const send = async (input: { body: string; label: LabelKey | ''; files: ChatFile[]; mentions: number[]; meeting_at: string }) => {
    if (editing) {
      const m = editing
      setEditing(null)
      try {
        const r = await ChatApi.edit(m.id, input.body, input.label)
        merge([], [r])
      } catch (e) {
        alert((e as Error).message)
      }
      return
    }
    const tempId = -Date.now()
    const reply = replyTo
    setReplyTo(null)
    const temp: LocalMsg = {
      id: tempId,
      tempId,
      kind: 'text',
      author: null,
      mine: true,
      body: input.body,
      label: input.label,
      meeting_at: input.meeting_at || null,
      reply: reply ? { id: reply.id, author: reply.author?.name ?? '', text: reply.body || '📎 ফাইল' } : null,
      files: input.files,
      reactions: [],
      pinned: false,
      mentions_me: false,
      edited: false,
      deleted: false,
      can_edit: false,
      can_delete: false,
      can_purge: false,
      at: new Date().toISOString(),
      pending: true,
    }
    atBottom.current = true
    stick.current = 'bottom'
    setUnreadFrom(null)
    setMsgs((p) => [...p, temp])
    const go = async () => {
      setMsgs((p) => p.map((x) => (x.tempId === tempId ? { ...x, failed: false, pending: true } : x)))
      try {
        const r = await ChatApi.send(roomId, {
          body: input.body,
          label: input.label,
          reply_to: reply?.id,
          file_ids: input.files.map((f) => f.id),
          mentions: input.mentions,
          meeting_at: input.meeting_at || undefined,
        })
        setMsgs((p) => {
          const rest = p.filter((x) => x.tempId !== tempId)
          return rest.some((x) => x.id === r.id) ? rest : [...rest, r]
        })
        lastId.current = Math.max(lastId.current, r.id)
        readSent.current = r.id
        onRoomsChanged()
      } catch {
        setMsgs((p) => p.map((x) => (x.tempId === tempId ? { ...x, failed: true, pending: true, retry: go } : x)))
      }
    }
    await go()
  }

  // ---- message actions ----
  const react = async (m: LocalMsg, emoji: string) => {
    setPicker(null)
    setMenu(null)
    if (m.id < 0) return
    // shows at once, the server answer then replaces it
    setMsgs((p) =>
      p.map((x) => {
        if (x.id !== m.id) return x
        const has = x.reactions.find((r) => r.emoji === emoji)
        let rx = x.reactions
        if (has?.mine) rx = rx.map((r) => (r.emoji === emoji ? { ...r, count: r.count - 1, mine: false } : r)).filter((r) => r.count > 0)
        else if (has) rx = rx.map((r) => (r.emoji === emoji ? { ...r, count: r.count + 1, mine: true } : r))
        else rx = [...rx, { emoji, count: 1, mine: true, names: ['আপনি'] }]
        return { ...x, reactions: rx }
      }),
    )
    try {
      merge([], [await ChatApi.react(m.id, emoji)])
    } catch {
      // the next refresh puts it right
    }
  }

  const doPin = async (m: LocalMsg) => {
    setMenu(null)
    try {
      const r = await ChatApi.pin(m.id)
      merge([], [r])
      setPinnedCount((n) => n + (r.pinned ? 1 : -1))
    } catch (e) {
      alert((e as Error).message)
    }
  }

  const doDelete = async (m: LocalMsg, purge = false) => {
    setMenu(null)
    const ok = await confirm(
      purge ? 'মেসেজটি রেকর্ড থেকে চিরতরে মুছে যাবে, ফাইলসহ। আর ফিরিয়ে আনা যাবে না।' : 'মেসেজটি মুছবেন? সবাই দেখবেন “মেসেজটি মুছে ফেলা হয়েছে”।',
      purge ? 'চিরতরে মুছুন' : 'মুছুন',
    )
    if (!ok) return
    try {
      const r = await ChatApi.remove(m.id, purge)
      if ('purged' in r) setMsgs((p) => p.filter((x) => x.id !== m.id))
      else merge([], [r])
    } catch (e) {
      alert((e as Error).message)
    }
  }

  const copy = (m: LocalMsg) => {
    setMenu(null)
    void navigator.clipboard?.writeText(m.body).catch(() => {})
  }

  // ---- search / filter ----
  useEffect(() => {
    if (!find.open || (!find.q.trim() && !find.label && !find.pinned)) {
      setResults(null)
      return
    }
    setResBusy(true)
    const t = setTimeout(() => {
      ChatApi.messages(roomId, { q: find.q.trim() || undefined, label: find.label || undefined, pinned: find.pinned })
        .then((r) => setResults([...r.messages].reverse()))
        .catch(() => setResults([]))
        .finally(() => setResBusy(false))
    }, 300)
    return () => clearTimeout(t)
  }, [find, roomId])

  // ---- drag & drop files onto the room ----
  const onDrop = (e: React.DragEvent) => {
    e.preventDefault()
    setDropping(false)
    const files = Array.from(e.dataTransfer.files || [])
    if (files.length) dropRef.current(files)
  }

  // ---- render helpers ----
  const lastMine = useMemo(() => {
    for (let i = msgs.length - 1; i >= 0; i--) if (msgs[i].mine && msgs[i].id > 0 && msgs[i].kind === 'text') return msgs[i].id
    return 0
  }, [msgs])
  const seenNames = (id: number) =>
    seen
      .filter((s) => s.read >= id && s.id !== me.id)
      .map((s) => memberName.get(s.id))
      .filter(Boolean) as string[]

  // phones: hold a message for the menu, swipe it right to reply
  const touch = isTouch()
  const long = useRef<ReturnType<typeof setTimeout> | null>(null)
  const swipe = useRef<{ x: number; y: number } | null>(null)
  const touchStart = (m: LocalMsg, e: React.TouchEvent) => {
    if (m.kind !== 'text' || m.deleted || m.id < 0) return
    swipe.current = { x: e.touches[0].clientX, y: e.touches[0].clientY }
    long.current = setTimeout(() => {
      swipe.current = null
      navigator.vibrate?.(15)
      setMenu(m)
    }, 450)
  }
  const touchMove = () => {
    if (long.current) clearTimeout(long.current)
  }
  const touchEnd = (m: LocalMsg, e: React.TouchEvent) => {
    if (long.current) clearTimeout(long.current)
    const s0 = swipe.current
    swipe.current = null
    if (!s0) return
    const dx = e.changedTouches[0].clientX - s0.x
    const dy = Math.abs(e.changedTouches[0].clientY - s0.y)
    if (dx > 70 && dy < 40) {
      navigator.vibrate?.(10)
      setReplyTo(m)
    }
  }

  const showResults = find.open && (!!results || resBusy)
  const headRef = useRef<HTMLDivElement>(null)
  const [findTop, setFindTop] = useState(110)
  useLayoutEffect(() => {
    if (headRef.current) setFindTop(headRef.current.offsetHeight)
  }, [find.open, showResults])
  const title = room?.name ?? initial?.name ?? ''
  const isDesk = room?.kind === 'desk'
  const sub = isDesk
    ? room?.about
    : typing.length
      ? ''
      : room
        ? `${bnNum(room.members)} জন সদস্য${members.filter((m) => m.online).length > 1 ? ` · ${bnNum(members.filter((m) => m.online && m.id !== me.id).length)} জন অনলাইনে` : ''}`
        : ''

  const rows: ReactNode[] = []
  let prev: LocalMsg | null = null
  for (const m of msgs) {
    if (!prev || dayKey(prev.at) !== dayKey(m.at)) {
      rows.push(
        <div key={`d${m.id}`} className="sticky top-1 z-[1] flex justify-center my-3 pointer-events-none">
          <span className="rounded-full bg-white/90 backdrop-blur px-3 py-1 text-[11.5px] font-medium text-neutral-500 shadow-sm ring-1 ring-black/5">{dayLabel(m.at)}</span>
        </div>,
      )
    }
    if (unreadFrom && m.id === unreadFrom) {
      rows.push(
        <div key="unread" data-unread-line className="flex items-center gap-2 my-3 px-2">
          <span className="flex-1 h-px bg-brand-200 bg-red-200" />
          <span className="text-[11.5px] font-semibold text-brand-600">নতুন মেসেজ</span>
          <span className="flex-1 h-px bg-red-200" />
        </div>,
      )
    }
    if (m.kind === 'system') {
      rows.push(
        <div key={m.id} data-mid={m.id} className="flex justify-center my-2 px-6">
          <span className="text-center text-[12px] text-neutral-500 bg-neutral-100 rounded-full px-3 py-1">{m.body}</span>
        </div>,
      )
      prev = m
      continue
    }
    const head = !prev || prev.kind === 'system' || prev.author?.id !== m.author?.id || prev.mine !== m.mine || new Date(m.at).getTime() - new Date(prev.at).getTime() > 5 * 60000 || dayKey(prev.at) !== dayKey(m.at)
    const mine = m.mine
    const media = !m.deleted && m.files.some((f) => f.kind === 'image' || f.kind === 'video')
    const urgent = m.label === 'urgent'
    const seenBy = mine && m.id === lastMine ? seenNames(m.id) : []
    const anySeen = mine && m.id > 0 && seen.some((s) => s.read >= m.id && s.id !== me.id)
    const bubble = m.deleted
      ? `${mine ? 'bg-neutral-100' : 'bg-white ring-1 ring-neutral-200'} text-neutral-400 italic`
      : mine
        ? `bg-brand-600 text-white ${urgent ? 'ring-2 ring-red-300' : ''}`
        : urgent
          ? 'bg-red-50 text-neutral-900 ring-2 ring-red-400'
          : m.mentions_me
            ? 'bg-amber-50 text-neutral-900 ring-1 ring-amber-300'
            : 'bg-white text-neutral-900 ring-1 ring-neutral-200/90'
    rows.push(
      <div
        key={m.tempId ?? m.id}
        data-mid={m.id}
        className={`group flex gap-2 px-3 ${head ? 'mt-3' : 'mt-0.5'} ${mine ? 'flex-row-reverse' : ''} ${flash === m.id ? 'animate-pulse' : ''}`}
      >
        {!mine && <div className="w-8 shrink-0">{head && m.author && <Avatar p={m.author} size={32} />}</div>}
        <div className={`min-w-0 ${mine ? 'max-w-[86%] items-end' : 'max-w-[calc(100%-40px)] items-start'} sm:max-w-[75%] flex flex-col`}>
          {head && !mine && m.author && (
            <div className="flex items-baseline gap-1.5 px-1 mb-0.5">
              <span className="text-[12.5px] font-semibold text-neutral-800">{m.author.name}</span>
              <span className="text-[11px] text-neutral-400">
                {m.author.role}
                {m.author.area ? ` · ${m.author.area}` : ''}
              </span>
            </div>
          )}
          <div className={`relative max-w-full ${media ? 'w-[300px]' : ''}`}>
            <div
              className={`rounded-2xl px-3 py-2 ${bubble} ${mine ? (head ? 'rounded-tr-md' : '') : head ? 'rounded-tl-md' : ''} ${m.pending && !m.failed ? 'opacity-70' : ''}`}
              onContextMenu={(e) => {
                if (m.kind !== 'text' || m.deleted || m.id < 0) return
                e.preventDefault()
                setMenu(m)
              }}
              onTouchStart={(e) => touchStart(m, e)}
              onTouchEnd={(e) => touchEnd(m, e)}
              onTouchMove={touchMove}
            >
              {m.deleted ? (
                <span className="text-[13px]">🚫 মেসেজটি মুছে ফেলা হয়েছে</span>
              ) : (
                <>
                  {(m.label || m.pinned) && (
                    <div className="flex items-center gap-1.5 mb-1">
                      {m.label && <LabelChip label={m.label} labels={labels} small />}
                      {m.pinned && <span className={`text-[10.5px] ${mine ? 'text-white/80' : 'text-neutral-500'}`}>📌 পিন করা</span>}
                    </div>
                  )}
                  {m.reply && (
                    <button
                      type="button"
                      onClick={() => jumpTo(m.reply!.id)}
                      className={`block w-full text-left mb-1.5 rounded-lg border-l-[3px] px-2 py-1 ${mine ? 'bg-white/15 border-white/60' : 'bg-neutral-100 border-brand-500'}`}
                    >
                      <span className={`block text-[11.5px] font-semibold ${mine ? 'text-white' : 'text-brand-700'}`}>{m.reply.author}</span>
                      <span className={`block text-[12px] line-clamp-2 ${mine ? 'text-white/85' : 'text-neutral-600'}`}>{m.reply.text}</span>
                    </button>
                  )}
                  {m.files.length > 0 && (
                    <div className={m.body ? 'mb-1.5' : ''}>
                      <Files files={m.files} mine={mine} onImage={setLightbox} />
                    </div>
                  )}
                  {m.body && (
                    <div className="text-[14.5px] leading-[1.55] whitespace-pre-wrap break-words">
                      <RichText text={m.body} mine={mine} />
                    </div>
                  )}
                  {m.label === 'meeting' && <MeetingCard m={m} roomName={title} mine={mine} />}
                </>
              )}
              <div className={`flex items-center justify-end gap-1 mt-0.5 text-[10.5px] ${mine && !m.deleted ? 'text-white/75' : 'text-neutral-400'}`}>
                {m.edited && !m.deleted && <span>সম্পাদিত ·</span>}
                <span title={fullWhen(m.at)}>{clock(m.at)}</span>
                {mine && !m.deleted && (m.failed ? null : m.pending ? <span>🕓</span> : <span className={anySeen ? 'text-sky-200 font-bold' : ''}>{anySeen ? '✓✓' : '✓'}</span>)}
              </div>
            </div>
            {/* quick actions (hover on a computer; ⋯ on phones) */}
            {m.id > 0 && !m.deleted && (
              <div className={`absolute top-1/2 -translate-y-1/2 ${mine ? 'right-full mr-1' : 'left-full ml-1'} ${touch ? 'hidden' : 'flex'} items-center gap-0.5 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity`}>
                <button type="button" onClick={() => setPicker(picker === m.id ? null : m.id)} className="w-7 h-7 rounded-full hover:bg-neutral-200/70 text-[15px]" title="প্রতিক্রিয়া" aria-label="প্রতিক্রিয়া">
                  😊
                </button>
                <button type="button" onClick={() => setReplyTo(m)} className="w-7 h-7 rounded-full hover:bg-neutral-200/70 text-[14px] text-neutral-500" title="উত্তর" aria-label="উত্তর">
                  ↩
                </button>
                <button type="button" onClick={() => setMenu(m)} className="w-7 h-7 rounded-full hover:bg-neutral-200/70 text-[15px] text-neutral-500" title="আরও" aria-label="আরও" data-testid="msg-more">
                  ⋯
                </button>
              </div>
            )}
            {picker === m.id && (
              <div className={`absolute z-10 -top-11 ${mine ? 'right-0' : 'left-0'} flex gap-0.5 rounded-full bg-white shadow-lg ring-1 ring-black/5 px-1.5 py-1`}>
                {reactions.map((e) => (
                  <button key={e} type="button" onClick={() => react(m, e)} className="w-8 h-8 rounded-full hover:bg-neutral-100 text-[18px] hover:scale-125 transition-transform">
                    {e}
                  </button>
                ))}
              </div>
            )}
          </div>
          {m.failed && (
            <button type="button" onClick={() => m.retry?.()} className="mt-0.5 text-[11.5px] text-red-600 font-medium">
              ⚠️ পাঠানো যায়নি · আবার চেষ্টা করুন
            </button>
          )}
          {m.reactions.length > 0 && (
            <div className={`flex flex-wrap gap-1 -mt-1.5 px-2 relative ${mine ? 'justify-end' : ''}`}>
              {m.reactions.map((r) => (
                <button
                  key={r.emoji}
                  type="button"
                  title={r.names.join(', ')}
                  onClick={() => react(m, r.emoji)}
                  className={`inline-flex items-center gap-1 rounded-full px-1.5 h-6 text-[12px] shadow-sm ring-1 ${r.mine ? 'bg-brand-50 ring-brand-300 text-brand-700' : 'bg-white ring-neutral-200 text-neutral-600'}`}
                >
                  <span className="text-[13px]">{r.emoji}</span>
                  {r.count > 1 && <span>{bnNum(r.count)}</span>}
                </button>
              ))}
            </div>
          )}
          {seenBy.length > 0 && (
            <div className="mt-0.5 px-1 text-[10.5px] text-neutral-400" data-testid="seen-by">
              দেখেছেন: {seenBy.slice(0, 3).join(', ')}
              {seenBy.length > 3 ? ` +${bnNum(seenBy.length - 3)}` : ''}
            </div>
          )}
        </div>
      </div>,
    )
    prev = m
  }

  return (
    <div
      className="relative flex flex-col h-full min-h-0 bg-[#f6f3ef]"
      data-testid="room-view"
      onDragOver={(e) => {
        if (room?.can_post && Array.from(e.dataTransfer.types).includes('Files')) {
          e.preventDefault()
          setDropping(true)
        }
      }}
      onDragLeave={(e) => e.currentTarget === e.target && setDropping(false)}
      onDrop={onDrop}
    >
      <div ref={headRef} className="shrink-0">
      {/* header */}
      <div className="flex items-center gap-2.5 px-2 sm:px-3 py-2 bg-white border-b border-neutral-200/80">
        {onBack && (
          <button type="button" onClick={onBack} className="w-9 h-9 rounded-full hover:bg-neutral-100 text-[20px] text-neutral-600 shrink-0" aria-label="পেছনে" data-testid="room-back">
            ←
          </button>
        )}
        <button type="button" onClick={() => setInfo(true)} className="flex items-center gap-2.5 min-w-0 flex-1 text-left" data-testid="room-title">
          <RoomIcon icon={room?.icon ?? '💬'} size={40} online={room?.online} avatar={isDesk && room && !room.name.startsWith('ডেস্ক') ? { id: roomId, name: room.name, avatar: room.avatar } : undefined} />
          <span className="min-w-0">
            <span className="flex items-center gap-1.5">
              <span className="block truncate text-[15.5px] font-bold text-neutral-900">{title}</span>
              {room?.muted && <span title="নিঃশব্দ">🔕</span>}
              {room?.archived && <span className="text-[11px] rounded bg-neutral-200 px-1.5">আর্কাইভ</span>}
            </span>
            <span className="block truncate text-[12px] text-neutral-500">
              {typing.length ? <span className="text-emerald-600 font-medium">{typing.slice(0, 2).join(', ')} লিখছেন…</span> : sub}
            </span>
          </span>
        </button>
        <button
          type="button"
          onClick={() => setFind((f) => ({ ...f, open: !f.open, q: '', label: '', pinned: false }))}
          className={`w-9 h-9 rounded-full text-[16px] shrink-0 ${find.open ? 'bg-brand-50 text-brand-700' : 'hover:bg-neutral-100 text-neutral-600'}`}
          title="খুঁজুন ও লেবেল"
          aria-label="খুঁজুন"
          data-testid="room-search"
        >
          🔍
        </button>
        <button type="button" onClick={() => setInfo(true)} className="w-9 h-9 rounded-full hover:bg-neutral-100 text-[17px] text-neutral-600 shrink-0" title="রুমের তথ্য" aria-label="রুমের তথ্য" data-testid="room-info-btn">
          ⓘ
        </button>
      </div>

      {/* search + label filter */}
      {find.open && (
        <div className="bg-white border-b border-neutral-200/80 px-3 py-2 space-y-2" data-testid="find-bar">
          <input
            autoFocus
            value={find.q}
            onChange={(e) => setFind((f) => ({ ...f, q: e.target.value }))}
            placeholder="এই রুমে খুঁজুন…"
            className="w-full rounded-xl bg-neutral-100 px-3 py-2 text-[14px] outline-none focus:ring-2 focus:ring-brand-200"
          />
          <div className="flex gap-1.5 overflow-x-auto pb-0.5 -mx-1 px-1">
            <button
              type="button"
              onClick={() => setFind((f) => ({ ...f, pinned: !f.pinned }))}
              className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[12px] font-semibold whitespace-nowrap ${find.pinned ? 'bg-neutral-800 text-white border-neutral-800' : 'bg-white text-neutral-600 border-neutral-300'}`}
            >
              📌 পিন করা
            </button>
            {labels
              .filter((l) => l.key !== 'general')
              .map((l) => (
                <LabelChip key={l.key} label={l.key} labels={labels} active={find.label === l.key ? undefined : false} onClick={() => setFind((f) => ({ ...f, label: f.label === l.key ? '' : l.key }))} />
              ))}
          </div>
        </div>
      )}

      </div>
      {/* pinned bar */}
      {!find.open && pinnedCount > 0 && (
        <button
          type="button"
          onClick={() => setFind({ open: true, q: '', label: '', pinned: true })}
          className="flex items-center gap-2 px-4 py-2 bg-amber-50 border-b border-amber-200/70 text-left text-[12.5px] text-amber-900"
          data-testid="pinned-bar"
        >
          📌 <span className="font-semibold">{bnNum(pinnedCount)}টি পিন করা মেসেজ</span>
          <span className="ml-auto text-amber-700">দেখুন →</span>
        </button>
      )}

      {/* messages */}
      <div ref={listRef} onScroll={onScroll} className="flex-1 min-h-0 overflow-y-auto overscroll-contain pb-3" data-testid="msg-list">
        {loading ? (
          <div className="h-full flex items-center justify-center">
            <Spinner className="w-7 h-7" />
          </div>
        ) : error ? (
          <div className="p-8 text-center text-[14px] text-red-600">{error}</div>
        ) : (
          <>
            {hasMore ? (
              <div className="flex justify-center py-3">{olderBusy ? <Spinner /> : <button type="button" onClick={() => void loadOlder()} className="text-[12.5px] text-brand-600 font-medium">আগের মেসেজ দেখুন</button>}</div>
            ) : (
              <div className="mx-auto max-w-[340px] mt-6 mb-2 px-4 text-center">
                <div className="mx-auto mb-2">
                  <RoomIcon icon={room?.icon ?? '💬'} size={56} />
                </div>
                <div className="text-[14px] font-semibold text-neutral-800">{title}</div>
                {room?.about && <div className="text-[12.5px] text-neutral-500 mt-0.5">{room.about}</div>}
                <div className="mt-2 text-[11.5px] text-neutral-400">🔒 শুধু মিহার সদস্যরা এই আলাপ দেখতে পান</div>
              </div>
            )}
            {rows}
          </>
        )}
      </div>

      {newBelow > 0 && (
        <button
          type="button"
          onClick={() => toBottom()}
          className="absolute right-4 bottom-[96px] z-[4] rounded-full bg-white shadow-lg ring-1 ring-black/5 px-3 py-2 text-[12.5px] font-semibold text-brand-700"
          data-testid="new-below"
        >
          ↓ {bnNum(newBelow)}টি নতুন
        </button>
      )}

      {dropping && (
        <div className="absolute inset-0 z-[6] m-2 rounded-2xl border-2 border-dashed border-brand-400 bg-brand-50/80 flex items-center justify-center text-[15px] font-semibold text-brand-700 pointer-events-none">
          এখানে ফাইল ছেড়ে দিন
        </div>
      )}

      {room && (
        <Composer
          room={room}
          labels={labels}
          members={members}
          me={me}
          replyTo={replyTo}
          editing={editing}
          onCancelReply={() => setReplyTo(null)}
          onCancelEdit={() => setEditing(null)}
          onSend={send}
          dropRef={dropRef}
        />
      )}

      {/* search results, over the messages */}
      {showResults && (
        <div className="absolute inset-x-0 bottom-0 z-[5] bg-white overflow-y-auto" style={{ top: findTop }} data-testid="find-results">
          {resBusy && !results ? (
            <div className="p-6 flex justify-center">
              <Spinner />
            </div>
          ) : (
            <>
              <div className="px-4 pt-3 pb-1 text-[12px] text-neutral-500">{results && results.length ? `${bnNum(results.length)}টি মেসেজ` : 'কিছু পাওয়া যায়নি'}</div>
              <div className="divide-y divide-neutral-100">
                {results?.map((m) => (
                  <button
                    key={m.id}
                    type="button"
                    onClick={() => {
                      setFind((f) => ({ ...f, open: false }))
                      setResults(null)
                      void jumpTo(m.id)
                    }}
                    className="w-full text-left flex gap-2.5 px-4 py-2.5 hover:bg-neutral-50"
                  >
                    {m.author && <Avatar p={m.author} size={32} />}
                    <span className="min-w-0 flex-1">
                      <span className="flex items-center gap-1.5 text-[12px]">
                        <b className="text-neutral-800">{m.mine ? 'আপনি' : m.author?.name}</b>
                        {m.label && <LabelChip label={m.label} labels={labels} small />}
                        <span className="ml-auto text-neutral-400">{fullWhen(m.at)}</span>
                      </span>
                      <span className="block text-[13.5px] text-neutral-700 line-clamp-2">{m.body || (m.files.length ? '📎 ফাইল' : '')}</span>
                    </span>
                  </button>
                ))}
              </div>
            </>
          )}
        </div>
      )}

      {/* message menu */}
      <Sheet open={!!menu} onClose={() => setMenu(null)}>
        {menu && (
          <div className="pb-2" data-testid="msg-menu">
            <div className="flex justify-center gap-1 px-4 pt-4 pb-3 border-b border-neutral-100">
              {reactions.map((e) => (
                <button key={e} type="button" onClick={() => react(menu, e)} className={`w-11 h-11 rounded-full text-[22px] ${menu.reactions.find((r) => r.emoji === e && r.mine) ? 'bg-brand-50 ring-1 ring-brand-300' : 'hover:bg-neutral-100'}`}>
                  {e}
                </button>
              ))}
            </div>
            {(
              [
                ['↩', 'উত্তর দিন', () => (setReplyTo(menu), setMenu(null)), true],
                ['📋', 'কপি করুন', () => copy(menu), !!menu.body],
                ['✏️', 'সম্পাদনা করুন', () => (setEditing(menu), setReplyTo(null), setMenu(null)), menu.can_edit],
                ['📌', menu.pinned ? 'পিন সরান' : 'পিন করুন (সবার উপরে থাকবে)', () => doPin(menu), !!room?.is_admin],
                ['🗑️', 'মুছুন', () => doDelete(menu), menu.can_delete],
                ['⛔', 'রেকর্ড থেকে চিরতরে মুছুন', () => doDelete(menu, true), menu.can_purge],
              ] as [string, string, () => void, boolean][]
            )
              .filter((x) => x[3])
              .map(([ic, label, fn]) => (
                <button key={label} type="button" onClick={fn} className={`w-full flex items-center gap-3 px-6 py-3 text-left text-[14.5px] hover:bg-neutral-50 ${ic === '⛔' || ic === '🗑️' ? 'text-red-600' : 'text-neutral-800'}`}>
                  <span className="w-6 text-center">{ic}</span>
                  {label}
                </button>
              ))}
            <div className="px-6 pt-1 text-[11.5px] text-neutral-400">
              {menu.mine ? 'আপনি' : menu.author?.name} · {fullWhen(menu.at)}
              {menu.label ? ` · ${LABEL_ICON[menu.label]} ${labels.find((l) => l.key === menu.label)?.name}` : ''}
            </div>
          </div>
        )}
      </Sheet>

      {/* picture viewer */}
      {lightbox && (
        <div className="fixed inset-0 z-[80] bg-black/90 flex flex-col" onClick={() => setLightbox(null)}>
          <div className="flex items-center gap-3 p-3 text-white text-[13px]">
            <span className="flex-1 truncate">{lightbox.name}</span>
            <a href={lightbox.download} download={lightbox.name} onClick={(e) => e.stopPropagation()} className="rounded-full bg-white/15 px-3 py-1.5">
              ডাউনলোড
            </a>
            <button type="button" className="text-[24px] leading-none px-2">
              ×
            </button>
          </div>
          <div className="flex-1 min-h-0 flex items-center justify-center p-3">
            <img src={lightbox.url} alt="" className="max-w-full max-h-full object-contain" />
          </div>
        </div>
      )}

      {info && room && (
        <RoomInfo
          room={room}
          me={me}
          onClose={() => setInfo(false)}
          onChanged={(r) => {
            if (r) setRoom(r)
            onRoomsChanged()
            ChatApi.members(roomId)
              .then((x) => setMembers(x.members))
              .catch(() => {})
          }}
          onLeft={onLeft}
          onShowPinned={() => {
            setInfo(false)
            setFind({ open: true, q: '', label: '', pinned: true })
          }}
        />
      )}
      {confirmNode}
    </div>
  )
}
