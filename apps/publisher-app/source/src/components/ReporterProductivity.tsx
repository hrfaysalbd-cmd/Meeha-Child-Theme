import { useEffect, useMemo, useState } from 'react'
import { InsightsApi, type InsightPeriod, type ReporterFilter, type ReportersReport } from '../lib/api'
import { Card, ErrorRetry, Spinner } from './ui'
import { IconDownload, IconSearch, IconUsers } from './Icon'
import { bnDigits, bnInt, wpAgoBn } from '../lib/insightFormat'

// Round 20 — "আজ কতজন সাংবাদিক কতটি নিউজ করেছেন, কার নিউজ নেই". Lists every
// reporter (correspondents and staff writers, plus anyone else who published
// in the window) with their count for the chosen day/period, and filters to
// those with news or those with none.

const PERIODS: Array<{ v: InsightPeriod | 'date'; l: string }> = [
  { v: 'today', l: 'আজ' },
  { v: 'yesterday', l: 'গতকাল' },
  { v: '7d', l: 'গত ৭ দিন' },
  { v: 'this_month', l: 'চলতি মাস' },
  { v: 'last_month', l: 'গত মাস' },
  { v: 'date', l: 'তারিখ বাছুন' },
]

const FILTERS: Array<{ v: ReporterFilter; l: string }> = [
  { v: 'all', l: 'সবাই' },
  { v: 'active', l: 'নিউজ করেছেন' },
  { v: 'zero', l: 'নিউজ নেই' },
]

function localYmd(d = new Date()) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export default function ReporterProductivity() {
  const [period, setPeriod] = useState<InsightPeriod | 'date'>('today')
  const [date, setDate] = useState(localYmd())
  const [filter, setFilter] = useState<ReporterFilter>('all')
  const [q, setQ] = useState('')
  const [data, setData] = useState<ReportersReport | null>(null)
  const [error, setError] = useState<unknown>(null)
  const [loading, setLoading] = useState(true)

  function load() {
    setLoading(true)
    InsightsApi.reporters(period, filter, period === 'date' ? date : undefined)
      .then((d) => {
        setData(d)
        setError(null)
      })
      .catch((e) => setError(e ?? new Error('unknown')))
      .finally(() => setLoading(false))
  }

  useEffect(load, [period, filter, date]) // eslint-disable-line react-hooks/exhaustive-deps

  const rows = useMemo(() => {
    const t = q.trim().toLowerCase()
    return (data?.items ?? []).filter((r) => !t || r.name.toLowerCase().includes(t) || r.division.toLowerCase().includes(t))
  }, [data, q])

  function exportCsv() {
    if (!data) return
    const head = ['নাম', 'ভূমিকা', 'বিভাগ', 'প্রকাশিত খবর', 'ভিডিও', 'অপেক্ষমাণ', 'ভিউ', 'মোট প্রকাশিত', 'শেষ প্রকাশ']
    const lines = rows.map((r) => [r.name, r.role_label, r.division, r.published, r.videos, r.pending, r.views, r.total, r.last_published ?? ''])
    const csv = '﻿' + [head, ...lines].map((l) => l.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n')
    const a = document.createElement('a')
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
    a.download = `reporters-${period === 'date' ? date : period}.csv`
    a.click()
    setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  }

  const s = data?.summary
  return (
    <Card className="p-5">
      <div data-testid="reporters">
      <div className="flex items-start justify-between gap-3 flex-wrap mb-4">
        <div>
          <h2 className="text-[15px] font-bold text-neutral-800 flex items-center gap-2">
            <IconUsers className="w-4.5 h-4.5 text-neutral-400" /> সাংবাদিকদের কাজ — {data?.period.label ?? ''}
          </h2>
          <p className="text-[12px] text-neutral-400 mt-0.5">কে কতটি খবর প্রকাশ করেছেন, কার কোনো খবর নেই</p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          <div className="flex rounded-full bg-neutral-100 p-0.5 flex-wrap">
            {PERIODS.map((p) => (
              <button
                key={p.v}
                onClick={() => setPeriod(p.v)}
                className={`px-3 py-1 rounded-full text-[12px] font-medium ${period === p.v ? 'bg-white text-neutral-900 shadow-sm' : 'text-neutral-500'}`}
              >
                {p.l}
              </button>
            ))}
          </div>
          {period === 'date' && (
            <input
              type="date"
              value={date}
              max={localYmd()}
              onChange={(e) => e.target.value && setDate(e.target.value)}
              className="rounded-lg border border-neutral-200 px-2 py-1 text-[12.5px]"
            />
          )}
        </div>
      </div>

      {s && (
        <div className="grid grid-cols-2 sm:grid-cols-5 gap-2.5 mb-4">
          <SumChip label="মোট সাংবাদিক" value={s.reporters} />
          <SumChip label="নিউজ করেছেন" value={s.active} tone="good" onClick={() => setFilter('active')} active={filter === 'active'} />
          <SumChip label="নিউজ নেই" value={s.zero} tone="bad" onClick={() => setFilter('zero')} active={filter === 'zero'} />
          <SumChip label="প্রকাশিত খবর" value={s.published + s.videos} />
          <SumChip label="রিভিউয়ের অপেক্ষায়" value={s.pending} />
        </div>
      )}

      <div className="flex items-center gap-2 mb-3 flex-wrap">
        <div className="flex rounded-full bg-neutral-100 p-0.5" role="tablist">
          {FILTERS.map((f) => (
            <button
              key={f.v}
              role="tab"
              aria-selected={filter === f.v}
              data-testid={`rep-filter-${f.v}`}
              onClick={() => setFilter(f.v)}
              className={`px-3 py-1 rounded-full text-[12px] font-medium ${filter === f.v ? 'bg-brand-600 text-white' : 'text-neutral-500'}`}
            >
              {f.l}
            </button>
          ))}
        </div>
        <label className="relative flex-1 min-w-[180px] max-w-xs">
          <IconSearch className="w-4 h-4 text-neutral-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="নাম বা বিভাগ দিয়ে খুঁজুন"
            className="w-full rounded-lg border border-neutral-200 pl-8 pr-3 py-1.5 text-[13px] focus:outline-none focus:ring-2 focus:ring-brand-600/20"
          />
        </label>
        <button onClick={exportCsv} className="ml-auto flex items-center gap-1 text-[12.5px] font-medium text-neutral-500 hover:text-brand-600">
          <IconDownload className="w-4 h-4" /> CSV
        </button>
      </div>

      {loading && !data ? (
        <Spinner />
      ) : error && !data ? (
        <ErrorRetry error={error} onRetry={load} />
      ) : rows.length === 0 ? (
        <p className="text-[13px] text-neutral-400 py-8 text-center">{filter === 'zero' ? 'সবাই খবর করেছেন 🎉' : 'কাউকে পাওয়া যায়নি।'}</p>
      ) : (
        <div className={`overflow-x-auto -mx-2 ${loading ? 'opacity-60' : ''}`}>
          <table className="w-full text-[13px]">
            <thead>
              <tr className="text-left text-[11.5px] text-neutral-400 whitespace-nowrap">
                <th className="font-medium px-2 pb-2">সাংবাদিক</th>
                <th className="font-medium px-2 pb-2 hidden md:table-cell">বিভাগ</th>
                <th className="font-medium px-2 pb-2 text-right">প্রকাশিত</th>
                <th className="font-medium px-2 pb-2 text-right hidden sm:table-cell">ভিডিও</th>
                <th className="font-medium px-2 pb-2 text-right hidden sm:table-cell">অপেক্ষমাণ</th>
                <th className="font-medium px-2 pb-2 text-right hidden lg:table-cell">এই সময়ে ভিউ</th>
                <th className="font-medium px-2 pb-2 text-right hidden lg:table-cell">মোট প্রকাশিত</th>
                <th className="font-medium px-2 pb-2 text-right hidden md:table-cell">শেষ প্রকাশ</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => {
                const zero = r.published + r.videos === 0
                return (
                  <tr key={r.id} className={`border-t border-neutral-100 ${zero ? 'bg-red-50/40' : ''}`} data-testid="rep-row">
                    <td className="px-2 py-2">
                      <div className="flex items-center gap-2.5">
                        <img src={r.avatar} alt="" className="w-8 h-8 rounded-full bg-neutral-100 object-cover" />
                        <div className="min-w-0">
                          <p className="font-medium text-neutral-800 truncate">{r.name}</p>
                          <p className="text-[11.5px] text-neutral-400">{r.role_label}</p>
                        </div>
                      </div>
                    </td>
                    <td className="px-2 py-2 text-neutral-500 hidden md:table-cell">{r.division || '—'}</td>
                    <td className="px-2 py-2 text-right">
                      {zero ? (
                        <span className="rounded-full bg-red-100 text-red-700 px-2 py-0.5 text-[11.5px] font-semibold">নিউজ নেই</span>
                      ) : (
                        <b className="text-[15px] text-neutral-900 tabular-nums">{bnDigits(r.published)}</b>
                      )}
                    </td>
                    <td className="px-2 py-2 text-right tabular-nums text-neutral-600 hidden sm:table-cell">{r.videos ? bnDigits(r.videos) : '—'}</td>
                    <td className="px-2 py-2 text-right tabular-nums text-neutral-600 hidden sm:table-cell">{r.pending ? bnDigits(r.pending) : '—'}</td>
                    <td className="px-2 py-2 text-right tabular-nums text-neutral-600 hidden lg:table-cell">{bnInt(r.views)}</td>
                    <td className="px-2 py-2 text-right tabular-nums text-neutral-600 hidden lg:table-cell">{bnInt(r.total)}</td>
                    <td className="px-2 py-2 text-right text-neutral-500 whitespace-nowrap hidden md:table-cell">{wpAgoBn(r.last_published)}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
      </div>
    </Card>
  )
}

function SumChip({ label, value, tone, onClick, active }: { label: string; value: number; tone?: 'good' | 'bad'; onClick?: () => void; active?: boolean }) {
  const color = tone === 'good' ? 'text-emerald-700' : tone === 'bad' ? 'text-red-600' : 'text-neutral-900'
  const Tag = onClick ? 'button' : 'div'
  return (
    <Tag
      onClick={onClick}
      className={`text-left rounded-xl px-3 py-2.5 ring-1 ${active ? 'ring-brand-600 bg-brand-50/40' : 'ring-neutral-200/80 bg-neutral-50/60'} ${onClick ? 'hover:ring-neutral-300' : ''}`}
    >
      <p className={`text-[22px] font-extrabold tabular-nums leading-tight ${color}`}>{bnInt(value)}</p>
      <p className="text-[11.5px] text-neutral-500">{label}</p>
    </Tag>
  )
}
