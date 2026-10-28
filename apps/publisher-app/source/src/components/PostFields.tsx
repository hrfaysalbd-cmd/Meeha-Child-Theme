/**
 * Round 12 — the theme's "মিহা সম্পাদকীয়" / "সাহিত্য তথ্য" fields in the
 * post editor: collapsible sections, tag chips with autocomplete, the
 * division → district → upazila cascade, editorial flags + একনজরে বাক্য,
 * and the literature fields rendered from GET /admin/lit-schema.
 */
import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react'
import {
  EditorialApi,
  type AreasResponse,
  type LitSchema,
  type PostArea,
  type PostFlags,
  type TagOption,
  type LibraryImage,
} from '../lib/api'
import { inputClass, bnNumber } from './ui'
import { IconChevronDown, IconLibraryPhoto, IconX } from './Icon'
import MediaGallery from './MediaGallery'

export const TAG_MAX = 15
export const TAG_LEN_MAX = 40
export const GLANCE_MAX = 90
export const TOPIC_MAX = 80

const cpLen = (s: string) => [...s].length

// ---------------------------------------------------------------------------

export function Section({
  title,
  summary,
  defaultOpen = true,
  children,
  testId,
  badge,
}: {
  title: string
  summary?: ReactNode
  defaultOpen?: boolean
  children: ReactNode
  testId?: string
  badge?: ReactNode
}) {
  const [open, setOpen] = useState(defaultOpen)
  const id = useId()
  return (
    <section className="rounded-2xl bg-white border border-neutral-200/80 shadow-sm" data-testid={testId} data-open={open ? '1' : '0'}>
      <button
        type="button"
        aria-expanded={open}
        aria-controls={id}
        onClick={() => setOpen((v) => !v)}
        className="w-full flex items-center gap-2 px-5 py-3.5 min-h-[52px] text-left rounded-2xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#025AB2]"
      >
        <span className="flex-1 min-w-0">
          <span className="flex items-center gap-2 text-[13.5px] font-semibold text-neutral-800">
            {title}
            {badge}
          </span>
          {!open && summary && <span className="block text-[12px] text-neutral-500 truncate mt-0.5">{summary}</span>}
        </span>
        <IconChevronDown className={`w-5 h-5 text-neutral-400 shrink-0 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div id={id} className="px-5 pb-5 -mt-1">
          {children}
        </div>
      )}
    </section>
  )
}

function Label({ htmlFor, children, right }: { htmlFor?: string; children: ReactNode; right?: ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-2 mb-1.5">
      <label htmlFor={htmlFor} className="text-[12.5px] font-medium text-neutral-600">
        {children}
      </label>
      {right}
    </div>
  )
}

export function Counter({ value, max, testId }: { value: string; max: number; testId?: string }) {
  const n = cpLen(value)
  return (
    <span data-testid={testId} className={`text-[11.5px] tabular-nums ${n > max ? 'text-red-600 font-semibold' : n > max * 0.85 ? 'text-amber-600' : 'text-neutral-400'}`}>
      {bnNumber(n)}/{bnNumber(max)}
    </span>
  )
}

// ---------------------------------------------------------------------------
// Tags

export function TagInput({ value, onChange }: { value: string[]; onChange: (v: string[]) => void }) {
  const [text, setText] = useState('')
  const [options, setOptions] = useState<TagOption[]>([])
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(-1)
  const [msg, setMsg] = useState('')
  const inputRef = useRef<HTMLInputElement>(null)
  const listId = useId()
  const seq = useRef(0)

  useEffect(() => {
    const q = text.trim()
    if (!q) {
      setOptions([])
      return
    }
    const my = ++seq.current
    const t = setTimeout(() => {
      EditorialApi.searchTags(q)
        .then((rows) => {
          if (my !== seq.current) return
          setOptions((Array.isArray(rows) ? rows : []).filter((r) => !value.includes(r.name)).slice(0, 8))
          setActive(-1)
        })
        .catch(() => my === seq.current && setOptions([]))
    }, 220)
    return () => clearTimeout(t)
  }, [text, value])

  function add(raw: string) {
    const name = raw.replace(/\s+/g, ' ').replace(/^[,،\s]+|[,،\s]+$/g, '').trim()
    setMsg('')
    if (!name) return
    if (cpLen(name) > TAG_LEN_MAX) {
      setMsg(`প্রতিটি ট্যাগ সর্বোচ্চ ${bnNumber(TAG_LEN_MAX)} অক্ষরের হতে পারে।`)
      return
    }
    if (value.some((v) => v.toLowerCase() === name.toLowerCase())) {
      setText('')
      return
    }
    if (value.length >= TAG_MAX) {
      setMsg(`সর্বোচ্চ ${bnNumber(TAG_MAX)}টি ট্যাগ দেওয়া যায়।`)
      return
    }
    onChange([...value, name])
    setText('')
    setOptions([])
    setActive(-1)
  }

  return (
    <div>
      <Label htmlFor="tag-input" right={<span className="text-[11.5px] text-neutral-400">{bnNumber(value.length)}/{bnNumber(TAG_MAX)}</span>}>
        ট্যাগ
      </Label>
      <div
        className="flex flex-wrap items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-2 py-1.5 min-h-[44px] focus-within:ring-2 focus-within:ring-brand-600/20 focus-within:border-brand-500"
        onClick={() => inputRef.current?.focus()}
        data-testid="tag-box"
      >
        {value.map((t) => (
          <span key={t} className="inline-flex items-center gap-1 rounded-full bg-[#EEF4FB] text-[#01468C] text-[12.5px] pl-2.5 pr-1 py-0.5" data-testid="tag-chip">
            {t}
            <button
              type="button"
              aria-label={`${t} ট্যাগ সরান`}
              onClick={(e) => {
                e.stopPropagation()
                onChange(value.filter((x) => x !== t))
              }}
              className="w-7 h-7 -my-1 rounded-full flex items-center justify-center hover:bg-[#D6E6F7]"
            >
              <IconX className="w-3.5 h-3.5" />
            </button>
          </span>
        ))}
        <div className="relative flex-1 min-w-[120px]">
          <input
            id="tag-input"
            ref={inputRef}
            value={text}
            role="combobox"
            aria-expanded={open && options.length > 0}
            aria-controls={listId}
            aria-autocomplete="list"
            enterKeyHint="enter"
            autoComplete="off"
            placeholder={value.length ? 'আরও ট্যাগ…' : 'ট্যাগ লিখে Enter চাপুন'}
            className="w-full bg-transparent text-[13.5px] py-1 px-1 outline-none"
            onChange={(e) => {
              const v = e.target.value
              if (/[,،]/.test(v)) {
                v.split(/[,،]/).slice(0, -1).forEach(add)
                setText(v.split(/[,،]/).pop() ?? '')
              } else setText(v)
              setOpen(true)
            }}
            onFocus={() => setOpen(true)}
            onBlur={() => setTimeout(() => setOpen(false), 150)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault()
                if (active >= 0 && options[active]) add(options[active].name)
                else add(text)
              } else if (e.key === 'ArrowDown' && options.length) {
                e.preventDefault()
                setActive((a) => Math.min(options.length - 1, a + 1))
              } else if (e.key === 'ArrowUp' && options.length) {
                e.preventDefault()
                setActive((a) => Math.max(-1, a - 1))
              } else if (e.key === 'Backspace' && !text && value.length) {
                onChange(value.slice(0, -1))
              } else if (e.key === 'Escape') {
                setOpen(false)
              }
            }}
          />
          {open && options.length > 0 && (
            <ul
              id={listId}
              role="listbox"
              data-testid="tag-options"
              className="absolute left-0 right-0 top-full mt-1 z-30 max-h-60 overflow-y-auto rounded-xl border border-neutral-200 bg-white shadow-lg py-1 min-w-[200px]"
            >
              {options.map((o, i) => (
                <li key={o.id} role="option" aria-selected={i === active}>
                  <button
                    type="button"
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={() => add(o.name)}
                    className={`w-full flex items-center justify-between gap-2 px-3 py-2.5 text-left text-[13.5px] ${i === active ? 'bg-[#EEF4FB]' : 'hover:bg-neutral-50'}`}
                  >
                    <span>{o.name}</span>
                    <span className="text-[11px] text-neutral-400">{bnNumber(o.count)}টি পোস্ট</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
      {msg ? (
        <p className="text-[11.5px] text-red-600 mt-1">{msg}</p>
      ) : (
        <p className="text-[11.5px] text-neutral-400 mt-1">কমা বা Enter দিয়ে আলাদা করুন। নতুন ট্যাগ সংরক্ষণের সময় তৈরি হবে।</p>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Area

export function AreaPicker({ value, onChange }: { value: PostArea; onChange: (v: PostArea) => void }) {
  const [areas, setAreas] = useState<AreasResponse | null>(null)
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    EditorialApi.areas()
      .then(setAreas)
      .catch(() => setFailed(true))
  }, [])
  const districts = areas?.divisions.find((d) => d.name === value.division)?.districts ?? []
  const upazilas = areas?.upazilas[value.district] ?? []
  // A saved value the list doesn't know (older data) is still shown.
  const withCurrent = (list: string[], cur: string) => (cur && !list.includes(cur) ? [cur, ...list] : list)

  return (
    <div className="grid gap-3" data-testid="area-picker">
      <div>
        <Label htmlFor="area-division">বিভাগ</Label>
        <select
          id="area-division"
          className={`${inputClass} min-h-[44px]`}
          value={value.division}
          onChange={(e) => onChange({ division: e.target.value, district: '', upazila: '' })}
        >
          <option value="">— বিভাগ —</option>
          {withCurrent(areas?.divisions.map((d) => d.name) ?? [], value.division).map((d) => (
            <option key={d} value={d}>
              {d}
            </option>
          ))}
        </select>
      </div>
      <div>
        <Label htmlFor="area-district">জেলা</Label>
        <select
          id="area-district"
          className={`${inputClass} min-h-[44px]`}
          value={value.district}
          disabled={!value.division}
          onChange={(e) => onChange({ ...value, district: e.target.value, upazila: '' })}
        >
          <option value="">{value.division ? '— জেলা —' : 'আগে বিভাগ বেছে নিন'}</option>
          {withCurrent(districts, value.district).map((d) => (
            <option key={d} value={d}>
              {d}
            </option>
          ))}
        </select>
      </div>
      <div>
        <Label htmlFor="area-upazila">উপজেলা</Label>
        <select
          id="area-upazila"
          className={`${inputClass} min-h-[44px]`}
          value={value.upazila}
          disabled={!value.district}
          onChange={(e) => onChange({ ...value, upazila: e.target.value })}
        >
          <option value="">{value.district ? '— উপজেলা —' : 'আগে জেলা বেছে নিন'}</option>
          {withCurrent(upazilas, value.upazila).map((d) => (
            <option key={d} value={d}>
              {d}
            </option>
          ))}
        </select>
      </div>
      {failed && <p className="text-[11.5px] text-amber-700">এলাকার তালিকা লোড করা যায়নি।</p>}
      <p className="text-[11.5px] text-neutral-400 -mt-1">এলাকার খবরের জন্য বিভাগ, জেলা ও উপজেলা দিন।</p>
    </div>
  )
}

export function areaSummary(a: PostArea) {
  return [a.division, a.district, a.upazila].filter(Boolean).join(' › ') || 'নির্বাচিত নয়'
}

// ---------------------------------------------------------------------------
// Editorial flags

const FLAG_DEFS: { key: keyof PostFlags; label: string; hint: string }[] = [
  { key: 'breaking', label: 'ব্রেকিং', hint: 'ব্রেকিং নিউজ টিকারে দেখাবে' },
  { key: 'trending', label: 'ট্রেন্ডিং পিন', hint: '২৪ ঘণ্টা ট্রেন্ডিং তালিকার শীর্ষে থাকবে' },
  { key: 'investigation', label: 'অনুসন্ধান', hint: 'অনুসন্ধানী প্রতিবেদন হিসেবে চিহ্নিত' },
  { key: 'glance', label: 'একনজরে', hint: 'হোমপেজের “একনজরে” তালিকায় দেখাবে' },
]

export function Switch({ checked, onChange, label, testId }: { checked: boolean; onChange: (v: boolean) => void; label: string; testId?: string }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      data-testid={testId}
      onClick={() => onChange(!checked)}
      className={`relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#025AB2] ${checked ? 'bg-[#025AB2]' : 'bg-neutral-300'}`}
    >
      <span className={`inline-block h-5 w-5 rounded-full bg-white shadow transition ${checked ? 'translate-x-6' : 'translate-x-1'}`} />
    </button>
  )
}

export function EditorialFlags({
  flags,
  onChange,
  glanceText,
  onGlanceText,
  title,
  trendingAt,
}: {
  flags: PostFlags
  onChange: (f: PostFlags) => void
  glanceText: string
  onGlanceText: (v: string) => void
  title: string
  trendingAt?: number | null
}) {
  const [now] = useState(() => Date.now())
  const pinEnds = trendingAt ? trendingAt * 1000 + 24 * 3600 * 1000 : 0
  return (
    <div className="flex flex-col gap-1" data-testid="editorial-flags">
      {FLAG_DEFS.map((f) => (
        <div key={f.key}>
          <div className="flex items-center gap-3 py-2">
            <div className="flex-1 min-w-0">
              <p className="text-[13.5px] font-medium text-neutral-800">{f.label}</p>
              <p className="text-[11.5px] text-neutral-400">{f.hint}</p>
            </div>
            <Switch checked={flags[f.key]} onChange={(v) => onChange({ ...flags, [f.key]: v })} label={f.label} testId={`flag-${f.key}`} />
          </div>
          {f.key === 'trending' && flags.trending && pinEnds > 0 && (
            <p className={`text-[11.5px] -mt-1 pb-1 ${pinEnds < now ? 'text-amber-700' : 'text-neutral-500'}`} data-testid="trending-expiry">
              {pinEnds < now
                ? 'পিনের ২৪ ঘণ্টা শেষ — আবার পিন করতে বন্ধ করে চালু করুন ও সংরক্ষণ করুন।'
                : `পিন থাকবে: ${new Date(pinEnds).toLocaleString('bn-BD', { timeZone: 'Asia/Dhaka', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', hourCycle: 'h23' })} পর্যন্ত`}
            </p>
          )}
          {f.key === 'glance' && flags.glance && (
            <div className="pb-1">
              <Label htmlFor="glance-text" right={<Counter value={glanceText} max={GLANCE_MAX} testId="glance-count" />}>
                একনজরে বাক্য
              </Label>
              <input
                id="glance-text"
                className={`${inputClass} min-h-[44px]`}
                value={glanceText}
                maxLength={GLANCE_MAX}
                onChange={(e) => onGlanceText(e.target.value)}
                placeholder={title.trim() || 'খালি থাকলে শিরোনাম দেখাবে'}
              />
              <p className="text-[11.5px] text-neutral-400 mt-1">খালি রাখলে শিরোনামটিই দেখাবে।</p>
            </div>
          )}
        </div>
      ))}
    </div>
  )
}

export function flagsSummary(f: PostFlags) {
  const on = FLAG_DEFS.filter((d) => f[d.key]).map((d) => d.label)
  return on.length ? on.join(', ') : 'কোনোটি চালু নেই'
}

// ---------------------------------------------------------------------------
// Literature

/** Genre implied by a category id (when the schema maps genres to categories). */
export function genreForCategory(schema: LitSchema | null, categoryId: number, categoryName: string) {
  if (!schema) return ''
  const byId = schema.genres.find((g) => (g.category_id && g.category_id === categoryId) || g.category_ids?.includes(categoryId))
  if (byId) return byId.id
  const byName = schema.genres.find((g) => g.label === categoryName)
  return byName?.id ?? ''
}

const LIT_ROOT_NAMES = ['বাঙালি সাহিত্য-সংস্কৃতি', 'সাহিত্যলোক', 'লোকসংস্কৃতি', 'ইতিহাস-ঐতিহ্য']

export function isLiteratureCategory(schema: LitSchema | null, categoryId: number, categoryName: string) {
  if (!categoryId) return false
  if (schema?.category_ids?.includes(categoryId)) return true
  if (genreForCategory(schema, categoryId, categoryName)) return true
  return LIT_ROOT_NAMES.includes(categoryName)
}

export function LiteratureFields({
  schema,
  lit,
  onChange,
  autoGenre,
  coverUrl,
}: {
  schema: LitSchema
  lit: Record<string, string>
  onChange: (v: Record<string, string>) => void
  autoGenre: string
  coverUrl?: string | null
}) {
  const [galleryFor, setGalleryFor] = useState<string | null>(null)
  const [thumbs, setThumbs] = useState<Record<string, string>>(() => (coverUrl ? { cover: coverUrl } : ({} as Record<string, string>)))
  const genre = lit.genre || autoGenre
  const fields = useMemo(
    () => schema.fields.filter((f) => f.key !== 'genre' && (f.genres.length === 0 || (genre && f.genres.includes(genre)))),
    [schema, genre],
  )
  const set = (k: string, v: string) => onChange({ ...lit, [k]: v })
  const autoLabel = schema.genres.find((g) => g.id === autoGenre)?.label

  return (
    <div className="grid gap-3" data-testid="lit-fields">
      <div>
        <Label htmlFor="lit-genre">ধরন</Label>
        <select id="lit-genre" className={`${inputClass} min-h-[44px]`} value={lit.genre ?? ''} onChange={(e) => set('genre', e.target.value)}>
          <option value="">স্বয়ংক্রিয় (বিভাগ থেকে){autoLabel ? ` — এখন: ${autoLabel}` : ''}</option>
          {schema.genres.map((g) => (
            <option key={g.id} value={g.id}>
              {g.label}
            </option>
          ))}
        </select>
      </div>
      {!genre && <p className="text-[12px] text-neutral-500">ধরন বেছে নিলে সেই ধরনের তথ্যের ঘর দেখাবে।</p>}
      {fields.map((f) => {
        const id = `lit-${f.key}`
        const v = lit[f.key] ?? ''
        if (f.type === 'checkbox') {
          return (
            <label key={f.key} className="flex items-center gap-3 min-h-[44px] cursor-pointer">
              <input id={id} type="checkbox" className="w-5 h-5 accent-[#025AB2]" checked={v === '1'} onChange={(e) => set(f.key, e.target.checked ? '1' : '')} />
              <span className="text-[13.5px] text-neutral-700">{f.label}</span>
            </label>
          )
        }
        if (f.type === 'textarea') {
          return (
            <div key={f.key}>
              <Label htmlFor={id}>{f.label}</Label>
              <textarea id={id} rows={2} className={inputClass} value={v} onChange={(e) => set(f.key, e.target.value)} />
            </div>
          )
        }
        if (f.type === 'rating') {
          return (
            <div key={f.key}>
              <Label htmlFor={id}>{f.label}</Label>
              <select id={id} className={`${inputClass} min-h-[44px]`} value={v} onChange={(e) => set(f.key, e.target.value)}>
                <option value="">—</option>
                {[1, 2, 3, 4, 5].map((n) => (
                  <option key={n} value={String(n)}>
                    {'★'.repeat(n)} ({bnNumber(n)})
                  </option>
                ))}
              </select>
            </div>
          )
        }
        if (f.type === 'image') {
          return (
            <div key={f.key}>
              <Label>{f.label}</Label>
              <div className="flex items-center gap-2">
                {thumbs[f.key] ? (
                  <img src={thumbs[f.key]} alt="" className="w-12 h-16 object-cover rounded-md border border-neutral-200" />
                ) : v ? (
                  <span className="text-[12px] text-neutral-500">ছবি #{bnNumber(v)}</span>
                ) : null}
                <button
                  type="button"
                  onClick={() => setGalleryFor(f.key)}
                  className="flex items-center gap-1.5 rounded-lg bg-neutral-100 text-neutral-700 px-3 min-h-[40px] text-[12.5px] font-medium hover:bg-neutral-200"
                >
                  <IconLibraryPhoto className="w-4 h-4" />
                  {v ? 'বদলান' : 'গ্যালারি থেকে'}
                </button>
                {v && (
                  <button type="button" onClick={() => set(f.key, '')} className="text-[12px] text-red-600 px-2 min-h-[40px]">
                    সরান
                  </button>
                )}
              </div>
            </div>
          )
        }
        return (
          <div key={f.key}>
            <Label htmlFor={id}>{f.label}</Label>
            <input
              id={id}
              type={f.type === 'url' ? 'url' : 'text'}
              inputMode={f.type === 'number' ? 'numeric' : f.type === 'url' ? 'url' : undefined}
              dir={f.type === 'url' ? 'ltr' : undefined}
              className={`${inputClass} min-h-[44px]`}
              value={v}
              onChange={(e) => set(f.key, e.target.value)}
            />
          </div>
        )
      })}
      {galleryFor && (
        <MediaGallery
          title="প্রচ্ছদের ছবি — গ্যালারি থেকে বেছে নিন"
          onClose={() => setGalleryFor(null)}
          onSelect={(img: LibraryImage) => {
            set(galleryFor, String(img.id))
            setThumbs((t) => ({ ...t, [galleryFor]: img.thumb || img.url }))
            setGalleryFor(null)
          }}
        />
      )}
    </div>
  )
}
