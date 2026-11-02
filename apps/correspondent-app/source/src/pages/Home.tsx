import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Area, AreaChart, Bar, BarChart, Legend, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useAuth } from '../lib/AuthContext'
import {
  AnalyticsApi,
  LedgerApi,
  SubmissionsApi,
  describeError,
  type AnalyticsRange,
  type AnalyticsTotals,
  type AnalyticsTrendPoint,
  type LedgerEntry,
  type Submission,
} from '../lib/api'
import {
  bnDigitsNum,
  comparePublishedThisVsLastMonth,
  compareIncomeThisVsLastMonth,
  computeBadges,
  computeStreak,
  computeViewInsights,
  groupLedgerByWeek,
  monthBn,
  weekdayBn,
  type Badge,
  type BadgeCategory,
  type NextBadge,
  type StreakInfo,
} from '../lib/dashboardInsights'
import { downloadChartAsPng } from '../lib/chartExport'
import NotificationBell from '../components/NotificationBell'
import { useOptionalNotifications } from '../lib/notifications'
import { statusGroup, type StatusFilter } from '../lib/statusFilter'
import { ErrorRetry, Spinner, StatusBadge, bnNumber } from '../components/ui'
import {
  IconAlertTriangle,
  IconCoin,
  IconDownload,
  IconEye,
  IconFlame,
  IconHeart,
  IconLock,
  IconMessage,
  IconNews,
  IconPlus,
  IconRefresh,
  IconShare,
  IconSparkles,
  IconTrendDown,
  IconTrendUp,
  IconTrophy,
} from '../components/Icon'

// Validated categorical palette (dataviz skill reference instance) — fixed
// order, not re-cycled, so each metric keeps the same color everywhere in
// the app. Chosen specifically because this exact 4-slot order clears the
// colorblind-safety checks for adjacent line pairs.
const SERIES_COLOR = {
  views: '#2a78d6', // slot 1 — blue
  reactions: '#eb6834', // slot 2 — orange
  comments: '#1baf7a', // slot 3 — aqua
  shares: '#eda100', // slot 4 — yellow
}

// Single-series "identity" color for income — kept consistent with the
// emerald used for money everywhere else in this app (Earn.tsx, News.tsx's
// payment badge). A single-series chart needs no legend/CVD check — the
// card title already names what it is (dataviz skill: "a single series
// needs no legend box - the title names it").
const INCOME_COLOR = '#059669'

const rangeOptions: { value: AnalyticsRange; label: string }[] = [
  { value: 'today', label: 'আজ' },
  { value: '3', label: '৩ দিন' },
  { value: '7', label: '৭ দিন' },
  { value: '15', label: '১৫ দিন' },
  { value: '30', label: 'মাস' },
  { value: 'all', label: 'সব সময়' },
]

function StatCard({ icon: Icon, label, value, tint }: { icon: typeof IconEye; label: string; value: number; tint: string }) {
  return (
    <div className="rounded-2xl bg-neutral-50 border border-neutral-100 p-3 flex flex-col gap-1.5">
      <div className={`w-8 h-8 rounded-lg flex items-center justify-center ${tint}`}>
        <Icon className="w-4.5 h-4.5" />
      </div>
      <span className="text-[18px] font-bold text-neutral-900 leading-none">{bnNumber(value)}</span>
      <span className="text-[11.5px] text-neutral-500">{label}</span>
    </div>
  )
}

// ---- Gamified "master dashboard" building blocks ----
// Each piece below reads purely from real, already-fetched data (see
// src/lib/dashboardInsights.ts for the computations) — nothing simulated.

// Compact Bengali-digit axis labels (৮৫০, ১.৫k, ৫২k, ১.২M) — short enough
// for a narrow phone-width y-axis. Round 9 follow-up 3: both charts had a
// negative left margin that pushed the y-axis labels off-screen (the income
// chart showed only each label's last digit, the engagement chart none).
function bnCompact(value: number) {
  const n = Number(value) || 0
  const short = (x: number) => bnDigitsNum(x.toFixed(Math.abs(x) < 10 ? 1 : 0).replace(/\.0$/, ''))
  if (Math.abs(n) >= 1_000_000) return `${short(n / 1_000_000)}M`
  if (Math.abs(n) >= 1_000) return `${short(n / 1_000)}k`
  return bnDigitsNum(Math.round(n))
}

// Round 9 follow-up 3: shown when SOME of the dashboard's requests failed
// but others succeeded — the page still renders, and this says plainly that
// a few numbers may be incomplete, with the technical reason in small print.
function PartialLoadBanner({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  const { detail } = describeError(error)
  return (
    <div className="mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-3.5 py-3">
      <div className="flex items-start gap-2.5">
        <IconAlertTriangle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
        <div className="flex-1 min-w-0">
          <p className="text-[12.5px] leading-snug text-amber-900">কিছু তথ্য লোড করা যায়নি, তাই কয়েকটি সংখ্যা অসম্পূর্ণ হতে পারে।</p>
          {detail && (
            <p dir="ltr" className="mt-0.5 text-[10.5px] leading-snug font-mono text-amber-700/80 break-all">
              {detail}
            </p>
          )}
        </div>
        <button onClick={onRetry} className="shrink-0 flex items-center gap-1 text-[12px] font-semibold text-amber-800 active:scale-[0.97]">
          <IconRefresh className="w-3.5 h-3.5" />
          আবার
        </button>
      </div>
    </div>
  )
}

function StreakCard({ streak }: { streak: StreakInfo }) {
  let message: string
  if (streak.current === 0) {
    message = 'আজই প্রথম সংবাদ লিখে আপনার লেখার ধারা শুরু করুন!'
  } else if (streak.atRisk) {
    message = 'ধারা ধরে রাখতে আজই একটি সংবাদ লিখুন!'
  } else {
    message = 'চমৎকার! আজকের লেখা হয়ে গেছে।'
  }

  const warn = streak.atRisk
  return (
    <div className={`rounded-2xl p-4 mb-5 flex items-center gap-3 ${warn ? 'bg-amber-50' : 'bg-orange-50'}`}>
      <div className={`w-11 h-11 rounded-xl flex items-center justify-center shrink-0 ${warn ? 'bg-amber-100 text-amber-600' : 'bg-orange-100 text-orange-600'}`}>
        <IconFlame className="w-6 h-6" />
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-[15px] font-bold text-neutral-900">{bnNumber(streak.current)} দিনের লেখার ধারা</p>
        <p className="text-[12px] text-neutral-500">{message}</p>
      </div>
    </div>
  )
}

function HeroStat({
  icon: Icon,
  label,
  value,
  deltaPct,
  tint,
}: {
  icon: typeof IconEye
  label: string
  value: string
  deltaPct: number | null
  tint: string
}) {
  return (
    <div className="rounded-2xl bg-neutral-50 border border-neutral-100 p-4 flex flex-col gap-1">
      <div className={`w-8 h-8 rounded-lg flex items-center justify-center mb-1 ${tint}`}>
        <Icon className="w-4.5 h-4.5" />
      </div>
      <span className="text-[19px] font-bold text-neutral-900 leading-none">{value}</span>
      <div className="flex items-center justify-between mt-0.5">
        <span className="text-[11.5px] text-neutral-500">{label}</span>
        {deltaPct !== null && (
          <span className={`flex items-center gap-0.5 text-[11px] font-semibold shrink-0 ${deltaPct >= 0 ? 'text-emerald-600' : 'text-red-500'}`}>
            {deltaPct >= 0 ? <IconTrendUp className="w-3 h-3" /> : <IconTrendDown className="w-3 h-3" />}
            {bnNumber(Math.abs(deltaPct).toFixed(0))}%
          </span>
        )}
      </div>
    </div>
  )
}

const BADGE_ICON: Record<BadgeCategory, typeof IconTrophy> = {
  published: IconTrophy,
  streak: IconFlame,
  income: IconCoin,
}

function BadgeRow({ earned, next }: { earned: Badge[]; next: NextBadge | null }) {
  if (earned.length === 0 && !next) return null
  return (
    <div className="mb-5">
      <p className="text-[13px] font-semibold text-neutral-700 mb-2.5">অর্জন</p>
      <div className="flex gap-2.5 overflow-x-auto pb-0.5">
        {earned.slice(0, 3).map((b) => {
          const Icon = BADGE_ICON[b.category]
          return (
            <div
              key={b.category}
              className="shrink-0 w-[132px] rounded-2xl border border-amber-100 bg-amber-50 p-3 flex flex-col items-center text-center gap-1.5"
            >
              <div className="w-9 h-9 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center">
                <Icon className="w-5 h-5" />
              </div>
              <span className="text-[11.5px] font-semibold text-neutral-800 leading-tight">{b.label}</span>
            </div>
          )
        })}
        {next && (
          <div className="shrink-0 w-[152px] rounded-2xl border border-dashed border-neutral-200 bg-neutral-50 p-3 flex flex-col gap-2">
            <div className="flex items-center gap-1.5">
              <div className="w-7 h-7 rounded-full bg-neutral-100 text-neutral-400 flex items-center justify-center shrink-0">
                <IconLock className="w-3.5 h-3.5" />
              </div>
              <span className="text-[11px] font-semibold text-neutral-600 leading-tight">{next.label}</span>
            </div>
            <div className="h-1.5 rounded-full bg-neutral-200 overflow-hidden">
              <div className="h-full rounded-full bg-brand-500" style={{ width: `${Math.round(next.progress * 100)}%` }} />
            </div>
            <span className="text-[10.5px] text-neutral-400">
              {next.category === 'income' ? `৳${bnNumber(next.remaining.toFixed(0))} বাকি` : `${bnNumber(Math.ceil(next.remaining))} বাকি`}
            </span>
          </div>
        )}
      </div>
    </div>
  )
}

interface InsightItem {
  icon: typeof IconSparkles
  text: string
  tint: string
}

function InsightsList({ items }: { items: InsightItem[] }) {
  if (items.length === 0) return null
  return (
    <div className="mb-5">
      <p className="text-[13px] font-semibold text-neutral-700 mb-2.5">অন্তর্দৃষ্টি</p>
      <div className="flex flex-col gap-2">
        {items.map((it, i) => {
          const Icon = it.icon
          return (
            <div key={i} className="flex items-start gap-2.5 rounded-xl bg-neutral-50 border border-neutral-100 p-3">
              <div className={`w-7 h-7 rounded-lg flex items-center justify-center shrink-0 ${it.tint}`}>
                <Icon className="w-4 h-4" />
              </div>
              <p className="text-[13px] text-neutral-700 leading-snug pt-0.5">{it.text}</p>
            </div>
          )
        })}
      </div>
    </div>
  )
}

// Round 18: where the correspondent's work stands, at a glance — each tile
// opens "আমার সংবাদ" filtered to that state.
const STATUS_TILES: { key: Exclude<StatusFilter, 'all'>; label: string; cls: string }[] = [
  { key: 'approved', label: 'অনুমোদিত', cls: 'text-emerald-700 bg-emerald-50' },
  { key: 'pending', label: 'রিভিউতে', cls: 'text-amber-700 bg-amber-50' },
  { key: 'rejected', label: 'প্রত্যাখ্যাত', cls: 'text-red-700 bg-red-50' },
  { key: 'draft', label: 'ড্রাফট', cls: 'text-neutral-600 bg-neutral-100' },
]

function StatusOverview({ submissions, onOpen }: { submissions: Submission[]; onOpen: (f: StatusFilter) => void }) {
  const counts = { approved: 0, pending: 0, rejected: 0, draft: 0 }
  submissions.forEach((s) => counts[statusGroup(s)]++)
  return (
    <div className="mb-5">
      <p className="text-[13px] font-semibold text-neutral-700 mb-2.5">আমার সংবাদের অবস্থা</p>
      <div className="grid grid-cols-4 gap-2">
        {STATUS_TILES.map((t) => (
          <button
            key={t.key}
            onClick={() => onOpen(t.key)}
            className={`rounded-xl py-2.5 px-1 flex flex-col items-center gap-0.5 active:scale-[0.97] transition ${t.cls}`}
          >
            <span className="text-[18px] font-bold tabular-nums leading-tight">{bnNumber(counts[t.key])}</span>
            <span className="text-[11.5px] font-medium">{t.label}</span>
          </button>
        ))}
      </div>
    </div>
  )
}

/** Returned posts need the correspondent's action — shown above everything else. */
function NeedsFixCard({ items, onOpen }: { items: Submission[]; onOpen: (id: number) => void }) {
  if (!items.length) return null
  return (
    <div className="rounded-2xl border border-red-100 bg-red-50/60 p-3.5 mb-5">
      <p className="flex items-center gap-1.5 text-[13.5px] font-semibold text-red-700 mb-2">
        <IconAlertTriangle className="w-4 h-4" /> সংশোধন করে আবার পাঠান ({bnNumber(items.length)})
      </p>
      <div className="flex flex-col gap-1.5">
        {items.slice(0, 3).map((s) => (
          <button key={s.id} onClick={() => onOpen(s.id)} className="text-left rounded-xl bg-white px-3 py-2.5 border border-red-100">
            <span className="block text-[13.5px] font-medium text-neutral-900 line-clamp-1">{s.title || 'শিরোনামহীন'}</span>
            {s.reject_reason && <span className="block text-[12px] text-red-600 line-clamp-2 mt-0.5">কারণ: {s.reject_reason}</span>}
          </button>
        ))}
      </div>
    </div>
  )
}

export default function Home() {
  const { profile } = useAuth()
  const navigate = useNavigate()
  const [totals, setTotals] = useState<AnalyticsTotals | null>(null)
  const [trend, setTrend] = useState<AnalyticsTrendPoint[]>([])
  const [insightsTrend, setInsightsTrend] = useState<AnalyticsTrendPoint[]>([])
  const [recent, setRecent] = useState<Submission[]>([])
  const [submissions, setSubmissions] = useState<Submission[]>([])
  const [earningsEntries, setEarningsEntries] = useState<LedgerEntry[]>([])
  const [availableBalance, setAvailableBalance] = useState(0)
  const [loading, setLoading] = useState(true)
  // Round 9 follow-up 3: `error` (the full-page error screen) is now only
  // for when NOTHING could be loaded; if just some of the four requests
  // fail, the rest of the dashboard still renders and `partialError` drives
  // a small banner saying some numbers may be incomplete. Both hold the
  // real thrown error so the screen can say what actually went wrong.
  const [error, setError] = useState<unknown>(null)
  const [partialError, setPartialError] = useState<unknown>(null)
  const [range, setRange] = useState<AnalyticsRange>('7')
  const [trendLoading, setTrendLoading] = useState(false)
  const chartRef = useRef<HTMLDivElement>(null)
  const notifVersion = useOptionalNotifications()?.version ?? 0
  const incomeChartRef = useRef<HTMLDivElement>(null)

  // Pulled out of the mount effect so it can also be called by the retry
  // button below and by the dashboard's silent auto-refresh (see
  // useDashboardAutoRefresh) — same fetch, three different triggers.
  //
  // Round 9 redesign: also pulls the correspondent's full submissions list
  // (not just the 4 most recent), their ledger/earnings, and a fixed 30-day
  // analytics window independent of the chart's own user-selectable `range`
  // — the streak/badge/insight computations below need all three and
  // shouldn't silently change meaning just because someone taps "আজ" on the
  // engagement chart.
  function load(opts: { silent?: boolean } = {}) {
    if (!opts.silent) {
      setLoading(true)
      setError(null)
    }
    // Round 9 follow-up 3: allSettled instead of all — previously ONE of
    // these four requests failing (a mobile-data blip on just one of them)
    // threw away the other three and showed the full-page error screen.
    return Promise.allSettled([AnalyticsApi.me(range), AnalyticsApi.me('30'), SubmissionsApi.list(), LedgerApi.earnings()])
      .then(([a, insightsA, subs, earnings]) => {
        if (a.status === 'fulfilled') {
          setTotals(a.value.totals)
          setTrend(a.value.trend)
        }
        if (insightsA.status === 'fulfilled') setInsightsTrend(insightsA.value.trend)
        if (subs.status === 'fulfilled') {
          setRecent(subs.value.slice(0, 4))
          setSubmissions(subs.value)
        }
        if (earnings.status === 'fulfilled') {
          setEarningsEntries(earnings.value.entries)
          setAvailableBalance(earnings.value.available_balance)
        }

        const failures = [a, insightsA, subs, earnings].filter((r): r is PromiseRejectedResult => r.status === 'rejected')
        if (failures.length === 0) {
          setError(null)
          setPartialError(null)
        } else if (!opts.silent) {
          // A silent background refresh that fails just keeps the last good
          // numbers on screen; only a load the correspondent asked for
          // (opening the page, tapping retry) reports a problem.
          if (failures.length === 4) setError(failures[0].reason ?? new Error('unknown'))
          else setPartialError(failures[0].reason ?? new Error('unknown'))
        }
      })
      .catch((err) => {
        // Only reachable if processing a (successful) response throws —
        // e.g. an unexpected response shape. Shown with its real message.
        if (!opts.silent) setError(err ?? new Error('unknown'))
      })
      .finally(() => {
        if (!opts.silent) setLoading(false)
      })
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Keeps the dashboard's numbers fresh without the correspondent having to
  // pull-to-refresh or reload: re-fetches quietly (no spinner, no error
  // flash) every 60s while the tab is visible, and immediately whenever the
  // tab/app comes back into focus — the exact moment a session left open in
  // the background is most likely to have gone stale.
  useEffect(() => {
    const interval = setInterval(() => {
      if (document.visibilityState === 'visible') load({ silent: true })
    }, 60_000)
    function onVisible() {
      if (document.visibilityState === 'visible') load({ silent: true })
    }
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      clearInterval(interval)
      document.removeEventListener('visibilitychange', onVisible)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [range])

  // A new notification (approved / returned) changes the numbers here.
  useEffect(() => {
    if (notifVersion) load({ silent: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [notifVersion])

  function onRangeChange(next: AnalyticsRange) {
    setRange(next)
    setTrendLoading(true)
    AnalyticsApi.me(next)
      .then((a) => {
        setTotals(a.totals)
        setTrend(a.trend)
      })
      .catch(() => {
        // A range switch failing shouldn't blank the whole page — the
        // totals/trend from before stay on screen, just unchanged.
      })
      .finally(() => setTrendLoading(false))
  }

  // Weekday names repeat and stop being readable past about a week, so a
  // longer window labels by day-of-month instead.
  const useWeekdayLabels = range === 'today' || range === '3' || range === '7'

  const chartData = trend.map((t) => {
    const d = new Date(t.stat_date)
    return {
      name: useWeekdayLabels ? weekdayBn[d.getDay()] : `${bnDigitsNum(d.getDate())} ${monthBn[d.getMonth()]}`,
      দেখা: Number(t.views),
      লাইক: Number(t.reactions),
      কমেন্ট: Number(t.comments),
      শেয়ার: Number(t.shares),
    }
  })

  // ---- Derived "automation" layer — see dashboardInsights.ts ----
  const streak = computeStreak(submissions)
  const publishedCount = submissions.filter((s) => s.status === 'publish').length
  const totalIncome = earningsEntries.filter((e) => e.status !== 'pending').reduce((sum, e) => sum + (Number(e.amount) || 0), 0)
  // "income configured" per the correspondent's own data: they've actually
  // received or been assigned a payment at least once — not an empty wallet
  // every correspondent starts with. Hides the whole income layer (hero
  // tile, income badge/trend) for someone with none yet, instead of
  // showing a wall of zeroes.
  const hasIncome = earningsEntries.length > 0 || availableBalance > 0 || submissions.some((s) => s.payment != null)
  const badges = computeBadges({ publishedCount, totalIncome, streakDays: streak.current })
  const incomeMonth = compareIncomeThisVsLastMonth(earningsEntries)
  const publishedMonth = comparePublishedThisVsLastMonth(submissions)
  const weeklyIncome = groupLedgerByWeek(earningsEntries)
  const viewInsights = computeViewInsights(insightsTrend)
  const paidSubs = submissions.filter((s) => s.payment != null)
  const avgPerArticle = paidSubs.length > 0 ? paidSubs.reduce((sum, s) => sum + (s.payment ?? 0), 0) / paidSubs.length : null

  const insightItems: InsightItem[] = []
  if (viewInsights.bestWeekday) {
    insightItems.push({
      icon: IconSparkles,
      text: `গত ৩০ দিনে আপনার সেরা দিন ছিল ${viewInsights.bestWeekday.label}, ${bnNumber(viewInsights.bestWeekday.views)} ভিউ নিয়ে।`,
      tint: 'bg-violet-50 text-violet-600',
    })
  }
  if (viewInsights.weekOverWeek) {
    const up = viewInsights.weekOverWeek.direction === 'up'
    insightItems.push({
      icon: up ? IconTrendUp : IconTrendDown,
      text: `গত সপ্তাহের তুলনায় এই সপ্তাহে ভিউ ${bnNumber(Math.abs(viewInsights.weekOverWeek.deltaPct).toFixed(0))}% ${up ? 'বেড়েছে' : 'কমেছে'}।`,
      tint: up ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-500',
    })
  }
  if (hasIncome && avgPerArticle !== null) {
    insightItems.push({
      icon: IconCoin,
      text: `প্রতি সংবাদে গড়ে ৳${bnNumber(avgPerArticle.toFixed(0))} আয় করছেন।`,
      tint: 'bg-amber-50 text-amber-600',
    })
  }

  if (loading) return <Spinner className="mt-10" />
  if (error != null) return <ErrorRetry error={error} onRetry={() => load()} />

  return (
    <div className="px-5 pt-5 pb-6">
      <div className="flex items-center gap-3 mb-5">
        <img src={profile?.avatar} alt="" className="w-12 h-12 rounded-full bg-neutral-100 object-cover" />
        <div className="flex-1 min-w-0">
          <p className="text-[13px] text-neutral-500">স্বাগতম,</p>
          <h1 className="text-[17px] font-bold text-neutral-900 truncate">{profile?.name}</h1>
        </div>
        <NotificationBell placement="sheet" />
      </div>

      {partialError != null && <PartialLoadBanner error={partialError} onRetry={() => load()} />}

      <button
        onClick={() => navigate('/app/write')}
        className="w-full rounded-2xl bg-brand-600 text-white py-3.5 font-semibold flex items-center justify-center gap-2 mb-5 shadow-lg shadow-brand-600/25 active:scale-[0.98] transition"
      >
        <IconPlus className="w-5 h-5" />
        নতুন সংবাদ লিখুন
      </button>

      <NeedsFixCard items={submissions.filter((s) => statusGroup(s) === 'rejected')} onOpen={(id) => navigate(`/app/write/${id}`)} />

      <StatusOverview submissions={submissions} onOpen={(f) => navigate(`/app/news?status=${f}`)} />

      <StreakCard streak={streak} />

      <div className="grid grid-cols-2 gap-2.5 mb-5">
        {hasIncome && (
          <HeroStat
            icon={IconCoin}
            label="এই মাসের আয়"
            value={`৳${bnNumber(incomeMonth.thisMonth.toFixed(0))}`}
            deltaPct={incomeMonth.deltaPct}
            tint="bg-emerald-50 text-emerald-600"
          />
        )}
        <HeroStat
          icon={IconNews}
          label="এই মাসে প্রকাশিত"
          value={bnNumber(publishedMonth.thisMonth)}
          deltaPct={publishedMonth.deltaPct}
          tint="bg-blue-50 text-blue-600"
        />
        {!hasIncome && (
          <HeroStat
            icon={IconEye}
            label="সর্বমোট দেখা"
            value={bnNumber(totals?.views ?? 0)}
            deltaPct={null}
            tint="bg-violet-50 text-violet-600"
          />
        )}
      </div>

      <BadgeRow earned={badges.earned} next={badges.next} />

      <InsightsList items={insightItems} />

      {hasIncome && (
        <div className="mb-6">
          <div className="flex items-center justify-between mb-2.5">
            <p className="text-[13px] font-semibold text-neutral-700">আয়ের প্রবণতা (সাপ্তাহিক)</p>
            {weeklyIncome.some((w) => w.amount > 0) && (
              <button
                onClick={() => downloadChartAsPng(incomeChartRef.current, 'income-trend.png')}
                className="flex items-center gap-1 text-[12px] font-medium text-neutral-500 hover:text-brand-600"
                aria-label="আয়ের চার্ট ডাউনলোড করুন"
              >
                <IconDownload className="w-4 h-4" />
                ডাউনলোড
              </button>
            )}
          </div>
          {weeklyIncome.some((w) => w.amount > 0) ? (
            <div ref={incomeChartRef} className="rounded-2xl border border-neutral-100 bg-white p-2 pt-3 h-40">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={weeklyIncome} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
                  <XAxis dataKey="weekLabel" tick={{ fontSize: 10.5, fill: '#898781' }} axisLine={false} tickLine={false} interval="preserveStartEnd" />
                  <YAxis tick={{ fontSize: 10.5, fill: '#898781' }} tickFormatter={bnCompact} axisLine={false} tickLine={false} width={36} />
                  <Tooltip
                    formatter={(v) => [`৳ ${bnNumber(Number(v).toFixed(0))}`, 'আয়']}
                    contentStyle={{ fontSize: 12, borderRadius: 12, border: '1px solid #e1e0d9' }}
                    labelStyle={{ fontWeight: 600, color: '#0b0b0b' }}
                    cursor={{ fill: 'rgba(5,150,105,0.08)' }}
                  />
                  <Bar dataKey="amount" fill={INCOME_COLOR} radius={[4, 4, 0, 0]} maxBarSize={28} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          ) : (
            <p className="text-[13px] text-neutral-400 py-8 text-center rounded-2xl border border-neutral-100">
              সাম্প্রতিক সপ্তাহগুলোতে এখনো কোনো আয় যুক্ত হয়নি।
            </p>
          )}
        </div>
      )}

      <p className="text-[13px] font-semibold text-neutral-700 mb-2.5">সর্বমোট এনগেজমেন্ট</p>
      <div className="grid grid-cols-4 gap-2 mb-6">
        <StatCard icon={IconEye} label="দেখা" value={totals?.views ?? 0} tint="bg-blue-50 text-blue-600" />
        <StatCard icon={IconHeart} label="লাইক" value={totals?.reactions ?? 0} tint="bg-rose-50 text-rose-600" />
        <StatCard icon={IconMessage} label="কমেন্ট" value={totals?.comments ?? 0} tint="bg-amber-50 text-amber-600" />
        <StatCard icon={IconShare} label="শেয়ার" value={totals?.shares ?? 0} tint="bg-emerald-50 text-emerald-600" />
      </div>

      <div className="mb-6">
        <div className="flex items-center justify-between mb-2.5">
          <p className="text-[13px] font-semibold text-neutral-700">এনগেজমেন্ট ট্রেন্ড</p>
          {chartData.length > 0 && (
            <button
              onClick={() => downloadChartAsPng(chartRef.current, `engagement-trend-${range}.png`)}
              className="flex items-center gap-1 text-[12px] font-medium text-neutral-500 hover:text-brand-600"
              aria-label="চার্ট ডাউনলোড করুন"
            >
              <IconDownload className="w-4 h-4" />
              ডাউনলোড
            </button>
          )}
        </div>

        <div className="flex gap-1.5 overflow-x-auto mb-3 pb-0.5">
          {rangeOptions.map((opt) => (
            <button
              key={opt.value}
              onClick={() => onRangeChange(opt.value)}
              className={`shrink-0 px-3 py-1.5 rounded-full text-[12.5px] font-medium ${
                range === opt.value ? 'bg-brand-600 text-white' : 'bg-neutral-100 text-neutral-500'
              }`}
            >
              {opt.label}
            </button>
          ))}
        </div>

        {trendLoading ? (
          <Spinner className="h-48" />
        ) : chartData.length > 0 ? (
          <div ref={chartRef} className="rounded-2xl border border-neutral-100 bg-white p-2 pt-3 h-48">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={chartData} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
                <defs>
                  <linearGradient id="viewsFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={SERIES_COLOR.views} stopOpacity={0.22} />
                    <stop offset="100%" stopColor={SERIES_COLOR.views} stopOpacity={0} />
                  </linearGradient>
                </defs>
                <XAxis dataKey="name" tick={{ fontSize: 11, fill: '#898781' }} axisLine={false} tickLine={false} interval="preserveStartEnd" />
                <YAxis tick={{ fontSize: 11, fill: '#898781' }} tickFormatter={bnCompact} axisLine={false} tickLine={false} width={36} />
                <Tooltip
                  contentStyle={{ fontSize: 12, borderRadius: 12, border: '1px solid #e1e0d9' }}
                  labelStyle={{ fontWeight: 600, color: '#0b0b0b' }}
                  cursor={{ stroke: '#c3c2b7', strokeWidth: 1 }}
                />
                <Legend
                  verticalAlign="top"
                  height={28}
                  iconType="circle"
                  iconSize={8}
                  wrapperStyle={{ fontSize: 12, color: '#52514e' }}
                />
                <Area
                  type="monotone"
                  dataKey="দেখা"
                  stroke={SERIES_COLOR.views}
                  strokeWidth={2}
                  strokeLinecap="round"
                  fill="url(#viewsFill)"
                  dot={false}
                  activeDot={{ r: 4 }}
                />
                <Line type="monotone" dataKey="লাইক" stroke={SERIES_COLOR.reactions} strokeWidth={2} strokeLinecap="round" dot={false} activeDot={{ r: 4 }} />
                <Line type="monotone" dataKey="কমেন্ট" stroke={SERIES_COLOR.comments} strokeWidth={2} strokeLinecap="round" dot={false} activeDot={{ r: 4 }} />
                <Line type="monotone" dataKey="শেয়ার" stroke={SERIES_COLOR.shares} strokeWidth={2} strokeLinecap="round" dot={false} activeDot={{ r: 4 }} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        ) : (
          <p className="text-[13px] text-neutral-400 py-8 text-center rounded-2xl border border-neutral-100">
            এই সময়ের জন্য এখনো কোনো তথ্য নেই।
          </p>
        )}
      </div>

      <div className="flex items-center justify-between mb-2.5">
        <p className="text-[13px] font-semibold text-neutral-700">সাম্প্রতিক সংবাদ</p>
        <button onClick={() => navigate('/app/news')} className="text-[12.5px] text-brand-600 font-medium">
          সব দেখুন
        </button>
      </div>

      {recent.length === 0 ? (
        <p className="text-[13px] text-neutral-400 py-6 text-center">এখনো কোনো সংবাদ লেখেননি।</p>
      ) : (
        <div className="flex flex-col gap-2">
          {recent.map((s) => (
            <button
              key={s.id}
              onClick={() => navigate(`/app/write/${s.id}`)}
              className="text-left rounded-xl border border-neutral-100 p-3 flex items-center justify-between gap-3"
            >
              <span className="text-[14px] text-neutral-800 truncate flex-1">{s.title || 'শিরোনামহীন'}</span>
              <div className="flex items-center gap-2 shrink-0">
                {!!s.payment && (
                  <span className={`flex items-center gap-1 text-[12px] font-medium ${s.payment_status === 'pending' ? 'text-amber-600' : 'text-emerald-600'}`}>
                    <IconCoin className="w-3.5 h-3.5" /> ৳{bnNumber(s.payment)}
                  </span>
                )}
                <StatusBadge status={s.status} rejected={s.rejected} />
              </div>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
