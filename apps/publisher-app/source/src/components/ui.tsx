import type { ReactNode } from 'react'
import { IconAlertTriangle, IconRefresh } from './Icon'
import { describeError } from '../lib/api'

export function Spinner({ className = '' }: { className?: string }) {
  return (
    <div className={`flex items-center justify-center py-10 ${className}`}>
      <div className="h-7 w-7 rounded-full border-2 border-brand-600 border-t-transparent animate-spin" />
    </div>
  )
}

// Shown in place of a page's content when its initial data load fails — see
// the matching component in the correspondent app for the full rationale
// (Overview.tsx previously had no .catch() at all on its load effect).
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

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 mb-6">
      <div>
        <h1 className="text-[22px] font-bold text-neutral-900">{title}</h1>
        {subtitle && <p className="text-[13.5px] text-neutral-500 mt-0.5">{subtitle}</p>}
      </div>
      {actions && <div className="flex items-center gap-2 shrink-0">{actions}</div>}
    </div>
  )
}

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`rounded-2xl bg-white border border-neutral-200/80 shadow-sm ${className}`}>{children}</div>
}

export function KpiCard({
  label,
  value,
  tint = 'bg-brand-50 text-brand-600',
  icon,
}: {
  label: string
  value: string | number
  tint?: string
  icon?: ReactNode
}) {
  return (
    <Card className="p-4 flex items-center gap-3">
      {icon && <div className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${tint}`}>{icon}</div>}
      <div>
        <p className="text-[20px] font-bold text-neutral-900 leading-tight">{value}</p>
        <p className="text-[12.5px] text-neutral-500">{label}</p>
      </div>
    </Card>
  )
}

export function Button({
  children,
  onClick,
  type = 'button',
  variant = 'primary',
  disabled,
  className = '',
  size = 'md',
  'data-testid': testId,
}: {
  children: ReactNode
  'data-testid'?: string
  onClick?: () => void
  type?: 'button' | 'submit'
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'success' | 'destructive'
  disabled?: boolean
  className?: string
  size?: 'sm' | 'md'
}) {
  const base = 'rounded-lg font-semibold transition active:scale-[0.98] disabled:opacity-50 disabled:active:scale-100 inline-flex items-center justify-center gap-1.5 whitespace-nowrap'
  const sizes = { sm: 'px-2.5 py-1.5 text-[12.5px]', md: 'px-4 py-2 text-[13.5px]' }
  const variants: Record<string, string> = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700',
    secondary: 'bg-neutral-100 text-neutral-800 hover:bg-neutral-200',
    ghost: 'bg-transparent text-brand-600 hover:bg-brand-50',
    danger: 'bg-red-50 text-red-600 hover:bg-red-100',
    success: 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100',
    // Round 10 — the final "yes, delete" button in a confirmation dialog.
    destructive: 'bg-red-600 text-white hover:bg-red-700',
  }
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      data-testid={testId}
      className={`${base} ${sizes[size]} ${variants[variant]} ${className}`}
    >
      {children}
    </button>
  )
}

export const inputClass =
  'w-full rounded-lg border border-neutral-200 bg-white px-3 py-2 text-[13.5px] text-neutral-900 placeholder:text-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-600/20 focus:border-brand-500'

export function Field({ label, children, required }: { label: string; children: ReactNode; required?: boolean }) {
  return (
    <label className="block mb-3.5">
      <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">
        {label} {required && <span className="text-brand-600">*</span>}
      </span>
      {children}
    </label>
  )
}

export function Banner({ kind, children }: { kind: 'error' | 'success' | 'info'; children: ReactNode }) {
  const styles = {
    error: 'bg-red-50 text-red-700 border-red-100',
    success: 'bg-emerald-50 text-emerald-700 border-emerald-100',
    info: 'bg-blue-50 text-blue-700 border-blue-100',
  }
  return <div className={`rounded-xl border px-3.5 py-2.5 text-[13px] mb-4 ${styles[kind]}`}>{children}</div>
}

export function Badge({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'amber' | 'emerald' | 'red' | 'blue' }) {
  const tones: Record<string, string> = {
    neutral: 'bg-neutral-100 text-neutral-600',
    amber: 'bg-amber-50 text-amber-700',
    emerald: 'bg-emerald-50 text-emerald-700',
    red: 'bg-red-50 text-red-600',
    blue: 'bg-blue-50 text-blue-700',
  }
  return <span className={`text-[11.5px] font-medium px-2 py-0.5 rounded-full whitespace-nowrap ${tones[tone]}`}>{children}</span>
}

export function EmptyState({ children }: { children: ReactNode }) {
  return <div className="text-center py-14 text-[13.5px] text-neutral-400">{children}</div>
}

export function Table({ children }: { children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-[13.5px]">{children}</table>
    </div>
  )
}

export function Th({ children }: { children: ReactNode }) {
  return <th className="px-4 py-2.5 font-medium text-neutral-500 text-[12px] border-b border-neutral-100 whitespace-nowrap">{children}</th>
}

export function Td({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <td className={`px-4 py-3 border-b border-neutral-50 ${className}`}>{children}</td>
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

export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
      <div className="bg-white rounded-2xl w-full max-w-md max-h-[85vh] overflow-y-auto shadow-xl">
        <div className="flex items-center justify-between px-5 py-4 border-b border-neutral-100 sticky top-0 bg-white">
          <h2 className="text-[15px] font-semibold text-neutral-900">{title}</h2>
          <button onClick={onClose} className="text-neutral-400 hover:text-neutral-700">
            <svg xmlns="http://www.w3.org/2000/svg" width={20} height={20} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
              <path d="M18 6l-12 12" /><path d="M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  )
}
