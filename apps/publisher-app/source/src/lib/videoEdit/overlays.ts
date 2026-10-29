/**
 * Round 23: the editor's newer overlays. These are markers drawn over the
 * picture (arrow, circle, box, highlight, spotlight, cross, tick), the
 * held-frame treatment, and the branded transitions between parts of the
 * video. Like draw.ts, they are pure functions of time, so the preview and
 * the export match.
 */
import type { EditConfig, Sticker, TransitionId } from './config'
import { BRAND, FONT_BODY, FONT_DISPLAY } from './assets'
import { clamp, ease, fillRoundRect, fitText, lerp, win, type Ctx } from './motion'
import { segSourceEnd, type Layout, type Rect, type Timeline } from './timeline'

const bodyFont = (w: number, px: number) => `${w} ${px}px ${FONT_BODY}`
const dispFont = (w: number, px: number) => `${w} ${px}px ${FONT_DISPLAY}`

// ---------------------------------------------------------------------------
// Stickers
// ---------------------------------------------------------------------------

/**
 * One marker. `d` is the picture's rect (the sticker's x/y/w/h are
 * fractions of it), `local` the seconds since it appeared and `dur` how long
 * it stays.
 */
export function drawSticker(ctx: Ctx, st: Sticker, d: Rect, clip: Rect, local: number, dur: number, s: number) {
  const x = d.x + st.x * d.w
  const y = d.y + st.y * d.h
  const w = st.w * d.w
  const h = st.h * d.h
  const cx = x + w / 2
  const cy = y + h / 2
  const appear = win(local, 0, 0.45)
  const leave = 1 - win(local, dur - 0.3, dur)
  const alpha = Math.min(1, appear * 3) * leave
  if (alpha <= 0) return
  const lw = Math.max(3, 9 * s)
  ctx.save()
  ctx.beginPath()
  ctx.rect(clip.x, clip.y, clip.w, clip.h)
  ctx.clip()
  ctx.globalAlpha = alpha
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.strokeStyle = st.color
  ctx.fillStyle = st.color
  ctx.shadowColor = 'rgba(0,0,0,0.45)'
  ctx.shadowBlur = 10 * s
  const draw = ease.outCubic(appear)

  switch (st.kind) {
    case 'circle': {
      // drawn like a pen stroke, then a gentle pulse
      const pulse = 1 + 0.03 * Math.sin(local * 5) * win(local, 0.5, 0.8)
      ctx.lineWidth = lw
      ctx.beginPath()
      ctx.ellipse(cx, cy, (w / 2) * pulse, (h / 2) * pulse, -0.08, -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * draw)
      ctx.stroke()
      break
    }
    case 'box': {
      ctx.lineWidth = lw
      const per = 2 * (w + h)
      ctx.setLineDash([per * draw, per])
      ctx.beginPath()
      ctx.roundRect(x, y, w, h, Math.min(w, h) * 0.08)
      ctx.stroke()
      ctx.setLineDash([])
      break
    }
    case 'highlight': {
      // a marker-pen sweep, left to right
      ctx.shadowBlur = 0
      ctx.globalAlpha = alpha * 0.42
      ctx.fillRect(x, y, w * draw, h)
      ctx.globalAlpha = alpha
      ctx.lineWidth = Math.max(2, 4 * s)
      ctx.strokeRect(x, y, w * draw, h)
      break
    }
    case 'focus': {
      // darken everything around the box
      ctx.shadowBlur = 0
      ctx.fillStyle = `rgba(0,0,0,${0.55 * draw})`
      ctx.beginPath()
      ctx.rect(clip.x, clip.y, clip.w, clip.h)
      ctx.ellipse(cx, cy, w / 2, h / 2, 0, 0, Math.PI * 2, true)
      ctx.fill('evenodd')
      ctx.lineWidth = Math.max(2, 4 * s)
      ctx.beginPath()
      ctx.ellipse(cx, cy, w / 2, h / 2, 0, 0, Math.PI * 2)
      ctx.stroke()
      break
    }
    case 'arrow': {
      // points at the box centre from outside, along `angle`, and bobs
      const ang = (st.angle * Math.PI) / 180
      const len = Math.max(w, h) * 0.9
      const bob = 10 * s * Math.sin(local * 6) * win(local, 0.45, 0.6)
      const tipX = cx - Math.cos(ang) * (Math.min(w, h) * 0.15 + bob)
      const tipY = cy - Math.sin(ang) * (Math.min(w, h) * 0.15 + bob)
      const tailX = tipX - Math.cos(ang) * len * draw
      const tailY = tipY - Math.sin(ang) * len * draw
      ctx.lineWidth = lw * 1.3
      ctx.beginPath()
      ctx.moveTo(tailX, tailY)
      ctx.lineTo(tipX - Math.cos(ang) * lw * 2, tipY - Math.sin(ang) * lw * 2)
      ctx.stroke()
      const head = Math.max(18 * s, len * 0.28)
      ctx.beginPath()
      ctx.moveTo(tipX, tipY)
      ctx.lineTo(tipX - Math.cos(ang - 0.5) * head, tipY - Math.sin(ang - 0.5) * head)
      ctx.lineTo(tipX - Math.cos(ang + 0.5) * head, tipY - Math.sin(ang + 0.5) * head)
      ctx.closePath()
      ctx.fill()
      break
    }
    case 'cross': {
      ctx.lineWidth = lw * 1.4
      const a = draw * 2
      const k = Math.min(w, h) / 2
      ctx.beginPath()
      ctx.moveTo(cx - k, cy - k)
      ctx.lineTo(cx - k + 2 * k * Math.min(1, a), cy - k + 2 * k * Math.min(1, a))
      if (a > 1) {
        ctx.moveTo(cx + k, cy - k)
        ctx.lineTo(cx + k - 2 * k * (a - 1), cy - k + 2 * k * (a - 1))
      }
      ctx.stroke()
      break
    }
    case 'check': {
      ctx.lineWidth = lw * 1.4
      const k = Math.min(w, h) / 2
      const p1 = { x: cx - k * 0.8, y: cy }
      const p2 = { x: cx - k * 0.2, y: cy + k * 0.6 }
      const p3 = { x: cx + k * 0.9, y: cy - k * 0.7 }
      const a = draw * 2
      ctx.beginPath()
      ctx.moveTo(p1.x, p1.y)
      ctx.lineTo(lerp(p1.x, p2.x, Math.min(1, a)), lerp(p1.y, p2.y, Math.min(1, a)))
      if (a > 1) ctx.lineTo(lerp(p2.x, p3.x, a - 1), lerp(p2.y, p3.y, a - 1))
      ctx.stroke()
      break
    }
  }

  if (st.label.trim()) {
    ctx.shadowBlur = 0
    ctx.font = bodyFont(700, 30 * s)
    const tw = ctx.measureText(st.label).width
    const pw = tw + 28 * s
    const ph = 48 * s
    let lx = clamp(cx - pw / 2, clip.x + 8 * s, clip.x + clip.w - pw - 8 * s)
    let ly = y - ph - 12 * s
    if (ly < clip.y + 8 * s) ly = Math.min(clip.y + clip.h - ph - 8 * s, y + h + 12 * s)
    lx = Math.round(lx)
    const pop = ease.outBack(win(local, 0.25, 0.6))
    ctx.translate(lx + pw / 2, ly + ph / 2)
    ctx.scale(pop, pop)
    fillRoundRect(ctx, -pw / 2, -ph / 2, pw, ph, 10 * s, st.color)
    ctx.fillStyle = st.color === '#FFFFFF' || st.color === '#FACC15' ? '#0B0F19' : '#FFFFFF'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText(st.label, 0, 2 * s)
  }
  ctx.restore()
}

// ---------------------------------------------------------------------------
// Held frame
// ---------------------------------------------------------------------------

/** White flash, a camera-shutter frame and the optional text on a frozen picture. */
export function drawFreezeOverlay(ctx: Ctx, L: Layout, text: string, local: number, dur: number) {
  const r = L.video
  const s = L.s / L.k
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  // flash
  const flash = 1 - win(local, 0, 0.35)
  if (flash > 0) {
    ctx.fillStyle = `rgba(255,255,255,${0.85 * flash})`
    ctx.fillRect(r.x, r.y, r.w, r.h)
  }
  // viewfinder corners
  const m = 28 * s
  const k = 70 * s
  ctx.strokeStyle = 'rgba(255,255,255,0.9)'
  ctx.lineWidth = 6 * s
  ctx.globalAlpha = win(local, 0.1, 0.4) * (1 - win(local, dur - 0.25, dur))
  for (const [x, y, dx, dy] of [
    [r.x + m, r.y + m, 1, 1],
    [r.x + r.w - m, r.y + m, -1, 1],
    [r.x + m, r.y + r.h - m, 1, -1],
    [r.x + r.w - m, r.y + r.h - m, -1, -1],
  ]) {
    ctx.beginPath()
    ctx.moveTo(x, y + dy * k)
    ctx.lineTo(x, y)
    ctx.lineTo(x + dx * k, y)
    ctx.stroke()
  }
  ctx.globalAlpha = 1
  if (text.trim()) {
    const scale = L.s
    const maxW = r.w * 0.82
    const f = fitText(ctx, text, (px) => dispFont(800, px), maxW - 60 * scale, 2, 58 * scale, 30 * scale, 1.25)
    ctx.font = dispFont(800, f.size)
    const tw = Math.max(...f.lines.map((l) => ctx.measureText(l).width))
    const bw = tw + 60 * scale
    const bh = f.lines.length * f.lineHeight + 34 * scale
    const slide = ease.outCubic(win(local, 0.25, 0.65))
    const out = ease.inCubic(win(local, dur - 0.3, dur))
    const bx = r.x + (r.w - bw) / 2 + (1 - slide) * -r.w * 0.6 + out * r.w * 0.6
    const by = r.y + r.h * 0.72 - bh / 2
    ctx.shadowColor = 'rgba(0,0,0,0.4)'
    ctx.shadowBlur = 18 * scale
    fillRoundRect(ctx, bx, by, bw, bh, 14 * scale, BRAND.blue)
    ctx.shadowBlur = 0
    ctx.fillStyle = BRAND.red
    ctx.fillRect(bx, by + bh - 7 * scale, bw, 7 * scale)
    ctx.fillStyle = '#FFFFFF'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    f.lines.forEach((l, i) => ctx.fillText(l, bx + bw / 2, by + 17 * scale + f.lineHeight * (i + 0.5)))
  }
  ctx.restore()
}

// ---------------------------------------------------------------------------
// Transitions
// ---------------------------------------------------------------------------

export interface TransitionHit {
  /** 0 → 1 across the transition; the cut happens at 0.5. */
  p: number
  /** Between two parts of the news (keep the frame bands), or anything else. */
  region: 'video' | 'all'
}

/** Whether output time T is inside a transition, and how far along. */
export function transitionAt(tl: Timeline, cfg: EditConfig, T: number): TransitionHit | null {
  const tr = cfg.transition
  if (tr.id === 'none') return null
  const half = tr.dur / 2
  const segs = tl.segments
  for (let i = 1; i < segs.length; i++) {
    const b = segs[i].t0
    if (T < b - half || T >= b + half) continue
    const prev = segs[i - 1]
    const next = segs[i]
    const bothMain = prev.kind === 'main' && next.kind === 'main'
    if (bothMain) {
      // only where a cut joins two parts (not at speed changes or held frames)
      if (!tr.cuts || prev.rate === 0 || next.rate === 0 || Math.abs(segSourceEnd(prev) - next.clipStart) < 0.05) continue
    }
    return { p: (T - (b - half)) / tr.dur, region: bothMain ? 'video' : 'all' }
  }
  return null
}

/** Output times of the transitions (for their whoosh). */
export function transitionTimes(tl: Timeline, cfg: EditConfig): number[] {
  if (cfg.transition.id === 'none') return []
  const out: number[] = []
  for (let i = 1; i < tl.segments.length; i++) {
    const b = tl.segments[i].t0
    if (transitionAt(tl, cfg, b)) out.push(b)
  }
  return out
}

/**
 * Paints the transition over `r`. The zoom style re-draws what is already
 * on the canvas (scaled), using `scratch` as a copy.
 */
export function drawTransition(ctx: Ctx, id: TransitionId, p: number, r: Rect, s: number, scratch: HTMLCanvasElement | OffscreenCanvas) {
  const cover = 1 - Math.abs(2 * p - 1) // 0 → 1 → 0
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  if (id === 'fade') {
    const g = ctx.createLinearGradient(r.x, r.y, r.x + r.w, r.y + r.h)
    g.addColorStop(0, BRAND.blue)
    g.addColorStop(1, BRAND.navy)
    ctx.globalAlpha = ease.inOutCubic(cover)
    ctx.fillStyle = g
    ctx.fillRect(r.x, r.y, r.w, r.h)
    ctx.globalAlpha = Math.max(0, cover * 2 - 1)
    ctx.fillStyle = BRAND.red
    ctx.fillRect(r.x + r.w * 0.3, r.y + r.h / 2 - 3 * s, r.w * 0.4, 6 * s)
  } else if (id === 'slide') {
    // blue panel in from the left, out to the right; a red edge leads it
    const pos = p < 0.5 ? ease.outCubic(p * 2) - 1 : ease.inCubic((p - 0.5) * 2)
    const x = r.x + pos * r.w
    ctx.fillStyle = BRAND.blue
    ctx.fillRect(x, r.y, r.w, r.h)
    ctx.fillStyle = BRAND.red
    const edge = 26 * s
    if (p < 0.5) ctx.fillRect(x + r.w, r.y, edge, r.h)
    else ctx.fillRect(x - edge, r.y, edge, r.h)
  } else if (id === 'wipe') {
    // one slanted brand band, with red and white edges, sweeps across
    const slant = r.h * 0.5
    const bw = r.w + r.h
    const left = lerp(r.x - bw - slant, r.x + r.w + slant, ease.inOutCubic(p))
    const band = (x0: number, x1: number, color: string) => {
      ctx.fillStyle = color
      ctx.beginPath()
      ctx.moveTo(x0, r.y + r.h)
      ctx.lineTo(x1, r.y + r.h)
      ctx.lineTo(x1 + slant, r.y)
      ctx.lineTo(x0 + slant, r.y)
      ctx.closePath()
      ctx.fill()
    }
    const e = 34 * s
    band(left, left + bw, BRAND.blue)
    band(left + bw, left + bw + e, BRAND.red)
    band(left + bw + e, left + bw + e * 1.4, '#FFFFFF')
    band(left - e, left, BRAND.red)
  } else if (id === 'zoom') {
    // punch in on what is there, flash white at the cut, settle back
    const m = ctx.getTransform()
    const cv = ctx.canvas as HTMLCanvasElement | OffscreenCanvas
    const sx = Math.max(0, Math.round(m.a * r.x + m.e))
    const sy = Math.max(0, Math.round(m.d * r.y + m.f))
    const sw = Math.min(cv.width - sx, Math.round(m.a * r.w))
    const sh = Math.min(cv.height - sy, Math.round(m.d * r.h))
    if (sw > 2 && sh > 2) {
      const cw = Math.max(2, Math.round(sw / 2))
      const ch = Math.max(2, Math.round(sh / 2))
      if (scratch.width !== cw || scratch.height !== ch) {
        scratch.width = cw
        scratch.height = ch
      }
      const g = scratch.getContext('2d') as Ctx
      g.drawImage(cv as CanvasImageSource, sx, sy, sw, sh, 0, 0, cw, ch)
      const z = 1 + 0.35 * ease.inOutCubic(cover)
      const w = r.w * z
      const h = r.h * z
      ctx.filter = `blur(${(cover * 6 * s).toFixed(1)}px)`
      ctx.drawImage(scratch as CanvasImageSource, r.x + (r.w - w) / 2, r.y + (r.h - h) / 2, w, h)
      ctx.filter = 'none'
    }
    ctx.fillStyle = `rgba(255,255,255,${Math.pow(cover, 3) * 0.95})`
    ctx.fillRect(r.x, r.y, r.w, r.h)
  }
  ctx.restore()
}
