/**
 * r46 — joins several footage clips (sent by a correspondent for one video
 * news) into one video file, in the browser, with a transition between each
 * pair.
 *
 * Same machinery as render.ts: every clip is decoded with WebCodecs through
 * Mediabunny, frames are streamed (never all held in memory), painted onto
 * one canvas and encoded as H.264 (VP9/WebM only when the browser has no
 * H.264 encoder); the sound of every clip is resampled to 48 kHz stereo,
 * cross-faded under the transitions and encoded as AAC (the bundled WASM
 * encoder when the browser can't), all muxed into a fast-start MP4.
 *
 * Clips of another shape than the first are fitted inside the frame with a
 * blurred, darkened copy of themselves behind (no black bars).
 */
import {
  ALL_FORMATS,
  AudioBufferSink,
  AudioBufferSource,
  BlobSource,
  BufferTarget,
  CanvasSink,
  CanvasSource,
  Input,
  Mp4OutputFormat,
  Output,
  Quality,
  WebMOutputFormat,
  canEncodeAudio,
  canEncodeVideo,
  type InputVideoTrack,
  type WrappedAudioBuffer,
  type WrappedCanvas,
} from 'mediabunny'

/** Same frame rate as the studio export (render.ts). */
const FPS = 30

export class JoinCancelled extends Error {
  constructor() {
    super('cancelled')
  }
}

/** Bitrate as render.ts's "high" choice, kept under `maxBytes` when given. */
function pickBitrate(W: number, H: number, duration: number, maxBytes: number | null) {
  const ideal = Math.round(Math.min(10_000_000, Math.max(1_500_000, W * H * FPS * 0.11)))
  if (!maxBytes || !(duration > 0)) return ideal
  const budget = (maxBytes * 0.88 * 8) / duration - 160_000
  return Math.round(Math.max(600_000, Math.min(ideal, Number.isFinite(budget) ? budget : ideal)))
}

export type TransitionKind = 'cut' | 'crossfade' | 'dipBlack' | 'dipWhite' | 'slideLeft' | 'slideUp' | 'wipe' | 'zoom' | 'blur'

export const TRANSITIONS: { id: TransitionKind; label: string }[] = [
  { id: 'cut', label: 'কাট' },
  { id: 'crossfade', label: 'ক্রসফেড' },
  { id: 'dipBlack', label: 'কালোয় মিলিয়ে' },
  { id: 'dipWhite', label: 'সাদায় মিলিয়ে' },
  { id: 'slideLeft', label: 'স্লাইড বাঁয়ে' },
  { id: 'slideUp', label: 'স্লাইড ওপরে' },
  { id: 'wipe', label: 'ওয়াইপ' },
  { id: 'zoom', label: 'জুম' },
  { id: 'blur', label: 'ব্লার' },
]

export const TRANSITION_MIN = 0.3
export const TRANSITION_MAX = 1.5
export const DEFAULT_TRANSITION: TransitionKind = 'crossfade'
export const DEFAULT_TRANSITION_DUR = 0.6

const SAMPLE_RATE = 48000
const MAX_LONG_SIDE = 1920

export interface JoinClip {
  blob: Blob
  /** Seconds from the clip's own start (default 0). */
  trimStart?: number
  /** Seconds from the clip's own start (default: the clip's end). */
  trimEnd?: number | null
}

export interface JoinProgress {
  stage: 'prepare' | 'render' | 'finish'
  /** 0..1 of the render stage. */
  ratio: number
  done: number
  total: number
  /** Estimated seconds left (render stage). */
  eta: number | null
}

export interface JoinJob {
  clips: JoinClip[]
  transition: TransitionKind
  /** Seconds, clamped to 0.3–1.5 (and to half the shorter neighbouring clip). */
  transitionDur: number
  /** Keeps the bitrate low enough for the server's upload limit. */
  maxBytes?: number | null
  onProgress?: (p: JoinProgress) => void
  signal?: AbortSignal
}

export interface JoinResult {
  blob: Blob
  mime: string
  ext: 'mp4' | 'webm'
  duration: number
  width: number
  height: number
  /** A frame from early in the video (for a thumbnail). */
  poster: Blob | null
}

// ---------------------------------------------------------------------------
// Plan (pure — also used by the panel to show the joined length)
// ---------------------------------------------------------------------------

export interface JoinPlan {
  /** Output start of every clip. */
  starts: number[]
  /** Used length of every clip. */
  lengths: number[]
  /** Overlap between clip i and i+1 (length n-1). */
  overlaps: number[]
  total: number
}

/** Where every clip sits in the joined video. Each transition overlaps the two clips by its length. */
export function planJoin(lengths: number[], kind: TransitionKind, dur: number): JoinPlan {
  const d0 = kind === 'cut' ? 0 : Math.min(TRANSITION_MAX, Math.max(TRANSITION_MIN, dur))
  const overlaps: number[] = []
  for (let i = 0; i < lengths.length - 1; i++) overlaps.push(Math.max(0, Math.min(d0, lengths[i] / 2, lengths[i + 1] / 2)))
  const starts: number[] = []
  let t = 0
  for (let i = 0; i < lengths.length; i++) {
    starts.push(t)
    t += lengths[i] - (overlaps[i] ?? 0)
  }
  const total = lengths.length ? starts[lengths.length - 1] + lengths[lengths.length - 1] : 0
  return { starts, lengths, overlaps, total }
}

/** Output size: the first clip's shape, the sharpest clip's detail, at most 1920 on the long side, even numbers. */
export function joinSize(sizes: { width: number; height: number }[]): { W: number; H: number } {
  const first = sizes.find((s) => s.width > 0 && s.height > 0) ?? { width: 1080, height: 1920 }
  const aspect = first.width / first.height
  const long = Math.min(MAX_LONG_SIDE, Math.max(...sizes.map((s) => Math.max(s.width, s.height)), 2))
  const even = (n: number) => Math.max(2, Math.round(n / 2) * 2)
  return aspect >= 1 ? { W: even(long), H: even(long / aspect) } : { W: even(long * aspect), H: even(long) }
}

// ---------------------------------------------------------------------------
// Drawing (also used by the live preview)
// ---------------------------------------------------------------------------

let smallCanvas: HTMLCanvasElement | null = null

/**
 * Paints `src` (sw×sh) to fill W×H: fitted inside, and when its shape is
 * different a blurred, darkened, zoomed copy of itself fills the rest.
 */
export function drawFitted(ctx: CanvasRenderingContext2D, src: CanvasImageSource, sw: number, sh: number, W: number, H: number) {
  if (!(sw > 0 && sh > 0)) {
    ctx.fillStyle = '#000'
    ctx.fillRect(0, 0, W, H)
    return
  }
  const k = Math.min(W / sw, H / sh)
  const dw = sw * k
  const dh = sh * k
  if (Math.abs(dw - W) > 2 || Math.abs(dh - H) > 2) {
    // blurred fill: cover-scaled onto a tiny canvas, blurred there (cheap), stretched back up
    const sk = 1 / 10
    const bw = Math.max(8, Math.round(W * sk))
    const bh = Math.max(8, Math.round(H * sk))
    if (!smallCanvas) smallCanvas = document.createElement('canvas')
    if (smallCanvas.width !== bw || smallCanvas.height !== bh) {
      smallCanvas.width = bw
      smallCanvas.height = bh
    }
    const s = smallCanvas.getContext('2d')!
    const ck = Math.max(bw / sw, bh / sh) * 1.08
    s.filter = 'blur(2px)'
    s.drawImage(src, (bw - sw * ck) / 2, (bh - sh * ck) / 2, sw * ck, sh * ck)
    s.filter = 'none'
    ctx.imageSmoothingEnabled = true
    ctx.imageSmoothingQuality = 'high'
    ctx.drawImage(smallCanvas, 0, 0, W, H)
    ctx.fillStyle = 'rgba(0,0,0,0.35)'
    ctx.fillRect(0, 0, W, H)
  }
  ctx.drawImage(src, (W - dw) / 2, (H - dh) / 2, dw, dh)
}

const ease = (p: number) => (p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2)

let filterOk: boolean | null = null
function canFilter(ctx: CanvasRenderingContext2D) {
  if (filterOk === null) filterOk = typeof ctx.filter === 'string'
  return filterOk
}

/**
 * One frame of the transition from `a` to `b` (both full W×H frames), at
 * p = 0 (all a) … 1 (all b).
 */
export function drawTransition(ctx: CanvasRenderingContext2D, kind: TransitionKind, a: CanvasImageSource, b: CanvasImageSource, p: number, W: number, H: number) {
  p = Math.max(0, Math.min(1, p))
  const e = ease(p)
  ctx.save()
  ctx.globalAlpha = 1
  switch (kind) {
    case 'cut':
      ctx.drawImage(p < 0.5 ? a : b, 0, 0, W, H)
      break
    case 'crossfade':
      ctx.drawImage(a, 0, 0, W, H)
      ctx.globalAlpha = p
      ctx.drawImage(b, 0, 0, W, H)
      break
    case 'dipBlack':
    case 'dipWhite': {
      const first = p < 0.5
      ctx.drawImage(first ? a : b, 0, 0, W, H)
      ctx.globalAlpha = first ? Math.min(1, p * 2) : Math.min(1, (1 - p) * 2)
      ctx.fillStyle = kind === 'dipBlack' ? '#000' : '#fff'
      ctx.fillRect(0, 0, W, H)
      break
    }
    case 'slideLeft':
      ctx.fillStyle = '#000'
      ctx.fillRect(0, 0, W, H)
      ctx.drawImage(a, -e * W, 0, W, H)
      ctx.drawImage(b, (1 - e) * W, 0, W, H)
      break
    case 'slideUp':
      ctx.fillStyle = '#000'
      ctx.fillRect(0, 0, W, H)
      ctx.drawImage(a, 0, -e * H, W, H)
      ctx.drawImage(b, 0, (1 - e) * H, W, H)
      break
    case 'wipe': {
      ctx.drawImage(a, 0, 0, W, H)
      const x = e * W
      ctx.beginPath()
      ctx.rect(0, 0, x, H)
      ctx.clip()
      ctx.drawImage(b, 0, 0, W, H)
      ctx.restore()
      ctx.save()
      // a soft bright edge on the wipe line
      if (p > 0 && p < 1) {
        const g = ctx.createLinearGradient(x - W * 0.02, 0, x + W * 0.005, 0)
        g.addColorStop(0, 'rgba(255,255,255,0)')
        g.addColorStop(1, 'rgba(255,255,255,0.55)')
        ctx.fillStyle = g
        ctx.fillRect(x - W * 0.02, 0, W * 0.025, H)
      }
      break
    }
    case 'zoom': {
      const za = 1 + 0.35 * e
      ctx.drawImage(a, (W - W * za) / 2, (H - H * za) / 2, W * za, H * za)
      const zb = 1.25 - 0.25 * e
      ctx.globalAlpha = Math.min(1, p * 1.4)
      ctx.drawImage(b, (W - W * zb) / 2, (H - H * zb) / 2, W * zb, H * zb)
      break
    }
    case 'blur': {
      const r = Math.round(Math.sin(p * Math.PI) * Math.max(W, H) * 0.018)
      if (canFilter(ctx) && r > 0) ctx.filter = `blur(${r}px)`
      ctx.drawImage(a, 0, 0, W, H)
      ctx.globalAlpha = e
      ctx.drawImage(b, 0, 0, W, H)
      ctx.filter = 'none'
      break
    }
  }
  ctx.restore()
}

// ---------------------------------------------------------------------------
// Audio: each clip read as a 48 kHz stereo stream, in order
// ---------------------------------------------------------------------------

class ClipAudioStream {
  private it: AsyncGenerator<WrappedAudioBuffer, void, unknown> | null
  private cur: WrappedAudioBuffer | null = null
  private ended = false
  /** Source time of the next output sample. */
  private pos: number

  constructor(sink: AudioBufferSink | null, from: number, to: number) {
    this.it = sink ? sink.buffers(from, to) : null
    this.pos = from
  }

  private async advanceTo(t: number) {
    while (!this.ended && (!this.cur || this.cur.timestamp + this.cur.duration <= t)) {
      if (!this.it) {
        this.ended = true
        break
      }
      const r = await this.it.next()
      if (r.done) {
        this.ended = true
        this.cur = null
      } else this.cur = r.value
    }
  }

  /** Adds `n` output samples × gain(k) into L/R at `offset`. */
  async mixInto(L: Float32Array, R: Float32Array, offset: number, n: number, gain: (k: number) => number) {
    const step = 1 / SAMPLE_RATE
    for (let k = 0; k < n; ) {
      const t = this.pos + k * step
      await this.advanceTo(t)
      const c = this.cur
      if (!c || this.ended) break
      const buf = c.buffer
      const sr = buf.sampleRate
      const d0 = buf.getChannelData(0)
      const d1 = buf.numberOfChannels > 1 ? buf.getChannelData(1) : d0
      const end = c.timestamp + c.duration
      // fill every output sample that falls inside this buffer
      for (; k < n; k++) {
        const tk = this.pos + k * step
        if (tk >= end) break
        const x = (tk - c.timestamp) * sr
        if (x < 0) continue // a gap before this buffer: silence
        const i = Math.floor(x)
        const f = x - i
        const j = Math.min(i + 1, d0.length - 1)
        const ii = Math.min(i, d0.length - 1)
        const g = gain(k)
        L[offset + k] += (d0[ii] + (d0[j] - d0[ii]) * f) * g
        R[offset + k] += (d1[ii] + (d1[j] - d1[ii]) * f) * g
      }
    }
    this.pos += n * step
  }

  async close() {
    await this.it?.return(undefined)
    this.it = null
  }
}

// ---------------------------------------------------------------------------
// The join
// ---------------------------------------------------------------------------

interface OpenClip {
  input: Input
  video: InputVideoTrack | null
  sink: CanvasSink | null
  audioSink: AudioBufferSink | null
  /** Source time of the clip's first moment. */
  base: number
  /** Full length of the clip. */
  length: number
  width: number
  height: number
}

async function openClip(blob: Blob, index: number): Promise<OpenClip> {
  const input = new Input({ source: new BlobSource(blob), formats: ALL_FORMATS })
  try {
    const video = await input.getPrimaryVideoTrack()
    if (!video) throw new Error(`ফুটেজ ${index + 1}-এ কোনো ভিডিও পাওয়া যায়নি।`)
    if (!(await video.canDecode())) {
      throw new Error(`এই ব্রাউজার ফুটেজ ${index + 1}-এর কোডেক (${video.codec ?? 'অজানা'}) পড়তে পারছে না — Chrome/Edge-এর নতুন সংস্করণে চেষ্টা করুন।`)
    }
    const at = await input.getPrimaryAudioTrack()
    const audioSink = at && (await at.canDecode()) ? new AudioBufferSink(at) : null
    const base = Math.max(0, await input.getFirstTimestamp().catch(() => 0))
    const end = await input.computeDuration()
    return { input, video, sink: null, audioSink, base, length: Math.max(0, end - base), width: video.displayWidth, height: video.displayHeight }
  } catch (err) {
    input.dispose()
    throw err
  }
}

/** Joins the clips, in the given order, into one video file. */
export async function joinClips(job: JoinJob): Promise<JoinResult> {
  const { signal } = job
  const check = () => {
    if (signal?.aborted) throw new JoinCancelled()
  }
  if (job.clips.length < 1) throw new Error('জোড়া দেওয়ার মতো কোনো ফুটেজ নেই।')
  job.onProgress?.({ stage: 'prepare', ratio: 0, done: 0, total: 0, eta: null })

  const open: OpenClip[] = []
  let outputRef: Output | null = null
  try {
    for (let i = 0; i < job.clips.length; i++) {
      open.push(await openClip(job.clips[i].blob, i))
      check()
    }
    // used part of every clip
    const ranges = open.map((c, i) => {
      const s = Math.max(0, Math.min(c.length, job.clips[i].trimStart ?? 0))
      const eRaw = job.clips[i].trimEnd
      const e = Math.max(s, Math.min(c.length, eRaw == null ? c.length : eRaw))
      return { from: c.base + s, len: e - s }
    })
    if (ranges.some((r) => r.len < 0.2)) throw new Error('একটি ফুটেজ খুব ছোট (ট্রিমের পর ০.২ সেকেন্ডেরও কম) — ট্রিম ঠিক করুন।')
    const plan = planJoin(
      ranges.map((r) => r.len),
      job.transition,
      job.transitionDur,
    )
    const total = plan.total
    const { W, H } = joinSize(open)

    // decode every clip at the size it is drawn at (fitted inside W×H)
    for (const c of open) {
      const k = Math.min(1, Math.min(W / c.width, H / c.height))
      c.sink = new CanvasSink(c.video!, { width: Math.max(2, Math.round(c.width * k)), height: Math.max(2, Math.round(c.height * k)), fit: 'fill', poolSize: 3 })
    }

    // --- codecs ---
    let container: 'mp4' | 'webm' = 'mp4'
    let vcodec: 'avc' | 'vp9' = 'avc'
    const bitrate = pickBitrate(W, H, total, job.maxBytes ?? null)
    if (!(await canEncodeVideo('avc', { width: W, height: H, bitrate }))) {
      if (await canEncodeVideo('vp9', { width: W, height: H, bitrate })) {
        container = 'webm'
        vcodec = 'vp9'
      } else {
        throw new Error('এই ব্রাউজার ভিডিও এনকোড করতে পারছে না — Chrome বা Edge-এর নতুন সংস্করণে চেষ্টা করুন।')
      }
    }
    let acodec: 'aac' | 'opus' = container === 'mp4' ? 'aac' : 'opus'
    if (acodec === 'aac' && !(await canEncodeAudio('aac', { numberOfChannels: 2, sampleRate: SAMPLE_RATE, bitrate: 160_000 }))) {
      try {
        const { registerAacEncoder } = await import('@mediabunny/aac-encoder')
        registerAacEncoder()
      } catch {
        acodec = 'opus'
      }
    }
    check()

    // --- output ---
    const canvas = document.createElement('canvas')
    canvas.width = W
    canvas.height = H
    const ctx = canvas.getContext('2d', { alpha: false })!
    // one full frame per clip during a transition
    const layers = [0, 1].map(() => {
      const c = document.createElement('canvas')
      c.width = W
      c.height = H
      return { c, ctx: c.getContext('2d', { alpha: false })! }
    })
    const target = new BufferTarget()
    const output = new Output({
      format: container === 'mp4' ? new Mp4OutputFormat({ fastStart: 'in-memory' }) : new WebMOutputFormat(),
      target,
    })
    const vsrc = new CanvasSource(canvas, { codec: vcodec, quality: new Quality({ bitrate }), keyFrameInterval: 2 })
    output.addVideoTrack(vsrc, { frameRate: FPS })
    const asrc = new AudioBufferSource({ codec: acodec, quality: new Quality({ bitrate: 160_000 }) })
    output.addAudioTrack(asrc)
    await output.start()
    outputRef = output

    const n = open.length
    const frameCount = Math.max(1, Math.round(total * FPS))
    // output frames of every clip: [fa, fb)
    const fr = plan.starts.map((s, i) => {
      const fa = Math.min(frameCount, Math.ceil(s * FPS - 1e-6))
      const fb = i === n - 1 ? frameCount : Math.min(frameCount, Math.ceil((s + plan.lengths[i]) * FPS - 1e-6))
      return { fa, fb: Math.max(fa, fb) }
    })

    // --- audio, window by window ---
    const AUDIO_WIN = 2
    const streams: (ClipAudioStream | null)[] = open.map(() => null)
    let audioDone = 0
    const MICRO = 0.008 // tiny fades on a hard cut, so it doesn't click
    const pumpAudio = async (until: number) => {
      while (audioDone < Math.min(until, total) - 1e-6) {
        const t0 = audioDone
        const t1 = Math.min(total, t0 + AUDIO_WIN)
        const s0 = Math.round(t0 * SAMPLE_RATE)
        const len = Math.max(1, Math.round(t1 * SAMPLE_RATE) - s0)
        const L = new Float32Array(len)
        const R = new Float32Array(len)
        for (let i = 0; i < n; i++) {
          const cs = plan.starts[i]
          const ce = cs + plan.lengths[i]
          if (ce <= t0 || cs >= t1) {
            if (ce <= t0 && streams[i]) {
              await streams[i]!.close()
              streams[i] = null
            }
            continue
          }
          const a = Math.max(t0, cs)
          const b = Math.min(t1, ce)
          const ka = Math.round(a * SAMPLE_RATE) - s0
          const kb = Math.min(len, Math.round(b * SAMPLE_RATE) - s0)
          if (kb <= ka) continue
          if (!streams[i]) {
            // starts exactly where this window meets the clip
            streams[i] = new ClipAudioStream(open[i].audioSink, ranges[i].from + (a - cs), ranges[i].from + ranges[i].len)
          }
          const fin = i > 0 ? plan.overlaps[i - 1] || MICRO : 0
          const fout = i < n - 1 ? plan.overlaps[i] || MICRO : 0
          await streams[i]!.mixInto(L, R, ka, kb - ka, (k) => {
            const t = (s0 + ka + k) / SAMPLE_RATE - cs
            let g = 1
            if (fin > 0 && t < fin) g *= Math.sin((Math.max(0, t) / fin) * (Math.PI / 2))
            const left = plan.lengths[i] - t
            if (fout > 0 && left < fout) g *= Math.sin((Math.max(0, left) / fout) * (Math.PI / 2))
            return g
          })
        }
        for (let k = 0; k < len; k++) {
          L[k] = Math.max(-1, Math.min(1, L[k]))
          R[k] = Math.max(-1, Math.min(1, R[k]))
        }
        const buf = new AudioBuffer({ numberOfChannels: 2, length: len, sampleRate: SAMPLE_RATE })
        buf.copyToChannel(L, 0)
        buf.copyToChannel(R, 1)
        await asrc.add(buf)
        audioDone = t1
      }
    }

    // --- video, frame by frame; at most two clips decode at once ---
    type Live = { it: AsyncGenerator<WrappedCanvas | null, void, unknown>; last: WrappedCanvas | null }
    const live: (Live | null)[] = open.map(() => null)
    const frameOf = async (i: number): Promise<WrappedCanvas | null> => {
      if (!live[i]) {
        const { fa, fb } = fr[i]
        const from = ranges[i].from
        const lastT = from + Math.max(0, ranges[i].len - 0.5 / FPS)
        const cs = plan.starts[i]
        function* times() {
          for (let f = fa; f < fb; f++) yield Math.min(lastT, Math.max(from, from + (f / FPS - cs)))
        }
        live[i] = { it: open[i].sink!.canvasesAtTimestamps(times()), last: null }
      }
      const l = live[i]!
      const r = await l.it.next()
      if (!r.done && r.value) l.last = r.value
      return l.last
    }
    const paint = (g: CanvasRenderingContext2D, pic: WrappedCanvas | null) => {
      if (pic) drawFitted(g, pic.canvas as CanvasImageSource, pic.canvas.width, pic.canvas.height, W, H)
      else {
        g.fillStyle = '#000'
        g.fillRect(0, 0, W, H)
      }
    }

    let poster: Blob | null = null
    let posterPending: Promise<void> | null = null
    const posterAt = Math.min(1.5, total / 3)
    const started = performance.now()
    try {
      for (let f = 0; f < frameCount; f++) {
        check()
        const T = f / FPS
        const act: number[] = []
        for (let i = 0; i < n; i++) if (f >= fr[i].fa && f < fr[i].fb) act.push(i)
        // clips that are over: free their decoders
        for (let i = 0; i < n; i++) {
          if (live[i] && f >= fr[i].fb) {
            await live[i]!.it.return(undefined)
            live[i] = null
          }
        }
        if (act.length >= 2) {
          const [ia, ib] = act
          const pa = await frameOf(ia)
          paint(layers[0].ctx, pa)
          const pb = await frameOf(ib)
          paint(layers[1].ctx, pb)
          const d = plan.overlaps[ia] || 1 / FPS
          const p = (T - plan.starts[ib]) / d
          drawTransition(ctx, job.transition, layers[0].c, layers[1].c, p, W, H)
        } else if (act.length === 1) {
          paint(ctx, await frameOf(act[0]))
        } else {
          ctx.fillStyle = '#000'
          ctx.fillRect(0, 0, W, H)
        }
        if (!poster && !posterPending && T >= posterAt) {
          posterPending = new Promise<void>((resolve) =>
            canvas.toBlob(
              (b) => {
                poster = b
                resolve()
              },
              'image/jpeg',
              0.9,
            ),
          )
        }
        await vsrc.add(T, 1 / FPS)
        if (T + 1 / FPS > audioDone - 0.5) await pumpAudio(T + AUDIO_WIN)
        if (f % 10 === 0) {
          const ratio = (f + 1) / frameCount
          const elapsed = (performance.now() - started) / 1000
          job.onProgress?.({ stage: 'render', ratio, done: T, total, eta: ratio > 0.03 ? (elapsed / ratio) * (1 - ratio) : null })
        }
      }
    } finally {
      for (let i = 0; i < n; i++) {
        await live[i]?.it.return(undefined)
        live[i] = null
      }
    }
    await pumpAudio(total)
    for (const s of streams) await s?.close()
    if (posterPending) await posterPending
    check()

    job.onProgress?.({ stage: 'finish', ratio: 1, done: total, total, eta: 0 })
    await output.finalize()
    const mime = container === 'mp4' ? 'video/mp4' : 'video/webm'
    return { blob: new Blob([target.buffer!], { type: mime }), mime, ext: container, duration: total, width: W, height: H, poster }
  } catch (err) {
    if (outputRef && outputRef.state !== 'finalized' && outputRef.state !== 'canceled') await outputRef.cancel().catch(() => undefined)
    throw err
  } finally {
    for (const c of open) c.input.dispose()
  }
}
