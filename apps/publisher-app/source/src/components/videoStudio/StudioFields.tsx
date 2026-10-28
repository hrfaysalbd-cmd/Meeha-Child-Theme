/**
 * Round 21 — small form controls shared by the video editor's panels.
 */
import { useEffect, useState, type ReactNode } from 'react'
import { bnClock } from '../../lib/videoEdit/motion'
import { bnNumber } from '../ui'

export function Section({ title, hint, children, right }: { title: string; hint?: string; children: ReactNode; right?: ReactNode }) {
  return (
    <div className="mb-5 last:mb-0">
      <div className="flex items-end justify-between gap-2 mb-2">
        <div>
          <p className="text-[12.5px] font-semibold text-neutral-700">{title}</p>
          {hint && <p className="text-[11.5px] text-neutral-400 leading-snug">{hint}</p>}
        </div>
        {right}
      </div>
      {children}
    </div>
  )
}

export function Switch({ checked, onChange, label, testId }: { checked: boolean; onChange: (v: boolean) => void; label: string; testId?: string }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      data-testid={testId}
      onClick={() => onChange(!checked)}
      className={`relative w-10 h-6 rounded-full shrink-0 transition-colors ${checked ? 'bg-brand-600' : 'bg-neutral-300'}`}
    >
      <span className={`absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform ${checked ? 'translate-x-4' : ''}`} />
    </button>
  )
}

export function SwitchRow({ label, hint, checked, onChange, testId }: { label: string; hint?: string; checked: boolean; onChange: (v: boolean) => void; testId?: string }) {
  return (
    <div className="flex items-center justify-between gap-3 py-1.5">
      <div className="min-w-0">
        <p className="text-[13px] text-neutral-800">{label}</p>
        {hint && <p className="text-[11.5px] text-neutral-400 leading-snug">{hint}</p>}
      </div>
      <Switch checked={checked} onChange={onChange} label={label} testId={testId} />
    </div>
  )
}

export function Slider({
  label,
  value,
  min,
  max,
  step = 1,
  onChange,
  format,
}: {
  label: string
  value: number
  min: number
  max: number
  step?: number
  onChange: (v: number) => void
  format?: (v: number) => string
}) {
  return (
    <label className="block py-1.5">
      <span className="flex items-center justify-between text-[12.5px] text-neutral-600 mb-1">
        {label}
        <span className="font-medium text-neutral-800 tabular-nums">{format ? format(value) : bnNumber(value)}</span>
      </span>
      <input
        type="range"
        min={min}
        max={max}
        step={step}
        value={value}
        onChange={(e) => onChange(Number(e.target.value))}
        className="w-full accent-[#b91c1c]"
        aria-label={label}
      />
    </label>
  )
}

/** Button group (one choice). */
export function Segmented<T extends string>({ value, options, onChange, size = 'md' }: { value: T; options: { id: T; name: string }[]; onChange: (v: T) => void; size?: 'sm' | 'md' }) {
  return (
    <div className="flex flex-wrap gap-1.5" role="radiogroup">
      {options.map((o) => (
        <button
          key={o.id}
          type="button"
          role="radio"
          aria-checked={value === o.id}
          onClick={() => onChange(o.id)}
          className={`rounded-lg border ${size === 'sm' ? 'px-2 py-1 text-[11.5px]' : 'px-2.5 py-1.5 text-[12.5px]'} font-medium transition ${
            value === o.id ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-neutral-200 text-neutral-600 hover:border-neutral-300'
          }`}
        >
          {o.name}
        </button>
      ))}
    </div>
  )
}

const BN_TO_LATIN: Record<string, string> = { '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4', '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9' }

/** "1:05.5", "৬৫", "1:05" → seconds; null if unreadable. */
export function parseTime(raw: string): number | null {
  const s = raw.trim().replace(/[০-৯]/g, (d) => BN_TO_LATIN[d]).replace(/[।,]/g, '.')
  if (!s) return null
  const parts = s.split(':')
  if (parts.length > 3 || parts.some((p) => p === '' || !/^\d*\.?\d*$/.test(p))) return null
  let t = 0
  for (const p of parts) t = t * 60 + Number(p)
  return Number.isFinite(t) ? t : null
}

/** Time input (m:ss.s) that also accepts Bengali digits, + optional "set from playhead". */
export function TimeField({
  label,
  value,
  onChange,
  onPlayhead,
  min = 0,
  max,
}: {
  label: string
  value: number
  onChange: (v: number) => void
  onPlayhead?: () => void
  min?: number
  max?: number
}) {
  const [text, setText] = useState(() => bnClock(value, true))
  const [bad, setBad] = useState(false)
  useEffect(() => {
    setText(bnClock(value, true))
    setBad(false)
  }, [value])
  const commit = () => {
    const t = parseTime(text)
    if (t === null) {
      setBad(true)
      return
    }
    const v = Math.min(max ?? Infinity, Math.max(min, t))
    onChange(Math.round(v * 10) / 10)
    setText(bnClock(v, true))
    setBad(false)
  }
  return (
    <div>
      <span className="block text-[11.5px] text-neutral-500 mb-1">{label}</span>
      <div className="flex items-center gap-1.5">
        <input
          value={text}
          onChange={(e) => setText(e.target.value)}
          onBlur={commit}
          onKeyDown={(e) => e.key === 'Enter' && commit()}
          inputMode="decimal"
          aria-label={label}
          className={`w-[84px] rounded-lg border px-2 py-1.5 text-[13px] tabular-nums focus:outline-none focus:ring-2 focus:ring-brand-600/20 ${
            bad ? 'border-red-300' : 'border-neutral-200'
          }`}
        />
        {onPlayhead && (
          <button
            type="button"
            onClick={onPlayhead}
            title="প্রিভিউ যেখানে থেমে আছে সেই সময়"
            className="text-[11.5px] px-2 py-1.5 rounded-lg bg-neutral-100 text-neutral-700 hover:bg-neutral-200 whitespace-nowrap"
          >
            এখানে
          </button>
        )}
      </div>
    </div>
  )
}

export function TextInput({
  label,
  value,
  onChange,
  max,
  placeholder,
  multiline,
  testId,
}: {
  label: string
  value: string
  onChange: (v: string) => void
  max: number
  placeholder?: string
  multiline?: boolean
  testId?: string
}) {
  const n = [...value].length
  const cls =
    'w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-[13.5px] text-neutral-900 placeholder:text-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-600/20 focus:border-brand-500'
  return (
    <label className="block mb-3">
      <span className="flex items-end justify-between text-[12px] font-medium text-neutral-600 mb-1">
        {label}
        <span className={`text-[11px] tabular-nums ${n > max * 0.9 ? 'text-amber-600' : 'text-neutral-400'}`}>
          {bnNumber(n)}/{bnNumber(max)}
        </span>
      </span>
      {multiline ? (
        <textarea className={cls} rows={2} value={value} maxLength={max} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} data-testid={testId} />
      ) : (
        <input className={cls} value={value} maxLength={max} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} data-testid={testId} />
      )}
    </label>
  )
}

/** Big selectable card (frames, intros, formats). */
export function ChoiceCard({
  active,
  onClick,
  title,
  hint,
  preview,
  testId,
}: {
  active: boolean
  onClick: () => void
  title: string
  hint?: string
  preview?: ReactNode
  testId?: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      data-testid={testId}
      className={`text-left rounded-xl border overflow-hidden transition ${
        active ? 'border-brand-500 ring-2 ring-brand-500/25' : 'border-neutral-200 hover:border-neutral-300'
      }`}
    >
      {preview}
      <span className="block px-2.5 py-2">
        <span className={`block text-[12.5px] font-semibold ${active ? 'text-brand-700' : 'text-neutral-800'}`}>{title}</span>
        {hint && <span className="block text-[11px] text-neutral-400 leading-snug mt-0.5">{hint}</span>}
      </span>
    </button>
  )
}
