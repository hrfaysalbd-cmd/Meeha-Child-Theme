/**
 * Round 26 — "নীরব অংশ কাটুন": finds the pauses in the main video's sound
 * (where nobody speaks) so they can be cut out in one go, with a transition
 * at every join.
 *
 * It works on the timeline's waveform (one RMS value per 1/20 s, already
 * read from the downloaded file). The threshold adapts to each video: it
 * sits between the background level (a low percentile) and the speech
 * level (a high percentile), so a noisy street report and a quiet studio
 * clip are both handled. A pause is cut only when it is longer than
 * `minSilence`, and `pad` seconds of it are kept on both sides so words
 * are never clipped.
 */
import type { Cut } from './config'
import type { Waveform } from './analyse'

export type AutoCutSensitivity = 'low' | 'medium' | 'high'

export interface AutoCutOptions {
  /** How eagerly quiet stretches count as silence. */
  sensitivity: AutoCutSensitivity
  /** Shortest pause that is cut (s). */
  minSilence: number
  /** Kept on each side of a pause (s). */
  pad: number
}

export const AUTOCUT_DEFAULTS: AutoCutOptions = { sensitivity: 'medium', minSilence: 0.7, pad: 0.15 }

const SPLIT: Record<AutoCutSensitivity, number> = { low: 0.22, medium: 0.33, high: 0.45 }

export interface AutoCutResult {
  cuts: Cut[]
  /** Seconds removed. */
  removed: number
  /** The silence threshold that was used (dBFS), for the hint. */
  thresholdDb: number
  /** False when the clip has no clear speech/silence contrast (music all through, or silent). */
  usable: boolean
}

function percentile(sorted: number[], p: number) {
  if (!sorted.length) return -90
  const i = Math.min(sorted.length - 1, Math.max(0, Math.round((sorted.length - 1) * p)))
  return sorted[i]
}

/** Pauses inside [start, end] (source seconds) that can be cut. */
export function findSilences(wave: Waveform, start: number, end: number, o: AutoCutOptions = AUTOCUT_DEFAULTS): AutoCutResult {
  const step = wave.step
  const i0 = Math.max(0, Math.floor(start / step))
  const i1 = Math.min(wave.peaks.length, Math.ceil(end / step))
  // the waveform is stored as 0..1 on a −54…−6 dB scale; back to dB
  const db: number[] = []
  for (let i = i0; i < i1; i++) db.push(wave.peaks[i] * 48 - 54)
  if (db.length < 4) return { cuts: [], removed: 0, thresholdDb: -54, usable: false }

  // smooth a little (150 ms) so a single quiet consonant is not a "pause"
  const sm = db.map((_, i) => {
    let s = 0
    let n = 0
    for (let k = i - 1; k <= i + 1; k++) {
      if (k < 0 || k >= db.length) continue
      s += db[k]
      n++
    }
    return s / n
  })
  const sorted = [...sm].sort((a, b) => a - b)
  const floor = percentile(sorted, 0.1)
  const speech = percentile(sorted, 0.85)
  const contrast = speech - floor
  const threshold = Math.max(floor + 4, floor + contrast * SPLIT[o.sensitivity])
  const usable = contrast >= 10

  const cuts: Cut[] = []
  if (usable) {
    let runStart = -1
    const close = (iEnd: number) => {
      const a = start + Math.max(0, runStart) * step
      const b = start + iEnd * step
      // never cut the very start/end of the range: the trim handles those
      const from = Math.max(start, a + (runStart === 0 ? 0 : o.pad))
      const to = Math.min(end, b - (iEnd >= sm.length ? 0 : o.pad))
      if (b - a >= o.minSilence && to - from >= 0.15) cuts.push({ from: Math.round(from * 100) / 100, to: Math.round(to * 100) / 100 })
    }
    for (let i = 0; i < sm.length; i++) {
      if (sm[i] < threshold) {
        if (runStart < 0) runStart = i
      } else if (runStart >= 0) {
        close(i)
        runStart = -1
      }
    }
    if (runStart >= 0) close(sm.length)
  }
  const removed = cuts.reduce((n, c) => n + c.to - c.from, 0)
  return { cuts, removed, thresholdDb: threshold, usable }
}

/** Existing cuts plus new ones, overlaps merged, in order. */
export function mergeCuts(a: Cut[], b: Cut[], limit: number): Cut[] {
  const all = [...a, ...b].map((c) => ({ from: Math.min(c.from, c.to), to: Math.max(c.from, c.to) })).sort((x, y) => x.from - y.from)
  const out: Cut[] = []
  for (const c of all) {
    const last = out[out.length - 1]
    if (last && c.from <= last.to + 0.05) last.to = Math.max(last.to, c.to)
    else out.push({ ...c })
  }
  return out.slice(0, limit)
}
