/**
 * Round 24 — "নকশা": every design of the card type as a small live card
 * drawn with the card's own photo and words, so choosing is seeing. The
 * thumbnails are drawn one after another (never all at once) and only
 * redrawn when the photo, the words or the size changed.
 */
import { useEffect, useRef, useState } from 'react'
import { bnNumber } from '../ui'
import { IconChevronDown, IconPlus, IconTrash } from '../Icon'

export interface DesignRow {
  key: string
  label: string
  tag?: string
  canDelete?: boolean
  isNew?: boolean
  /** Round 25: the collapsible section it sits in (common is open by default). */
  group?: string
}

const ORDER = ['common', 'breaking', 'minimal', 'vibrant', 'collage', 'circle', 'readymade', 'classic', 'own']
const FALLBACK_LABELS: Record<string, string> = {
  common: 'সাধারণ', minimal: 'মিনিমাল', breaking: 'ব্রেকিং', vibrant: 'উজ্জ্বল ও ভাইব্রেন্ট', collage: 'কোলাজ',
  circle: 'গোল ছবি ও উক্তি', classic: 'আগের নকশা', readymade: 'রেডিমেড', own: 'নিজের ফ্রেম',
}
const HINTS: Record<string, string> = {
  common: 'বেশি ব্যবহৃত নীল নকশা',
  minimal: 'সাদা, পরিষ্কার, শুধু শিরোনাম',
  breaking: 'লাল ব্যাজ, টিভি টিকার, জরুরি',
  vibrant: 'উজ্জ্বল গ্রেডিয়েন্ট, পপ রঙ',
  collage: '২, ৩ বা ৪টি ছবি এক কার্ডে',
  circle: 'গোল ছবি, উদ্ধৃতি, পাশে ছবি',
  readymade: 'বাঁধানো ফ্রেমসহ নকশা',
  classic: 'আগের রাউন্ডের নকশা',
  own: 'আপলোড করা ফ্রেম',
}

let queue: Promise<unknown> = Promise.resolve()

function Thumb({ rowKey, version, draw, ratio }: { rowKey: string; version: string; draw: (key: string) => Promise<HTMLCanvasElement | null>; ratio: number }) {
  const ref = useRef<HTMLCanvasElement>(null)
  // The latest drawer, without making every parent render redraw the tile.
  const drawRef = useRef(draw)
  useEffect(() => {
    drawRef.current = draw
  }, [draw])
  useEffect(() => {
    let live = true
    const t = setTimeout(() => {
      queue = queue.then(async () => {
        const cv = ref.current
        if (!live || !cv || !cv.isConnected) return
        try {
          const c = await drawRef.current(rowKey)
          if (!live || !c || !ref.current) return
          const x = ref.current.getContext('2d')!
          x.clearRect(0, 0, ref.current.width, ref.current.height)
          x.drawImage(c, 0, 0, ref.current.width, ref.current.height)
        } catch {
          /* a design that cannot draw keeps its blank tile */
        }
      })
    }, 120)
    return () => {
      live = false
      clearTimeout(t)
    }
  }, [rowKey, version])
  const w = 168
  return <canvas ref={ref} width={w} height={Math.round(w * ratio)} className="w-full h-auto block bg-neutral-100" aria-hidden="true" />
}

export default function DesignGrid({
  rows,
  selected,
  onSelect,
  draw,
  version,
  ratio,
  canUpload,
  onUpload,
  onDelete,
  sizeLabel,
  help,
  groupLabels,
  scope,
}: {
  rows: DesignRow[]
  selected: string
  onSelect: (key: string) => void
  draw: (key: string) => Promise<HTMLCanvasElement | null>
  version: string
  ratio: number
  canUpload: boolean
  onUpload: (file: File) => void
  onDelete: (key: string) => void
  sizeLabel: string
  help: string
  groupLabels?: Record<string, string>
  /** Resets which sections are open (the card type). */
  scope: string
}) {
  const fileRef = useRef<HTMLInputElement>(null)
  const selGroup = rows.find((r) => r.key === selected)?.group || 'common'
  const [open, setOpen] = useState<Record<string, boolean>>({})
  const [openScope, setOpenScope] = useState('')
  // A new card type: only "সাধারণ" (and the section of the chosen design) open.
  if (openScope !== scope) {
    setOpenScope(scope)
    setOpen({ common: true, [selGroup]: true })
  }
  const groups = ORDER.filter((g) => rows.some((r) => (r.group || 'common') === g) || (g === 'own' && canUpload))
  const labels = { ...FALLBACK_LABELS, ...(groupLabels || {}) }
  const single = groups.length <= 1

  const tile = (r: DesignRow) => {
    const on = r.key === selected
    return (
      <li key={r.key} className="relative">
        <button
          type="button"
          role="radio"
          aria-checked={on}
          onClick={() => onSelect(r.key)}
          className={`w-full text-left rounded-xl overflow-hidden border-2 transition bg-white ${
            on ? 'border-brand-600 ring-2 ring-brand-600/25' : 'border-neutral-200 hover:border-neutral-300'
          }`}
        >
          <Thumb rowKey={r.key} version={version} draw={draw} ratio={ratio} />
          <span className="block px-1.5 py-1 text-[11.5px] leading-tight font-medium text-neutral-700 truncate">{r.label}</span>
        </button>
        {(r.tag || r.isNew) && (
          <span className={`absolute left-1 top-1 text-[9.5px] font-bold px-1.5 py-0.5 rounded ${r.isNew ? 'bg-amber-400 text-amber-950' : 'bg-neutral-900/70 text-white'}`}>
            {r.isNew ? 'নতুন' : r.tag}
          </span>
        )}
        {r.canDelete && (
          <button
            type="button"
            onClick={() => onDelete(r.key)}
            title="ফ্রেমটি মুছুন"
            aria-label={`ফ্রেম মুছুন: ${r.label}`}
            className="absolute right-1 top-1 w-6 h-6 rounded-full bg-white/95 text-red-600 shadow flex items-center justify-center"
          >
            <IconTrash className="w-3.5 h-3.5" />
          </button>
        )}
      </li>
    )
  }
  const uploadTile = canUpload && (
    <li>
      <button
        type="button"
        onClick={() => fileRef.current?.click()}
        className="w-full h-full min-h-[96px] rounded-xl border-2 border-dashed border-neutral-300 text-neutral-500 hover:border-brand-400 hover:text-brand-600 flex flex-col items-center justify-center gap-1 text-[11.5px] font-medium"
      >
        <IconPlus className="w-5 h-5" />
        নিজের ফ্রেম
      </button>
      <input
        ref={fileRef}
        type="file"
        accept="image/png"
        hidden
        onChange={(e) => {
          const f = e.target.files?.[0]
          if (f) onUpload(f)
          e.target.value = ''
        }}
      />
    </li>
  )
  const grid = 'grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-3 xl:grid-cols-4 gap-2'

  return (
    <div>
      <div className="space-y-2" role="radiogroup" aria-label="নকশা">
        {groups.map((g) => {
          const list = rows.filter((r) => (r.group || 'common') === g)
          const isOpen = single || !!open[g]
          const hasSel = list.some((r) => r.key === selected)
          return (
            <section key={g} className={`rounded-xl border ${isOpen ? 'border-neutral-200' : 'border-neutral-100'} bg-white`}>
              {!single && (
                <button
                  type="button"
                  aria-expanded={isOpen}
                  onClick={() => setOpen((o) => ({ ...o, [g]: !o[g] }))}
                  className="w-full flex items-center gap-2 px-3 py-2.5 text-left"
                >
                  <span className="text-[13.5px] font-semibold text-neutral-800">{labels[g] || g}</span>
                  <span className="text-[11px] text-neutral-400 truncate">{HINTS[g] || ''}</span>
                  {hasSel && !isOpen && <span className="text-[10.5px] font-semibold text-brand-700 bg-brand-50 rounded px-1.5 py-0.5">বাছাই এখানে</span>}
                  <span className="ml-auto text-[11.5px] text-neutral-500 bg-neutral-100 rounded-full px-2 py-0.5">{bnNumber(list.length)}</span>
                  <IconChevronDown className={`w-4 h-4 text-neutral-400 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
                </button>
              )}
              {isOpen && (
                <ul className={`${grid} ${single ? '' : 'px-2.5 pb-2.5'}`}>
                  {list.map(tile)}
                  {g === 'own' && uploadTile}
                </ul>
              )}
            </section>
          )
        })}
      </div>
      <p className="text-[11.5px] text-neutral-500 mt-2 leading-relaxed">
        {bnNumber(rows.length)}টি নকশা · {sizeLabel}। {help}
      </p>
    </div>
  )
}
