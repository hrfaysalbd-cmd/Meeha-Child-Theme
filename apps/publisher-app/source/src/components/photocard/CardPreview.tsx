/**
 * Round 24 — the live card. Drag the photo to move it inside its frame,
 * wheel or pinch to zoom, arrow keys to nudge; the dotted line marks the
 * 60 px edge the social apps may cover.
 */
import { useEffect, useRef, useState } from 'react'

export default function CardPreview({
  card,
  busy,
  error,
  interactive,
  zoom,
  onMove,
  onZoom,
  meta,
  story,
}: {
  card: HTMLCanvasElement | null
  busy: boolean
  error: string
  interactive: boolean
  zoom: number
  /** dx/dy in percent of the photo (positive = photo moves right/down). */
  onMove: (dx: number, dy: number, done: boolean) => void
  onZoom: (z: number) => void
  meta: string
  story: boolean
}) {
  const view = useRef<HTMLCanvasElement>(null)
  const [guide, setGuide] = useState(true)
  const drag = useRef<{ x: number; y: number; id: number } | null>(null)
  const pinch = useRef<{ d: number; z: number } | null>(null)
  const pointers = useRef(new Map<number, { x: number; y: number }>())
  const zoomRef = useRef(zoom)
  useEffect(() => {
    zoomRef.current = zoom
  }, [zoom])

  useEffect(() => {
    const v = view.current
    if (!v || !card) return
    v.width = card.width
    v.height = card.height
    v.getContext('2d')!.drawImage(card, 0, 0)
  }, [card])

  // Wheel zoom (passive: false so the page does not scroll under the card).
  useEffect(() => {
    const v = view.current
    if (!v) return
    const onWheel = (e: WheelEvent) => {
      if (!interactive) return
      e.preventDefault()
      const z = Math.max(1, Math.min(2.5, zoomRef.current * (e.deltaY < 0 ? 1.06 : 1 / 1.06)))
      onZoom(Math.round(z * 100) / 100)
    }
    v.addEventListener('wheel', onWheel, { passive: false })
    return () => v.removeEventListener('wheel', onWheel)
  }, [interactive, onZoom])

  function down(e: React.PointerEvent<HTMLCanvasElement>) {
    if (!interactive) return
    e.currentTarget.setPointerCapture(e.pointerId)
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    if (pointers.current.size === 2) {
      const [a, b] = [...pointers.current.values()]
      pinch.current = { d: Math.hypot(a.x - b.x, a.y - b.y), z: zoomRef.current }
      drag.current = null
    } else {
      drag.current = { x: e.clientX, y: e.clientY, id: e.pointerId }
    }
  }
  function move(e: React.PointerEvent<HTMLCanvasElement>) {
    if (!pointers.current.has(e.pointerId)) return
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    if (pinch.current && pointers.current.size === 2) {
      const [a, b] = [...pointers.current.values()]
      const d = Math.hypot(a.x - b.x, a.y - b.y)
      onZoom(Math.round(Math.max(1, Math.min(2.5, (pinch.current.z * d) / pinch.current.d)) * 100) / 100)
      return
    }
    const g = drag.current
    if (!g || g.id !== e.pointerId) return
    const r = e.currentTarget.getBoundingClientRect()
    onMove(((e.clientX - g.x) / r.width) * 100, ((e.clientY - g.y) / r.height) * 100, false)
    drag.current = { ...g, x: e.clientX, y: e.clientY }
  }
  function up(e: React.PointerEvent<HTMLCanvasElement>) {
    pointers.current.delete(e.pointerId)
    if (pointers.current.size < 2) pinch.current = null
    if (drag.current?.id === e.pointerId) {
      drag.current = null
      onMove(0, 0, true)
    }
  }
  function key(e: React.KeyboardEvent<HTMLCanvasElement>) {
    if (!interactive) return
    const step = e.shiftKey ? 6 : 2
    const d: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }
    if (d[e.key]) {
      e.preventDefault()
      onMove(d[e.key][0], d[e.key][1], true)
    } else if (e.key === '+' || e.key === '=') {
      onZoom(Math.min(2.5, Math.round((zoomRef.current + 0.05) * 100) / 100))
    } else if (e.key === '-') {
      onZoom(Math.max(1, Math.round((zoomRef.current - 0.05) * 100) / 100))
    }
  }

  return (
    <div>
      <div className={`relative mx-auto ${story ? 'max-w-[300px]' : 'max-w-[560px]'}`}>
        <div className="relative rounded-xl overflow-hidden shadow-lg ring-1 ring-black/5 bg-neutral-200">
          <canvas
            ref={view}
            width={1080}
            height={1080}
            tabIndex={0}
            role="img"
            aria-label="কার্ডের প্রিভিউ — ছবি টেনে সরান, চাকা ঘুরিয়ে জুম"
            onPointerDown={down}
            onPointerMove={move}
            onPointerUp={up}
            onPointerCancel={up}
            onKeyDown={key}
            className={`block w-full h-auto touch-none focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-500/40 ${
              interactive ? 'cursor-grab active:cursor-grabbing' : ''
            }`}
          />
          {guide && card && !story && (
            <span
              className="pointer-events-none absolute border-2 border-dashed border-sky-400/70 rounded"
              style={{ inset: `${(60 / card.height) * 100}% ${(60 / card.width) * 100}%` }}
              aria-hidden="true"
            />
          )}
          {busy && (
            <span className="absolute right-2 top-2 bg-white/90 rounded-full p-1.5 shadow" aria-label="তৈরি হচ্ছে">
              <span className="block h-4 w-4 rounded-full border-2 border-brand-600 border-t-transparent animate-spin" />
            </span>
          )}
          {!card && !error && (
            <span className="absolute inset-0 flex items-center justify-center">
              <span className="block h-7 w-7 rounded-full border-2 border-brand-600 border-t-transparent animate-spin" />
            </span>
          )}
        </div>
        {error && <p className="text-[12.5px] text-red-600 mt-2">{error}</p>}
      </div>
      <div className="flex items-center justify-center gap-3 mt-2 text-[11.5px] text-neutral-500">
        <span>{meta}</span>
        {!story && (
          <label className="flex items-center gap-1 cursor-pointer select-none">
            <input type="checkbox" checked={guide} onChange={(e) => setGuide(e.target.checked)} /> নিরাপদ এলাকা
          </label>
        )}
      </div>
    </div>
  )
}
