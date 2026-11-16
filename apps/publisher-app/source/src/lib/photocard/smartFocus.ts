/**
 * Round 24 — "স্মার্ট ফোকাস": where the photo's subject is, as a focal
 * point (0–100 % across, 0–100 % down). Every card design crops the photo
 * around that point, so one good point keeps faces whole in the square, the
 * tall and the wide designs alike.
 *
 * Faces first, when the browser has the Shape Detection API (Chrome with
 * the experimental flag, some Android builds — normal desktop Chrome usually
 * has no FaceDetector). Otherwise a quick look at the picture itself: skin
 * tones, detail (edges) and colour, weighted towards the middle and the
 * upper half where news photos keep their subject.
 */
import { loadImageElement } from './images'

export interface FocusGuess {
  x: number
  y: number
  how: 'face' | 'scene'
  faces?: number
}

interface DetectedFace {
  boundingBox: DOMRectReadOnly
}

type FaceDetectorCtor = new (o?: { fastMode?: boolean; maxDetectedFaces?: number }) => {
  detect: (src: CanvasImageSource) => Promise<DetectedFace[]>
}

const clamp = (v: number) => Math.max(0, Math.min(100, Math.round(v * 10) / 10))

export async function smartFocus(src: string): Promise<FocusGuess | null> {
  const img = await loadImageElement(src)
  const W = img.naturalWidth
  const H = img.naturalHeight
  if (!W || !H) return null

  const FD = (window as unknown as { FaceDetector?: FaceDetectorCtor }).FaceDetector
  if (FD) {
    try {
      const faces = await new FD({ fastMode: true, maxDetectedFaces: 8 }).detect(img)
      if (faces.length) {
        let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity
        for (const f of faces) {
          const b = f.boundingBox
          x0 = Math.min(x0, b.x); y0 = Math.min(y0, b.y)
          x1 = Math.max(x1, b.x + b.width); y1 = Math.max(y1, b.y + b.height)
        }
        // A little below the eyes: keeps the chin and shoulders in a tight box.
        return { x: clamp(((x0 + x1) / 2 / W) * 100), y: clamp(((y0 + (y1 - y0) * 0.6) / H) * 100), how: 'face', faces: faces.length }
      }
    } catch {
      /* fall through to the scene guess */
    }
  }

  const n = 96
  const w = W >= H ? n : Math.max(8, Math.round((n * W) / H))
  const h = W >= H ? Math.max(8, Math.round((n * H) / W)) : n
  const c = document.createElement('canvas')
  c.width = w
  c.height = h
  const x = c.getContext('2d', { willReadFrequently: true })!
  x.drawImage(img, 0, 0, w, h)
  let px: Uint8ClampedArray
  try {
    px = x.getImageData(0, 0, w, h).data
  } catch {
    return null
  }
  const lum = new Float32Array(w * h)
  for (let i = 0; i < w * h; i++) lum[i] = 0.299 * px[i * 4] + 0.587 * px[i * 4 + 1] + 0.114 * px[i * 4 + 2]

  const score = new Float32Array(w * h)
  let max = 0
  for (let j = 1; j < h - 1; j++) {
    for (let i = 1; i < w - 1; i++) {
      const k = j * w + i
      const r = px[k * 4], g = px[k * 4 + 1], b = px[k * 4 + 2]
      const edge = Math.abs(lum[k + 1] - lum[k - 1]) + Math.abs(lum[k + w] - lum[k - w])
      const mx = Math.max(r, g, b), mn = Math.min(r, g, b)
      const sat = mx ? (mx - mn) / mx : 0
      // Skin in YCbCr (all skin tones share a narrow Cb/Cr band).
      const cb = 128 - 0.168736 * r - 0.331264 * g + 0.5 * b
      const cr = 128 + 0.5 * r - 0.418688 * g - 0.081312 * b
      const skin = cr > 135 && cr < 175 && cb > 85 && cb < 135 && lum[k] > 50 ? 1 : 0
      const dx = i / w - 0.5, dy = j / h - 0.42
      const prior = Math.exp(-(dx * dx + dy * dy) / (2 * 0.32 * 0.32))
      const s = (edge / 120 + sat * 0.6 + skin * 1.4) * prior
      score[k] = s
      if (s > max) max = s
    }
  }
  if (!max) return { x: 50, y: 45, how: 'scene' }
  // The weighted middle of the strongest fifth of the picture.
  const sorted = Array.from(score).sort((a, b) => b - a)
  const cut = sorted[Math.floor(sorted.length * 0.2)] || 0
  let sx = 0, sy = 0, sw = 0
  for (let j = 0; j < h; j++) {
    for (let i = 0; i < w; i++) {
      const s = score[j * w + i]
      if (s >= cut && s > 0) {
        sx += i * s
        sy += j * s
        sw += s
      }
    }
  }
  if (!sw) return { x: 50, y: 45, how: 'scene' }
  return { x: clamp((sx / sw / (w - 1)) * 100), y: clamp((sy / sw / (h - 1)) * 100), how: 'scene' }
}
