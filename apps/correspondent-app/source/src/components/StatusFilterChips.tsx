import { bnNumber } from './ui'
import { STATUS_FILTERS, statusGroup, type StatusFilter } from '../lib/statusFilter'

export { parseStatusFilter, statusGroup, type StatusFilter } from '../lib/statusFilter'

export default function StatusFilterChips<T extends { status: string; rejected?: boolean }>({
  items,
  value,
  onChange,
}: {
  items: T[]
  value: StatusFilter
  onChange: (v: StatusFilter) => void
}) {
  const counts: Record<StatusFilter, number> = { all: items.length, approved: 0, pending: 0, rejected: 0, draft: 0 }
  items.forEach((i) => counts[statusGroup(i)]++)
  return (
    <div className="flex gap-1.5 overflow-x-auto pb-1 mb-3 -mx-5 px-5" role="tablist" aria-label="অবস্থা অনুযায়ী">
      {STATUS_FILTERS.map((f) => {
        const active = f.value === value
        if (f.value !== 'all' && counts[f.value] === 0 && !active) return null
        return (
          <button
            key={f.value}
            role="tab"
            aria-selected={active}
            onClick={() => onChange(f.value)}
            className={`shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[12.5px] font-medium border ${
              active ? 'bg-neutral-900 border-neutral-900 text-white' : 'bg-white border-neutral-200 text-neutral-600'
            }`}
          >
            {f.dot && <span className={`w-1.5 h-1.5 rounded-full ${f.dot}`} />}
            {f.label}
            <span className={active ? 'text-white/70' : 'text-neutral-400'}>{bnNumber(counts[f.value])}</span>
          </button>
        )
      })}
    </div>
  )
}
