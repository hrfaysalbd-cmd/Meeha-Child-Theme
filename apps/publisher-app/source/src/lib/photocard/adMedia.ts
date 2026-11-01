/**
 * Round 25 — a gallery ad as a picture the card can draw: an image or GIF
 * comes through the API (a GIF gives its first frame), a video gives its
 * poster, or else one frame grabbed from the file itself.
 */
import { downloadMediaFile } from '../api'
import { imageById, imageByUrl } from './images'
import type { AdChoice } from './style'

const frames = new Map<number, Promise<string | null>>()

function grabFrame(id: number): Promise<string | null> {
  const hit = frames.get(id)
  if (hit) return hit
  const job = downloadMediaFile(id)
    .then(
      (blob) =>
        new Promise<string | null>((resolve) => {
          const url = URL.createObjectURL(blob)
          const v = document.createElement('video')
          v.muted = true
          v.playsInline = true
          v.preload = 'auto'
          const done = (out: string | null) => {
            URL.revokeObjectURL(url)
            resolve(out)
          }
          const timer = setTimeout(() => done(null), 20000)
          v.onloadedmetadata = () => {
            v.currentTime = Math.min(1, (v.duration || 2) / 2)
          }
          v.onseeked = () => {
            clearTimeout(timer)
            try {
              const c = document.createElement('canvas')
              c.width = v.videoWidth || 1080
              c.height = v.videoHeight || 120
              c.getContext('2d')!.drawImage(v, 0, 0, c.width, c.height)
              done(c.toDataURL('image/jpeg', 0.9))
            } catch {
              done(null)
            }
          }
          v.onerror = () => {
            clearTimeout(timer)
            done(null)
          }
          v.src = url
        }),
    )
    .catch(() => null)
  frames.set(id, job)
  job.then((u) => !u && frames.delete(id))
  return job
}

/** The renderer's ad descriptor for one strip (true = the empty placeholder strip). */
export async function adDescriptor(c: AdChoice | null): Promise<Record<string, unknown> | true> {
  if (!c) return true
  if (c.kind === 'house') return { house: c.key }
  if (c.kind === 'theme') {
    const u = await imageByUrl(c.url)
    return u ? { url: u } : true
  }
  let u: string | null = null
  if (c.mediaKind === 'video') u = (c.thumb ? await imageByUrl(c.thumb) : null) || (await grabFrame(c.id))
  else u = await imageById(c.id, 'full')
  return u ? { url: u } : true
}
