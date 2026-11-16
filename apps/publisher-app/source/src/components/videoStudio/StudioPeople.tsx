/**
 * Round 23: one-click name plates. Lists every correspondent (from the
 * newsroom, with their area) and a saved list of other people (guests,
 * officials) that anyone on the staff can add to. Picking a person adds a
 * "নাম ও পদবি" caption at the playhead.
 */
import { useMemo, useState } from 'react'
import { VideoKitApi, describeError, type VideoKit, type VideoKitPerson } from '../../lib/api'
import { IconPlus, IconTrash, IconX } from '../Icon'
import { inputClass } from '../ui'

export default function PeoplePicker({
  kit,
  onKit,
  onPick,
  onClose,
}: {
  kit: VideoKit | null
  onKit: (k: VideoKit) => void
  onPick: (p: VideoKitPerson) => void
  onClose: () => void
}) {
  const [q, setQ] = useState('')
  const [name, setName] = useState('')
  const [role, setRole] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const people = kit?.people ?? []
  const reporters = kit?.reporters ?? []
  const match = (p: VideoKitPerson) => !q.trim() || `${p.name} ${p.role}`.toLowerCase().includes(q.trim().toLowerCase())
  const shownPeople = useMemo(() => people.map((p, i) => ({ p, i })).filter(({ p }) => match(p)), [people, q])
  const shownReporters = useMemo(() => reporters.filter(match), [reporters, q])

  async function save(next: VideoKitPerson[]) {
    setBusy(true)
    setError('')
    try {
      onKit(await VideoKitApi.savePeople(next))
      return true
    } catch (err) {
      setError(describeError(err).message)
      return false
    } finally {
      setBusy(false)
    }
  }

  const row = (p: VideoKitPerson, key: string, onDelete?: () => void) => (
    <div key={key} className="flex items-center gap-2 rounded-lg hover:bg-neutral-50 px-2 py-1.5">
      <button type="button" onClick={() => onPick(p)} className="flex-1 min-w-0 text-left" data-testid="person">
        <span className="block text-[13px] font-medium text-neutral-800 truncate">{p.name}</span>
        {p.role && <span className="block text-[11.5px] text-neutral-500 truncate">{p.role}</span>}
      </button>
      {onDelete && (
        <button type="button" onClick={onDelete} disabled={busy} className="p-1 text-neutral-400 hover:text-red-600" aria-label={`${p.name} তালিকা থেকে সরান`}>
          <IconTrash className="w-3.5 h-3.5" />
        </button>
      )}
    </div>
  )

  return (
    <div className="rounded-xl border border-brand-200 bg-white p-3 mb-3" data-testid="people-picker">
      <div className="flex items-center justify-between mb-2">
        <p className="text-[13px] font-semibold text-neutral-800">নামের তালিকা</p>
        <button type="button" onClick={onClose} aria-label="বন্ধ করুন" className="text-neutral-400 hover:text-neutral-700">
          <IconX className="w-4 h-4" />
        </button>
      </div>
      <input className={inputClass} value={q} onChange={(e) => setQ(e.target.value)} placeholder="নাম বা পদবি খুঁজুন…" />
      <div className="max-h-64 overflow-y-auto mt-2">
        {shownPeople.length > 0 && (
          <>
            <p className="text-[11px] font-semibold text-neutral-400 px-2 mt-1">সংরক্ষিত নাম</p>
            {shownPeople.map(({ p, i }) => row(p, `p${i}`, () => save(people.filter((_, j) => j !== i))))}
          </>
        )}
        {shownReporters.length > 0 && (
          <>
            <p className="text-[11px] font-semibold text-neutral-400 px-2 mt-2">সংবাদদাতা</p>
            {shownReporters.map((p) => row(p, `r${p.id}`))}
          </>
        )}
        {!shownPeople.length && !shownReporters.length && <p className="text-[12px] text-neutral-400 text-center py-3">কোনো নাম পাওয়া যায়নি।</p>}
      </div>
      <form
        className="mt-3 border-t border-neutral-100 pt-3"
        onSubmit={async (e) => {
          e.preventDefault()
          if (!name.trim()) return
          const person = { name: name.trim(), role: role.trim() }
          // the same name and title again just moves it to the end
          const rest = people.filter((p) => p.name !== person.name || p.role !== person.role)
          if (await save([...rest, person])) {
            setName('')
            setRole('')
          }
        }}
      >
        <p className="text-[11.5px] text-neutral-500 mb-1">তালিকায় নতুন নাম রাখুন (অতিথি, কর্মকর্তা…)</p>
        <div className="flex gap-1.5">
          <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} placeholder="নাম" maxLength={90} data-testid="person-name" />
          <input className={inputClass} value={role} onChange={(e) => setRole(e.target.value)} placeholder="পদবি" maxLength={70} data-testid="person-role" />
          <button type="submit" disabled={busy || !name.trim()} className="shrink-0 rounded-lg bg-brand-600 text-white px-2.5 disabled:opacity-50" aria-label="তালিকায় রাখুন" data-testid="person-add">
            <IconPlus className="w-4 h-4" />
          </button>
        </div>
        {error && <p className="text-[11.5px] text-red-600 mt-1">{error}</p>}
      </form>
    </div>
  )
}
