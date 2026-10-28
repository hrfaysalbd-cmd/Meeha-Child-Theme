import { useMemo, useRef, useState } from 'react'
import {
  buildCategoryTree,
  categoryPath,
  pathLabel,
  readRecentCategories,
  searchCategories,
  type CategoryLike,
  type CategoryNode,
} from '../lib/categoryTree'

// Round 18 — category picker for the Write screen (correspondent app) and
// the post editor (publisher app).
//
// Layout, top to bottom:
//   1. search box — finds a category at ANY level and shows its full path
//      ("খেলা › ক্রিকেট"), so a deep sub-category is one search away;
//   2. "সাম্প্রতিক" — the categories this person picked last (one tap);
//   3. main categories as chips, most-used first; the first few are shown
//      and "আরও …টি" reveals the rest in one tap;
//   4. picking a main category that has sub-categories opens a chip row of
//      its sub-categories under it, and a third row for a sub-sub level.
// The deepest picked level is the post's category; choosing a sub-category
// is optional (the main category alone is a valid choice).

const MAIN_VISIBLE = 6

const bn = (n: number) => String(n).replace(/[0-9]/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])

function Chip({
  active,
  muted,
  onClick,
  children,
  hasChildren,
}: {
  active?: boolean
  muted?: boolean
  onClick: () => void
  children: React.ReactNode
  hasChildren?: boolean
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={`inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-[13.5px] leading-tight transition active:scale-[0.97] ${
        active
          ? 'border-brand-600 bg-brand-600 text-white shadow-sm'
          : muted
            ? 'border-dashed border-neutral-300 bg-white text-neutral-500'
            : 'border-neutral-200 bg-white text-neutral-800 hover:border-brand-500/60'
      }`}
    >
      {children}
      {hasChildren && <span className={`text-[11px] ${active ? 'text-white/80' : 'text-neutral-400'}`}>›</span>}
    </button>
  )
}

export default function CategoryPicker<C extends CategoryLike>({
  categories,
  value,
  onChange,
  recentKey,
  loading,
  error,
  onRetry,
}: {
  categories: C[]
  value: number
  onChange: (id: number) => void
  recentKey: string
  loading?: boolean
  error?: boolean
  onRetry?: () => void
}) {
  const tree = useMemo(() => buildCategoryTree(categories), [categories])
  const [query, setQuery] = useState('')
  const [showAllMain, setShowAllMain] = useState(false)
  const [recent] = useState(() => readRecentCategories(recentKey))
  const searchRef = useRef<HTMLInputElement>(null)

  const path = value ? categoryPath(tree, value) : []
  const hits = useMemo(() => searchCategories(tree, query, 12), [tree, query])
  const recentCats = recent.map((id) => tree.byId.get(id)?.cat).filter((c): c is C => !!c).slice(0, 5)

  // Levels to render: main row, then a row for each picked category that has children.
  const levels: { parentName?: string; nodes: CategoryNode<C>[]; picked?: number }[] = [{ nodes: tree.roots, picked: path[0]?.id }]
  path.forEach((cat, i) => {
    const node = tree.byId.get(cat.id)
    if (node && node.children.length) levels.push({ parentName: cat.name, nodes: node.children, picked: path[i + 1]?.id })
  })

  const pick = (id: number) => {
    onChange(id)
    setQuery('')
  }

  if (loading) {
    return <div className="h-24 rounded-xl bg-neutral-100 animate-pulse" aria-label="ক্যাটেগরি লোড হচ্ছে" />
  }
  if (error) {
    return (
      <div className="rounded-xl border border-red-100 bg-red-50 px-3.5 py-2.5 text-[13px] text-red-700 flex items-center justify-between gap-2">
        ক্যাটেগরি লোড করা যায়নি।
        {onRetry && (
          <button type="button" onClick={onRetry} className="font-semibold underline">
            আবার চেষ্টা
          </button>
        )}
      </div>
    )
  }

  const mainNodes = levels[0].nodes
  const pickedMainIndex = mainNodes.findIndex((n) => n.cat.id === path[0]?.id)
  // Keep the picked main category visible even when it's beyond the first few.
  const visibleMain = showAllMain
    ? mainNodes
    : mainNodes.filter((_, i) => i < MAIN_VISIBLE || i === pickedMainIndex)
  const hiddenCount = mainNodes.length - visibleMain.length

  return (
    <div data-testid="category-picker" className="rounded-xl border border-neutral-200 bg-neutral-50/70 p-3">
      {/* Selected path */}
      <div className="flex items-start justify-between gap-2 mb-2.5">
        {path.length ? (
          <div className="flex flex-wrap items-center gap-1 text-[13.5px] min-w-0">
            <span className="text-neutral-500">নির্বাচিত:</span>
            {path.map((c, i) => (
              <span key={c.id} className="flex items-center gap-1">
                {i > 0 && <span className="text-neutral-400">›</span>}
                <button
                  type="button"
                  onClick={() => pick(c.id)}
                  className={`font-semibold ${i === path.length - 1 ? 'text-brand-700' : 'text-neutral-700 underline decoration-dotted'}`}
                >
                  {c.name}
                </button>
              </span>
            ))}
          </div>
        ) : (
          <span className="text-[13.5px] text-neutral-500">একটি ক্যাটেগরি বেছে নিন</span>
        )}
        {path.length > 0 && (
          <button type="button" onClick={() => pick(0)} className="shrink-0 text-[12.5px] text-neutral-500 underline">
            মুছুন
          </button>
        )}
      </div>

      {/* Search */}
      <div className="relative mb-3">
        <input
          ref={searchRef}
          type="search"
          inputMode="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              if (hits[0]) pick(hits[0].cat.id)
            }
          }}
          placeholder="ক্যাটেগরি খুঁজুন…"
          className="w-full rounded-lg border border-neutral-200 bg-white pl-9 pr-3 py-2 text-[14.5px] focus:outline-none focus:ring-2 focus:ring-brand-600/30 focus:border-brand-500"
        />
        <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <circle cx="11" cy="11" r="7" />
          <path d="m20 20-3.5-3.5" />
        </svg>
      </div>

      {query.trim() ? (
        <div className="rounded-lg border border-neutral-200 bg-white divide-y divide-neutral-100 max-h-72 overflow-y-auto">
          {hits.length === 0 ? (
            <p className="px-3 py-3 text-[13.5px] text-neutral-500">“{query.trim()}” নামে কোনো ক্যাটেগরি নেই।</p>
          ) : (
            hits.map((h) => (
              <button
                type="button"
                key={h.cat.id}
                onClick={() => pick(h.cat.id)}
                className={`w-full text-left px-3 py-2.5 active:bg-neutral-50 ${h.cat.id === value ? 'bg-brand-50' : ''}`}
              >
                <span className="block text-[14.5px] font-medium text-neutral-900">{h.cat.name}</span>
                {h.path.length > 1 && <span className="block text-[12px] text-neutral-500">{pathLabel(h.path.slice(0, -1))}</span>}
              </button>
            ))
          )}
        </div>
      ) : (
        <>
          {recentCats.length > 0 && (
            <div className="mb-3">
              <p className="text-[12px] font-medium text-neutral-500 mb-1.5">সাম্প্রতিক</p>
              <div className="flex flex-wrap gap-1.5">
                {recentCats.map((c) => (
                  <Chip key={c.id} active={c.id === value} onClick={() => pick(c.id)}>
                    {pathLabel(categoryPath(tree, c.id))}
                  </Chip>
                ))}
              </div>
            </div>
          )}

          {levels.map((level, li) => {
            const nodes = li === 0 ? visibleMain : level.nodes
            return (
              <div key={li} className={li > 0 ? 'mt-3 pl-3 border-l-2 border-brand-100' : ''}>
                <p className="text-[12px] font-medium text-neutral-500 mb-1.5">
                  {li === 0 ? 'মূল ক্যাটেগরি' : `${level.parentName} — উপ-ক্যাটেগরি (ঐচ্ছিক)`}
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {nodes.map((n) => (
                    <Chip
                      key={n.cat.id}
                      active={n.cat.id === level.picked}
                      hasChildren={n.children.length > 0}
                      onClick={() => pick(n.cat.id === level.picked && li > 0 ? path[li - 1].id : n.cat.id)}
                    >
                      {n.cat.name}
                    </Chip>
                  ))}
                  {li === 0 && hiddenCount > 0 && (
                    <Chip muted onClick={() => setShowAllMain(true)}>
                      আরও {bn(hiddenCount)}টি ▾
                    </Chip>
                  )}
                  {li === 0 && showAllMain && mainNodes.length > MAIN_VISIBLE && (
                    <Chip muted onClick={() => setShowAllMain(false)}>
                      কম দেখান ▴
                    </Chip>
                  )}
                </div>
              </div>
            )
          })}
        </>
      )}
    </div>
  )
}
