const BN = '০১২৩৪৫৬৭৮৯'

export const bnNum = (n: number | string) => String(n).replace(/\d/g, (d) => BN[Number(d)])

export const DEFAULT_LABELS = [
  { key: 'urgent', name: 'জরুরি', color: '#dc2626' },
  { key: 'meeting', name: 'মিটিং', color: '#7c3aed' },
  { key: 'notice', name: 'ঘোষণা', color: '#2563eb' },
  { key: 'task', name: 'কাজ', color: '#d97706' },
  { key: 'info', name: 'তথ্য চাই', color: '#0d9488' },
  { key: 'general', name: 'সাধারণ', color: '#64748b' },
] as const

export const LABEL_ICON: Record<string, string> = {
  urgent: '🚨',
  meeting: '📅',
  notice: '📢',
  task: '✅',
  info: '❓',
  general: '💬',
}

const pad = (n: number) => (n < 10 ? `0${n}` : String(n))

/** ১০:০৫ AM style, Bangla digits. */
export function clock(iso: string | null | undefined) {
  if (!iso) return ''
  const d = new Date(iso)
  let h = d.getHours()
  const ap = h < 12 ? 'AM' : 'PM'
  h = h % 12 || 12
  return bnNum(`${h}:${pad(d.getMinutes())}`) + ' ' + ap
}

const MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর']
const DAYS = ['রবিবার', 'সোমবার', 'মঙ্গলবার', 'বুধবার', 'বৃহস্পতিবার', 'শুক্রবার', 'শনিবার']

export function dayKey(iso: string) {
  const d = new Date(iso)
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`
}

export function dayLabel(iso: string) {
  const d = new Date(iso)
  const today = new Date()
  const y = new Date()
  y.setDate(today.getDate() - 1)
  if (dayKey(d.toISOString()) === dayKey(today.toISOString())) return 'আজ'
  if (dayKey(d.toISOString()) === dayKey(y.toISOString())) return 'গতকাল'
  const diff = (today.getTime() - d.getTime()) / 86400000
  if (diff < 6) return DAYS[d.getDay()]
  return `${bnNum(d.getDate())} ${MONTHS[d.getMonth()]}${d.getFullYear() !== today.getFullYear() ? ' ' + bnNum(d.getFullYear()) : ''}`
}

/** For the room list: time today, else "গতকাল" / day / date. */
export function shortWhen(iso: string | null | undefined) {
  if (!iso) return ''
  const l = dayLabel(iso)
  return l === 'আজ' ? clock(iso) : l
}

export function fullWhen(iso: string) {
  return `${dayLabel(iso)}, ${clock(iso)}`
}

export function fileSize(b: number) {
  if (b < 1024) return `${bnNum(b)} B`
  if (b < 1024 * 1024) return `${bnNum(Math.round(b / 1024))} KB`
  return `${bnNum((b / 1024 / 1024).toFixed(1))} MB`
}

export function duration(s: number) {
  s = Math.max(0, Math.round(s))
  return bnNum(`${Math.floor(s / 60)}:${pad(s % 60)}`)
}

/** Time left until a meeting, in words. */
export function until(iso: string) {
  const ms = new Date(iso).getTime() - Date.now()
  if (ms < -60 * 60000) return 'শেষ'
  if (ms < 0) return 'চলছে'
  const m = Math.round(ms / 60000)
  if (m < 60) return `${bnNum(m)} মিনিট পর`
  const h = Math.round(m / 60)
  if (h < 24) return `${bnNum(h)} ঘণ্টা পর`
  return `${bnNum(Math.round(h / 24))} দিন পর`
}

/** A small .ics file so a meeting can go into the phone's calendar. */
export function meetingIcs(title: string, iso: string, room: string) {
  const d = new Date(iso)
  const e = new Date(d.getTime() + 60 * 60000)
  const f = (x: Date) => x.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '')
  const body = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Meeha//Chat//BN',
    'BEGIN:VEVENT',
    `UID:${d.getTime()}-${Math.random().toString(36).slice(2)}@meeha`,
    `DTSTAMP:${f(new Date())}`,
    `DTSTART:${f(d)}`,
    `DTEND:${f(e)}`,
    `SUMMARY:${title.replace(/[\r\n]+/g, ' ').slice(0, 120)}`,
    `DESCRIPTION:মিহা চ্যাট — ${room}`,
    'END:VEVENT',
    'END:VCALENDAR',
  ].join('\r\n')
  const url = URL.createObjectURL(new Blob([body], { type: 'text/calendar' }))
  const a = document.createElement('a')
  a.href = url
  a.download = 'meeha-meeting.ics'
  a.click()
  setTimeout(() => URL.revokeObjectURL(url), 2000)
}

export const isTouch = () => typeof window !== 'undefined' && window.matchMedia?.('(hover: none)').matches

export function initials(name: string) {
  const p = name.trim().split(/\s+/)
  return (p[0]?.[0] ?? '') + (p[1]?.[0] ?? '')
}

const TINTS = ['#fde68a', '#bfdbfe', '#c7d2fe', '#fbcfe8', '#bbf7d0', '#fed7aa', '#ddd6fe', '#a5f3fc']
export const tint = (id: number) => TINTS[Math.abs(id) % TINTS.length]

/** <input type="datetime-local"> value → ISO (UTC). */
export function localToIso(v: string) {
  if (!v) return ''
  const d = new Date(v)
  return isNaN(d.getTime()) ? '' : d.toISOString()
}
