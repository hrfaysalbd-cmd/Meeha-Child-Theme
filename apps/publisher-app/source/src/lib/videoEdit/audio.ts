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
import { sfxLength, synthLib } from './sfxlib'
import { outputAtOrAfter, pipClipTime, segSourceEnd, sourceToOutput, type ClipKey, type Segment, type Timeline } from './timeline'
import { Denoiser, type DenoiseLevel } from './denoise'
import { removeMusic, type MusicRemoveLevel } from './musicremove'

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
  // round 26: the sound library's own sounds
  if (synthLib(ctx, out, kind, when, gain)) return
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

/** Pre-rendered effect (stereo, its own length plus a short tail). */
export function sfxBuffer(kind: SfxKind): Promise<AudioBuffer> {
  let p = sfxCache.get(kind)
  if (!p) {
    const ctx = new OfflineAudioContext(2, Math.ceil((sfxLength(kind) + 0.2) * SAMPLE_RATE), SAMPLE_RATE)
    synth(ctx, ctx.destination, kind, 0, 1)
    // a few hits stack above full scale: bring every effect under 0.9 so nothing clips
    p = ctx.startRendering().then((buf) => {
      let peak = 0
      for (let c = 0; c < buf.numberOfChannels; c++) {
        const d = buf.getChannelData(c)
        for (let i = 0; i < d.length; i++) peak = Math.max(peak, Math.abs(d[i]))
      }
      if (peak > 0.9) {
        const k = 0.9 / peak
        for (let c = 0; c < buf.numberOfChannels; c++) {
          const d = buf.getChannelData(c)
          for (let i = 0; i < d.length; i++) d[i] *= k
        }
      }
      return buf
    })
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
export function voiceChain(ctx: BaseAudioContext, mode: 'clear' | 'strong', out: AudioNode): AudioNode {
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
  voices: VoiceTrack[] = [],
): Promise<AudioBuffer> {
  const r0 = Math.max(0, t0 - PAD)
  const lead = Math.round((t0 - r0) * SAMPLE_RATE)
  const frames = Math.max(1, Math.round((t1 - t0) * SAMPLE_RATE))
  const ctx = new OfflineAudioContext(2, frames + lead, SAMPLE_RATE)
  const span = (frames + lead) / SAMPLE_RATE
  // round 27: the clips' sound goes through a "bed" that dips while a voice-over speaks
  const bed = ctx.createGain()
  bed.connect(ctx.destination)
  duckBed(bed.gain, voices, r0, span)
  const voiceIn = cfg.audio.voice !== 'off' ? voiceChain(ctx, cfg.audio.voice, bed) : null

  for (const seg of tl.segments) {
    if (!seg.clip) continue
    const reader = readers[seg.clip]
    if (!reader) continue
    const segEnd = seg.t0 + seg.dur
    const a = Math.max(r0, seg.t0)
    const b = Math.min(t1, segEnd)
    if (b <= a) continue
    const vol = segmentVolume(seg, cfg)
    // held frames and speed parts set to silent have no sound of their own (the music carries on);
    // round 26: slow / fast parts play their sound slowed / sped up with the picture (a slower, deeper voice)
    if (vol <= 0 || seg.rate <= 0 || seg.mute) continue
    const rate = seg.rate

    const g = ctx.createGain()
    g.connect(seg.kind === 'main' && voiceIn ? voiceIn : bed)
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

    const from = seg.clipStart + (a - seg.t0) * rate
    const to = seg.clipStart + (b - seg.t0) * rate
    for await (const chunk of reader.buffers(from, to)) {
      // where this chunk's first sample lands in the window (output seconds)
      const when = seg.t0 + (chunk.timestamp - seg.clipStart) / rate - r0
      const start = Math.max(0, when, seg.t0 - r0)
      const stop = Math.min(span, sOut)
      // offset and length are in the chunk's own (source) seconds
      const offset = (start - when) * rate
      const len = Math.min(chunk.buffer.duration - offset, (stop - start) * rate)
      if (len <= 0) continue
      const src = ctx.createBufferSource()
      src.buffer = chunk.buffer
      if (rate !== 1) src.playbackRate.value = rate
      src.connect(g)
      src.start(start, offset, len)
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
      g.connect(bed)
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
    if (c.t >= t1 || c.t + sfxLength(c.kind) + 0.2 <= r0) continue
    const buf = await sfxBuffer(c.kind)
    const src = ctx.createBufferSource()
    src.buffer = buf
    const g = ctx.createGain()
    g.gain.value = c.gain
    src.connect(g).connect(ctx.destination)
    const when = c.t - r0
    src.start(Math.max(0, when), Math.max(0, -when))
  }

  // round 27: voice-overs, on top of the dipped bed
  for (const v of voices) {
    const when = v.start - r0
    const len = v.len
    if (len <= 0 || when >= span || when + len <= 0) continue
    const g = ctx.createGain()
    const vEnd = when + len
    g.gain.setValueAtTime(0, Math.max(0, when))
    g.gain.linearRampToValueAtTime(v.vol, Math.max(0, when) + 0.04)
    g.gain.setValueAtTime(v.vol, Math.max(0, vEnd - 0.06))
    g.gain.linearRampToValueAtTime(0, Math.max(0, vEnd))
    g.connect(v.clean ? voiceChain(ctx, 'clear', ctx.destination) : ctx.destination)
    const src = ctx.createBufferSource()
    src.buffer = v.buf
    src.connect(g)
    src.start(Math.max(0, when), v.offset + Math.max(0, -when), Math.max(0.01, len - Math.max(0, -when)))
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

/** Decodes [from, to] of a clip into one contiguous block per channel (at most stereo). */
async function gather(base: AudioReader, from: number, to: number) {
  const parts: { buffer: AudioBuffer; timestamp: number }[] = []
  for await (const c of base.buffers(from, to)) parts.push({ buffer: c.buffer, timestamp: c.timestamp })
  if (!parts.length) return null
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
  return { data, sr, start, len, chans }
}

function toBuffer(chans: Float32Array<ArrayBuffer>[], sr: number) {
  const buffer = new AudioBuffer({ numberOfChannels: chans.length, length: chans[0].length, sampleRate: sr })
  chans.forEach((d, c) => buffer.copyToChannel(d, c))
  return buffer
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
      const g = await gather(base, Math.max(0, from - EDGE), to + EDGE)
      if (!g) return
      dn ??= new Denoiser(level, g.sr)
      yield { buffer: toBuffer(dn.process(g.data), g.sr), timestamp: g.start }
    },
  }
}

/**
 * Round 26 — wraps a clip's audio so a music bed under the speech is
 * lowered (musicremove.ts). Each request is decoded with 0.6 s extra on
 * both sides, because the "held note" test looks half a second around
 * every moment.
 */
export function musicRemovedReader(base: AudioReader, level: Exclude<MusicRemoveLevel, 'off'>): AudioReader {
  const EDGE = 0.6
  return {
    async *buffers(from: number, to: number) {
      const g = await gather(base, Math.max(0, from - EDGE), to + EDGE)
      if (!g) return
      yield { buffer: toBuffer(removeMusic(g.data, g.sr, level), g.sr), timestamp: g.start }
    },
  }
}

// ---- Round 27: voice-overs ----

/** A decoded voice-over placed on the output timeline. */
export interface VoiceTrack {
  buf: AudioBuffer
  /** Output second where it starts. */
  start: number
  offset: number
  /** Round 29: seconds of the recording that play (to its end, or to the chosen stop). */
  len: number
  vol: number
  duck: number
  clean: boolean
}

const voiceCache = new WeakMap<Blob, Promise<AudioBuffer>>()

/** Decodes a recorded / uploaded voice (any format the browser plays) at the mix rate. */
export function decodeVoice(blob: Blob): Promise<AudioBuffer> {
  let p = voiceCache.get(blob)
  if (!p) {
    p = blob.arrayBuffer().then((ab) => new OfflineAudioContext(2, 1, SAMPLE_RATE).decodeAudioData(ab))
    voiceCache.set(blob, p)
    p.catch(() => voiceCache.delete(blob))
  }
  return p
}

/** The config's voice-overs as tracks (`blobs` in the same order as cfg.voices; missing ones are skipped). */
export async function prepareVoices(cfg: EditConfig, tl: Timeline, blobs: (Blob | null | undefined)[]): Promise<VoiceTrack[]> {
  const out: VoiceTrack[] = []
  for (const [i, v] of cfg.voices.entries()) {
    const blob = blobs[i]
    if (!blob) continue
    const start = sourceToOutput(tl, v.at) ?? outputAtOrAfter(tl, v.at)
    if (start === null) continue
    try {
      const buf = await decodeVoice(blob)
      const offset = Math.min(v.offset, Math.max(0, buf.duration - 0.1))
      let len = buf.duration - offset
      if (v.until !== null && v.until > v.at) {
        const stop = sourceToOutput(tl, v.until) ?? outputAtOrAfter(tl, v.until)
        if (stop !== null && stop > start) len = Math.min(len, stop - start)
      }
      out.push({ buf, start, offset, len, vol: v.vol, duck: v.duck, clean: v.clean })
    } catch {
      // a file the browser cannot read: left out rather than failing the render
    }
  }
  return out
}

/** Dips `gain` (times relative to the window start r0) while any voice speaks. */
function duckBed(gain: AudioParam, voices: VoiceTrack[], r0: number, span: number) {
  gain.setValueAtTime(1, 0)
  const spans = voices
    .map((v) => ({ a: v.start - r0, b: v.start - r0 + v.len, d: v.duck }))
    .filter((x) => x.d < 0.999 && x.b > -0.5 && x.a < span + 0.5)
    .sort((x, y) => x.a - y.a)
  if (!spans.length) return
  // merge overlaps (the deeper dip wins)
  const merged: { a: number; b: number; d: number }[] = []
  for (const x of spans) {
    const last = merged[merged.length - 1]
    if (last && x.a <= last.b + 0.3) {
      last.b = Math.max(last.b, x.b)
      last.d = Math.min(last.d, x.d)
    } else merged.push({ ...x })
  }
  // the level at time t: 0.25 s down before a voice, 0.4 s back up after
  const level = (t: number) => {
    let g = 1
    for (const m of merged) {
      let k = 0
      if (t >= m.a && t <= m.b) k = 1
      else if (t < m.a && t > m.a - 0.25) k = 1 - (m.a - t) / 0.25
      else if (t > m.b && t < m.b + 0.4) k = 1 - (t - m.b) / 0.4
      g = Math.min(g, 1 - k * (1 - m.d))
    }
    return g
  }
  // sampled every 50 ms on absolute time, so neighbouring windows join seamlessly
  const first = Math.ceil(r0 / 0.05) * 0.05 - r0
  gain.setValueAtTime(level(0), 0)
  for (let t = first; t <= span + 0.001; t += 0.05) gain.linearRampToValueAtTime(level(t), t)
}
