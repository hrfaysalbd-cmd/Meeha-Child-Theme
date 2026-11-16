/**
 * r43 — small React pieces for designs (shared by both apps).
 */
import { useEffect, useMemo, useState } from 'react'
import { ensureFonts } from './fonts'
import { exportPages, printPages, type Format, type Page } from './export'

/** Makes a design SVG fill its box (keeps the aspect ratio). */
export function fitSvg(svg: string, cls = ''): string {
  return svg.replace(/<svg([^>]*?)\swidth="[^"]*"\s+height="[^"]*"/, `<svg$1 width="100%" class="${cls}" style="display:block;height:auto"`)
}

/** Waits for the design fonts (so text is measured and drawn right). */
export function useBrandFonts(): boolean {
  const [ok, setOk] = useState(false)
  useEffect(() => {
    let live = true
    ensureFonts().finally(() => live && setOk(true))
    return () => {
      live = false
    }
  }, [])
  return ok
}

export function SvgBox({ svg, className = '', shadow = true }: { svg: string; className?: string; shadow?: boolean }) {
  const html = useMemo(() => fitSvg(svg), [svg])
  return <div className={`${shadow ? 'drop-shadow-xl' : ''} ${className}`} dangerouslySetInnerHTML={{ __html: html }} />
}

/** The card that turns over on tap (front ⇄ back). */
export function FlipCard({ front, back, className = '' }: { front: string; back: string; className?: string }) {
  const [flip, setFlip] = useState(false)
  return (
    <button type="button" onClick={() => setFlip((f) => !f)} className={`block w-full [perspective:1200px] ${className}`} aria-label="কার্ড উল্টান">
      <div className="relative transition-transform duration-700 [transform-style:preserve-3d]" style={{ transform: flip ? 'rotateY(180deg)' : 'none' }}>
        <div className="[backface-visibility:hidden]">
          <SvgBox svg={front} />
        </div>
        <div className="absolute inset-0 [backface-visibility:hidden] [transform:rotateY(180deg)]">
          <SvgBox svg={back} />
        </div>
      </div>
    </button>
  )
}

const FORMATS: { f: Format | 'print'; label: string }[] = [
  { f: 'pdf', label: 'PDF' },
  { f: 'jpg', label: 'JPG' },
  { f: 'png', label: 'PNG' },
  { f: 'print', label: 'প্রিন্ট' },
]

/** JPG / PNG / PDF / প্রিন্ট buttons for a set of pages. */
export function ExportBar({ pages, name, dpi = 300, compact = false, hidePrint = false, onError }: { pages: () => Page[]; name: string; dpi?: number; compact?: boolean; hidePrint?: boolean; onError?: (m: string) => void }) {
  const [busy, setBusy] = useState<string>('')
  async function run(f: Format | 'print') {
    setBusy(f)
    try {
      const p = pages()
      if (f === 'print') await printPages(p, name)
      else await exportPages(p, f, name, dpi)
    } catch (e) {
      onError?.(e instanceof Error ? e.message : 'ডাউনলোড করা যায়নি।')
    } finally {
      setBusy('')
    }
  }
  return (
    <div className={`flex flex-wrap gap-2 ${compact ? '' : 'mt-1'}`}>
      {FORMATS.filter((x) => !(hidePrint && x.f === 'print')).map((x) => (
        <button
          key={x.f}
          type="button"
          disabled={!!busy}
          onClick={() => run(x.f)}
          className={`px-3.5 py-2 rounded-xl text-[13px] font-semibold border transition active:scale-[0.98] disabled:opacity-60 ${
            x.f === 'pdf' ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-neutral-700 border-neutral-200 hover:border-neutral-300'
          }`}
        >
          {busy === x.f ? 'তৈরি হচ্ছে…' : `⬇ ${x.label}`}
        </button>
      ))}
    </div>
  )
}

/** A picked photo → a small data: URL (JPEG, longest side `max`), so it can be drawn and saved with the design. */
export function fileToDataUrl(file: File, max = 900, type: 'image/jpeg' | 'image/png' = 'image/jpeg'): Promise<string> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const img = new Image()
    img.onload = () => {
      const k = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight))
      const c = document.createElement('canvas')
      c.width = Math.round(img.naturalWidth * k)
      c.height = Math.round(img.naturalHeight * k)
      const g = c.getContext('2d')!
      if (type === 'image/jpeg') {
        g.fillStyle = '#fff'
        g.fillRect(0, 0, c.width, c.height)
      }
      g.drawImage(img, 0, 0, c.width, c.height)
      URL.revokeObjectURL(url)
      resolve(c.toDataURL(type, 0.88))
    }
    img.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('ছবিটি পড়া যায়নি।'))
    }
    img.src = url
  })
}
