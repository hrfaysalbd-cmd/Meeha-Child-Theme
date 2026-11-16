/**
 * r43 — fonts for cards, letters and print files.
 *
 * The same files serve two jobs:
 *  - the live preview (an inline <svg> in the page) uses them as normal
 *    document fonts (FontFace API);
 *  - an exported file (JPG/PNG/PDF) draws the SVG as an image, and an SVG
 *    image may only use fonts embedded in it, so fontCss() returns the
 *    @font-face rules with the files inlined as base64.
 *
 * Bengali: the theme's own Noto subsets (OFL). Latin: Montserrat,
 * Cormorant Garamond and Bebas Neue from fontsource (OFL).
 */

const BASE = `${import.meta.env.BASE_URL || '/'}brand/fonts/`

interface FontDef {
  family: string
  file: string
  weight: number
  style?: 'normal' | 'italic'
}

export const FONTS: FontDef[] = [
  { family: 'MBSans', file: 'meeha-sans-bn-400.woff2', weight: 400 },
  { family: 'MBSans', file: 'meeha-sans-bn-600.woff2', weight: 600 },
  { family: 'MBSerif', file: 'meeha-serif-bn-700.woff2', weight: 700 },
  { family: 'MMont', file: 'montserrat-latin-500-normal.woff2', weight: 500 },
  { family: 'MMont', file: 'montserrat-latin-700-normal.woff2', weight: 700 },
  { family: 'MMont', file: 'montserrat-latin-800-normal.woff2', weight: 800 },
  { family: 'MCorm', file: 'cormorant-garamond-latin-600-normal.woff2', weight: 600 },
  { family: 'MCorm', file: 'cormorant-garamond-latin-600-italic.woff2', weight: 600, style: 'italic' },
  { family: 'MBebas', file: 'bebas-neue-latin-400-normal.woff2', weight: 400 },
]

/** Font stacks: Bengali first (Latin falls through to Montserrat), or Latin first. */
export const F = {
  bn: "MBSans, MMont, 'Noto Sans Bengali', sans-serif",
  bnSerif: "MBSerif, MCorm, 'Noto Serif Bengali', serif",
  en: "MMont, MBSans, sans-serif",
  serif: "MCorm, MBSerif, serif",
  display: "MBebas, MMont, MBSans, sans-serif",
}

let docLoad: Promise<void> | null = null

/** Makes the fonts usable in the page (preview + text measuring). */
export function ensureFonts(): Promise<void> {
  if (docLoad) return docLoad
  docLoad = (async () => {
    if (typeof document === 'undefined' || !('fonts' in document)) return
    await Promise.all(
      FONTS.map(async (f) => {
        try {
          const face = new FontFace(f.family, `url(${BASE}${f.file}) format("woff2")`, { weight: String(f.weight), style: f.style ?? 'normal' })
          await face.load()
          document.fonts.add(face)
        } catch {
          /* a missing font only changes the look, never blocks */
        }
      }),
    )
  })()
  return docLoad
}

let cssLoad: Promise<string> | null = null

function toB64(buf: ArrayBuffer): string {
  const bytes = new Uint8Array(buf)
  let s = ''
  for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode(...bytes.subarray(i, i + 0x8000))
  return btoa(s)
}

/** @font-face rules with the fonts inlined (for exported SVG images). */
export function fontCss(): Promise<string> {
  if (cssLoad) return cssLoad
  cssLoad = (async () => {
    const parts = await Promise.all(
      FONTS.map(async (f) => {
        try {
          const r = await fetch(BASE + f.file)
          if (!r.ok) return ''
          const b64 = toB64(await r.arrayBuffer())
          return `@font-face{font-family:${f.family};src:url(data:font/woff2;base64,${b64}) format("woff2");font-weight:${f.weight};font-style:${f.style ?? 'normal'}}`
        } catch {
          return ''
        }
      }),
    )
    return parts.join('')
  })()
  return cssLoad
}
