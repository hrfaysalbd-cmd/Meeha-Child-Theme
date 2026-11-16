/**
 * Round 24 — "উক্তি বাছুন": the site's উক্তি (search); a pick fills the text,
 * speaker, role, type and the matching frame.
 */
import { useEffect, useRef, useState } from 'react'
import { PhotocardApi, describeError, type CardQuote } from '../../lib/api'
import { inputClass } from '../ui'
import { IconSearch } from '../Icon'

export default function QuotePicker({ pickedId, onPick }: { pickedId: number | null; onPick: (q: CardQuote) => void }) {
  const [query, setQuery] = useState('')
  const [items, setItems] = useState<CardQuote[]>([])
  const [note, setNote] = useState('খোঁজা হচ্ছে…')
  const seq = useRef(0)

  useEffect(() => {
    const t = setTimeout(async () => {
      const mine = ++seq.current
      try {
        const r = await PhotocardApi.quotes(query.trim())
        if (mine !== seq.current) return
        setItems(r.items || [])
        setNote(r.items?.length ? '' : 'কোনো উক্তি পাওয়া যায়নি।')
      } catch (e) {
        if (mine === seq.current) setNote(describeError(e).message)
      }
    }, query ? 350 : 0)
    return () => clearTimeout(t)
  }, [query])

  return (
    <div>
      <div className="relative mb-2">
        <IconSearch className="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-neutral-400" />
        <input className={`${inputClass} pl-8`} type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="উক্তি বা বক্তার নাম" aria-label="উক্তি খুঁজুন" />
      </div>
      <ul className="max-h-[260px] overflow-y-auto space-y-1.5 pr-1" role="listbox" aria-label="উক্তি">
        {note && <li className="text-[12.5px] text-neutral-500 px-1 py-2">{note}</li>}
        {items.map((q) => (
          <li key={q.id}>
            <button
              type="button"
              role="option"
              aria-selected={q.id === pickedId}
              onClick={() => onPick(q)}
              className={`w-full text-left rounded-xl border px-2.5 py-2 ${q.id === pickedId ? 'border-brand-500 bg-brand-50' : 'border-neutral-200 hover:bg-neutral-50'}`}
            >
              <span className="block text-[13px] text-neutral-800 line-clamp-2">“{q.text}”</span>
              <small className="block text-[11.5px] text-neutral-500 mt-0.5">{[q.speaker, q.type_label].filter(Boolean).join(' · ')}</small>
            </button>
          </li>
        ))}
      </ul>
    </div>
  )
}
