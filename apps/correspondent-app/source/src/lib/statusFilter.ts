// Round 18: the list can be narrowed by where each item stands, so approved
// and returned posts are one tap away (and the approved/returned
// notifications open the matching filter via ?status=).
export type StatusFilter = 'all' | 'approved' | 'pending' | 'rejected' | 'draft'

export const STATUS_FILTERS: { value: StatusFilter; label: string; dot: string }[] = [
  { value: 'all', label: 'সব', dot: '' },
  { value: 'approved', label: 'অনুমোদিত', dot: 'bg-emerald-500' },
  { value: 'pending', label: 'রিভিউতে', dot: 'bg-amber-500' },
  { value: 'rejected', label: 'প্রত্যাখ্যাত', dot: 'bg-red-500' },
  { value: 'draft', label: 'ড্রাফট', dot: 'bg-neutral-400' },
]

export function statusGroup(item: { status: string; rejected?: boolean }): Exclude<StatusFilter, 'all'> {
  if (item.status === 'publish' || item.status === 'future') return 'approved'
  if (item.status === 'pending') return 'pending'
  return item.rejected ? 'rejected' : 'draft'
}

export function parseStatusFilter(v: string | null): StatusFilter {
  return STATUS_FILTERS.some((f) => f.value === v) ? (v as StatusFilter) : 'all'
}
