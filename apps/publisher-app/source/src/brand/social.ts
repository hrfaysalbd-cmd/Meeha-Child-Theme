/**
 * r44 — সোশ্যাল মিডিয়া পোস্ট: ready-made recruitment notices (সাংবাদিক /
 * জেলা / উপজেলা / ক্যাম্পাস প্রতিনিধি নিয়োগ) and calls for writing (লেখা
 * আহ্বান, খবর পাঠান, ছবি আহ্বান) for Facebook, Instagram and WhatsApp.
 *
 * 1 unit = 10 px: square 108 (1080×1080), portrait 108×135 (1080×1350),
 * story 108×192 (1080×1920). Exported at 254 dpi, so the files come out at
 * exactly those pixel sizes.
 *
 * Five looks (navy, blue, bold, clean, mic); every text is editable and
 * shrinks to fit, so a long list still stays inside the post.
 */
import type { Page } from './export'
import { F } from './fonts'
import { logo, logoH, mark } from './logo'
import { place, scene, shadow } from './mockup'
import { qr } from './qr'
import { bnDate, esc, fitSize, goldGrad, guilloche, mix, svgDoc, textWidth, uid, wrap, type OrgInfo } from './util'
import type { Ctx, Design, FieldDef, Preset, Values } from './stationery'

export const SOCIAL_DPI = 254

const SIZES: Record<string, { w: number; h: number; label: string }> = {
  square: { w: 108, h: 108, label: 'বর্গ ১০৮০×১০৮০ (ফেসবুক/ইনস্টা)' },
  portrait: { w: 108, h: 135, label: 'লম্বা ১০৮০×১৩৫০ (ফেসবুক ফিড)' },
  story: { w: 108, h: 192, label: 'স্টোরি ১০৮০×১৯২০ (স্টোরি/হোয়াটসঅ্যাপ)' },
}

const STYLES = [
  { value: 'navy', label: 'নেভি গোল্ড (প্রিমিয়াম)' },
  { value: 'blue', label: 'মিহা ব্লু' },
  { value: 'bold', label: 'লাল-কালো (জোরালো)' },
  { value: 'clean', label: 'সাদা মিনিমাল' },
  { value: 'mic', label: 'মাইক্রোফোন (সাংবাদিকতা)' },
]

interface Look {
  bg: string
  defs: string
  head: string
  hi: string
  sub: string
  text: string
  tagBg: string
  tagInk: string
  dot: string
  dotInk: string
  panel: string
  ctaBg: string
  ctaInk: string
  logo: string
  foot: string
  plate: string
}

const lines = (s: string | undefined) => (s ?? '').split('\n').map((x) => x.trim()).filter(Boolean)

function look(style: string, org: OrgInfo, W: number, H: number, g: string): Look {
  const blue = org.color_primary || '#025AB2'
  const red = org.color_accent || '#C8102E'
  const gold = org.color_gold || '#C9A54C'
  switch (style) {
    case 'blue':
      return {
        defs: `<linearGradient id="${g}b" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${mix(blue, '#ffffff', 0.08)}"/><stop offset="1" stop-color="${mix(blue, '#000000', 0.35)}"/></linearGradient>`,
        bg: `<rect width="${W}" height="${H}" fill="url(#${g}b)"/>${guilloche(0, 0, W, H * 0.6, '#ffffff', 0.08, 14, 0.12)}<circle cx="${W * 0.95}" cy="${H * 0.08}" r="${W * 0.32}" fill="#ffffff" opacity=".07"/><circle cx="${W * 0.05}" cy="${H * 0.97}" r="${W * 0.28}" fill="#ffffff" opacity=".06"/><rect x="0" y="${H - 1.6}" width="${W}" height="1.6" fill="${red}"/>`,
        head: '#ffffff', hi: '#ffd54a', sub: '#dbeafe', text: '#ffffff',
        tagBg: red, tagInk: '#ffffff', dot: '#ffffff', dotInk: blue, panel: 'rgba(255,255,255,.12)',
        ctaBg: '#ffffff', ctaInk: blue, logo: '#ffffff', foot: '#dbeafe', plate: '#ffffff',
      }
    case 'bold':
      return {
        defs: '',
        bg: `<rect width="${W}" height="${H}" fill="#0d0d0f"/><path d="M0 0H${W}V${H * 0.1}L0 ${H * 0.2}Z" fill="${red}"/><path d="M0 ${H * 0.2}L${W} ${H * 0.1}V${H * 0.125}L0 ${H * 0.225}Z" fill="#ffd400"/>${Array.from({ length: 9 }, (_, i) => `<rect x="${W - 22 + i * 3}" y="${H - 9}" width="1.4" height="9" fill="#ffd400" opacity=".9" transform="skewX(-20)"/>`).join('')}`,
        head: '#ffffff', hi: '#ffd400', sub: '#e5e7eb', text: '#f3f4f6',
        tagBg: '#ffd400', tagInk: '#0d0d0f', dot: red, dotInk: '#ffffff', panel: 'rgba(255,255,255,.07)',
        ctaBg: red, ctaInk: '#ffffff', logo: '#ffffff', foot: '#9ca3af', plate: '#ffffff',
      }
    case 'clean':
      return {
        defs: '',
        bg: `<rect width="${W}" height="${H}" fill="#ffffff"/><circle cx="${W * 1.02}" cy="${-H * 0.02}" r="${W * 0.36}" fill="${blue}"/><circle cx="${W * 0.86}" cy="${H * 0.02}" r="${W * 0.12}" fill="${red}" opacity=".9"/><circle cx="${-W * 0.06}" cy="${H * 1.02}" r="${W * 0.26}" fill="${mix(blue, '#ffffff', 0.86)}"/>${Array.from({ length: 6 }, (_, r) => Array.from({ length: 6 }, (_, cIdx) => `<circle cx="${W - 24 + cIdx * 3.6}" cy="${H - 22 + r * 3.6}" r=".55" fill="${mix(blue, '#ffffff', 0.6)}"/>`).join('')).join('')}`,
        head: '#0b1f3a', hi: blue, sub: '#475569', text: '#1e293b',
        tagBg: mix(blue, '#ffffff', 0.88), tagInk: blue, dot: blue, dotInk: '#ffffff', panel: mix(blue, '#ffffff', 0.95),
        ctaBg: blue, ctaInk: '#ffffff', logo: blue, foot: '#64748b', plate: '#ffffff',
      }
    case 'mic':
      return {
        defs: `<radialGradient id="${g}m" cx=".75" cy=".35" r=".9"><stop offset="0" stop-color="#1e3a68"/><stop offset="1" stop-color="#060d1a"/></radialGradient>${goldGrad(g + 'au', 0.1)}`,
        bg: `<rect width="${W}" height="${H}" fill="url(#${g}m)"/>${micArt(W * 0.6, H * 0.12, Math.min(W * 0.5, H * 0.45), g)}`,
        head: '#ffffff', hi: gold, sub: '#cbd5e1', text: '#e2e8f0',
        tagBg: `url(#${g}au)`, tagInk: '#1a1203', dot: gold, dotInk: '#1a1203', panel: 'rgba(255,255,255,.06)',
        ctaBg: `url(#${g}au)`, ctaInk: '#1a1203', logo: '#ffffff', foot: '#94a3b8', plate: '#ffffff',
      }
    default:
      return {
        defs: `<radialGradient id="${g}n" cx=".3" cy=".2" r="1"><stop offset="0" stop-color="#103566"/><stop offset="1" stop-color="#050f22"/></radialGradient>${goldGrad(g + 'au', 0.1)}`,
        bg: `<rect width="${W}" height="${H}" fill="url(#${g}n)"/>${guilloche(0, H * 0.55, W, H * 0.45, gold, 0.1, 18, 0.1)}<rect x="3" y="3" width="${W - 6}" height="${H - 6}" fill="none" stroke="url(#${g}au)" stroke-width=".35"/><rect x="4.4" y="4.4" width="${W - 8.8}" height="${H - 8.8}" fill="none" stroke="url(#${g}au)" stroke-width=".12" opacity=".7"/>`,
        head: '#ffffff', hi: gold, sub: '#cbd5e1', text: '#e2e8f0',
        tagBg: `url(#${g}au)`, tagInk: '#1a1203', dot: gold, dotInk: '#1a1203', panel: 'rgba(255,255,255,.06)',
        ctaBg: `url(#${g}au)`, ctaInk: '#1a1203', logo: '#ffffff', foot: '#94a3b8', plate: '#ffffff',
      }
  }
}

/** A studio microphone (the "mic" look). */
function micArt(x: number, y: number, s: number, g: string): string {
  const k = s / 100
  const grille = Array.from({ length: 7 }, (_, i) => `<line x1="${24 * k}" y1="${(16 + i * 7) * k}" x2="${76 * k}" y2="${(16 + i * 7) * k}" stroke="#0b1424" stroke-width="${1.6 * k}" opacity=".55"/>`).join('')
  return `<g transform="translate(${x} ${y}) rotate(14 ${50 * k} ${70 * k})" opacity=".95">
    <circle cx="${50 * k}" cy="${40 * k}" r="${58 * k}" fill="#ffffff" opacity=".04"/>
    <rect x="${22 * k}" y="${4 * k}" width="${56 * k}" height="${66 * k}" rx="${28 * k}" fill="url(#${g}au)"/>
    <rect x="${26 * k}" y="${8 * k}" width="${48 * k}" height="${58 * k}" rx="${24 * k}" fill="#cfd8e6"/>
    ${grille}
    <rect x="${18 * k}" y="${64 * k}" width="${64 * k}" height="${14 * k}" rx="${3 * k}" fill="#C8102E"/>
    <text x="${50 * k}" y="${74 * k}" text-anchor="middle" font-family="${F.en}" font-weight="800" font-size="${7 * k}" letter-spacing="${1.2 * k}" fill="#fff">PRESS</text>
    <path d="M${8 * k} ${44 * k}v${8 * k}a${42 * k} ${42 * k} 0 0 0 ${84 * k} 0v-${8 * k}" fill="none" stroke="url(#${g}au)" stroke-width="${3 * k}" stroke-linecap="round"/>
    <rect x="${46 * k}" y="${94 * k}" width="${8 * k}" height="${34 * k}" rx="${2 * k}" fill="url(#${g}au)"/>
    <rect x="${28 * k}" y="${126 * k}" width="${44 * k}" height="${7 * k}" rx="${3.5 * k}" fill="url(#${g}au)"/>
  </g>`
}

/** Text lines → <text> with tspans, left aligned. */
function textBlock(ls: string[], x: number, y: number, size: number, lh: number, attrs: string) {
  return ls.map((l, i) => `<text x="${x}" y="${y + i * lh}" font-size="${size}" ${attrs}>${esc(l)}</text>`).join('')
}

function socialRender(kind: 'recruit' | 'call', v: Values, c: Ctx): Page[] {
  const S = SIZES[v.format] ?? SIZES.square
  const W = S.w
  const H = S.h
  const g = uid('so')
  const st = v.style || 'navy'
  const L = look(st, c.org, W, H, g)
  const story = H > 150
  const tall = H > 120
  const pad = story ? 9 : 8
  const narrow = st === 'mic' ? W * 0.62 : W - pad * 2
  const raw = c.raw
  const T = (s: string | undefined, ph: string) => (raw ? ph : s || '')
  const out: string[] = [L.bg]

  // ---- top: logo + tag ----
  let y = story ? 16 : st === 'bold' ? 7 : 8
  const lw = story ? 40 : 34
  out.push(logo(pad, y, lw, L.logo))
  const tag = T(v.tag, kind === 'recruit' ? 'নিয়োগ বিজ্ঞপ্তি' : 'আহ্বান')
  if (tag) {
    const ts = 3.4
    const tw = textWidth(tag, F.bn, ts, 600) + 6
    out.push(`<rect x="${W - pad - tw}" y="${y - 0.4}" width="${tw}" height="${ts + 3.2}" rx="${(ts + 3.2) / 2}" fill="${L.tagBg}"/><text x="${W - pad - tw / 2}" y="${y + ts + 0.7}" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="${ts}" fill="${L.tagInk}">${esc(tag)}</text>`)
  }
  y += logoH(lw) + (story ? 16 : st === 'bold' ? 12 : tall ? 9 : 6)
  if (st === 'bold') y = Math.max(y, H * 0.235 + (tall ? 6 : 1.5))

  // ---- footer (measured first, drawn last): CTA + contact + QR ----
  const link = T(v.link, 'https://')
  const showQr = v.show_qr === '1' && !!link
  const qrS = story ? 24 : tall ? 21 : 18
  const footH = Math.max(showQr ? qrS + 2 : 0, 18)
  const footY = H - pad - footH

  // ---- headline ----
  const head = T(v.headline, 'শিরোনাম')
  const hi = T(v.highlight, '')
  const hs0 = story ? 13 : tall ? 12 : 9.6
  const hs = fitSize(head, F.bnSerif, hs0, narrow, 700, hs0 * 0.5)
  const headLines = wrap(head, F.bnSerif, hs, narrow, 700).slice(0, 2)
  out.push(textBlock(headLines, pad, y + hs * 0.8, hs, hs * 1.12, `font-family="${F.bnSerif}" font-weight="700" fill="${L.head}"`))
  y += headLines.length * hs * 1.12 + 1
  if (hi) {
    const his = fitSize(hi, F.bn, hs * 0.62, narrow, 600, 3.6)
    const hil = wrap(hi, F.bn, his, narrow, 600).slice(0, 2)
    out.push(textBlock(hil, pad, y + his * 0.95, his, his * 1.25, `font-family="${F.bn}" font-weight="600" fill="${L.hi}"`))
    y += hil.length * his * 1.25 + 1.5
  }
  out.push(`<rect x="${pad}" y="${y + 0.5}" width="14" height="1" rx=".5" fill="${L.hi}"/>`)
  y += 4.5
  const sub = T(v.sub, '')
  if (sub) {
    const ss = story ? 4.2 : 3.7
    const sl = wrap(sub, F.bn, ss, narrow, 400).slice(0, tall ? 3 : 2)
    out.push(textBlock(sl, pad, y + ss, ss, ss * 1.45, `font-family="${F.bn}" fill="${L.sub}"`))
    y += sl.length * ss * 1.45 + 3
  }

  // ---- lists (shrink to fit the space above the footer) ----
  const blocks = [
    { title: T(v.points_title, ''), items: raw ? ['…', '…', '…'] : lines(v.points) },
    { title: T(v.extra_title, ''), items: raw ? ['…', '…'] : lines(v.extra) },
  ].filter((b) => b.items.length)
  const deadline = !raw && v.deadline ? `শেষ তারিখ: ${bnDate(v.deadline)}` : ''
  const room = footY - y - (deadline ? 9 : 2)
  // one column, or two side by side when the post is square; items are
  // dropped from the end of the longer list only when nothing else fits
  const twoOk = blocks.length === 2 && st !== 'mic'
  const colW = (cols: number) => (cols === 2 ? (narrow + 4 - 3) / 2 - 4 : narrow)
  const lay = (s: number, cols: number, src = blocks) => {
    const res = src.map((b) => {
      const its = b.items.map((it) => wrap(it, F.bn, s, colW(cols) - 8, 400))
      const bh = (b.title ? s * 1.9 : 0) + its.reduce((a, ls) => a + ls.length * s * 1.3 + s * 0.5, 0) + 3.5
      return { ...b, its, bh }
    })
    const h = cols === 2 ? Math.max(...res.map((r) => r.bh)) + 2 : res.reduce((a, r) => a + r.bh + 2, 0)
    return { res, h, s, cols }
  }
  const bs0 = story ? 4.4 : tall ? 4 : 3.6
  const sizes: number[] = []
  for (let s = bs0; s >= 2.95; s -= 0.15) sizes.push(s)
  const tries: [number, number][] = [
    ...sizes.filter((s) => s >= 3.3).map((s) => [s, 1] as [number, number]),
    ...(twoOk ? sizes.map((s) => [s, 2] as [number, number]) : []),
    ...sizes.filter((s) => s < 3.3).map((s) => [s, 1] as [number, number]),
  ]
  let L2: ReturnType<typeof lay> | null = null
  let src = blocks.map((b) => ({ ...b, items: [...b.items] }))
  while (!L2 && src.some((b) => b.items.length)) {
    for (const [s, cols] of tries) {
      const t = lay(s, cols, src.filter((b) => b.items.length))
      if (t.h <= room) {
        L2 = t
        break
      }
    }
    if (!L2) {
      const big = src.reduce((a, b) => (b.items.length >= a.items.length ? b : a))
      big.items.pop()
    }
  }
  const bs = L2?.s ?? bs0
  const cw2 = L2 ? colW(L2.cols) : narrow
  let x0 = pad
  const yTop = y
  for (const b of L2?.res ?? []) {
    const by = L2!.cols === 2 ? yTop : y
    const bh = L2!.cols === 2 ? Math.max(...L2!.res.map((r) => r.bh)) : b.bh
    out.push(`<rect x="${x0 - 2}" y="${by}" width="${cw2 + 4}" height="${bh}" rx="2.4" fill="${L.panel}"/>`)
    let yy = by + 2.2
    if (b.title) {
      out.push(`<text x="${x0 + 1}" y="${yy + bs}" font-family="${F.bn}" font-weight="600" font-size="${bs * 1.02}" fill="${L.hi}">${esc(b.title)}</text>`)
      yy += bs * 1.9
    }
    for (const ls of b.its) {
      const r = bs * 0.55
      out.push(`<circle cx="${x0 + 1 + r}" cy="${yy + bs * 0.62}" r="${r}" fill="${L.dot}"/><path d="M${x0 + 1 + r * 0.5} ${yy + bs * 0.62}l${r * 0.38} ${r * 0.4} ${r * 0.72}-${r * 0.8}" fill="none" stroke="${L.dotInk}" stroke-width="${r * 0.32}" stroke-linecap="round" stroke-linejoin="round"/>`)
      out.push(textBlock(ls, x0 + 1 + r * 2 + 2.2, yy + bs, bs, bs * 1.3, `font-family="${F.bn}" fill="${L.text}"`))
      yy += ls.length * bs * 1.3 + bs * 0.5
    }
    if (L2!.cols === 2) x0 += cw2 + 4 + 3
    else y += b.bh + 2
  }
  if (L2?.cols === 2) y = yTop + L2.h
  if (deadline) {
    const ds = story ? 4 : 3.5
    const dw = textWidth(deadline, F.bn, ds, 600) + 7
    out.push(`<rect x="${pad}" y="${y + 1}" width="${dw}" height="${ds + 3.4}" rx="1.4" fill="none" stroke="${L.hi}" stroke-width=".35"/><text x="${pad + dw / 2}" y="${y + ds + 2.4}" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="${ds}" fill="${L.hi}">⏰ ${esc(deadline)}</text>`)
  }

  // ---- footer ----
  const cta = T(v.cta, kind === 'recruit' ? 'আবেদন করুন' : 'লেখা পাঠান')
  const cs = story ? 4.8 : 4.2
  const cw = Math.min((showQr ? W - pad * 2 - qrS - 4 : W - pad * 2), textWidth(cta, F.bn, cs, 600) + 12)
  out.push(`<rect x="${pad}" y="${footY}" width="${cw}" height="${cs + 4.6}" rx="${(cs + 4.6) / 2}" fill="${L.ctaBg}"/><text x="${pad + cw / 2}" y="${footY + cs + 1.6}" text-anchor="middle" font-family="${F.bn}" font-weight="600" font-size="${cs}" fill="${L.ctaInk}">${esc(cta)} →</text>`)
  const info = [link && link.replace(/^https?:\/\//, '').replace(/\/$/, ''), T(v.phone, ''), T(v.email, '')].filter(Boolean) as string[]
  const fs = story ? 3.4 : 3
  info.slice(0, 3).forEach((t, i) => {
    const maxW = showQr ? W - pad * 2 - qrS - 4 : W - pad * 2
    const s2 = fitSize(t, F.en, fs, maxW, 500, 2)
    out.push(`<text x="${pad}" y="${footY + cs + 9.5 + i * fs * 1.45}" font-family="${F.en}" font-weight="500" font-size="${s2}" fill="${L.foot}">${esc(t)}</text>`)
  })
  if (showQr) out.push(qr(link, W - pad - qrS, footY + footH - qrS, qrS, { plate: L.plate, pad: 1.4, radius: 1.6 }))
  else out.push(mark(W - pad - 10, footY + footH - 10, 10, L.logo))

  const body = out.join('')
  return [{ svg: svgDoc(W, H, body, { defs: L.defs }), w: W, h: H, name: `${kind}-${v.format || 'square'}` }]
}

/** The post on a phone, next to the flat file. */
function phoneScene(page: Page, caption: string): string {
  const s = uid('ps')
  const ph = 168
  const pw = ph * 0.49
  const px = 46
  const py = (200 - ph) / 2
  const screenW = pw - 6
  const k = screenW / page.w
  const sh = page.h * k
  const sy = py + 18 + Math.max(0, (ph - 36 - sh) / 2)
  const phone = `<g filter="url(#${s})"><rect x="${px}" y="${py}" width="${pw}" height="${ph}" rx="10" fill="#0b0f19"/><rect x="${px + 3}" y="${py + 3}" width="${pw - 6}" height="${ph - 6}" rx="8" fill="#f1f5f9"/></g>
    <rect x="${px + 3}" y="${py + 8}" width="${pw - 6}" height="9" fill="#ffffff"/><circle cx="${px + 9}" cy="${py + 12.5}" r="2.6" fill="#025AB2"/><rect x="${px + 13}" y="${py + 10.6}" width="22" height="1.6" rx=".8" fill="#334155"/><rect x="${px + 13}" y="${py + 13.2}" width="12" height="1.2" rx=".6" fill="#94a3b8"/>
    ${place(page.svg, px + 3, sy, screenW, sh)}
    <rect x="${px + pw / 2 - 9}" y="${py + 4.2}" width="18" height="2.6" rx="1.3" fill="#0b0f19"/>`
  const fk = Math.min(110 / page.w, 150 / page.h)
  const flat = place(page.svg, 160, (200 - page.h * fk) / 2, page.w * fk, page.h * fk, 3, s)
  return scene(`${flat}${phone}`, shadow(s, 3.5, 4), 'studio', caption)
}

const commonFields = (kind: 'recruit' | 'call'): FieldDef[] => [
  { key: 'style', label: 'ডিজাইন', type: 'select', options: STYLES },
  { key: 'format', label: 'মাপ', type: 'select', options: Object.entries(SIZES).map(([value, s]) => ({ value, label: s.label })) },
  { key: 'tag', label: 'উপরের ছোট লেবেল', type: 'text' },
  { key: 'headline', label: 'বড় শিরোনাম', type: 'text' },
  { key: 'highlight', label: 'রঙিন দ্বিতীয় লাইন', type: 'text' },
  { key: 'sub', label: 'ছোট বিবরণ', type: 'textarea', rows: 2 },
  { key: 'points_title', label: kind === 'recruit' ? 'প্রথম তালিকার শিরোনাম (যোগ্যতা)' : 'প্রথম তালিকার শিরোনাম (বিষয়)', type: 'text' },
  { key: 'points', label: 'প্রথম তালিকা (প্রতি লাইনে একটি)', type: 'textarea', rows: 4 },
  { key: 'extra_title', label: kind === 'recruit' ? 'দ্বিতীয় তালিকার শিরোনাম (সুবিধা)' : 'দ্বিতীয় তালিকার শিরোনাম (নিয়ম)', type: 'text' },
  { key: 'extra', label: 'দ্বিতীয় তালিকা (প্রতি লাইনে একটি)', type: 'textarea', rows: 3 },
  { key: 'deadline', label: 'শেষ তারিখ (ঐচ্ছিক)', type: 'date' },
  { key: 'cta', label: 'বোতামের লেখা', type: 'text' },
  { key: 'link', label: 'লিংক (QR-এ যাবে)', type: 'text' },
  { key: 'phone', label: 'ফোন / হোয়াটসঅ্যাপ', type: 'text' },
  { key: 'email', label: 'ইমেইল', type: 'text' },
  { key: 'show_qr', label: 'QR কোড দেখান', type: 'toggle' },
]

const RECRUIT_PRESETS: Preset[] = [
  {
    label: 'সাংবাদিক নিয়োগ (সারা দেশ)',
    values: {
      tag: 'নিয়োগ বিজ্ঞপ্তি', headline: 'সাংবাদিক নিয়োগ', highlight: 'সারা দেশে জেলা, উপজেলা ও ক্যাম্পাস প্রতিনিধি',
      sub: 'সত্য তুলে ধরার সাহস আছে? মিহা নিউজ পরিবারে যোগ দিন।',
      points_title: 'যোগ্যতা', points: 'ন্যূনতম এইচএসসি পাস\nস্মার্টফোন ও ইন্টারনেট ব্যবহারে দক্ষ\nশুদ্ধ বাংলায় লেখার দক্ষতা\nনিজ এলাকার খবরাখবর সম্পর্কে সচেতন',
      extra_title: 'যা পাবেন', extra: 'প্রকাশিত খবরে সম্মানী\nমিহা প্রেস আইডি কার্ড\nডেস্কের সহায়তা ও প্রশিক্ষণ',
      cta: 'আজই আবেদন করুন',
    },
  },
  {
    label: 'জেলা প্রতিনিধি',
    values: {
      tag: 'নিয়োগ চলছে', headline: 'জেলা প্রতিনিধি নিয়োগ', highlight: 'দেশের সকল জেলায়',
      sub: 'নিজ জেলার রাজনীতি, প্রশাসন, অপরাধ ও উন্নয়নের খবর পাঠাবেন।',
      points_title: 'যোগ্যতা', points: 'স্নাতক বা সমমান (অভিজ্ঞদের অগ্রাধিকার)\nসাংবাদিকতায় অভিজ্ঞতা থাকলে ভালো\nছবি ও ভিডিও করতে পারেন\nনিরপেক্ষ ও সৎ',
      extra_title: 'যা পাবেন', extra: 'সম্মানী ও বোনাস\nপ্রেস আইডি কার্ড ও নিয়োগপত্র\nভিডিও নিউজের সুযোগ',
      cta: 'আবেদন করুন',
    },
  },
  {
    label: 'উপজেলা প্রতিনিধি',
    values: {
      tag: 'নিয়োগ চলছে', headline: 'উপজেলা প্রতিনিধি নিয়োগ', highlight: 'দেশের প্রতিটি উপজেলায়',
      sub: 'আপনার উপজেলার খবর এখন পৌঁছাবে সারা দেশে।',
      points_title: 'যোগ্যতা', points: 'ন্যূনতম এইচএসসি পাস\nনিজ উপজেলায় বসবাস\nস্মার্টফোন দিয়ে ছবি ও ভিডিও তুলতে পারেন',
      extra_title: 'যা পাবেন', extra: 'প্রকাশিত খবরে সম্মানী\nপ্রেস আইডি কার্ড\nমোবাইল অ্যাপে সহজে খবর পাঠানো',
      cta: 'আবেদন করুন',
    },
  },
  {
    label: 'ক্যাম্পাস প্রতিনিধি',
    values: {
      tag: 'শিক্ষার্থীদের জন্য', headline: 'ক্যাম্পাস প্রতিনিধি নিয়োগ', highlight: 'বিশ্ববিদ্যালয় ও কলেজে',
      sub: 'পড়াশোনার পাশাপাশি সাংবাদিকতা শিখুন, ক্যাম্পাসের কণ্ঠ হয়ে উঠুন।',
      points_title: 'যোগ্যতা', points: 'বিশ্ববিদ্যালয় বা কলেজের নিয়মিত শিক্ষার্থী\nলেখালেখিতে আগ্রহ\nক্যাম্পাসের খবরাখবরে সক্রিয়',
      extra_title: 'যা পাবেন', extra: 'সাংবাদিকতার হাতেকলমে প্রশিক্ষণ\nপ্রকাশিত খবরে সম্মানী\nক্যাম্পাস প্রেস কার্ড',
      cta: 'যুক্ত হোন',
    },
  },
  {
    label: 'ফটো ও ভিডিও সাংবাদিক',
    values: {
      tag: 'নিয়োগ বিজ্ঞপ্তি', headline: 'ফটো ও ভিডিও সাংবাদিক', highlight: 'ক্যামেরায় গল্প বলতে পারেন?',
      sub: 'ছবি ও ভিডিও দিয়ে খবর তুলে আনুন মিহা নিউজের সঙ্গে।',
      points_title: 'যোগ্যতা', points: 'ভালো মানের ছবি/ভিডিও তোলার দক্ষতা\nমোবাইল বা ক্যামেরায় ভিডিও সম্পাদনা\nঝুঁকিপূর্ণ ঘটনায় নিরাপদে কাজের জ্ঞান',
      extra_title: 'যা পাবেন', extra: 'ছবি ও ভিডিওর জন্য আলাদা সম্মানী\nনিজের নামে ক্রেডিট\nপ্রেস আইডি কার্ড',
      cta: 'আবেদন করুন',
    },
  },
  {
    label: 'নারী সাংবাদিক (বিশেষ আহ্বান)',
    values: {
      tag: 'বিশেষ আহ্বান', headline: 'নারী সাংবাদিকদের আহ্বান', highlight: 'আপনার চোখে দেখা সমাজের খবর',
      sub: 'নারী, শিশু, শিক্ষা ও স্বাস্থ্যের খবরে নারী সাংবাদিকদের বিশেষভাবে আহ্বান জানানো হচ্ছে।',
      points_title: 'যোগ্যতা', points: 'ন্যূনতম এইচএসসি পাস\nলেখালেখি ও মানুষের সঙ্গে কথা বলার আগ্রহ',
      extra_title: 'যা পাবেন', extra: 'নিরাপদ ও সম্মানজনক কাজের পরিবেশ\nঘরে বসেই অ্যাপে খবর পাঠানোর সুযোগ\nপ্রকাশিত খবরে সম্মানী',
      cta: 'আবেদন করুন',
    },
  },
]

const CALL_PRESETS: Preset[] = [
  {
    label: 'লেখা আহ্বান (সাধারণ)',
    values: {
      tag: 'লেখা আহ্বান', headline: 'আপনার লেখা পাঠান', highlight: 'মত, গল্প, কবিতা ও ফিচার',
      sub: 'মিহা নিউজে প্রকাশের জন্য পাঠকদের কাছ থেকে লেখা আহ্বান করা হচ্ছে।',
      points_title: 'যা পাঠাতে পারেন', points: 'মতামত ও কলাম\nছোটগল্প ও কবিতা\nভ্রমণ ও ফিচার\nসমাজ ও সংস্কৃতি নিয়ে লেখা',
      extra_title: 'নিয়ম', extra: 'লেখা মৌলিক ও অপ্রকাশিত হতে হবে\n৫০০–১২০০ শব্দ, ইউনিকোড বাংলায়\nলেখার সঙ্গে নাম, ছবি ও ফোন নম্বর দিন',
      cta: 'লেখা পাঠান',
    },
  },
  {
    label: 'বিশেষ সংখ্যা (ঈদ/পূজা/বিজয় দিবস)',
    values: {
      tag: 'বিশেষ সংখ্যা', headline: 'বিশেষ সংখ্যার জন্য লেখা আহ্বান', highlight: 'ঈদ · পূজা · বিজয় দিবস',
      sub: 'উৎসবের বিশেষ আয়োজনে থাকতে পারে আপনার লেখাও।',
      points_title: 'যা পাঠাতে পারেন', points: 'গল্প ও কবিতা\nস্মৃতিকথা\nউৎসবের ফিচার ও ছবি',
      extra_title: 'নিয়ম', extra: 'একজন সর্বোচ্চ দুটি লেখা\nলেখা অবশ্যই মৌলিক হতে হবে',
      cta: 'লেখা পাঠান',
    },
  },
  {
    label: 'মতামত কলাম',
    values: {
      tag: 'মতামত', headline: 'আপনার মত, আপনার কলাম', highlight: 'যুক্তি দিয়ে লিখুন, সমাজকে ভাবান',
      sub: 'চলমান ঘটনা নিয়ে বিশ্লেষণধর্মী মতামত আহ্বান করা হচ্ছে।',
      points_title: 'বিষয়', points: 'রাজনীতি ও প্রশাসন\nঅর্থনীতি ও কৃষি\nশিক্ষা ও স্বাস্থ্য\nপ্রযুক্তি ও তরুণ সমাজ',
      extra_title: 'নিয়ম', extra: '৬০০–১০০০ শব্দ\nতথ্যসূত্র উল্লেখ করুন\nলেখকের সংক্ষিপ্ত পরিচয় দিন',
      cta: 'কলাম পাঠান',
    },
  },
  {
    label: 'তরুণ লেখক / শিক্ষার্থী',
    values: {
      tag: 'তরুণদের জন্য', headline: 'তরুণ লেখক খুঁজছে মিহা', highlight: 'স্কুল, কলেজ ও বিশ্ববিদ্যালয়ের শিক্ষার্থীরা',
      sub: 'তোমার ভাবনা, তোমার লেখা — ছাপা হবে মিহা নিউজে।',
      points_title: 'যা পাঠাতে পারো', points: 'ক্যাম্পাসের গল্প\nকবিতা ও ছোটগল্প\nবিজ্ঞান ও প্রযুক্তির সহজ লেখা',
      extra_title: 'নিয়ম', extra: 'লেখার সঙ্গে প্রতিষ্ঠানের নাম দাও\nনিজের লেখা নিজেই পাঠাও',
      cta: 'লেখা পাঠাও',
    },
  },
  {
    label: 'আপনার এলাকার খবর পাঠান',
    values: {
      tag: 'নাগরিক সাংবাদিকতা', headline: 'আপনার এলাকার খবর পাঠান', highlight: 'অনিয়ম, দুর্ভোগ বা ভালো কাজ',
      sub: 'ছবি, ভিডিও বা তথ্য পাঠান — যাচাই করে প্রকাশ করবে মিহা নিউজ। পরিচয় গোপন রাখা হবে।',
      points_title: 'যা পাঠাতে পারেন', points: 'রাস্তা, স্কুল, হাসপাতালের দুর্ভোগ\nঅনিয়ম ও দুর্নীতির তথ্য\nএলাকার সাফল্যের গল্প',
      extra_title: 'মনে রাখুন', extra: 'তারিখ ও জায়গার নাম লিখুন\nগুজব নয়, যা দেখেছেন তা-ই পাঠান',
      cta: 'খবর পাঠান',
    },
  },
  {
    label: 'ছবি আহ্বান',
    values: {
      tag: 'ছবি আহ্বান', headline: 'ক্যামেরায় বাংলাদেশ', highlight: 'আপনার তোলা সেরা ছবি পাঠান',
      sub: 'নির্বাচিত ছবি প্রকাশিত হবে মিহা নিউজে, আলোকচিত্রীর নামসহ।',
      points_title: 'বিষয়', points: 'প্রকৃতি ও ঋতু\nমানুষ ও জীবিকা\nশহর ও গ্রাম',
      extra_title: 'নিয়ম', extra: 'নিজের তোলা ছবি হতে হবে\nছবির জায়গা ও তারিখ লিখুন\nএকজন সর্বোচ্চ ৩টি ছবি',
      cta: 'ছবি পাঠান',
    },
  },
]

function defaultsFor(kind: 'recruit' | 'call', org: OrgInfo): Values {
  const web = org.home_url || `https://${(org.website || 'meeha.news').replace(/^https?:\/\//, '')}`
  return {
    style: 'navy',
    format: 'square',
    ...(kind === 'recruit' ? RECRUIT_PRESETS[0].values : CALL_PRESETS[0].values),
    deadline: '',
    link: kind === 'recruit' ? 'https://correspondent.meeha.news/apply' : web,
    phone: org.whatsapp || org.phone || '',
    email: org.email || '',
    show_qr: '1',
  }
}

const mk = (kind: 'recruit' | 'call'): Design => ({
  key: kind === 'recruit' ? 'social_recruit' : 'social_call',
  kind: 'social',
  name: kind === 'recruit' ? 'নিয়োগ বিজ্ঞপ্তি (সোশ্যাল পোস্ট)' : 'লেখা আহ্বান (সোশ্যাল পোস্ট)',
  about:
    kind === 'recruit'
      ? 'সাংবাদিক, জেলা/উপজেলা/ক্যাম্পাস প্রতিনিধি নিয়োগ · ৫ ডিজাইন · ফিড ও স্টোরি মাপ (বর্গ মাপে জায়গা কম, তাই তালিকার শুধু প্রথম কয়েকটি লাইন আসে)'
      : 'লেখা, মতামত, খবর ও ছবি আহ্বান · ৫ ডিজাইন · ফিড ও স্টোরি মাপ (বর্গ মাপে জায়গা কম, তাই তালিকার শুধু প্রথম কয়েকটি লাইন আসে)',
  fields: commonFields(kind),
  defaults: (org) => defaultsFor(kind, org),
  presets: kind === 'recruit' ? RECRUIT_PRESETS : CALL_PRESETS,
  dpi: SOCIAL_DPI,
  render: (v, c) => socialRender(kind, v, c),
  mockup: (v, c) => phoneScene(socialRender(kind, v, c)[0], kind === 'recruit' ? 'নিয়োগ বিজ্ঞপ্তি · সোশ্যাল পোস্ট' : 'লেখা আহ্বান · সোশ্যাল পোস্ট'),
})

export const SOCIAL_DESIGNS: Design[] = [mk('recruit'), mk('call')]
export { STYLES as SOCIAL_STYLES }
