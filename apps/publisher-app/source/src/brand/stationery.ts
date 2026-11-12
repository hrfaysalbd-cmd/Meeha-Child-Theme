/**
 * r43 — স্টেশনারি: every Meeha print design, editable.
 *
 * A design = fields (what the desk can edit) + defaults (filled from
 * মিহার তথ্য, so the office address/phone/email are typed once) +
 * presets (ready texts) + render() → pages (SVG, mm) + mockup().
 * "র টেমপ্লেট" = render() with empty fields (blank lines to fill by hand).
 */
import { F } from './fonts'
import { cardBack, cardFront, CARD_H, CARD_W, ID_TEMPLATES, type CardData } from './idcard'
import { logo, logoH } from './logo'
import { cardsScene, idScene, paperScene, penScene, SCENE_H, SCENE_W, stickerScene, stripScene, tshirtScene } from './mockup'
import type { Page } from './export'
import { qr } from './qr'
import { bnDate, bnDigits, esc, fitSize, goldGrad, guilloche, icon, lines, mix, svgDoc, textWidth, todayIso, uid, wrap, type BrandAssets, type OrgInfo } from './util'
import { SOCIAL_DESIGNS } from './social'
import { visitingBack, visitingFront, VC_H, VC_TEMPLATES, VC_W } from './visiting'

export type Values = Record<string, string>

export interface FieldDef {
  key: string
  label: string
  type: 'text' | 'textarea' | 'date' | 'select' | 'color' | 'toggle' | 'image'
  options?: { value: string; label: string }[]
  help?: string
  rows?: number
  placeholder?: string
}

export interface Preset {
  label: string
  values: Values
}

export interface Ctx {
  org: OrgInfo
  assets: BrandAssets
  /** blank "raw" template: placeholders instead of text */
  raw?: boolean
}

export interface Design {
  key: string
  kind: string
  name: string
  about: string
  fields: FieldDef[]
  defaults: (org: OrgInfo) => Values
  presets?: Preset[]
  render: (v: Values, c: Ctx, bleed?: number) => Page[]
  mockup: (v: Values, c: Ctx) => string
  /** export resolution (social posts use 254 → 10 px per unit) */
  dpi?: number
}

export const KINDS: { key: string; name: string; icon: string }[] = [
  { key: 'letterhead', name: 'লেটারহেড ও চিঠি', icon: '📄' },
  { key: 'appointment', name: 'নিয়োগপত্র', icon: '📜' },
  { key: 'pad', name: 'নোট প্যাড', icon: '🗒️' },
  { key: 'visiting', name: 'ভিজিটিং কার্ড', icon: '💳' },
  { key: 'idcard', name: 'আইডি কার্ড (হাতে)', icon: '🪪' },
  { key: 'invitation', name: 'ইনভাইটেশন কার্ড', icon: '💌' },
  { key: 'certificate', name: 'সনদপত্র', icon: '🏅' },
  { key: 'envelope', name: 'খাম', icon: '✉️' },
  { key: 'lanyard', name: 'ফিতা (ল্যানইয়ার্ড)', icon: '🎗️' },
  { key: 'tshirt', name: 'টি-শার্ট', icon: '👕' },
  { key: 'sticker', name: 'স্টিকার', icon: '🏷️' },
  { key: 'pen', name: 'কলম', icon: '🖊️' },
  { key: 'social', name: 'সোশ্যাল মিডিয়া পোস্ট', icon: '📣' },
]

const on = (v: string | undefined) => v === '1' || v === 'true'
const A4 = { w: 210, h: 297 }

// ---------------------------------------------------------------------------
// Letters (letterhead, appointment, pad)
// ---------------------------------------------------------------------------

const letterFields: FieldDef[] = [
  { key: 'style', label: 'ডিজাইন', type: 'select', options: [ { value: 'classic', label: 'ক্লাসিক (উপরে লোগো, নিচে নীল ফুটার)' }, { value: 'modern', label: 'মডার্ন (বাঁ পাশে নীল পট্টি)' }, { value: 'gold', label: 'প্রিমিয়াম গোল্ড' } ] },
  { key: 'ref', label: 'স্মারক নং', type: 'text', placeholder: 'মিহা/প্রশা/২০২৬/০০১' },
  { key: 'date', label: 'তারিখ', type: 'date' },
  { key: 'to', label: 'প্রাপক (কয়েক লাইন)', type: 'textarea', rows: 4 },
  { key: 'subject', label: 'বিষয়', type: 'text' },
  { key: 'salute', label: 'সম্বোধন', type: 'text', placeholder: 'জনাব,' },
  { key: 'body', label: 'চিঠির মূল লেখা', type: 'textarea', rows: 12 },
  { key: 'closing', label: 'শেষ শব্দ', type: 'text', placeholder: 'ধন্যবাদান্তে,' },
  { key: 'sign_name', label: 'স্বাক্ষরকারীর নাম', type: 'text' },
  { key: 'sign_title', label: 'পদবি', type: 'text' },
  { key: 'cc', label: 'অনুলিপি (ঐচ্ছিক)', type: 'textarea', rows: 3 },
  { key: 'show_sign', label: 'স্বাক্ষর বসান (সেটিংস থেকে)', type: 'toggle' },
  { key: 'show_seal', label: 'সিল বসান', type: 'toggle' },
  { key: 'watermark', label: 'হালকা জলছাপ লোগো', type: 'toggle' },
]

function letterDefaults(org: OrgInfo): Values {
  return {
    style: 'classic',
    ref: `মিহা/প্রশা/${bnDigits(new Date().getFullYear())}/`,
    date: todayIso(),
    to: '',
    subject: '',
    salute: 'জনাব,',
    body: '',
    closing: 'ধন্যবাদান্তে,',
    sign_name: org.signer_name || org.editor_name,
    sign_title: org.signer_title || 'সম্পাদক ও প্রকাশক',
    cc: '',
    show_sign: '1',
    show_seal: '',
    watermark: '1',
  }
}

const LETTER_PRESETS: Preset[] = [
  {
    label: 'সাধারণ চিঠি',
    values: { subject: '', body: 'যথাবিহিত সম্মানপূর্বক জানানো যাচ্ছে যে, …\n\nএ বিষয়ে আপনার সদয় সহযোগিতা কামনা করছি।' },
  },
  {
    label: 'প্রত্যয়নপত্র',
    values: {
      to: '',
      subject: 'প্রত্যয়নপত্র',
      salute: '',
      body: 'এই মর্মে প্রত্যয়ন করা যাচ্ছে যে, [নাম], পিতা: [পিতার নাম], ঠিকানা: [ঠিকানা], মিহা নিউজের [পদবি] হিসেবে [তারিখ] থেকে কর্মরত আছেন। তাঁর আইডি কার্ড নম্বর [কার্ড নম্বর]।\n\nআমার জানামতে তিনি সৎ, নিষ্ঠাবান ও দায়িত্বশীল। রাষ্ট্র ও সমাজবিরোধী কোনো কাজে তাঁর সম্পৃক্ততার তথ্য আমাদের কাছে নেই।\n\nআমি তাঁর সার্বিক মঙ্গল ও উত্তরোত্তর সাফল্য কামনা করি।',
      closing: '',
    },
  },
  {
    label: 'সংবাদ সংগ্রহের অনুমতি',
    values: {
      to: 'বরাবর,\n[কর্মকর্তার পদবি]\n[দপ্তরের নাম]\n[ঠিকানা]',
      subject: 'সংবাদ সংগ্রহে সহযোগিতা ও অনুমতি প্রদান প্রসঙ্গে',
      body: 'যথাবিহিত সম্মানপূর্বক জানানো যাচ্ছে যে, মিহা নিউজের [পদবি] [নাম] (আইডি: [কার্ড নম্বর]) আগামী [তারিখ] তারিখে [বিষয়] সংক্রান্ত সংবাদ সংগ্রহের জন্য আপনার দপ্তরে উপস্থিত হবেন।\n\nঅতএব, তাঁকে প্রয়োজনীয় তথ্য প্রদান ও সংবাদ সংগ্রহে সার্বিক সহযোগিতা করার জন্য বিনীত অনুরোধ জানাচ্ছি।',
    },
  },
  {
    label: 'অভিনন্দন পত্র',
    values: {
      subject: 'অভিনন্দন',
      salute: 'প্রিয় [নাম],',
      body: 'মিহা নিউজ পরিবারের পক্ষ থেকে আপনাকে আন্তরিক অভিনন্দন। [অর্জনের বিবরণ] — আপনার এই সাফল্য আমাদের সবার জন্য গর্বের।\n\nআগামী দিনেও আপনার নিষ্ঠা ও সাহসী সাংবাদিকতা অব্যাহত থাকবে — এই প্রত্যাশা রইল।',
      closing: 'শুভেচ্ছান্তে,',
    },
  },
  {
    label: 'প্রেস বিজ্ঞপ্তি',
    values: {
      to: 'বরাবর,\nবার্তা সম্পাদক\n[পত্রিকা/চ্যানেলের নাম]',
      subject: 'প্রেস বিজ্ঞপ্তি — [শিরোনাম]',
      salute: '',
      body: 'ঢাকা, [তারিখ] — [মূল তথ্য: কে, কী, কোথায়, কখন, কেন]।\n\n[বিস্তারিত দ্বিতীয় অনুচ্ছেদ]\n\nবিজ্ঞপ্তিটি আপনার বহুল প্রচারিত গণমাধ্যমে প্রচারের অনুরোধ রইল।',
    },
  },
  {
    label: 'কারণ দর্শানো নোটিশ',
    values: {
      to: '[নাম]\n[পদবি], মিহা নিউজ\n[এলাকা]',
      subject: 'কারণ দর্শানো নোটিশ',
      salute: '',
      body: 'আপনার বিরুদ্ধে [অভিযোগের বিবরণ] মর্মে অভিযোগ পাওয়া গেছে, যা মিহা নিউজের নীতিমালার পরিপন্থী।\n\nএ বিষয়ে আপনার বক্তব্য পত্র প্রাপ্তির [৭] কর্মদিবসের মধ্যে লিখিতভাবে জানাতে নির্দেশ দেওয়া হলো। নির্ধারিত সময়ে জবাব না পেলে নীতিমালা অনুযায়ী ব্যবস্থা নেওয়া হবে।',
      closing: '',
    },
  },
]

interface Frame {
  /** content box */
  x: number
  y: number
  w: number
  bottom: number
  body: string
  defs: string
  ink: string
  accent: string
}

function contactsOf(org: OrgInfo) {
  return {
    phone: [org.phone, org.phone2].filter(Boolean).map((p) => bnDigits(p)).join(', '),
    email: org.email,
    web: org.website,
    addr: org.address_bn,
  }
}

/** Header + footer of a letterhead page. */
function letterFrame(style: string, org: OrgInfo, _c: Ctx, first: boolean, watermark: boolean, size = A4): Frame {
  const g = uid('lh')
  const blue = org.color_primary || '#025AB2'
  const red = org.color_accent || '#C8102E'
  const gold = org.color_gold || '#C9A54C'
  const ct = contactsOf(org)
  const W = size.w
  const H = size.h
  const k = W / 210
  const wm = watermark ? `<g opacity=".045">${logo(W / 2 - 75 * k, H / 2 - 12 * k, 150 * k, blue)}</g>` : ''
  const lines4 = [
    ct.phone && (['phone', ct.phone] as const),
    ct.email && (['mail', ct.email] as const),
    ct.web && (['web', ct.web] as const),
  ].filter(Boolean) as (readonly ['phone' | 'mail' | 'web', string])[]
  if (style === 'modern') {
    const defs = `<linearGradient id="${g}s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${mix(blue, '#3aa0ff', 0.2)}"/><stop offset="1" stop-color="${mix(blue, '#001a3d', 0.55)}"/></linearGradient>`
    const lw = first ? 62 * k : 44 * k
    const chips = lines4
      .map(([ic, t], i) => {
        const x = 30 * k + i * 56 * k
        return `${icon(ic, x, 33.2 * k, 3.4 * k, blue)}<text x="${x + 4.8 * k}" y="${36 * k}" font-family="${/[ঀ-৿]/.test(t) ? F.bn : F.en}" font-size="${3 * k}" font-weight="500" fill="#334155">${esc(t)}</text>`
      })
      .join('')
    return {
      x: 30 * k,
      y: (first ? 50 : 34) * k,
      w: W - 48 * k,
      bottom: H - 26 * k,
      ink: '#0f172a',
      accent: blue,
      defs,
      body: `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="#fff"/>${wm}
      <rect x="-3" y="-3" width="${15 * k + 3}" height="${H + 6}" fill="url(#${g}s)"/>
      <rect x="${15 * k}" y="-3" width="${1.4 * k}" height="${H + 6}" fill="${red}"/>
      <text transform="translate(${9.6 * k} ${H - 22 * k}) rotate(-90)" font-family="${F.en}" font-weight="800" font-size="${5.4 * k}" letter-spacing="${2.2 * k}" fill="#fff" opacity=".9">${esc(org.name_en.toUpperCase())}</text>
      ${logo(30 * k, 14 * k, lw, blue, red)}
      ${first ? `<text x="${30 * k}" y="${14 * k + logoH(lw) + 5 * k}" font-family="${F.bn}" font-size="${3.3 * k}" fill="#64748b">${esc(org.tagline_bn)}</text>` : ''}
      ${first ? `<text x="${W - 18 * k}" y="${18 * k}" text-anchor="end" font-family="${F.bnSerif}" font-weight="700" font-size="${6.4 * k}" fill="${blue}">${esc(org.name_bn)}</text>${org.reg_no ? `<text x="${W - 18 * k}" y="${23.4 * k}" text-anchor="end" font-family="${F.bn}" font-size="${2.7 * k}" fill="#64748b">${esc(org.reg_no)}</text>` : ''}` : ''}
      ${first ? chips : ''}
      <line x1="${30 * k}" y1="${(first ? 41 : 26) * k}" x2="${W - 18 * k}" y2="${(first ? 41 : 26) * k}" stroke="#e2e8f0" stroke-width="${0.4 * k}"/>
      <line x1="${30 * k}" y1="${H - 19 * k}" x2="${W - 18 * k}" y2="${H - 19 * k}" stroke="${blue}" stroke-width="${0.4 * k}"/>
      <text x="${(W + 12 * k) / 2}" y="${H - 13.5 * k}" text-anchor="middle" font-family="${F.bn}" font-size="${2.9 * k}" fill="#475569">${esc(ct.addr || 'অফিসের ঠিকানা সেটিংসে যোগ করুন')}</text>
      <text x="${(W + 12 * k) / 2}" y="${H - 9 * k}" text-anchor="middle" font-family="${F.en}" font-weight="700" font-size="${2.7 * k}" letter-spacing="${0.4 * k}" fill="${blue}">${esc(org.website.toUpperCase())}</text>`,
    }
  }
  if (style === 'gold') {
    const navy = '#0b1f3a'
    const defs = goldGrad(g + 'au', 0.1)
    const lw = first ? 64 * k : 46 * k
    return {
      x: 24 * k,
      y: (first ? 54 : 34) * k,
      w: W - 48 * k,
      bottom: H - 30 * k,
      ink: '#0f172a',
      accent: navy,
      defs,
      body: `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="#fffdf8"/>${wm}
      <rect x="${8 * k}" y="${8 * k}" width="${W - 16 * k}" height="${H - 16 * k}" fill="none" stroke="url(#${g}au)" stroke-width="${0.8 * k}"/>
      <rect x="${10 * k}" y="${10 * k}" width="${W - 20 * k}" height="${H - 20 * k}" fill="none" stroke="${gold}" stroke-width="${0.25 * k}"/>
      ${logo((W - lw) / 2, 17 * k, lw, navy, gold)}
      ${first ? `<text x="${W / 2}" y="${17 * k + logoH(lw) + 6 * k}" text-anchor="middle" font-family="${F.bn}" font-size="${3.3 * k}" fill="#7c6a3a">${esc(org.tagline_bn)}</text>
      <text x="${W / 2}" y="${17 * k + logoH(lw) + 11.5 * k}" text-anchor="middle" font-family="${F.bn}" font-size="${2.9 * k}" fill="#475569">${esc([ct.phone, ct.email, ct.web].filter(Boolean).join('  •  '))}</text>` : ''}
      <path d="M${24 * k} ${(first ? 47 : 30) * k}H${W / 2 - 6 * k}M${W / 2 + 6 * k} ${(first ? 47 : 30) * k}H${W - 24 * k}" stroke="url(#${g}au)" stroke-width="${0.5 * k}"/>
      <path d="M${W / 2} ${(first ? 45 : 28) * k}l${2 * k} ${2 * k}-${2 * k} ${2 * k}-${2 * k}-${2 * k}z" fill="${gold}"/>
      <line x1="${24 * k}" y1="${H - 24 * k}" x2="${W - 24 * k}" y2="${H - 24 * k}" stroke="url(#${g}au)" stroke-width="${0.4 * k}"/>
      <text x="${W / 2}" y="${H - 18 * k}" text-anchor="middle" font-family="${F.bn}" font-size="${2.9 * k}" fill="#475569">${esc(ct.addr || 'অফিসের ঠিকানা সেটিংসে যোগ করুন')}</text>`,
    }
  }
  // classic
  const lw = first ? 60 * k : 44 * k
  const right = lines4
    .concat(ct.addr ? [['pin' as never, ct.addr.split('\n')[0]] as never] : [])
    .map(([ic, t], i) => {
      const y = (15 + i * 5) * k
      const tw = textWidth(t, /[ঀ-৿]/.test(t) ? F.bn : F.en, 3 * k, 500)
      return `<text x="${W - 22 * k}" y="${y + 2.4 * k}" text-anchor="end" font-family="${/[ঀ-৿]/.test(t) ? F.bn : F.en}" font-weight="500" font-size="${3 * k}" fill="#334155">${esc(t)}</text>${icon(ic, W - 22 * k - tw - 5 * k, y - 0.2 * k, 3.4 * k, blue)}`
    })
    .join('')
  return {
    x: 20 * k,
    y: (first ? 48 : 32) * k,
    w: W - 40 * k,
    bottom: H - 30 * k,
    ink: '#0f172a',
    accent: blue,
    defs: '',
    body: `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="#fff"/>${wm}
    <rect x="-3" y="-3" width="${W + 6}" height="${5 * k}" fill="${blue}"/>
    ${logo(20 * k, 13 * k, lw, blue, red)}
    ${first ? `<text x="${20 * k}" y="${13 * k + logoH(lw) + 5.4 * k}" font-family="${F.bnSerif}" font-weight="700" font-size="${4.6 * k}" fill="#0f172a">${esc(org.name_bn)} <tspan font-family="${F.bn}" font-weight="400" font-size="${3.1 * k}" fill="#64748b">· ${esc(org.tagline_bn)}</tspan></text>` : ''}
    ${first ? right : ''}
    <rect x="${20 * k}" y="${(first ? 38 : 24) * k}" width="${W - 40 * k}" height="${0.45 * k}" fill="${blue}"/>
    <rect x="${20 * k}" y="${(first ? 37.4 : 23.4) * k}" width="${26 * k}" height="${1.6 * k}" fill="${red}"/>
    <rect x="-3" y="${H - 17 * k}" width="${W + 6}" height="${17 * k + 3}" fill="${blue}"/>
    <rect x="-3" y="${H - 17.8 * k}" width="${W + 6}" height="${0.8 * k}" fill="${red}"/>
    <text x="${W / 2}" y="${H - 10.4 * k}" text-anchor="middle" font-family="${F.bn}" font-size="${3 * k}" fill="#fff">${esc(ct.addr || 'অফিসের ঠিকানা সেটিংসে যোগ করুন')}</text>
    <text x="${W / 2}" y="${H - 5.4 * k}" text-anchor="middle" font-family="${F.en}" font-weight="500" font-size="${2.7 * k}" fill="#dbeafe">${esc([ct.phone, ct.email, ct.web].filter(Boolean).join('   |   '))}</text>`,
  }
}

interface Block {
  /** text lines, already wrapped */
  ls: string[]
  size: number
  lh: number
  weight: number
  family: string
  color?: string
  align?: 'left' | 'right' | 'center'
  gapBefore?: number
}

/** Lays letter blocks out over as many A4 pages as needed. */
function flowLetter(v: Values, c: Ctx, blocks: (f: Frame) => Block[], tail: (f: Frame, y: number) => { svg: string; h: number }, size = A4): Page[] {
  const style = v.style || 'classic'
  const pages: Page[] = []
  let first = true
  let f = letterFrame(style, c.org, c, true, on(v.watermark), size)
  let all = blocks(f)
  let body = ''
  let y = f.y
  const flush = () => {
    pages.push({ svg: svgDoc(size.w, size.h, f.body + body, { defs: f.defs }), w: size.w, h: size.h, name: `p${pages.length + 1}` })
    first = false
    f = letterFrame(style, c.org, c, false, on(v.watermark), size)
    body = ''
    y = f.y
  }
  for (const b of all) {
    y += b.gapBefore ?? 0
    for (const l of b.ls) {
      if (y + b.lh > f.bottom) flush()
      const x = b.align === 'right' ? f.x + f.w : b.align === 'center' ? f.x + f.w / 2 : f.x
      const anchor = b.align === 'right' ? 'end' : b.align === 'center' ? 'middle' : 'start'
      if (l) body += `<text x="${x}" y="${(y + b.size).toFixed(2)}" text-anchor="${anchor}" font-family="${b.family}" font-weight="${b.weight}" font-size="${b.size}" fill="${b.color ?? f.ink}">${esc(l)}</text>`
      y += b.lh
    }
  }
  const t = tail(f, y)
  if (y + t.h > f.bottom) flush()
  body += tail(f, y).svg
  all = []
  flush()
  void first
  return pages
}

function signBlock(v: Values, c: Ctx, f: Frame, y: number, closing: string) {
  const sig = on(v.show_sign) ? c.assets.signature : ''
  const seal = on(v.show_seal) ? c.assets.seal : ''
  const x = f.x
  let s = ''
  let yy = y + 6
  if (closing) {
    s += `<text x="${x}" y="${yy}" font-family="${F.bn}" font-size="3.6" fill="${f.ink}">${esc(closing)}</text>`
    yy += 2
  }
  if (sig) s += `<image href="${esc(sig)}" x="${x}" y="${yy}" width="42" height="14" preserveAspectRatio="xMinYMax meet"/>`
  if (seal) s += `<image href="${esc(seal)}" x="${x + 40}" y="${yy - 4}" width="24" height="24" preserveAspectRatio="xMidYMid meet" opacity=".9"/>`
  yy += 16
  s += `<line x1="${x}" y1="${yy}" x2="${x + 52}" y2="${yy}" stroke="#94a3b8" stroke-width=".25"/>`
  if (v.sign_name || c.raw) s += `<text x="${x}" y="${yy + 5}" font-family="${F.bn}" font-weight="600" font-size="3.6" fill="${f.ink}">${esc(c.raw ? '' : v.sign_name)}</text>`
  s += `<text x="${x}" y="${yy + 10}" font-family="${F.bn}" font-size="3.2" fill="#475569">${esc(c.raw ? '' : v.sign_title)}</text>`
  s += `<text x="${x}" y="${yy + 14.6}" font-family="${F.bn}" font-size="3.2" fill="#475569">${esc(c.org.name_bn)}</text>`
  let h = yy + 16 - y
  if (v.cc && !c.raw) {
    const ls = wrap(v.cc, F.bn, 3, f.w)
    s += `<text x="${x}" y="${yy + 24}" font-family="${F.bn}" font-weight="600" font-size="3.1" fill="${f.ink}">অনুলিপি:</text>${lines(ls, x, yy + 29, 4.6, `font-family="${F.bn}" font-size="3" fill="#334155"`)}`
    h += 12 + ls.length * 4.6
  }
  return { svg: s, h }
}

function letterRender(v: Values, c: Ctx): Page[] {
  if (c.raw) {
    const f = letterFrame(v.style || 'classic', c.org, c, true, on(v.watermark))
    return [{ svg: svgDoc(210, 297, f.body, { defs: f.defs }), w: 210, h: 297, name: 'p1' }]
  }
  const S = 3.6
  return flowLetter(
    v,
    c,
    (f) => {
      const out: Block[] = []
      const meta = `${v.ref ? `স্মারক নং: ${v.ref}` : ''}`
      out.push({ ls: [meta], size: 3.2, lh: 0, weight: 400, family: F.bn, color: '#334155' })
      out.push({ ls: [v.date ? `তারিখ: ${bnDate(v.date)}` : ''], size: 3.2, lh: 9, weight: 400, family: F.bn, color: '#334155', align: 'right' })
      if (v.to) out.push({ ls: wrap(v.to, F.bn, S, f.w * 0.7), size: S, lh: 5.6, weight: 400, family: F.bn, gapBefore: 2 })
      if (v.subject) out.push({ ls: wrap(`বিষয়: ${v.subject}`, F.bn, S, f.w, 600), size: S, lh: 5.8, weight: 600, family: F.bn, gapBefore: 5 })
      if (v.salute) out.push({ ls: [v.salute], size: S, lh: 6, weight: 400, family: F.bn, gapBefore: 4 })
      out.push({ ls: wrap(v.body, F.bn, S, f.w), size: S, lh: 6.3, weight: 400, family: F.bn, gapBefore: 3 })
      return out
    },
    (f, y) => signBlock(v, c, f, y + 4, v.closing),
  )
}

function paperMock(pages: Page[], caption: string) {
  return paperScene(pages[0], caption, pages[1])
}

const letterhead: Design = {
  key: 'letterhead',
  kind: 'letterhead',
  name: 'লেটারহেড ও চিঠি',
  about: 'A4 · তিন স্টাইল · চিঠি লিখে PDF/JPG, অথবা খালি লেটারহেড প্রিন্ট',
  fields: letterFields,
  defaults: letterDefaults,
  presets: LETTER_PRESETS,
  render: (v, c) => letterRender(v, c),
  mockup: (v, c) => paperMock(letterRender(v, c), 'লেটারহেড · A4'),
}

// ---- নিয়োগপত্র ----

const appointmentFields: FieldDef[] = [
  { key: 'style', label: 'ডিজাইন', type: 'select', options: letterFields[0].options },
  { key: 'ref', label: 'স্মারক নং', type: 'text' },
  { key: 'date', label: 'তারিখ', type: 'date' },
  { key: 'name', label: 'নাম', type: 'text' },
  { key: 'father', label: 'পিতার নাম (ঐচ্ছিক)', type: 'text' },
  { key: 'address', label: 'ঠিকানা', type: 'textarea', rows: 2 },
  { key: 'designation', label: 'পদবি', type: 'text' },
  { key: 'area', label: 'কর্মএলাকা', type: 'text' },
  { key: 'card_no', label: 'আইডি কার্ড নম্বর', type: 'text' },
  { key: 'join', label: 'যোগদানের তারিখ', type: 'date' },
  { key: 'valid', label: 'মেয়াদ শেষ', type: 'date' },
  { key: 'pay', label: 'সম্মানী / বেতন', type: 'text', placeholder: 'প্রকাশিত সংবাদভিত্তিক সম্মানী' },
  { key: 'terms', label: 'শর্তাবলি (প্রতি লাইনে একটি)', type: 'textarea', rows: 7 },
  { key: 'sign_name', label: 'স্বাক্ষরকারীর নাম', type: 'text' },
  { key: 'sign_title', label: 'পদবি', type: 'text' },
  { key: 'show_sign', label: 'স্বাক্ষর বসান', type: 'toggle' },
  { key: 'show_seal', label: 'সিল বসান', type: 'toggle' },
  { key: 'watermark', label: 'জলছাপ', type: 'toggle' },
]

export const APPOINTMENT_TERMS = [
  'আপনি মিহা নিউজের সম্পাদকীয় নীতিমালা, আচরণবিধি ও দেশের প্রচলিত আইন মেনে সংবাদ সংগ্রহ ও পরিবেশন করবেন।',
  'যাচাই ছাড়া কোনো সংবাদ, ছবি বা ভিডিও পাঠাবেন না; সূত্রের গোপনীয়তা রক্ষা করবেন।',
  'মিহা নিউজের নাম, লোগো ও আইডি কার্ড ব্যক্তিগত সুবিধা বা কোনো অনৈতিক কাজে ব্যবহার করা যাবে না।',
  'আইডি কার্ডের মেয়াদ এক বছর; মেয়াদ শেষে রিনিউ না করলে কার্ড ও এই নিয়োগ স্বয়ংক্রিয়ভাবে বাতিল হবে।',
  'নীতিমালা ভঙ্গ করলে কর্তৃপক্ষ কোনো কারণ দর্শানো ছাড়াই এই নিয়োগ বাতিল করার অধিকার সংরক্ষণ করে।',
]

function appointmentDefaults(org: OrgInfo): Values {
  const d = new Date()
  const n = new Date(d)
  n.setFullYear(d.getFullYear() + 1)
  const iso = (x: Date) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`
  return {
    ...letterDefaults(org),
    ref: `মিহা/নিয়োগ/${bnDigits(d.getFullYear())}/`,
    name: '',
    father: '',
    address: '',
    designation: 'জেলা প্রতিনিধি',
    area: '',
    card_no: '',
    join: iso(d),
    valid: iso(n),
    pay: 'প্রকাশিত সংবাদভিত্তিক সম্মানী (মিহা অ্যাপের হিসাব অনুযায়ী)',
    terms: APPOINTMENT_TERMS.join('\n'),
    show_seal: '1',
  }
}

function appointmentRender(v: Values, c: Ctx): Page[] {
  const S = 3.6
  const blank = (s: string, n = 26) => (c.raw ? '.'.repeat(n) : s || '.'.repeat(n))
  return flowLetter(
    v,
    c,
    (f) => {
      const out: Block[] = []
      out.push({ ls: [v.ref ? `স্মারক নং: ${blank(v.ref)}` : ''], size: 3.2, lh: 0, weight: 400, family: F.bn, color: '#334155' })
      out.push({ ls: [`তারিখ: ${c.raw ? '..../..../......' : bnDate(v.date)}`], size: 3.2, lh: 10, weight: 400, family: F.bn, color: '#334155', align: 'right' })
      out.push({ ls: ['নিয়োগপত্র'], size: 7, lh: 10, weight: 700, family: F.bnSerif, color: f.accent, align: 'center' })
      out.push({ ls: ['LETTER OF APPOINTMENT'], size: 2.6, lh: 9, weight: 700, family: F.en, color: '#94a3b8', align: 'center' })
      const to = [`জনাব ${blank(v.name, 34)}`, v.father || c.raw ? `পিতা: ${blank(v.father, 30)}` : '', ...(v.address || c.raw ? wrap(`ঠিকানা: ${blank(v.address, 40)}`, F.bn, S, f.w * 0.75) : [])].filter(Boolean)
      out.push({ ls: to, size: S, lh: 5.6, weight: 400, family: F.bn })
      const p1 = `আপনার আবেদন, যোগ্যতা ও অভিজ্ঞতা বিবেচনা করে আপনাকে ${c.org.name_bn}-এর “${blank(v.designation, 18)}” পদে ${blank(v.area, 18)} এলাকার জন্য ${c.raw ? '..../..../......' : bnDate(v.join)} তারিখ থেকে নিয়োগ দেওয়া হলো। আপনার আইডি কার্ড নম্বর ${blank(v.card_no, 14)}; এই নিয়োগের মেয়াদ ${c.raw ? '..../..../......' : bnDate(v.valid)} পর্যন্ত, যা নবায়নযোগ্য।`
      out.push({ ls: wrap(p1, F.bn, S, f.w), size: S, lh: 6.3, weight: 400, family: F.bn, gapBefore: 5 })
      out.push({ ls: wrap(`সম্মানী: ${blank(v.pay, 40)}`, F.bn, S, f.w), size: S, lh: 6.3, weight: 400, family: F.bn, gapBefore: 2 })
      out.push({ ls: ['শর্তাবলি:'], size: S, lh: 6.3, weight: 600, family: F.bn, gapBefore: 3 })
      const terms = (v.terms || '').split('\n').map((t) => t.trim()).filter(Boolean)
      terms.forEach((t, i) => {
        const ls = wrap(`${bnDigits(i + 1)}. ${t}`, F.bn, 3.4, f.w - 4)
        out.push({ ls: ls.map((l, j) => (j ? '    ' + l : l)), size: 3.4, lh: 5.8, weight: 400, family: F.bn })
      })
      out.push({ ls: wrap(`${c.org.name_bn} পরিবারে আপনাকে স্বাগতম। আপনার সততা ও সাহসী সাংবাদিকতা আমাদের এগিয়ে নেবে।`, F.bn, S, f.w), size: S, lh: 6.3, weight: 400, family: F.bn, gapBefore: 3 })
      return out
    },
    (f, y) => signBlock(v, c, f, y + 2, 'শুভেচ্ছান্তে,'),
  )
}

const appointment: Design = {
  key: 'appointment',
  kind: 'appointment',
  name: 'নিয়োগপত্র',
  about: 'আইডি কার্ড প্যাকেজের নিয়োগপত্র — কার্ডের তথ্য থেকে নিজে পূরণ হয়',
  fields: appointmentFields,
  defaults: appointmentDefaults,
  render: (v, c) => appointmentRender(v, c),
  mockup: (v, c) => paperMock(appointmentRender(v, c), 'নিয়োগপত্র · A4'),
}

// ---- নোট প্যাড (A5) ----

const padFields: FieldDef[] = [
  { key: 'style', label: 'ডিজাইন', type: 'select', options: [ { value: 'classic', label: 'ক্লাসিক' }, { value: 'modern', label: 'মডার্ন' }, { value: 'gold', label: 'গোল্ড' } ] },
  { key: 'size', label: 'মাপ', type: 'select', options: [ { value: 'a5', label: 'A5 (১৪৮×২১০ মিমি)' }, { value: 'a4', label: 'A4' } ] },
  { key: 'ruled', label: 'লাইন টানা', type: 'toggle' },
  { key: 'title', label: 'উপরের লেখা (ঐচ্ছিক)', type: 'text', placeholder: 'নোট / মিটিং নোট / সংবাদ সংগ্রহ' },
  { key: 'watermark', label: 'জলছাপ', type: 'toggle' },
]

function padRender(v: Values, c: Ctx): Page[] {
  const size = v.size === 'a4' ? A4 : { w: 148, h: 210 }
  const f = letterFrame(v.style || 'classic', c.org, c, true, on(v.watermark), size)
  let body = f.body
  const k = size.w / 210
  let y = f.y + 4 * k
  if (v.title) {
    body += `<text x="${f.x}" y="${y}" font-family="${F.bn}" font-weight="600" font-size="${4.4 * k}" fill="${f.accent}">${esc(v.title)}</text><text x="${f.x + f.w}" y="${y}" text-anchor="end" font-family="${F.bn}" font-size="${3.2 * k}" fill="#94a3b8">তারিখ: ........................</text>`
    y += 8 * k
  }
  if (on(v.ruled)) {
    for (let ly = y + 8 * k; ly < f.bottom - 2; ly += 8.5 * k) body += `<line x1="${f.x}" y1="${ly}" x2="${f.x + f.w}" y2="${ly}" stroke="${mix(f.accent, '#ffffff', 0.78)}" stroke-width="${0.22 * k}"/>`
  }
  return [{ svg: svgDoc(size.w, size.h, body, { defs: f.defs }), w: size.w, h: size.h, name: 'pad' }]
}

const pad: Design = {
  key: 'pad',
  kind: 'pad',
  name: 'নোট প্যাড',
  about: 'A5/A4 প্যাড — লাইন টানা, অফিসের তথ্যসহ',
  fields: padFields,
  defaults: () => ({ style: 'modern', size: 'a5', ruled: '1', title: 'নোট', watermark: '1' }),
  presets: [
    { label: 'নোট', values: { title: 'নোট' } },
    { label: 'মিটিং নোট', values: { title: 'মিটিং নোট' } },
    { label: 'সংবাদ সংগ্রহ', values: { title: 'সংবাদ সংগ্রহ — মাঠের নোট' } },
    { label: 'খালি', values: { title: '' } },
  ],
  render: (v, c) => padRender(v, c),
  mockup: (v, c) => paperScene(padRender(v, c)[0], 'নোট প্যাড'),
}

// ---------------------------------------------------------------------------
// Visiting card (hand-filled) + ID card (hand-filled)
// ---------------------------------------------------------------------------

const visitingFields: FieldDef[] = [
  { key: 'tpl', label: 'ডিজাইন', type: 'select', options: VC_TEMPLATES.map((t) => ({ value: t.key, label: t.name })) },
  { key: 'name_bn', label: 'নাম (বাংলা)', type: 'text' },
  { key: 'name_en', label: 'নাম (ইংরেজি)', type: 'text' },
  { key: 'designation', label: 'পদবি', type: 'text' },
  { key: 'designation_en', label: 'পদবি (ইংরেজি, ঐচ্ছিক)', type: 'text' },
  { key: 'phone', label: 'মোবাইল', type: 'text' },
  { key: 'email', label: 'ইমেইল', type: 'text' },
  { key: 'area', label: 'এলাকা/অফিস', type: 'text' },
  { key: 'photo', label: 'ছবি', type: 'image' },
  { key: 'show_photo', label: 'ছবি দেখান', type: 'toggle' },
  { key: 'qr_url', label: 'পেছনের কিউআর লিংক', type: 'text', help: 'খালি রাখলে ওয়েবসাইট' },
]

const visitingData = (v: Values, c: Ctx) => ({
  name_bn: c.raw ? 'আপনার নাম' : v.name_bn,
  name_en: c.raw ? 'YOUR NAME' : v.name_en,
  designation: c.raw ? 'পদবি' : v.designation,
  designation_en: v.designation_en,
  phone: c.raw ? '০১XXX-XXXXXX' : v.phone,
  email: c.raw ? 'name@meeha.news' : v.email,
  area: c.raw ? '' : v.area,
  photo: c.raw ? '' : v.photo,
  show_photo: on(v.show_photo),
  qr_url: v.qr_url || c.org.home_url || `https://${c.org.website}`,
  qr_label: v.qr_url ? 'স্ক্যান করুন' : c.org.website,
})

const visiting: Design = {
  key: 'visiting',
  kind: 'visiting',
  name: 'ভিজিটিং কার্ড',
  about: '৯০×৫৪ মিমি · দুই পাশ · ২ ডিজাইন',
  fields: visitingFields,
  defaults: (org) => ({ tpl: 'midnight', name_bn: org.editor_name || '', name_en: '', designation: org.editor_title || 'সম্পাদক', designation_en: '', phone: org.phone, email: org.email, area: 'প্রধান কার্যালয়', photo: '', show_photo: '1', qr_url: '' }),
  render: (v, c, bleed = 0) => {
    const d = visitingData(v, c)
    return [
      { svg: visitingFront(v.tpl, d, c.org, bleed), w: VC_W + 2 * bleed, h: VC_H + 2 * bleed, name: 'front' },
      { svg: visitingBack(v.tpl, d, c.org, bleed), w: VC_W + 2 * bleed, h: VC_H + 2 * bleed, name: 'back' },
    ]
  },
  mockup: (v, c) => {
    const d = visitingData(v, c)
    return cardsScene({ svg: visitingFront(v.tpl, d, c.org), w: VC_W, h: VC_H }, { svg: visitingBack(v.tpl, d, c.org), w: VC_W, h: VC_H }, 'ভিজিটিং কার্ড · ৯০×৫৪ মিমি')
  },
}

const idFields: FieldDef[] = [
  { key: 'tpl', label: 'ডিজাইন', type: 'select', options: ID_TEMPLATES.map((t) => ({ value: t.key, label: t.name })) },
  { key: 'name_bn', label: 'নাম', type: 'text' },
  { key: 'name_en', label: 'নাম (ইংরেজি)', type: 'text' },
  { key: 'designation', label: 'পদবি', type: 'text' },
  { key: 'area', label: 'এলাকা', type: 'text' },
  { key: 'card_no', label: 'কার্ড নম্বর', type: 'text' },
  { key: 'issued', label: 'ইস্যু', type: 'date' },
  { key: 'expires', label: 'মেয়াদ', type: 'date' },
  { key: 'blood', label: 'রক্তের গ্রুপ', type: 'text' },
  { key: 'phone', label: 'মোবাইল', type: 'text' },
  { key: 'emergency', label: 'জরুরি যোগাযোগ', type: 'text' },
  { key: 'photo', label: 'ছবি', type: 'image' },
  { key: 'verify_url', label: 'সামনের কিউআর (যাচাই লিংক)', type: 'text', help: 'আইডি কার্ড ডেস্ক থেকে ইস্যু করা কার্ডে এটা নিজে বসে।' },
]

const idData = (v: Values, c: Ctx): CardData => ({
  name_bn: c.raw ? 'নাম' : v.name_bn,
  name_en: c.raw ? 'NAME' : v.name_en,
  designation: c.raw ? 'পদবি' : v.designation,
  area: c.raw ? 'এলাকা' : v.area,
  card_no: c.raw ? 'MN-XX-XXXX' : v.card_no,
  issued: v.issued,
  expires: c.raw ? '' : v.expires,
  blood: c.raw ? '' : v.blood,
  phone: c.raw ? '' : v.phone,
  emergency: c.raw ? '' : v.emergency,
  photo: c.raw ? '' : v.photo,
  verify_url: v.verify_url || c.org.verify_base || `https://${c.org.website}/verify/`,
  status: 'preview',
})

const idcard: Design = {
  key: 'idcard',
  kind: 'idcard',
  name: 'আইডি কার্ড (হাতে বানানো)',
  about: 'অতিথি/ইভেন্ট/অফিস কার্ড — আসল কার্ড আইডি কার্ড ডেস্ক থেকে ইস্যু হয়',
  fields: idFields,
  defaults: () => {
    const d = new Date()
    const iso = (x: Date) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`
    const n = new Date(d)
    n.setFullYear(d.getFullYear() + 1)
    return { tpl: 'royal', name_bn: '', name_en: '', designation: 'অতিথি সাংবাদিক', area: '', card_no: '', issued: iso(d), expires: iso(n), blood: '', phone: '', emergency: '', photo: '', verify_url: '' }
  },
  render: (v, c, bleed = 0) => {
    const d = idData(v, c)
    const ctx = { org: c.org, assets: c.assets }
    return [
      { svg: cardFront(v.tpl, d, ctx, { bleed }), w: CARD_W + 2 * bleed, h: CARD_H + 2 * bleed, name: 'front' },
      { svg: cardBack(v.tpl, d, ctx, { bleed }), w: CARD_W + 2 * bleed, h: CARD_H + 2 * bleed, name: 'back' },
    ]
  },
  mockup: (v, c) => {
    const d = idData(v, c)
    const ctx = { org: c.org, assets: c.assets }
    return idScene(cardFront(v.tpl, d, ctx, { round: true }), cardBack(v.tpl, d, ctx, { round: true }), lanyardSvg({ ...lanyard.defaults(c.org) }, c.org), 'আইডি কার্ড + ফিতা')
  },
}

// ---------------------------------------------------------------------------
// Invitation (5 × 7 in) + certificate (A4 landscape)
// ---------------------------------------------------------------------------

const INV = { w: 127, h: 178 }

const invitationFields: FieldDef[] = [
  { key: 'style', label: 'ডিজাইন', type: 'select', options: [ { value: 'royal', label: 'রয়্যাল নেভি-গোল্ড' }, { value: 'light', label: 'সাদা-নীল আধুনিক' } ] },
  { key: 'kicker', label: 'উপরের লেখা', type: 'text' },
  { key: 'event', label: 'অনুষ্ঠানের নাম', type: 'text' },
  { key: 'message', label: 'আমন্ত্রণ বার্তা', type: 'textarea', rows: 4 },
  { key: 'date', label: 'তারিখ', type: 'date' },
  { key: 'time', label: 'সময়', type: 'text' },
  { key: 'venue', label: 'স্থান', type: 'textarea', rows: 2 },
  { key: 'host', label: 'আমন্ত্রণকারী', type: 'text' },
  { key: 'guest', label: 'অতিথির নাম (ঐচ্ছিক)', type: 'text' },
  { key: 'rsvp', label: 'যোগাযোগ / আরএসভিপি', type: 'text' },
  { key: 'map_url', label: 'ম্যাপ লিংক (কিউআর)', type: 'text' },
]

const INVITATION_PRESETS: Preset[] = [
  { label: 'প্রতিষ্ঠাবার্ষিকী', values: { kicker: 'সবিনয় আমন্ত্রণ', event: 'মিহা নিউজের প্রতিষ্ঠাবার্ষিকী', message: 'সত্য ও সাহসী সাংবাদিকতার আরও একটি বছর। এই আনন্দঘন মুহূর্তে আপনার উপস্থিতি আমাদের অনুপ্রাণিত করবে।' } },
  { label: 'সংবাদ সম্মেলন', values: { kicker: 'আমন্ত্রণ', event: 'সংবাদ সম্মেলন', message: '[বিষয়] নিয়ে এক সংবাদ সম্মেলনের আয়োজন করা হয়েছে। সংবাদ সংগ্রহের জন্য আপনার প্রতিনিধি পাঠানোর অনুরোধ রইল।' } },
  { label: 'সাংবাদিক কর্মশালা', values: { kicker: 'প্রশিক্ষণ কর্মশালা', event: 'অনুসন্ধানী সাংবাদিকতা কর্মশালা', message: 'মাঠপর্যায়ের সাংবাদিকদের দক্ষতা বাড়াতে দিনব্যাপী কর্মশালায় আপনাকে আমন্ত্রণ জানাচ্ছি।' } },
  { label: 'প্রতিনিধি সম্মেলন', values: { kicker: 'সবিনয় আমন্ত্রণ', event: 'মিহা নিউজ প্রতিনিধি সম্মেলন', message: 'দেশজুড়ে মিহা পরিবারের সদস্যদের মিলনমেলা, সম্মাননা প্রদান ও নৈশভোজে আপনি আমন্ত্রিত।' } },
  { label: 'ইফতার মাহফিল', values: { kicker: 'দাওয়াত', event: 'ইফতার ও দোয়া মাহফিল', message: 'পবিত্র রমজান উপলক্ষে আয়োজিত ইফতার ও দোয়া মাহফিলে আপনাকে সাদর আমন্ত্রণ।' } },
]

function invitationRender(v: Values, c: Ctx): Page[] {
  const W = INV.w
  const H = INV.h
  const g = uid('iv')
  const royal = v.style !== 'light'
  const gold = c.org.color_gold || '#C9A54C'
  const blue = c.org.color_primary || '#025AB2'
  const red = c.org.color_accent || '#C8102E'
  const ink = royal ? '#ffffff' : '#0b1f3a'
  const sub = royal ? '#cbd5e1' : '#475569'
  const acc = royal ? gold : blue
  const defs = `${goldGrad(g + 'au', 0.1)}<linearGradient id="${g}bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${royal ? '#0d2f63' : '#ffffff'}"/><stop offset="1" stop-color="${royal ? '#040e1f' : mix(blue, '#ffffff', 0.92)}"/></linearGradient>`
  const corner = (x: number, y: number, sx: number, sy: number) =>
    `<path transform="translate(${x} ${y}) scale(${sx} ${sy})" d="M0 22V8Q0 0 8 0H22M4 22V10Q4 4 10 4H22M0 0Q10 10 14 2M0 0Q10 10 2 14" fill="none" stroke="${royal ? `url(#${g}au)` : blue}" stroke-width=".6"/>`
  const lw = 50
  const event = c.raw ? '' : v.event
  const evSize = fitSize(event, F.bnSerif, 11, W - 26, 700, 6)
  const evLines = textWidth(event, F.bnSerif, evSize, 700) > W - 26 ? wrap(event, F.bnSerif, 7.4, W - 26, 700) : [event]
  const evS = evLines.length > 1 ? 7.4 : evSize
  let y = 58
  let body = `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="url(#${g}bg)"/>
  ${royal ? guilloche(-3, -3, W + 6, H + 6, gold, 0.07, 24, 0.08) : ''}
  <rect x="7" y="7" width="${W - 14}" height="${H - 14}" fill="none" stroke="${royal ? `url(#${g}au)` : blue}" stroke-width=".5"/>
  ${corner(5, 5, 1, 1)}${corner(W - 5, 5, -1, 1)}${corner(5, H - 5, 1, -1)}${corner(W - 5, H - 5, -1, -1)}
  ${logo((W - lw) / 2, 18, lw, royal ? '#ffffff' : blue, royal ? gold : red)}
  <text x="${W / 2}" y="${18 + logoH(lw) + 12}" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="5" fill="${acc}" letter-spacing=".3">${esc(c.raw ? 'সবিনয় আমন্ত্রণ' : v.kicker)}</text>`
  if (v.guest && !c.raw) {
    body += `<text x="${W / 2}" y="${y - 3}" text-anchor="middle" font-family="${F.bn}" font-size="3.6" fill="${sub}">প্রিয় ${esc(v.guest)}</text>`
    y += 3
  }
  body += lines(evLines, W / 2, y + evS, evS * 1.25, `text-anchor="middle" font-family="${F.bnSerif}" font-weight="700" font-size="${evS}" fill="${ink}"`)
  y += evS * 1.25 * evLines.length + 4
  body += `<path d="M${W / 2 - 22} ${y}H${W / 2 - 4}M${W / 2 + 4} ${y}H${W / 2 + 22}" stroke="${royal ? `url(#${g}au)` : red}" stroke-width=".5"/><circle cx="${W / 2}" cy="${y}" r="1.2" fill="${royal ? gold : red}"/>`
  y += 8
  const msg = wrap(c.raw ? '' : v.message, F.bn, 3.8, W - 30)
  body += lines(msg, W / 2, y, 6, `text-anchor="middle" font-family="${F.bn}" font-size="3.8" fill="${sub}"`)
  y += msg.length * 6 + 6
  const infoRow = (label: string, val: string) => {
    const ls = wrap(val, F.bn, 4, W - 34, 600)
    const out = `<text x="${W / 2}" y="${y}" text-anchor="middle" font-family="${F.bn}" font-size="3" fill="${acc}" letter-spacing=".4">${label}</text>${lines(ls, W / 2, y + 5.6, 5.4, `text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="4" fill="${ink}"`)}`
    y += 6 + ls.length * 5.4 + 3.4
    return out
  }
  body += infoRow('তারিখ ও সময়', c.raw ? '................................' : [bnDate(v.date), v.time].filter(Boolean).join(' · '))
  body += infoRow('স্থান', c.raw ? '................................' : v.venue)
  const foot = H - 22
  body += `<text x="${W / 2}" y="${foot}" text-anchor="middle" font-family="${F.bn}" font-size="3.2" fill="${sub}">আমন্ত্রণে</text><text x="${W / 2}" y="${foot + 5.6}" text-anchor="middle" font-family="${F.bnSerif}" font-weight="700" font-size="4.6" fill="${acc}">${esc(v.host || c.org.name_bn + ' পরিবার')}</text>`
  if (v.rsvp && !c.raw) body += `<text x="${W / 2}" y="${foot + 10.6}" text-anchor="middle" font-family="${F.bn}" font-size="2.8" fill="${sub}">যোগাযোগ: ${esc(bnDigits(v.rsvp))}</text>`
  if (v.map_url && !c.raw) body += qr(v.map_url, W - 30, H - 32, 16, { plate: '#ffffff', radius: 1.4, color: '#0b1f3a' }) + `<text x="${W - 22}" y="${H - 13.4}" text-anchor="middle" font-family="${F.bn}" font-size="2.2" fill="${sub}">ম্যাপ</text>`
  return [{ svg: svgDoc(W, H, body, { defs }), w: W, h: H, name: 'card' }]
}

const invitation: Design = {
  key: 'invitation',
  kind: 'invitation',
  name: 'ইনভাইটেশন কার্ড',
  about: '৫×৭ ইঞ্চি · রয়্যাল ও আধুনিক · কিউআরে ম্যাপ',
  fields: invitationFields,
  defaults: (org) => ({ style: 'royal', kicker: 'সবিনয় আমন্ত্রণ', event: 'মিহা নিউজ প্রতিনিধি সম্মেলন', message: INVITATION_PRESETS[3].values.message, date: todayIso(), time: 'বিকেল ৪টা', venue: org.address_bn || 'অনুষ্ঠানস্থল', host: `${org.name_bn} পরিবার`, guest: '', rsvp: org.phone, map_url: '' }),
  presets: INVITATION_PRESETS,
  render: (v, c) => invitationRender(v, c),
  mockup: (v, c) => paperScene(invitationRender(v, c)[0], 'ইনভাইটেশন · ৫×৭ ইঞ্চি', invitationRender({ ...v, style: v.style === 'light' ? 'royal' : 'light' }, c)[0]),
}

const certificateFields: FieldDef[] = [
  { key: 'title', label: 'শিরোনাম', type: 'text' },
  { key: 'name', label: 'প্রাপকের নাম', type: 'text' },
  { key: 'line', label: 'বিবরণ', type: 'textarea', rows: 3 },
  { key: 'date', label: 'তারিখ', type: 'date' },
  { key: 'sign1_name', label: 'স্বাক্ষর ১ — নাম', type: 'text' },
  { key: 'sign1_title', label: 'স্বাক্ষর ১ — পদবি', type: 'text' },
  { key: 'sign2_name', label: 'স্বাক্ষর ২ — নাম', type: 'text' },
  { key: 'sign2_title', label: 'স্বাক্ষর ২ — পদবি', type: 'text' },
  { key: 'show_sign', label: 'স্বাক্ষর ১-এ সংরক্ষিত স্বাক্ষর', type: 'toggle' },
  { key: 'show_seal', label: 'সিল বসান', type: 'toggle' },
]

function certificateRender(v: Values, c: Ctx): Page[] {
  const W = 297
  const H = 210
  const g = uid('ce')
  const gold = c.org.color_gold || '#C9A54C'
  const navy = '#0b1f3a'
  const defs = `${goldGrad(g + 'au', 0.1)}<radialGradient id="${g}bg" cx=".5" cy=".5" r=".75"><stop offset="0" stop-color="#fffdf6"/><stop offset="1" stop-color="#f4ead2"/></radialGradient>`
  const lw = 64
  const name = c.raw ? '' : v.name
  const nSize = fitSize(name, F.bnSerif, 13, 200, 700, 8)
  const desc = wrap(c.raw ? '' : v.line, F.bn, 4.6, 200)
  const sig = on(v.show_sign) ? c.assets.signature : ''
  const seal = on(v.show_seal) ? c.assets.seal : ''
  const signAt = (x: number, n: string, t: string, img?: string) =>
    `${img ? `<image href="${esc(img)}" x="${x - 25}" y="164" width="50" height="14" preserveAspectRatio="xMidYMax meet"/>` : ''}<line x1="${x - 32}" y1="180" x2="${x + 32}" y2="180" stroke="${navy}" stroke-width=".3"/><text x="${x}" y="186" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="4" fill="${navy}">${esc(c.raw ? '' : n)}</text><text x="${x}" y="191.5" text-anchor="middle" font-family="${F.bn}" font-size="3.4" fill="#64748b">${esc(c.raw ? '' : t)}</text>`
  const body = `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="url(#${g}bg)"/>
  ${guilloche(-3, -3, W + 6, H + 6, gold, 0.1, 26, 0.12)}
  <rect x="8" y="8" width="${W - 16}" height="${H - 16}" fill="none" stroke="url(#${g}au)" stroke-width="2.2"/>
  <rect x="13" y="13" width="${W - 26}" height="${H - 26}" fill="none" stroke="${navy}" stroke-width=".4"/>
  ${logo((W - lw) / 2, 24, lw, navy, gold)}
  <text x="${W / 2}" y="62" text-anchor="middle" font-family="${F.bnSerif}" font-weight="700" font-size="14" fill="${navy}">${esc(c.raw ? 'সনদপত্র' : v.title)}</text>
  <path d="M${W / 2 - 50} 68H${W / 2 - 6}M${W / 2 + 6} 68H${W / 2 + 50}" stroke="url(#${g}au)" stroke-width=".8"/><circle cx="${W / 2}" cy="68" r="2" fill="${gold}"/>
  <text x="${W / 2}" y="84" text-anchor="middle" font-family="${F.bn}" font-size="4.6" fill="#475569">এই সনদ প্রদান করা হলো</text>
  <text x="${W / 2}" y="${104}" text-anchor="middle" font-family="${F.bnSerif}" font-weight="700" font-size="${nSize}" fill="${navy}">${esc(name) || '<tspan fill="#94a3b8">...................................................</tspan>'}</text>
  <line x1="${W / 2 - 80}" y1="109" x2="${W / 2 + 80}" y2="109" stroke="${gold}" stroke-width=".5"/>
  ${lines(desc, W / 2, 122, 7, `text-anchor="middle" font-family="${F.bn}" font-size="4.6" fill="#334155"`)}
  <text x="${W / 2}" y="${Math.max(146, 122 + desc.length * 7 + 6)}" text-anchor="middle" font-family="${F.bn}" font-size="3.8" fill="#64748b">তারিখ: ${esc(c.raw ? '..................' : bnDate(v.date))}</text>
  ${signAt(70, v.sign1_name, v.sign1_title, sig)}${signAt(W - 70, v.sign2_name, v.sign2_title)}
  ${seal ? `<image href="${esc(seal)}" x="${W / 2 - 16}" y="156" width="32" height="32" opacity=".9"/>` : `<circle cx="${W / 2}" cy="172" r="15" fill="url(#${g}au)"/><circle cx="${W / 2}" cy="172" r="12" fill="none" stroke="#fff" stroke-width=".5" stroke-dasharray="1 1"/><text x="${W / 2}" y="170.6" text-anchor="middle" font-family="${F.en}" font-weight="800" font-size="3.6" fill="${navy}">MEEHA</text><text x="${W / 2}" y="175.6" text-anchor="middle" font-family="${F.en}" font-weight="700" font-size="2.6" fill="${navy}" letter-spacing=".6">NEWS</text>`}`
  return [{ svg: svgDoc(W, H, body, { defs }), w: W, h: H, name: 'certificate' }]
}

const certificate: Design = {
  key: 'certificate',
  kind: 'certificate',
  name: 'সনদপত্র / সম্মাননা',
  about: 'A4 আড়াআড়ি · গোল্ড বর্ডার · দুই স্বাক্ষর',
  fields: certificateFields,
  defaults: (org) => ({ title: 'সম্মাননা সনদ', name: '', line: 'সাহসী ও বস্তুনিষ্ঠ সাংবাদিকতায় বিশেষ অবদানের স্বীকৃতিস্বরূপ মিহা নিউজের পক্ষ থেকে এই সম্মাননা প্রদান করা হলো।', date: todayIso(), sign1_name: org.editor_name, sign1_title: org.editor_title || 'সম্পাদক', sign2_name: org.publisher_name, sign2_title: org.publisher_title || 'প্রকাশক', show_sign: '1', show_seal: '' }),
  presets: [
    { label: 'সম্মাননা', values: { title: 'সম্মাননা সনদ', line: 'সাহসী ও বস্তুনিষ্ঠ সাংবাদিকতায় বিশেষ অবদানের স্বীকৃতিস্বরূপ মিহা নিউজের পক্ষ থেকে এই সম্মাননা প্রদান করা হলো।' } },
    { label: 'কর্মশালা সম্পন্ন', values: { title: 'প্রশিক্ষণ সনদ', line: 'মিহা নিউজ আয়োজিত “অনুসন্ধানী সাংবাদিকতা” কর্মশালায় সাফল্যের সঙ্গে অংশগ্রহণ করায় এই সনদ প্রদান করা হলো।' } },
    { label: 'সেরা প্রতিনিধি', values: { title: 'সেরা প্রতিনিধি', line: 'বছরের সর্বাধিক মানসম্পন্ন সংবাদ পরিবেশনের জন্য “সেরা প্রতিনিধি” হিসেবে নির্বাচিত হওয়ায় অভিনন্দন।' } },
  ],
  render: (v, c) => certificateRender(v, c),
  mockup: (v, c) => paperScene(certificateRender(v, c)[0], 'সনদপত্র · A4 আড়াআড়ি'),
}

// ---------------------------------------------------------------------------
// Envelope, lanyard, T-shirt, stickers, pen
// ---------------------------------------------------------------------------

function envelopeRender(v: Values, c: Ctx): Page[] {
  const W = v.size === 'a4' ? 324 : 220
  const H = v.size === 'a4' ? 229 : 110
  const blue = c.org.color_primary || '#025AB2'
  const red = c.org.color_accent || '#C8102E'
  const k = H / 110
  const ct = contactsOf(c.org)
  const lw = 52 * k
  const body = `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="#fff"/>
  <rect x="-3" y="-3" width="${W + 6}" height="${4 * k}" fill="${blue}"/><rect x="-3" y="${4 * k - 3}" width="${W + 6}" height="${0.8 * k}" fill="${red}"/>
  ${logo(12 * k, 12 * k, lw, blue, red)}
  <text x="${12 * k}" y="${12 * k + logoH(lw) + 5 * k}" font-family="${F.bn}" font-size="${3 * k}" fill="#475569">${esc(ct.addr.split('\n')[0] || '')}</text>
  <text x="${12 * k}" y="${12 * k + logoH(lw) + 9.4 * k}" font-family="${F.bn}" font-size="${2.8 * k}" fill="#64748b">${esc([ct.phone, ct.email, ct.web].filter(Boolean).join(' · '))}</text>
  <text x="${W * 0.5}" y="${H * 0.52}" font-family="${F.bn}" font-weight="600" font-size="${3.6 * k}" fill="#0f172a">প্রাপক</text>
  ${[0, 1, 2, 3].map((i) => `<line x1="${W * 0.5}" y1="${H * 0.52 + (9 + i * 9) * k}" x2="${W - 14 * k}" y2="${H * 0.52 + (9 + i * 9) * k}" stroke="#cbd5e1" stroke-width="${0.25 * k}"/>`).join('')}
  <rect x="${W - 30 * k}" y="${10 * k}" width="${18 * k}" height="${22 * k}" fill="none" stroke="#cbd5e1" stroke-width="${0.3 * k}" stroke-dasharray="${1.2 * k} ${1.2 * k}"/><text x="${W - 21 * k}" y="${23 * k}" text-anchor="middle" font-family="${F.bn}" font-size="${2.4 * k}" fill="#94a3b8">স্ট্যাম্প</text>
  <rect x="-3" y="${H - 6 * k}" width="${W + 6}" height="${6 * k + 3}" fill="${blue}"/>
  <text x="${W - 12 * k}" y="${H - 2 * k}" text-anchor="end" font-family="${F.en}" font-weight="700" font-size="${2.4 * k}" letter-spacing=".5" fill="#fff">${esc(c.org.website.toUpperCase())}</text>`
  return [{ svg: svgDoc(W, H, body), w: W, h: H, name: 'envelope' }]
}

const envelope: Design = {
  key: 'envelope',
  kind: 'envelope',
  name: 'অফিস খাম',
  about: 'DL (২২০×১১০) অথবা বড় A4 খাম',
  fields: [{ key: 'size', label: 'মাপ', type: 'select', options: [ { value: 'dl', label: 'DL — ২২০×১১০ মিমি' }, { value: 'a4', label: 'বড় খাম — ৩২৪×২২৯ মিমি' } ] }],
  defaults: () => ({ size: 'dl' }),
  render: (v, c) => envelopeRender(v, c),
  mockup: (v, c) => paperScene(envelopeRender(v, c)[0], 'অফিস খাম'),
}

// ---- ফিতা: a horizontal strip, 20 mm × 450 mm (one half of the loop) ----

export const LANYARD = { w: 450, h: 20 }

export function lanyardSvg(v: Values, org: OrgInfo, bleed = 0): string {
  const W = LANYARD.w
  const H = LANYARD.h
  const palettes: Record<string, [string, string, string]> = {
    navy: ['#071a36', '#ffffff', org.color_gold || '#C9A54C'],
    blue: [org.color_primary || '#025AB2', '#ffffff', '#ffd2d8'],
    red: [org.color_accent || '#C8102E', '#ffffff', '#ffe08a'],
    white: ['#ffffff', org.color_primary || '#025AB2', org.color_accent || '#C8102E'],
  }
  const [bg, ink, acc] = palettes[v.color] ?? palettes.navy
  const seg = 75
  let body = `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" fill="${bg}"/><rect x="-3" y="1.2" width="${W + 6}" height=".5" fill="${acc}"/><rect x="-3" y="${H - 1.7}" width="${W + 6}" height=".5" fill="${acc}"/>`
  for (let x = 4, i = 0; x < W; x += seg, i++) {
    if (i % 2 === 0) body += logo(x, (H - logoH(34)) / 2, 34, ink, acc)
    else body += `<text x="${x + 2}" y="${H / 2 + 2.3}" font-family="${F.display}" font-size="7.4" letter-spacing="1.6" fill="${ink}">${esc(v.text || 'PRESS')}</text>`
    body += `<circle cx="${x + seg - 10}" cy="${H / 2}" r="1.2" fill="${acc}"/>`
    if (v.text_bn) body += `<text x="${x + seg - 30}" y="${H / 2 + 1.6}" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="4.4" fill="${ink}" opacity=".9">${i % 2 === 0 ? '' : esc(v.text_bn)}</text>`
  }
  return svgDoc(W, H, body, { bleed })
}

const lanyard: Design = {
  key: 'lanyard',
  kind: 'lanyard',
  name: 'ফিতা (ল্যানইয়ার্ড)',
  about: '২০ মিমি চওড়া · ৯০ সেমি লুপের অর্ধেক (৪৫ সেমি) প্রিন্ট ফাইল',
  fields: [
    { key: 'color', label: 'রং', type: 'select', options: [ { value: 'navy', label: 'নেভি + গোল্ড' }, { value: 'blue', label: 'মিহা নীল' }, { value: 'red', label: 'লাল' }, { value: 'white', label: 'সাদা' } ] },
    { key: 'text', label: 'ইংরেজি লেখা', type: 'text' },
    { key: 'text_bn', label: 'বাংলা লেখা (ঐচ্ছিক)', type: 'text' },
  ],
  defaults: () => ({ color: 'navy', text: 'PRESS', text_bn: 'মিহা নিউজ' }),
  render: (v, c, bleed = 0) => [{ svg: lanyardSvg(v, c.org, bleed), w: LANYARD.w + 2 * bleed, h: LANYARD.h + 2 * bleed, name: 'lanyard' }],
  mockup: (v, c) => {
    const card = cardFront('royal', idData(idcard.defaults(c.org), { ...c, raw: true }), { org: c.org, assets: c.assets }, { round: true })
    return stripScene(lanyardSvg(v, c.org), card, 'ফিতা · ২০ মিমি')
  },
}

// ---- টি-শার্ট ----

const TS_FRONT = { w: 100, h: 34 }
const TS_BACK = { w: 280, h: 200 }

function tshirtPrints(v: Values, org: OrgInfo) {
  const dark = !['white', 'yellow'].includes(v.color)
  const ink = dark ? '#ffffff' : org.color_primary || '#025AB2'
  const acc = dark ? org.color_gold || '#C9A54C' : org.color_accent || '#C8102E'
  const front = svgDoc(TS_FRONT.w, TS_FRONT.h, `${logo(2, 2, 96, ink, acc)}<text x="${TS_FRONT.w / 2}" y="${2 + logoH(96) + 8}" text-anchor="middle" font-family="${F.en}" font-weight="700" font-size="5" letter-spacing="3.2" fill="${acc}">${esc(v.front_text || 'PRESS')}</text>`)
  const big = v.back_text || 'PRESS'
  const bs = fitSize(big, F.display, 110, 270, 400, 40)
  const back = svgDoc(
    TS_BACK.w,
    TS_BACK.h,
    `<text x="${TS_BACK.w / 2}" y="${bs * 0.86}" text-anchor="middle" font-family="${F.display}" font-size="${bs}" letter-spacing="${bs * 0.06}" fill="${ink}">${esc(big)}</text>
    <rect x="${TS_BACK.w / 2 - 70}" y="${bs * 0.86 + 8}" width="140" height="2.4" fill="${acc}"/>
    ${logo(TS_BACK.w / 2 - 80, bs * 0.86 + 18, 160, ink, acc)}
    ${v.back_bn ? `<text x="${TS_BACK.w / 2}" y="${bs * 0.86 + 18 + logoH(160) + 22}" text-anchor="middle" font-family="${F.bnSerif}" font-weight="700" font-size="17" fill="${ink}">${esc(v.back_bn)}</text>` : ''}`,
  )
  return { front, back }
}

const TS_COLORS: Record<string, string> = { navy: '#0b1f3a', black: '#111827', blue: '#025AB2', red: '#B4152B', white: '#f8fafc', yellow: '#fde68a' }

const tshirt: Design = {
  key: 'tshirt',
  kind: 'tshirt',
  name: 'টি-শার্ট',
  about: 'বুকে লোগো + পিঠে বড় PRESS — প্রিন্ট ফাইল ও মকআপ',
  fields: [
    { key: 'color', label: 'শার্টের রং', type: 'select', options: [ { value: 'navy', label: 'নেভি' }, { value: 'black', label: 'কালো' }, { value: 'blue', label: 'মিহা নীল' }, { value: 'red', label: 'লাল' }, { value: 'white', label: 'সাদা' } ] },
    { key: 'front_text', label: 'বুকে লোগোর নিচে', type: 'text' },
    { key: 'back_text', label: 'পিঠে বড় লেখা', type: 'text' },
    { key: 'back_bn', label: 'পিঠে বাংলা লাইন', type: 'text' },
  ],
  defaults: () => ({ color: 'navy', front_text: 'PRESS', back_text: 'PRESS', back_bn: 'মিহা নিউজ' }),
  render: (v, c) => {
    const p = tshirtPrints(v, c.org)
    return [
      { svg: p.front, w: TS_FRONT.w, h: TS_FRONT.h, name: 'front-chest' },
      { svg: p.back, w: TS_BACK.w, h: TS_BACK.h, name: 'back' },
    ]
  },
  mockup: (v, c) => {
    const p = tshirtPrints(v, c.org)
    return tshirtScene(TS_COLORS[v.color] ?? TS_COLORS.navy, p.front, TS_FRONT.w, TS_FRONT.h, p.back, TS_BACK.w, TS_BACK.h, 'টি-শার্ট মকআপ')
  },
}

// ---- স্টিকার ----

function stickerRender(v: Values, c: Ctx): Page[] {
  const blue = c.org.color_primary || '#025AB2'
  const red = c.org.color_accent || '#C8102E'
  const W = 200
  const H = 70
  const car = svgDoc(
    W,
    H,
    `<rect x="-3" y="-3" width="${W + 6}" height="${H + 6}" rx="8" fill="${v.color === 'white' ? '#ffffff' : '#0b1f3a'}"/>
    <rect x="4" y="4" width="${W - 8}" height="${H - 8}" rx="5" fill="none" stroke="${red}" stroke-width="1.6"/>
    <text x="${W / 2}" y="40" text-anchor="middle" font-family="${F.display}" font-size="36" letter-spacing="7" fill="${v.color === 'white' ? blue : '#ffffff'}">${esc(v.text || 'PRESS')}</text>
    ${logo(W / 2 - 34, 46, 68, v.color === 'white' ? blue : '#ffffff', red)}
    <text x="${W / 2}" y="${46 + logoH(68) + 6}" text-anchor="middle" font-family="${F.en}" font-weight="700" font-size="3.4" letter-spacing="1" fill="${v.color === 'white' ? '#475569' : '#cbd5e1'}">${esc(c.org.website.toUpperCase())}</text>`,
  )
  const R = 60
  const round = svgDoc(
    R,
    R,
    `<circle cx="${R / 2}" cy="${R / 2}" r="${R / 2}" fill="${blue}"/><circle cx="${R / 2}" cy="${R / 2}" r="${R / 2 - 2.6}" fill="none" stroke="#fff" stroke-width=".6"/>
    <text x="${R / 2}" y="19" text-anchor="middle" font-family="${F.display}" font-size="9" letter-spacing="2" fill="#fff">${esc(v.text || 'PRESS')}</text>
    ${logo(R / 2 - 21, 25, 42, '#ffffff', '#ffd2d8')}
    <text x="${R / 2}" y="${25 + logoH(42) + 8}" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="4.6" fill="#fff">${esc(c.org.name_bn)}</text>`,
  )
  return [
    { svg: car, w: W, h: H, name: 'car' },
    { svg: round, w: R, h: R, name: 'round' },
  ]
}

const sticker: Design = {
  key: 'sticker',
  kind: 'sticker',
  name: 'প্রেস স্টিকার',
  about: 'গাড়ি/বাইকের PRESS স্টিকার (২০০×৭০) + গোল লোগো স্টিকার',
  fields: [
    { key: 'text', label: 'বড় লেখা', type: 'text' },
    { key: 'color', label: 'জমিন', type: 'select', options: [ { value: 'navy', label: 'নেভি' }, { value: 'white', label: 'সাদা' } ] },
  ],
  defaults: () => ({ text: 'PRESS', color: 'navy' }),
  render: (v, c) => stickerRender(v, c),
  mockup: (v, c) => {
    const p = stickerRender(v, c)
    return stickerScene(p[0].svg, p[1].svg, 'স্টিকার')
  },
}

// ---- কলম ----

function penStrip(v: Values, org: OrgInfo) {
  const W = 120
  const H = 8
  return svgDoc(W, H, `${logo(2, (H - logoH(36)) / 2, 36, '#0b1f3a', org.color_gold || '#C9A54C')}<text x="${W - 2}" y="${H / 2 + 1.4}" text-anchor="end" font-family="${F.en}" font-weight="700" font-size="3.6" letter-spacing=".5" fill="#0b1f3a">${esc(v.text || org.website.toUpperCase())}</text>`)
}

const pen: Design = {
  key: 'pen',
  kind: 'pen',
  name: 'কলম',
  about: 'কলমে প্রিন্টের লোগো-স্ট্রিপ (১২০×৮ মিমি) ও মকআপ',
  fields: [{ key: 'text', label: 'লেখা', type: 'text' }],
  defaults: (org) => ({ text: org.website.toUpperCase() }),
  render: (v, c) => [{ svg: penStrip(v, c.org), w: 120, h: 8, name: 'pen' }],
  mockup: (v, c) => penScene(penStrip(v, c.org), 'কলম'),
}

export const DESIGNS: Design[] = [...SOCIAL_DESIGNS, letterhead, appointment, pad, visiting, idcard, invitation, certificate, envelope, lanyard, tshirt, sticker, pen]

export const designOf = (key: string) => DESIGNS.find((d) => d.key === key) ?? letterhead

export { SCENE_W, SCENE_H, qr }
