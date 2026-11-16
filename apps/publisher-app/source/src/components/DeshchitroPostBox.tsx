/**
 * r26.6 — "দেশচিত্র" box right under the news body: the desk types how many incidents this news reports
 * (খুন ২, গ্রেপ্তার ৩ …). The district is the post's own area (এলাকা), so nothing is entered twice.
 * Published counts feed the LIVE band of the দৈনিক দেশচিত্র page at once, and the midnight sheet starts from them.
 * Needs newsroom-os 1.12.0 + theme 2.4.9; with an older backend the box simply does not show.
 */
import { useEffect, useMemo, useState } from 'react'
import { DeshchitroApi, type DcFields } from '../lib/api'
import { Section } from './PostFields'
import { IconMinus, IconPlus } from './Icon'

const BN = '০১২৩৪৫৬৭৮৯'
const bn = (v: number | string) => String(v).replace(/[0-9]/g, (d) => BN[Number(d)])
const en = (v: string) => v.replace(/[০-৯]/g, (d) => String(BN.indexOf(d)))

const TONE: Record<string, { bar: string; on: string; text: string }> = {
  bad: { bar: 'border-l-red-500', on: 'bg-red-50 border-red-200', text: 'text-red-700' },
  warn: { bar: 'border-l-amber-500', on: 'bg-amber-50 border-amber-200', text: 'text-amber-800' },
  good: { bar: 'border-l-emerald-500', on: 'bg-emerald-50 border-emerald-200', text: 'text-emerald-700' },
  info: { bar: 'border-l-blue-500', on: 'bg-blue-50 border-blue-200', text: 'text-blue-700' },
}

export function dcSummary(fields: DcFields | null, value: Record<string, number>): string {
  if (!fields) return ''
  const parts = fields.indicators.filter((i) => value[i.key]).map((i) => `${i.short} ${bn(value[i.key])}`)
  return parts.length ? parts.join(' · ') : 'কোনো সংখ্যা দেওয়া নেই'
}

export default function DeshchitroPostBox({
  value,
  onChange,
  district,
  title,
  defaultOpen,
}: {
  value: Record<string, number>
  onChange: (v: Record<string, number>) => void
  district: string
  title: string
  defaultOpen: boolean
}) {
  const [fields, setFields] = useState<DcFields | null>(null)
  useEffect(() => {
    let alive = true
    DeshchitroApi.fields().then((f) => { if (alive) setFields(f) })
    return () => { alive = false }
  }, [])

  // Indicators the headline suggests (same Bangla keywords the theme uses on the night sheet).
  const hinted = useMemo(() => {
    const out = new Set<string>()
    if (!fields || !title.trim()) return out
    Object.entries(fields.keywords).forEach(([k, re]) => {
      try { if (new RegExp(re, 'u').test(title)) out.add(k) } catch { /* bad pattern from settings: ignore */ }
    })
    return out
  }, [fields, title])

  if (!fields || !fields.available || !fields.indicators.length) return null

  const set = (k: string, n: number) => {
    const next = { ...value }
    n = Math.max(0, Math.min(9999, Math.round(n || 0)))
    if (n) next[k] = n
    else delete next[k]
    onChange(next)
  }
  const total = fields.indicators.reduce((s, i) => s + (value[i.key] || 0), 0)

  return (
    <Section
      title="দেশচিত্র — এই খবরের হিসাব"
      summary={dcSummary(fields, value)}
      defaultOpen={defaultOpen}
      testId="sec-deshchitro"
      badge={total ? <span className="text-[11px] font-medium bg-red-50 text-red-700 rounded-full px-2">{bn(total)}</span> : null}
    >
      <p className={`text-[12.5px] mb-3 ${district ? 'text-neutral-600' : 'text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2'}`}>
        {district ? (
          <>জেলা: <b className="text-neutral-900">{district}</b> <span className="text-neutral-400">(এলাকা অংশ থেকে নেওয়া)</span></>
        ) : (
          <>জেলা বাছাই করা নেই। "এলাকা" অংশে জেলা দিন, নইলে সংখ্যাগুলো "জেলা অজানা" হিসেবে গোনা হবে।</>
        )}
      </p>
      <div className="grid grid-cols-1 min-[380px]:grid-cols-2 sm:grid-cols-3 gap-2">
        {fields.indicators.map((i) => {
          const v = value[i.key] || 0
          const t = TONE[i.tone] || TONE.info
          const hint = !v && hinted.has(i.key)
          return (
            <div
              key={i.key}
              data-testid={`dc-${i.key}`}
              className={`flex items-center gap-1 rounded-xl border border-l-4 ${t.bar} pl-2.5 pr-1 py-1 ${v ? t.on : 'bg-white border-neutral-200'} ${hint ? 'ring-2 ring-amber-300' : ''}`}
            >
              <span className={`flex-1 min-w-0 text-[12.5px] leading-tight ${v ? `font-semibold ${t.text}` : 'text-neutral-700'}`} title={i.label}>
                {i.short}
                {hint && <span className="block text-[10.5px] font-normal text-amber-700">শিরোনামে আছে</span>}
              </span>
              <button type="button" aria-label={`${i.short} কমান`} onClick={() => set(i.key, v - 1)} disabled={!v} className="w-7 h-8 grid place-items-center rounded-md text-neutral-500 hover:bg-neutral-100 disabled:opacity-30">
                <IconMinus className="w-4 h-4" />
              </button>
              <input
                inputMode="numeric"
                aria-label={i.label}
                className={`w-10 text-center rounded-md border px-0.5 py-1 text-[14px] ${v ? 'font-bold border-transparent bg-white/70' : 'border-neutral-200'}`}
                value={v ? bn(v) : ''}
                placeholder="০"
                onChange={(e) => set(i.key, parseInt(en(e.target.value).replace(/[^0-9]/g, '') || '0', 10))}
              />
              <button type="button" aria-label={`${i.short} বাড়ান`} onClick={() => set(i.key, v + 1)} className="w-7 h-8 grid place-items-center rounded-md text-neutral-600 hover:bg-neutral-100">
                <IconPlus className="w-4 h-4" />
              </button>
            </div>
          )
        })}
      </div>
      <p className="text-[11.5px] text-neutral-500 mt-2.5 leading-relaxed">
        খবরে যতগুলো ঘটনা আছে সেই সংখ্যা দিন। প্রকাশের সাথে সাথে "দৈনিক দেশচিত্র" পাতার লাইভ অংশে যোগ হবে, আর রাত ১২টার হিসাবে আগে থেকেই বসে থাকবে।
        একই ঘটনার ফলোআপ খবরে আবার সংখ্যা দেবেন না।
      </p>
    </Section>
  )
}
