import { useEffect, useState } from 'react'
import RichEditor, { type EditorStats } from './RichEditor'
import { IconCheck, IconX } from './Icon'
import { bnNumber } from './ui'

/**
 * Round 12: the overlay follows the *visual* viewport, so on a phone the
 * whole editor (header, text, bottom toolbar) shrinks to the area above the
 * on-screen keyboard instead of the keyboard covering the toolbar.
 */
function useVisualViewportBox() {
  const read = () => {
    const vv = typeof window !== 'undefined' ? window.visualViewport : null
    return vv ? { top: vv.offsetTop, height: vv.height } : null
  }
  const [box, setBox] = useState(read)
  useEffect(() => {
    const vv = window.visualViewport
    if (!vv) return
    let raf = 0
    const on = () => {
      cancelAnimationFrame(raf)
      raf = requestAnimationFrame(() => setBox({ top: vv.offsetTop, height: vv.height }))
    }
    vv.addEventListener('resize', on)
    vv.addEventListener('scroll', on)
    return () => {
      cancelAnimationFrame(raf)
      vv.removeEventListener('resize', on)
      vv.removeEventListener('scroll', on)
    }
  }, [])
  return box
}

export default function NewsEditorOverlay({
  initialContent,
  onDone,
  onClose,
}: {
  initialContent: string
  onDone: (html: string) => void
  onClose: () => void
}) {
  const [html, setHtml] = useState(initialContent)
  const [stats, setStats] = useState<EditorStats>({ words: 0, chars: 0, uploading: 0 })
  const [warn, setWarn] = useState('')
  const box = useVisualViewportBox()

  // The page behind must not scroll (iOS would otherwise move it under the
  // keyboard together with the overlay).
  useEffect(() => {
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = prev
    }
  }, [])

  function done() {
    if (stats.uploading > 0) {
      setWarn('ছবি আপলোড শেষ হওয়া পর্যন্ত অপেক্ষা করুন।')
      return
    }
    onDone(html)
  }

  function close() {
    if (html !== initialContent && !window.confirm('পরিবর্তনগুলো রাখা হবে না — এডিটর বন্ধ করবেন?')) return
    onClose()
  }

  return (
    <div
      className="fixed inset-x-0 top-0 z-50 flex justify-center bg-black/30"
      style={box ? { top: box.top, height: box.height } : { bottom: 0 }}
      data-testid="news-editor"
    >
      <div className="w-full max-w-[480px] bg-white flex flex-col h-full min-h-0">
        <div className="flex items-center justify-between gap-2 px-2 py-1 border-b border-neutral-100 shrink-0">
          <button onClick={close} aria-label="বন্ধ করুন" className="w-11 h-11 flex items-center justify-center text-neutral-500 active:opacity-60">
            <IconX className="w-5 h-5" />
          </button>
          <div className="min-w-0 text-center">
            <h1 className="text-[15px] font-semibold text-neutral-900 leading-tight">নিউজ এডিটর</h1>
            <p className="text-[11.5px] text-neutral-400 tabular-nums" data-testid="word-count" aria-live="polite">
              {bnNumber(stats.words)} শব্দ · {bnNumber(stats.chars)} অক্ষর
            </p>
          </div>
          <button
            onClick={done}
            className="flex items-center gap-1 h-11 px-3 rounded-lg bg-brand-600 text-white text-[14px] font-semibold active:scale-[0.97] transition"
          >
            <IconCheck className="w-4 h-4" />
            সম্পন্ন
          </button>
        </div>
        {warn && stats.uploading > 0 && (
          <p className="shrink-0 px-4 py-1.5 text-[12.5px] text-amber-700 bg-amber-50">{warn}</p>
        )}

        <div className="flex-1 min-h-0">
          <RichEditor content={html} onChange={setHtml} onStats={setStats} />
        </div>
      </div>
    </div>
  )
}
