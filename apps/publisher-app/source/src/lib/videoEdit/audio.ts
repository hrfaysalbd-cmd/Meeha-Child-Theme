/**
 * Round 21 — sound for the video editor.
 *
 * - Sound effects (whoosh / hit / chime / soft) are synthesised with Web
 *   Audio — no audio files to license or ship — and rendered once into
 *   AudioBuffers.
 * - mixWindow() builds a short stretch of the final soundtrack (clip audio
 *   at the right offsets and volumes, short fades at every cut so nothing
 *   clicks, plus the effects) with an OfflineAudioContext. The exporter
 *   calls it window by window, so even a long video never needs its whole
 *   soundtrack in memory at once.
 */
import type { EditConfig } from './config'
import type { SfxKind } from './draw'
import type { ClipKey, Segment, Timeline } from './timeline'

export const SAMPLE_RATE = 48000

function noiseBuffer(ctx: BaseAudioContext, seconds: number) {
  const b = ctx.createBuffer(1, Math.ceil(seconds * ctx.sampleRate), ctx.sampleRate)
  const d = b.getChannelData(0)
  let seed = 12345
  for (let i = 0; i < d.length; i++) {
    seed = (seed * 1103515245 + 12345) & 0x7fffffff
    d[i] = (seed / 0x7fffffff) * 2 - 1
  }
  return b
}

/** Schedules one effect into `ctx` at `when`. */
function synth(ctx: BaseAudioContext, out: AudioNode, kind: SfxKind, when: number, gain: number) {
  const g = ctx.createGain()
  g.connect(out)
  if (kind === 'whoosh' || kind === 'soft') {
    const len = kind === 'soft' ? 0.5 : 0.75
    const src = ctx.createBufferSource()
    src.buffer = noiseBuffer(ctx, len)
    const bp = ctx.createBiquadFilter()
    bp.type = 'bandpass'
    bp.Q.value = 1.2
    bp.frequency.setValueAtTime(kind === 'soft' ? 500 : 300, when)
    bp.frequency.exponentialRampToValueAtTime(kind === 'soft' ? 1800 : 4200, when + len * 0.75)
    g.gain.setValueAtTime(0.0001, when)
    g.gain.exponentialRampToValueAtTime(0.6 * gain, when + len * 0.55)
    g.gain.exponentialRampToValueAtTime(0.0001, when + len)
    src.connect(bp).connect(g)
    src.start(when)
    src.stop(when + len)
  } else if (kind === 'hit') {
    const o = ctx.createOscillator()
    o.type = 'sine'
    o.frequency.setValueAtTime(110, when)
    o.frequency.exponentialRampToValueAtTime(38, when + 0.6)
    g.gain.setValueAtTime(0.0001, when)
    g.gain.exponentialRampToValueAtTime(0.95 * gain, when + 0.012)
    g.gain.exponentialRampToValueAtTime(0.0001, when + 0.9)
    o.connect(g)
    o.start(when)
    o.stop(when + 0.95)
    // a short bright transient on top
    const n = ctx.createBufferSource()
    n.buffer = noiseBuffer(ctx, 0.12)
    const hp = ctx.createBiquadFilter()
    hp.type = 'highpass'
    hp.frequency.value = 2500
    const ng = ctx.createGain()
    ng.gain.setValueAtTime(0.35 * gain, when)
    ng.gain.exponentialRampToValueAtTime(0.0001, when + 0.12)
    n.connect(hp).connect(ng).connect(out)
    n.start(when)
  } else {
    // chime: two soft bell partials
    for (const [f, a] of [
      [880, 0.32],
      [1318.5, 0.2],
      [1760, 0.08],
    ] as const) {
      const o = ctx.createOscillator()
      o.type = 'sine'
      o.frequency.value = f
      const og = ctx.createGain()
      og.gain.setValueAtTime(0.0001, when)
      og.gain.exponentialRampToValueAtTime(a * gain, when + 0.01)
      og.gain.exponentialRampToValueAtTime(0.0001, when + 1.6)
      o.connect(og).connect(out)
      o.start(when)
      o.stop(when + 1.65)
    }
  }
}

const sfxCache = new Map<SfxKind, Promise<AudioBuffer>>()

/** Pre-rendered effect (≈1.7 s, stereo). */
export function sfxBuffer(kind: SfxKind): Promise<AudioBuffer> {
  let p = sfxCache.get(kind)
  if (!p) {
    const ctx = new OfflineAudioContext(2, Math.ceil(1.7 * SAMPLE_RATE), SAMPLE_RATE)
    synth(ctx, ctx.destination, kind, 0, 1)
    p = ctx.startRendering()
    sfxCache.set(kind, p)
  }
  return p
}

/** Plays an effect now (preview). */
export async function playSfx(ctx: AudioContext, kind: SfxKind, gain: number, delay = 0) {
  const buf = await sfxBuffer(kind)
  const src = ctx.createBufferSource()
  src.buffer = buf
  const g = ctx.createGain()
  g.gain.value = gain
  src.connect(g).connect(ctx.destination)
  src.start(ctx.currentTime + Math.max(0, delay))
}

/** Something that yields decoded audio for a clip range (clip seconds). */
export interface AudioReader {
  buffers(from: number, to: number): AsyncIterable<{ buffer: AudioBuffer; timestamp: number }>
}

export function segmentVolume(seg: Segment, cfg: EditConfig) {
  return seg.kind === 'main' ? cfg.audio.main : cfg.audio.clips
}

const FADE = 0.012

/**
 * Renders output seconds [t0, t1) of the soundtrack. Each audible segment
 * plays its clip audio from `clipStart`; edges get 12 ms fades.
 */
export async function mixWindow(
  tl: Timeline,
  cfg: EditConfig,
  readers: Partial<Record<ClipKey, AudioReader | null>>,
  cues: { t: number; kind: SfxKind; gain: number }[],
  t0: number,
  t1: number,
): Promise<AudioBuffer> {
  const frames = Math.max(1, Math.round((t1 - t0) * SAMPLE_RATE))
  const ctx = new OfflineAudioContext(2, frames, SAMPLE_RATE)
  const span = frames / SAMPLE_RATE

  for (const seg of tl.segments) {
    if (!seg.clip) continue
    const reader = readers[seg.clip]
    if (!reader) continue
    const segEnd = seg.t0 + seg.dur
    const a = Math.max(t0, seg.t0)
    const b = Math.min(t1, segEnd)
    if (b <= a) continue
    const vol = segmentVolume(seg, cfg)
    if (vol <= 0) continue

    const g = ctx.createGain()
    g.connect(ctx.destination)
    // fades at the segment's own edges (relative to this window)
    const sIn = seg.t0 - t0
    const sOut = segEnd - t0
    g.gain.setValueAtTime(sIn > -FADE ? 0 : vol, 0)
    if (sIn > -FADE) {
      g.gain.setValueAtTime(0, Math.max(0, sIn))
      g.gain.linearRampToValueAtTime(vol, Math.max(0, sIn) + FADE)
    }
    if (sOut < span + FADE) {
      g.gain.setValueAtTime(vol, Math.max(0, sOut - FADE))
      g.gain.linearRampToValueAtTime(0, Math.max(0, sOut))
    }

    const from = seg.clipStart + (a - seg.t0)
    const to = seg.clipStart + (b - seg.t0)
    for await (const chunk of reader.buffers(from, to)) {
      // where this chunk's first sample lands in the window
      const when = seg.t0 + (chunk.timestamp - seg.clipStart) - t0
      const offset = Math.max(0, -when, seg.t0 - t0 - when)
      const start = when + offset
      const stop = Math.min(span, sOut)
      const len = Math.min(chunk.buffer.duration - offset, stop - start)
      if (len <= 0) continue
      const src = ctx.createBufferSource()
      src.buffer = chunk.buffer
      src.connect(g)
      src.start(Math.max(0, start), offset, len)
    }
  }

  for (const c of cues) {
    if (c.t >= t1 || c.t + 1.7 <= t0) continue
    const buf = await sfxBuffer(c.kind)
    const src = ctx.createBufferSource()
    src.buffer = buf
    const g = ctx.createGain()
    g.gain.value = c.gain
    src.connect(g).connect(ctx.destination)
    const when = c.t - t0
    src.start(Math.max(0, when), Math.max(0, -when))
  }

  return ctx.startRendering()
}
