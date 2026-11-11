/**
 * r43 — mockups: the design shown on a desk, a lanyard, a T-shirt …
 * Each scene is a 300 × 200 SVG that nests the real design SVG, so the
 * mockup always shows the latest edit.
 */
import { F } from './fonts'
import { logo } from './logo'
import { esc, mix, uid } from './util'

export const SCENE_W = 300
export const SCENE_H = 200

/** Places a design SVG inside a scene at (x,y) with size (w,h), rotated `rot`° about its centre. */
export function place(svg: string, x: number, y: number, w: number, h: number, rot = 0, filter = '', par = 'xMidYMid meet'): string {
  const inner = svg.replace(/<svg([^>]*?)\swidth="[^"]*"\s+height="[^"]*"/, `<svg$1 x="${x}" y="${y}" width="${w}" height="${h}" preserveAspectRatio="${par}"`)
  return `<g transform="rotate(${rot} ${x + w / 2} ${y + h / 2})"${filter ? ` filter="url(#${filter})"` : ''}>${inner}</g>`
}

function scene(body: string, defs: string, bg: 'desk' | 'dark' | 'studio' = 'desk', caption = ''): string {
  const g = uid('sc')
  const bgs = {
    desk: `<linearGradient id="${g}bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#eef1f6"/><stop offset="1" stop-color="#d5dbe5"/></linearGradient>`,
    dark: `<radialGradient id="${g}bg" cx=".5" cy=".35" r=".9"><stop offset="0" stop-color="#2a3a57"/><stop offset="1" stop-color="#0b1220"/></radialGradient>`,
    studio: `<radialGradient id="${g}bg" cx=".5" cy=".4" r=".85"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#cfd6e1"/></radialGradient>`,
  }
  const cap = caption
    ? `<text x="${SCENE_W - 8}" y="${SCENE_H - 7}" text-anchor="end" font-family="${F.bn}" font-size="4.2" fill="${bg === 'dark' ? '#cbd5e1' : '#64748b'}">${esc(caption)}</text>`
    : ''
  return `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="${SCENE_W}mm" height="${SCENE_H}mm" viewBox="0 0 ${SCENE_W} ${SCENE_H}"><defs>${bgs[bg]}${defs}</defs><rect width="${SCENE_W}" height="${SCENE_H}" fill="url(#${g}bg)"/>${body}${cap}</svg>`
}

const shadow = (id: string, blur = 3, dy = 3, op = 0.32) =>
  `<filter id="${id}" x="-30%" y="-30%" width="160%" height="160%"><feDropShadow dx="0" dy="${dy}" stdDeviation="${blur}" flood-color="#0b1220" flood-opacity="${op}"/></filter>`

/** A sheet of paper (letterhead, pad, certificate, invitation …) on a desk with a pen. */
export function paperScene(page: { svg: string; w: number; h: number }, caption = '', second?: { svg: string; w: number; h: number }): string {
  const s = uid('sh')
  const k = Math.min(170 / page.h, 200 / page.w)
  const w = page.w * k
  const h = page.h * k
  const x = (SCENE_W - w) / 2 + (second ? 28 : 0)
  const y = (SCENE_H - h) / 2
  const back = second ? place(second.svg, x - 60, y + 6, second.w * k * 0.92, second.h * k * 0.92, -7, s) : ''
  const pen = `<g transform="translate(${Math.min(SCENE_W - 30, x + w + 16)} ${y + h * 0.3}) rotate(-14)"><rect x="0" y="0" width="5" height="72" rx="2.4" fill="#0b1f3a"/><rect x="0" y="0" width="5" height="16" rx="2.4" fill="#C9A54C"/><path d="M0.3 72 L2.5 82 L4.7 72Z" fill="#94a3b8"/><rect x="4.4" y="3" width="1.2" height="22" rx=".5" fill="#C9A54C"/></g>`
  return scene(`${back}${place(page.svg, x, y, w, h, second ? 3 : -2, s)}${pen}`, shadow(s, 3.2, 3.5), 'desk', caption)
}

/** Two cards (front + back) — visiting cards, stickers. */
export function cardsScene(front: { svg: string; w: number; h: number }, back: { svg: string; w: number; h: number }, caption = '', bg: 'desk' | 'dark' = 'dark'): string {
  const s = uid('sh')
  const k = Math.min(120 / front.w, 120 / front.h)
  const w = front.w * k
  const h = front.h * k
  return scene(`${place(back.svg, 150 - w + 12, 100 - h / 2 + 14, w, h, 8, s)}${place(front.svg, 150 - w / 2 - 40, 100 - h / 2 - 14, w, h, -6, s)}`, shadow(s, 3.5, 4, 0.45), bg, caption)
}

/** A strap (the lanyard print, horizontal) drawn from (x1,y1) to (x2,y2), `sw` wide. */
function strap(strip: string, x1: number, y1: number, x2: number, y2: number, sw: number, sid: string) {
  const L = Math.hypot(x2 - x1, y2 - y1)
  const rot = (Math.atan2(y2 - y1, x2 - x1) * 180) / Math.PI
  const mx = (x1 + x2) / 2
  const my = (y1 + y2) / 2
  return place(strip, mx - L / 2, my - sw / 2, L, sw, rot, sid, 'xMinYMid slice')
}

/** The ID card hanging on the lanyard, back side beside it. */
export function idScene(front: string, back: string, strip: string, caption = ''): string {
  const s = uid('sh')
  const cw = 54 * 1.55
  const ch = 85.6 * 1.55
  const cx = 70
  const cy = 64
  const mid = cx + cw / 2
  const clip = `<rect x="${mid - 7}" y="${cy - 20}" width="14" height="9" rx="2" fill="#e2e8f0" stroke="#94a3b8" stroke-width=".6"/><rect x="${mid - 3}" y="${cy - 12}" width="6" height="13" rx="1.5" fill="#94a3b8"/><rect x="${mid - 8}" y="${cy - 1.6}" width="16" height="3" rx="1.5" fill="#64748b"/>`
  return scene(
    `${strap(strip, mid - 46, -8, mid - 2, cy - 16, 9, s)}${strap(strip, mid + 46, -8, mid + 2, cy - 16, 9, s)}${clip}
     ${place(front, cx, cy, cw, ch, 0, s)}
     ${place(back, 186, 40, cw * 0.9, ch * 0.9, 7, s)}`,
    shadow(s, 3.5, 4, 0.5),
    'dark',
    caption,
  )
}

/** A T-shirt silhouette, in any colour, with prints placed on it. */
function shirt(x: number, y: number, w: number, color: string, print: string, px: number, py: number, pw: number, ph: number, sid: string): string {
  const k = w / 100
  const p = (a: number, b: number) => `${(x + a * k).toFixed(2)} ${(y + b * k).toFixed(2)}`
  const shade = mix(color, '#000000', 0.18)
  const d = `M${p(30, 0)} C${p(38, 6)} ${p(62, 6)} ${p(70, 0)} L${p(96, 11)} L${p(108, 38)} L${p(86, 46)} L${p(80, 32)} L${p(80, 112)} C${p(60, 116)} ${p(40, 116)} ${p(20, 112)} L${p(20, 32)} L${p(14, 46)} L${p(-8, 38)} L${p(4, 11)} Z`
  return `<g filter="url(#${sid})"><path d="${d}" fill="${color}"/>
    <path d="M${p(30, 0)} C${p(38, 6)} ${p(62, 6)} ${p(70, 0)} C${p(63, 10)} ${p(37, 10)} ${p(30, 0)}Z" fill="${shade}"/>
    <path d="M${p(20, 32)} L${p(20, 112)}" stroke="${shade}" stroke-width=".5" opacity=".5"/><path d="M${p(80, 32)} L${p(80, 112)}" stroke="${shade}" stroke-width=".5" opacity=".5"/></g>
    ${place(print, x + px * k, y + py * k, pw * k, ph * k)}`
}

export function tshirtScene(color: string, front: string, frontW: number, frontH: number, back: string, backW: number, backH: number, caption = ''): string {
  const s = uid('sh')
  // print sizes (mm on a real shirt) mapped onto a 100-unit-wide shirt ≈ 520 mm
  const mm = 100 / 520
  const fw = frontW * mm
  const fh = frontH * mm
  const bw = backW * mm
  const bh = backH * mm
  return scene(
    `${shirt(28, 26, 112, color, front, 58 - fw, 26, fw, fh, s)}${shirt(164, 26, 112, color, back, 50 - bw / 2, 22, bw, bh, s)}
     <text x="84" y="166" text-anchor="middle" font-family="${F.bn}" font-size="4.4" fill="#475569">সামনে</text><text x="220" y="166" text-anchor="middle" font-family="${F.bn}" font-size="4.4" fill="#475569">পেছনে</text>`,
    shadow(s, 2.6, 3, 0.28),
    'studio',
    caption,
  )
}

/** The lanyard print lying flat (two lengths) with a card on it. */
export function stripScene(strip: string, card: string, caption = ''): string {
  const s = uid('sh')
  return scene(
    `${place(strip, 14, 40, 272, 14, -3, s, 'xMinYMid slice')}${place(strip, 14, 70, 272, 14, 2, s, 'xMidYMid slice')}${strap(strip, 60, 200, 150, 112, 14, s)}${card ? place(card, 178, 92, 54 * 1.1, 85.6 * 1.1, -8, s) : ''}`,
    shadow(s, 2.4, 2.6, 0.35),
    'desk',
    caption,
  )
}

export function penScene(strip: string, caption = ''): string {
  const s = uid('sh')
  const pens = [0, 1, 2]
    .map((i) => {
      const y = 60 + i * 30
      const col = ['#0b1f3a', '#025AB2', '#ffffff'][i]
      const ink = i === 2 ? '#0b1f3a' : '#ffffff'
      return `<g transform="translate(-16 0) rotate(${-8 + i * 6} 150 ${y})" filter="url(#${s})"><rect x="50" y="${y - 5}" width="190" height="10" rx="5" fill="${col}" stroke="#cbd5e1" stroke-width="${i === 2 ? 0.4 : 0}"/><path d="M240 ${y - 4} L262 ${y} L240 ${y + 4}Z" fill="#94a3b8"/><rect x="44" y="${y - 5.5}" width="22" height="11" rx="4" fill="#C9A54C"/><rect x="58" y="${y - 7.5}" width="44" height="2.6" rx="1.3" fill="#C9A54C"/>
      ${place(strip.replace(/#0b1f3a/g, ink), 110, y - 3.4, 110, 6.8)}</g>`
    })
    .join('')
  return scene(pens, shadow(s, 2, 2.5, 0.3), 'studio', caption)
}

/** The car PRESS sticker and the round logo sticker side by side. */
export function stickerScene(car: string, round: string, caption = ''): string {
  const s = uid('sh')
  return scene(`${place(car, 18, 62, 168, 58.8, -5, s)}${place(round, 200, 64, 72, 72, 8, s)}`, shadow(s, 2.6, 3, 0.35), 'desk', caption)
}

/** A plain logo badge used where a design has no picture yet. */
export function emptyScene(text: string): string {
  return scene(`${logo(90, 80, 120, '#025AB2', '#C8102E')}<text x="150" y="120" text-anchor="middle" font-family="${F.bn}" font-size="6" fill="#64748b">${esc(text)}</text>`, '', 'studio')
}
