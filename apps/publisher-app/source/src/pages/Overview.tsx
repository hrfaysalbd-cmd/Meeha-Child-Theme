import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Area, AreaChart, Legend, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import {
  AnalyticsApi,
  OverviewApi,
  type AnalyticsRange,
  type AnalyticsTotals,
  type AnalyticsTrendPoint,
  type Overview as OverviewData,
} from '../lib/api'
import { downloadChartAsPng } from '../lib/chartExport'
import { Card, ErrorRetry, KpiCard, Spinner, bnNumber } from '../components/ui'
import {
  IconBan,
  IconBuildingStore,
  IconDownload,
  IconEye,
  IconFileCheck,
  IconHeart,
  IconInbox,
  IconMessage,
  IconShare,
  IconShieldSearch,
  IconUsers,
  IconVideo,
  IconWalletCoins,
} from '../components/Icon'

// Validated categorical palette (dataviz skill reference instance) — same
// fixed order as the correspondent app's chart, so the two apps' charts
// read consistently to anyone who uses both.
const SERIES_COLOR = {
  views: '#2a78d6', // slot 1 — blue
  reactions: '#eb6834', // slot 2 — orange
  comments: '#1baf7a', // slot 3 — aqua
  shares: '#eda100', // slot 4 — yellow
}

const rangeOptions: { value: AnalyticsRange; label: string }[] = [
  { value: 'today', label: 'আজ' },
  { value: '3', label: '৩ দিন' },
  { value: '7', label: '৭ দিন' },
  { value: '15', label: '১৫ দিন' },
  { value: '30', label: 'মাস' },
  { value: 'all', label: 'সব সময়' },
]

const weekdayBn = ['রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহ', 'শুক্র', 'শনি']
const monthBn = ['জানু', 'ফেব্রু', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টে', 'অক্টো', 'নভে', 'ডিসে']

function bnDigitsNum(n: number) {
  const digits: Record<string, string> = { '0': '০', '1': '১', '2': '২', '3': '৩', '4': '৪', '5': '৫', '6': '৬', '7': '৭', '8': '৮', '9': '৯' }
  return String(n).replace(/[0-9]/g, (d) => digits[d])
}

export default function Overview() {
  const navigate = useNavigate()
  const [data, setData] = useState<OverviewData | null>(null)
  const [totals, setTotals] = useState<AnalyticsTotals | null>(null)
  const [trend, setTrend] = useState<AnalyticsTrendPoint[]>([])
  const [range, setRange] = useState<AnalyticsRange>('7')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<unknown>(null)
  const [trendLoading, setTrendLoading] = useState(false)
  const chartRef = useRef<HTMLDivElement>(null)

  // Pulled out of the mount effect so the retry button and the silent
  // auto-refresh below can both call the same fetch.
  function load(opts: { silent?: boolean } = {}) {
    if (!opts.silent) {
      setLoading(true)
      setError(null)
    }
    return Promise.all([OverviewApi.get(), AnalyticsApi.site(range)])
      .then(([o, a]) => {
        setData(o)
        setTotals(a.totals)
        setTrend(a.trend)
        setError(null)
      })
      .catch((err) => {
        // Previously there was no .catch() at all here — any failure left
        // the spinner gone and the whole Overview page permanently blank
        // (this was the publisher/editor "dashboard" the complaint was
        // about), with no error message and no way to recover but a hard
        // reload. Now it shows a clear reason + retry instead.
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

  // Keeps the overview's KPIs/chart fresh automatically — every 60s while
  // the tab is visible, and immediately when the tab/app regains focus
  // (the moment a dashboard left open in the background is most likely to
  // be showing stale numbers).
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

  function onRangeChange(next: AnalyticsRange) {
    setRange(next)
    setTrendLoading(true)
    AnalyticsApi.site(next)
      .then((a) => {
        setTotals(a.totals)
        setTrend(a.trend)
      })
      .catch(() => {
        // A range switch failing shouldn't blank the whole page.
      })
      .finally(() => setTrendLoading(false))
  }

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

  if (loading) return <Spinner className="mt-16" />
  if (error != null || !data) return <ErrorRetry error={error ?? undefined} onRetry={() => load()} />

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-[22px] font-bold text-neutral-900">ওভারভিউ</h1>
        <p className="text-[13.5px] text-neutral-500 mt-0.5">পুরো নিউজরুমের এক নজরে অবস্থা</p>
      </div>

      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
        <button onClick={() => navigate('/app/correspondents')} className="text-left">
          <KpiCard label="সংবাদদাতা" value={bnNumber(data.correspondents)} icon={<IconUsers className="w-5 h-5" />} tint="bg-blue-50 text-blue-600" />
        </button>
        <button onClick={() => navigate('/app/applications')} className="text-left">
          <KpiCard label="পেন্ডিং আবেদন" value={bnNumber(data.pending_applications)} icon={<IconFileCheck className="w-5 h-5" />} tint="bg-amber-50 text-amber-700" />
        </button>
        <button onClick={() => navigate('/app/queue')} className="text-left">
          <KpiCard label="রিভিউ কিউ" value={bnNumber(data.pending_queue)} icon={<IconInbox className="w-5 h-5" />} tint="bg-brand-50 text-brand-600" />
        </button>
        {data.pending_videos !== undefined && (
          <button onClick={() => navigate('/app/videos?tab=pending')} className="text-left" data-testid="kpi-pending-videos">
            <KpiCard label="রিভিউয়ের অপেক্ষায় ভিডিও" value={bnNumber(data.pending_videos)} icon={<IconVideo className="w-5 h-5" />} tint="bg-indigo-50 text-indigo-600" />
          </button>
        )}
        <button onClick={() => navigate('/app/ad-clients')} className="text-left">
          <KpiCard label="সক্রিয় বিজ্ঞাপনদাতা" value={bnNumber(data.active_clients)} icon={<IconBuildingStore className="w-5 h-5" />} tint="bg-emerald-50 text-emerald-700" />
        </button>
        <button onClick={() => navigate('/app/investigations')} className="text-left">
          <KpiCard label="চলমান তদন্ত" value={bnNumber(data.open_cases)} icon={<IconShieldSearch className="w-5 h-5" />} tint="bg-purple-50 text-purple-700" />
        </button>
        <button onClick={() => navigate('/app/suspensions')} className="text-left">
          <KpiCard label="সক্রিয় সাসপেনশন" value={bnNumber(data.active_suspensions)} icon={<IconBan className="w-5 h-5" />} tint="bg-red-50 text-red-600" />
        </button>
        {data.finance_visible && (
          <KpiCard
            label="পেন্ডিং উইথড্র (৳)"
            value={bnNumber(Math.round(data.pending_withdrawal_amount ?? 0))}
            icon={<IconWalletCoins className="w-5 h-5" />}
            tint="bg-teal-50 text-teal-700"
          />
        )}
      </div>

      <Card className="p-5">
        <div className="flex items-center justify-between mb-4 flex-wrap gap-3">
          <div className="flex items-center gap-3">
            <p className="text-[14px] font-semibold text-neutral-800">সাইট-ওয়াইড এনগেজমেন্ট ট্রেন্ড</p>
            {chartData.length > 0 && (
              <button
                onClick={() => downloadChartAsPng(chartRef.current, `site-engagement-trend-${range}.png`)}
                className="flex items-center gap-1 text-[12px] font-medium text-neutral-500 hover:text-brand-600"
                aria-label="চার্ট ডাউনলোড করুন"
              >
                <IconDownload className="w-4 h-4" />
                ডাউনলোড
              </button>
            )}
          </div>
          <div className="flex gap-1.5 flex-wrap">
            {rangeOptions.map((opt) => (
              <button
                key={opt.value}
                onClick={() => onRangeChange(opt.value)}
                className={`px-3 py-1.5 rounded-full text-[12px] font-medium ${
                  range === opt.value ? 'bg-brand-600 text-white' : 'bg-neutral-100 text-neutral-500'
                }`}
              >
                {opt.label}
              </button>
            ))}
          </div>
        </div>

        <div className="grid grid-cols-4 gap-3 mb-5">
          <div className="text-center">
            <IconEye className="w-4 h-4 text-blue-600 mx-auto mb-1" />
            <p className="text-[16px] font-bold text-neutral-900">{bnNumber(totals?.views ?? 0)}</p>
            <p className="text-[11.5px] text-neutral-400">দেখা</p>
          </div>
          <div className="text-center">
            <IconHeart className="w-4 h-4 text-rose-600 mx-auto mb-1" />
            <p className="text-[16px] font-bold text-neutral-900">{bnNumber(totals?.reactions ?? 0)}</p>
            <p className="text-[11.5px] text-neutral-400">লাইক</p>
          </div>
          <div className="text-center">
            <IconMessage className="w-4 h-4 text-amber-600 mx-auto mb-1" />
            <p className="text-[16px] font-bold text-neutral-900">{bnNumber(totals?.comments ?? 0)}</p>
            <p className="text-[11.5px] text-neutral-400">কমেন্ট</p>
          </div>
          <div className="text-center">
            <IconShare className="w-4 h-4 text-emerald-600 mx-auto mb-1" />
            <p className="text-[16px] font-bold text-neutral-900">{bnNumber(totals?.shares ?? 0)}</p>
            <p className="text-[11.5px] text-neutral-400">শেয়ার</p>
          </div>
        </div>

        {trendLoading ? (
          <Spinner className="h-56" />
        ) : chartData.length > 0 ? (
          <div ref={chartRef} className="h-56">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={chartData} margin={{ top: 4, right: 8, bottom: 0, left: -20 }}>
                <defs>
                  <linearGradient id="siteViewsFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={SERIES_COLOR.views} stopOpacity={0.22} />
                    <stop offset="100%" stopColor={SERIES_COLOR.views} stopOpacity={0} />
                  </linearGradient>
                </defs>
                <XAxis dataKey="name" tick={{ fontSize: 11, fill: '#898781' }} axisLine={false} tickLine={false} interval="preserveStartEnd" />
                <YAxis tick={{ fontSize: 11, fill: '#898781' }} axisLine={false} tickLine={false} width={32} />
                <Tooltip
                  contentStyle={{ fontSize: 12, borderRadius: 12, border: '1px solid #e1e0d9' }}
                  labelStyle={{ fontWeight: 600, color: '#0b0b0b' }}
                  cursor={{ stroke: '#c3c2b7', strokeWidth: 1 }}
                />
                <Legend verticalAlign="top" height={28} iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12, color: '#52514e' }} />
                <Area
                  type="monotone"
                  dataKey="দেখা"
                  stroke={SERIES_COLOR.views}
                  strokeWidth={2}
                  strokeLinecap="round"
                  fill="url(#siteViewsFill)"
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
          <p className="text-[13px] text-neutral-400 py-10 text-center">এই সময়ের জন্য এখনো কোনো তথ্য নেই।</p>
        )}
      </Card>
    </div>
  )
}
