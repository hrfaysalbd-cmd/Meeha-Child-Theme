/**
 * r43 — small helpers shared by every design (ID cards, visiting cards,
 * letterheads …). Shared, byte-for-byte, by the publisher and
 * correspondent apps (src/brand/), like src/chat/.
 */

/** মিহার তথ্য (GET /brand/org) — saved once, used by every design. */
export interface OrgInfo {
  name_bn: string
  name_en: string
  tagline_bn: string
  tagline_en: string
  website: string
  email: string
  email2: string
  phone: string
  phone2: string
  whatsapp: string
  address_bn: string
  address_en: string
  office_hours: string
  reg_no: string
  facebook: string
  youtube: string
  editor_name: string
  editor_title: string
  publisher_name: string
  publisher_title: string
  signer_name: string
  signer_title: string
  signature_url: string
  seal_url: string
  logo_url: string
  color_primary: string
  color_accent: string
  color_gold: string
  home_url?: string
  verify_base?: string
}

/** Signature / seal / custom logo as data: URIs (drawable on a canvas). */
export interface BrandAssets {
  signature?: string
  seal?: string
  logo?: string
}

export const ORG_FALLBACK: OrgInfo = {
  name_bn: 'মিহা নিউজ',
  name_en: 'Meeha News',
  tagline_bn: 'অনুসন্ধানী ও সর্বশেষ বাংলা সংবাদ',
  tagline_en: 'Investigative & latest Bangla news',
  website: 'meeha.news',
  email: 'tips@meeha.news',
  email2: '',
  phone: '',
  phone2: '',
  whatsapp: '',
  address_bn: '',
  address_en: '',
  office_hours: '',
  reg_no: '',
  facebook: '',
  youtube: '',
  editor_name: '',
  editor_title: 'সম্পাদক',
  publisher_name: '',
  publisher_title: 'প্রকাশক',
  signer_name: '',
  signer_title: 'সম্পাদক ও প্রকাশক',
  signature_url: '',
  seal_url: '',
  logo_url: '',
  color_primary: '#025AB2',
  color_accent: '#C8102E',
  color_gold: '#C9A54C',
}

export const esc = (s: unknown) =>
  String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')

const BN = '০১২৩৪৫৬৭৮৯'
export const bnDigits = (s: string | number) => String(s).replace(/[0-9]/g, (d) => BN[Number(d)])
export const enDigits = (s: string) => String(s).replace(/[০-৯]/g, (d) => String(BN.indexOf(d)))

const BN_MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর']
const EN_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

function parse(d: string | Date | undefined): Date | null {
  if (!d) return null
  if (d instanceof Date) return d
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(enDigits(d))
  if (!m) return null
  return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
}

/** "১১ নভেম্বর ২০২৬" */
export function bnDate(d?: string | Date): string {
  const x = parse(d)
  return x ? `${bnDigits(x.getDate())} ${BN_MONTHS[x.getMonth()]} ${bnDigits(x.getFullYear())}` : ''
}

/** "11 Nov 2026" */
export function enDate(d?: string | Date): string {
  const x = parse(d)
  return x ? `${x.getDate()} ${EN_MONTHS[x.getMonth()]} ${x.getFullYear()}` : ''
}

/** "১১/১১/২০২৬" */
export function bnShortDate(d?: string | Date): string {
  const x = parse(d)
  return x ? bnDigits(`${String(x.getDate()).padStart(2, '0')}/${String(x.getMonth() + 1).padStart(2, '0')}/${x.getFullYear()}`) : ''
}

export const todayIso = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** A unique prefix for gradient/filter ids, so two previews on one page never clash. */
let seq = 0
export const uid = (p = 'm') => `${p}${(++seq).toString(36)}${Math.random().toString(36).slice(2, 5)}`

// ---- text measuring + wrapping (the fonts must be loaded: ensureFonts()) ----

let ctx: CanvasRenderingContext2D | null = null
function measureCtx() {
  if (!ctx && typeof document !== 'undefined') ctx = document.createElement('canvas').getContext('2d')
  return ctx
}

/** Width of `text` in the same units as `size` (mm in our designs). */
export function textWidth(text: string, family: string, size: number, weight = 400, style = 'normal'): number {
  const c = measureCtx()
  if (!c) return text.length * size * 0.55
  c.font = `${style} ${weight} 100px ${family}`
  return (c.measureText(text).width / 100) * size
}

/** Font size that keeps `text` within `maxW` (shrinks from `size`, never below `min`). */
export function fitSize(text: string, family: string, size: number, maxW: number, weight = 400, min = size * 0.55): number {
  const w = textWidth(text, family, size, weight)
  if (w <= maxW) return size
  return Math.max(min, (size * maxW) / w)
}

/** Breaks text into lines no wider than maxW (keeps the writer's own line breaks). */
export function wrap(text: string, family: string, size: number, maxW: number, weight = 400): string[] {
  const out: string[] = []
  for (const para of String(text ?? '').split('\n')) {
    if (!para.trim()) {
      out.push('')
      continue
    }
    let line = ''
    for (const word of para.split(/\s+/)) {
      const tryLine = line ? `${line} ${word}` : word
      if (textWidth(tryLine, family, size, weight) <= maxW || !line) line = tryLine
      else {
        out.push(line)
        line = word
      }
    }
    if (line) out.push(line)
  }
  return out
}

/** <text> lines starting at y with a line height. */
export function lines(ls: string[], x: number, y: number, lh: number, attrs: string): string {
  return ls.map((l, i) => `<text x="${x}" y="${(y + i * lh).toFixed(2)}" ${attrs}>${esc(l)}</text>`).join('')
}

/** Mixes two hex colours (t = 0 → a, 1 → b). */
export function mix(a: string, b: string, t: number): string {
  const p = (h: string) => {
    const x = h.replace('#', '')
    const f = x.length === 3 ? x.split('').map((c) => c + c).join('') : x
    return [0, 2, 4].map((i) => parseInt(f.slice(i, i + 2), 16))
  }
  const A = p(a)
  const B = p(b)
  return '#' + A.map((v, i) => Math.round(v + (B[i] - v) * t).toString(16).padStart(2, '0')).join('')
}

/** Simple phone/email/web glyphs drawn as paths (0…10 box). */
export const ICON = {
  phone: 'M2.6 1.2 4.3 3.4c.3.4.3 1-.1 1.3l-.9.8c.6 1.3 1.6 2.4 2.9 3l.8-.9c.4-.4.9-.4 1.3-.1l2.2 1.7c.4.3.5.9.1 1.3l-1 1.1C8.4 12.8 1.2 7.3 1.1 2.6c0-.4.2-.7.5-1l.9-.6c.3-.2.8-.2 1.1.2Z',
  mail: 'M1 2.5h10v7H1zM1 2.7l5 3.8 5-3.8',
  web: 'M6 1a5 5 0 1 0 0 10A5 5 0 0 0 6 1Zm-5 5h10M6 1c1.6 1.7 1.6 8.3 0 10M6 1c-1.6 1.7-1.6 8.3 0 10',
  pin: 'M6 11S2 7 2 4.5a4 4 0 0 1 8 0C10 7 6 11 6 11Zm0-5.2a1.4 1.4 0 1 0 0-2.8 1.4 1.4 0 0 0 0 2.8Z',
  fb: 'M7 11V6.5h1.5l.3-1.8H7V3.6c0-.5.2-.9.9-.9h.9V1.1C8.5 1 8 1 7.4 1 6 1 5.2 1.8 5.2 3.3v1.4H3.7v1.8h1.5V11',
  yt: 'M1 6c0-2 .2-3 1.4-3.2C3.5 2.6 8.5 2.6 9.6 2.8 10.8 3 11 4 11 6s-.2 3-1.4 3.2c-1.1.2-6.1.2-7.2 0C1.2 9 1 8 1 6Zm4-1.8v3.6L8 6Z',
}

/** An icon (12-unit box) at x,y, `s` wide. Stroke icons get stroke, solid ones fill. */
export function icon(name: keyof typeof ICON, x: number, y: number, s: number, color: string): string {
  const k = s / 12
  const stroke = name === 'mail' || name === 'web'
  return `<path transform="translate(${x} ${y}) scale(${k})" d="${ICON[name]}" ${stroke ? `fill="none" stroke="${color}" stroke-width="1.1" stroke-linejoin="round"` : `fill="${color}" fill-rule="evenodd"`}/>`
}

/**
 * An <svg> document `w` × `h` mm. `bleed` widens the canvas on every side
 * (print files); `round` clips the corners (on-screen cards).
 */
export function svgDoc(w: number, h: number, body: string, opt: { bleed?: number; defs?: string; round?: number } = {}): string {
  const b = opt.bleed ?? 0
  const W = w + 2 * b
  const H = h + 2 * b
  const id = uid('clip')
  const clip = opt.round ? `<clipPath id="${id}"><rect x="0" y="0" width="${w}" height="${h}" rx="${opt.round}"/></clipPath>` : ''
  return `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="${W}mm" height="${H}mm" viewBox="${-b} ${-b} ${W} ${H}"><defs>${clip}${opt.defs ?? ''}</defs>${opt.round ? `<g clip-path="url(#${id})">${body}</g>` : body}</svg>`
}

/** Grey head-and-shoulders placeholder (no photo yet). */
export function silhouette(x: number, y: number, w: number, h: number, bg = '#dbe3ee', fg = '#9fb0c8'): string {
  const cx = x + w / 2
  return `<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${bg}"/><circle cx="${cx}" cy="${y + h * 0.4}" r="${w * 0.2}" fill="${fg}"/><path d="M${x + w * 0.12} ${y + h} C${x + w * 0.14} ${y + h * 0.68} ${x + w * 0.86} ${y + h * 0.68} ${x + w * 0.88} ${y + h}Z" fill="${fg}"/>`
}

/** Fine security lines (guilloche) across a box. */
export function guilloche(x: number, y: number, w: number, h: number, color: string, opacity = 0.14, rows = 16, sw = 0.1): string {
  let d = ''
  for (let i = 0; i < rows; i++) {
    const y0 = y + (h * (i + 0.5)) / rows
    const amp = 1.4 + (i % 3) * 0.5
    const wl = 14 + (i % 4) * 3
    const ph = i * 1.7
    let p = ''
    for (let t = 0; t <= w; t += 1) {
      const yy = y0 + amp * Math.sin(((t + ph) / wl) * Math.PI * 2) + amp * 0.45 * Math.sin(((t * 2.3 + ph) / wl) * Math.PI * 2)
      p += `${t === 0 ? 'M' : 'L'}${(x + t).toFixed(1)} ${yy.toFixed(2)}`
    }
    d += p
  }
  return `<path d="${d}" fill="none" stroke="${color}" stroke-width="${sw}" opacity="${opacity}"/>`
}

/** A gold-foil linear gradient definition. */
export const goldGrad = (id: string, angle = 0) =>
  `<linearGradient id="${id}" x1="0" y1="0" x2="1" y2="${angle}"><stop offset="0" stop-color="#8a6a1f"/><stop offset=".22" stop-color="#e9d38c"/><stop offset=".45" stop-color="#b38a2e"/><stop offset=".7" stop-color="#f6e7ad"/><stop offset="1" stop-color="#9a7524"/></linearGradient>`
