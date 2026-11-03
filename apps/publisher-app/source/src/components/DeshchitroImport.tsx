/**
 * দেশচিত্র — "এআই প্রম্পট" and "ইমপোর্ট" dialogs (publisher r26.5).
 * The prompt is built from the live configuration (indicator keys, economy keys, districts, date), so it always
 * matches what the importer accepts. The import dialog previews every incident with its sources before anything
 * reaches the sheet; nothing is published from here — at most a draft is saved.
 */
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { DeshchitroApi, describeError, type DcData, type DcSheet } from '../lib/api'
import { buildImport, buildPrompt, importTemplate, readImportText, type DcImportFile, type DcImportOptions } from '../lib/deshchitroImport'
import { Badge, Banner, Button, inputClass } from './ui'
import { IconClipboard, IconDownload, IconExternalLink, IconUpload, IconX } from './Icon'

const BN = '০১২৩৪৫৬৭৮৯'
const bn = (v: number | string) => String(v).replace(/[0-9]/g, (d) => BN[Number(d)])

function Shell({ title, onClose, children, footer }: { title: string; onClose: () => void; children: ReactNode; footer?: ReactNode }) {
  useEffect(() => {
    const h = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', h)
    return () => window.removeEventListener('keydown', h)
  }, [onClose])
  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 sm:px-4" role="dialog" aria-modal="true" aria-label={title}>
      <div className="bg-white w-full sm:max-w-4xl max-h-[92vh] sm:rounded-2xl rounded-t-2xl flex flex-col shadow-xl">
        <div className="flex items-center justify-between px-5 py-3.5 border-b border-neutral-100">
          <h2 className="text-[15.5px] font-bold text-neutral-900">{title}</h2>
          <button onClick={onClose} className="text-neutral-400 hover:text-neutral-700" aria-label="বন্ধ"><IconX className="w-5 h-5" /></button>
        </div>
        <div className="p-5 overflow-y-auto flex-1">{children}</div>
        {footer && <div className="px-5 py-3 border-t border-neutral-100 flex flex-wrap gap-2 justify-end">{footer}</div>}
      </div>
    </div>
  )
}

function download(name: string, text: string, type: string) {
  const url = URL.createObjectURL(new Blob([text], { type }))
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/* ------------------------------------------------------------------ prompt */
export function PromptDialog({ sheet, onClose }: { sheet: DcSheet; onClose: () => void }) {
  const [date, setDate] = useState(sheet.day)
  const [copied, setCopied] = useState(false)
  const prompt = useMemo(() => buildPrompt(sheet, date), [sheet, date])
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(prompt)
    } catch {
      const t = document.createElement('textarea')
      t.value = prompt
      document.body.appendChild(t)
      t.select()
      document.execCommand('copy')
      t.remove()
    }
    setCopied(true)
    setTimeout(() => setCopied(false), 2500)
  }
  return (
    <Shell
      title="এআই প্রম্পট — দিনের তথ্য সংগ্রহ"
      onClose={onClose}
      footer={<>
        <Button variant="secondary" onClick={() => download(`meeha-deshchitro-template-${date}.json`, JSON.stringify(importTemplate(sheet, date), null, 2), 'application/json')}><IconDownload className="w-4 h-4" /> খালি টেমপ্লেট</Button>
        <Button variant="secondary" onClick={() => download(`meeha-deshchitro-prompt-${date}.txt`, prompt, 'text/plain;charset=utf-8')}><IconDownload className="w-4 h-4" /> .txt</Button>
        <Button onClick={copy}><IconClipboard className="w-4 h-4" /> {copied ? 'কপি হয়েছে' : 'প্রম্পট কপি করুন'}</Button>
      </>}
    >
      <ol className="text-[13px] text-neutral-700 space-y-1 mb-3 list-decimal pl-5">
        <li>তারিখ বেছে নিন, তারপর "প্রম্পট কপি করুন" চাপুন।</li>
        <li>ওয়েব সার্চ চালু আছে এমন এআই-তে পেস্ট করুন (যেমন Claude-এ Research/ওয়েব সার্চ চালু রেখে)।</li>
        <li>উত্তরে যে JSON আসবে সেটা ফাইল হিসেবে রাখুন বা পুরো উত্তর কপি করুন, তারপর এখানে "ইমপোর্ট" চাপুন।</li>
      </ol>
      <Banner kind="info">প্রম্পটে আপনার সাইটের সব সূচক, অর্থনীতির ঘর আর ৬৪ জেলার কী নিজে বসে যায়। সেটিংসে সূচক বদলালে প্রম্পটও বদলায়। এআই সব জেলার সব ঘটনা খুঁজে পাবে না, তাই সংখ্যাগুলো ডেস্কের যাচাইয়ের জন্য খসড়া।</Banner>
      <label className="flex items-center gap-2 mb-3 text-[13px] font-semibold">তারিখ
        <input type="date" className={`${inputClass} !w-auto`} value={date} max={sheet.today} onChange={(e) => e.target.value && setDate(e.target.value)} />
      </label>
      <textarea readOnly className={`${inputClass} font-mono text-[11.5px] leading-relaxed`} rows={16} value={prompt} onFocus={(e) => e.currentTarget.select()} />
    </Shell>
  )
}

/* ------------------------------------------------------------------ import */
export function ImportDialog({
  sheet,
  data,
  onClose,
  onApply,
}: {
  sheet: DcSheet
  data: DcData
  onClose: () => void
  /** target sheet (may be another date), new data, and whether to save a draft right away */
  onApply: (target: DcSheet, next: DcData, saveDraft: boolean) => Promise<void> | void
}) {
  const [text, setText] = useState('')
  const [file, setFile] = useState<DcImportFile | null>(null)
  const [target, setTarget] = useState<DcSheet | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [opt, setOpt] = useState<DcImportOptions>({ mode: 'replace', requireSource: true, includeLow: false, strictDate: true })
  const [showSkipped, setShowSkipped] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)

  const read = async (raw: string) => {
    setError('')
    setFile(null)
    setTarget(null)
    try {
      const f = readImportText(raw)
      const day = f.date && /^\d{4}-\d{2}-\d{2}$/.test(f.date) ? f.date : sheet.day
      if (day > sheet.today) throw new Error(`ফাইলের তারিখ (${day}) ভবিষ্যতের।`)
      setBusy(true)
      const t = day === sheet.day ? sheet : await DeshchitroApi.get(day)
      setTarget(t)
      setFile(f)
    } catch (e) {
      setError(e instanceof Error ? e.message : describeError(e).message)
    } finally {
      setBusy(false)
    }
  }
  const onFile = (f?: File | null) => {
    if (!f) return
    const r = new FileReader()
    r.onload = () => { const t = String(r.result || ''); setText(t); read(t) }
    r.readAsText(f, 'utf-8')
  }

  const base = target && target.day === sheet.day ? data : target?.data
  const res = useMemo(() => (file && target && base ? buildImport(file, target, base, opt) : null), [file, target, base, opt])
  const indName = (k: string) => target?.indicators.find((i) => i.key === k)?.short || k
  const distName = (k: string) => (k && target ? target.geo.districts[k]?.[0] : '') || 'জেলা অজানা'

  const apply = async (save: boolean) => {
    if (!res || !target) return
    setBusy(true)
    try {
      await onApply(target, res.data, save)
      onClose()
    } catch (e) {
      setError(describeError(e).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Shell
      title="এআই ফাইল ইমপোর্ট"
      onClose={onClose}
      footer={res ? <>
        <Button variant="secondary" onClick={() => { setFile(null); setTarget(null) }} disabled={busy}>অন্য ফাইল</Button>
        <Button variant="secondary" onClick={() => apply(false)} disabled={busy || !res.counted && !res.eco.length}>শিটে বসান</Button>
        {!(target?.exists && target.status === 'publish') && <Button onClick={() => apply(true)} disabled={busy || !res.counted && !res.eco.length}>{busy ? 'হচ্ছে…' : 'বসিয়ে খসড়া সংরক্ষণ'}</Button>}
      </> : undefined}
    >
      {error && <Banner kind="error">{error}</Banner>}
      {!res && (
        <div>
          <p className="text-[13px] text-neutral-600 mb-3">এআই-এর দেওয়া <b>.json</b> ফাইল বেছে নিন, অথবা এআই-এর পুরো উত্তর নিচে পেস্ট করুন (```json ব্লকসহ দিলেও চলবে)।</p>
          <input ref={fileRef} type="file" accept=".json,application/json,text/plain" className="hidden" onChange={(e) => onFile(e.target.files?.[0])} />
          <Button variant="secondary" onClick={() => fileRef.current?.click()} disabled={busy}><IconUpload className="w-4 h-4" /> ফাইল বেছে নিন</Button>
          <textarea className={`${inputClass} font-mono text-[12px] mt-3`} rows={10} placeholder='{"format": "meeha-deshchitro", ... }' value={text} onChange={(e) => setText(e.target.value)} />
          <div className="mt-2 flex justify-end"><Button onClick={() => read(text)} disabled={busy || !text.trim()}>{busy ? 'পড়ছি…' : 'পড়ুন ও যাচাই করুন'}</Button></div>
        </div>
      )}
      {res && target && (
        <div className="space-y-4">
          <div className="flex flex-wrap gap-2 items-center">
            <span className="font-bold text-[14.5px]">{target.label}</span>
            {target.exists && <Badge tone={target.status === 'publish' ? 'emerald' : 'amber'}>এই দিনের হিসাব আগে থেকেই আছে ({target.status === 'publish' ? 'প্রকাশিত' : 'খসড়া'})</Badge>}
            <Badge tone="emerald">গণনায় এসেছে {bn(res.counted)}টি ঘটনা</Badge>
            {res.skipped > 0 && <Badge tone="red">বাদ {bn(res.skipped)}টি</Badge>}
            {res.eco.length > 0 && <Badge tone="blue">অর্থনীতি {bn(res.eco.length)}টি মান</Badge>}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-[13px]">
            <label className="flex items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2">
              <span className="font-semibold">জেলার আগের সংখ্যা</span>
              <select className="ml-auto border rounded px-2 py-1" value={opt.mode} onChange={(e) => setOpt({ ...opt, mode: e.target.value as 'replace' | 'add' })}>
                <option value="replace">বদলে ফাইলেরটা বসান</option>
                <option value="add">রেখে যোগ করুন</option>
              </select>
            </label>
            <label className="flex items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2"><input type="checkbox" checked={opt.requireSource} onChange={(e) => setOpt({ ...opt, requireSource: e.target.checked })} /> উৎসের লিংক ছাড়া ঘটনা বাদ</label>
            <label className="flex items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2"><input type="checkbox" checked={opt.strictDate} onChange={(e) => setOpt({ ...opt, strictDate: e.target.checked })} /> অন্য তারিখের ঘটনা বাদ</label>
            <label className="flex items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2"><input type="checkbox" checked={opt.includeLow} onChange={(e) => setOpt({ ...opt, includeLow: e.target.checked })} /> "কম নিশ্চিত" ঘটনাও গুনুন</label>
          </div>

          <div className="flex gap-2 overflow-x-auto pb-1">
            {target.indicators.filter((i) => +i.on).map((i) => (
              <div key={i.key} className="shrink-0 rounded-xl bg-neutral-50 border border-neutral-200 px-3 py-1.5 text-center">
                <p className="text-[11px] text-neutral-500">{i.short}</p>
                <p className="text-[17px] font-bold">{bn(res.totals[i.key] || 0)}</p>
              </div>
            ))}
          </div>

          {res.warnings.length > 0 && <Banner kind="info"><ul className="list-disc pl-4">{res.warnings.map((w, i) => <li key={i}>{w}</li>)}</ul></Banner>}
          {res.gaps.length > 0 && <div className="rounded-xl bg-amber-50 border border-amber-100 px-3.5 py-2.5 text-[12.5px] text-amber-800"><b>এআই যা খুঁজে পায়নি:</b> {res.gaps.join(' • ')}</div>}

          <div>
            <div className="flex items-center justify-between mb-1.5">
              <p className="font-bold text-[13.5px]">ঘটনার তালিকা (উৎসসহ যাচাই করুন)</p>
              {res.skipped > 0 && <button className="text-[12.5px] text-brand-600 font-semibold" onClick={() => setShowSkipped(!showSkipped)}>{showSkipped ? 'বাদ পড়াগুলো লুকান' : 'বাদ পড়াগুলোও দেখুন'}</button>}
            </div>
            <div className="rounded-xl border border-neutral-200 divide-y divide-neutral-100 max-h-[40vh] overflow-y-auto">
              {res.rows.filter((r) => showSkipped || !r.skip).map((r) => (
                <div key={r.i} className={`px-3 py-2 text-[12.5px] ${r.skip ? 'bg-red-50/50 text-neutral-500' : ''}`}>
                  <div className="flex flex-wrap items-center gap-1.5">
                    <b className="text-neutral-900">{distName(r.district)}</b>
                    <Badge tone={r.skip ? 'neutral' : 'amber'}>{indName(r.indicator || r.indicatorRaw)} {bn(r.count)}</Badge>
                    {r.confidence === 'low' && <Badge tone="red">কম নিশ্চিত</Badge>}
                    {r.confidence === 'high' && <Badge tone="emerald">নিশ্চিত</Badge>}
                    {r.skip && <Badge tone="red">বাদ: {r.skip}</Badge>}
                  </div>
                  {r.summary && <p className="mt-0.5 text-neutral-700">{r.summary}</p>}
                  <div className="flex flex-wrap gap-x-3 gap-y-0.5 mt-0.5">
                    {r.sources.filter((s) => s.url).slice(0, 4).map((s, k) => (
                      <a key={k} href={s.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-0.5 text-brand-600">{s.outlet || 'উৎস'} <IconExternalLink className="w-3 h-3" /></a>
                    ))}
                  </div>
                </div>
              ))}
              {!res.rows.length && <p className="px-3 py-6 text-center text-neutral-400 text-[13px]">ফাইলে কোনো ঘটনা নেই।</p>}
            </div>
          </div>

          {res.eco.length > 0 && (
            <div>
              <p className="font-bold text-[13.5px] mb-1.5">অর্থনীতি</p>
              <div className="flex flex-wrap gap-2">
                {res.eco.map((e) => (
                  <span key={e.key} className="rounded-lg bg-blue-50 text-blue-900 px-2.5 py-1 text-[12.5px]" title={e.source}>
                    {target.economy.find((x) => x.key === e.key)?.label || e.key}: <b>{bn(e.value)}</b>
                  </span>
                ))}
              </div>
            </div>
          )}
          {target.exists && target.status === 'publish' && <Banner kind="info">এই দিনটি আগেই প্রকাশিত। তাই ইমপোর্ট শুধু শিটে বসবে; যাচাই করে "হালনাগাদ করুন" চাপলে তবেই সাইটে বদলাবে।</Banner>}
          <p className="text-[12px] text-neutral-500">ইমপোর্ট কখনো সরাসরি প্রকাশ করে না। শিটে বসার পর যাচাই করে "প্রকাশ" চাপুন। ব্যবহৃত উৎসের তালিকা শিটের "উৎস ও ভেতরের নোট"-এ জমা থাকে (পাঠক দেখেন না)।</p>
        </div>
      )}
    </Shell>
  )
}
