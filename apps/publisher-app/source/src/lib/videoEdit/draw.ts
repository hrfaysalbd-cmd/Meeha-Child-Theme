/**
 * Round 21 — the video editor's compositor and motion graphics.
 *
 * drawOutputFrame() paints ONE output frame for output time T: the video
 * (fitted into its box), the animated news frame around it (header band
 * with badge/kicker/title/date, footer band with ticker / ad / branding),
 * the animated corner logo, name lower-thirds, and the full-screen drawn
 * scenes (intros, outro, ad-break cards). It is a pure function of
 * (settings, time, current video picture), so the live preview and the
 * MP4 export produce identical pictures.
 *
 * All sizes are in units of `s` (1 at a 1080-pixel shorter side), so every
 * design scales to 720p/1080p and to any aspect ratio.
 */
import { FRAMES, type Caption, type EditConfig, type FrameId } from './config'
import { BRAND, FONT_BODY, FONT_DISPLAY, type LogoAsset } from './assets'
import { clamp, ease, fillRoundRect, fitText, lerp, rnd, win, type Ctx } from './motion'
import type { Layout, Rect, SceneId, Segment, Timeline } from './timeline'

export interface Picture {
  src: CanvasImageSource
  w: number
  h: number
}

export interface DrawEnv {
  cfg: EditConfig
  L: Layout
  tl: Timeline
  logo: LogoAsset
  adImage: Picture | null
  /** Header title (custom text, else the post title). */
  title: string
  /** Intro title (custom intro text, else the header title). */
  introTitle: string
  kicker: string
  ticker: string
  dateText: string
  website: string
  scratch: { blur: HTMLCanvasElement | OffscreenCanvas; shine: HTMLCanvasElement | OffscreenCanvas }
}

export function makeScratch() {
  const mk = () => (typeof OffscreenCanvas !== 'undefined' ? new OffscreenCanvas(8, 8) : Object.assign(document.createElement('canvas'), { width: 8, height: 8 }))
  return { blur: mk(), shine: mk() }
}

const bodyFont = (w: number, px: number) => `${w} ${px}px ${FONT_BODY}`
const dispFont = (w: number, px: number) => `${w} ${px}px ${FONT_DISPLAY}`

// ---------------------------------------------------------------------------
// Video fitting
// ---------------------------------------------------------------------------

function coverRect(pw: number, ph: number, r: Rect): Rect {
  const k = Math.max(r.w / pw, r.h / ph)
  const w = pw * k
  const h = ph * k
  return { x: r.x + (r.w - w) / 2, y: r.y + (r.h - h) / 2, w, h }
}

function containRect(pw: number, ph: number, r: Rect): Rect {
  const k = Math.min(r.w / pw, r.h / ph)
  const w = pw * k
  const h = ph * k
  return { x: r.x + (r.w - w) / 2, y: r.y + (r.h - h) / 2, w, h }
}

/** Soft, darkened, blown-up copy of the picture — cheap (drawn tiny, then upscaled). */
function drawBlurFill(ctx: Ctx, env: DrawEnv, pic: Picture, r: Rect) {
  const c = env.scratch.blur
  const bw = 72
  const bh = Math.max(8, Math.round((bw * r.h) / r.w))
  if (c.width !== bw || c.height !== bh) {
    c.width = bw
    c.height = bh
  }
  const b = c.getContext('2d') as Ctx
  b.filter = 'blur(3px)'
  const cr = coverRect(pic.w, pic.h, { x: -6, y: -6, w: bw + 12, h: bh + 12 })
  b.drawImage(pic.src, cr.x, cr.y, cr.w, cr.h)
  b.filter = 'none'
  ctx.save()
  ctx.imageSmoothingEnabled = true
  ctx.imageSmoothingQuality = 'high'
  ctx.drawImage(c as CanvasImageSource, r.x, r.y, r.w, r.h)
  ctx.fillStyle = 'rgba(0,0,0,0.38)'
  ctx.fillRect(r.x, r.y, r.w, r.h)
  ctx.restore()
}

export function drawPicture(ctx: Ctx, env: DrawEnv, pic: Picture | null, r: Rect, fit: EditConfig['fit']) {
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  ctx.fillStyle = '#000'
  ctx.fillRect(r.x, r.y, r.w, r.h)
  if (pic && pic.w > 0 && pic.h > 0) {
    const same = Math.abs(pic.w / pic.h - r.w / r.h) < 0.012
    if (same || fit === 'cover') {
      const d = coverRect(pic.w, pic.h, r)
      ctx.drawImage(pic.src, d.x, d.y, d.w, d.h)
    } else {
      if (fit === 'blur') drawBlurFill(ctx, env, pic, r)
      const d = containRect(pic.w, pic.h, r)
      ctx.drawImage(pic.src, d.x, d.y, d.w, d.h)
    }
  }
  ctx.restore()
}

// ---------------------------------------------------------------------------
// Frame themes
// ---------------------------------------------------------------------------

interface Theme {
  /** Paints the header band background (+ its continuous animation). */
  headerBg: (ctx: Ctx, r: Rect, t: number, s: number) => void
  footerBg: (ctx: Ctx, r: Rect, t: number, s: number) => void
  title: string
  date: string
  kickerBg: string
  kickerFg: string
  badge?: { text: string; bg: string; fg: string; kind: 'pulse' | 'live' | 'gold' | 'tape' }
  labelBg: string
  labelFg: string
  tickerFg: string
  /** Lower-third accent bar. */
  accent: string
  /** Thin line between header and video. */
  edge?: string
}

function linear(ctx: Ctx, r: Rect, stops: [number, string][], angle = 0) {
  const cx = r.x + r.w / 2
  const cy = r.y + r.h / 2
  const dx = (Math.cos(angle) * r.w) / 2
  const dy = (Math.sin(angle) * r.h) / 2
  const g = ctx.createLinearGradient(cx - dx, cy - dy, cx + dx, cy + dy)
  for (const [o, c] of stops) g.addColorStop(o, c)
  return g
}

function stripes(ctx: Ctx, r: Rect, t: number, s: number, color: string, gap = 46, width = 16, speed = 40) {
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  ctx.strokeStyle = color
  ctx.lineWidth = width * s
  const step = gap * s
  const off = ((t * speed * s) % step) - step
  for (let x = r.x - r.h + off; x < r.x + r.w + r.h; x += step) {
    ctx.beginPath()
    ctx.moveTo(x, r.y + r.h + 4)
    ctx.lineTo(x + r.h + 8, r.y - 4)
    ctx.stroke()
  }
  ctx.restore()
}

/** A soft diagonal light that sweeps across `r` once every `period` s. */
function sweep(ctx: Ctx, r: Rect, t: number, period: number, alpha = 0.16) {
  const p = (t % period) / 1.4
  if (p > 1) return
  const x = lerp(r.x - r.w * 0.4, r.x + r.w * 1.2, ease.inOutCubic(p))
  const g = ctx.createLinearGradient(x - r.w * 0.15, r.y, x + r.w * 0.15, r.y + r.h)
  g.addColorStop(0, 'rgba(255,255,255,0)')
  g.addColorStop(0.5, `rgba(255,255,255,${alpha})`)
  g.addColorStop(1, 'rgba(255,255,255,0)')
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  ctx.fillStyle = g
  ctx.fillRect(r.x, r.y, r.w, r.h)
  ctx.restore()
}

function wave(ctx: Ctx, r: Rect, baseY: number, amp: number, len: number, phase: number, fill: string, toBottom = true) {
  ctx.beginPath()
  ctx.moveTo(r.x, toBottom ? r.y + r.h : r.y)
  for (let x = 0; x <= r.w + 8; x += 8) {
    ctx.lineTo(r.x + x, baseY + Math.sin((x / len) * Math.PI * 2 + phase) * amp)
  }
  ctx.lineTo(r.x + r.w, toBottom ? r.y + r.h : r.y)
  ctx.closePath()
  ctx.fillStyle = fill
  ctx.fill()
}

const THEMES: Record<Exclude<FrameId, 'none'>, Theme> = {
  breaking: {
    headerBg: (ctx, r, t, s) => {
      ctx.fillStyle = linear(ctx, r, [
        [0, '#8E0B10'],
        [0.55, '#D11B22'],
        [1, '#E5322F'],
      ])
      ctx.fillRect(r.x, r.y, r.w, r.h)
      stripes(ctx, r, t, s, 'rgba(255,255,255,0.06)', 54, 18, 70)
      sweep(ctx, r, t, 5, 0.18)
      ctx.fillStyle = '#111827'
      ctx.fillRect(r.x, r.y + r.h - 6 * s, r.w, 6 * s)
    },
    footerBg: (ctx, r) => {
      ctx.fillStyle = '#111827'
      ctx.fillRect(r.x, r.y, r.w, r.h)
    },
    title: '#FFFFFF',
    date: 'rgba(255,255,255,0.85)',
    kickerBg: '#111827',
    kickerFg: '#FFFFFF',
    badge: { text: 'ব্রেকিং নিউজ', bg: '#FFFFFF', fg: '#C8102E', kind: 'pulse' },
    labelBg: '#D11B22',
    labelFg: '#FFFFFF',
    tickerFg: '#FFFFFF',
    accent: '#D11B22',
  },
  news: {
    headerBg: (ctx, r, t) => {
      ctx.fillStyle = linear(
        ctx,
        r,
        [
          [0, BRAND.navy],
          [1, BRAND.blue],
        ],
        -0.35,
      )
      ctx.fillRect(r.x, r.y, r.w, r.h)
      sweep(ctx, r, t, 7, 0.14)
    },
    footerBg: (ctx, r) => {
      ctx.fillStyle = '#FFFFFF'
      ctx.fillRect(r.x, r.y, r.w, r.h)
    },
    title: '#FFFFFF',
    date: '#BFD8F5',
    kickerBg: BRAND.red,
    kickerFg: '#FFFFFF',
    labelBg: BRAND.blue,
    labelFg: '#FFFFFF',
    tickerFg: '#0B2746',
    accent: BRAND.red,
    edge: BRAND.red,
  },
  live: {
    headerBg: (ctx, r, t) => {
      ctx.fillStyle = linear(ctx, r, [
        [0, '#0B1120'],
        [1, '#1E293B'],
      ])
      ctx.fillRect(r.x, r.y, r.w, r.h)
      sweep(ctx, r, t, 9, 0.08)
    },
    footerBg: (ctx, r) => {
      ctx.fillStyle = '#B91C1C'
      ctx.fillRect(r.x, r.y, r.w, r.h)
    },
    title: '#FFFFFF',
    date: '#94A3B8',
    kickerBg: '#334155',
    kickerFg: '#FFFFFF',
    badge: { text: 'সরাসরি', bg: '#DC2626', fg: '#FFFFFF', kind: 'live' },
    labelBg: '#0B1120',
    labelFg: '#FFFFFF',
    tickerFg: '#FFFFFF',
    accent: '#DC2626',
    edge: '#DC2626',
  },
  special: {
    headerBg: (ctx, r, t, s) => {
      ctx.fillStyle = linear(ctx, r, [
        [0, '#070D18'],
        [1, '#0F1F3D'],
      ])
      ctx.fillRect(r.x, r.y, r.w, r.h)
      stripes(ctx, r, t, s, 'rgba(232,179,58,0.05)', 38, 2, 12)
      sweep(ctx, r, t, 8, 0.07)
    },
    footerBg: (ctx, r) => {
      ctx.fillStyle = '#0B1220'
      ctx.fillRect(r.x, r.y, r.w, r.h)
    },
    title: '#FFFFFF',
    date: '#E8B33A',
    kickerBg: 'rgba(232,179,58,0.16)',
    kickerFg: '#F5CF72',
    badge: { text: 'বিশেষ প্রতিবেদন', bg: 'transparent', fg: '#E8B33A', kind: 'gold' },
    labelBg: '#E8B33A',
    labelFg: '#0B1220',
    tickerFg: '#F8FAFC',
    accent: '#E8B33A',
    edge: '#E8B33A',
  },
  wave: {
    headerBg: (ctx, r, t, s) => {
      ctx.fillStyle = linear(
        ctx,
        r,
        [
          [0, '#014A93'],
          [1, BRAND.blue],
        ],
        -0.2,
      )
      ctx.fillRect(r.x, r.y, r.w, r.h)
      const base = r.y + r.h - 26 * s
      wave(ctx, r, base - 10 * s, 12 * s, r.w * 0.55, t * 1.4, 'rgba(255,255,255,0.10)')
      wave(ctx, r, base, 9 * s, r.w * 0.38, -t * 1.9 + 1, 'rgba(255,255,255,0.12)')
      wave(ctx, r, base + 12 * s, 7 * s, r.w * 0.46, t * 2.3 + 2, BRAND.red)
    },
    footerBg: (ctx, r, t, s) => {
      ctx.fillStyle = BRAND.navy
      ctx.fillRect(r.x, r.y, r.w, r.h)
      wave(ctx, r, r.y + 8 * s, 6 * s, r.w * 0.4, t * 2, 'rgba(255,255,255,0.08)', false)
    },
    title: '#FFFFFF',
    date: '#CFE3FA',
    kickerBg: '#FFFFFF',
    kickerFg: BRAND.blue,
    labelBg: BRAND.red,
    labelFg: '#FFFFFF',
    tickerFg: '#FFFFFF',
    accent: BRAND.red,
  },
  exclusive: {
    headerBg: (ctx, r, t, s) => {
      ctx.fillStyle = '#0A0A0A'
      ctx.fillRect(r.x, r.y, r.w, r.h)
      const band = { x: r.x, y: r.y + r.h - 14 * s, w: r.w, h: 14 * s }
      ctx.fillStyle = '#FACC15'
      ctx.fillRect(band.x, band.y, band.w, band.h)
      stripes(ctx, band, t, s, '#0A0A0A', 34, 12, 50)
      sweep(ctx, r, t, 6, 0.08)
    },
    footerBg: (ctx, r) => {
      ctx.fillStyle = '#FACC15'
      ctx.fillRect(r.x, r.y, r.w, r.h)
    },
    title: '#FFFFFF',
    date: '#FDE68A',
    kickerBg: '#262626',
    kickerFg: '#FACC15',
    badge: { text: 'এক্সক্লুসিভ', bg: '#FACC15', fg: '#0A0A0A', kind: 'tape' },
    labelBg: '#0A0A0A',
    labelFg: '#FACC15',
    tickerFg: '#0A0A0A',
    accent: '#FACC15',
  },
  minimal: {
    headerBg: (ctx, r, _t, s) => {
      ctx.fillStyle = '#FFFFFF'
      ctx.fillRect(r.x, r.y, r.w, r.h)
      ctx.fillStyle = '#E5E7EB'
      ctx.fillRect(r.x, r.y + r.h - 2 * s, r.w, 2 * s)
    },
    footerBg: (ctx, r) => {
      ctx.fillStyle = '#F3F4F6'
      ctx.fillRect(r.x, r.y, r.w, r.h)
    },
    title: '#111827',
    date: '#6B7280',
    kickerBg: '#E8F1FB',
    kickerFg: BRAND.blue,
    labelBg: BRAND.blue,
    labelFg: '#FFFFFF',
    tickerFg: '#111827',
    accent: BRAND.blue,
  },
}

export function themeOf(id: FrameId): Theme | null {
  return id === 'none' ? null : THEMES[id]
}

// ---------------------------------------------------------------------------
// Header band
// ---------------------------------------------------------------------------

function drawBadge(ctx: Ctx, theme: Theme, x: number, cy: number, h: number, t: number, s: number, appear: number) {
  const b = theme.badge!
  ctx.save()
  ctx.font = dispFont(800, h * 0.62)
  const isLive = b.kind === 'live'
  const dot = isLive ? h * 0.32 : 0
  const padX = h * 0.36
  const tw = ctx.measureText(b.text).width
  const w = tw + padX * 2 + (isLive ? dot + h * 0.22 : 0)
  const sc = ease.outBack(appear)
  ctx.translate(x, cy)
  ctx.scale(sc, sc)

  if (b.kind === 'gold') {
    ctx.fillStyle = b.fg
    ctx.textBaseline = 'middle'
    ctx.fillText(b.text, 0, h * 0.04)
    // underline that draws itself, with a light running along it
    const lw = tw * ease.outCubic(win(appear, 0.2, 1))
    ctx.fillRect(0, h * 0.42, lw, 3 * s)
    const p = (t % 3) / 3
    const g = ctx.createLinearGradient(lw * p - 60 * s, 0, lw * p + 60 * s, 0)
    g.addColorStop(0, 'rgba(255,255,255,0)')
    g.addColorStop(0.5, 'rgba(255,245,210,0.9)')
    g.addColorStop(1, 'rgba(255,255,255,0)')
    ctx.fillStyle = g
    ctx.fillRect(0, h * 0.42, lw, 3 * s)
    ctx.restore()
    return tw
  }

  let glow = 0
  if (b.kind === 'pulse') glow = 0.5 + 0.5 * Math.sin(t * Math.PI * 2 * 1.2)
  if (glow > 0) {
    ctx.shadowColor = 'rgba(255,255,255,0.85)'
    ctx.shadowBlur = 18 * s * glow
  }
  if (b.kind === 'tape') {
    ctx.beginPath()
    const sk = h * 0.28
    ctx.moveTo(sk, -h / 2)
    ctx.lineTo(w + sk, -h / 2)
    ctx.lineTo(w, h / 2)
    ctx.lineTo(0, h / 2)
    ctx.closePath()
    ctx.fillStyle = b.bg
    ctx.fill()
  } else {
    fillRoundRect(ctx, 0, -h / 2, w, h, h * 0.18, b.bg)
  }
  ctx.shadowBlur = 0
  let tx = padX + (b.kind === 'tape' ? h * 0.14 : 0)
  if (isLive) {
    const on = Math.sin(t * Math.PI * 2) > -0.2
    ctx.beginPath()
    ctx.arc(padX + dot / 2, 0, dot / 2, 0, Math.PI * 2)
    ctx.fillStyle = on ? '#FFFFFF' : 'rgba(255,255,255,0.35)'
    ctx.fill()
    if (on) {
      ctx.beginPath()
      ctx.arc(padX + dot / 2, 0, (dot / 2) * (1.4 + 0.6 * ((t * 1.5) % 1)), 0, Math.PI * 2)
      ctx.strokeStyle = `rgba(255,255,255,${0.5 * (1 - ((t * 1.5) % 1))})`
      ctx.lineWidth = 2 * s
      ctx.stroke()
    }
    tx += dot + h * 0.22
  }
  ctx.fillStyle = b.fg
  ctx.textBaseline = 'middle'
  ctx.fillText(b.text, tx, h * 0.04)
  ctx.restore()
  return w
}

function drawChip(ctx: Ctx, text: string, x: number, cy: number, h: number, bg: string, fg: string, appear: number) {
  ctx.save()
  ctx.globalAlpha *= appear
  ctx.font = bodyFont(700, h * 0.56)
  const w = ctx.measureText(text).width + h * 0.8
  const dx = (1 - ease.outCubic(appear)) * -20
  fillRoundRect(ctx, x + dx, cy - h / 2, w, h, h / 2, bg)
  ctx.fillStyle = fg
  ctx.textBaseline = 'middle'
  ctx.fillText(text, x + dx + h * 0.4, cy + h * 0.04)
  ctx.restore()
  return w
}

function drawHeader(ctx: Ctx, env: DrawEnv, theme: Theme, r: Rect, t: number) {
  const s = env.L.s
  const enter = win(t, 0, 0.5)
  // background wipes in from the left on the first frames of the video
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w * ease.outCubic(enter), r.h)
  ctx.clip()
  theme.headerBg(ctx, r, t, s)
  ctx.restore()
  if (enter < 1) {
    ctx.fillStyle = '#000'
    ctx.fillRect(r.x + r.w * ease.outCubic(enter), r.y, r.w * (1 - ease.outCubic(enter)), r.h)
  }

  const padX = 44 * s
  const rowCy = r.y + 50 * s
  const rowH = 48 * s
  let x = r.x + padX
  const minimal = env.cfg.frame.id === 'minimal'
  if (minimal) {
    // accent bar left of the text block
    const bh = (r.h - 44 * s) * ease.outCubic(win(t, 0.15, 0.6))
    ctx.fillStyle = theme.accent
    ctx.fillRect(r.x + padX, r.y + 22 * s, 8 * s, bh)
    x += 26 * s
  }

  let hasRow = false
  if (theme.badge) {
    const w = drawBadge(ctx, theme, x, rowCy, rowH, t, s, win(t, 0.2, 0.55))
    x += w + 16 * s
    hasRow = true
  }
  if (env.kicker.trim()) {
    x += drawChip(ctx, env.kicker.trim(), x, rowCy, 40 * s, theme.kickerBg, theme.kickerFg, win(t, 0.3, 0.65)) + 16 * s
    hasRow = true
  }
  if (env.cfg.frame.date && env.dateText) {
    ctx.save()
    ctx.globalAlpha *= win(t, 0.45, 0.8)
    ctx.font = bodyFont(500, 26 * s)
    ctx.fillStyle = theme.date
    ctx.textAlign = 'right'
    ctx.textBaseline = 'middle'
    ctx.fillText(env.dateText, r.x + r.w - padX, rowCy + 2 * s)
    ctx.restore()
    hasRow = true
  }

  // Title — one big line if it fits, else two smaller ones.
  const title = env.title.trim()
  if (!title) return
  const left = r.x + padX + (minimal ? 26 * s : 0)
  const maxW = r.x + r.w - padX - left
  const top = hasRow ? r.y + 84 * s : r.y + 18 * s
  const bottom = r.y + r.h - 20 * s
  const avail = bottom - top
  const font = (px: number) => bodyFont(700, px)
  let fit = fitText(ctx, title, font, maxW, 1, Math.min(56 * s, avail * 0.82), 40 * s)
  if (fit.lines.length === 1 && ctx.measureText(fit.lines[0]).width > maxW) fit = fitText(ctx, title, font, maxW, 2, avail / 2.5, 28 * s)
  else if (fit.lines.length === 0 || fit.lines[0].endsWith('…')) fit = fitText(ctx, title, font, maxW, 2, Math.min(46 * s, avail / 2.45), 28 * s)
  const blockH = fit.lines.length * fit.lineHeight
  const y0 = top + (avail - blockH) / 2
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, top - 6 * s, r.w, avail + 12 * s)
  ctx.clip()
  ctx.font = font(fit.size)
  ctx.fillStyle = theme.title
  ctx.textBaseline = 'middle'
  fit.lines.forEach((line, i) => {
    const p = ease.outCubic(win(t, 0.35 + i * 0.1, 0.8 + i * 0.1))
    ctx.globalAlpha = p
    ctx.fillText(line, left, y0 + fit.lineHeight * (i + 0.5) + (1 - p) * fit.lineHeight * 0.8)
  })
  ctx.restore()
}

// ---------------------------------------------------------------------------
// Footer band
// ---------------------------------------------------------------------------

function drawLabel(ctx: Ctx, text: string, r: Rect, bg: string, fg: string, s: number, appear: number) {
  ctx.save()
  ctx.font = dispFont(800, Math.min(34 * s, r.h * 0.42))
  const w = ctx.measureText(text).width + 52 * s
  const shown = w * ease.outCubic(appear)
  ctx.beginPath()
  ctx.moveTo(r.x, r.y)
  ctx.lineTo(r.x + shown + 18 * s, r.y)
  ctx.lineTo(r.x + shown, r.y + r.h)
  ctx.lineTo(r.x, r.y + r.h)
  ctx.closePath()
  ctx.fillStyle = bg
  ctx.fill()
  ctx.clip()
  ctx.fillStyle = fg
  ctx.textBaseline = 'middle'
  ctx.fillText(text, r.x + 24 * s, r.y + r.h / 2 + 2 * s)
  ctx.restore()
  return w + 18 * s
}

/** Endless right-to-left scroll of `text` inside `r`. */
function scroll(ctx: Ctx, text: string, r: Rect, t: number, s: number, color: string, px: number, weight = 500) {
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  ctx.font = bodyFont(weight, px)
  ctx.fillStyle = color
  ctx.textBaseline = 'middle'
  const item = `${text}     ●     `
  const iw = ctx.measureText(item).width
  const speed = 120 * s
  const off = (t * speed) % iw
  for (let x = r.x + r.w * 0.35 - off; x < r.x + r.w; x += iw) {
    if (x + iw > r.x) ctx.fillText(item, x, r.y + r.h / 2 + 2 * s)
  }
  ctx.restore()
}

function drawFooter(ctx: Ctx, env: DrawEnv, theme: Theme, r: Rect, t: number) {
  const s = env.L.s
  const f = env.cfg.frame
  const enter = win(t, 0.1, 0.55)
  ctx.save()
  ctx.beginPath()
  const shown = r.w * ease.outCubic(enter)
  ctx.rect(r.x + r.w - shown, r.y, shown, r.h)
  ctx.clip()
  theme.footerBg(ctx, r, t, s)
  ctx.restore()
  if (enter < 1) {
    ctx.fillStyle = '#000'
    ctx.fillRect(r.x, r.y, r.w - shown, r.h)
  }
  const fade = win(t, 0.4, 0.8)
  const info = FRAMES.find((x) => x.id === f.id)

  if (f.footer === 'ad-image' && env.adImage) {
    ctx.save()
    ctx.globalAlpha = fade
    drawPicture(ctx, env, env.adImage, r, 'cover')
    ctx.font = bodyFont(700, 18 * s)
    const tw = ctx.measureText('বিজ্ঞাপন').width + 16 * s
    fillRoundRect(ctx, r.x + 8 * s, r.y + 8 * s, tw, 28 * s, 6 * s, 'rgba(0,0,0,0.6)')
    ctx.fillStyle = '#fff'
    ctx.textBaseline = 'middle'
    ctx.fillText('বিজ্ঞাপন', r.x + 16 * s, r.y + 23 * s)
    ctx.restore()
    return
  }

  if (f.footer === 'brand') {
    ctx.save()
    ctx.globalAlpha = fade
    const lh = r.h * 0.46
    const lw = (env.logo.w / env.logo.h) * lh
    const plateX = r.x + 30 * s
    const dark = theme.tickerFg === '#FFFFFF' || theme.tickerFg === '#F8FAFC'
    if (dark) fillRoundRect(ctx, plateX - 14 * s, r.y + (r.h - lh) / 2 - 10 * s, lw + 28 * s, lh + 20 * s, (lh + 20 * s) / 2, '#FFFFFF')
    ctx.drawImage(env.logo.color, plateX, r.y + (r.h - lh) / 2, lw, lh)
    ctx.textBaseline = 'middle'
    ctx.textAlign = 'right'
    ctx.font = bodyFont(700, 34 * s)
    ctx.fillStyle = theme.tickerFg
    const site = env.website || 'meeha.news'
    ctx.fillText(site, r.x + r.w - 34 * s, r.y + r.h / 2)
    const siteW = ctx.measureText(site).width
    ctx.font = bodyFont(500, 26 * s)
    const msg = 'সর্বশেষ সংবাদ পেতে ভিজিট করুন'
    const room = r.w - (plateX + lw + 40 * s - r.x) - siteW - 70 * s
    if (ctx.measureText(msg).width < room) {
      ctx.globalAlpha = fade * 0.8
      ctx.fillText(msg, r.x + r.w - 34 * s - siteW - 24 * s, r.y + r.h / 2 + 2 * s)
    }
    ctx.restore()
    return
  }

  const isAd = f.footer === 'ad-text'
  const label = isAd ? 'বিজ্ঞাপন' : info?.label ?? 'শিরোনাম'
  const lw = drawLabel(ctx, label, r, theme.labelBg, theme.labelFg, s, win(t, 0.3, 0.7))
  const text = (isAd ? f.adText : env.ticker).trim()
  if (!text) return
  const area = { x: r.x + lw + 8 * s, y: r.y, w: r.w - lw - 8 * s, h: r.h }
  ctx.save()
  ctx.globalAlpha = fade
  ctx.font = bodyFont(isAd ? 700 : 500, 36 * s)
  if (isAd && ctx.measureText(text).width < area.w - 40 * s) {
    ctx.fillStyle = theme.tickerFg
    ctx.textBaseline = 'middle'
    ctx.textAlign = 'center'
    ctx.fillText(text, area.x + area.w / 2, area.y + area.h / 2 + 2 * s)
  } else {
    scroll(ctx, text, area, t, s, theme.tickerFg, 36 * s, isAd ? 700 : 500)
  }
  ctx.restore()
}

/** Gold corner brackets around the video (বিশেষ প্রতিবেদন). */
function drawBrackets(ctx: Ctx, r: Rect, t: number, s: number, color: string) {
  const len = Math.min(r.w, r.h) * 0.09 * ease.outCubic(win(t, 0.3, 0.9))
  if (len <= 0) return
  const m = 22 * s
  ctx.save()
  ctx.strokeStyle = color
  ctx.lineWidth = 4 * s
  ctx.lineCap = 'square'
  const corners: [number, number, number, number][] = [
    [r.x + m, r.y + m, 1, 1],
    [r.x + r.w - m, r.y + m, -1, 1],
    [r.x + m, r.y + r.h - m, 1, -1],
    [r.x + r.w - m, r.y + r.h - m, -1, -1],
  ]
  for (const [x, y, dx, dy] of corners) {
    ctx.beginPath()
    ctx.moveTo(x, y + dy * len)
    ctx.lineTo(x, y)
    ctx.lineTo(x + dx * len, y)
    ctx.stroke()
  }
  ctx.restore()
}

// ---------------------------------------------------------------------------
// Corner logo
// ---------------------------------------------------------------------------

function shineOver(ctx: Ctx, env: DrawEnv, img: CanvasImageSource, x: number, y: number, w: number, h: number, p: number) {
  if (p <= 0 || p >= 1) return
  const c = env.scratch.shine
  const cw = Math.max(2, Math.ceil(w))
  const ch = Math.max(2, Math.ceil(h))
  if (c.width !== cw || c.height !== ch) {
    c.width = cw
    c.height = ch
  }
  const g = c.getContext('2d') as Ctx
  g.globalCompositeOperation = 'source-over'
  g.clearRect(0, 0, cw, ch)
  g.drawImage(img, 0, 0, cw, ch)
  g.globalCompositeOperation = 'source-in'
  const sx = lerp(-cw * 0.4, cw * 1.4, p)
  const gr = g.createLinearGradient(sx - ch, 0, sx + ch, ch)
  gr.addColorStop(0, 'rgba(255,255,255,0)')
  gr.addColorStop(0.5, 'rgba(255,255,255,0.85)')
  gr.addColorStop(1, 'rgba(255,255,255,0)')
  g.fillStyle = gr
  g.fillRect(0, 0, cw, ch)
  ctx.drawImage(c as CanvasImageSource, x, y, w, h)
}

/**
 * The corner logo over the video. `t` = seconds of main content shown.
 *
 * reveal: the round "M" stays; every `every` s the pill opens and the full
 *         wordmark slides out of it, a light runs across, and it closes.
 * spin:   the "M" alone, coin-flipping every `every` s.
 * pulse:  the full logo, with a light sweep + small bounce every `every` s.
 * static: the full logo, fading in once.
 */
export function drawLogoBug(ctx: Ctx, env: DrawEnv, t: number) {
  const lg = env.cfg.logo
  if (!lg.on) return
  const v = env.L.video
  const short = Math.min(v.w, v.h)
  const h = (lg.size / 100) * short
  const scale = h / env.logo.h
  const fullW = env.logo.w * scale
  const markW = env.logo.markW * scale
  const splittable = env.logo.markW < env.logo.w
  const pad = lg.plate ? h * 0.26 : 0
  const m = short * 0.035
  const right = lg.corner === 'tr' || lg.corner === 'br'
  const bottom = lg.corner === 'br' || lg.corner === 'bl'
  const img = lg.plate ? env.logo.color : env.logo.white

  const t0 = 0.6
  if (t < t0) return
  const lt = t - t0
  const appear = win(lt, 0, 0.5)
  const P = lg.every

  // How open the wordmark is (0 = mark only, 1 = full logo).
  let open = 1
  let shine = 0
  let rot = 0
  let bounce = 1
  if (lg.style === 'reveal' && splittable) {
    const c = lt % P
    const first = lt < P
    const o0 = first ? 0.55 : 0
    open = ease.inOutCubic(win(c, o0, o0 + 0.6)) * (1 - ease.inOutCubic(win(c, o0 + 4, o0 + 4.6)))
    shine = win(c, o0 + 1.1, o0 + 1.9)
  } else if (lg.style === 'spin' && splittable) {
    open = 0
    const c = lt % P
    rot = c > 1.2 && c < 2.1 ? ease.inOutCubic(win(c, 1.2, 2.1)) : 0
  } else if (lg.style === 'pulse') {
    const c = lt % P
    shine = win(c, 1, 1.8)
    bounce = 1 + 0.06 * Math.sin(Math.PI * win(c, 1, 1.5))
  }
  if (!splittable) open = 1

  const innerW = markW + (fullW - markW) * open
  const boxW = innerW + pad * 2
  const boxH = h + pad * 2
  const bx = right ? v.x + v.w - m - boxW : v.x + m
  const by = bottom ? v.y + v.h - m - boxH : v.y + m

  ctx.save()
  ctx.globalAlpha = lg.opacity * ease.outCubic(appear)
  const pop = ease.outBack(appear) * bounce
  const ox = right ? bx + boxW - boxH / 2 : bx + boxH / 2
  const oy = by + boxH / 2
  ctx.translate(ox, oy)
  ctx.scale(pop, pop)
  ctx.translate(-ox, -oy)

  if (lg.plate) {
    ctx.shadowColor = 'rgba(0,0,0,0.28)'
    ctx.shadowBlur = 14 * env.L.s
    ctx.shadowOffsetY = 3 * env.L.s
    fillRoundRect(ctx, bx, by, boxW, boxH, boxH / 2, '#FFFFFF')
    ctx.shadowColor = 'transparent'
  } else {
    ctx.shadowColor = 'rgba(0,0,0,0.45)'
    ctx.shadowBlur = 10 * env.L.s
  }

  const ix = bx + pad
  const iy = by + pad
  // wordmark, clipped to the open part of the pill
  if (open > 0.001 && splittable) {
    ctx.save()
    ctx.beginPath()
    ctx.rect(ix + markW * 0.5, by, innerW - markW * 0.5, boxH)
    ctx.clip()
    const wx = env.logo.wordX
    const shift = (1 - open) * -(fullW - markW) * 0.35
    ctx.globalAlpha *= ease.inCubic(open) * 0.6 + 0.4 * open
    ctx.drawImage(img, wx, 0, env.logo.w - wx, env.logo.h, ix + wx * scale + shift, iy, (env.logo.w - wx) * scale, h)
    ctx.restore()
  }
  // the mark (or the whole logo when it can't be split)
  if (splittable) {
    ctx.save()
    const cx = ix + markW / 2
    const cy = iy + h / 2
    ctx.translate(cx, cy)
    if (rot > 0) ctx.scale(Math.cos(rot * Math.PI * 2), 1)
    if (lt < 0.5) ctx.rotate((1 - ease.outCubic(appear)) * -Math.PI)
    ctx.drawImage(img, 0, 0, env.logo.markW, env.logo.h, -markW / 2, -h / 2, markW, h)
    ctx.restore()
  } else {
    ctx.drawImage(img, ix, iy, fullW, h)
  }
  ctx.shadowColor = 'transparent'
  if (shine > 0 && shine < 1) shineOver(ctx, env, img, ix, iy, innerW >= fullW - 1 ? fullW : innerW, h, shine)
  ctx.restore()
}

// ---------------------------------------------------------------------------
// Captions & credits (name plate / credit tag / subtitle)
// ---------------------------------------------------------------------------

/**
 * Draws one caption; returns the height it takes so several captions at the
 * same position stack instead of overlapping. `local` = seconds since it
 * appeared, `dur` = how long it stays (already resolved for "till the end").
 */
function drawCaption(ctx: Ctx, env: DrawEnv, c: Caption, local: number, dur: number, accent: string, stack: number): number {
  const v = env.L.video
  const s = Math.min(v.w, v.h) / 1080
  const inP = win(local, 0, 0.6)
  const outP = win(local, dur - 0.45, dur)
  if (outP >= 1) return 0
  const close = ease.inCubic(outP)
  const margin = Math.max(40 * s, Math.min(v.w, v.h) * 0.045)
  const top = c.pos === 'tl' || c.pos === 'tr'
  const right = c.pos === 'br' || c.pos === 'tr'
  const centre = c.pos === 'bc'
  const maxW = v.w * (centre ? 0.88 : 0.78)
  // keep clear of the corner logo when it sits in the same corner
  const lg = env.cfg.logo
  const logoHere = lg.on && ((top && c.pos[0] === lg.corner[0] && c.pos[1] === lg.corner[1]) || (!top && !centre && c.pos === lg.corner))
  const logoGap = logoHere ? (lg.size / 100) * Math.min(v.w, v.h) * 1.55 + 10 * s : 0

  ctx.save()
  if (c.style === 'tag') {
    const h = 50 * s
    ctx.font = bodyFont(700, 27 * s)
    const tw = Math.min(maxW - 46 * s, ctx.measureText(c.text).width)
    const w = tw + 46 * s
    const shown = w * ease.outCubic(win(inP, 0, 0.7)) * (1 - close)
    const x = right ? v.x + v.w - margin - shown : centre ? v.x + (v.w - shown) / 2 : v.x + margin
    const y = top ? v.y + margin + logoGap + stack : v.y + v.h - margin - h - stack - logoGap
    if (shown > 1) {
      ctx.globalAlpha = clamp(inP * 2) * (1 - close)
      fillRoundRect(ctx, x, y, shown, h, 10 * s, 'rgba(10,14,24,0.72)')
      ctx.fillStyle = accent
      ctx.fillRect(right ? x + shown - 6 * s : x, y + 10 * s, 6 * s, h - 20 * s)
      ctx.beginPath()
      ctx.rect(x, y, shown, h)
      ctx.clip()
      ctx.fillStyle = '#FFFFFF'
      ctx.textBaseline = 'middle'
      ctx.fillText(c.text, (right ? x + shown - w : x) + 24 * s, y + h / 2 + 2 * s, tw)
    }
    ctx.restore()
    return h + 12 * s
  }

  if (c.style === 'subtitle') {
    const fit = fitText(ctx, c.text, (px) => bodyFont(700, px), maxW - 48 * s, 2, 44 * s, 30 * s, 1.3)
    const h = fit.lines.length * fit.lineHeight + 26 * s
    ctx.font = bodyFont(700, fit.size)
    const w = Math.max(...fit.lines.map((l) => ctx.measureText(l).width)) + 48 * s
    const x = centre ? v.x + (v.w - w) / 2 : right ? v.x + v.w - margin - w : v.x + margin
    const y = top ? v.y + margin + logoGap + stack : v.y + v.h - margin - h - stack
    ctx.globalAlpha = ease.outCubic(win(inP, 0, 0.5)) * (1 - close)
    ctx.translate(0, (1 - ease.outCubic(win(inP, 0, 0.5))) * 16 * s)
    fillRoundRect(ctx, x, y, w, h, 10 * s, 'rgba(0,0,0,0.68)')
    ctx.fillStyle = '#FFFFFF'
    ctx.textBaseline = 'middle'
    ctx.textAlign = 'center'
    fit.lines.forEach((l, i) => ctx.fillText(l, x + w / 2, y + 13 * s + fit.lineHeight * (i + 0.5) + 2 * s))
    ctx.restore()
    return h + 12 * s
  }

  // name plate
  const nameH = 64 * s
  const roleH = c.sub.trim() ? 46 * s : 0
  const total = nameH + roleH
  const y = top ? v.y + margin + logoGap + stack : v.y + v.h - Math.max(60 * s, v.h * 0.08) - total - stack
  ctx.font = bodyFont(700, 38 * s)
  const nameW = Math.min(maxW, ctx.measureText(c.text).width + 52 * s)
  ctx.font = bodyFont(500, 27 * s)
  const roleW = Math.min(maxW, ctx.measureText(c.sub).width + 44 * s)
  const boxW = Math.max(nameW, roleW)
  const x = right ? v.x + v.w - margin - boxW - 10 * s : centre ? v.x + (v.w - boxW) / 2 - 5 * s : v.x + margin
  const barH = total * ease.outCubic(win(inP, 0, 0.4)) * (1 - close)
  ctx.fillStyle = accent
  ctx.fillRect(x, y + total - barH, 10 * s, barH)
  const nw = nameW * ease.outCubic(win(inP, 0.15, 0.7)) * (1 - close)
  if (nw > 1) {
    ctx.save()
    ctx.shadowColor = 'rgba(0,0,0,0.25)'
    ctx.shadowBlur = 12 * s
    ctx.fillStyle = '#FFFFFF'
    ctx.fillRect(x + 10 * s, y, nw, nameH)
    ctx.restore()
    ctx.save()
    ctx.beginPath()
    ctx.rect(x + 10 * s, y, nw, nameH)
    ctx.clip()
    ctx.font = bodyFont(700, 38 * s)
    ctx.fillStyle = '#0B1220'
    ctx.textBaseline = 'middle'
    ctx.fillText(c.text, x + 34 * s, y + nameH / 2 + 2 * s, maxW - 52 * s)
    ctx.restore()
  }
  const rw = roleW * ease.outCubic(win(inP, 0.3, 0.85)) * (1 - close)
  if (roleH && rw > 1) {
    ctx.fillStyle = BRAND.blue
    ctx.fillRect(x + 10 * s, y + nameH, rw, roleH)
    ctx.beginPath()
    ctx.rect(x + 10 * s, y + nameH, rw, roleH)
    ctx.clip()
    ctx.font = bodyFont(500, 27 * s)
    ctx.fillStyle = '#FFFFFF'
    ctx.textBaseline = 'middle'
    ctx.fillText(c.sub, x + 32 * s, y + nameH + roleH / 2 + 2 * s, maxW - 44 * s)
  }
  ctx.restore()
  return total + 16 * s
}

// ---------------------------------------------------------------------------
// Full-screen scenes (intros, outro, ad-break cards)
// ---------------------------------------------------------------------------

function bokeh(ctx: Ctx, W: number, H: number, t: number, s: number, n = 16, color = '255,255,255') {
  for (let i = 0; i < n; i++) {
    const r = (40 + rnd(i) * 140) * s
    const x = rnd(i + 50) * W + Math.sin(t * 0.4 + i) * 30 * s
    const y = ((rnd(i + 99) * (H + 2 * r) - t * (14 + rnd(i + 7) * 26) * s) % (H + 2 * r) + H + 2 * r) % (H + 2 * r) - r
    const a = 0.035 + rnd(i + 3) * 0.06
    const g = ctx.createRadialGradient(x, y, 0, x, y, r)
    g.addColorStop(0, `rgba(${color},${a})`)
    g.addColorStop(0.7, `rgba(${color},${a * 0.6})`)
    g.addColorStop(1, `rgba(${color},0)`)
    ctx.fillStyle = g
    ctx.beginPath()
    ctx.arc(x, y, r, 0, Math.PI * 2)
    ctx.fill()
  }
}

function blackFade(ctx: Ctx, W: number, H: number, a: number) {
  if (a <= 0) return
  ctx.fillStyle = `rgba(0,0,0,${clamp(a)})`
  ctx.fillRect(0, 0, W, H)
}

function logoPill(ctx: Ctx, env: DrawEnv, cx: number, cy: number, h: number, appear: number) {
  if (appear <= 0) return
  const lw = (env.logo.w / env.logo.h) * h
  const pad = h * 0.32
  ctx.save()
  ctx.translate(cx, cy)
  const sc = ease.outBack(clamp(appear))
  ctx.scale(sc, sc)
  ctx.shadowColor = 'rgba(0,0,0,0.25)'
  ctx.shadowBlur = h * 0.5
  fillRoundRect(ctx, -lw / 2 - pad, -h / 2 - pad, lw + pad * 2, h + pad * 2, h / 2 + pad, '#FFFFFF')
  ctx.shadowColor = 'transparent'
  ctx.drawImage(env.logo.color, -lw / 2, -h / 2, lw, h)
  ctx.restore()
}

function sceneBreaking(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = Math.min(W, H) / 1080
  const cx = W / 2
  const cy = H * 0.46
  const exit = win(t, dur - 0.45, dur)
  ctx.save()
  const zoom = 1 + 0.12 * ease.inCubic(exit)
  ctx.translate(cx, cy)
  ctx.scale(zoom, zoom)
  ctx.translate(-cx, -cy)

  const bg = ctx.createRadialGradient(cx, cy, 0, cx, cy, Math.hypot(W, H) * 0.6)
  bg.addColorStop(0, '#E3262B')
  bg.addColorStop(1, '#6E070B')
  ctx.fillStyle = bg
  ctx.fillRect(0, 0, W, H)
  // rotating rays
  ctx.save()
  ctx.translate(cx, cy)
  ctx.rotate(t * 0.25)
  const R = Math.hypot(W, H)
  for (let i = 0; i < 18; i++) {
    ctx.beginPath()
    ctx.moveTo(0, 0)
    ctx.arc(0, 0, R, (i / 18) * Math.PI * 2, ((i + 0.5) / 18) * Math.PI * 2)
    ctx.closePath()
    ctx.fillStyle = 'rgba(255,255,255,0.045)'
    ctx.fill()
  }
  ctx.restore()
  // band behind the words
  const bandH = 330 * s
  const band = { x: 0, y: cy - bandH / 2, w: W, h: bandH }
  ctx.fillStyle = 'rgba(0,0,0,0.30)'
  ctx.fillRect(band.x, band.y, band.w, band.h * ease.outCubic(win(t, 0.05, 0.3)))
  stripes(ctx, band, t, s, 'rgba(255,255,255,0.05)', 60, 22, 160)

  // logo
  logoPill(ctx, env, cx, H * 0.14 + 20 * s, 54 * s, win(t, 0.45, 0.85))

  // "ব্রেকিং" slam + shake
  const slam = win(t, 0.15, 0.42)
  if (slam > 0) {
    const shake = t > 0.38 && t < 0.7 ? (1 - win(t, 0.38, 0.7)) * 16 * s : 0
    const sx = Math.sin(t * 90) * shake
    const sy = Math.cos(t * 77) * shake
    ctx.save()
    ctx.font = dispFont(800, 210 * s)
    let size = 210 * s
    const maxW = W * 0.86
    const w0 = ctx.measureText('ব্রেকিং').width
    if (w0 > maxW) size *= maxW / w0
    ctx.font = dispFont(800, size)
    const sc = lerp(2.3, 1, ease.outCubic(slam))
    ctx.translate(cx + sx, cy - 40 * s + sy)
    ctx.scale(sc, sc)
    ctx.globalAlpha = ease.outCubic(slam)
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    const glitch = t > 1.3 && t < 1.42
    if (glitch) {
      ctx.globalCompositeOperation = 'lighter'
      ctx.fillStyle = 'rgba(0,255,255,0.6)'
      ctx.fillText('ব্রেকিং', -8 * s, 0)
      ctx.fillStyle = 'rgba(255,0,80,0.6)'
      ctx.fillText('ব্রেকিং', 8 * s, 0)
      ctx.globalCompositeOperation = 'source-over'
    }
    ctx.shadowColor = 'rgba(0,0,0,0.35)'
    ctx.shadowBlur = 24 * s
    ctx.fillStyle = '#FFFFFF'
    ctx.fillText('ব্রেকিং', 0, 0)
    ctx.restore()
  }
  // "নিউজ" box slides in
  const box = ease.outCubic(win(t, 0.38, 0.65))
  if (box > 0) {
    ctx.save()
    ctx.font = dispFont(800, 92 * s)
    const tw = ctx.measureText('নিউজ').width
    const bw = tw + 70 * s
    const bh = 112 * s
    const x = cx - bw / 2 + (1 - box) * W * 0.6
    const y = cy + 70 * s
    fillRoundRect(ctx, x, y, bw, bh, 14 * s, '#FFFFFF')
    ctx.fillStyle = '#C8102E'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText('নিউজ', x + bw / 2, y + bh / 2 + 4 * s)
    ctx.restore()
  }
  // headline strip at the bottom
  const title = env.introTitle.trim()
  const strip = ease.outCubic(win(t, 0.8, 1.2))
  if (title && strip > 0) {
    ctx.save()
    const maxW = W * 0.84
    const fit = fitText(ctx, title, (px) => bodyFont(700, px), maxW, 2, 56 * s, 34 * s)
    const sh = fit.lines.length * fit.lineHeight + 44 * s
    const y = H * 0.8 - sh / 2 + (1 - strip) * 80 * s
    ctx.globalAlpha = strip
    fillRoundRect(ctx, (W - maxW) / 2 - 30 * s, y, maxW + 60 * s, sh, 14 * s, '#FFFFFF')
    ctx.fillStyle = '#C8102E'
    ctx.fillRect((W - maxW) / 2 - 30 * s, y, 12 * s, sh)
    ctx.font = bodyFont(700, fit.size)
    ctx.fillStyle = '#111827'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    fit.lines.forEach((l, i) => ctx.fillText(l, W / 2, y + 22 * s + fit.lineHeight * (i + 0.5)))
    ctx.restore()
  }
  ctx.restore()
  // white flash at the start, fade to black at the end
  const flash = 1 - win(t, 0, 0.16)
  if (flash > 0) {
    ctx.fillStyle = `rgba(255,255,255,${flash})`
    ctx.fillRect(0, 0, W, H)
  }
  blackFade(ctx, W, H, ease.inCubic(win(t, dur - 0.25, dur)))
}

function sceneHeadline(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = Math.min(W, H) / 1080
  const portrait = H > W * 1.1
  ctx.fillStyle = linear(
    ctx,
    { x: 0, y: 0, w: W, h: H },
    [
      [0, BRAND.navy],
      [1, BRAND.blue],
    ],
    portrait ? Math.PI / 2.4 : 0.5,
  )
  ctx.fillRect(0, 0, W, H)
  bokeh(ctx, W, H, t, s)
  const exitP = ease.inCubic(win(t, dur - 0.55, dur))
  ctx.save()
  ctx.translate(0, -exitP * 60 * s)
  ctx.globalAlpha = 1 - exitP * 0.6

  const left = W * (portrait ? 0.08 : 0.1)
  const maxW = W - left * 2
  logoPill(ctx, env, W / 2, H * (portrait ? 0.2 : 0.17), (portrait ? 64 : 56) * s, win(t, 0.12, 0.55))

  const title = env.introTitle.trim() || 'মিহা নিউজ'
  const fit = fitText(ctx, title, (px) => bodyFont(700, px), maxW, portrait ? 5 : 3, (portrait ? 96 : 86) * s, 48 * s, 1.25)
  const blockH = fit.lines.length * fit.lineHeight
  const y0 = H * (portrait ? 0.47 : 0.5) - blockH / 2
  const kicker = env.kicker.trim() || 'সংবাদ'
  const chipH = 58 * s
  ctx.font = bodyFont(700, chipH * 0.56)
  const chipW = ctx.measureText(kicker).width + chipH * 0.8
  drawChip(ctx, kicker, W / 2 - chipW / 2, y0 - 76 * s, chipH, BRAND.red, '#FFFFFF', win(t, 0.45, 0.8))

  ctx.font = bodyFont(700, fit.size)
  ctx.fillStyle = '#FFFFFF'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  fit.lines.forEach((line, i) => {
    const p = ease.outCubic(win(t, 0.65 + i * 0.13, 1.1 + i * 0.13))
    const cy = y0 + fit.lineHeight * (i + 0.5)
    ctx.save()
    ctx.globalAlpha *= p
    ctx.fillText(line, W / 2, cy + (1 - p) * fit.lineHeight * 0.6)
    ctx.restore()
  })
  // accent bar
  const bar = ease.outCubic(win(t, 1.1, 1.6))
  const bw = Math.min(maxW, 260 * s) * bar
  ctx.fillStyle = BRAND.red
  ctx.fillRect(W / 2 - bw / 2, y0 + blockH + 26 * s, bw, 8 * s)
  // date + site
  ctx.globalAlpha *= win(t, 1.3, 1.8)
  ctx.font = bodyFont(500, 32 * s)
  ctx.fillStyle = '#CFE3FA'
  ctx.fillText([env.dateText, env.website].filter(Boolean).join('  •  '), W / 2, H * (portrait ? 0.86 : 0.88))
  ctx.restore()
  blackFade(ctx, W, H, 1 - win(t, 0, 0.18))
  blackFade(ctx, W, H, ease.inCubic(win(t, dur - 0.3, dur)))
}

function sceneSpecial(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = Math.min(W, H) / 1080
  const full = { x: 0, y: 0, w: W, h: H }
  ctx.fillStyle = linear(ctx, full, [
    [0, '#05090F'],
    [1, '#0F1F3D'],
  ], 0.9)
  ctx.fillRect(0, 0, W, H)
  stripes(ctx, full, t, s, 'rgba(232,179,58,0.04)', 44, 2, 18)
  // gold frame drawing itself
  const inset = Math.min(W, H) * 0.07
  const per = ease.inOutCubic(win(t, 0.1, 1.0))
  const rw = W - inset * 2
  const rh = H - inset * 2
  const total = 2 * (rw + rh)
  let left = total * per
  ctx.save()
  ctx.strokeStyle = '#E8B33A'
  ctx.lineWidth = 3 * s
  ctx.beginPath()
  ctx.moveTo(inset, inset)
  const pts: [number, number][] = [
    [inset + rw, inset],
    [inset + rw, inset + rh],
    [inset, inset + rh],
    [inset, inset],
  ]
  let px = inset
  let py = inset
  for (const [qx, qy] of pts) {
    const seg = Math.hypot(qx - px, qy - py)
    if (left <= 0) break
    const k = Math.min(1, left / seg)
    ctx.lineTo(lerp(px, qx, k), lerp(py, qy, k))
    left -= seg
    px = qx
    py = qy
  }
  ctx.stroke()
  ctx.restore()

  const cy = H * 0.44
  // title text in gold, revealed from the centre outwards
  const rev = ease.outCubic(win(t, 0.45, 1.1))
  if (rev > 0) {
    ctx.save()
    let size = 120 * s
    ctx.font = dispFont(800, size)
    const text = 'বিশেষ প্রতিবেদন'
    const tw = ctx.measureText(text).width
    if (tw > W * 0.8) size *= (W * 0.8) / tw
    ctx.font = dispFont(800, size)
    const w = ctx.measureText(text).width
    ctx.beginPath()
    ctx.rect(W / 2 - (w / 2 + 20 * s) * rev, cy - size, (w + 40 * s) * rev, size * 2)
    ctx.clip()
    const g = ctx.createLinearGradient(W / 2 - w / 2, 0, W / 2 + w / 2, 0)
    const p = (t * 0.6) % 1
    g.addColorStop(0, '#C8901F')
    g.addColorStop(clamp(p - 0.1), '#E8B33A')
    g.addColorStop(clamp(p), '#FFF1C2')
    g.addColorStop(clamp(p + 0.1), '#E8B33A')
    g.addColorStop(1, '#C8901F')
    ctx.fillStyle = g
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText(text, W / 2, cy)
    ctx.restore()
  }
  const title = env.introTitle.trim()
  if (title) {
    const a = ease.outCubic(win(t, 1.0, 1.5))
    ctx.save()
    ctx.globalAlpha = a
    const fit = fitText(ctx, title, (px) => bodyFont(700, px), W * 0.74, 3, 62 * s, 38 * s)
    ctx.font = bodyFont(700, fit.size)
    ctx.fillStyle = '#FFFFFF'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    fit.lines.forEach((l, i) => ctx.fillText(l, W / 2, cy + 120 * s + fit.lineHeight * (i + 0.5) + (1 - a) * 30 * s))
    ctx.restore()
  }
  ctx.save()
  ctx.globalAlpha = win(t, 1.2, 1.7) * 0.9
  const lh = 44 * s
  const lw = (env.logo.w / env.logo.h) * lh
  ctx.drawImage(env.logo.white, W / 2 - lw / 2, H - inset - lh - 40 * s, lw, lh)
  ctx.restore()
  blackFade(ctx, W, H, 1 - win(t, 0, 0.2))
  blackFade(ctx, W, H, ease.inCubic(win(t, dur - 0.45, dur)))
}

function sceneThanks(ctx: Ctx, env: DrawEnv, t: number, dur: number) {
  const { W, H } = env.L
  const s = Math.min(W, H) / 1080
  ctx.fillStyle = linear(ctx, { x: 0, y: 0, w: W, h: H }, [
    [0, BRAND.blue],
    [1, BRAND.navy],
  ], 1)
  ctx.fillRect(0, 0, W, H)
  bokeh(ctx, W, H, t + 3, s, 18)
  logoPill(ctx, env, W / 2, H * 0.3, 70 * s, win(t, 0.15, 0.6))
  const a = ease.outCubic(win(t, 0.5, 0.95))
  ctx.save()
  ctx.globalAlpha = a
  let size = 104 * s
  ctx.font = dispFont(800, size)
  const text = 'দেখার জন্য ধন্যবাদ'
  const tw = ctx.measureText(text).width
  if (tw > W * 0.86) size *= (W * 0.86) / tw
  ctx.font = dispFont(800, size)
  ctx.fillStyle = '#FFFFFF'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText(text, W / 2, H * 0.5 + (1 - a) * 40 * s)
  ctx.globalAlpha = ease.outCubic(win(t, 0.8, 1.2))
  ctx.font = bodyFont(500, 38 * s)
  ctx.fillStyle = '#CFE3FA'
  ctx.fillText('আরও খবর পেতে চোখ রাখুন মিহা নিউজে', W / 2, H * 0.5 + 100 * s, W * 0.9)
  // website button with a soft pulse
  const b = ease.outBack(win(t, 1.0, 1.4))
  if (b > 0) {
    const pulse = 1 + 0.04 * Math.sin(Math.max(0, t - 1.4) * Math.PI * 2 * 0.9)
    ctx.translate(W / 2, H * 0.5 + 210 * s)
    ctx.scale(b * pulse, b * pulse)
    ctx.font = bodyFont(700, 42 * s)
    const site = env.website || 'meeha.news'
    const bw = ctx.measureText(site).width + 90 * s
    const bh = 84 * s
    ctx.globalAlpha = 1
    fillRoundRect(ctx, -bw / 2, -bh / 2, bw, bh, bh / 2, '#FFFFFF')
    ctx.fillStyle = BRAND.blue
    ctx.fillText(site, 0, 3 * s)
  }
  ctx.restore()
  blackFade(ctx, W, H, 1 - win(t, 0, 0.25))
  blackFade(ctx, W, H, ease.inCubic(win(t, dur - 0.4, dur)))
}

function sceneAdCard(ctx: Ctx, env: DrawEnv, t: number, dur: number, into: boolean) {
  const { W, H } = env.L
  const s = Math.min(W, H) / 1080
  ctx.fillStyle = into ? '#0B0F19' : BRAND.navy
  ctx.fillRect(0, 0, W, H)
  if (!into) bokeh(ctx, W, H, t, s, 10)
  const a = ease.outCubic(win(t, 0.05, 0.35))
  ctx.save()
  ctx.globalAlpha = a
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  if (into) {
    ctx.font = dispFont(800, 88 * s)
    const text = 'বিজ্ঞাপন বিরতি'
    const tw = ctx.measureText(text).width
    fillRoundRect(ctx, W / 2 - tw / 2 - 40 * s, H / 2 - 80 * s, tw + 80 * s, 130 * s, 18 * s, '#FACC15')
    ctx.fillStyle = '#0B0F19'
    ctx.fillText(text, W / 2, H / 2 - 12 * s)
    ctx.font = bodyFont(500, 34 * s)
    ctx.fillStyle = '#CBD5E1'
    ctx.fillText('বিজ্ঞাপনের পর সংবাদ চলবে', W / 2, H / 2 + 110 * s)
    const pw = Math.min(W * 0.5, 420 * s)
    ctx.fillStyle = 'rgba(255,255,255,0.15)'
    ctx.fillRect(W / 2 - pw / 2, H / 2 + 170 * s, pw, 6 * s)
    ctx.fillStyle = '#FACC15'
    ctx.fillRect(W / 2 - pw / 2, H / 2 + 170 * s, pw * win(t, 0.1, dur - 0.1), 6 * s)
  } else {
    const lh = 60 * s
    const mw = (env.logo.markW / env.logo.h) * lh
    ctx.drawImage(env.logo.white, 0, 0, env.logo.markW, env.logo.h, W / 2 - mw / 2, H / 2 - 120 * s, mw, lh)
    ctx.font = dispFont(800, 80 * s)
    ctx.fillStyle = '#FFFFFF'
    ctx.fillText('সংবাদে ফিরছি', W / 2, H / 2 + 20 * s)
  }
  ctx.restore()
  blackFade(ctx, W, H, 1 - win(t, 0, 0.15))
  blackFade(ctx, W, H, win(t, dur - 0.15, dur))
}

export function drawScene(ctx: Ctx, env: DrawEnv, id: SceneId, t: number, dur: number) {
  switch (id) {
    case 'breaking':
      return sceneBreaking(ctx, env, t, dur)
    case 'headline':
      return sceneHeadline(ctx, env, t, dur)
    case 'special':
      return sceneSpecial(ctx, env, t, dur)
    case 'thanks':
      return sceneThanks(ctx, env, t, dur)
    case 'ad-in':
      return sceneAdCard(ctx, env, t, dur, true)
    case 'ad-out':
      return sceneAdCard(ctx, env, t, dur, false)
  }
}

// ---------------------------------------------------------------------------
// One output frame
// ---------------------------------------------------------------------------

function drawMain(ctx: Ctx, env: DrawEnv, seg: Segment, local: number, pic: Picture | null) {
  const { L, cfg } = env
  const mainT = seg.mainOffset + local
  const theme = themeOf(cfg.frame.id)
  if (theme) {
    ctx.fillStyle = '#000'
    ctx.fillRect(0, 0, L.W, L.H)
  }
  drawPicture(ctx, env, pic, L.video, cfg.fit)
  // video fades up from black at the very start of the news
  if (seg.mainOffset === 0) blackFade(ctx, L.W, L.H, 1 - win(local, 0, 0.3))

  if (theme) {
    if (L.header) drawHeader(ctx, env, theme, L.header, mainT)
    if (L.footer) drawFooter(ctx, env, theme, L.footer, mainT)
    if (theme.edge && L.header) {
      const p = ease.outCubic(win(mainT, 0.2, 0.7))
      ctx.fillStyle = theme.edge
      ctx.fillRect(L.header.x, L.header.y + L.header.h - 5 * L.s, L.header.w * p, 5 * L.s)
    }
    if (cfg.frame.id === 'special') drawBrackets(ctx, L.video, mainT, L.s, '#E8B33A')
  }

  drawLogoBug(ctx, env, mainT)
  const src = seg.clipStart + local
  const srcEnd = env.tl.segments.reduce((m, x) => (x.kind === 'main' ? Math.max(m, x.clipStart + x.dur) : m), 0)
  const stacks: Record<string, number> = {}
  for (const c of cfg.captions) {
    const dur = c.dur ?? Math.max(1, srcEnd - c.at)
    if (src < c.at || src >= c.at + dur) continue
    const used = drawCaption(ctx, env, c, src - c.at, c.dur === null ? dur + 1 : dur, theme?.accent ?? BRAND.red, stacks[c.pos] ?? 0)
    stacks[c.pos] = (stacks[c.pos] ?? 0) + used
  }
}

function drawClipFull(ctx: Ctx, env: DrawEnv, pic: Picture | null, local: number, dur: number, fadeIn: boolean) {
  const { W, H } = env.L
  drawPicture(ctx, env, pic, { x: 0, y: 0, w: W, h: H }, 'blur')
  if (fadeIn) blackFade(ctx, W, H, 1 - win(local, 0, 0.2))
  blackFade(ctx, W, H, win(local, dur - 0.2, dur))
}

function drawAdTag(ctx: Ctx, env: DrawEnv) {
  const s = env.L.s
  ctx.save()
  ctx.font = bodyFont(700, 26 * s)
  const w = ctx.measureText('বিজ্ঞাপন').width + 36 * s
  fillRoundRect(ctx, 24 * s, 24 * s, w, 46 * s, 10 * s, 'rgba(250,204,21,0.95)')
  ctx.fillStyle = '#0B0F19'
  ctx.textBaseline = 'middle'
  ctx.fillText('বিজ্ঞাপন', 42 * s, 48 * s)
  ctx.restore()
}

/** Paints output time T. `pic` is the current picture of the segment's clip (if any). */
export function drawOutputFrame(ctx: Ctx, env: DrawEnv, seg: Segment, T: number, pic: Picture | null) {
  const local = clamp(T - seg.t0, 0, seg.dur)
  ctx.save()
  ctx.globalAlpha = 1
  ctx.globalCompositeOperation = 'source-over'
  ctx.textAlign = 'left'
  switch (seg.kind) {
    case 'main':
      drawMain(ctx, env, seg, local, pic)
      break
    case 'intro':
    case 'outro':
      if (seg.scene) drawScene(ctx, env, seg.scene, local, seg.dur)
      else drawClipFull(ctx, env, pic, local, seg.dur, seg.kind === 'outro')
      break
    case 'ad':
      drawClipFull(ctx, env, pic, local, seg.dur, true)
      drawAdTag(ctx, env)
      break
    case 'card':
      if (seg.scene) drawScene(ctx, env, seg.scene, local, seg.dur)
      break
  }
  ctx.restore()
}

/** Sound-effect cues on the output timeline (whoosh / hit / chime). */
export type SfxKind = 'whoosh' | 'hit' | 'chime' | 'soft'
export function sfxCues(tl: Timeline, cfg: EditConfig): { t: number; kind: SfxKind; gain: number }[] {
  if (!cfg.audio.sfx) return []
  const cues: { t: number; kind: SfxKind; gain: number }[] = []
  for (const s of tl.segments) {
    if (s.scene === 'breaking') cues.push({ t: s.t0, kind: 'whoosh', gain: 0.8 }, { t: s.t0 + 0.17, kind: 'hit', gain: 1 }, { t: s.t0 + s.dur - 0.5, kind: 'whoosh', gain: 0.6 })
    else if (s.scene === 'headline') cues.push({ t: s.t0 + 0.05, kind: 'whoosh', gain: 0.6 }, { t: s.t0 + 0.6, kind: 'chime', gain: 0.6 })
    else if (s.scene === 'special') cues.push({ t: s.t0 + 0.08, kind: 'hit', gain: 0.6 }, { t: s.t0 + 0.5, kind: 'chime', gain: 0.55 })
    else if (s.scene === 'thanks') cues.push({ t: s.t0 + 0.45, kind: 'chime', gain: 0.55 })
    else if (s.scene === 'ad-in' || s.scene === 'ad-out') cues.push({ t: s.t0, kind: 'soft', gain: 0.5 })
  }
  if (cfg.frame.id !== 'none') cues.push({ t: tl.mainStart, kind: 'whoosh', gain: 0.35 })
  return cues
}
