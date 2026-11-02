/**
 * Pure, side-effect-free computations behind the correspondent dashboard's
 * "automation" layer (Home.tsx) — streaks, badges, week/month comparisons,
 * and plain-language insights. Kept separate from the component so each
 * piece is independently testable and Home.tsx stays a rendering layer, not
 * a data-crunching one.
 *
 * Every number here is derived strictly from real data the correspondent
 * already has (their own submissions + their own ledger entries) — nothing
 * is invented or simulated, including badge thresholds (fixed, disclosed
 * tiers) and the "best day" / "week over week" insights (computed from
 * actual AnalyticsTrendPoint rows, never guessed).
 */
import type { AnalyticsTrendPoint, LedgerEntry, Submission } from './api'

export const weekdayBn = ['রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহ', 'শুক্র', 'শনি']
export const monthBn = ['জানু', 'ফেব্রু', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টে', 'অক্টো', 'নভে', 'ডিসে']

export function bnDigitsNum(n: number | string) {
  const digits: Record<string, string> = { '0': '০', '1': '১', '2': '২', '3': '৩', '4': '৪', '5': '৫', '6': '৬', '7': '৭', '8': '৮', '9': '৯' }
  return String(n).replace(/[0-9]/g, (d) => digits[d])
}

function dateKey(d: Date) {
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`
}

// ---- Writing streak ----
// Loss-aversion is the whole point here: a correspondent who sees an active
// streak is far more motivated to not let it lapse than one who just sees a
// raw count. "Active day" is approximated as a day the correspondent
// created at least one submission (draft or otherwise) — the only activity
// signal this app actually has; there's no finer-grained edit log to draw
// on, so this stays honest about what it's counting rather than pretending
// to track more than it does.
export interface StreakInfo {
  current: number
  wroteToday: boolean
  // An active streak from previous days that will reset to 0 if nothing is
  // written today — used for a "keep it going" nudge.
  atRisk: boolean
}

export function computeStreak(submissions: Submission[]): StreakInfo {
  const days = new Set(submissions.map((s) => dateKey(new Date(s.created_at.replace(' ', 'T')))))

  const today = new Date()
  const wroteToday = days.has(dateKey(today))

  let cursor = new Date(today)
  if (!wroteToday) {
    // Give today the benefit of the doubt — the day isn't over yet — and
    // start counting from yesterday instead, so the streak number doesn't
    // drop to 0 the moment the clock passes midnight.
    cursor.setDate(cursor.getDate() - 1)
  }

  let current = 0
  while (days.has(dateKey(cursor))) {
    current += 1
    cursor = new Date(cursor)
    cursor.setDate(cursor.getDate() - 1)
  }

  return { current, wroteToday, atRisk: current > 0 && !wroteToday }
}

// ---- Achievement badges ----
// Fixed, disclosed tiers — not personalized targets, so there's nothing to
// fabricate. Showing only what's actually been earned (plus one "almost
// there" teaser) is the goal-gradient effect: motivation visibly rises as
// the next tier gets close.
export type BadgeCategory = 'published' | 'streak' | 'income'

export interface Badge {
  category: BadgeCategory
  tierIndex: number
  label: string
}

interface Tier {
  threshold: number
  label: string
}

const PUBLISHED_TIERS: Tier[] = [
  { threshold: 1, label: 'প্রথম সংবাদ প্রকাশ' },
  { threshold: 10, label: '১০টি সংবাদ প্রকাশ' },
  { threshold: 50, label: '৫০টি সংবাদ প্রকাশ' },
  { threshold: 100, label: '১০০টি সংবাদ প্রকাশ' },
  { threshold: 250, label: '২৫০টি সংবাদ প্রকাশ' },
  { threshold: 500, label: '৫০০টি সংবাদ প্রকাশ' },
]

const STREAK_TIERS: Tier[] = [
  { threshold: 3, label: '৩ দিনের লেখার ধারা' },
  { threshold: 7, label: '৭ দিনের লেখার ধারা' },
  { threshold: 14, label: '১৪ দিনের লেখার ধারা' },
  { threshold: 30, label: '৩০ দিনের লেখার ধারা' },
  { threshold: 100, label: '১০০ দিনের লেখার ধারা' },
]

const INCOME_TIERS: Tier[] = [
  { threshold: 1, label: 'প্রথম আয়' },
  { threshold: 2000, label: '৳২,০০০ আয়' },
  { threshold: 10000, label: '৳১০,০০০ আয়' },
  { threshold: 50000, label: '৳৫০,০০০ আয়' },
  { threshold: 200000, label: '৳২,০০,০০০ আয়' },
]

export interface NextBadge {
  category: BadgeCategory
  label: string
  progress: number // 0..1
  remaining: number
}

export function computeBadges(params: {
  publishedCount: number
  totalIncome: number
  streakDays: number
}): { earned: Badge[]; next: NextBadge | null } {
  const sets: Array<{ category: BadgeCategory; value: number; tiers: Tier[] }> = [
    { category: 'published', value: params.publishedCount, tiers: PUBLISHED_TIERS },
    { category: 'streak', value: params.streakDays, tiers: STREAK_TIERS },
    { category: 'income', value: params.totalIncome, tiers: INCOME_TIERS },
  ]

  const earned: Badge[] = []
  let next: NextBadge | null = null
  let bestProgress = -1

  for (const set of sets) {
    let earnedIndex = -1
    for (let i = 0; i < set.tiers.length; i++) {
      if (set.value >= set.tiers[i].threshold) earnedIndex = i
    }
    if (earnedIndex >= 0) {
      earned.push({ category: set.category, tierIndex: earnedIndex, label: set.tiers[earnedIndex].label })
    }

    const upcoming = set.tiers[earnedIndex + 1]
    if (upcoming) {
      const prevThreshold = earnedIndex >= 0 ? set.tiers[earnedIndex].threshold : 0
      const progress = (set.value - prevThreshold) / (upcoming.threshold - prevThreshold)
      if (progress > bestProgress) {
        bestProgress = progress
        next = {
          category: set.category,
          label: upcoming.label,
          progress: Math.max(0, Math.min(1, progress)),
          remaining: Math.max(0, upcoming.threshold - set.value),
        }
      }
    }
  }

  // Most recently/impressively earned first (highest tier reached).
  earned.sort((a, b) => b.tierIndex - a.tierIndex)

  return { earned, next }
}

// ---- Income over time (weekly) ----
// Only counts non-pending ledger entries, matching /me/earnings'
// available_balance semantics (Earn.tsx) — a pending, auto-applied amount
// isn't realized income yet, so folding it into a trend chart would
// overstate what's actually been earned.
export interface WeeklyAmount {
  weekLabel: string
  amount: number
}

export function groupLedgerByWeek(entries: LedgerEntry[], weeks = 8): WeeklyAmount[] {
  const now = new Date()
  const startOfThisWeek = new Date(now)
  const dow = (startOfThisWeek.getDay() + 6) % 7 // Monday = 0
  startOfThisWeek.setHours(0, 0, 0, 0)
  startOfThisWeek.setDate(startOfThisWeek.getDate() - dow)

  const buckets: WeeklyAmount[] = []
  for (let i = weeks - 1; i >= 0; i--) {
    const start = new Date(startOfThisWeek)
    start.setDate(start.getDate() - i * 7)
    buckets.push({ weekLabel: `${bnDigitsNum(start.getDate())} ${monthBn[start.getMonth()]}`, amount: 0 })
  }

  const oldestStart = new Date(startOfThisWeek)
  oldestStart.setDate(oldestStart.getDate() - (weeks - 1) * 7)

  for (const e of entries) {
    if (e.status === 'pending') continue
    const d = new Date(e.created_at.replace(' ', 'T'))
    if (d < oldestStart) continue
    const diffDays = Math.floor((d.getTime() - oldestStart.getTime()) / 86400000)
    const idx = Math.floor(diffDays / 7)
    if (idx >= 0 && idx < buckets.length) {
      buckets[idx].amount += Number(e.amount) || 0
    }
  }

  return buckets
}

// ---- This-month vs last-month comparisons ----
export interface MonthComparison {
  thisMonth: number
  lastMonth: number
  deltaPct: number | null // null when lastMonth is 0 — a % change would be meaningless/infinite
}

function monthKey(d: Date) {
  return d.getFullYear() * 12 + d.getMonth()
}

export function compareIncomeThisVsLastMonth(entries: LedgerEntry[]): MonthComparison {
  const now = new Date()
  const thisKey = monthKey(now)
  const lastKey = thisKey - 1

  let thisMonth = 0
  let lastMonth = 0
  for (const e of entries) {
    if (e.status === 'pending') continue
    const k = monthKey(new Date(e.created_at.replace(' ', 'T')))
    const amt = Number(e.amount) || 0
    if (k === thisKey) thisMonth += amt
    else if (k === lastKey) lastMonth += amt
  }

  return { thisMonth, lastMonth, deltaPct: lastMonth > 0 ? ((thisMonth - lastMonth) / lastMonth) * 100 : null }
}

export function comparePublishedThisVsLastMonth(submissions: Submission[]): MonthComparison {
  const now = new Date()
  const thisKey = monthKey(now)
  const lastKey = thisKey - 1

  let thisMonth = 0
  let lastMonth = 0
  for (const s of submissions) {
    if (s.status !== 'publish') continue
    const k = monthKey(new Date(s.created_at.replace(' ', 'T')))
    if (k === thisKey) thisMonth += 1
    else if (k === lastKey) lastMonth += 1
  }

  return { thisMonth, lastMonth, deltaPct: lastMonth > 0 ? ((thisMonth - lastMonth) / lastMonth) * 100 : null }
}

// ---- View-trend insights ("automation" read of the engagement chart) ----
export interface ViewInsights {
  bestWeekday: { label: string; views: number } | null
  weekOverWeek: { deltaPct: number; direction: 'up' | 'down' } | null
}

// Expects a trend array covering at least ~14 days for weekOverWeek to be
// meaningful; a shorter array still safely yields bestWeekday (or null) and
// a null weekOverWeek rather than a misleading figure.
export function computeViewInsights(trend: AnalyticsTrendPoint[]): ViewInsights {
  if (trend.length === 0) return { bestWeekday: null, weekOverWeek: null }

  let bestWeekday: { label: string; views: number } | null = null
  for (const t of trend) {
    const views = Number(t.views) || 0
    if (!bestWeekday || views > bestWeekday.views) {
      const d = new Date(t.stat_date)
      bestWeekday = { label: weekdayBn[d.getDay()], views }
    }
  }
  if (bestWeekday && bestWeekday.views <= 0) bestWeekday = null

  let weekOverWeek: ViewInsights['weekOverWeek'] = null
  if (trend.length >= 14) {
    const sorted = [...trend].sort((a, b) => a.stat_date.localeCompare(b.stat_date))
    const last14 = sorted.slice(-14)
    const prev7 = last14.slice(0, 7).reduce((sum, t) => sum + (Number(t.views) || 0), 0)
    const last7 = last14.slice(7).reduce((sum, t) => sum + (Number(t.views) || 0), 0)
    if (prev7 > 0) {
      const deltaPct = ((last7 - prev7) / prev7) * 100
      weekOverWeek = { deltaPct, direction: deltaPct >= 0 ? 'up' : 'down' }
    }
  }

  return { bestWeekday, weekOverWeek }
}
