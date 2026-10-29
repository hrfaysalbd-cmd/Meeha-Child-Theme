/**
 * Round 21 — live preview of an edit. Plays the real files in hidden
 * <video> elements (one per clip) and paints every animation frame with
 * the same drawOutputFrame() the exporter uses, so what plays here is what
 * gets rendered. Sound comes from the elements themselves (at the edit's
 * volumes) plus the synthesised effects and the brand music bed. The bed
 * dips by a fixed amount under the main video here; the exact mix (real
 * ducking, voice clean-up, noise reduction) is heard with "শুনে দেখুন",
 * which renders it the way the export does.
 *
 * The preview never needs the file bytes, so cross-origin files play
 * straight from the site; only the export downloads them.
 */
import type { DrawEnv, Picture } from './draw'
import { drawOutputFrame, sfxCues } from './draw'
import { playSfx } from './audio'
import { musicBuffer } from './music'
import type { MusicChoice } from './config'
import { segmentAt, type ClipKey, type Segment } from './timeline'

export interface PreviewState {
  time: number
  playing: boolean
  waiting: boolean
}

export class PreviewPlayer {
  private canvas: HTMLCanvasElement
  private ctx: CanvasRenderingContext2D
  private env: DrawEnv | null = null
  private els = new Map<ClipKey, HTMLVideoElement>()
  private urls: Partial<Record<ClipKey, string>> = {}
  private raf = 0
  private T = 0
  private playing = false
  private dirty = true
  private wallStart = 0
  private tStart = 0
  private seg: Segment | null = null
  private audio: AudioContext | null = null
  private lastCueT = 0
  private onState: (s: PreviewState) => void
  private lastReport = 0
  private waiting = false
  private musicBuf: AudioBuffer | null = null
  private musicId = ''
  private musicSrc: AudioBufferSourceNode | null = null
  private musicGain: GainNode | null = null
  private muted = false

  constructor(canvas: HTMLCanvasElement, onState: (s: PreviewState) => void) {
    this.canvas = canvas
    this.ctx = canvas.getContext('2d')!
    this.onState = onState
    this.raf = requestAnimationFrame(this.loop)
  }

  /** New settings / layout / timeline (keeps the play position). */
  setEnv(env: DrawEnv) {
    const resized = !this.env || this.env.L.W !== env.L.W || this.env.L.H !== env.L.H
    this.env = env
    if (resized) {
      const k = Math.min(1, 1280 / Math.max(env.L.W, env.L.H))
      this.canvas.width = Math.round(env.L.W * k)
      this.canvas.height = Math.round(env.L.H * k)
    }
    if (this.T > env.tl.duration) this.T = Math.max(0, env.tl.duration - 0.05)
    this.loadMusic(env.cfg.audio.music)
    // the segment layout may have changed under the playhead
    if (this.playing) this.enter(this.T)
    this.dirty = true
  }

  setSources(urls: Partial<Record<ClipKey, string>>) {
    for (const [key, url] of Object.entries(urls) as [ClipKey, string][]) {
      if (this.urls[key] === url) continue
      this.urls[key] = url
      let el = this.els.get(key)
      if (!el) {
        el = document.createElement('video')
        el.playsInline = true
        el.preload = 'auto'
        el.addEventListener('seeked', () => (this.dirty = true))
        el.addEventListener('loadeddata', () => (this.dirty = true))
        el.addEventListener('waiting', () => (this.waiting = true))
        el.addEventListener('playing', () => (this.waiting = false))
        this.els.set(key, el)
      }
      el.src = url
    }
    for (const key of [...this.els.keys()]) {
      if (!urls[key]) {
        const el = this.els.get(key)!
        el.pause()
        el.removeAttribute('src')
        el.load()
        this.els.delete(key)
        delete this.urls[key]
      }
    }
    this.dirty = true
  }

  get time() {
    return this.T
  }

  private volumeFor(seg: Segment) {
    const a = this.env!.cfg.audio
    return Math.min(1, Math.max(0, seg.kind === 'main' ? a.main : a.clips))
  }

  /** Starts the segment under T (when playing). */
  private enter(T: number) {
    const env = this.env
    if (!env) return
    const seg = segmentAt(env.tl, T)
    for (const [k, el] of this.els) if (k !== seg.clip) el.pause()
    this.seg = seg
    this.tStart = T
    this.wallStart = performance.now()
    if (seg.clip) {
      const el = this.els.get(seg.clip)
      if (el) {
        const want = seg.clipStart + (T - seg.t0)
        if (Math.abs(el.currentTime - want) > 0.08) el.currentTime = want
        el.volume = this.volumeFor(seg)
        el.muted = this.muted
        el.play().catch(() => {
          // autoplay refused (no gesture yet): play muted instead
          el.muted = true
          el.play().catch(() => undefined)
        })
      }
    }
  }

  play() {
    if (!this.env || this.playing) return
    if (this.T >= this.env.tl.duration - 0.05) this.T = 0
    if (!this.audio && typeof AudioContext !== 'undefined') this.audio = new AudioContext()
    this.audio?.resume().catch(() => undefined)
    this.playing = true
    this.lastCueT = this.T - 0.001
    this.enter(this.T)
    this.startMusic()
    this.report(true)
  }

  /** Silences the clips, effects and music (while a rendered soundtrack plays instead). */
  setMuted(m: boolean) {
    this.muted = m
    for (const el of this.els.values()) el.muted = m
    if (m) this.stopMusic()
    else if (this.playing) this.startMusic()
  }

  /** The shared AudioContext (created on first play). */
  audioContext() {
    if (!this.audio && typeof AudioContext !== 'undefined') this.audio = new AudioContext()
    return this.audio
  }

  private loadMusic(id: MusicChoice) {
    if (id === this.musicId) {
      this.updateMusicGain()
      return
    }
    this.musicId = id
    this.musicBuf = null
    this.stopMusic()
    if (id === 'none') return
    musicBuffer(id)
      .then((b) => {
        if (this.musicId !== id) return
        this.musicBuf = b
        if (this.playing) this.startMusic()
      })
      .catch(() => undefined)
  }

  private musicLevel() {
    const env = this.env
    if (!env || !this.seg) return 0
    const a = env.cfg.audio
    if (a.music === 'none') return 0
    if (a.musicOver === 'main' && this.seg.kind !== 'main') return 0
    const speech = this.seg.kind === 'main' ? a.main > 0 : a.clips > 0
    const fade = Math.min(1, Math.max(0, (env.tl.duration - this.T) / 2.5))
    return a.musicVol * (a.duck && speech ? 0.5 : 1) * fade
  }

  private startMusic() {
    this.stopMusic()
    const ctx = this.audio
    if (!ctx || !this.musicBuf || this.muted || !this.playing) return
    const src = ctx.createBufferSource()
    src.buffer = this.musicBuf
    src.loop = true
    const g = ctx.createGain()
    g.gain.value = 0
    g.gain.setTargetAtTime(this.musicLevel(), ctx.currentTime, 0.08)
    src.connect(g).connect(ctx.destination)
    src.start(0, this.T % this.musicBuf.duration)
    this.musicSrc = src
    this.musicGain = g
  }

  private stopMusic() {
    if (this.musicSrc) {
      try {
        this.musicSrc.stop()
      } catch {
        // already stopped
      }
      this.musicSrc.disconnect()
    }
    this.musicGain?.disconnect()
    this.musicSrc = null
    this.musicGain = null
  }

  private updateMusicGain() {
    if (this.musicGain && this.audio) this.musicGain.gain.setTargetAtTime(this.musicLevel(), this.audio.currentTime, 0.12)
  }

  pause() {
    this.playing = false
    this.stopMusic()
    for (const el of this.els.values()) el.pause()
    this.dirty = true
    this.report(true)
  }

  toggle() {
    if (this.playing) this.pause()
    else this.play()
  }

  seek(T: number) {
    if (!this.env) return
    this.T = Math.min(Math.max(0, T), Math.max(0, this.env.tl.duration - 0.001))
    const seg = segmentAt(this.env.tl, this.T)
    if (this.playing) {
      this.lastCueT = this.T
      this.enter(this.T)
      this.startMusic()
    } else if (seg.clip) {
      const el = this.els.get(seg.clip)
      if (el) el.currentTime = seg.clipStart + (this.T - seg.t0)
    }
    this.dirty = true
    this.report(true)
  }

  private report(force = false) {
    const now = performance.now()
    if (!force && now - this.lastReport < 100) return
    this.lastReport = now
    this.onState({ time: this.T, playing: this.playing, waiting: this.playing && this.waiting })
  }

  private loop = () => {
    this.raf = requestAnimationFrame(this.loop)
    const env = this.env
    if (!env) return
    if (this.playing) {
      const seg = this.seg ?? segmentAt(env.tl, this.T)
      const el = seg.clip ? this.els.get(seg.clip) : undefined
      let T: number
      if (el && el.readyState >= 2) T = seg.t0 + (el.currentTime - seg.clipStart)
      else T = this.tStart + (performance.now() - this.wallStart) / 1000
      // a clip that ended early (shorter than its metadata said) moves on too
      const ended = el ? el.ended : false
      if (T >= seg.t0 + seg.dur - 0.02 || ended) {
        const next = seg.t0 + seg.dur
        if (next >= env.tl.duration - 0.01) {
          this.T = env.tl.duration - 0.001
          this.draw()
          this.pause()
          return
        }
        this.T = next
        this.enter(next)
      } else {
        this.T = Math.max(this.T, T)
      }
      this.playCues(env)
      this.updateMusicGain()
      this.draw()
      this.report()
    } else if (this.dirty) {
      this.draw()
      this.dirty = false
    }
  }

  private playCues(env: DrawEnv) {
    if (!this.audio || this.muted) return
    for (const c of sfxCues(env.tl, env.cfg)) {
      if (c.t > this.lastCueT && c.t <= this.T + 0.02) playSfx(this.audio, c.kind, c.gain)
    }
    this.lastCueT = this.T + 0.02
  }

  /** Paints the current time. */
  draw() {
    const env = this.env
    if (!env) return
    const seg = segmentAt(env.tl, this.T)
    let pic: Picture | null = null
    if (seg.clip) {
      const el = this.els.get(seg.clip)
      if (el && el.readyState >= 2 && el.videoWidth) pic = { src: el, w: el.videoWidth, h: el.videoHeight }
    }
    const k = this.canvas.width / env.L.W
    this.ctx.setTransform(k, 0, 0, k, 0, 0)
    drawOutputFrame(this.ctx, env, seg, this.T, pic)
    this.ctx.setTransform(1, 0, 0, 1, 0, 0)
  }

  destroy() {
    cancelAnimationFrame(this.raf)
    for (const el of this.els.values()) {
      el.pause()
      el.removeAttribute('src')
      el.load()
    }
    this.els.clear()
    this.stopMusic()
    this.audio?.close().catch(() => undefined)
  }
}

/** Duration and size of a playable URL (exact, not rounded). */
export function probeUrl(url: string, timeoutMs = 20_000): Promise<{ duration: number; width: number; height: number } | null> {
  return new Promise((resolve) => {
    const el = document.createElement('video')
    el.preload = 'metadata'
    el.muted = true
    let done = false
    const finish = (v: { duration: number; width: number; height: number } | null) => {
      if (done) return
      done = true
      clearTimeout(timer)
      el.removeAttribute('src')
      el.load()
      resolve(v)
    }
    const timer = setTimeout(() => finish(null), timeoutMs)
    el.onloadedmetadata = () => {
      if (!Number.isFinite(el.duration) || el.duration <= 0) return finish(null)
      finish({ duration: el.duration, width: el.videoWidth, height: el.videoHeight })
    }
    el.onerror = () => finish(null)
    el.src = url
  })
}
