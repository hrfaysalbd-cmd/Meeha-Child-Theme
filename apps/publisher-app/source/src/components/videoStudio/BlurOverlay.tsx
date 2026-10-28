/**
 * Round 21.1 — the selected blur / mosaic box drawn over the preview canvas:
 * drag it to move, drag the corner to resize. Box coordinates are fractions
 * of the picture as shown (see mainPictureRect), so they follow zoom and
 * rotation.
 */
import { useEffect, useRef, useState } from 'react'
import { mainPictureRect } from '../../lib/videoEdit/draw'
import type { EditConfig } from '../../lib/videoEdit/config'
import type { Layout } from '../../lib/videoEdit/timeline'
import type { Setter } from './StudioPanels'

type Drag = { mode: 'move' | 'size'; px: number; py: number; x: number; y: number; w: number; h: number; dw: number; dh: number }

const clamp = (v: number, a: number, b: number) => Math.min(b, Math.max(a, v))

export default function BlurOverlay({
  canvas,
  cfg,
  L,
  picW,
  picH,
  index,
  set,
}: {
  canvas: HTMLCanvasElement | null
  cfg: EditConfig
  L: Layout
  picW: number
  picH: number
  index: number
  set: Setter
}) {
  const [box, setBox] = useState<{ x: number; y: number; w: number } | null>(null)
  const drag = useRef<Drag | null>(null)

  useEffect(() => {
    if (!canvas) return
    const update = () => setBox({ x: canvas.offsetLeft, y: canvas.offsetTop, w: canvas.offsetWidth })
    update()
    const ro = new ResizeObserver(update)
    ro.observe(canvas)
    window.addEventListener('resize', update)
    return () => {
      ro.disconnect()
      window.removeEventListener('resize', update)
    }
  }, [canvas])

  const b = cfg.blurs[index]
  if (!b || !box || !box.w) return null
  const k = box.w / L.W
  const d = mainPictureRect(cfg, picW, picH, L.video)
  const style = {
    left: box.x + (d.x + b.x * d.w) * k,
    top: box.y + (d.y + b.y * d.h) * k,
    width: b.w * d.w * k,
    height: b.h * d.h * k,
  }

  const start = (mode: Drag['mode']) => (e: React.PointerEvent) => {
    e.stopPropagation()
    e.preventDefault()
    ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
    drag.current = { mode, px: e.clientX, py: e.clientY, x: b.x, y: b.y, w: b.w, h: b.h, dw: d.w * k, dh: d.h * k }
  }
  const move = (e: React.PointerEvent) => {
    const g = drag.current
    if (!g) return
    e.stopPropagation()
    const dx = (e.clientX - g.px) / g.dw
    const dy = (e.clientY - g.py) / g.dh
    set((c) => ({
      ...c,
      blurs: c.blurs.map((x, j) => {
        if (j !== index) return x
        if (g.mode === 'move') return { ...x, x: clamp(g.x + dx, 0, 1 - g.w), y: clamp(g.y + dy, 0, 1 - g.h) }
        return { ...x, w: clamp(g.w + dx, 0.03, 1 - g.x), h: clamp(g.h + dy, 0.03, 1 - g.y) }
      }),
    }))
  }
  const end = (e: React.PointerEvent) => {
    e.stopPropagation()
    drag.current = null
  }

  return (
    <div
      className="absolute border-2 border-white rounded-md shadow-[0_0_0_1px_rgba(0,0,0,0.5)] cursor-move touch-none"
      style={style}
      onPointerDown={start('move')}
      onPointerMove={move}
      onPointerUp={end}
      onClick={(e) => e.stopPropagation()}
      data-testid="blur-box"
    >
      <span className="absolute -top-6 left-0 text-[11px] font-semibold text-white bg-black/60 rounded px-1.5 py-0.5 whitespace-nowrap pointer-events-none">
        {b.mode === 'pixel' ? 'মোজাইক' : 'ঝাপসা'}
      </span>
      <span
        className="absolute -right-2 -bottom-2 w-4 h-4 rounded-sm bg-white border border-neutral-500 cursor-nwse-resize"
        onPointerDown={start('size')}
        onPointerMove={move}
        onPointerUp={end}
        data-testid="blur-resize"
      />
    </div>
  )
}
