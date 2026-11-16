/**
 * Round 46 — the studio's professional asset browser (frames, intros,
 * outros, name plates, stingers): search, category chips, "সাম্প্রতিক
 * ব্যবহৃত" and favourites (both kept in localStorage), and a grid of
 * preview cards that come alive on hover (slow push-in + light sweep).
 */
import { useMemo, useState, type ReactNode } from 'react'
import { bnNumber } from '../ui'

export interface AssetItem {
  id: string
  name: string
  hint?: string
  group?: string
  isNew?: boolean
}

const KEY = 'meeha-studio:assets'

function readStore(): Record<string, { fav?: string[]; recent?: string[] }> {
  try {
    return JSON.parse(localStorage.getItem(KEY) || '{}') || {}
  } catch {
    return {}
  }
}
function writeStore(v: Record<string, { fav?: string[]; recent?: string[] }>) {
  try {
    localStorage.setItem(KEY, JSON.stringify(v))
  } catch {
    // private mode: favourites just don't persist
  }
}

/** Favourites and recently used ids for one kind of asset (shared with the sound library). */
export function useAssetMemory(kind: string) {
  const [store, setStore] = useState(readStore)
  const mine = store[kind] ?? {}
  const update = (patch: { fav?: string[]; recent?: string[] }) => {
    const next = { ...readStore() }
    next[kind] = { ...(next[kind] ?? {}), ...patch }
    writeStore(next)
    setStore(next)
  }
  const fav = mine.fav ?? []
  const recent = mine.recent ?? []
  return {
    fav,
    recent,
    isFav: (id: string) => fav.includes(id),
    toggleFav: (id: string) => update({ fav: fav.includes(id) ? fav.filter((x) => x !== id) : [id, ...fav].slice(0, 60) }),
    used: (id: string) => update({ recent: [id, ...recent.filter((x) => x !== id)].slice(0, 8) }),
  }
}

const norm = (s: string) => s.toLowerCase().trim()

export function StarButton({ on, onClick, label }: { on: boolean; onClick: () => void; label: string }) {
  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation()
        onClick()
      }}
      aria-pressed={on}
      aria-label={on ? `${label} প্রিয় থেকে সরান` : `${label} প্রিয়তে রাখুন`}
      title={on ? 'প্রিয় থেকে সরান' : 'প্রিয়তে রাখুন'}
      className={`shrink-0 w-7 h-7 rounded-full flex items-center justify-center ${on ? 'text-amber-500' : 'text-neutral-300 hover:text-amber-400'}`}
    >
      <svg viewBox="0 0 24 24" className="w-4 h-4" fill={on ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth={2} aria-hidden="true">
        <path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z" strokeLinejoin="round" />
      </svg>
    </button>
  )
}

export function SearchBox({ value, onChange, placeholder, testId }: { value: string; onChange: (v: string) => void; placeholder: string; testId?: string }) {
  return (
    <div className="relative">
      <svg viewBox="0 0 24 24" className="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-neutral-400" fill="none" stroke="currentColor" strokeWidth={2} aria-hidden="true">
        <circle cx="11" cy="11" r="6.5" />
        <path d="M16 16l4 4" strokeLinecap="round" />
      </svg>
      <input
        type="search"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        data-testid={testId}
        className="w-full rounded-lg border border-neutral-200 bg-white pl-8 pr-3 py-1.5 text-[12.5px] focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-400"
      />
    </div>
  )
}

export function Chip({ on, onClick, children, testId }: { on: boolean; onClick: () => void; children: ReactNode; testId?: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={on}
      data-testid={testId}
      className={`shrink-0 rounded-full px-2.5 py-1 text-[11.5px] font-medium border ${on ? 'bg-brand-600 border-brand-600 text-white' : 'bg-white border-neutral-200 text-neutral-600 hover:border-neutral-300'}`}
    >
      {children}
    </button>
  )
}

const SHINE_CSS = `@keyframes meehaAssetShine{0%{transform:translateX(-120%) skewX(-18deg)}100%{transform:translateX(220%) skewX(-18deg)}}
.meeha-asset:hover .meeha-asset-pic{transform:scale(1.07)}
.meeha-asset:hover .meeha-asset-shine{animation:meehaAssetShine 1.1s ease-in-out infinite}`

/**
 * Grid browser. `none` adds a "নেই" card first. The chosen item's group is
 * the default chip, so the current choice is always visible.
 */
export default function AssetBrowser<T extends AssetItem>({
  kind,
  items,
  groups,
  current,
  onPick,
  preview,
  none,
  searchPlaceholder = 'নাম বা ধরন দিয়ে খুঁজুন…',
  cols = 2,
}: {
  kind: string
  items: T[]
  groups?: { id: string; name: string }[]
  current: string
  onPick: (id: T['id']) => void
  preview?: (item: T) => ReactNode
  none?: { label: string; hint?: string }
  searchPlaceholder?: string
  cols?: 2 | 3
}) {
  const mem = useAssetMemory(kind)
  const [q, setQ] = useState('')
  const usedGroups = (groups ?? []).filter((g) => items.some((i) => i.group === g.id))
  const [cat, setCat] = useState<string>('all')
  const list = useMemo(() => {
    const qq = norm(q)
    let l = items
    if (cat === 'fav') l = mem.fav.map((id) => items.find((i) => i.id === id)).filter((x): x is T => !!x)
    else if (cat === 'recent') l = mem.recent.map((id) => items.find((i) => i.id === id)).filter((x): x is T => !!x)
    else if (cat === 'new') l = items.filter((i) => i.isNew)
    else if (cat !== 'all') l = items.filter((i) => i.group === cat)
    if (qq) l = l.filter((i) => norm(`${i.name} ${i.hint ?? ''} ${i.id}`).includes(qq))
    return l
  }, [items, q, cat, mem.fav, mem.recent])
  const recentItems = mem.recent.map((id) => items.find((i) => i.id === id)).filter((x): x is T => !!x)
  const pick = (id: T['id']) => {
    mem.used(id)
    onPick(id)
  }
  const hasNew = items.some((i) => i.isNew)

  return (
    <div data-testid={`assets-${kind}`}>
      <style>{SHINE_CSS}</style>
      <div className="space-y-2 mb-2.5">
        <SearchBox value={q} onChange={setQ} placeholder={searchPlaceholder} testId={`assets-${kind}-search`} />
        <div className="flex gap-1.5 overflow-x-auto pb-0.5 -mx-0.5 px-0.5">
          <Chip on={cat === 'all'} onClick={() => setCat('all')}>
            সব ({bnNumber(items.length)})
          </Chip>
          {hasNew && (
            <Chip on={cat === 'new'} onClick={() => setCat('new')} testId={`assets-${kind}-new`}>
              নতুন
            </Chip>
          )}
          <Chip on={cat === 'fav'} onClick={() => setCat('fav')} testId={`assets-${kind}-fav`}>
            ★ প্রিয় {mem.fav.length ? `(${bnNumber(mem.fav.filter((id) => items.some((i) => i.id === id)).length)})` : ''}
          </Chip>
          <Chip on={cat === 'recent'} onClick={() => setCat('recent')}>
            সাম্প্রতিক
          </Chip>
          {usedGroups.map((g) => (
            <Chip key={g.id} on={cat === g.id} onClick={() => setCat(g.id)} testId={`assets-${kind}-cat-${g.id}`}>
              {g.name}
            </Chip>
          ))}
        </div>
        {cat === 'all' && !q && recentItems.length > 0 && (
          <div className="flex items-center gap-1.5 overflow-x-auto">
            <span className="shrink-0 text-[11px] text-neutral-400">সাম্প্রতিক ব্যবহৃত:</span>
            {recentItems.slice(0, 5).map((i) => (
              <button
                key={i.id}
                type="button"
                onClick={() => pick(i.id)}
                className={`shrink-0 rounded-md px-2 py-0.5 text-[11px] border ${current === i.id ? 'border-brand-500 text-brand-700 bg-brand-50' : 'border-neutral-200 text-neutral-600 hover:border-neutral-300'}`}
              >
                {i.name}
              </button>
            ))}
          </div>
        )}
      </div>
      <div className={`grid ${cols === 3 ? 'grid-cols-3' : 'grid-cols-2'} gap-2.5`}>
        {none && cat === 'all' && !q && (
          <button
            type="button"
            onClick={() => onPick('none' as T['id'])}
            aria-pressed={current === 'none'}
            data-testid={`${kind}-none`}
            className={`text-left rounded-xl border px-2.5 py-2 flex flex-col justify-center ${current === 'none' ? 'border-brand-500 ring-2 ring-brand-500/25' : 'border-neutral-200 hover:border-neutral-300'}`}
          >
            <span className={`text-[12.5px] font-semibold ${current === 'none' ? 'text-brand-700' : 'text-neutral-800'}`}>{none.label}</span>
            {none.hint && <span className="text-[11px] text-neutral-400">{none.hint}</span>}
          </button>
        )}
        {list.map((o) => {
          const active = current === o.id
          return (
            <div
              key={o.id}
              role="button"
              tabIndex={0}
              onClick={() => pick(o.id)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault()
                  pick(o.id)
                }
              }}
              aria-pressed={active}
              data-testid={`${kind}-${o.id}`}
              className={`meeha-asset cursor-pointer text-left rounded-xl border overflow-hidden transition ${active ? 'border-brand-500 ring-2 ring-brand-500/25' : 'border-neutral-200 hover:border-neutral-300 hover:shadow-md'}`}
            >
              {preview && (
                <div className="relative overflow-hidden">
                  <div className="meeha-asset-pic transition-transform duration-[1400ms] ease-out">{preview(o)}</div>
                  <div className="meeha-asset-shine pointer-events-none absolute inset-y-0 left-0 w-1/3 bg-gradient-to-r from-transparent via-white/35 to-transparent" style={{ transform: 'translateX(-120%)' }} />
                </div>
              )}
              <div className="flex items-start gap-1 px-2.5 py-2">
                <div className="min-w-0 flex-1">
                  <span className={`flex items-center gap-1.5 text-[12.5px] font-semibold ${active ? 'text-brand-700' : 'text-neutral-800'}`}>
                    <span className="min-w-0 truncate">{o.name}</span>
                    {o.isNew && <span className="shrink-0 text-[10px] font-bold text-amber-800 bg-amber-100 rounded px-1 py-px">নতুন</span>}
                  </span>
                  {o.hint && <span className="block text-[11px] text-neutral-400 leading-snug mt-0.5">{o.hint}</span>}
                </div>
                <StarButton on={mem.isFav(o.id)} onClick={() => mem.toggleFav(o.id)} label={o.name} />
              </div>
            </div>
          )
        })}
      </div>
      {list.length === 0 && (
        <p className="text-[12px] text-neutral-500 text-center py-4">
          {cat === 'fav' ? 'এখনও কিছু প্রিয়তে রাখেননি — কার্ডের ☆ চাপুন।' : cat === 'recent' ? 'এখনও কিছু ব্যবহার করেননি।' : 'কিছু পাওয়া যায়নি।'}
        </p>
      )}
    </div>
  )
}
