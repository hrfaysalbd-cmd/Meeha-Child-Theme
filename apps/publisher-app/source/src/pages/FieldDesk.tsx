import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { apiRequest, describeError, EditorialApi, type AreasResponse } from '../lib/api'
import { Badge, Banner, Button, Card, EmptyState, ErrorRetry, Field, inputClass, KpiCard, Modal, PageHeader, Spinner, bnDate, bnNumber } from '../components/ui'

/**
 * r44 — মাঠ ডেস্ক (newsroom-os 1.27.0, NROS_Field):
 *  কাজ — paid assignments for an area; accept a submission and the money
 *        goes into the correspondent's earnings.
 *  নিরাপত্তা — SOS and late check-ins from the field, with phone and map.
 *  কভারেজ — correspondents per district/upazila; empty areas → a
 *        recruitment poster in one click.
 */

interface Claim {
  id: number
  status: 'taken' | 'submitted' | 'paid' | 'rejected' | 'dropped'
  note: string
  post_id: number | null
  post_title: string
  link: string
  desk_note: string
  paid: number
  user_id: number
  name: string
  phone: string
  area: string
  submitted_at: string | null
}
interface Job {
  id: number
  title: string
  brief: string
  district: string
  upazila: string
  fee: number
  travel: number
  slots: number
  left: number
  kind: string
  kind_label: string
  urgent: boolean
  deadline: string | null
  status: 'open' | 'closed' | 'cancelled'
  created_at: string
  claims: Claim[]
}
interface SafetyRow {
  id: number
  kind: 'sos' | 'checkin'
  status: 'active' | 'overdue' | 'safe' | 'resolved'
  place: string
  note: string
  lat: number | null
  lng: number | null
  due_at: string | null
  created_at: string
  closed_at: string | null
  name: string
  phone: string
  area: string
  avatar: string
  emergency: string
}
interface Desk {
  jobs: Job[]
  safety: SafetyRow[]
  waiting: number
  alerts: number
  kinds: Record<string, string>
  can_pay: boolean
}
interface CovRow {
  division: string
  district: string
  people: number
  active: number
  upazila_total: number
  upazila_have: number
  upazila_empty: string[]
}

const DeskApi = {
  desk: () => apiRequest<Desk>('/admin/field'),
  save: (id: number, body: Record<string, unknown>) => apiRequest<{ ok: true; id: number; told?: number }>(id ? `/admin/field/assignments/${id}` : '/admin/field/assignments', { method: 'POST', body }),
  decide: (id: number, body: Record<string, unknown>) => apiRequest<{ ok: true }>(`/admin/field/claims/${id}/decide`, { method: 'POST', body }),
  resolve: (id: number) => apiRequest<{ ok: true }>(`/admin/field/safety/${id}/resolve`, { method: 'POST' }),
  coverage: () => apiRequest<{ rows: CovRow[]; unknown: string[]; total: number }>('/admin/field/coverage'),
}

type Tab = 'jobs' | 'safety' | 'coverage'
const taka = (n: number) => `৳${bnNumber(Math.round(n).toLocaleString('en-IN'))}`
const ago = (iso: string) => {
  const m = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000))
  if (m < 1) return 'এইমাত্র'
  if (m < 60) return `${bnNumber(m)} মিনিট আগে`
  if (m < 1440) return `${bnNumber(Math.floor(m / 60))} ঘণ্টা আগে`
  return bnDate(iso)
}

export default function FieldDesk() {
  const [sp, setSp] = useSearchParams()
  const tab = (['jobs', 'safety', 'coverage'].includes(sp.get('tab') || '') ? sp.get('tab') : 'jobs') as Tab
  const [desk, setDesk] = useState<Desk | null>(null)
  const [err, setErr] = useState<unknown>(null)
  const [form, setForm] = useState<Partial<Job> | null>(null)
  const [msg, setMsg] = useState<{ k: 'error' | 'success'; t: string } | null>(null)

  const load = useCallback(() => {
    setErr(null)
    DeskApi.desk().then(setDesk, setErr)
  }, [])
  useEffect(load, [load])
  // an SOS must show up without a reload
  useEffect(() => {
    const t = window.setInterval(() => {
      if (document.visibilityState === 'visible') DeskApi.desk().then(setDesk, () => undefined)
    }, 30000)
    return () => window.clearInterval(t)
  }, [])

  const tabs: { k: Tab; label: string; n?: number; red?: boolean }[] = [
    { k: 'jobs', label: 'কাজের বোর্ড', n: desk?.waiting },
    { k: 'safety', label: 'নিরাপত্তা', n: desk?.alerts, red: true },
    { k: 'coverage', label: 'কভারেজ ম্যাপ' },
  ]

  return (
    <div>
      <PageHeader
        title="মাঠ ডেস্ক"
        subtitle="টাকাসহ কাজ পাঠান, মাঠের সহকর্মীদের নিরাপত্তা দেখুন, কোথায় প্রতিনিধি নেই জানুন"
        actions={
          <Button onClick={() => setForm({ kind: 'news', slots: 1, fee: 0, travel: 0 })} data-testid="new-job">
            + নতুন কাজ
          </Button>
        }
      />
      {desk && desk.alerts > 0 && tab !== 'safety' && (
        <button type="button" onClick={() => setSp({ tab: 'safety' })} className="w-full mb-4 rounded-xl bg-red-600 text-white px-4 py-3 text-left font-semibold animate-pulse">
          🚨 {bnNumber(desk.alerts)} জন সহকর্মী বিপদে বা সময়মতো ফেরেননি — এখনই দেখুন
        </button>
      )}
      <div className="flex gap-1.5 mb-5 overflow-x-auto">
        {tabs.map((t) => (
          <button
            key={t.k}
            type="button"
            onClick={() => setSp({ tab: t.k })}
            className={`px-4 py-2 rounded-full text-[13.5px] font-semibold whitespace-nowrap flex items-center gap-1.5 ${tab === t.k ? 'bg-neutral-900 text-white' : 'bg-white border border-neutral-200 text-neutral-700'}`}
          >
            {t.label}
            {!!t.n && <span className={`min-w-[18px] h-[18px] px-1 rounded-full text-[10.5px] leading-[18px] text-center ${t.red ? 'bg-red-600 text-white' : 'bg-brand-600 text-white'}`}>{bnNumber(t.n)}</span>}
          </button>
        ))}
      </div>
      {msg && <Banner kind={msg.k}>{msg.t}</Banner>}
      {tab === 'coverage' ? (
        <Coverage onJob={(district, upazila) => setForm({ kind: 'news', slots: 1, fee: 0, travel: 0, district, upazila })} />
      ) : err ? (
        <ErrorRetry error={err} onRetry={load} />
      ) : !desk ? (
        <Spinner className="mt-10" />
      ) : tab === 'jobs' ? (
        <Jobs desk={desk} reload={load} onEdit={setForm} setMsg={setMsg} />
      ) : (
        <SafetyBoard rows={desk.safety} reload={load} />
      )}
      {form && (
        <JobForm
          job={form}
          kinds={desk?.kinds ?? { news: 'সংবাদ' }}
          onClose={() => setForm(null)}
          onSaved={(told) => {
            setForm(null)
            setMsg({ k: 'success', t: told != null ? `কাজ চালু হয়েছে — ${bnNumber(told)} জন সংবাদদাতার ফোনে নোটিশ গেছে।` : 'কাজ হালনাগাদ হয়েছে।' })
            if (tab !== 'jobs') setSp({ tab: 'jobs' })
            load()
          }}
        />
      )}
    </div>
  )
}

// ---------------------------------------------------------------------

function JobForm({ job, kinds, onClose, onSaved }: { job: Partial<Job>; kinds: Record<string, string>; onClose: () => void; onSaved: (told?: number) => void }) {
  const [v, setV] = useState<Partial<Job>>(job)
  const [areas, setAreas] = useState<AreasResponse | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  useEffect(() => {
    EditorialApi.areas().then(setAreas, () => setAreas({ divisions: [], upazilas: {}, source: 'unavailable' }))
  }, [])
  const districts = useMemo(() => (areas?.divisions ?? []).flatMap((d) => d.districts).sort((a, b) => a.localeCompare(b, 'bn')), [areas])
  const ups = v.district ? areas?.upazilas[v.district] ?? [] : []
  const set = <K extends keyof Job>(k: K, x: Job[K]) => setV((o) => ({ ...o, [k]: x }))
  const localDl = v.deadline ? v.deadline.slice(0, 16) : ''
  const save = async () => {
    if (!v.title?.trim()) return setErr('কাজের শিরোনাম দিন।')
    setBusy(true)
    setErr('')
    try {
      const r = await DeskApi.save(v.id ?? 0, {
        title: v.title,
        brief: v.brief ?? '',
        district: v.district ?? '',
        upazila: v.upazila ?? '',
        fee: v.fee ?? 0,
        travel: v.travel ?? 0,
        slots: v.slots ?? 1,
        kind: v.kind ?? 'news',
        urgent: v.urgent ? 1 : 0,
        deadline: localDl,
      })
      onSaved(v.id ? undefined : r.told ?? 0)
    } catch (e) {
      setErr(describeError(e).message)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title={v.id ? 'কাজ সম্পাদনা' : 'নতুন কাজ'} onClose={onClose}>
      {err && <Banner kind="error">{err}</Banner>}
      <Field label="শিরোনাম" required>
        <input className={inputClass} value={v.title ?? ''} onChange={(e) => set('title', e.target.value)} placeholder="যেমন: বন্যায় কৃষকের ক্ষতি — সরেজমিন প্রতিবেদন" />
      </Field>
      <Field label="কী করতে হবে">
        <textarea className={inputClass} rows={3} value={v.brief ?? ''} onChange={(e) => set('brief', e.target.value)} placeholder={'কার সঙ্গে কথা বলতে হবে, কী ছবি/ভিডিও লাগবে…'} />
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="ধরন">
          <select className={inputClass} value={v.kind ?? 'news'} onChange={(e) => set('kind', e.target.value)}>
            {Object.entries(kinds).map(([k, l]) => (
              <option key={k} value={k}>
                {l}
              </option>
            ))}
          </select>
        </Field>
        <Field label="কতজন নিতে পারবে">
          <input type="number" min={1} max={50} className={inputClass} value={v.slots ?? 1} onChange={(e) => set('slots', Number(e.target.value))} />
        </Field>
        <Field label="জেলা (খালি = সারা দেশ)">
          <select className={inputClass} value={v.district ?? ''} onChange={(e) => setV((o) => ({ ...o, district: e.target.value, upazila: '' }))}>
            <option value="">সারা দেশ</option>
            {districts.map((d) => (
              <option key={d} value={d}>
                {d}
              </option>
            ))}
          </select>
        </Field>
        <Field label="উপজেলা">
          <select className={inputClass} value={v.upazila ?? ''} onChange={(e) => set('upazila', e.target.value)} disabled={!ups.length}>
            <option value="">পুরো জেলা</option>
            {ups.map((u) => (
              <option key={u} value={u}>
                {u}
              </option>
            ))}
          </select>
        </Field>
        <Field label="সম্মানী (৳)">
          <input type="number" min={0} className={inputClass} value={v.fee ?? 0} onChange={(e) => set('fee', Number(e.target.value))} />
        </Field>
        <Field label="যাতায়াত ভাতা (৳)">
          <input type="number" min={0} className={inputClass} value={v.travel ?? 0} onChange={(e) => set('travel', Number(e.target.value))} />
        </Field>
      </div>
      <Field label="শেষ সময়">
        <input type="datetime-local" className={inputClass} value={localDl} onChange={(e) => set('deadline', e.target.value || null)} />
      </Field>
      <label className="flex items-center gap-2 mb-4 text-[13.5px]">
        <input type="checkbox" checked={!!v.urgent} onChange={(e) => set('urgent', e.target.checked)} /> জরুরি (তালিকার শুরুতে, লাল চিহ্ন)
      </label>
      <p className="text-[12px] text-neutral-500 mb-3 leading-relaxed">চালু করলেই ওই জেলার সব সংবাদদাতার ফোনে নোটিশ যাবে। কাজ গ্রহণ করলে সম্মানী ও যাতায়াত ভাতা তাঁর আয়ে যোগ হবে।</p>
      <Button onClick={() => void save()} disabled={busy} className="w-full">
        {busy ? 'রাখা হচ্ছে…' : v.id ? 'হালনাগাদ' : 'কাজ চালু করুন'}
      </Button>
    </Modal>
  )
}

const CLAIM: Record<string, { t: string; tone: 'neutral' | 'amber' | 'emerald' | 'red' | 'blue' }> = {
  taken: { t: 'কাজ করছেন', tone: 'blue' },
  submitted: { t: 'জমা দিয়েছেন', tone: 'amber' },
  paid: { t: 'গৃহীত', tone: 'emerald' },
  rejected: { t: 'ফেরত', tone: 'red' },
}

function Jobs({ desk, reload, onEdit, setMsg }: { desk: Desk; reload: () => void; onEdit: (j: Job) => void; setMsg: (m: { k: 'error' | 'success'; t: string } | null) => void }) {
  const [busy, setBusy] = useState(0)
  const navigate = useNavigate()
  const act = async (fn: () => Promise<unknown>, id: number, ok: string) => {
    setBusy(id)
    setMsg(null)
    try {
      await fn()
      setMsg({ k: 'success', t: ok })
      reload()
    } catch (e) {
      setMsg({ k: 'error', t: describeError(e).message })
    } finally {
      setBusy(0)
    }
  }
  if (!desk.jobs.length)
    return (
      <Card className="p-6 text-center">
        <p className="text-[15px] font-semibold text-neutral-800">এখনো কোনো কাজ দেওয়া হয়নি</p>
        <p className="text-[13px] text-neutral-500 mt-1 max-w-md mx-auto leading-relaxed">
          যে খবরটা দরকার, সেটা টাকাসহ কাজ হিসেবে দিন — ওই এলাকার সংবাদদাতারা সঙ্গে সঙ্গে ফোনে জানবেন। মাঠে যাওয়ার খরচ নিজের পকেট থেকে দিতে হয় বলে অনেক উপজেলা প্রতিনিধি মাঠে যেতে পারেন না; যাতায়াত ভাতা সেই বাধা সরায়।
        </p>
      </Card>
    )
  return (
    <div className="grid gap-3">
      {desk.jobs.map((j) => (
        <Card key={j.id} className={`p-4 ${j.urgent && j.status === 'open' ? 'ring-1 ring-red-300' : ''}`}>
          <div className="flex items-start gap-3">
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-1.5 flex-wrap mb-1">
                {j.urgent && <Badge tone="red">জরুরি</Badge>}
                <Badge tone="blue">{j.kind_label}</Badge>
                <Badge tone={j.status === 'open' ? 'emerald' : 'neutral'}>{j.status === 'open' ? 'খোলা' : j.status === 'closed' ? 'বন্ধ' : 'বাতিল'}</Badge>
                <span className="text-[12px] text-neutral-500">📍 {j.upazila ? `${j.upazila}, ${j.district}` : j.district || 'সারা দেশ'}</span>
                {j.deadline && <span className="text-[12px] text-neutral-500">· ⏰ {bnDate(j.deadline)}</span>}
              </div>
              <p className="text-[15px] font-bold text-neutral-900">{j.title}</p>
              {j.brief && <p className="text-[13px] text-neutral-600 mt-0.5 whitespace-pre-line line-clamp-3">{j.brief}</p>}
            </div>
            <div className="text-right shrink-0">
              <p className="text-[17px] font-bold text-emerald-700">{taka(j.fee + j.travel)}</p>
              {j.travel > 0 && <p className="text-[11.5px] text-neutral-500">যাতায়াত {taka(j.travel)}</p>}
              <p className="text-[11.5px] text-neutral-500">{bnNumber(j.slots - j.left)}/{bnNumber(j.slots)} নেওয়া</p>
            </div>
          </div>
          {j.claims.length > 0 && (
            <div className="mt-3 border-t border-neutral-100 pt-3 grid gap-2">
              {j.claims.map((c) => (
                <div key={c.id} className="rounded-xl bg-neutral-50 px-3 py-2.5" data-testid="claim-row">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-[13.5px] font-semibold text-neutral-900">{c.name}</span>
                    <span className="text-[12px] text-neutral-500">{c.area}</span>
                    {c.phone && (
                      <a href={`tel:${c.phone}`} className="text-[12px] text-brand-600">
                        📞 {c.phone}
                      </a>
                    )}
                    <Badge tone={CLAIM[c.status]?.tone ?? 'neutral'}>{CLAIM[c.status]?.t ?? c.status}</Badge>
                    {c.status === 'paid' && c.paid > 0 && <span className="text-[12px] font-bold text-emerald-700">{taka(c.paid)}</span>}
                  </div>
                  {(c.post_title || c.link || c.note) && (
                    <div className="text-[12.5px] text-neutral-600 mt-1 space-y-0.5">
                      {c.post_id && (
                        <button type="button" className="text-brand-600 underline" onClick={() => navigate(`/app/write/${c.post_id}`)}>
                          সংবাদ: {c.post_title || `#${c.post_id}`}
                        </button>
                      )}
                      {c.link && (
                        <a href={c.link} target="_blank" rel="noreferrer noopener" className="block text-brand-600 truncate">
                          {c.link}
                        </a>
                      )}
                      {c.note && <p className="whitespace-pre-line">{c.note}</p>}
                    </div>
                  )}
                  {c.status === 'submitted' && (
                    <div className="flex gap-2 mt-2">
                      <Button
                        size="sm"
                        variant="success"
                        disabled={busy === c.id || (!desk.can_pay && j.fee + j.travel > 0)}
                        onClick={() => void act(() => DeskApi.decide(c.id, { action: 'approve' }), c.id, `${c.name}-এর কাজ গৃহীত, ${taka(j.fee + j.travel)} আয়ে যোগ হয়েছে।`)}
                      >
                        ✓ গ্রহণ{j.fee + j.travel > 0 ? ` ও ${taka(j.fee + j.travel)} দিন` : ''}
                      </Button>
                      <Button
                        size="sm"
                        variant="danger"
                        disabled={busy === c.id}
                        onClick={() => {
                          const note = prompt('কী ঠিক করতে হবে? (তিনি নোটিশে দেখবেন)') ?? ''
                          void act(() => DeskApi.decide(c.id, { action: 'reject', note }), c.id, 'ফেরত পাঠানো হয়েছে।')
                        }}
                      >
                        ফেরত পাঠান
                      </Button>
                      {!desk.can_pay && j.fee + j.travel > 0 && <span className="text-[11.5px] text-neutral-500 self-center">টাকা দিতে পাবলিশার লাগবে</span>}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
          <div className="flex gap-2 mt-3">
            {j.status === 'open' ? (
              <>
                <Button size="sm" variant="secondary" onClick={() => onEdit(j)}>
                  সম্পাদনা
                </Button>
                <Button size="sm" variant="secondary" disabled={busy === -j.id} onClick={() => void act(() => DeskApi.save(j.id, { status: 'closed' }), -j.id, 'কাজটি বন্ধ হয়েছে।')}>
                  বন্ধ করুন
                </Button>
                <Button size="sm" variant="danger" disabled={busy === -j.id} onClick={() => confirm('কাজটি বাতিল করবেন?') && void act(() => DeskApi.save(j.id, { status: 'cancelled' }), -j.id, 'কাজটি বাতিল হয়েছে।')}>
                  বাতিল
                </Button>
              </>
            ) : (
              <Button size="sm" variant="ghost" disabled={busy === -j.id} onClick={() => void act(() => DeskApi.save(j.id, { status: 'open' }), -j.id, 'কাজটি আবার খোলা হয়েছে।')}>
                আবার খুলুন
              </Button>
            )}
          </div>
        </Card>
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------

function SafetyBoard({ rows, reload }: { rows: SafetyRow[]; reload: () => void }) {
  const [busy, setBusy] = useState(0)
  const live = rows.filter((r) => r.status === 'active' || r.status === 'overdue')
  const past = rows.filter((r) => !(r.status === 'active' || r.status === 'overdue'))
  const hot = (r: SafetyRow) => r.kind === 'sos' || r.status === 'overdue'
  const resolve = async (r: SafetyRow) => {
    setBusy(r.id)
    try {
      await DeskApi.resolve(r.id)
      reload()
    } finally {
      setBusy(0)
    }
  }
  return (
    <div>
      {live.length === 0 && <EmptyState>এখন কেউ বিপদ-সংকেত দেননি, চলমান চেক-ইনও নেই।</EmptyState>}
      <div className="grid gap-3 md:grid-cols-2">
        {live
          .sort((a, b) => Number(hot(b)) - Number(hot(a)))
          .map((r) => (
            <Card key={r.id} className={`p-4 ${hot(r) ? 'border-red-300 bg-red-50' : ''}`}>
              <div className="flex items-center gap-3">
                <img src={r.avatar} alt="" className="w-11 h-11 rounded-full object-cover bg-neutral-200" />
                <div className="flex-1 min-w-0">
                  <p className="text-[15px] font-bold text-neutral-900">
                    {r.kind === 'sos' ? '🚨 SOS' : r.status === 'overdue' ? '⏰ ফেরেননি' : '📍 চেক-ইন'} · {r.name}
                  </p>
                  <p className="text-[12.5px] text-neutral-600">
                    {r.area} · {ago(r.created_at)}
                  </p>
                </div>
              </div>
              {(r.place || r.note) && <p className="text-[13px] text-neutral-700 mt-2">{[r.place, r.note].filter(Boolean).join(' — ')}</p>}
              {r.kind === 'checkin' && r.due_at && <p className="text-[12.5px] text-neutral-500 mt-1">ফেরার কথা: {new Date(r.due_at).toLocaleTimeString('bn-BD', { hour: 'numeric', minute: '2-digit' })}</p>}
              {r.emergency && <p className="text-[12.5px] text-neutral-600 mt-1">জরুরি যোগাযোগ: {r.emergency}</p>}
              <div className="flex flex-wrap gap-2 mt-3">
                {r.phone && (
                  <a href={`tel:${r.phone}`} className="rounded-lg bg-red-600 text-white px-3 py-1.5 text-[13px] font-semibold">
                    📞 ফোন করুন
                  </a>
                )}
                {r.lat != null && r.lng != null && (
                  <a href={`https://maps.google.com/?q=${r.lat},${r.lng}`} target="_blank" rel="noreferrer noopener" className="rounded-lg bg-white border border-neutral-200 px-3 py-1.5 text-[13px] font-semibold text-neutral-800">
                    🗺️ ম্যাপে দেখুন
                  </a>
                )}
                <Button size="sm" variant="secondary" disabled={busy === r.id} onClick={() => void resolve(r)}>
                  ✓ খোঁজ নিয়েছি
                </Button>
              </div>
            </Card>
          ))}
      </div>
      {past.length > 0 && (
        <>
          <h3 className="text-[13px] font-semibold text-neutral-500 mt-6 mb-2">গত ৭ দিন</h3>
          <Card className="divide-y divide-neutral-100">
            {past.map((r) => (
              <div key={r.id} className="px-4 py-2.5 text-[13px] text-neutral-600 flex gap-2">
                <span>{r.kind === 'sos' ? '🚨' : '📍'}</span>
                <span className="flex-1">
                  {r.name} — {r.place || r.area}
                </span>
                <span className="text-neutral-400">{r.status === 'safe' ? 'নিজে নিরাপদ জানিয়েছেন' : 'ডেস্ক দেখেছে'} · {ago(r.created_at)}</span>
              </div>
            ))}
          </Card>
        </>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------

function Coverage({ onJob }: { onJob: (district: string, upazila?: string) => void }) {
  const [data, setData] = useState<{ rows: CovRow[]; unknown: string[]; total: number } | null>(null)
  const [err, setErr] = useState<unknown>(null)
  const [filter, setFilter] = useState<'all' | 'empty' | 'thin'>('empty')
  const [open, setOpen] = useState('')
  const navigate = useNavigate()
  const load = useCallback(() => {
    setErr(null)
    DeskApi.coverage().then(setData, setErr)
  }, [])
  useEffect(load, [load])
  if (err) return <ErrorRetry error={err} onRetry={load} />
  if (!data) return <Spinner className="mt-10" />
  const rows = data.rows
  const covered = rows.filter((r) => r.people > 0).length
  const upTot = rows.reduce((a, r) => a + r.upazila_total, 0)
  const upHave = rows.reduce((a, r) => a + r.upazila_have, 0)
  const shown = rows.filter((r) => (filter === 'empty' ? r.people === 0 : filter === 'thin' ? r.people > 0 && r.upazila_have < r.upazila_total / 2 : true))
  const byDiv = shown.reduce<Record<string, CovRow[]>>((m, r) => ((m[r.division] ??= []).push(r), m), {})
  const poster = (place: string, level: 'district' | 'upazila') => {
    const fill =
      level === 'district'
        ? { headline: `${place} জেলা প্রতিনিধি নিয়োগ`, highlight: `${place} জেলার সব উপজেলায়`, format: 'portrait' }
        : { headline: `${place} উপজেলা প্রতিনিধি নিয়োগ`, highlight: `${place} উপজেলায় আমাদের প্রতিনিধি হোন`, format: 'portrait' }
    navigate(`/app/stationery?d=social_recruit&p=${level === 'district' ? 1 : 2}&fill=${encodeURIComponent(JSON.stringify(fill))}`)
  }
  const tone = (r: CovRow) => (r.people === 0 ? 'bg-red-500' : r.upazila_have / Math.max(1, r.upazila_total) < 0.5 ? 'bg-amber-400' : 'bg-emerald-500')
  return (
    <div>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <KpiCard label="মোট সংবাদদাতা" value={bnNumber(data.total)} />
        <KpiCard label="জেলায় প্রতিনিধি আছে" value={`${bnNumber(covered)}/${bnNumber(rows.length)}`} tint="bg-emerald-50 text-emerald-600" />
        <KpiCard label="উপজেলায় প্রতিনিধি আছে" value={`${bnNumber(upHave)}/${bnNumber(upTot)}`} tint="bg-amber-50 text-amber-600" />
        <KpiCard label="প্রতিনিধিহীন জেলা" value={bnNumber(rows.length - covered)} tint="bg-red-50 text-red-600" />
      </div>
      <Card className="p-4 mb-5">
        <p className="text-[13px] font-semibold text-neutral-700 mb-2">৬৪ জেলা এক নজরে</p>
        <div className="flex flex-wrap gap-1" data-testid="coverage-grid">
          {rows.map((r) => (
            <button
              key={r.district}
              type="button"
              title={`${r.district}: ${r.people} জন · উপজেলা ${r.upazila_have}/${r.upazila_total}`}
              onClick={() => {
                setFilter('all')
                setOpen(r.district)
              }}
              className={`px-2 py-1 rounded-md text-[11.5px] font-semibold text-white ${tone(r)}`}
            >
              {r.district}
            </button>
          ))}
        </div>
        <p className="text-[11.5px] text-neutral-500 mt-2">🟥 প্রতিনিধি নেই · 🟨 অর্ধেকের কম উপজেলায় আছে · 🟩 ভালো। “সক্রিয়” = গত ৬০ দিনে অন্তত একটি সংবাদ/ভিডিও।</p>
      </Card>
      <div className="flex gap-1.5 mb-3">
        {(
          [
            ['empty', 'প্রতিনিধিহীন জেলা'],
            ['thin', 'কম উপজেলা'],
            ['all', 'সব জেলা'],
          ] as const
        ).map(([k, l]) => (
          <button key={k} type="button" onClick={() => setFilter(k)} className={`px-3 py-1.5 rounded-full text-[12.5px] font-semibold ${filter === k ? 'bg-brand-600 text-white' : 'bg-neutral-100 text-neutral-700'}`}>
            {l}
          </button>
        ))}
      </div>
      {shown.length === 0 && <EmptyState>এই তালিকায় কিছু নেই — দারুণ!</EmptyState>}
      {Object.entries(byDiv).map(([div, list]) => (
        <div key={div} className="mb-4">
          <p className="text-[12.5px] font-bold text-neutral-500 mb-1.5">{div} বিভাগ</p>
          <Card className="divide-y divide-neutral-100">
            {list.map((r) => (
              <div key={r.district} className="px-4 py-3">
                <div className="flex items-center gap-3 flex-wrap">
                  <button type="button" className="flex-1 min-w-[160px] text-left" onClick={() => setOpen(open === r.district ? '' : r.district)}>
                    <p className="text-[14px] font-semibold text-neutral-900">{r.district}</p>
                    <p className="text-[12px] text-neutral-500">
                      {bnNumber(r.people)} জন ({bnNumber(r.active)} সক্রিয়) · উপজেলা {bnNumber(r.upazila_have)}/{bnNumber(r.upazila_total)}
                    </p>
                    <div className="h-1.5 rounded-full bg-neutral-100 mt-1.5 max-w-[240px] overflow-hidden">
                      <div className={`h-full ${tone(r)}`} style={{ width: `${Math.round((100 * r.upazila_have) / Math.max(1, r.upazila_total))}%` }} />
                    </div>
                  </button>
                  <Button size="sm" variant="secondary" onClick={() => poster(r.district, 'district')}>
                    📣 নিয়োগ পোস্টার
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => onJob(r.district)}>
                    কাজ দিন
                  </Button>
                </div>
                {open === r.district && r.upazila_empty.length > 0 && (
                  <div className="mt-2.5 flex flex-wrap gap-1.5">
                    <span className="text-[12px] text-neutral-500 w-full">প্রতিনিধি নেই এমন উপজেলা — চাপ দিলে সেই উপজেলার নিয়োগ পোস্টার:</span>
                    {r.upazila_empty.map((u) => (
                      <button key={u} type="button" onClick={() => poster(u, 'upazila')} className="px-2 py-1 rounded-md bg-red-50 text-red-700 text-[12px] font-medium">
                        {u}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </Card>
        </div>
      ))}
      {data.unknown.length > 0 && <p className="text-[12px] text-neutral-500 mt-2">প্রোফাইলে অচেনা জেলার নাম আছে (বানান মিলছে না): {data.unknown.join(', ')}</p>}
    </div>
  )
}
