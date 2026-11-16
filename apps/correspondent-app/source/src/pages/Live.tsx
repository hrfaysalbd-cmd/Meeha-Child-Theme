/**
 * 1.11.0 — লাইভ: the live posts an editor assigned this correspondent to, and sending updates to one of them
 * from the spot. An update is published at once, or waits for the editor when the live post is set to
 * "যাচাই করে প্রকাশ". The correspondent can edit or delete only their own updates; everyone's updates are shown
 * so they know what is already covered. The page refreshes itself every 20 seconds.
 */
import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { LiveApi, describeError, type LiveDetail, type LiveEntry, type LiveKind, type LivePostRow } from '../lib/api'
import RichEditor from '../components/RichEditor'
import { Banner, Button, Spinner, TopBar, bnNumber, inputClass } from '../components/ui'
import { IconBroadcast, IconExternalLink, IconTrash } from '../components/Icon'

const POLL_MS = 20_000

function ago(ts: number) {
  if (!ts) return ''
  const s = Math.max(0, Math.floor(Date.now() / 1000) - ts)
  if (s < 60) return 'এইমাত্র'
  for (const [sec, l] of [[86400, 'দিন'], [3600, 'ঘণ্টা'], [60, 'মিনিট']] as [number, string][]) {
    if (s >= sec) return `${bnNumber(Math.floor(s / sec))} ${l} আগে`
  }
  return 'এইমাত্র'
}
function clock(ts: number) {
  const d = new Date(ts * 1000)
  const h = d.getHours()
  const part = h < 5 ? 'রাত' : h < 12 ? 'সকাল' : h < 15 ? 'দুপুর' : h < 18 ? 'বিকেল' : h < 20 ? 'সন্ধ্যা' : 'রাত'
  return `${part} ${bnNumber(h % 12 || 12)}:${bnNumber(String(d.getMinutes()).padStart(2, '0'))}`
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
const STATUS: Record<string, [string, string]> = {
  live: ['লাইভ', 'bg-red-600 text-white'],
  paused: ['বিরতি', 'bg-amber-300 text-[#0B2A6B]'],
  ended: ['শেষ', 'bg-neutral-200 text-neutral-600'],
}

function StatusPill({ status }: { status: string }) {
  const [l, c] = STATUS[status] ?? STATUS.ended
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ${c}`}>
      <span className={`w-1.5 h-1.5 rounded-full bg-current ${status === 'live' ? 'animate-pulse' : ''}`} />
      {l}
    </span>
  )
}

/** Home-screen strip: running live posts this correspondent is part of. */
export function LiveStrip() {
  const navigate = useNavigate()
  const [items, setItems] = useState<LivePostRow[]>([])
  useEffect(() => {
    LiveApi.list()
      .then((r) => setItems(r.items.filter((i) => i.status !== 'ended')))
      .catch(() => setItems([]))
  }, [])
  if (!items.length) return null
  return (
    <div className="mb-5 space-y-2">
      {items.slice(0, 3).map((it) => (
        <button
          key={it.id}
          onClick={() => navigate(`/app/live/${it.id}`)}
          className="w-full text-left rounded-2xl bg-gradient-to-br from-[#0B2A6B] to-[#1546B8] text-white p-3.5 flex items-center gap-3 active:scale-[0.99] transition"
        >
          <span className="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
            <IconBroadcast className="w-5 h-5" />
          </span>
          <span className="flex-1 min-w-0">
            <span className="flex items-center gap-1.5 mb-0.5">
              <StatusPill status={it.status} />
              <span className="text-[11px] text-white/70">{it.type === 'investigation' ? 'অপরাধ তদন্ত লাইভ' : 'লাইভ ইভেন্ট'}</span>
            </span>
            <span className="block text-[14px] font-semibold leading-snug line-clamp-2">{it.title}</span>
          </span>
          <span className="text-[12px] font-semibold text-amber-300 shrink-0">আপডেট দিন ›</span>
        </button>
      ))}
    </div>
  )
}

export default function Live() {
  const { id } = useParams()
  return id ? <LiveDesk id={Number(id)} /> : <LiveList />
}

function LiveList() {
  const navigate = useNavigate()
  const [items, setItems] = useState<LivePostRow[] | null>(null)
  const [error, setError] = useState('')
  useEffect(() => {
    LiveApi.list()
      .then((r) => setItems(r.items))
      .catch((e) => setError(describeError(e).message))
  }, [])
  return (
    <div>
      <TopBar title="লাইভ" onBack={() => navigate('/app')} />
      <div className="p-4 space-y-2">
        {error && <Banner kind="error">{error}</Banner>}
        {!items && !error && <Spinner className="mt-10" />}
        {items && items.length === 0 && (
          <p className="text-center text-[13.5px] text-neutral-500 py-10">কোনো লাইভ পোস্টে আপনাকে এখনো যুক্ত করা হয়নি। সম্পাদক যুক্ত করলে এখানে দেখাবে।</p>
        )}
        {items?.map((it) => (
          <button key={it.id} onClick={() => navigate(`/app/live/${it.id}`)} className="w-full text-left rounded-2xl border border-neutral-100 bg-white p-3.5 active:bg-neutral-50">
            <div className="flex items-center gap-1.5 mb-1">
              <StatusPill status={it.status} />
              <span className="text-[11.5px] text-neutral-500">{it.type === 'investigation' ? 'অপরাধ তদন্ত লাইভ' : 'লাইভ ইভেন্ট'}</span>
            </div>
            <p className="text-[14.5px] font-semibold text-neutral-900 leading-snug">{it.title}</p>
            <p className="text-[12px] text-neutral-500 mt-1">
              {bnNumber(it.count)}টি আপডেট{it.last ? ` · সর্বশেষ ${ago(it.last)}` : ''}
            </p>
          </button>
        ))}
      </div>
    </div>
  )
}

function LiveDesk({ id }: { id: number }) {
  const navigate = useNavigate()
  const [detail, setDetail] = useState<LiveDetail | null>(null)
  const [error, setError] = useState('')
  const busy = useRef(false)

  const reload = useCallback(
    async (quiet = false) => {
      if (busy.current) return
      busy.current = true
      try {
        setDetail(await LiveApi.get(id))
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
    const t = setInterval(() => !document.hidden && reload(true), POLL_MS)
    return () => clearInterval(t)
  }, [reload])

  return (
    <div>
      <TopBar
        title={detail ? detail.post.title : 'লাইভ'}
        onBack={() => navigate('/app/live')}
        right={
          detail && (
            <a href={detail.post.link} target="_blank" rel="noreferrer" className="p-1 text-neutral-500" aria-label="সাইটে দেখুন">
              <IconExternalLink className="w-5 h-5" />
            </a>
          )
        }
      />
      <div className="p-4">
        {error && <Banner kind="error">{error}</Banner>}
        {!detail && !error && <Spinner className="mt-10" />}
        {detail && (
          <>
            <div className="flex items-center gap-1.5 mb-3">
              <StatusPill status={detail.config.status} />
              <span className="text-[12px] text-neutral-500">
                {bnNumber(detail.entries.filter((e) => e.status === 'publish').length)}টি আপডেট প্রকাশিত
              </span>
            </div>
            {detail.config.status === 'ended' ? (
              <Banner kind="info">এই লাইভ শেষ হয়ে গেছে, নতুন আপডেট পাঠানো যাবে না।</Banner>
            ) : (
              <Composer detail={detail} onSent={(e) => setDetail((d) => (d ? { ...d, entries: [e, ...d.entries] } : d))} />
            )}
            <h2 className="font-bold text-[15px] text-neutral-900 mt-6 mb-3">সব আপডেট</h2>
            <div className="ml-1.5">
              {detail.entries.map((e) => (
                <Entry
                  key={`${e.id}-${e.modified}`}
                  e={e}
                  detail={detail}
                  onChange={(n) => setDetail((d) => (d ? { ...d, entries: d.entries.map((x) => (x.id === n.id ? n : x)) } : d))}
                  onRemove={(rid) => setDetail((d) => (d ? { ...d, entries: d.entries.filter((x) => x.id !== rid) } : d))}
                />
              ))}
              {detail.entries.length === 0 && <p className="text-[13px] text-neutral-500 py-4">এখনো কোনো আপডেট নেই — প্রথমটি আপনিই দিন।</p>}
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function Composer({ detail, editing, onSent, onCancel }: { detail: LiveDetail; editing?: LiveEntry; onSent: (e: LiveEntry) => void; onCancel?: () => void }) {
  const kinds = detail.kinds.filter((k) => k.for === 'both' || k.for === detail.config.type)
  const [kind, setKind] = useState<LiveKind>(editing?.kind ?? 'update')
  const [title, setTitle] = useState(editing?.title ?? '')
  const [content, setContent] = useState(editing?.content ?? '')
  const [source, setSource] = useState(editing?.source ?? '')
  const [key, setKey] = useState(0)
  const [saving, setSaving] = useState(false)
  const [msg, setMsg] = useState<{ kind: 'error' | 'success'; text: string } | null>(null)
  const isDoc = kind === 'document' || kind === 'evidence'

  async function send() {
    setMsg(null)
    if (!content.replace(/<[^>]*>/g, '').trim() && !title.trim() && !content.includes('<img')) {
      setMsg({ kind: 'error', text: 'আপডেটে কিছু লিখুন।' })
      return
    }
    setSaving(true)
    try {
      const body = { kind, title: title.trim(), content, source: isDoc ? source.trim() : '' }
      const e = editing ? await LiveApi.editEntry(detail.post.id, editing.id, body) : await LiveApi.addEntry(detail.post.id, body)
      onSent(e)
      if (!editing) {
        setTitle('')
        setContent('')
        setSource('')
        setKind('update')
        setKey((k) => k + 1)
        setMsg({ kind: 'success', text: e.status === 'pending' ? 'পাঠানো হয়েছে — সম্পাদক যাচাই করে প্রকাশ করবেন।' : 'আপডেট প্রকাশ হয়েছে।' })
      }
    } catch (err) {
      setMsg({ kind: 'error', text: describeError(err).message })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="rounded-2xl border border-neutral-100 bg-white p-3.5 space-y-3">
      <div className="flex flex-wrap gap-1.5">
        {kinds.map((k) => (
          <button
            key={k.id}
            type="button"
            onClick={() => setKind(k.id)}
            className={`rounded-full px-3 py-1 text-[12.5px] font-semibold border ${kind === k.id ? `${KIND_STYLE[k.id]} border-transparent` : 'border-neutral-200 text-neutral-600 bg-white'}`}
          >
            {k.label}
          </button>
        ))}
      </div>
      <input className={inputClass} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="শিরোনাম (ঐচ্ছিক)" maxLength={200} />
      <RichEditor key={`${editing?.id ?? 'new'}-${key}`} content={content} onChange={setContent} />
      {isDoc && <input className={inputClass} value={source} onChange={(e) => setSource(e.target.value)} placeholder="সূত্র — যেমন: থানার এজাহার" maxLength={120} />}
      {msg && <Banner kind={msg.kind}>{msg.text}</Banner>}
      <div className="flex gap-2">
        {onCancel && (
          <Button variant="secondary" onClick={onCancel}>
            বাতিল
          </Button>
        )}
        <Button onClick={send} disabled={saving}>
          {saving ? 'পাঠানো হচ্ছে…' : editing ? 'সংরক্ষণ' : detail.config.moderate ? 'যাচাইয়ের জন্য পাঠান' : 'আপডেট প্রকাশ করুন'}
        </Button>
      </div>
      {!editing && <p className="text-[11.5px] text-neutral-500">{detail.config.moderate ? 'এই লাইভে সম্পাদক যাচাই করে প্রকাশ করেন।' : 'পাঠানোর সঙ্গে সঙ্গে পাঠকের পাতায় চলে যাবে।'}</p>}
    </div>
  )
}

function Entry({ e, detail, onChange, onRemove }: { e: LiveEntry; detail: LiveDetail; onChange: (e: LiveEntry) => void; onRemove: (id: number) => void }) {
  const [editing, setEditing] = useState(false)
  const [confirm, setConfirm] = useState(false)
  const [err, setErr] = useState('')
  const label = detail.kinds.find((k) => k.id === e.kind)?.label ?? ''
  const mine = e.author.id === detail.me

  if (editing) {
    return (
      <div className="mb-4">
        <Composer
          detail={detail}
          editing={e}
          onSent={(n) => {
            onChange(n)
            setEditing(false)
          }}
          onCancel={() => setEditing(false)}
        />
      </div>
    )
  }
  return (
    <div className={`relative pl-4 pb-5 border-l-2 ${e.status === 'pending' ? 'border-amber-300' : 'border-neutral-200'}`}>
      <span className={`absolute -left-[7px] top-1 w-3 h-3 rounded-full border-2 bg-white ${mine ? 'border-brand-600 bg-brand-600' : 'border-neutral-400'}`} />
      <div className="flex flex-wrap items-center gap-1.5 text-[11.5px] text-neutral-500">
        <span className="font-semibold text-neutral-800">{clock(e.time)}</span>
        <span>· {ago(e.time)}</span>
        <span>· {mine ? 'আপনি' : e.author.name}</span>
        {e.kind !== 'update' && <span className={`rounded px-1.5 py-0.5 text-[10.5px] font-bold ${KIND_STYLE[e.kind]}`}>{label}</span>}
        {e.status === 'pending' && <span className="rounded px-1.5 py-0.5 text-[10.5px] font-bold bg-amber-100 text-amber-800">যাচাইয়ের অপেক্ষায়</span>}
      </div>
      <div className={`mt-1 rounded-xl p-3 ${e.status === 'pending' ? 'bg-amber-50' : 'bg-white border border-neutral-100'}`}>
        {e.title && <p className="font-bold text-[14.5px] text-neutral-900 mb-1">{e.title}</p>}
        <div className="text-[14px] text-neutral-800 leading-relaxed [&_img]:rounded-lg [&_p]:my-1" dangerouslySetInnerHTML={{ __html: e.content }} />
        {e.source && <p className="text-[11.5px] text-neutral-500 mt-1.5">সূত্র: {e.source}</p>}
      </div>
      {err && <p className="text-[12px] text-red-600 mt-1">{err}</p>}
      {e.can_edit && (
        <div className="flex gap-3 mt-1.5 text-[12.5px] font-semibold">
          <button onClick={() => setEditing(true)} className="text-brand-600">
            সম্পাদনা
          </button>
          {confirm ? (
            <>
              <button
                onClick={async () => {
                  try {
                    await LiveApi.removeEntry(detail.post.id, e.id)
                    onRemove(e.id)
                  } catch (x) {
                    setErr(describeError(x).message)
                  }
                }}
                className="text-red-600"
              >
                নিশ্চিত — মুছুন
              </button>
              <button onClick={() => setConfirm(false)} className="text-neutral-500">
                না
              </button>
            </>
          ) : (
            <button onClick={() => setConfirm(true)} className="text-red-600 inline-flex items-center gap-1">
              <IconTrash className="w-3.5 h-3.5" /> মুছুন
            </button>
          )}
        </div>
      )}
    </div>
  )
}
