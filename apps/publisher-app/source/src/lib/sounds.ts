/**
 * r35 — three short sounds, made in the browser (no files to download):
 *
 *   inbox — soft two-note "ding-dong": something waits in the ইনবক্স (queue, approvals…)
 *   post  — three quick bright pings: a watched profile posted (পোস্ট এলার্ট)
 *   video — a rising "whoosh" and a deep two-tone bell: a watched profile posted a video
 *   chat  — r36: a soft rising "pop-pop": a new chat message
 *   urgent — r36: a firm two-tone call, twice: a জরুরি chat message (plays even in a muted room)
 *
 * Browsers only allow sound after the person has clicked or tapped the page
 * once, so `unlockSounds()` is called on the first click. Each sound can be
 * switched off; the choice is kept on this device.
 */

export type SoundKind = 'inbox' | 'post' | 'video' | 'chat' | 'urgent'

export interface SoundPrefs {
  inbox: boolean
  post: boolean
  video: boolean
  chat: boolean
  urgent: boolean
  /** 0..1 */
  volume: number
}

const KEY = 'nros_sound_prefs'
const DEFAULTS: SoundPrefs = { inbox: true, post: true, video: true, chat: true, urgent: true, volume: 0.8 }

export function loadSoundPrefs(): SoundPrefs {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return { ...DEFAULTS }
    const p = JSON.parse(raw) as Partial<SoundPrefs>
    return {
      inbox: p.inbox !== false,
      post: p.post !== false,
      video: p.video !== false,
      chat: p.chat !== false,
      urgent: p.urgent !== false,
      volume: typeof p.volume === 'number' ? Math.min(1, Math.max(0, p.volume)) : DEFAULTS.volume,
    }
  } catch {
    return { ...DEFAULTS }
  }
}

export function saveSoundPrefs(p: SoundPrefs) {
  try {
    localStorage.setItem(KEY, JSON.stringify(p))
  } catch {
    // private mode: the choice lasts until reload
  }
}

let ctx: AudioContext | null = null

function audio(): AudioContext | null {
  try {
    if (!ctx) {
      const AC = window.AudioContext || (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
      if (!AC) return null
      ctx = new AC()
    }
    if (ctx.state === 'suspended') void ctx.resume()
    return ctx
  } catch {
    return null
  }
}

/** Call from the first click/tap so later sounds are allowed to play. */
export function unlockSounds() {
  const c = audio()
  if (!c) return
  // A silent blip makes iOS Safari treat the context as started by the person.
  const o = c.createOscillator()
  const g = c.createGain()
  g.gain.value = 0
  o.connect(g).connect(c.destination)
  o.start()
  o.stop(c.currentTime + 0.01)
}

const hz = (midi: number) => 440 * Math.pow(2, (midi - 69) / 12)

/** One bell-like note: a sine plus a quieter overtone, quick attack, soft fade. */
function bell(c: AudioContext, out: AudioNode, t: number, midi: number, len: number, level: number, type: OscillatorType = 'sine', over = 2.76) {
  for (const [m, a] of [
    [1, 1],
    [over, 0.22],
  ] as const) {
    const o = c.createOscillator()
    o.type = type
    o.frequency.value = hz(midi) * m
    const g = c.createGain()
    g.gain.setValueAtTime(0.0001, t)
    g.gain.linearRampToValueAtTime(level * a, t + 0.006)
    g.gain.exponentialRampToValueAtTime(0.0001, t + len)
    o.connect(g).connect(out)
    o.start(t)
    o.stop(t + len + 0.05)
  }
}

export function playSound(kind: SoundKind, volume = loadSoundPrefs().volume) {
  const c = audio()
  if (!c || volume <= 0) return
  try {
    const out = c.createGain()
    out.gain.value = volume
    out.connect(c.destination)
    const t = c.currentTime + 0.03
    if (kind === 'inbox') {
      // ding-dong (E6 → C6), calm
      bell(c, out, t, 88, 0.9, 0.2)
      bell(c, out, t + 0.22, 84, 1.3, 0.2)
    } else if (kind === 'post') {
      // ping-ping-ping (A6), bright and short
      for (let i = 0; i < 3; i++) bell(c, out, t + i * 0.11, 93, 0.35, 0.16, 'triangle', 3.0)
    } else if (kind === 'chat') {
      // pop-pop (C6 → G6), short and friendly
      bell(c, out, t, 84, 0.22, 0.18, 'sine', 2.0)
      bell(c, out, t + 0.09, 91, 0.32, 0.18, 'sine', 2.0)
    } else if (kind === 'urgent') {
      // two-tone call (A5 / E6), played twice — hard to miss
      for (let r = 0; r < 2; r++) {
        const b = t + r * 0.62
        bell(c, out, b, 81, 0.26, 0.2, 'triangle', 2.0)
        bell(c, out, b + 0.16, 88, 0.26, 0.2, 'triangle', 2.0)
        bell(c, out, b + 0.32, 81, 0.3, 0.2, 'triangle', 2.0)
      }
    } else {
      // rising whoosh, then a deep two-tone bell (G4 + D5)
      const len = 0.38
      const noise = c.createBuffer(1, Math.floor(c.sampleRate * len), c.sampleRate)
      const d = noise.getChannelData(0)
      for (let i = 0; i < d.length; i++) d[i] = (Math.random() * 2 - 1) * (i / d.length)
      const src = c.createBufferSource()
      src.buffer = noise
      const bp = c.createBiquadFilter()
      bp.type = 'bandpass'
      bp.Q.value = 1.4
      bp.frequency.setValueAtTime(400, t)
      bp.frequency.exponentialRampToValueAtTime(3200, t + len)
      const ng = c.createGain()
      ng.gain.setValueAtTime(0.0001, t)
      ng.gain.linearRampToValueAtTime(0.22, t + len * 0.85)
      ng.gain.exponentialRampToValueAtTime(0.0001, t + len + 0.05)
      src.connect(bp).connect(ng).connect(out)
      src.start(t)
      src.stop(t + len + 0.06)
      bell(c, out, t + len, 67, 1.4, 0.24, 'sine', 2.0)
      bell(c, out, t + len + 0.16, 74, 1.5, 0.2, 'sine', 2.0)
    }
  } catch {
    // no sound here: the badge and the pop-up still show
  }
}

export const SOUND_LABEL: Record<SoundKind, string> = {
  inbox: 'ইনবক্স (কিউ, অনুমোদন, আবেদন…)',
  post: 'পোস্ট এলার্ট',
  video: 'ভিডিও এলার্ট',
  chat: 'চ্যাট মেসেজ',
  urgent: 'জরুরি চ্যাট মেসেজ',
}
