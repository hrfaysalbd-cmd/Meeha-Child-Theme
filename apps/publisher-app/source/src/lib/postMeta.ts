/**
 * Round 10 — shared helpers for post/video management: the viewer's
 * effective permissions (delete / change date / change author), conversion
 * between WordPress's 'YYYY-MM-DD HH:MM:SS' site-time strings and
 * <input type="datetime-local"> values, and Bengali labels.
 */
import { useAuth } from './AuthContext'
import { bnNumber } from '../components/ui'

// The site (meeha.news) runs on Bangladesh time; post dates are site-local
// wall-clock strings, so "is this in the future?" is judged in this zone
// regardless of the viewer's own device clock zone.
export const SITE_TIME_ZONE = 'Asia/Dhaka'

export interface PostPermissions {
  deletePosts: boolean
  changeDate: boolean
  changeAuthor: boolean
}

export function usePostPermissions(): PostPermissions {
  const { profile } = useAuth()
  const can = profile?.can
  // An older backend that doesn't send the round-10 flags: Publisher keeps
  // its always-allowed rights, an Editor gets none (the server decides anyway).
  const fallback = !!can?.publisher
  return {
    deletePosts: can?.delete_posts ?? fallback,
    changeDate: can?.change_post_date ?? fallback,
    changeAuthor: can?.change_post_author ?? fallback,
  }
}

export const NEED_PUBLISHER_PERMISSION = 'পাবলিশারের অনুমতি প্রয়োজন'

/** 'YYYY-MM-DD HH:MM:SS' → 'YYYY-MM-DDTHH:MM' (datetime-local value). */
export function wpDateToInput(wp: string | null | undefined): string {
  if (!wp) return ''
  const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/.exec(wp)
  return m ? `${m[1]}T${m[2]}:${m[3]}` : ''
}

/**
 * Round 13 — the date-input value to show for a loaded post/video. A draft or
 * pending item whose date is not set yet ("floating": the server's
 * date_floating, or by status for an older backend) shows an EMPTY field —
 * its stored date is only the last save/submission time, and WordPress
 * stamps the real time when it is published. Prefilling it made an untouched
 * field look like a chosen date (the round-13 backdating bug).
 */
export function initialDateInput(item: { date?: string | null; created_at?: string | null; status?: string; date_floating?: boolean } | null): string {
  if (!item) return ''
  const floating = item.date_floating ?? (item.status === 'draft' || item.status === 'pending')
  return floating ? '' : wpDateToInput(item.date ?? item.created_at)
}

/** 'YYYY-MM-DDTHH:MM' → 'YYYY-MM-DD HH:MM:00'. */
export function inputToWpDate(value: string): string {
  const m = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})/.exec(value)
  return m ? `${m[1]} ${m[2]}:${m[3]}:00` : ''
}

/** Current site wall-clock time as a datetime-local value. */
export function siteNowInput(): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: SITE_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date())
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '00'
  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`
}

/** True when a datetime-local value lies after the site's current time. */
export function isFutureSiteTime(value: string): boolean {
  return !!value && value > siteNowInput()
}

const MONTHS = ['জানু', 'ফেব্রু', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টে', 'অক্টো', 'নভে', 'ডিসে']

function dayPart(h: number) {
  if (h < 4) return 'রাত'
  if (h < 6) return 'ভোর'
  if (h < 12) return 'সকাল'
  if (h < 15) return 'দুপুর'
  if (h < 18) return 'বিকাল'
  if (h < 20) return 'সন্ধ্যা'
  return 'রাত'
}

/** '2026-10-05 14:30:00' → 'দুপুর ২:৩০' ('' when there's no time part). */
export function bnTime(wp: string | null | undefined): string {
  const m = wp ? /[ T](\d{2}):(\d{2})/.exec(wp) : null
  if (!m) return ''
  const h = Number(m[1])
  const h12 = h % 12 === 0 ? 12 : h % 12
  return `${dayPart(h)} ${bnNumber(h12)}:${bnNumber(m[2])}`
}

/** '2026-10-05 14:30:00' → '৫ অক্টো, ২০২৬ · দুপুর ২:৩০' */
export function bnDateTime(wp: string | null | undefined, withTime = true): string {
  if (!wp) return '—'
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(wp)
  if (!m) return wp
  const [, y, mo, d] = m
  const date = `${bnNumber(Number(d))} ${MONTHS[Number(mo) - 1] ?? ''}, ${bnNumber(y)}`
  const time = withTime ? bnTime(wp) : ''
  return time ? `${date} · ${time}` : date
}

export function roleLabel(role: string): string {
  switch (role) {
    case 'administrator':
      return 'অ্যাডমিন'
    case 'meeha_publisher':
      return 'পাবলিশার'
    case 'editor':
      return 'এডিটর'
    case 'correspondent':
      return 'সংবাদদাতা'
    default:
      return role || '—'
  }
}

/** Bengali duration, e.g. 75 → '১:১৫'. */
export function bnDuration(seconds: number | null | undefined): string {
  if (!seconds || seconds < 0) return ''
  const s = Math.round(seconds)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = String(s % 60).padStart(2, '0')
  return bnNumber(h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`)
}

/** Bengali file size, e.g. 5242880 → '৫ MB'. */
export function bnFileSize(bytes: number): string {
  if (bytes >= 1024 * 1024) {
    const mb = bytes / (1024 * 1024)
    return `${bnNumber(mb >= 10 ? Math.round(mb) : mb.toFixed(1))} MB`
  }
  if (bytes <= 0) return `${bnNumber(0)} KB`
  return `${bnNumber(Math.max(1, Math.round(bytes / 1024)))} KB`
}

/** WordPress stores non-Latin slugs percent-encoded; show them readable. */
export function decodeSlug(slug: string | null | undefined): string {
  if (!slug) return ''
  try {
    return decodeURIComponent(slug)
  } catch {
    return slug
  }
}
