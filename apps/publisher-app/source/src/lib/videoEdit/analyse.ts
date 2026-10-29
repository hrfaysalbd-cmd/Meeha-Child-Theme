/**
 * Round 23 — reading the main video itself (from the downloaded file; the
 * preview's cross-origin media can't be read):
 *
 *  - waveformPeaks: the loudness of every 1/20 s, for the timeline, so
 *    speech and silence can be seen when cutting;
 *  - reframeTrack: where the interesting part of the picture is, about two
 *    times a second, so a 16:9 video cropped to 9:16 follows the people.
 *    Faces come from the browser's FaceDetector where it has one; otherwise
 *    (and between faces) skin tone and movement are used, with a pull to
 *    the centre. The path is smoothed so the crop glides instead of jumping.
 */
import { ALL_FORMATS, AudioBufferSink, BlobSource, CanvasSink, Input } from 'mediabunny'

export class AnalysisCancelled extends Error {
  constructor() {
    super('cancelled')
  }
}

export interface Waveform {
  /** Seconds per value. */
  step: number
  /** 0..1, one value per step of the source. */
  peaks: Float32Array
}

export async function waveformPeaks(blob: Blob, signal?: AbortSignal, onProgress?: (ratio: number) => void): Promise<Waveform | null> {
  const input = new Input({ source: new BlobSource(blob), formats: ALL_FORMATS })
  try {
    const track = await input.getPrimaryAudioTrack()
    if (!track || !(await track.canDecode())) return null
    const duration = await input.computeDuration()
    const step = 0.05
    const n = Math.max(1, Math.ceil(duration / step))
    const peaks = new Float32Array(n)
    const sink = new AudioBufferSink(track)
    let last = 0
    for await (const { buffer, timestamp } of sink.buffers()) {
      if (signal?.aborted) throw new AnalysisCancelled()
      const rate = buffer.sampleRate
      const chans = Array.from({ length: buffer.numberOfChannels }, (_, c) => buffer.getChannelData(c))
      const len = buffer.length
      // RMS per bucket (peaks look too busy for speech)
      let b = Math.floor(timestamp / step)
      let sum = 0
      let cnt = 0
      for (let i = 0; i < len; i++) {
        const bi = Math.floor((timestamp + i / rate) / step)
        if (bi !== b) {
          if (cnt && b >= 0 && b < n) peaks[b] = Math.max(peaks[b], Math.sqrt(sum / cnt))
          b = bi
          sum = 0
          cnt = 0
        }
        let v = 0
        for (const ch of chans) v += ch[i]
        v /= chans.length
        sum += v * v
        cnt++
      }
      if (cnt && b >= 0 && b < n) peaks[b] = Math.max(peaks[b], Math.sqrt(sum / cnt))
      const r = Math.min(1, (timestamp + buffer.duration) / duration)
      if (r - last > 0.02) {
        last = r
        onProgress?.(r)
      }
    }
    // to 0..1 on a dB scale (−54 dB … −6 dB) so quiet speech still shows
    for (let i = 0; i < n; i++) {
      const db = 20 * Math.log10(peaks[i] + 1e-9)
      peaks[i] = Math.min(1, Math.max(0, (db + 54) / 48))
    }
    return { step, peaks }
  } finally {
    input.dispose()
  }
}

// ---- smart reframe ----

interface FaceBox {
  boundingBox: DOMRectReadOnly
}
type FaceDetectorCtor = new (o?: { fastMode?: boolean; maxDetectedFaces?: number }) => { detect(src: CanvasImageSource): Promise<FaceBox[]> }

async function faceDetector() {
  const FD = (globalThis as unknown as { FaceDetector?: FaceDetectorCtor }).FaceDetector
  if (!FD) return null
  try {
    const d = new FD({ fastMode: true, maxDetectedFaces: 4 })
    // some builds expose the class but can't run it
    const probe = new OffscreenCanvas(32, 32)
    await d.detect(probe as unknown as CanvasImageSource)
    return d
  } catch {
    return null
  }
}

const SW = 96

/** Centre of skin-coloured and moving pixels (0..1), with its strength, or null. */
function saliency(cur: Uint8ClampedArray, prev: Uint8ClampedArray | null, w: number, h: number): { x: number; y: number; m: number } | null {
  let sx = 0
  let sy = 0
  let sw = 0
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const i = (y * w + x) * 4
      const r = cur[i]
      const g = cur[i + 1]
      const b = cur[i + 2]
      const Y = 0.299 * r + 0.587 * g + 0.114 * b
      const cb = 128 - 0.168736 * r - 0.331264 * g + 0.5 * b
      const cr = 128 + 0.5 * r - 0.418688 * g - 0.081312 * b
      let wgt = 0
      if (Y > 40 && cb >= 77 && cb <= 127 && cr >= 137 && cr <= 173) wgt += 1
      if (prev) {
        const pY = 0.299 * prev[i] + 0.587 * prev[i + 1] + 0.114 * prev[i + 2]
        const d = Math.abs(Y - pY)
        if (d > 22) wgt += Math.min(1.2, d / 60)
      }
      // ignore the very top and bottom (sky, ground, burnt-in captions)
      if (y < h * 0.06 || y > h * 0.94) wgt *= 0.3
      if (wgt > 0) {
        sx += x * wgt
        sy += y * wgt
        sw += wgt
      }
    }
  }
  const m = sw / (w * h)
  if (m < 0.004) return null
  return { x: (sx / sw + 0.5) / w, y: (sy / sw + 0.5) / h, m }
}

function smooth(v: number[], sigma: number): number[] {
  if (v.length < 3 || sigma <= 0) return v
  const r = Math.ceil(sigma * 2.5)
  const k: number[] = []
  for (let i = -r; i <= r; i++) k.push(Math.exp(-(i * i) / (2 * sigma * sigma)))
  return v.map((_, i) => {
    let s = 0
    let w = 0
    for (let j = -r; j <= r; j++) {
      const t = Math.min(v.length - 1, Math.max(0, i + j))
      s += v[t] * k[j + r]
      w += k[j + r]
    }
    return s / w
  })
}

function median(v: number[], r: number): number[] {
  return v.map((_, i) => {
    const a = v.slice(Math.max(0, i - r), i + r + 1).sort((x, y) => x - y)
    return a[a.length >> 1]
  })
}

export interface ReframeResult {
  step: number
  xs: number[]
  ys: number[]
  faces: boolean
}

/**
 * Follows the action through the whole main video. `maxSamples` caps the
 * track length (config LIMITS.reframe).
 */
export async function reframeTrack(blob: Blob, maxSamples: number, signal?: AbortSignal, onProgress?: (ratio: number) => void): Promise<ReframeResult | null> {
  const input = new Input({ source: new BlobSource(blob), formats: ALL_FORMATS })
  try {
    const video = await input.getPrimaryVideoTrack()
    if (!video || !(await video.canDecode())) return null
    const duration = await input.computeDuration()
    const step = Math.max(0.5, Math.ceil((duration / (maxSamples - 1)) * 10) / 10)
    const n = Math.min(maxSamples, Math.floor(duration / step) + 1)
    const times = Array.from({ length: n }, (_, i) => Math.min(duration - 0.05, i * step))
    const fd = await faceDetector()
    const sink = new CanvasSink(video, { width: Math.min(fd ? 480 : 192, video.displayWidth), poolSize: 1 })
    const small = new OffscreenCanvas(SW, Math.max(8, Math.round((SW * video.displayHeight) / video.displayWidth)))
    const sctx = small.getContext('2d', { willReadFrequently: true })!
    const raw: ({ x: number; y: number; w: number } | null)[] = []
    let prev: Uint8ClampedArray | null = null
    let faces = false
    let i = 0
    for await (const wc of sink.canvasesAtTimestamps(times)) {
      if (signal?.aborted) throw new AnalysisCancelled()
      if (!wc) {
        raw.push(null)
        i++
        continue
      }
      const cv = wc.canvas
      let point: { x: number; y: number; w: number } | null = null
      if (fd) {
        try {
          const found = await fd.detect(cv as CanvasImageSource)
          if (found.length) {
            // the biggest face, a little above its centre (the eyes)
            const f = found.reduce((a, b) => (b.boundingBox.width * b.boundingBox.height > a.boundingBox.width * a.boundingBox.height ? b : a))
            const bb = f.boundingBox
            point = { x: (bb.x + bb.width / 2) / cv.width, y: (bb.y + bb.height * 0.45) / cv.height, w: 3 }
            faces = true
          }
        } catch {
          // fall through to the colour / motion guess
        }
      }
      sctx.drawImage(cv as CanvasImageSource, 0, 0, small.width, small.height)
      const data = sctx.getImageData(0, 0, small.width, small.height).data
      if (!point) {
        const s = saliency(data, prev, small.width, small.height)
        if (s) point = { x: s.x, y: s.y, w: Math.min(1, s.m * 12) }
      }
      prev = new Uint8ClampedArray(data)
      raw.push(point)
      i++
      if (i % 4 === 0) onProgress?.(i / n)
    }
    // fill gaps (hold the last point, drift to the centre when nothing is found)
    const xs: number[] = []
    const ys: number[] = []
    const first = raw.find((p) => p)
    let lx = first?.x ?? 0.5
    let ly = first?.y ?? 0.45
    for (const p of raw) {
      if (p) {
        const a = Math.min(1, 0.35 + p.w * 0.5)
        lx = lx + (p.x - lx) * a
        ly = ly + (p.y - ly) * a
      } else {
        lx += (0.5 - lx) * 0.08
        ly += (0.45 - ly) * 0.08
      }
      xs.push(lx)
      ys.push(ly)
    }
    const sigma = 1.6 / step
    const sx = smooth(median(xs, 2), sigma)
    const sy = smooth(median(ys, 2), sigma)
    const r2 = (v: number) => Math.round(Math.min(1, Math.max(0, v)) * 100) / 100
    return { step, xs: sx.map(r2), ys: sy.map(r2), faces }
  } finally {
    input.dispose()
  }
}
