/**
 * Round 23, rebuilt in round 26 — the thumbnail designer.
 *
 * A scene of the news (with its colour, zoom and privacy boxes) under one of
 * 26 designs in six families, in any of four shapes (16:9, 9:16, 1:1, 4:5)
 * or the video's own. Like the photocards, **marked** words in the headline
 * are highlighted in the accent colour, a palette recolours the design, and
 * free layers (text, burst badge, arrow, circle, box …) sit on top and are
 * dragged on the preview. The same painter draws the live preview, the
 * gallery cards, the JPEG put on the post and the "all sizes" download.
 */
import { ALL_FORMATS, BlobSource, CanvasSink, Input } from 'mediabunny'
import { FONT_BODY, FONT_DISPLAY } from './assets'
import { THUMB_PALETTES, type EditConfig, type Sticker, type ThumbLayer, type ThumbPaletteId, type ThumbSize, type ThumbTemplate } from './config'
import { coverRect, drawMainPicture, type DrawEnv, type Picture } from './draw'
import { drawSticker } from './overlays'
import { fillRoundRect, type Ctx } from './motion'
import type { Layout, Rect } from './timeline'

const disp = (px: number) => `800 ${px}px ${FONT_DISPLAY}`
const body = (w: number, px: number) => `${w} ${px}px ${FONT_BODY}`

// ---------------------------------------------------------------------------
// Sizes
// ---------------------------------------------------------------------------

/** Pixel size of one thumbnail shape (`L` is needed for 'video'). */
export function thumbDims(size: ThumbSize, L: { W: number; H: number }): { W: number; H: number } {
  switch (size) {
    case '16:9':
      return { W: 1280, H: 720 }
    case '9:16':
      return { W: 1080, H: 1920 }
    case '1:1':
      return { W: 1080, H: 1080 }
    case '4:5':
      return { W: 1080, H: 1350 }
    default: {
      const k = 1280 / Math.max(L.W, L.H)
      return { W: Math.round(L.W * k), H: Math.round(L.H * k) }
    }
  }
}

/** Output size of the post's thumbnail. */
export function thumbSize(cfg: EditConfig, L: Layout): { W: number; H: number } {
  return thumbDims(cfg.thumb.size, L)
}

/** The source second the thumbnail picture comes from. */
export function thumbSource(cfg: EditConfig, start: number, end: number): number {
  const at = cfg.thumb.at
  if (at !== null && at >= start && at <= end) return at
  return Math.min(end, start + Math.min(2.5, (end - start) / 3))
}

// ---------------------------------------------------------------------------
// Palettes
// ---------------------------------------------------------------------------

interface Pal {
  dark: string
  mid: string
  accent: string
  hot: string
}

/** The palette each design uses when the palette is "নকশার নিজের". */
const AUTO_PAL: Partial<Record<ThumbTemplate, ThumbPaletteId>> = {
  alert: 'night',
  exclusive: 'night',
  shock: 'night',
  sports: 'green',
  economy: 'green',
  showbiz: 'purple',
}

function palette(id: ThumbPaletteId, template: ThumbTemplate): Pal {
  const want = id === 'auto' ? (AUTO_PAL[template] ?? 'nil') : id
  const p = THUMB_PALETTES.find((x) => x.id === want) ?? THUMB_PALETTES[1]
  return { dark: p.dark, mid: p.mid, accent: p.accent, hot: p.hot }
}

/** Dark or white text, whichever reads on `bg`. */
function inkOn(bg: string) {
  const m = /^#([0-9a-f]{6})$/i.exec(bg)
  if (!m) return '#fff'
  const n = parseInt(m[1], 16)
  const lum = 0.299 * ((n >> 16) & 255) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)
  return lum > 150 ? '#111111' : '#FFFFFF'
}

function rgba(hex: string, a: number) {
  const n = parseInt(hex.slice(1), 16)
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`
}

// ---------------------------------------------------------------------------
// Rich text: **marked** words are highlighted, like the photocards
// ---------------------------------------------------------------------------

interface Seg {
  t: string
  hi: boolean
}
/** One word; a word can change colour midway ("**বৃষ্টি**," keeps its comma). */
interface Word {
  segs: Seg[]
}
const wt = (w: Word) => w.segs.map((x) => x.t).join('')
const lineText = (l: Word[]) => l.map(wt).join(' ')
interface Rich {
  size: number
  lineHeight: number
  lines: Word[][]
  widths: number[]
  /** Height of the block. */
  h: number
  /** Widest line. */
  w: number
}

function parseRich(text: string): Word[] {
  const out: Word[] = []
  let glue = false
  text.split('**').forEach((part, i) => {
    const hi = i % 2 === 1
    const toks = part.split(/\s+/)
    toks.forEach((t, j) => {
      if (!t) return
      if (j === 0 && glue && out.length) out[out.length - 1].segs.push({ t, hi })
      else out.push({ segs: [{ t, hi }] })
    })
    // the next part sticks to this word unless whitespace separates them
    glue = part.length > 0 ? !/\s$/.test(part) : glue
  })
  return out
}

/** Plain text of a marked headline (for places that cannot highlight). */
export function plainTitle(text: string) {
  return text.split('**').join('')
}

function wrapWords(ctx: Ctx, words: Word[], maxW: number): Word[][] {
  const lines: Word[][] = []
  let cur: Word[] = []
  for (const w of words) {
    const next = [...cur, w]
    if (cur.length && ctx.measureText(lineText(next)).width > maxW) {
      lines.push(cur)
      cur = [w]
    } else cur = next
  }
  if (cur.length) lines.push(cur)
  return lines
}

function richFit(ctx: Ctx, text: string, font: (px: number) => string, maxW: number, maxLines: number, max: number, min: number, lh = 1.2, maxH = Infinity): Rich {
  const words = parseRich(text)
  const done = (size: number, lines: Word[][]): Rich => {
    ctx.font = font(size)
    const widths = lines.map((l) => ctx.measureText(lineText(l)).width)
    return { size, lineHeight: size * lh, lines, widths, h: lines.length * size * lh, w: Math.max(0, ...widths) }
  }
  if (!words.length) return done(max, [])
  const step = Math.max(1, (max - min) / 16)
  for (let size = max; size >= min - 0.01; size -= step) {
    ctx.font = font(size)
    const lines = wrapWords(ctx, words, maxW)
    if (lines.length <= maxLines && lines.length * size * lh <= maxH && lines.every((l) => ctx.measureText(lineText(l)).width <= maxW)) return done(size, lines)
  }
  ctx.font = font(min)
  const lines = wrapWords(ctx, words, maxW).slice(0, Math.max(1, Math.min(maxLines, Math.floor(maxH / (min * lh)))))
  const last = lines[lines.length - 1]
  if (last) {
    // drop words until the ellipsis fits
    while (last.length > 1 && ctx.measureText(lineText(last) + '…').width > maxW) last.pop()
    const lw = last[last.length - 1]
    const ls = lw.segs[lw.segs.length - 1]
    last[last.length - 1] = { segs: [...lw.segs.slice(0, -1), { ...ls, t: ls.t + '…' }] }
  }
  return done(min, lines)
}

interface RichStyle {
  color: string
  hi: string
  align?: 'left' | 'center' | 'right'
  shadow?: boolean
  /** Outline colour (YouTube-style thick edge). */
  stroke?: string
  /** Highlight as a marker behind the word (text in `markerInk`). */
  marker?: boolean
  markerInk?: string
}

/** Draws a fitted block with its top-left (or top-centre/right) at x, y. */
function drawRich(ctx: Ctx, r: Rich, font: (px: number) => string, x: number, y: number, st: RichStyle) {
  ctx.font = font(r.size)
  ctx.textBaseline = 'alphabetic'
  ctx.textAlign = 'left'
  const space = ctx.measureText(' ').width
  r.lines.forEach((line, i) => {
    const lw = r.widths[i]
    let cx = st.align === 'center' ? x - lw / 2 : st.align === 'right' ? x - lw : x
    const base = y + i * r.lineHeight + r.size * 0.98
    for (const word of line) {
      for (const w of word.segs) {
        const ww = ctx.measureText(w.t).width
        if (w.hi && st.marker) {
          ctx.fillStyle = st.hi
          ctx.fillRect(cx - space * 0.35, base - r.size * 0.86, ww + space * 0.7, r.size * 1.08)
        }
        if (st.stroke) {
          ctx.lineJoin = 'round'
          ctx.lineWidth = r.size * 0.16
          ctx.strokeStyle = st.stroke
          ctx.strokeText(w.t, cx, base)
        }
        if (st.shadow) {
          ctx.shadowColor = 'rgba(0,0,0,0.55)'
          ctx.shadowBlur = r.size * 0.14
          ctx.shadowOffsetY = r.size * 0.04
        }
        ctx.fillStyle = w.hi ? (st.marker ? (st.markerInk ?? '#111') : st.hi) : st.color
        ctx.fillText(w.t, cx, base)
        ctx.shadowColor = 'transparent'
        ctx.shadowBlur = 0
        ctx.shadowOffsetY = 0
        cx += ww
      }
      cx += space
    }
  })
}

// ---------------------------------------------------------------------------
// Shared pieces
// ---------------------------------------------------------------------------

type Corner = 'tl' | 'tr' | 'bl' | 'br'

interface TC {
  ctx: Ctx
  env: DrawEnv
  W: number
  H: number
  /** 1 at a 720-pixel short side. */
  u: number
  wide: boolean
  tall: boolean
  pad: number
  title: string
  badge: string
  sub: string
  pal: Pal
  pic: Picture | null
  face: Picture | null
  src: number
  scale: number
  grade: EditConfig['thumb']['grade']
  /** Where the design puts the logo (the user can move it). */
  logo: { corner: Corner; plate: boolean; inset: number }
}

/** Max headline lines by shape. */
const lines = (c: TC, wide: number, square: number, tall: number) => (c.wide ? wide : c.tall ? tall : square)

function fitTitle(c: TC, maxW: number, maxLines: number, maxPx: number, minPx = 30, font = disp, lh = 1.18, maxH = Infinity) {
  return richFit(c.ctx, c.title, font, maxW, maxLines, maxPx * c.u * c.scale, minPx * c.u, lh, maxH)
}

/** The scene (cropped to fill `r`), with the picture punch applied. */
function picture(c: TC, r: Rect, pic: Picture | null = c.pic) {
  const { ctx } = c
  const L2: Layout = { ...c.env.L, W: c.W, H: c.H, s: Math.min(c.W, c.H) / 1080, k: 1, header: null, footer: null, video: { ...r } }
  const e2: DrawEnv = { ...c.env, L: L2, cfg: { ...c.env.cfg, fit: 'cover', stickers: [] } }
  ctx.save()
  if (pic === c.pic) drawMainPicture(ctx, e2, pic, r, c.src)
  else cover(ctx, pic, r)
  if (c.grade === 'pop') {
    ctx.beginPath()
    ctx.rect(r.x, r.y, r.w, r.h)
    ctx.clip()
    ctx.filter = 'saturate(1.35) contrast(1.12)'
    ctx.drawImage(ctx.canvas as CanvasImageSource, r.x, r.y, r.w, r.h, r.x, r.y, r.w, r.h)
    ctx.filter = 'none'
  } else if (c.grade === 'dark') {
    ctx.fillStyle = 'rgba(0,0,0,0.32)'
    ctx.fillRect(r.x, r.y, r.w, r.h)
  }
  ctx.restore()
}

/** A picture cropped to fill `r` (faces sit high in portrait photos). */
function cover(ctx: Ctx, pic: Picture | null, r: Rect) {
  ctx.save()
  ctx.beginPath()
  ctx.rect(r.x, r.y, r.w, r.h)
  ctx.clip()
  ctx.fillStyle = '#111'
  ctx.fillRect(r.x, r.y, r.w, r.h)
  if (pic && pic.w > 0) {
    const d = coverRect(pic.w, pic.h, r)
    if (pic.h > pic.w) d.y = Math.min(r.y, d.y + (d.h - r.h) * 0.3)
    ctx.drawImage(pic.src, d.x, d.y, d.w, d.h)
  }
  ctx.restore()
}

function circle(c: TC, pic: Picture | null, cx: number, cy: number, r: number, ring = c.pal.accent) {
  const { ctx } = c
  ctx.save()
  ctx.beginPath()
  ctx.arc(cx, cy, r, 0, Math.PI * 2)
  ctx.clip()
  cover(ctx, pic, { x: cx - r, y: cy - r, w: r * 2, h: r * 2 })
  ctx.restore()
  ctx.lineWidth = r * 0.07
  ctx.strokeStyle = '#fff'
  ctx.beginPath()
  ctx.arc(cx, cy, r, 0, Math.PI * 2)
  ctx.stroke()
  ctx.lineWidth = r * 0.05
  ctx.strokeStyle = ring
  ctx.beginPath()
  ctx.arc(cx, cy, r * 1.08, -Math.PI * 0.85, Math.PI * 0.25)
  ctx.stroke()
}

function shade(c: TC, from: number, to: number, color: string, a0: number, a1: number) {
  const g = c.ctx.createLinearGradient(0, from, 0, to)
  g.addColorStop(0, rgba(color, a0))
  g.addColorStop(1, rgba(color, a1))
  c.ctx.fillStyle = g
  c.ctx.fillRect(0, Math.min(from, to), c.W, Math.abs(to - from))
}

function pill(c: TC, text: string, x: number, y: number, fill: string, px = 34, align: 'left' | 'center' | 'right' = 'left') {
  if (!text) return { w: 0, h: 0 }
  const { ctx, u } = c
  ctx.font = body(700, px * u)
  const w = ctx.measureText(text).width + 40 * u
  const h = px * 1.7 * u
  const x0 = align === 'center' ? x - w / 2 : align === 'right' ? x - w : x
  fillRoundRect(ctx, x0, y, w, h, 10 * u, fill)
  ctx.fillStyle = inkOn(fill)
  ctx.textBaseline = 'middle'
  ctx.textAlign = 'left'
  ctx.fillText(text, x0 + 20 * u, y + h / 2 + 2 * u)
  return { w, h }
}

function smallText(c: TC, text: string, x: number, y: number, color: string, px = 30, align: 'left' | 'center' | 'right' = 'left', w = 600) {
  if (!text) return
  const { ctx } = c
  ctx.font = body(w, px * c.u)
  ctx.fillStyle = color
  ctx.textAlign = align
  ctx.textBaseline = 'top'
  ctx.fillText(text, x, y)
  ctx.textAlign = 'left'
}

function star(ctx: Ctx, cx: number, cy: number, r: number, points: number, inner: number, rot = 0) {
  ctx.beginPath()
  for (let i = 0; i < points * 2; i++) {
    const a = rot + (i * Math.PI) / points - Math.PI / 2
    const rr = i % 2 ? r * inner : r
    const x = cx + Math.cos(a) * rr
    const y = cy + Math.sin(a) * rr
    if (i) ctx.lineTo(x, y)
    else ctx.moveTo(x, y)
  }
  ctx.closePath()
}

/** A starburst badge with a word in it. */
function burst(ctx: Ctx, cx: number, cy: number, r: number, fill: string, text: string, rot = -0.12) {
  ctx.save()
  ctx.translate(cx, cy)
  ctx.rotate(rot)
  ctx.shadowColor = 'rgba(0,0,0,0.4)'
  ctx.shadowBlur = r * 0.12
  star(ctx, 0, 0, r, 14, 0.8)
  ctx.fillStyle = fill
  ctx.fill()
  ctx.shadowColor = 'transparent'
  ctx.lineWidth = r * 0.06
  ctx.strokeStyle = '#fff'
  ctx.stroke()
  if (text) {
    let px = r * 0.55
    ctx.font = disp(px)
    while (px > r * 0.2 && ctx.measureText(text).width > r * 1.45) {
      px *= 0.92
      ctx.font = disp(px)
    }
    ctx.fillStyle = inkOn(fill)
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText(text, 0, px * 0.06)
  }
  ctx.restore()
}

/** Thick curved arrow from a to b (head at b). */
function bigArrow(ctx: Ctx, ax: number, ay: number, bx: number, by: number, color: string, width: number, bend = 0.25) {
  const mx = (ax + bx) / 2 - (by - ay) * bend
  const my = (ay + by) / 2 + (bx - ax) * bend
  const ang = Math.atan2(by - my, bx - mx)
  const head = width * 2.6
  const ex = bx - Math.cos(ang) * head * 0.8
  const ey = by - Math.sin(ang) * head * 0.8
  ctx.save()
  ctx.lineCap = 'round'
  ctx.strokeStyle = '#fff'
  ctx.lineWidth = width + width * 0.6
  ctx.beginPath()
  ctx.moveTo(ax, ay)
  ctx.quadraticCurveTo(mx, my, ex, ey)
  ctx.stroke()
  ctx.strokeStyle = color
  ctx.lineWidth = width
  ctx.stroke()
  const tri = () => {
    ctx.beginPath()
    ctx.moveTo(bx, by)
    ctx.lineTo(bx - Math.cos(ang - 0.45) * head, by - Math.sin(ang - 0.45) * head)
    ctx.lineTo(bx - Math.cos(ang + 0.45) * head, by - Math.sin(ang + 0.45) * head)
    ctx.closePath()
  }
  tri()
  ctx.lineJoin = 'round'
  ctx.lineWidth = width * 0.6
  ctx.strokeStyle = '#fff'
  ctx.stroke()
  ctx.fillStyle = color
  ctx.fill()
  ctx.restore()
}

function hGrad(c: TC, x0: number, x1: number, a: string, b: string) {
  const g = c.ctx.createLinearGradient(x0, 0, x1, 0)
  g.addColorStop(0, a)
  g.addColorStop(1, b)
  return g
}

function drawLogo(c: TC, corner: Corner, plate: boolean, inset: number) {
  const { ctx, env, u, W, H } = c
  const h = (c.wide ? 50 : 56) * u
  const w = (env.logo.w / env.logo.h) * h
  const m = c.pad * 0.8
  const x = corner === 'tl' || corner === 'bl' ? m : W - m - w
  const y = corner === 'tl' || corner === 'tr' ? m + inset : H - m - h - inset
  // a soft dark pill keeps the white logo readable over bright pictures
  fillRoundRect(ctx, x - 14 * u, y - 12 * u, w + 28 * u, h + 24 * u, 12 * u, plate ? 'rgba(255,255,255,0.94)' : 'rgba(0,0,0,0.3)')
  ctx.save()
  if (!plate) {
    ctx.shadowColor = 'rgba(0,0,0,0.45)'
    ctx.shadowBlur = 10 * u
  }
  ctx.drawImage(plate ? env.logo.color : env.logo.white, x, y, w, h)
  ctx.restore()
}

// ---------------------------------------------------------------------------
// The designs
// ---------------------------------------------------------------------------

const FULL = (c: TC): Rect => ({ x: 0, y: 0, w: c.W, h: c.H })

const DESIGNS: Record<ThumbTemplate, (c: TC) => void> = {
  // ---- মিহা নীল ----
  'nil-bar': (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    const ft = fitTitle(c, W - pad * 2, lines(c, 2, 3, 4), c.wide ? 76 : 84)
    const subH = c.sub ? 44 * u : 0
    const bandH = ft.h + pad * 1.15 + subH
    const y0 = H - bandH
    shade(c, y0 - H * 0.25, y0, pal.dark, 0, 0.55)
    ctx.fillStyle = hGrad(c, 0, W, rgba(pal.dark, 0.96), rgba(pal.mid, 0.94))
    ctx.fillRect(0, y0, W, bandH)
    ctx.fillStyle = pal.accent
    ctx.fillRect(pad, y0 - 5 * u, 170 * u, 10 * u)
    smallText(c, c.sub, pad, y0 + pad * 0.45, pal.accent, 30, 'left', 700)
    drawRich(ctx, ft, disp, pad, y0 + pad * 0.5 + subH, { color: '#fff', hi: pal.accent })
    if (c.badge) pill(c, c.badge, pad, pad, pal.hot)
    c.logo = { corner: 'tr', plate: false, inset: 0 }
  },
  'nil-split': (c) => {
    const { ctx, W, H, u, pad, pal } = c
    if (c.wide) {
      const x0 = W * 0.54
      picture(c, { x: 0, y: 0, w: W * 0.6, h: H })
      ctx.fillStyle = hGrad(c, x0, W, pal.dark, pal.mid)
      ctx.beginPath()
      ctx.moveTo(x0 + 70 * u, 0)
      ctx.lineTo(W, 0)
      ctx.lineTo(W, H)
      ctx.lineTo(x0, H)
      ctx.closePath()
      ctx.fill()
      ctx.strokeStyle = pal.accent
      ctx.lineWidth = 12 * u
      ctx.beginPath()
      ctx.moveTo(x0 + 70 * u, 0)
      ctx.lineTo(x0, H)
      ctx.stroke()
      const tx = x0 + 80 * u
      const ft = fitTitle(c, W - tx - pad * 0.8, 5, 62, 28)
      const subH = c.sub ? 46 * u : 0
      const top = (H - ft.h - subH) / 2 + 20 * u
      smallText(c, c.sub, tx, top, pal.accent, 30, 'left', 700)
      drawRich(ctx, ft, disp, tx, top + subH, { color: '#fff', hi: pal.accent })
      if (c.badge) pill(c, c.badge, pad, pad, pal.hot)
      c.logo = { corner: 'br', plate: false, inset: 0 }
      return
    }
    const y0 = H * (c.tall ? 0.52 : 0.5)
    picture(c, { x: 0, y: 0, w: W, h: y0 + 60 * u })
    ctx.fillStyle = hGrad(c, 0, W, pal.dark, pal.mid)
    ctx.beginPath()
    ctx.moveTo(0, y0 + 60 * u)
    ctx.lineTo(W, y0)
    ctx.lineTo(W, H)
    ctx.lineTo(0, H)
    ctx.closePath()
    ctx.fill()
    ctx.strokeStyle = pal.accent
    ctx.lineWidth = 12 * u
    ctx.beginPath()
    ctx.moveTo(0, y0 + 60 * u)
    ctx.lineTo(W, y0)
    ctx.stroke()
    const subH = c.sub ? 50 * u : 0
    const ft = fitTitle(c, W - pad * 2, lines(c, 3, 4, 6), 84, 26, disp, 1.18, H - y0 - 60 * u - subH - pad * 1.2)
    const top = y0 + 60 * u + (H - y0 - 60 * u - ft.h - subH) / 2
    smallText(c, c.sub, pad, top, pal.accent, 32, 'left', 700)
    drawRich(ctx, ft, disp, pad, top + subH, { color: '#fff', hi: pal.accent })
    if (c.badge) pill(c, c.badge, pad, pad, pal.hot)
    c.logo = { corner: 'tr', plate: false, inset: 0 }
  },
  'nil-frame': (c) => {
    const { ctx, W, H, u, pad, pal } = c
    ctx.fillStyle = hGrad(c, 0, W, pal.dark, pal.mid)
    ctx.fillRect(0, 0, W, H)
    const m = pad * 0.8
    const ih = H * (c.wide ? 0.6 : c.tall ? 0.5 : 0.55)
    const r: Rect = { x: m, y: m, w: W - m * 2, h: ih }
    picture(c, r)
    // amber corner brackets
    const k = 60 * u
    ctx.strokeStyle = pal.accent
    ctx.lineWidth = 10 * u
    ctx.lineCap = 'square'
    const br = (x: number, y: number, dx: number, dy: number) => {
      ctx.beginPath()
      ctx.moveTo(x + dx * k, y)
      ctx.lineTo(x, y)
      ctx.lineTo(x, y + dy * k)
      ctx.stroke()
    }
    const o = 12 * u
    br(r.x - o, r.y - o, 1, 1)
    br(r.x + r.w + o, r.y - o, -1, 1)
    br(r.x - o, r.y + r.h + o, 1, -1)
    br(r.x + r.w + o, r.y + r.h + o, -1, -1)
    const ty = r.y + r.h + pad * 0.9
    const ft = fitTitle(c, W - pad * 2, lines(c, 2, 3, 4), c.wide ? 64 : 76, 26)
    const subH = c.sub ? 44 * u : 0
    const top = ty + Math.max(0, (H - ty - pad * 0.6 - ft.h - subH) / 2)
    smallText(c, c.sub, W / 2, top, pal.accent, 30, 'center', 700)
    drawRich(ctx, ft, disp, W / 2, top + subH, { color: '#fff', hi: pal.accent, align: 'center' })
    if (c.badge) pill(c, c.badge, r.x + 24 * u, r.y + r.h - 70 * u, pal.hot)
    c.logo = { corner: 'tr', plate: false, inset: m }
  },
  'nil-quote': (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    if (c.wide) {
      const g = ctx.createLinearGradient(0, 0, W, 0)
      g.addColorStop(0, rgba(pal.dark, 0.95))
      g.addColorStop(0.62, rgba(pal.dark, 0.82))
      g.addColorStop(1, rgba(pal.dark, 0.15))
      ctx.fillStyle = g
      ctx.fillRect(0, 0, W, H)
    } else shade(c, H * 0.25, H, pal.dark, 0.15, 0.96)
    const maxW = c.wide ? W * 0.6 : W - pad * 2
    const ft = fitTitle(c, maxW, lines(c, 4, 5, 6), c.wide ? 56 : 66, 26, (px) => body(700, px), 1.32)
    const qPx = (c.wide ? 210 : 240) * u
    const nameH = c.sub ? 56 * u : 0
    const top = c.wide ? Math.max(pad + qPx * 0.55, (H - ft.h - nameH) / 2 + qPx * 0.2) : H - pad - nameH - ft.h
    ctx.font = disp(qPx)
    ctx.fillStyle = pal.accent
    ctx.textBaseline = 'alphabetic'
    ctx.textAlign = 'left'
    ctx.fillText('“', pad - 8 * u, top + qPx * 0.12)
    drawRich(ctx, ft, (px) => body(700, px), pad, top, { color: '#fff', hi: pal.accent })
    if (c.sub) {
      ctx.fillStyle = pal.accent
      ctx.fillRect(pad, top + ft.h + 22 * u, 44 * u, 6 * u)
      smallText(c, c.sub, pad + 58 * u, top + ft.h + 6 * u, '#fff', 32, 'left', 700)
    }
    if (c.face && c.wide) circle(c, c.face, W * 0.82, H * 0.5, H * 0.26)
    if (c.badge) pill(c, c.badge, W - pad, pad, pal.hot, 30, 'right')
    c.logo = { corner: c.wide ? (c.badge ? 'br' : 'tr') : 'tl', plate: false, inset: 0 }
  },
  'nil-card': (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    shade(c, H * 0.4, H, '#000000', 0, 0.45)
    const m = pad * 0.8
    const ft = fitTitle(c, W - m * 2 - pad * 1.2, lines(c, 2, 3, 4), c.wide ? 66 : 76)
    const cardH = ft.h + pad * 1.3
    const y0 = H - m - cardH
    ctx.save()
    ctx.shadowColor = 'rgba(0,0,0,0.35)'
    ctx.shadowBlur = 30 * u
    fillRoundRect(ctx, m, y0, W - m * 2, cardH, 18 * u, '#ffffff')
    ctx.restore()
    ctx.fillStyle = pal.mid
    ctx.fillRect(m, y0 + 22 * u, 10 * u, cardH - 44 * u)
    drawRich(ctx, ft, disp, m + pad * 0.6, y0 + pad * 0.6, { color: pal.dark, hi: pal.accent, marker: true, markerInk: pal.dark })
    const tag = c.badge || c.sub
    if (tag) pill(c, tag, m + pad * 0.6, y0 - 52 * u, pal.dark, 28)
    c.logo = { corner: 'tr', plate: true, inset: 0 }
  },

  // ---- সংবাদ ----
  bold: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    shade(c, H * 0.35, H, '#061020', 0, 0.95)
    const ft = fitTitle(c, W - pad * 2, lines(c, 3, 3, 4), c.wide ? 84 : 92, 44)
    const subH = c.sub ? 46 * u : 0
    const y = H - pad - ft.h
    drawRich(ctx, ft, disp, pad, y, { color: '#fff', hi: pal.accent, shadow: true })
    smallText(c, c.sub, pad, y - subH - 4 * u, pal.accent, 30, 'left', 700)
    ctx.fillStyle = pal.hot
    ctx.fillRect(pad, y - subH - 22 * u, 120 * u, 9 * u)
    if (c.badge) pill(c, c.badge, pad, pad, pal.hot)
    c.logo = { corner: 'tr', plate: false, inset: 0 }
  },
  top: (c) => {
    const { ctx, W, u, pad, pal } = c
    picture(c, FULL(c))
    const ft = fitTitle(c, W - pad * 2, lines(c, 2, 3, 4), c.wide ? 70 : 80)
    const subH = c.sub ? 44 * u : 0
    const bandH = ft.h + pad * 1.1 + subH
    ctx.fillStyle = hGrad(c, 0, W, rgba(pal.dark, 0.95), rgba(pal.mid, 0.92))
    ctx.fillRect(0, 0, W, bandH)
    ctx.fillStyle = pal.accent
    ctx.fillRect(0, bandH, W, 10 * u)
    smallText(c, c.sub, pad, pad * 0.5, pal.accent, 30, 'left', 700)
    drawRich(ctx, ft, disp, pad, pad * 0.55 + subH, { color: '#fff', hi: pal.accent })
    if (c.badge) pill(c, c.badge, pad, c.H - pad - 58 * u, pal.hot)
    c.logo = { corner: 'br', plate: false, inset: 0 }
  },
  lower: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    shade(c, H * 0.55, H, '#000000', 0, 0.4)
    const m = c.wide ? pad * 0.8 : pad * 0.5
    const ft = fitTitle(c, W - m * 2 - pad, lines(c, 2, 3, 3), c.wide ? 62 : 70, 28)
    const barH = ft.h + pad * 0.8
    const stripH = 50 * u
    const y0 = H - (c.wide ? pad * 1.2 : pad * 2.2) - stripH - barH
    // kicker tab
    const tab = c.badge || 'খবর'
    ctx.font = body(800, 30 * u)
    const tw = ctx.measureText(tab).width + 40 * u
    ctx.fillStyle = pal.accent
    ctx.fillRect(m, y0 - 54 * u, tw, 54 * u)
    ctx.fillStyle = inkOn(pal.accent)
    ctx.textBaseline = 'middle'
    ctx.fillText(tab, m + 20 * u, y0 - 26 * u)
    ctx.fillStyle = hGrad(c, m, W - m, pal.mid, pal.dark)
    ctx.fillRect(m, y0, W - m * 2, barH)
    drawRich(ctx, ft, disp, m + pad * 0.5, y0 + pad * 0.4, { color: '#fff', hi: pal.accent })
    ctx.fillStyle = 'rgba(255,255,255,0.95)'
    ctx.fillRect(m, y0 + barH, W - m * 2, stripH)
    smallText(c, c.sub || c.env.website, m + pad * 0.5, y0 + barH + 10 * u, pal.dark, 26, 'left', 700)
    c.logo = { corner: 'tr', plate: false, inset: 0 }
  },
  glass: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    const bw = c.wide ? W * 0.74 : W - pad * 1.4
    const ft = fitTitle(c, bw - pad * 1.4, lines(c, 3, 4, 5), c.wide ? 66 : 76, 28)
    const subH = c.sub ? 44 * u : 0
    const bh = ft.h + pad * 1.4 + subH
    const r: Rect = { x: (W - bw) / 2, y: (H - bh) / 2 + (c.tall ? H * 0.12 : 0), w: bw, h: bh }
    ctx.save()
    ctx.beginPath()
    ctx.roundRect(r.x, r.y, r.w, r.h, 26 * u)
    ctx.clip()
    ctx.filter = `blur(${18 * u}px)`
    ctx.drawImage(ctx.canvas as CanvasImageSource, r.x, r.y, r.w, r.h, r.x, r.y, r.w, r.h)
    ctx.filter = 'none'
    ctx.fillStyle = rgba(pal.dark, 0.55)
    ctx.fillRect(r.x, r.y, r.w, r.h)
    ctx.restore()
    ctx.strokeStyle = 'rgba(255,255,255,0.35)'
    ctx.lineWidth = 2 * u
    ctx.beginPath()
    ctx.roundRect(r.x, r.y, r.w, r.h, 26 * u)
    ctx.stroke()
    smallText(c, c.sub, W / 2, r.y + pad * 0.6, pal.accent, 30, 'center', 700)
    drawRich(ctx, ft, disp, W / 2, r.y + pad * 0.7 + subH, { color: '#fff', hi: pal.accent, align: 'center' })
    ctx.fillStyle = pal.accent
    ctx.fillRect(W / 2 - 60 * u, r.y + r.h - 18 * u, 120 * u, 8 * u)
    if (c.badge) pill(c, c.badge, W / 2, r.y - 76 * u, pal.hot, 32, 'center')
    c.logo = { corner: 'br', plate: false, inset: 0 }
  },
  minimal: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    shade(c, H * 0.5, H, '#000000', 0, 0.7)
    const ft = fitTitle(c, W - pad * 2, lines(c, 2, 3, 4), 60, 32, (px) => body(700, px), 1.25)
    const y = H - pad - ft.h
    drawRich(ctx, ft, (px) => body(700, px), pad, y, { color: '#fff', hi: pal.accent, shadow: true })
    const tag = c.badge || c.sub
    if (tag) {
      ctx.font = body(700, 28 * u)
      ctx.fillStyle = pal.accent
      ctx.textBaseline = 'bottom'
      ctx.textAlign = 'left'
      ctx.fillText(tag, pad, y - 10 * u)
    }
    c.logo = { corner: 'tl', plate: true, inset: 0 }
  },

  // ---- ব্রেকিং ও লাইভ ----
  breaking: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    const bh = 92 * u
    ctx.fillStyle = pal.hot
    ctx.beginPath()
    ctx.moveTo(0, pad * 0.6)
    ctx.lineTo(W * (c.wide ? 0.52 : 0.86), pad * 0.6)
    ctx.lineTo(W * (c.wide ? 0.49 : 0.82), pad * 0.6 + bh)
    ctx.lineTo(0, pad * 0.6 + bh)
    ctx.closePath()
    ctx.fill()
    ctx.font = disp(56 * u)
    ctx.fillStyle = inkOn(pal.hot)
    ctx.textBaseline = 'middle'
    ctx.textAlign = 'left'
    ctx.fillText(c.badge || 'ব্রেকিং নিউজ', pad, pad * 0.6 + bh / 2 + 4 * u)
    const ft = fitTitle(c, W - pad * 2, lines(c, 2, 3, 4), c.wide ? 76 : 84, 40)
    const subH = c.sub ? 44 * u : 0
    const bandH = ft.h + pad * 1.2 + subH
    const y0 = H - bandH
    ctx.fillStyle = hGrad(c, 0, W, pal.dark, pal.mid)
    ctx.fillRect(0, y0, W, bandH)
    ctx.fillStyle = pal.hot
    ctx.fillRect(0, y0 - 10 * u, W, 10 * u)
    smallText(c, c.sub, pad, y0 + pad * 0.5, pal.accent, 30, 'left', 700)
    drawRich(ctx, ft, disp, pad, y0 + pad * 0.6 + subH, { color: '#fff', hi: pal.accent })
    c.logo = { corner: 'br', plate: false, inset: bandH + 10 * u }
  },
  live: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    // the LIVE chip
    const label = c.badge || 'সরাসরি'
    ctx.font = disp(40 * u)
    const lw = ctx.measureText(label).width + 100 * u
    fillRoundRect(ctx, pad, pad, lw, 72 * u, 12 * u, '#E11D2E')
    ctx.fillStyle = '#fff'
    ctx.beginPath()
    ctx.arc(pad + 34 * u, pad + 36 * u, 12 * u, 0, Math.PI * 2)
    ctx.fill()
    ctx.strokeStyle = 'rgba(255,255,255,0.5)'
    ctx.lineWidth = 4 * u
    ctx.beginPath()
    ctx.arc(pad + 34 * u, pad + 36 * u, 21 * u, 0, Math.PI * 2)
    ctx.stroke()
    ctx.textBaseline = 'middle'
    ctx.textAlign = 'left'
    ctx.fillText(label, pad + 64 * u, pad + 39 * u)
    const ft = fitTitle(c, W - pad * 2, lines(c, 2, 3, 4), c.wide ? 70 : 80)
    const bandH = ft.h + pad * 1.1
    const stripH = 50 * u
    const y0 = H - bandH - stripH
    ctx.fillStyle = 'rgba(0,0,0,0.88)'
    ctx.fillRect(0, y0, W, stripH)
    smallText(c, c.sub || c.env.dateText, pad, y0 + 10 * u, '#fff', 26, 'left', 700)
    ctx.fillStyle = hGrad(c, 0, W, '#B3121F', '#E11D2E')
    ctx.fillRect(0, y0 + stripH, W, bandH)
    drawRich(ctx, ft, disp, pad, y0 + stripH + pad * 0.55, { color: '#fff', hi: pal.accent })
    c.logo = { corner: 'tr', plate: false, inset: 0 }
  },
  alert: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    ctx.fillStyle = 'rgba(0,0,0,0.45)'
    ctx.fillRect(0, 0, W, H)
    const sh = 30 * u
    const stripes = (y: number) => {
      ctx.save()
      ctx.beginPath()
      ctx.rect(0, y, W, sh)
      ctx.clip()
      ctx.fillStyle = pal.accent
      ctx.fillRect(0, y, W, sh)
      ctx.fillStyle = '#111'
      for (let x = -sh; x < W + sh; x += sh * 2) {
        ctx.beginPath()
        ctx.moveTo(x, y + sh)
        ctx.lineTo(x + sh, y)
        ctx.lineTo(x + sh * 2, y)
        ctx.lineTo(x + sh, y + sh)
        ctx.closePath()
        ctx.fill()
      }
      ctx.restore()
    }
    stripes(0)
    stripes(H - sh)
    const label = c.badge || 'জরুরি'
    const ft = fitTitle(c, W - pad * 2, lines(c, 3, 4, 5), c.wide ? 80 : 90, 34)
    const subH = c.sub ? 46 * u : 0
    const lh = 84 * u
    const blockH = lh + 26 * u + ft.h + subH
    const top = (H - blockH) / 2
    ctx.font = disp(54 * u)
    const lw = ctx.measureText(label).width + 110 * u
    fillRoundRect(ctx, pad, top, lw, lh, 10 * u, pal.accent)
    // warning triangle
    ctx.fillStyle = '#111'
    const tx = pad + 44 * u
    const ty = top + lh / 2
    ctx.beginPath()
    ctx.moveTo(tx, ty - 24 * u)
    ctx.lineTo(tx + 26 * u, ty + 20 * u)
    ctx.lineTo(tx - 26 * u, ty + 20 * u)
    ctx.closePath()
    ctx.fill()
    ctx.fillStyle = pal.accent
    ctx.font = disp(30 * u)
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText('!', tx, ty + 4 * u)
    ctx.font = disp(54 * u)
    ctx.fillStyle = '#111'
    ctx.textAlign = 'left'
    ctx.fillText(label, pad + 84 * u, ty + 4 * u)
    drawRich(ctx, ft, disp, pad, top + lh + 26 * u, { color: '#fff', hi: pal.accent, shadow: true })
    smallText(c, c.sub, pad, top + lh + 26 * u + ft.h + 8 * u, pal.accent, 30, 'left', 700)
    c.logo = { corner: 'tr', plate: false, inset: sh }
  },
  exclusive: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    shade(c, H * 0.4, H, '#000000', 0, 0.85)
    ctx.strokeStyle = pal.accent
    ctx.lineWidth = 6 * u
    ctx.strokeRect(16 * u, 16 * u, W - 32 * u, H - 32 * u)
    // corner ribbon
    const label = c.badge || 'এক্সক্লুসিভ'
    const rw = 360 * u
    ctx.save()
    ctx.translate(W, 0)
    ctx.rotate(Math.PI / 4)
    const g = ctx.createLinearGradient(-rw / 2, 0, rw / 2, 0)
    g.addColorStop(0, '#B8860B')
    g.addColorStop(0.5, pal.accent)
    g.addColorStop(1, '#B8860B')
    ctx.fillStyle = g
    ctx.shadowColor = 'rgba(0,0,0,0.4)'
    ctx.shadowBlur = 16 * u
    ctx.fillRect(-rw / 2 - 40 * u, 120 * u, rw + 80 * u, 70 * u)
    ctx.shadowColor = 'transparent'
    let px = 36 * u
    ctx.font = disp(px)
    while (ctx.measureText(label).width > rw * 0.7 && px > 16 * u) {
      px *= 0.92
      ctx.font = disp(px)
    }
    ctx.fillStyle = '#1A1300'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText(label, 0, 157 * u)
    ctx.restore()
    const ft = fitTitle(c, W - pad * 2.4, lines(c, 2, 3, 4), c.wide ? 76 : 86)
    const y = H - pad * 1.2 - ft.h
    drawRich(ctx, ft, disp, pad * 1.2, y, { color: '#fff', hi: pal.accent, shadow: true })
    smallText(c, c.sub, pad * 1.2, y - 50 * u, pal.accent, 30, 'left', 700)
    c.logo = { corner: 'tl', plate: false, inset: 10 * u }
  },

  // ---- মুখ ও সাক্ষাৎকার ----
  face: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    const facePic = c.face ?? c.pic
    if (!c.wide) {
      picture(c, FULL(c))
      shade(c, H * 0.4, H, pal.dark, 0, 1)
      const r = W * 0.24
      circle(c, facePic, W / 2, H * 0.5 - r * 0.2, r)
      const ft = fitTitle(c, W - pad * 2, lines(c, 3, 3, 4), 80, 40)
      drawRich(ctx, ft, disp, W / 2, H - pad - ft.h, { color: '#fff', hi: pal.accent, align: 'center', shadow: true })
      smallText(c, c.sub, W / 2, H - pad - ft.h - 48 * u, pal.accent, 30, 'center', 700)
      if (c.badge) pill(c, c.badge, pad, pad, pal.hot)
      c.logo = { corner: 'tr', plate: false, inset: 0 }
      return
    }
    picture(c, { x: 0, y: 0, w: W * 0.62, h: H })
    const x0 = W * 0.56
    ctx.fillStyle = hGrad(c, x0, W, pal.dark, pal.mid)
    ctx.beginPath()
    ctx.moveTo(x0 + 60 * u, 0)
    ctx.lineTo(W, 0)
    ctx.lineTo(W, H)
    ctx.lineTo(x0, H)
    ctx.closePath()
    ctx.fill()
    ctx.strokeStyle = pal.hot
    ctx.lineWidth = 10 * u
    ctx.beginPath()
    ctx.moveTo(x0 + 60 * u, 0)
    ctx.lineTo(x0, H)
    ctx.stroke()
    const r = H * 0.2
    circle(c, facePic, x0 + 30 * u, H * 0.3, r)
    const tx = x0 + 70 * u
    const ft = fitTitle(c, W - tx - pad * 0.8, 4, 64, 34)
    const y = Math.max(H * 0.3 + r + 24 * u, H - pad - ft.h)
    drawRich(ctx, ft, disp, tx, y, { color: '#fff', hi: pal.accent })
    if (c.badge) pill(c, c.badge, pad, pad, pal.hot)
    c.logo = { corner: 'tr', plate: false, inset: 0 }
  },
  duo: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    ctx.fillStyle = pal.dark
    ctx.fillRect(0, 0, W, H)
    ctx.fillStyle = pal.mid
    ctx.beginPath()
    ctx.moveTo(W * 0.56, 0)
    ctx.lineTo(W, 0)
    ctx.lineTo(W, H)
    ctx.lineTo(W * 0.44, H)
    ctx.closePath()
    ctx.fill()
    const ft = fitTitle(c, W - pad * 2, lines(c, 2, 3, 4), c.wide ? 62 : 72, 28)
    const bandH = ft.h + pad
    const areaH = H - bandH
    const r = Math.min(W * 0.21, areaH * 0.36)
    const cy = c.tall ? areaH * 0.45 : areaH * 0.5
    circle(c, c.pic, W * 0.27, cy, r, pal.accent)
    circle(c, c.face ?? c.pic, W * 0.73, cy, r, pal.hot)
    burst(ctx, W / 2, cy, r * 0.42, pal.hot, c.badge || 'বনাম', 0)
    ctx.fillStyle = 'rgba(0,0,0,0.55)'
    ctx.fillRect(0, H - bandH, W, bandH)
    ctx.fillStyle = pal.accent
    ctx.fillRect(0, H - bandH, W, 8 * u)
    drawRich(ctx, ft, disp, W / 2, H - bandH + pad * 0.55, { color: '#fff', hi: pal.accent, align: 'center' })
    smallText(c, c.sub, W / 2, pad * 0.5, pal.accent, 30, 'center', 700)
    c.logo = { corner: 'tl', plate: false, inset: 0 }
  },
  interview: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    const facePic = c.face ?? c.pic
    ctx.fillStyle = hGrad(c, 0, W, pal.dark, pal.mid)
    ctx.fillRect(0, 0, W, H)
    const label = c.badge || 'সাক্ষাৎকার'
    if (c.wide) {
      const pw = W * 0.48
      if (c.face) cover(ctx, facePic, { x: 0, y: 0, w: pw, h: H })
      else picture(c, { x: 0, y: 0, w: pw, h: H })
      const g = ctx.createLinearGradient(pw * 0.6, 0, pw, 0)
      g.addColorStop(0, rgba(pal.dark, 0))
      g.addColorStop(1, rgba(pal.dark, 1))
      ctx.fillStyle = g
      ctx.fillRect(pw * 0.6, 0, pw * 0.4 + 1, H)
      const tx = pw + 20 * u
      pill(c, label, tx, pad, pal.accent, 28)
      const ft = fitTitle(c, W - tx - pad, 4, 58, 26, (px) => body(700, px), 1.3)
      const nameH = c.sub ? 60 * u : 0
      const top = Math.max(pad + 110 * u, (H - ft.h - nameH) / 2 + 30 * u)
      ctx.font = disp(150 * u)
      ctx.fillStyle = rgba(pal.accent, 0.9)
      ctx.textBaseline = 'alphabetic'
      ctx.fillText('“', tx - 6 * u, top + 70 * u)
      drawRich(ctx, ft, (px) => body(700, px), tx, top, { color: '#fff', hi: pal.accent })
      smallText(c, c.sub, tx, top + ft.h + 16 * u, pal.accent, 34, 'left', 800)
      c.logo = { corner: 'br', plate: false, inset: 0 }
      return
    }
    const ph = H * (c.tall ? 0.56 : 0.5)
    if (c.face) cover(ctx, facePic, { x: 0, y: 0, w: W, h: ph })
    else picture(c, { x: 0, y: 0, w: W, h: ph })
    shade(c, ph * 0.6, ph, pal.dark, 0, 1)
    pill(c, label, pad, pad, pal.accent, 30)
    const nameH = c.sub ? 60 * u : 0
    const top = ph + 20 * u
    const ft = fitTitle(c, W - pad * 2, lines(c, 3, 4, 6), 66, 24, (px) => body(700, px), 1.3, H - top - nameH - pad)
    smallText(c, c.sub, pad, top, pal.accent, 38, 'left', 800)
    drawRich(ctx, ft, (px) => body(700, px), pad, top + nameH, { color: '#fff', hi: pal.accent })
    c.logo = { corner: 'tr', plate: false, inset: 0 }
  },
  reaction: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    // blurred, dark backdrop of the scene
    picture(c, FULL(c))
    ctx.filter = `blur(${22 * u}px) brightness(0.55)`
    ctx.drawImage(ctx.canvas as CanvasImageSource, 0, 0)
    ctx.filter = 'none'
    const facePic = c.face ?? c.pic
    const r: Rect = c.wide ? { x: W * 0.5, y: H * 0.1, w: W * 0.44, h: H * 0.8 } : { x: W * 0.1, y: H * 0.06, w: W * 0.8, h: H * (c.tall ? 0.46 : 0.5) }
    ctx.save()
    ctx.translate(r.x + r.w / 2, r.y + r.h / 2)
    ctx.rotate(c.wide ? 0.05 : -0.04)
    ctx.shadowColor = 'rgba(0,0,0,0.5)'
    ctx.shadowBlur = 30 * u
    fillRoundRect(ctx, -r.w / 2 - 12 * u, -r.h / 2 - 12 * u, r.w + 24 * u, r.h + 24 * u, 22 * u, '#fff')
    ctx.shadowColor = 'transparent'
    ctx.beginPath()
    ctx.roundRect(-r.w / 2, -r.h / 2, r.w, r.h, 16 * u)
    ctx.clip()
    if (c.face) cover(ctx, facePic, { x: -r.w / 2, y: -r.h / 2, w: r.w, h: r.h })
    else {
      ctx.translate(-r.x - r.w / 2, -r.y - r.h / 2)
      picture(c, r)
    }
    ctx.restore()
    const maxW = c.wide ? W * 0.44 : W - pad * 2
    const top0 = r.y + r.h + pad * 0.9
    const ft = fitTitle(c, maxW, lines(c, 4, 3, 4), c.wide ? 84 : 92, 30, disp, 1.18, c.wide ? H - pad * 2 : H - top0 - pad - 120 * u)
    const top = c.wide ? (H - ft.h) / 2 : top0
    drawRich(ctx, ft, disp, pad, top, { color: '#fff', hi: pal.accent, stroke: '#000' })
    smallText(c, c.sub, pad, c.wide ? top - 46 * u : top + ft.h + 10 * u, pal.accent, 30, 'left', 700)
    if (c.badge) burst(ctx, r.x + r.w - 10 * u, r.y + 20 * u, 80 * u, pal.hot, c.badge)
    c.logo = { corner: c.wide ? 'tl' : 'br', plate: false, inset: 0 }
  },

  // ---- নজরকাড়া ----
  question: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    if (c.wide) {
      const g = ctx.createLinearGradient(0, 0, W, 0)
      g.addColorStop(0, rgba(pal.dark, 0.95))
      g.addColorStop(0.55, rgba(pal.dark, 0.7))
      g.addColorStop(1, rgba(pal.dark, 0.1))
      ctx.fillStyle = g
      ctx.fillRect(0, 0, W, H)
    } else shade(c, H * 0.3, H, pal.dark, 0.1, 0.95)
    const qPx = c.wide ? H * 0.95 : W * 0.75
    ctx.save()
    ctx.translate(c.wide ? W * 0.8 : W * 0.72, c.wide ? H * 0.54 : H * 0.3)
    ctx.rotate(0.12)
    ctx.font = disp(qPx)
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.lineWidth = qPx * 0.04
    ctx.strokeStyle = pal.dark
    ctx.shadowColor = 'rgba(0,0,0,0.5)'
    ctx.shadowBlur = 40 * u
    ctx.strokeText('?', 0, 0)
    ctx.shadowColor = 'transparent'
    ctx.fillStyle = pal.accent
    ctx.fillText('?', 0, 0)
    ctx.restore()
    const maxW = c.wide ? W * 0.58 : W - pad * 2
    const ft = fitTitle(c, maxW, lines(c, 4, 4, 5), c.wide ? 80 : 90, 34)
    const top = c.wide ? (H - ft.h) / 2 : H - pad - ft.h
    drawRich(ctx, ft, disp, pad, top, { color: '#fff', hi: pal.accent, shadow: true })
    smallText(c, c.sub, pad, top - 48 * u, pal.accent, 30, 'left', 700)
    if (c.badge) pill(c, c.badge, pad, c.wide ? pad : pad + 100 * u, pal.hot)
    c.logo = { corner: c.wide ? 'br' : 'tl', plate: false, inset: 0 }
  },
  number: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    ctx.fillStyle = rgba(pal.dark, 0.62)
    ctx.fillRect(0, 0, W, H)
    const src = `${c.badge} ${plainTitle(c.title)}`
    const m = /[০-৯0-9][০-৯0-9,.]*\s*(%|শতাংশ|কোটি|লাখ|লক্ষ|হাজার|জন|টাকা|বছর|দিন)?/.exec(src)
    const num = (m?.[0] ?? '!').trim()
    const area = c.wide ? { w: W * 0.46, h: H - pad * 2 } : { w: W - pad * 2, h: H * 0.38 }
    let px = area.h * 0.8
    ctx.font = disp(px)
    while (px > 40 * u && ctx.measureText(num).width > area.w) {
      px *= 0.94
      ctx.font = disp(px)
    }
    ctx.textAlign = 'left'
    ctx.textBaseline = 'middle'
    const nx = pad
    const ny = c.wide ? H / 2 : pad + area.h / 2
    ctx.lineWidth = px * 0.05
    ctx.strokeStyle = pal.dark
    ctx.strokeText(num, nx, ny)
    ctx.fillStyle = pal.accent
    ctx.shadowColor = 'rgba(0,0,0,0.5)'
    ctx.shadowBlur = 30 * u
    ctx.fillText(num, nx, ny)
    ctx.shadowColor = 'transparent'
    const tx = c.wide ? Math.min(W * 0.52, nx + ctx.measureText(num).width + 40 * u) : pad
    if (c.wide) {
      ctx.fillStyle = pal.accent
      ctx.fillRect(tx - 24 * u, H * 0.22, 8 * u, H * 0.56)
    }
    const top0 = pad + area.h + pad * 0.6
    const ft = fitTitle(c, c.wide ? W - tx - pad : W - pad * 2, lines(c, 4, 4, 5), c.wide ? 58 : 74, 24, disp, 1.18, c.wide ? H - pad * 2 : H - top0 - pad - 110 * u)
    const top = c.wide ? (H - ft.h) / 2 : top0
    drawRich(ctx, ft, disp, tx, top, { color: '#fff', hi: pal.accent })
    smallText(c, c.sub, tx, top + ft.h + 14 * u, pal.accent, 30, 'left', 700)
    c.logo = { corner: c.wide ? 'tr' : 'br', plate: false, inset: 0 }
  },
  shock: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    const v = ctx.createRadialGradient(W / 2, H / 2, Math.min(W, H) * 0.3, W / 2, H / 2, Math.max(W, H) * 0.75)
    v.addColorStop(0, 'rgba(0,0,0,0)')
    v.addColorStop(1, 'rgba(0,0,0,0.7)')
    ctx.fillStyle = v
    ctx.fillRect(0, 0, W, H)
    shade(c, H * 0.45, H, '#000000', 0, 0.6)
    const ft = fitTitle(c, W - pad * 2, lines(c, 3, 3, 4), c.wide ? 104 : 112, 40, disp, 1.08)
    const top = H - pad - ft.h
    drawRich(ctx, ft, disp, pad, top, { color: '#fff', hi: pal.accent, stroke: '#000' })
    smallText(c, c.sub, pad, top - 48 * u, pal.accent, 32, 'left', 800)
    burst(ctx, W - pad - 90 * u, pad + 90 * u, 100 * u, pal.hot, c.badge || '!')
    c.logo = { corner: 'tl', plate: false, inset: 0 }
  },
  pointer: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    let target: { x: number; y: number }
    let from: { x: number; y: number }
    if (c.wide) {
      const pw = W * 0.42
      ctx.fillStyle = rgba(pal.dark, 0.92)
      ctx.fillRect(0, 0, pw, H)
      ctx.fillStyle = pal.accent
      ctx.fillRect(pw, 0, 8 * u, H)
      const ft = fitTitle(c, pw - pad * 1.6, 5, 64, 26)
      const top = (H - ft.h) / 2
      drawRich(ctx, ft, disp, pad, top, { color: '#fff', hi: pal.accent })
      smallText(c, c.sub, pad, top - 48 * u, pal.accent, 28, 'left', 700)
      from = { x: pw - 30 * u, y: top + ft.h * 0.5 }
      target = { x: W * 0.72, y: H * 0.42 }
    } else {
      const ft = fitTitle(c, W - pad * 2, lines(c, 3, 3, 4), 76, 30)
      const ph = ft.h + pad * 1.2
      ctx.fillStyle = rgba(pal.dark, 0.92)
      ctx.fillRect(0, 0, W, ph)
      ctx.fillStyle = pal.accent
      ctx.fillRect(0, ph, W, 8 * u)
      drawRich(ctx, ft, disp, pad, pad * 0.6, { color: '#fff', hi: pal.accent })
      from = { x: W * 0.3, y: ph + 30 * u }
      target = { x: W * 0.6, y: ph + (H - ph) * 0.5 }
    }
    const R = Math.min(W, H) * 0.17
    ctx.save()
    ctx.lineWidth = 12 * u
    ctx.strokeStyle = '#fff'
    ctx.beginPath()
    ctx.ellipse(target.x, target.y, R + 6 * u, R * 0.9 + 6 * u, -0.1, 0, Math.PI * 2)
    ctx.stroke()
    ctx.lineWidth = 9 * u
    ctx.strokeStyle = pal.hot
    ctx.stroke()
    ctx.restore()
    const ang = Math.atan2(target.y - from.y, target.x - from.x)
    bigArrow(ctx, from.x, from.y, target.x - Math.cos(ang) * (R + 20 * u), target.y - Math.sin(ang) * (R * 0.9 + 20 * u), pal.hot, 16 * u, c.wide ? -0.25 : 0.25)
    if (c.badge) pill(c, c.badge, c.wide ? W - pad : pad, H - pad - 58 * u, pal.hot, 32, c.wide ? 'right' : 'left')
    c.logo = { corner: c.wide ? 'tr' : 'br', plate: false, inset: 0 }
  },

  // ---- বিষয়ভিত্তিক ----
  sports: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    shade(c, H * 0.45, H, pal.dark, 0, 0.75)
    const ft = fitTitle(c, W - pad * 2.4, lines(c, 2, 3, 4), c.wide ? 74 : 84, 30)
    const bandH = ft.h + pad * 1.1
    const y0 = H - pad * 0.7 - bandH
    // speed stripes
    ctx.save()
    ctx.globalAlpha = 0.9
    for (let i = 0; i < 3; i++) {
      ctx.fillStyle = i === 1 ? pal.accent : pal.mid
      const sx = W * (c.wide ? 0.62 : 0.5) + i * 46 * u
      ctx.beginPath()
      ctx.moveTo(sx, H)
      ctx.lineTo(sx + 26 * u, H)
      ctx.lineTo(sx + 26 * u + H * 0.5, 0)
      ctx.lineTo(sx + H * 0.5, 0)
      ctx.closePath()
      ctx.fill()
    }
    ctx.restore()
    const sk = 40 * u
    ctx.fillStyle = hGrad(c, 0, W, pal.dark, pal.mid)
    ctx.beginPath()
    ctx.moveTo(pad * 0.4 + sk, y0)
    ctx.lineTo(W - pad * 0.4, y0)
    ctx.lineTo(W - pad * 0.4 - sk, y0 + bandH)
    ctx.lineTo(pad * 0.4, y0 + bandH)
    ctx.closePath()
    ctx.fill()
    ctx.fillStyle = pal.accent
    ctx.fillRect(pad * 0.4 + sk, y0 - 8 * u, W * 0.3, 8 * u)
    drawRich(ctx, ft, disp, pad * 0.4 + sk + 20 * u, y0 + pad * 0.55, { color: '#fff', hi: pal.accent })
    // score-like badge
    const tag = c.badge || 'খেলা'
    ctx.font = disp(46 * u)
    const tw = ctx.measureText(tag).width + 50 * u
    ctx.fillStyle = '#111'
    ctx.fillRect(pad, pad, tw + 16 * u, 80 * u)
    ctx.fillStyle = pal.accent
    ctx.fillRect(pad + 8 * u, pad + 8 * u, tw, 64 * u)
    ctx.fillStyle = inkOn(pal.accent)
    ctx.textBaseline = 'middle'
    ctx.textAlign = 'left'
    ctx.fillText(tag, pad + 33 * u, pad + 43 * u)
    smallText(c, c.sub, pad, pad + 96 * u, '#fff', 30, 'left', 700)
    c.logo = { corner: 'tr', plate: false, inset: 0 }
  },
  showbiz: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    const g = ctx.createLinearGradient(0, H * 0.35, 0, H)
    g.addColorStop(0, rgba(pal.mid, 0))
    g.addColorStop(0.6, rgba(pal.mid, 0.75))
    g.addColorStop(1, rgba(pal.dark, 0.95))
    ctx.fillStyle = g
    ctx.fillRect(0, H * 0.35, W, H * 0.65)
    // sparkles (fixed spots, so the preview and the file match)
    const spots = [
      [0.08, 0.2, 1],
      [0.9, 0.16, 0.8],
      [0.82, 0.42, 0.55],
      [0.18, 0.5, 0.5],
      [0.62, 0.1, 0.45],
    ]
    for (const [x, y, k] of spots) {
      ctx.save()
      ctx.shadowColor = pal.accent
      ctx.shadowBlur = 24 * u
      star(ctx, W * x, H * y, 30 * u * k, 4, 0.28)
      ctx.fillStyle = '#fff'
      ctx.fill()
      ctx.restore()
    }
    const ft = fitTitle(c, W - pad * 2, lines(c, 2, 3, 4), c.wide ? 78 : 88, 32)
    const y = H - pad - ft.h
    drawRich(ctx, ft, disp, W / 2, y, { color: '#fff', hi: pal.accent, align: 'center', shadow: true })
    smallText(c, c.sub, W / 2, y - 48 * u, pal.accent, 30, 'center', 700)
    pill(c, c.badge || 'বিনোদন', W / 2, pad + (c.wide ? 0 : 100 * u), pal.hot, 32, 'center')
    c.logo = { corner: c.wide ? 'tl' : 'tr', plate: false, inset: 0 }
  },
  economy: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    picture(c, FULL(c))
    ctx.fillStyle = rgba(pal.dark, 0.55)
    ctx.fillRect(0, 0, W, H)
    // grid + rising line
    ctx.strokeStyle = 'rgba(255,255,255,0.12)'
    ctx.lineWidth = 2 * u
    for (let i = 1; i < 6; i++) {
      ctx.beginPath()
      ctx.moveTo(0, (H * i) / 6)
      ctx.lineTo(W, (H * i) / 6)
      ctx.stroke()
    }
    const pts = [
      [0.04, 0.9],
      [0.2, 0.78],
      [0.32, 0.84],
      [0.48, 0.64],
      [0.6, 0.7],
      [0.76, 0.46],
      [0.94, 0.26],
    ].map(([x, y]) => [x * W, (c.wide ? y : 0.5 + y * 0.46) * H])
    ctx.save()
    ctx.lineJoin = 'round'
    ctx.lineCap = 'round'
    ctx.beginPath()
    pts.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)))
    ctx.lineTo(W * 0.94, H)
    ctx.lineTo(W * 0.04, H)
    ctx.closePath()
    const fg = ctx.createLinearGradient(0, H * 0.3, 0, H)
    fg.addColorStop(0, rgba(pal.accent, 0.35))
    fg.addColorStop(1, rgba(pal.accent, 0))
    ctx.fillStyle = fg
    ctx.fill()
    ctx.beginPath()
    pts.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)))
    ctx.strokeStyle = pal.accent
    ctx.lineWidth = 10 * u
    ctx.shadowColor = 'rgba(0,0,0,0.5)'
    ctx.shadowBlur = 12 * u
    ctx.stroke()
    ctx.restore()
    const [lx, ly] = pts[pts.length - 1]
    burst(ctx, lx, ly, 26 * u, pal.accent, '', 0)
    const ft = fitTitle(c, c.wide ? W * 0.7 : W - pad * 2, lines(c, 3, 3, 4), c.wide ? 74 : 84, 30)
    const subH = c.sub ? 46 * u : 0
    const tag = pill(c, c.badge || 'অর্থনীতি', pad, pad, pal.mid, 30)
    smallText(c, c.sub, pad, pad + tag.h + 16 * u, pal.accent, 30, 'left', 700)
    drawRich(ctx, ft, disp, pad, pad + tag.h + 24 * u + subH, { color: '#fff', hi: pal.accent, shadow: true })
    c.logo = { corner: 'br', plate: false, inset: 0 }
  },
  campus: (c) => {
    const { ctx, W, H, u, pad, pal } = c
    // notebook paper
    ctx.fillStyle = '#FFFBEA'
    ctx.fillRect(0, 0, W, H)
    ctx.strokeStyle = 'rgba(37,99,235,0.22)'
    ctx.lineWidth = 2 * u
    for (let y = 70 * u; y < H; y += 46 * u) {
      ctx.beginPath()
      ctx.moveTo(0, y)
      ctx.lineTo(W, y)
      ctx.stroke()
    }
    ctx.strokeStyle = 'rgba(220,38,38,0.45)'
    ctx.beginPath()
    ctx.moveTo(pad * 1.6, 0)
    ctx.lineTo(pad * 1.6, H)
    ctx.stroke()
    // the photo, pasted on with tape
    const r: Rect = c.wide ? { x: W * 0.52, y: H * 0.12, w: W * 0.42, h: H * 0.68 } : { x: W * 0.1, y: H * 0.07, w: W * 0.62, h: H * (c.tall ? 0.36 : 0.4) }
    ctx.save()
    ctx.translate(r.x + r.w / 2, r.y + r.h / 2)
    ctx.rotate(0.035)
    ctx.shadowColor = 'rgba(0,0,0,0.25)'
    ctx.shadowBlur = 20 * u
    ctx.fillStyle = '#fff'
    ctx.fillRect(-r.w / 2 - 14 * u, -r.h / 2 - 14 * u, r.w + 28 * u, r.h + 28 * u)
    ctx.shadowColor = 'transparent'
    ctx.translate(-r.x - r.w / 2, -r.y - r.h / 2)
    picture(c, r)
    ctx.fillStyle = 'rgba(253,224,71,0.75)'
    ctx.translate(r.x + r.w / 2, r.y - 10 * u)
    ctx.rotate(-0.08)
    ctx.fillRect(-70 * u, -18 * u, 140 * u, 36 * u)
    ctx.restore()
    const tx = pad * 1.6 + 28 * u
    const maxW = c.wide ? r.x - tx - 30 * u : W - tx - pad
    const ft = fitTitle(c, maxW, lines(c, 5, 4, 5), c.wide ? 62 : 72, 26)
    const subH = c.sub ? 46 * u : 0
    const top = c.wide ? Math.max(pad + 70 * u, (H - ft.h - subH) / 2) : r.y + r.h + pad * 1.1
    pill(c, c.badge || 'শিক্ষা', tx, top - 80 * u, pal.mid, 28)
    drawRich(ctx, ft, disp, tx, top, { color: pal.dark, hi: pal.accent, marker: true, markerInk: pal.dark })
    smallText(c, c.sub, tx, top + ft.h + 12 * u, pal.mid, 30, 'left', 700)
    c.logo = { corner: c.wide ? 'bl' : 'tr', plate: true, inset: 0 }
  },
}

// ---------------------------------------------------------------------------
// Free layers
// ---------------------------------------------------------------------------

/** Bounding box of each layer (for picking and dragging on the preview). */
export type LayerBox = Rect

function drawLayer(ctx: Ctx, l: ThumbLayer, W: number, H: number): LayerBox {
  const S = Math.min(W, H)
  const cx = l.x * W
  const cy = l.y * H
  if (l.kind === 'text') {
    const px = l.h * S
    const text = l.text.trim() || 'লেখা'
    ctx.font = disp(px)
    const tw = ctx.measureText(text).width
    const padX = px * 0.35
    const bw = tw + padX * 2
    const bh = px * 1.45
    ctx.save()
    ctx.translate(cx, cy)
    ctx.rotate((l.angle * Math.PI) / 180)
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    if (l.plate) {
      ctx.shadowColor = 'rgba(0,0,0,0.35)'
      ctx.shadowBlur = px * 0.25
      fillRoundRect(ctx, -bw / 2, -bh / 2, bw, bh, px * 0.2, l.color)
      ctx.shadowColor = 'transparent'
      ctx.fillStyle = inkOn(l.color)
      ctx.fillText(text, 0, px * 0.06)
    } else {
      ctx.lineJoin = 'round'
      ctx.lineWidth = px * 0.16
      ctx.strokeStyle = inkOn(l.color) === '#111111' ? '#000' : '#fff'
      ctx.strokeText(text, 0, px * 0.06)
      ctx.fillStyle = l.color
      ctx.fillText(text, 0, px * 0.06)
    }
    ctx.restore()
    return { x: cx - bw / 2, y: cy - bh / 2, w: bw, h: bh }
  }
  const w = l.w * W
  const h = l.h * H
  if (l.kind === 'burst') {
    const r = Math.min(w, h) / 2
    burst(ctx, cx, cy, r, l.color, l.text.trim(), (l.angle * Math.PI) / 180)
    return { x: cx - r, y: cy - r, w: r * 2, h: r * 2 }
  }
  const st: Sticker = { kind: l.kind, x: l.x - l.w / 2, y: l.y - l.h / 2, w: l.w, h: l.h, angle: l.angle, color: l.color, label: l.text, at: 0, dur: null }
  drawSticker(ctx, st, { x: 0, y: 0, w: W, h: H }, { x: 0, y: 0, w: W, h: H }, 10, 1e9, (S / 720) * 1.4)
  return { x: cx - w / 2, y: cy - h / 2, w, h }
}

/** A new layer of `kind`, centred. */
export function newLayer(kind: ThumbLayer['kind'], color: string): ThumbLayer {
  if (kind === 'text') return { kind, x: 0.5, y: 0.5, w: 0.3, h: 0.08, angle: -4, color, text: 'নতুন লেখা', plate: true }
  if (kind === 'burst') return { kind, x: 0.8, y: 0.25, w: 0.2, h: 0.2 * (16 / 9), angle: -8, color, text: 'নতুন!', plate: true }
  if (kind === 'arrow') return { kind, x: 0.5, y: 0.5, w: 0.22, h: 0.12, angle: 30, color, text: '', plate: true }
  if (kind === 'highlight') return { kind, x: 0.5, y: 0.5, w: 0.3, h: 0.08, angle: 0, color, text: '', plate: true }
  return { kind, x: 0.6, y: 0.45, w: 0.2, h: 0.3, angle: 0, color, text: '', plate: true }
}

// ---------------------------------------------------------------------------
// Entry points
// ---------------------------------------------------------------------------

export interface ThumbOverride {
  template?: ThumbTemplate
  size?: ThumbSize
  /** Leave out the free layers (gallery cards). */
  noLayers?: boolean
}

/**
 * Paints the thumbnail on a W×H canvas. `pic` is the decoded main-video
 * frame at source second `src`, `face` the optional portrait. Returns the
 * free layers' boxes.
 */
export function drawThumbnail(ctx: Ctx, env: DrawEnv, pic: Picture | null, face: Picture | null, W: number, H: number, src: number, o: ThumbOverride = {}): LayerBox[] {
  const t = env.cfg.thumb
  const template = o.template ?? t.template
  const u = Math.min(W, H) / 720
  const c: TC = {
    ctx,
    env,
    W,
    H,
    u,
    wide: W / H > 1.25,
    tall: H / W > 1.3,
    pad: 44 * u,
    title: (t.title.trim() || env.title).trim(),
    badge: t.badge.trim(),
    sub: t.sub.trim(),
    pal: palette(t.palette, template),
    pic,
    face,
    src,
    scale: t.scale,
    grade: t.grade,
    logo: { corner: 'tr', plate: false, inset: 0 },
  }
  ctx.save()
  ctx.clearRect(0, 0, W, H)
  ;(DESIGNS[template] ?? DESIGNS['nil-bar'])(c)
  ctx.restore()
  if (t.logo !== 'off') {
    const corner = t.logo === 'auto' ? c.logo.corner : t.logo
    drawLogo(c, corner, c.logo.plate, t.logo === 'auto' ? c.logo.inset : 0)
  }
  if (o.noLayers) return []
  return t.layers.map((l) => {
    ctx.save()
    const box = drawLayer(ctx, l, W, H)
    ctx.restore()
    return box
  })
}

/** The main video's picture at `src` seconds, decoded from the downloaded file (pixels readable, unlike the preview). */
export async function grabFrame(blob: Blob, src: number, maxW = 1920): Promise<Picture | null> {
  const input = new Input({ source: new BlobSource(blob), formats: ALL_FORMATS })
  try {
    const video = await input.getPrimaryVideoTrack()
    if (!video || !(await video.canDecode())) return null
    const sink = new CanvasSink(video, { width: Math.min(maxW, video.displayWidth), poolSize: 0 })
    const wc = await sink.getCanvas(Math.max(0, src))
    if (!wc) return null
    return { src: wc.canvas as CanvasImageSource, w: wc.canvas.width, h: wc.canvas.height }
  } finally {
    input.dispose()
  }
}

/** Paints the designed thumbnail (in `size`, default the chosen one) and returns it as a JPEG. */
export async function thumbnailJpeg(env: DrawEnv, pic: Picture | null, face: Picture | null, src: number, size?: ThumbSize): Promise<Blob | null> {
  const { W, H } = size ? thumbDims(size, env.L) : thumbSize(env.cfg, env.L)
  const canvas = document.createElement('canvas')
  canvas.width = W
  canvas.height = H
  const ctx = canvas.getContext('2d')
  if (!ctx) return null
  drawThumbnail(ctx, env, pic, face, W, H, src, size ? { size } : {})
  return new Promise((resolve) => canvas.toBlob((b) => resolve(b), 'image/jpeg', 0.9))
}
