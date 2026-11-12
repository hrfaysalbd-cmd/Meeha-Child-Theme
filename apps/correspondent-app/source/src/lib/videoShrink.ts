/**
 * r33 (copied from the publisher's video studio, round 27): re-encodes a
 * video on the phone so a 500 MB – 1 GB clip goes up as a small file. The
 * target size follows the connection: a slow network gets a smaller file
 * (and so a lower resolution, 1080p → 720p → 540p → 480p). Uses WebCodecs
 * (Chrome, Edge, the Android app); where it is missing the original goes up.
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

/** r35: a short reason a shrink did not work (shown to the correspondent). */
export class ShrinkFailed extends Error {}

type Attempt = { container: 'mp4' | 'webm'; vcodec: 'avc' | 'vp9' | 'vp8'; hw: 'no-preference' | 'prefer-software' }

const REASON: Record<string, string> = {
  undecodable_source_codec: 'এই ফোন ভিডিওটির ফরম্যাট (যেমন HEVC) পড়তে পারে না',
  unknown_source_codec: 'ভিডিওর ফরম্যাট চেনা যায়নি',
  no_encodable_target_codec: 'এই ফোনে ভিডিও তৈরির এনকোডার নেই',
}

/**
 * Re-encodes `file` to about `targetBytes`.
 *
 * r35: phones differ a lot, so it tries, in order, H.264 (hardware, then
 * software) and VP9/VP8 in WebM; a result without its sound is never used;
 * when the encoder stops moving for 90 s it gives up (the original is sent).
 */
export async function shrinkVideo(file: Blob, targetBytes: number, opts: { onProgress?: (ratio: number) => void; signal?: AbortSignal } = {}): Promise<ShrinkResult> {
  const plan = await planShrink(file, targetBytes)
  if (!plan) throw new ShrinkFailed('ভিডিওটি পড়া যায়নি')
  const k = plan.short / Math.min(plan.width, plan.height)
  // multiples of 16 suit every hardware encoder
  const W = Math.max(16, Math.round((plan.width * k) / 16) * 16)
  const H = Math.max(16, Math.round((plan.height * k) / 16) * 16)

  const attempts: Attempt[] = []
  const cfg = { width: W, height: H, bitrate: plan.bitrate }
  if (await canEncodeVideo('avc', cfg)) attempts.push({ container: 'mp4', vcodec: 'avc', hw: 'no-preference' }, { container: 'mp4', vcodec: 'avc', hw: 'prefer-software' })
  if (await canEncodeVideo('vp9', cfg)) attempts.push({ container: 'webm', vcodec: 'vp9', hw: 'no-preference' })
  if (await canEncodeVideo('vp8', cfg)) attempts.push({ container: 'webm', vcodec: 'vp8', hw: 'no-preference' })
  if (!attempts.length) throw new ShrinkFailed('এই ফোনে ভিডিও তৈরির এনকোডার নেই')

  let aacReady = await canEncodeAudio('aac', { numberOfChannels: 2, sampleRate: 48000, bitrate: AUDIO_BPS })
  let last = ''
  for (const a of attempts) {
    if (opts.signal?.aborted) throw new ShrinkCancelled('cancelled')
    let acodec: 'aac' | 'opus' = a.container === 'mp4' ? 'aac' : 'opus'
    if (acodec === 'aac' && !aacReady) {
      try {
        const { registerAacEncoder } = await import('@mediabunny/aac-encoder')
        registerAacEncoder()
        aacReady = true
      } catch {
        last = 'শব্দ এনকোড করা যায়নি'
        continue
      }
    }
    try {
      return await runOnce(file, plan, W, H, a, acodec, opts)
    } catch (err) {
      if (err instanceof ShrinkCancelled || opts.signal?.aborted) throw new ShrinkCancelled('cancelled')
      last = err instanceof Error ? err.message : String(err)
      opts.onProgress?.(0)
    }
  }
  throw new ShrinkFailed(last || 'ভিডিওটি ছোট করা গেল না')
}

async function runOnce(file: Blob, plan: ShrinkPlan, W: number, H: number, a: Attempt, acodec: 'aac' | 'opus', opts: { onProgress?: (ratio: number) => void; signal?: AbortSignal }): Promise<ShrinkResult> {
  const input = new Input({ source: new BlobSource(file), formats: ALL_FORMATS })
  const target = new BufferTarget()
  const output = new Output({ format: a.container === 'mp4' ? new Mp4OutputFormat({ fastStart: 'in-memory' }) : new WebMOutputFormat(), target })
  try {
    const hadAudio = !!(await input.getPrimaryAudioTrack())
    const conv = await Conversion.init({
      input,
      output,
      tracks: 'primary',
      showWarnings: false,
      video: { width: W, height: H, fit: 'contain', codec: a.vcodec, bitrate: plan.bitrate, keyFrameInterval: 2, frameRate: 30, hardwareAcceleration: a.hw },
      audio: { codec: acodec, bitrate: AUDIO_BPS, numberOfChannels: 2, sampleRate: 48000 },
    })
    const dropped = conv.discardedTracks.find((d) => d.track.type === 'video') ?? (hadAudio ? conv.discardedTracks.find((d) => d.track.type === 'audio') : undefined)
    if (!conv.isValid || dropped) {
      const why = dropped ? REASON[dropped.reason] ?? dropped.reason : 'অসমর্থিত ফরম্যাট'
      throw new ShrinkFailed(dropped?.track.type === 'audio' ? `শব্দ রাখা যাচ্ছে না: ${why}` : why)
    }
    // the encoder can hang on some phones: give up when it stops moving
    let lastMove = Date.now()
    let stuck = false
    const watch = setInterval(() => {
      if (Date.now() - lastMove > 90_000) {
        stuck = true
        void conv.cancel()
      }
    }, 5000)
    const onAbort = () => void conv.cancel()
    opts.signal?.addEventListener('abort', onAbort)
    let lastP = -1
    conv.onProgress = (p) => {
      if (p > lastP) {
        lastP = p
        lastMove = Date.now()
      }
      opts.onProgress?.(p)
    }
    try {
      await conv.execute()
    } catch (err) {
      if (opts.signal?.aborted) throw new ShrinkCancelled('cancelled')
      if (stuck) throw new ShrinkFailed('ফোনে ছোট করা থেমে গেছে')
      throw new ShrinkFailed(err instanceof Error && err.message ? err.message.slice(0, 120) : 'এনকোড করা যায়নি')
    } finally {
      clearInterval(watch)
      opts.signal?.removeEventListener('abort', onAbort)
    }
    const buf = target.buffer
    if (!buf || buf.byteLength < 1024) throw new ShrinkFailed('ছোট করা ফাইল তৈরি হয়নি')
    const mime = a.container === 'mp4' ? 'video/mp4' : 'video/webm'
    return { blob: new Blob([buf], { type: mime }), mime, ext: a.container, width: W, height: H }
  } finally {
    input.dispose()
  }
}
