/**
 * r43 — QR codes as SVG paths (qrcode-generator, MIT). Two on every ID
 * card: the front one opens the verify page, the back one the website.
 */
import qrcode from 'qrcode-generator'

export interface QrPath {
  /** module count (the QR is n × n) */
  n: number
  /** one path of 1×1 squares in module units */
  d: string
}

const cache = new Map<string, QrPath>()

export function qrPath(text: string, level: 'L' | 'M' | 'Q' | 'H' = 'M'): QrPath {
  const key = level + text
  const hit = cache.get(key)
  if (hit) return hit
  const q = qrcode(0, level)
  q.addData(text)
  q.make()
  const n = q.getModuleCount()
  let d = ''
  for (let r = 0; r < n; r++) {
    let run = -1
    for (let c = 0; c <= n; c++) {
      const dark = c < n && q.isDark(r, c)
      if (dark && run < 0) run = c
      if (!dark && run >= 0) {
        d += `M${run} ${r}h${c - run}v1h${run - c}z`
        run = -1
      }
    }
  }
  const out = { n, d }
  cache.set(key, out)
  return out
}

/**
 * A QR at (x, y), `size` wide, with an optional rounded white plate and a
 * small centre badge (the QR uses level Q so the badge never breaks it).
 */
export function qr(text: string, x: number, y: number, size: number, opt: { color?: string; plate?: string; pad?: number; radius?: number; badge?: string } = {}): string {
  const p = qrPath(text, opt.badge ? 'Q' : 'M')
  const pad = opt.pad ?? size * 0.06
  const inner = size - pad * 2
  const k = inner / p.n
  const plate = opt.plate ? `<rect x="${x}" y="${y}" width="${size}" height="${size}" rx="${opt.radius ?? size * 0.08}" fill="${opt.plate}"/>` : ''
  const badge = opt.badge
    ? `<rect x="${x + size / 2 - size * 0.11}" y="${y + size / 2 - size * 0.11}" width="${size * 0.22}" height="${size * 0.22}" rx="${size * 0.04}" fill="${opt.plate || '#fff'}"/>${opt.badge}`
    : ''
  return `${plate}<path transform="translate(${x + pad} ${y + pad}) scale(${k})" fill="${opt.color ?? '#0b1324'}" d="${p.d}" shape-rendering="crispEdges"/>${badge}`
}
