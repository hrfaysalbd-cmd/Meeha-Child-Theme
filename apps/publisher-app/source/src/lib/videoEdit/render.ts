/**
 * Round 21 — exports the edit as a real video file, in the browser.
 *
 * Every clip (main video, intro/outro files, ad clips) is decoded with
 * WebCodecs through Mediabunny; each output frame is painted by
 * drawOutputFrame() and encoded as H.264 (VP9/WebM only if the browser has
 * no H.264 encoder), the soundtrack is mixed window by window (audio.ts)
 * and encoded as AAC (a bundled WASM encoder is loaded when the browser
 * can't encode AAC itself), and everything is muxed into a fast-start MP4.
 *
 * It runs faster than real time on a normal PC, keeps working in a
 * background tab, and never touches the server.
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
  type WrappedCanvas,
} from 'mediabunny'
import type { EditConfig } from './config'
import { drawOutputFrame, sfxCues, type DrawEnv, type Picture } from './draw'
import { denoisedReader, mixWindow, newMixState, SAMPLE_RATE, type AudioReader } from './audio'
import { musicBuffer } from './music'
import { LoudnessMeter, normalizeGain } from './loudness'
import { clipsUsed, pipActive, pipClipTime, segSource, type ClipKey, type Timeline } from './timeline'

export const FPS = 30

export interface RenderProgress {
  stage: 'prepare' | 'loudness' | 'render' | 'finish'
  /** 0..1 of the render stage. */
  ratio: number
  /** Output seconds done. */
  done: number
  total: number
  /** Estimated seconds left (render stage). */
  eta: number | null
}

export interface RenderResult {
  blob: Blob
  mime: string
  ext: 'mp4' | 'webm'
  duration: number
  width: number
  height: number
  /** A frame from early in the news (for the thumbnail). */
  poster: Blob | null
}

export class RenderCancelled extends Error {
  constructor() {
    super('cancelled')
  }
}

export interface Capability {
  ok: boolean
  reason?: string
}

/** Whether this browser can export at all (WebCodecs + OfflineAudioContext). */
export function exportCapability(): Capability {
  if (typeof VideoEncoder === 'undefined' || typeof VideoDecoder === 'undefined') {
    return {
      ok: false,
      reason: 'এই ব্রাউজারে ভিডিও তৈরি করা যাবে না — কম্পিউটার বা অ্যান্ড্রয়েডে Google Chrome বা Microsoft Edge-এর নতুন সংস্করণ ব্যবহার করুন।',
    }
  }
  if (typeof OfflineAudioContext === 'undefined') return { ok: false, reason: 'এই ব্রাউজারে অডিও প্রসেস করা যাচ্ছে না।' }
  return { ok: true }
}

/** Target video bitrate: ~0.11 bit/pixel/frame, never so high the file busts `maxBytes`. */
export function pickBitrate(W: number, H: number, duration: number, maxBytes: number | null) {
  // Encoders take whole bits per second only ("bitrate must be a positive integer").
  const ideal = Math.round(Math.min(10_000_000, Math.max(1_500_000, W * H * FPS * 0.11)))
  if (!maxBytes || !(duration > 0)) return ideal
  const budget = (maxBytes * 0.88 * 8) / duration - 160_000
  return Math.round(Math.max(600_000, Math.min(ideal, Number.isFinite(budget) ? budget : ideal)))
}

interface ClipIO {
  input: Input
  video: InputVideoTrack | null
  sink: CanvasSink | null
  audio: AudioReader | null
}

async function openClip(blob: Blob, maxW: number, maxH: number, cover = false): Promise<ClipIO> {
  const input = new Input({ source: new BlobSource(blob), formats: ALL_FORMATS })
  const video = await input.getPrimaryVideoTrack()
  const audioTrack = await input.getPrimaryAudioTrack()
  let sink: CanvasSink | null = null
  if (video) {
    if (!(await video.canDecode())) {
      throw new Error(`এই ব্রাউজার ভিডিওটির কোডেক (${video.codec ?? 'অজানা'}) পড়তে পারছে না — Chrome/Edge-এর নতুন সংস্করণে চেষ্টা করুন।`)
    }
    // Decode straight to the size it will be drawn at (less memory, faster).
    const k = Math.min(1, cover ? Math.max(maxW / video.displayWidth, maxH / video.displayHeight) : Math.min(maxW / video.displayWidth, maxH / video.displayHeight))
    const width = Math.max(2, Math.round(video.displayWidth * Math.max(k, Math.min(1, (maxW * 0.5) / video.displayWidth))))
    sink = new CanvasSink(video, { width, poolSize: 3 })
  }
  let audio: AudioReader | null = null
  if (audioTrack && (await audioTrack.canDecode())) {
    const as = new AudioBufferSink(audioTrack)
    audio = { buffers: (from, to) => as.buffers(from, to) }
  }
  return { input, video, sink, audio }
}

function audioReaders(io: Partial<Record<ClipKey, { audio: AudioReader | null }>>, used: Iterable<ClipKey>, cfg: EditConfig) {
  const readers: Partial<Record<ClipKey, AudioReader | null>> = {}
  for (const k of used) {
    const r = io[k]?.audio ?? null
    readers[k] = r && k === 'main' && cfg.audio.denoise !== 'off' ? denoisedReader(r, cfg.audio.denoise) : r
  }
  return readers
}

export interface RenderJob {
  cfg: EditConfig
  tl: Timeline
  env: Omit<DrawEnv, 'scratch'> & { scratch: DrawEnv['scratch'] }
  /** Files for every clip the timeline uses. */
  clips: Partial<Record<ClipKey, Blob>>
  maxBytes: number | null
  posterAt: number
  onProgress?: (p: RenderProgress) => void
  signal?: AbortSignal
}

export async function renderEdit(job: RenderJob): Promise<RenderResult> {
  const { cfg, tl, env, signal } = job
  const { W, H } = env.L
  const total = tl.duration
  const check = () => {
    if (signal?.aborted) throw new RenderCancelled()
  }
  job.onProgress?.({ stage: 'prepare', ratio: 0, done: 0, total, eta: null })

  // --- codecs ---
  let container: 'mp4' | 'webm' = 'mp4'
  let vcodec: 'avc' | 'vp9' = 'avc'
  const bitrate = pickBitrate(W, H, total, job.maxBytes)
  if (!(await canEncodeVideo('avc', { width: W, height: H, bitrate }))) {
    if (await canEncodeVideo('vp9', { width: W, height: H, bitrate })) {
      container = 'webm'
      vcodec = 'vp9'
    } else {
      throw new Error('এই ব্রাউজার ভিডিও এনকোড করতে পারছে না — Chrome বা Edge-এর নতুন সংস্করণে চেষ্টা করুন।')
    }
  }
  let acodec: 'aac' | 'opus' = container === 'mp4' ? 'aac' : 'opus'
  if (acodec === 'aac' && !(await canEncodeAudio('aac', { numberOfChannels: 2, sampleRate: 48000, bitrate: 160_000 }))) {
    try {
      const { registerAacEncoder } = await import('@mediabunny/aac-encoder')
      registerAacEncoder()
    } catch {
      acodec = 'opus'
    }
  }
  check()

  // --- inputs ---
  const io: Partial<Record<ClipKey, ClipIO>> = {}
  let outputRef: Output | null = null
  const used = clipsUsed(tl, cfg)
  try {
    for (const key of used) {
      const blob = job.clips[key]
      if (!blob) throw new Error(`ভিডিও ফাইল পাওয়া যায়নি (${key})।`)
      if (key === 'main') {
        // decode at the size it is shown: turned sideways and zoomed in if so
        const v = env.L.video
        const turned = cfg.look.rotate === 90 || cfg.look.rotate === 270
        const z = cfg.look.zoom
        io[key] = await openClip(blob, Math.ceil((turned ? v.h : v.w) * z), Math.ceil((turned ? v.w : v.h) * z), cfg.fit === 'cover')
      } else {
        io[key] = await openClip(blob, W, H, true)
      }
      check()
    }

    // --- output ---
    const canvas = document.createElement('canvas')
    canvas.width = W
    canvas.height = H
    const ctx = canvas.getContext('2d', { alpha: false })!
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

    const cues = sfxCues(tl, cfg)
    const music = cfg.audio.music !== 'none' ? await musicBuffer(cfg.audio.music) : null
    let gain = 1
    if (cfg.audio.loudness !== 'off') {
      // first pass: measure the finished mix, then play it again at the target
      const meter = new LoudnessMeter()
      const probe = newMixState(music)
      const pre = audioReaders(io, used, cfg)
      for (let a = 0; a < total - 1e-6; a += 2) {
        check()
        const buf = await mixWindow(tl, cfg, pre, cues, a, Math.min(total, a + 2), probe)
        meter.feed(buf.getChannelData(0), buf.getChannelData(1))
        job.onProgress?.({ stage: 'loudness', ratio: Math.min(1, (a + 2) / total), done: a, total, eta: null })
      }
      gain = normalizeGain(meter.integrated(), Number(cfg.audio.loudness))
    }
    const readers = audioReaders(io, used, cfg)
    const mix = newMixState(music, gain, cfg.audio.loudness !== 'off')

    const frameCount = Math.max(1, Math.round(total * FPS))
    const AUDIO_WIN = 2
    let audioDone = 0
    let poster: Blob | null = null
    let posterPending: Promise<void> | null = null
    const started = performance.now()

    const pumpAudio = async (until: number) => {
      while (audioDone < Math.min(until, total) - 1e-6) {
        const t1 = Math.min(total, audioDone + AUDIO_WIN)
        const buf = await mixWindow(tl, cfg, readers, cues, audioDone, t1, mix)
        await asrc.add(buf)
        audioDone = t1
      }
    }

    // Output frame i belongs to the segment playing at i / FPS.
    const frameSeg: number[] = []
    for (let i = 0, k = 0; i < frameCount; i++) {
      const T = i / FPS
      while (k < tl.segments.length - 1 && T >= tl.segments[k].t0 + tl.segments[k].dur - 1e-9) k++
      frameSeg.push(k)
    }

    let i = 0
    while (i < frameCount) {
      const seg = tl.segments[frameSeg[i]]
      let j = i
      while (j < frameCount && frameSeg[j] === frameSeg[i]) j++
      const clip = seg.clip ? io[seg.clip] : undefined
      const times: number[] = []
      // a held frame decodes its one picture once
      if (seg.rate === 0) times.push(seg.clipStart)
      else for (let f = i; f < j; f++) times.push(Math.max(0, segSource(seg, f / FPS - seg.t0)))
      const pics = clip?.sink ? clip.sink.canvasesAtTimestamps(times) : null
      let last: Picture | null = null
      // the second video, for the frames where it shows
      const pipOn: boolean[] = []
      const pipTimes: number[] = []
      if (seg.kind === 'main' && io.pip?.sink) {
        for (let f = i; f < j; f++) {
          const on = pipActive(cfg, segSource(seg, f / FPS - seg.t0))
          pipOn.push(on)
          if (on) pipTimes.push(pipClipTime(tl, cfg, f / FPS))
        }
      }
      const pips = pipTimes.length ? io.pip!.sink!.canvasesAtTimestamps(pipTimes) : null
      let lastPip: Picture | null = null
      try {
        for (let f = i; f < j; f++) {
          check()
          const T = f / FPS
          let pic: Picture | null = null
          if (pics && (seg.rate !== 0 || !last)) {
            const r = await pics.next()
            const wc: WrappedCanvas | null = r.done ? null : r.value
            pic = wc ? { src: wc.canvas as CanvasImageSource, w: wc.canvas.width, h: wc.canvas.height } : last
            last = pic
          } else if (pics) pic = last
          if (pips && pipOn[f - i]) {
            const r = await pips.next()
            const wc: WrappedCanvas | null = r.done ? null : r.value
            if (wc) lastPip = { src: wc.canvas as CanvasImageSource, w: wc.canvas.width, h: wc.canvas.height }
          }
          drawOutputFrame(ctx, env, seg, T, pic, lastPip)
          if (!poster && !posterPending && T >= job.posterAt) {
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
        await pics?.return(undefined)
        await pips?.return(undefined)
      }
      i = j
    }
    await pumpAudio(total)
    if (posterPending) await posterPending
    check()

    job.onProgress?.({ stage: 'finish', ratio: 1, done: total, total, eta: 0 })
    await output.finalize()
    const mime = container === 'mp4' ? 'video/mp4' : 'video/webm'
    return {
      blob: new Blob([target.buffer!], { type: mime }),
      mime,
      ext: container,
      duration: total,
      width: W,
      height: H,
      poster,
    }
  } catch (err) {
    if (outputRef && outputRef.state !== 'finalized' && outputRef.state !== 'canceled') await outputRef.cancel().catch(() => undefined)
    throw err
  } finally {
    for (const k of Object.keys(io) as ClipKey[]) io[k]?.input.dispose()
  }
}

/** Duration + display size of a video file, read with Mediabunny (no <video> needed). */
export async function probeClip(blob: Blob): Promise<{ duration: number; width: number; height: number; hasAudio: boolean }> {
  const input = new Input({ source: new BlobSource(blob), formats: ALL_FORMATS })
  try {
    const v = await input.getPrimaryVideoTrack()
    const a = await input.getPrimaryAudioTrack()
    const duration = await input.computeDuration()
    return { duration, width: v?.displayWidth ?? 0, height: v?.displayHeight ?? 0, hasAudio: !!a }
  } finally {
    input.dispose()
  }
}

/**
 * The finished soundtrack for output seconds [from, from + seconds), made
 * exactly as the export makes it (voice clean-up, noise reduction, music,
 * ducking), so the editor can play it before rendering the whole video.
 */
export async function renderAudioPreview(cfg: EditConfig, tl: Timeline, clips: Partial<Record<ClipKey, Blob>>, from: number, seconds: number, signal?: AbortSignal): Promise<AudioBuffer> {
  const t0 = Math.max(0, Math.min(from, tl.duration - 0.5))
  const t1 = Math.min(tl.duration, t0 + seconds)
  const used = new Set(tl.segments.filter((s) => s.clip && s.t0 < t1 && s.t0 + s.dur > t0).map((s) => s.clip as ClipKey))
  if (clipsUsed(tl, cfg).has('pip')) used.add('pip')
  const io: Partial<Record<ClipKey, { input: Input; audio: AudioReader | null }>> = {}
  try {
    for (const k of used) {
      const blob = clips[k]
      if (!blob) continue
      const input = new Input({ source: new BlobSource(blob), formats: ALL_FORMATS })
      const track = await input.getPrimaryAudioTrack()
      let audio: AudioReader | null = null
      if (track && (await track.canDecode())) {
        const sink = new AudioBufferSink(track)
        audio = { buffers: (a, b) => sink.buffers(a, b) }
      }
      io[k] = { input, audio }
    }
    const cues = sfxCues(tl, cfg)
    const music = cfg.audio.music !== 'none' ? await musicBuffer(cfg.audio.music) : null
    const frames = Math.max(1, Math.round((t1 - t0) * SAMPLE_RATE))
    const pass = async (mix: ReturnType<typeof newMixState>, meter: LoudnessMeter | null) => {
      const readers = audioReaders(io, used, cfg)
      const out = new AudioBuffer({ numberOfChannels: 2, length: frames, sampleRate: SAMPLE_RATE })
      let at = t0
      let pos = 0
      while (at < t1 - 1e-6) {
        if (signal?.aborted) throw new RenderCancelled()
        const b = Math.min(t1, at + 2)
        const buf = await mixWindow(tl, cfg, readers, cues, at, b, mix)
        meter?.feed(buf.getChannelData(0), buf.getChannelData(1))
        const n = Math.min(buf.length, frames - pos)
        for (let c = 0; c < 2; c++) out.copyToChannel(buf.getChannelData(c).subarray(0, n), c, pos)
        pos += n
        at = b
      }
      return out
    }
    if (cfg.audio.loudness === 'off') return pass(newMixState(music), null)
    // the export measures the whole video; this preview measures its own 15 s
    const meter = new LoudnessMeter()
    await pass(newMixState(music), meter)
    return pass(newMixState(music, normalizeGain(meter.integrated(), Number(cfg.audio.loudness)), true), null)
  } finally {
    for (const k of Object.keys(io) as ClipKey[]) io[k]?.input.dispose()
  }
}
