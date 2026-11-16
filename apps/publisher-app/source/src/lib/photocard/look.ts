/**
 * Round 24 — photo tone for a card (উজ্জ্বলতা, কনট্রাস্ট, রং, উষ্ণতা,
 * সাদা-কালো). The renderer draws whatever picture it is given, so the
 * adjusted photo is baked into a new blob: URL once per setting and reused
 * by every design and size. The original file is never changed.
 */
import { loadImageElement } from './images'

export interface PhotoLook {
  bright: number // -40 … 40 (%)
  contrast: number // -40 … 40
  sat: number // -100 … 60
  warm: number // -30 … 30
  gray: boolean
}

export const NO_LOOK: PhotoLook = { bright: 0, contrast: 0, sat: 0, warm: 0, gray: false }

export const LOOK_PRESETS: { id: string; label: string; look: PhotoLook }[] = [
  { id: 'none', label: 'আসল', look: NO_LOOK },
  { id: 'clear', label: 'ঝকঝকে', look: { bright: 6, contrast: 12, sat: 10, warm: 0, gray: false } },
  { id: 'bright', label: 'উজ্জ্বল', look: { bright: 16, contrast: 4, sat: 4, warm: 0, gray: false } },
  { id: 'warm', label: 'উষ্ণ', look: { bright: 4, contrast: 6, sat: 8, warm: 16, gray: false } },
  { id: 'cool', label: 'শীতল', look: { bright: 2, contrast: 8, sat: -6, warm: -14, gray: false } },
  { id: 'news', label: 'সংবাদ', look: { bright: 0, contrast: 16, sat: -18, warm: 0, gray: false } },
  { id: 'mono', label: 'সাদা-কালো', look: { bright: 2, contrast: 14, sat: 0, warm: 0, gray: true } },
]

export function isPlain(l: PhotoLook | null | undefined) {
  return !l || (!l.bright && !l.contrast && !l.sat && !l.warm && !l.gray)
}

const MAX_SIDE = 2400
const cache = new Map<string, Promise<string>>()

export function applyLook(src: string, look: PhotoLook): Promise<string> {
  if (isPlain(look)) return Promise.resolve(src)
  const key = `${src}|${look.bright}|${look.contrast}|${look.sat}|${look.warm}|${look.gray ? 1 : 0}`
  const hit = cache.get(key)
  if (hit) return hit
  const job = loadImageElement(src).then(
    (img) =>
      new Promise<string>((resolve) => {
        const k = Math.min(1, MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight))
        const c = document.createElement('canvas')
        c.width = Math.round(img.naturalWidth * k)
        c.height = Math.round(img.naturalHeight * k)
        const x = c.getContext('2d')!
        x.filter = [
          `brightness(${1 + look.bright / 100})`,
          `contrast(${1 + look.contrast / 100})`,
          look.gray ? 'grayscale(1)' : `saturate(${1 + look.sat / 100})`,
        ].join(' ')
        x.drawImage(img, 0, 0, c.width, c.height)
        x.filter = 'none'
        if (look.warm && !look.gray) {
          // A soft colour wash: amber for warm, blue for cool.
          x.globalCompositeOperation = 'soft-light'
          x.fillStyle = look.warm > 0 ? `rgba(255,150,40,${look.warm / 100})` : `rgba(40,120,255,${-look.warm / 100})`
          x.fillRect(0, 0, c.width, c.height)
          x.globalCompositeOperation = 'source-over'
        }
        c.toBlob((b) => resolve(b ? URL.createObjectURL(b) : src), 'image/jpeg', 0.93)
      }),
  )
  cache.set(key, job)
  job.catch(() => cache.delete(key))
  return job
}
