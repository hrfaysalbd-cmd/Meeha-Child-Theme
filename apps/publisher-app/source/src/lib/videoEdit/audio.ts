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
 * - Round 22: voice clean-up, noise reduction (denoise.ts) and the brand
 *   music bed (music.ts), which loops under the video and dips under speech.
 */
import type { EditConfig } from './config'
import type { SfxKind } from './draw'
import { pipClipTime, segSourceEnd, type ClipKey, type Segment, type Timeline } from './timeline'
import { Denoiser, type DenoiseLevel } from './denoise'

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
/** Extra audio rendered before each window so filters and the compressor are settled. */
const PAD = 0.3

/** Carries the music ducking and the limiter from one window to the next. */
export interface MixState {
  music: AudioBuffer | null
  env: number
  duck: number
  cover: number
  limit: number
  /** Loudness-normalisation gain on the whole mix. */
  gain: number
  /** Limiter ceiling (lower when normalising, to leave true-peak room). */
  ceiling: number
}

export function newMixState(music: AudioBuffer | null, gain = 1, normalised = false): MixState {
  return { music, env: 0, duck: 1, cover: 1, limit: 1, gain, ceiling: normalised ? 0.83 : 0.95 }
}

/** Voice clean-up: low cut, less boom, more presence, gentle compression. */
function voiceChain(ctx: BaseAudioContext, mode: 'clear' | 'strong', out: AudioNode): AudioNode {
  const strong = mode === 'strong'
  const hp = ctx.createBiquadFilter()
  hp.type = 'highpass'
  hp.frequency.value = strong ? 110 : 80
  hp.Q.value = 0.7
  const mud = ctx.createBiquadFilter()
  mud.type = 'peaking'
  mud.frequency.value = 260
  mud.Q.value = 1
  mud.gain.value = strong ? -3.5 : -2
  const presence = ctx.createBiquadFilter()
  presence.type = 'peaking'
  presence.frequency.value = 3200
  presence.Q.value = 0.9
  presence.gain.value = strong ? 5 : 3
  const air = ctx.createBiquadFilter()
  air.type = 'highshelf'
  air.frequency.value = 9000
  air.gain.value = strong ? 2.5 : 1.5
  const comp = ctx.createDynamicsCompressor()
  comp.threshold.value = strong ? -30 : -26
  comp.knee.value = 10
  comp.ratio.value = strong ? 4.5 : 3
  comp.attack.value = 0.004
  comp.release.value = 0.2
  hp.connect(mud).connect(presence).connect(air).connect(comp).connect(out)
  return hp
}

/**
 * Renders output seconds [t0, t1) of the soundtrack. Each audible segment
 * plays its clip audio from `clipStart`; edges get 12 ms fades. The main
 * video's sound can go through the voice clean-up; then the brand music is
 * laid under everything (dipping while someone speaks) and a limiter keeps
 * the sum from clipping.
 */
export async function mixWindow(
  tl: Timeline,
  cfg: EditConfig,
  readers: Partial<Record<ClipKey, AudioReader | null>>,
  cues: { t: number; kind: SfxKind; gain: number }[],
  t0: number,
  t1: number,
  state: MixState,
): Promise<AudioBuffer> {
  const r0 = Math.max(0, t0 - PAD)
  const lead = Math.round((t0 - r0) * SAMPLE_RATE)
  const frames = Math.max(1, Math.round((t1 - t0) * SAMPLE_RATE))
  const ctx = new OfflineAudioContext(2, frames + lead, SAMPLE_RATE)
  const span = (frames + lead) / SAMPLE_RATE
  const voiceIn = cfg.audio.voice !== 'off' ? voiceChain(ctx, cfg.audio.voice, ctx.destination) : null

  for (const seg of tl.segments) {
    if (!seg.clip) continue
    const reader = readers[seg.clip]
    if (!reader) continue
    const segEnd = seg.t0 + seg.dur
    const a = Math.max(r0, seg.t0)
    const b = Math.min(t1, segEnd)
    if (b <= a) continue
    const vol = segmentVolume(seg, cfg)
    // slow / fast / held parts play without their own sound (the music carries on)
    if (vol <= 0 || seg.rate !== 1) continue

    const g = ctx.createGain()
    g.connect(seg.kind === 'main' && voiceIn ? voiceIn : ctx.destination)
    // fades at the segment's own edges (relative to this window)
    const sIn = seg.t0 - r0
    const sOut = segEnd - r0
    g.gain.setValueAtTime(sIn > -FADE ? 0 : vol, 0)
    if (sIn > -FADE) {
      g.gain.setValueAtTime(0, Math.max(0, sIn))
      g.gain.linearRampToValueAtTime(vol, Math.max(0, sIn) + FADE)
    }
    if (sOut < span + FADE) {
      g.gain.setValueAtTime(vol, Math.max(0, sOut - FADE))
      g.gain.linearRampToValueAtTime(0, Math.max(0, sOut))
    }
    // the second video's sound only: the main sound steps aside while it shows
    const pw = seg.kind === 'main' && cfg.pip?.sound === 'pip' ? pipWindow(seg, cfg) : null
    if (pw) {
      const a0 = pw[0] - r0
      const b0 = pw[1] - r0
      if (a0 > 0.06) {
        g.gain.setValueAtTime(vol, a0 - 0.06)
        g.gain.linearRampToValueAtTime(0, a0)
      } else g.gain.setValueAtTime(0, Math.max(0, a0))
      if (b0 < span) {
        g.gain.setValueAtTime(0, Math.max(0, b0))
        g.gain.linearRampToValueAtTime(vol, Math.max(0, b0) + 0.06)
      }
    }

    const from = seg.clipStart + (a - seg.t0)
    const to = seg.clipStart + (b - seg.t0)
    for await (const chunk of reader.buffers(from, to)) {
      // where this chunk's first sample lands in the window
      const when = seg.t0 + (chunk.timestamp - seg.clipStart) - r0
      const offset = Math.max(0, -when, seg.t0 - r0 - when)
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

  // the second video's own sound, where it shows
  const pr = readers.pip
  if (pr && cfg.pip && cfg.pip.sound !== 'main') {
    for (const seg of tl.segments) {
      const pw = seg.kind === 'main' ? pipWindow(seg, cfg) : null
      if (!pw) continue
      const a = Math.max(r0, pw[0])
      const b = Math.min(t1, pw[1])
      if (b <= a) continue
      const ca = pipClipTime(tl, cfg, a)
      const cb = pipClipTime(tl, cfg, b)
      if (cb <= ca + 0.01) continue
      const g = ctx.createGain()
      const vol = cfg.audio.main
      g.gain.setValueAtTime(0, Math.max(0, a - r0))
      g.gain.linearRampToValueAtTime(vol, Math.max(0, a - r0) + FADE * 4)
      g.gain.setValueAtTime(vol, Math.max(0, b - r0 - FADE * 4))
      g.gain.linearRampToValueAtTime(0, Math.max(0, b - r0))
      g.connect(ctx.destination)
      for await (const chunk of pr.buffers(ca, cb)) {
        const when = a - r0 + (chunk.timestamp - ca)
        const offset = Math.max(0, a - r0 - when)
        const len = Math.min(chunk.buffer.duration - offset, b - r0 - (when + offset))
        if (len <= 0) continue
        const src = ctx.createBufferSource()
        src.buffer = chunk.buffer
        src.connect(g)
        src.start(Math.max(0, when + offset), offset, len)
      }
    }
  }

  for (const c of cues) {
    if (c.t >= t1 || c.t + 1.7 <= r0) continue
    const buf = await sfxBuffer(c.kind)
    const src = ctx.createBufferSource()
    src.buffer = buf
    const g = ctx.createGain()
    g.gain.value = c.gain
    src.connect(g).connect(ctx.destination)
    const when = c.t - r0
    src.start(Math.max(0, when), Math.max(0, -when))
  }

  const mixed = await ctx.startRendering()
  return finishWindow(mixed, lead, frames, tl, cfg, t0, state)
}

/** Output-time window where the second video shows inside a normal-speed main segment, or null. */
function pipWindow(seg: Segment, cfg: EditConfig): [number, number] | null {
  const p = cfg.pip
  if (!p?.clip || seg.rate !== 1) return null
  const sa = Math.max(p.at, seg.clipStart)
  const sb = Math.min(p.dur === null ? Infinity : p.at + p.dur, segSourceEnd(seg))
  if (sb <= sa) return null
  return [seg.t0 + (sa - seg.clipStart), seg.t0 + (sb - seg.clipStart)]
}

const coef = (seconds: number) => 1 - Math.exp(-1 / (seconds * SAMPLE_RATE))
const ENV_ATTACK = coef(0.01)
const ENV_RELEASE = coef(0.3)
const DUCK_DOWN = coef(0.08)
const DUCK_UP = coef(0.7)
const COVER = coef(0.15)
const LIMIT_UP = coef(0.08)
/** How far the music dips under speech (about -6 dB). */
const DUCK_FLOOR = 0.5

/** Adds the music bed (with ducking and fades) and limits the result. */
function finishWindow(mixed: AudioBuffer, lead: number, frames: number, tl: Timeline, cfg: EditConfig, t0: number, st: MixState): AudioBuffer {
  const L = mixed.getChannelData(0).subarray(lead, lead + frames)
  const R = mixed.getChannelData(1).subarray(lead, lead + frames)
  const outL = new Float32Array(frames)
  const outR = new Float32Array(frames)
  const music = cfg.audio.music !== 'none' && cfg.audio.musicVol > 0 ? st.music : null
  const mL = music?.getChannelData(0)
  const mR = music?.getChannelData(1)
  const loop = music?.length ?? 1
  const total = tl.duration
  const vol = cfg.audio.musicVol
  const startFrame = Math.round(t0 * SAMPLE_RATE)

  let segIdx = 0
  let segEnd = -1
  let coverTarget = 1
  let duckTarget = 1
  for (let i = 0; i < frames; i++) {
    let l = L[i]
    let r = R[i]
    if (music) {
      const t = t0 + i / SAMPLE_RATE
      if (t >= segEnd) {
        while (segIdx < tl.segments.length - 1 && t >= tl.segments[segIdx].t0 + tl.segments[segIdx].dur) segIdx++
        const seg = tl.segments[segIdx]
        segEnd = seg.t0 + seg.dur
        coverTarget = cfg.audio.musicOver === 'main' && seg.kind !== 'main' ? 0 : 1
      }
      st.cover += (coverTarget - st.cover) * COVER
      // follow the level of everything else, every sample; re-aim the dip every 64
      const x = (Math.abs(l) + Math.abs(r)) * 0.5
      st.env += (x - st.env) * (x > st.env ? ENV_ATTACK : ENV_RELEASE)
      if ((i & 63) === 0) {
        if (cfg.audio.duck) {
          const db = 20 * Math.log10(st.env * 1.6 + 1e-9)
          const d = Math.min(1, Math.max(0, (db + 50) / 20))
          duckTarget = 1 - d * (1 - DUCK_FLOOR)
        } else duckTarget = 1
      }
      st.duck += (duckTarget - st.duck) * (duckTarget < st.duck ? DUCK_DOWN : DUCK_UP)
      const fade = Math.min(1, t / 1.2, Math.max(0, (total - t) / 2.5))
      const g = vol * st.duck * st.cover * fade
      const p = (startFrame + i) % loop
      l += mL![p] * g
      r += mR![p] * g
    }
    l *= st.gain
    r *= st.gain
    const peak = Math.max(Math.abs(l), Math.abs(r))
    const want = peak > st.ceiling ? st.ceiling / peak : 1
    st.limit = want < st.limit ? want : st.limit + (want - st.limit) * LIMIT_UP
    outL[i] = l * st.limit
    outR[i] = r * st.limit
  }
  const out = new AudioBuffer({ numberOfChannels: 2, length: frames, sampleRate: SAMPLE_RATE })
  out.copyToChannel(outL, 0)
  out.copyToChannel(outR, 1)
  return out
}

/**
 * Wraps a clip's audio so it comes out with the background noise reduced.
 * Each request is decoded with a little extra on both sides (the FFT
 * needs it); the Denoiser keeps its noise estimate between requests.
 */
export function denoisedReader(base: AudioReader, level: Exclude<DenoiseLevel, 'off'>): AudioReader {
  let dn: Denoiser | null = null
  const EDGE = 0.1
  return {
    async *buffers(from: number, to: number) {
      const a = Math.max(0, from - EDGE)
      const parts: { buffer: AudioBuffer; timestamp: number }[] = []
      for await (const c of base.buffers(a, to + EDGE)) parts.push({ buffer: c.buffer, timestamp: c.timestamp })
      if (!parts.length) return
      const sr = parts[0].buffer.sampleRate
      const chans = Math.min(2, parts[0].buffer.numberOfChannels)
      const start = parts[0].timestamp
      const last = parts[parts.length - 1]
      const len = Math.max(1, Math.round((last.timestamp - start) * sr) + last.buffer.length)
      const data = Array.from({ length: chans }, () => new Float32Array(len))
      for (const p of parts) {
        const at = Math.round((p.timestamp - start) * sr)
        for (let c = 0; c < chans; c++) {
          const src = p.buffer.getChannelData(Math.min(c, p.buffer.numberOfChannels - 1))
          const n = Math.min(src.length, len - at)
          if (n > 0 && at >= 0) data[c].set(src.subarray(0, n), at)
        }
      }
      dn ??= new Denoiser(level, sr)
      const clean = dn.process(data)
      const buffer = new AudioBuffer({ numberOfChannels: chans, length: len, sampleRate: sr })
      clean.forEach((d, c) => buffer.copyToChannel(d, c))
      yield { buffer, timestamp: start }
    },
  }
}
