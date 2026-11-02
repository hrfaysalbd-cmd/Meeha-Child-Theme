import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { LedgerApi, ApiError, type LedgerEntry, type WithdrawRequest } from '../lib/api'
import { Banner, Button, ErrorRetry, Field, inputClass, Spinner, bnDate, bnNumber } from '../components/ui'

const typeLabel: Record<string, string> = {
  honorarium: 'সম্মানী',
  commission: 'কমিশন',
  bonus: 'বোনাস',
  adjustment: 'সমন্বয়',
  post_payment: 'পোস্ট পেমেন্ট',
  // Round 12: per-video payments (CONTRACT-R12 §C)
  video_payment: 'ভিডিও পেমেন্ট',
  video: 'ভিডিও পেমেন্ট',
  view_bonus: 'ভিউ-স্ল্যাব বোনাস',
  salary: 'মাসিক স্যালারি',
}

const wStatusLabel: Record<string, { label: string; cls: string }> = {
  pending: { label: 'প্রসেসিং', cls: 'bg-amber-50 text-amber-700' },
  paid: { label: 'পরিশোধিত', cls: 'bg-emerald-50 text-emerald-700' },
  rejected: { label: 'বাতিল', cls: 'bg-red-50 text-red-700' },
}

export default function Earn() {
  const navigate = useNavigate()
  const [balance, setBalance] = useState(0)
  const [entries, setEntries] = useState<LedgerEntry[]>([])
  const [requests, setRequests] = useState<WithdrawRequest[]>([])
  const [configured, setConfigured] = useState(true)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<unknown>(null)

  const [showWithdraw, setShowWithdraw] = useState(false)
  const [amount, setAmount] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function loadAll() {
    const [earnings, method, reqs] = await Promise.all([
      LedgerApi.earnings(),
      LedgerApi.getMethod(),
      LedgerApi.listWithdrawRequests(),
    ])
    setBalance(earnings.available_balance)
    setEntries(earnings.entries)
    setConfigured(method.configured)
    setRequests(reqs)
  }

  function load() {
    setLoading(true)
    setLoadError(null)
    loadAll()
      .then(() => setLoadError(null))
      // Previously missing — a failed fetch left the balance/ledger stuck
      // at their initial empty state with no visible error or retry.
      .catch((err) => setLoadError(err ?? new Error('unknown')))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    load()
  }, [])

  // Keeps the balance/ledger in sync automatically — most relevant right
  // after a Publisher confirms a pending payment elsewhere.
  useEffect(() => {
    const interval = setInterval(() => {
      if (document.visibilityState === 'visible') loadAll().catch(() => {})
    }, 60_000)
    function onVisible() {
      if (document.visibilityState === 'visible') loadAll().catch(() => {})
    }
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      clearInterval(interval)
      document.removeEventListener('visibilitychange', onVisible)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function submitWithdraw() {
    setError('')
    const amt = Number(amount)
    if (!configured) {
      setError('প্রথমে প্রোফাইলে উইথড্র পদ্ধতি সেট করুন।')
      return
    }
    if (!amt || amt <= 0) {
      setError('সঠিক পরিমাণ লিখুন।')
      return
    }
    if (amt > balance) {
      setError('উপলব্ধ ব্যালেন্সের চেয়ে বেশি লেখা হয়েছে।')
      return
    }
    setSubmitting(true)
    try {
      await LedgerApi.requestWithdraw(amt)
      setNotice('উইথড্র রিকোয়েস্ট পাঠানো হয়েছে।')
      setShowWithdraw(false)
      setAmount('')
      await loadAll()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'রিকোয়েস্ট পাঠানো যায়নি।')
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <Spinner className="mt-10" />
  if (loadError != null) return <ErrorRetry error={loadError} onRetry={load} />

  return (
    <div className="px-5 pt-5 pb-6">
      <h1 className="text-[18px] font-bold text-neutral-900 mb-4">আয়</h1>

      {notice && <Banner kind="success">{notice}</Banner>}

      <div className="rounded-2xl bg-brand-600 text-white p-5 mb-4">
        <p className="text-[12.5px] opacity-80 mb-1">উপলব্ধ ব্যালেন্স</p>
        <p className="text-[26px] font-bold mb-4">৳ {bnNumber(balance.toFixed(2))}</p>
        <button
          onClick={() => setShowWithdraw((v) => !v)}
          className="w-full bg-white text-brand-700 rounded-xl py-2.5 text-[14px] font-semibold active:scale-[0.98] transition"
        >
          উইথড্র করুন
        </button>
      </div>

      {!configured && (
        <Banner kind="info">
          উইথড্র করার আগে{' '}
          <button onClick={() => navigate('/app/profile')} className="underline font-semibold">
            প্রোফাইলে পেমেন্ট পদ্ধতি
          </button>{' '}
          সেট করুন।
        </Banner>
      )}

      {showWithdraw && (
        <div className="rounded-2xl border border-neutral-100 p-4 mb-5">
          {error && <Banner kind="error">{error}</Banner>}
          <Field label="পরিমাণ (টাকা)" required>
            <input className={inputClass} type="number" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="০.০০" />
          </Field>
          <Button onClick={submitWithdraw} disabled={submitting}>
            {submitting ? 'পাঠানো হচ্ছে…' : 'নিশ্চিত করুন'}
          </Button>
        </div>
      )}

      {requests.length > 0 && (
        <>
          <p className="text-[13px] font-semibold text-neutral-700 mb-2.5">উইথড্র রিকোয়েস্ট</p>
          <div className="flex flex-col gap-2 mb-6">
            {requests.map((r) => {
              const s = wStatusLabel[r.status]
              return (
                <div key={r.id} className="flex items-center justify-between rounded-xl border border-neutral-100 p-3">
                  <div>
                    <p className="text-[14px] font-medium text-neutral-900">৳ {bnNumber(r.amount)}</p>
                    <p className="text-[12px] text-neutral-400">{bnDate(r.created_at)}</p>
                  </div>
                  <span className={`text-[12px] font-medium px-2 py-0.5 rounded-full ${s.cls}`}>{s.label}</span>
                </div>
              )
            })}
          </div>
        </>
      )}

      <p className="text-[13px] font-semibold text-neutral-700 mb-2.5">আয়ের বিস্তারিত</p>
      {entries.length === 0 ? (
        <p className="text-[13px] text-neutral-400 py-6 text-center">এখনো কোনো আয় যুক্ত হয়নি।</p>
      ) : (
        <div className="flex flex-col gap-2">
          {entries.map((e) => (
            <div key={e.id} className="flex items-center justify-between rounded-xl border border-neutral-100 p-3">
              <div className="min-w-0">
                <p className="text-[14px] font-medium text-neutral-900 flex items-center gap-1.5">
                  {typeLabel[e.type] ?? e.type_label ?? e.type}
                  {e.status === 'pending' && (
                    <span className="text-[11px] font-medium px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-700">পেন্ডিং</span>
                  )}
                </p>
                {e.note && <p className="text-[12px] text-neutral-400 truncate">{e.note}</p>}
                <p className="text-[12px] text-neutral-400">{bnDate(e.created_at)}</p>
              </div>
              <span className={`text-[14px] font-semibold shrink-0 ${e.status === 'pending' ? 'text-amber-600' : 'text-emerald-600'}`}>
                +৳{bnNumber(e.amount)}
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
