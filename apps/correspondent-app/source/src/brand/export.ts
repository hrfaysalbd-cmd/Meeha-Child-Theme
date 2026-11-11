/**
 * r43 — turns a design (SVG string, sizes in mm) into files:
 *  - JPG / PNG at print resolution (300 dpi by default),
 *  - PDF (one page per side, exact paper size; the page is a 300 dpi
 *    image, so Bengali text looks exactly as on screen),
 *  - প্রিন্ট: the browser's own print dialog with the vector SVG, for a
 *    sharp vector PDF ("Save as PDF") or a direct print.
 */
import { fontCss } from './fonts'

export interface Page {
  svg: string
  /** paper size in mm */
  w: number
  h: number
  /** suffix for the file name when there is more than one page (e.g. "front") */
  name?: string
}

/** Puts the embedded @font-face rules into an SVG string. */
export async function withFonts(svg: string): Promise<string> {
  const css = await fontCss()
  return svg.replace(/<svg([^>]*)>/, `<svg$1><style>${css}</style>`)
}

function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.decoding = 'async'
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('ডিজাইনটি ছবি বানাতে গিয়ে আটকে গেছে।'))
    img.src = src
  })
}

/** Draws one page on a canvas at `dpi`. */
export async function rasterize(p: Page, dpi = 300, background = '#ffffff'): Promise<HTMLCanvasElement> {
  const svg = await withFonts(p.svg)
  const wPx = Math.round((p.w / 25.4) * dpi)
  const hPx = Math.round((p.h / 25.4) * dpi)
  const sized = svg.replace(/<svg([^>]*?)\swidth="[^"]*"\s+height="[^"]*"/, `<svg$1 width="${wPx}" height="${hPx}"`)
  const url = URL.createObjectURL(new Blob([sized], { type: 'image/svg+xml;charset=utf-8' }))
  try {
    const img = await loadImage(url)
    // fonts inside an SVG image can land a moment after onload: settle first
    await new Promise((r) => setTimeout(r, 120))
    const c = document.createElement('canvas')
    c.width = wPx
    c.height = hPx
    const g = c.getContext('2d')!
    if (background) {
      g.fillStyle = background
      g.fillRect(0, 0, wPx, hPx)
    }
    g.drawImage(img, 0, 0, wPx, hPx)
    return c
  } finally {
    URL.revokeObjectURL(url)
  }
}

export function canvasBlob(c: HTMLCanvasElement, type: 'image/jpeg' | 'image/png', q = 0.93): Promise<Blob> {
  return new Promise((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new Error('ফাইল বানানো যায়নি।'))), type, q))
}

// ---- a tiny PDF writer: JPEG pages (DCTDecode), exact paper size ----

const enc = new TextEncoder()

export async function pdfFromPages(pages: Page[], dpi = 300, title = 'Meeha'): Promise<Blob> {
  const imgs: { data: Uint8Array; w: number; h: number; mmW: number; mmH: number }[] = []
  for (const p of pages) {
    const c = await rasterize(p, dpi)
    const b = await canvasBlob(c, 'image/jpeg', 0.92)
    imgs.push({ data: new Uint8Array(await b.arrayBuffer()), w: c.width, h: c.height, mmW: p.w, mmH: p.h })
  }
  const chunks: Uint8Array[] = []
  const offsets: number[] = []
  let len = 0
  const push = (x: string | Uint8Array) => {
    const u = typeof x === 'string' ? enc.encode(x) : x
    chunks.push(u)
    len += u.length
  }
  const obj = (n: number, body: () => void) => {
    offsets[n] = len
    push(`${n} 0 obj\n`)
    body()
    push('\nendobj\n')
  }
  const n = imgs.length
  // 1 catalog, 2 pages, 3 info, then per page: page, content, image
  push('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n')
  const pageIds = imgs.map((_, i) => 4 + i * 3)
  obj(1, () => push('<< /Type /Catalog /Pages 2 0 R >>'))
  obj(2, () => push(`<< /Type /Pages /Count ${n} /Kids [${pageIds.map((id) => `${id} 0 R`).join(' ')}] >>`))
  obj(3, () => push(`<< /Producer (Meeha Newsroom) /Title (${title.replace(/[^\x20-\x7e]/g, '').replace(/[()\\]/g, '')}) >>`))
  imgs.forEach((im, i) => {
    const pid = 4 + i * 3
    const W = ((im.mmW / 25.4) * 72).toFixed(2)
    const H = ((im.mmH / 25.4) * 72).toFixed(2)
    obj(pid, () => push(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${W} ${H}] /Resources << /XObject << /Im0 ${pid + 2} 0 R >> >> /Contents ${pid + 1} 0 R >>`))
    const content = `q ${W} 0 0 ${H} 0 0 cm /Im0 Do Q`
    obj(pid + 1, () => push(`<< /Length ${content.length} >>\nstream\n${content}\nendstream`))
    obj(pid + 2, () => {
      push(`<< /Type /XObject /Subtype /Image /Width ${im.w} /Height ${im.h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${im.data.length} >>\nstream\n`)
      push(im.data)
      push('\nendstream')
    })
  })
  const total = 4 + n * 3
  const xref = len
  let x = `xref\n0 ${total}\n0000000000 65535 f \n`
  for (let i = 1; i < total; i++) x += `${String(offsets[i]).padStart(10, '0')} 00000 n \n`
  push(x)
  push(`trailer\n<< /Size ${total} /Root 1 0 R /Info 3 0 R >>\nstartxref\n${xref}\n%%EOF`)
  return new Blob(chunks as BlobPart[], { type: 'application/pdf' })
}

/** Saves a file (Web Share on phones that can't download, else a normal download). */
export async function saveBlob(blob: Blob, name: string) {
  const nav = navigator as Navigator & { canShare?: (d: unknown) => boolean }
  const isApp = typeof window !== 'undefined' && !!(window as unknown as { Capacitor?: { isNativePlatform?: () => boolean } }).Capacitor?.isNativePlatform?.()
  if (isApp && nav.canShare) {
    const file = new File([blob], name, { type: blob.type })
    if (nav.canShare({ files: [file] })) {
      try {
        await nav.share({ files: [file], title: name })
        return
      } catch {
        /* fall back to a download */
      }
    }
  }
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 30_000)
}

export type Format = 'jpg' | 'png' | 'pdf'

/** Downloads the pages in one format (JPG/PNG: one file per page). */
export async function exportPages(pages: Page[], format: Format, base: string, dpi = 300) {
  const safe = base.replace(/[\\/:*?"<>|]+/g, '-').slice(0, 80) || 'meeha'
  if (format === 'pdf') {
    await saveBlob(await pdfFromPages(pages, dpi, safe), `${safe}.pdf`)
    return
  }
  for (const p of pages) {
    const c = await rasterize(p, dpi, format === 'png' ? '' : '#ffffff')
    const b = await canvasBlob(c, format === 'png' ? 'image/png' : 'image/jpeg')
    await saveBlob(b, `${safe}${pages.length > 1 && p.name ? '-' + p.name : ''}.${format}`)
  }
}

/** A small image (data URL) of a page — for thumbnails and the mockup gallery. */
export async function thumb(p: Page, widthPx = 480): Promise<string> {
  const dpi = (widthPx / p.w) * 25.4
  const c = await rasterize(p, dpi, '')
  return c.toDataURL('image/png')
}

/** The browser print dialog with the vector pages at their real size. */
export async function printPages(pages: Page[], title = 'Meeha') {
  const css = await fontCss()
  const w = pages[0]?.w ?? 210
  const h = pages[0]?.h ?? 297
  const body = pages
    .map((p) => `<div class="pg">${p.svg.replace(/<svg([^>]*?)\swidth="[^"]*"\s+height="[^"]*"/, `<svg$1 width="${p.w}mm" height="${p.h}mm"`)}</div>`)
    .join('')
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${title}</title><style>${css}
@page{size:${w}mm ${h}mm;margin:0}html,body{margin:0;padding:0}.pg{width:${w}mm;height:${h}mm;overflow:hidden;page-break-after:always;break-after:page}.pg:last-child{page-break-after:auto;break-after:auto}svg{display:block}
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>${body}</body></html>`
  const f = document.createElement('iframe')
  f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden'
  document.body.appendChild(f)
  const d = f.contentDocument!
  d.open()
  d.write(html)
  d.close()
  await new Promise((r) => setTimeout(r, 400))
  try {
    await (f.contentWindow as Window & { document: Document }).document.fonts?.ready
  } catch {
    /* ignore */
  }
  f.contentWindow?.focus()
  f.contentWindow?.print()
  setTimeout(() => f.remove(), 60_000)
}
