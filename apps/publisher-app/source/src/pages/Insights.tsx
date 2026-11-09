/**
 * r34 — ভিউ/ইনসাইট. Website + Facebook Pages + YouTube channels + TikTok, together.
 *
 * Three tabs:
 *   সারসংক্ষেপ  — filter (আজ … লাইফটাইম, কাস্টম), totals, per-platform cards, a stacked chart,
 *                 each account, top content. Every platform card says from which day its data starts.
 *   লাইভ        — who is on the site right now + live viewers of every Facebook/YouTube live.
 *   সেটআপ       — (Publisher) app keys, connect / reconnect / disconnect accounts, editor access.
 */
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useAuth } from '../lib/AuthContext'
import { describeError } from '../lib/api'
import {
  PERIODS,
  PLATFORM,
  ViApi,
  bucketLabel,
  type ViAccount,
  type ViFbPage,
  type ViLive,
  type ViOverview,
  type ViPeriod,
  type ViPlatform,
  type ViProvider,
  type ViSetup,
} from '../lib/viewInsights'
import { Badge, Banner, Button, Card, ErrorRetry, Modal, PageHeader, Spinner, inputClass } from '../components/ui'
import {
  IconBrandFacebook,
  IconBrandTiktok,
  IconBrandYoutube,
  IconCopy,
  IconExternalLink,
  IconRefresh,
  IconTrash,
  IconUnlink,
  IconUsers,
  IconWorld,
} from '../components/Icon'
import { bnDigits, bnInt, bnShort, dayLabelShort, pctChange } from '../lib/insightFormat'

type Tab = 'overview' | 'live' | 'setup'
const SOCIAL: Exclude<ViPlatform, 'website'>[] = ['facebook', 'youtube', 'tiktok']
const ALL: ViPlatform[] = ['website', 'facebook', 'youtube', 'tiktok']
const tooltipStyle = { fontSize: 12, borderRadius: 12, border: '1px solid #e1e0d9', boxShadow: '0 4px 16px rgba(0,0,0,.06)' }
const LIVE_MS = 20_000

const MiniSpin = () => <span className="inline-block h-3.5 w-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" />

function PIcon({ p, className = 'w-4 h-4' }: { p: ViPlatform; className?: string }) {
  if (p === 'facebook') return <IconBrandFacebook className={className} />
  if (p === 'youtube') return <IconBrandYoutube className={className} />
  if (p === 'tiktok') return <IconBrandTiktok className={className} />
  return <IconWorld className={className} />
}

function Delta({ cur, prev }: { cur: number; prev: number | null | undefined }) {
  const d = pctChange(cur, prev)
  if (d === null) return null
  const up = d >= 0
  return (
    <span className={`text-[11.5px] font-semibold ${up ? 'text-emerald-600' : 'text-red-600'}`}>
      {up ? '▲' : '▼'} {bnDigits(Math.abs(d).toFixed(Math.abs(d) < 10 ? 1 : 0))}%
    </span>
  )
}

function Signed({ n }: { n: number | null | undefined }) {
  if (n === null || n === undefined) return <span className="text-neutral-400">—</span>
  return <span className={n >= 0 ? 'text-emerald-600' : 'text-red-600'}>{n >= 0 ? '+' : '−'}{bnInt(Math.abs(n))}</span>
}

const ymdToday = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const bnDay = (s: string | null) => (s ? `${dayLabelShort(s)} ${bnDigits(s.slice(0, 4))}` : '—')
const agoBn = (iso: string | null) => {
  if (!iso) return 'কখনো না'
  const s = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 1000))
  if (s < 60) return 'এইমাত্র'
  if (s < 3600) return `${bnDigits(Math.floor(s / 60))} মিনিট আগে`
  if (s < 86400) return `${bnDigits(Math.floor(s / 3600))} ঘণ্টা আগে`
  return `${bnDigits(Math.floor(s / 86400))} দিন আগে`
}

export default function Insights() {
  const { isPublisher } = useAuth()
  const [params, setParams] = useSearchParams()
  const [tab, setTab] = useState<Tab>(() => (params.get('tab') as Tab) || (params.get('vi_fb') ? 'setup' : 'overview'))
  const [flash, setFlash] = useState<{ kind: 'success' | 'error'; text: string } | null>(null)
  const [fbKey, setFbKey] = useState<string>('')

  // Coming back from a platform's sign-in page.
  useEffect(() => {
    const ok = params.get('vi_ok')
    const err = params.get('vi_error')
    const fb = params.get('vi_fb')
    if (!ok && !err && !fb) return
    if (ok) setFlash({ kind: 'success', text: `${PLATFORM[ok as ViPlatform]?.label ?? ok} যুক্ত হয়েছে: ${params.get('vi_names') ?? ''}। আগের তথ্য পেছন থেকে ধাপে ধাপে আসছে।` })
    if (err) setFlash({ kind: 'error', text: `সংযোগ হয়নি: ${err}` })
    if (fb) setFbKey(fb)
    setTab('setup')
    const next = new URLSearchParams()
    next.set('tab', 'setup')
    setParams(next, { replace: true })
  }, [params, setParams])

  const tabs: { key: Tab; label: string }[] = [
    { key: 'overview', label: 'সারসংক্ষেপ' },
    { key: 'live', label: 'লাইভ' },
    ...(isPublisher ? [{ key: 'setup' as Tab, label: 'অ্যাকাউন্ট ও সেটআপ' }] : []),
  ]

  return (
    <div className="space-y-5">
      <PageHeader title="ভিউ/ইনসাইট" subtitle="ওয়েবসাইট, ফেসবুক, ইউটিউব ও টিকটকের ভিউ ও ফলোয়ার এক জায়গায়" />
      <div className="flex gap-1 border-b border-neutral-200">
        {tabs.map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            data-testid={`vi-tab-${t.key}`}
            className={`px-4 py-2 text-[13.5px] font-semibold border-b-2 -mb-px ${tab === t.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-neutral-500 hover:text-neutral-800'}`}
          >
            {t.label}
          </button>
        ))}
      </div>
      {flash && (
        <Banner kind={flash.kind}>
          <div className="flex items-start justify-between gap-3">
            <span>{flash.text}</span>
            <button className="text-[12px] underline" onClick={() => setFlash(null)}>বন্ধ</button>
          </div>
        </Banner>
      )}
      {tab === 'overview' && <OverviewTab onLive={() => setTab('live')} onSetup={isPublisher ? () => setTab('setup') : undefined} />}
      {tab === 'live' && <LiveTab />}
      {tab === 'setup' && isPublisher && <SetupTab fbKey={fbKey} onFbDone={() => setFbKey('')} />}
    </div>
  )
}

// ---------------------------------------------------------------------------
// সারসংক্ষেপ
// ---------------------------------------------------------------------------

function OverviewTab({ onLive, onSetup }: { onLive: () => void; onSetup?: () => void }) {
  const [period, setPeriod] = useState<ViPeriod>('30d')
  const [from, setFrom] = useState(() => ymdToday().slice(0, 8) + '01')
  const [to, setTo] = useState(ymdToday)
  const [data, setData] = useState<ViOverview | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [busy, setBusy] = useState(false)
  const [liveNow, setLiveNow] = useState<number | null>(null)

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      setData(await ViApi.overview(period, from, to))
    } catch (e) {
      setError(e)
    } finally {
      setBusy(false)
    }
  }, [period, from, to])

  useEffect(() => {
    if (period !== 'custom') load()
  }, [period, load])

  useEffect(() => {
    let stop = false
    const tick = () => ViApi.live().then((l) => !stop && setLiveNow(l.total)).catch(() => {})
    tick()
    const t = setInterval(tick, 60_000)
    return () => {
      stop = true
      clearInterval(t)
    }
  }, [])

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-2">
        {PERIODS.map((p) => (
          <button
            key={p.key}
            onClick={() => setPeriod(p.key)}
            data-testid={`vi-period-${p.key}`}
            className={`px-3 py-1.5 rounded-full text-[12.5px] font-semibold border ${period === p.key ? 'bg-neutral-900 text-white border-neutral-900' : 'bg-white text-neutral-700 border-neutral-200 hover:border-neutral-400'}`}
          >
            {p.label}
          </button>
        ))}
        {period === 'custom' && (
          <div className="flex items-center gap-2">
            <input type="date" className={`${inputClass} !w-auto !py-1.5`} value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
            <span className="text-neutral-400">—</span>
            <input type="date" className={`${inputClass} !w-auto !py-1.5`} value={to} max={ymdToday()} onChange={(e) => setTo(e.target.value)} />
            <Button size="sm" onClick={load}>দেখুন</Button>
          </div>
        )}
        <button onClick={onLive} className="ml-auto inline-flex items-center gap-2 rounded-full bg-red-50 text-red-700 px-3 py-1.5 text-[12.5px] font-semibold">
          <span className="relative flex h-2 w-2"><span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75" /><span className="relative inline-flex h-2 w-2 rounded-full bg-red-500" /></span>
          এখন দেখছেন {liveNow === null ? '…' : bnInt(liveNow)} জন
        </button>
      </div>

      {error ? (
        <ErrorRetry error={error} onRetry={load} />
      ) : !data ? (
        <div className="py-16 flex justify-center"><Spinner /></div>
      ) : (
        <div className={`space-y-5 ${busy ? 'opacity-60' : ''}`}>
          {!data.has_accounts && (
            <Banner kind="info">
              এখন শুধু ওয়েবসাইটের হিসাব দেখা যাচ্ছে। ফেসবুক, ইউটিউব ও টিকটক যোগ করতে {onSetup ? <button className="underline font-semibold" onClick={onSetup}>অ্যাকাউন্ট ও সেটআপ</button> : 'পাবলিশারকে বলুন'}।
            </Banner>
          )}
          <Totals data={data} />
          <PlatformCards data={data} />
          <TrendChart data={data} />
          <AccountsTable data={data} />
          <TopContent data={data} />
          <p className="text-[12px] text-neutral-500 leading-relaxed">
            প্রতিটি প্ল্যাটফর্মের "ভিউ" আলাদা জিনিস: ওয়েবসাইটে পাতা দেখা, ফেসবুকে কনটেন্ট স্ক্রিনে আসা (views), ইউটিউবে ভিডিও দেখা, টিকটকে ভিডিওর ভিউ।
            তাই "সব মিলিয়ে" সংখ্যাটি মোট পৌঁছানোর ধারণা, আলাদা মানুষের সংখ্যা নয়। ফেসবুক ও ইউটিউবের দিন যুক্তরাষ্ট্রের প্যাসিফিক সময়ে গোনা হয়; ইউটিউবের শেষ ১–৩ দিনের সংখ্যা পরে বাড়তে পারে।
          </p>
        </div>
      )}
    </div>
  )
}

function Totals({ data }: { data: ViOverview }) {
  const t = data.totals
  const range = data.range.period === 'lifetime' ? `${bnDay(data.range.from)} থেকে আজ` : data.range.from === data.range.to ? bnDay(data.range.from) : `${bnDay(data.range.from)} – ${bnDay(data.range.to)}`
  const parts = ALL.map((k) => ({ k, v: data.platforms.find((p) => p.key === k)?.views ?? 0 }))
  const sum = parts.reduce((a, b) => a + b.v, 0) || 1
  return (
    <Card className="p-5">
      <div className="grid md:grid-cols-[1fr_1fr_1.4fr] gap-5 items-center">
        <div>
          <p className="text-[12.5px] text-neutral-500">সব মিলিয়ে মোট ভিউ · {data.range.label}</p>
          <p className="text-[32px] font-bold text-neutral-900 leading-tight" data-testid="vi-total-views">{bnInt(t.views)}</p>
          <p className="text-[12px] text-neutral-500 flex items-center gap-2">{range} <Delta cur={t.views} prev={t.prev_views} /></p>
        </div>
        <div>
          <p className="text-[12.5px] text-neutral-500">মোট ফলোয়ার/সাবস্ক্রাইবার (এখন)</p>
          <p className="text-[32px] font-bold text-neutral-900 leading-tight">{bnInt(t.followers)}</p>
          <p className="text-[12px] text-neutral-500">এই সময়ে <Signed n={t.followers_change} /></p>
        </div>
        <div>
          <p className="text-[12.5px] text-neutral-500 mb-2">কোথা থেকে কত ভিউ</p>
          <div className="flex h-3 rounded-full overflow-hidden bg-neutral-100">
            {parts.map((p) => p.v > 0 && <div key={p.k} style={{ width: `${(p.v / sum) * 100}%`, background: PLATFORM[p.k].color }} title={PLATFORM[p.k].label} />)}
          </div>
          <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-neutral-600">
            {parts.map((p) => (
              <span key={p.k} className="inline-flex items-center gap-1.5">
                <span className="w-2 h-2 rounded-full" style={{ background: PLATFORM[p.k].color }} />
                {PLATFORM[p.k].label} {bnDigits(Math.round((p.v / sum) * 100))}%
              </span>
            ))}
          </div>
        </div>
      </div>
    </Card>
  )
}

function PlatformCards({ data }: { data: ViOverview }) {
  return (
    <div className="grid sm:grid-cols-2 xl:grid-cols-4 gap-3">
      {ALL.map((k) => {
        const p = data.platforms.find((x) => x.key === k)
        const none = k !== 'website' && (!p || !p.accounts.length)
        return (
          <Card key={k} className="p-4" >
            <div className="flex items-center gap-2 mb-2">
              <span className="w-8 h-8 rounded-lg flex items-center justify-center text-white" style={{ background: PLATFORM[k].color }}><PIcon p={k} /></span>
              <span className="font-semibold text-neutral-800 text-[14px]">{PLATFORM[k].label}</span>
              {p && k !== 'website' && p.accounts.length > 1 && <Badge>{bnDigits(p.accounts.length)}টি</Badge>}
            </div>
            {none ? (
              <p className="text-[12.5px] text-neutral-400 py-3">যুক্ত নেই</p>
            ) : (
              <>
                <p className="text-[22px] font-bold text-neutral-900 leading-tight" data-testid={`vi-card-${k}`}>{bnInt(p?.views ?? 0)}</p>
                <p className="text-[12px] text-neutral-500 flex items-center gap-2">ভিউ <Delta cur={p?.views ?? 0} prev={p?.prev_views} /></p>
                {k !== 'website' && (
                  <p className="text-[12.5px] text-neutral-700 mt-2 flex items-center gap-1.5">
                    <IconUsers className="w-3.5 h-3.5 text-neutral-400" /> {p?.followers === null ? '—' : bnInt(p?.followers ?? 0)} <span className="text-[11.5px]"><Signed n={p?.followers_change} /></span>
                  </p>
                )}
                <p className={`text-[11.5px] mt-1 ${p?.partial ? 'text-amber-700' : 'text-neutral-400'}`}>
                  তথ্য {p?.since ? `${bnDay(p.since)} থেকে` : 'এখনো আসেনি'}{p?.partial ? ' — এই সময়ের পুরোটা নেই' : ''}
                </p>
              </>
            )}
          </Card>
        )
      })}
    </div>
  )
}

function TrendChart({ data }: { data: ViOverview }) {
  const gran = data.range.granularity
  const rows = useMemo(
    () =>
      data.series.map((s) => ({
        name: bucketLabel(s.key, gran),
        [PLATFORM.website.label]: s.website,
        [PLATFORM.facebook.label]: s.facebook,
        [PLATFORM.youtube.label]: s.youtube,
        [PLATFORM.tiktok.label]: s.tiktok,
      })),
    [data.series, gran],
  )
  const shown = ALL.filter((k) => data.series.some((s) => s[k] > 0))
  const unit = gran === 'day' ? 'দিনে' : gran === 'week' ? 'সপ্তাহে' : 'মাসে'
  return (
    <Card className="p-5">
      <div className="flex items-center justify-between mb-3">
        <h3 className="font-semibold text-neutral-900 text-[15px]">ভিউ — প্রতি {unit}</h3>
        <span className="text-[12px] text-neutral-500">প্ল্যাটফর্ম অনুযায়ী ভাগ করা</span>
      </div>
      {data.series.length <= 1 ? (
        <p className="text-[13px] text-neutral-500 py-8 text-center">এক দিনের হিসাব — চার্টের জন্য ৭ দিন বা তার বেশি বেছে নিন।</p>
      ) : (
        <div className="h-72">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={rows} margin={{ top: 6, right: 8, bottom: 0, left: 0 }}>
              <CartesianGrid vertical={false} stroke="#eeede8" />
              <XAxis dataKey="name" tick={{ fontSize: 11, fill: '#898781' }} tickLine={false} axisLine={false} interval="preserveStartEnd" minTickGap={18} />
              <YAxis tick={{ fontSize: 11, fill: '#898781' }} tickLine={false} axisLine={false} width={56} tickFormatter={(v) => bnShort(Number(v))} />
              <Tooltip contentStyle={tooltipStyle} formatter={(v) => bnInt(Number(v))} cursor={{ fill: 'rgba(0,0,0,.04)' }} />
              <Legend wrapperStyle={{ fontSize: 12 }} iconType="circle" />
              {shown.map((k, i) => (
                <Bar key={k} dataKey={PLATFORM[k].label} stackId="v" fill={PLATFORM[k].color} radius={i === shown.length - 1 ? [3, 3, 0, 0] : 0} maxBarSize={36} animationDuration={300} />
              ))}
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
    </Card>
  )
}

function AccountsTable({ data }: { data: ViOverview }) {
  const rows = SOCIAL.flatMap((k) => (data.platforms.find((p) => p.key === k)?.accounts ?? []).map((a) => ({ ...a, platform: k })))
  if (!rows.length) return null
  return (
    <Card className="overflow-hidden">
      <div className="px-5 pt-4 pb-2"><h3 className="font-semibold text-neutral-900 text-[15px]">পেজ ও চ্যানেল আলাদাভাবে</h3></div>
      <div className="overflow-x-auto">
        <table className="w-full text-[13px]">
          <thead className="bg-neutral-50 text-neutral-500 text-[12px]">
            <tr>
              <th className="text-left font-semibold px-5 py-2">অ্যাকাউন্ট</th>
              <th className="text-right font-semibold px-3 py-2">ভিউ</th>
              <th className="text-right font-semibold px-3 py-2">আগের সময়ের তুলনায়</th>
              <th className="text-right font-semibold px-3 py-2">ফলোয়ার</th>
              <th className="text-right font-semibold px-3 py-2">এই সময়ে</th>
              <th className="text-right font-semibold px-5 py-2">শেষ হালনাগাদ</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((a) => (
              <tr key={a.id} className="border-t border-neutral-100">
                <td className="px-5 py-2.5">
                  <div className="flex items-center gap-2">
                    <span className="w-6 h-6 rounded-md flex items-center justify-center text-white shrink-0" style={{ background: PLATFORM[a.platform].color }}><PIcon p={a.platform} className="w-3.5 h-3.5" /></span>
                    <a href={a.url} target="_blank" rel="noreferrer" className="font-medium text-neutral-800 hover:underline truncate max-w-[260px]">{a.name}</a>
                    {a.status !== 'ok' && <Badge tone="red">{a.status === 'expired' ? 'আবার সংযোগ লাগবে' : a.status === 'disconnected' ? 'বিচ্ছিন্ন' : 'সমস্যা'}</Badge>}
                  </div>
                  {a.partial && <p className="text-[11px] text-amber-700 mt-0.5 ml-8">তথ্য {a.since ? `${bnDay(a.since)} থেকে` : 'এখনো আসেনি'}</p>}
                </td>
                <td className="px-3 py-2.5 text-right font-semibold tabular-nums">{bnInt(a.views)}</td>
                <td className="px-3 py-2.5 text-right"><Delta cur={a.views} prev={a.prev_views} /></td>
                <td className="px-3 py-2.5 text-right tabular-nums">{a.followers === null ? '—' : bnInt(a.followers)}</td>
                <td className="px-3 py-2.5 text-right tabular-nums"><Signed n={a.followers_change} /></td>
                <td className="px-5 py-2.5 text-right text-neutral-500">{agoBn(a.last_sync)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  )
}

function TopContent({ data }: { data: ViOverview }) {
  const life = data.range.period === 'lifetime'
  return (
    <div>
      <h3 className="font-semibold text-neutral-900 text-[15px] mb-2">সেরা কনটেন্ট <span className="text-[12px] font-normal text-neutral-500">{life ? '(সব সময়)' : '(ওয়েবসাইট: এই সময়ের ভিউ ধরে · সোশ্যাল: এই সময়ে প্রকাশিত, মোট ভিউ ধরে)'}</span></h3>
      <div className="grid md:grid-cols-2 xl:grid-cols-4 gap-3">
        {ALL.map((k) => {
          const list = data.top[k] ?? []
          return (
            <Card key={k} className="p-4">
              <p className="flex items-center gap-2 text-[13px] font-semibold mb-2" style={{ color: PLATFORM[k].color }}><PIcon p={k} /> {PLATFORM[k].label}</p>
              {!list.length ? (
                <p className="text-[12.5px] text-neutral-400">কিছু নেই</p>
              ) : (
                <ol className="space-y-2">
                  {list.map((it, i) => (
                    <li key={i} className="flex gap-2">
                      {it.thumb ? <img src={it.thumb} alt="" className="w-12 h-12 rounded-md object-cover shrink-0 bg-neutral-100" loading="lazy" /> : <span className="w-12 h-12 rounded-md bg-neutral-100 shrink-0" />}
                      <div className="min-w-0">
                        <a href={it.url} target="_blank" rel="noreferrer" className="text-[12.5px] text-neutral-800 leading-snug line-clamp-2 hover:underline">{it.title || '(শিরোনাম নেই)'}</a>
                        <p className="text-[11.5px] text-neutral-500">{bnInt(it.views)} ভিউ{k === 'website' && !life ? ' (এই সময়ে)' : ''}</p>
                      </div>
                    </li>
                  ))}
                </ol>
              )}
            </Card>
          )
        })}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// লাইভ
// ---------------------------------------------------------------------------

function LiveTab() {
  const [live, setLive] = useState<ViLive | null>(null)
  const [error, setError] = useState<unknown>(null)
  const load = useCallback(async (fresh = false) => {
    try {
      setLive(await ViApi.live(fresh))
      setError(null)
    } catch (e) {
      setError(e)
    }
  }, [])
  useEffect(() => {
    load()
    const t = setInterval(() => load(), LIVE_MS)
    return () => clearInterval(t)
  }, [load])

  if (error && !live) return <ErrorRetry error={error} onRetry={() => load(true)} />
  if (!live) return <div className="py-16 flex justify-center"><Spinner /></div>
  const w = live.website
  const social = live.accounts.reduce((a, b) => a + b.viewers, 0)
  return (
    <div className="space-y-4">
      <Card className="p-6 flex flex-wrap items-center gap-6">
        <div>
          <p className="text-[13px] text-neutral-500 flex items-center gap-2">
            <span className="relative flex h-2.5 w-2.5"><span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75" /><span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-red-500" /></span>
            সব প্ল্যাটফর্ম মিলিয়ে এখন দেখছেন
          </p>
          <p className="text-[44px] font-bold text-neutral-900 leading-none mt-1" data-testid="vi-live-total">{bnInt(live.total)}</p>
          <p className="text-[12px] text-neutral-500 mt-1">প্রতি {bnDigits(LIVE_MS / 1000)} সেকেন্ডে নিজে থেকে হালনাগাদ হয়</p>
        </div>
        <div className="flex gap-6 text-[13px]">
          <div><p className="text-neutral-500">ওয়েবসাইটে</p><p className="text-[22px] font-bold">{bnInt(w.now)}</p></div>
          <div><p className="text-neutral-500">ফেসবুক/ইউটিউব লাইভে</p><p className="text-[22px] font-bold">{bnInt(social)}</p></div>
        </div>
        <Button variant="secondary" size="sm" className="ml-auto" onClick={() => load(true)}><IconRefresh className="w-4 h-4" /> এখনই দেখুন</Button>
      </Card>

      <div className="grid lg:grid-cols-2 gap-4">
        <Card className="p-5">
          <p className="flex items-center gap-2 font-semibold text-[14px] mb-1" style={{ color: PLATFORM.website.color }}><IconWorld className="w-4 h-4" /> ওয়েবসাইট</p>
          {!w.supported ? (
            <p className="text-[12.5px] text-neutral-500">থিমের লাইভ কাউন্টার পাওয়া যায়নি (Meeha থিম ২.৪.২ বা নতুন লাগবে)।</p>
          ) : (
            <>
              <p className="text-[12px] text-neutral-500 mb-3">গত {bnDigits(Math.round(w.window / 60 * 10) / 10)} মিনিটে সক্রিয় · গত ৩০ মিনিটে {bnInt(w.last30)} · আজ {bnInt(w.today)} জন</p>
              {!w.enabled && <p className="text-[12px] text-amber-700 mb-2">থিম সেটিংসে লাইভ কাউন্টার বন্ধ আছে।</p>}
              <ul className="space-y-1.5">
                {w.pages.map((p, i) => (
                  <li key={i} className="flex justify-between gap-3 text-[13px]">
                    <a href={p.url} target="_blank" rel="noreferrer" className="truncate hover:underline">{p.title}</a>
                    <span className="font-semibold tabular-nums">{bnInt(p.n)}</span>
                  </li>
                ))}
                {!w.pages.length && <li className="text-[12.5px] text-neutral-400">এই মুহূর্তে কেউ নেই</li>}
              </ul>
            </>
          )}
        </Card>

        <Card className="p-5 space-y-3">
          <p className="font-semibold text-[14px]">ফেসবুক, ইউটিউব ও টিকটক লাইভ</p>
          {!live.accounts.length && <p className="text-[12.5px] text-neutral-500">কোনো পেজ বা চ্যানেল যুক্ত নেই।</p>}
          {live.accounts.map((a) => (
            <div key={a.id} className="border border-neutral-100 rounded-xl p-3">
              <div className="flex items-center gap-2">
                <span className="w-6 h-6 rounded-md flex items-center justify-center text-white" style={{ background: PLATFORM[a.platform].color }}><PIcon p={a.platform} className="w-3.5 h-3.5" /></span>
                <span className="font-medium text-[13px] truncate">{a.name}</span>
                <span className="ml-auto">
                  {!a.supported ? <Badge>API দেয় না</Badge> : a.live ? <Badge tone="red">লাইভ · {bnInt(a.viewers)} জন</Badge> : <Badge>এখন লাইভ নেই</Badge>}
                </span>
              </div>
              {a.items.map((it, i) => (
                <a key={i} href={it.url} target="_blank" rel="noreferrer" className="flex justify-between gap-3 text-[12.5px] mt-2 hover:underline">
                  <span className="truncate">{it.title || 'লাইভ ভিডিও'}</span>
                  <span className="font-semibold tabular-nums">{bnInt(it.viewers)}</span>
                </a>
              ))}
              {a.error && <p className="text-[11.5px] text-red-600 mt-1">{a.error}</p>}
              {!a.supported && <p className="text-[11.5px] text-neutral-500 mt-1">টিকটকের সাধারণ API লাইভ দর্শকের সংখ্যা দেয় না, তাই এখানে গোনা হয় না।</p>}
            </div>
          ))}
          <p className="text-[11.5px] text-neutral-400">ফেসবুক/ইউটিউবের সংখ্যা সার্ভার প্রতি {bnDigits(live.ttl)} সেকেন্ডে একবার আনে।</p>
        </Card>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// অ্যাকাউন্ট ও সেটআপ
// ---------------------------------------------------------------------------

const PROVIDER_INFO: Record<ViProvider, { label: string; platform: Exclude<ViPlatform, 'website'>; idLabel: string; secretLabel: string; console: string; consoleLabel: string }> = {
  facebook: { label: 'ফেসবুক পেজ', platform: 'facebook', idLabel: 'App ID', secretLabel: 'App Secret', console: 'https://developers.facebook.com/apps/', consoleLabel: 'Meta for Developers' },
  google: { label: 'ইউটিউব চ্যানেল', platform: 'youtube', idLabel: 'Client ID', secretLabel: 'Client Secret', console: 'https://console.cloud.google.com/apis/credentials', consoleLabel: 'Google Cloud Console' },
  tiktok: { label: 'টিকটক অ্যাকাউন্ট', platform: 'tiktok', idLabel: 'Client Key', secretLabel: 'Client Secret', console: 'https://developers.tiktok.com/apps/', consoleLabel: 'TikTok for Developers' },
}

function SetupTab({ fbKey, onFbDone }: { fbKey: string; onFbDone: () => void }) {
  const [setup, setSetup] = useState<ViSetup | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [msg, setMsg] = useState<{ kind: 'success' | 'error'; text: string } | null>(null)
  const [busyId, setBusyId] = useState<number | string>(0)
  const [confirm, setConfirm] = useState<{ acc: ViAccount; purge: boolean } | null>(null)

  const load = useCallback(async () => {
    try {
      setSetup(await ViApi.setup())
      setError(null)
    } catch (e) {
      setError(e)
    }
  }, [])
  useEffect(() => {
    load()
  }, [load])

  const act = async (key: number | string, fn: () => Promise<unknown>, ok?: string) => {
    setBusyId(key)
    setMsg(null)
    try {
      const r = await fn()
      if (r && typeof r === 'object' && 'sync_error' in r && (r as ViAccount).sync_error) setMsg({ kind: 'error', text: `হালনাগাদ হয়নি: ${(r as ViAccount).sync_error}` })
      else if (ok) setMsg({ kind: 'success', text: ok })
      await load()
    } catch (e) {
      setMsg({ kind: 'error', text: describeError(e).message })
    } finally {
      setBusyId(0)
    }
  }

  const connect = (p: ViProvider) =>
    act(p, async () => {
      const { url } = await ViApi.connect(p, `${window.location.origin}/app/insights?tab=setup`)
      window.location.href = url
    })

  if (error && !setup) return <ErrorRetry error={error} onRetry={load} />
  if (!setup) return <div className="py-16 flex justify-center"><Spinner /></div>

  return (
    <div className="space-y-5">
      {msg && <Banner kind={msg.kind}>{msg.text}</Banner>}

      <Card className="p-5">
        <h3 className="font-semibold text-neutral-900 text-[15px]">যুক্ত অ্যাকাউন্ট</h3>
        <p className="text-[12.5px] text-neutral-500 mb-3">প্রতি ঘণ্টায় নিজে থেকে হালনাগাদ হয় (সার্ভারের ক্রন)। শেষবার চলেছে: {agoBn(setup.cron.last)}।</p>
        {!setup.accounts.length && <p className="text-[13px] text-neutral-500">এখনো কিছু যুক্ত হয়নি। নিচে প্রতিটি প্ল্যাটফর্মের অ্যাপ কী বসিয়ে "যোগ করুন" চাপুন।</p>}
        <div className="divide-y divide-neutral-100">
          {setup.accounts.map((a) => (
            <div key={a.id} className="py-3 flex flex-wrap items-center gap-3" data-testid={`vi-acc-${a.id}`}>
              {a.avatar ? <img src={a.avatar} alt="" className="w-10 h-10 rounded-full object-cover bg-neutral-100" /> : <span className="w-10 h-10 rounded-full flex items-center justify-center text-white" style={{ background: PLATFORM[a.platform].color }}><PIcon p={a.platform} /></span>}
              <div className="min-w-0 flex-1">
                <p className="font-semibold text-[13.5px] flex items-center gap-2 flex-wrap">
                  <a href={a.url} target="_blank" rel="noreferrer" className="hover:underline">{a.name}</a>
                  <span className={`text-[11px] px-1.5 py-0.5 rounded ${PLATFORM[a.platform].soft}`}>{PLATFORM[a.platform].label}</span>
                  <StatusBadge a={a} />
                  {!a.enabled && <Badge>হিসাবে ধরা হচ্ছে না</Badge>}
                </p>
                <p className="text-[12px] text-neutral-500">
                  ফলোয়ার {a.followers === null ? '—' : bnInt(a.followers)} · তথ্য {a.data_since ? `${bnDay(a.data_since)} থেকে` : 'এখনো আসেনি'}
                  {!a.backfill_done && a.connected && ' · পুরোনো তথ্য আনা চলছে'} · হালনাগাদ {agoBn(a.last_sync)}
                </p>
                {a.last_error && <p className="text-[12px] text-red-600">{a.last_error}</p>}
                {a.warning && <p className="text-[12px] text-amber-700">{a.warning}</p>}
                {a.platform === 'tiktok' && a.refresh_expires && <p className="text-[11.5px] text-neutral-400">টিকটকের সংযোগ {bnDay(a.refresh_expires.slice(0, 10))} পর্যন্ত চলবে, তার আগে একবার আবার যোগ করুন।</p>}
              </div>
              <div className="flex flex-wrap gap-1.5">
                {a.connected && (
                  <Button size="sm" variant="secondary" disabled={!!busyId} onClick={() => act(a.id, () => ViApi.sync(a.id), `${a.name} হালনাগাদ হয়েছে।`)}>
                    {busyId === a.id ? <MiniSpin /> : <IconRefresh className="w-3.5 h-3.5" />} এখনই হালনাগাদ
                  </Button>
                )}
                {(a.status === 'expired' || !a.connected) && (
                  <Button size="sm" disabled={!!busyId} onClick={() => connect(a.platform === 'youtube' ? 'google' : a.platform)}>আবার যুক্ত করুন</Button>
                )}
                <Button size="sm" variant="ghost" disabled={!!busyId} onClick={() => act(a.id, () => ViApi.patch(a.id, { enabled: !a.enabled }))}>
                  {a.enabled ? 'হিসাব থেকে বাদ' : 'হিসাবে ধরুন'}
                </Button>
                {a.connected && <Button size="sm" variant="danger" disabled={!!busyId} onClick={() => setConfirm({ acc: a, purge: false })}><IconUnlink className="w-3.5 h-3.5" /> বিচ্ছিন্ন</Button>}
                <Button size="sm" variant="danger" disabled={!!busyId} onClick={() => setConfirm({ acc: a, purge: true })}><IconTrash className="w-3.5 h-3.5" /></Button>
              </div>
            </div>
          ))}
        </div>
      </Card>

      <div className="grid xl:grid-cols-3 gap-4">
        {(Object.keys(PROVIDER_INFO) as ViProvider[]).map((p) => (
          <ProviderCard key={p} p={p} setup={setup} busy={busyId === p} onSaved={setSetup} onConnect={() => connect(p)} onMsg={setMsg} />
        ))}
      </div>

      <Card className="p-5 flex flex-wrap items-center gap-3">
        <div className="flex-1 min-w-[240px]">
          <p className="font-semibold text-[14px]">এডিটররাও দেখতে পারবেন</p>
          <p className="text-[12.5px] text-neutral-500">চালু করলে এডিটররা সারসংক্ষেপ ও লাইভ দেখবেন। সেটআপ ও সংযোগ সবসময় শুধু পাবলিশারের।</p>
        </div>
        <label className="inline-flex items-center gap-2 text-[13px] font-medium cursor-pointer">
          <input
            type="checkbox"
            className="w-4 h-4"
            checked={setup.settings.editors}
            onChange={(e) => act('editors', async () => setSetup(await ViApi.saveSetup({ editors: e.target.checked })), 'সংরক্ষিত।')}
          />
          {setup.settings.editors ? 'চালু' : 'বন্ধ'}
        </label>
      </Card>

      {fbKey && <FbPicker k={fbKey} onClose={onFbDone} onDone={(n) => { onFbDone(); setMsg({ kind: 'success', text: `${bnDigits(n)}টি ফেসবুক পেজ যুক্ত হয়েছে। দুই বছরের পুরোনো তথ্য ধাপে ধাপে আসছে।` }); load() }} />}

      {confirm && (
        <Modal title={confirm.purge ? 'ডেটাসহ মুছবেন?' : 'সংযোগ বিচ্ছিন্ন করবেন?'} onClose={() => setConfirm(null)}>
          <p className="text-[13.5px] text-neutral-700 mb-4">
            {confirm.purge
              ? `${confirm.acc.name} এর জমানো সব দিনের হিসাব মুছে যাবে। ফেসবুকের দুই বছরের পুরোনো তথ্য আর কখনো ফিরিয়ে আনা যাবে না।`
              : `${confirm.acc.name} এর চাবি (টোকেন) মুছে যাবে, নতুন তথ্য আসা বন্ধ হবে। এ পর্যন্ত জমানো হিসাব থাকবে এবং মোট সংখ্যায় গোনা হবে।`}
          </p>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setConfirm(null)}>না</Button>
            <Button
              variant="destructive"
              onClick={() => {
                const c = confirm
                setConfirm(null)
                act(c.acc.id, () => ViApi.remove(c.acc.id, c.purge), c.purge ? 'মুছে ফেলা হয়েছে।' : 'বিচ্ছিন্ন হয়েছে, জমানো তথ্য আছে।')
              }}
            >
              {confirm.purge ? 'হ্যাঁ, মুছুন' : 'হ্যাঁ, বিচ্ছিন্ন করুন'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  )
}

function StatusBadge({ a }: { a: ViAccount }) {
  if (a.status === 'disconnected' || !a.connected) return <Badge>বিচ্ছিন্ন</Badge>
  if (a.status === 'expired') return <Badge tone="red">আবার সংযোগ লাগবে</Badge>
  if (a.status === 'error') return <Badge tone="amber">সমস্যা</Badge>
  return <Badge tone="emerald">চলছে</Badge>
}

function Copyable({ value }: { value: string }) {
  const [done, setDone] = useState(false)
  return (
    <div className="flex items-center gap-1.5">
      <code className="text-[11.5px] bg-neutral-50 border border-neutral-200 rounded px-2 py-1 break-all flex-1">{value}</code>
      <button
        className="p-1.5 rounded hover:bg-neutral-100 text-neutral-500"
        title="কপি"
        onClick={() => navigator.clipboard?.writeText(value).then(() => { setDone(true); setTimeout(() => setDone(false), 1500) }).catch(() => {})}
      >
        {done ? <span className="text-[11px] text-emerald-600">কপি</span> : <IconCopy className="w-3.5 h-3.5" />}
      </button>
    </div>
  )
}

function ProviderCard({ p, setup, busy, onSaved, onConnect, onMsg }: { p: ViProvider; setup: ViSetup; busy: boolean; onSaved: (s: ViSetup) => void; onConnect: () => void; onMsg: (m: { kind: 'success' | 'error'; text: string }) => void }) {
  const info = PROVIDER_INFO[p]
  const st = setup.apps[p]
  const [id, setId] = useState(st.id)
  const [secret, setSecret] = useState('')
  const [version, setVersion] = useState(st.version ?? '')
  const [saving, setSaving] = useState(false)
  const n = setup.accounts.filter((a) => a.platform === info.platform && a.connected).length

  const save = async () => {
    setSaving(true)
    try {
      const s = await ViApi.saveSetup({ [p]: { id, ...(secret ? { secret } : {}), ...(p === 'facebook' && version ? { version } : {}) } })
      onSaved(s)
      setSecret('')
      onMsg({ kind: 'success', text: `${info.label}: অ্যাপ কী সংরক্ষিত।` })
    } catch (e) {
      onMsg({ kind: 'error', text: describeError(e).message })
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card className="p-5 flex flex-col gap-3">
      <div className="flex items-center gap-2">
        <span className="w-8 h-8 rounded-lg flex items-center justify-center text-white" style={{ background: PLATFORM[info.platform].color }}><PIcon p={info.platform} /></span>
        <div>
          <p className="font-semibold text-[14px]">{info.label}</p>
          <p className="text-[11.5px] text-neutral-500">{st.ready ? (n ? `${bnDigits(n)}টি যুক্ত` : 'প্রস্তুত, এখনো যুক্ত হয়নি') : 'অ্যাপ কী বসানো বাকি'}</p>
        </div>
        <a href={info.console} target="_blank" rel="noreferrer" className="ml-auto text-[11.5px] text-brand-600 inline-flex items-center gap-1 hover:underline">{info.consoleLabel} <IconExternalLink className="w-3 h-3" /></a>
      </div>
      <div>
        <p className="text-[11.5px] text-neutral-500 mb-1">Redirect URI (কনসোলে হুবহু বসান)</p>
        <Copyable value={st.redirect} />
      </div>
      <label className="text-[12px] text-neutral-600">{info.idLabel}
        <input className={inputClass} value={id} onChange={(e) => setId(e.target.value)} autoComplete="off" />
      </label>
      <label className="text-[12px] text-neutral-600">{info.secretLabel}
        <input className={inputClass} type="password" value={secret} onChange={(e) => setSecret(e.target.value)} placeholder={st.has_secret ? '•••••••• (সংরক্ষিত — বদলাতে নতুনটি লিখুন)' : ''} autoComplete="new-password" />
      </label>
      {p === 'facebook' && (
        <label className="text-[12px] text-neutral-600">Graph API সংস্করণ
          <input className={inputClass} value={version} onChange={(e) => setVersion(e.target.value)} placeholder="v25.0" />
        </label>
      )}
      <div className="flex gap-2 mt-auto">
        <Button variant="secondary" size="sm" onClick={save} disabled={saving || !id}>{saving ? <MiniSpin /> : null} সংরক্ষণ</Button>
        <Button size="sm" onClick={onConnect} disabled={!st.ready || busy} data-testid={`vi-connect-${p}`}>
          {busy ? <MiniSpin /> : null} {p === 'facebook' ? 'ফেসবুক পেজ যোগ করুন' : p === 'google' ? 'ইউটিউব চ্যানেল যোগ করুন' : 'টিকটক যোগ করুন'}
        </Button>
      </div>
      <Hint p={p} />
    </Card>
  )
}

function Hint({ p }: { p: ViProvider }) {
  const t: Record<ViProvider, ReactNode> = {
    facebook: <>এক সাইন-ইনে আপনি যে পেজগুলোর অ্যাডমিন, তার তালিকা আসবে; যেগুলো চান টিক দিন। আরেকটি পেজ পরে যোগ করতে আবার চাপুন। দুই বছরের পুরোনো তথ্যও আসে, আর এখানে জমানো তথ্য কখনো মোছে না।</>,
    google: <>প্রতিটি চ্যানেলের জন্য একবার করে যোগ করুন (ব্র্যান্ড চ্যানেল হলে সাইন-ইনে চ্যানেলটি বেছে নিন)। চ্যানেল খোলার দিন থেকে সব তথ্য আসে।</>,
    tiktok: <>টিকটক শুধু আজকের মোট সংখ্যা দেয়, তাই ইতিহাস শুরু হবে যুক্ত করার দিন থেকে। অ্যাপ রিভিউ পাস না হওয়া পর্যন্ত শুধু স্যান্ডবক্সে যোগ করা অ্যাকাউন্ট চলবে।</>,
  }
  return <p className="text-[11.5px] text-neutral-500 leading-relaxed">{t[p]}</p>
}

function FbPicker({ k, onClose, onDone }: { k: string; onClose: () => void; onDone: (n: number) => void }) {
  const [pages, setPages] = useState<ViFbPage[] | null>(null)
  const [sel, setSel] = useState<string[]>([])
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    ViApi.fbPending(k)
      .then((r) => {
        setPages(r.pages)
        setSel(r.pages.filter((p) => !p.connected).slice(0, 1).map((p) => p.id))
      })
      .catch((e) => setErr(describeError(e).message))
  }, [k])
  const add = async () => {
    setBusy(true)
    try {
      const r = await ViApi.fbAdd(k, sel)
      onDone(r.added.length)
    } catch (e) {
      setErr(describeError(e).message)
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="কোন ফেসবুক পেজ যোগ করবেন?" onClose={onClose}>
      {err && <Banner kind="error">{err}</Banner>}
      {!pages && !err && <div className="py-8 flex justify-center"><Spinner /></div>}
      {pages && (
        <div className="space-y-2 max-h-[50vh] overflow-y-auto">
          {pages.map((p) => (
            <label key={p.id} className="flex items-center gap-3 p-2 rounded-lg hover:bg-neutral-50 cursor-pointer">
              <input type="checkbox" className="w-4 h-4" checked={sel.includes(p.id)} onChange={(e) => setSel((s) => (e.target.checked ? [...s, p.id] : s.filter((x) => x !== p.id)))} />
              {p.picture ? <img src={p.picture} alt="" className="w-9 h-9 rounded-full" /> : <span className="w-9 h-9 rounded-full bg-neutral-100" />}
              <span className="flex-1 min-w-0">
                <span className="block text-[13.5px] font-medium truncate">{p.name}</span>
                <span className="block text-[11.5px] text-neutral-500">{p.followers === null ? '' : `${bnInt(p.followers)} ফলোয়ার`}{p.connected ? ' · আগে থেকেই যুক্ত (নতুন চাবি বসবে)' : ''}</span>
              </span>
            </label>
          ))}
        </div>
      )}
      <div className="flex justify-end gap-2 mt-4">
        <Button variant="secondary" onClick={onClose}>বাতিল</Button>
        <Button onClick={add} disabled={busy || !sel.length} data-testid="vi-fb-add">{busy ? <MiniSpin /> : null} {bnDigits(sel.length)}টি পেজ যোগ করুন</Button>
      </div>
    </Modal>
  )
}
