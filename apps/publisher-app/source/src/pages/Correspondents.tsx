import { useEffect, useState } from 'react'
import { useAuth } from '../lib/AuthContext'
import { ApiError, CorrespondentsApi, type Correspondent, type CorrespondentDetail } from '../lib/api'
import { Badge, Banner, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, Table, Td, Th, bnNumber, inputClass } from '../components/ui'

const suspensionLabels: Record<string, string> = {
  client_commission: 'ক্লায়েন্ট কমিশন স্থগিত',
  all_commission: 'সব কমিশন স্থগিত',
  news_submission: 'সংবাদ জমাদান স্থগিত',
  account: 'একাউন্ট সাসপেন্ড',
}

export default function Correspondents() {
  const { isPublisher } = useAuth()
  const [items, setItems] = useState<Correspondent[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [detail, setDetail] = useState<CorrespondentDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [promoting, setPromoting] = useState(false)
  const [promoted, setPromoted] = useState(false)
  const [salaryInput, setSalaryInput] = useState('')
  const [salarySaving, setSalarySaving] = useState(false)
  const [salaryNotice, setSalaryNotice] = useState('')
  const [rateInput, setRateInput] = useState('')
  const [rateSaving, setRateSaving] = useState(false)
  const [rateNotice, setRateNotice] = useState('')
  const [videoRateInput, setVideoRateInput] = useState('')
  const [videoRateSaving, setVideoRateSaving] = useState(false)
  const [videoRateNotice, setVideoRateNotice] = useState('')
  const [titleInput, setTitleInput] = useState('')
  const [titleSaving, setTitleSaving] = useState(false)
  const [titleNotice, setTitleNotice] = useState('')
  const [q, setQ] = useState('')
  const [div, setDiv] = useState('')

  useEffect(() => {
    CorrespondentsApi.list()
      .then(setItems)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }, [])

  async function openDetail(id: number) {
    setDetailLoading(true)
    setPromoted(false)
    setSalaryNotice('')
    setRateNotice('')
    setVideoRateNotice('')
    setTitleNotice('')
    try {
      const d = await CorrespondentsApi.detail(id)
      setDetail(d)
      setTitleInput(d.title ?? '')
      setSalaryInput(d.monthly_salary ? String(d.monthly_salary) : '')
      setRateInput(d.default_post_rate ? String(d.default_post_rate) : '')
      setVideoRateInput(d.default_video_payment ? String(d.default_video_payment) : '')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setDetailLoading(false)
    }
  }

  async function saveSalary() {
    if (!detail) return
    setSalarySaving(true)
    setSalaryNotice('')
    try {
      const amount = Number(salaryInput) || 0
      const res = await CorrespondentsApi.setSalary(detail.id, amount)
      setDetail({ ...detail, monthly_salary: res.monthly_salary })
      setSalaryInput(res.monthly_salary ? String(res.monthly_salary) : '')
      setSalaryNotice('সংরক্ষিত হয়েছে।')
    } catch (e) {
      setSalaryNotice(e instanceof ApiError ? e.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setSalarySaving(false)
    }
  }

  async function saveRate() {
    if (!detail) return
    setRateSaving(true)
    setRateNotice('')
    try {
      const amount = Number(rateInput) || 0
      const res = await CorrespondentsApi.setDefaultRate(detail.id, amount)
      setDetail({ ...detail, default_post_rate: res.default_post_rate })
      setRateInput(res.default_post_rate ? String(res.default_post_rate) : '')
      setRateNotice('সংরক্ষিত হয়েছে।')
      setItems((prev) =>
        prev.map((c) => (c.id === detail.id ? { ...c, default_post_rate: res.default_post_rate } : c))
      )
    } catch (e) {
      setRateNotice(e instanceof ApiError ? e.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setRateSaving(false)
    }
  }

  async function saveVideoRate() {
    if (!detail) return
    setVideoRateSaving(true)
    setVideoRateNotice('')
    try {
      const amount = Number(videoRateInput) || 0
      const res = await CorrespondentsApi.setDefaultVideoRate(detail.id, amount)
      setDetail({ ...detail, default_video_payment: res.default_video_payment })
      setVideoRateInput(res.default_video_payment ? String(res.default_video_payment) : '')
      setVideoRateNotice('সংরক্ষিত হয়েছে।')
      setItems((prev) => prev.map((c) => (c.id === detail.id ? { ...c, default_video_payment: res.default_video_payment } : c)))
    } catch (e) {
      setVideoRateNotice(e instanceof ApiError ? e.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setVideoRateSaving(false)
    }
  }

  async function saveTitle(value = titleInput) {
    if (!detail) return
    setTitleSaving(true)
    setTitleNotice('')
    try {
      const res = await CorrespondentsApi.setTitle(detail.id, value.trim())
      setTitleInput(res.title)
      setDetail({ ...detail, title: res.title })
      setItems((prev) => prev.map((c) => (c.id === detail.id ? { ...c, title: res.title } : c)))
      setTitleNotice(res.title ? 'পদবি সংরক্ষিত হয়েছে, ওয়েবসাইটে নামের নিচে এটাই দেখাবে।' : 'পদবি সরানো হয়েছে।')
    } catch (e) {
      setTitleNotice(e instanceof ApiError ? e.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setTitleSaving(false)
    }
  }

  const divisions = Array.from(new Set(items.map((c) => c.division).filter(Boolean))).sort()
  const needle = q.trim().toLowerCase()
  const shown = items.filter(
    (c) =>
      (!div || c.division === div) &&
      (!needle || [c.name, c.email, c.phone, c.title, c.division].filter(Boolean).some((v) => String(v).toLowerCase().includes(needle))),
  )

  async function promoteToEditor() {
    if (!detail) return
    setPromoting(true)
    setError('')
    try {
      await CorrespondentsApi.promoteEditor(detail.id)
      setPromoted(true)
      // Pull them out of the correspondent list — they're staff now and
      // will sign in here at publisher.meeha.news instead.
      setItems((prev) => prev.filter((c) => c.id !== detail.id))
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'এডিটর করা যায়নি।')
    } finally {
      setPromoting(false)
    }
  }

  return (
    <div>
      <PageHeader title="সংবাদদাতা" subtitle="সব সংবাদদাতার তালিকা ও বর্তমান অবস্থা" />
      {error && <Banner kind="error">{error}</Banner>}

      {loading ? (
        <Spinner className="mt-10" />
      ) : items.length === 0 ? (
        <Card><EmptyState>এখনো কোনো সংবাদদাতা নেই।</EmptyState></Card>
      ) : (
        <Card>
          <div className="flex flex-wrap items-center gap-2 mb-3" data-testid="corr-filters">
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="নাম, ফোন, ইমেইল বা পদবি দিয়ে খুঁজুন" className={`${inputClass} max-w-xs`} data-testid="corr-search" />
            <select value={div} onChange={(e) => setDiv(e.target.value)} className={`${inputClass} max-w-[180px]`}>
              <option value="">সব বিভাগ</option>
              {divisions.map((d) => (
                <option key={d} value={d}>
                  {d}
                </option>
              ))}
            </select>
            <span className="text-[12px] text-neutral-500">{bnNumber(shown.length)} জন</span>
          </div>
          <Table>
            <thead>
              <tr>
                <Th>নাম ও পদবি</Th>
                <Th>ইমেইল</Th>
                <Th>এলাকা</Th>
                {items.some((c) => c.default_post_rate !== undefined) && <Th>পোস্ট-প্রতি রেট</Th>}
                {items.some((c) => c.default_video_payment !== undefined) && <Th>ভিডিও-প্রতি রেট</Th>}
                <Th>অবস্থা</Th>
              </tr>
            </thead>
            <tbody>
              {shown.map((c) => (
                <tr key={c.id} onClick={() => openDetail(c.id)} className="cursor-pointer hover:bg-neutral-50">
                  <Td className="font-medium text-neutral-900">
                    {c.name}
                    <div className={`text-[11.5px] font-normal ${c.title ? 'text-brand-700' : 'text-neutral-300'}`}>{c.title || 'পদবি নেই'}</div>
                  </Td>
                  <Td>{c.email}</Td>
                  <Td>{c.division || '—'}</Td>
                  {items.some((x) => x.default_post_rate !== undefined) && (
                    <Td>
                      {c.default_post_rate ? (
                        <span className="font-medium text-neutral-700">৳ {bnNumber(c.default_post_rate)}</span>
                      ) : (
                        <span className="text-neutral-300">—</span>
                      )}
                    </Td>
                  )}
                  {items.some((x) => x.default_video_payment !== undefined) && (
                    <Td>
                      {c.default_video_payment ? (
                        <span className="font-medium text-neutral-700">৳ {bnNumber(c.default_video_payment)}</span>
                      ) : (
                        <span className="text-neutral-300">—</span>
                      )}
                    </Td>
                  )}
                  <Td>
                    {c.suspensions.length === 0 ? (
                      <Badge tone="emerald">সক্রিয়</Badge>
                    ) : (
                      <div className="flex flex-wrap gap-1">
                        {c.suspensions.map((s) => (
                          <Badge key={s} tone="red">{suspensionLabels[s] ?? s}</Badge>
                        ))}
                      </div>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {(detail || detailLoading) && (
        <Modal title="সংবাদদাতার বিস্তারিত" onClose={() => setDetail(null)}>
          {detailLoading && !detail ? (
            <Spinner />
          ) : detail ? (
            <div className="space-y-3 text-[13.5px]">
              <div>
                <p className="font-semibold text-neutral-900">{detail.name}</p>
                <p className="text-neutral-500">{detail.email}</p>
                <p className="text-neutral-500">{detail.phone || '—'}</p>
              </div>
              <div className="text-neutral-600">
                {[detail.division, detail.district, detail.upazila].filter(Boolean).join(' / ') || 'এলাকা নেই'}
              </div>
              <div className="rounded-xl ring-1 ring-brand-100 bg-brand-50/40 p-3" data-testid="title-editor">
                <Field label="পদবি (ওয়েবসাইটে নামের নিচে দেখায়)">
                  <input
                    className={inputClass}
                    value={titleInput}
                    maxLength={80}
                    onChange={(e) => setTitleInput(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && void saveTitle()}
                    placeholder="যেমন: জেলা প্রতিনিধি, কুমিল্লা"
                    data-testid="title-input"
                  />
                </Field>
                <div className="flex flex-wrap gap-1.5 -mt-1 mb-2">
                  {(detail.title_presets ?? []).map((t) => {
                    const place = detail.district || detail.upazila || ''
                    const v = place && /প্রতিনিধি$/.test(t) ? `${t}, ${place}` : t
                    return (
                      <button key={t} type="button" onClick={() => setTitleInput(v)} className="rounded-full bg-white ring-1 ring-neutral-200 px-2.5 py-1 text-[11.5px] text-neutral-700 hover:ring-brand-300">
                        {v}
                      </button>
                    )
                  })}
                </div>
                <div className="flex gap-2">
                  <Button size="sm" disabled={titleSaving || titleInput.trim() === (detail.title ?? '')} onClick={() => void saveTitle()} className="flex-1" data-testid="title-save">
                    {titleSaving ? 'সংরক্ষণ হচ্ছে…' : 'পদবি সংরক্ষণ করুন'}
                  </Button>
                  {detail.title && (
                    <Button size="sm" variant="secondary" disabled={titleSaving} onClick={() => void saveTitle('')}>
                      সরান
                    </Button>
                  )}
                </div>
                {titleNotice && <p className="text-[11.5px] text-neutral-600 mt-1.5">{titleNotice}</p>}
                {!!detail.title_log?.length && (
                  <details className="mt-2 text-[11.5px] text-neutral-500">
                    <summary className="cursor-pointer">আগের পদবি ({bnNumber(detail.title_log.length)})</summary>
                    <ul className="mt-1 space-y-0.5">
                      {detail.title_log
                        .filter((l) => l && typeof l === 'object')
                        .map((l, i) => (
                          <li key={i}>
                            {l.from || '—'} → <b className="text-neutral-700">{l.to || '(সরানো)'}</b> · {l.by || ''} · {(l.at || '').slice(0, 16)}
                          </li>
                        ))}
                    </ul>
                  </details>
                )}
              </div>
              <div className="grid grid-cols-3 gap-2 pt-2">
                <div className="text-center bg-neutral-50 rounded-xl py-2">
                  <p className="font-bold text-neutral-900">{bnNumber(detail.post_counts.draft)}</p>
                  <p className="text-[11.5px] text-neutral-400">ড্রাফট</p>
                </div>
                <div className="text-center bg-neutral-50 rounded-xl py-2">
                  <p className="font-bold text-neutral-900">{bnNumber(detail.post_counts.pending)}</p>
                  <p className="text-[11.5px] text-neutral-400">রিভিউতে</p>
                </div>
                <div className="text-center bg-neutral-50 rounded-xl py-2">
                  <p className="font-bold text-neutral-900">{bnNumber(detail.post_counts.publish)}</p>
                  <p className="text-[11.5px] text-neutral-400">প্রকাশিত</p>
                </div>
              </div>
              {typeof detail.available_balance === 'number' && (
                <div className="bg-emerald-50 rounded-xl py-2 text-center">
                  <p className="font-bold text-emerald-700">৳ {bnNumber(detail.available_balance)}</p>
                  <p className="text-[11.5px] text-emerald-600">উপলব্ধ ব্যালেন্স</p>
                </div>
              )}
              <div className="pt-2 border-t border-neutral-100">
                <Field label="মাসিক স্যালারি (৳) — না থাকলে খালি রাখুন">
                  <input
                    type="number"
                    min={0}
                    className={inputClass}
                    value={salaryInput}
                    disabled={!isPublisher}
                    onChange={(e) => setSalaryInput(e.target.value)}
                    placeholder="যেমন ১০০০০"
                  />
                </Field>
                <p className="text-[11.5px] text-neutral-400 -mt-2 mb-2">
                  সেট থাকলে প্রতি মাসের ১ তারিখে স্বয়ংক্রিয়ভাবে এই পরিমাণ সংবাদদাতার ইনকামে যুক্ত হবে।
                </p>
                {isPublisher && (
                  <Button variant="secondary" size="sm" disabled={salarySaving} onClick={saveSalary} className="w-full">
                    {salarySaving ? 'সংরক্ষণ হচ্ছে…' : 'স্যালারি সংরক্ষণ করুন'}
                  </Button>
                )}
                {salaryNotice && <p className="text-[11.5px] text-neutral-500 mt-1.5">{salaryNotice}</p>}
              </div>

              <div className="pt-2 border-t border-neutral-100">
                <Field label="পোস্ট-প্রতি ডিফল্ট পেমেন্ট (৳) — চুক্তি ভিত্তিক, না থাকলে খালি রাখুন">
                  <input
                    type="number"
                    min={0}
                    className={inputClass}
                    value={rateInput}
                    disabled={!isPublisher}
                    onChange={(e) => setRateInput(e.target.value)}
                    placeholder="যেমন ৫"
                  />
                </Field>
                <p className="text-[11.5px] text-neutral-400 -mt-2 mb-2">
                  সেট থাকলে এই সংবাদদাতার কোনো পোস্ট প্রকাশ হওয়ার সময় (আর ওই পোস্টে আলাদা পেমেন্ট না থাকলে) এই পরিমাণ স্বয়ংক্রিয়ভাবে
                  যুক্ত হবে — কিন্তু পেন্ডিং অবস্থায় থাকবে, আপনি নিউজ লিস্ট থেকে নিশ্চিত না করা পর্যন্ত টোটাল ইনকামে যুক্ত হবে না।
                </p>
                {isPublisher && (
                  <Button variant="secondary" size="sm" disabled={rateSaving} onClick={saveRate} className="w-full">
                    {rateSaving ? 'সংরক্ষণ হচ্ছে…' : 'রেট সংরক্ষণ করুন'}
                  </Button>
                )}
                {rateNotice && <p className="text-[11.5px] text-neutral-500 mt-1.5">{rateNotice}</p>}
              </div>

              {detail.default_video_payment !== undefined && (
                <div className="pt-2 border-t border-neutral-100" data-testid="video-rate">
                  <Field label="ভিডিও-প্রতি ডিফল্ট পেমেন্ট (৳) — আপলোড করা ভিডিও নিউজের জন্য">
                    <input
                      type="number"
                      min={0}
                      inputMode="decimal"
                      className={inputClass}
                      value={videoRateInput}
                      disabled={!isPublisher}
                      onChange={(e) => setVideoRateInput(e.target.value)}
                      placeholder="যেমন ১০০"
                    />
                  </Field>
                  <p className="text-[11.5px] text-neutral-400 -mt-2 mb-2">
                    সেট থাকলে এই সংবাদদাতার আপলোড করা ভিডিও প্রথমবার প্রকাশের সময় এই পরিমাণ পেন্ডিং হিসেবে যুক্ত হবে — ভিডিও নিউজ তালিকা বা ভিডিও এডিটর থেকে নিশ্চিত
                    করলে ব্যালেন্সে যোগ হবে।
                  </p>
                  {isPublisher && (
                    <Button variant="secondary" size="sm" disabled={videoRateSaving} onClick={saveVideoRate} className="w-full">
                      {videoRateSaving ? 'সংরক্ষণ হচ্ছে…' : 'ভিডিও রেট সংরক্ষণ করুন'}
                    </Button>
                  )}
                  {videoRateNotice && <p className="text-[11.5px] text-neutral-500 mt-1.5">{videoRateNotice}</p>}
                </div>
              )}

              {detail.suspensions.length > 0 && (
                <div>
                  <p className="text-[12px] font-medium text-neutral-500 mb-1">বর্তমান সাসপেনশন</p>
                  <div className="flex flex-wrap gap-1">
                    {detail.suspensions.map((s) => (
                      <Badge key={s} tone="red">{suspensionLabels[s] ?? s}</Badge>
                    ))}
                  </div>
                </div>
              )}

              {isPublisher && (
                <div className="pt-2 border-t border-neutral-100">
                  {promoted ? (
                    <Badge tone="emerald">এডিটর করা হয়েছে</Badge>
                  ) : (
                    <>
                      <p className="text-[12px] text-neutral-500 mb-2">
                        এডিটর করলে এই সংবাদদাতা একই অ্যাকাউন্টে publisher.meeha.news-এ লগইন করে এডিটরের সব এক্সেস পাবে — আলাদা কোনো ড্যাশবোর্ড লাগবে না।
                      </p>
                      <Button variant="secondary" size="sm" disabled={promoting} onClick={promoteToEditor} className="w-full">
                        {promoting ? 'করা হচ্ছে…' : 'এডিটর করুন'}
                      </Button>
                    </>
                  )}
                </div>
              )}
            </div>
          ) : null}
        </Modal>
      )}
    </div>
  )
}
