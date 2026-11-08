// r31 — প্রোফাইল আমদানি ও রপ্তানি।
// আমদানি: ফাইল (JSON / CSV / TSV) তুলুন বা পেস্ট করুন → আগে দেখুন (কোনটা নতুন, কোনটা আগে থেকেই আছে) → যোগ করুন।
// এআই প্রম্পট কপি করে ChatGPT / Claude / Gemini-কে দিলে সে এই ফরম্যাটে JSON বানিয়ে দেবে।
// রপ্তানি: সব প্রোফাইল JSON (আবার আমদানি করা যায়) বা CSV (এক্সেল) হিসেবে নিজের কম্পিউটারে।
import { useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { ProfileDeskApi, bn, errText, saveText, type PdImportResult, type PdImportRow, type PdMeta } from '../../lib/profileDesk'
import { Banner, Button, Spinner, inputClass } from '../ui'
import { IconCheck, IconClipboard, IconDownload, IconSparkles } from '../Icon'
import { Overlay } from './bits'
import { IconUpload } from './icons'

const CHUNK = 200

// ---------------------------------------------------------------------------
// parsing
// ---------------------------------------------------------------------------

/** RFC-4180-ish CSV / TSV parser (quoted cells, "" escapes, newlines inside quotes). */
export function parseDelimited(text: string): string[][] {
  const t = text.replace(/^﻿/, '')
  const firstLine = t.split(/\r?\n/, 1)[0] ?? ''
  const sep = (firstLine.match(/\t/g) ?? []).length > (firstLine.match(/,/g) ?? []).length ? '\t' : ','
  const rows: string[][] = []
  let row: string[] = []
  let cell = ''
  let q = false
  for (let i = 0; i < t.length; i++) {
    const c = t[i]
    if (q) {
      if (c === '"') {
        if (t[i + 1] === '"') {
          cell += '"'
          i++
        } else q = false
      } else cell += c
    } else if (c === '"' && cell === '') q = true
    else if (c === sep) {
      row.push(cell)
      cell = ''
    } else if (c === '\n' || c === '\r') {
      if (c === '\r' && t[i + 1] === '\n') i++
      row.push(cell)
      cell = ''
      if (row.some((x) => x.trim() !== '')) rows.push(row)
      row = []
    } else cell += c
  }
  row.push(cell)
  if (row.some((x) => x.trim() !== '')) rows.push(row)
  return rows
}

/** Header names people (or a spreadsheet) might use → import keys. */
const HEADER: Record<string, keyof PdImportRow> = {
  name_bn: 'name_bn', 'নাম': 'name_bn', 'বাংলা নাম': 'name_bn', 'নাম (বাংলা)': 'name_bn',
  name_en: 'name_en', name: 'name_en', 'english name': 'name_en', 'ইংরেজি নাম': 'name_en', 'নাম (ইংরেজি)': 'name_en',
  aliases: 'aliases', 'ডাকনাম': 'aliases', 'অন্য নাম': 'aliases',
  category: 'category', 'ধরন': 'category', type: 'category',
  title: 'title', 'পদ': 'title', 'পরিচয়': 'title', position: 'title', designation: 'title',
  org: 'org', 'দল': 'org', 'সংস্থা': 'org', 'দল / সংস্থা': 'org', party: 'org', organisation: 'org', organization: 'org',
  country: 'country', 'দেশ': 'country',
  district: 'district', 'জেলা': 'district',
  upazila: 'upazila', 'উপজেলা': 'upazila',
  tags: 'tags', 'ট্যাগ': 'tags',
  importance: 'importance', 'গুরুত্ব': 'importance',
  status: 'status', 'অবস্থা': 'status',
  sensitivity: 'sensitivity' as keyof PdImportRow,
  phone: 'phone', 'ফোন': 'phone', mobile: 'phone',
  email: 'email', 'ইমেইল': 'email',
  bio: 'bio', 'পরিচিতি': 'bio', notes: 'bio',
  links: 'links', 'লিংক': 'links', 'সোশ্যাল লিংক': 'links', facebook: 'links', website: 'links', youtube: 'links',
}

/** Text from a file or the paste box → import rows. Throws a Bangla message when it can't read it. */
export function parseImport(text: string): PdImportRow[] {
  let t = text.trim()
  if (!t) throw new Error('কিছু পেস্ট করা হয়নি।')
  // an AI often wraps JSON in ```json … ```
  const fence = /```(?:json)?\s*([\s\S]*?)```/i.exec(t)
  if (fence) t = fence[1].trim()
  if (t.startsWith('{') || t.startsWith('[')) {
    let data: unknown
    try {
      data = JSON.parse(t)
    } catch {
      // last resort: the outermost [...] block
      const a = t.indexOf('[')
      const b = t.lastIndexOf(']')
      try {
        data = a >= 0 && b > a ? JSON.parse(t.slice(a, b + 1)) : null
      } catch {
        data = null
      }
      if (!data) throw new Error('JSON পড়া গেল না। এআই-এর উত্তরে কোথাও লেখা ভাঙা থাকতে পারে, পুরোটা আবার কপি করে দেখুন।')
    }
    const rows = Array.isArray(data) ? data : data && typeof data === 'object' && Array.isArray((data as { rows?: unknown }).rows) ? (data as { rows: unknown[] }).rows : null
    if (!rows) throw new Error('JSON-এ "rows" তালিকা বা প্রোফাইলের তালিকা পাওয়া যায়নি।')
    return rows.filter((r): r is PdImportRow => !!r && typeof r === 'object')
  }
  const grid = parseDelimited(t)
  if (grid.length < 2) throw new Error('CSV-তে অন্তত একটা শিরোনাম সারি আর একটা ডেটা সারি লাগবে।')
  const head = grid[0].map((h) => HEADER[h.trim().toLowerCase()] ?? HEADER[h.trim()] ?? null)
  if (!head.includes('name_bn') && !head.includes('name_en')) throw new Error('প্রথম সারিতে "name_bn" বা "নাম" নামে একটা কলাম লাগবে।')
  return grid.slice(1).map((cells) => {
    const r: Record<string, string> = {}
    head.forEach((k, i) => {
      if (!k) return
      let v = (cells[i] ?? '').trim()
      // our CSV export escapes a leading = + - @ with ' (so Excel doesn't run it); undo that
      if (/^'[=+\-@]/.test(v)) v = v.slice(1)
      if (!v) return
      r[k] = k === 'links' && r.links ? `${r.links} ${v}` : v
    })
    return r as PdImportRow
  })
}

// ---------------------------------------------------------------------------
// AI prompt
// ---------------------------------------------------------------------------

export function aiPrompt(meta: PdMeta, topic: string) {
  const cats = meta.categories.map((c) => `${c.key} = ${c.label}`).join('; ')
  const sts = meta.statuses.map((s) => `${s.key} = ${s.label}`).join('; ')
  const evs = meta.event_kinds.map((s) => `${s.key} = ${s.label}`).join('; ')
  return `তুমি একটি বাংলাদেশি সংবাদমাধ্যমের গবেষণা সহকারী। নিচের বিষয়ে যাঁদের ও যেসব সংস্থার প্রোফাইল দরকার, ওয়েবে খুঁজে তথ্য জোগাড় করো এবং শুধু একটি JSON দাও।

বিষয়: ${topic || '[এখানে লিখুন, যেমন: "বর্তমান মন্ত্রিসভার সব মন্ত্রী" বা "চট্টগ্রামের সব সংসদ সদস্য"]'}

কঠোর নিয়ম:
1. কোনো তথ্য বানাবে না। যা নিশ্চিত নও, সেই ঘর খালি রাখো। অনুমান করলে bio-তে "যাচাই করুন" লিখে দাও।
2. প্রতিটি পদ, মামলা, গ্রেপ্তার বা গুরুত্বপূর্ণ দাবির জন্য events-এ একটি ঘটনা দাও, যার source_url-এ আসল খবরের বা দাপ্তরিক পাতার লিংক থাকবে। লিংক নিজে বানাবে না।
3. সোশ্যাল লিংক (ফেসবুক, এক্স, ইউটিউব) শুধু তখনই দেবে যখন সেটা যাচাই করা (ভেরিফায়েড) বা দাপ্তরিক বলে নিশ্চিত। ভুয়া বা ফ্যান পেজ দেবে না।
4. ব্যক্তিগত ফোন নম্বর, বাসার ঠিকানা বা পরিবারের সদস্যদের ব্যক্তিগত তথ্য দেবে না। দাপ্তরিক ইমেইল/ফোন থাকলে দিতে পারো।
5. নাম বাংলায় (name_bn) ও ইংরেজিতে (name_en) দুটোই দাও।
6. ব্যক্তির দল বা সংস্থার নাম "org" ঘরে হুবহু একইভাবে লেখো (যেমন সবখানে "বাংলাদেশ জাতীয়তাবাদী দল (বিএনপি)")। দলগুলোর নিজেদের প্রোফাইলও তালিকার শুরুতে দাও।
7. আজকের তারিখ ${new Date().toISOString().slice(0, 10)}। পুরোনো তথ্য হলে (যেমন সাবেক মন্ত্রী) title-এ "সাবেক" লিখে দাও।

ফরম্যাট (শুধু এই JSON, আগে-পরে কোনো লেখা নয়):
{
  "format": "meeha-profile-desk",
  "version": 1,
  "rows": [
    {
      "name_bn": "বাংলা নাম",
      "name_en": "English Name",
      "aliases": ["ডাকনাম বা অন্য বানান"],
      "category": "politician",
      "title": "পদ, যেমন: পররাষ্ট্রমন্ত্রী; সংসদ সদস্য, ঢাকা-১০",
      "org": "দল বা সংস্থার পুরো নাম",
      "district": "জেলা (বাংলায়)",
      "country": "বাংলাদেশ",
      "status": "active",
      "importance": 4,
      "tags": ["মন্ত্রিসভা"],
      "bio": "২-৩ লাইনের নিরপেক্ষ পরিচিতি",
      "links": ["https://..."],
      "events": [
        { "date": "2026-02-17", "kind": "position", "title": "পররাষ্ট্রমন্ত্রী হিসেবে শপথ", "source_url": "https://..." }
      ]
    }
  ]
}

category-র মান: ${cats}
status-এর মান: ${sts}
events-এর kind: ${evs}
importance: ১ থেকে ৫ (৫ = সবচেয়ে গুরুত্বপূর্ণ, যেমন প্রধানমন্ত্রী)।

শেষে মনে রেখো: ভুল তথ্যের চেয়ে খালি ঘর ভালো।`
}

// ---------------------------------------------------------------------------
// the dialog
// ---------------------------------------------------------------------------

type Step = 'input' | 'preview' | 'done'

export function ImportDialog({ meta, onClose, onDone }: { meta: PdMeta; onClose: () => void; onDone: () => void }) {
  const [text, setText] = useState('')
  const [topic, setTopic] = useState('')
  const [showPrompt, setShowPrompt] = useState(false)
  const [copied, setCopied] = useState(false)
  const [rows, setRows] = useState<PdImportRow[]>([])
  const [preview, setPreview] = useState<PdImportResult['rows']>([])
  const [result, setResult] = useState<PdImportResult['rows']>([])
  const [step, setStep] = useState<Step>('input')
  const [busy, setBusy] = useState('')
  const [err, setErr] = useState('')
  const [drag, setDrag] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)
  const prompt = useMemo(() => aiPrompt(meta, topic), [meta, topic])

  async function readFile(f: File) {
    if (f.size > 20 * 1024 * 1024) return setErr('ফাইলটা ২০ এমবির বেশি বড়।')
    setText(await f.text())
    setErr('')
  }

  async function run(list: PdImportRow[], dry: boolean) {
    const out: PdImportResult['rows'] = []
    for (let i = 0; i < list.length; i += CHUNK) {
      setBusy(`${dry ? 'দেখা' : 'যোগ করা'} হচ্ছে… ${bn(Math.min(i + CHUNK, list.length))}/${bn(list.length)}`)
      const r = await ProfileDeskApi.importRows(list.slice(i, i + CHUNK), dry)
      out.push(...r.rows.map((x) => ({ ...x, row: x.row + i })))
    }
    return out
  }

  async function check() {
    setErr('')
    let list: PdImportRow[]
    try {
      list = parseImport(text)
    } catch (e) {
      return setErr(errText(e))
    }
    if (!list.length) return setErr('কোনো প্রোফাইল পাওয়া যায়নি।')
    try {
      const p = await run(list, true)
      setRows(list)
      setPreview(p)
      setStep('preview')
    } catch (e) {
      setErr(errText(e))
    }
    setBusy('')
  }

  async function doImport() {
    setErr('')
    // only rows that are new (duplicates and errors are left out)
    const keep = rows.filter((_, i) => preview[i]?.status === 'new')
    try {
      const r = await run(keep, false)
      setResult(r)
      setStep('done')
      onDone()
    } catch (e) {
      setErr(errText(e))
    }
    setBusy('')
  }

  const counts = (list: PdImportResult['rows']) => ({
    neu: list.filter((r) => r.status === 'new' || r.status === 'created').length,
    dup: list.filter((r) => r.status === 'duplicate').length,
    bad: list.filter((r) => r.status === 'error').length,
    near: list.filter((r) => (r.status === 'new' || r.status === 'created') && r.similar && r.similar.length).length,
  })

  return (
    <Overlay onClose={busy ? () => {} : onClose} wide>
      <div className="p-5 space-y-3" data-testid="pd-import">
        <div className="flex items-center justify-between">
          <h2 className="text-[16px] font-bold">প্রোফাইল আমদানি</h2>
          <button type="button" className="text-neutral-400 hover:text-neutral-700 text-[20px] leading-none" onClick={onClose} disabled={!!busy}>
            ×
          </button>
        </div>
        {err && <Banner kind="error">{err}</Banner>}

        {step === 'input' && (
          <>
            <div className="rounded-xl border border-violet-200 bg-violet-50/60 p-3">
              <button type="button" className="w-full flex items-center justify-between text-left" onClick={() => setShowPrompt(!showPrompt)}>
                <span className="flex items-center gap-2 text-[13.5px] font-semibold text-violet-800">
                  <IconSparkles className="w-4 h-4" /> এআই দিয়ে প্রোফাইল বানান
                </span>
                <span className="text-[12px] text-violet-600">{showPrompt ? 'লুকান' : 'দেখুন'}</span>
              </button>
              {showPrompt && (
                <div className="mt-2 space-y-2 text-[12.5px] text-violet-900">
                  <ol className="list-decimal pl-5 space-y-0.5">
                    <li>কাদের প্রোফাইল চান, নিচে এক লাইনে লিখুন।</li>
                    <li>"প্রম্পট কপি" চেপে ChatGPT, Claude বা Gemini-তে পেস্ট করুন (ওয়েব সার্চ চালু রাখুন)।</li>
                    <li>এআই যে JSON দেবে, পুরোটা কপি করে নিচের বড় ঘরে পেস্ট করুন, তারপর "আগে দেখুন"।</li>
                  </ol>
                  <input className={inputClass} value={topic} placeholder='যেমন: "বর্তমান মন্ত্রিসভার সব মন্ত্রী ও প্রতিমন্ত্রী"' onChange={(e) => setTopic(e.target.value)} />
                  <textarea className={`${inputClass} !text-[12px] leading-relaxed`} rows={6} readOnly value={prompt} />
                  <div className="flex flex-wrap items-center gap-2">
                    <Button
                      size="sm"
                      onClick={() => {
                        navigator.clipboard.writeText(prompt).then(
                          () => {
                            setCopied(true)
                            setTimeout(() => setCopied(false), 2000)
                          },
                          () => setErr('কপি হয়নি। ঘরটা থেকে নিজে বেছে কপি করুন।'),
                        )
                      }}
                      data-testid="pd-ai-copy"
                    >
                      {copied ? <IconCheck className="w-4 h-4" /> : <IconClipboard className="w-4 h-4" />} {copied ? 'কপি হয়েছে' : 'প্রম্পট কপি'}
                    </Button>
                    <span className="text-[11.5px] text-violet-700">এআই ভুল করতে পারে: আমদানির পর প্রোফাইলগুলো "যাচাই হয়নি" অবস্থায় থাকে, লিংক ও তথ্য একবার মিলিয়ে নিন।</span>
                  </div>
                </div>
              )}
            </div>

            <div
              onDragOver={(e) => {
                e.preventDefault()
                setDrag(true)
              }}
              onDragLeave={() => setDrag(false)}
              onDrop={(e) => {
                e.preventDefault()
                setDrag(false)
                const f = e.dataTransfer.files[0]
                if (f) readFile(f)
              }}
              className={`rounded-xl border-2 border-dashed ${drag ? 'border-brand-500 bg-brand-50' : 'border-neutral-200'} p-3`}
            >
              <textarea
                className={`${inputClass} !text-[12.5px] !border-0 !ring-0 !p-1`}
                rows={10}
                value={text}
                placeholder={'এখানে এআই-এর JSON, অথবা এক্সেল/গুগল শিট থেকে কপি করা সারি পেস্ট করুন।\nঅথবা .json / .csv ফাইল এখানে টেনে আনুন।\n\nCSV-র প্রথম সারিতে কলামের নাম: name_bn, name_en, category, title, org, district, tags, links …'}
                onChange={(e) => setText(e.target.value)}
                data-testid="pd-import-text"
              />
              <div className="flex flex-wrap items-center gap-2 pt-2 border-t border-neutral-100">
                <input ref={fileRef} type="file" accept=".json,.csv,.tsv,.txt,application/json,text/csv" className="hidden" onChange={(e) => e.target.files?.[0] && readFile(e.target.files[0])} />
                <Button variant="secondary" size="sm" onClick={() => fileRef.current?.click()}>
                  <IconUpload className="w-4 h-4" /> ফাইল বেছে নিন
                </Button>
                <span className="text-[11.5px] text-neutral-500">রপ্তানি করা JSON ফাইলও এখানে দিয়ে আবার আনা যায়।</span>
              </div>
            </div>
            <div className="flex justify-end gap-2">
              {busy && <span className="text-[12.5px] text-neutral-500 self-center">{busy}</span>}
              <Button variant="secondary" onClick={onClose} disabled={!!busy}>
                বাতিল
              </Button>
              <Button onClick={check} disabled={!!busy || !text.trim()} data-testid="pd-import-check">
                আগে দেখুন
              </Button>
            </div>
          </>
        )}

        {step === 'preview' && (
          <>
            {(() => {
              const c = counts(preview)
              return (
                <div className="flex flex-wrap gap-2 text-[12.5px]">
                  <span className="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 font-semibold">নতুন {bn(c.neu)}</span>
                  <span className="px-2.5 py-1 rounded-full bg-neutral-100 text-neutral-600">আগে থেকেই আছে {bn(c.dup)}</span>
                  {c.bad > 0 && <span className="px-2.5 py-1 rounded-full bg-red-50 text-red-600">ভুল {bn(c.bad)}</span>}
                  {c.near > 0 && <span className="px-2.5 py-1 rounded-full bg-amber-50 text-amber-700">কাছাকাছি নাম আছে {bn(c.near)}</span>}
                </div>
              )
            })()}
            <div className="max-h-[50vh] overflow-y-auto rounded-xl border border-neutral-200">
              <table className="w-full text-[12.5px]">
                <thead className="bg-neutral-50 sticky top-0">
                  <tr className="text-left text-neutral-500">
                    <th className="px-3 py-2 w-10">#</th>
                    <th className="px-3 py-2">নাম</th>
                    <th className="px-3 py-2 hidden sm:table-cell">ধরন · পদ · দল</th>
                    <th className="px-3 py-2">অবস্থা</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-neutral-100">
                  {preview.map((p, i) => {
                    const r = rows[i] ?? {}
                    return (
                      <tr key={i} className={p.status === 'new' ? '' : 'text-neutral-400'}>
                        <td className="px-3 py-1.5">{bn(p.row)}</td>
                        <td className="px-3 py-1.5 font-medium">
                          {p.name}
                          {r.name_en && r.name_bn && <span className="block text-[11px] font-normal text-neutral-400">{r.name_en}</span>}
                        </td>
                        <td className="px-3 py-1.5 hidden sm:table-cell text-neutral-500">
                          {[meta.categories.find((c) => c.key === p.category)?.label, r.title, r.org].filter(Boolean).join(' · ')}
                          {!!p.links && <span className="ml-1 text-[11px]">· {bn(p.links)} লিংক</span>}
                          {!!p.events && <span className="ml-1 text-[11px]">· {bn(p.events)} ঘটনা</span>}
                        </td>
                        <td className="px-3 py-1.5">
                          {p.status === 'new' && (
                            <span className="text-emerald-700">
                              নতুন
                              {p.similar && p.similar.length > 0 && (
                                <span className="block text-[11px] text-amber-700" title={p.similar.map((s) => s.name).join(', ')}>
                                  কাছাকাছি: {p.similar[0].name}
                                </span>
                              )}
                            </span>
                          )}
                          {p.status === 'duplicate' && (
                            <span title={p.message}>
                              আছে
                              {p.similar?.[0] && (
                                <Link to={`/app/profile-desk/${p.similar[0].id}`} target="_blank" className="block text-[11px] text-brand-600 hover:underline">
                                  {p.similar[0].name}
                                </Link>
                              )}
                            </span>
                          )}
                          {p.status === 'error' && <span className="text-red-600">{p.message}</span>}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <p className="text-[11.5px] text-neutral-500">
              যেগুলো "আছে", সেগুলো বাদ যাবে, আগের প্রোফাইল বদলাবে না। দল/সংস্থার নাম মিললে ব্যক্তিকে সেই দলের সাথে যুক্ত করা হবে, না মিললে নতুন সংস্থা তৈরি হবে।
            </p>
            <div className="flex justify-end gap-2">
              {busy && <span className="text-[12.5px] text-neutral-500 self-center">{busy}</span>}
              <Button variant="secondary" onClick={() => setStep('input')} disabled={!!busy}>
                পেছনে
              </Button>
              <Button onClick={doImport} disabled={!!busy || counts(preview).neu === 0} data-testid="pd-import-go">
                {bn(counts(preview).neu)}টি প্রোফাইল যোগ করুন
              </Button>
            </div>
          </>
        )}

        {step === 'done' && (
          <div className="text-center py-6">
            <div className="mx-auto w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center">
              <IconCheck className="w-6 h-6" />
            </div>
            <p className="mt-3 text-[15px] font-bold" data-testid="pd-import-done">
              {bn(result.filter((r) => r.status === 'created').length)}টি প্রোফাইল যোগ হয়েছে
            </p>
            {result.some((r) => r.status !== 'created') && (
              <p className="text-[12.5px] text-neutral-500">{bn(result.filter((r) => r.status !== 'created').length)}টি যোগ হয়নি (একই নাম আগে থেকে ছিল বা ভুল)।</p>
            )}
            <p className="mt-2 text-[12.5px] text-neutral-500">লিংকগুলো "যাচাই হয়নি" হিসেবে আছে। প্রোফাইলে গিয়ে মিলিয়ে নিন।</p>
            <Button className="mt-4" onClick={onClose}>
              ঠিক আছে
            </Button>
          </div>
        )}
        {busy && step !== 'done' && <Spinner className="my-2" />}
      </div>
    </Overlay>
  )
}

/** Export buttons (publisher only): JSON keeps everything for re-import, CSV opens in Excel. */
export function ExportMenu({ onClose }: { onClose: () => void }) {
  const [busy, setBusy] = useState('')
  const [err, setErr] = useState('')
  const [done, setDone] = useState('')
  async function go(kind: 'json' | 'csv') {
    setBusy(kind)
    setErr('')
    try {
      if (kind === 'json') {
        const r = await ProfileDeskApi.exportJson()
        saveText(r.json, r.filename, 'application/json')
        setDone(`${bn(r.count)}টি প্রোফাইল ${r.filename} নামে নামানো হয়েছে।`)
      } else {
        const r = await ProfileDeskApi.exportCsv()
        saveText(r.csv, r.filename, 'text/csv;charset=utf-8')
        setDone(`${bn(r.count)}টি প্রোফাইল ${r.filename} নামে নামানো হয়েছে।`)
      }
    } catch (e) {
      setErr(errText(e))
    }
    setBusy('')
  }
  return (
    <Overlay onClose={onClose}>
      <div className="p-5 space-y-3" data-testid="pd-export">
        <h2 className="text-[16px] font-bold">রপ্তানি: নিজের কম্পিউটারে রাখুন</h2>
        {err && <Banner kind="error">{err}</Banner>}
        {done && <Banner kind="success">{done}</Banner>}
        <button type="button" disabled={!!busy} onClick={() => go('json')} className="w-full text-left rounded-xl border border-neutral-200 p-3 hover:border-brand-400 disabled:opacity-60" data-testid="pd-export-json">
          <p className="font-semibold text-[13.5px] flex items-center gap-1.5">
            <IconDownload className="w-4 h-4" /> পুরো ব্যাকআপ (JSON) {busy === 'json' && '…'}
          </p>
          <p className="text-[12px] text-neutral-500 mt-0.5">সব প্রোফাইল, লিংক, দল/সংস্থা আর ঘটনাপঞ্জি। পরে "আমদানি"-তে এই ফাইল দিলেই আবার ফিরে আসে (এই সাইটে বা নতুন সাইটে)।</p>
        </button>
        <button type="button" disabled={!!busy} onClick={() => go('csv')} className="w-full text-left rounded-xl border border-neutral-200 p-3 hover:border-brand-400 disabled:opacity-60" data-testid="pd-export-csv">
          <p className="font-semibold text-[13.5px] flex items-center gap-1.5">
            <IconDownload className="w-4 h-4" /> এক্সেল / গুগল শিট (CSV) {busy === 'csv' && '…'}
          </p>
          <p className="text-[12px] text-neutral-500 mt-0.5">এক প্রোফাইল এক সারিতে। ঘটনাপঞ্জি থাকে না।</p>
        </button>
        <div className="rounded-xl bg-amber-50 border border-amber-200 px-3 py-2 text-[12px] text-amber-800 space-y-1">
          <p>ফাইলটা ব্রাউজারের "Downloads" ফোল্ডারে যায় (ব্রাউজার জিজ্ঞেস করলে নিজের পছন্দের ফোল্ডার বেছে নিন)।</p>
          <p>
            এনক্রিপ্ট করা গোপন নোট, ফাইল আর ভল্ট এতে <b>থাকে না</b>। ফাইলে মানুষের তথ্য খোলা অবস্থায় থাকে, তাই নিরাপদ জায়গায় রাখুন, কাউকে পাঠাবেন না। কে কখন রপ্তানি করেছেন, লগে থাকে।
          </p>
        </div>
        <div className="flex justify-end">
          <Button variant="secondary" onClick={onClose}>
            বন্ধ
          </Button>
        </div>
      </div>
    </Overlay>
  )
}
