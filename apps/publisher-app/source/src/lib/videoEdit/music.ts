/**
 * Round 22: Meeha's brand music beds.
 *
 * Five short pieces written for news video. Every one is synthesised here
 * with Web Audio, so there are no samples or third-party recordings to license
 * and nothing extra to download. Each is rendered once into a seamless loop:
 * the reverb tail that spills past the end is folded back onto the start, so
 * the bed can repeat under a video of any length without a seam.
 *
 * All five share the "Meeha motif", a rising four-note phrase (scale
 * degrees 5-1-2-3) that opens every loop on the piece's lead instrument.
 * That phrase is the sound signature that ties the beds to the brand.
 */
import { SAMPLE_RATE } from './audio'
import type { MusicId } from './config'

const midi = (n: number) => 440 * Math.pow(2, (n - 69) / 12)

/** Deterministic noise, so every render of a piece is the same. */
function rng(seed: number) {
  let s = seed >>> 0
  return () => {
    s = (s * 1664525 + 1013904223) >>> 0
    return s / 4294967296
  }
}

interface Kit {
  ctx: OfflineAudioContext
  /** Dry bus (into the master). */
  dry: AudioNode
  /** Reverb send. */
  rev: AudioNode
  /** Echo send (dotted-eighth delay into the reverb). */
  echo: AudioNode
  noise: AudioBuffer
  beat: number
}

function makeNoise(ctx: BaseAudioContext, seconds: number, seed: number) {
  const b = ctx.createBuffer(1, Math.ceil(seconds * ctx.sampleRate), ctx.sampleRate)
  const d = b.getChannelData(0)
  const r = rng(seed)
  for (let i = 0; i < d.length; i++) d[i] = r() * 2 - 1
  return b
}

/** A small stereo hall: decaying noise, darker as it fades. */
function makeImpulse(ctx: BaseAudioContext, seconds: number) {
  const n = Math.ceil(seconds * ctx.sampleRate)
  const b = ctx.createBuffer(2, n, ctx.sampleRate)
  for (let ch = 0; ch < 2; ch++) {
    const d = b.getChannelData(ch)
    const r = rng(77 + ch * 991)
    let lp = 0
    for (let i = 0; i < n; i++) {
      const t = i / n
      const k = 0.55 - 0.45 * t // one-pole low-pass that closes over time
      lp += k * (r() * 2 - 1 - lp)
      d[i] = lp * Math.pow(1 - t, 2.6) * (i < 0.012 * ctx.sampleRate ? i / (0.012 * ctx.sampleRate) : 1)
    }
  }
  return b
}

function env(g: GainNode, t: number, peak: number, a: number, d: number, sus: number, end: number, r: number) {
  const p = g.gain
  p.setValueAtTime(0.0001, t)
  p.linearRampToValueAtTime(peak, t + a)
  p.setTargetAtTime(peak * sus, t + a, d / 3)
  p.setValueAtTime(peak * sus, Math.max(t + a, end))
  p.setTargetAtTime(0.0001, Math.max(t + a, end), r / 4)
}

function out(k: Kit, node: AudioNode, dry: number, rev: number, echo = 0, pan = 0) {
  const p = k.ctx.createStereoPanner()
  p.pan.value = pan
  node.connect(p)
  const send = (dest: AudioNode, v: number) => {
    if (v <= 0) return
    const g = k.ctx.createGain()
    g.gain.value = v
    p.connect(g).connect(dest)
  }
  send(k.dry, dry)
  send(k.rev, rev)
  send(k.echo, echo)
}

// ---------------------------------------------------------------- voices

/** Warm pad: two detuned saws and a sub, through a soft low-pass. */
function pad(k: Kit, notes: number[], t: number, dur: number, vel = 1, bright = 1100) {
  const { ctx } = k
  const lp = ctx.createBiquadFilter()
  lp.type = 'lowpass'
  lp.frequency.value = bright
  lp.Q.value = 0.4
  const g = ctx.createGain()
  env(g, t, 0.07 * vel, 0.7, 0.8, 0.85, t + dur - 0.2, 1.4)
  lp.connect(g)
  out(k, g, 0.7, 0.55)
  for (const n of notes) {
    for (const det of [-7, 7]) {
      const o = ctx.createOscillator()
      o.type = 'sawtooth'
      o.frequency.value = midi(n)
      o.detune.value = det
      o.connect(lp)
      o.start(t)
      o.stop(t + dur + 1.6)
    }
  }
  const sub = ctx.createOscillator()
  sub.type = 'sine'
  sub.frequency.value = midi(notes[0] - 12)
  const sg = ctx.createGain()
  sg.gain.value = 0.5
  sub.connect(sg).connect(lp)
  sub.start(t)
  sub.stop(t + dur + 1.6)
}

/** Staccato string-machine pulse (the classic newsroom ostinato). */
function pulseNote(k: Kit, n: number, t: number, len: number, vel = 1, pan = 0) {
  const { ctx } = k
  const o = ctx.createOscillator()
  o.type = 'sawtooth'
  o.frequency.value = midi(n)
  const lp = ctx.createBiquadFilter()
  lp.type = 'lowpass'
  lp.Q.value = 2
  lp.frequency.setValueAtTime(2600, t)
  lp.frequency.exponentialRampToValueAtTime(500, t + len)
  const g = ctx.createGain()
  env(g, t, 0.06 * vel, 0.006, len * 0.8, 0.3, t + len * 0.7, 0.08)
  o.connect(lp).connect(g)
  out(k, g, 0.8, 0.25, 0, pan)
  o.start(t)
  o.stop(t + len + 0.2)
}

/** FM bell (the signature lead). */
function bell(k: Kit, n: number, t: number, vel = 1, pan = 0) {
  const { ctx } = k
  const f = midi(n)
  const car = ctx.createOscillator()
  car.frequency.value = f
  const mod = ctx.createOscillator()
  mod.frequency.value = f * 3.5
  const mg = ctx.createGain()
  mg.gain.setValueAtTime(f * 2.2, t)
  mg.gain.exponentialRampToValueAtTime(f * 0.08, t + 1.2)
  mod.connect(mg).connect(car.frequency)
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, t)
  g.gain.linearRampToValueAtTime(0.11 * vel, t + 0.004)
  g.gain.exponentialRampToValueAtTime(0.0001, t + 2.4)
  car.connect(g)
  out(k, g, 0.6, 0.5, 0.35, pan)
  car.start(t)
  mod.start(t)
  car.stop(t + 2.5)
  mod.stop(t + 2.5)
}

/** Electric piano (FM, ratio 1). */
function epiano(k: Kit, n: number, t: number, dur: number, vel = 1, pan = 0) {
  const { ctx } = k
  const f = midi(n)
  const car = ctx.createOscillator()
  car.frequency.value = f
  const mod = ctx.createOscillator()
  mod.frequency.value = f
  const mg = ctx.createGain()
  mg.gain.setValueAtTime(f * 1.4, t)
  mg.gain.exponentialRampToValueAtTime(f * 0.15, t + 0.9)
  mod.connect(mg).connect(car.frequency)
  const tine = ctx.createOscillator()
  tine.frequency.value = f * 4
  const tg = ctx.createGain()
  tg.gain.setValueAtTime(0.025 * vel, t)
  tg.gain.exponentialRampToValueAtTime(0.0001, t + 0.25)
  tine.connect(tg)
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, t)
  g.gain.linearRampToValueAtTime(0.1 * vel, t + 0.005)
  g.gain.exponentialRampToValueAtTime(0.03 * vel, t + 0.6)
  g.gain.setTargetAtTime(0.0001, t + Math.max(0.3, dur), 0.35)
  car.connect(g)
  out(k, g, 0.75, 0.5, 0, pan)
  out(k, tg, 0.6, 0.3, 0, pan)
  for (const o of [car, mod, tine]) {
    o.start(t)
    o.stop(t + dur + 2)
  }
}

/** Marimba: a sine with a short bright overtone. */
function marimba(k: Kit, n: number, t: number, vel = 1, pan = 0, echo = 0.2) {
  const { ctx } = k
  const f = midi(n)
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, t)
  g.gain.linearRampToValueAtTime(0.12 * vel, t + 0.003)
  g.gain.exponentialRampToValueAtTime(0.0001, t + 0.55)
  const o = ctx.createOscillator()
  o.frequency.value = f
  o.connect(g)
  const o2 = ctx.createOscillator()
  o2.frequency.value = f * 3.93
  const g2 = ctx.createGain()
  g2.gain.setValueAtTime(0.05 * vel, t)
  g2.gain.exponentialRampToValueAtTime(0.0001, t + 0.09)
  o2.connect(g2)
  out(k, g, 0.8, 0.3, echo, pan)
  out(k, g2, 0.7, 0.2, 0, pan)
  o.start(t)
  o2.start(t)
  o.stop(t + 0.6)
  o2.stop(t + 0.12)
}

/** Round, slightly filtered bass. */
function bass(k: Kit, n: number, t: number, len: number, vel = 1) {
  const { ctx } = k
  const o = ctx.createOscillator()
  o.type = 'triangle'
  o.frequency.value = midi(n)
  const s = ctx.createOscillator()
  s.type = 'sine'
  s.frequency.value = midi(n)
  const lp = ctx.createBiquadFilter()
  lp.type = 'lowpass'
  lp.frequency.value = 420
  const g = ctx.createGain()
  env(g, t, 0.14 * vel, 0.008, 0.25, 0.7, t + len * 0.9, 0.06)
  o.connect(lp)
  s.connect(lp)
  lp.connect(g)
  out(k, g, 1, 0.04)
  for (const x of [o, s]) {
    x.start(t)
    x.stop(t + len + 0.2)
  }
}

function kick(k: Kit, t: number, vel = 1) {
  const { ctx } = k
  const o = ctx.createOscillator()
  o.frequency.setValueAtTime(140, t)
  o.frequency.exponentialRampToValueAtTime(46, t + 0.11)
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, t)
  g.gain.linearRampToValueAtTime(0.28 * vel, t + 0.004)
  g.gain.exponentialRampToValueAtTime(0.0001, t + 0.38)
  o.connect(g)
  out(k, g, 1, 0.03)
  o.start(t)
  o.stop(t + 0.4)
}

function noiseHit(k: Kit, t: number, type: BiquadFilterType, freq: number, q: number, peak: number, decay: number, attack = 0.001, rev = 0.15, pan = 0) {
  const { ctx } = k
  const src = ctx.createBufferSource()
  src.buffer = k.noise
  const f = ctx.createBiquadFilter()
  f.type = type
  f.frequency.value = freq
  f.Q.value = q
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, t)
  g.gain.linearRampToValueAtTime(peak, t + attack)
  g.gain.exponentialRampToValueAtTime(0.0001, t + attack + decay)
  src.connect(f).connect(g)
  out(k, g, 1, rev, 0, pan)
  // a different stretch of the noise for each hit
  src.start(t, (t * 7.31) % Math.max(0.1, k.noise.duration - 1), attack + decay + 0.05)
}

const hat = (k: Kit, t: number, vel = 1, open = false, pan = 0.2) => noiseHit(k, t, 'highpass', 7500, 0.7, 0.07 * vel, open ? 0.22 : 0.045, 0.001, 0.1, pan)
const shaker = (k: Kit, t: number, vel = 1) => noiseHit(k, t, 'bandpass', 6200, 1.1, 0.05 * vel, 0.07, 0.012, 0.1, -0.25)
function clap(k: Kit, t: number, vel = 1) {
  for (const [dt, a] of [
    [0, 0.6],
    [0.011, 0.8],
    [0.023, 1],
  ] as const)
    noiseHit(k, t + dt, 'bandpass', 1500, 0.9, 0.11 * vel * a, dt === 0.023 ? 0.16 : 0.012, 0.001, 0.35)
}

/** Round 26: snare (noise body + short tone). */
function snare(k: Kit, t: number, vel = 1) {
  noiseHit(k, t, 'bandpass', 2200, 0.7, 0.11 * vel, 0.14, 0.001, 0.3)
  noiseHit(k, t, 'highpass', 5000, 0.6, 0.05 * vel, 0.08, 0.001, 0.2)
  const o = k.ctx.createOscillator()
  o.type = 'triangle'
  o.frequency.setValueAtTime(240, t)
  o.frequency.exponentialRampToValueAtTime(170, t + 0.06)
  const g = k.ctx.createGain()
  g.gain.setValueAtTime(0.0001, t)
  g.gain.linearRampToValueAtTime(0.08 * vel, t + 0.002)
  g.gain.exponentialRampToValueAtTime(0.0001, t + 0.12)
  o.connect(g)
  out(k, g, 1, 0.2)
  o.start(t)
  o.stop(t + 0.14)
}

/** Round 26: short bright brass-like stab (filtered saw chord). */
function stab(k: Kit, notes: number[], t: number, len: number, vel = 1) {
  const { ctx } = k
  const lp = ctx.createBiquadFilter()
  lp.type = 'lowpass'
  lp.Q.value = 1.2
  lp.frequency.setValueAtTime(3800, t)
  lp.frequency.exponentialRampToValueAtTime(900, t + len)
  const g = ctx.createGain()
  env(g, t, 0.05 * vel, 0.01, len * 0.6, 0.5, t + len * 0.8, 0.12)
  lp.connect(g)
  out(k, g, 0.8, 0.35)
  for (const n of notes) {
    for (const det of [-9, 9]) {
      const o = ctx.createOscillator()
      o.type = 'sawtooth'
      o.frequency.value = midi(n)
      o.detune.value = det
      o.connect(lp)
      o.start(t)
      o.stop(t + len + 0.4)
    }
  }
}

/** Clock tick (high, dry, short). */
function tick(k: Kit, t: number, vel = 1, pan = 0) {
  const { ctx } = k
  const o = ctx.createOscillator()
  o.type = 'square'
  o.frequency.value = 2400
  const bp = ctx.createBiquadFilter()
  bp.type = 'bandpass'
  bp.frequency.value = 3200
  bp.Q.value = 3
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, t)
  g.gain.linearRampToValueAtTime(0.05 * vel, t + 0.001)
  g.gain.exponentialRampToValueAtTime(0.0001, t + 0.03)
  o.connect(bp).connect(g)
  out(k, g, 1, 0.08, 0, pan)
  o.start(t)
  o.stop(t + 0.05)
}

/** Orchestral low hit. */
function timpani(k: Kit, n: number, t: number, vel = 1) {
  const { ctx } = k
  const o = ctx.createOscillator()
  o.frequency.setValueAtTime(midi(n) * 1.06, t)
  o.frequency.exponentialRampToValueAtTime(midi(n), t + 0.15)
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, t)
  g.gain.linearRampToValueAtTime(0.3 * vel, t + 0.006)
  g.gain.exponentialRampToValueAtTime(0.0001, t + 1.6)
  o.connect(g)
  out(k, g, 0.9, 0.5)
  o.start(t)
  o.stop(t + 1.7)
  noiseHit(k, t, 'lowpass', 900, 0.5, 0.12 * vel, 0.25, 0.002, 0.4)
}

/** Reverse-cymbal swell into the next downbeat. */
function swell(k: Kit, t: number, dur: number, vel = 1) {
  const { ctx } = k
  const src = ctx.createBufferSource()
  src.buffer = k.noise
  const hp = ctx.createBiquadFilter()
  hp.type = 'highpass'
  hp.frequency.setValueAtTime(2500, t)
  hp.frequency.exponentialRampToValueAtTime(7000, t + dur)
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, t)
  g.gain.exponentialRampToValueAtTime(0.06 * vel, t + dur)
  g.gain.linearRampToValueAtTime(0.0001, t + dur + 0.03)
  src.connect(hp).connect(g)
  out(k, g, 0.8, 0.4)
  src.start(t, 0.5, dur + 0.05)
}

/** Bamboo flute: sine + breath, with delayed vibrato and a soft glide in. */
function flute(k: Kit, n: number, t: number, dur: number, vel = 1, from?: number) {
  const { ctx } = k
  const f = midi(n)
  const o = ctx.createOscillator()
  o.type = 'sine'
  if (from !== undefined) {
    o.frequency.setValueAtTime(midi(from), t)
    o.frequency.exponentialRampToValueAtTime(f, t + 0.09)
  } else o.frequency.value = f
  const o2 = ctx.createOscillator()
  o2.type = 'triangle'
  o2.frequency.value = f * 2
  const g2 = ctx.createGain()
  g2.gain.value = 0.12
  o2.connect(g2)
  const lfo = ctx.createOscillator()
  lfo.frequency.value = 5.2
  const lg = ctx.createGain()
  lg.gain.setValueAtTime(0, t)
  lg.gain.linearRampToValueAtTime(f * 0.006, t + Math.min(dur, 0.45))
  lfo.connect(lg)
  lg.connect(o.frequency)
  lg.connect(o2.frequency)
  const g = ctx.createGain()
  env(g, t, 0.1 * vel, 0.06, 0.3, 0.8, t + dur, 0.12)
  o.connect(g)
  g2.connect(g)
  out(k, g, 0.7, 0.55, 0.15, -0.1)
  // breath
  const src = ctx.createBufferSource()
  src.buffer = k.noise
  const bp = ctx.createBiquadFilter()
  bp.type = 'bandpass'
  bp.frequency.value = f * 2
  bp.Q.value = 2.5
  const bg = ctx.createGain()
  env(bg, t, 0.035 * vel, 0.03, 0.15, 0.35, t + dur, 0.1)
  src.connect(bp).connect(bg)
  out(k, bg, 0.7, 0.4, 0, -0.1)
  for (const x of [o, o2, lfo]) {
    x.start(t)
    x.stop(t + dur + 0.4)
  }
  src.start(t, (t * 3.7) % 4, dur + 0.4)
}

const ksCache = new Map<string, AudioBuffer>()
/** Plucked string (Karplus-Strong), used for the dotara. */
function pluck(k: Kit, n: number, t: number, vel = 1, pan = 0, bright = 0.5) {
  const { ctx } = k
  const key = `${n}:${bright}`
  let buf = ksCache.get(key)
  if (!buf || buf.sampleRate !== ctx.sampleRate) {
    const sr = ctx.sampleRate
    const len = Math.ceil(sr * 1.6)
    buf = ctx.createBuffer(1, len, sr)
    const d = buf.getChannelData(0)
    const p = Math.max(2, Math.round(sr / midi(n)))
    const line = new Float32Array(p)
    const r = rng(n * 31 + 7)
    for (let i = 0; i < p; i++) line[i] = r() * 2 - 1
    let idx = 0
    for (let i = 0; i < len; i++) {
      const cur = line[idx]
      const nxt = line[(idx + 1) % p]
      const v = (cur * (1 - bright) + nxt * bright) * 0.996
      line[idx] = v
      d[i] = cur
      idx = (idx + 1) % p
    }
    ksCache.set(key, buf)
  }
  const src = ctx.createBufferSource()
  src.buffer = buf
  const g = ctx.createGain()
  g.gain.value = 0.13 * vel
  const hp = ctx.createBiquadFilter()
  hp.type = 'highpass'
  hp.frequency.value = 120
  src.connect(hp).connect(g)
  out(k, g, 0.8, 0.35, 0.12, pan)
  src.start(t)
}

/** Tanpura-like drone note: buzzy saw, slow bloom, long ring. */
function drone(k: Kit, n: number, t: number, vel = 1, pan = 0) {
  const { ctx } = k
  const o = ctx.createOscillator()
  o.type = 'sawtooth'
  o.frequency.value = midi(n)
  const bp = ctx.createBiquadFilter()
  bp.type = 'bandpass'
  bp.Q.value = 1.4
  bp.frequency.setValueAtTime(midi(n) * 6, t)
  bp.frequency.exponentialRampToValueAtTime(midi(n) * 3, t + 2.2)
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, t)
  g.gain.linearRampToValueAtTime(0.05 * vel, t + 0.05)
  g.gain.exponentialRampToValueAtTime(0.0001, t + 3)
  o.connect(bp).connect(g)
  out(k, g, 0.7, 0.5, 0, pan)
  o.start(t)
  o.stop(t + 3.1)
}

/** Tabla strokes: 'dha' (bass + ring), 'na' (ring), 'ge' (bass), 'ti' (dry slap). */
function tabla(k: Kit, stroke: 'dha' | 'na' | 'ge' | 'ti' | 'dhi', t: number, vel = 1) {
  const { ctx } = k
  if (stroke === 'dha' || stroke === 'ge' || stroke === 'dhi') {
    const o = ctx.createOscillator()
    o.frequency.setValueAtTime(78, t)
    o.frequency.linearRampToValueAtTime(stroke === 'ge' ? 96 : 104, t + 0.18) // bayan glide up
    const g = ctx.createGain()
    g.gain.setValueAtTime(0.0001, t)
    g.gain.linearRampToValueAtTime(0.26 * vel, t + 0.005)
    g.gain.exponentialRampToValueAtTime(0.0001, t + 0.5)
    o.connect(g)
    out(k, g, 1, 0.12, 0, -0.15)
    o.start(t)
    o.stop(t + 0.55)
  }
  if (stroke === 'dha' || stroke === 'na' || stroke === 'dhi') {
    const f = stroke === 'dhi' ? 540 : 620
    for (const [m, a] of [
      [1, 1],
      [2.03, 0.35],
      [3.1, 0.15],
    ] as const) {
      const o = ctx.createOscillator()
      o.frequency.value = f * m
      const g = ctx.createGain()
      g.gain.setValueAtTime(0.0001, t)
      g.gain.linearRampToValueAtTime(0.05 * vel * a, t + 0.002)
      g.gain.exponentialRampToValueAtTime(0.0001, t + (stroke === 'dhi' ? 0.45 : 0.3) / m)
      o.connect(g)
      out(k, g, 1, 0.15, 0, 0.15)
      o.start(t)
      o.stop(t + 0.5)
    }
  }
  noiseHit(k, t, 'bandpass', stroke === 'ti' ? 2400 : 1800, 1.2, (stroke === 'ti' ? 0.07 : 0.03) * vel, stroke === 'ti' ? 0.05 : 0.02, 0.001, 0.08, 0.15)
}

// ---------------------------------------------------------------- pieces

interface Piece {
  bpm: number
  bars: number
  write: (k: Kit, beat: (bar: number, b: number) => number) => void
}

/** The Meeha motif (5-1-2-3) starting on `root` (the "1"), minor or major third. */
function motif(root: number, minor: boolean): [number, number, number][] {
  // [note, start beat, length in beats]
  return [
    [root - 5, 0, 0.5],
    [root, 0.5, 0.5],
    [root + 2, 1, 1],
    [root + (minor ? 3 : 4), 2, 2],
  ]
}

const PIECES: Record<MusicId, Piece> = {
  // D major, 112 BPM. Bm - G - D - A, pulse, light drums, bell motif.
  signature: {
    bpm: 112,
    bars: 8,
    write(k, at) {
      const chords = [
        [59, 62, 66],
        [55, 59, 62],
        [57, 62, 66],
        [57, 61, 64],
      ]
      const roots = [47, 43, 38, 45]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        const r = roots[bar % 4]
        pad(k, c, at(bar, 0), k.beat * 4, 1)
        bass(k, r, at(bar, 0), k.beat * 1.5)
        bass(k, r, at(bar, 1.5), k.beat * 0.5, 0.8)
        bass(k, r, at(bar, 2), k.beat * 2, 0.9)
        for (let e = 0; e < 8; e++) pulseNote(k, (e % 2 ? c[2] : c[0]) - 12, at(bar, e / 2), k.beat * 0.45, e % 2 ? 0.7 : 1, e % 2 ? 0.25 : -0.25)
        kick(k, at(bar, 0), 0.9)
        kick(k, at(bar, 2), 0.7)
        if (bar % 2 === 1) kick(k, at(bar, 3.5), 0.45)
        for (let e = 0; e < 8; e++) if (e % 2) hat(k, at(bar, e / 2), 0.8)
        for (let s = 0; s < 16; s++) shaker(k, at(bar, s / 4), s % 4 === 2 ? 0.9 : 0.45)
      }
      for (const [n, b] of motif(74, false)) bell(k, n, at(0, b), 1, 0.1)
      for (const [n, b] of motif(74, false).slice(0, 3)) bell(k, n + 12, at(4, b), 0.6, -0.2)
      bell(k, 78, at(4, 2), 0.7, -0.2)
      bell(k, 81, at(6, 2), 0.5, 0.25)
      swell(k, at(7, 2), k.beat * 2, 0.9)
    },
  },
  // A minor, 124 BPM. Am - F - C - G, ticking clock, timpani, rising pulse.
  pulse: {
    bpm: 124,
    bars: 8,
    write(k, at) {
      const chords = [
        [57, 60, 64],
        [53, 57, 60],
        [55, 60, 64],
        [55, 59, 62],
      ]
      const roots = [45, 41, 36, 43]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        const r = roots[bar % 4]
        pad(k, [c[0] - 12, c[1] - 12, c[2] - 12], at(bar, 0), k.beat * 4, 0.8, 800)
        for (let e = 0; e < 8; e++) bass(k, r, at(bar, e / 2), k.beat * 0.35, e % 2 ? 0.6 : 0.9)
        for (let s = 0; s < 16; s++) tick(k, at(bar, s / 4), s % 4 === 0 ? 1 : 0.45, s % 2 ? 0.3 : -0.3)
        if (bar >= 4) for (let s = 0; s < 16; s++) pulseNote(k, c[s % 3], at(bar, s / 4), k.beat * 0.22, 0.45 + (bar - 4) * 0.12, s % 2 ? 0.35 : -0.35)
        kick(k, at(bar, 0), 0.9)
        kick(k, at(bar, 2), 0.8)
        hat(k, at(bar, 1), 0.6, true)
        hat(k, at(bar, 3), 0.6, true)
        if (bar % 4 === 0) timpani(k, 33, at(bar, 0), 1)
      }
      for (const [n, b] of motif(69, true)) pulseNote(k, n + 12, at(0, b), k.beat * 0.9, 1.4, 0)
      for (const [n, b] of motif(69, true)) bell(k, n + 12, at(0, b), 0.55, 0)
      timpani(k, 33, at(7, 3), 0.6)
      timpani(k, 33, at(7, 3.5), 0.8)
      swell(k, at(7, 2), k.beat * 2, 1)
    },
  },
  // C major, 76 BPM. Cmaj7 - Am7 - Fmaj7 - G6, broken-chord electric piano, no drums.
  calm: {
    bpm: 76,
    bars: 8,
    write(k, at) {
      const chords = [
        [48, 55, 59, 64],
        [45, 52, 55, 60],
        [41, 48, 52, 57],
        [43, 50, 52, 59],
      ]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        pad(k, [c[1], c[2], c[3]], at(bar, 0), k.beat * 4, 0.8, 900)
        bass(k, c[0] >= 45 ? c[0] - 12 : c[0], at(bar, 0), k.beat * 3.8, 0.6)
        const arp = [c[1], c[2], c[3], c[2] + 12, c[3], c[2]]
        const rhythm = [0, 1, 1.5, 2, 3, 3.5]
        for (let i = 0; i < arp.length; i++) {
          if (bar === 0 && i >= 1 && i <= 3) continue // leave room for the motif
          epiano(k, arp[i], at(bar, rhythm[i]), k.beat * 0.9, i === 0 ? 0.85 : 0.6, i % 2 ? 0.3 : -0.3)
        }
        shaker(k, at(bar, 1.5), 0.25)
        shaker(k, at(bar, 3.5), 0.25)
      }
      for (const [n, b, l] of motif(72, false)) epiano(k, n, at(0, b + 0.5), k.beat * l, 1, 0)
      for (const [n, b, l] of motif(72, false)) epiano(k, n + 12, at(4, b + 0.5), k.beat * l, 0.5, 0.2)
    },
  },
  // G major, 120 BPM. G - D - Em - C, claps, bouncy bass, marimba.
  showbiz: {
    bpm: 120,
    bars: 8,
    write(k, at) {
      const chords = [
        [55, 59, 62],
        [54, 57, 62],
        [52, 55, 59],
        [52, 55, 60],
      ]
      const roots = [43, 38, 40, 36]
      const arp = [0, 1, 2, 1, 0, 2, 1, 2]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        const r = roots[bar % 4]
        pad(k, c, at(bar, 0), k.beat * 4, 0.6, 1600)
        const bl = [
          [0, r, 0.4],
          [0.75, r + 12, 0.25],
          [1.5, r, 0.4],
          [2, r + 7, 0.4],
          [2.75, r + 12, 0.25],
          [3.5, r + 7, 0.4],
        ] as const
        for (const [b, n, l] of bl) bass(k, n, at(bar, b), k.beat * l, 0.85)
        kick(k, at(bar, 0), 0.85)
        kick(k, at(bar, 1.5), 0.55)
        kick(k, at(bar, 2), 0.8)
        clap(k, at(bar, 1), 0.9)
        clap(k, at(bar, 3), 0.9)
        for (let e = 0; e < 8; e++) hat(k, at(bar, e / 2), e % 2 ? 0.9 : 0.5, e === 7)
        for (let s = 0; s < 8; s++) {
          if (bar === 0 && s < 6) continue // the motif plays here
          marimba(k, c[arp[s]] + 12, at(bar, s / 2), s % 2 ? 0.6 : 0.85, s % 2 ? 0.35 : -0.35, 0.15)
        }
      }
      for (const [n, b] of motif(79, false)) marimba(k, n, at(0, b), 1.2, 0, 0.3)
      for (const [n, b] of motif(79, false)) bell(k, n + 12, at(4, b), 0.35, 0.3)
      bell(k, 86, at(7, 3), 0.4, -0.3)
      swell(k, at(7, 3), k.beat, 0.8)
    },
  },
  // D, 92 BPM in keherwa. Tanpura drone, dotara, bamboo flute, tabla.
  desi: {
    bpm: 92,
    bars: 8,
    write(k, at) {
      for (let bar = 0; bar < 8; bar++) {
        // tanpura cycle: Pa Sa Sa Sa
        drone(k, 45, at(bar, 0), 0.9, -0.3)
        drone(k, 50, at(bar, 1), 0.8, -0.1)
        drone(k, 50, at(bar, 2), 0.8, 0.1)
        drone(k, 38, at(bar, 3), 1, 0.3)
        // keherwa: dha ge na ti | na ka dhi na
        const strokes = ['dha', 'ge', 'na', 'ti', 'na', 'ti', 'dhi', 'na'] as const
        strokes.forEach((s, i) => tabla(k, s, at(bar, i / 2), i === 0 ? 1 : i === 4 ? 0.7 : 0.8))
        if (bar % 2 === 1) tabla(k, 'ti', at(bar, 3.75), 0.6)
        // dotara ostinato
        const dot = bar % 4 < 2 ? [62, 69, 66, 69, 62, 69, 66, 64] : [60, 67, 64, 67, 62, 69, 66, 69]
        dot.forEach((n, i) => {
          if (bar === 0 && i < 4) return
          pluck(k, n, at(bar, i / 2), i % 2 ? 0.7 : 1, i % 2 ? 0.3 : -0.2, 0.45)
        })
      }
      // flute: the motif, then a Bhatiyali-style answer
      for (const [n, b, l] of motif(74, false)) flute(k, n, at(0, b), k.beat * l * 0.95, 1, n === 69 ? 67 : undefined)
      const phrase: [number, number, number, number?][] = [
        [81, 0, 1.5, 79],
        [79, 1.5, 0.5],
        [78, 2, 1],
        [76, 3, 1],
        [74, 0, 2, 76],
        [72, 2, 1],
        [74, 3, 1],
        [78, 0, 1.5],
        [76, 1.5, 0.5],
        [74, 2, 2, 72],
      ]
      const bars = [2, 2, 2, 2, 3, 3, 3, 5, 5, 5]
      phrase.forEach(([n, b, l, from], i) => flute(k, n, at(bars[i], b), k.beat * l * 0.95, 0.8, from))
      flute(k, 69, at(6, 2), k.beat * 1.9, 0.6, 71)
      flute(k, 74, at(7, 0), k.beat * 3.5, 0.55, 72)
    },
  },
  // ---- round 26 ----
  // D minor, 84 BPM. Dm - Bb - Gm - A: low pulse, heartbeat kick, ticking — for investigations.
  investigation: {
    bpm: 84,
    bars: 8,
    write(k, at) {
      const chords = [
        [50, 53, 57],
        [46, 50, 53],
        [43, 46, 50],
        [45, 49, 52],
      ]
      const roots = [38, 34, 31, 33]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        pad(k, c, at(bar, 0), k.beat * 4, 0.9, 650)
        for (let e = 0; e < 8; e++) bass(k, roots[bar % 4], at(bar, e / 2), k.beat * 0.3, e % 2 ? 0.45 : 0.75)
        kick(k, at(bar, 0), 0.75)
        kick(k, at(bar, 0.35), 0.5)
        kick(k, at(bar, 2), 0.7)
        kick(k, at(bar, 2.35), 0.45)
        for (let s = 0; s < 8; s++) tick(k, at(bar, s / 2), s % 2 ? 0.35 : 0.7, s % 2 ? 0.4 : -0.4)
        if (bar % 4 === 3) timpani(k, 33, at(bar, 3), 0.55)
      }
      for (const [n, b] of motif(74, true)) bell(k, n - 12, at(0, b), 0.9, 0)
      for (const [n, b] of motif(74, true)) bell(k, n, at(4, b), 0.45, 0.3)
      swell(k, at(7, 2), k.beat * 2, 0.7)
    },
  },
  // E minor, 128 BPM. Em - C - G - D: four-on-the-floor, claps, brass stabs — for sport.
  sports: {
    bpm: 128,
    bars: 8,
    write(k, at) {
      const chords = [
        [64, 67, 71],
        [60, 64, 67],
        [62, 67, 71],
        [62, 66, 69],
      ]
      const roots = [40, 36, 43, 38]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        const r = roots[bar % 4]
        pad(k, c.map((n) => n - 12), at(bar, 0), k.beat * 4, 0.55, 1400)
        for (let b = 0; b < 4; b++) kick(k, at(bar, b), 0.95)
        clap(k, at(bar, 1), 0.85)
        clap(k, at(bar, 3), 0.85)
        for (let s = 0; s < 16; s++) hat(k, at(bar, s / 4), s % 4 === 2 ? 1 : 0.45, s === 14)
        for (let e = 0; e < 8; e++) bass(k, e % 2 ? r + 12 : r, at(bar, e / 2), k.beat * 0.4, 0.85)
        if (bar >= 1) {
          stab(k, c, at(bar, 0.5), k.beat * 0.35, 0.9)
          stab(k, c, at(bar, 2.5), k.beat * 0.35, 0.8)
        }
        if (bar === 7) for (let s = 0; s < 8; s++) snare(k, at(bar, 2 + s / 4), 0.4 + s * 0.08)
      }
      for (const [n, b, l] of motif(76, true)) stab(k, [n, n + 7], at(0, b), k.beat * l * 0.8, 1.1)
    },
  },
  // F major, 118 BPM. Fmaj7 - Dm7 - Bbmaj7 - C: plucked arpeggios with echo — economy, business, tech.
  tech: {
    bpm: 118,
    bars: 8,
    write(k, at) {
      const chords = [
        [53, 57, 60, 64],
        [50, 53, 57, 60],
        [46, 50, 53, 57],
        [48, 52, 55, 60],
      ]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        pad(k, [c[1], c[2], c[3]], at(bar, 0), k.beat * 4, 0.45, 1800)
        bass(k, c[0] - 12, at(bar, 0), k.beat * 0.9, 0.8)
        bass(k, c[0] - 12, at(bar, 1.5), k.beat * 0.4, 0.6)
        bass(k, c[0] - 5, at(bar, 2.5), k.beat * 1.2, 0.7)
        kick(k, at(bar, 0), 0.75)
        kick(k, at(bar, 2), 0.65)
        snare(k, at(bar, 1), 0.35)
        snare(k, at(bar, 3), 0.35)
        for (let s = 0; s < 16; s++) shaker(k, at(bar, s / 4), s % 2 ? 0.35 : 0.6)
        for (let s = 0; s < 8; s++) {
          if (bar === 0 && s < 6) continue
          marimba(k, c[[0, 2, 1, 3, 2, 1, 3, 2][s]] + 12, at(bar, s / 2), s % 2 ? 0.55 : 0.8, s % 2 ? 0.4 : -0.4, 0.35)
        }
      }
      for (const [n, b] of motif(77, false)) bell(k, n, at(0, b), 1, 0)
      bell(k, 84, at(4, 2), 0.5, -0.3)
      swell(k, at(7, 3), k.beat, 0.7)
    },
  },
  // A minor, 66 BPM. Am - F - C - E: slow piano, soft strings, no drums — loss, grief, human stories.
  sad: {
    bpm: 66,
    bars: 8,
    write(k, at) {
      const chords = [
        [45, 52, 57, 60],
        [41, 48, 53, 57],
        [48, 55, 60, 64],
        [40, 52, 56, 59],
      ]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        pad(k, [c[1], c[2], c[3]], at(bar, 0), k.beat * 4, 0.6, 700)
        bass(k, c[0] - 12, at(bar, 0), k.beat * 3.8, 0.45)
        const arp = [c[1], c[2], c[3], c[2]]
        arp.forEach((n, i) => {
          if (bar === 0 && i > 0) return
          epiano(k, n + 12, at(bar, i), k.beat * 1.2, i === 0 ? 0.7 : 0.5, i % 2 ? 0.25 : -0.25)
        })
      }
      for (const [n, b, l] of motif(69, true)) epiano(k, n + 12, at(0, b + 1), k.beat * l, 0.95, 0)
      for (const [n, b, l] of motif(69, true)) epiano(k, n + 12, at(4, b + 1), k.beat * l, 0.5, 0.15)
    },
  },
  // C major, 96 BPM. C - G - Am - F: big chords, timpani, bells — national days, festivals, celebration.
  anthem: {
    bpm: 96,
    bars: 8,
    write(k, at) {
      const chords = [
        [60, 64, 67],
        [59, 62, 67],
        [57, 60, 64],
        [57, 60, 65],
      ]
      const roots = [36, 43, 45, 41]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        pad(k, c, at(bar, 0), k.beat * 4, 0.95, 2000)
        pad(k, c.map((n) => n - 12), at(bar, 0), k.beat * 4, 0.5, 900)
        bass(k, roots[bar % 4], at(bar, 0), k.beat * 1.8, 0.9)
        bass(k, roots[bar % 4], at(bar, 2), k.beat * 1.8, 0.8)
        kick(k, at(bar, 0), 0.85)
        snare(k, at(bar, 2), 0.6)
        if (bar % 2 === 0) timpani(k, roots[bar % 4] - 12 + 12, at(bar, 0), 0.7)
        for (let e = 0; e < 4; e++) stab(k, c, at(bar, e + 0.5), k.beat * 0.25, 0.35)
      }
      for (const [n, b] of motif(72, false)) bell(k, n + 12, at(0, b), 1, 0)
      for (const [n, b, l] of motif(72, false)) stab(k, [n, n + 4], at(4, b), k.beat * l * 0.9, 0.8)
      for (let s = 0; s < 8; s++) snare(k, at(7, 2 + s / 4), 0.3 + s * 0.08)
      swell(k, at(7, 2), k.beat * 2, 1)
    },
  },
  // G major, 100 BPM. Gmaj7 - Em7 - Cmaj7 - D: plucked guitar, shaker, bells — morning, lifestyle, travel.
  morning: {
    bpm: 100,
    bars: 8,
    write(k, at) {
      const chords = [
        [55, 59, 62, 66],
        [52, 55, 59, 62],
        [48, 52, 55, 59],
        [50, 54, 57, 62],
      ]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        pad(k, [c[1], c[2], c[3]], at(bar, 0), k.beat * 4, 0.35, 1500)
        bass(k, c[0] - 12, at(bar, 0), k.beat * 1.4, 0.7)
        bass(k, c[0] - 5, at(bar, 2), k.beat * 1.4, 0.6)
        kick(k, at(bar, 0), 0.6)
        kick(k, at(bar, 2.5), 0.45)
        clap(k, at(bar, 1), 0.4)
        clap(k, at(bar, 3), 0.4)
        for (let s = 0; s < 16; s++) shaker(k, at(bar, s / 4), s % 4 === 2 ? 0.8 : 0.4)
        // strum: the chord's notes a few ms apart, on a skipping rhythm
        for (const b of [0, 0.75, 1.5, 2, 2.75, 3.5]) {
          if (bar === 0 && b < 2) continue
          c.forEach((n, i) => pluck(k, n + 12, at(bar, b) + i * 0.012, b % 1 ? 0.55 : 0.8, i % 2 ? 0.25 : -0.25, 0.6))
        }
      }
      for (const [n, b] of motif(74, false)) pluck(k, n + 12, at(0, b), 1.1, 0, 0.7)
      for (const [n, b] of motif(74, false)) bell(k, n + 12, at(4, b), 0.35, 0.3)
    },
  },
  // ---- round 27 ----
  // D minor, 70 BPM. Dm - Bb - F - C: wide low pads, timpani swells, distant bells — documentary, long reports.
  cinematic: {
    bpm: 70,
    bars: 8,
    write(k, at) {
      const chords = [
        [50, 53, 57],
        [46, 50, 53],
        [53, 57, 60],
        [48, 52, 55],
      ]
      const roots = [38, 34, 41, 36]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        pad(k, c, at(bar, 0), k.beat * 4, 0.95, 750)
        pad(k, [c[0] + 12, c[2] + 12], at(bar, 0), k.beat * 4, 0.35, 1800)
        bass(k, roots[bar % 4], at(bar, 0), k.beat * 3.8, 0.7)
        if (bar % 2 === 0) timpani(k, roots[bar % 4], at(bar, 0), 0.75)
        kick(k, at(bar, 0), 0.5)
        if (bar % 2 === 1) kick(k, at(bar, 2.5), 0.35)
        for (let e = 0; e < 4; e++) pulseNote(k, c[e % 3] + 12, at(bar, e + 0.5), k.beat * 0.4, 0.35, e % 2 ? 0.4 : -0.4)
      }
      for (const [n, b] of motif(74, true)) bell(k, n, at(0, b), 0.9, 0)
      for (const [n, b] of motif(74, true)) bell(k, n + 12, at(4, b), 0.45, 0.3)
      swell(k, at(3, 2), k.beat * 2, 0.7)
      swell(k, at(7, 2), k.beat * 2, 1)
    },
  },
  // E minor, 108 BPM. Em - C - G - D: marching snare, string stabs, timpani — elections, politics, parliament.
  election: {
    bpm: 108,
    bars: 8,
    write(k, at) {
      const chords = [
        [52, 55, 59],
        [48, 52, 55],
        [55, 59, 62],
        [50, 54, 57],
      ]
      const roots = [40, 36, 43, 38]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        const r = roots[bar % 4]
        pad(k, c, at(bar, 0), k.beat * 4, 0.6, 1200)
        for (let e = 0; e < 8; e++) bass(k, r, at(bar, e / 2), k.beat * 0.3, e % 2 ? 0.55 : 0.85)
        for (const b of [0, 0.75, 1.5, 2.5, 3]) stab(k, c.map((n) => n + 12), at(bar, b), k.beat * 0.22, 0.45)
        kick(k, at(bar, 0), 0.85)
        kick(k, at(bar, 2), 0.75)
        snare(k, at(bar, 1), 0.55)
        snare(k, at(bar, 3), 0.55)
        snare(k, at(bar, 3.75), 0.25)
        for (let s = 0; s < 16; s++) tick(k, at(bar, s / 4), s % 4 === 0 ? 0.6 : 0.25, s % 2 ? 0.3 : -0.3)
        if (bar % 4 === 0) timpani(k, 28, at(bar, 0), 0.9)
      }
      for (const [n, b] of motif(76, true)) bell(k, n, at(0, b), 0.9, 0)
      for (const [n, b, l] of motif(76, true)) stab(k, [n, n + 7], at(4, b), k.beat * l * 0.8, 0.6)
      for (let s = 0; s < 8; s++) snare(k, at(7, 2 + s / 4), 0.25 + s * 0.07)
      swell(k, at(7, 2), k.beat * 2, 0.9)
    },
  },
  // F major, 84 BPM. F - Dm - Bb - C: marimba drops, bamboo flute, soft shaker — weather, nature, environment.
  weather: {
    bpm: 84,
    bars: 8,
    write(k, at) {
      const chords = [
        [53, 57, 60],
        [50, 53, 57],
        [46, 50, 53],
        [48, 52, 55],
      ]
      const roots = [41, 38, 34, 36]
      const drops = [0, 2, 1, 2, 0, 1, 2, 1]
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        pad(k, c, at(bar, 0), k.beat * 4, 0.55, 1300)
        bass(k, roots[bar % 4], at(bar, 0), k.beat * 3.6, 0.55)
        for (let s = 0; s < 8; s++) {
          if (bar === 0 && s < 5) continue
          marimba(k, c[drops[s]] + 12 + (s === 7 ? 12 : 0), at(bar, s / 2), s % 2 ? 0.5 : 0.75, s % 2 ? 0.4 : -0.4, 0.35)
        }
        for (let s = 0; s < 8; s++) shaker(k, at(bar, s / 2 + 0.25), 0.3)
        kick(k, at(bar, 0), 0.4)
      }
      for (const [n, b] of motif(72, false)) marimba(k, n, at(0, b), 1.1, 0, 0.35)
      flute(k, 72, at(2, 0), k.beat * 2, 0.6, 69)
      flute(k, 74, at(2, 2), k.beat * 2, 0.55)
      flute(k, 76, at(6, 0), k.beat * 3, 0.6, 74)
    },
  },
  // A minor, 80 BPM. Am7 - Dm7 - G7 - Cmaj7, swung: warm e-piano, soft kick and snare — features, explainers.
  lofi: {
    bpm: 80,
    bars: 8,
    write(k, at) {
      const chords = [
        [57, 60, 64, 67],
        [50, 53, 57, 60],
        [55, 59, 62, 65],
        [48, 52, 55, 59],
      ]
      const swing = (b: number) => b + (b % 1 === 0.5 ? 0.08 : 0)
      for (let bar = 0; bar < 8; bar++) {
        const c = chords[bar % 4]
        for (const b of [0, 1.5, 2.5]) {
          if (bar === 0 && b === 0) continue
          c.forEach((n, i) => epiano(k, n, at(bar, swing(b)) + i * 0.015, k.beat * 0.9, b === 0 ? 0.6 : 0.45, i % 2 ? 0.2 : -0.2))
        }
        bass(k, c[0] - 12 - (c[0] >= 55 ? 12 : 0), at(bar, 0), k.beat * 1.6, 0.7)
        bass(k, c[0] - 5 - (c[0] >= 55 ? 12 : 0), at(bar, 2.5), k.beat * 1.2, 0.55)
        kick(k, at(bar, 0), 0.6)
        kick(k, at(bar, swing(2.5)), 0.45)
        snare(k, at(bar, 1), 0.35)
        snare(k, at(bar, 3), 0.35)
        for (let e = 0; e < 8; e++) hat(k, at(bar, swing(e / 2)), e % 2 ? 0.45 : 0.7)
      }
      for (const [n, b, l] of motif(69, true)) epiano(k, n + 12, at(0, b), k.beat * l, 0.9, 0)
      for (const [n, b] of motif(69, true)) bell(k, n + 12, at(4, b), 0.3, 0.25)
    },
  },
  // G, 100 BPM. Drone, plucked strings in a folk rhythm, tabla, flute — village, agriculture, folk culture.
  village: {
    bpm: 100,
    bars: 8,
    write(k, at) {
      for (let bar = 0; bar < 8; bar++) {
        drone(k, 43, at(bar, 0), 0.8, -0.3)
        drone(k, 50, at(bar, 2), 0.7, 0.3)
        const line = bar % 2 ? [67, 69, 71, 69, 67, 64, 62, 64] : [62, 64, 67, 69, 71, 69, 67, 64]
        line.forEach((n, i) => {
          if (bar === 0 && i < 4) return
          pluck(k, n, at(bar, i / 2), i % 2 ? 0.55 : 0.8, i % 2 ? 0.3 : -0.2, 0.55)
        })
        tabla(k, 'dha', at(bar, 0), 0.9)
        tabla(k, 'ge', at(bar, 0.75), 0.6)
        tabla(k, 'na', at(bar, 1), 0.7)
        tabla(k, 'ti', at(bar, 1.5), 0.5)
        tabla(k, 'dha', at(bar, 2), 0.8)
        tabla(k, 'dhi', at(bar, 2.75), 0.6)
        tabla(k, 'na', at(bar, 3), 0.7)
        tabla(k, 'ti', at(bar, 3.5), 0.5)
      }
      for (const [n, b] of motif(67, false)) pluck(k, n, at(0, b), 1.1, 0, 0.7)
      flute(k, 74, at(4, 0), k.beat * 2, 0.6, 71)
      flute(k, 71, at(4, 2), k.beat * 1, 0.5)
      flute(k, 69, at(4, 3), k.beat * 1, 0.5)
      flute(k, 67, at(5, 0), k.beat * 3, 0.55)
    },
  },
}

const cache = new Map<MusicId, Promise<AudioBuffer>>()

/** Loop length in seconds. */
export function musicLength(id: MusicId) {
  const p = PIECES[id]
  return (p.bars * 4 * 60) / p.bpm
}

/** The piece as a seamless stereo loop at SAMPLE_RATE, loudness-matched to the others. */
export function musicBuffer(id: MusicId): Promise<AudioBuffer> {
  let p = cache.get(id)
  if (!p) {
    p = renderPiece(id)
    cache.set(id, p)
    p.catch(() => cache.delete(id))
  }
  return p
}

const TAIL = 3.5
/** Every bed is scaled to this RMS (about -21 dBFS), so the volume slider means the same for all. */
const TARGET_RMS = 0.089

async function renderPiece(id: MusicId): Promise<AudioBuffer> {
  const piece = PIECES[id]
  const len = musicLength(id)
  const loopFrames = Math.round(len * SAMPLE_RATE)
  const ctx = new OfflineAudioContext(2, loopFrames + Math.ceil(TAIL * SAMPLE_RATE), SAMPLE_RATE)

  const master = ctx.createDynamicsCompressor()
  master.threshold.value = -16
  master.knee.value = 10
  master.ratio.value = 2.5
  master.attack.value = 0.01
  master.release.value = 0.25
  master.connect(ctx.destination)
  // Phone speakers can't play deep bass and it only eats headroom under a
  // voice, so the beds stay light at the bottom.
  const hp = ctx.createBiquadFilter()
  hp.type = 'highpass'
  hp.frequency.value = 60
  hp.Q.value = 0.6
  const shelf = ctx.createBiquadFilter()
  shelf.type = 'lowshelf'
  shelf.frequency.value = 160
  shelf.gain.value = -4
  hp.connect(shelf).connect(master)
  const dry = ctx.createGain()
  dry.connect(hp)
  const conv = ctx.createConvolver()
  conv.buffer = makeImpulse(ctx, 2.6)
  const revIn = ctx.createGain()
  revIn.gain.value = 0.6
  revIn.connect(conv).connect(hp)
  const echoIn = ctx.createGain()
  const delay = ctx.createDelay(2)
  const beat = 60 / piece.bpm
  delay.delayTime.value = beat * 0.75
  const fb = ctx.createGain()
  fb.gain.value = 0.32
  const dlp = ctx.createBiquadFilter()
  dlp.type = 'lowpass'
  dlp.frequency.value = 3200
  echoIn.connect(delay)
  delay.connect(dlp).connect(fb).connect(delay)
  const echoOut = ctx.createGain()
  echoOut.gain.value = 0.5
  dlp.connect(echoOut)
  echoOut.connect(dry)
  echoOut.connect(revIn)

  const kit: Kit = { ctx, dry, rev: revIn, echo: echoIn, noise: makeNoise(ctx, 6, 4242), beat }
  piece.write(kit, (bar, b) => (bar * 4 + b) * beat)
  const rendered = await ctx.startRendering()

  // fold the tail back onto the start: a seamless loop
  const outBuf = new AudioBuffer({ numberOfChannels: 2, length: loopFrames, sampleRate: SAMPLE_RATE })
  let sum = 0
  let peak = 0
  const chans: Float32Array<ArrayBuffer>[] = []
  for (let ch = 0; ch < 2; ch++) {
    const src = rendered.getChannelData(ch)
    const d = new Float32Array(loopFrames)
    d.set(src.subarray(0, loopFrames))
    for (let i = loopFrames; i < src.length; i++) d[i - loopFrames] += src[i]
    for (let i = 0; i < loopFrames; i++) {
      sum += d[i] * d[i]
      const a = Math.abs(d[i])
      if (a > peak) peak = a
    }
    chans.push(d)
  }
  const rms = Math.sqrt(sum / (loopFrames * 2)) || 1
  const gain = Math.min(TARGET_RMS / rms, 0.95 / (peak || 1))
  for (let ch = 0; ch < 2; ch++) {
    const d = chans[ch]
    for (let i = 0; i < loopFrames; i++) d[i] *= gain
    outBuf.copyToChannel(d, ch)
  }
  return outBuf
}
