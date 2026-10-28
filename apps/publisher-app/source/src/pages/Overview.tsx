import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import {
  InsightsApi,
  OverviewApi,
  type InsightCard,
  type InsightPeriod,
  type Insights,
  type Overview as OverviewData,
  type Realtime,
} from '../lib/api'
import { downloadChartAsPng } from '../lib/chartExport'
import { Card, ErrorRetry, Spinner } from '../components/ui'
import {
  IconAlertTriangle,
  IconBan,
  IconChevronRight,
  IconDeviceDesktop,
  IconDeviceMobile,
  IconDownload,
  IconExternalLink,
  IconEye,
  IconFileCheck,
  IconHeart,
  IconInbox,
  IconMessage,
  IconNews,
  IconRefresh,
  IconShare,
  IconShieldSearch,
  IconTrendingUp,
  IconVideo,
  IconWalletCoins,
} from '../components/Icon'
import ReporterProductivity from '../components/ReporterProductivity'
import {
  bnDigits,
  bnInt,
  bnShort,
  dayLabelLong,
  dayLabelShort,
  hourLabelBn,
  pctChange,
  timeAgoBn,
  todayLongBn,
  weekdayShort,
} from '../lib/insightFormat'

// Round 20 — the analytics dashboard (desktop first). Reading order, top to
// bottom, follows what a publisher asks first: who is on the site right now
// → what needs my action → how much did we publish → how is it being read →
// what is being read → who is producing. Every number on this page comes from
// newsroom-os 1.5.0's event-based endpoints (views counted in the hour they
// happened), so a day's figure is that day's and no day goes missing.

// Same validated categorical order as the rest of the app's charts.
const C = {
  views: '#2a78d6', // slot 1 — blue
  published: '#eb6834', // slot 2 — orange
  comments: '#1baf7a', // slot 3 — aqua
  shares: '#eda100', // slot 4 — yellow
  muted: '#a3a29b',
  grid: '#eeede8',
  axis: '#898781',
}

const REALTIME_MS = 15_000
const INSIGHTS_MS = 60_000

const SOURCE_LABEL: Record<string, string> = {
  direct: 'সরাসরি',
  facebook: 'ফেসবুক',
  search: 'গুগল/সার্চ',
  social: 'অন্য সোশ্যাল',
  internal: 'সাইটের ভেতর থেকে',
  other: 'অন্য সাইট',
}
const NET_LABEL: Record<string, string> = {
  facebook: 'ফেসবুক',
  whatsapp: 'হোয়াটসঅ্যাপ',
  messenger: 'মেসেঞ্জার',
  x: 'এক্স',
  telegram: 'টেলিগ্রাম',
  copy: 'লিংক কপি',
  native: 'ফোনের শেয়ার',
  linkedin: 'লিংকডইন',
  email: 'ইমেইল',
}

const PREV_LABEL: Record<InsightPeriod, string> = {
  today: 'গতকাল এই সময় পর্যন্ত',
  yesterday: 'আগের দিন',
  '7d': 'আগের ৭ দিন',
  this_month: 'গত মাসের একই সময়',
  last_month: 'তার আগের মাস',
  all: '',
}

function useVisibleInterval(fn: () => void, ms: number) {
  const ref = useRef(fn)
  ref.current = fn
  useEffect(() => {
    const id = setInterval(() => {
      if (document.visibilityState === 'visible') ref.current()
    }, ms)
    const onVis = () => document.visibilityState === 'visible' && ref.current()
    document.addEventListener('visibilitychange', onVis)
    return () => {
      clearInterval(id)
      document.removeEventListener('visibilitychange', onVis)
    }
  }, [ms])
}

export default function Overview() {
  const navigate = useNavigate()
  const [ops, setOps] = useState<OverviewData | null>(null)
  const [ins, setIns] = useState<Insights | null>(null)
  const [live, setLive] = useState<Realtime | null>(null)
  const [samples, setSamples] = useState<Array<{ t: number; n: number }>>([])
  const [period, setPeriod] = useState<InsightPeriod>('today')
  const [range, setRange] = useState<7 | 30 | 90>(30)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<unknown>(null)
  const [refreshing, setRefreshing] = useState(false)
  const [, tick] = useState(0)

  const loadInsights = useCallback(
    (p = period, r = range, silent = false) => {
      if (!silent) setRefreshing(true)
      return InsightsApi.get(p, r)
        .then((d) => {
          setIns(d)
          setError(null)
        })
        .catch((e) => {
          if (!silent) setError(e ?? new Error('unknown'))
        })
        .finally(() => setRefreshing(false))
    },
    [period, range]
  )

  const loadLive = useCallback(() => {
    InsightsApi.realtime()
      .then((d) => {
        setLive(d)
        setSamples((s) => [...s.slice(-59), { t: Date.now(), n: d.now }])
      })
      .catch(() => {})
  }, [])

  const loadOps = useCallback(() => {
    OverviewApi.get()
      .then(setOps)
      .catch(() => {})
  }, [])

  useEffect(() => {
    setLoading(true)
    Promise.all([loadInsights(period, range, false), loadLive(), loadOps()]).finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useVisibleInterval(loadLive, REALTIME_MS)
  useVisibleInterval(() => {
    loadInsights(period, range, true)
    loadOps()
  }, INSIGHTS_MS)
  useVisibleInterval(() => tick((n) => n + 1), 10_000)

  function choosePeriod(p: InsightPeriod) {
    setPeriod(p)
    loadInsights(p, range)
  }
  function chooseRange(r: 7 | 30 | 90) {
    setRange(r)
    loadInsights(period, r)
  }

  if (loading && !ins) return <Spinner className="mt-16" />
  if (!ins) return <ErrorRetry error={error ?? undefined} onRetry={() => loadInsights(period, range)} />

  return (
    <div className="space-y-5" data-testid="analytics-dashboard">
      {/* Header */}
      <div className="flex items-end justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-[24px] font-bold text-neutral-900 leading-tight">ড্যাশবোর্ড</h1>
          <p className="text-[13.5px] text-neutral-500 mt-0.5">
            {todayLongBn()} · হালনাগাদ {timeAgoBn(ins.generated_at)}
          </p>
        </div>
        <button
          onClick={() => {
            loadInsights(period, range)
            loadLive()
            loadOps()
          }}
          className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white ring-1 ring-neutral-200 text-[13px] font-medium text-neutral-600 hover:bg-neutral-50"
        >
          <IconRefresh className={`w-4 h-4 ${refreshing ? 'animate-spin' : ''}`} />
          নতুন করে দেখুন
        </button>
      </div>

      <HealthBanner h={ins.health} />

      {/* Row 1 — realtime · being read now · needs attention */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
        <RealtimeHero live={live} samples={samples} className="lg:col-span-5" />
        <ReadingNow live={live} className="lg:col-span-4" />
        <NeedsAttention ops={ops} onGo={navigate} className="lg:col-span-3" />
      </div>

      {/* Row 2 — publishing by period (the tiles also choose the period below) */}
      <section>
        <SectionTitle title="প্রকাশিত খবর" hint="ঘরে চাপ দিলে নিচের সব হিসাব সেই সময়ের হয়ে যাবে" />
        <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3" data-testid="publish-cards">
          {ins.cards.map((c) => (
            <PublishTile key={c.key} c={c} active={c.key === period} onClick={() => choosePeriod(c.key)} />
          ))}
        </div>
      </section>

      {/* Row 3 — engagement KPIs for the chosen period */}
      <section>
        <SectionTitle title={`পাঠকের সাড়া — ${ins.kpi.label}`} hint={PREV_LABEL[period] ? `তুলনা: ${PREV_LABEL[period]}` : undefined} />
        <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
          <Kpi icon={<IconEye className="w-5 h-5" />} tint="bg-blue-50 text-blue-600" label="ভিউ" value={ins.kpi.views} prev={ins.kpi.prev_views} />
          <Kpi icon={<IconShare className="w-5 h-5" />} tint="bg-amber-50 text-amber-700" label="শেয়ার" value={ins.kpi.shares} prev={ins.kpi.prev_shares} />
          <Kpi icon={<IconHeart className="w-5 h-5" />} tint="bg-rose-50 text-rose-600" label="প্রতিক্রিয়া" value={ins.kpi.reactions} prev={ins.kpi.prev_reactions} />
          <Kpi icon={<IconMessage className="w-5 h-5" />} tint="bg-emerald-50 text-emerald-700" label="মন্তব্য" value={ins.kpi.comments} prev={ins.kpi.prev_comments} />
          <Kpi
            icon={<IconTrendingUp className="w-5 h-5" />}
            tint="bg-violet-50 text-violet-700"
            label="খবরপ্রতি গড় ভিউ"
            value={ins.kpi.avg_views_per_news}
            sub="এই সময়ে প্রকাশিত খবরের"
          />
        </div>
      </section>

      {/* Row 4 — daily trend + hour by hour */}
      <div className="grid grid-cols-1 xl:grid-cols-12 gap-4">
        <TrendCard ins={ins} range={range} onRange={chooseRange} className="xl:col-span-8" />
        <HourlyCard ins={ins} className="xl:col-span-4" />
      </div>

      {/* Row 5 — what is being read, by story and by section */}
      <div className="grid grid-cols-1 xl:grid-cols-12 gap-4">
        <TopNews ins={ins} className="xl:col-span-7" />
        <div className="xl:col-span-5 space-y-4">
          <CategoryCard ins={ins} />
          <ShareNetworks sources={ins.sources} />
        </div>
      </div>

      {/* Row 6 — who produced what */}
      <ReporterProductivity />
    </div>
  )
}

// ---------------------------------------------------------------------------

function SectionTitle({ title, hint, right }: { title: string; hint?: string; right?: ReactNode }) {
  return (
    <div className="flex items-end justify-between gap-3 mb-2.5 flex-wrap">
      <div className="flex items-baseline gap-2 flex-wrap">
        <h2 className="text-[15px] font-bold text-neutral-800">{title}</h2>
        {hint && <span className="text-[12px] text-neutral-400">{hint}</span>}
      </div>
      {right}
    </div>
  )
}

function Delta({ cur, prev, compact = false }: { cur: number; prev?: number; compact?: boolean }) {
  const p = pctChange(cur, prev)
  if (p === null) {
    if (prev === 0 && cur > 0) return <span className="text-[11.5px] font-semibold text-emerald-700">▲ নতুন</span>
    return null
  }
  const up = p > 0.5
  const down = p < -0.5
  const cls = up ? 'text-emerald-700 bg-emerald-50' : down ? 'text-red-600 bg-red-50' : 'text-neutral-500 bg-neutral-100'
  const arrow = up ? '▲' : down ? '▼' : '•'
  return (
    <span className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-semibold ${cls}`} title={prev !== undefined ? `আগে: ${bnInt(prev)}` : undefined}>
      {arrow} {bnDigits(Math.abs(Math.round(p)))}%{!compact && prev !== undefined && <span className="font-normal text-neutral-400 ml-1">({bnShort(prev)})</span>}
    </span>
  )
}

function HealthBanner({ h }: { h: Insights['health'] }) {
  const msgs: string[] = []
  if (!h.theme_metrics) msgs.push('থিমের ভিউ-টেবিল পাওয়া যায়নি — মিহা থিম ২.৪.২ বা নতুন সক্রিয় আছে কি না দেখুন।')
  if (h.cron_lag > 30 * 60)
    msgs.push(
      `সাইটের WP-Cron ${bnDigits(Math.floor(h.cron_lag / 60))} মিনিট পিছিয়ে${h.cron_disabled ? ' (DISABLE_WP_CRON চালু)' : ''}। হিসাব ঠিক থাকবে, তবে হোস্টিংয়ে সার্ভার-ক্রন যোগ করুন (ডেপ্লয় নোট দেখুন)।`
    )
  if (!msgs.length) return null
  return (
    <div className="flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
      <IconAlertTriangle className="w-4.5 h-4.5 mt-0.5 shrink-0" />
      <div className="space-y-0.5">
        {msgs.map((m) => (
          <p key={m}>{m}</p>
        ))}
      </div>
    </div>
  )
}

// ---- Realtime -------------------------------------------------------------

function RealtimeHero({ live, samples, className = '' }: { live: Realtime | null; samples: Array<{ t: number; n: number }>; className?: string }) {
  const off = live && (!live.supported || !live.enabled)
  const d = live?.devices ?? {}
  const total = (d.m ?? 0) + (d.d ?? 0) + (d.t ?? 0)
  const pct = (n = 0) => (total ? Math.round((n / total) * 100) : 0)
  const max = Math.max(1, ...samples.map((s) => s.n))
  const pts = samples.map((s, i) => `${samples.length < 2 ? 0 : (i / (samples.length - 1)) * 100},${36 - (s.n / max) * 32}`).join(' ')

  return (
    <div className={`rounded-2xl bg-gradient-to-br from-[#0b2746] to-[#025ab2] text-white p-5 shadow-sm relative overflow-hidden ${className}`} data-testid="realtime-hero">
      <div className="flex items-center gap-2 text-[13px] font-medium text-white/80">
        <span className="relative flex h-2.5 w-2.5">
          <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 animate-ping" />
          <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-400" />
        </span>
        এখন সাইটে আছেন
        <span className="ml-auto text-[11.5px] text-white/50">প্রতি ১৫ সেকেন্ডে হালনাগাদ</span>
      </div>
      {off ? (
        <p className="mt-6 text-[14px] text-white/80">
          রিয়েলটাইম গণনা বন্ধ আছে বা থিম পুরোনো। মিহা থিম ২.৪.২ আপলোড করে <b>মিহা থিম › হোমপেজ › রিয়েলটাইম ভিজিটর</b> চালু করুন।
        </p>
      ) : (
        <>
          <div className="flex items-end gap-3 mt-2">
            <p className="text-[64px] leading-none font-extrabold tracking-tight tabular-nums" data-testid="realtime-now">
              {live ? bnInt(live.now) : '—'}
            </p>
            <p className="text-[15px] text-white/70 pb-2">জন</p>
            {samples.length > 1 && (
              <svg viewBox="0 0 100 38" preserveAspectRatio="none" className="ml-auto w-40 h-12 pb-1" aria-hidden>
                <polyline points={pts} fill="none" stroke="#7dd3fc" strokeWidth="2" vectorEffect="non-scaling-stroke" strokeLinejoin="round" strokeLinecap="round" />
              </svg>
            )}
          </div>
          <div className="grid grid-cols-3 gap-2 mt-4">
            <HeroStat label="গত ৩০ মিনিটে" value={live?.last30} />
            <HeroStat label="আজ মোট ভিজিটর" value={live?.today} />
            <HeroStat label="এই ঘণ্টার ভিউ" value={live?.views_this_hour} />
          </div>
          <div className="mt-4">
            <div className="flex h-2 rounded-full overflow-hidden bg-white/10 gap-0.5">
              {total > 0 && (
                <>
                  <div style={{ width: `${pct(d.m)}%` }} className="bg-sky-300" />
                  <div style={{ width: `${pct(d.d)}%` }} className="bg-white/80" />
                  <div style={{ width: `${pct(d.t)}%` }} className="bg-amber-300" />
                </>
              )}
            </div>
            <div className="flex gap-4 mt-2 text-[12px] text-white/80 flex-wrap">
              <span className="flex items-center gap-1">
                <IconDeviceMobile className="w-3.5 h-3.5" /> মোবাইল {bnDigits(pct(d.m))}%
              </span>
              <span className="flex items-center gap-1">
                <IconDeviceDesktop className="w-3.5 h-3.5" /> কম্পিউটার {bnDigits(pct(d.d))}%
              </span>
              {(d.t ?? 0) > 0 && <span>ট্যাবলেট {bnDigits(pct(d.t))}%</span>}
            </div>
          </div>
          {live && Object.keys(live.sources).length > 0 && (
            <div className="mt-3 pt-3 border-t border-white/10 flex flex-wrap gap-1.5">
              <span className="text-[11.5px] text-white/50 mr-1 self-center">কোথা থেকে (৩০ মিনিট):</span>
              {Object.entries(live.sources).map(([k, n]) => (
                <span key={k} className="rounded-full bg-white/10 px-2 py-0.5 text-[11.5px]">
                  {SOURCE_LABEL[k] ?? k} {bnDigits(n)}
                </span>
              ))}
            </div>
          )}
        </>
      )}
    </div>
  )
}

function HeroStat({ label, value }: { label: string; value?: number }) {
  return (
    <div className="rounded-xl bg-white/10 px-3 py-2">
      <p className="text-[20px] font-bold tabular-nums leading-tight">{value === undefined ? '—' : bnInt(value)}</p>
      <p className="text-[11.5px] text-white/60">{label}</p>
    </div>
  )
}

function ReadingNow({ live, className = '' }: { live: Realtime | null; className?: string }) {
  const pages = live?.pages ?? []
  const max = Math.max(1, ...pages.map((p) => p.n))
  return (
    <Card className={`p-5 ${className}`}>
      <SectionTitle title="এখন যা পড়া হচ্ছে" />
      {pages.length === 0 ? (
        <p className="text-[13px] text-neutral-400 py-8 text-center">এই মুহূর্তে কেউ কোনো খবর পড়ছেন না।</p>
      ) : (
        <ol className="space-y-2.5">
          {pages.map((p, i) => (
            <li key={`${p.id}-${i}`}>
              <div className="flex items-start gap-2">
                <span className="w-5 text-[12px] font-bold text-neutral-400 tabular-nums pt-0.5">{bnDigits(i + 1)}</span>
                <a href={p.url} target="_blank" rel="noreferrer" className="flex-1 min-w-0 text-[13px] text-neutral-800 hover:text-brand-600 line-clamp-2 leading-snug">
                  {p.title}
                </a>
                <span className="text-[13px] font-bold text-neutral-900 tabular-nums">{bnDigits(p.n)}</span>
              </div>
              <div className="ml-7 mt-1 h-1 rounded-full bg-neutral-100 overflow-hidden">
                <div className="h-full rounded-full" style={{ width: `${(p.n / max) * 100}%`, background: C.views }} />
              </div>
            </li>
          ))}
        </ol>
      )}
    </Card>
  )
}

function NeedsAttention({ ops, onGo, className = '' }: { ops: OverviewData | null; onGo: (to: string) => void; className?: string }) {
  const rows: Array<{ to: string; label: string; n: number; icon: ReactNode }> = ops
    ? [
        { to: '/app/queue', label: 'রিভিউয়ের অপেক্ষায় খবর', n: ops.pending_queue, icon: <IconInbox className="w-4 h-4" /> },
        ...(ops.pending_videos !== undefined ? [{ to: '/app/videos?tab=pending', label: 'রিভিউয়ের অপেক্ষায় ভিডিও', n: ops.pending_videos, icon: <IconVideo className="w-4 h-4" /> }] : []),
        { to: '/app/applications', label: 'নতুন আবেদন', n: ops.pending_applications, icon: <IconFileCheck className="w-4 h-4" /> },
        ...(ops.finance_visible ? [{ to: '/app/finance', label: 'উইথড্র অনুরোধ', n: ops.pending_withdrawals, icon: <IconWalletCoins className="w-4 h-4" /> }] : []),
        { to: '/app/investigations', label: 'চলমান তদন্ত', n: ops.open_cases, icon: <IconShieldSearch className="w-4 h-4" /> },
        { to: '/app/suspensions', label: 'সক্রিয় সাসপেনশন', n: ops.active_suspensions, icon: <IconBan className="w-4 h-4" /> },
      ]
    : []
  return (
    <Card className={`p-5 ${className}`}>
      <SectionTitle title="আপনার কাজ" />
      <ul className="-mx-2">
        {rows.map((r) => (
          <li key={r.to}>
            <button onClick={() => onGo(r.to)} className="w-full flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-neutral-50 text-left">
              <span className={`w-7 h-7 rounded-lg flex items-center justify-center ${r.n ? 'bg-brand-50 text-brand-600' : 'bg-neutral-100 text-neutral-400'}`}>{r.icon}</span>
              <span className="flex-1 text-[13px] text-neutral-700">{r.label}</span>
              <span className={`text-[14px] font-bold tabular-nums ${r.n ? 'text-neutral-900' : 'text-neutral-300'}`}>{bnDigits(r.n)}</span>
              <IconChevronRight className="w-4 h-4 text-neutral-300" />
            </button>
          </li>
        ))}
      </ul>
      {ops && (
        <p className="mt-2 pt-2 border-t border-neutral-100 text-[12px] text-neutral-500">
          সংবাদদাতা {bnDigits(ops.correspondents)} জন · সক্রিয় বিজ্ঞাপনদাতা {bnDigits(ops.active_clients)}
        </p>
      )}
    </Card>
  )
}

// ---- Publishing tiles & KPIs ----------------------------------------------

function PublishTile({ c, active, onClick }: { c: InsightCard; active: boolean; onClick: () => void }) {
  return (
    <button
      onClick={onClick}
      data-testid={`publish-${c.key}`}
      className={`text-left rounded-2xl p-4 border transition ${
        active ? 'bg-white border-brand-600 ring-2 ring-brand-600/15 shadow-sm' : 'bg-white border-neutral-200/80 hover:border-neutral-300 shadow-sm'
      }`}
    >
      <div className="flex items-center justify-between">
        <span className={`text-[12.5px] font-semibold ${active ? 'text-brand-600' : 'text-neutral-500'}`}>{c.label}</span>
        <IconNews className="w-4 h-4 text-neutral-300" />
      </div>
      <div className="flex items-baseline gap-2 mt-1.5">
        <span className="text-[28px] font-extrabold text-neutral-900 tabular-nums leading-none">{bnInt(c.published.total)}</span>
        <span className="text-[12px] text-neutral-400">টি</span>
      </div>
      <div className="mt-1.5 min-h-[20px]">
        <Delta cur={c.published.total} prev={c.prev_published} compact />
      </div>
      <div className="mt-2 pt-2 border-t border-neutral-100 text-[11.5px] text-neutral-500 space-y-0.5">
        <p>
          খবর {bnInt(c.published.news)} · ভিডিও {bnInt(c.published.video)}
        </p>
        <p className="flex items-center gap-1 flex-wrap">
          <IconEye className="w-3.5 h-3.5" /> <span className="whitespace-nowrap">{bnShort(c.views)} ভিউ</span> <Delta cur={c.views} prev={c.prev_views} compact />
        </p>
      </div>
    </button>
  )
}

function Kpi({ icon, tint, label, value, prev, sub }: { icon: ReactNode; tint: string; label: string; value: number; prev?: number; sub?: string }) {
  return (
    <Card className="p-4">
      <div className="flex items-center gap-2.5">
        <span className={`w-9 h-9 rounded-xl flex items-center justify-center ${tint}`}>{icon}</span>
        <span className="text-[13px] text-neutral-500">{label}</span>
      </div>
      <p className="mt-2 text-[26px] font-extrabold text-neutral-900 tabular-nums leading-none">{bnInt(value)}</p>
      <div className="mt-1.5 min-h-[20px]">{sub ? <span className="text-[11.5px] text-neutral-400">{sub}</span> : <Delta cur={value} prev={prev} />}</div>
    </Card>
  )
}

// ---- Charts ---------------------------------------------------------------

const tooltipStyle = { fontSize: 12, borderRadius: 12, border: '1px solid #e1e0d9', boxShadow: '0 4px 16px rgba(0,0,0,.06)' }

function TrendCard({ ins, range, onRange, className = '' }: { ins: Insights; range: 7 | 30 | 90; onRange: (r: 7 | 30 | 90) => void; className?: string }) {
  const ref = useRef<HTMLDivElement>(null)
  const data = useMemo(
    () =>
      ins.trend.map((d) => ({
        date: d.stat_date,
        name: range === 7 ? weekdayShort(d.stat_date) : dayLabelShort(d.stat_date),
        ভিউ: Number(d.views),
        শেয়ার: Number(d.shares),
        প্রকাশিত: Number(d.published),
      })),
    [ins.trend, range]
  )
  const tickFmt = (v: number) => bnShort(v)
  const labelFmt = (_: unknown, p: ReadonlyArray<{ payload?: { date?: string } }>) => (p?.[0]?.payload?.date ? dayLabelLong(p[0].payload.date) : '')
  const best = data.reduce((a, b) => (b.ভিউ > (a?.ভিউ ?? -1) ? b : a), data[0])
  return (
    <Card className={`p-5 ${className}`}>
      <SectionTitle
        title="দিনভিত্তিক ভিউ ও প্রকাশ"
        hint={best ? `সেরা দিন: ${dayLabelLong(best.date)} (${bnInt(best.ভিউ)} ভিউ)` : undefined}
        right={
          <div className="flex items-center gap-2">
            <div className="flex rounded-full bg-neutral-100 p-0.5">
              {([7, 30, 90] as const).map((r) => (
                <button
                  key={r}
                  onClick={() => onRange(r)}
                  className={`px-3 py-1 rounded-full text-[12px] font-medium ${range === r ? 'bg-white text-neutral-900 shadow-sm' : 'text-neutral-500'}`}
                >
                  {bnDigits(r)} দিন
                </button>
              ))}
            </div>
            <button onClick={() => downloadChartAsPng(ref.current, `meeha-trend-${range}d.png`)} className="p-1.5 rounded-lg text-neutral-400 hover:text-brand-600" aria-label="চার্ট ডাউনলোড">
              <IconDownload className="w-4 h-4" />
            </button>
          </div>
        }
      />
      <div ref={ref}>
        <div className="h-60" data-testid="trend-chart">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={data} margin={{ top: 6, right: 8, bottom: 0, left: 0 }} syncId="trend">
              <defs>
                <linearGradient id="tViews" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={C.views} stopOpacity={0.22} />
                  <stop offset="100%" stopColor={C.views} stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid vertical={false} stroke={C.grid} />
              <XAxis dataKey="name" tick={{ fontSize: 11, fill: C.axis }} axisLine={false} tickLine={false} interval="preserveStartEnd" minTickGap={16} />
              <YAxis tick={{ fontSize: 11, fill: C.axis }} axisLine={false} tickLine={false} width={66} tickFormatter={tickFmt} />
              <Tooltip contentStyle={tooltipStyle} labelFormatter={labelFmt} formatter={(v) => bnInt(Number(v))} cursor={{ stroke: '#c3c2b7' }} />
              <Legend verticalAlign="top" align="right" height={26} iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12 }} />
              <Area type="monotone" dataKey="ভিউ" stroke={C.views} strokeWidth={2} fill="url(#tViews)" dot={false} activeDot={{ r: 4 }} />
              <Line type="monotone" dataKey="শেয়ার" stroke={C.shares} strokeWidth={2} dot={false} activeDot={{ r: 4 }} />
            </AreaChart>
          </ResponsiveContainer>
        </div>
        <p className="text-[12px] font-medium text-neutral-500 mt-3 mb-1">প্রতিদিন প্রকাশিত খবর</p>
        <div className="h-24">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={data} margin={{ top: 2, right: 8, bottom: 0, left: 0 }} syncId="trend">
              <XAxis dataKey="name" hide />
              <YAxis tick={{ fontSize: 10, fill: C.axis }} axisLine={false} tickLine={false} width={66} allowDecimals={false} tickFormatter={tickFmt} />
              <Tooltip contentStyle={tooltipStyle} labelFormatter={labelFmt} formatter={(v) => bnInt(Number(v))} cursor={{ fill: 'rgba(0,0,0,.04)' }} />
              <Bar dataKey="প্রকাশিত" fill={C.published} radius={[4, 4, 0, 0]} maxBarSize={18} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>
    </Card>
  )
}

function HourlyCard({ ins, className = '' }: { ins: Insights; className?: string }) {
  const data = ins.hourly.today.map((v, h) => ({
    name: hourLabelBn(h),
    h,
    আজ: h <= ins.hourly.hour ? v : null,
    গতকাল: ins.hourly.yesterday[h],
  }))
  const peak = data.reduce((a, b) => ((b.গতকাল ?? 0) > (a.গতকাল ?? 0) ? b : a), data[0])
  const todaySoFar = ins.hourly.today.slice(0, ins.hourly.hour + 1).reduce((a, b) => a + b, 0)
  const ydaySoFar = ins.hourly.yesterday.slice(0, ins.hourly.hour + 1).reduce((a, b) => a + b, 0)
  return (
    <Card className={`p-5 ${className}`}>
      <SectionTitle title="ঘণ্টাভিত্তিক ভিউ" hint="আজ বনাম গতকাল" />
      <div className="flex items-center gap-3 mb-2 text-[12.5px] text-neutral-600">
        <span>
          আজ এখন পর্যন্ত <b className="text-neutral-900">{bnInt(todaySoFar)}</b>
        </span>
        <Delta cur={todaySoFar} prev={ydaySoFar} compact />
      </div>
      <div className="h-[262px]" data-testid="hourly-chart">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={data} margin={{ top: 6, right: 8, bottom: 0, left: 0 }}>
            <CartesianGrid vertical={false} stroke={C.grid} />
            <XAxis dataKey="h" tick={{ fontSize: 10.5, fill: C.axis }} axisLine={false} tickLine={false} ticks={[0, 6, 12, 18, 23]} tickFormatter={(h) => hourLabelBn(Number(h))} />
            <YAxis tick={{ fontSize: 11, fill: C.axis }} axisLine={false} tickLine={false} width={58} tickFormatter={(v) => bnShort(v)} />
            <Tooltip contentStyle={tooltipStyle} labelFormatter={(h) => hourLabelBn(Number(h))} formatter={(v) => (v === null ? '—' : bnInt(Number(v)))} />
            <Legend verticalAlign="top" align="right" height={24} iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12 }} />
            <Line type="monotone" dataKey="গতকাল" stroke={C.muted} strokeWidth={2} strokeDasharray="4 4" dot={false} />
            <Line type="monotone" dataKey="আজ" stroke={C.views} strokeWidth={2.5} dot={false} activeDot={{ r: 4 }} connectNulls={false} />
          </LineChart>
        </ResponsiveContainer>
      </div>
      {peak && (peak.গতকাল ?? 0) > 0 && <p className="text-[12px] text-neutral-500 mt-1">গতকাল সবচেয়ে বেশি পড়া হয়েছে {peak.name}-এ।</p>}
    </Card>
  )
}

function TopNews({ ins, className = '' }: { ins: Insights; className?: string }) {
  return (
    <Card className={`p-5 ${className}`}>
      <SectionTitle title={`সবচেয়ে বেশি পড়া — ${ins.kpi.label}`} />
      {ins.top_posts.length === 0 ? (
        <p className="text-[13px] text-neutral-400 py-10 text-center">এই সময়ে এখনো কোনো ভিউ গোনা হয়নি।</p>
      ) : (
        <div className="overflow-x-auto -mx-2">
          <table className="w-full text-[13px]" data-testid="top-news">
            <thead>
              <tr className="text-left text-[11.5px] text-neutral-400">
                <th className="font-medium px-2 pb-2 w-6">#</th>
                <th className="font-medium px-2 pb-2">খবর</th>
                <th className="font-medium px-2 pb-2 text-right whitespace-nowrap">ভিউ</th>
                <th className="font-medium px-2 pb-2 text-right whitespace-nowrap hidden md:table-cell">শেয়ার</th>
              </tr>
            </thead>
            <tbody>
              {ins.top_posts.map((p, i) => (
                <tr key={p.id} className="border-t border-neutral-100 align-top">
                  <td className="px-2 py-2.5 text-neutral-400 font-bold tabular-nums">{bnDigits(i + 1)}</td>
                  <td className="px-2 py-2.5">
                    <div className="flex gap-2.5">
                      {p.thumb ? <img src={p.thumb} alt="" className="w-12 h-9 rounded-md object-cover bg-neutral-100 shrink-0" /> : null}
                      <div className="min-w-0">
                        <a href={p.url} target="_blank" rel="noreferrer" className="text-neutral-800 hover:text-brand-600 line-clamp-2 leading-snug">
                          {p.title}
                          <IconExternalLink className="inline w-3 h-3 ml-1 text-neutral-300" />
                        </a>
                        <p className="text-[11.5px] text-neutral-400 mt-0.5">
                          {[p.category, p.author, p.type === 'meeha_video' ? 'ভিডিও' : p.type === 'meeha_photocard' ? 'ফটোকার্ড' : ''].filter(Boolean).join(' · ')}
                        </p>
                      </div>
                    </div>
                  </td>
                  <td className="px-2 py-2.5 text-right font-bold text-neutral-900 tabular-nums">{bnInt(p.views)}</td>
                  <td className="px-2 py-2.5 text-right text-neutral-600 tabular-nums hidden md:table-cell">{bnInt(p.shares)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

function CategoryCard({ ins }: { ins: Insights }) {
  const max = Math.max(1, ...ins.categories.map((c) => c.views))
  return (
    <Card className="p-5">
      <SectionTitle title="বিভাগভিত্তিক" hint={`${ins.kpi.label} · ভিউ ও প্রকাশিত খবর`} />
      {ins.categories.length === 0 ? (
        <p className="text-[13px] text-neutral-400 py-6 text-center">এখনো কোনো তথ্য নেই।</p>
      ) : (
        <ul className="space-y-2.5" data-testid="categories">
          {ins.categories.map((c) => (
            <li key={c.id}>
              <div className="flex items-center justify-between text-[12.5px]">
                <span className="text-neutral-700 truncate">{c.name}</span>
                <span className="text-neutral-500 tabular-nums shrink-0 ml-2">
                  <b className="text-neutral-900">{bnInt(c.views)}</b> ভিউ · {bnDigits(c.published)} খবর
                </span>
              </div>
              <div className="mt-1 h-1.5 rounded-full bg-neutral-100 overflow-hidden">
                <div className="h-full rounded-full" style={{ width: `${(c.views / max) * 100}%`, background: C.views }} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function ShareNetworks({ sources }: { sources: Record<string, number> }) {
  const entries = Object.entries(sources).slice(0, 6)
  const total = entries.reduce((a, [, n]) => a + n, 0)
  if (!total) return null
  return (
    <Card className="p-5">
      <SectionTitle title="কোথায় শেয়ার হয়" hint="সব সময়" />
      <div className="flex flex-wrap gap-2">
        {entries.map(([k, n]) => (
          <span key={k} className="rounded-xl bg-neutral-50 ring-1 ring-neutral-200/70 px-3 py-1.5 text-[12.5px] text-neutral-700">
            {NET_LABEL[k] ?? k} <b className="tabular-nums">{bnDigits(Math.round((n / total) * 100))}%</b>
          </span>
        ))}
      </div>
    </Card>
  )
}
