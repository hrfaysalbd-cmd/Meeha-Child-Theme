/**
 * Round 25 — the card's own settings beyond the words and the photo: the
 * blue shade, the highlight style, the title size, where the logo goes, the
 * ad strips, a border, the photo credit, and the layers (texts, arrows,
 * marks, stickers, round inset photos, blur) drawn over the design.
 * Everything here is plain data, so a change can be undone and a whole look
 * can be kept as a preset.
 */

export type LogoPos = 'auto' | 'tl' | 'tc' | 'tr' | 'bl' | 'bc' | 'br' | 'hide'
export type AdPos = 'bottom' | 'top' | 'both'

export interface CardStyle {
  palette: string
  hiStyle: 'text' | 'marker'
  titleScale: number
  logoPos: LogoPos
  logoScale: number
  logoStyle: 'auto' | 'plate'
  border: 'none' | 'white' | 'amber' | 'navy'
  credit: string
}

export const DEFAULT_STYLE: CardStyle = {
  palette: 'auto',
  hiStyle: 'text',
  titleScale: 1,
  logoPos: 'auto',
  logoScale: 1,
  logoStyle: 'auto',
  border: 'none',
  credit: '',
}

export const BORDERS: Record<CardStyle['border'], { label: string; color?: string; inner?: string }> = {
  none: { label: 'নেই' },
  white: { label: 'সাদা', color: '#FFFFFF', inner: '#0B2A6B' },
  amber: { label: 'অ্যাম্বার', color: '#FFC20E' },
  navy: { label: 'নেভি', color: '#0B2A6B', inner: '#FFC20E' },
}

export type LayerKind = 'text' | 'arrow' | 'shape' | 'mark' | 'sticker' | 'inset' | 'blur'

export interface Layer {
  /** Stable id for React lists. */
  id: string
  t: LayerKind
  /** Centre (start point for arrows), % of the card's content area. */
  x: number
  y: number
  x2?: number
  y2?: number
  w?: number
  h?: number
  size?: number
  color?: string
  text?: string
  bg?: 'none' | 'box' | 'pill' | 'shadow' | 'outline'
  bgc?: string
  fam?: 'sans' | 'serif'
  bold?: boolean
  rot?: number
  curve?: number
  both?: boolean
  outline?: boolean
  /** shape: circle | rect | spot; mark: num | pin | …; sticker: live | …; inset: circle | round | square; blur: rect | ellipse */
  s?: string
  n?: number
  dash?: boolean
  fill?: boolean
  dim?: number
  /** inset: which photo of the studio (PhotoItem key), and its own crop */
  photoKey?: string
  focus?: { x: number; y: number }
  zoom?: number
  ring?: string
  label?: string
  mode?: 'blur' | 'pixel'
  amount?: number
  hidden?: boolean
  /** inset: the photo's blob URL, filled in just before rendering */
  img?: string
}

export const SWATCHES = ['#FFC20E', '#FFFFFF', '#D7261E', '#0B2A6B', '#1546B8', '#0E9F6E', '#111111', '#7C3AED']

let seq = 0
export const newId = () => `l${Date.now().toString(36)}${(seq++).toString(36)}`

/** A new layer of a kind, placed near the middle (a little apart from the last one). */
export function makeLayer(t: LayerKind, extra: Partial<Layer> = {}, nth = 0): Layer {
  const d = (nth % 5) * 4
  const base = { id: newId(), t, x: 50 + d, y: 40 + d }
  switch (t) {
    case 'text':
      return { ...base, text: 'এখানে লিখুন', size: 46, color: '#FFFFFF', bg: 'box', bgc: '#0B2A6B', fam: 'sans', bold: true, rot: 0, ...extra }
    case 'arrow':
      return { ...base, x: 30 + d, y: 30 + d, x2: 52 + d, y2: 46 + d, size: 12, color: '#FFC20E', curve: 0.25, both: false, outline: true, ...extra }
    case 'shape':
      return { ...base, w: 24, h: 24, s: 'circle', size: 8, color: '#FFC20E', dash: false, fill: false, dim: 0.6, ...extra }
    case 'mark':
      return { ...base, s: 'num', n: 1, size: 80, color: '#D7261E', ...extra }
    case 'sticker':
      return { ...base, x: 22 + d, y: 12 + d, s: 'live', size: 40, ...extra }
    case 'inset':
      return { ...base, x: 78, y: 26, size: 28, s: 'circle', ring: '#FFC20E', label: '', focus: { x: 50, y: 50 }, zoom: 1, ...extra }
    case 'blur':
      return { ...base, x: 30 + d, y: 62 + d, w: 18, h: 16, s: 'ellipse', mode: 'blur', amount: 18, ...extra }
  }
}

export const LAYER_LABEL: Record<LayerKind, string> = {
  text: 'লেখা',
  arrow: 'তীর',
  shape: 'দাগ/ঘের',
  mark: 'চিহ্ন',
  sticker: 'স্টিকার',
  inset: 'গোল ছবি',
  blur: 'ঝাপসা',
}

/** One-click looks. Each sets the design (if the tab has it) and the style. */
export interface StylePreset {
  id: string
  label: string
  hint: string
  design?: string
  style: Partial<CardStyle>
  layers?: Omit<Layer, 'id'>[]
}

export const STYLE_PRESETS: StylePreset[] = [
  { id: 'nil', label: 'মিহা নীল', hint: 'নীল ব্লক, অ্যাম্বার কি-ওয়ার্ড', design: 'nilblock', style: { palette: 'royal', hiStyle: 'text', logoPos: 'auto', border: 'none' } },
  { id: 'brk', label: 'ব্রেকিং ঝটপট', hint: 'টিভি টিকার, লাল ব্যাজ', design: 'tv', style: { palette: 'navy', titleScale: 1.08 } },
  { id: 'live', label: 'লাইভ আপডেট', hint: 'নীল ছায়া + লাইভ স্টিকার', design: 'nilchhaya', style: { palette: 'navy' }, layers: [{ t: 'sticker', x: 15, y: 9, s: 'live', size: 40 }] },
  { id: 'binodon', label: 'বিনোদন রঙিন', hint: 'উজ্জ্বল, নীল-বেগুনি', design: 'ujjol', style: { palette: 'indigo', hiStyle: 'marker' } },
  { id: 'orthoniti', label: 'অর্থনীতি', hint: 'টিল-নীল, তির্যক', design: 'tirjok', style: { palette: 'teal' } },
  { id: 'minimal', label: 'পরিষ্কার মিনিমাল', hint: 'সাদা, ছোট লোগো ওপরে বাঁয়ে', design: 'minimal', style: { palette: 'royal', logoPos: 'auto', logoScale: 0.9, border: 'none' } },
  { id: 'fact', label: 'ফ্যাক্টচেক', hint: 'মিনিমাল রেখা + ভুয়া সিল', design: 'rekha', style: { palette: 'navy' }, layers: [{ t: 'sticker', x: 76, y: 22, s: 'fake', size: 52 }] },
  { id: 'bigtitle', label: 'বড় শিরোনাম', hint: 'শিরোনাম ২০% বড়, মার্কার', style: { titleScale: 1.2, hiStyle: 'marker' } },
]

const MINE_KEY = 'meeha.photocard.mypresets.v1'

export interface MyPreset {
  id: string
  label: string
  design?: string
  style: CardStyle
  layers: Layer[]
}

export function loadMine(): MyPreset[] {
  try {
    const v = JSON.parse(localStorage.getItem(MINE_KEY) || '[]')
    return Array.isArray(v) ? v.slice(0, 24) : []
  } catch {
    return []
  }
}
export function saveMine(list: MyPreset[]) {
  try {
    localStorage.setItem(MINE_KEY, JSON.stringify(list.slice(0, 24)))
  } catch {
    /* private mode: presets only live this session */
  }
}

/** Ad strip picked for one slot. */
export type AdChoice =
  | { kind: 'house'; key: string; label: string }
  | { kind: 'theme'; id: string; label: string; url: string }
  | { kind: 'lib'; id: number; label: string; image: string; thumb: string; mediaKind: 'image' | 'gif' | 'video' }
