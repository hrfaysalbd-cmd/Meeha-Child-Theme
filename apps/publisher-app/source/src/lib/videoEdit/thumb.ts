/**
 * Round 23 — the thumbnail designer: a scene of the news (with its colour,
 * zoom and privacy boxes) under a big headline, a badge, the Meeha logo
 * and, for the "face" template, a round portrait. The same painter draws
 * the live preview in the থাম্বনেইল tab and the JPEG put on the post.
 */
import { ALL_FORMATS, BlobSource, CanvasSink, Input } from 'mediabunny'
import { BRAND, FONT_BODY, FONT_DISPLAY } from './assets'
import type { EditConfig } from './config'
import { coverRect, drawMainPicture, type DrawEnv, type Picture } from './draw'
import { fillRoundRect, fitText, type Ctx } from './motion'
import type { Layout, Rect } from './timeline'

const disp = (px: number) => `800 ${px}px ${FONT_DISPLAY}`
const body = (w: number, px: number) => `${w} ${px}px ${FONT_BODY}`

/** Output size: YouTube's 1280×720, or the video's own shape with a 1280 long side. */
export function thumbSize(cfg: EditConfig, L: Layout): { W: number; H: number } {
  if (cfg.thumb.size === '16:9') return { W: 1280, H: 720 }
  const k = 1280 / Math.max(L.W, L.H)
  return { W: Math.round(L.W * k), H: Math.round(L.H * k) }
}

/** The source second the thumbnail picture comes from. */
export function thumbSource(cfg: EditConfig, start: number, end: number): number {
  const at = cfg.thumb.at
  if (at !== null && at >= start && at <= end) return at
  return Math.min(end, start + Math.min(2.5, (end - start) / 3))
}

function circle(ctx: Ctx, pic: Picture, cx: number, cy: number, r: number) {
  ctx.save()
  ctx.beginPath()
  ctx.arc(cx, cy, r, 0, Math.PI * 2)
  ctx.clip()
  const d = coverRect(pic.w, pic.h, { x: cx - r, y: cy - r, w: r * 2, h: r * 2 })
  // faces sit in the upper part of a portrait photo
  if (pic.h > pic.w) d.y = Math.min(cy - r, d.y + (d.h - 2 * r) * 0.3)
  ctx.drawImage(pic.src, d.x, d.y, d.w, d.h)
  ctx.restore()
  ctx.lineWidth = r * 0.07
  ctx.strokeStyle = '#fff'
  ctx.beginPath()
  ctx.arc(cx, cy, r, 0, Math.PI * 2)
  ctx.stroke()
  ctx.lineWidth = r * 0.05
  ctx.strokeStyle = BRAND.red
  ctx.beginPath()
  ctx.arc(cx, cy, r * 1.07, -Math.PI * 0.85, Math.PI * 0.25)
  ctx.stroke()
}

function badgePill(ctx: Ctx, text: string, x: number, y: number, u: number, fill: string) {
  if (!text) return 0
  ctx.font = body(700, 34 * u)
  const w = ctx.measureText(text).width + 40 * u
  const h = 58 * u
  fillRoundRect(ctx, x, y, w, h, 10 * u, fill)
  ctx.fillStyle = '#fff'
  ctx.textBaseline = 'middle'
  ctx.textAlign = 'left'
  ctx.fillText(text, x + 20 * u, y + h / 2 + 2 * u)
  return h
}

function textLines(ctx: Ctx, lines: string[], x: number, y: number, lh: number, color: string, shadow = true) {
  ctx.textAlign = 'left'
  ctx.textBaseline = 'top'
  ctx.fillStyle = color
  if (shadow) {
    ctx.shadowColor = 'rgba(0,0,0,0.55)'
    ctx.shadowBlur = lh * 0.12
    ctx.shadowOffsetY = lh * 0.03
  }
  lines.forEach((l, i) => ctx.fillText(l, x, y + i * lh))
  ctx.shadowColor = 'transparent'
  ctx.shadowBlur = 0
  ctx.shadowOffsetY = 0
}

function logo(ctx: Ctx, env: DrawEnv, x: number, y: number, h: number, align: 'left' | 'right', white = true) {
  const w = (env.logo.w / env.logo.h) * h
  ctx.drawImage(white ? env.logo.white : env.logo.color, align === 'right' ? x - w : x, y, w, h)
}

/**
 * Paints the thumbnail on a W×H canvas. `pic` is the decoded main-video
 * frame at source second `src`, `face` the optional portrait.
 */
export function drawThumbnail(ctx: Ctx, env: DrawEnv, pic: Picture | null, face: Picture | null, W: number, H: number, src: number) {
  const { cfg } = env
  const t = cfg.thumb
  const u = Math.min(W, H) / 720
  const portrait = H > W
  const title = (t.title.trim() || env.title).trim()
  const badge = t.badge.trim()

  // the scene, cropped to fill (privacy boxes and colour included)
  const L2: Layout = { ...env.L, W, H, s: Math.min(W, H) / 1080, k: 1, header: null, footer: null, video: { x: 0, y: 0, w: W, h: H } }
  const e2: DrawEnv = { ...env, L: L2, cfg: { ...cfg, fit: 'cover', stickers: [] } }
  ctx.save()
  const full: Rect = { x: 0, y: 0, w: W, h: H }
  if (t.template === 'face' && !portrait) drawMainPicture(ctx, e2, pic, { x: 0, y: 0, w: W * 0.62, h: H }, src)
  else drawMainPicture(ctx, e2, pic, full, src)
  ctx.restore()

  const pad = 44 * u
  switch (t.template) {
    case 'bold': {
      const g = ctx.createLinearGradient(0, H * 0.35, 0, H)
      g.addColorStop(0, 'rgba(6,16,32,0)')
      g.addColorStop(0.55, 'rgba(6,16,32,0.78)')
      g.addColorStop(1, 'rgba(6,16,32,0.95)')
      ctx.fillStyle = g
      ctx.fillRect(0, H * 0.35, W, H * 0.65)
      const maxW = W - pad * 2
      const ft = fitText(ctx, title, disp, maxW, portrait ? 4 : 3, (portrait ? 92 : 84) * u, 44 * u, 1.18)
      const blockH = ft.lines.length * ft.lineHeight
      const y = H - pad - blockH
      ctx.font = disp(ft.size)
      textLines(ctx, ft.lines, pad, y, ft.lineHeight, '#fff')
      ctx.fillStyle = BRAND.red
      ctx.fillRect(pad, y - 22 * u, 120 * u, 9 * u)
      if (badge) badgePill(ctx, badge, pad, pad, u, BRAND.red)
      logo(ctx, env, W - pad, pad, 52 * u, 'right')
      break
    }
    case 'breaking': {
      // slanted red banner at the top, navy headline band at the bottom
      const bh = 92 * u
      ctx.fillStyle = BRAND.red
      ctx.beginPath()
      ctx.moveTo(0, pad * 0.6)
      ctx.lineTo(W * (portrait ? 0.86 : 0.52), pad * 0.6)
      ctx.lineTo(W * (portrait ? 0.82 : 0.49), pad * 0.6 + bh)
      ctx.lineTo(0, pad * 0.6 + bh)
      ctx.closePath()
      ctx.fill()
      ctx.font = disp(56 * u)
      ctx.fillStyle = '#fff'
      ctx.textBaseline = 'middle'
      ctx.textAlign = 'left'
      ctx.fillText(badge || 'ব্রেকিং নিউজ', pad, pad * 0.6 + bh / 2 + 4 * u)
      const maxW = W - pad * 2
      const ft = fitText(ctx, title, disp, maxW, portrait ? 4 : 2, (portrait ? 84 : 76) * u, 40 * u, 1.18)
      const bandH = ft.lines.length * ft.lineHeight + pad * 1.2
      const y0 = H - bandH
      const g = ctx.createLinearGradient(0, y0, W, H)
      g.addColorStop(0, BRAND.navy)
      g.addColorStop(1, BRAND.blue)
      ctx.fillStyle = g
      ctx.fillRect(0, y0, W, bandH)
      ctx.fillStyle = BRAND.red
      ctx.fillRect(0, y0 - 10 * u, W, 10 * u)
      ctx.font = disp(ft.size)
      textLines(ctx, ft.lines, pad, y0 + pad * 0.6, ft.lineHeight, '#fff', false)
      logo(ctx, env, W - pad, y0 - 10 * u - 66 * u, 50 * u, 'right')
      break
    }
    case 'face': {
      if (portrait) {
        // portrait: face on top, headline below
        const g = ctx.createLinearGradient(0, H * 0.4, 0, H)
        g.addColorStop(0, 'rgba(11,39,70,0)')
        g.addColorStop(0.5, 'rgba(11,39,70,0.9)')
        g.addColorStop(1, BRAND.navy)
        ctx.fillStyle = g
        ctx.fillRect(0, H * 0.4, W, H * 0.6)
        const r = W * 0.24
        const facePic = face ?? pic
        if (facePic) circle(ctx, facePic, W / 2, H * 0.52 - r * 0.2, r)
        const ft = fitText(ctx, title, disp, W - pad * 2, 4, 80 * u, 40 * u, 1.18)
        ctx.font = disp(ft.size)
        const y = H - pad - ft.lines.length * ft.lineHeight
        textLines(ctx, ft.lines, pad, y, ft.lineHeight, '#fff')
        if (badge) badgePill(ctx, badge, pad, pad, u, BRAND.red)
        logo(ctx, env, W - pad, pad, 50 * u, 'right')
        break
      }
      // landscape: navy panel on the right, slanted edge, round face on the seam
      const x0 = W * 0.56
      const g = ctx.createLinearGradient(x0, 0, W, H)
      g.addColorStop(0, BRAND.navy)
      g.addColorStop(1, BRAND.blue)
      ctx.fillStyle = g
      ctx.beginPath()
      ctx.moveTo(x0 + 60 * u, 0)
      ctx.lineTo(W, 0)
      ctx.lineTo(W, H)
      ctx.lineTo(x0, H)
      ctx.closePath()
      ctx.fill()
      ctx.strokeStyle = BRAND.red
      ctx.lineWidth = 10 * u
      ctx.beginPath()
      ctx.moveTo(x0 + 60 * u, 0)
      ctx.lineTo(x0, H)
      ctx.stroke()
      const r = H * 0.2
      const facePic = face ?? pic
      if (facePic) circle(ctx, facePic, x0 + 30 * u, H * 0.3, r)
      const tx = x0 + 70 * u
      const ft = fitText(ctx, title, disp, W - tx - pad * 0.8, 4, 64 * u, 34 * u, 1.18)
      ctx.font = disp(ft.size)
      const blockH = ft.lines.length * ft.lineHeight
      const y = Math.max(H * 0.3 + r + 24 * u, H - pad - blockH)
      textLines(ctx, ft.lines, tx, y, ft.lineHeight, '#fff', false)
      if (badge) badgePill(ctx, badge, pad, pad, u, BRAND.red)
      logo(ctx, env, W - pad * 0.8, pad * 0.8, 44 * u, 'right')
      break
    }
    case 'minimal': {
      const g = ctx.createLinearGradient(0, H * 0.5, 0, H)
      g.addColorStop(0, 'rgba(0,0,0,0)')
      g.addColorStop(1, 'rgba(0,0,0,0.7)')
      ctx.fillStyle = g
      ctx.fillRect(0, H * 0.5, W, H * 0.5)
      const ft = fitText(ctx, title, (px) => body(700, px), W - pad * 2, portrait ? 4 : 2, 60 * u, 32 * u, 1.25)
      ctx.font = body(700, ft.size)
      const y = H - pad - ft.lines.length * ft.lineHeight
      textLines(ctx, ft.lines, pad, y, ft.lineHeight, '#fff')
      if (badge) {
        ctx.font = body(700, 28 * u)
        ctx.fillStyle = '#FACC15'
        ctx.textBaseline = 'bottom'
        ctx.fillText(badge, pad, y - 10 * u)
      }
      // white logo on a soft plate, top-left
      fillRoundRect(ctx, pad - 14 * u, pad - 12 * u, (env.logo.w / env.logo.h) * 40 * u + 28 * u, 64 * u, 12 * u, 'rgba(255,255,255,0.92)')
      logo(ctx, env, pad, pad, 40 * u, 'left', false)
      break
    }
  }
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

/** Paints the designed thumbnail and returns it as a JPEG. */
export async function thumbnailJpeg(env: DrawEnv, pic: Picture | null, face: Picture | null, src: number): Promise<Blob | null> {
  const { W, H } = thumbSize(env.cfg, env.L)
  const canvas = document.createElement('canvas')
  canvas.width = W
  canvas.height = H
  const ctx = canvas.getContext('2d')
  if (!ctx) return null
  drawThumbnail(ctx, env, pic, face, W, H, src)
  return new Promise((resolve) => canvas.toBlob((b) => resolve(b), 'image/jpeg', 0.9))
}
