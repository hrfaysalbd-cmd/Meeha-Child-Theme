/**
 * Round 24 — "খবর বাছুন": the latest published posts (search, paging) or
 * any post by its ID / link. Picking one fills the card's text and brings
 * in the post's photos.
 */
import { useEffect, useRef, useState } from 'react'
import { PhotocardApi, describeError, type CardRecent } from '../../lib/api'
import { bnNumber, inputClass } from '../ui'
import { IconChevronRight, IconRefresh, IconSearch } from '../Icon'

export default function PostPicker({
  pickedId,
  onPick,
  busy,
}: {
  pickedId: number | null
  onPick: (post: string | number, suggest?: string) => void
  busy: boolean
}) {
  const [items, setItems] = useState<CardRecent[]>([])
  const [offset, setOffset] = useState(0)
  const [per, setPer] = useState(5)
  const [more, setMore] = useState(false)
  const [query, setQuery] = useState('')
  const [search, setSearch] = useState('')
  const [note, setNote] = useState('আনা হচ্ছে…')
  const [direct, setDirect] = useState('')
  const seq = useRef(0)

  async function load(off: number, term = search) {
    const mine = ++seq.current
    try {
      const r = await PhotocardApi.recent(off, term)
      if (mine !== seq.current) return
      setItems(r.items || [])
      setPer(r.per || 5)
      setMore(!!r.more)
      setOffset(off)
      setNote(r.items?.length ? '' : term ? 'এই শব্দে কোনো প্রকাশিত পোস্ট পাওয়া যায়নি।' : 'এখনো কোনো প্রকাশিত পোস্ট নেই।')
    } catch (e) {
      if (mine !== seq.current) return
      setItems([])
      setNote(`পোস্টের তালিকা আনা যায়নি: ${describeError(e).message}`)
    }
  }

  useEffect(() => {
    load(0)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    const t = setTimeout(() => {
      if (query.trim() !== search) {
        setSearch(query.trim())
        load(0, query.trim())
      }
    }, 350)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query])

  return (
    <div>
      <div className="flex gap-2 mb-2">
        <div className="relative flex-1">
          <IconSearch className="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-neutral-400" />
          <input
            className={`${inputClass} pl-8`}
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="শিরোনামে খুঁজুন…"
            aria-label="পোস্ট খুঁজুন"
          />
        </div>
        <button
          type="button"
          onClick={() => load(0)}
          className="px-2.5 rounded-lg bg-neutral-100 text-neutral-600 hover:bg-neutral-200"
          title="তালিকা নতুন করে আনুন"
          aria-label="তালিকা নতুন করে আনুন"
        >
          <IconRefresh className="w-4 h-4" />
        </button>
      </div>
      <ul className="space-y-1.5" aria-label="সাম্প্রতিক পোস্ট">
        {note && <li className="text-[12.5px] text-neutral-500 px-1 py-2">{note}</li>}
        {items.map((p) => {
          const on = p.id === pickedId
          return (
            <li key={p.id}>
              <button
                type="button"
                disabled={busy}
                onClick={() => onPick(p.id, p.type)}
                aria-pressed={on}
                className={`w-full flex items-center gap-2.5 text-left rounded-xl border px-2 py-1.5 transition ${
                  on ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/20' : 'border-neutral-200 hover:border-neutral-300 hover:bg-neutral-50'
                }`}
              >
                {p.thumb ? (
                  <img src={p.thumb} alt="" loading="lazy" className="w-12 h-12 rounded-lg object-cover shrink-0 bg-neutral-100" />
                ) : (
                  <span className="w-12 h-12 rounded-lg bg-neutral-100 shrink-0" />
                )}
                <span className="min-w-0">
                  <b className="block text-[13px] font-semibold text-neutral-800 leading-snug line-clamp-2">{p.title}</b>
                  <small className="block text-[11.5px] text-neutral-500 truncate">
                    {[`আইডি ${bnNumber(p.id)}`, p.category, p.date].filter(Boolean).join(' · ')}
                  </small>
                </span>
              </button>
            </li>
          )
        })}
      </ul>
      <div className="flex items-center justify-between mt-2">
        <button
          type="button"
          disabled={offset <= 0}
          onClick={() => load(Math.max(0, offset - per))}
          className="text-[12.5px] font-medium text-brand-600 disabled:text-neutral-300 flex items-center gap-1"
        >
          <IconChevronRight className="w-4 h-4 rotate-180" /> নতুনগুলো
        </button>
        <button
          type="button"
          disabled={!more}
          onClick={() => load(offset + per)}
          className="text-[12.5px] font-medium text-brand-600 disabled:text-neutral-300 flex items-center gap-1"
        >
          পুরোনোগুলো <IconChevronRight className="w-4 h-4" />
        </button>
      </div>
      <form
        className="flex gap-2 mt-3 pt-3 border-t border-neutral-100"
        onSubmit={(e) => {
          e.preventDefault()
          if (direct.trim()) onPick(direct.trim())
        }}
      >
        <input
          className={inputClass}
          value={direct}
          onChange={(e) => setDirect(e.target.value)}
          inputMode="url"
          placeholder="পোস্ট আইডি বা লিংক, যেমন ১১৮৭"
          aria-label="পোস্ট আইডি বা লিংক"
        />
        <button type="submit" disabled={busy || !direct.trim()} className="px-3 rounded-lg bg-brand-600 text-white text-[13px] font-semibold disabled:opacity-50 whitespace-nowrap">
          আনুন
        </button>
      </form>
    </div>
  )
}
