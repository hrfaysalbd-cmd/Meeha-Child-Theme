/**
 * Round 24 — the photocard renderer.
 *
 * public/photocard/cards.js is a byte-for-byte copy of the theme's own
 * renderer (theme/meeha/assets/js/cards.js, copied by `npm run
 * sync:photocard`), so a card made here looks exactly like the one the
 * site's "ফটোকার্ড ডাউনলোড" button makes. It is a plain script that sets
 * window.MeehaCards; it is loaded only when the studio opens, never with the
 * rest of the app.
 */

export interface CardTemplate {
  label: string
  ad: boolean
  photo: boolean
  presets: Record<string, string>
  fields: string[]
}

export interface MeehaCardsApi {
  configure: (c: Record<string, unknown>) => Record<string, unknown>
  render: (type: string, data: Record<string, unknown>, opts: Record<string, unknown>) => Promise<HTMLCanvasElement>
  toBlob: (canvas: HTMLCanvasElement, mime?: string, quality?: number) => Promise<Blob>
  filename: (type: string, title: string, date?: string, ext?: string) => string
  loadFonts: () => Promise<void>
  templates: Record<string, CardTemplate>
  quoteFrames: Record<string, { bg: string; ink: string; accent: string; label: string }>
  isDeco: (type: string, preset: string) => boolean
  isStandalone: (type: string) => boolean
  standaloneText: Record<string, [string, string, string][]>
  adAllowed: (type: string, data: Record<string, unknown>, opts: Record<string, unknown>) => boolean
  bnDigits: (s: string | number) => string
}

declare global {
  interface Window {
    MeehaCards?: MeehaCardsApi
    MeehaCardsConfig?: Record<string, unknown>
  }
}

// Bump together with the theme version whenever cards.js changes, so a
// browser never keeps an old copy next to new app code.
export const CARDS_VERSION = '2.4.3'

/** Absolute URL of public/photocard/ (fonts, frames and the script live there). */
export function cardsBase() {
  return new URL(`${import.meta.env.BASE_URL}photocard/`, window.location.href).href
}

let loading: Promise<MeehaCardsApi> | null = null

export function loadCards(): Promise<MeehaCardsApi> {
  if (window.MeehaCards) return Promise.resolve(window.MeehaCards)
  if (loading) return loading
  loading = new Promise<MeehaCardsApi>((resolve, reject) => {
    // Set before the script runs: cards.js never guesses its own folder then.
    window.MeehaCardsConfig = { ...(window.MeehaCardsConfig || {}), base: cardsBase() }
    const s = document.createElement('script')
    s.src = `${cardsBase()}cards.js?v=${CARDS_VERSION}`
    s.async = true
    s.onload = () => (window.MeehaCards ? resolve(window.MeehaCards) : reject(new Error('কার্ড রেন্ডারার চালু হয়নি।')))
    s.onerror = () => {
      loading = null
      s.remove()
      reject(new Error('কার্ড রেন্ডারার লোড হয়নি — ইন্টারনেট সংযোগ দেখে পেজটি আবার খুলুন।'))
    }
    document.head.appendChild(s)
  })
  return loading
}
