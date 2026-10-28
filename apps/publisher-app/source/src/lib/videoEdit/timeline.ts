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

export type ClipKey = 'main' | 'intro' | 'outro' | `ad${number}`
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
  const push = (s: Omit<Segment, 't0' | 'mainOffset'> & { mainOffset?: number }) => {
    if (s.dur <= 0.01) return
    segs.push({ ...s, t0: t, mainOffset: s.mainOffset ?? 0 })
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

  let shown = 0
  let b = 0
  for (const r of kept) {
    let cursor = r.from
    while (b < breaks.length && breaks[b].at < r.to - 0.1) {
      const br = breaks[b++]
      if (br.at <= cursor + 0.1) continue
      push({ kind: 'main', dur: br.at - cursor, clip: 'main', clipStart: cursor, mainOffset: shown })
      shown += br.at - cursor
      cursor = br.at
      if (br.bumper) push({ kind: 'card', dur: AD_IN_CARD, scene: 'ad-in', clipStart: 0 })
      push({ kind: 'ad', dur: br.dur, clip: `ad${br.i}`, clipStart: 0 })
      if (br.bumper) push({ kind: 'card', dur: AD_OUT_CARD, scene: 'ad-out', clipStart: 0 })
    }
    push({ kind: 'main', dur: r.to - cursor, clip: 'main', clipStart: cursor, mainOffset: shown })
    shown += r.to - cursor
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
    if (s.kind === 'main' && src >= s.clipStart - 1e-3 && src <= s.clipStart + s.dur + 1e-3) return s.t0 + (src - s.clipStart)
  }
  return null
}

/** Output time → source-video time when a main segment is playing. */
export function outputToSource(tl: Timeline, t: number): number | null {
  const s = segmentAt(tl, t)
  return s.kind === 'main' ? s.clipStart + Math.min(s.dur, Math.max(0, t - s.t0)) : null
}
