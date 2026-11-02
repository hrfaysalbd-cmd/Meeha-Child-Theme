import type { ReactNode } from 'react'
import { IconArrowLeft, IconAlertTriangle, IconRefresh } from './Icon'
import { describeError } from '../lib/api'

export function Spinner({ className = '' }: { className?: string }) {
  return (
    <div className={`flex items-center justify-center py-10 ${className}`}>
      <div className="h-7 w-7 rounded-full border-2 border-brand-600 border-t-transparent animate-spin" />
    </div>
  )
}

// Shown in place of a page's content when its initial data load fails —
// previously these screens had no .catch() at all, so any failure (an
// expired session, a dropped mobile connection, a backend hiccup) just
// left the loading spinner gone and the page silently blank forever, with
// no way back short of a hard reload. This gives every such screen a
// visible reason plus a one-tap retry instead.
// Round 9 follow-up 3: pass the caught `error` and the screen says what
// actually went wrong (no connection / server error / session ended) plus a
// one-line technical detail (endpoint · HTTP status · code) in small print,
// instead of one generic sentence for every possible failure.
export function ErrorRetry({
  message,
  error,
  onRetry,
}: {
  message?: string
  error?: unknown
  onRetry: () => void
}) {
  const described = error !== undefined && error !== null ? describeError(error) : null
  const text = message ?? described?.message ?? 'ডাটা লোড করতে সমস্যা হয়েছে।'
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-14 px-6 text-center">
      <div className="w-12 h-12 rounded-full bg-red-50 text-red-500 flex items-center justify-center">
        <IconAlertTriangle className="w-6 h-6" />
      </div>
      <p className="text-[13.5px] text-neutral-500 max-w-sm">{text}</p>
      {described?.detail && (
        <p dir="ltr" className="text-[11px] leading-snug text-neutral-400 font-mono break-all max-w-sm">
          {described.detail}
        </p>
      )}
      <button
        onClick={onRetry}
        className="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-neutral-100 text-neutral-700 text-[13px] font-medium active:scale-[0.98]"
      >
        <IconRefresh className="w-4 h-4" />
        আবার চেষ্টা করুন
      </button>
    </div>
  )
}

export function TopBar({
  title,
  onBack,
  right,
}: {
  title: string
  onBack?: () => void
  right?: ReactNode
}) {
  return (
    <div className="sticky top-0 z-10 flex items-center gap-2 bg-white/95 backdrop-blur border-b border-black/5 px-3 py-3">
      {onBack && (
        <button onClick={onBack} className="p-1 -ml-1 text-neutral-600 active:opacity-60">
          <IconArrowLeft className="w-5 h-5" />
        </button>
      )}
      <h1 className="text-[17px] font-semibold text-neutral-900 flex-1 truncate">{title}</h1>
      {right}
    </div>
  )
}

export function Button({
  children,
  onClick,
  type = 'button',
  variant = 'primary',
  disabled,
  className = '',
  full = true,
}: {
  children: ReactNode
  onClick?: () => void
  type?: 'button' | 'submit'
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
  disabled?: boolean
  className?: string
  full?: boolean
}) {
  const base = 'rounded-xl px-4 py-3 text-[15px] font-semibold transition active:scale-[0.98] disabled:opacity-50 disabled:active:scale-100'
  const variants: Record<string, string> = {
    primary: 'bg-brand-600 text-white shadow-sm shadow-brand-600/20',
    secondary: 'bg-neutral-100 text-neutral-800',
    ghost: 'bg-transparent text-brand-600',
    danger: 'bg-red-50 text-red-600',
  }
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`${base} ${variants[variant]} ${full ? 'w-full' : ''} ${className}`}
    >
      {children}
    </button>
  )
}

export function Field({
  label,
  children,
  required,
}: {
  label: string
  children: ReactNode
  required?: boolean
}) {
  return (
    <label className="block mb-4">
      <span className="block text-[13px] font-medium text-neutral-600 mb-1.5">
        {label} {required && <span className="text-brand-600">*</span>}
      </span>
      {children}
    </label>
  )
}

export const inputClass =
  'w-full rounded-xl border border-neutral-200 bg-neutral-50 px-3.5 py-2.5 text-[15px] text-neutral-900 placeholder:text-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-600/30 focus:border-brand-500'

export function Banner({ kind, children }: { kind: 'error' | 'success' | 'info'; children: ReactNode }) {
  const styles = {
    error: 'bg-red-50 text-red-700 border-red-100',
    success: 'bg-emerald-50 text-emerald-700 border-emerald-100',
    info: 'bg-blue-50 text-blue-700 border-blue-100',
  }
  return <div className={`rounded-xl border px-3.5 py-2.5 text-[13.5px] mb-4 ${styles[kind]}`}>{children}</div>
}

export function StatusBadge({ status, rejected }: { status: string; rejected?: boolean }) {
  // A rejected submission is a WordPress "draft" under the hood (see the
  // backend doc) — but it needs its own visible label here, not the plain
  // "ড্রাফট" a correspondent would read as "I haven't submitted this yet."
  if (rejected && status === 'draft') {
    return <span className="text-[12px] font-medium px-2 py-0.5 rounded-full bg-red-50 text-red-700">প্রত্যাখ্যাত</span>
  }
  const map: Record<string, { label: string; cls: string }> = {
    draft: { label: 'ড্রাফট', cls: 'bg-neutral-100 text-neutral-600' },
    pending: { label: 'রিভিউতে', cls: 'bg-amber-50 text-amber-700' },
    publish: { label: 'প্রকাশিত', cls: 'bg-emerald-50 text-emerald-700' },
    future: { label: 'নির্ধারিত', cls: 'bg-sky-50 text-sky-700' },
  }
  const m = map[status] || { label: status, cls: 'bg-neutral-100 text-neutral-600' }
  return <span className={`text-[12px] font-medium px-2 py-0.5 rounded-full ${m.cls}`}>{m.label}</span>
}

export function bnNumber(n: number | string) {
  const digits: Record<string, string> = {
    '0': '০', '1': '১', '2': '২', '3': '৩', '4': '৪',
    '5': '৫', '6': '৬', '7': '৭', '8': '৮', '9': '৯',
  }
  return String(n).replace(/[0-9]/g, (d) => digits[d])
}

export function bnDate(iso: string) {
  try {
    const d = new Date(iso.replace(' ', 'T'))
    const months = ['জানু', 'ফেব্রু', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টে', 'অক্টো', 'নভে', 'ডিসে']
    return `${bnNumber(d.getDate())} ${months[d.getMonth()]}, ${bnNumber(d.getFullYear())}`
  } catch {
    return iso
  }
}

/** "১:০৫" / "১:০২:০৩" — a video duration in Bengali digits. */
export function bnDuration(seconds: number | null | undefined) {
  if (seconds == null || !Number.isFinite(seconds) || seconds < 0) return ''
  const s = Math.round(seconds)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = String(s % 60).padStart(2, '0')
  return bnNumber(h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`)
}

/**
 * Two (or more) mutually exclusive options in a pill track — used to switch
 * between "সংবাদ" and "ভিডিও" without adding another bottom tab.
 */
export function Segmented<T extends string>({
  options,
  value,
  onChange,
  className = '',
  ariaLabel,
}: {
  options: Array<{ value: T; label: string; icon?: (p: { className?: string }) => ReactNode }>
  value: T
  onChange: (v: T) => void
  className?: string
  ariaLabel?: string
}) {
  return (
    <div role="tablist" aria-label={ariaLabel} className={`flex rounded-xl bg-neutral-100 p-1 ${className}`}>
      {options.map(({ value: v, label, icon: Icon }) => {
        const active = v === value
        return (
          <button
            key={v}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => !active && onChange(v)}
            className={`flex-1 flex items-center justify-center gap-1.5 rounded-[10px] py-2 text-[13.5px] transition ${
              active ? 'bg-white text-brand-600 font-semibold shadow-sm' : 'text-neutral-500 font-medium active:opacity-70'
            }`}
          >
            {Icon && <Icon className="w-4 h-4" />}
            {label}
          </button>
        )
      })}
    </div>
  )
}

/**
 * "৪২/৭০" under a text field. Past `recommended` it turns amber (a soft
 * guideline, e.g. a headline that will be cut off); past `max` red.
 */
export function CharCount({ length, recommended, max }: { length: number; recommended?: number; max?: number }) {
  const limit = max ?? recommended ?? 0
  const cls =
    max != null && length > max
      ? 'text-red-600 font-semibold'
      : recommended != null && length > recommended
        ? 'text-amber-600 font-medium'
        : 'text-neutral-400'
  return (
    <span className={`text-[11.5px] tabular-nums ${cls}`}>
      {bnNumber(length)}/{bnNumber(limit)}
    </span>
  )
}
