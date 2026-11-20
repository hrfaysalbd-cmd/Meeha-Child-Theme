/**
 * Round 46 — the professional studio pack: subject-desk frames, more drawn
 * intros and outros, name-plate (lower-third) designs and branded
 * stingers between segments.
 *
 * Everything here is drawn in code with the canvas (no downloaded media),
 * as pure functions of time, so the live preview and the export match.
 * Sizes are in units of `s` (1 at a 1080-pixel shorter side), like draw.ts.
 *
 * Only types are imported from draw.ts, so there is no runtime import cycle:
 * draw.ts spreads PACK_THEMES into its theme table and hands unknown scenes
 * and lower-third styles to the functions below.
 */
import { BRAND, FONT_BODY, FONT_DISPLAY, type LogoAsset } from './assets'
import type { Caption, CaptionStyle, LowerThirdId, PackIntroId, PackOutroId, StingerId } from './config'
import type { DrawEnv, Theme } from './draw'
import { netIcon } from './houseads'
import { HEADER_ART, clamp, ease, fillRoundRect, fitText, lerp, rnd, roundRect, win, type Ctx } from './motion'
import type { SfxId } from './sfxlib'
import type { Rect, SceneId } from './timeline'

const bodyFont = (w: number, px: number) => `${w} ${px}px ${FONT_BODY}`
const dispFont = (w: number, px: number) => `${w} ${px}px ${FONT_DISPLAY}`
const TAU = Math.PI * 2
const TRI = ['#CC2A1F', '#FFC20E', '#12A150']
const NIL = { navy: '#0B2A6B', royal: '#1546B8', amber: '#FFC20E' }

function hexA(hex: string, a: number) {
  const n = parseInt(hex.slice(1), 16)
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${clamp(a, 0, 1).toFixed(3)})`
}

function grad(ctx: Ctx, r: Rect, stops: [number, string][], angle = 0) {
  const cx = r.x + r.w / 2
  const cy = r.y + r.h / 2
  const dx = (Math.cos(angle) * r.w) / 2
  const dy = (Math.sin(angle) * r.h) / 2
  const g = ctx.createLinearGradient(cx - dx, cy - dy, cx + dx, cy + dy)
  for (const [o, c] of stops) g.addColorStop(o, c)
  return g
}

function fill(ctx: Ctx, r: Rect, style: string | CanvasGradient) {
  ctx.fillStyle = style
  ctx.fillRect(r.x, r.y, r.w, r.h)
}

/** A soft band of light crossing `r` every `period` seconds. */
function glint(ctx: Ctx, r: Rect, t: number, period: number, alpha = 0.14) {
  const p = (t % period) / period
  const w = Math.max(r.w * 0.18, r.h)
  const x = r.x - w + (r.w + 2 * w) * p
  const g = ctx.createLinearGradient(x - w / 2, 0, x + w / 2, 0)
  g.addColorStop(0, 'rgba(255,255,255,0)')
  g.addColorStop(0.5, `rgba(255,255,255,${alpha})`)
  g.addColorStop(1, 'rgba(255,255,255,0)')
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  ctx.fillStyle = g
  ctx.fillRect(x - w / 2, r.y, w, r.h)
  ctx.restore()
}

/** Diagonal hazard stripes. */
function hazard(ctx: Ctx, r: Rect, t: number, s: number, a: string, b: string, speed = 30) {
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  fill(ctx, r, a)
  ctx.fillStyle = b
  const gap = 34 * s
  const off = (t * speed * s) % (gap * 2)
  for (let x = r.x - r.h - gap * 2 + off; x < r.x + r.w + r.h; x += gap * 2) {
    ctx.beginPath()
    ctx.moveTo(x, r.y + r.h)
    ctx.lineTo(x + gap, r.y + r.h)
    ctx.lineTo(x + gap + r.h, r.y)
    ctx.lineTo(x + r.h, r.y)
    ctx.closePath()
    ctx.fill()
  }
  ctx.restore()
}

/** Red, yellow and green blocks gliding along a thin bar. */
function triBar(ctx: Ctx, r: Rect, t: number, s: number, speed = 40) {
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  const seg = Math.max(r.w / 6, 80 * s)
  const period = seg * 3
  const off = (((t * speed * s) % period) + period) % period
  for (let i = -3; i * seg < r.w + period; i++) {
    ctx.fillStyle = TRI[((i % 3) + 3) % 3]
    ctx.fillRect(r.x + i * seg + off - period, r.y, seg + 1, r.h)
  }
  ctx.restore()
}

function star(ctx: Ctx, cx: number, cy: number, r: number, points = 5, inner = 0.45, rot = -Math.PI / 2) {
  ctx.beginPath()
  for (let i = 0; i < points * 2; i++) {
    const a = rot + (i * Math.PI) / points
    const rr = i % 2 ? r * inner : r
    ctx.lineTo(cx + Math.cos(a) * rr, cy + Math.sin(a) * rr)
  }
  ctx.closePath()
}

function sparkles(ctx: Ctx, r: Rect, t: number, s: number, n: number, color: string, seed = 0) {
  for (let i = 0; i < n; i++) {
    const ph = (t * (0.5 + rnd(i + seed) * 0.7) + rnd(i + seed + 40)) % 1
    const a = Math.sin(ph * Math.PI)
    if (a < 0.05) continue
    const x = r.x + rnd(i + seed + 7) * r.w
    const y = r.y + rnd(i + seed + 19) * r.h
    ctx.fillStyle = hexA(color, a * 0.9)
    star(ctx, x, y, (3 + rnd(i + seed + 3) * 5) * s * a, 4, 0.3, 0)
    ctx.fill()
  }
}

// ---------------------------------------------------------------------------
// Icons (drawn at the right of the header band)
// ---------------------------------------------------------------------------

function iconSpot(r: Rect) {
  const size = Math.min(r.h * 0.5, r.w * 0.12)
  return { cx: r.x + r.w - r.h * 0.72, cy: r.y + r.h * 0.5, u: size }
}

function ballotIcon(ctx: Ctx, cx: number, cy: number, u: number, t: number, fg: string, accent: string) {
  // the paper drops into the box every 2.4 s
  const p = (t % 2.4) / 2.4
  const drop = ease.inCubic(win(p, 0.1, 0.55))
  ctx.save()
  ctx.beginPath()
  ctx.rect(cx - u, cy - u * 1.6, u * 2, u * 1.55)
  ctx.clip()
  const py = cy - u * 1.25 + drop * u * 0.9
  fillRoundRect(ctx, cx - u * 0.38, py - u * 0.42, u * 0.76, u * 0.84, u * 0.06, '#FFFFFF')
  ctx.strokeStyle = accent
  ctx.lineWidth = u * 0.11
  ctx.lineCap = 'round'
  ctx.beginPath()
  ctx.moveTo(cx - u * 0.18, py)
  ctx.lineTo(cx - u * 0.03, py + u * 0.15)
  ctx.lineTo(cx + u * 0.22, py - u * 0.18)
  ctx.stroke()
  ctx.restore()
  fillRoundRect(ctx, cx - u * 0.85, cy - u * 0.1, u * 1.7, u * 1.0, u * 0.1, fg)
  ctx.fillStyle = 'rgba(0,0,0,0.45)'
  ctx.fillRect(cx - u * 0.5, cy - u * 0.1, u, u * 0.1)
  ctx.fillStyle = accent
  ctx.fillRect(cx - u * 0.85, cy + u * 0.35, u * 1.7, u * 0.12)
}

function barsIcon(ctx: Ctx, cx: number, cy: number, u: number, t: number, colors: string[]) {
  const n = 4
  const bw = u * 0.34
  for (let i = 0; i < n; i++) {
    const h = u * (0.5 + 0.35 * i + 0.22 * Math.sin(t * 2.2 + i * 1.3))
    const x = cx - u * 0.85 + i * bw * 1.4
    fillRoundRect(ctx, x, cy + u * 0.85 - h, bw, h, bw * 0.2, colors[i % colors.length])
  }
  // rising arrow
  ctx.strokeStyle = '#FFFFFF'
  ctx.lineWidth = u * 0.09
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.beginPath()
  ctx.moveTo(cx - u * 0.9, cy + u * 0.2)
  ctx.lineTo(cx - u * 0.3, cy - u * 0.25)
  ctx.lineTo(cx + u * 0.1, cy)
  ctx.lineTo(cx + u * 0.85, cy - u * 0.75)
  ctx.stroke()
  ctx.beginPath()
  ctx.moveTo(cx + u * 0.85, cy - u * 0.75)
  ctx.lineTo(cx + u * 0.5, cy - u * 0.72)
  ctx.moveTo(cx + u * 0.85, cy - u * 0.75)
  ctx.lineTo(cx + u * 0.82, cy - u * 0.4)
  ctx.stroke()
}

function scalesIcon(ctx: Ctx, cx: number, cy: number, u: number, tilt: number, color: string) {
  ctx.save()
  ctx.strokeStyle = color
  ctx.fillStyle = color
  ctx.lineWidth = u * 0.08
  ctx.lineCap = 'round'
  // pole and foot
  ctx.beginPath()
  ctx.moveTo(cx, cy - u * 0.85)
  ctx.lineTo(cx, cy + u * 0.75)
  ctx.stroke()
  fillRoundRect(ctx, cx - u * 0.45, cy + u * 0.72, u * 0.9, u * 0.14, u * 0.05, color)
  ctx.beginPath()
  ctx.arc(cx, cy - u * 0.9, u * 0.1, 0, TAU)
  ctx.fill()
  // beam
  const a = tilt
  const bx = Math.cos(a) * u * 0.9
  const by = Math.sin(a) * u * 0.9
  const top = cy - u * 0.65
  ctx.beginPath()
  ctx.moveTo(cx - bx, top - by)
  ctx.lineTo(cx + bx, top + by)
  ctx.stroke()
  for (const sgn of [-1, 1]) {
    const px = cx + sgn * bx
    const py = top + sgn * by
    ctx.lineWidth = u * 0.04
    ctx.beginPath()
    ctx.moveTo(px, py)
    ctx.lineTo(px - u * 0.25, py + u * 0.6)
    ctx.moveTo(px, py)
    ctx.lineTo(px + u * 0.25, py + u * 0.6)
    ctx.stroke()
    ctx.beginPath()
    ctx.ellipse(px, py + u * 0.62, u * 0.3, u * 0.09, 0, 0, Math.PI)
    ctx.fill()
  }
  ctx.restore()
}

function beaconIcon(ctx: Ctx, cx: number, cy: number, u: number, t: number) {
  const a = t * 4
  ctx.save()
  // rotating beam
  const g = ctx.createRadialGradient(cx, cy, 0, cx, cy, u * 2.2)
  g.addColorStop(0, 'rgba(255,170,40,0.55)')
  g.addColorStop(1, 'rgba(255,170,40,0)')
  ctx.fillStyle = g
  ctx.beginPath()
  ctx.moveTo(cx, cy)
  ctx.arc(cx, cy, u * 2.2, a - 0.35, a + 0.35)
  ctx.closePath()
  ctx.fill()
  ctx.beginPath()
  ctx.moveTo(cx, cy)
  ctx.arc(cx, cy, u * 2.2, a + Math.PI - 0.35, a + Math.PI + 0.35)
  ctx.closePath()
  ctx.fill()
  // dome
  const on = 0.6 + 0.4 * Math.abs(Math.sin(t * 4))
  ctx.fillStyle = `rgba(255,${Math.round(140 + 60 * on)},40,1)`
  ctx.beginPath()
  ctx.arc(cx, cy + u * 0.15, u * 0.55, Math.PI, 0)
  ctx.closePath()
  ctx.fill()
  fillRoundRect(ctx, cx - u * 0.75, cy + u * 0.15, u * 1.5, u * 0.3, u * 0.08, '#E5E7EB')
  ctx.restore()
}

function crossIcon(ctx: Ctx, cx: number, cy: number, u: number, t: number, color: string) {
  const beat = (t % 1.1) / 1.1
  const k = 1 + 0.12 * Math.max(0, Math.sin(beat * Math.PI * 4)) * (beat < 0.5 ? 1 : 0)
  ctx.save()
  ctx.translate(cx, cy)
  ctx.scale(k, k)
  const w = u * 0.42
  const g = ctx.createRadialGradient(0, 0, 0, 0, 0, u * 1.3)
  g.addColorStop(0, hexA(color, 0.25))
  g.addColorStop(1, hexA(color, 0))
  ctx.fillStyle = g
  ctx.beginPath()
  ctx.arc(0, 0, u * 1.3, 0, TAU)
  ctx.fill()
  fillRoundRect(ctx, -w / 2, -u * 0.8, w, u * 1.6, w * 0.2, color)
  fillRoundRect(ctx, -u * 0.8, -w / 2, u * 1.6, w, w * 0.2, color)
  ctx.restore()
}

function capIcon(ctx: Ctx, cx: number, cy: number, u: number, t: number, color: string, tassel: string) {
  const bob = Math.sin(t * 2) * u * 0.06
  ctx.save()
  ctx.translate(cx, cy + bob)
  ctx.fillStyle = color
  // base
  ctx.beginPath()
  ctx.moveTo(-u * 0.55, -u * 0.05)
  ctx.lineTo(-u * 0.55, u * 0.4)
  ctx.quadraticCurveTo(0, u * 0.7, u * 0.55, u * 0.4)
  ctx.lineTo(u * 0.55, -u * 0.05)
  ctx.closePath()
  ctx.fill()
  // board
  ctx.beginPath()
  ctx.moveTo(0, -u * 0.6)
  ctx.lineTo(u * 1.05, -u * 0.15)
  ctx.lineTo(0, u * 0.3)
  ctx.lineTo(-u * 1.05, -u * 0.15)
  ctx.closePath()
  ctx.fill()
  ctx.strokeStyle = tassel
  ctx.lineWidth = u * 0.07
  ctx.beginPath()
  ctx.moveTo(0, -u * 0.15)
  ctx.lineTo(u * 0.75, u * 0.05)
  const sw = Math.sin(t * 2.6) * u * 0.08
  ctx.lineTo(u * 0.75 + sw, u * 0.55)
  ctx.stroke()
  ctx.fillStyle = tassel
  ctx.beginPath()
  ctx.arc(u * 0.75 + sw, u * 0.6, u * 0.09, 0, TAU)
  ctx.fill()
  ctx.restore()
}

function ballIcon(ctx: Ctx, cx: number, cy: number, u: number, t: number) {
  ctx.save()
  ctx.translate(cx, cy)
  ctx.rotate(t * 1.2)
  ctx.fillStyle = '#FFFFFF'
  ctx.beginPath()
  ctx.arc(0, 0, u * 0.75, 0, TAU)
  ctx.fill()
  ctx.fillStyle = '#111111'
  star(ctx, 0, 0, u * 0.28, 5, 0.8)
  ctx.fill()
  for (let i = 0; i < 5; i++) {
    const a = -Math.PI / 2 + (i * TAU) / 5
    ctx.save()
    ctx.beginPath()
    ctx.arc(0, 0, u * 0.75, 0, TAU)
    ctx.clip()
    star(ctx, Math.cos(a) * u * 0.78, Math.sin(a) * u * 0.78, u * 0.24, 5, 0.8, a)
    ctx.fill()
    ctx.restore()
  }
  ctx.restore()
}

function crescent(ctx: Ctx, cx: number, cy: number, u: number, color: string, bg: string) {
  ctx.save()
  ctx.fillStyle = color
  ctx.beginPath()
  ctx.arc(cx, cy, u * 0.7, 0, TAU)
  ctx.fill()
  ctx.globalCompositeOperation = 'destination-out'
  ctx.beginPath()
  ctx.arc(cx + u * 0.3, cy - u * 0.12, u * 0.6, 0, TAU)
  ctx.fill()
  ctx.restore()
  void bg
}

function sunIcon(ctx: Ctx, cx: number, cy: number, u: number, t: number, color = '#FDE047') {
  ctx.save()
  ctx.translate(cx, cy)
  ctx.rotate(t * 0.3)
  ctx.strokeStyle = color
  ctx.lineWidth = u * 0.09
  ctx.lineCap = 'round'
  for (let i = 0; i < 10; i++) {
    const a = (i * TAU) / 10
    ctx.beginPath()
    ctx.moveTo(Math.cos(a) * u * 0.62, Math.sin(a) * u * 0.62)
    ctx.lineTo(Math.cos(a) * u * 0.85, Math.sin(a) * u * 0.85)
    ctx.stroke()
  }
  ctx.fillStyle = color
  ctx.beginPath()
  ctx.arc(0, 0, u * 0.45, 0, TAU)
  ctx.fill()
  ctx.restore()
}

function cloud(ctx: Ctx, cx: number, cy: number, u: number, color: string) {
  ctx.fillStyle = color
  ctx.beginPath()
  ctx.arc(cx - u * 0.45, cy + u * 0.1, u * 0.35, 0, TAU)
  ctx.arc(cx, cy - u * 0.12, u * 0.5, 0, TAU)
  ctx.arc(cx + u * 0.5, cy + u * 0.1, u * 0.36, 0, TAU)
  ctx.fill()
  fillRoundRect(ctx, cx - u * 0.8, cy + u * 0.05, u * 1.65, u * 0.42, u * 0.2, color)
}

/** Rice stalks swaying along the bottom of `r`. */
function paddy(ctx: Ctx, r: Rect, t: number, s: number, color: string, grain: string) {
  const step = 18 * s
  const h = r.h * 0.42
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  ctx.lineCap = 'round'
  for (let i = 0, x = r.x; x < r.x + r.w + step; i++, x += step) {
    const hh = h * (0.7 + rnd(i) * 0.5)
    const sway = Math.sin(t * 1.6 + i * 0.45) * 8 * s
    ctx.strokeStyle = color
    ctx.lineWidth = 2.4 * s
    ctx.beginPath()
    ctx.moveTo(x, r.y + r.h)
    ctx.quadraticCurveTo(x + sway * 0.3, r.y + r.h - hh * 0.6, x + sway, r.y + r.h - hh)
    ctx.stroke()
    if (i % 2 === 0) {
      ctx.fillStyle = grain
      for (let k = 0; k < 3; k++) {
        ctx.beginPath()
        ctx.ellipse(x + sway + k * 2 * s, r.y + r.h - hh + k * 5 * s, 2.2 * s, 4 * s, 0.5, 0, TAU)
        ctx.fill()
      }
    }
  }
  ctx.restore()
}

/** Faint eight-point star lattice (religion desk). */
function lattice(ctx: Ctx, r: Rect, s: number, color: string) {
  const step = 56 * s
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  ctx.strokeStyle = color
  ctx.lineWidth = 1.4 * s
  for (let y = r.y + step / 2; y < r.y + r.h + step; y += step) {
    for (let x = r.x + step / 2; x < r.x + r.w + step; x += step) {
      star(ctx, x, y, step * 0.34, 8, 0.62, 0)
      ctx.stroke()
    }
  }
  ctx.restore()
}

// ---------------------------------------------------------------------------
// Frames (subject desks)
// ---------------------------------------------------------------------------

export const PACK_THEMES: Record<'vote' | 'budget' | 'court' | 'accident' | 'medical' | 'education' | 'stadium' | 'glamour' | 'agri' | 'religion', Theme> = {
  vote: {
    headerBg: (ctx, r, t, s) => {
      fill(ctx, r, grad(ctx, r, [[0, '#0B3B2E'], [0.6, '#0B2A4A'], [1, '#0A1F3C']], -0.25))
      // results bars running along the bottom edge
      const bh = 10 * s
      const parts = [0.38 + 0.05 * Math.sin(t * 0.9), 0.3 + 0.04 * Math.sin(t * 1.1 + 1), 0.2, 0.12]
      const sum = parts.reduce((a, b) => a + b, 0)
      let x = r.x
      ;['#12A150', '#F2C230', '#CC2A1F', '#38BDF8'].forEach((c, i) => {
        const w = (parts[i] / sum) * r.w
        fill(ctx, { x, y: r.y + r.h - bh, w: w + 1, h: bh }, c)
        x += w
      })
      glint(ctx, r, t, 6, 0.08)
      if (HEADER_ART) {
        const { cx, cy, u } = iconSpot(r)
        ballotIcon(ctx, cx, cy, u, t, '#E5E7EB', '#12A150')
      }
    },
    footerBg: (ctx, r, t, s) => {
      fill(ctx, r, '#0A1F3C')
      fill(ctx, { x: r.x, y: r.y, w: r.w, h: 4 * s }, '#F2C230')
      void t
    },
    title: '#FFFFFF',
    date: '#BBF7D0',
    kickerBg: '#F2C230',
    kickerFg: '#1A1400',
    badge: { text: 'ভোটের খবর', bg: '#12A150', fg: '#FFFFFF', kind: 'pulse' },
    labelBg: '#12A150',
    labelFg: '#FFFFFF',
    tickerFg: '#FFFFFF',
    accent: '#F2C230',
    edge: '#F2C230',
    rightRoom: 1.5,
  },
  budget: {
    headerBg: (ctx, r, t, s) => {
      fill(ctx, r, grad(ctx, r, [[0, '#0A1A3A'], [1, '#0F2E5C']], 0.2))
      // faint chart grid and a moving market line
      ctx.strokeStyle = 'rgba(255,255,255,0.06)'
      ctx.lineWidth = 1 * s
      for (let y = r.y + 30 * s; y < r.y + r.h; y += 30 * s) {
        ctx.beginPath()
        ctx.moveTo(r.x, y)
        ctx.lineTo(r.x + r.w, y)
        ctx.stroke()
      }
      ctx.strokeStyle = 'rgba(52,211,153,0.35)'
      ctx.lineWidth = 3 * s
      ctx.beginPath()
      for (let x = 0; x <= r.w; x += 12 * s) {
        const y = r.y + r.h * 0.62 - Math.sin(x / (90 * s) + t * 1.2) * 14 * s - Math.sin(x / (37 * s) - t * 2) * 6 * s - (x / r.w) * r.h * 0.18
        if (x === 0) ctx.moveTo(r.x + x, y)
        else ctx.lineTo(r.x + x, y)
      }
      ctx.stroke()
      if (HEADER_ART) {
        const { cx, cy, u } = iconSpot(r)
        barsIcon(ctx, cx, cy, u, t, ['#34D399', '#22D3EE', '#F59E0B', '#34D399'])
        ctx.save()
        ctx.font = dispFont(800, u * 0.9)
        ctx.fillStyle = '#FDE68A'
        ctx.textAlign = 'center'
        ctx.textBaseline = 'middle'
        ctx.fillText('৳', cx - u * 1.35, cy - u * 0.15)
        ctx.restore()
      }
    },
    footerBg: (ctx, r, t, s) => {
      fill(ctx, r, '#081530')
      fill(ctx, { x: r.x, y: r.y, w: r.w, h: 4 * s }, '#34D399')
      void t
    },
    title: '#FFFFFF',
    date: '#A7F3D0',
    kickerBg: '#34D399',
    kickerFg: '#042F1F',
    badge: { text: 'বাজেট', bg: '#F59E0B', fg: '#1A0E00', kind: 'tape' },
    labelBg: '#34D399',
    labelFg: '#042F1F',
    tickerFg: '#FFFFFF',
    accent: '#34D399',
    edge: '#34D399',
    rightRoom: 2.1,
  },
  court: {
    headerBg: (ctx, r, t, s) => {
      fill(ctx, r, grad(ctx, r, [[0, '#2A080C'], [0.7, '#4A1018'], [1, '#2A080C']], 0))
      // columns
      ctx.fillStyle = 'rgba(212,175,55,0.07)'
      for (let x = r.x + 40 * s; x < r.x + r.w; x += 120 * s) ctx.fillRect(x, r.y, 26 * s, r.h)
      fill(ctx, { x: r.x, y: r.y + r.h - 5 * s, w: r.w, h: 5 * s }, '#D4AF37')
      glint(ctx, r, t, 8, 0.06)
      if (HEADER_ART) {
        const { cx, cy, u } = iconSpot(r)
        scalesIcon(ctx, cx, cy, u, Math.sin(t * 1.3) * 0.12, '#D4AF37')
      }
    },
    footerBg: (ctx, r, t, s) => {
      fill(ctx, r, '#1A0508')
      fill(ctx, { x: r.x, y: r.y, w: r.w, h: 3 * s }, '#D4AF37')
      void t
    },
    title: '#FFF7E6',
    date: '#E9D8A6',
    kickerBg: 'rgba(212,175,55,0.18)',
    kickerFg: '#F3DC8A',
    badge: { text: 'আদালত', bg: 'transparent', fg: '#D4AF37', kind: 'gold' },
    labelBg: '#D4AF37',
    labelFg: '#2A080C',
    tickerFg: '#FFF7E6',
    accent: '#D4AF37',
    edge: '#D4AF37',
    rightRoom: 1.5,
  },
  accident: {
    headerBg: (ctx, r, t, s) => {
      fill(ctx, r, grad(ctx, r, [[0, '#1C1C1E'], [1, '#2C2C30']], 0.3))
      hazard(ctx, { x: r.x, y: r.y + r.h - 14 * s, w: r.w, h: 14 * s }, t, s, '#F59E0B', '#111111', 40)
      if (HEADER_ART) {
        const { cx, cy, u } = iconSpot(r)
        beaconIcon(ctx, cx, cy, u, t)
      }
    },
    footerBg: (ctx, r, t, s) => {
      fill(ctx, r, '#111113')
      hazard(ctx, { x: r.x, y: r.y, w: r.w, h: 6 * s }, t, s, '#F59E0B', '#111111', -40)
    },
    title: '#FFFFFF',
    date: '#FCD34D',
    kickerBg: '#F59E0B',
    kickerFg: '#111111',
    badge: { text: 'দুর্ঘটনা', bg: '#F59E0B', fg: '#111111', kind: 'pulse' },
    labelBg: '#F59E0B',
    labelFg: '#111111',
    tickerFg: '#FFFFFF',
    accent: '#F59E0B',
    rightRoom: 1.5,
  },
  medical: {
    headerBg: (ctx, r, t, s) => {
      fill(ctx, r, grad(ctx, r, [[0, '#FFFFFF'], [1, '#E6FFFB']], 0.2))
      // faint plus pattern
      ctx.fillStyle = 'rgba(13,148,136,0.07)'
      for (let y = r.y + 20 * s; y < r.y + r.h; y += 46 * s)
        for (let x = r.x + 20 * s + ((y / (46 * s)) % 2) * 23 * s; x < r.x + r.w; x += 46 * s) {
          ctx.fillRect(x - 7 * s, y - 2 * s, 14 * s, 4 * s)
          ctx.fillRect(x - 2 * s, y - 7 * s, 4 * s, 14 * s)
        }
      // ECG along the bottom
      const base = r.y + r.h - 16 * s
      ctx.strokeStyle = '#0D9488'
      ctx.lineWidth = 3 * s
      ctx.lineJoin = 'round'
      ctx.beginPath()
      const period = 260 * s
      const off = (t * 160 * s) % period
      for (let x = 0; x <= r.w; x += 4 * s) {
        const ph = ((x + off) % period) / period
        let y = 0
        if (ph > 0.4 && ph < 0.44) y = -((ph - 0.4) / 0.04) * 10
        else if (ph >= 0.44 && ph < 0.48) y = -10 + ((ph - 0.44) / 0.04) * 34
        else if (ph >= 0.48 && ph < 0.52) y = 24 - ((ph - 0.48) / 0.04) * 30
        else if (ph >= 0.52 && ph < 0.56) y = -6 + ((ph - 0.52) / 0.04) * 6
        if (x === 0) ctx.moveTo(r.x + x, base + y * s * 0.8)
        else ctx.lineTo(r.x + x, base + y * s * 0.8)
      }
      ctx.stroke()
      if (HEADER_ART) {
        const { cx, cy, u } = iconSpot(r)
        crossIcon(ctx, cx, cy - r.h * 0.05, u * 0.9, t, '#E11D48')
      }
    },
    footerBg: (ctx, r, t, s) => {
      fill(ctx, r, '#0D9488')
      void t
      void s
    },
    title: '#0B2E2B',
    date: '#0F766E',
    kickerBg: '#0D9488',
    kickerFg: '#FFFFFF',
    badge: { text: 'স্বাস্থ্য', bg: '#E11D48', fg: '#FFFFFF', kind: 'pulse' },
    labelBg: '#0B2E2B',
    labelFg: '#FFFFFF',
    tickerFg: '#FFFFFF',
    accent: '#0D9488',
    edge: '#0D9488',
    rightRoom: 1.5,
  },
  education: {
    headerBg: (ctx, r, t, s) => {
      fill(ctx, r, grad(ctx, r, [[0, '#1E1B6B'], [1, '#312E81']], -0.3))
      // notebook lines and margin
      ctx.strokeStyle = 'rgba(165,180,252,0.12)'
      ctx.lineWidth = 1.5 * s
      for (let y = r.y + 34 * s; y < r.y + r.h; y += 34 * s) {
        ctx.beginPath()
        ctx.moveTo(r.x, y)
        ctx.lineTo(r.x + r.w, y)
        ctx.stroke()
      }
      ctx.strokeStyle = 'rgba(251,113,133,0.35)'
      ctx.beginPath()
      ctx.moveTo(r.x + 26 * s, r.y)
      ctx.lineTo(r.x + 26 * s, r.y + r.h)
      ctx.stroke()
      fill(ctx, { x: r.x, y: r.y + r.h - 6 * s, w: r.w, h: 6 * s }, '#FBBF24')
      glint(ctx, r, t, 7, 0.08)
      if (HEADER_ART) {
        const { cx, cy, u } = iconSpot(r)
        capIcon(ctx, cx, cy, u, t, '#F8FAFC', '#FBBF24')
      }
    },
    footerBg: (ctx, r, t, s) => {
      fill(ctx, r, '#17154F')
      fill(ctx, { x: r.x, y: r.y, w: r.w, h: 4 * s }, '#FBBF24')
      void t
    },
    title: '#FFFFFF',
    date: '#C7D2FE',
    kickerBg: '#FBBF24',
    kickerFg: '#1E1B4B',
    badge: { text: 'শিক্ষা', bg: '#FBBF24', fg: '#1E1B4B', kind: 'tape' },
    labelBg: '#FBBF24',
    labelFg: '#1E1B4B',
    tickerFg: '#FFFFFF',
    accent: '#FBBF24',
    edge: '#FBBF24',
    rightRoom: 1.6,
  },
  stadium: {
    headerBg: (ctx, r, t, s) => {
      fill(ctx, r, grad(ctx, r, [[0, '#03130B'], [1, '#063B22']], Math.PI / 2))
      // mowing stripes of the pitch
      ctx.fillStyle = 'rgba(255,255,255,0.035)'
      for (let x = r.x; x < r.x + r.w; x += 120 * s) ctx.fillRect(x, r.y, 60 * s, r.h)
      // floodlight beams sweeping
      for (const [ox, dir] of [[0.18, 1], [0.62, -1]] as const) {
        const a = Math.PI / 2 + dir * (0.35 + 0.25 * Math.sin(t * 0.8 + ox * 5))
        const x0 = r.x + r.w * ox
        const g = ctx.createRadialGradient(x0, r.y, 0, x0, r.y, r.h * 1.6)
        g.addColorStop(0, 'rgba(255,255,230,0.22)')
        g.addColorStop(1, 'rgba(255,255,230,0)')
        ctx.fillStyle = g
        ctx.beginPath()
        ctx.moveTo(x0, r.y)
        ctx.arc(x0, r.y, r.h * 1.6, a - 0.18, a + 0.18)
        ctx.closePath()
        ctx.fill()
      }
      fill(ctx, { x: r.x, y: r.y + r.h - 6 * s, w: r.w, h: 6 * s }, '#E11D48')
      if (HEADER_ART) {
        const { cx, cy, u } = iconSpot(r)
        ballIcon(ctx, cx, cy, u, t)
      }
    },
    footerBg: (ctx, r, t, s) => {
      fill(ctx, r, '#0B0F0D')
      fill(ctx, { x: r.x, y: r.y, w: r.w, h: 4 * s }, '#E11D48')
      void t
    },
    title: '#FFFFFF',
    date: '#BBF7D0',
    kickerBg: '#E11D48',
    kickerFg: '#FFFFFF',
    badge: { text: 'খেলা', bg: '#FFFFFF', fg: '#063B22', kind: 'tape' },
    labelBg: '#E11D48',
    labelFg: '#FFFFFF',
    tickerFg: '#FFFFFF',
    accent: '#E11D48',
    rightRoom: 1.5,
  },
  glamour: {
    headerBg: (ctx, r, t, s) => {
      fill(ctx, r, grad(ctx, r, [[0, '#0D0003'], [0.5, '#1A0006'], [1, '#0D0003']], 0))
      // red carpet perspective at the bottom
      ctx.fillStyle = grad(ctx, { x: r.x, y: r.y + r.h * 0.7, w: r.w, h: r.h * 0.3 }, [[0, '#7F1D1D'], [1, '#B91C1C']], Math.PI / 2)
      ctx.beginPath()
      ctx.moveTo(r.x + r.w * 0.25, r.y + r.h * 0.72)
      ctx.lineTo(r.x + r.w * 0.75, r.y + r.h * 0.72)
      ctx.lineTo(r.x + r.w, r.y + r.h)
      ctx.lineTo(r.x, r.y + r.h)
      ctx.closePath()
      ctx.fill()
      // spotlights
      for (const ox of [0.3, 0.7]) {
        const x0 = r.x + r.w * ox + Math.sin(t * 0.9 + ox * 7) * r.w * 0.08
        const g = ctx.createRadialGradient(x0, r.y + r.h * 0.6, 0, x0, r.y + r.h * 0.6, r.h * 0.9)
        g.addColorStop(0, 'rgba(255,220,140,0.2)')
        g.addColorStop(1, 'rgba(255,220,140,0)')
        ctx.fillStyle = g
        ctx.fillRect(r.x, r.y, r.w, r.h)
      }
      sparkles(ctx, r, t, s, 22, '#F5C542', 3)
      fill(ctx, { x: r.x, y: r.y + r.h - 4 * s, w: r.w, h: 4 * s }, '#F5C542')
      if (HEADER_ART) {
        const { cx, cy, u } = iconSpot(r)
        ctx.save()
        ctx.translate(cx, cy)
        ctx.rotate(Math.sin(t) * 0.15)
        ctx.fillStyle = '#F5C542'
        star(ctx, 0, 0, u * 0.85)
        ctx.fill()
        ctx.restore()
      }
    },
    footerBg: (ctx, r, t, s) => {
      fill(ctx, r, '#120004')
      fill(ctx, { x: r.x, y: r.y, w: r.w, h: 3 * s }, '#F5C542')
      void t
    },
    title: '#FFFFFF',
    date: '#FDE68A',
    kickerBg: '#B91C1C',
    kickerFg: '#FFFFFF',
    badge: { text: 'বিনোদন', bg: '#F5C542', fg: '#1A0006', kind: 'pulse' },
    labelBg: '#F5C542',
    labelFg: '#1A0006',
    tickerFg: '#FFFFFF',
    accent: '#F5C542',
    rightRoom: 1.4,
  },
  agri: {
    headerBg: (ctx, r, t, s) => {
      fill(ctx, r, grad(ctx, r, [[0, '#14532D'], [1, '#166534']], -0.3))
      paddy(ctx, r, t, s, 'rgba(163,230,53,0.45)', 'rgba(250,204,21,0.7)')
      if (HEADER_ART) {
        const { cx, cy, u } = iconSpot(r)
        sunIcon(ctx, cx, cy - r.h * 0.08, u * 0.9, t, '#FACC15')
      }
    },
    footerBg: (ctx, r, t, s) => {
      fill(ctx, r, '#0F3D21')
      fill(ctx, { x: r.x, y: r.y, w: r.w, h: 4 * s }, '#FACC15')
      void t
    },
    title: '#FFFFFF',
    date: '#D9F99D',
    kickerBg: '#FACC15',
    kickerFg: '#14280F',
    badge: { text: 'কৃষি', bg: '#FACC15', fg: '#14280F', kind: 'tape' },
    labelBg: '#FACC15',
    labelFg: '#14280F',
    tickerFg: '#FFFFFF',
    accent: '#FACC15',
    edge: '#FACC15',
    rightRoom: 1.4,
  },
  religion: {
    headerBg: (ctx, r, t, s) => {
      fill(ctx, r, grad(ctx, r, [[0, '#0A2A3A'], [1, '#0B3B35']], 0.3))
      lattice(ctx, r, s, 'rgba(231,200,115,0.09)')
      glint(ctx, r, t, 8, 0.07)
      fill(ctx, { x: r.x, y: r.y + r.h - 5 * s, w: r.w, h: 5 * s }, '#E7C873')
      if (HEADER_ART) {
        const { cx, cy, u } = iconSpot(r)
        crescent(ctx, cx, cy, u, '#E7C873', '#0A2A3A')
        ctx.fillStyle = '#E7C873'
        star(ctx, cx + u * 0.45, cy - u * 0.35, u * 0.22 * (0.85 + 0.15 * Math.sin(t * 3)))
        ctx.fill()
      }
    },
    footerBg: (ctx, r, t, s) => {
      fill(ctx, r, '#082029')
      fill(ctx, { x: r.x, y: r.y, w: r.w, h: 3 * s }, '#E7C873')
      void t
    },
    title: '#FFFFFF',
    date: '#E7C873',
    kickerBg: 'rgba(231,200,115,0.18)',
    kickerFg: '#F3E2A9',
    labelBg: '#E7C873',
    labelFg: '#0A2A3A',
    tickerFg: '#FFFFFF',
    accent: '#E7C873',
    edge: '#E7C873',
    rightRoom: 1.4,
  },
}

// ---------------------------------------------------------------------------
// Scene helpers
// ---------------------------------------------------------------------------

const tall = (env: DrawEnv) => env.L.H > env.L.W * 1.1
const unit = (env: DrawEnv) => Math.min(env.L.W, env.L.H) / 1080

function black(ctx: Ctx, env: DrawEnv, a: number) {
  if (a <= 0) return
  ctx.fillStyle = `rgba(0,0,0,${clamp(a)})`
  ctx.fillRect(0, 0, env.L.W, env.L.H)
}

function logoPill(ctx: Ctx, env: DrawEnv, cx: number, cy: number, h: number, appear: number, plate = '#FFFFFF') {
  if (appear <= 0) return
  const lw = (env.logo.w / env.logo.h) * h
  const pad = h * 0.32
  ctx.save()
  ctx.translate(cx, cy)
  const sc = ease.outBack(clamp(appear))
  ctx.scale(sc, sc)
  ctx.shadowColor = 'rgba(0,0,0,0.25)'
  ctx.shadowBlur = h * 0.5
  fillRoundRect(ctx, -lw / 2 - pad, -h / 2 - pad, lw + pad * 2, h + pad * 2, h / 2 + pad, plate)
  ctx.shadowColor = 'transparent'
  ctx.drawImage(env.logo.color, -lw / 2, -h / 2, lw, h)
  ctx.restore()
}

/** Title lines centred at cy, fading up line by line from t0. */
function title(ctx: Ctx, env: DrawEnv, t: number, t0: number, cy: number, maxW: number, color: string, maxLines: number, big: number, weight = 700) {
  const s = unit(env)
  const text = env.introTitle.trim() || 'মিহা নিউজ'
  const fit = fitText(ctx, text, (px) => bodyFont(weight, px), maxW, maxLines, big * s, 40 * s, 1.25)
  const blockH = fit.lines.length * fit.lineHeight
  const y0 = cy - blockH / 2
  ctx.save()
  ctx.font = bodyFont(weight, fit.size)
  ctx.fillStyle = color
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  fit.lines.forEach((line, i) => {
    const p = ease.outCubic(win(t, t0 + i * 0.12, t0 + 0.45 + i * 0.12))
    ctx.globalAlpha = p
    ctx.fillText(line, env.L.W / 2, y0 + fit.lineHeight * (i + 0.5) + (1 - p) * fit.lineHeight * 0.5)
  })
  ctx.restore()
  return { top: y0, bottom: y0 + blockH }
}

/** Big display word ("নির্বাচন", "বাজেট"…), scaled in. */
function word(ctx: Ctx, env: DrawEnv, text: string, cx: number, cy: number, px: number, color: string, appear: number, shadow = 'rgba(0,0,0,0.35)') {
  if (appear <= 0) return 0
  ctx.save()
  ctx.font = dispFont(800, px)
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  const w = Math.min(ctx.measureText(text).width, env.L.W * 0.9)
  ctx.translate(cx, cy)
  const k = ease.outBack(clamp(appear))
  ctx.scale(k, k)
  ctx.globalAlpha = clamp(appear * 2)
  ctx.shadowColor = shadow
  ctx.shadowBlur = px * 0.25
  ctx.fillStyle = color
  ctx.fillText(text, 0, px * 0.06, env.L.W * 0.9)
  ctx.restore()
  return w
}

function footLine(ctx: Ctx, env: DrawEnv, t: number, t0: number, color: string, y?: number) {
  const s = unit(env)
  const txt = [env.dateText, env.website || 'meeha.news'].filter(Boolean).join('  •  ')
  ctx.save()
  ctx.globalAlpha = win(t, t0, t0 + 0.4)
  ctx.font = bodyFont(500, 30 * s)
  ctx.fillStyle = color
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText(txt, env.L.W / 2, y ?? env.L.H * (tall(env) ? 0.9 : 0.91), env.L.W * 0.9)
  ctx.restore()
}

function chip(ctx: Ctx, text: string, cx: number, cy: number, h: number, bg: string, fg: string, appear: number) {
  if (appear <= 0 || !text) return
  ctx.save()
  ctx.globalAlpha = clamp(appear)
  ctx.font = bodyFont(700, h * 0.56)
  const w = ctx.measureText(text).width + h * 0.9
  const k = ease.outBack(clamp(appear))
  ctx.translate(cx, cy)
  ctx.scale(k, k)
  fillRoundRect(ctx, -w / 2, -h / 2, w, h, h / 2, bg)
  ctx.fillStyle = fg
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText(text, 0, h * 0.04)
  ctx.restore()
}

const kickerOr = (env: DrawEnv, def: string) => env.kicker.trim() || def

// ---------------------------------------------------------------------------
// Intros
// ---------------------------------------------------------------------------

function introElection(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, grad(ctx, { x: 0, y: 0, w: W, h: H }, [[0, '#062A21'], [0.55, '#0B2A4A'], [1, '#081A33']], port ? Math.PI / 2.3 : 0.4))
  // rays from the box
  const cx = W / 2
  const by = H * (port ? 0.3 : 0.3)
  ctx.save()
  ctx.translate(cx, by)
  ctx.rotate(t * 0.15)
  for (let i = 0; i < 16; i++) {
    ctx.fillStyle = i % 2 ? 'rgba(242,194,48,0.05)' : 'rgba(18,161,80,0.06)'
    ctx.beginPath()
    ctx.moveTo(0, 0)
    ctx.arc(0, 0, Math.hypot(W, H), (i * TAU) / 16, ((i + 0.5) * TAU) / 16)
    ctx.closePath()
    ctx.fill()
  }
  ctx.restore()
  const u = (port ? 120 : 105) * s
  const appear = ease.outBack(win(t, 0.05, 0.5))
  ctx.save()
  ctx.translate(cx, by)
  ctx.scale(appear, appear)
  ctx.translate(-cx, -by)
  ballotIcon(ctx, cx, by, u, clamp(t - 0.2, 0, 0.9) * (2.4 / 1.9) + 0.0001, '#E5E7EB', '#12A150')
  ctx.restore()
  // stamp mark
  const st = win(t, 1.0, 1.15)
  if (st > 0) {
    ctx.save()
    ctx.translate(cx + u * 1.25, by - u * 0.9)
    ctx.rotate(-0.25)
    const k = lerp(1.8, 1, ease.outCubic(st))
    ctx.scale(k, k)
    ctx.globalAlpha = st
    ctx.strokeStyle = '#CC2A1F'
    ctx.lineWidth = 6 * s
    ctx.beginPath()
    ctx.arc(0, 0, u * 0.45, 0, TAU)
    ctx.stroke()
    ctx.font = dispFont(800, u * 0.28)
    ctx.fillStyle = '#CC2A1F'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText('ভোট', 0, u * 0.03)
    ctx.restore()
  }
  word(ctx, env, kickerOr(env, 'নির্বাচন'), cx, H * (port ? 0.46 : 0.5), (port ? 110 : 96) * s, '#F2C230', win(t, 0.6, 1.05))
  title(ctx, env, t, 1.25, H * (port ? 0.63 : 0.7), W * 0.84, '#FFFFFF', port ? 4 : 2, port ? 76 : 64)
  // results bar
  const rb = ease.outCubic(win(t, 0.9, 1.6))
  const bw = W * 0.7 * rb
  let x = cx - W * 0.35
  ;[0.42, 0.33, 0.25].forEach((p, i) => {
    fill(ctx, { x, y: H * (port ? 0.8 : 0.85), w: bw * p, h: 12 * s }, ['#12A150', '#F2C230', '#CC2A1F'][i])
    x += bw * p
  })
  footLine(ctx, env, t, 1.6, '#BBF7D0', H * (port ? 0.86 : 0.92))
  black(ctx, env, 1 - win(t, 0, 0.12))
  black(ctx, env, ease.inCubic(win(t, dur - 0.3, dur)))
}

function introBudget(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, grad(ctx, { x: 0, y: 0, w: W, h: H }, [[0, '#06122B'], [1, '#0F2E5C']], 0.5))
  // grid
  ctx.strokeStyle = 'rgba(255,255,255,0.05)'
  ctx.lineWidth = 1.5 * s
  for (let y = 0; y < H; y += 60 * s) {
    ctx.beginPath()
    ctx.moveTo(0, y)
    ctx.lineTo(W, y)
    ctx.stroke()
  }
  // rising bars across the bottom
  const n = port ? 9 : 14
  const bw = W / n
  for (let i = 0; i < n; i++) {
    const grow = ease.outCubic(win(t, 0.05 + i * 0.05, 0.7 + i * 0.05))
    const h = H * (0.12 + 0.3 * rnd(i + 4) + i * 0.012) * grow
    ctx.fillStyle = i % 3 === 2 ? 'rgba(245,158,11,0.35)' : 'rgba(52,211,153,0.28)'
    ctx.fillRect(i * bw + bw * 0.18, H - h, bw * 0.64, h)
  }
  // coin
  const cy = H * (port ? 0.27 : 0.27)
  const cr = (port ? 95 : 82) * s
  const spin = Math.cos(win(t, 0.1, 1.2) * Math.PI * 4)
  const ap = ease.outBack(win(t, 0.1, 0.55))
  ctx.save()
  ctx.translate(W / 2, cy)
  ctx.scale(Math.max(0.08, Math.abs(spin)) * ap, ap)
  ctx.fillStyle = '#F59E0B'
  ctx.beginPath()
  ctx.arc(0, 0, cr, 0, TAU)
  ctx.fill()
  ctx.fillStyle = '#FCD34D'
  ctx.beginPath()
  ctx.arc(0, 0, cr * 0.82, 0, TAU)
  ctx.fill()
  ctx.font = dispFont(800, cr * 1.1)
  ctx.fillStyle = '#92400E'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText('৳', 0, cr * 0.08)
  ctx.restore()
  word(ctx, env, kickerOr(env, 'বাজেট'), W / 2, H * (port ? 0.43 : 0.47), (port ? 110 : 92) * s, '#34D399', win(t, 0.55, 1))
  title(ctx, env, t, 1.15, H * (port ? 0.58 : 0.66), W * 0.84, '#FFFFFF', port ? 4 : 2, port ? 76 : 64)
  footLine(ctx, env, t, 1.6, '#A7F3D0', H * (port ? 0.72 : 0.84))
  black(ctx, env, 1 - win(t, 0, 0.12))
  black(ctx, env, ease.inCubic(win(t, dur - 0.3, dur)))
}

function introCourt(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  // the gavel hits at 0.55 s: a flash and a shake
  const hit = 0.55
  const shake = t > hit && t < hit + 0.35 ? Math.sin((t - hit) * 70) * (1 - (t - hit) / 0.35) * 8 * s : 0
  ctx.save()
  ctx.translate(shake, 0)
  fill(ctx, { x: -20 * s, y: 0, w: W + 40 * s, h: H }, grad(ctx, { x: 0, y: 0, w: W, h: H }, [[0, '#1A0508'], [0.5, '#4A1018'], [1, '#1A0508']], 0))
  ctx.fillStyle = 'rgba(212,175,55,0.06)'
  const colW = (port ? 70 : 90) * s
  for (let x = colW * 0.5; x < W; x += colW * 2.4) ctx.fillRect(x, 0, colW, H)
  const cx = W / 2
  const cy = H * (port ? 0.28 : 0.3)
  const u = (port ? 110 : 95) * s
  // gavel
  const swing = t < hit ? lerp(-1.1, 0, ease.inCubic(win(t, 0.05, hit))) : Math.sin((t - hit) * 12) * 0.08 * Math.max(0, 1 - (t - hit) * 2)
  ctx.save()
  ctx.translate(cx + u * 1.6, cy + u * 0.8)
  ctx.rotate(swing - 0.5)
  fillRoundRect(ctx, -u * 0.08, -u * 1.5, u * 0.16, u * 1.4, u * 0.05, '#8B5A2B')
  fillRoundRect(ctx, -u * 0.45, -u * 1.75, u * 0.9, u * 0.42, u * 0.1, '#A0522D')
  fillRoundRect(ctx, -u * 0.5, -u * 1.7, u * 0.1, u * 0.32, u * 0.03, '#D4AF37')
  fillRoundRect(ctx, u * 0.4, -u * 1.7, u * 0.1, u * 0.32, u * 0.03, '#D4AF37')
  ctx.restore()
  scalesIcon(ctx, cx - u * 0.4, cy, u, Math.sin(t * 2) * 0.1 * win(t, hit, hit + 0.3), '#D4AF37')
  ctx.restore()
  if (t > hit && t < hit + 0.25) {
    ctx.fillStyle = `rgba(255,240,200,${0.4 * (1 - (t - hit) / 0.25)})`
    ctx.fillRect(0, 0, W, H)
  }
  word(ctx, env, kickerOr(env, 'আদালত'), cx, H * (port ? 0.46 : 0.52), (port ? 104 : 90) * s, '#D4AF37', win(t, 0.7, 1.1))
  const box = title(ctx, env, t, 1.2, H * (port ? 0.62 : 0.71), W * 0.82, '#FFF7E6', port ? 4 : 2, port ? 74 : 62)
  const ln = ease.outCubic(win(t, 1.3, 1.8))
  fill(ctx, { x: cx - W * 0.2 * ln, y: box.bottom + 24 * s, w: W * 0.4 * ln, h: 4 * s }, '#D4AF37')
  footLine(ctx, env, t, 1.7, '#E9D8A6')
  black(ctx, env, 1 - win(t, 0, 0.1))
  black(ctx, env, ease.inCubic(win(t, dur - 0.3, dur)))
}

function introWeather(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, grad(ctx, { x: 0, y: 0, w: W, h: H }, [[0, '#0C4A8F'], [0.6, '#1E7BC8'], [1, '#7CC4F0']], Math.PI / 2))
  const u = (port ? 120 : 100) * s
  const sx = W * (port ? 0.62 : 0.6)
  const sy = H * (port ? 0.22 : 0.26)
  const sa = ease.outBack(win(t, 0.05, 0.6))
  ctx.save()
  ctx.translate(sx, sy)
  ctx.scale(sa, sa)
  ctx.translate(-sx, -sy)
  const glow = ctx.createRadialGradient(sx, sy, 0, sx, sy, u * 2.4)
  glow.addColorStop(0, 'rgba(255,230,120,0.45)')
  glow.addColorStop(1, 'rgba(255,230,120,0)')
  ctx.fillStyle = glow
  ctx.fillRect(sx - u * 2.4, sy - u * 2.4, u * 4.8, u * 4.8)
  sunIcon(ctx, sx, sy, u, t, '#FFD65A')
  ctx.restore()
  // clouds drift in from both sides
  const c1 = lerp(-W * 0.3, W * (port ? 0.38 : 0.42), ease.outCubic(win(t, 0.2, 1.0))) + Math.sin(t) * 6 * s
  const c2 = lerp(W * 1.3, W * (port ? 0.75 : 0.72), ease.outCubic(win(t, 0.35, 1.1))) - Math.sin(t * 0.8) * 6 * s
  cloud(ctx, c1, sy + u * 0.55, u * 1.1, 'rgba(255,255,255,0.95)')
  cloud(ctx, c2, sy + u * 0.95, u * 0.8, 'rgba(226,240,252,0.9)')
  // rain from the first cloud
  ctx.strokeStyle = 'rgba(255,255,255,0.65)'
  ctx.lineWidth = 3 * s
  ctx.lineCap = 'round'
  const rain = win(t, 0.9, 1.2)
  for (let i = 0; i < 14 && rain > 0; i++) {
    const x = c1 - u * 0.8 + (i / 13) * u * 1.6
    const ph = (t * 1.6 + rnd(i)) % 1
    const y = sy + u * 1.1 + ph * u * 1.4
    ctx.globalAlpha = rain * (1 - ph)
    ctx.beginPath()
    ctx.moveTo(x, y)
    ctx.lineTo(x - 6 * s, y + 22 * s)
    ctx.stroke()
  }
  ctx.globalAlpha = 1
  word(ctx, env, kickerOr(env, 'আবহাওয়া'), W / 2, H * (port ? 0.5 : 0.55), (port ? 104 : 88) * s, '#FFFFFF', win(t, 0.7, 1.1), 'rgba(12,74,143,0.6)')
  // lower panel with the headline
  const py = H * (port ? 0.58 : 0.65)
  const ph = H * (port ? 0.26 : 0.24)
  const pa = ease.outCubic(win(t, 1.0, 1.4))
  fillRoundRect(ctx, W * 0.06, py + (1 - pa) * 40 * s, W * 0.88, ph, 24 * s, `rgba(255,255,255,${0.92 * pa})`)
  title(ctx, env, t, 1.25, py + ph / 2, W * 0.78, '#0B2A4A', port ? 4 : 2, port ? 70 : 58)
  footLine(ctx, env, t, 1.7, '#FFFFFF', H * (port ? 0.9 : 0.94))
  black(ctx, env, 1 - win(t, 0, 0.12))
  black(ctx, env, ease.inCubic(win(t, dur - 0.3, dur)))
}

function introScoreboard(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, grad(ctx, { x: 0, y: 0, w: W, h: H }, [[0, '#020A06'], [1, '#063B22']], Math.PI / 2))
  // floodlights switching on one after another
  const lights = [0.12, 0.38, 0.62, 0.88]
  lights.forEach((lx, i) => {
    const on = win(t, 0.1 + i * 0.15, 0.2 + i * 0.15)
    if (on <= 0) return
    const x0 = W * lx
    const g = ctx.createRadialGradient(x0, 0, 0, x0, 0, H * 1.1)
    g.addColorStop(0, `rgba(255,255,235,${0.28 * on})`)
    g.addColorStop(1, 'rgba(255,255,235,0)')
    ctx.fillStyle = g
    ctx.beginPath()
    ctx.moveTo(x0 - 30 * s, 0)
    ctx.lineTo(x0 + 30 * s, 0)
    ctx.lineTo(x0 + W * 0.22, H)
    ctx.lineTo(x0 - W * 0.22, H)
    ctx.closePath()
    ctx.fill()
    fillRoundRect(ctx, x0 - 34 * s, 14 * s, 68 * s, 26 * s, 6 * s, `rgba(255,255,240,${on})`)
  })
  // pitch lines
  ctx.strokeStyle = 'rgba(255,255,255,0.12)'
  ctx.lineWidth = 3 * s
  ctx.beginPath()
  ctx.ellipse(W / 2, H * 0.9, W * 0.3, H * 0.08, 0, 0, TAU)
  ctx.stroke()
  // scoreboard flips in
  const bw = W * (port ? 0.88 : 0.74)
  const bh = H * (port ? 0.36 : 0.44)
  const bx = (W - bw) / 2
  const by = H * (port ? 0.3 : 0.24)
  const flip = ease.outCubic(win(t, 0.6, 1.15))
  ctx.save()
  ctx.translate(W / 2, by + bh / 2)
  ctx.scale(1, Math.max(0.02, flip))
  ctx.translate(-W / 2, -(by + bh / 2))
  fillRoundRect(ctx, bx, by, bw, bh, 18 * s, '#0B0F0D')
  ctx.strokeStyle = '#E11D48'
  ctx.lineWidth = 6 * s
  roundRect(ctx, bx, by, bw, bh, 18 * s)
  ctx.stroke()
  // dot-matrix texture
  ctx.fillStyle = 'rgba(255,255,255,0.035)'
  for (let y = by + 12 * s; y < by + bh; y += 12 * s) for (let x = bx + 12 * s; x < bx + bw; x += 12 * s) ctx.fillRect(x, y, 3 * s, 3 * s)
  fillRoundRect(ctx, bx + bw / 2 - 120 * s, by - 26 * s, 240 * s, 52 * s, 26 * s, '#E11D48')
  ctx.font = dispFont(800, 30 * s)
  ctx.fillStyle = '#FFFFFF'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText(kickerOr(env, 'খেলা'), W / 2, by + 2 * s, 220 * s)
  ctx.restore()
  if (flip > 0.9) title(ctx, env, t, 1.15, by + bh / 2 + 8 * s, bw * 0.86, '#FDE047', port ? 4 : 3, port ? 74 : 64, 700)
  footLine(ctx, env, t, 1.5, '#BBF7D0', by + bh + (port ? 120 : 70) * s)
  black(ctx, env, 1 - win(t, 0, 0.1))
  black(ctx, env, ease.inCubic(win(t, dur - 0.3, dur)))
}

function introTriBurst(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, '#0A1430')
  const cx = W / 2
  const cy = H * (port ? 0.34 : 0.36)
  const R = Math.hypot(W, H)
  // three expanding rings, each colour in turn
  for (let i = 0; i < 3; i++) {
    const p = ease.outCubic(win(t, 0.05 + i * 0.12, 1.1 + i * 0.12))
    if (p <= 0) continue
    ctx.strokeStyle = hexA(TRI[i], 0.9 * (1 - p * 0.6))
    ctx.lineWidth = (60 - i * 14) * s * (1 - p * 0.5)
    ctx.beginPath()
    ctx.arc(cx, cy, R * 0.55 * p, 0, TAU)
    ctx.stroke()
  }
  // confetti-like triangles drifting
  for (let i = 0; i < 26; i++) {
    const p = win(t, 0.2, 2.2)
    const a = rnd(i) * TAU
    const d = R * 0.12 + R * 0.4 * ease.outCubic(p) * (0.5 + rnd(i + 9))
    const x = cx + Math.cos(a) * d
    const y = cy + Math.sin(a) * d + p * 40 * s
    ctx.fillStyle = hexA(TRI[i % 3], 0.85 * (1 - p * 0.7))
    ctx.save()
    ctx.translate(x, y)
    ctx.rotate(t * 3 + i)
    ctx.fillRect(-7 * s, -3 * s, 14 * s, 6 * s)
    ctx.restore()
  }
  logoPill(ctx, env, cx, cy, (port ? 78 : 68) * s, win(t, 0.35, 0.8))
  triBar(ctx, { x: W * 0.2, y: cy + (port ? 110 : 95) * s, w: W * 0.6 * ease.outCubic(win(t, 0.7, 1.1)), h: 10 * s }, t, s, 60)
  chip(ctx, env.kicker.trim(), cx, cy + (port ? 165 : 145) * s, 54 * s, TRI[1], '#111111', win(t, 0.9, 1.2))
  title(ctx, env, t, 1.0, H * (port ? 0.64 : 0.72), W * 0.84, '#FFFFFF', port ? 4 : 2, port ? 80 : 66)
  footLine(ctx, env, t, 1.6, 'rgba(255,255,255,0.75)')
  black(ctx, env, 1 - win(t, 0, 0.1))
  black(ctx, env, ease.inCubic(win(t, dur - 0.3, dur)))
}

function introTicker(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, '#0B0F1A')
  const rows = port ? 9 : 6
  const rh = H / rows
  ctx.save()
  for (let i = 0; i < rows; i++) {
    const inP = ease.outCubic(win(t, 0.03 * i, 0.35 + 0.03 * i))
    const dir = i % 2 ? 1 : -1
    const y = i * rh
    const bg = i % 3 === 0 ? '#C8102E' : i % 3 === 1 ? '#111827' : '#1546B8'
    ctx.save()
    ctx.translate(dir * (1 - inP) * W, 0)
    fill(ctx, { x: 0, y: y + 2 * s, w: W, h: rh - 4 * s }, bg)
    ctx.font = dispFont(800, rh * 0.42)
    ctx.fillStyle = 'rgba(255,255,255,0.22)'
    ctx.textBaseline = 'middle'
    const txt = 'সর্বশেষ  •  ব্রেকিং  •  মিহা নিউজ  •  '
    const tw = ctx.measureText(txt).width
    const off = ((t * 220 * s * dir) % tw + tw) % tw
    for (let x = -tw + off; x < W; x += tw) ctx.fillText(txt, x, y + rh / 2)
    ctx.restore()
  }
  ctx.restore()
  // centre box
  const bw = W * (port ? 0.9 : 0.78)
  const bh = H * (port ? 0.34 : 0.42)
  const bx = (W - bw) / 2
  const by = (H - bh) / 2
  const bp = ease.outBack(win(t, 0.5, 0.95))
  ctx.save()
  ctx.translate(W / 2, H / 2)
  ctx.scale(bp, bp)
  ctx.translate(-W / 2, -H / 2)
  ctx.shadowColor = 'rgba(0,0,0,0.5)'
  ctx.shadowBlur = 40 * s
  fillRoundRect(ctx, bx, by, bw, bh, 16 * s, '#FFFFFF')
  ctx.shadowColor = 'transparent'
  fill(ctx, { x: bx, y: by, w: 16 * s, h: bh }, '#C8102E')
  ctx.restore()
  if (bp > 0.9) {
    chip(ctx, kickerOr(env, 'সর্বশেষ'), W / 2, by + 50 * s, 54 * s, '#C8102E', '#FFFFFF', win(t, 0.8, 1.05))
    title(ctx, env, t, 0.95, by + bh / 2 + 30 * s, bw * 0.84, '#0B1220', port ? 4 : 2, port ? 72 : 62)
  }
  black(ctx, env, 1 - win(t, 0, 0.08))
  black(ctx, env, ease.inCubic(win(t, dur - 0.3, dur)))
}

function introDocu(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, grad(ctx, { x: 0, y: 0, w: W, h: H }, [[0, '#0B0A08'], [0.5, '#1C1812'], [1, '#0B0A08']], 0.6))
  // a warm light leak drifting across
  const lx = lerp(-0.3, 1.2, win(t, 0, dur)) * W
  const g = ctx.createRadialGradient(lx, H * 0.3, 0, lx, H * 0.3, Math.max(W, H) * 0.6)
  g.addColorStop(0, 'rgba(255,170,90,0.22)')
  g.addColorStop(1, 'rgba(255,170,90,0)')
  ctx.fillStyle = g
  ctx.fillRect(0, 0, W, H)
  // film grain (deterministic per frame)
  const fr = Math.floor(t * 24)
  ctx.fillStyle = 'rgba(255,255,255,0.05)'
  for (let i = 0; i < 160; i++) ctx.fillRect(rnd(i + fr * 7) * W, rnd(i * 3 + fr) * H, 2 * s, 2 * s)
  // letterbox bars close in
  const bar = H * (port ? 0.16 : 0.12) * ease.inOutCubic(win(t, 0, 0.6))
  fill(ctx, { x: 0, y: 0, w: W, h: bar }, '#000')
  fill(ctx, { x: 0, y: H - bar, w: W, h: bar }, '#000')
  // kicker with a thin line, then the title, slowly
  const k = kickerOr(env, 'বিশেষ প্রামাণ্য প্রতিবেদন')
  ctx.save()
  ctx.globalAlpha = win(t, 0.5, 1.0)
  ctx.font = bodyFont(500, 30 * s)
  ctx.fillStyle = '#E8C48A'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  const cy = H * 0.4
  ctx.fillText(k, W / 2, cy, W * 0.8)
  const kw = Math.min(ctx.measureText(k).width, W * 0.8)
  const ln = ease.outCubic(win(t, 0.7, 1.3))
  ctx.fillRect(W / 2 - kw / 2 - 90 * s * ln, cy - 1 * s, 70 * s * ln, 2 * s)
  ctx.fillRect(W / 2 + kw / 2 + 20 * s, cy - 1 * s, 70 * s * ln, 2 * s)
  ctx.restore()
  title(ctx, env, t, 1.0, H * (port ? 0.53 : 0.56), W * 0.8, '#F5F0E6', port ? 4 : 2, port ? 74 : 64, 500)
  ctx.save()
  ctx.globalAlpha = win(t, 1.8, 2.3) * 0.85
  const lh = 34 * s
  const lw = (env.logo.w / env.logo.h) * lh
  ctx.drawImage(env.logo.white, W / 2 - lw / 2, H - bar - lh - 40 * s, lw, lh)
  ctx.restore()
  black(ctx, env, 1 - win(t, 0, 0.3))
  black(ctx, env, ease.inCubic(win(t, dur - 0.4, dur)))
}

function lantern(ctx: Ctx, x: number, y: number, u: number, t: number, i: number, color: string) {
  const sw = Math.sin(t * 1.5 + i) * 0.08
  ctx.save()
  ctx.translate(x, 0)
  ctx.rotate(sw)
  ctx.strokeStyle = 'rgba(231,200,115,0.6)'
  ctx.lineWidth = 2 * u * 0.02
  ctx.beginPath()
  ctx.moveTo(0, 0)
  ctx.lineTo(0, y - u * 0.5)
  ctx.stroke()
  const glow = ctx.createRadialGradient(0, y, 0, 0, y, u * 1.4)
  glow.addColorStop(0, hexA(color, 0.35))
  glow.addColorStop(1, hexA(color, 0))
  ctx.fillStyle = glow
  ctx.fillRect(-u * 1.4, y - u * 1.4, u * 2.8, u * 2.8)
  fillRoundRect(ctx, -u * 0.3, y - u * 0.5, u * 0.6, u * 0.12, u * 0.04, '#E7C873')
  ctx.fillStyle = color
  ctx.beginPath()
  ctx.ellipse(0, y, u * 0.34, u * 0.42, 0, 0, TAU)
  ctx.fill()
  ctx.fillStyle = 'rgba(255,255,255,0.35)'
  ctx.beginPath()
  ctx.ellipse(-u * 0.1, y - u * 0.1, u * 0.08, u * 0.2, 0, 0, TAU)
  ctx.fill()
  fillRoundRect(ctx, -u * 0.22, y + u * 0.38, u * 0.44, u * 0.1, u * 0.04, '#E7C873')
  ctx.restore()
}

function introEid(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, grad(ctx, { x: 0, y: 0, w: W, h: H }, [[0, '#061A2E'], [0.6, '#0B3B35'], [1, '#062A22']], Math.PI / 2))
  lattice(ctx, { x: 0, y: 0, w: W, h: H }, s * 1.6, 'rgba(231,200,115,0.05)')
  // twinkling stars
  for (let i = 0; i < 40; i++) {
    const a = 0.3 + 0.7 * Math.abs(Math.sin(t * (1 + rnd(i) * 2) + i))
    ctx.fillStyle = `rgba(255,245,210,${a * 0.8})`
    star(ctx, rnd(i + 3) * W, rnd(i + 11) * H * 0.55, (2 + rnd(i + 5) * 4) * s, 4, 0.35, 0)
    ctx.fill()
  }
  const u = (port ? 75 : 62) * s
  const drop = ease.outBack(win(t, 0.1, 0.8))
  const xs = port ? [0.14, 0.86, 0.32, 0.68] : [0.1, 0.9, 0.24, 0.76]
  const ys = port ? [0.18, 0.2, 0.1, 0.12] : [0.28, 0.3, 0.14, 0.16]
  xs.forEach((x, i) => lantern(ctx, W * x, H * ys[i] * drop, u * (i < 2 ? 1 : 0.75), t, i, i % 2 ? '#F59E0B' : '#F43F5E'))
  // moon
  const mx = W / 2
  const my = H * (port ? 0.28 : 0.3)
  const ma = ease.outCubic(win(t, 0.2, 0.9))
  ctx.save()
  ctx.globalAlpha = ma
  const glow = ctx.createRadialGradient(mx, my, 0, mx, my, u * 3)
  glow.addColorStop(0, 'rgba(231,200,115,0.35)')
  glow.addColorStop(1, 'rgba(231,200,115,0)')
  ctx.fillStyle = glow
  ctx.fillRect(mx - u * 3, my - u * 3, u * 6, u * 6)
  crescent(ctx, mx, my, u * 2, '#F3E2A9', '#061A2E')
  ctx.fillStyle = '#F3E2A9'
  star(ctx, mx + u * 0.95, my - u * 0.55, u * 0.38)
  ctx.fill()
  ctx.restore()
  word(ctx, env, kickerOr(env, 'উৎসবের শুভেচ্ছা'), W / 2, H * (port ? 0.47 : 0.54), (port ? 96 : 80) * s, '#E7C873', win(t, 0.8, 1.25))
  title(ctx, env, t, 1.3, H * (port ? 0.62 : 0.72), W * 0.82, '#FFFFFF', port ? 4 : 2, port ? 70 : 58)
  footLine(ctx, env, t, 1.8, '#E7C873')
  black(ctx, env, 1 - win(t, 0, 0.15))
  black(ctx, env, ease.inCubic(win(t, dur - 0.3, dur)))
}

function candle(ctx: Ctx, cx: number, base: number, u: number, t: number, light: number) {
  // glow
  const fy = base - u * 1.25
  const fl = 1 + 0.06 * Math.sin(t * 13) + 0.04 * Math.sin(t * 7.3)
  const glow = ctx.createRadialGradient(cx, fy, 0, cx, fy, u * 3.2 * light)
  glow.addColorStop(0, `rgba(255,200,120,${0.3 * light})`)
  glow.addColorStop(1, 'rgba(255,200,120,0)')
  ctx.fillStyle = glow
  ctx.fillRect(cx - u * 3.5, fy - u * 3.5, u * 7, u * 7)
  // body
  const g = ctx.createLinearGradient(cx - u * 0.35, 0, cx + u * 0.35, 0)
  g.addColorStop(0, '#D6D3D1')
  g.addColorStop(0.5, '#FAFAF9')
  g.addColorStop(1, '#A8A29E')
  fillRoundRect(ctx, cx - u * 0.35, base - u * 1.05, u * 0.7, u * 1.05, u * 0.08, g)
  ctx.fillStyle = '#292524'
  ctx.fillRect(cx - u * 0.02, base - u * 1.15, u * 0.04, u * 0.12)
  if (light > 0) {
    ctx.save()
    ctx.translate(cx, base - u * 1.12)
    ctx.scale(fl * light, fl)
    const f = ctx.createRadialGradient(0, -u * 0.18, 0, 0, -u * 0.18, u * 0.3)
    f.addColorStop(0, '#FFFBEB')
    f.addColorStop(0.5, '#FCD34D')
    f.addColorStop(1, 'rgba(245,158,11,0)')
    ctx.fillStyle = f
    ctx.beginPath()
    ctx.moveTo(0, -u * 0.5)
    ctx.quadraticCurveTo(u * 0.18, -u * 0.12, 0, 0)
    ctx.quadraticCurveTo(-u * 0.18, -u * 0.12, 0, -u * 0.5)
    ctx.fill()
    ctx.restore()
  }
}

function introMourn(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, '#050505')
  const u = (port ? 90 : 75) * s
  const base = H * (port ? 0.42 : 0.46)
  candle(ctx, W / 2, base, u, t, ease.outCubic(win(t, 0.3, 1.0)))
  ctx.save()
  ctx.globalAlpha = win(t, 0.8, 1.4)
  ctx.font = bodyFont(500, 34 * s)
  ctx.fillStyle = '#A3A3A3'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText(kickerOr(env, 'শোক ও স্মরণ'), W / 2, base + 70 * s, W * 0.8)
  ctx.restore()
  title(ctx, env, t, 1.2, H * (port ? 0.62 : 0.7), W * 0.8, '#F5F5F5', port ? 4 : 2, port ? 66 : 56, 500)
  footLine(ctx, env, t, 1.9, '#737373')
  black(ctx, env, 1 - win(t, 0, 0.5))
  black(ctx, env, ease.inCubic(win(t, dur - 0.5, dur)))
}

// ---------------------------------------------------------------------------
// Outros
// ---------------------------------------------------------------------------

const NETS = ['facebook', 'youtube', 'instagram', 'web'] as const

/** Social icon, plus a globe for the website (houseads has no web icon). */
function siteIcon(ctx: Ctx, net: string, cx: number, cy: number, r: number) {
  if (net !== 'web') return netIcon(ctx, net, cx, cy, r)
  ctx.save()
  ctx.fillStyle = '#0B2746'
  ctx.beginPath()
  ctx.arc(cx, cy, r, 0, TAU)
  ctx.fill()
  ctx.strokeStyle = '#FFFFFF'
  ctx.lineWidth = r * 0.09
  ctx.beginPath()
  ctx.arc(cx, cy, r * 0.62, 0, TAU)
  ctx.moveTo(cx - r * 0.62, cy)
  ctx.lineTo(cx + r * 0.62, cy)
  ctx.moveTo(cx, cy - r * 0.62)
  ctx.lineTo(cx, cy + r * 0.62)
  ctx.stroke()
  ctx.beginPath()
  ctx.ellipse(cx, cy, r * 0.28, r * 0.62, 0, 0, TAU)
  ctx.stroke()
  ctx.restore()
}
const NET_NAMES: Record<(typeof NETS)[number], string> = { facebook: 'ফেসবুক', youtube: 'ইউটিউব', instagram: 'ইনস্টাগ্রাম', web: 'ওয়েবসাইট' }

function outroTri(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, '#FFFFFF')
  // three bars slide away to reveal the card
  const open = ease.inOutCubic(win(t, 0.1, 0.8))
  const bh = H / 3
  TRI.forEach((c, i) => {
    const dir = i % 2 ? 1 : -1
    fill(ctx, { x: dir * open * W * 1.05, y: i * bh, w: W, h: bh + 1 }, c)
  })
  triBar(ctx, { x: 0, y: 0, w: W, h: 14 * s }, t, s, 40)
  triBar(ctx, { x: 0, y: H - 14 * s, w: W, h: 14 * s }, t, s, -40)
  const cy = H * (port ? 0.36 : 0.36)
  logoPill(ctx, env, W / 2, cy, (port ? 90 : 78) * s, win(t, 0.6, 1.05), '#F8FAFC')
  word(ctx, env, 'ফলো করুন', W / 2, cy + (port ? 170 : 150) * s, (port ? 90 : 76) * s, '#0B2746', win(t, 0.9, 1.3), 'rgba(0,0,0,0)')
  ctx.save()
  ctx.globalAlpha = win(t, 1.2, 1.6)
  ctx.font = bodyFont(500, 32 * s)
  ctx.fillStyle = '#565A66'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText('সত্য খবর, সবার আগে — প্রতিদিন', W / 2, cy + (port ? 250 : 220) * s, W * 0.9)
  ctx.restore()
  const iy = H * (port ? 0.74 : 0.8)
  const gap = (port ? 130 : 120) * s
  NETS.forEach((n, i) => {
    const a = ease.outBack(win(t, 1.3 + i * 0.1, 1.7 + i * 0.1))
    if (a <= 0) return
    ctx.save()
    ctx.translate(W / 2 + (i - 1.5) * gap, iy)
    ctx.scale(a, a)
    siteIcon(ctx, n, 0, 0, 40 * s)
    ctx.restore()
  })
  footLine(ctx, env, t, 1.8, '#0B2746', H * (port ? 0.85 : 0.92))
  black(ctx, env, ease.inCubic(win(t, dur - 0.35, dur)))
}

function outroWebsite(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, grad(ctx, { x: 0, y: 0, w: W, h: H }, [[0, NIL.navy], [1, NIL.royal]], port ? Math.PI / 2.4 : 0.45))
  const bw = W * (port ? 0.88 : 0.7)
  const bh = H * (port ? 0.42 : 0.56)
  const bx = (W - bw) / 2
  const by = H * (port ? 0.22 : 0.16)
  const a = ease.outBack(win(t, 0.05, 0.55))
  ctx.save()
  ctx.translate(W / 2, by + bh / 2)
  ctx.scale(a, a)
  ctx.translate(-W / 2, -(by + bh / 2))
  ctx.shadowColor = 'rgba(0,0,0,0.35)'
  ctx.shadowBlur = 50 * s
  fillRoundRect(ctx, bx, by, bw, bh, 22 * s, '#FFFFFF')
  ctx.shadowColor = 'transparent'
  // browser chrome
  fillRoundRect(ctx, bx, by, bw, 70 * s, 22 * s, '#E5E7EB')
  fill(ctx, { x: bx, y: by + 48 * s, w: bw, h: 22 * s }, '#E5E7EB')
  ;['#EF4444', '#F59E0B', '#22C55E'].forEach((c, i) => {
    ctx.fillStyle = c
    ctx.beginPath()
    ctx.arc(bx + 34 * s + i * 30 * s, by + 35 * s, 9 * s, 0, TAU)
    ctx.fill()
  })
  const ax = bx + 140 * s
  const aw = bw - 170 * s
  fillRoundRect(ctx, ax, by + 14 * s, aw, 42 * s, 21 * s, '#FFFFFF')
  const site = env.website || 'meeha.news'
  const n = Math.floor(win(t, 0.6, 1.5) * site.length)
  ctx.font = bodyFont(500, 26 * s)
  ctx.fillStyle = '#111827'
  ctx.textBaseline = 'middle'
  ctx.fillText(site.slice(0, n), ax + 22 * s, by + 36 * s)
  if (Math.floor(t * 2.5) % 2 === 0 && t < 2) {
    const cw = ctx.measureText(site.slice(0, n)).width
    ctx.fillRect(ax + 24 * s + cw, by + 22 * s, 2 * s, 28 * s)
  }
  // page content
  const page = win(t, 1.6, 2.0)
  ctx.globalAlpha = page
  const lh = Math.min(bh * 0.2, 80 * s)
  const lw = (env.logo.w / env.logo.h) * lh
  ctx.drawImage(env.logo.color, W / 2 - lw / 2, by + 110 * s, lw, lh)
  for (let i = 0; i < 3; i++) {
    fillRoundRect(ctx, bx + 50 * s, by + 130 * s + lh + i * 46 * s, (bw - 100 * s) * (1 - i * 0.18), 22 * s, 11 * s, i === 0 ? '#CBD5E1' : '#E5E7EB')
  }
  ctx.restore()
  // cursor clicks the address bar
  const cp = ease.inOutCubic(win(t, 1.9, 2.5))
  const cx = lerp(W * 0.8, W / 2, cp)
  const cyy = lerp(H * 0.9, by + bh * 0.6, cp)
  if (t > 1.9) {
    ctx.save()
    ctx.translate(cx, cyy)
    const press = t > 2.5 && t < 2.7 ? 0.85 : 1
    ctx.scale(press, press)
    ctx.fillStyle = '#FFFFFF'
    ctx.strokeStyle = '#111827'
    ctx.lineWidth = 3 * s
    ctx.beginPath()
    ctx.moveTo(0, 0)
    ctx.lineTo(0, 46 * s)
    ctx.lineTo(12 * s, 35 * s)
    ctx.lineTo(22 * s, 56 * s)
    ctx.lineTo(30 * s, 52 * s)
    ctx.lineTo(20 * s, 32 * s)
    ctx.lineTo(36 * s, 32 * s)
    ctx.closePath()
    ctx.fill()
    ctx.stroke()
    ctx.restore()
  }
  word(ctx, env, 'আরও খবর ওয়েবসাইটে', W / 2, by + bh + (port ? 110 : 80) * s, (port ? 70 : 58) * s, NIL.amber, win(t, 1.4, 1.8))
  black(ctx, env, 1 - win(t, 0, 0.12))
  black(ctx, env, ease.inCubic(win(t, dur - 0.35, dur)))
}

function outroNext(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, '#0B1220')
  // chevrons streaming to the right
  ctx.save()
  for (let i = 0; i < 10; i++) {
    const x = ((i * 0.12 + t * 0.35) % 1.2) * W - W * 0.1
    ctx.strokeStyle = `rgba(37,99,235,${0.12 + 0.08 * (i % 3)})`
    ctx.lineWidth = 26 * s
    ctx.lineJoin = 'miter'
    ctx.beginPath()
    ctx.moveTo(x, H * 0.2)
    ctx.lineTo(x + H * 0.15, H * 0.5)
    ctx.lineTo(x, H * 0.8)
    ctx.stroke()
  }
  ctx.restore()
  word(ctx, env, 'পরের খবর', W / 2, H * (port ? 0.22 : 0.2), (port ? 104 : 88) * s, '#FFFFFF', win(t, 0.1, 0.5))
  ctx.save()
  ctx.globalAlpha = win(t, 0.4, 0.8)
  ctx.font = bodyFont(500, 32 * s)
  ctx.fillStyle = '#CBD5E1'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText('দেখতে চোখ রাখুন মিহা নিউজে', W / 2, H * (port ? 0.29 : 0.3), W * 0.9)
  ctx.restore()
  // three "video" cards with play buttons
  const n = 3
  const cw = port ? W * 0.56 : W * 0.26
  const ch = cw * 9 / 16
  for (let i = 0; i < n; i++) {
    const a = ease.outBack(win(t, 0.6 + i * 0.15, 1.0 + i * 0.15))
    if (a <= 0) continue
    const x = port ? (W - cw) / 2 : W / 2 + (i - 1) * (cw + 40 * s) - cw / 2
    const y = port ? H * 0.34 + i * (ch + 24 * s) : H * 0.42
    ctx.save()
    ctx.translate(x + cw / 2, y + ch / 2)
    ctx.scale(a, a)
    fillRoundRect(ctx, -cw / 2, -ch / 2, cw, ch, 16 * s, grad(ctx, { x: -cw / 2, y: -ch / 2, w: cw, h: ch }, [[0, ['#1546B8', '#C8102E', '#0F766E'][i]], [1, '#111827']], 0.6))
    ctx.fillStyle = 'rgba(255,255,255,0.92)'
    ctx.beginPath()
    ctx.arc(0, 0, ch * 0.18, 0, TAU)
    ctx.fill()
    ctx.fillStyle = '#111827'
    ctx.beginPath()
    ctx.moveTo(-ch * 0.06, -ch * 0.09)
    ctx.lineTo(ch * 0.1, 0)
    ctx.lineTo(-ch * 0.06, ch * 0.09)
    ctx.closePath()
    ctx.fill()
    fillRoundRect(ctx, -cw / 2 + 18 * s, ch / 2 - 40 * s, cw * 0.55, 16 * s, 8 * s, 'rgba(255,255,255,0.55)')
    ctx.restore()
  }
  logoPill(ctx, env, W / 2, H * (port ? 0.93 : 0.84), 50 * s, win(t, 1.3, 1.7))
  black(ctx, env, 1 - win(t, 0, 0.12))
  black(ctx, env, ease.inCubic(win(t, dur - 0.35, dur)))
}

function outroSocialGrid(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, grad(ctx, { x: 0, y: 0, w: W, h: H }, [[0, '#06143A'], [1, NIL.royal]], 0.6))
  logoPill(ctx, env, W / 2, H * (port ? 0.16 : 0.16), (port ? 64 : 56) * s, win(t, 0.05, 0.45))
  word(ctx, env, 'আমাদের সাথে থাকুন', W / 2, H * (port ? 0.27 : 0.3), (port ? 76 : 64) * s, '#FFFFFF', win(t, 0.3, 0.7))
  const cols = 2
  const tw = port ? W * 0.4 : W * 0.24
  const th = port ? tw * 0.9 : tw * 0.52
  const gap = 26 * s
  const gx = W / 2 - tw - gap / 2
  const gy = H * (port ? 0.36 : 0.4)
  const colors: Record<string, string> = { facebook: '#1877F2', youtube: '#FF0000', instagram: '#C13584', web: '#0B2746' }
  NETS.forEach((n, i) => {
    const col = i % cols
    const row = Math.floor(i / cols)
    const x = gx + col * (tw + gap)
    const y = gy + row * (th + gap)
    const f = ease.outCubic(win(t, 0.6 + i * 0.15, 1.0 + i * 0.15))
    if (f <= 0) return
    ctx.save()
    ctx.translate(x + tw / 2, y + th / 2)
    ctx.scale(Math.max(0.02, f), 1)
    fillRoundRect(ctx, -tw / 2, -th / 2, tw, th, 22 * s, '#FFFFFF')
    fill(ctx, { x: -tw / 2, y: th / 2 - 10 * s, w: tw, h: 10 * s }, colors[n])
    const r = Math.min(th * 0.24, 46 * s)
    siteIcon(ctx, n, 0, -th * 0.12, r)
    ctx.font = bodyFont(700, Math.min(30 * s, th * 0.15))
    ctx.fillStyle = '#111827'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText(NET_NAMES[n], 0, th * 0.24, tw * 0.9)
    ctx.restore()
  })
  footLine(ctx, env, t, 1.5, '#C7D6F5', gy + 2 * th + gap + (port ? 90 : 60) * s)
  black(ctx, env, 1 - win(t, 0, 0.12))
  black(ctx, env, ease.inCubic(win(t, dur - 0.35, dur)))
}

function outroWave(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, '#F7F9FF')
  const rise = ease.outCubic(win(t, 0, 1.0))
  const layers: [string, number, number][] = [
    ['rgba(21,70,184,0.25)', 0.5, 1.1],
    ['rgba(2,90,178,0.6)', 0.6, 1.6],
    [NIL.navy, 0.7, 2.1],
  ]
  layers.forEach(([c, lvl, sp], i) => {
    const base = lerp(H * 1.05, H * lvl, rise)
    ctx.fillStyle = c
    ctx.beginPath()
    ctx.moveTo(0, H)
    for (let x = 0; x <= W; x += 10 * s) ctx.lineTo(x, base + Math.sin(x / (W * 0.18) + t * sp + i) * 24 * s)
    ctx.lineTo(W, H)
    ctx.closePath()
    ctx.fill()
  })
  word(ctx, env, 'ধন্যবাদ', W / 2, H * (port ? 0.26 : 0.24), (port ? 140 : 120) * s, NIL.navy, win(t, 0.4, 0.85), 'rgba(0,0,0,0)')
  ctx.save()
  ctx.globalAlpha = win(t, 0.7, 1.1)
  ctx.font = bodyFont(500, 36 * s)
  ctx.fillStyle = '#334155'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText('দেখার জন্য — লাইক, শেয়ার ও ফলো করুন', W / 2, H * (port ? 0.35 : 0.37), W * 0.9)
  ctx.restore()
  logoPill(ctx, env, W / 2, H * (port ? 0.8 : 0.84), (port ? 64 : 56) * s, win(t, 1.0, 1.4))
  black(ctx, env, ease.inCubic(win(t, dur - 0.35, dur)))
}

function outroSignoff(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, grad(ctx, { x: 0, y: 0, w: W, h: H }, [[0, '#0B1220'], [1, '#1E293B']], 0.5))
  // a studio desk light arc
  const g = ctx.createRadialGradient(W / 2, H * 1.1, 0, W / 2, H * 1.1, H * 0.9)
  g.addColorStop(0, 'rgba(37,99,235,0.35)')
  g.addColorStop(1, 'rgba(37,99,235,0)')
  ctx.fillStyle = g
  ctx.fillRect(0, 0, W, H)
  const cy = H * (port ? 0.3 : 0.3)
  logoPill(ctx, env, W / 2, cy, (port ? 72 : 62) * s, win(t, 0.1, 0.5))
  word(ctx, env, 'এই ছিল আজকের খবর', W / 2, cy + (port ? 170 : 150) * s, (port ? 76 : 66) * s, '#FFFFFF', win(t, 0.5, 0.9))
  ctx.save()
  ctx.globalAlpha = win(t, 0.9, 1.3)
  ctx.font = bodyFont(500, 34 * s)
  ctx.fillStyle = '#93C5FD'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText('আবার দেখা হবে পরের খবরে', W / 2, cy + (port ? 250 : 220) * s, W * 0.9)
  ctx.restore()
  // date plate
  const dp = ease.outCubic(win(t, 1.2, 1.6))
  const pw = Math.min(W * 0.7, 560 * s)
  const py = H * (port ? 0.56 : 0.75)
  fillRoundRect(ctx, W / 2 - (pw * dp) / 2, py, pw * dp, 64 * s, 32 * s, '#FFC20E')
  if (dp > 0.8) {
    ctx.font = bodyFont(700, 30 * s)
    ctx.fillStyle = '#1B1400'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText(env.dateText || (env.website || 'meeha.news'), W / 2, py + 34 * s, pw * 0.9)
  }
  footLine(ctx, env, t, 1.6, '#94A3B8', py + (port ? 140 : 110) * s)
  black(ctx, env, 1 - win(t, 0, 0.12))
  black(ctx, env, ease.inCubic(win(t, dur - 0.4, dur)))
}

function outroMourn(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = unit(env)
  const port = tall(env)
  fill(ctx, { x: 0, y: 0, w: W, h: H }, '#0A0A0A')
  const u = (port ? 70 : 58) * s
  candle(ctx, W / 2, H * (port ? 0.3 : 0.34), u, t, 1)
  word(ctx, env, 'গভীর শোক ও সমবেদনা', W / 2, H * (port ? 0.45 : 0.5), (port ? 72 : 62) * s, '#E5E5E5', win(t, 0.4, 0.9), 'rgba(0,0,0,0)')
  ctx.save()
  ctx.globalAlpha = win(t, 0.8, 1.3)
  ctx.font = bodyFont(500, 32 * s)
  ctx.fillStyle = '#A3A3A3'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText('শোকসন্তপ্ত পরিবারের প্রতি — মিহা নিউজ পরিবার', W / 2, H * (port ? 0.52 : 0.59), W * 0.9)
  ctx.restore()
  ctx.save()
  ctx.globalAlpha = win(t, 1.2, 1.7) * 0.85
  const lh = 44 * s
  const lw = (env.logo.w / env.logo.h) * lh
  ctx.drawImage(env.logo.white, W / 2 - lw / 2, H * (port ? 0.74 : 0.74), lw, lh)
  ctx.restore()
  footLine(ctx, env, t, 1.5, '#737373')
  black(ctx, env, 1 - win(t, 0, 0.4))
  black(ctx, env, ease.inCubic(win(t, dur - 0.5, dur)))
}

const PACK_SCENES: Record<PackIntroId | PackOutroId, (ctx: Ctx, env: DrawEnv, t: number, dur: number) => void> = {
  'i-election': introElection,
  'i-budget': introBudget,
  'i-court': introCourt,
  'i-weather': introWeather,
  'i-scoreboard': introScoreboard,
  'i-tri-burst': introTriBurst,
  'i-ticker': introTicker,
  'i-docu': introDocu,
  'i-eid': introEid,
  'i-mourn': introMourn,
  'o-tri': outroTri,
  'o-website': outroWebsite,
  'o-next': outroNext,
  'o-social-grid': outroSocialGrid,
  'o-wave': outroWave,
  'o-signoff': outroSignoff,
  'o-mourn': outroMourn,
}

/** Draws a pack scene; unknown ids paint nothing. */
export function drawPackScene(ctx: Ctx, env: DrawEnv, id: SceneId, t: number, dur: number) {
  const f = PACK_SCENES[id as PackIntroId]
  if (f) f(ctx, env, t, dur)
}

/** When a pack intro looks finished (its cover frame and thumbnail). */
export function packSettledAt(id: SceneId): number | undefined {
  const at: Partial<Record<string, number>> = { 'i-docu': 2.6, 'i-mourn': 2.6, 'i-eid': 2.4, 'i-election': 2.3, 'i-budget': 2.3, 'i-court': 2.3, 'i-weather': 2.3, 'i-scoreboard': 2.2, 'i-tri-burst': 2.2, 'i-ticker': 2 }
  return at[id]
}

/** Sound cues of the pack scenes: [seconds from the start, sound, gain]. */
export const PACK_SCENE_SFX: Partial<Record<SceneId, [number, SfxId, number][]>> = {
  'i-election': [[0.05, 'whoosh-air', 0.5], [0.65, 'paper', 0.5], [1.0, 'stamp', 0.7], [1.3, 'chime', 0.4]],
  'i-budget': [[0.05, 'swoosh-up', 0.5], [0.4, 'cash', 0.5], [0.6, 'whoosh', 0.4], [1.2, 'chime', 0.4]],
  'i-court': [[0.1, 'whoosh-deep', 0.5], [0.55, 'stamp', 0.9], [0.56, 'boom', 0.45], [1.2, 'chime', 0.35]],
  'i-weather': [[0.05, 'whoosh-air', 0.6], [0.9, 'rain', 0.25], [1.2, 'chime', 0.4]],
  'i-scoreboard': [[0.1, 'impact', 0.3], [0.6, 'whoosh-fast', 0.6], [0.9, 'crowd', 0.35], [1.15, 'sting', 0.4]],
  'i-tri-burst': [[0.05, 'bassdrop', 0.5], [0.35, 'pop', 0.6], [0.4, 'sparkle', 0.45], [1.0, 'ident', 0.5]],
  'i-ticker': [[0, 'whoosh-fast', 0.6], [0.5, 'impact', 0.45], [0.9, 'ping', 0.5]],
  'i-docu': [[0, 'whoosh-deep', 0.4], [0.5, 'riser', 0.25], [1.0, 'boom', 0.4]],
  'i-eid': [[0.1, 'magic', 0.5], [0.8, 'sparkle', 0.45], [1.3, 'chime', 0.45]],
  'i-mourn': [[0.3, 'whoosh-air', 0.25]],
  'o-tri': [[0.1, 'whoosh', 0.55], [0.6, 'pop', 0.5], [1.3, 'notify', 0.4]],
  'o-website': [[0.05, 'pop', 0.5], [0.6, 'typing', 0.4], [2.5, 'pop', 0.45], [1.6, 'chime', 0.4]],
  'o-next': [[0.05, 'whoosh-fast', 0.5], [0.6, 'swoosh-up', 0.4], [1.3, 'ping', 0.4]],
  'o-social-grid': [[0.05, 'ident', 0.45], [0.6, 'paper', 0.4], [0.9, 'paper', 0.35], [1.2, 'ping', 0.4]],
  'o-wave': [[0, 'whoosh-air', 0.55], [0.45, 'chime', 0.45]],
  'o-signoff': [[0.1, 'ident', 0.5]],
  'o-mourn': [[0.3, 'whoosh-air', 0.2]],
}

// ---------------------------------------------------------------------------
// Lower thirds (name plates)
// ---------------------------------------------------------------------------

const LT_IDS = new Set<string>(['lt-tri', 'lt-glass', 'lt-nil', 'lt-breaking', 'lt-minimal', 'lt-split', 'lt-tv', 'lt-sports', 'lt-gold', 'lt-reel', 'lt-mourn'])
export const isLowerThird = (style: CaptionStyle): style is LowerThirdId => LT_IDS.has(style)

/**
 * Draws a name plate in one of the pack designs; returns the height it
 * takes (so captions at the same position stack). Slides in over ~0.5 s and
 * leaves in the last 0.45 s, like the classic name plate.
 */
export function drawLowerThird(ctx: Ctx, env: DrawEnv, c: Caption, local: number, dur: number, accent: string, stack: number, logoGap: number): number {
  const style = c.style as LowerThirdId
  const v = env.L.video
  const s = (Math.min(v.w, v.h) / 1080) * env.L.k * (style === 'lt-reel' ? 1.15 : 1)
  const inP = win(local, 0, 0.6)
  const outP = win(local, dur - 0.45, dur)
  if (outP >= 1) return 0
  const close = ease.inCubic(outP)
  const top = c.pos === 'tl' || c.pos === 'tr'
  const right = c.pos === 'br' || c.pos === 'tr'
  const centre = c.pos === 'bc'
  const margin = Math.max(40 * s, Math.min(v.w, v.h) * 0.045)
  const maxW = v.w * (centre ? 0.88 : 0.8)
  const name = c.text
  const role = c.sub.trim()
  const namePx = 40 * s
  const rolePx = 27 * s
  ctx.save()
  ctx.font = bodyFont(700, namePx)
  const nW = Math.min(maxW - 60 * s, ctx.measureText(name).width)
  ctx.font = bodyFont(500, rolePx)
  const rW = role ? Math.min(maxW - 60 * s, ctx.measureText(role).width) : 0
  // box size per design
  const padX = 28 * s
  let w = Math.max(nW, rW) + padX * 2
  let h = 64 * s + (role ? 44 * s : 0)
  let lead = 0
  if (style === 'lt-split') {
    w = Math.min(maxW, nW + rW + padX * 4)
    h = 70 * s
  }
  if (style === 'lt-tv') {
    lead = h
    w += lead
  }
  if (style === 'lt-reel') {
    h += 12 * s
    w += 20 * s
  }
  w = Math.min(w, maxW)
  const x = right ? v.x + v.w - margin - w : centre ? v.x + (v.w - w) / 2 : v.x + margin
  const y = top ? v.y + margin + logoGap + stack : v.y + v.h - Math.max(60 * s, v.h * 0.08) - h - stack - (right || !centre ? 0 : 0)
  const a = ease.outCubic(win(inP, 0, 0.7)) * (1 - close)
  const reveal = ease.outCubic(win(inP, 0.05, 0.75)) * (1 - close)

  const textAt = (nx: number, ny: number, nc: string, rx: number, ry: number, rc: string, maxText: number) => {
    ctx.textBaseline = 'middle'
    ctx.font = bodyFont(700, namePx)
    ctx.fillStyle = nc
    ctx.fillText(name, nx, ny, maxText)
    if (role) {
      ctx.font = bodyFont(500, rolePx)
      ctx.fillStyle = rc
      ctx.fillText(role, rx, ry, maxText)
    }
  }
  const clipReveal = (cx0: number, cw: number) => {
    ctx.beginPath()
    if (right) ctx.rect(cx0 + cw * (1 - reveal), y - 20 * s, cw * reveal + 1, h + 40 * s)
    else ctx.rect(cx0, y - 20 * s, cw * reveal, h + 40 * s)
    ctx.clip()
  }
  const nameY = y + 34 * s
  const roleY = y + 64 * s + 20 * s
  const tx = x + padX + lead

  switch (style) {
    case 'lt-tri': {
      clipReveal(x, w)
      ctx.shadowColor = 'rgba(0,0,0,0.25)'
      ctx.shadowBlur = 14 * s
      fill(ctx, { x, y, w, h }, '#FFFFFF')
      ctx.shadowColor = 'transparent'
      triBar(ctx, { x, y: y + h - 8 * s, w, h: 8 * s }, local, s, 50)
      textAt(tx, nameY, '#0B2746', tx, roleY - 4 * s, '#475569', w - padX * 2)
      break
    }
    case 'lt-nil': {
      clipReveal(x, w + 20 * s)
      fillRoundRect(ctx, x, y, w, 64 * s, 10 * s, grad(ctx, { x, y, w, h: 64 * s }, [[0, NIL.navy], [1, NIL.royal]], 0))
      if (role) {
        ctx.font = bodyFont(600, rolePx)
        const cw = Math.min(w, rW + padX * 1.6)
        fillRoundRect(ctx, x + 16 * s, y + 64 * s + 6 * s, cw, 38 * s, 19 * s, NIL.amber)
        ctx.fillStyle = '#1B1400'
        ctx.textBaseline = 'middle'
        ctx.fillText(role, x + 16 * s + padX * 0.8, y + 64 * s + 26 * s, cw - padX * 1.6)
      }
      ctx.font = bodyFont(700, namePx)
      ctx.fillStyle = '#FFFFFF'
      ctx.textBaseline = 'middle'
      ctx.fillText(name, tx, nameY, w - padX * 2)
      fill(ctx, { x, y: y + 60 * s, w, h: 4 * s }, NIL.amber)
      break
    }
    case 'lt-glass': {
      ctx.globalAlpha = a
      ctx.translate(0, (1 - a) * 18 * s)
      fillRoundRect(ctx, x, y, w, h, 18 * s, 'rgba(15,23,42,0.55)')
      ctx.strokeStyle = 'rgba(255,255,255,0.28)'
      ctx.lineWidth = 1.5 * s
      roundRect(ctx, x, y, w, h, 18 * s)
      ctx.stroke()
      const g = ctx.createLinearGradient(x, y, x, y + h)
      g.addColorStop(0, 'rgba(255,255,255,0.16)')
      g.addColorStop(0.5, 'rgba(255,255,255,0)')
      fillRoundRect(ctx, x, y, w, h, 18 * s, g)
      fillRoundRect(ctx, x + 12 * s, y + h * 0.2, 5 * s, h * 0.6, 3 * s, '#38BDF8')
      textAt(tx, nameY, '#FFFFFF', tx, roleY - 4 * s, '#BAE6FD', w - padX * 2)
      break
    }
    case 'lt-tv': {
      clipReveal(x, w)
      fill(ctx, { x, y, w: lead, h }, '#FFFFFF')
      const lh = lead * 0.62
      const markW = env.logo.markW * (lh / env.logo.h)
      ctx.drawImage(env.logo.color, 0, 0, env.logo.markW, env.logo.h, x + (lead - markW) / 2, y + (h - lh) / 2, markW, lh)
      fill(ctx, { x: x + lead, y, w: w - lead, h: 64 * s }, BRAND.blue)
      if (role) fill(ctx, { x: x + lead, y: y + 64 * s, w: w - lead, h: h - 64 * s }, '#0B2746')
      fill(ctx, { x: x + lead, y: y + 62 * s, w: w - lead, h: 3 * s }, BRAND.red)
      textAt(tx, nameY, '#FFFFFF', tx, roleY - 2 * s, '#CFE3FA', w - lead - padX * 2)
      break
    }
    case 'lt-breaking': {
      clipReveal(x, w)
      fill(ctx, { x, y, w, h: 64 * s }, '#C8102E')
      if (role) fill(ctx, { x, y: y + 64 * s, w, h: h - 64 * s }, '#111827')
      const p = (local % 2.5) / 2.5
      const gx = x + w * p * 1.4 - w * 0.2
      const g = ctx.createLinearGradient(gx - 60 * s, 0, gx + 60 * s, 0)
      g.addColorStop(0, 'rgba(255,255,255,0)')
      g.addColorStop(0.5, 'rgba(255,255,255,0.3)')
      g.addColorStop(1, 'rgba(255,255,255,0)')
      ctx.fillStyle = g
      ctx.fillRect(x, y, w, 64 * s)
      textAt(tx, nameY, '#FFFFFF', tx, roleY - 2 * s, '#F1F5F9', w - padX * 2)
      break
    }
    case 'lt-split': {
      clipReveal(x, w)
      ctx.font = bodyFont(700, namePx)
      const left = Math.min(w * 0.62, nW + padX * 2)
      fill(ctx, { x, y, w: left, h }, '#111827')
      fill(ctx, { x: x + left, y, w: w - left, h }, '#F59E0B')
      ctx.fillStyle = '#FFFFFF'
      ctx.textBaseline = 'middle'
      ctx.fillText(name, x + padX, y + h / 2 + 2 * s, left - padX * 2)
      if (role) {
        ctx.font = bodyFont(600, rolePx)
        ctx.fillStyle = '#111827'
        ctx.fillText(role, x + left + padX, y + h / 2 + 2 * s, w - left - padX * 2)
      }
      break
    }
    case 'lt-minimal': {
      ctx.globalAlpha = a
      ctx.shadowColor = 'rgba(0,0,0,0.75)'
      ctx.shadowBlur = 10 * s
      const lw = (w - padX) * reveal
      fill(ctx, { x: right ? x + w - lw : x, y: y + 64 * s, w: lw, h: 3 * s }, '#FFFFFF')
      textAt(x + (right ? padX : 0), nameY, '#FFFFFF', x + (right ? padX : 0), roleY - 2 * s, 'rgba(255,255,255,0.85)', w - padX)
      break
    }
    case 'lt-sports': {
      const sk = 24 * s
      const off = (1 - reveal) * (right ? 1 : -1) * (w + 60 * s)
      ctx.translate(off, 0)
      ctx.globalAlpha = clamp(reveal * 1.5)
      const para = (px: number, py: number, pw: number, ph: number, col: string) => {
        ctx.fillStyle = col
        ctx.beginPath()
        ctx.moveTo(px + sk, py)
        ctx.lineTo(px + pw + sk, py)
        ctx.lineTo(px + pw, py + ph)
        ctx.lineTo(px, py + ph)
        ctx.closePath()
        ctx.fill()
      }
      para(x, y, w, 64 * s, '#065F46')
      if (role) para(x - sk * 0.6, y + 64 * s, w, h - 64 * s, '#A3E635')
      para(x - 20 * s, y, 12 * s, 64 * s, '#A3E635')
      textAt(tx + 6 * s, nameY, '#FFFFFF', tx - 4 * s, roleY - 2 * s, '#14280F', w - padX * 2)
      break
    }
    case 'lt-gold': {
      clipReveal(x, w)
      fillRoundRect(ctx, x, y, w, h, 6 * s, 'rgba(11,9,5,0.88)')
      ctx.strokeStyle = '#D6B45E'
      ctx.lineWidth = 2 * s
      roundRect(ctx, x + 6 * s, y + 6 * s, w - 12 * s, h - 12 * s, 4 * s)
      ctx.stroke()
      const p = (local % 3) / 3
      const gx = x + w * p
      const g = ctx.createLinearGradient(gx - 50 * s, 0, gx + 50 * s, 0)
      g.addColorStop(0, 'rgba(255,240,200,0)')
      g.addColorStop(0.5, 'rgba(255,240,200,0.25)')
      g.addColorStop(1, 'rgba(255,240,200,0)')
      ctx.fillStyle = g
      ctx.fillRect(x, y, w, h)
      textAt(tx, nameY + 2 * s, '#F5E6B8', tx, roleY - 4 * s, '#D6B45E', w - padX * 2)
      break
    }
    case 'lt-reel': {
      const k = ease.outBack(win(inP, 0, 0.6)) * (1 - close)
      ctx.translate(x + w / 2, y + h / 2)
      ctx.scale(k, k)
      ctx.translate(-(x + w / 2), -(y + h / 2))
      ctx.shadowColor = 'rgba(0,0,0,0.35)'
      ctx.shadowBlur = 18 * s
      fillRoundRect(ctx, x, y, w, h, 26 * s, '#FFC20E')
      ctx.shadowColor = 'transparent'
      ctx.textBaseline = 'middle'
      ctx.font = bodyFont(700, namePx)
      ctx.fillStyle = '#111111'
      ctx.fillText(name, x + padX + 10 * s, y + 40 * s, w - padX * 2 - 20 * s)
      if (role) {
        ctx.font = bodyFont(600, rolePx)
        ctx.fillStyle = '#3F3200'
        ctx.fillText(role, x + padX + 10 * s, y + 88 * s, w - padX * 2 - 20 * s)
      }
      break
    }
    case 'lt-mourn': {
      ctx.globalAlpha = a
      fill(ctx, { x, y, w, h }, 'rgba(0,0,0,0.82)')
      fill(ctx, { x, y, w: 6 * s, h }, '#9CA3AF')
      // black ribbon
      ctx.save()
      ctx.translate(right ? x + 24 * s : x + w - 24 * s, y + 18 * s)
      ctx.fillStyle = '#E5E7EB'
      ctx.beginPath()
      ctx.moveTo(0, -8 * s)
      ctx.lineTo(-9 * s, 14 * s)
      ctx.lineTo(-3 * s, 14 * s)
      ctx.lineTo(0, 4 * s)
      ctx.lineTo(3 * s, 14 * s)
      ctx.lineTo(9 * s, 14 * s)
      ctx.closePath()
      ctx.fill()
      ctx.restore()
      textAt(tx, nameY, '#F5F5F5', tx, roleY - 4 * s, '#A3A3A3', w - padX * 2 - 30 * s)
      break
    }
  }
  ctx.restore()
  void accent
  return h + 16 * s
}

// ---------------------------------------------------------------------------
// Stingers (between segments)
// ---------------------------------------------------------------------------

const STINGERS = new Set<string>(['st-tri', 'st-logo', 'st-nil', 'st-breaking', 'st-glitch', 'st-ink', 'st-shutter'])
export const isStinger = (id: string): id is StingerId => STINGERS.has(id)

/**
 * Paints a stinger over `r`. `p` runs 0 → 1; the picture underneath
 * changes at 0.5, where the stinger covers it completely.
 */
export function drawStinger(ctx: Ctx, id: StingerId, p: number, r: Rect, s: number, logo?: LogoAsset) {
  const cover = 1 - Math.abs(2 * p - 1)
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  const cx = r.x + r.w / 2
  const cy = r.y + r.h / 2
  const drawLogo = (h: number, a: number, white = false) => {
    if (!logo || a <= 0) return
    const lw = (logo.w / logo.h) * h
    ctx.save()
    ctx.globalAlpha = a
    ctx.drawImage(white ? logo.white : logo.color, cx - lw / 2, cy - h / 2, lw, h)
    ctx.restore()
  }
  if (id === 'st-tri') {
    // three slanted bars sweep across in a row; the middle one covers all at 0.5
    const slant = r.h * 0.45
    const bw = r.w + slant * 2.5
    TRI.forEach((c, i) => {
      const q = clamp((p - 0.5) * 1.25 + 0.5 + (2 - i) * 0.06)
      const left = lerp(r.x - bw - slant - i * 40 * s, r.x + r.w + slant, ease.inOutCubic(q))
      ctx.fillStyle = c
      ctx.beginPath()
      ctx.moveTo(left, r.y + r.h)
      ctx.lineTo(left + bw, r.y + r.h)
      ctx.lineTo(left + bw + slant, r.y)
      ctx.lineTo(left + slant, r.y)
      ctx.closePath()
      ctx.fill()
    })
  } else if (id === 'st-logo') {
    const k = ease.inOutCubic(clamp(cover * 1.4))
    ctx.fillStyle = BRAND.blue
    ctx.beginPath()
    ctx.arc(cx, cy, Math.hypot(r.w, r.h) * 0.55 * k, 0, TAU)
    ctx.fill()
    const la = win(cover, 0.6, 0.85)
    if (la > 0) {
      const h = Math.min(r.h * 0.12, 90 * s)
      const lw = logo ? (logo.w / logo.h) * h : h * 4
      fillRoundRect(ctx, cx - lw / 2 - h * 0.4, cy - h * 0.8, lw + h * 0.8, h * 1.6, h * 0.8, `rgba(255,255,255,${la})`)
      drawLogo(h, la)
    }
  } else if (id === 'st-nil') {
    const k = ease.inOutCubic(cover)
    const half = r.w / 2
    fill(ctx, { x: r.x - half + half * k, y: r.y, w: half, h: r.h }, NIL.navy)
    fill(ctx, { x: r.x + r.w - half * k, y: r.y, w: half, h: r.h }, NIL.royal)
    fill(ctx, { x: r.x + half * k - 8 * s, y: r.y, w: 8 * s, h: r.h }, NIL.amber)
    fill(ctx, { x: r.x + r.w - half * k, y: r.y, w: 8 * s, h: r.h }, NIL.amber)
    if (cover > 0.85) drawLogo(Math.min(r.h * 0.09, 70 * s), win(cover, 0.85, 1), true)
  } else if (id === 'st-breaking') {
    ctx.fillStyle = `rgba(200,16,46,${ease.inOutCubic(clamp(cover * 1.3))})`
    ctx.fillRect(r.x, r.y, r.w, r.h)
    if (cover > 0.5) {
      const px = Math.min(r.h * 0.16, 120 * s)
      ctx.font = dispFont(800, px)
      ctx.fillStyle = `rgba(255,255,255,${win(cover, 0.5, 0.8)})`
      ctx.textAlign = 'center'
      ctx.textBaseline = 'middle'
      const k = 1 + (1 - cover) * 0.3
      ctx.translate(cx, cy)
      ctx.scale(k, k)
      ctx.fillText('সর্বশেষ', 0, px * 0.06, r.w * 0.9)
    }
    ctx.fillStyle = `rgba(255,255,255,${Math.pow(cover, 10) * 0.18})`
    ctx.fillRect(r.x - r.w, r.y - r.h, r.w * 3, r.h * 3)
  } else if (id === 'st-glitch') {
    const fr = Math.floor(p * 30)
    const bands = 14
    const bh = r.h / bands
    for (let i = 0; i < bands; i++) {
      const on = rnd(i * 13 + fr) < 0.25 + cover * 0.75
      if (!on) continue
      const off = (rnd(i + fr * 3) - 0.5) * r.w * 0.2 * cover
      const col = ['rgba(255,0,80,0.55)', 'rgba(0,220,255,0.55)', 'rgba(10,10,20,0.95)'][(i + fr) % 3]
      ctx.fillStyle = col
      ctx.fillRect(r.x + off, r.y + i * bh, r.w, bh * (0.6 + rnd(i + fr) * 0.6))
    }
    if (cover > 0.7) {
      ctx.fillStyle = `rgba(10,10,20,${win(cover, 0.7, 0.95)})`
      ctx.fillRect(r.x, r.y, r.w, r.h)
      drawLogo(Math.min(r.h * 0.09, 70 * s), win(cover, 0.8, 1), true)
    }
  } else if (id === 'st-ink') {
    const R = Math.hypot(r.w, r.h)
    const blobs: [number, number, string][] = [
      [0.2, 0.3, BRAND.red],
      [0.8, 0.7, BRAND.blue],
      [0.5, 0.5, NIL.navy],
    ]
    blobs.forEach(([bx, by, c], i) => {
      const k = ease.inOutCubic(clamp(cover * 1.4 - i * 0.15))
      if (k <= 0) return
      ctx.fillStyle = c
      ctx.beginPath()
      const rad = R * 0.62 * k
      for (let a = 0; a <= 24; a++) {
        const ang = (a / 24) * TAU
        const wob = 1 + 0.06 * Math.sin(ang * 5 + i * 2)
        const px = r.x + r.w * bx + Math.cos(ang) * rad * wob
        const py = r.y + r.h * by + Math.sin(ang) * rad * wob
        if (a === 0) ctx.moveTo(px, py)
        else ctx.lineTo(px, py)
      }
      ctx.closePath()
      ctx.fill()
    })
    if (cover > 0.85) drawLogo(Math.min(r.h * 0.09, 70 * s), win(cover, 0.85, 1), true)
  } else if (id === 'st-shutter') {
    const n = 8
    const bh = r.h / n
    for (let i = 0; i < n; i++) {
      const k = ease.inOutCubic(clamp(cover * 1.3 - (i % 2) * 0.12))
      ctx.fillStyle = i % 2 ? NIL.navy : '#0B2746'
      ctx.fillRect(r.x, r.y + i * bh + (bh * (1 - k)) / 2, r.w, bh * k + 1)
    }
    if (cover > 0.9) {
      fill(ctx, { x: r.x, y: cy - 3 * s, w: r.w, h: 6 * s }, NIL.amber)
    }
  }
  ctx.restore()
}
