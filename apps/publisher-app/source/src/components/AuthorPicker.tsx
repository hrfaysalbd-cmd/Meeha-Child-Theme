/**
 * Round 10 — searchable author combobox (GET /admin/authors?search=,
 * debounced). Used by PostEditor and VideoEditor's "প্রকাশের তথ্য" card.
 */
import { useEffect, useId, useRef, useState } from 'react'
import { AuthorsApi, describeError, type AuthorOption } from '../lib/api'
import { roleLabel } from '../lib/postMeta'
import { IconCheck, IconSearch, IconSelector } from './Icon'

export interface AuthorValue {
  id: number
  name: string
  avatar?: string
  role?: string
}

const SEARCH_DEBOUNCE_MS = 300

function Avatar({ src, name, size = 28 }: { src?: string; name: string; size?: number }) {
  const [broken, setBroken] = useState(false)
  const style = { width: size, height: size }
  if (src && !broken) {
    return <img src={src} alt="" style={style} onError={() => setBroken(true)} className="rounded-full object-cover bg-neutral-100 shrink-0" />
  }
  return (
    <span
      style={style}
      className="rounded-full bg-brand-50 text-brand-700 text-[12px] font-semibold flex items-center justify-center shrink-0"
      aria-hidden
    >
      {name.trim().charAt(0) || '?'}
    </span>
  )
}

export default function AuthorPicker({
  value,
  onChange,
  disabled,
}: {
  value: AuthorValue | null
  onChange: (author: AuthorOption) => void
  disabled?: boolean
}) {
  const listId = useId()
  const wrapRef = useRef<HTMLDivElement>(null)
  const searchRef = useRef<HTMLInputElement>(null)
  const seq = useRef(0)

  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [options, setOptions] = useState<AuthorOption[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [active, setActive] = useState(0)

  // Debounced search while open (the first, empty-query load runs at once).
  useEffect(() => {
    if (!open) return
    const mySeq = ++seq.current
    const timer = setTimeout(
      () => {
        setLoading(true)
        setError('')
        AuthorsApi.search(query)
          .then((rows) => {
            if (mySeq !== seq.current) return
            setOptions(rows)
            setActive(0)
          })
          .catch((err) => {
            if (mySeq !== seq.current) return
            setOptions([])
            setError(describeError(err).message)
          })
          .finally(() => {
            if (mySeq === seq.current) setLoading(false)
          })
      },
      query ? SEARCH_DEBOUNCE_MS : 0,
    )
    return () => clearTimeout(timer)
  }, [open, query])

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDown)
    return () => document.removeEventListener('mousedown', onDown)
  }, [open])

  function openPicker() {
    if (disabled) return
    setQuery('')
    setOpen(true)
    setTimeout(() => searchRef.current?.focus(), 0)
  }

  function choose(a: AuthorOption) {
    onChange(a)
    setOpen(false)
  }

  function onKeyDown(e: React.KeyboardEvent) {
    if (e.key === 'Escape') {
      e.preventDefault()
      setOpen(false)
    } else if (e.key === 'ArrowDown') {
      e.preventDefault()
      setActive((i) => Math.min(options.length - 1, i + 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setActive((i) => Math.max(0, i - 1))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      if (options[active]) choose(options[active])
    }
  }

  return (
    <div ref={wrapRef} className="relative">
      <button
        type="button"
        onClick={() => (open ? setOpen(false) : openPicker())}
        disabled={disabled}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label="লেখক নির্বাচন"
        data-testid="author-picker"
        className="w-full flex items-center gap-2.5 rounded-lg border border-neutral-200 bg-white px-2.5 py-1.5 text-left hover:border-neutral-300 focus:outline-none focus:ring-2 focus:ring-brand-600/20 focus:border-brand-500 disabled:bg-neutral-50 disabled:cursor-not-allowed disabled:hover:border-neutral-200"
      >
        {value ? (
          <>
            <Avatar src={value.avatar} name={value.name} />
            <span className="min-w-0 flex-1">
              <span className={`block text-[13.5px] font-medium truncate ${disabled ? 'text-neutral-500' : 'text-neutral-900'}`}>{value.name}</span>
              {value.role && <span className="block text-[11px] text-neutral-400 leading-tight">{roleLabel(value.role)}</span>}
            </span>
          </>
        ) : (
          <span className="flex-1 text-[13.5px] text-neutral-400 py-1">লেখক বেছে নিন</span>
        )}
        <IconSelector className="w-4 h-4 text-neutral-400 shrink-0" />
      </button>

      {open && (
        <div className="absolute z-40 left-0 right-0 mt-1.5 rounded-xl border border-neutral-200 bg-white shadow-lg overflow-hidden">
          <div className="flex items-center gap-2 px-3 border-b border-neutral-100">
            <IconSearch className="w-4 h-4 text-neutral-400 shrink-0" />
            <input
              ref={searchRef}
              role="combobox"
              aria-controls={listId}
              aria-expanded
              aria-autocomplete="list"
              aria-activedescendant={options[active] ? `${listId}-${options[active].id}` : undefined}
              className="w-full py-2.5 text-[13.5px] focus:outline-none placeholder:text-neutral-400"
              placeholder="নাম, ইমেইল বা ইউজারনেম দিয়ে খুঁজুন"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={onKeyDown}
            />
          </div>
          <ul id={listId} role="listbox" className="max-h-64 overflow-y-auto py-1">
            {loading && options.length === 0 ? (
              <li className="px-3 py-4 text-center text-[12.5px] text-neutral-400">খোঁজা হচ্ছে…</li>
            ) : error ? (
              <li className="px-3 py-4 text-center text-[12.5px] text-red-600">{error}</li>
            ) : options.length === 0 ? (
              <li className="px-3 py-4 text-center text-[12.5px] text-neutral-400">কাউকে পাওয়া যায়নি।</li>
            ) : (
              options.map((a, i) => {
                const selected = value?.id === a.id
                return (
                  <li
                    key={a.id}
                    id={`${listId}-${a.id}`}
                    role="option"
                    aria-selected={selected}
                    onMouseEnter={() => setActive(i)}
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={() => choose(a)}
                    className={`flex items-center gap-2.5 px-3 py-2 cursor-pointer ${i === active ? 'bg-neutral-50' : ''}`}
                  >
                    <Avatar src={a.avatar} name={a.name} />
                    <span className="min-w-0 flex-1">
                      <span className="block text-[13px] font-medium text-neutral-900 truncate">{a.name}</span>
                      <span className="block text-[11px] text-neutral-400 leading-tight">{roleLabel(a.role)}</span>
                    </span>
                    {selected && <IconCheck className="w-4 h-4 text-brand-600 shrink-0" />}
                  </li>
                )
              })
            )}
          </ul>
          {loading && options.length > 0 && <div className="h-0.5 bg-brand-500/60 animate-pulse" />}
        </div>
      )}
    </div>
  )
}
