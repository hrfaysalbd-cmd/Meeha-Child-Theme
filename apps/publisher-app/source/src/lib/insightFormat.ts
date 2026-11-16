// Round 20 — small formatting helpers for the analytics dashboard.

const BN = '০১২৩৪৫৬৭৮৯'

export const bnDigits = (s: string | number) => String(s).replace(/[0-9]/g, (d) => BN[Number(d)])

/** 12345 → '১২,৩৪৫' (Bangladeshi grouping). */
export function bnInt(n: number | null | undefined): string {
  const v = Math.round(Number(n) || 0)
  try {
    return new Intl.NumberFormat('bn-BD').format(v)
  } catch {
    return bnDigits(v)
  }
}

/** 1520 → '১.৫ হাজার', 1_250_000 → '১২.৫ লাখ' — for tight spots (axes, chips). */
export function bnShort(n: number): string {
  const v = Math.abs(n)
  if (v >= 10_000_000) return bnDigits(+(n / 10_000_000).toFixed(1)) + ' কোটি'
  if (v >= 100_000) return bnDigits(+(n / 100_000).toFixed(1)) + ' লাখ'
  if (v >= 1_000) return bnDigits(+(n / 1_000).toFixed(1)) + ' হাজার'
  return bnDigits(Math.round(n))
}

/** Percent change, or null when there is nothing to compare with. */
export function pctChange(cur: number, prev: number | undefined | null): number | null {
  if (prev === undefined || prev === null) return null
  if (prev === 0) return cur === 0 ? 0 : null
  return ((cur - prev) / prev) * 100
}

export function timeAgoBn(unixSec: number, nowMs = Date.now()): string {
  if (!unixSec) return 'কখনো না'
  const s = Math.max(0, Math.round(nowMs / 1000 - unixSec))
  if (s < 60) return 'এইমাত্র'
  if (s < 3600) return `${bnDigits(Math.floor(s / 60))} মিনিট আগে`
  if (s < 86400) return `${bnDigits(Math.floor(s / 3600))} ঘণ্টা আগে`
  return `${bnDigits(Math.floor(s / 86400))} দিন আগে`
}

/** 'Y-m-d H:i:s' (site-local) → relative Bangla, measured against the browser clock. */
export function wpAgoBn(wp: string | null | undefined): string {
  if (!wp) return '—'
  const t = Date.parse(wp.replace(' ', 'T'))
  return Number.isNaN(t) ? wp : timeAgoBn(t / 1000)
}

const WEEKDAYS = ['রবিবার', 'সোমবার', 'মঙ্গলবার', 'বুধবার', 'বৃহস্পতিবার', 'শুক্রবার', 'শনিবার']
const WEEKDAYS_SHORT = ['রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহ', 'শুক্র', 'শনি']
const MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর']
const MONTHS_SHORT = ['জানু', 'ফেব্রু', 'মার্চ', 'এপ্রি', 'মে', 'জুন', 'জুলা', 'আগ', 'সেপ্টে', 'অক্টো', 'নভে', 'ডিসে']

/** 'Y-m-d' → Date at local noon (never shifts a day across time zones). */
const ymd = (s: string) => {
  const [y, m, d] = s.split('-').map(Number)
  return new Date(y, (m || 1) - 1, d || 1, 12)
}

export const dayLabelShort = (s: string) => {
  const d = ymd(s)
  return `${bnDigits(d.getDate())} ${MONTHS_SHORT[d.getMonth()]}`
}
export const dayLabelLong = (s: string) => {
  const d = ymd(s)
  return `${WEEKDAYS[d.getDay()]}, ${bnDigits(d.getDate())} ${MONTHS[d.getMonth()]}`
}
export const weekdayShort = (s: string) => WEEKDAYS_SHORT[ymd(s).getDay()]

export function todayLongBn(d = new Date()): string {
  return `${WEEKDAYS[d.getDay()]}, ${bnDigits(d.getDate())} ${MONTHS[d.getMonth()]} ${bnDigits(d.getFullYear())}`
}

export function hourLabelBn(h: number): string {
  const part = h < 4 ? 'রাত' : h < 6 ? 'ভোর' : h < 12 ? 'সকাল' : h < 15 ? 'দুপুর' : h < 18 ? 'বিকেল' : h < 20 ? 'সন্ধ্যা' : 'রাত'
  const h12 = h % 12 === 0 ? 12 : h % 12
  return `${part} ${bnDigits(h12)}টা`
}
