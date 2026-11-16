/**
 * Round 21 — the video editor's built-in assets: fonts for drawing Bengali
 * text on the canvas, the Meeha logo (cut into its "M" circle and its
 * wordmark so they can be animated separately) and the two clips Hafiz
 * made (logo animation intro, social outro). All are bundled with the app
 * under /video-kit/, so a render never depends on Google Fonts or on a
 * cross-origin file.
 */

const KIT = `${import.meta.env.BASE_URL}video-kit/`

export const BUILTIN_CLIPS = {
  'meeha-logo': `${KIT}intro-meeha-logo.mp4`,
  'meeha-social': `${KIT}outro-meeha-social.mp4`,
} as const

export const BRAND = {
  blue: '#025AB2',
  navy: '#0B2746',
  red: '#CC2A1F',
  white: '#FFFFFF',
}

/** Canvas font families registered by loadFonts(). */
export const FONT_BODY = '"MeehaKit Sans", "Noto Sans Bengali", system-ui, sans-serif'
export const FONT_DISPLAY = '"MeehaKit Display", "MeehaKit Sans", "Noto Sans Bengali", system-ui, sans-serif'

const FONT_FILES: [family: string, weight: string, file: string, range: string][] = [
  ['MeehaKit Sans', '500', 'noto-sans-bengali-bengali-500-normal.woff2', 'U+0951-0952,U+0964-0965,U+0980-09FE,U+1CD0,U+1CD2,U+1CD5-1CD6,U+1CD8,U+1CE1,U+1CEA,U+1CED,U+1CF2,U+1CF5-1CF7,U+200C-200D,U+20B9,U+25CC,U+A8F1'],
  ['MeehaKit Sans', '500', 'noto-sans-bengali-latin-500-normal.woff2', 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD'],
  ['MeehaKit Sans', '700', 'noto-sans-bengali-bengali-700-normal.woff2', 'U+0951-0952,U+0964-0965,U+0980-09FE,U+1CD0,U+1CD2,U+1CD5-1CD6,U+1CD8,U+1CE1,U+1CEA,U+1CED,U+1CF2,U+1CF5-1CF7,U+200C-200D,U+20B9,U+25CC,U+A8F1'],
  ['MeehaKit Sans', '700', 'noto-sans-bengali-latin-700-normal.woff2', 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD'],
  ['MeehaKit Display', '700', 'baloo-da-2-bengali-700-normal.woff2', 'U+0951-0952,U+0964-0965,U+0980-09FE,U+1CD0,U+1CD2,U+1CD5-1CD6,U+1CD8,U+1CE1,U+1CEA,U+1CED,U+1CF2,U+1CF5-1CF7,U+200C-200D,U+20B9,U+25CC,U+A8F1'],
  ['MeehaKit Display', '700', 'baloo-da-2-latin-700-normal.woff2', 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD'],
  ['MeehaKit Display', '800', 'baloo-da-2-bengali-800-normal.woff2', 'U+0951-0952,U+0964-0965,U+0980-09FE,U+1CD0,U+1CD2,U+1CD5-1CD6,U+1CD8,U+1CE1,U+1CEA,U+1CED,U+1CF2,U+1CF5-1CF7,U+200C-200D,U+20B9,U+25CC,U+A8F1'],
  ['MeehaKit Display', '800', 'baloo-da-2-latin-800-normal.woff2', 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD'],
]

let fontsPromise: Promise<void> | null = null

/** Registers + loads the bundled fonts once (unicode-range subsets). */
export function loadFonts(): Promise<void> {
  if (!fontsPromise) {
    fontsPromise = Promise.all(
      FONT_FILES.map(async ([family, weight, file, range]) => {
        const face = new FontFace(family, `url(${KIT}fonts/${file}) format("woff2")`, { weight, unicodeRange: range, display: 'block' })
        await face.load()
        document.fonts.add(face)
      }),
    )
      .then(() => undefined)
      .catch((err) => {
        fontsPromise = null
        throw err
      })
  }
  return fontsPromise
}

// ---- logo ----

/**
 * The logo, trimmed to its visible pixels, plus the split point between
 * the round "M" mark and the "eeha News" wordmark (measured on the file
 * Hafiz supplied: mark = 236 px wide, wordmark starts 20 px after it).
 */
export interface LogoAsset {
  /** Full logo, brand blue. */
  color: CanvasImageSource
  /** Same shapes in white (for dark backgrounds). */
  white: CanvasImageSource
  w: number
  h: number
  /** Width of the round mark (from x = 0). */
  markW: number
  /** Where the wordmark starts. */
  wordX: number
}

function makeCanvas(w: number, h: number) {
  const c = document.createElement('canvas')
  c.width = w
  c.height = h
  return c
}

/** Bounding box of the non-transparent pixels. */
function alphaBox(img: HTMLImageElement | ImageBitmap) {
  const c = makeCanvas(img.width, img.height)
  const ctx = c.getContext('2d', { willReadFrequently: true })!
  ctx.drawImage(img, 0, 0)
  const { data, width, height } = ctx.getImageData(0, 0, c.width, c.height)
  let x0 = width
  let y0 = height
  let x1 = 0
  let y1 = 0
  const cols = new Uint8Array(width)
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      if (data[(y * width + x) * 4 + 3] > 20) {
        if (x < x0) x0 = x
        if (y < y0) y0 = y
        if (x > x1) x1 = x
        if (y > y1) y1 = y
        cols[x] = 1
      }
    }
  }
  if (x1 < x0) return { x0: 0, y0: 0, x1: width - 1, y1: height - 1, firstGap: -1, gapEnd: -1 }
  // First empty column run after the mark = end of the "M" circle.
  let firstGap = -1
  let gapEnd = -1
  for (let x = x0; x <= x1; x++) {
    if (!cols[x] && firstGap < 0) firstGap = x
    if (firstGap >= 0 && cols[x]) {
      gapEnd = x
      break
    }
  }
  return { x0, y0, x1, y1, firstGap, gapEnd }
}

function tint(src: CanvasImageSource, w: number, h: number, color: string) {
  const c = makeCanvas(w, h)
  const ctx = c.getContext('2d')!
  ctx.drawImage(src, 0, 0)
  ctx.globalCompositeOperation = 'source-in'
  ctx.fillStyle = color
  ctx.fillRect(0, 0, w, h)
  return c
}

export async function loadImage(src: string | Blob): Promise<ImageBitmap> {
  const blob = typeof src === 'string' ? await (await fetch(src)).blob() : src
  return createImageBitmap(blob)
}

/** Builds the LogoAsset from the bundled PNG, or from a custom logo blob. */
export async function loadLogo(custom?: Blob | null): Promise<LogoAsset> {
  const img = await loadImage(custom ?? `${KIT}meeha-logo.png`)
  const b = alphaBox(img)
  const w = b.x1 - b.x0 + 1
  const h = b.y1 - b.y0 + 1
  const color = makeCanvas(w, h)
  color.getContext('2d')!.drawImage(img, b.x0, b.y0, w, h, 0, 0, w, h)
  // A logo whose first part is roughly square (a round/square mark) can be
  // animated mark-then-wordmark; anything else is treated as one piece.
  const markW = b.firstGap > 0 ? b.firstGap - b.x0 : w
  const splittable = markW > h * 0.6 && markW < h * 1.6 && b.gapEnd > 0
  return {
    color,
    white: tint(color, w, h, '#ffffff'),
    w,
    h,
    markW: splittable ? markW : w,
    wordX: splittable ? b.gapEnd - b.x0 : w,
  }
}

// ---- clip files ----

export async function fetchBlob(url: string, signal?: AbortSignal): Promise<Blob> {
  const res = await fetch(url, { signal })
  if (!res.ok) throw new Error(`HTTP ${res.status} — ${url}`)
  return res.blob()
}
