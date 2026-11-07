/**
 * Round 29 — subtitles from an .srt / .vtt file, or from plain pasted text.
 * Times are seconds of the ORIGINAL (main) video, like captions.
 */
import type { SubLine } from './config'

const BN = '০১২৩৪৫৬৭৮৯'
const toLatin = (s: string) => s.replace(/[০-৯]/g, (d) => String(BN.indexOf(d)))

/** "00:01:02,500" / "01:02.5" / "62.5" → seconds. */
export function parseStamp(raw: string): number | null {
  const s = toLatin(raw.trim()).replace(',', '.')
  const m = s.match(/^(?:(\d+):)?(?:(\d+):)?(\d+(?:\.\d+)?)$/)
  if (!m) return null
  const parts = [m[1], m[2], m[3]].filter((x) => x !== undefined).map(Number)
  let t = 0
  for (const p of parts) t = t * 60 + p
  return Number.isFinite(t) ? t : null
}

/** Whether the text looks like SRT / WebVTT (has "a --> b" lines). */
export const looksTimed = (text: string) => /-->/.test(text)

/** SRT or WebVTT → lines (formatting tags dropped). */
export function parseTimed(text: string, maxLen = 160): SubLine[] {
  const out: SubLine[] = []
  const blocks = toLatin(text).replace(/\r/g, '').split(/\n\s*\n/)
  for (const b of blocks) {
    const rows = b.split('\n').map((x) => x.trim()).filter(Boolean)
    const i = rows.findIndex((r) => r.includes('-->'))
    if (i < 0) continue
    const [a, z] = rows[i].split('-->').map((x) => x.trim().split(/\s+/)[0])
    const at = parseStamp(a)
    const end = parseStamp(z)
    const words = rows
      .slice(i + 1)
      .join(' ')
      .replace(/<[^>]+>/g, '')
      .replace(/\{[^}]+\}/g, '')
      .trim()
    if (at === null || end === null || end <= at || !words) continue
    out.push({ at, end, text: words.slice(0, maxLen) })
  }
  return out.sort((x, y) => x.at - y.at)
}

/**
 * Plain text → lines spread over [from, to]: one subtitle per line (long
 * lines split at sentence marks), each on screen for a time that matches
 * its length, the way people read.
 */
export function spreadPlain(text: string, from: number, to: number, maxLen = 160): SubLine[] {
  const lines = text
    .replace(/\r/g, '')
    .split('\n')
    .flatMap((l) => (l.length > 70 ? l.split(/(?<=[।?!,;])\s+/) : [l]))
    .map((l) => l.trim().slice(0, maxLen))
    .filter(Boolean)
  if (!lines.length || to - from < 0.5) return []
  const weight = lines.map((l) => Math.max(8, l.length))
  const total = weight.reduce((a, b) => a + b, 0)
  const out: SubLine[] = []
  let t = from
  lines.forEach((l, i) => {
    const d = ((to - from) * weight[i]) / total
    out.push({ at: Math.round(t * 10) / 10, end: Math.round((t + d) * 10) / 10, text: l })
    t += d
  })
  return out
}

/** Lines → SRT text (to download / edit elsewhere). */
export function toSrt(items: SubLine[]) {
  const stamp = (t: number) => {
    const ms = Math.round(t * 1000)
    const h = Math.floor(ms / 3600000)
    const m = Math.floor((ms % 3600000) / 60000)
    const s = Math.floor((ms % 60000) / 1000)
    const r = ms % 1000
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')},${String(r).padStart(3, '0')}`
  }
  return items.map((x, i) => `${i + 1}\n${stamp(x.at)} --> ${stamp(x.end)}\n${x.text}\n`).join('\n')
}
