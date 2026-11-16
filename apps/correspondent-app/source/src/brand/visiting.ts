/**
 * r43 — ভিজিটিং কার্ড (90 × 54 mm, the usual Bangladesh size), two
 * designs, front + back. Made by itself from a journalist's ID card
 * (correspondent app) or filled by hand (publisher স্টেশনারি).
 *
 *  midnight — নেভি + গোল্ড, ছবি-সহ
 *  splash   — সাদা + মিহা নীল তির্যক প্যানেল, লাল রেখা
 */
import { F } from './fonts'
import { logo, logoH } from './logo'
import { qr } from './qr'
import { bnDigits, esc, fitSize, goldGrad, guilloche, icon, mix, silhouette, svgDoc, uid, type OrgInfo } from './util'

export const VC_W = 90
export const VC_H = 54

export interface VisitingData {
  name_bn: string
  name_en: string
  designation: string
  designation_en?: string
  phone: string
  email: string
  area: string
  photo?: string
  /** the QR on the back (verify page, profile or website) */
  qr_url?: string
  qr_label?: string
  show_photo?: boolean
}

export const VC_TEMPLATES = [
  { key: 'midnight', name: 'মিডনাইট গোল্ড', about: 'নেভি জমিন, সোনালি রেখা, ছবি-সহ' },
  { key: 'splash', name: 'ব্লু স্প্ল্যাশ', about: 'সাদা জমিন, মিহা-নীল তির্যক প্যানেল' },
]

function contacts(d: VisitingData, org: OrgInfo, x: number, y: number, step: number, color: string, iconColor: string, size = 2.35) {
  const rows: [Parameters<typeof icon>[0], string][] = []
  if (d.phone) rows.push(['phone', bnDigits(d.phone)])
  if (d.email) rows.push(['mail', d.email])
  rows.push(['web', org.website])
  if (d.area) rows.push(['pin', d.area])
  return rows
    .slice(0, 4)
    .map(([ic, t], i) => `${icon(ic, x, y + i * step - size * 0.95, size * 1.05, iconColor)}<text x="${x + size * 1.6}" y="${y + i * step}" font-family="${/[ঀ-৿]/.test(t) ? F.bn : F.en}" font-weight="500" font-size="${size}" fill="${color}">${esc(t)}</text>`)
    .join('')
}

function midnightFront(d: VisitingData, org: OrgInfo, bleed: number) {
  const W = VC_W
  const H = VC_H
  const g = uid('vm')
  const gold = org.color_gold || '#C9A54C'
  const defs = `<linearGradient id="${g}bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0d2f63"/><stop offset=".6" stop-color="#071a36"/><stop offset="1" stop-color="#040e1f"/></linearGradient>${goldGrad(g + 'au', 0.2)}
  <clipPath id="${g}pc"><circle cx="72" cy="20" r="10.2"/></clipPath>`
  const photo = d.show_photo !== false
  const nx = 7
  const nameSize = fitSize(d.name_bn, F.bnSerif, 5.2, photo ? 52 : 70, 700, 3.2)
  return svgDoc(
    W,
    H,
    `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="url(#${g}bg)"/>
    ${guilloche(-3, -3, W + 6, H + 6, gold, 0.09, 14, 0.08)}
    <path d="M${W - 30} -3H${W + 3}V${H + 3}H${W - 12}Z" fill="#ffffff" opacity=".035"/>
    <rect x="${nx - 3}" y="8.2" width=".7" height="13.6" fill="url(#${g}au)"/>
    <text x="${nx}" y="13.4" font-family="${F.bnSerif}" font-weight="700" font-size="${nameSize}" fill="#fff">${esc(d.name_bn)}</text>
    ${d.name_en ? `<text x="${nx}" y="17.4" font-family="${F.en}" font-weight="700" font-size="1.9" letter-spacing=".42" fill="${gold}">${esc(d.name_en.toUpperCase())}</text>` : ''}
    <text x="${nx}" y="21.4" font-family="${F.bn}" font-weight="600" font-size="2.6" fill="#e9eef7">${esc(d.designation)}${d.designation_en ? ` <tspan font-family="${F.en}" font-size="1.8" fill="#9fb3cf">· ${esc(d.designation_en)}</tspan>` : ''}</text>
    <line x1="${nx}" y1="26" x2="${photo ? 58 : 82}" y2="26" stroke="url(#${g}au)" stroke-width=".22"/>
    ${contacts(d, org, nx, 31.6, 4.6, '#eef3fb', gold)}
    ${photo ? `<circle cx="72" cy="20" r="11.4" fill="url(#${g}au)"/><g clip-path="url(#${g}pc)">${d.photo ? `<image href="${esc(d.photo)}" x="61.8" y="9.8" width="20.4" height="20.4" preserveAspectRatio="xMidYMin slice"/>` : silhouette(61.8, 9.8, 20.4, 20.4)}</g>` : ''}
    ${logo(photo ? 60 : 58, photo ? 39 : 7, photo ? 24 : 26, '#ffffff', gold)}
    ${photo ? `<text x="72" y="${39 + logoH(24) + 2.6}" text-anchor="middle" font-family="${F.en}" font-weight="700" font-size="1.4" letter-spacing=".5" fill="${gold}">PRESS</text>` : ''}
    <rect x="-3" y="${H - 1.6}" width="${W + 6}" height="4.6" fill="url(#${g}au)"/>`,
    { bleed, defs },
  )
}

function midnightBack(d: VisitingData, org: OrgInfo, bleed: number) {
  const W = VC_W
  const H = VC_H
  const g = uid('vmb')
  const gold = org.color_gold || '#C9A54C'
  const defs = `<radialGradient id="${g}bg" cx=".5" cy=".45" r=".75"><stop offset="0" stop-color="#11407f"/><stop offset=".6" stop-color="#071a36"/><stop offset="1" stop-color="#030b19"/></radialGradient>${goldGrad(g + 'au', 0.2)}`
  const lw = 46
  return svgDoc(
    W,
    H,
    `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="url(#${g}bg)"/>
    ${[14, 20, 26, 32].map((r) => `<circle cx="${W / 2}" cy="${H / 2 - 2}" r="${r}" fill="none" stroke="${gold}" stroke-width=".08" opacity=".25"/>`).join('')}
    ${logo((W - lw) / 2, 17, lw, '#ffffff', gold)}
    <text x="${W / 2}" y="${17 + logoH(lw) + 4.6}" text-anchor="middle" font-family="${F.bn}" font-size="2.4" fill="${gold}">${esc(org.tagline_bn)}</text>
    ${d.qr_url ? `${qr(d.qr_url, W - 17.5, H - 19.5, 12.5, { plate: '#ffffff', radius: 1.2, color: '#071a36' })}<text x="${W - 11.25}" y="${H - 4.6}" text-anchor="middle" font-family="${F.bn}" font-size="1.35" fill="${gold}">${esc(d.qr_label || 'স্ক্যান করুন')}</text>` : ''}
    <text x="7" y="${H - 6}" font-family="${F.en}" font-weight="700" font-size="2" letter-spacing=".6" fill="#e9eef7">${esc(org.website.toUpperCase())}</text>
    <rect x="-3" y="-3" width="${W + 6}" height="4.6" fill="url(#${g}au)"/>`,
    { bleed, defs },
  )
}

function splashFront(d: VisitingData, org: OrgInfo, bleed: number) {
  const W = VC_W
  const H = VC_H
  const g = uid('vs')
  const blue = org.color_primary || '#025AB2'
  const red = org.color_accent || '#C8102E'
  const ink = '#0b1f3a'
  const defs = `<linearGradient id="${g}bl" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${mix(blue, '#3aa0ff', 0.25)}"/><stop offset="1" stop-color="${mix(blue, '#001a3d', 0.5)}"/></linearGradient>
  <clipPath id="${g}pc"><circle cx="17" cy="21" r="9.6"/></clipPath>`
  const photo = d.show_photo !== false
  const tx = 39
  const nameSize = fitSize(d.name_bn, F.bnSerif, 4.6, W - tx - 5, 700, 2.8)
  return svgDoc(
    W,
    H,
    `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="#fff"/>
    <path d="M-3 -3H33L25 ${H + 3}H-3Z" fill="url(#${g}bl)"/>
    <path d="M33 -3H35.2L27.2 ${H + 3}H25Z" fill="${red}"/>
    ${guilloche(-3, -3, 36, H + 6, '#ffffff', 0.08, 10, 0.08)}
    ${photo ? `<circle cx="17" cy="21" r="10.8" fill="#ffffff"/><g clip-path="url(#${g}pc)">${d.photo ? `<image href="${esc(d.photo)}" x="7.4" y="11.4" width="19.2" height="19.2" preserveAspectRatio="xMidYMin slice"/>` : silhouette(7.4, 11.4, 19.2, 19.2)}</g>` : ''}
    ${logo(6, photo ? 40 : 22, 19, '#ffffff', '#ffd2d8')}
    <text x="${tx}" y="13" font-family="${F.bnSerif}" font-weight="700" font-size="${nameSize}" fill="${ink}">${esc(d.name_bn)}</text>
    ${d.name_en ? `<text x="${tx}" y="16.8" font-family="${F.en}" font-weight="700" font-size="1.8" letter-spacing=".38" fill="${blue}">${esc(d.name_en.toUpperCase())}</text>` : ''}
    <text x="${tx}" y="21.4" font-family="${F.bn}" font-weight="600" font-size="2.55" fill="${red}">${esc(d.designation)}</text>
    <rect x="${tx}" y="24.3" width="9" height=".5" fill="${red}"/><rect x="${tx + 9.6}" y="24.3" width="${W - tx - 9.6 - 5}" height=".5" fill="#e2e8f0"/>
    ${contacts(d, org, tx, 30.4, 4.5, '#1e293b', blue, 2.2)}`,
    { bleed, defs },
  )
}

function splashBack(d: VisitingData, org: OrgInfo, bleed: number) {
  const W = VC_W
  const H = VC_H
  const g = uid('vsb')
  const blue = org.color_primary || '#025AB2'
  const red = org.color_accent || '#C8102E'
  const defs = `<pattern id="${g}dot" width="2.4" height="2.4" patternUnits="userSpaceOnUse"><circle cx="1.2" cy="1.2" r=".2" fill="${blue}" opacity=".16"/></pattern>`
  const lw = 48
  return svgDoc(
    W,
    H,
    `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="#fff"/><rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="url(#${g}dot)"/>
    ${logo((W - lw) / 2, 16, lw, blue, red)}
    <rect x="${W / 2 - 6}" y="${16 + logoH(lw) + 2.4}" width="12" height=".6" fill="${red}"/>
    <text x="${W / 2}" y="${16 + logoH(lw) + 7}" text-anchor="middle" font-family="${F.bn}" font-size="2.4" fill="#334155">${esc(org.tagline_bn)}</text>
    ${d.qr_url ? qr(d.qr_url, W - 16.5, H - 16.5, 12, { plate: '#ffffff', color: '#0b1f3a', pad: 0.4 }) : ''}
    <rect x="-3" y="${H - 3.2}" width="${W + 6}" height="6.2" fill="${blue}"/>
    <text x="${W / 2}" y="${H - 0.85}" text-anchor="middle" font-family="${F.en}" font-weight="700" font-size="1.7" letter-spacing=".6" fill="#fff">${esc(org.website.toUpperCase())}</text>`,
    { bleed, defs },
  )
}

export function visitingFront(tpl: string, d: VisitingData, org: OrgInfo, bleed = 0) {
  return tpl === 'splash' ? splashFront(d, org, bleed) : midnightFront(d, org, bleed)
}
export function visitingBack(tpl: string, d: VisitingData, org: OrgInfo, bleed = 0) {
  return tpl === 'splash' ? splashBack(d, org, bleed) : midnightBack(d, org, bleed)
}
export function visitingPages(tpl: string, d: VisitingData, org: OrgInfo, bleed = 0) {
  return [
    { svg: visitingFront(tpl, d, org, bleed), w: VC_W + bleed * 2, h: VC_H + bleed * 2, name: 'front' },
    { svg: visitingBack(tpl, d, org, bleed), w: VC_W + bleed * 2, h: VC_H + bleed * 2, name: 'back' },
  ]
}
