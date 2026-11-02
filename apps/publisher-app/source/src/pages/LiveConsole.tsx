/**
 * 1.10.0 — the live console for one live post. Left: write an update (kind, optional headline, text with photos,
 * source for documents / evidence, pin) and the running feed, where every update can be edited, pinned, approved
 * (when it came from a correspondent and needs checking) or deleted. Right (staff): live state, scoreboard and key
 * figures for a live event, or the whole case file for a crime-investigation live, plus the correspondents who
 * may send updates. The feed refreshes itself every 20 seconds so several people can work on one live post.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  CorrespondentsApi,
  LiveApi,
  describeError,
  type Correspondent,
  type LiveConfig,
  type LiveDetail,
  type LiveEntry,
  type LiveEntryInput,
  type LiveKind,
  type LiveStatus,
  type LibraryImage,
} from '../lib/api'
import RichEditor from '../components/RichEditor'
import MediaGallery from '../components/MediaGallery'
import { Badge, Banner, Button, Card, Field, Spinner, bnNumber, inputClass } from '../components/ui'
import { IconTrash, IconPencil, IconX, IconPlus, IconCheck } from '../components/Icon'
import { LIVE_TYPE, LiveBadge, ViewOnSite, liveAgo } from './Live'

const POLL_MS = 20_000

export function liveClock(ts: number) {
  const d = new Date(ts * 1000)
  const h = d.getHours()
  const part = h < 5 ? 'রাত' : h < 12 ? 'সকাল' : h < 15 ? 'দুপুর' : h < 18 ? 'বিকেল' : h < 20 ? 'সন্ধ্যা' : 'রাত'
  const hh = h % 12 || 12
  const mm = String(d.getMinutes()).padStart(2, '0')
  const today = new Date().toDateString() === d.toDateString()
  const day = today ? '' : `${bnNumber(d.getDate())}/${bnNumber(d.getMonth() + 1)} · `
  return `${day}${part} ${bnNumber(hh)}:${bnNumber(mm)}`
}

const KIND_STYLE: Record<LiveKind, string> = {
  update: 'bg-neutral-100 text-neutral-700',
  key: 'bg-amber-300 text-[#0B2A6B]',
  breaking: 'bg-red-600 text-white',
  statement: 'bg-indigo-100 text-indigo-800',
  score: 'bg-emerald-100 text-emerald-800',
  document: 'bg-[#0B2A6B] text-white',
  evidence: 'bg-teal-700 text-white',
  milestone: 'bg-amber-300 text-[#0B2A6B]',
}

// ---------------------------------------------------------------------------

export function LiveComposer({
  detail,
  editing,
  onDone,
  onCancel,
}: {
  detail: LiveDetail
  editing?: LiveEntry | null
  onDone: (e: LiveEntry) => void
  onCancel?: () => void
}) {
  const type = detail.config.type
  const kinds = detail.kinds.filter((k) => k.for === 'both' || k.for === type)
  const [kind, setKind] = useState<LiveKind>(editing?.kind ?? 'update')
  const [title, setTitle] = useState(editing?.title ?? '')
  const [content, setContent] = useState(editing?.content ?? '')
  const [source, setSource] = useState(editing?.source ?? '')
  const [pinned, setPinned] = useState(editing?.pinned ?? false)
  const [editorKey, setEditorKey] = useState(0)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const staff = detail.can_manage
  const isDoc = kind === 'document' || kind === 'evidence'

  async function submit() {
    setError('')
    const text = content.replace(/<[^>]*>/g, '').trim()
    if (!text && !title.trim() && !content.includes('<img')) {
      setError('আপডেটে কিছু লিখুন।')
      return
    }
    setSaving(true)
    const body: LiveEntryInput = { kind, title: title.trim(), content, source: isDoc ? source.trim() : '' }
    if (staff) body.pinned = pinned
    try {
      const e = editing ? await LiveApi.editEntry(detail.post.id, editing.id, body) : await LiveApi.addEntry(detail.post.id, body)
      onDone(e)
      if (!editing) {
        setTitle('')
        setContent('')
        setSource('')
        setPinned(false)
        setKind('update')
        setEditorKey((k) => k + 1)
      }
    } catch (err) {
      setError(describeError(err).message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label="আপডেটের ধরন">
        {kinds.map((k) => (
          <button
            key={k.id}
            type="button"
            role="radio"
            aria-checked={kind === k.id}
            onClick={() => setKind(k.id)}
            className={`rounded-full px-3 py-1 text-[12.5px] font-semibold border transition ${kind === k.id ? `${KIND_STYLE[k.id]} border-transparent ring-2 ring-offset-1 ring-brand-600/30` : 'border-neutral-200 text-neutral-600 bg-white hover:border-neutral-300'}`}
          >
            {k.label}
          </button>
        ))}
      </div>
      <input className={`${inputClass} text-[15px] font-semibold`} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="শিরোনাম (ঐচ্ছিক) — যেমন: উইকেট! মিরাজ আউট" maxLength={200} />
      <RichEditor key={`${editing?.id ?? 'new'}-${editorKey}`} content={content} onChange={setContent} placeholder="কী ঘটছে? ছবিও দিতে পারেন…" />
      {isDoc && (
        <input className={inputClass} value={source} onChange={(e) => setSource(e.target.value)} placeholder="সূত্র — যেমন: থানার এজাহার, ময়নাতদন্ত প্রতিবেদন, সিসিটিভি ফুটেজ" maxLength={120} />
      )}
      {error && <Banner kind="error">{error}</Banner>}
      <div className="flex flex-wrap items-center justify-between gap-2">
        {staff ? (
          <label className="inline-flex items-center gap-2 text-[13px] text-neutral-700">
            <input type="checkbox" checked={pinned} onChange={(e) => setPinned(e.target.checked)} className="w-4 h-4 accent-brand-600" />
            ওপরে পিন করুন
          </label>
        ) : (
          <span className="text-[12px] text-neutral-500">{detail.config.moderate ? 'সম্পাদক যাচাই করার পর প্রকাশ হবে।' : 'পাঠানোর সঙ্গে সঙ্গে প্রকাশ হবে।'}</span>
        )}
        <div className="flex gap-2">
          {onCancel && (
            <Button variant="secondary" onClick={onCancel}>
              বাতিল
            </Button>
          )}
          <Button onClick={submit} disabled={saving}>
            {saving ? 'পাঠানো হচ্ছে…' : editing ? 'সংরক্ষণ' : staff || !detail.config.moderate ? 'আপডেট প্রকাশ করুন' : 'যাচাইয়ের জন্য পাঠান'}
          </Button>
        </div>
      </div>
    </div>
  )
}

export function LiveEntryCard({
  e,
  detail,
  onChanged,
  onRemoved,
}: {
  e: LiveEntry
  detail: LiveDetail
  onChanged: (e: LiveEntry) => void
  onRemoved: (id: number) => void
}) {
  const [editing, setEditing] = useState(false)
  const [busy, setBusy] = useState(false)
  const [confirm, setConfirm] = useState(false)
  const [error, setError] = useState('')
  const label = detail.kinds.find((k) => k.id === e.kind)?.label ?? 'আপডেট'
  const staff = detail.can_manage

  async function patch(body: LiveEntryInput) {
    setBusy(true)
    setError('')
    try {
      onChanged(await LiveApi.editEntry(detail.post.id, e.id, body))
    } catch (err) {
      setError(describeError(err).message)
    } finally {
      setBusy(false)
    }
  }
  async function remove() {
    setBusy(true)
    try {
      await LiveApi.removeEntry(detail.post.id, e.id)
      onRemoved(e.id)
    } catch (err) {
      setError(describeError(err).message)
      setBusy(false)
    }
  }

  if (editing) {
    return (
      <Card className="p-4 border-brand-500/60">
        <LiveComposer
          detail={detail}
          editing={e}
          onDone={(n) => {
            onChanged(n)
            setEditing(false)
          }}
          onCancel={() => setEditing(false)}
        />
      </Card>
    )
  }

  return (
    <div className={`relative pl-5 pb-5 border-l-2 ${e.status === 'pending' ? 'border-amber-300' : 'border-neutral-200'}`}>
      <span className={`absolute -left-[7px] top-1.5 w-3 h-3 rounded-full border-2 bg-white ${e.kind === 'breaking' ? 'border-red-600 bg-red-600' : ['key', 'milestone'].includes(e.kind) ? 'border-amber-400 bg-amber-400' : 'border-brand-600'}`} />
      <div className="flex flex-wrap items-center gap-1.5 text-[12px] text-neutral-500">
        <span className="font-semibold text-neutral-800">{liveClock(e.time)}</span>
        <span>· {liveAgo(e.time)}</span>
        <span>· {e.author.name}</span>
        {e.kind !== 'update' && <span className={`rounded px-1.5 py-0.5 text-[11px] font-bold ${KIND_STYLE[e.kind]}`}>{label}</span>}
        {e.pinned && <Badge tone="blue">পিন করা</Badge>}
        {e.status === 'pending' && <Badge tone="amber">অনুমোদনের অপেক্ষায়</Badge>}
      </div>
      <div className={`mt-1.5 rounded-xl p-3 ${e.status === 'pending' ? 'bg-amber-50' : 'bg-white border border-neutral-100'}`}>
        {e.title && <p className="font-bold text-[15px] text-neutral-900 mb-1">{e.title}</p>}
        <div className="prose prose-sm max-w-none text-[14px] text-neutral-800 [&_img]:rounded-lg [&_img]:max-h-72" dangerouslySetInnerHTML={{ __html: e.content }} />
        {e.source && <p className="text-[12px] text-neutral-500 mt-2">সূত্র: {e.source}</p>}
      </div>
      {error && <p className="text-[12px] text-red-600 mt-1">{error}</p>}
      {e.can_edit && (
        <div className="flex flex-wrap gap-1 mt-1.5">
          {staff && e.status === 'pending' && (
            <Button size="sm" variant="success" disabled={busy} onClick={() => patch({ status: 'publish' })}>
              <IconCheck className="w-3.5 h-3.5" /> অনুমোদন ও প্রকাশ
            </Button>
          )}
          <Button size="sm" variant="ghost" disabled={busy} onClick={() => setEditing(true)}>
            <IconPencil className="w-3.5 h-3.5" /> সম্পাদনা
          </Button>
          {staff && (
            <Button size="sm" variant="ghost" disabled={busy} onClick={() => patch({ pinned: !e.pinned })}>
              {e.pinned ? 'পিন সরান' : 'পিন করুন'}
            </Button>
          )}
          {confirm ? (
            <>
              <Button size="sm" variant="destructive" disabled={busy} onClick={remove}>
                নিশ্চিত — মুছুন
              </Button>
              <Button size="sm" variant="secondary" onClick={() => setConfirm(false)}>
                না
              </Button>
            </>
          ) : (
            <Button size="sm" variant="danger" disabled={busy} onClick={() => setConfirm(true)}>
              <IconTrash className="w-3.5 h-3.5" /> মুছুন
            </Button>
          )}
        </div>
      )}
    </div>
  )
}

/** Feed with "all / waiting for approval" filter. */
export function LiveFeed({ detail, setDetail }: { detail: LiveDetail; setDetail: (fn: (d: LiveDetail) => LiveDetail) => void }) {
  const [filter, setFilter] = useState<'all' | 'pending' | 'mine'>('all')
  const pending = detail.entries.filter((e) => e.status === 'pending').length
  const list = detail.entries.filter((e) => (filter === 'pending' ? e.status === 'pending' : filter === 'mine' ? e.author.id === detail.me : true))
  const replace = (n: LiveEntry) =>
    setDetail((d) => ({ ...d, entries: d.entries.map((x) => (x.id === n.id ? n : x)).sort((a, b) => b.time - a.time || b.id - a.id) }))
  const drop = (id: number) => setDetail((d) => ({ ...d, entries: d.entries.filter((x) => x.id !== id) }))
  return (
    <div>
      <div className="flex items-center justify-between gap-2 mb-3">
        <h2 className="font-bold text-[15px] text-neutral-900">আপডেট ({bnNumber(detail.entries.length)})</h2>
        <div className="flex gap-1 text-[12px]">
          {(
            [
              ['all', 'সব'],
              ['pending', `অপেক্ষমাণ${pending ? ` (${bnNumber(pending)})` : ''}`],
              ['mine', 'আমার'],
            ] as const
          ).map(([k, l]) => (
            <button key={k} type="button" onClick={() => setFilter(k)} className={`rounded-full px-2.5 py-1 font-semibold ${filter === k ? 'bg-[#0B2A6B] text-white' : 'bg-neutral-100 text-neutral-600'}`}>
              {l}
            </button>
          ))}
        </div>
      </div>
      {list.length === 0 ? (
        <p className="text-[13px] text-neutral-500 py-6 text-center">কোনো আপডেট নেই।</p>
      ) : (
        <div className="ml-1.5">
          {list.map((e) => (
            <LiveEntryCard key={`${e.id}-${e.modified}`} e={e} detail={detail} onChanged={replace} onRemoved={drop} />
          ))}
        </div>
      )}
    </div>
  )
}

/** Loads one live post and keeps it fresh. */
export function useLiveDetail(id: number) {
  const [detail, setDetailState] = useState<LiveDetail | null>(null)
  const [error, setError] = useState('')
  const busy = useRef(false)
  const reload = useCallback(
    async (quiet = false) => {
      if (busy.current) return
      busy.current = true
      try {
        const d = await LiveApi.get(id)
        setDetailState(d)
        setError('')
      } catch (e) {
        if (!quiet) setError(describeError(e).message)
      } finally {
        busy.current = false
      }
    },
    [id],
  )
  useEffect(() => {
    reload()
    const t = setInterval(() => {
      if (!document.hidden) reload(true)
    }, POLL_MS)
    return () => clearInterval(t)
  }, [reload])
  const setDetail = useCallback((fn: (d: LiveDetail) => LiveDetail) => setDetailState((d) => (d ? fn(d) : d)), [])
  return { detail, error, reload, setDetail, replaceDetail: setDetailState }
}

// ---------------------------------------------------------------------------

export default function LiveConsole() {
  const { id } = useParams()
  const postId = Number(id)
  const { detail, error, setDetail, replaceDetail } = useLiveDetail(postId)

  if (error && !detail) return <Banner kind="error">{error}</Banner>
  if (!detail) return <Spinner className="mt-10" />

  const added = (e: LiveEntry) => setDetail((d) => ({ ...d, entries: [e, ...d.entries.filter((x) => x.id !== e.id)] }))

  return (
    <div className="max-w-6xl">
      <div className="mb-4">
        <Link to="/app/live" className="text-[12.5px] text-neutral-500 hover:text-brand-600">
          ← সব লাইভ
        </Link>
        <div className="flex flex-wrap items-center gap-2 mt-1">
          <LiveBadge status={detail.config.status} />
          <Badge tone="blue">{LIVE_TYPE[detail.config.type]}</Badge>
          <ViewOnSite href={detail.post.link} />
          <Link to={`/app/write/${detail.post.id}`} className="text-[12.5px] font-semibold text-brand-600 hover:underline">
            মূল পোস্ট সম্পাদনা
          </Link>
        </div>
        <h1 className="text-[20px] font-bold text-neutral-900 mt-1 leading-snug">{detail.post.title}</h1>
      </div>
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px] items-start">
        <div className="space-y-4 min-w-0">
          <Card className="p-4">
            <h2 className="font-bold text-[15px] text-neutral-900 mb-3">নতুন আপডেট</h2>
            {detail.config.status === 'ended' && <Banner kind="info">লাইভ শেষ করা আছে। নতুন আপডেট দিলে পাঠক দেখবেন, তবে পাতাটি নিজে থেকে হালনাগাদ হবে না।</Banner>}
            <LiveComposer detail={detail} onDone={added} />
          </Card>
          <Card className="p-4">
            <LiveFeed detail={detail} setDetail={setDetail} />
          </Card>
        </div>
        {detail.can_manage && <LiveSettings detail={detail} onSaved={replaceDetail} />}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Settings (staff)

const STAGES: [LiveConfig['case']['stage'], string][] = [
  ['filed', 'মামলা দায়ের'],
  ['investigating', 'তদন্ত চলছে'],
  ['arrests', 'গ্রেপ্তার / আটক'],
  ['chargesheet', 'অভিযোগপত্র'],
  ['trial', 'বিচার চলছে'],
  ['verdict', 'রায়'],
]
const PERSON_STATES: [string, string][] = [
  ['suspect', 'সন্দেহভাজন'],
  ['accused', 'অভিযুক্ত / আসামি'],
  ['arrested', 'গ্রেপ্তার'],
  ['remand', 'রিমান্ডে'],
  ['bail', 'জামিনে'],
  ['absconding', 'পলাতক'],
  ['witness', 'সাক্ষী'],
  ['cleared', 'অব্যাহতি'],
  ['convicted', 'দোষী সাব্যস্ত'],
  ['other', 'অন্যান্য'],
]

function Section({ title, children, open: initial = false }: { title: string; children: React.ReactNode; open?: boolean }) {
  const [open, setOpen] = useState(initial)
  return (
    <div className="border-t border-neutral-100 first:border-t-0">
      <button type="button" onClick={() => setOpen(!open)} className="w-full flex items-center justify-between py-3 text-left">
        <span className="font-semibold text-[14px] text-neutral-900">{title}</span>
        <span className="text-neutral-400 text-[18px] leading-none">{open ? '−' : '+'}</span>
      </button>
      {open && <div className="pb-4 space-y-3">{children}</div>}
    </div>
  )
}

function RowList<T>({
  items,
  onChange,
  blank,
  render,
  addLabel,
  max = 20,
}: {
  items: T[]
  onChange: (v: T[]) => void
  blank: T
  render: (it: T, set: (patch: Partial<T>) => void) => React.ReactNode
  addLabel: string
  max?: number
}) {
  return (
    <div className="space-y-2">
      {items.map((it, i) => (
        <div key={i} className="relative rounded-lg border border-neutral-200 bg-neutral-50 p-2.5 pr-8">
          {render(it, (patch) => onChange(items.map((x, j) => (j === i ? { ...x, ...patch } : x))))}
          <button type="button" aria-label="সরান" onClick={() => onChange(items.filter((_, j) => j !== i))} className="absolute top-2 right-2 text-neutral-400 hover:text-red-600">
            <IconX className="w-4 h-4" />
          </button>
        </div>
      ))}
      {items.length < max && (
        <button type="button" onClick={() => onChange([...items, { ...blank }])} className="inline-flex items-center gap-1 text-[12.5px] font-semibold text-brand-600">
          <IconPlus className="w-3.5 h-3.5" /> {addLabel}
        </button>
      )}
    </div>
  )
}

function Weight({ value, onChange }: { value: number; onChange: (n: number) => void }) {
  return (
    <div className="flex items-center gap-1" aria-label="গুরুত্ব">
      <span className="text-[11.5px] text-neutral-500 mr-1">গুরুত্ব</span>
      {[1, 2, 3, 4, 5].map((n) => (
        <button key={n} type="button" onClick={() => onChange(n)} aria-label={`${n}`} className={`w-5 h-5 rounded-full border ${n <= value ? 'bg-[#0B2A6B] border-[#0B2A6B]' : 'bg-white border-neutral-300'}`} />
      ))}
    </div>
  )
}

function PhotoPick({ id, url, onPick }: { id: number; url?: string; onPick: (img: LibraryImage | null) => void }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button type="button" onClick={() => (id ? onPick(null) : setOpen(true))} className="w-11 h-11 rounded-full overflow-hidden border border-neutral-200 bg-white shrink-0 text-[10px] text-neutral-500" title={id ? 'ছবি সরান' : 'ছবি দিন'}>
        {id && url ? <img src={url} alt="" className="w-full h-full object-cover" /> : 'ছবি'}
      </button>
      {open && (
        <MediaGallery
          onClose={() => setOpen(false)}
          onSelect={(img) => {
            onPick(img)
            setOpen(false)
          }}
        />
      )}
    </>
  )
}

function LiveSettings({ detail, onSaved }: { detail: LiveDetail; onSaved: (d: LiveDetail) => void }) {
  const nav = useNavigate()
  const [cfg, setCfg] = useState<LiveConfig>(detail.config)
  const [photos, setPhotos] = useState<Record<string, string>>(detail.photos || {})
  const [corrs, setCorrs] = useState<Correspondent[]>([])
  const [corrSearch, setCorrSearch] = useState('')
  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState<{ kind: 'success' | 'error'; text: string } | null>(null)
  const [confirmOff, setConfirmOff] = useState(false)
  const dirty = JSON.stringify(cfg) !== JSON.stringify(detail.config)
  const c = cfg.case
  const setCase = (patch: Partial<LiveConfig['case']>) => setCfg((x) => ({ ...x, case: { ...x.case, ...patch } }))
  const setBoard = (patch: Partial<LiveConfig['board']>) => setCfg((x) => ({ ...x, board: { ...x.board, ...patch } }))

  useEffect(() => {
    CorrespondentsApi.list()
      .then(setCorrs)
      .catch(() => setCorrs([]))
  }, [])
  // Someone else changed the settings and we have nothing unsaved: follow along.
  const lastServer = useRef(JSON.stringify(detail.config))
  useEffect(() => {
    const s = JSON.stringify(detail.config)
    if (s !== lastServer.current) {
      setCfg((cur) => (JSON.stringify(cur) === lastServer.current ? detail.config : cur))
      lastServer.current = s
    }
  }, [detail.config])

  async function save(patch?: Partial<LiveConfig>) {
    setSaving(true)
    setMsg(null)
    try {
      const body = patch ?? cfg
      const d = await LiveApi.save(detail.post.id, body)
      lastServer.current = JSON.stringify(d.config)
      setCfg(patch ? { ...cfg, ...d.config } : d.config)
      onSaved(d)
      setMsg({ kind: 'success', text: 'সংরক্ষণ হয়েছে — পাঠকের পাতায় আধা মিনিটের মধ্যে দেখাবে।' })
    } catch (e) {
      setMsg({ kind: 'error', text: describeError(e).message })
    } finally {
      setSaving(false)
    }
  }
  async function disable() {
    try {
      await LiveApi.disable(detail.post.id)
      nav('/app/live')
    } catch (e) {
      setMsg({ kind: 'error', text: describeError(e).message })
    }
  }

  const corrHits = useMemo(() => {
    const q = corrSearch.trim().toLowerCase()
    return corrs.filter((x) => !cfg.reporters.includes(x.id) && (!q || x.name.toLowerCase().includes(q) || (x.division || '').toLowerCase().includes(q))).slice(0, 8)
  }, [corrs, corrSearch, cfg.reporters])
  const nameOf = (id: number) => corrs.find((x) => x.id === id)?.name ?? detail.reporters.find((r) => r.id === id)?.name ?? `#${id}`

  const shareSum = c.people.reduce((s, p) => s + p.share, 0)

  return (
    <Card className="p-4 lg:sticky lg:top-4 max-h-none lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto">
      <p className="text-[12px] font-semibold text-neutral-500 mb-1.5">লাইভের অবস্থা</p>
      <div className="grid grid-cols-3 gap-1 rounded-lg bg-neutral-100 p-1 mb-3">
        {(['live', 'paused', 'ended'] as LiveStatus[]).map((s) => (
          <button
            key={s}
            type="button"
            disabled={saving}
            onClick={() => {
              setCfg((x) => ({ ...x, status: s }))
              save({ status: s })
            }}
            className={`rounded-md py-1.5 text-[13px] font-semibold ${cfg.status === s ? (s === 'live' ? 'bg-red-600 text-white' : s === 'paused' ? 'bg-amber-300 text-[#0B2A6B]' : 'bg-white text-neutral-800 shadow-sm') : 'text-neutral-500'}`}
          >
            {s === 'live' ? 'লাইভ' : s === 'paused' ? 'বিরতি' : 'শেষ'}
          </button>
        ))}
      </div>

      {cfg.type === 'event' ? (
        <>
          <Section title="স্কোরবোর্ড" open>
            <label className="inline-flex items-center gap-2 text-[13px]">
              <input type="checkbox" className="w-4 h-4 accent-brand-600" checked={cfg.board.on} onChange={(e) => setBoard({ on: e.target.checked })} />
              স্কোরবোর্ড দেখান (খেলা, নির্বাচনের ফল ইত্যাদি)
            </label>
            {cfg.board.on && (
              <div className="grid grid-cols-[1fr_88px] gap-2">
                <input className={inputClass} value={cfg.board.a_name} onChange={(e) => setBoard({ a_name: e.target.value })} placeholder="দল / পক্ষ ১" />
                <input className={`${inputClass} font-bold`} value={cfg.board.a_score} onChange={(e) => setBoard({ a_score: e.target.value })} placeholder="স্কোর" />
                <input className={inputClass} value={cfg.board.b_name} onChange={(e) => setBoard({ b_name: e.target.value })} placeholder="দল / পক্ষ ২" />
                <input className={`${inputClass} font-bold`} value={cfg.board.b_score} onChange={(e) => setBoard({ b_score: e.target.value })} placeholder="স্কোর" />
                <input className={`${inputClass} col-span-2`} value={cfg.board.note} onChange={(e) => setBoard({ note: e.target.value })} placeholder="অবস্থা — যেমন: ৭২ ওভার · ৬৭ রানে পিছিয়ে" />
              </div>
            )}
          </Section>
          <Section title="মূল তথ্য (সংখ্যা)">
            <FactsEditor facts={cfg.facts} onChange={(facts) => setCfg((x) => ({ ...x, facts }))} hint="যেমন: নিহত ৩, আহত ৪৫, আটক ১২, রান রেট ৩.৪০" />
          </Section>
        </>
      ) : (
        <>
          <Section title="কেস ফাইল" open>
            <div className="grid grid-cols-3 gap-1 rounded-lg bg-neutral-100 p-1">
              {(
                [
                  ['open', 'ওপেন'],
                  ['stalled', 'স্থবির'],
                  ['closed', 'ক্লোজড'],
                ] as const
              ).map(([s, l]) => (
                <button key={s} type="button" onClick={() => setCase({ state: s })} className={`rounded-md py-1 text-[12.5px] font-semibold ${c.state === s ? 'bg-white shadow-sm text-neutral-900' : 'text-neutral-500'}`}>
                  কেস {l}
                </button>
              ))}
            </div>
            <Field label="তদন্তের ধাপ">
              <select className={inputClass} value={c.stage} onChange={(e) => setCase({ stage: e.target.value as LiveConfig['case']['stage'] })}>
                {STAGES.map(([v, l], i) => (
                  <option key={v} value={v}>
                    {bnNumber(i + 1)}. {l}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="সারসংক্ষেপ">
              <textarea className={`${inputClass} min-h-[80px]`} value={c.summary} onChange={(e) => setCase({ summary: e.target.value })} placeholder="ঘটনা কী, কোথায়, কেন — দুই-তিন লাইনে" maxLength={1200} />
            </Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label="মামলা নম্বর">
                <input className={inputClass} value={c.case_no} onChange={(e) => setCase({ case_no: e.target.value })} />
              </Field>
              <Field label="স্থান">
                <input className={inputClass} value={c.place} onChange={(e) => setCase({ place: e.target.value })} />
              </Field>
              <Field label="ঘটনার তারিখ">
                <input type="date" className={inputClass} value={c.incident} onChange={(e) => setCase({ incident: e.target.value })} />
              </Field>
              <Field label="মামলা দায়ের">
                <input type="date" className={inputClass} value={c.filed} onChange={(e) => setCase({ filed: e.target.value })} />
              </Field>
            </div>
          </Section>
          <Section title={`ভিক্টিম (${bnNumber(c.victims.length)})`}>
            <RowList
              items={c.victims}
              onChange={(victims) => setCase({ victims })}
              blank={{ name: '', age: '', detail: '', photo: 0 }}
              addLabel="ভিক্টিম যোগ করুন"
              max={8}
              render={(v, set) => (
                <div className="flex gap-2">
                  <PhotoPick
                    id={v.photo}
                    url={photos[v.photo]}
                    onPick={(img) => {
                      if (img) setPhotos((p) => ({ ...p, [img.id]: img.thumb || img.url }))
                      set({ photo: img ? img.id : 0 })
                    }}
                  />
                  <div className="flex-1 space-y-1.5">
                    <div className="grid grid-cols-[1fr_70px] gap-1.5">
                      <input className={inputClass} value={v.name} onChange={(e) => set({ name: e.target.value })} placeholder="নাম" />
                      <input className={inputClass} value={v.age} onChange={(e) => set({ age: e.target.value })} placeholder="বয়স" />
                    </div>
                    <input className={inputClass} value={v.detail} onChange={(e) => set({ detail: e.target.value })} placeholder="পরিচয় / যা ঘটেছে" />
                  </div>
                </div>
              )}
            />
          </Section>
          <Section title={`যাদের নাম এসেছে (${bnNumber(c.people.length)})`}>
            <label className="inline-flex items-start gap-2 text-[12.5px] text-neutral-700">
              <input type="checkbox" className="w-4 h-4 mt-0.5 accent-brand-600" checked={c.show_share} onChange={(e) => setCase({ show_share: e.target.checked })} />
              <span>সম্পৃক্ততার শতাংশ চার্ট দেখান (পাঠক দেখবেন “মিহা নিউজের মূল্যায়ন, আদালতের সিদ্ধান্ত নয়”)</span>
            </label>
            <RowList
              items={c.people}
              onChange={(people) => setCase({ people })}
              blank={{ name: '', role: '', status: 'suspect', share: 0, note: '', photo: 0 }}
              addLabel="নাম যোগ করুন"
              render={(p, set) => (
                <div className="flex gap-2">
                  <PhotoPick
                    id={p.photo}
                    url={photos[p.photo]}
                    onPick={(img) => {
                      if (img) setPhotos((x) => ({ ...x, [img.id]: img.thumb || img.url }))
                      set({ photo: img ? img.id : 0 })
                    }}
                  />
                  <div className="flex-1 space-y-1.5">
                    <input className={inputClass} value={p.name} onChange={(e) => set({ name: e.target.value })} placeholder="নাম" />
                    <input className={inputClass} value={p.role} onChange={(e) => set({ role: e.target.value })} placeholder="ভূমিকা — যেমন: এজাহারভুক্ত প্রধান আসামি" />
                    <select className={inputClass} value={p.status} onChange={(e) => set({ status: e.target.value })}>
                      {PERSON_STATES.map(([v, l]) => (
                        <option key={v} value={v}>
                          {l}
                        </option>
                      ))}
                    </select>
                    {c.show_share && (
                      <div className="flex items-center gap-2">
                        <span className="text-[11.5px] text-neutral-500 shrink-0">সম্পৃক্ততা</span>
                        <input type="range" min={0} max={100} step={5} value={p.share} onChange={(e) => set({ share: Number(e.target.value) })} className="flex-1 accent-[#0B2A6B]" />
                        <span className="w-10 text-right text-[13px] font-bold">{bnNumber(p.share)}%</span>
                      </div>
                    )}
                    <input className={inputClass} value={p.note} onChange={(e) => set({ note: e.target.value })} placeholder="টীকা (ঐচ্ছিক)" />
                  </div>
                </div>
              )}
            />
            {c.show_share && shareSum > 0 && (
              <p className="text-[11.5px] text-neutral-500">প্রত্যেকের শতাংশ আলাদা মূল্যায়ন — যোগফল ১০০ হতে হবে না।</p>
            )}
          </Section>
          <Section title="শক্তিশালী ও দুর্বল দিক">
            <p className="text-[12px] font-semibold text-emerald-700">শক্তিশালী দিক</p>
            <RowList
              items={c.strengths}
              onChange={(strengths) => setCase({ strengths })}
              blank={{ text: '', weight: 3 }}
              addLabel="শক্তিশালী দিক যোগ"
              max={12}
              render={(it, set) => (
                <div className="space-y-1.5">
                  <input className={inputClass} value={it.text} onChange={(e) => set({ text: e.target.value })} placeholder="যেমন: সিসিটিভি ফুটেজে আসামিকে দেখা যায়" />
                  <Weight value={it.weight} onChange={(weight) => set({ weight })} />
                </div>
              )}
            />
            <p className="text-[12px] font-semibold text-red-700 pt-2">দুর্বল দিক</p>
            <RowList
              items={c.weaknesses}
              onChange={(weaknesses) => setCase({ weaknesses })}
              blank={{ text: '', weight: 3 }}
              addLabel="দুর্বল দিক যোগ"
              max={12}
              render={(it, set) => (
                <div className="space-y-1.5">
                  <input className={inputClass} value={it.text} onChange={(e) => set({ text: e.target.value })} placeholder="যেমন: ফরেনসিক রিপোর্ট এখনো আসেনি" />
                  <Weight value={it.weight} onChange={(weight) => set({ weight })} />
                </div>
              )}
            />
          </Section>
          <Section title="আরও তথ্য (সংখ্যা)">
            <FactsEditor facts={cfg.facts} onChange={(facts) => setCfg((x) => ({ ...x, facts }))} hint="যেমন: আটক ৩, সাক্ষী ৭। মোট আপডেট, নথি ও দিনের হিসাব নিজে থেকেই দেখায়।" />
          </Section>
          <Section title="বিঃদ্রঃ">
            <textarea className={`${inputClass} min-h-[110px] text-[12.5px]`} value={c.disclaimer} onChange={(e) => setCase({ disclaimer: e.target.value })} placeholder={detail.default_disclaimer} maxLength={800} />
            <p className="text-[11.5px] text-neutral-500">ফাঁকা রাখলে ওপরের ধূসর লেখাটিই দেখাবে।</p>
          </Section>
        </>
      )}

      <Section title={`সংবাদদাতা (${bnNumber(cfg.reporters.length)})`}>
        <p className="text-[12px] text-neutral-500">যাদের যোগ করবেন, তারা সংবাদদাতা অ্যাপের “লাইভ” থেকে এই পোস্টে আপডেট পাঠাতে পারবেন।</p>
        <div className="flex flex-wrap gap-1.5">
          {cfg.reporters.map((rid) => (
            <span key={rid} className="inline-flex items-center gap-1 rounded-full bg-brand-50 text-brand-700 pl-2.5 pr-1 py-0.5 text-[12.5px]">
              {nameOf(rid)}
              <button type="button" aria-label="সরান" onClick={() => setCfg((x) => ({ ...x, reporters: x.reporters.filter((r) => r !== rid) }))} className="p-0.5 hover:text-red-600">
                <IconX className="w-3.5 h-3.5" />
              </button>
            </span>
          ))}
        </div>
        <input className={inputClass} value={corrSearch} onChange={(e) => setCorrSearch(e.target.value)} placeholder="সংবাদদাতা খুঁজুন (নাম বা বিভাগ)" />
        {corrSearch.trim() && (
          <div className="space-y-1">
            {corrHits.map((x) => (
              <button
                key={x.id}
                type="button"
                onClick={() => {
                  setCfg((cur) => ({ ...cur, reporters: [...cur.reporters, x.id] }))
                  setCorrSearch('')
                }}
                className="w-full text-left rounded-lg border border-neutral-200 px-2.5 py-1.5 text-[13px] hover:border-brand-500"
              >
                {x.name} <span className="text-neutral-400 text-[11.5px]">{x.division}</span>
              </button>
            ))}
            {corrHits.length === 0 && <p className="text-[12px] text-neutral-500">কেউ নেই।</p>}
          </div>
        )}
        <label className="inline-flex items-start gap-2 text-[12.5px] text-neutral-700">
          <input type="checkbox" className="w-4 h-4 mt-0.5 accent-brand-600" checked={cfg.moderate} onChange={(e) => setCfg((x) => ({ ...x, moderate: e.target.checked }))} />
          <span>সংবাদদাতার আপডেট যাচাই করে প্রকাশ (না দিলে সঙ্গে সঙ্গে প্রকাশ হবে)</span>
        </label>
      </Section>

      {msg && <Banner kind={msg.kind}>{msg.text}</Banner>}
      <div className="sticky bottom-0 bg-white -mx-4 -mb-4 px-4 pb-4 pt-3 mt-1 border-t border-neutral-100 flex items-center justify-between gap-2 z-10">
        {confirmOff ? (
          <span className="flex gap-1">
            <Button size="sm" variant="destructive" onClick={disable}>
              হ্যাঁ, সাধারণ পোস্ট
            </Button>
            <Button size="sm" variant="secondary" onClick={() => setConfirmOff(false)}>
              না
            </Button>
          </span>
        ) : (
          <button type="button" onClick={() => setConfirmOff(true)} className="text-[12px] text-neutral-500 hover:text-red-600">
            লাইভ সরিয়ে সাধারণ পোস্ট করুন
          </button>
        )}
        <Button onClick={() => save()} disabled={saving || !dirty}>
          {saving ? 'সংরক্ষণ…' : dirty ? 'সংরক্ষণ করুন' : 'সংরক্ষিত'}
        </Button>
      </div>
    </Card>
  )
}

function FactsEditor({ facts, onChange, hint }: { facts: LiveConfig['facts']; onChange: (f: LiveConfig['facts']) => void; hint: string }) {
  return (
    <>
      <p className="text-[12px] text-neutral-500">{hint}</p>
      <RowList
        items={facts}
        onChange={onChange}
        blank={{ label: '', value: '' }}
        addLabel="তথ্য যোগ করুন"
        max={12}
        render={(f, set) => (
          <div className="grid grid-cols-[1fr_90px] gap-1.5">
            <input className={inputClass} value={f.label} onChange={(e) => set({ label: e.target.value })} placeholder="বিষয় — যেমন: আহত" />
            <input className={`${inputClass} font-bold`} value={f.value} onChange={(e) => set({ value: e.target.value })} placeholder="সংখ্যা" />
          </div>
        )}
      />
    </>
  )
}

