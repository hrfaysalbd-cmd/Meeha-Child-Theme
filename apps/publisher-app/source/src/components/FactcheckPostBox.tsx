/**
 * r46.1 — "ফ্যাক্টচেক" box in the news editor (Hafiz: it was only in wp-admin).
 * Same fields as the theme's wp-admin box: দাবি, দাবিকারী, যেখানে ছড়িয়েছে, রায়, যাচাইয়ের বিবরণ, সূত্র.
 * When it is on, the site adds the verdict card, Google ClaimReview data and the "ফ্যাক্টচেক" tag by itself.
 * Needs newsroom-os 1.28.1 + theme 2.5.0; with an older backend (factcheck = null) the box does not show.
 */
import type { FactcheckData, FactcheckFields, FactcheckSource } from '../lib/api'
import { Section } from './PostFields'
import { inputClass } from './ui'

export const EMPTY_FC: FactcheckFields = {
  on: false,
  claim: '',
  claimant: '',
  claimant_type: 'person',
  claim_date: '',
  spread: [],
  verdict: '',
  evidence: '',
  sources: [],
}

/** The theme's verdicts (inc/factcheck/bootstrap.php), for a brand-new article that has no API copy yet. */
export const NEW_POST_FC: FactcheckData = {
  ...EMPTY_FC,
  verdicts: [
    { key: 'true', label: 'সত্য', color: '#1E7B45', meaning: 'দাবিটি তথ্যপ্রমাণে সঠিক।' },
    { key: 'partly', label: 'আংশিক সত্য', color: '#8A6100', meaning: 'দাবির কিছু অংশ সঠিক, কিছু অংশ ভুল বা অসম্পূর্ণ।' },
    { key: 'misleading', label: 'বিভ্রান্তিকর', color: '#C2410C', meaning: 'তথ্য সত্য হলেও এমনভাবে উপস্থাপিত যে ভুল ধারণা তৈরি করে।' },
    { key: 'false', label: 'মিথ্যা', color: '#CC2A1F', meaning: 'দাবিটি তথ্যপ্রমাণে ভুল প্রমাণিত।' },
    { key: 'context', label: 'প্রেক্ষাপটহীন', color: '#6B4FA0', meaning: 'পুরোনো বা অন্য ঘটনার ছবি/ভিডিও/উক্তি ভিন্ন প্রেক্ষাপটে ছড়ানো হয়েছে।' },
    { key: 'satire', label: 'ব্যঙ্গ', color: '#2C6E9B', meaning: 'ব্যঙ্গাত্মক কনটেন্ট, যা অনেকে সত্য খবর ভেবে ছড়িয়েছেন।' },
  ],
}

/** Words in a headline that usually mean a fact-check story (automation hint). */
const FC_HINT = /ফ্যাক্ট\s*চেক|ফ্যাক্টচেক|যাচাই|গুজব|ভুয়া|ভুয়া|মিথ্যা দাবি|ভাইরাল|সত্যতা|এডিটেড|এআই দিয়ে তৈরি|পুরোনো ছবি|পুরোনো ভিডিও/u

export function fcFromPost(d: FactcheckData | null | undefined): FactcheckFields {
  if (!d) return { ...EMPTY_FC }
  return {
    on: !!d.on,
    claim: d.claim ?? '',
    claimant: d.claimant ?? '',
    claimant_type: d.claimant_type === 'org' ? 'org' : 'person',
    claim_date: d.claim_date ?? '',
    spread: Array.isArray(d.spread) ? d.spread : [],
    verdict: d.verdict ?? '',
    evidence: d.evidence ?? '',
    sources: Array.isArray(d.sources) ? d.sources.map((s) => ({ title: s?.title ?? '', url: s?.url ?? '' })) : [],
  }
}

function platform(url: string): string {
  const u = url.toLowerCase()
  if (/facebook\.com|fb\.watch|fb\.com/.test(u)) return 'ফেসবুক'
  if (/youtube\.com|youtu\.be/.test(u)) return 'ইউটিউব'
  if (/tiktok\.com/.test(u)) return 'টিকটক'
  if (/twitter\.com|x\.com/.test(u)) return 'এক্স'
  if (/instagram\.com/.test(u)) return 'ইনস্টাগ্রাম'
  if (/whatsapp|wa\.me/.test(u)) return 'হোয়াটসঅ্যাপ'
  return 'ওয়েব'
}

export default function FactcheckPostBox({
  data,
  value,
  onChange,
  title,
  defaultOpen,
}: {
  /** From the API: null = backend has no fact-check module, so nothing is shown. */
  data: FactcheckData | null | undefined
  value: FactcheckFields
  onChange: (v: FactcheckFields) => void
  title: string
  defaultOpen: boolean
}) {
  if (!data) return null
  const verdicts = data.verdicts ?? []
  const set = (patch: Partial<FactcheckFields>) => onChange({ ...value, ...patch })
  const cur = verdicts.find((v) => v.key === value.verdict)
  const hinted = !value.on && FC_HINT.test(title)
  const spreadText = value.spread.join('\n')
  const setSource = (i: number, patch: Partial<FactcheckSource>) =>
    set({ sources: value.sources.map((s, j) => (j === i ? { ...s, ...patch } : s)) })

  const missing: string[] = []
  if (value.on) {
    if (!value.claim.trim()) missing.push('দাবি')
    if (!value.verdict) missing.push('রায়')
  }

  const summary = value.on
    ? cur
      ? <span className="font-semibold" style={{ color: cur.color }}>রায়: {cur.label}</span>
      : 'চালু — রায় দেওয়া হয়নি'
    : hinted
      ? 'শিরোনাম দেখে মনে হচ্ছে ফ্যাক্টচেক — চালু করবেন?'
      : 'বন্ধ'

  return (
    <Section title="ফ্যাক্টচেক" summary={summary} defaultOpen={defaultOpen || value.on || hinted} testId="sec-factcheck">
      <label className="flex items-start gap-2.5 mb-3 cursor-pointer">
        <input
          type="checkbox"
          className="mt-1 h-4 w-4 accent-brand-600"
          checked={value.on}
          onChange={(e) => set({ on: e.target.checked })}
          data-testid="fc-on"
        />
        <span>
          <span className="block text-sm font-semibold text-neutral-900">এটি একটি ফ্যাক্টচেক প্রতিবেদন</span>
          <span className="block text-[12px] text-neutral-500">
            চালু করলে লেখার শুরুতে রায়-কার্ড, Google-এর ClaimReview ডেটা আর “ফ্যাক্টচেক” ট্যাগ নিজে থেকেই যোগ হবে, খবরটি /factcheck/ পাতায় দেখাবে।
          </span>
        </span>
      </label>

      {hinted && (
        <button
          type="button"
          onClick={() => set({ on: true })}
          className="mb-3 text-[12.5px] font-medium text-brand-700 bg-brand-50 border border-brand-100 rounded-full px-3 py-1"
        >
          শিরোনামে “যাচাই/গুজব/ভুয়া” আছে — ফ্যাক্টচেক হিসেবে চালু করুন
        </button>
      )}

      {value.on && (
        <div className="space-y-3.5">
          <label className="block">
            <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">
              দাবি <span className="text-brand-600">*</span> <span className="font-normal text-neutral-400">(যে কথাটি যাচাই করা হলো, এক-দুই বাক্যে)</span>
            </span>
            <textarea className={inputClass} rows={2} value={value.claim} onChange={(e) => set({ claim: e.target.value })} data-testid="fc-claim" />
          </label>

          <div className="grid gap-3 sm:grid-cols-[2fr_1fr_1fr]">
            <label className="block">
              <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">দাবিকারী <span className="font-normal text-neutral-400">(ব্যক্তি, পেজ বা প্রতিষ্ঠান)</span></span>
              <input className={inputClass} value={value.claimant} onChange={(e) => set({ claimant: e.target.value })} />
            </label>
            <label className="block">
              <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">দাবিকারীর ধরন</span>
              <select className={inputClass} value={value.claimant_type} onChange={(e) => set({ claimant_type: e.target.value === 'org' ? 'org' : 'person' })}>
                <option value="person">ব্যক্তি</option>
                <option value="org">পেজ / প্রতিষ্ঠান</option>
              </select>
            </label>
            <label className="block">
              <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">দাবির তারিখ</span>
              <input type="date" className={inputClass} value={value.claim_date} onChange={(e) => set({ claim_date: e.target.value })} />
            </label>
          </div>

          <label className="block">
            <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">
              যেখানে ছড়িয়েছে <span className="font-normal text-neutral-400">(ফেসবুক / ইউটিউব / টিকটক লিংক — প্রতি লাইনে একটি)</span>
            </span>
            <textarea
              className={inputClass}
              rows={3}
              placeholder="https://www.facebook.com/…"
              value={spreadText}
              onChange={(e) => set({ spread: e.target.value.split(/\r?\n/) })}
              onBlur={() => set({ spread: value.spread.map((s) => s.trim()).filter(Boolean) })}
            />
            {value.spread.some((s) => s.trim()) && (
              <span className="mt-1.5 flex flex-wrap gap-1.5">
                {value.spread.filter((s) => s.trim()).map((s, i) => (
                  <span key={i} className="text-[11.5px] rounded-full bg-neutral-100 text-neutral-700 px-2 py-0.5">{platform(s)}</span>
                ))}
              </span>
            )}
          </label>

          <div>
            <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">রায় <span className="text-brand-600">*</span></span>
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="রায়">
              {verdicts.map((v) => {
                const on = v.key === value.verdict
                return (
                  <button
                    key={v.key}
                    type="button"
                    role="radio"
                    aria-checked={on}
                    title={v.meaning}
                    onClick={() => set({ verdict: on ? '' : v.key })}
                    className="rounded-full border px-3 py-1.5 text-[13px] font-semibold transition-colors"
                    style={on ? { background: v.color, borderColor: v.color, color: '#fff' } : { borderColor: v.color, color: v.color }}
                    data-testid={`fc-verdict-${v.key}`}
                  >
                    {v.label}
                  </button>
                )
              })}
            </div>
            {cur?.meaning && <p className="mt-1.5 text-[12px] text-neutral-500">{cur.meaning}</p>}
          </div>

          <label className="block">
            <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">
              যেভাবে যাচাই করেছি <span className="font-normal text-neutral-400">(কোন টুল, কার সঙ্গে কথা, কী পাওয়া গেল)</span>
            </span>
            <textarea className={inputClass} rows={5} value={value.evidence} onChange={(e) => set({ evidence: e.target.value })} />
          </label>

          <div>
            <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">সূত্র</span>
            <div className="space-y-2">
              {value.sources.map((s, i) => (
                <div key={i} className="flex gap-2">
                  <input className={inputClass} placeholder="শিরোনাম (যেমন: নির্বাচন কমিশনের বিজ্ঞপ্তি)" value={s.title} onChange={(e) => setSource(i, { title: e.target.value })} />
                  <input className={inputClass} placeholder="https://…" value={s.url} onChange={(e) => setSource(i, { url: e.target.value })} />
                  <button
                    type="button"
                    aria-label="সূত্র মুছুন"
                    onClick={() => set({ sources: value.sources.filter((_, j) => j !== i) })}
                    className="shrink-0 w-10 rounded-xl border border-neutral-200 text-neutral-500 hover:text-red-600"
                  >
                    ✕
                  </button>
                </div>
              ))}
            </div>
            <button
              type="button"
              onClick={() => set({ sources: [...value.sources, { title: '', url: '' }] })}
              className="mt-2 text-[13px] font-medium text-brand-700"
              data-testid="fc-add-source"
            >
              ＋ সূত্র যোগ করুন
            </button>
          </div>

          {missing.length > 0 && (
            <p className="text-[12.5px] text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">
              রায়-কার্ড ঠিকমতো দেখাতে {missing.join(' ও ')} দিন।
            </p>
          )}
        </div>
      )}
    </Section>
  )
}
