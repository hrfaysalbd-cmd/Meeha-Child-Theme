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
  // round 27
  | 'sting' | 'transition' | 'swipeup' | 'siren' | 'thunder' | 'rain' | 'applause' | 'success' | 'buzzer' | 'sparkle'
  // round 46
  | 'whoosh-fast' | 'whoosh-deep' | 'whoosh-air' | 'riser-long' | 'boom' | 'glitch-data' | 'typewriter' | 'shutter' | 'ping' | 'clock'
  | 'heart-fast' | 'siren-soft' | 'crowd' | 'paper' | 'swoosh-up' | 'bassdrop' | 'magic' | 'stamp'
export type SfxId = BaseSfx | LibSfx

export type SfxGroup = 'brand' | 'move' | 'news' | 'fx' | 'texture'

export const SFX_GROUPS: { id: SfxGroup; name: string; hint: string }[] = [
  { id: 'brand', name: 'মিহা ব্র্যান্ড সাউন্ড', hint: 'সাউন্ড লোগো, ব্রেকিং অ্যালার্ট' },
  { id: 'move', name: 'ট্রানজিশন', hint: 'হুশ, রাইজার, উল্টো সুইপ' },
  { id: 'news', name: 'সংবাদের আবহ', hint: 'ঘড়ি, টাইপিং, ক্যামেরা, নোটিফিকেশন' },
  { id: 'fx', name: 'ইফেক্ট', hint: 'ইমপ্যাক্ট, হার্টবিট, গ্লিচ, পপ' },
  { id: 'texture', name: 'আবহ ও টেক্সচার (নতুন)', hint: 'ভিড়ের গুঞ্জন, কাগজ, ঘড়ি, হৃৎস্পন্দন' },
]

export const SFX: { id: SfxId; name: string; hint: string; group: SfxGroup; len: number; isNew?: boolean }[] = [
  { id: 'ident', name: 'মিহা সাউন্ড লোগো', hint: 'চার-নোটের মিহা সুর, ঘণ্টা ও আঘাত — শুরু বা শেষে', group: 'brand', len: 2.8 },
  { id: 'breaking', name: 'ব্রেকিং অ্যালার্ট', hint: 'জরুরি বিপ-বিপ ও আঘাত', group: 'brand', len: 2.2 },
  { id: 'chime', name: 'চাইম', hint: 'নরম ঘণ্টা — শিরোনাম আসার সময়', group: 'brand', len: 1.7 },
  { id: 'sting', name: 'নিউজ স্টিং', hint: 'ব্রাসের মতো জোরালো কর্ড ও আঘাত — শিরোনামের ঝটকা', group: 'brand', len: 1.8, isNew: true },
  { id: 'whoosh', name: 'হুশ', hint: 'দ্রুত বাতাস — দৃশ্য বদলাতে', group: 'move', len: 0.8 },
  { id: 'soft', name: 'নরম হুশ', hint: 'হালকা বাতাস — লেখা আসতে', group: 'move', len: 0.6 },
  { id: 'riser', name: 'রাইজার', hint: 'উত্তেজনা বাড়ে — বড় তথ্যের আগে', group: 'move', len: 2.2 },
  { id: 'reverse', name: 'উল্টো সুইপ', hint: 'ধীরে ফুলে উঠে থামে — কাটের আগে', group: 'move', len: 1.4 },
  { id: 'transition', name: 'সিনেমাটিক ট্রানজিশন', hint: 'রাইজার, হুশ আর গম্ভীর বুম একসাথে', group: 'move', len: 2.4, isNew: true },
  { id: 'swipeup', name: 'সোয়াইপ আপ', hint: 'দ্রুত ওপরে ওঠা ঝিলিক — লেখা বা ছবি আসতে', group: 'move', len: 0.5, isNew: true },
  { id: 'ticking', name: 'ঘড়ির টিকটিক', hint: 'সময় গোনা, ডেডলাইন', group: 'news', len: 2.1 },
  { id: 'typing', name: 'টাইপিং', hint: 'কিবোর্ডে লেখা — নথি, বিবৃতি', group: 'news', len: 1.8 },
  { id: 'camera', name: 'ক্যামেরা ক্লিক', hint: 'ছবি তোলার শাটার', group: 'news', len: 0.5 },
  { id: 'notify', name: 'নোটিফিকেশন', hint: 'দুই সুরের টুং — নতুন তথ্য', group: 'news', len: 1.2 },
  { id: 'cash', name: 'টাকা-পয়সা', hint: 'কয়েনের টুংটাং — অর্থনীতি, দাম', group: 'news', len: 1.3 },
  { id: 'siren', name: 'সাইরেন', hint: 'ওঠানামা করা সাইরেন — দুর্ঘটনা, অপরাধ, উদ্ধার', group: 'news', len: 2.6, isNew: true },
  { id: 'thunder', name: 'বজ্রপাত', hint: 'কড়কড় শব্দ আর গুমগুম — ঝড়, আবহাওয়া', group: 'news', len: 3, isNew: true },
  { id: 'rain', name: 'বৃষ্টি', hint: 'ঝিরঝির বৃষ্টি — বর্ষা, বন্যা, আবহাওয়া', group: 'news', len: 3, isNew: true },
  { id: 'applause', name: 'হাততালি', hint: 'ছোট দলের হাততালি — পুরস্কার, সাফল্য', group: 'news', len: 2.6, isNew: true },
  { id: 'hit', name: 'আঘাত', hint: 'গভীর ধাক্কা — চমকে দেওয়া তথ্য', group: 'fx', len: 1 },
  { id: 'impact', name: 'বড় ইমপ্যাক্ট', hint: 'সিনেমার মতো গম্ভীর বুম', group: 'fx', len: 2.6 },
  { id: 'heartbeat', name: 'হার্টবিট', hint: 'দুটি হৃৎস্পন্দন — উত্তেজনা, অপরাধ', group: 'fx', len: 1.6 },
  { id: 'drumroll', name: 'ড্রামরোল', hint: 'ঘোষণার আগে স্নেয়ার রোল', group: 'fx', len: 2.3 },
  { id: 'glitch', name: 'গ্লিচ', hint: 'ডিজিটাল ঝিলিক — প্রযুক্তি, সাইবার', group: 'fx', len: 0.7 },
  { id: 'pop', name: 'পপ', hint: 'ছোট্ট টুপ — স্টিকার বা লেখা ফুটে উঠতে', group: 'fx', len: 0.35 },
  { id: 'success', name: 'সঠিক / টিক', hint: 'দুই সুরে ওঠা টুং — সত্য, সঠিক তথ্য', group: 'fx', len: 1, isNew: true },
  { id: 'buzzer', name: 'ভুল / বাজার', hint: 'নিচু ব্যাঁ — গুজব, ভুল তথ্য', group: 'fx', len: 0.8, isNew: true },
  { id: 'sparkle', name: 'ঝিকমিক', hint: 'উঁচু ছোট ঘণ্টার ঝিকমিক — বিনোদন, উৎসব', group: 'fx', len: 1.6, isNew: true },
  // round 46: professional studio pack
  { id: 'whoosh-fast', name: 'দ্রুত হুশ', hint: 'ঝটপট বাতাস — দ্রুত কাট, লেখা ছুটে আসা', group: 'move', len: 0.45, isNew: true },
  { id: 'whoosh-deep', name: 'গভীর হুশ', hint: 'ভারী, নিচু বাতাস — বড় দৃশ্য বদল', group: 'move', len: 1.1, isNew: true },
  { id: 'whoosh-air', name: 'বাতাসের হুশ', hint: 'নরম, চওড়া বাতাস — শান্ত ট্রানজিশন', group: 'move', len: 1.2, isNew: true },
  { id: 'riser-long', name: 'লম্বা রাইজার', hint: '৪ সেকেন্ড ধরে উত্তেজনা বাড়ে — বড় ঘোষণার আগে', group: 'move', len: 4.2, isNew: true },
  { id: 'swoosh-up', name: 'ওপরে সুশ', hint: 'নিচ থেকে ওপরে ঝলক — শিরোনাম বা গ্রাফ উঠতে', group: 'move', len: 0.7, isNew: true },
  { id: 'boom', name: 'সিনেমাটিক বুম', hint: 'গভীর বুম ও লম্বা গুমগুম — বড় তথ্য', group: 'fx', len: 3, isNew: true },
  { id: 'bassdrop', name: 'বেস ড্রপ', hint: 'নেমে যাওয়া গভীর বেস — রিলের মোড় ঘোরানো মুহূর্ত', group: 'fx', len: 1.6, isNew: true },
  { id: 'glitch-data', name: 'ডেটা গ্লিচ', hint: 'ডিজিটাল কড়কড় ও বিপ — সাইবার, ফাঁস হওয়া তথ্য', group: 'fx', len: 0.9, isNew: true },
  { id: 'magic', name: 'জাদুর ঝিলিক', hint: 'ওপরে ওঠা ঝিকমিক — চমক, প্রকাশ', group: 'fx', len: 1.8, isNew: true },
  { id: 'stamp', name: 'সিলমোহর', hint: 'ভারী সিলের ঠক — অনুমোদিত, যাচাই করা, ভোট', group: 'fx', len: 0.6, isNew: true },
  { id: 'typewriter', name: 'টাইপরাইটার', hint: 'পুরোনো টাইপরাইটার, শেষে ঘণ্টা ও রোলার', group: 'news', len: 2.2, isNew: true },
  { id: 'shutter', name: 'ক্যামেরা শাটার (সিরিজ)', hint: 'পরপর তিনটি শাটার — সংবাদ সম্মেলন', group: 'news', len: 0.9, isNew: true },
  { id: 'ping', name: 'নোটিফিকেশন পিং', hint: 'এক সুরের পরিষ্কার টুং — নতুন আপডেট', group: 'news', len: 0.9, isNew: true },
  { id: 'siren-soft', name: 'নরম সাইরেন', hint: 'দূরের, দুই সুরের মৃদু সাইরেন — ভয় না দেখিয়ে জরুরি ভাব', group: 'news', len: 2.6, isNew: true },
  { id: 'clock', name: 'টিক-টক ঘড়ি', hint: 'ধীর, গম্ভীর দেয়ালঘড়ি — অপেক্ষা, সময়সীমা', group: 'texture', len: 3, isNew: true },
  { id: 'heart-fast', name: 'দ্রুত হৃৎস্পন্দন', hint: 'বাড়তে থাকা হৃৎস্পন্দন — উত্তেজনা, উদ্ধার', group: 'texture', len: 2.4, isNew: true },
  { id: 'crowd', name: 'ভিড়ের গুঞ্জন', hint: 'মানুষের মৃদু গুঞ্জন — সমাবেশ, বাজার, সভা', group: 'texture', len: 3.2, isNew: true },
  { id: 'paper', name: 'কাগজ ওল্টানো', hint: 'পাতা ওল্টানোর খসখস — নথি, রিপোর্ট, বাজেট', group: 'texture', len: 0.8, isNew: true },
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
    // ---- round 27 ----
    case 'sting': {
      // a brass-like D minor stab (saws through a closing low-pass), doubled an octave down, on a hit
      const notes = [62, 65, 69, 74]
      const lp = ctx.createBiquadFilter()
      lp.type = 'lowpass'
      lp.frequency.setValueAtTime(4200, when)
      lp.frequency.exponentialRampToValueAtTime(600, when + 1.2)
      const sg = ctx.createGain()
      sg.gain.setValueAtTime(0.0001, when)
      sg.gain.linearRampToValueAtTime(0.09, when + 0.02)
      sg.gain.exponentialRampToValueAtTime(0.0001, when + 1.5)
      lp.connect(sg).connect(g)
      for (const n of [...notes, notes[0] - 12]) {
        for (const det of [-8, 8]) {
          const o = ctx.createOscillator()
          o.type = 'sawtooth'
          o.frequency.value = hz(n)
          o.detune.value = det
          o.connect(lp)
          o.start(when)
          o.stop(when + 1.6)
        }
      }
      boom(ctx, g, when, 0.6, 140, 40, 0.9)
      burst(ctx, g, when, 0.5, 'highpass', 6000, 0.6, 0.08, 0.002, 101)
      return true
    }
    case 'transition': {
      burst(ctx, g, when, 1.4, 'bandpass', 600, 0.9, 0.16, 1.3, 111, 7000)
      tone(ctx, g, 160, when, 0.06, 1.3, 0.2, 'sawtooth', 900)
      burst(ctx, g, when + 1.4, 0.5, 'bandpass', 3000, 0.7, 0.2, 0.01, 112, 400)
      boom(ctx, g, when + 1.42, 0.8, 100, 30, 1)
      return true
    }
    case 'swipeup': {
      tone(ctx, g, 400, when, 0.24, 0.01, 0.2, 'triangle', 2400)
      burst(ctx, g, when, 0.25, 'bandpass', 1500, 1.2, 0.24, 0.03, 121, 8000)
      return true
    }
    case 'siren': {
      const o = ctx.createOscillator()
      o.type = 'triangle'
      const f = o.frequency
      f.setValueAtTime(700, when)
      for (let i = 0; i < 4; i++) {
        f.linearRampToValueAtTime(1300, when + i * 0.6 + 0.3)
        f.linearRampToValueAtTime(700, when + i * 0.6 + 0.6)
      }
      const og = ctx.createGain()
      og.gain.setValueAtTime(0.0001, when)
      og.gain.linearRampToValueAtTime(0.12, when + 0.1)
      og.gain.setValueAtTime(0.12, when + 2.2)
      og.gain.linearRampToValueAtTime(0.0001, when + 2.5)
      o.connect(og).connect(g)
      o.start(when)
      o.stop(when + 2.55)
      return true
    }
    case 'thunder': {
      burst(ctx, g, when, 0.25, 'highpass', 2500, 0.5, 0.35, 0.002, 131)
      burst(ctx, g, when + 0.08, 0.35, 'bandpass', 1200, 0.6, 0.3, 0.002, 132)
      burst(ctx, g, when + 0.15, 2.6, 'lowpass', 300, 0.7, 0.5, 0.15, 133, 60)
      boom(ctx, g, when + 0.2, 0.5, 70, 28, 2.4)
      return true
    }
    case 'rain': {
      const src = ctx.createBufferSource()
      src.buffer = noise(ctx, 3.1, 141)
      const bp = ctx.createBiquadFilter()
      bp.type = 'bandpass'
      bp.frequency.value = 4500
      bp.Q.value = 0.4
      const rg = ctx.createGain()
      rg.gain.setValueAtTime(0.0001, when)
      rg.gain.linearRampToValueAtTime(0.16, when + 0.6)
      rg.gain.setValueAtTime(0.16, when + 2.3)
      rg.gain.linearRampToValueAtTime(0.0001, when + 3)
      src.connect(bp).connect(rg).connect(g)
      src.start(when)
      let seed = 7
      const r = () => ((seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff)
      for (let i = 0; i < 40; i++) {
        const t = when + 0.2 + r() * 2.6
        tone(ctx, g, 1800 + r() * 2200, t, 0.03 + r() * 0.03, 0.001, 0.03, 'sine', 900)
      }
      return true
    }
    case 'applause': {
      let seed = 11
      const r = () => ((seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff)
      for (let i = 0; i < 90; i++) {
        const t = when + r() * 2.3
        const fade = t - when > 1.7 ? 1 - (t - when - 1.7) / 0.6 : Math.min(1, (t - when) / 0.3 + 0.3)
        burst(ctx, g, t, 0.025, 'bandpass', 1100 + r() * 1600, 1.4, (0.27 + r() * 0.2) * Math.max(0, fade), 0.001, 150 + i)
      }
      return true
    }
    case 'success': {
      tone(ctx, g, hz(84), when, 0.13, 0.004, 0.35)
      tone(ctx, g, hz(91), when + 0.12, 0.14, 0.004, 0.8)
      tone(ctx, g, hz(96), when + 0.12, 0.04, 0.004, 0.6)
      return true
    }
    case 'buzzer': {
      for (const f of [150, 155]) tone(ctx, g, f, when, 0.08, 0.01, 0.65, 'square', 110)
      return true
    }
    case 'sparkle': {
      let seed = 3
      const r = () => ((seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff)
      for (let i = 0; i < 12; i++) {
        const t = when + i * 0.08 + r() * 0.04
        const n = 88 + Math.floor(r() * 12)
        tone(ctx, g, hz(n), t, 0.1, 0.002, 0.4)
        tone(ctx, g, hz(n) * 2.01, t, 0.03, 0.002, 0.25)
      }
      burst(ctx, g, when, 1.2, 'highpass', 9000, 0.5, 0.03, 0.2, 161)
      return true
    }
    // ---- round 46 ----
    case 'whoosh-fast': {
      burst(ctx, g, when, 0.32, 'bandpass', 700, 1.1, 0.5, 0.09, 201, 5200)
      burst(ctx, g, when + 0.05, 0.22, 'highpass', 3000, 0.6, 0.12, 0.05, 202)
      return true
    }
    case 'whoosh-deep': {
      burst(ctx, g, when, 0.95, 'lowpass', 300, 0.9, 0.55, 0.42, 211, 1600)
      burst(ctx, g, when, 0.9, 'bandpass', 500, 0.8, 0.22, 0.45, 212, 2400)
      tone(ctx, g, 70, when + 0.35, 0.18, 0.12, 0.6, 'sine', 45)
      return true
    }
    case 'whoosh-air': {
      const src = ctx.createBufferSource()
      src.buffer = noise(ctx, 1.3, 221)
      const bp = ctx.createBiquadFilter()
      bp.type = 'bandpass'
      bp.Q.value = 0.5
      bp.frequency.setValueAtTime(500, when)
      bp.frequency.exponentialRampToValueAtTime(3500, when + 0.6)
      bp.frequency.exponentialRampToValueAtTime(900, when + 1.15)
      const pan = ctx.createStereoPanner()
      pan.pan.setValueAtTime(-0.7, when)
      pan.pan.linearRampToValueAtTime(0.7, when + 1.1)
      const sg = ctx.createGain()
      sg.gain.setValueAtTime(0.0001, when)
      sg.gain.linearRampToValueAtTime(0.32, when + 0.6)
      sg.gain.linearRampToValueAtTime(0.0001, when + 1.15)
      src.connect(bp).connect(sg).connect(pan).connect(g)
      src.start(when)
      return true
    }
    case 'riser-long': {
      for (const [f0, det] of [[80, -10], [80, 10], [160, 0]] as const) {
        const o = ctx.createOscillator()
        o.type = 'sawtooth'
        o.detune.value = det
        o.frequency.setValueAtTime(f0, when)
        o.frequency.exponentialRampToValueAtTime(f0 * 8, when + 4)
        const lp = ctx.createBiquadFilter()
        lp.type = 'lowpass'
        lp.frequency.setValueAtTime(300, when)
        lp.frequency.exponentialRampToValueAtTime(6000, when + 4)
        const og = ctx.createGain()
        og.gain.setValueAtTime(0.0001, when)
        og.gain.exponentialRampToValueAtTime(0.05, when + 3.9)
        og.gain.linearRampToValueAtTime(0.0001, when + 4.05)
        o.connect(lp).connect(og).connect(g)
        o.start(when)
        o.stop(when + 4.1)
      }
      burst(ctx, g, when, 4, 'highpass', 1200, 0.7, 0.1, 3.9, 231, 10000)
      return true
    }
    case 'swoosh-up': {
      tone(ctx, g, 300, when, 0.16, 0.02, 0.45, 'triangle', 2600)
      tone(ctx, g, 600, when + 0.02, 0.05, 0.02, 0.4, 'sine', 4800)
      burst(ctx, g, when, 0.5, 'bandpass', 900, 1, 0.3, 0.2, 241, 9000)
      return true
    }
    case 'boom': {
      boom(ctx, g, when, 1, 75, 24, 2.8)
      burst(ctx, g, when, 2.6, 'lowpass', 400, 0.7, 0.3, 0.004, 251, 80)
      burst(ctx, g, when, 0.12, 'bandpass', 1800, 0.8, 0.2, 0.001, 252)
      return true
    }
    case 'bassdrop': {
      tone(ctx, g, 220, when, 0.5, 0.01, 1.4, 'sine', 32)
      tone(ctx, g, 110, when, 0.25, 0.01, 1.4, 'triangle', 28)
      burst(ctx, g, when, 0.3, 'lowpass', 900, 0.8, 0.2, 0.002, 261)
      return true
    }
    case 'glitch-data': {
      let seed = 271
      const r = () => ((seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff)
      for (let i = 0; i < 14; i++) {
        const t = when + i * 0.055 + r() * 0.02
        if (r() > 0.45) tone(ctx, g, 200 + Math.floor(r() * 12) * 300, t, 0.06, 0.001, 0.035, 'square')
        else burst(ctx, g, t, 0.03, 'bandpass', 1500 + r() * 6000, 2, 0.14, 0.001, 272 + i)
      }
      tone(ctx, g, 2093, when + 0.8, 0.05, 0.001, 0.08, 'square')
      return true
    }
    case 'magic': {
      for (let i = 0; i < 14; i++) {
        const t = when + i * 0.07
        const n = 79 + i * 1.5
        tone(ctx, g, hz(n), t, 0.07, 0.003, 0.5)
        tone(ctx, g, hz(n) * 3, t, 0.015, 0.003, 0.3)
      }
      burst(ctx, g, when, 1.4, 'highpass', 8000, 0.5, 0.05, 0.7, 281)
      bell(ctx, g, 96, when + 1, 0.05)
      return true
    }
    case 'stamp': {
      tone(ctx, g, 150, when, 0.6, 0.002, 0.18, 'sine', 60)
      burst(ctx, g, when, 0.08, 'lowpass', 1400, 0.8, 0.55, 0.001, 291)
      burst(ctx, g, when + 0.01, 0.05, 'bandpass', 3200, 1.5, 0.15, 0.001, 292)
      return true
    }
    case 'typewriter': {
      let seed = 301
      const r = () => ((seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff)
      let t = when
      for (let i = 0; i < 11; i++) {
        burst(ctx, g, t, 0.025, 'bandpass', 1800 + r() * 900, 3, 0.3, 0.001, 302 + i)
        tone(ctx, g, 120 + r() * 30, t, 0.1, 0.001, 0.04, 'triangle')
        burst(ctx, g, t + 0.012, 0.02, 'highpass', 5000, 0.8, 0.08, 0.001, 320 + i)
        t += 0.09 + r() * 0.06
      }
      bell(ctx, g, 100, t + 0.05, 0.12)
      burst(ctx, g, t + 0.3, 0.5, 'bandpass', 900, 0.7, 0.12, 0.25, 331, 2200)
      return true
    }
    case 'shutter': {
      for (let i = 0; i < 3; i++) {
        const t = when + i * 0.25
        burst(ctx, g, t, 0.03, 'bandpass', 4000, 1.6, 0.32, 0.001, 341 + i)
        burst(ctx, g, t + 0.06, 0.05, 'bandpass', 2400, 1.3, 0.26, 0.001, 345 + i)
        tone(ctx, g, 1100, t + 0.06, 0.04, 0.001, 0.04, 'square')
      }
      return true
    }
    case 'ping': {
      tone(ctx, g, hz(93), when, 0.16, 0.002, 0.7)
      tone(ctx, g, hz(93) * 2.76, when, 0.03, 0.002, 0.25)
      tone(ctx, g, hz(81), when, 0.04, 0.002, 0.5)
      return true
    }
    case 'siren-soft': {
      // two soft tones, a fourth apart, far away (low-passed) — urgent without alarm
      const o = ctx.createOscillator()
      o.type = 'sine'
      for (let i = 0; i < 4; i++) {
        o.frequency.setValueAtTime(660, when + i * 0.6)
        o.frequency.setValueAtTime(880, when + i * 0.6 + 0.3)
      }
      const lp = ctx.createBiquadFilter()
      lp.type = 'lowpass'
      lp.frequency.value = 1400
      const og = ctx.createGain()
      og.gain.setValueAtTime(0.0001, when)
      og.gain.linearRampToValueAtTime(0.07, when + 0.6)
      og.gain.setValueAtTime(0.07, when + 1.8)
      og.gain.linearRampToValueAtTime(0.0001, when + 2.5)
      o.connect(lp).connect(og).connect(g)
      o.start(when)
      o.stop(when + 2.55)
      return true
    }
    case 'clock': {
      for (let i = 0; i < 6; i++) {
        const t = when + i * 0.5
        tone(ctx, g, i % 2 ? 1250 : 950, t, 0.12, 0.001, 0.06, 'triangle')
        burst(ctx, g, t, 0.04, 'bandpass', i % 2 ? 2600 : 1900, 4, 0.12, 0.001, 351 + i)
        tone(ctx, g, 90, t, 0.06, 0.002, 0.12)
      }
      return true
    }
    case 'heart-fast': {
      let t = 0
      for (let i = 0; i < 6; i++) {
        const gap = 0.5 - i * 0.045
        boom(ctx, g, when + t, 0.55 + i * 0.05, 72, 38, 0.22)
        boom(ctx, g, when + t + 0.16, 0.35 + i * 0.04, 66, 36, 0.2)
        t += gap
      }
      return true
    }
    case 'crowd': {
      // murmur: many band-passed noise voices with slow syllable-like swells
      for (let v = 0; v < 8; v++) {
        const src = ctx.createBufferSource()
        src.buffer = noise(ctx, 3.3, 361 + v)
        const bp = ctx.createBiquadFilter()
        bp.type = 'bandpass'
        bp.frequency.value = 350 + v * 110
        bp.Q.value = 3
        const vg = ctx.createGain()
        vg.gain.setValueAtTime(0.0001, when)
        for (let k = 0; k < 12; k++) {
          const t = when + 0.1 + k * 0.25 + ((v * 37 + k * 13) % 10) * 0.012
          vg.gain.linearRampToValueAtTime(0.06 + ((v + k) % 3) * 0.03, t)
          vg.gain.linearRampToValueAtTime(0.02, t + 0.12)
        }
        vg.gain.linearRampToValueAtTime(0.0001, when + 3.2)
        const pan = ctx.createStereoPanner()
        pan.pan.value = (v / 7) * 1.4 - 0.7
        src.connect(bp).connect(vg).connect(pan).connect(g)
        src.start(when)
      }
      return true
    }
    case 'paper': {
      burst(ctx, g, when, 0.18, 'bandpass', 3000, 0.7, 0.18, 0.06, 371, 6000)
      burst(ctx, g, when + 0.2, 0.25, 'bandpass', 4500, 0.6, 0.22, 0.04, 372, 2000)
      burst(ctx, g, when + 0.45, 0.08, 'highpass', 2500, 0.6, 0.12, 0.002, 373)
      return true
    }
    default:
      return false
  }
}
