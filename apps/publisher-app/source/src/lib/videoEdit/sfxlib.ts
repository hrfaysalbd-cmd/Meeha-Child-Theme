/**
 * Round 26 — Meeha's sound library: short sounds a newsroom uses again and
 * again (the Meeha sound logo, a breaking alert, a riser, a camera click…).
 *
 * Like the music beds, every sound is synthesised here with Web Audio from
 * oscillators and generated noise. There are no samples or recordings from
 * anyone else in them, so they are Meeha's own material.
 */
import type { MusicGroup } from './config'

export type BaseSfx = 'whoosh' | 'hit' | 'chime' | 'soft'
export type LibSfx = 'ident' | 'breaking' | 'riser' | 'impact' | 'ticking' | 'typing' | 'camera' | 'notify' | 'heartbeat' | 'reverse' | 'glitch' | 'pop' | 'cash' | 'drumroll'
export type SfxId = BaseSfx | LibSfx

export type SfxGroup = 'brand' | 'move' | 'news' | 'fx'

export const SFX_GROUPS: { id: SfxGroup; name: string; hint: string }[] = [
  { id: 'brand', name: 'মিহা ব্র্যান্ড সাউন্ড', hint: 'সাউন্ড লোগো, ব্রেকিং অ্যালার্ট' },
  { id: 'move', name: 'ট্রানজিশন', hint: 'হুশ, রাইজার, উল্টো সুইপ' },
  { id: 'news', name: 'সংবাদের আবহ', hint: 'ঘড়ি, টাইপিং, ক্যামেরা, নোটিফিকেশন' },
  { id: 'fx', name: 'ইফেক্ট', hint: 'ইমপ্যাক্ট, হার্টবিট, গ্লিচ, পপ' },
]

export const SFX: { id: SfxId; name: string; hint: string; group: SfxGroup; len: number }[] = [
  { id: 'ident', name: 'মিহা সাউন্ড লোগো', hint: 'চার-নোটের মিহা সুর, ঘণ্টা ও আঘাত — শুরু বা শেষে', group: 'brand', len: 2.8 },
  { id: 'breaking', name: 'ব্রেকিং অ্যালার্ট', hint: 'জরুরি বিপ-বিপ ও আঘাত', group: 'brand', len: 2.2 },
  { id: 'chime', name: 'চাইম', hint: 'নরম ঘণ্টা — শিরোনাম আসার সময়', group: 'brand', len: 1.7 },
  { id: 'whoosh', name: 'হুশ', hint: 'দ্রুত বাতাস — দৃশ্য বদলাতে', group: 'move', len: 0.8 },
  { id: 'soft', name: 'নরম হুশ', hint: 'হালকা বাতাস — লেখা আসতে', group: 'move', len: 0.6 },
  { id: 'riser', name: 'রাইজার', hint: 'উত্তেজনা বাড়ে — বড় তথ্যের আগে', group: 'move', len: 2.2 },
  { id: 'reverse', name: 'উল্টো সুইপ', hint: 'ধীরে ফুলে উঠে থামে — কাটের আগে', group: 'move', len: 1.4 },
  { id: 'ticking', name: 'ঘড়ির টিকটিক', hint: 'সময় গোনা, ডেডলাইন', group: 'news', len: 2.1 },
  { id: 'typing', name: 'টাইপিং', hint: 'কিবোর্ডে লেখা — নথি, বিবৃতি', group: 'news', len: 1.8 },
  { id: 'camera', name: 'ক্যামেরা ক্লিক', hint: 'ছবি তোলার শাটার', group: 'news', len: 0.5 },
  { id: 'notify', name: 'নোটিফিকেশন', hint: 'দুই সুরের টুং — নতুন তথ্য', group: 'news', len: 1.2 },
  { id: 'cash', name: 'টাকা-পয়সা', hint: 'কয়েনের টুংটাং — অর্থনীতি, দাম', group: 'news', len: 1.3 },
  { id: 'hit', name: 'আঘাত', hint: 'গভীর ধাক্কা — চমকে দেওয়া তথ্য', group: 'fx', len: 1 },
  { id: 'impact', name: 'বড় ইমপ্যাক্ট', hint: 'সিনেমার মতো গম্ভীর বুম', group: 'fx', len: 2.6 },
  { id: 'heartbeat', name: 'হার্টবিট', hint: 'দুটি হৃৎস্পন্দন — উত্তেজনা, অপরাধ', group: 'fx', len: 1.6 },
  { id: 'drumroll', name: 'ড্রামরোল', hint: 'ঘোষণার আগে স্নেয়ার রোল', group: 'fx', len: 2.3 },
  { id: 'glitch', name: 'গ্লিচ', hint: 'ডিজিটাল ঝিলিক — প্রযুক্তি, সাইবার', group: 'fx', len: 0.7 },
  { id: 'pop', name: 'পপ', hint: 'ছোট্ট টুপ — স্টিকার বা লেখা ফুটে উঠতে', group: 'fx', len: 0.35 },
]

export const SFX_IDS = SFX.map((s) => s.id)
export const sfxLength = (id: SfxId) => SFX.find((s) => s.id === id)?.len ?? 1.7

/** The music library and this sound library share the group look in the panel. */
export type LibraryGroup = MusicGroup | SfxGroup

function noise(ctx: BaseAudioContext, seconds: number, seed = 9) {
  const b = ctx.createBuffer(1, Math.max(1, Math.ceil(seconds * ctx.sampleRate)), ctx.sampleRate)
  const d = b.getChannelData(0)
  let s = seed
  for (let i = 0; i < d.length; i++) {
    s = (s * 1103515245 + 12345) & 0x7fffffff
    d[i] = (s / 0x7fffffff) * 2 - 1
  }
  return b
}

const hz = (n: number) => 440 * Math.pow(2, (n - 69) / 12)

function tone(ctx: BaseAudioContext, out: AudioNode, f: number, when: number, peak: number, attack: number, decay: number, type: OscillatorType = 'sine', glideTo?: number) {
  const o = ctx.createOscillator()
  o.type = type
  o.frequency.setValueAtTime(f, when)
  if (glideTo) o.frequency.exponentialRampToValueAtTime(glideTo, when + attack + decay)
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, when)
  g.gain.linearRampToValueAtTime(peak, when + attack)
  g.gain.exponentialRampToValueAtTime(0.0001, when + attack + decay)
  o.connect(g).connect(out)
  o.start(when)
  o.stop(when + attack + decay + 0.05)
}

function burst(ctx: BaseAudioContext, out: AudioNode, when: number, dur: number, type: BiquadFilterType, freq: number, q: number, peak: number, attack = 0.001, seed = 3, freqTo?: number) {
  const src = ctx.createBufferSource()
  src.buffer = noise(ctx, dur + attack + 0.05, seed)
  const f = ctx.createBiquadFilter()
  f.type = type
  f.frequency.setValueAtTime(freq, when)
  if (freqTo) f.frequency.exponentialRampToValueAtTime(freqTo, when + attack + dur)
  f.Q.value = q
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, when)
  g.gain.linearRampToValueAtTime(peak, when + attack)
  g.gain.exponentialRampToValueAtTime(0.0001, when + attack + dur)
  src.connect(f).connect(g).connect(out)
  src.start(when)
}

/** FM bell, the brand lead. */
function bell(ctx: BaseAudioContext, out: AudioNode, n: number, when: number, peak: number) {
  const f = hz(n)
  const car = ctx.createOscillator()
  car.frequency.value = f
  const mod = ctx.createOscillator()
  mod.frequency.value = f * 3.5
  const mg = ctx.createGain()
  mg.gain.setValueAtTime(f * 2.2, when)
  mg.gain.exponentialRampToValueAtTime(f * 0.08, when + 1.2)
  mod.connect(mg).connect(car.frequency)
  const g = ctx.createGain()
  g.gain.setValueAtTime(0.0001, when)
  g.gain.linearRampToValueAtTime(peak, when + 0.004)
  g.gain.exponentialRampToValueAtTime(0.0001, when + 1.8)
  car.connect(g).connect(out)
  car.start(when)
  mod.start(when)
  car.stop(when + 1.9)
  mod.stop(when + 1.9)
}

function boom(ctx: BaseAudioContext, out: AudioNode, when: number, peak: number, from = 120, to = 34, decay = 1.2) {
  tone(ctx, out, from, when, peak, 0.005, decay, 'sine', to)
  burst(ctx, out, when, 0.25, 'lowpass', 1200, 0.6, peak * 0.35, 0.002, 21)
}

/** Schedules one library sound into `out` at `when`. Returns false for the four base sounds (audio.ts draws them). */
export function synthLib(ctx: BaseAudioContext, out: AudioNode, kind: SfxId, when: number, gain: number): boolean {
  const g = ctx.createGain()
  g.gain.value = gain
  g.connect(out)
  switch (kind) {
    case 'ident': {
      // the Meeha motif 5-1-2-3 on bells (D major), a soft hit under the last note, a shimmer
      const notes: [number, number][] = [
        [69, 0],
        [74, 0.16],
        [76, 0.32],
        [78, 0.52],
      ]
      for (const [n, t] of notes) bell(ctx, g, n + 12, when + t, t === 0.52 ? 0.16 : 0.11)
      bell(ctx, g, 78, when + 0.52, 0.06)
      boom(ctx, g, when + 0.52, 0.45, 110, 40, 1.1)
      burst(ctx, g, when + 0.5, 1.6, 'highpass', 7000, 0.6, 0.05, 0.3, 12)
      tone(ctx, g, hz(62), when + 0.52, 0.06, 0.2, 1.8, 'triangle')
      return true
    }
    case 'breaking': {
      for (let i = 0; i < 4; i++) {
        tone(ctx, g, i % 2 ? 1320 : 990, when + i * 0.16, 0.12, 0.004, 0.13, 'square')
      }
      boom(ctx, g, when + 0.7, 0.7, 150, 38, 1.2)
      burst(ctx, g, when + 0.7, 0.6, 'bandpass', 2500, 0.8, 0.12, 0.002, 4, 600)
      return true
    }
    case 'riser': {
      const o = ctx.createOscillator()
      o.type = 'sawtooth'
      o.frequency.setValueAtTime(110, when)
      o.frequency.exponentialRampToValueAtTime(880, when + 2)
      const lp = ctx.createBiquadFilter()
      lp.type = 'lowpass'
      lp.frequency.setValueAtTime(400, when)
      lp.frequency.exponentialRampToValueAtTime(5000, when + 2)
      const og = ctx.createGain()
      og.gain.setValueAtTime(0.0001, when)
      og.gain.exponentialRampToValueAtTime(0.09, when + 1.95)
      og.gain.linearRampToValueAtTime(0.0001, when + 2.05)
      o.connect(lp).connect(og).connect(g)
      o.start(when)
      o.stop(when + 2.1)
      burst(ctx, g, when, 2, 'highpass', 1500, 0.7, 0.09, 1.9, 7, 9000)
      return true
    }
    case 'reverse': {
      const src = ctx.createBufferSource()
      src.buffer = noise(ctx, 1.4, 31)
      const hp = ctx.createBiquadFilter()
      hp.type = 'bandpass'
      hp.frequency.setValueAtTime(800, when)
      hp.frequency.exponentialRampToValueAtTime(6000, when + 1.3)
      hp.Q.value = 0.8
      const sg = ctx.createGain()
      sg.gain.setValueAtTime(0.0001, when)
      sg.gain.exponentialRampToValueAtTime(0.22, when + 1.3)
      sg.gain.linearRampToValueAtTime(0.0001, when + 1.36)
      src.connect(hp).connect(sg).connect(g)
      src.start(when)
      return true
    }
    case 'ticking': {
      for (let i = 0; i < 4; i++) {
        const t = when + i * 0.5
        tone(ctx, g, i % 2 ? 2600 : 3100, t, 0.09, 0.001, 0.035, 'square')
        burst(ctx, g, t, 0.03, 'bandpass', 4000, 3, 0.08, 0.001, 40 + i)
      }
      return true
    }
    case 'typing': {
      let seed = 5
      const r = () => ((seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff)
      let t = when
      for (let i = 0; i < 14; i++) {
        burst(ctx, g, t, 0.03, 'bandpass', 2500 + r() * 2500, 2, 0.12 + r() * 0.08, 0.001, 50 + i)
        tone(ctx, g, 180 + r() * 60, t, 0.05, 0.001, 0.03, 'triangle')
        t += 0.07 + r() * 0.08
      }
      tone(ctx, g, 1760, t + 0.05, 0.06, 0.002, 0.35)
      return true
    }
    case 'camera': {
      burst(ctx, g, when, 0.04, 'bandpass', 3500, 1.5, 0.35, 0.001, 61)
      burst(ctx, g, when + 0.09, 0.07, 'bandpass', 2200, 1.2, 0.3, 0.001, 62)
      tone(ctx, g, 900, when + 0.09, 0.05, 0.001, 0.05, 'square')
      return true
    }
    case 'notify': {
      tone(ctx, g, hz(81), when, 0.14, 0.004, 0.5)
      tone(ctx, g, hz(88), when + 0.13, 0.14, 0.004, 0.9)
      tone(ctx, g, hz(100), when + 0.13, 0.03, 0.004, 0.5)
      return true
    }
    case 'cash': {
      for (let i = 0; i < 5; i++) {
        const t = when + i * 0.07 + (i > 2 ? 0.12 : 0)
        tone(ctx, g, 2400 + i * 330, t, 0.06, 0.001, 0.5)
        tone(ctx, g, (2400 + i * 330) * 2.7, t, 0.025, 0.001, 0.25)
      }
      bell(ctx, g, 96, when + 0.45, 0.07)
      return true
    }
    case 'impact': {
      boom(ctx, g, when, 0.95, 90, 26, 2.3)
      burst(ctx, g, when, 1.8, 'lowpass', 600, 0.7, 0.22, 0.003, 71, 120)
      burst(ctx, g, when, 0.4, 'highpass', 3000, 0.6, 0.12, 0.001, 72)
      return true
    }
    case 'heartbeat': {
      for (const t of [0, 0.2, 0.85, 1.05]) boom(ctx, g, when + t, t === 0 || t === 0.85 ? 0.7 : 0.45, 70, 38, 0.25)
      return true
    }
    case 'drumroll': {
      for (let i = 0; i < 28; i++) {
        const t = when + i * 0.065
        burst(ctx, g, t, 0.08, 'bandpass', 2000, 0.7, 0.04 + (i / 28) * 0.1, 0.001, 80 + i)
      }
      boom(ctx, g, when + 1.85, 0.5, 130, 45, 0.45)
      burst(ctx, g, when + 1.85, 0.45, 'highpass', 5000, 0.5, 0.12, 0.001, 79)
      return true
    }
    case 'glitch': {
      for (let i = 0; i < 6; i++) {
        const t = when + i * 0.09
        tone(ctx, g, [220, 1760, 440, 3520, 330, 880][i], t, 0.07, 0.001, 0.06, 'square')
        burst(ctx, g, t + 0.03, 0.04, 'highpass', 4000, 0.5, 0.08, 0.001, 90 + i)
      }
      return true
    }
    case 'pop': {
      tone(ctx, g, 500, when, 0.25, 0.002, 0.12, 'sine', 1400)
      burst(ctx, g, when, 0.03, 'bandpass', 3000, 1, 0.08, 0.001, 99)
      return true
    }
    default:
      return false
  }
}
