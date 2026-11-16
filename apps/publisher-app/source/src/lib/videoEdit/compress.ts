/**
 * Round 27 — "ছোট করে আপলোড": re-encodes a video in the browser so it fits
 * a size (the server's 200 MB, or smaller for a faster upload). The picture
 * is scaled down only as far as the bitrate needs (1080p → 720p → 480p),
 * so a talking-head news clip stays sharp. Uses the same WebCodecs encoder
 * as the editor's render (Chrome / Edge).
 */
import { ALL_FORMATS, BlobSource, BufferTarget, Conversion, Input, Mp4OutputFormat, Output, WebMOutputFormat, canEncodeAudio, canEncodeVideo } from 'mediabunny'

export class ShrinkCancelled extends Error {}

export interface ShrinkResult {
  blob: Blob
  mime: string
  ext: 'mp4' | 'webm'
  width: number
  height: number
}

export interface ShrinkPlan {
  duration: number
  width: number
  height: number
  /** Video bits per second that fit the target. */
  bitrate: number
  /** Short side after scaling. */
  short: number
  /** Expected size in bytes. */
  estimate: number
}

const AUDIO_BPS = 128_000

/** What a shrink to `targetBytes` would do (no encoding). */
export async function planShrink(file: Blob, targetBytes: number): Promise<ShrinkPlan | null> {
  const input = new Input({ source: new BlobSource(file), formats: ALL_FORMATS })
  try {
    const v = await input.getPrimaryVideoTrack()
    if (!v) return null
    const duration = await input.computeDuration()
    if (!(duration > 0)) return null
    const width = v.displayWidth
    const height = v.displayHeight
    const bitrate = Math.round(Math.max(350_000, Math.min(8_000_000, (targetBytes * 0.9 * 8) / duration - AUDIO_BPS)))
    const srcShort = Math.min(width, height)
    // bits per pixel per frame stays watchable (~0.05) by lowering the size
    const short = Math.min(srcShort, bitrate >= 3_000_000 ? 1080 : bitrate >= 1_300_000 ? 720 : bitrate >= 650_000 ? 540 : 480)
    return { duration, width, height, bitrate, short, estimate: Math.round(((bitrate + AUDIO_BPS) * duration) / 8) }
  } finally {
    input.dispose()
  }
}

/** Re-encodes `file` to about `targetBytes`. */
export async function shrinkVideo(file: Blob, targetBytes: number, opts: { onProgress?: (ratio: number) => void; signal?: AbortSignal } = {}): Promise<ShrinkResult> {
  const plan = await planShrink(file, targetBytes)
  if (!plan) throw new Error('ভিডিওটি পড়া যায়নি — ছোট করা সম্ভব হলো না।')
  const k = plan.short / Math.min(plan.width, plan.height)
  // even numbers, as encoders want
  const W = Math.max(2, Math.round((plan.width * k) / 2) * 2)
  const H = Math.max(2, Math.round((plan.height * k) / 2) * 2)

  let container: 'mp4' | 'webm' = 'mp4'
  let vcodec: 'avc' | 'vp9' = 'avc'
  if (!(await canEncodeVideo('avc', { width: W, height: H, bitrate: plan.bitrate }))) {
    if (await canEncodeVideo('vp9', { width: W, height: H, bitrate: plan.bitrate })) {
      container = 'webm'
      vcodec = 'vp9'
    } else throw new Error('এই ব্রাউজার ভিডিও এনকোড করতে পারছে না — Chrome বা Edge-এর নতুন সংস্করণে চেষ্টা করুন।')
  }
  let acodec: 'aac' | 'opus' = container === 'mp4' ? 'aac' : 'opus'
  if (acodec === 'aac' && !(await canEncodeAudio('aac', { numberOfChannels: 2, sampleRate: 48000, bitrate: AUDIO_BPS }))) {
    try {
      const { registerAacEncoder } = await import('@mediabunny/aac-encoder')
      registerAacEncoder()
    } catch {
      acodec = 'opus'
    }
  }

  const input = new Input({ source: new BlobSource(file), formats: ALL_FORMATS })
  const target = new BufferTarget()
  const output = new Output({ format: container === 'mp4' ? new Mp4OutputFormat({ fastStart: 'in-memory' }) : new WebMOutputFormat(), target })
  const conv = await Conversion.init({
    input,
    output,
    tracks: 'primary',
    video: { width: W, height: H, fit: 'contain', codec: vcodec, bitrate: plan.bitrate, keyFrameInterval: 2, frameRate: 30 },
    audio: { codec: acodec, bitrate: AUDIO_BPS, numberOfChannels: 2, sampleRate: 48000 },
  })
  if (!conv.isValid) {
    input.dispose()
    throw new Error('এই ভিডিওটি ছোট করা যাচ্ছে না (অসমর্থিত ফরম্যাট)।')
  }
  const onAbort = () => void conv.cancel()
  opts.signal?.addEventListener('abort', onAbort)
  conv.onProgress = (p) => opts.onProgress?.(p)
  try {
    await conv.execute()
  } catch (err) {
    if (opts.signal?.aborted) throw new ShrinkCancelled('cancelled')
    throw err
  } finally {
    opts.signal?.removeEventListener('abort', onAbort)
    input.dispose()
  }
  const buf = target.buffer
  if (!buf) throw new Error('ছোট করা ফাইল তৈরি হয়নি।')
  const mime = container === 'mp4' ? 'video/mp4' : 'video/webm'
  return { blob: new Blob([buf], { type: mime }), mime, ext: container, width: W, height: H }
}
