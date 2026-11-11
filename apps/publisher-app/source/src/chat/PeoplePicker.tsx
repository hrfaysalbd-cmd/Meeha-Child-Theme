import { useEffect, useState } from 'react'
import { ChatApi, type Person } from './api'
import { Avatar, Sheet, Spinner } from './bits'
import { bnNum } from './util'

/** Staff only: search correspondents / staff by name, district or upazila. */
export default function PeoplePicker({
  title,
  multi = false,
  onlyCorrespondents = false,
  onlyStaff = false,
  exclude = [],
  already = [],
  onPick,
  onClose,
}: {
  title: string
  multi?: boolean
  onlyCorrespondents?: boolean
  /** only Editors/Publishers (ব্যক্তিগত মেসেজ, 1.26.0) */
  onlyStaff?: boolean
  exclude?: number[]
  /** shown, but marked “আছেন” and not pickable (already in the room) */
  already?: number[]
  onPick: (ids: number[]) => void
  onClose: () => void
}) {
  const [q, setQ] = useState('')
  const [people, setPeople] = useState<Person[] | null>(null)
  const [picked, setPicked] = useState<Person[]>([])

  useEffect(() => {
    const t = setTimeout(() => {
      ChatApi.people(q.trim(), onlyStaff)
        .then((r) => setPeople(r.people))
        .catch(() => setPeople([]))
    }, 250)
    return () => clearTimeout(t)
  }, [q, onlyStaff])

  const list = (people ?? []).filter((p) => !exclude.includes(p.id) && (!onlyCorrespondents || p.role === 'সংবাদদাতা') && (!onlyStaff || p.role === 'এডিটর' || p.role === 'পাবলিশার'))
  const toggle = (p: Person) => {
    if (already.includes(p.id)) return
    if (!multi) return onPick([p.id])
    setPicked((x) => (x.some((y) => y.id === p.id) ? x.filter((y) => y.id !== p.id) : [...x, p]))
  }

  return (
    <Sheet open onClose={onClose} title={title} wide>
      <div className="px-5 pb-2" data-testid="people-picker">
        <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="নাম, জেলা বা উপজেলা লিখুন…" className="w-full rounded-xl bg-neutral-100 px-3 py-2.5 text-[14px] outline-none focus:ring-2 focus:ring-brand-200" />
        {picked.length > 0 && (
          <div className="flex flex-wrap gap-1.5 mt-2">
            {picked.map((p) => (
              <button key={p.id} type="button" onClick={() => toggle(p)} className="inline-flex items-center gap-1 rounded-full bg-brand-50 text-brand-700 px-2.5 py-1 text-[12.5px] font-medium">
                {p.name} ×
              </button>
            ))}
          </div>
        )}
      </div>
      <div className="max-h-[50vh] overflow-y-auto">
        {!people ? (
          <div className="p-6 flex justify-center">
            <Spinner />
          </div>
        ) : list.length === 0 ? (
          <p className="p-6 text-center text-[13.5px] text-neutral-500">কাউকে পাওয়া যায়নি</p>
        ) : (
          list.map((p) => {
            const on = picked.some((x) => x.id === p.id)
            const inside = already.includes(p.id)
            return (
              <button key={p.id} type="button" onClick={() => toggle(p)} className={`w-full flex items-center gap-3 px-5 py-2.5 text-left ${inside ? 'cursor-default' : on ? 'bg-brand-50/60' : 'hover:bg-neutral-50'}`} data-testid={`person-${p.id}`}>
                <Avatar p={p} size={38} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[14px] font-medium text-neutral-900">{p.name}</span>
                  <span className="block truncate text-[11.5px] text-neutral-500">
                    {p.role}
                    {p.area ? ` · ${p.area}` : ''}
                  </span>
                </span>
                {inside ? (
                  <span className="shrink-0 rounded-full bg-emerald-50 text-emerald-700 px-2 py-0.5 text-[11.5px] font-semibold">✓ রুমে আছেন</span>
                ) : multi && <span className={`w-5 h-5 rounded-full border-2 flex items-center justify-center text-[11px] ${on ? 'bg-brand-600 border-brand-600 text-white' : 'border-neutral-300'}`}>{on ? '✓' : ''}</span>}
              </button>
            )
          })
        )}
      </div>
      {multi && (
        <div className="px-5 py-3 border-t border-neutral-100 flex justify-end">
          <button type="button" disabled={!picked.length} onClick={() => onPick(picked.map((p) => p.id))} className="px-5 py-2 rounded-xl bg-brand-600 disabled:bg-neutral-300 text-white text-[14px] font-semibold" data-testid="people-done">
            {picked.length ? `${bnNum(picked.length)} জনকে যোগ করুন` : 'বাছাই করুন'}
          </button>
        </div>
      )}
    </Sheet>
  )
}
