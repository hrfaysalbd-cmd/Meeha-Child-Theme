/**
 * Round 26 — মিহা নিউজের নিজস্ব ভিডিও বিজ্ঞাপন: short animated promos
 * (6 s) that go into an ad break exactly like an ad clip from the gallery,
 * but are drawn here, so there is nothing to upload and they fit every
 * size (16:9, 9:16, 1:1, 4:5). They match the photocard studio's house ad
 * strips: the same names, the same colours and the same social links /
 * campus address (read from the site through the photocard kit).
 *
 * Nothing here invents a number or a handle: a social handle is shown only
 * when the site's own link has one, otherwise the website is shown.
 */
import { BRAND, FONT_BODY, FONT_DISPLAY } from './assets'
import { clamp, ease, fillRoundRect, fitText, rnd, win, type Ctx } from './motion'
import type { LogoAsset } from './assets'

export type HouseAdId = 'youtube' | 'social' | 'whatsapp' | 'web' | 'shikkha' | 'campus' | 'advertise'

export const HOUSE_ADS: { id: HouseAdId; name: string; hint: string; dur: number }[] = [
  { id: 'youtube', name: 'ইউটিউব সাবস্ক্রাইব', hint: 'চ্যানেলে সাবস্ক্রাইব ও বেল আইকন', dur: 6 },
  { id: 'social', name: 'সোশ্যাল মিডিয়া', hint: 'ফেসবুক, ইউটিউবসহ সব পেজ', dur: 6 },
  { id: 'whatsapp', name: 'হোয়াটসঅ্যাপ চ্যানেল', hint: 'খবর সরাসরি ফোনে', dur: 6 },
  { id: 'web', name: 'ওয়েবসাইট', hint: 'সবার আগে, সঠিক খবর', dur: 6 },
  { id: 'shikkha', name: 'শিক্ষা ও ক্যাম্পাস', hint: 'ভর্তি, পরীক্ষা, ফলাফল, বৃত্তি', dur: 6 },
  { id: 'campus', name: 'ক্যাম্পাস ও ক্যারিয়ার', hint: 'পড়াশোনা, নিয়োগ, ক্যারিয়ার', dur: 6 },
  { id: 'advertise', name: 'বিজ্ঞাপন দিন', hint: 'ব্যবসার প্রচার মিহা নিউজে', dur: 6.5 },
]

export const houseAdDur = (id: HouseAdId) => HOUSE_ADS.find((h) => h.id === id)?.dur ?? 6

/** What the house ads print, from the site (newsroom-os ≥ 1.9.0) — never made up. */
export interface HouseInfo {
  /** Social networks the site links to (facebook, youtube, instagram, x, whatsapp, telegram). */
  nets: string[]
  /** "@handle" taken from one of the site's links, or ''. */
  handle: string
  campusTitle: string
  campusUrl: string
}

export const EMPTY_HOUSE: HouseInfo = { nets: [], handle: '', campusTitle: '', campusUrl: '' }

const NETS: Record<string, string> = { facebook: '#1877F2', youtube: '#FF0000', instagram: '#DD2A7B', x: '#000000', whatsapp: '#25D366', telegram: '#229ED9' }

/** Social links as the site keeps them ([key, label, url]) → nets + a handle (only when a URL really has one). */
export function houseInfo(social: [string, string, string][] | undefined, campus?: { title: string; url: string }): HouseInfo {
  const list = Array.isArray(social) ? social : []
  const nets = list.map((s) => s[0]).filter((k) => !!NETS[k])
  let handle = ''
  for (const s of list) {
    const m = /^https?:\/\/[^/]+\/(?:@)?([A-Za-z0-9_.-]{3,40})\/?$/.exec(String(s[2] || ''))
    if (m && !/^(channel|c|user|profile\.php|groups|pages)$/i.test(m[1])) {
      handle = '@' + m[1]
      break
    }
  }
  return { nets: [...new Set(nets)], handle, campusTitle: campus?.title || '', campusUrl: campus?.url || '' }
}

const disp = (px: number) => `800 ${px}px ${FONT_DISPLAY}`
const body = (w: number, px: number) => `${w} ${px}px ${FONT_BODY}`
const shortUrl = (u: string) => u.replace(/^https?:\/\/(www\.)?/i, '').replace(/\/$/, '')

export function netIcon(ctx: Ctx, net: string, cx: number, cy: number, r: number) {
  ctx.save()
  if (net === 'instagram') {
    const g = ctx.createLinearGradient(cx - r, cy + r, cx + r, cy - r)
    g.addColorStop(0, '#F58529')
    g.addColorStop(0.5, '#DD2A7B')
    g.addColorStop(1, '#8134AF')
    ctx.fillStyle = g
  } else ctx.fillStyle = NETS[net] || '#1546B8'
  ctx.beginPath()
  ctx.arc(cx, cy, r, 0, Math.PI * 2)
  ctx.fill()
  ctx.fillStyle = '#FFFFFF'
  ctx.strokeStyle = '#FFFFFF'
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  const s = r / 34
  if (net === 'facebook') {
    ctx.font = `700 ${Math.round(r * 1.45)}px Arial, Helvetica, sans-serif`
    ctx.textAlign = 'center'
    ctx.textBaseline = 'alphabetic'
    ctx.fillText('f', cx + r * 0.06, cy + r * 0.52)
  } else if (net === 'youtube') {
    ctx.beginPath()
    ctx.moveTo(cx - 9 * s, cy - 13 * s)
    ctx.lineTo(cx + 14 * s, cy)
    ctx.lineTo(cx - 9 * s, cy + 13 * s)
    ctx.closePath()
    ctx.fill()
  } else if (net === 'instagram') {
    ctx.lineWidth = 4 * s
    ctx.beginPath()
    ctx.roundRect(cx - 17 * s, cy - 17 * s, 34 * s, 34 * s, 10 * s)
    ctx.stroke()
    ctx.beginPath()
    ctx.arc(cx, cy, 8 * s, 0, Math.PI * 2)
    ctx.stroke()
    ctx.beginPath()
    ctx.arc(cx + 10 * s, cy - 10 * s, 2.6 * s, 0, Math.PI * 2)
    ctx.fill()
  } else if (net === 'x') {
    ctx.lineWidth = 5 * s
    ctx.beginPath()
    ctx.moveTo(cx - 12 * s, cy - 13 * s)
    ctx.lineTo(cx + 12 * s, cy + 13 * s)
    ctx.moveTo(cx + 12 * s, cy - 13 * s)
    ctx.lineTo(cx - 12 * s, cy + 13 * s)
    ctx.stroke()
  } else if (net === 'whatsapp') {
    ctx.lineWidth = 4 * s
    ctx.beginPath()
    ctx.arc(cx + 1 * s, cy - 1 * s, 15 * s, 0.7, Math.PI * 2 + 0.35)
    ctx.lineTo(cx - 15 * s, cy + 15 * s)
    ctx.closePath()
    ctx.stroke()
    ctx.lineWidth = 5 * s
    ctx.beginPath()
    ctx.arc(cx + 1 * s, cy - 1 * s, 7 * s, Math.PI * 0.7, Math.PI * 1.25)
    ctx.stroke()
  } else if (net === 'telegram') {
    ctx.beginPath()
    ctx.moveTo(cx - 16 * s, cy - 1 * s)
    ctx.lineTo(cx + 15 * s, cy - 13 * s)
    ctx.lineTo(cx + 9 * s, cy + 14 * s)
    ctx.lineTo(cx + 1 * s, cy + 6 * s)
    ctx.lineTo(cx - 4 * s, cy + 12 * s)
    ctx.lineTo(cx - 5 * s, cy + 4 * s)
    ctx.closePath()
    ctx.fill()
  }
  ctx.restore()
}

interface Look {
  bg: [string, string, string]
  bubble: string
  head: string
  sub: string
  ctaBg: string
  ctaFg: string
  kicker: string
}

const LOOKS: Record<HouseAdId, Look> = {
  youtube: { bg: ['#111114', '#2B0B0B', '#5A0D0D'], bubble: 'rgba(255,0,0,0.12)', head: '#FFFFFF', sub: '#FFC9C9', ctaBg: '#FF0000', ctaFg: '#FFFFFF', kicker: '#FF6B6B' },
  social: { bg: ['#0B2A6B', '#1546B8', '#5230CF'], bubble: 'rgba(255,255,255,0.07)', head: '#FFFFFF', sub: 'rgba(255,255,255,0.88)', ctaBg: '#FFC20E', ctaFg: '#1B1400', kicker: '#FFC20E' },
  whatsapp: { bg: ['#064E45', '#0B8A5F', '#25D366'], bubble: 'rgba(255,255,255,0.08)', head: '#FFFFFF', sub: 'rgba(255,255,255,0.9)', ctaBg: '#FFFFFF', ctaFg: '#0B7A4B', kicker: '#C9F7DC' },
  web: { bg: ['#071C4D', '#1546B8', '#2457CF'], bubble: 'rgba(255,255,255,0.06)', head: '#FFFFFF', sub: 'rgba(255,255,255,0.88)', ctaBg: '#FFC20E', ctaFg: '#1B1400', kicker: '#FFC20E' },
  shikkha: { bg: ['#0B3F94', '#0A7FA8', '#0EA58C'], bubble: 'rgba(255,255,255,0.08)', head: '#FFFFFF', sub: 'rgba(255,255,255,0.9)', ctaBg: '#FFC20E', ctaFg: '#1B1400', kicker: '#FFE08A' },
  campus: { bg: ['#F97316', '#E11D48', '#7C3AED'], bubble: 'rgba(255,255,255,0.1)', head: '#FFFFFF', sub: 'rgba(255,255,255,0.92)', ctaBg: '#FFFFFF', ctaFg: '#C2185B', kicker: '#FFF1C2' },
  advertise: { bg: ['#06173F', '#0B2A6B', '#1546B8'], bubble: 'rgba(255,194,14,0.08)', head: '#FFFFFF', sub: 'rgba(255,255,255,0.88)', ctaBg: '#FFC20E', ctaFg: '#1B1400', kicker: '#FFC20E' },
}

interface Words {
  kicker: string
  head: string
  sub: string
  cta: string
}

function words(id: HouseAdId, h: HouseInfo, site: string): Words {
  switch (id) {
    case 'youtube':
      return { kicker: 'মিহা নিউজ ইউটিউব', head: 'ভিডিওতে খবর দেখুন', sub: 'প্রতিদিনের ভিডিও খবর পেতে চ্যানেলটি সাবস্ক্রাইব করুন, বেল আইকন চাপুন', cta: 'সাবস্ক্রাইব করুন' }
    case 'social':
      return { kicker: 'সঙ্গে থাকুন', head: 'সোশ্যাল মিডিয়ায় মিহা নিউজ', sub: 'লাইক, ফলো ও শেয়ার করে প্রতিদিনের খবর পান', cta: h.handle || site }
    case 'whatsapp':
      return { kicker: 'হোয়াটসঅ্যাপ চ্যানেল', head: 'খবর সরাসরি আপনার ফোনে', sub: 'মিহা নিউজের হোয়াটসঅ্যাপ চ্যানেলে যুক্ত হোন', cta: 'ফলো করুন' }
    case 'web':
      return { kicker: site, head: 'সবার আগে, সঠিক খবর', sub: 'দেশ-বিদেশের প্রতিদিনের সব খবর পড়ুন আমাদের ওয়েবসাইটে', cta: site }
    case 'shikkha':
      return { kicker: 'মিহা নিউজ', head: h.campusTitle || 'শিক্ষা ও ক্যাম্পাস', sub: 'ভর্তি • পরীক্ষা • ফলাফল • বৃত্তি — সব খবর এক জায়গায়', cta: 'পড়ুন ' + (h.campusUrl ? shortUrl(h.campusUrl) : site) }
    case 'campus':
      return { kicker: 'প্রতিদিন মিহা নিউজে', head: 'ক্যাম্পাস ও ক্যারিয়ার', sub: 'পড়াশোনা, নিয়োগ আর ক্যারিয়ারের খবর', cta: 'এখনই পড়ুন' }
    case 'advertise':
      return { kicker: 'ব্যবসার প্রচার', head: 'আপনার বিজ্ঞাপন দিন মিহা নিউজে', sub: 'ওয়েবসাইট • ভিডিও • ফটোকার্ড — যোগাযোগ করুন', cta: site }
  }
}

function bubbles(ctx: Ctx, W: number, H: number, t: number, color: string) {
  ctx.fillStyle = color
  const u = Math.min(W, H)
  for (let i = 0; i < 7; i++) {
    const x = W * (0.1 + rnd(i) * 0.9) + Math.sin(t * 0.6 + i) * u * 0.03
    const y = H * (0.05 + rnd(i + 9) * 0.9) - t * u * 0.02 * (1 + rnd(i + 3))
    const r = u * (0.06 + rnd(i + 20) * 0.16)
    ctx.beginPath()
    ctx.arc(x, y, r, 0, Math.PI * 2)
    ctx.fill()
  }
}

/** The ad's picture: an icon / small animated mock-up inside the box (cx, cy, size). */
function hero(ctx: Ctx, id: HouseAdId, cx: number, cy: number, size: number, t: number, h: HouseInfo, logo: LogoAsset, site: string) {
  const r = size / 2
  const pop = ease.outBack(win(t, 0.15, 0.7))
  ctx.save()
  ctx.translate(cx, cy)
  ctx.scale(pop, pop)
  switch (id) {
    case 'youtube': {
      const beat = 1 + 0.04 * Math.sin(t * 6)
      ctx.scale(beat, beat)
      ctx.shadowColor = 'rgba(255,0,0,0.6)'
      ctx.shadowBlur = r * 0.4
      fillRoundRect(ctx, -r * 0.9, -r * 0.62, r * 1.8, r * 1.24, r * 0.32, '#FF0000')
      ctx.shadowBlur = 0
      ctx.fillStyle = '#FFFFFF'
      ctx.beginPath()
      ctx.moveTo(-r * 0.24, -r * 0.34)
      ctx.lineTo(r * 0.4, 0)
      ctx.lineTo(-r * 0.24, r * 0.34)
      ctx.closePath()
      ctx.fill()
      // the bell rings after the "tap"
      const ring = win(t, 2.6, 3.4)
      if (ring > 0) {
        ctx.save()
        ctx.translate(r * 0.85, -r * 0.62)
        ctx.rotate(Math.sin(t * 22) * 0.35 * (1 - win(t, 3.4, 4.4)))
        ctx.globalAlpha = ease.outCubic(ring)
        ctx.fillStyle = '#FFC20E'
        ctx.beginPath()
        ctx.arc(0, 0, r * 0.26, 0, Math.PI * 2)
        ctx.fill()
        ctx.fillStyle = '#1B1400'
        ctx.beginPath()
        ctx.moveTo(-r * 0.12, r * 0.08)
        ctx.quadraticCurveTo(-r * 0.12, -r * 0.14, 0, -r * 0.15)
        ctx.quadraticCurveTo(r * 0.12, -r * 0.14, r * 0.12, r * 0.08)
        ctx.closePath()
        ctx.fill()
        ctx.beginPath()
        ctx.arc(0, r * 0.12, r * 0.04, 0, Math.PI * 2)
        ctx.fill()
        ctx.restore()
      }
      break
    }
    case 'social': {
      const nets = h.nets.length ? h.nets.slice(0, 5) : ['facebook', 'youtube']
      const n = nets.length
      const gap = (r * 2) / Math.max(n, 2.6)
      const ir = Math.min(gap * 0.42, r * 0.36)
      nets.forEach((net, i) => {
        const k = ease.outBack(win(t, 0.4 + i * 0.18, 0.9 + i * 0.18))
        const x = (i - (n - 1) / 2) * gap
        const y = Math.sin(t * 2.4 + i) * r * 0.04
        ctx.save()
        ctx.translate(x, y)
        ctx.scale(k, k)
        ctx.fillStyle = 'rgba(255,255,255,0.95)'
        ctx.beginPath()
        ctx.arc(0, 0, ir * 1.14, 0, Math.PI * 2)
        ctx.fill()
        netIcon(ctx, net, 0, 0, ir)
        ctx.restore()
      })
      break
    }
    case 'whatsapp': {
      // a phone with chat bubbles sliding in
      const pw = r * 1.0
      const ph = r * 1.8
      fillRoundRect(ctx, -pw / 2, -ph / 2, pw, ph, pw * 0.16, '#0B1A16')
      fillRoundRect(ctx, -pw / 2 + pw * 0.06, -ph / 2 + pw * 0.06, pw * 0.88, ph - pw * 0.12, pw * 0.11, '#ECE5DD')
      fillRoundRect(ctx, -pw / 2 + pw * 0.06, -ph / 2 + pw * 0.06, pw * 0.88, ph * 0.13, pw * 0.11, '#075E54')
      netIcon(ctx, 'whatsapp', -pw * 0.28, -ph / 2 + pw * 0.06 + ph * 0.065, ph * 0.045)
      ctx.fillStyle = '#FFFFFF'
      ctx.fillRect(-pw * 0.18, -ph / 2 + pw * 0.06 + ph * 0.05, pw * 0.4, ph * 0.03)
      for (let i = 0; i < 4; i++) {
        const k = ease.outCubic(win(t, 0.8 + i * 0.45, 1.2 + i * 0.45))
        if (k <= 0) continue
        const y = -ph / 2 + ph * (0.24 + i * 0.17)
        const w = pw * (0.5 + rnd(i) * 0.25)
        const x = -pw / 2 + pw * 0.12 - (1 - k) * pw * 0.4
        ctx.globalAlpha = k
        fillRoundRect(ctx, x, y, w, ph * 0.12, pw * 0.05, '#FFFFFF')
        ctx.fillStyle = '#9CA3AF'
        ctx.fillRect(x + pw * 0.05, y + ph * 0.03, w * 0.75, ph * 0.02)
        ctx.fillRect(x + pw * 0.05, y + ph * 0.07, w * 0.5, ph * 0.02)
        ctx.fillStyle = '#25D366'
        ctx.fillRect(x, y, pw * 0.018, ph * 0.12)
        ctx.globalAlpha = 1
      }
      break
    }
    case 'web': {
      // a browser window: the address types itself, headlines appear
      const bw = r * 2
      const bh = r * 1.4
      ctx.shadowColor = 'rgba(0,0,0,0.35)'
      ctx.shadowBlur = r * 0.15
      fillRoundRect(ctx, -bw / 2, -bh / 2, bw, bh, r * 0.08, '#FFFFFF')
      ctx.shadowBlur = 0
      fillRoundRect(ctx, -bw / 2, -bh / 2, bw, bh * 0.16, r * 0.08, '#E5E7EB')
      ;['#EF4444', '#F59E0B', '#22C55E'].forEach((c, i) => {
        ctx.fillStyle = c
        ctx.beginPath()
        ctx.arc(-bw / 2 + r * 0.1 + i * r * 0.09, -bh / 2 + bh * 0.08, r * 0.03, 0, Math.PI * 2)
        ctx.fill()
      })
      fillRoundRect(ctx, -bw / 2 + r * 0.4, -bh / 2 + bh * 0.03, bw * 0.7, bh * 0.1, bh * 0.05, '#FFFFFF')
      const typed = Math.floor(site.length * win(t, 0.5, 1.6))
      ctx.fillStyle = '#111827'
      ctx.font = body(600, bh * 0.065)
      ctx.textBaseline = 'middle'
      ctx.textAlign = 'left'
      ctx.fillText(site.slice(0, typed) + (Math.floor(t * 2) % 2 && typed < site.length ? '|' : ''), -bw / 2 + r * 0.48, -bh / 2 + bh * 0.08)
      const lh = logo.h
      const lw2 = (logo.w / lh) * bh * 0.09
      ctx.drawImage(logo.color, -bw / 2 + r * 0.12, -bh / 2 + bh * 0.22, lw2, bh * 0.09)
      ctx.fillStyle = BRAND.red
      ctx.fillRect(-bw / 2, -bh / 2 + bh * 0.35, bw, bh * 0.012)
      for (let i = 0; i < 3; i++) {
        const k = ease.outCubic(win(t, 1.4 + i * 0.3, 1.9 + i * 0.3))
        const y = -bh / 2 + bh * (0.42 + i * 0.18)
        ctx.globalAlpha = k
        fillRoundRect(ctx, -bw / 2 + r * 0.12, y, bw * 0.28, bh * 0.14, r * 0.03, ['#93C5FD', '#FCD34D', '#A7F3D0'][i])
        ctx.fillStyle = '#1F2937'
        ctx.fillRect(-bw / 2 + r * 0.12 + bw * 0.32, y + bh * 0.02, bw * 0.5 * k, bh * 0.03)
        ctx.fillStyle = '#9CA3AF'
        ctx.fillRect(-bw / 2 + r * 0.12 + bw * 0.32, y + bh * 0.08, bw * 0.38 * k, bh * 0.025)
        ctx.globalAlpha = 1
      }
      break
    }
    case 'shikkha': {
      ctx.fillStyle = '#FFC20E'
      ctx.beginPath()
      ctx.arc(0, 0, r * 0.8, 0, Math.PI * 2)
      ctx.fill()
      const bob = Math.sin(t * 3) * r * 0.03
      ctx.translate(0, bob)
      ctx.fillStyle = '#0B2A6B'
      ctx.beginPath()
      ctx.moveTo(0, -r * 0.45)
      ctx.lineTo(r * 0.62, -r * 0.16)
      ctx.lineTo(0, r * 0.12)
      ctx.lineTo(-r * 0.62, -r * 0.16)
      ctx.closePath()
      ctx.fill()
      ctx.beginPath()
      ctx.moveTo(-r * 0.34, 0)
      ctx.lineTo(-r * 0.34, r * 0.28)
      ctx.quadraticCurveTo(0, r * 0.5, r * 0.34, r * 0.28)
      ctx.lineTo(r * 0.34, 0)
      ctx.lineTo(0, r * 0.16)
      ctx.closePath()
      ctx.fill()
      ctx.strokeStyle = '#0B2A6B'
      ctx.lineWidth = r * 0.05
      ctx.beginPath()
      ctx.moveTo(r * 0.55, -r * 0.12)
      ctx.lineTo(r * 0.55, r * 0.34)
      ctx.stroke()
      break
    }
    case 'campus': {
      ctx.fillStyle = '#FFFFFF'
      ctx.beginPath()
      ctx.arc(0, 0, r * 0.8, 0, Math.PI * 2)
      ctx.fill()
      ctx.fillStyle = '#E11D48'
      ctx.beginPath()
      ctx.moveTo(-r * 0.52, -r * 0.18)
      ctx.lineTo(0, -r * 0.5)
      ctx.lineTo(r * 0.52, -r * 0.18)
      ctx.closePath()
      ctx.fill()
      for (const x of [-0.38, -0.08, 0.22]) ctx.fillRect(x * r, -r * 0.1, r * 0.17, r * 0.44)
      ctx.fillRect(-r * 0.52, r * 0.38, r * 1.04, r * 0.12)
      // a rising star (career)
      const k = win(t, 1, 2)
      ctx.fillStyle = '#FFC20E'
      ctx.globalAlpha = k
      star(ctx, r * 0.55, -r * 0.55 - k * r * 0.1, r * 0.16)
      ctx.globalAlpha = 1
      break
    }
    case 'advertise': {
      // megaphone with waves
      ctx.fillStyle = '#FFC20E'
      ctx.beginPath()
      ctx.moveTo(-r * 0.55, -r * 0.18)
      ctx.lineTo(r * 0.25, -r * 0.55)
      ctx.lineTo(r * 0.25, r * 0.55)
      ctx.lineTo(-r * 0.55, r * 0.18)
      ctx.closePath()
      ctx.fill()
      fillRoundRect(ctx, -r * 0.75, -r * 0.2, r * 0.25, r * 0.4, r * 0.06, '#FFFFFF')
      fillRoundRect(ctx, -r * 0.5, r * 0.15, r * 0.16, r * 0.42, r * 0.05, '#FFFFFF')
      ctx.strokeStyle = '#FFFFFF'
      ctx.lineCap = 'round'
      for (let i = 0; i < 3; i++) {
        const p = (t * 0.9 + i / 3) % 1
        ctx.globalAlpha = 1 - p
        ctx.lineWidth = r * 0.06
        ctx.beginPath()
        ctx.arc(r * 0.25, 0, r * (0.3 + p * 0.55), -0.6, 0.6)
        ctx.stroke()
      }
      ctx.globalAlpha = 1
      break
    }
  }
  ctx.restore()
}

function star(ctx: Ctx, cx: number, cy: number, r: number) {
  ctx.beginPath()
  for (let i = 0; i < 10; i++) {
    const a = -Math.PI / 2 + (i * Math.PI) / 5
    const rr = i % 2 ? r * 0.45 : r
    ctx.lineTo(cx + Math.cos(a) * rr, cy + Math.sin(a) * rr)
  }
  ctx.closePath()
  ctx.fill()
}

/**
 * Paints the ad at `t` seconds of `dur`, full frame W×H. `logo` is the
 * Meeha logo, `site` the website shown when there is no handle.
 */
export function drawHouseAd(ctx: Ctx, id: HouseAdId, W: number, H: number, t: number, dur: number, h: HouseInfo, logo: LogoAsset, site: string) {
  const look = LOOKS[id]
  const w = words(id, h, site)
  const u = Math.min(W, H) / 1080
  const wide = W / H > 1.25
  ctx.save()
  const g = ctx.createLinearGradient(0, 0, W, H)
  g.addColorStop(0, look.bg[0])
  g.addColorStop(0.55, look.bg[1])
  g.addColorStop(1, look.bg[2])
  ctx.fillStyle = g
  ctx.fillRect(0, 0, W, H)
  bubbles(ctx, W, H, t, look.bubble)
  // a light sweep across, now and then
  const sp = (t % 3.2) / 3.2
  const sg = ctx.createLinearGradient(W * (sp * 1.6 - 0.4), 0, W * (sp * 1.6 - 0.1), H)
  sg.addColorStop(0, 'rgba(255,255,255,0)')
  sg.addColorStop(0.5, 'rgba(255,255,255,0.06)')
  sg.addColorStop(1, 'rgba(255,255,255,0)')
  ctx.fillStyle = sg
  ctx.fillRect(0, 0, W, H)

  // layout: picture left + words right on wide frames, picture above words otherwise
  const pad = 70 * u
  let heroBox: { cx: number; cy: number; size: number }
  let tx: number
  let ty: number
  let tw: number
  let align: CanvasTextAlign
  if (wide) {
    const size = Math.min(H * 0.6, W * 0.32)
    heroBox = { cx: pad + size * 0.6, cy: H * 0.5, size }
    tx = pad + size * 1.3
    tw = W - tx - pad
    ty = H * 0.24
    align = 'left'
  } else {
    const size = Math.min(W * 0.62, H * 0.34)
    heroBox = { cx: W / 2, cy: H * (H / W > 1.5 ? 0.33 : 0.3), size }
    tx = W / 2
    tw = W - pad * 2
    ty = heroBox.cy + size * 0.62 + 30 * u
    align = 'center'
  }
  hero(ctx, id, heroBox.cx, heroBox.cy, heroBox.size, t, h, logo, site)

  const rise = (a: number) => ease.outCubic(win(t, a, a + 0.5))
  ctx.textAlign = align
  ctx.textBaseline = 'top'
  // kicker
  let y = ty
  ctx.globalAlpha = rise(0.35)
  ctx.font = body(700, 40 * u)
  ctx.fillStyle = look.kicker
  ctx.fillText(w.kicker, tx, y + (1 - rise(0.35)) * 30 * u)
  y += 62 * u
  // headline
  const ft = fitText(ctx, w.head, disp, tw, wide ? 2 : 3, (wide ? 92 : 96) * u, 54 * u, 1.18)
  ctx.font = disp(ft.size)
  ctx.fillStyle = look.head
  ft.lines.forEach((line, i) => {
    const k = rise(0.5 + i * 0.12)
    ctx.globalAlpha = k
    ctx.fillText(line, tx, y + i * ft.lineHeight + (1 - k) * 40 * u)
  })
  y += ft.lines.length * ft.lineHeight + 16 * u
  // sub line
  const fs = fitText(ctx, w.sub, (px) => body(600, px), tw, 2, 44 * u, 30 * u, 1.3)
  ctx.font = body(600, fs.size)
  ctx.fillStyle = look.sub
  fs.lines.forEach((line, i) => {
    ctx.globalAlpha = rise(0.85 + i * 0.1)
    ctx.fillText(line, tx, y + i * fs.lineHeight)
  })
  y += fs.lines.length * fs.lineHeight + 40 * u
  ctx.globalAlpha = 1

  // call to action: pops in, pulses; on YouTube a finger "taps" it at 2.4 s and it turns grey
  const ctaIn = ease.outBack(win(t, 1.3, 1.8))
  if (ctaIn > 0.01) {
    ctx.font = body(800, 46 * u)
    const cw = ctx.measureText(w.cta).width + 90 * u
    const ch = 96 * u
    const cx = align === 'center' ? tx - cw / 2 : tx
    const tapped = id === 'youtube' && t > 2.5
    const pulse = tapped ? 1 : 1 + 0.035 * Math.sin(t * 5)
    ctx.save()
    ctx.translate(cx + cw / 2, y + ch / 2)
    ctx.scale(ctaIn * pulse, ctaIn * pulse)
    ctx.shadowColor = 'rgba(0,0,0,0.3)'
    ctx.shadowBlur = 20 * u
    ctx.shadowOffsetY = 6 * u
    fillRoundRect(ctx, -cw / 2, -ch / 2, cw, ch, ch / 2, tapped ? '#3F3F46' : look.ctaBg)
    ctx.shadowBlur = 0
    ctx.shadowOffsetY = 0
    ctx.fillStyle = tapped ? '#E4E4E7' : look.ctaFg
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText(tapped ? 'সাবস্ক্রাইবড ✓' : w.cta, 0, 3 * u)
    ctx.restore()
    if (id === 'youtube') {
      // the "finger": a white circle that moves in and taps
      const k = win(t, 1.9, 2.45)
      const tapK = win(t, 2.45, 2.6)
      const leave = win(t, 3, 3.6)
      if (k > 0 && leave < 1) {
        const fx = cx + cw * 0.7 + (1 - ease.outCubic(k)) * 160 * u
        const fy = y + ch * 0.6 + (1 - ease.outCubic(k)) * 120 * u
        ctx.globalAlpha = 1 - leave
        ctx.fillStyle = 'rgba(255,255,255,0.9)'
        ctx.beginPath()
        ctx.arc(fx, fy, 28 * u * (1 - 0.25 * Math.sin(tapK * Math.PI)), 0, Math.PI * 2)
        ctx.fill()
        if (tapK > 0) {
          ctx.strokeStyle = 'rgba(255,255,255,0.8)'
          ctx.lineWidth = 4 * u
          ctx.beginPath()
          ctx.arc(fx, fy, 28 * u + tapK * 50 * u, 0, Math.PI * 2)
          ctx.stroke()
        }
        ctx.globalAlpha = 1
      }
    }
  }

  // Meeha logo, bottom right (wide) or bottom centre
  const lh = 64 * u
  const lw = (logo.w / logo.h) * lh
  const lk = win(t, 0.6, 1.1)
  ctx.globalAlpha = lk
  fillRoundRect(ctx, (wide ? W - pad - lw : W / 2 - lw / 2) - 22 * u, H - pad * 0.7 - lh - 14 * u, lw + 44 * u, lh + 28 * u, (lh + 28 * u) / 2, 'rgba(255,255,255,0.95)')
  ctx.drawImage(logo.color, wide ? W - pad - lw : W / 2 - lw / 2, H - pad * 0.7 - lh, lw, lh)
  ctx.globalAlpha = 1

  // in from black / out to black
  const fade = Math.max(1 - win(t, 0, 0.25), win(t, dur - 0.3, dur))
  if (fade > 0) {
    ctx.fillStyle = `rgba(0,0,0,${clamp(fade)})`
    ctx.fillRect(0, 0, W, H)
  }
  ctx.restore()
}
