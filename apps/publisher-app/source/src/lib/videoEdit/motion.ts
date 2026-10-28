/**
 * Round 21 — small motion-graphics toolkit for the video editor's canvas:
 * easing curves, time windows, deterministic noise, rounded shapes and
 * Bengali-safe text fitting (word wrap + shrink-to-fit + ellipsis).
 */

export type Ctx = CanvasRenderingContext2D | OffscreenCanvasRenderingContext2D

export const clamp = (v: number, a = 0, b = 1) => Math.min(b, Math.max(a, v))
export const lerp = (a: number, b: number, t: number) => a + (b - a) * t
/** 0 → 1 while `t` goes from `a` to `b`. */
export const win = (t: number, a: number, b: number) => clamp((t - a) / (b - a))

export const ease = {
  outCubic: (t: number) => 1 - Math.pow(1 - t, 3),
  inCubic: (t: number) => t * t * t,
  inOutCubic: (t: number) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2),
  outQuart: (t: number) => 1 - Math.pow(1 - t, 4),
  outBack: (t: number) => {
    const c1 = 1.70158
    const c3 = c1 + 1
    return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2)
  },
  outElastic: (t: number) =>
    t === 0 || t === 1 ? t : Math.pow(2, -10 * t) * Math.sin((t * 10 - 0.75) * ((2 * Math.PI) / 3)) + 1,
}

/** Deterministic 0..1 noise for index `i` (same every frame and every render). */
export function rnd(i: number) {
  const x = Math.sin(i * 127.1 + 311.7) * 43758.5453
  return x - Math.floor(x)
}

export function roundRect(ctx: Ctx, x: number, y: number, w: number, h: number, r: number) {
  const rr = Math.max(0, Math.min(r, w / 2, h / 2))
  ctx.beginPath()
  ctx.moveTo(x + rr, y)
  ctx.arcTo(x + w, y, x + w, y + h, rr)
  ctx.arcTo(x + w, y + h, x, y + h, rr)
  ctx.arcTo(x, y + h, x, y, rr)
  ctx.arcTo(x, y, x + w, y, rr)
  ctx.closePath()
}

export function fillRoundRect(ctx: Ctx, x: number, y: number, w: number, h: number, r: number, fill: string | CanvasGradient) {
  roundRect(ctx, x, y, w, h, r)
  ctx.fillStyle = fill
  ctx.fill()
}

export interface FittedText {
  size: number
  lines: string[]
  lineHeight: number
}

function wrap(ctx: Ctx, words: string[], maxW: number): string[] {
  const lines: string[] = []
  let line = ''
  for (const w of words) {
    const next = line ? `${line} ${w}` : w
    if (!line || ctx.measureText(next).width <= maxW) line = next
    else {
      lines.push(line)
      line = w
    }
  }
  if (line) lines.push(line)
  return lines
}

function ellipsize(ctx: Ctx, text: string, maxW: number) {
  if (ctx.measureText(text).width <= maxW) return text
  const chars = [...text]
  while (chars.length > 1 && ctx.measureText(chars.join('') + '…').width > maxW) chars.pop()
  return chars.join('').trimEnd() + '…'
}

/**
 * Largest font size (from `max` down to `min`) at which `text` fits in
 * `maxLines` lines of `maxW`. At `min` it still overflows → the last line
 * gets an ellipsis. `font(size)` returns the canvas font string.
 */
export function fitText(
  ctx: Ctx,
  text: string,
  font: (size: number) => string,
  maxW: number,
  maxLines: number,
  max: number,
  min: number,
  lineHeight = 1.22,
): FittedText {
  const words = text.trim().split(/\s+/).filter(Boolean)
  if (!words.length) return { size: max, lines: [], lineHeight: max * lineHeight }
  const step = Math.max(1, (max - min) / 14)
  for (let size = max; size >= min - 0.01; size -= step) {
    ctx.font = font(size)
    const lines = wrap(ctx, words, maxW)
    if (lines.length <= maxLines && lines.every((l) => ctx.measureText(l).width <= maxW)) {
      return { size, lines, lineHeight: size * lineHeight }
    }
  }
  ctx.font = font(min)
  const lines = wrap(ctx, words, maxW)
  const kept = lines.slice(0, maxLines)
  if (lines.length > maxLines || kept.some((l) => ctx.measureText(l).width > maxW)) {
    const rest = lines.slice(maxLines - 1).join(' ')
    kept[maxLines - 1] = ellipsize(ctx, rest, maxW)
    for (let i = 0; i < kept.length - 1; i++) kept[i] = ellipsize(ctx, kept[i], maxW)
  }
  return { size: min, lines: kept, lineHeight: min * lineHeight }
}

const BN_DIGITS = '০১২৩৪৫৬৭৮৯'
export const bnDigits = (s: string | number) => String(s).replace(/\d/g, (d) => BN_DIGITS[Number(d)])

/** "২৮ অক্টোবর ২০২৬" */
export function bnDate(d = new Date()) {
  const months = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর']
  return `${bnDigits(d.getDate())} ${months[d.getMonth()]} ${bnDigits(d.getFullYear())}`
}

/** "১:০৫" / "১২:৩০.৫" style time for the UI. */
export function bnClock(sec: number, tenths = false) {
  const s = Math.max(0, sec)
  const m = Math.floor(s / 60)
  const r = s - m * 60
  const whole = Math.floor(r)
  const base = `${m}:${String(whole).padStart(2, '0')}`
  return bnDigits(tenths ? `${base}.${Math.floor((r - whole) * 10)}` : base)
}
