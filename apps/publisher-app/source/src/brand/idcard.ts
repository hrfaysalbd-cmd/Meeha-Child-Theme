/**
 * r43 — মিহা সাংবাদিক আইডি কার্ড: two premium designs, front + back.
 *
 * Size: CR80 (54 × 85.6 mm, portrait) — the normal PVC card size printers
 * in Bangladesh use. Print files can carry 2 mm bleed.
 *
 *  royal  — রয়্যাল: midnight navy, gold foil, security lines.
 *  aurora — অরোরা: white, Meeha blue sweep with a red ribbon.
 *
 * Front QR → the verify page (name, designation, district, valid-till /
 * মেয়াদ শেষ). Back QR → the website.
 */
import { F } from './fonts'
import { logo, logoH } from './logo'
import { qr } from './qr'
import { bnDate, bnDigits, esc, fitSize, goldGrad, guilloche, icon, mix, silhouette, svgDoc, textWidth, uid, wrap, lines, type BrandAssets, type OrgInfo } from './util'

export const CARD_W = 54
export const CARD_H = 85.6

export type CardStatus = 'active' | 'expired' | 'cancelled' | 'suspended' | 'preview'

export interface CardData {
  name_bn: string
  name_en: string
  designation: string
  designation_en?: string
  area: string
  card_no: string
  issued: string
  expires: string
  blood: string
  phone: string
  emergency: string
  email?: string
  /** a data: URI (or same-origin URL) */
  photo: string
  verify_url: string
  status: CardStatus
}

export interface CardOpts {
  bleed?: number
  /** round the corners (screen) */
  round?: boolean
  /** show the মেয়াদ শেষ / বাতিল stamp (default: when not active) */
  stamp?: boolean
}

export const ID_TEMPLATES: { key: string; name: string; about: string }[] = [
  { key: 'royal', name: 'রয়্যাল', about: 'নেভি ও গোল্ড-ফয়েল, সিকিউরিটি লাইন — সবচেয়ে প্রিমিয়াম' },
  { key: 'aurora', name: 'অরোরা', about: 'সাদা জমিনে মিহা-নীল ঢেউ ও লাল রিবন — আধুনিক ও উজ্জ্বল' },
]

export interface CardContext {
  org: OrgInfo
  assets?: BrandAssets
  terms?: string[]
}

const webUrl = (org: OrgInfo) => org.home_url || `https://${org.website.replace(/^https?:\/\//, '')}`

function photo(src: string, x: number, y: number, w: number, h: number, rx: number, clipId: string): string {
  const clip = `<clipPath id="${clipId}"><rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${rx}"/></clipPath>`
  const img = src
    ? `<image href="${esc(src)}" x="${x}" y="${y}" width="${w}" height="${h}" preserveAspectRatio="xMidYMin slice"/>`
    : silhouette(x, y, w, h)
  return `<defs>${clip}</defs><g clip-path="url(#${clipId})">${img}</g>`
}

function stamp(d: CardData, w: number, h: number): string {
  if (d.status === 'active' || d.status === 'preview') return ''
  const txt = d.status === 'expired' ? 'মেয়াদ উত্তীর্ণ' : d.status === 'suspended' ? 'স্থগিত' : 'বাতিল'
  const en = d.status === 'expired' ? 'EXPIRED' : d.status === 'suspended' ? 'SUSPENDED' : 'CANCELLED'
  return `<g transform="rotate(-28 ${w / 2} ${h / 2})" opacity=".92"><rect x="${-10}" y="${h / 2 - 6}" width="${w + 20}" height="12" fill="#b91c1c" opacity=".88"/><text x="${w / 2}" y="${h / 2 - 0.2}" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="4.6" fill="#fff">${txt}</text><text x="${w / 2}" y="${h / 2 + 3.9}" text-anchor="middle" font-family="${F.en}" font-weight="800" font-size="2.2" letter-spacing=".9" fill="#fff">${en}</text></g>`
}

const tick = (cx: number, cy: number, r: number, fill: string, ink: string) =>
  `<circle cx="${cx}" cy="${cy}" r="${r}" fill="${fill}" stroke="#fff" stroke-width="${r * 0.18}"/><path d="M${cx - r * 0.45} ${cy + r * 0.02} l${r * 0.32} ${r * 0.34} l${r * 0.62} ${-r * 0.7}" fill="none" stroke="${ink}" stroke-width="${r * 0.26}" stroke-linecap="round" stroke-linejoin="round"/>`

// ---------------------------------------------------------------------------
// রয়্যাল — navy + gold
// ---------------------------------------------------------------------------

function royalFront(d: CardData, c: CardContext, o: CardOpts): string {
  const W = CARD_W
  const H = CARD_H
  const g = uid('r')
  const navy = '#071a36'
  const gold = c.org.color_gold || '#C9A54C'
  const defs = `<linearGradient id="${g}bg" x1="0" y1="0" x2=".6" y2="1"><stop offset="0" stop-color="#0d2f63"/><stop offset=".55" stop-color="${navy}"/><stop offset="1" stop-color="#040e1f"/></linearGradient>
    ${goldGrad(g + 'au', 0.15)}
    <radialGradient id="${g}gl" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#3f7fd0" stop-opacity=".55"/><stop offset="1" stop-color="#3f7fd0" stop-opacity="0"/></radialGradient>`
  const nameSize = fitSize(d.name_bn, F.bnSerif, 4.5, 46, 700, 2.8)
  const des = d.designation || 'প্রতিনিধি'
  const desSize = fitSize(des, F.bn, 2.6, 38, 600, 1.8)
  const desW = Math.min(46, textWidth(des, F.bn, desSize, 600) + 8)
  const area = d.area
  const lw = 27
  const body = `
  <rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="url(#${g}bg)"/>
  ${guilloche(-3, 8, W + 6, 72, gold, 0.13, 18, 0.09)}
  ${[17, 21, 25, 29].map((r) => `<circle cx="${W / 2}" cy="27.5" r="${r}" fill="none" stroke="${gold}" stroke-width=".08" opacity=".22"/>`).join('')}
  <rect x="2.2" y="2.2" width="${W - 4.4}" height="77.6" rx="2.4" fill="none" stroke="url(#${g}au)" stroke-width=".28"/>
  <rect x="3" y="3" width="${W - 6}" height="76" rx="1.9" fill="none" stroke="${gold}" stroke-width=".08" opacity=".6"/>
  ${logo((W - lw) / 2, 4.7, lw, '#ffffff', gold)}
  <text x="${W / 2}" y="${4.7 + logoH(lw) + 2.6}" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="1.75" fill="${gold}" letter-spacing=".12">সাংবাদিক পরিচয়পত্র · <tspan font-family="${F.en}" font-weight="700" font-size="1.45" letter-spacing=".35">PRESS ID</tspan></text>
  <circle cx="${W / 2}" cy="27.5" r="17" fill="url(#${g}gl)"/>
  <rect x="14.2" y="12.6" width="25.6" height="30.2" rx="3.1" fill="url(#${g}au)"/>
  ${photo(d.photo, 15, 13.4, 24, 28.6, 2.5, g + 'ph')}
  ${d.status === 'active' || d.status === 'preview' ? tick(37.6, 40.8, 2.3, `url(#${g}au)`, navy) : ''}
  <text x="${W / 2}" y="48.8" text-anchor="middle" font-family="${F.bnSerif}" font-weight="700" font-size="${nameSize}" fill="#ffffff">${esc(d.name_bn)}</text>
  ${d.name_en ? `<text x="${W / 2}" y="52.4" text-anchor="middle" font-family="${F.en}" font-weight="700" font-size="${fitSize(d.name_en.toUpperCase(), F.en, 1.85, 44, 700, 1.3)}" fill="${gold}" letter-spacing=".32">${esc(d.name_en.toUpperCase())}</text>` : ''}
  <rect x="${(W - desW) / 2}" y="54.2" width="${desW}" height="5" rx="2.5" fill="url(#${g}au)"/>
  <text x="${W / 2}" y="${57.55}" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="${desSize}" fill="${navy}">${esc(des)}</text>
  ${area ? `<g>${icon('pin', W / 2 - textWidth(area, F.bn, 2.15) / 2 - 2.6, 60.55, 2.3, gold)}<text x="${W / 2 + 1.2}" y="62.6" text-anchor="middle" font-family="${F.bn}" font-size="2.15" fill="#e6edf7">${esc(area)}</text></g>` : ''}
  <line x1="5.4" y1="65.4" x2="${W - 5.4}" y2="65.4" stroke="${gold}" stroke-width=".1" opacity=".55"/>
  <text x="5.4" y="68.6" font-family="${F.bn}" font-size="1.55" fill="${gold}">কার্ড নম্বর</text>
  <text x="5.4" y="71.5" font-family="${F.en}" font-weight="700" font-size="2.25" fill="#fff" letter-spacing=".1">${esc(d.card_no || '—')}</text>
  <text x="5.4" y="74.6" font-family="${F.bn}" font-size="1.55" fill="${gold}">মেয়াদ</text>
  <text x="5.4" y="77.6" font-family="${F.bn}" font-weight="600" font-size="2.15" fill="#fff">${esc(bnDate(d.expires) || '—')} পর্যন্ত</text>
  ${d.blood ? `<rect x="27.6" y="66.4" width="8.2" height="6.4" rx="1.2" fill="#b4152b"/><text x="31.7" y="68.6" text-anchor="middle" font-family="${F.bn}" font-size="1.3" fill="#ffd9de">রক্ত</text><text x="31.7" y="71.6" text-anchor="middle" font-family="${F.en}" font-weight="800" font-size="2.5" fill="#fff">${esc(d.blood)}</text>` : ''}
  ${qr(d.verify_url || 'https://meeha.news', 37.4, 66.2, 13, { plate: '#ffffff', radius: 1.3, color: navy })}
  <text x="43.9" y="81.1" text-anchor="middle" font-family="${F.bn}" font-size="1.25" fill="${gold}">স্ক্যান করে যাচাই</text>
  <rect x="-3" y="82" width="${W + 6}" height="${H - 82 + 3}" fill="url(#${g}au)"/>
  <text x="${W / 2}" y="85.05" text-anchor="middle" font-family="${F.display}" font-size="3.2" letter-spacing="1.6" fill="${navy}">★ PRESS ★</text>
  ${o.stamp === false ? '' : stamp(d, W, H)}`
  return svgDoc(W, H, body, { bleed: o.bleed, defs, round: o.round ? 3.18 : 0 })
}

function backRows(d: CardData) {
  return [
    ['রক্তের গ্রুপ', d.blood || '—'],
    ['মোবাইল', d.phone ? bnDigits(d.phone) : '—'],
    ['জরুরি যোগাযোগ', d.emergency ? bnDigits(d.emergency) : '—'],
    ['ইস্যুর তারিখ', bnDate(d.issued) || '—'],
    ['মেয়াদ শেষ', bnDate(d.expires) || '—'],
  ]
}

function signature(c: CardContext, x: number, y: number, w: number, ink: string, sub: string) {
  const sig = c.assets?.signature
  const name = c.org.signer_name || c.org.editor_name || ''
  const title = c.org.signer_title || 'সম্পাদক ও প্রকাশক'
  return `${sig ? `<image href="${esc(sig)}" x="${x}" y="${y}" width="${w}" height="6.4" preserveAspectRatio="xMidYMax meet"/>` : ''}
  <line x1="${x}" y1="${y + 7}" x2="${x + w}" y2="${y + 7}" stroke="${ink}" stroke-width=".12" opacity=".7"/>
  ${name ? `<text x="${x + w / 2}" y="${y + 9.3}" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="1.75" fill="${ink}">${esc(name)}</text>` : ''}
  <text x="${x + w / 2}" y="${y + (name ? 11.4 : 9.3)}" text-anchor="middle" font-family="${F.bn}" font-size="1.5" fill="${sub}">${esc(title)}</text>`
}

function royalBack(d: CardData, c: CardContext, o: CardOpts): string {
  const W = CARD_W
  const H = CARD_H
  const g = uid('rb')
  const navy = '#071a36'
  const gold = c.org.color_gold || '#C9A54C'
  const defs = `<linearGradient id="${g}bg" x1="0" y1="0" x2=".5" y2="1"><stop offset="0" stop-color="#0b2853"/><stop offset="1" stop-color="#050f22"/></linearGradient>${goldGrad(g + 'au', 0.15)}`
  const lw = 24
  const rows = backRows(d)
  const terms = (c.terms && c.terms.length ? c.terms : ['এই কার্ডটি মিহা নিউজের সম্পত্তি।']).slice(0, 3)
  let ty = 44.6
  const termSvg = terms
    .map((t) => {
      const ls = wrap(t, F.bn, 1.6, 41)
      const out = `<circle cx="6.3" cy="${ty - 0.55}" r=".42" fill="${gold}"/>${lines(ls, 7.6, ty, 2.25, `font-family="${F.bn}" font-size="1.6" fill="#d6dfec"`)}`
      ty += ls.length * 2.25 + 0.6
      return out
    })
    .join('')
  const addr = c.org.address_bn || ''
  const contact = [c.org.phone && `ফোন: ${bnDigits(c.org.phone)}`, c.org.email].filter(Boolean).join(' · ')
  const body = `
  <rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="url(#${g}bg)"/>
  ${guilloche(-3, 0, W + 6, 85, gold, 0.07, 22, 0.08)}
  <rect x="-3" y="-3" width="${W + 6}" height="5.2" fill="url(#${g}au)"/>
  ${logo((W - lw) / 2, 4.6, lw, '#ffffff', gold)}
  <text x="${W / 2}" y="${4.6 + logoH(lw) + 2.5}" text-anchor="middle" font-family="${F.bn}" font-size="1.55" fill="${gold}">${esc(c.org.tagline_bn)}</text>
  <text x="${W / 2}" y="16.3" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="2" fill="#fff">কার্ডধারীর তথ্য</text>
  <line x1="18" y1="17.6" x2="36" y2="17.6" stroke="url(#${g}au)" stroke-width=".22"/>
  ${rows
    .map(
      ([k, v], i) => `<text x="5.6" y="${21.8 + i * 4.3}" font-family="${F.bn}" font-size="1.7" fill="${gold}">${k}</text><text x="${W - 5.6}" y="${21.8 + i * 4.3}" text-anchor="end" font-family="${F.bn}" font-weight="600" font-size="1.95" fill="#fff">${esc(v)}</text><line x1="5.6" y1="${22.9 + i * 4.3}" x2="${W - 5.6}" y2="${22.9 + i * 4.3}" stroke="#ffffff" stroke-width=".06" opacity=".18"/>`,
    )
    .join('')}
  ${termSvg}
  ${signature(c, 5.6, 55.2, 22, '#ffffff', gold)}
  ${qr(webUrl(c.org), 35, 55, 13.4, { plate: '#ffffff', radius: 1.3, color: navy })}
  <text x="41.7" y="70.4" text-anchor="middle" font-family="${F.en}" font-weight="700" font-size="1.45" fill="${gold}" letter-spacing=".15">${esc(c.org.website.toUpperCase())}</text>
  <line x1="5.6" y1="72.2" x2="${W - 5.6}" y2="72.2" stroke="${gold}" stroke-width=".08" opacity=".5"/>
  <text x="${W / 2}" y="74.5" text-anchor="middle" font-family="${F.bn}" font-size="1.45" fill="#b9c6da">কার্ডটি পাওয়া গেলে অনুগ্রহ করে নিচের ঠিকানায় ফেরত দিন</text>
  ${addr ? `<text x="${W / 2}" y="77" text-anchor="middle" font-family="${F.bn}" font-size="1.6" fill="#fff">${esc(wrap(addr, F.bn, 1.6, 46)[0])}</text>` : ''}
  ${contact ? `<text x="${W / 2}" y="${addr ? 79.3 : 77}" text-anchor="middle" font-family="${F.bn}" font-size="1.55" fill="#dbe4f1">${esc(contact)}</text>` : ''}
  <rect x="-3" y="82" width="${W + 6}" height="${H - 82 + 3}" fill="url(#${g}au)"/>
  <text x="${W / 2}" y="84.85" text-anchor="middle" font-family="${F.en}" font-weight="800" font-size="1.7" letter-spacing=".5" fill="${navy}">${esc(d.card_no || '')}</text>`
  return svgDoc(W, H, body, { bleed: o.bleed, defs, round: o.round ? 3.18 : 0 })
}

// ---------------------------------------------------------------------------
// অরোরা — white + Meeha blue + red ribbon
// ---------------------------------------------------------------------------

function auroraFront(d: CardData, c: CardContext, o: CardOpts): string {
  const W = CARD_W
  const H = CARD_H
  const g = uid('a')
  const blue = c.org.color_primary || '#025AB2'
  const red = c.org.color_accent || '#C8102E'
  const ink = '#0b1f3a'
  const defs = `<linearGradient id="${g}bl" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${mix(blue, '#3aa0ff', 0.25)}"/><stop offset=".6" stop-color="${blue}"/><stop offset="1" stop-color="${mix(blue, '#001a3d', 0.55)}"/></linearGradient>
    <linearGradient id="${g}rd" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="${red}"/><stop offset="1" stop-color="${mix(red, '#ff6a3d', 0.35)}"/></linearGradient>
    <linearGradient id="${g}ring" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${blue}"/><stop offset="1" stop-color="${red}"/></linearGradient>
    <pattern id="${g}dot" width="2.2" height="2.2" patternUnits="userSpaceOnUse"><circle cx="1.1" cy="1.1" r=".18" fill="${blue}" opacity=".14"/></pattern>`
  const nameSize = fitSize(d.name_bn, F.bnSerif, 4.4, 46, 700, 2.8)
  const des = d.designation || 'প্রতিনিধি'
  const desSize = fitSize(des, F.bn, 2.7, 40, 600, 1.8)
  const dw = textWidth(des, F.bn, desSize, 600)
  const area = d.area
  const aw = area ? Math.min(46, textWidth(area, F.bn, 2.05) + 7.4) : 0
  const lw = 25
  const body = `
  <rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="#ffffff"/>
  <rect x="-3" y="44" width="${W + 6}" height="40" fill="url(#${g}dot)"/>
  <path d="M-3 -3H${W + 3}V27.5C${W - 8} 33 ${W - 22} 39.5 -3 33.5Z" fill="url(#${g}bl)"/>
  ${guilloche(-3, -3, W + 6, 30, '#ffffff', 0.1, 9, 0.08)}
  <path d="M-3 33.5C${W - 22} 39.5 ${W - 8} 33 ${W + 3} 27.5V30.6C${W - 8} 36.4 ${W - 22} 42.4 -3 36.2Z" fill="url(#${g}rd)"/>
  ${logo(4.6, 4.6, lw, '#ffffff', '#ffd2d8')}
  <text x="4.6" y="${4.6 + logoH(lw) + 2.5}" font-family="${F.en}" font-weight="700" font-size="1.45" fill="#dbe9ff" letter-spacing=".42">PRESS IDENTITY CARD</text>
  <text transform="translate(50.6 4.4) rotate(90)" font-family="${F.display}" font-size="4.4" letter-spacing="1.1" fill="#ffffff" opacity=".95">PRESS</text>
  <circle cx="${W / 2}" cy="29.5" r="13.6" fill="url(#${g}ring)"/>
  <circle cx="${W / 2}" cy="29.5" r="12.7" fill="#ffffff"/>
  <defs><clipPath id="${g}pc"><circle cx="${W / 2}" cy="29.5" r="11.9"/></clipPath></defs>
  <g clip-path="url(#${g}pc)">${d.photo ? `<image href="${esc(d.photo)}" x="${W / 2 - 11.9}" y="17.6" width="23.8" height="23.8" preserveAspectRatio="xMidYMin slice"/>` : silhouette(W / 2 - 11.9, 17.6, 23.8, 23.8)}</g>
  ${d.status === 'active' || d.status === 'preview' ? tick(W / 2 + 9.2, 38.4, 2.3, blue, '#ffffff') : ''}
  <text x="${W / 2}" y="49.6" text-anchor="middle" font-family="${F.bnSerif}" font-weight="700" font-size="${nameSize}" fill="${ink}">${esc(d.name_bn)}</text>
  ${d.name_en ? `<text x="${W / 2}" y="53" text-anchor="middle" font-family="${F.en}" font-weight="700" font-size="${fitSize(d.name_en.toUpperCase(), F.en, 1.8, 44, 700, 1.3)}" fill="${blue}" letter-spacing=".3">${esc(d.name_en.toUpperCase())}</text>` : ''}
  <line x1="${W / 2 - dw / 2 - 5.4}" y1="56.5" x2="${W / 2 - dw / 2 - 1.4}" y2="56.5" stroke="${red}" stroke-width=".3"/>
  <line x1="${W / 2 + dw / 2 + 1.4}" y1="56.5" x2="${W / 2 + dw / 2 + 5.4}" y2="56.5" stroke="${red}" stroke-width=".3"/>
  <text x="${W / 2}" y="57.4" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="${desSize}" fill="${red}">${esc(des)}</text>
  ${area ? `<rect x="${(W - aw) / 2}" y="59.3" width="${aw}" height="4.4" rx="2.2" fill="${mix(blue, '#ffffff', 0.88)}"/>${icon('pin', (W - aw) / 2 + 1.6, 60.25, 2.4, blue)}<text x="${W / 2 + 1.2}" y="62.3" text-anchor="middle" font-family="${F.bn}" font-size="2.05" fill="${ink}">${esc(area)}</text>` : ''}
  <rect x="4.2" y="65.4" width="31.6" height="14.4" rx="1.6" fill="#f3f6fb"/>
  <text x="6" y="68.5" font-family="${F.bn}" font-size="1.5" fill="#64748b">কার্ড নম্বর</text>
  <text x="6" y="71.4" font-family="${F.en}" font-weight="800" font-size="2.2" fill="${ink}" letter-spacing=".1">${esc(d.card_no || '—')}</text>
  <text x="6" y="74.6" font-family="${F.bn}" font-size="1.5" fill="#64748b">মেয়াদ</text>
  <text x="6" y="77.6" font-family="${F.bn}" font-weight="600" font-size="2.1" fill="${ink}">${esc(bnDate(d.expires) || '—')}</text>
  ${d.blood ? `<circle cx="31.6" cy="70.2" r="3.1" fill="${red}"/><text x="31.6" y="71.15" text-anchor="middle" font-family="${F.en}" font-weight="800" font-size="2.1" fill="#fff">${esc(d.blood)}</text><text x="31.6" y="75.2" text-anchor="middle" font-family="${F.bn}" font-size="1.3" fill="#64748b">রক্ত</text>` : ''}
  ${qr(d.verify_url || 'https://meeha.news', 37.2, 65.2, 13.2, { plate: '#ffffff', radius: 1.2, color: ink, pad: 0.5 })}
  <rect x="37.2" y="65.2" width="13.2" height="13.2" rx="1.2" fill="none" stroke="${mix(blue, '#ffffff', 0.6)}" stroke-width=".15"/>
  <text x="43.8" y="80.3" text-anchor="middle" font-family="${F.bn}" font-size="1.2" fill="#64748b">স্ক্যান করে যাচাই</text>
  <rect x="-3" y="81.8" width="${W + 6}" height="${H - 81.8 + 3}" fill="url(#${g}bl)"/>
  <rect x="-3" y="81.8" width="${W + 6}" height=".55" fill="url(#${g}rd)"/>
  <text x="${W / 2}" y="84.75" text-anchor="middle" font-family="${F.en}" font-weight="700" font-size="1.75" letter-spacing=".45" fill="#ffffff">${esc(c.org.website.toUpperCase())}</text>
  ${o.stamp === false ? '' : stamp(d, W, H)}`
  return svgDoc(W, H, body, { bleed: o.bleed, defs, round: o.round ? 3.18 : 0 })
}

function auroraBack(d: CardData, c: CardContext, o: CardOpts): string {
  const W = CARD_W
  const H = CARD_H
  const g = uid('ab')
  const blue = c.org.color_primary || '#025AB2'
  const red = c.org.color_accent || '#C8102E'
  const ink = '#0b1f3a'
  const defs = `<linearGradient id="${g}bl" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${mix(blue, '#3aa0ff', 0.25)}"/><stop offset="1" stop-color="${mix(blue, '#001a3d', 0.5)}"/></linearGradient>
  <linearGradient id="${g}rd" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="${red}"/><stop offset="1" stop-color="${mix(red, '#ff6a3d', 0.35)}"/></linearGradient>`
  const lw = 25
  const rows = backRows(d)
  const terms = (c.terms && c.terms.length ? c.terms : ['এই কার্ডটি মিহা নিউজের সম্পত্তি।']).slice(0, 3)
  let ty = 45.4
  const termSvg = terms
    .map((t) => {
      const ls = wrap(t, F.bn, 1.6, 41)
      const out = `<rect x="5.6" y="${ty - 1.25}" width=".9" height=".9" rx=".2" fill="${red}"/>${lines(ls, 7.5, ty, 2.25, `font-family="${F.bn}" font-size="1.6" fill="#334155"`)}`
      ty += ls.length * 2.25 + 0.6
      return out
    })
    .join('')
  const addr = c.org.address_bn || ''
  const contact = [c.org.phone && `ফোন: ${bnDigits(c.org.phone)}`, c.org.email].filter(Boolean).join(' · ')
  const body = `
  <rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="#ffffff"/>
  <path d="M-3 -3H${W + 3}V13.2C${W - 14} 16.4 14 16.4 -3 13.2Z" fill="url(#${g}bl)"/>
  <path d="M-3 13.2C14 16.4 ${W - 14} 16.4 ${W + 3} 13.2V14.2C${W - 14} 17.4 14 17.4 -3 14.2Z" fill="url(#${g}rd)"/>
  ${logo((W - lw) / 2, 3.8, lw, '#ffffff', '#ffd2d8')}
  <text x="${W / 2}" y="${3.8 + logoH(lw) + 2.4}" text-anchor="middle" font-family="${F.bn}" font-size="1.5" fill="#e3eeff">${esc(c.org.tagline_bn)}</text>
  <text x="5.6" y="21.4" font-family="${F.bn}" font-weight="600" font-size="2" fill="${ink}">কার্ডধারীর তথ্য</text>
  <rect x="5.6" y="22.3" width="6" height=".45" fill="${red}"/>
  ${rows
    .map(
      ([k, v], i) => `<text x="5.6" y="${25.8 + i * 3.6}" font-family="${F.bn}" font-size="1.65" fill="#64748b">${k}</text><text x="${W - 5.6}" y="${25.8 + i * 3.6}" text-anchor="end" font-family="${F.bn}" font-weight="600" font-size="1.9" fill="${ink}">${esc(v)}</text><line x1="5.6" y1="${26.8 + i * 3.6}" x2="${W - 5.6}" y2="${26.8 + i * 3.6}" stroke="#e2e8f0" stroke-width=".1"/>`,
    )
    .join('')}
  ${termSvg}
  ${signature(c, 5.6, 55.4, 22, ink, '#64748b')}
  ${qr(webUrl(c.org), 35.2, 55, 13.2, { plate: '#ffffff', color: ink, pad: 0.5 })}
  <rect x="35.2" y="55" width="13.2" height="13.2" rx="1.2" fill="none" stroke="${mix(blue, '#ffffff', 0.6)}" stroke-width=".15"/>
  <text x="41.8" y="70.2" text-anchor="middle" font-family="${F.bn}" font-size="1.3" fill="#64748b">ওয়েবসাইট</text>
  <text x="${W / 2}" y="73.3" text-anchor="middle" font-family="${F.bn}" font-size="1.45" fill="#64748b">কার্ডটি পাওয়া গেলে অনুগ্রহ করে নিচের ঠিকানায় ফেরত দিন</text>
  ${addr ? `<text x="${W / 2}" y="75.8" text-anchor="middle" font-family="${F.bn}" font-size="1.6" fill="${ink}">${esc(wrap(addr, F.bn, 1.6, 46)[0])}</text>` : ''}
  ${contact ? `<text x="${W / 2}" y="${addr ? 78.1 : 75.8}" text-anchor="middle" font-family="${F.bn}" font-size="1.55" fill="#334155">${esc(contact)}</text>` : ''}
  <rect x="-3" y="81.8" width="${W + 6}" height="${H - 81.8 + 3}" fill="url(#${g}bl)"/>
  <rect x="-3" y="81.8" width="${W + 6}" height=".55" fill="url(#${g}rd)"/>
  <text x="${W / 2}" y="84.7" text-anchor="middle" font-family="${F.en}" font-weight="800" font-size="1.7" letter-spacing=".5" fill="#ffffff">${esc(d.card_no || '')}</text>`
  return svgDoc(W, H, body, { bleed: o.bleed, defs, round: o.round ? 3.18 : 0 })
}

// ---------------------------------------------------------------------------

export function cardFront(tpl: string, d: CardData, c: CardContext, o: CardOpts = {}): string {
  return tpl === 'aurora' ? auroraFront(d, c, o) : royalFront(d, c, o)
}

export function cardBack(tpl: string, d: CardData, c: CardContext, o: CardOpts = {}): string {
  return tpl === 'aurora' ? auroraBack(d, c, o) : royalBack(d, c, o)
}

/** Pages for export (front + back, with bleed for the printer). */
export function cardPages(tpl: string, d: CardData, c: CardContext, bleed = 0, round = false) {
  const w = CARD_W + bleed * 2
  const h = CARD_H + bleed * 2
  return [
    { svg: cardFront(tpl, d, c, { bleed, round }), w, h, name: 'front' },
    { svg: cardBack(tpl, d, c, { bleed, round }), w, h, name: 'back' },
  ]
}

/** An A4 print sheet: up to 8 cards (front side or back side), with crop marks. */
export function cardSheet(tpl: string, cards: CardData[], c: CardContext, side: 'front' | 'back'): string {
  const cols = 3
  const gapX = 8
  const gapY = 8
  const W = 210
  const H = 297
  const totalW = cols * CARD_W + (cols - 1) * gapX
  const x0 = (W - totalW) / 2
  const y0 = 16
  const parts = cards.slice(0, 9).map((d, i) => {
    // the back side is mirrored left↔right so it lines up with the front when printed duplex
    const col = side === 'back' ? cols - 1 - (i % cols) : i % cols
    const row = Math.floor(i / cols)
    const x = x0 + col * (CARD_W + gapX)
    const y = y0 + row * (CARD_H + gapY)
    const inner = side === 'front' ? cardFront(tpl, d, c, { stamp: false }) : cardBack(tpl, d, c, { stamp: false })
    const nested = inner.replace(/<svg([^>]*?)\swidth="[^"]*"\s+height="[^"]*"/, `<svg$1 x="${x}" y="${y}" width="${CARD_W}" height="${CARD_H}"`)
    const m = 3
    const crop = `<g stroke="#111" stroke-width=".12">
      <line x1="${x - m - 2}" y1="${y}" x2="${x - 1}" y2="${y}"/><line x1="${x}" y1="${y - m - 2}" x2="${x}" y2="${y - 1}"/>
      <line x1="${x + CARD_W + 1}" y1="${y}" x2="${x + CARD_W + m + 2}" y2="${y}"/><line x1="${x + CARD_W}" y1="${y - m - 2}" x2="${x + CARD_W}" y2="${y - 1}"/>
      <line x1="${x - m - 2}" y1="${y + CARD_H}" x2="${x - 1}" y2="${y + CARD_H}"/><line x1="${x}" y1="${y + CARD_H + 1}" x2="${x}" y2="${y + CARD_H + m + 2}"/>
      <line x1="${x + CARD_W + 1}" y1="${y + CARD_H}" x2="${x + CARD_W + m + 2}" y2="${y + CARD_H}"/><line x1="${x + CARD_W}" y1="${y + CARD_H + 1}" x2="${x + CARD_W}" y2="${y + CARD_H + m + 2}"/></g>`
    return nested + crop
  })
  const head = `<text x="${W / 2}" y="9" text-anchor="middle" font-family="${F.bn}" font-size="3" fill="#475569">মিহা আইডি কার্ড — প্রিন্ট শিট (${side === 'front' ? 'সামনের দিক' : 'পেছনের দিক, উল্টো সাজানো'}) · CR80 54×85.6 মিমি · ১০০% স্কেলে প্রিন্ট করুন</text>`
  return svgDoc(W, H, `<rect width="${W}" height="${H}" fill="#fff"/>${head}${parts.join('')}`)
}
