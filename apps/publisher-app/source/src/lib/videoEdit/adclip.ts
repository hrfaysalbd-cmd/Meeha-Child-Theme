/**
 * r49.2 — a moving corner ad: a short MP4/WebM clip or an animated GIF/WebP from the advertiser,
 * decoded once into small frames and played on a loop in the corner box, all through the video
 * (the way TV channels run a bank's animated spot beside their logo).
 *
 * The box is small (at most ~20% of the picture's height), so frames are kept at most
 * MAX_H px tall, at most MAX_FPS, for at most MAX_SECONDS — memory stays modest
 * (15 s × 15 fps × 270 px ≈ 60 MB of bitmaps at worst).
 */
import { ALL_FORMATS, BlobSource, CanvasSink, Input } from 'mediabunny'
import type { Picture } from './draw'

export interface AdClip {
  frames: ImageBitmap[]
  /** Start time of each frame, seconds from the clip's start. */
  starts: number[]
  duration: number
  w: number
  h: number
}

const MAX_H = 270
const MAX_FPS = 15
export const AD_CLIP_MAX_SECONDS = 15
const MAX_FRAMES = MAX_FPS * AD_CLIP_MAX_SECONDS

/** Is this blob an image format that can move (GIF / WebP / APNG)? Sniffs the bytes, not only the type. */
async function maybeAnimated(blob: Blob): Promise<string | null> {
  const head = new Uint8Array(await blob.slice(0, 16).arrayBuffer())
  const ascii = (a: number, b: number) => String.fromCharCode(...head.slice(a, b))
  if (ascii(0, 3) === 'GIF') return 'image/gif'
  if (ascii(0, 4) === 'RIFF' && ascii(8, 12) === 'WEBP') return 'image/webp'
  if (head[0] === 0x89 && ascii(1, 4) === 'PNG') return 'image/png'
  return null
}

async function fromAnimatedImage(blob: Blob, type: string, signal?: AbortSignal): Promise<AdClip | null> {
  // ImageDecoder (WebCodecs) is in Chrome/Edge; elsewhere the image simply stays still.
  const Dec = (globalThis as unknown as { ImageDecoder?: typeof ImageDecoder }).ImageDecoder
  if (!Dec || !(await Dec.isTypeSupported(type))) return null
  const dec = new Dec({ data: await blob.arrayBuffer(), type })
  try {
    await dec.tracks.ready
    const track = dec.tracks.selectedTrack
    if (!track || !track.animated || track.frameCount < 2) return null
    const frames: ImageBitmap[] = []
    const starts: number[] = []
    let t = 0
    const n = Math.min(track.frameCount, MAX_FRAMES)
    for (let i = 0; i < n && t < AD_CLIP_MAX_SECONDS; i++) {
      if (signal?.aborted) break
      const { image } = await dec.decode({ frameIndex: i })
      const h = Math.min(MAX_H, image.displayHeight)
      const w = Math.max(1, Math.round((image.displayWidth * h) / image.displayHeight))
      frames.push(await createImageBitmap(image, { resizeWidth: w, resizeHeight: h, resizeQuality: 'high' }))
      starts.push(t)
      // GIFs often say 0 or 10 ms per frame; browsers treat that as 100 ms
      const d = (image.duration ?? 0) / 1e6
      t += d >= 0.02 ? d : 0.1
      image.close()
    }
    if (frames.length < 2) {
      frames.forEach((f) => f.close())
      return null
    }
    return { frames, starts, duration: t, w: frames[0].width, h: frames[0].height }
  } finally {
    dec.close()
  }
}

async function fromVideo(blob: Blob, signal?: AbortSignal): Promise<AdClip | null> {
  const input = new Input({ source: new BlobSource(blob), formats: ALL_FORMATS })
  try {
    const video = await input.getPrimaryVideoTrack()
    if (!video || !(await video.canDecode())) return null
    const total = Math.min(AD_CLIP_MAX_SECONDS, await input.computeDuration())
    if (!(total > 0)) return null
    const step = 1 / MAX_FPS
    const times: number[] = []
    for (let t = 0; t < total - step / 2; t += step) times.push(t)
    const h = Math.min(MAX_H, video.displayHeight)
    const w = Math.max(2, Math.round((video.displayWidth * h) / video.displayHeight))
    const sink = new CanvasSink(video, { width: w, height: h, fit: 'fill', poolSize: 1 })
    const frames: ImageBitmap[] = []
    const starts: number[] = []
    let i = 0
    for await (const wc of sink.canvasesAtTimestamps(times)) {
      if (signal?.aborted) break
      if (wc) {
        frames.push(await createImageBitmap(wc.canvas as CanvasImageSource & ImageBitmapSource))
        starts.push(times[i])
      }
      i++
    }
    if (!frames.length) return null
    return { frames, starts, duration: total, w: frames[0].width, h: frames[0].height }
  } finally {
    input.dispose?.()
  }
}

/**
 * Decode a corner-ad file into a looping clip. Returns null for a still image (or when this
 * browser can't decode it) — the caller then shows it as a still picture.
 */
export async function loadAdClip(blob: Blob, signal?: AbortSignal): Promise<AdClip | null> {
  if (blob.type.startsWith('video/')) return fromVideo(blob, signal)
  const img = await maybeAnimated(blob)
  if (img) return fromAnimatedImage(blob, img, signal)
  return null
}

/** The clip's picture at `t` seconds, looping. */
export function adClipFrame(clip: AdClip, t: number): Picture {
  const d = clip.duration > 0 ? clip.duration : 1
  const x = ((t % d) + d) % d
  // binary search: the last frame that starts at or before x
  let lo = 0
  let hi = clip.starts.length - 1
  while (lo < hi) {
    const mid = (lo + hi + 1) >> 1
    if (clip.starts[mid] <= x) lo = mid
    else hi = mid - 1
  }
  return { src: clip.frames[lo], w: clip.w, h: clip.h }
}

export function closeAdClip(clip: AdClip | null) {
  clip?.frames.forEach((f) => f.close())
}
