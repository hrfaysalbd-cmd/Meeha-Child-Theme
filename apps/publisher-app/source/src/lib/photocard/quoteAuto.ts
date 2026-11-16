/**
 * Round 46 — "স্বয়ংক্রিয় কার্ড" for উক্তি: from the quote's length and the
 * speaker's photo (its shape and where the face is, from smart focus) pick
 * the 4–6 উক্তি কার্ড designs that will look best, one of each kind of
 * photo cut, so the newsroom gets a ready set to choose from in one click.
 * Also the photo's own colour as the card's accent (an option).
 */
import type { QuoteDesignMeta } from './cards'
import { loadImageElement } from './images'

export interface AutoInput {
  /** characters of the quote (markers left out) */
  len: number
  hasPhoto: boolean
  /** photo width ÷ height (0 when unknown) */
  aspect: number
  /** where the face is across the photo, 0–100 (50 when unknown) */
  faceX: number
}

/** The best `n` designs (ids), varied in how the photo is cut. */
export function pickQuoteDesigns(meta: Record<string, QuoteDesignMeta>, a: AutoInput, n = 6): string[] {
  const need = a.len > 260 ? 3 : a.len > 140 ? 2 : 1
  const tall = a.aspect > 0 && a.aspect < 0.9
  const wide = a.aspect > 1.25
  const off = Math.abs(a.faceX - 50) > 15
  const scored = Object.entries(meta).map(([id, m]) => {
    let s = 10
    if (!a.hasPhoto) s += m.photo === 'need' ? -20 : 6
    // text: a design that holds less than the quote needs loses, a roomy one for a short quote loses a little
    s += m.cap >= need ? 4 - (m.cap - need) : -6 * (need - m.cap)
    if (a.hasPhoto) {
      if (m.wants === 'tall') s += tall ? 4 : wide ? -3 : 0
      if (m.wants === 'wide') s += wide ? 4 : tall ? -3 : 0
      // big full-bleed photos need the face clear of the text column
      if (m.shape === 'full') s += a.len > 200 ? -3 : 2
      if (off && (m.shape === 'side' || m.shape === 'slash')) s += 2
    }
    return { id, m, s }
  })
  scored.sort((x, y) => y.s - x.s)
  const out: string[] = []
  const shapes = new Map<string, number>()
  const tones = new Map<string, number>()
  for (const r of scored) {
    if (out.length >= n) break
    if (!a.hasPhoto && r.m.photo === 'need') continue
    if ((shapes.get(r.m.shape) || 0) >= (a.hasPhoto ? 2 : 4)) continue
    if ((tones.get(r.m.tone) || 0) >= Math.ceil(n / 2)) continue
    out.push(r.id)
    shapes.set(r.m.shape, (shapes.get(r.m.shape) || 0) + 1)
    tones.set(r.m.tone, (tones.get(r.m.tone) || 0) + 1)
  }
  for (const r of scored) if (out.length < Math.min(4, scored.length) && !out.includes(r.id)) out.push(r.id)
  return out
}

const hex = (r: number, g: number, b: number) => '#' + [r, g, b].map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('')

function hsl(r: number, g: number, b: number) {
  r /= 255; g /= 255; b /= 255
  const mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2
  if (mx === mn) return { h: 0, s: 0, l }
  const d = mx - mn
  const s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn)
  const h = mx === r ? ((g - b) / d + (g < b ? 6 : 0)) / 6 : mx === g ? ((b - r) / d + 2) / 6 : ((r - g) / d + 4) / 6
  return { h, s, l }
}

function fromHsl(h: number, s: number, l: number) {
  const f = (n: number) => {
    const k = (n + h * 12) % 12
    const a = s * Math.min(l, 1 - l)
    return 255 * (l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1)))
  }
  return hex(f(0), f(8), f(4))
}

/**
 * The photo's own colour as a card accent: the most common lively hue
 * (skin tones and greys count less), made bright enough to read on navy.
 * null when the photo has no clear colour.
 */
export async function photoAccent(src: string): Promise<string | null> {
  const img = await loadImageElement(src)
  const c = document.createElement('canvas')
  c.width = 64
  c.height = 64
  const x = c.getContext('2d', { willReadFrequently: true })
  if (!x) return null
  x.drawImage(img, 0, 0, 64, 64)
  let px: Uint8ClampedArray
  try {
    px = x.getImageData(0, 0, 64, 64).data
  } catch {
    return null
  }
  const bins = new Array(24).fill(0)
  for (let i = 0; i < px.length; i += 4) {
    const { h, s, l } = hsl(px[i], px[i + 1], px[i + 2])
    if (s < 0.22 || l < 0.12 || l > 0.9) continue
    const b = Math.floor(h * 24) % 24
    // skin (orange, low-mid saturation) is in nearly every portrait: weigh it down
    const skin = h > 0.02 && h < 0.11 && s < 0.6 ? 0.25 : 1
    bins[b] += s * skin
  }
  let best = -1
  let bw = 0
  bins.forEach((w, i) => {
    if (w > bw) {
      bw = w
      best = i
    }
  })
  if (best < 0 || bw < 6) return null
  const h = (best + 0.5) / 24
  return fromHsl(h, 0.82, 0.6)
}
