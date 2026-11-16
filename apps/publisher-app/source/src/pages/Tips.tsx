import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { describeError, TipsApi, type TipDetail, type TipList, type TipPerson, type TipStatus } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { Badge, Banner, Button, Card, EmptyState, ErrorRetry, PageHeader, Spinner, bnNumber, inputClass } from '../components/ui'
import { IconAlertTriangle, IconDownload, IconLock, IconSearch } from '../components/Icon'

/**
 * r46 — টিপস (গোপন তথ্য): tips readers sent from meeha.news/tips/ (newsroom-os NROS_Tips).
 * The desk reads, changes status, assigns, keeps internal notes and replies to
 * the tipster (shown on their anonymous status page). Files open via signed links.
 */

const STATUS_ORDER: TipStatus[] = ['new', 'verifying', 'assigned', 'published', 'rejected']
const STATUS_LABEL: Record<TipStatus, string> = {
  new: 'নতুন',
  verifying: 'যাচাই হচ্ছে',
  assigned: 'অ্যাসাইন',
  rejected: 'বাতিল',
  published: 'প্রকাশিত',
}
const STATUS_TONE: Record<TipStatus, 'blue' | 'amber' | 'emerald' | 'neutral' | 'red'> = {
  new: 'blue',
  verifying: 'amber',
  assigned: 'amber',
  rejected: 'neutral',
  published: 'emerald',
}

const when = (iso: string) => {
  try {
    return new Date(iso).toLocaleString('bn-BD', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
  } catch {
    return iso
  }
}
const size = (b: number) => (b > 1048576 ? `${bnNumber((b / 1048576).toFixed(1))} এমবি` : `${bnNumber(Math.max(1, Math.round(b / 1024)))} কেবি`)

export default function Tips() {
  const [params, setParams] = useSearchParams()
  const [status, setStatus] = useState(params.get('status') ?? 'open')
  const [q, setQ] = useState('')
  const [urgent, setUrgent] = useState(false)
  const [mine, setMine] = useState(false)
  const [data, setData] = useState<TipList | null>(null)
  const [err, setErr] = useState<unknown>(null)
  const [loading, setLoading] = useState(true)
  const openId = Number(params.get('id') || 0)

  const load = useCallback(() => {
    setLoading(true)
    setErr(null)
    TipsApi.list({ status, q, urgent, mine })
      .then(setData)
      .catch(setErr)
      .finally(() => setLoading(false))
  }, [status, q, urgent, mine])

  useEffect(() => {
    load()
  }, [load])

  const open = (id: number) => {
    const p = new URLSearchParams(params)
    if (id) p.set('id', String(id))
    else p.delete('id')
    setParams(p, { replace: true })
  }

  const tabs: { key: string; label: string; n?: number }[] = [
    { key: 'open', label: 'চলমান', n: data ? data.counts.new + data.counts.verifying + data.counts.assigned : undefined },
    ...STATUS_ORDER.map((s) => ({ key: s, label: STATUS_LABEL[s], n: data?.counts[s] })),
    { key: '', label: 'সব' },
  ]

  return (
    <div>
      <PageHeader
        title="টিপস — গোপন তথ্য"
        subtitle="পাঠকেরা meeha.news/tips/ থেকে পরিচয় গোপন রেখে যা পাঠিয়েছেন। তথ্যদাতার পরিচয় কখনো প্রকাশ করবেন না।"
      />
      <div className="flex flex-wrap items-center gap-2 mb-4">
        {tabs.map((t) => (
          <button
            key={t.key || 'all'}
            onClick={() => setStatus(t.key)}
            className={`px-3 py-1.5 rounded-full text-[13px] font-medium border ${status === t.key ? 'bg-neutral-900 text-white border-neutral-900' : 'bg-white text-neutral-700 border-neutral-200 hover:border-neutral-400'}`}
          >
            {t.label}
            {t.n ? <span className="ml-1 opacity-70">{bnNumber(t.n)}</span> : null}
          </button>
        ))}
        <label className="flex items-center gap-1.5 text-[13px] text-red-600 ml-2">
          <input type="checkbox" checked={urgent} onChange={(e) => setUrgent(e.target.checked)} /> শুধু জরুরি
        </label>
        <label className="flex items-center gap-1.5 text-[13px] text-neutral-600">
          <input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} /> আমাকে দেওয়া
        </label>
        <form
          className="relative ml-auto w-full sm:w-64"
          onSubmit={(e) => {
            e.preventDefault()
            const v = (e.currentTarget.elements.namedItem('q') as HTMLInputElement).value
            setQ(v.trim())
          }}
        >
          <IconSearch className="w-4 h-4 absolute left-2.5 top-2.5 text-neutral-400" />
          <input name="q" className={`${inputClass} pl-8`} placeholder="কোড, জেলা বা লেখা খুঁজুন" />
        </form>
      </div>

      <div className="grid lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-4 items-start">
        <Card className="overflow-hidden">
          {loading && !data ? (
            <Spinner className="my-10" />
          ) : err ? (
            <ErrorRetry error={err} onRetry={load} />
          ) : !data?.items.length ? (
            <EmptyState>এখানে কোনো টিপ নেই।</EmptyState>
          ) : (
            <ul className="divide-y divide-neutral-100">
              {data.items.map((t) => (
                <li key={t.id}>
                  <button
                    onClick={() => open(t.id)}
                    className={`w-full text-left px-4 py-3 hover:bg-neutral-50 ${openId === t.id ? 'bg-brand-50/60' : ''}`}
                  >
                    <div className="flex items-center gap-1.5 flex-wrap">
                      {t.unread && <span className="w-2 h-2 rounded-full bg-brand-600" aria-label="নতুন, পড়া হয়নি" />}
                      {t.urgent && <Badge tone="red">জরুরি</Badge>}
                      <Badge tone={STATUS_TONE[t.status]}>{t.status_label}</Badge>
                      <span className="text-[12px] text-neutral-500">{t.category_label}{t.district ? ` · ${t.district}` : ''}</span>
                      <span className="ml-auto text-[11.5px] text-neutral-400">{when(t.created_at)}</span>
                    </div>
                    <p className={`mt-1 text-[13.5px] line-clamp-2 ${t.unread ? 'font-semibold text-neutral-900' : 'text-neutral-700'}`}>{t.excerpt}</p>
                    <p className="mt-1 text-[11.5px] text-neutral-400">
                      {t.code}
                      {t.files_count ? ` · ${bnNumber(t.files_count)}টি ফাইল` : ''}
                      {t.assignee ? ` · দায়িত্বে: ${t.assignee.name}` : ' · কারও দায়িত্বে নেই'}
                    </p>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <div className="lg:sticky lg:top-4">
          {openId ? (
            <TipPanel key={openId} id={openId} onChanged={load} onClose={() => open(0)} />
          ) : (
            <Card className="p-6 text-center text-[13.5px] text-neutral-500">
              <IconLock className="w-8 h-8 mx-auto mb-2 text-neutral-300" />
              বাঁ দিকের তালিকা থেকে একটি টিপ খুলুন।
            </Card>
          )}
        </div>
      </div>
    </div>
  )
}

function TipPanel({ id, onChanged, onClose }: { id: number; onChanged: () => void; onClose: () => void }) {
  const { isPublisher } = useAuth()
  const [tip, setTip] = useState<TipDetail | null>(null)
  const [err, setErr] = useState<unknown>(null)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState<string | null>(null)
  const [noteKind, setNoteKind] = useState<'note' | 'reply'>('note')
  const [text, setText] = useState('')
  const [people, setPeople] = useState<TipPerson[]>([])
  const [pq, setPq] = useState('')
  const [pubUrl, setPubUrl] = useState('')

  const load = useCallback(() => {
    setErr(null)
    TipsApi.get(id)
      .then((t) => {
        setTip(t)
        setPubUrl(t.published_url)
        onChanged()
      })
      .catch(setErr)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])
  useEffect(() => {
    load()
  }, [load])
  useEffect(() => {
    if (!tip) return
    const t = setTimeout(() => {
      TipsApi.people(pq, tip.district).then((r) => setPeople(r.items)).catch(() => {})
    }, 250)
    return () => clearTimeout(t)
  }, [pq, tip?.district]) // eslint-disable-line react-hooks/exhaustive-deps

  const act = async (fn: () => Promise<TipDetail>, ok?: string) => {
    setBusy(true)
    setMsg(null)
    try {
      const t = await fn()
      setTip(t)
      onChanged()
      if (ok) setMsg(ok)
    } catch (e) {
      setMsg(describeError(e).message)
    } finally {
      setBusy(false)
    }
  }

  if (err) return <Card className="p-4"><ErrorRetry error={err} onRetry={load} /></Card>
  if (!tip) return <Card className="p-8"><Spinner /></Card>

  return (
    <Card className="p-5">
      <div className="flex items-start gap-2 mb-3">
        <div className="min-w-0">
          <div className="flex items-center gap-1.5 flex-wrap">
            {tip.urgent && (
              <Badge tone="red">
                <IconAlertTriangle className="w-3 h-3 inline -mt-0.5" /> জরুরি
              </Badge>
            )}
            <Badge tone={STATUS_TONE[tip.status]}>{tip.status_label}</Badge>
            <span className="text-[12.5px] text-neutral-500">{tip.category_label}{tip.district ? ` · ${tip.district}` : ''} · {when(tip.created_at)}</span>
          </div>
          <p className="text-[12px] text-neutral-400 mt-1">ট্র্যাকিং কোড {tip.code} · টিপ #{bnNumber(tip.id)}</p>
        </div>
        <button onClick={onClose} className="ml-auto text-[13px] text-neutral-500 hover:text-neutral-800">বন্ধ</button>
      </div>

      {msg && <Banner kind="info">{msg}</Banner>}

      <div className="whitespace-pre-wrap text-[14px] leading-relaxed text-neutral-800 bg-neutral-50 rounded-xl p-3.5 mb-4 max-h-80 overflow-y-auto">{tip.body}</div>

      {tip.files.length > 0 && (
        <div className="mb-4">
          <p className="text-[12.5px] font-medium text-neutral-600 mb-1.5">ফাইল (লিংক ২ ঘণ্টা কাজ করবে)</p>
          <ul className="grid sm:grid-cols-2 gap-2">
            {tip.files.map((f) => (
              <li key={f.id} className="border border-neutral-200 rounded-xl p-2 flex gap-2 items-center">
                {f.kind === 'image' ? (
                  <a href={f.url} target="_blank" rel="noreferrer noopener">
                    <img src={f.url} alt="" className="w-14 h-14 object-cover rounded-lg bg-neutral-100" referrerPolicy="no-referrer" />
                  </a>
                ) : (
                  <span className="w-14 h-14 rounded-lg bg-neutral-100 flex items-center justify-center text-[11px] font-bold text-neutral-500 uppercase">{f.kind}</span>
                )}
                <div className="min-w-0 flex-1">
                  <p className="text-[12.5px] truncate" title={f.name}>{f.name}</p>
                  <p className="text-[11px] text-neutral-400">
                    {size(f.size)} · {f.kind === 'image' ? (f.stripped ? 'লোকেশন/EXIF মোছা' : 'EXIF মোছা যায়নি') : 'মেটাডেটা অপরিবর্তিত'}
                  </p>
                </div>
                <a href={f.download} className="p-1.5 text-neutral-500 hover:text-brand-600" title="ডাউনলোড" aria-label={`${f.name} ডাউনলোড`} rel="noreferrer noopener">
                  <IconDownload className="w-4 h-4" />
                </a>
              </li>
            ))}
          </ul>
        </div>
      )}

      {tip.contact && Object.keys(tip.contact).length > 0 && (
        <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-[13px]">
          <p className="font-semibold text-amber-800 mb-1">তথ্যদাতার যোগাযোগ — গোপনীয়, কাউকে দেবেন না</p>
          {tip.contact.name && <p>নাম: {tip.contact.name}</p>}
          {tip.contact.phone && <p>ফোন: {tip.contact.phone}</p>}
          {tip.contact.email && <p>ইমেইল: {tip.contact.email}</p>}
        </div>
      )}

      <div className="grid sm:grid-cols-2 gap-3 mb-4">
        <label className="block">
          <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">অবস্থা</span>
          <select
            className={inputClass}
            value={tip.status}
            disabled={busy}
            onChange={(e) => act(() => TipsApi.update(tip.id, { status: e.target.value as TipStatus }), 'অবস্থা বদলানো হলো।')}
          >
            {STATUS_ORDER.map((s) => (
              <option key={s} value={s}>{STATUS_LABEL[s]}</option>
            ))}
          </select>
        </label>
        <label className="block">
          <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">
            দায়িত্ব {tip.routed === 'district' ? '(জেলা দেখে স্বয়ংক্রিয়)' : tip.routed === 'editor' ? '(স্বয়ংক্রিয় — এডিটর)' : ''}
          </span>
          <input className={`${inputClass} mb-1.5`} placeholder="নাম লিখে খুঁজুন…" value={pq} onChange={(e) => setPq(e.target.value)} />
          <select
            className={inputClass}
            value={tip.assignee?.id ?? 0}
            disabled={busy}
            onChange={(e) => act(() => TipsApi.update(tip.id, { assignee_id: Number(e.target.value) }), 'দায়িত্ব দেওয়া হলো — তাঁকে নোটিফিকেশন পাঠানো হয়েছে।')}
          >
            <option value={0}>— কেউ নয় —</option>
            {tip.assignee && !people.some((p) => p.id === tip.assignee!.id) && (
              <option value={tip.assignee.id}>{tip.assignee.name} ({tip.assignee.role})</option>
            )}
            {people.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name} ({p.role}{p.district ? `, ${p.district}` : ''})
              </option>
            ))}
          </select>
        </label>
      </div>

      {tip.status === 'published' && (
        <div className="flex gap-2 mb-4">
          <input className={inputClass} placeholder="প্রকাশিত প্রতিবেদনের লিংক (তথ্যদাতা দেখবেন)" value={pubUrl} onChange={(e) => setPubUrl(e.target.value)} />
          <Button variant="secondary" disabled={busy} onClick={() => act(() => TipsApi.update(tip.id, { published_url: pubUrl }), 'লিংক রাখা হলো।')}>রাখুন</Button>
        </div>
      )}

      <div className="mb-4">
        <p className="text-[12.5px] font-medium text-neutral-600 mb-1.5">কথোপকথন ও নোট</p>
        <ul className="space-y-2 max-h-80 overflow-y-auto">
          {tip.notes.map((n) => (
            <li
              key={n.id}
              className={`rounded-xl px-3 py-2 text-[13px] whitespace-pre-wrap ${
                n.kind === 'reply'
                  ? 'bg-brand-50 text-neutral-800'
                  : n.kind === 'tipster'
                    ? 'bg-emerald-50 text-neutral-800'
                    : n.kind === 'system'
                      ? 'bg-neutral-50 text-neutral-500 text-[12px]'
                      : 'bg-amber-50 text-neutral-800'
              }`}
            >
              <span className="block text-[11px] text-neutral-500 mb-0.5">
                {n.kind === 'reply' ? 'তথ্যদাতাকে উত্তর' : n.kind === 'tipster' ? 'তথ্যদাতা' : n.kind === 'system' ? 'স্বয়ংক্রিয়' : 'অভ্যন্তরীণ নোট'} · {n.who} · {when(n.at)}
              </span>
              {n.body}
            </li>
          ))}
        </ul>
      </div>

      <div className="border-t border-neutral-100 pt-3">
        <div className="flex gap-1 mb-2" role="tablist">
          {(['note', 'reply'] as const).map((k) => (
            <button
              key={k}
              role="tab"
              aria-selected={noteKind === k}
              onClick={() => setNoteKind(k)}
              className={`px-3 py-1 rounded-full text-[12.5px] ${noteKind === k ? 'bg-neutral-900 text-white' : 'bg-neutral-100 text-neutral-600'}`}
            >
              {k === 'note' ? 'অভ্যন্তরীণ নোট' : 'তথ্যদাতাকে উত্তর'}
            </button>
          ))}
        </div>
        <textarea
          className={inputClass}
          rows={3}
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder={noteKind === 'note' ? 'শুধু ডেস্ক দেখবে…' : 'তথ্যদাতা তাঁর ট্র্যাকিং কোড দিয়ে এটি দেখবেন — নিজের বা সূত্রের নাম লিখবেন না।'}
        />
        <div className="flex items-center gap-2 mt-2">
          <Button
            disabled={busy || !text.trim()}
            onClick={() =>
              act(async () => {
                const t = await TipsApi.note(tip.id, noteKind, text)
                setText('')
                return t
              }, noteKind === 'reply' ? 'উত্তর পাঠানো হলো — তথ্যদাতা অবস্থার পাতায় দেখবেন।' : 'নোট রাখা হলো।')
            }
          >
            {noteKind === 'note' ? 'নোট রাখুন' : 'উত্তর পাঠান'}
          </Button>
          {isPublisher && (
            <Button
              variant="danger"
              className="ml-auto"
              disabled={busy}
              onClick={async () => {
                if (!window.confirm('টিপটি ফাইলসহ চিরতরে মুছবেন? ফেরানো যাবে না।')) return
                setBusy(true)
                try {
                  await TipsApi.remove(tip.id)
                  onChanged()
                  onClose()
                } catch (e) {
                  setMsg(describeError(e).message)
                  setBusy(false)
                }
              }}
            >
              চিরতরে মুছুন
            </Button>
          )}
        </div>
      </div>
    </Card>
  )
}
