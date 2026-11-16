import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { apiRequest, describeError, SubmissionsApi, type Submission } from '../lib/api'
import { Banner, Button, ErrorRetry, Field as Row, inputClass, Segmented, Spinner, TopBar, bnDate, bnNumber } from '../components/ui'
import { useAuth } from '../lib/AuthContext'

/**
 * r44 — মাঠ: টাকাসহ কাজ, নিরাপত্তা (SOS + চেক-ইন) ও তথ্য অধিকার সহকারী.
 * Server: NROS_Field (newsroom-os 1.27.0).
 */

type Tab = 'jobs' | 'safety' | 'rti'

export interface Assignment {
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
  status: string
  mine?: boolean
  nearby?: boolean
}
interface Claim {
  id: number
  assign_id: number
  status: 'taken' | 'submitted' | 'paid' | 'rejected' | 'dropped'
  note: string
  post_id: number | null
  post_title: string
  link: string
  desk_note: string
  paid: number
  assignment: Assignment
}
export interface Safety {
  id: number
  kind: 'sos' | 'checkin'
  status: 'active' | 'overdue' | 'safe' | 'resolved'
  place: string
  note: string
  due_at: string | null
  created_at: string
}
interface Board {
  area: { district: string; upazila: string }
  board: Assignment[]
  mine: Claim[]
  earned: number
  safety: Safety[]
  hours: number[]
}
interface Rti {
  id: number
  office: string
  officer: string
  address: string
  subject: string
  info: string
  method: string
  status: string
  sent_on: string | null
  answered_on: string | null
  appeal_on: string | null
  complaint_on: string | null
  result: string
  dates: { answer_due?: string; answer_due_long?: string; appeal_by?: string; appeal_answer_due?: string; complaint_by?: string }
}
interface RtiMe {
  name: string
  phone: string
  email: string
  area: string
  address: string
}

export const FieldApi = {
  board: () => apiRequest<Board>('/field'),
  take: (id: number) => apiRequest<{ ok: true }>(`/field/assignments/${id}/take`, { method: 'POST' }),
  submit: (id: number, body: { post_id?: number; link?: string; note?: string }) => apiRequest<{ ok: true }>(`/field/claims/${id}/submit`, { method: 'POST', body }),
  drop: (id: number) => apiRequest<{ ok: true }>(`/field/claims/${id}/drop`, { method: 'POST' }),
  sos: (body: Record<string, unknown>) => apiRequest<{ ok: true; id: number }>('/field/sos', { method: 'POST', body }),
  checkin: (body: Record<string, unknown>) => apiRequest<{ ok: true; safety: Safety[] }>('/field/checkin', { method: 'POST', body }),
  safe: (id: number) => apiRequest<{ ok: true; safety: Safety[] }>(`/field/safety/${id}/safe`, { method: 'POST' }),
  extend: (id: number, hours: number) => apiRequest<{ ok: true; safety: Safety[] }>(`/field/safety/${id}/extend`, { method: 'POST', body: { hours } }),
  rti: () => apiRequest<{ items: Rti[]; me: RtiMe }>('/field/rti'),
  rtiSave: (id: number, body: Record<string, unknown>) => apiRequest<{ ok: true; item: Rti }>(id ? `/field/rti/${id}` : '/field/rti', { method: 'POST', body }),
  rtiDelete: (id: number) => apiRequest<{ ok: true }>(`/field/rti/${id}`, { method: 'DELETE' }),
}

const taka = (n: number) => `৳${bnNumber(Math.round(n).toLocaleString('en-IN'))}`

function left(iso: string | null) {
  if (!iso) return ''
  const ms = new Date(iso).getTime() - Date.now()
  if (ms <= 0) return 'সময় শেষ'
  const h = Math.floor(ms / 3600000)
  if (h >= 48) return `${bnNumber(Math.floor(h / 24))} দিন বাকি`
  if (h >= 1) return `${bnNumber(h)} ঘণ্টা বাকি`
  return `${bnNumber(Math.max(1, Math.floor(ms / 60000)))} মিনিট বাকি`
}

const clock = (iso: string) => {
  const d = new Date(iso)
  const h = d.getHours()
  const m = String(d.getMinutes()).padStart(2, '0')
  return `${h < 12 ? 'সকাল' : h < 17 ? 'দুপুর' : h < 19 ? 'বিকেল' : 'রাত'} ${bnNumber(((h + 11) % 12) + 1)}:${bnNumber(m)}`
}

/** Phone location, if the person allows it (never blocks for long). */
function where(timeout = 8000): Promise<{ lat: number; lng: number } | null> {
  return new Promise((resolve) => {
    if (!('geolocation' in navigator)) return resolve(null)
    const t = setTimeout(() => resolve(null), timeout + 500)
    navigator.geolocation.getCurrentPosition(
      (p) => {
        clearTimeout(t)
        resolve({ lat: p.coords.latitude, lng: p.coords.longitude })
      },
      () => {
        clearTimeout(t)
        resolve(null)
      },
      { enableHighAccuracy: true, timeout, maximumAge: 60000 },
    )
  })
}

export default function FieldPage() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const tab = (['jobs', 'safety', 'rti'].includes(params.get('tab') || '') ? params.get('tab') : 'jobs') as Tab
  const [data, setData] = useState<Board | null>(null)
  const [err, setErr] = useState<unknown>(null)

  const load = useCallback(() => {
    setErr(null)
    FieldApi.board().then(setData, setErr)
  }, [])
  useEffect(load, [load])

  return (
    <div className="pb-8">
      <TopBar title="মাঠ" onBack={() => navigate('/app')} />
      <div className="px-4 pt-3">
        <Segmented
          ariaLabel="মাঠ"
          value={tab}
          onChange={(v) => setParams({ tab: v }, { replace: true })}
          options={[
            { value: 'jobs', label: 'কাজ ও আয়' },
            { value: 'safety', label: 'নিরাপত্তা' },
            { value: 'rti', label: 'তথ্য অধিকার' },
          ]}
        />
      </div>
      {tab === 'rti' ? (
        <RtiTab />
      ) : err ? (
        <ErrorRetry error={err} onRetry={load} />
      ) : !data ? (
        <Spinner />
      ) : tab === 'jobs' ? (
        <JobsTab data={data} reload={load} />
      ) : (
        <SafetyTab items={data.safety} hours={data.hours} onChange={(safety) => setData({ ...data, safety })} />
      )}
    </div>
  )
}

// ---------------------------------------------------------------------
// কাজ ও আয়
// ---------------------------------------------------------------------

const claimLabel: Record<string, { t: string; c: string }> = {
  taken: { t: 'আপনি নিয়েছেন', c: 'bg-sky-50 text-sky-700' },
  submitted: { t: 'জমা — ডেস্ক দেখছে', c: 'bg-amber-50 text-amber-700' },
  paid: { t: 'গৃহীত · টাকা আয়ে যোগ', c: 'bg-emerald-50 text-emerald-700' },
  rejected: { t: 'ফেরত এসেছে', c: 'bg-red-50 text-red-700' },
  dropped: { t: 'ছেড়ে দিয়েছেন', c: 'bg-neutral-100 text-neutral-500' },
}

function Money({ a }: { a: Assignment }) {
  if (a.fee + a.travel <= 0) return <span className="text-[12.5px] text-neutral-500">সম্মানী নেই</span>
  return (
    <span className="flex items-baseline gap-1.5">
      <span className="text-[17px] font-bold text-emerald-700">{taka(a.fee + a.travel)}</span>
      {a.travel > 0 && <span className="text-[11.5px] text-neutral-500">(যাতায়াত {taka(a.travel)} সহ)</span>}
    </span>
  )
}

function JobCard({ a, children }: { a: Assignment; children?: React.ReactNode }) {
  const area = a.upazila ? `${a.upazila}, ${a.district}` : a.district || 'সারা দেশ'
  return (
    <div className={`rounded-2xl border p-3.5 mb-3 ${a.urgent ? 'border-red-200 bg-red-50/40' : 'border-neutral-100 bg-white'}`} data-testid="job-card">
      <div className="flex items-center gap-1.5 mb-1.5 flex-wrap">
        {a.urgent && <span className="text-[11px] font-bold px-2 py-0.5 rounded-full bg-red-600 text-white">জরুরি</span>}
        <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-brand-50 text-brand-700">{a.kind_label}</span>
        <span className="text-[11.5px] text-neutral-500">📍 {area}</span>
        {a.nearby && <span className="text-[11px] text-emerald-700 font-semibold">· আপনার উপজেলা</span>}
      </div>
      <p className="text-[15px] font-bold text-neutral-900 leading-snug">{a.title}</p>
      {a.brief && <p className="text-[13px] text-neutral-600 leading-relaxed mt-1 whitespace-pre-line">{a.brief}</p>}
      <div className="flex items-center justify-between mt-2.5 gap-2">
        <Money a={a} />
        <span className="text-[11.5px] text-neutral-500 text-right">
          {a.deadline && a.status === 'open' && <span className="block">⏰ {left(a.deadline)}</span>}
          {a.slots > 1 && <span className="block">{bnNumber(a.left)}/{bnNumber(a.slots)} জায়গা খালি</span>}
        </span>
      </div>
      {children}
    </div>
  )
}

function JobsTab({ data, reload }: { data: Board; reload: () => void }) {
  const [busy, setBusy] = useState(0)
  const [msg, setMsg] = useState<{ k: 'error' | 'success'; t: string } | null>(null)
  const [sending, setSending] = useState<Claim | null>(null)
  const active = data.mine.filter((c) => c.status !== 'dropped')
  const mineArea = data.board.filter((a) => a.mine)
  const others = data.board.filter((a) => !a.mine)

  const take = async (a: Assignment) => {
    setBusy(a.id)
    setMsg(null)
    try {
      await FieldApi.take(a.id)
      setMsg({ k: 'success', t: 'কাজটি আপনার। শেষ করে “জমা দিন” চাপুন।' })
      reload()
    } catch (e) {
      setMsg({ k: 'error', t: describeError(e).message })
    } finally {
      setBusy(0)
    }
  }
  const drop = async (c: Claim) => {
    if (!confirm('কাজটি ছেড়ে দেবেন? অন্য কেউ নিতে পারবেন।')) return
    setBusy(-c.id)
    try {
      await FieldApi.drop(c.id)
      reload()
    } catch (e) {
      setMsg({ k: 'error', t: describeError(e).message })
    } finally {
      setBusy(0)
    }
  }

  return (
    <div className="px-4 pt-4">
      <div className="rounded-2xl bg-gradient-to-br from-emerald-600 to-teal-700 text-white p-4 mb-4">
        <p className="text-[12.5px] opacity-85">মাঠের কাজ থেকে এ পর্যন্ত আয়</p>
        <p className="text-[26px] font-bold leading-tight">{taka(data.earned)}</p>
        <p className="text-[12px] opacity-85 mt-1 leading-relaxed">ডেস্ক কাজ দিলে আগেই টাকা ও যাতায়াত ভাতা লেখা থাকে। কাজ গৃহীত হলে টাকা সরাসরি আপনার “আয়”-এ যোগ হয়।</p>
      </div>
      {msg && <Banner kind={msg.k}>{msg.t}</Banner>}

      {active.length > 0 && (
        <>
          <h2 className="text-[14px] font-bold text-neutral-800 mb-2">আমার কাজ</h2>
          {active.map((c) => (
            <JobCard key={c.id} a={c.assignment}>
              <div className="mt-2.5 pt-2.5 border-t border-neutral-100">
                <span className={`text-[11.5px] font-semibold px-2 py-0.5 rounded-full ${claimLabel[c.status]?.c}`}>{claimLabel[c.status]?.t}</span>
                {c.status === 'paid' && c.paid > 0 && <span className="ml-2 text-[12.5px] font-bold text-emerald-700">+{taka(c.paid)}</span>}
                {c.desk_note && <p className="text-[12.5px] text-neutral-600 mt-1.5">ডেস্ক: {c.desk_note}</p>}
                {c.post_title && <p className="text-[12px] text-neutral-500 mt-1">সংবাদ: {c.post_title}</p>}
                {(c.status === 'taken' || c.status === 'rejected' || c.status === 'submitted') && (
                  <div className="flex gap-2 mt-2.5">
                    <Button full={false} className="flex-1 !py-2 !text-[13.5px]" onClick={() => setSending(c)}>
                      {c.status === 'submitted' ? 'আবার জমা দিন' : 'জমা দিন'}
                    </Button>
                    <Button full={false} variant="secondary" className="!py-2 !text-[13.5px]" disabled={busy === -c.id} onClick={() => void drop(c)}>
                      ছেড়ে দিন
                    </Button>
                  </div>
                )}
              </div>
            </JobCard>
          ))}
        </>
      )}

      <h2 className="text-[14px] font-bold text-neutral-800 mb-2 mt-1">
        খোলা কাজ {data.area.district && <span className="font-normal text-neutral-500 text-[12.5px]">· {data.area.district}</span>}
      </h2>
      {data.board.length === 0 && (
        <div className="rounded-2xl border border-dashed border-neutral-200 p-5 text-center text-[13px] text-neutral-500 mb-4">
          এখন কোনো খোলা কাজ নেই। নতুন কাজ এলে ফোনে নোটিশ যাবে।
        </div>
      )}
      {[...mineArea, ...others].map((a, i) => (
        <div key={a.id}>
          {i === mineArea.length && others.length > 0 && mineArea.length > 0 && <p className="text-[12px] text-neutral-500 mb-2">অন্য এলাকার কাজ</p>}
          <JobCard a={a}>
            <Button className="mt-3 !py-2.5" disabled={busy === a.id} onClick={() => void take(a)}>
              {busy === a.id ? 'নেওয়া হচ্ছে…' : 'কাজটি নিন'}
            </Button>
          </JobCard>
        </div>
      ))}
      {sending && (
        <SubmitSheet
          claim={sending}
          onClose={() => setSending(null)}
          onDone={() => {
            setSending(null)
            setMsg({ k: 'success', t: 'জমা হয়েছে। ডেস্ক দেখে টাকা দেবে।' })
            reload()
          }}
        />
      )}
    </div>
  )
}

function Sheet({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <div className="fixed inset-0 z-[60] bg-black/40 flex items-end justify-center" onClick={onClose}>
      <div className="w-full max-w-[480px] max-h-[88vh] overflow-y-auto rounded-t-3xl bg-white p-5 pb-[calc(20px+env(safe-area-inset-bottom))]" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center mb-3">
          <h3 className="flex-1 text-[16px] font-bold text-neutral-900">{title}</h3>
          <button type="button" onClick={onClose} className="text-neutral-400 text-[22px] leading-none px-1" aria-label="বন্ধ">
            ×
          </button>
        </div>
        {children}
      </div>
    </div>
  )
}

function SubmitSheet({ claim, onClose, onDone }: { claim: Claim; onClose: () => void; onDone: () => void }) {
  const [posts, setPosts] = useState<Submission[] | null>(null)
  const [postId, setPostId] = useState(claim.post_id ?? 0)
  const [link, setLink] = useState(claim.link)
  const [note, setNote] = useState(claim.note)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  useEffect(() => {
    SubmissionsApi.list().then((l) => setPosts(l.slice(0, 30)), () => setPosts([]))
  }, [])
  const send = async () => {
    setBusy(true)
    setErr('')
    try {
      await FieldApi.submit(claim.id, { post_id: postId || undefined, link: link.trim() || undefined, note: note.trim() || undefined })
      onDone()
    } catch (e) {
      setErr(describeError(e).message)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Sheet title="কাজ জমা দিন" onClose={onClose}>
      <p className="text-[13px] text-neutral-600 mb-3">{claim.assignment.title}</p>
      {err && <Banner kind="error">{err}</Banner>}
      <Row label="এই কাজের সংবাদ (অ্যাপে লেখা)">
        {posts === null ? (
          <p className="text-[12.5px] text-neutral-400">লোড হচ্ছে…</p>
        ) : (
          <select className={inputClass} value={postId} onChange={(e) => setPostId(Number(e.target.value))}>
            <option value={0}>— বাছুন (না থাকলে খালি রাখুন) —</option>
            {posts.map((p) => (
              <option key={p.id} value={p.id}>
                {p.title || '(শিরোনাম নেই)'}
              </option>
            ))}
          </select>
        )}
      </Row>
      <Row label="লিংক (ছবি/ভিডিওর ড্রাইভ লিংক ইত্যাদি)">
        <input className={inputClass} value={link} onChange={(e) => setLink(e.target.value)} placeholder="https://" inputMode="url" />
      </Row>
      <Row label="ডেস্ককে কী জানাবেন">
        <textarea className={inputClass} rows={3} value={note} onChange={(e) => setNote(e.target.value)} placeholder="কী করেছেন, কার সঙ্গে কথা বলেছেন…" />
      </Row>
      <Button disabled={busy} onClick={() => void send()}>
        {busy ? 'পাঠানো হচ্ছে…' : 'জমা দিন'}
      </Button>
    </Sheet>
  )
}

// ---------------------------------------------------------------------
// নিরাপত্তা
// ---------------------------------------------------------------------

function SafetyTab({ items, hours, onChange }: { items: Safety[]; hours: number[]; onChange: (s: Safety[]) => void }) {
  const { profile } = useAuth()
  const [place, setPlace] = useState('')
  const [note, setNote] = useState('')
  const [h, setH] = useState(2)
  const [em, setEm] = useState('')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState<{ k: 'error' | 'success' | 'info'; t: string } | null>(null)

  const checkin = async () => {
    if (!place.trim()) return setMsg({ k: 'error', t: 'কোথায় যাচ্ছেন লিখুন।' })
    setBusy('in')
    setMsg(null)
    try {
      const pos = await where(5000)
      const r = await FieldApi.checkin({ place: place.trim(), note: note.trim(), hours: h, emergency: em.trim(), ...(pos ?? {}) })
      onChange(r.safety)
      setPlace('')
      setNote('')
      setMsg({ k: 'success', t: `চেক-ইন হয়েছে। ${bnNumber(h)} ঘণ্টার মধ্যে “আমি নিরাপদ” না চাপলে ডেস্ক খোঁজ নেবে।` })
    } catch (e) {
      setMsg({ k: 'error', t: describeError(e).message })
    } finally {
      setBusy('')
    }
  }
  const safe = async (s: Safety) => {
    setBusy('s' + s.id)
    try {
      const r = await FieldApi.safe(s.id)
      onChange(r.safety)
      setMsg({ k: 'success', t: 'ভালো থাকুন। ডেস্ককে জানানো হয়েছে।' })
    } catch (e) {
      setMsg({ k: 'error', t: describeError(e).message })
    } finally {
      setBusy('')
    }
  }
  const extend = async (s: Safety) => {
    setBusy('e' + s.id)
    try {
      const r = await FieldApi.extend(s.id, 1)
      onChange(r.safety)
    } catch (e) {
      setMsg({ k: 'error', t: describeError(e).message })
    } finally {
      setBusy('')
    }
  }

  return (
    <div className="px-4 pt-4">
      {msg && <Banner kind={msg.k}>{msg.t}</Banner>}
      <SosButton onSent={(t) => setMsg({ k: 'success', t })} onError={(t) => setMsg({ k: 'error', t })} />

      {items.length > 0 && (
        <div className="mb-5">
          <h2 className="text-[14px] font-bold text-neutral-800 mb-2">চলমান</h2>
          {items.map((s) => (
            <div key={s.id} className={`rounded-2xl p-3.5 mb-2.5 border ${s.status === 'overdue' || s.kind === 'sos' ? 'border-red-200 bg-red-50' : 'border-neutral-100 bg-white'}`} data-testid="safety-item">
              <p className="text-[14px] font-bold text-neutral-900">
                {s.kind === 'sos' ? '🚨 SOS পাঠানো হয়েছে' : `📍 ${s.place}`}
              </p>
              <p className="text-[12.5px] text-neutral-600 mt-0.5">
                {s.kind === 'checkin' && s.due_at && (s.status === 'overdue' ? 'সময় পেরিয়েছে — ডেস্ককে জানানো হয়েছে' : `ফেরার সময় ${clock(s.due_at)} (${left(s.due_at)})`)}
                {s.kind === 'sos' && 'ডেস্কের সবাই নোটিশ পেয়েছে। নিরাপদ হলে নিচে চাপুন।'}
              </p>
              <div className="flex gap-2 mt-2.5">
                <Button full={false} className="flex-1 !py-2 !text-[13.5px] !bg-emerald-600" disabled={!!busy} onClick={() => void safe(s)}>
                  ✓ আমি নিরাপদ
                </Button>
                {s.kind === 'checkin' && (
                  <Button full={false} variant="secondary" className="!py-2 !text-[13.5px]" disabled={!!busy} onClick={() => void extend(s)}>
                    +১ ঘণ্টা
                  </Button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      <div className="rounded-2xl border border-neutral-100 p-4 mb-5">
        <h2 className="text-[15px] font-bold text-neutral-900">ঝুঁকির কাজে যাচ্ছেন? চেক-ইন করুন</h2>
        <p className="text-[12.5px] text-neutral-500 mt-0.5 mb-3 leading-relaxed">মিছিল, সংঘর্ষ, দখল বা অপরাধের খবরে যাওয়ার আগে লিখে রাখুন। সময়মতো “আমি নিরাপদ” না চাপলে ডেস্ক আপনাকে ফোন করবে।</p>
        <Row label="কোথায় যাচ্ছেন" required>
          <input className={inputClass} value={place} onChange={(e) => setPlace(e.target.value)} placeholder="যেমন: সদর উপজেলা পরিষদ, সংঘর্ষের খবরে" />
        </Row>
        <p className="text-[13px] font-medium text-neutral-600 mb-1.5">কত সময়ের মধ্যে ফিরবেন</p>
        <div className="flex flex-wrap gap-1.5 mb-4">
          {hours.map((x) => (
            <button key={x} type="button" onClick={() => setH(x)} className={`px-3 py-1.5 rounded-full text-[13px] font-semibold ${h === x ? 'bg-brand-600 text-white' : 'bg-neutral-100 text-neutral-700'}`}>
              {bnNumber(x)} ঘণ্টা
            </button>
          ))}
        </div>
        <Row label="কিছু জানাতে চাইলে">
          <input className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} placeholder="কার সঙ্গে যাচ্ছেন, কী নিয়ে কাজ…" />
        </Row>
        <Row label="জরুরি যোগাযোগ (পরিবারের কেউ)">
          <input className={inputClass} value={em} onChange={(e) => setEm(e.target.value)} placeholder={`যেমন: ভাই — ${profile?.phone || '01…'}`} />
        </Row>
        <Button disabled={busy === 'in'} onClick={() => void checkin()}>
          {busy === 'in' ? 'হচ্ছে…' : 'চেক-ইন করুন'}
        </Button>
        <p className="text-[11.5px] text-neutral-400 mt-2">ফোনের লোকেশন চালু থাকলে জায়গাটাও ডেস্ক দেখতে পাবে।</p>
      </div>

      <details className="rounded-2xl bg-neutral-50 p-4 text-[13px] text-neutral-700 leading-relaxed">
        <summary className="font-semibold text-neutral-900 cursor-pointer">মাঠে নিজেকে রক্ষার কয়েকটি অভ্যাস</summary>
        <ul className="list-disc pl-5 mt-2 space-y-1">
          <li>উত্তেজনার জায়গায় একা যাবেন না; সহকর্মীকে জানিয়ে যান।</li>
          <li>ভিড়ের কিনারে থাকুন, বের হওয়ার পথ আগে দেখে নিন।</li>
          <li>প্রেস কার্ড সঙ্গে রাখুন, তবে বিপদে আগে নিজের নিরাপত্তা।</li>
          <li>হুমকি পেলে তারিখ, সময়, নম্বর ও কথা লিখে রাখুন, স্ক্রিনশট রাখুন — ডেস্ককে জানান।</li>
          <li>ফোনের চার্জ ও ব্যালান্স রাখুন; ছবি-ভিডিও দ্রুত অফিসে পাঠিয়ে দিন।</li>
        </ul>
      </details>
    </div>
  )
}

/** Hold for about two seconds — a pocket tap must not send an SOS. */
function SosButton({ onSent, onError }: { onSent: (t: string) => void; onError: (t: string) => void }) {
  const [p, setP] = useState(0)
  const [sending, setSending] = useState(false)
  const timer = useRef<number | undefined>(undefined)
  const start = useRef(0)
  const stop = () => {
    window.clearInterval(timer.current)
    if (!sending) setP(0)
  }
  const fire = async () => {
    window.clearInterval(timer.current)
    setSending(true)
    try {
      navigator.vibrate?.(200)
      const pos = await where(6000)
      await FieldApi.sos({ ...(pos ?? {}), place: '' })
      onSent(pos ? 'SOS গেছে, আপনার লোকেশনসহ। ডেস্ক যোগাযোগ করবে। সম্ভব হলে নিরাপদ জায়গায় যান।' : 'SOS গেছে (লোকেশন পাওয়া যায়নি)। ডেস্ক যোগাযোগ করবে।')
    } catch (e) {
      onError(`SOS যায়নি: ${describeError(e).message} — সরাসরি অফিসে বা ৯৯৯-এ ফোন করুন।`)
    } finally {
      setSending(false)
      setP(0)
    }
  }
  const begin = () => {
    if (sending) return
    start.current = Date.now()
    window.clearInterval(timer.current)
    timer.current = window.setInterval(() => {
      const v = Math.min(1, (Date.now() - start.current) / 1800)
      setP(v)
      if (v >= 1) void fire()
    }, 40)
  }
  useEffect(() => () => window.clearInterval(timer.current), [])
  return (
    <div className="flex flex-col items-center mb-6">
      <button
        type="button"
        onPointerDown={begin}
        onPointerUp={stop}
        onPointerLeave={stop}
        onPointerCancel={stop}
        onContextMenu={(e) => e.preventDefault()}
        className="relative w-40 h-40 rounded-full bg-red-600 text-white shadow-xl shadow-red-600/30 select-none touch-none active:scale-[0.98] transition"
        data-testid="sos-button"
        aria-label="SOS — চেপে ধরে রাখুন"
      >
        <svg className="absolute inset-0 -rotate-90" viewBox="0 0 100 100">
          <circle cx="50" cy="50" r="46" fill="none" stroke="rgba(255,255,255,.25)" strokeWidth="5" />
          {p > 0 && <circle cx="50" cy="50" r="46" fill="none" stroke="#fff" strokeWidth="5" strokeDasharray={`${p * 289} 289`} strokeLinecap="round" />}
        </svg>
        <span className="relative block text-[34px] font-black tracking-wider">SOS</span>
        <span className="relative block text-[12px] opacity-90">{sending ? 'পাঠানো হচ্ছে…' : 'চেপে ধরে রাখুন'}</span>
      </button>
      <p className="text-[12.5px] text-neutral-500 text-center mt-3 leading-relaxed max-w-[300px]">
        হামলা, হুমকি বা আটকের মুখে দুই সেকেন্ড চেপে ধরুন — ডেস্কের সবার ফোনে সঙ্গে সঙ্গে জরুরি নোটিশ যাবে, লোকেশনসহ। জীবন ঝুঁকিতে থাকলে ৯৯৯-এ ফোন করুন।
      </p>
      <a href="tel:999" className="mt-2 text-[13px] font-bold text-red-600">📞 ৯৯৯-এ ফোন করুন</a>
    </div>
  )
}

// ---------------------------------------------------------------------
// তথ্য অধিকার সহকারী
// ---------------------------------------------------------------------

const RTI_STATUS: Record<string, { t: string; c: string }> = {
  draft: { t: 'খসড়া', c: 'bg-neutral-100 text-neutral-600' },
  sent: { t: 'জমা দিয়েছি', c: 'bg-sky-50 text-sky-700' },
  answered: { t: 'তথ্য পেয়েছি', c: 'bg-emerald-50 text-emerald-700' },
  refused: { t: 'দেয়নি / আংশিক', c: 'bg-red-50 text-red-700' },
  appeal: { t: 'আপিল করেছি', c: 'bg-amber-50 text-amber-700' },
  complaint: { t: 'কমিশনে অভিযোগ', c: 'bg-violet-50 text-violet-700' },
  closed: { t: 'শেষ', c: 'bg-neutral-100 text-neutral-500' },
}
const METHODS: Record<string, string> = {
  copy: 'ছাপানো / ফটোকপি',
  email: 'ইমেইলে',
  inspect: 'সরেজমিনে দেখে',
  cd: 'সিডি / পেনড্রাইভে',
  print: 'লিখিত নোট নিয়ে',
}
const dshort = (d?: string | null) => (d ? bnDate(d) : '—')
const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const longDate = (iso: string) => {
  const [y, m, d] = iso.split('-').map(Number)
  const months = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর']
  return `${bnNumber(d)} ${months[m - 1]} ${bnNumber(y)}`
}

function letter(r: Partial<Rti>, me: RtiMe) {
  const lines = [
    'বরাবর',
    `দায়িত্বপ্রাপ্ত কর্মকর্তা${r.officer ? `, ${r.officer}` : ''}`,
    r.office || '……………………',
    r.address || '',
    '',
    `বিষয়: তথ্য অধিকার আইন, ২০০৯-এর ধারা ৮ অনুযায়ী তথ্য প্রাপ্তির আবেদন${r.subject ? ` — ${r.subject}` : ''}।`,
    '',
    'জনাব,',
    'তথ্য অধিকার আইন, ২০০৯ অনুযায়ী নিচের তথ্য পাওয়ার জন্য আবেদন করছি।',
    '',
    `১. আবেদনকারীর নাম: ${me.name}`,
    `   ঠিকানা: ${me.address || me.area || '……………………'}`,
    `   ফোন: ${me.phone || '……………'}${me.email ? `   ইমেইল: ${me.email}` : ''}`,
    '২. যে তথ্য চাই (স্পষ্ট ও নির্দিষ্টভাবে):',
    ...(r.info || '……………………').split('\n').map((l) => `   ${l}`),
    `৩. তথ্য যে পদ্ধতিতে পেতে চাই: ${METHODS[r.method || 'copy']}`,
    '৪. তথ্যের জন্য নির্ধারিত মূল্য পরিশোধে আমি প্রস্তুত।',
    '',
    'অতএব, আইনে নির্ধারিত সময়ের মধ্যে উল্লিখিত তথ্য সরবরাহের জন্য অনুরোধ করছি।',
    '',
    `তারিখ: ${longDate(r.sent_on || today())}`,
    '',
    'বিনীত,',
    '',
    '(স্বাক্ষর)',
    me.name,
  ]
  return lines.join('\n')
}

function RtiTab() {
  const [items, setItems] = useState<Rti[] | null>(null)
  const [me, setMe] = useState<RtiMe | null>(null)
  const [err, setErr] = useState<unknown>(null)
  const [edit, setEdit] = useState<Partial<Rti> | null>(null)
  const load = useCallback(() => {
    setErr(null)
    FieldApi.rti().then((r) => {
      setItems(r.items)
      setMe(r.me)
    }, setErr)
  }, [])
  useEffect(load, [load])
  if (err) return <ErrorRetry error={err} onRetry={load} />
  if (!items || !me) return <Spinner />
  return (
    <div className="px-4 pt-4">
      <div className="rounded-2xl bg-gradient-to-br from-[#0b2a55] to-[#025AB2] text-white p-4 mb-4">
        <p className="text-[15px] font-bold">তথ্য অধিকার সহকারী</p>
        <p className="text-[12.5px] opacity-90 mt-1 leading-relaxed">সরকারি অফিসের তথ্য দিয়ে অনুসন্ধানী খবর। আবেদনপত্র এখানেই তৈরি হয়, আর কবে উত্তর আসার কথা, কবে আপিল করবেন — অ্যাপ মনে রাখবে ও নোটিশ দেবে।</p>
        <Button full={false} className="mt-3 !bg-white !text-brand-700 !py-2 !text-[13.5px]" onClick={() => setEdit({ method: 'copy', status: 'draft' })}>
          + নতুন আবেদন
        </Button>
      </div>
      {items.length === 0 && <p className="text-[13px] text-neutral-500 text-center py-6">এখনো কোনো আবেদন নেই।</p>}
      {items.map((r) => {
        const next =
          r.status === 'sent' && r.dates.answer_due
            ? `উত্তরের শেষ দিন ${dshort(r.dates.answer_due)}`
            : (r.status === 'refused' || (r.status === 'sent' && r.dates.appeal_by)) && r.dates.appeal_by
              ? `আপিলের শেষ দিন ${dshort(r.dates.appeal_by)}`
              : r.status === 'appeal' && r.dates.complaint_by
                ? `কমিশনে অভিযোগের শেষ দিন ${dshort(r.dates.complaint_by)}`
                : ''
        return (
          <button key={r.id} type="button" onClick={() => setEdit(r)} className="w-full text-left rounded-2xl border border-neutral-100 p-3.5 mb-2.5 active:bg-neutral-50" data-testid="rti-item">
            <div className="flex items-center gap-2">
              <span className={`text-[11px] font-semibold px-2 py-0.5 rounded-full ${RTI_STATUS[r.status]?.c}`}>{RTI_STATUS[r.status]?.t}</span>
              {next && <span className="text-[11.5px] text-neutral-500">⏰ {next}</span>}
            </div>
            <p className="text-[14.5px] font-bold text-neutral-900 mt-1.5">{r.subject || r.office}</p>
            <p className="text-[12.5px] text-neutral-500">{r.office}</p>
          </button>
        )
      })}
      <p className="text-[11.5px] text-neutral-400 leading-relaxed mt-3">
        সময়সীমা তথ্য অধিকার আইন, ২০০৯ অনুযায়ী হিসাব করা: উত্তর ২০ কার্যদিবসে (অন্য ইউনিটের তথ্য হলে ৩০), না পেলে ৩০ দিনের মধ্যে আপিল, আপিলের নিষ্পত্তি ১৫ দিনে, তারপর ৩০ দিনের মধ্যে তথ্য কমিশনে অভিযোগ। শুক্র-শনি বাদ দেওয়া হয়েছে, সরকারি ছুটি নয় — তাই আসল তারিখ দু-এক দিন পরে হতে পারে। গুরুত্বপূর্ণ ক্ষেত্রে আইনটি বা আইনজীবীর সঙ্গে মিলিয়ে নিন।
      </p>
      {edit && (
        <RtiEditor
          r={edit}
          me={me}
          onClose={() => setEdit(null)}
          onSaved={(x, gone) => {
            setItems((l) => (gone ? (l ?? []).filter((i) => i.id !== x.id) : [x, ...(l ?? []).filter((i) => i.id !== x.id)]))
            setEdit(gone ? null : x)
          }}
        />
      )}
    </div>
  )
}

function RtiEditor({ r, me, onClose, onSaved }: { r: Partial<Rti>; me: RtiMe; onClose: () => void; onSaved: (r: Rti, gone?: boolean) => void }) {
  const [v, setV] = useState<Partial<Rti>>(r)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [view, setView] = useState(false)
  const [copied, setCopied] = useState(false)
  const set = (k: keyof Rti, x: string) => setV((o) => ({ ...o, [k]: x }))
  const save = async (extra: Partial<Rti> = {}) => {
    setBusy(true)
    setErr('')
    try {
      const body = { ...v, ...extra }
      const res = await FieldApi.rtiSave(v.id ?? 0, body as Record<string, unknown>)
      setV(res.item)
      onSaved(res.item)
    } catch (e) {
      setErr(describeError(e).message)
    } finally {
      setBusy(false)
    }
  }
  const del = async () => {
    if (!v.id || !confirm('আবেদনটি মুছে ফেলবেন?')) return
    await FieldApi.rtiDelete(v.id).catch(() => undefined)
    onSaved(v as Rti, true)
  }
  const text = letter(v, me)
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      setErr('কপি হয়নি — লেখাটি চেপে ধরে কপি করুন।')
    }
  }
  const share = async () => {
    if (navigator.share) await navigator.share({ title: 'তথ্য প্রাপ্তির আবেদন', text }).catch(() => undefined)
    else void copy()
  }
  const d = (v as Rti).dates
  return (
    <Sheet title={v.id ? 'তথ্য অধিকার আবেদন' : 'নতুন আবেদন'} onClose={onClose}>
      {err && <Banner kind="error">{err}</Banner>}
      {view ? (
        <>
          <pre className="whitespace-pre-wrap font-[inherit] text-[13.5px] leading-relaxed bg-neutral-50 rounded-xl p-3.5 mb-3 select-text" data-testid="rti-letter">
            {text}
          </pre>
          <p className="text-[11.5px] text-neutral-500 mb-3 leading-relaxed">অফিসে নির্ধারিত আবেদন ফরম থাকলে এই তথ্যগুলোই সেখানে লিখুন। জমার সময় একটি কপিতে প্রাপ্তি স্বীকার (সিল ও তারিখ) নিয়ে রাখুন।</p>
          <div className="flex gap-2">
            <Button full={false} className="flex-1" onClick={() => void copy()}>
              {copied ? '✓ কপি হয়েছে' : 'কপি করুন'}
            </Button>
            <Button full={false} variant="secondary" className="flex-1" onClick={() => void share()}>
              শেয়ার
            </Button>
          </div>
          <Button variant="ghost" className="mt-1" onClick={() => setView(false)}>
            ← সম্পাদনা
          </Button>
        </>
      ) : (
        <>
          <Row label="অফিসের নাম" required>
            <input className={inputClass} value={v.office ?? ''} onChange={(e) => set('office', e.target.value)} placeholder="যেমন: উপজেলা নির্বাহী অফিসারের কার্যালয়, …" />
          </Row>
          <Row label="দায়িত্বপ্রাপ্ত কর্মকর্তার নাম/পদবি">
            <input className={inputClass} value={v.officer ?? ''} onChange={(e) => set('officer', e.target.value)} placeholder="অফিসের নোটিশ বোর্ড বা ওয়েবসাইটে থাকে" />
          </Row>
          <Row label="অফিসের ঠিকানা">
            <input className={inputClass} value={v.address ?? ''} onChange={(e) => set('address', e.target.value)} />
          </Row>
          <Row label="বিষয় (এক লাইনে)">
            <input className={inputClass} value={v.subject ?? ''} onChange={(e) => set('subject', e.target.value)} placeholder="যেমন: ২০২৫-২৬ অর্থবছরের কাবিখা প্রকল্পের তালিকা" />
          </Row>
          <Row label="কী তথ্য চান — যত নির্দিষ্ট, তত ভালো" required>
            <textarea className={inputClass} rows={4} value={v.info ?? ''} onChange={(e) => set('info', e.target.value)} placeholder={'ক) প্রকল্পের নাম ও বরাদ্দ\nখ) ঠিকাদার/সভাপতির নাম\nগ) কাজ শেষের প্রত্যয়ন'} />
          </Row>
          <Row label="কীভাবে নিতে চান">
            <select className={inputClass} value={v.method ?? 'copy'} onChange={(e) => set('method', e.target.value)}>
              {Object.entries(METHODS).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </Row>

          {v.id && (
            <div className="rounded-2xl bg-neutral-50 p-3.5 mb-4">
              <Row label="অবস্থা">
                <select className={inputClass} value={v.status ?? 'draft'} onChange={(e) => set('status', e.target.value)}>
                  {Object.entries(RTI_STATUS).map(([k, s]) => (
                    <option key={k} value={k}>
                      {s.t}
                    </option>
                  ))}
                </select>
              </Row>
              <div className="grid grid-cols-2 gap-2">
                <Row label="জমার তারিখ">
                  <input type="date" className={inputClass} value={v.sent_on ?? ''} onChange={(e) => set('sent_on', e.target.value)} />
                </Row>
                <Row label="উত্তর পেলাম">
                  <input type="date" className={inputClass} value={v.answered_on ?? ''} onChange={(e) => set('answered_on', e.target.value)} />
                </Row>
                <Row label="আপিলের তারিখ">
                  <input type="date" className={inputClass} value={v.appeal_on ?? ''} onChange={(e) => set('appeal_on', e.target.value)} />
                </Row>
                <Row label="কমিশনে অভিযোগ">
                  <input type="date" className={inputClass} value={v.complaint_on ?? ''} onChange={(e) => set('complaint_on', e.target.value)} />
                </Row>
              </div>
              {d && (d.answer_due || d.appeal_by) && (
                <ul className="text-[12.5px] text-neutral-700 space-y-1" data-testid="rti-dates">
                  {d.answer_due && <li>📅 উত্তরের শেষ দিন: <b>{dshort(d.answer_due)}</b> (অন্য ইউনিট জড়িত হলে {dshort(d.answer_due_long)})</li>}
                  {d.appeal_by && <li>⚖️ আপিলের শেষ দিন: <b>{dshort(d.appeal_by)}</b></li>}
                  {d.appeal_answer_due && <li>📅 আপিলের উত্তর: <b>{dshort(d.appeal_answer_due)}</b>-এর মধ্যে</li>}
                  {d.complaint_by && <li>🏛️ তথ্য কমিশনে অভিযোগের শেষ দিন: <b>{dshort(d.complaint_by)}</b></li>}
                </ul>
              )}
              <Row label="ফল / মন্তব্য">
                <textarea className={inputClass} rows={2} value={v.result ?? ''} onChange={(e) => set('result', e.target.value)} />
              </Row>
            </div>
          )}

          <div className="flex gap-2">
            <Button full={false} className="flex-1" disabled={busy} onClick={() => void save()}>
              {busy ? 'রাখা হচ্ছে…' : 'রাখুন'}
            </Button>
            <Button full={false} variant="secondary" className="flex-1" onClick={() => setView(true)}>
              আবেদনপত্র
            </Button>
          </div>
          {v.id && v.status === 'draft' && (
            <Button variant="ghost" className="mt-1" disabled={busy} onClick={() => void save({ status: 'sent', sent_on: v.sent_on || today() })}>
              আজ জমা দিয়েছি — তারিখ গোনা শুরু করুন
            </Button>
          )}
          {v.id && (
            <button type="button" onClick={() => void del()} className="w-full text-center text-[12.5px] text-red-500 mt-2">
              মুছে ফেলুন
            </button>
          )}
        </>
      )}
    </Sheet>
  )
}
