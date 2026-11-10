import { useEffect, useState, type ReactNode } from 'react'
import type { ChatLabel, Person } from './api'
import { initials, LABEL_ICON, tint } from './util'

/** Round picture: initials on a soft colour (gravatar's grey default face is skipped). */
export function Avatar({ p, size = 40, ring = false }: { p: Pick<Person, 'id' | 'name' | 'avatar'> & { online?: boolean }; size?: number; ring?: boolean }) {
  const own = p.avatar && !/gravatar\.com/.test(p.avatar)
  return (
    <span className="relative inline-flex shrink-0" style={{ width: size, height: size }}>
      {own ? (
        <img src={p.avatar} alt="" className={`rounded-full object-cover w-full h-full ${ring ? 'ring-2 ring-white' : ''}`} />
      ) : (
        <span
          className={`rounded-full w-full h-full flex items-center justify-center font-semibold text-neutral-700 ${ring ? 'ring-2 ring-white' : ''}`}
          style={{ background: tint(p.id), fontSize: size * 0.38 }}
        >
          {initials(p.name)}
        </span>
      )}
      {p.online && <span className="absolute bottom-0 right-0 rounded-full bg-emerald-500 ring-2 ring-white" style={{ width: size * 0.28, height: size * 0.28 }} />}
    </span>
  )
}

export function RoomIcon({ icon, size = 44, online = false, avatar }: { icon: string; size?: number; online?: boolean; avatar?: Pick<Person, 'id' | 'name' | 'avatar'> }) {
  if (avatar) return <Avatar p={{ ...avatar, online }} size={size} />
  return (
    <span className="relative inline-flex shrink-0 rounded-2xl bg-gradient-to-br from-brand-50 to-orange-50 ring-1 ring-brand-100 items-center justify-center" style={{ width: size, height: size, fontSize: size * 0.5 }}>
      {icon || '💬'}
    </span>
  )
}

export function LabelChip({ label, labels, small = false, onClick, active }: { label: string; labels: ChatLabel[]; small?: boolean; onClick?: () => void; active?: boolean }) {
  const l = labels.find((x) => x.key === label)
  if (!l) return null
  const style = active === false ? { color: l.color, background: '#fff', borderColor: `${l.color}55` } : { color: '#fff', background: l.color, borderColor: l.color }
  const cls = `inline-flex items-center gap-1 rounded-full border font-semibold whitespace-nowrap ${small ? 'px-1.5 py-[1px] text-[10.5px]' : 'px-2.5 py-1 text-[12px]'}`
  const inner = (
    <>
      <span>{LABEL_ICON[l.key]}</span>
      {l.name}
    </>
  )
  return onClick ? (
    <button type="button" onClick={onClick} className={cls} style={style} data-testid={`label-${l.key}`}>
      {inner}
    </button>
  ) : (
    <span className={cls} style={style}>
      {inner}
    </span>
  )
}

export function CountPill({ n, tone = 'bg-brand-600' }: { n: number; tone?: string }) {
  if (!n) return null
  return <span className={`inline-flex min-w-[20px] h-5 px-1.5 items-center justify-center rounded-full ${tone} text-white text-[11px] font-bold`}>{n > 99 ? '৯৯+' : String(n).replace(/\d/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])}</span>
}

/** Bottom sheet on phones, centred card on a big screen. */
export function Sheet({ open, onClose, title, children, wide = false }: { open: boolean; onClose: () => void; title?: ReactNode; children: ReactNode; wide?: boolean }) {
  useEffect(() => {
    if (!open) return
    const k = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    document.addEventListener('keydown', k)
    return () => document.removeEventListener('keydown', k)
  }, [open, onClose])
  if (!open) return null
  return (
    <div className="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" role="dialog">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div
        className={`relative w-full ${wide ? 'sm:max-w-[520px]' : 'sm:max-w-[400px]'} max-h-[88vh] bg-white rounded-t-3xl sm:rounded-2xl shadow-2xl flex flex-col overflow-hidden pb-[env(safe-area-inset-bottom)]`}
      >
        {title !== undefined && (
          <div className="flex items-center gap-2 px-5 pt-4 pb-2">
            <div className="flex-1 text-[16px] font-bold text-neutral-900">{title}</div>
            <button type="button" onClick={onClose} className="w-8 h-8 rounded-full hover:bg-neutral-100 text-neutral-500 text-[20px] leading-none" aria-label="বন্ধ">
              ×
            </button>
          </div>
        )}
        <div className="overflow-y-auto min-h-0">{children}</div>
      </div>
    </div>
  )
}

export function Spinner({ className = 'w-5 h-5' }: { className?: string }) {
  return <span className={`inline-block rounded-full border-2 border-neutral-300 border-t-brand-600 animate-spin ${className}`} />
}

/** "ঠিক আছে / বাতিল" in a sheet instead of the browser's confirm(). */
export function useConfirm() {
  const [ask, setAsk] = useState<{ text: string; yes: string; danger: boolean; resolve: (v: boolean) => void } | null>(null)
  const confirm = (text: string, yes = 'হ্যাঁ', danger = true) => new Promise<boolean>((resolve) => setAsk({ text, yes, danger, resolve }))
  const node = (
    <Sheet open={!!ask} onClose={() => (ask?.resolve(false), setAsk(null))}>
      <div className="px-6 pt-6 pb-5">
        <p className="text-[15px] text-neutral-800 leading-relaxed whitespace-pre-line">{ask?.text}</p>
        <div className="mt-5 flex gap-2 justify-end">
          <button type="button" className="px-4 py-2 rounded-xl text-[14px] text-neutral-600 hover:bg-neutral-100" onClick={() => (ask?.resolve(false), setAsk(null))}>
            বাতিল
          </button>
          <button
            type="button"
            data-testid="confirm-yes"
            className={`px-4 py-2 rounded-xl text-[14px] font-semibold text-white ${ask?.danger ? 'bg-red-600' : 'bg-brand-600'}`}
            onClick={() => (ask?.resolve(true), setAsk(null))}
          >
            {ask?.yes}
          </button>
        </div>
      </div>
    </Sheet>
  )
  return { confirm, node }
}
