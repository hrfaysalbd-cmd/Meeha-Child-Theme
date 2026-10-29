/**
 * Round 21 — turns an EditConfig into (1) the output canvas layout and
 * (2) a flat list of segments on the output timeline:
 *
 *   [intro] [main …] [ad-in card] [ad] [ad-out card] [main …] … [outro]
 *
 * Both the live preview and the MP4 export walk the same segments, so the
 * preview is exactly what gets rendered.
 */
import { AD_IN_CARD, AD_OUT_CARD, FRAME_SIZES, INTROS, OUTROS, keptRanges, type EditConfig } from './config'

export type ClipKey = 'main' | 'intro' | 'outro' | 'pip' | `ad${number}`
export type SceneId = 'breaking' | 'headline' | 'special' | 'swipe' | 'live' | 'countdown' | 'thanks' | 'follow' | 'ad-in' | 'ad-out'

export interface Segment {
  kind: 'intro' | 'outro' | 'main' | 'ad' | 'card'
  /** Start on the output timeline (s). */
  t0: number
  dur: number
  /** A video file plays (from clipStart), or a drawn scene. */
  clip?: ClipKey
  clipStart: number
  scene?: SceneId
  /** main only: seconds of main content shown before this segment. */
  mainOffset: number
  /** Source seconds per output second: 1 normal, 0.5 slow motion, 2 fast, 0 a held (frozen) frame. */
  rate: number
  /** Text shown over a frozen frame. */
  freezeText?: string
}

/** Source second shown at `local` seconds into a segment. */
export function segSource(seg: Segment, local: number) {
  return seg.clipStart + Math.max(0, local) * seg.rate
}

/** Last source second a segment shows. */
export function segSourceEnd(seg: Segment) {
  return seg.clipStart + seg.dur * seg.rate
}

export interface Timeline {
  segments: Segment[]
  duration: number
  /** Output time where main content starts (frame entrance, lower thirds). */
  mainStart: number
  mainDuration: number
}

export interface Rect {
  x: number
  y: number
  w: number
  h: number
}

export interface Layout {
  W: number
  H: number
  /** Scale unit of the frame bands: 1 at a 1080-pixel shorter side, × the frame size. */
  s: number
  /** The frame-size factor alone (captions use it too). */
  k: number
  header: Rect | null
  footer: Rect | null
  video: Rect
}

const FIXED: Record<string, [number, number]> = {
  '16:9': [1920, 1080],
  '9:16': [1080, 1920],
  '1:1': [1080, 1080],
  '4:5': [1080, 1350],
}

const even = (n: number) => Math.max(2, Math.round(n / 2) * 2)

export const HEADER_UNITS = 210
export const FOOTER_UNITS = 118

/**
 * Output size + where the header band, video and footer band go.
 * `srcW/srcH` = the main video's display size.
 */
/**
 * Frame-size factor. "auto" makes the bands bigger on tall videos: a reel
 * is watched full-screen on a phone, where a band sized for a TV frame
 * looks tiny.
 */
export function frameScale(cfg: EditConfig, W: number, H: number) {
  const fixed = FRAME_SIZES.find((x) => x.id === cfg.frame.size)?.k ?? 0
  if (fixed > 0) return fixed
  const r = H / W
  return r >= 1.5 ? 1.4 : r >= 1.1 ? 1.2 : 1
}

export function computeLayout(cfg: EditConfig, srcW0: number, srcH0: number): Layout {
  const short = cfg.quality === '720' ? 720 : 1080
  const hasHeader = cfg.frame.id !== 'none'
  const hasFooter = hasHeader && cfg.frame.footer !== 'none'
  // a sideways phone video turned upright swaps its sides
  const turned = cfg.look.rotate === 90 || cfg.look.rotate === 270
  const srcW = Math.max(2, turned ? srcH0 : srcW0)
  const srcH = Math.max(2, turned ? srcW0 : srcH0)

  if (cfg.format === 'source') {
    const kk = Math.min(1, short / Math.min(srcW, srcH), (short * 16) / 9 / Math.max(srcW, srcH))
    const vw = even(srcW * kk)
    const vh = even(srcH * kk)
    // judge "tall" by the finished picture, bands included
    const k = frameScale(cfg, vw, vh + (hasHeader ? HEADER_UNITS : 0) * (Math.min(vw, vh) / 1080))
    const s = (Math.min(vw, vh) / 1080) * k
    const hh = hasHeader ? even(HEADER_UNITS * s) : 0
    const fh = hasFooter ? even(FOOTER_UNITS * s) : 0
    return {
      W: vw,
      H: vh + hh + fh,
      s,
      k,
      header: hasHeader ? { x: 0, y: 0, w: vw, h: hh } : null,
      footer: hasFooter ? { x: 0, y: hh + vh, w: vw, h: fh } : null,
      video: { x: 0, y: hh, w: vw, h: vh },
    }
  }

  const [fw, fh0] = FIXED[cfg.format]
  const q = short / 1080
  const W = even(fw * q)
  const H = even(fh0 * q)
  const k = frameScale(cfg, W, H)
  const s = (Math.min(W, H) / 1080) * k
  const hh = hasHeader ? even(HEADER_UNITS * s) : 0
  const fh = hasFooter ? even(FOOTER_UNITS * s) : 0
  return {
    W,
    H,
    s,
    k,
    header: hasHeader ? { x: 0, y: 0, w: W, h: hh } : null,
    footer: hasFooter ? { x: 0, y: H - fh, w: W, h: fh } : null,
    video: { x: 0, y: hh, w: W, h: H - hh - fh },
  }
}

export interface ClipDurations {
  main: number
  intro?: number | null
  outro?: number | null
  ads?: (number | null)[]
}

/** The trimmed main range in source seconds. */
export function trimRange(cfg: EditConfig, mainDuration: number) {
  const start = Math.min(Math.max(0, cfg.trim.start), Math.max(0, mainDuration - 0.5))
  const endRaw = cfg.trim.end ?? mainDuration
  const end = Math.max(start + 0.5, Math.min(mainDuration, endRaw))
  return { start, end }
}

export function buildTimeline(cfg: EditConfig, d: ClipDurations): Timeline {
  const segs: Segment[] = []
  let t = 0
  const push = (s: Omit<Segment, 't0' | 'mainOffset' | 'rate'> & { mainOffset?: number; rate?: number }) => {
    if (s.dur <= 0.01) return
    segs.push({ ...s, t0: t, mainOffset: s.mainOffset ?? 0, rate: s.rate ?? 1 })
    t += s.dur
  }

  // Intro
  if (cfg.intro.id !== 'none') {
    const info = INTROS.find((i) => i.id === cfg.intro.id)
    if (info?.duration) push({ kind: 'intro', dur: info.duration, scene: cfg.intro.id as SceneId, clipStart: 0 })
    else if (d.intro) push({ kind: 'intro', dur: d.intro, clip: 'intro', clipStart: 0 })
  }

  // Main: the kept ranges (trim minus cuts), split again at the ad breaks
  const { start, end } = trimRange(cfg, d.main)
  const mainStart = t
  const kept = keptRanges(cfg.cuts, start, end)
  const breaks = cfg.ads
    .map((a, i) => ({ ...a, i, dur: d.ads?.[i] ?? a.clip.duration ?? 0 }))
    .filter((a) => a.dur > 0 && a.at > start + 0.25 && a.at < end - 0.25 && kept.some((r) => a.at > r.from + 0.1 && a.at < r.to - 0.1))
    .sort((a, b) => a.at - b.at)

  // speed changes and held frames split the kept ranges further
  const rateAt = (src: number) => cfg.speeds.find((x) => src >= x.from && src < x.to)?.rate ?? 1
  const freezes = [...cfg.freezes].sort((a, b) => a.at - b.at)

  let shown = 0
  const used = new Set<number>()
  for (const r of kept) {
    const edges = [r.from, r.to]
    for (const x of cfg.speeds) edges.push(x.from, x.to)
    for (const br of breaks) if (br.at > r.from + 0.1 && br.at < r.to - 0.1) edges.push(br.at)
    for (const f of freezes) edges.push(f.at)
    const cuts = [...new Set(edges.filter((e) => e >= r.from && e <= r.to).map((e) => Math.round(e * 1000) / 1000))].sort((a, b) => a - b)
    for (let k = 0; k < cuts.length; k++) {
      const a = cuts[k]
      // what happens at this point: a held frame, then an ad break
      for (const f of freezes) {
        if (Math.abs(f.at - a) < 0.0015 && a < r.to - 0.05 && !used.has(f.at)) {
          used.add(f.at)
          push({ kind: 'main', dur: f.dur, clip: 'main', clipStart: a, mainOffset: shown, rate: 0, freezeText: f.text })
          shown += f.dur
        }
      }
      const br = breaks.find((x) => Math.abs(x.at - a) < 0.0015 && a > r.from + 0.1 && a < r.to - 0.1)
      if (br) {
        if (br.bumper) push({ kind: 'card', dur: AD_IN_CARD, scene: 'ad-in', clipStart: 0 })
        push({ kind: 'ad', dur: br.dur, clip: `ad${br.i}`, clipStart: 0 })
        if (br.bumper) push({ kind: 'card', dur: AD_OUT_CARD, scene: 'ad-out', clipStart: 0 })
      }
      const b = cuts[k + 1]
      if (b === undefined || b - a < 0.02) continue
      const rate = rateAt((a + b) / 2)
      const dur = (b - a) / rate
      push({ kind: 'main', dur, clip: 'main', clipStart: a, mainOffset: shown, rate })
      shown += dur
    }
  }

  // Outro
  if (cfg.outro.id !== 'none') {
    const info = OUTROS.find((i) => i.id === cfg.outro.id)
    if (info?.duration) push({ kind: 'outro', dur: info.duration, scene: cfg.outro.id as SceneId, clipStart: 0 })
    else if (d.outro) push({ kind: 'outro', dur: d.outro, clip: 'outro', clipStart: 0 })
  }

  return { segments: segs, duration: t, mainStart, mainDuration: shown }
}

/** The segment playing at output time `t` (the last one at/after the end). */
export function segmentAt(tl: Timeline, t: number): Segment {
  const segs = tl.segments
  for (let i = 0; i < segs.length; i++) {
    if (t < segs[i].t0 + segs[i].dur) return segs[i]
  }
  return segs[segs.length - 1]
}

/** Source-video time → output time (first main segment that shows it), or null. */
export function sourceToOutput(tl: Timeline, src: number): number | null {
  for (const s of tl.segments) {
    if (s.kind === 'main' && s.rate > 0 && src >= s.clipStart - 1e-3 && src <= segSourceEnd(s) + 1e-3) return s.t0 + (src - s.clipStart) / s.rate
  }
  return null
}

/** First output time that shows main source second `src` or anything after it. */
export function outputAtOrAfter(tl: Timeline, src: number): number | null {
  const exact = sourceToOutput(tl, src)
  if (exact !== null) return exact
  for (const s of tl.segments) if (s.kind === 'main' && s.clipStart >= src) return s.t0
  return null
}

/** Every file the finished video needs: the segments' clips, plus the second video if it ever shows. */
export function clipsUsed(tl: Timeline, cfg: EditConfig): Set<ClipKey> {
  const used = new Set(tl.segments.map((s) => s.clip).filter(Boolean) as ClipKey[])
  const p = cfg.pip
  if (p?.clip) {
    const end = p.dur === null ? Infinity : p.at + p.dur
    if (tl.segments.some((s) => s.kind === 'main' && s.clipStart < end && segSourceEnd(s) > p.at)) used.add('pip')
  }
  return used
}

/** Whether the second video (picture-in-picture / split) shows at main source second `src`. */
export function pipActive(cfg: EditConfig, src: number) {
  const p = cfg.pip
  return !!p?.clip && src >= p.at && (p.dur === null || src < p.at + p.dur)
}

/** Seconds into the second video at output time T (it starts where it first appears). */
export function pipClipTime(tl: Timeline, cfg: EditConfig, T: number) {
  const p = cfg.pip
  if (!p) return 0
  const start = outputAtOrAfter(tl, p.at)
  const len = p.clip?.duration ?? Infinity
  return Math.max(0, Math.min(T - (start ?? 0), Number.isFinite(len) ? Math.max(0, len - 0.05) : Infinity))
}

/** Output time → source-video time when a main segment is playing. */
export function outputToSource(tl: Timeline, t: number): number | null {
  const s = segmentAt(tl, t)
  return s.kind === 'main' ? segSource(s, Math.min(s.dur, t - s.t0)) : null
}
