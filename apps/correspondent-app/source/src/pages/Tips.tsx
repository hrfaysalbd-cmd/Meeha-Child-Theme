/**
 * r46 — গোপন তথ্য (tips) the desk system routed to me by my district.
 * The tipster's identity is never shown here; I add notes for the desk.
 */
import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { TipsApi, describeError, formatBytes, type AssignedTip } from '../lib/api'
import { Banner, Spinner, TopBar } from '../components/ui'

const when = (iso: string) => {
  try {
    return new Date(iso).toLocaleString('bn-BD', { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

export default function Tips() {
  const [params, setParams] = useSearchParams()
  const id = Number(params.get('id')) || 0
  const navigate = useNavigate()
  const [list, setList] = useState<AssignedTip[] | null>(null)
  const [tip, setTip] = useState<AssignedTip | null>(null)
  const [err, setErr] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    setErr('')
    if (id) {
      setTip(null)
      TipsApi.get(id).then(setTip).catch((e) => setErr(describeError(e).message))
    } else {
      TipsApi.mine()
        .then((r) => setList(r.items))
        .catch((e) => setErr(describeError(e).message))
    }
  }, [id])

  const send = async () => {
    if (!note.trim() || !tip) return
    setBusy(true)
    try {
      await TipsApi.note(tip.id, note.trim())
      setNote('')
      setTip(await TipsApi.get(tip.id))
    } catch (e) {
      setErr(describeError(e).message)
    } finally {
      setBusy(false)
    }
  }

  if (id) {
    return (
      <div className="flex-1 flex flex-col">
        <TopBar title={tip ? `টিপ ${tip.code}` : 'গোপন তথ্য'} onBack={() => setParams({})} />
        <div className="px-5 py-4 space-y-3">
          {err && <Banner kind="error">{err}</Banner>}
          {!tip && !err && <Spinner className="mx-auto mt-10" />}
          {tip && (
            <>
              <div className="flex flex-wrap gap-1.5 text-[11.5px]">
                {tip.urgent && <span className="rounded-full bg-red-600 text-white px-2 py-0.5 font-semibold">জরুরি</span>}
                <span className="rounded-full bg-neutral-100 px-2 py-0.5">{tip.category_label}</span>
                {tip.district && <span className="rounded-full bg-neutral-100 px-2 py-0.5">{tip.district}</span>}
                <span className="rounded-full bg-brand-50 text-brand-700 px-2 py-0.5">{tip.status_label}</span>
              </div>
              <p className="text-[11.5px] text-neutral-500">{when(tip.created_at)}</p>
              <div className="rounded-xl bg-neutral-50 p-3 text-[14px] leading-relaxed whitespace-pre-wrap text-neutral-900">{tip.body}</div>
              {!!tip.files?.length && (
                <div className="space-y-1.5">
                  {tip.files.map((f) => (
                    <a key={f.id} href={f.download} target="_blank" rel="noreferrer" className="flex items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2 text-[13px]">
                      <span>📎</span>
                      <span className="flex-1 truncate">{f.name}</span>
                      <span className="text-neutral-400 text-[11.5px]">{formatBytes(f.size)}</span>
                    </a>
                  ))}
                </div>
              )}
              <p className="text-[11.5px] text-amber-700 bg-amber-50 rounded-lg px-3 py-2">তথ্যদাতার পরিচয় গোপন রাখা হয়েছে। যাচাই করে যা পেলেন, নিচে ডেস্কের জন্য লিখুন।</p>
              <div className="space-y-2">
                {(tip.notes ?? []).map((n) => (
                  <div key={n.id} className="rounded-lg bg-white ring-1 ring-neutral-200 px-3 py-2">
                    <div className="text-[11px] text-neutral-500">
                      {n.who} · {when(n.at)}
                    </div>
                    <div className="text-[13.5px] whitespace-pre-wrap text-neutral-900">{n.body}</div>
                  </div>
                ))}
              </div>
              <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={3} placeholder="যাচাইয়ের অগ্রগতি, তথ্য, ছবি কোথায় আছে…" className="w-full rounded-xl border border-neutral-200 px-3 py-2 text-[14px] text-neutral-900" />
              <button type="button" disabled={busy || !note.trim()} onClick={() => void send()} className="w-full h-11 rounded-xl bg-brand-600 text-white font-semibold disabled:opacity-50">
                {busy ? 'পাঠানো হচ্ছে…' : 'ডেস্কে নোট পাঠান'}
              </button>
            </>
          )}
        </div>
      </div>
    )
  }

  return (
    <div className="flex-1 flex flex-col">
      <TopBar title="গোপন তথ্য (টিপস)" onBack={() => navigate('/app')} />
      <div className="px-5 py-4">
        {err && <Banner kind="error">{err}</Banner>}
        {!list && !err && <Spinner className="mx-auto mt-10" />}
        {list && list.length === 0 && <p className="text-center text-[13.5px] text-neutral-500 mt-10">আপনার এলাকার কোনো গোপন তথ্য এখন নেই।</p>}
        <div className="space-y-2">
          {list?.map((t) => (
            <button key={t.id} type="button" onClick={() => setParams({ id: String(t.id) })} className="w-full text-left rounded-xl ring-1 ring-neutral-200 p-3 active:bg-neutral-50">
              <div className="flex items-center gap-1.5 text-[11px] mb-1">
                {t.urgent && <span className="rounded-full bg-red-600 text-white px-1.5 font-semibold">জরুরি</span>}
                <span className="text-neutral-500">{t.code}</span>
                <span className="ml-auto rounded-full bg-brand-50 text-brand-700 px-2">{t.status_label}</span>
              </div>
              <p className="text-[13.5px] text-neutral-900 line-clamp-2">{t.excerpt}</p>
              <p className="text-[11px] text-neutral-500 mt-1">
                {t.category_label} · {t.district || 'জেলা নেই'} · {when(t.created_at)}
              </p>
            </button>
          ))}
        </div>
      </div>
    </div>
  )
}
