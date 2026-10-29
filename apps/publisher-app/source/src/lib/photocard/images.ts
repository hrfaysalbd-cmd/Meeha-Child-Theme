/**
 * Round 24 — every picture a card draws must reach the canvas as
 * same-origin bytes: the site's uploads folder sends no CORS headers, and a
 * canvas that drew a foreign picture can no longer be saved. So the site's
 * images come through GET /admin/photocard/file and are handed to the
 * renderer as blob: URLs (kept for the session; the studio reuses them for
 * every design and size).
 */
import { PhotocardApi } from '../api'
import { cardsBase } from './cards'

const cache = new Map<string, Promise<string | null>>()

function remember(key: string, job: () => Promise<Blob>): Promise<string | null> {
  const hit = cache.get(key)
  if (hit) return hit
  const p = job()
    .then((b) => URL.createObjectURL(b))
    .catch(() => {
      cache.delete(key)
      return null
    })
  cache.set(key, p)
  return p
}

/** A media-library image by id (the "large" copy when it is still ≥ 1080 px). */
export function imageById(id: number, size = 'large') {
  return remember(`i${id}:${size}`, () => PhotocardApi.imageById(id, size))
}

/**
 * Any image URL: blob:/data: as they are; the theme's bundled frames from
 * the app's own copy; the site's uploads through the API. Gives the original
 * URL back as a last try when the API can't serve it (the renderer then
 * simply leaves that picture out if the browser blocks it).
 */
export function imageByUrl(url: string | null | undefined): Promise<string | null> {
  if (!url) return Promise.resolve(null)
  if (/^(blob|data):/i.test(url)) return Promise.resolve(url)
  const m = url.match(/\/assets\/frames\/(.+)$/)
  if (m) return Promise.resolve(`${cardsBase()}assets/frames/${m[1]}`)
  return remember(`u${url}`, () => PhotocardApi.imageByUrl(url)).then((u) => u || url)
}

/** A photo picked from the device: shown straight from memory. */
export function imageFromFile(file: File) {
  const u = URL.createObjectURL(file)
  cache.set(`f${u}`, Promise.resolve(u))
  return u
}

export function loadImageElement(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.decoding = 'async'
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('ছবিটি খোলা যায়নি।'))
    img.src = src
  })
}
