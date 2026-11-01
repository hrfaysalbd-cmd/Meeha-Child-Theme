/**
 * Round 24/25 — the live card.
 *
 * Drag a photo to move it inside its frame (in a collage, each tile on its
 * own), wheel or pinch to zoom (0.4× shows the whole photo on a blurred
 * fill, up to 3×), arrow keys to nudge. Layers (text, arrows, marks …) are
 * picked and dragged right on the card; the selected one shows its handles
 * — the corner dot resizes, an arrow's two dots move its ends. The renderer
 * leaves the hit regions on the canvas (canvas.__regions), so the preview
 * knows exactly what is under the pointer in every design and size.
 */
import { useEffect, useRef, useState } from 'react'
import type { CardCanvas, CardRegion } from '../../lib/photocard/cards'
import type { Layer } from '../../lib/photocard/style'

export const ZMIN = 0.4
export const ZMAX = 3
const clampZ = (z: number) => Math.round(Math.max(ZMIN, Math.min(ZMAX, z)) * 100) / 100

type Phase = 'start' | 'move' | 'end'
type Drag =
  | { m: 'tile'; i: number; x: number; y: number; id: number; w: number; h: number }
  | { m: 'layer'; lid: string; orig: Layer; px: number; py: number; id: number; moved: boolean }
  | { m: 'end'; lid: string; which: 1 | 2; orig: Layer; id: number }
  | { m: 'size'; lid: string; orig: Layer; px: number; py: number; id: number }

export default function CardPreview({
  card,
  busy,
  error,
  interactive,
  zooms,
  onTile,
  onTileZoom,
  layers = [],
  selLayer = null,
  onLayerSelect,
  onLayerEdit,
  onLayerRemove,
  meta,
  story,
}: {
  card: CardCanvas | null
  busy: boolean
  error: string
  /** The main photo is there to move. */
  interactive: boolean
  /** Zoom of each photo the card shows, by its index (0 = the main photo, 1… = collage tiles). */
  zooms: number[]
  /** dx/dy in percent of the photo's frame (positive = photo moves right/down). */
  onTile: (i: number, dx: number, dy: number, done: boolean) => void
  onTileZoom: (i: number, z: number) => void
  layers?: Layer[]
  selLayer?: string | null
  onLayerSelect?: (id: string | null) => void
  onLayerEdit?: (id: string, patch: Partial<Layer>, phase: Phase) => void
  onLayerRemove?: (id: string) => void
  meta: string
  story: boolean
}) {
  const view = useRef<HTMLCanvasElement>(null)
  const [guide, setGuide] = useState(true)
  const drag = useRef<Drag | null>(null)
  const pinch = useRef<{ d: number; z: number; i: number } | null>(null)
  const pointers = useRef(new Map<number, { x: number; y: number }>())
  const zoomsRef = useRef(zooms)
  zoomsRef.current = zooms
  const layersRef = useRef(layers)
  layersRef.current = layers
  const cardRef = useRef(card)
  cardRef.current = card

  useEffect(() => {
    const v = view.current
    if (!v || !card) return
    v.width = card.width
    v.height = card.height
    v.getContext('2d')!.drawImage(card, 0, 0)
  }, [card])

  const regions = (): CardRegion[] => cardRef.current?.__regions || []
  const content = () => {
    const c = cardRef.current
    return c?.__content || (c ? { x: 0, y: 0, w: c.width, h: c.height } : { x: 0, y: 0, w: 1080, h: 1080 })
  }
  /** Pointer → canvas pixels. */
  const toCanvas = (clientX: number, clientY: number) => {
    const v = view.current!
    const r = v.getBoundingClientRect()
    const c = cardRef.current
    const W = c?.width || v.width
    const H = c?.height || v.height
    return { x: ((clientX - r.left) / r.width) * W, y: ((clientY - r.top) / r.height) * H, k: W / r.width }
  }
  /** Canvas pixels → % of the card's content area (where layers live). */
  const toPct = (x: number, y: number) => {
    const C = content()
    return { x: ((x - C.x) / C.w) * 100, y: ((y - C.y) / C.h) * 100 }
  }
  const fromPct = (x: number, y: number) => {
    const C = content()
    return { x: C.x + (x / 100) * C.w, y: C.y + (y / 100) * C.h }
  }
  const hit = (x: number, y: number) => {
    const rs = regions()
    for (let j = rs.length - 1; j >= 0; j--) {
      const r = rs[j]
      if (r && x >= r.x && x <= r.x + r.w && y >= r.y && y <= r.y + r.h) return r
    }
    return null
  }
  const tileAt = (x: number, y: number) => {
    const rs = regions()
    for (let j = rs.length - 1; j >= 0; j--) {
      const r = rs[j]
      if (r && r.k === 'photo' && x >= r.x && x <= r.x + r.w && y >= r.y && y <= r.y + r.h) return r
    }
    return null
  }
  const layerRegion = (id: string | null) => {
    if (!id) return null
    const i = layersRef.current.findIndex((l) => l.id === id)
    if (i < 0) return null
    const rs = regions().filter((r) => r.k === 'layer' && r.i === i)
    return rs.length ? rs[rs.length - 1] : null
  }

  // Wheel zoom (passive: false so the page does not scroll under the card).
  useEffect(() => {
    const v = view.current
    if (!v) return
    const onWheel = (e: WheelEvent) => {
      const p = toCanvas(e.clientX, e.clientY)
      const t = tileAt(p.x, p.y)
      const i = t ? t.i : interactive ? 0 : -1
      if (i < 0) return
      e.preventDefault()
      const z = zoomsRef.current[i] ?? 1
      onTileZoom(i, clampZ(z * (e.deltaY < 0 ? 1.06 : 1 / 1.06)))
    }
    v.addEventListener('wheel', onWheel, { passive: false })
    return () => v.removeEventListener('wheel', onWheel)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [interactive, onTileZoom])

  function down(e: React.PointerEvent<HTMLCanvasElement>) {
    const p = toCanvas(e.clientX, e.clientY)
    e.currentTarget.setPointerCapture(e.pointerId)
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    if (pointers.current.size === 2) {
      const [a, b] = [...pointers.current.values()]
      const t = drag.current?.m === 'tile' ? drag.current.i : 0
      if (drag.current?.m === 'tile') onTile(drag.current.i, 0, 0, true)
      pinch.current = { d: Math.hypot(a.x - b.x, a.y - b.y), z: zoomsRef.current[t] ?? 1, i: t }
      drag.current = null
      return
    }
    // 1) the selected layer's handles
    const sel = layersRef.current.find((l) => l.id === selLayer)
    const grab = 18 * p.k
    if (sel && onLayerEdit) {
      if (sel.t === 'arrow') {
        const a = fromPct(sel.x, sel.y)
        const b = fromPct(sel.x2 ?? sel.x + 15, sel.y2 ?? sel.y)
        const which = Math.hypot(p.x - b.x, p.y - b.y) < grab ? 2 : Math.hypot(p.x - a.x, p.y - a.y) < grab ? 1 : 0
        if (which) {
          drag.current = { m: 'end', lid: sel.id, which, orig: sel, id: e.pointerId }
          onLayerEdit(sel.id, {}, 'start')
          return
        }
      } else {
        const r = layerRegion(sel.id)
        if (r && Math.hypot(p.x - (r.x + r.w), p.y - (r.y + r.h)) < grab) {
          const q = toPct(p.x, p.y)
          drag.current = { m: 'size', lid: sel.id, orig: sel, px: q.x, py: q.y, id: e.pointerId }
          onLayerEdit(sel.id, {}, 'start')
          return
        }
      }
    }
    // 2) whatever is under the pointer
    const r = hit(p.x, p.y)
    if (r?.k === 'layer' && onLayerEdit) {
      const l = layersRef.current[r.i]
      if (l) {
        onLayerSelect?.(l.id)
        const q = toPct(p.x, p.y)
        drag.current = { m: 'layer', lid: l.id, orig: l, px: q.x, py: q.y, id: e.pointerId, moved: false }
        onLayerEdit(l.id, {}, 'start')
        return
      }
    }
    if (selLayer) onLayerSelect?.(null)
    if (r?.k === 'photo') {
      drag.current = { m: 'tile', i: r.i, x: e.clientX, y: e.clientY, id: e.pointerId, w: r.w / p.k, h: r.h / p.k }
      return
    }
    if (interactive) {
      const v = e.currentTarget.getBoundingClientRect()
      drag.current = { m: 'tile', i: 0, x: e.clientX, y: e.clientY, id: e.pointerId, w: v.width, h: v.height }
    }
  }

  function move(e: React.PointerEvent<HTMLCanvasElement>) {
    if (!pointers.current.has(e.pointerId)) return
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    if (pinch.current && pointers.current.size === 2) {
      const [a, b] = [...pointers.current.values()]
      const d = Math.hypot(a.x - b.x, a.y - b.y)
      onTileZoom(pinch.current.i, clampZ((pinch.current.z * d) / pinch.current.d))
      return
    }
    const g = drag.current
    if (!g || g.id !== e.pointerId) return
    if (g.m === 'tile') {
      onTile(g.i, ((e.clientX - g.x) / g.w) * 100, ((e.clientY - g.y) / g.h) * 100, false)
      drag.current = { ...g, x: e.clientX, y: e.clientY }
      return
    }
    if (!onLayerEdit) return
    const c = toCanvas(e.clientX, e.clientY)
    const q = toPct(c.x, c.y)
    const cl = (v: number) => Math.round(Math.max(-10, Math.min(110, v)) * 10) / 10
    if (g.m === 'layer') {
      const dx = q.x - g.px
      const dy = q.y - g.py
      const o = g.orig
      const patch: Partial<Layer> = { x: cl(o.x + dx), y: cl(o.y + dy) }
      if (o.t === 'arrow') {
        patch.x2 = cl((o.x2 ?? o.x + 15) + dx)
        patch.y2 = cl((o.y2 ?? o.y) + dy)
      }
      g.moved = true
      onLayerEdit(g.lid, patch, 'move')
    } else if (g.m === 'end') {
      onLayerEdit(g.lid, g.which === 1 ? { x: cl(q.x), y: cl(q.y) } : { x2: cl(q.x), y2: cl(q.y) }, 'move')
    } else if (g.m === 'size') {
      const o = g.orig
      if (o.t === 'shape' || o.t === 'blur') {
        onLayerEdit(g.lid, { w: Math.max(3, Math.min(100, Math.round(Math.abs(q.x - o.x) * 2))), h: Math.max(3, Math.min(100, Math.round(Math.abs(q.y - o.y) * 2))) }, 'move')
      } else {
        const C = content()
        const cx = o.x
        const cy = o.y
        const d0 = Math.hypot(((g.px - cx) / 100) * C.w, ((g.py - cy) / 100) * C.h) || 1
        const d1 = Math.hypot(((q.x - cx) / 100) * C.w, ((q.y - cy) / 100) * C.h)
        const base = o.size || (o.t === 'text' ? 46 : o.t === 'mark' ? 80 : o.t === 'sticker' ? 40 : 28)
        const lim: Record<string, [number, number]> = { text: [16, 140], mark: [24, 220], sticker: [16, 100], inset: [10, 70] }
        const [lo, hi] = lim[o.t] || [10, 200]
        onLayerEdit(g.lid, { size: Math.round(Math.max(lo, Math.min(hi, (base * d1) / d0))) }, 'move')
      }
    }
  }

  function up(e: React.PointerEvent<HTMLCanvasElement>) {
    pointers.current.delete(e.pointerId)
    if (pointers.current.size < 2) pinch.current = null
    const g = drag.current
    if (g?.id === e.pointerId) {
      drag.current = null
      if (g.m === 'tile') onTile(g.i, 0, 0, true)
      else onLayerEdit?.(g.lid, {}, 'end')
    }
  }

  function key(e: React.KeyboardEvent<HTMLCanvasElement>) {
    const step = e.shiftKey ? 6 : 2
    const d: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }
    const sel = layers.find((l) => l.id === selLayer)
    if (sel && onLayerEdit) {
      if (d[e.key]) {
        e.preventDefault()
        const [dx, dy] = [d[e.key][0] / 2, d[e.key][1] / 2]
        const patch: Partial<Layer> = { x: sel.x + dx, y: sel.y + dy }
        if (sel.t === 'arrow') {
          patch.x2 = (sel.x2 ?? sel.x + 15) + dx
          patch.y2 = (sel.y2 ?? sel.y) + dy
        }
        onLayerEdit(sel.id, patch, 'start')
        onLayerEdit(sel.id, {}, 'end')
        return
      }
      if ((e.key === 'Delete' || e.key === 'Backspace') && onLayerRemove) {
        e.preventDefault()
        onLayerRemove(sel.id)
        return
      }
      if (e.key === 'Escape') {
        onLayerSelect?.(null)
        return
      }
    }
    if (!interactive) return
    const z = zoomsRef.current[0] ?? 1
    if (d[e.key]) {
      e.preventDefault()
      onTile(0, d[e.key][0], d[e.key][1], true)
    } else if (e.key === '+' || e.key === '=') {
      onTileZoom(0, clampZ(z + 0.05))
    } else if (e.key === '-') {
      onTileZoom(0, clampZ(z - 0.05))
    }
  }

  // selection overlay (percent of the card, so it follows the preview's size)
  const sel = layers.find((l) => l.id === selLayer)
  const selR = sel ? layerRegion(sel.id) : null
  const W = card?.width || 1080
  const H = card?.height || 1080
  const pct = (x: number, y: number) => ({ left: `${(x / W) * 100}%`, top: `${(y / H) * 100}%` })
  let overlay: React.ReactNode = null
  if (sel && card) {
    if (sel.t === 'arrow') {
      const a = fromPct(sel.x, sel.y)
      const b = fromPct(sel.x2 ?? sel.x + 15, sel.y2 ?? sel.y)
      overlay = (
        <>
          <Dot style={pct(a.x, a.y)} label="তীরের লেজ" />
          <Dot style={pct(b.x, b.y)} label="তীরের মাথা" />
        </>
      )
    } else if (selR) {
      overlay = (
        <>
          <span className="pointer-events-none absolute border-2 border-sky-400 rounded-sm shadow-[0_0_0_1px_rgba(255,255,255,.8)]" style={{ ...pct(selR.x, selR.y), width: `${(selR.w / W) * 100}%`, height: `${(selR.h / H) * 100}%` }} aria-hidden="true" />
          <Dot style={pct(selR.x + selR.w, selR.y + selR.h)} label="মাপ বদলান" />
        </>
      )
    }
  }
  const anyRegions = !!card?.__regions?.length
  const canGrab = interactive || anyRegions

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
            aria-label="কার্ডের প্রিভিউ — ছবি বা লেয়ার টেনে সরান, চাকা ঘুরিয়ে জুম"
            onPointerDown={down}
            onPointerMove={move}
            onPointerUp={up}
            onPointerCancel={up}
            onKeyDown={key}
            className={`block w-full h-auto touch-none focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-500/40 ${canGrab ? 'cursor-grab active:cursor-grabbing' : ''}`}
          />
          {guide && card && !story && (
            <span className="pointer-events-none absolute border-2 border-dashed border-sky-400/70 rounded" style={{ inset: `${(60 / card.height) * 100}% ${(60 / card.width) * 100}%` }} aria-hidden="true" />
          )}
          {overlay}
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

function Dot({ style, label }: { style: React.CSSProperties; label: string }) {
  return <span className="pointer-events-none absolute w-4 h-4 -ml-2 -mt-2 rounded-full bg-white border-[3px] border-sky-500 shadow" style={style} aria-label={label} />
}
