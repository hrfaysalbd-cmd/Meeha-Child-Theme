/**
 * দেশচিত্র — the night desk's daily sheet for the theme's [meeha_deshchitro] page.
 * After 12 a.m. the desk enters the previous day's figures per district (murders, road deaths, injuries, rape,
 * theft/robbery, suicide …, configured in wp-admin), national economy values, the day's summary and notable
 * events. "প্রকাশ" makes the day public on the site once the day is over. Same data and rules as wp-admin.
 *
 * Two ways to enter incidents: "জেলা ধরে" (tap a district, use − / + per indicator; best on a phone) and
 * "ছক" (the whole 64-district grid, for a desktop; paste from Excel works there).
 */
import { useCallback, useEffect, useMemo, useState } from 'react'
import { DeshchitroApi, describeError, type DcData, type DcSheet } from '../lib/api'
import { Badge, Banner, Button, Card, PageHeader, Spinner, inputClass } from '../components/ui'
import { IconChevronDown, IconExternalLink, IconPlus, IconSparkles, IconUpload, IconX } from '../components/Icon'
import { ImportDialog, PromptDialog } from '../components/DeshchitroImport'

const BN = '০১২৩৪৫৬৭৮৯'
const bn = (v: number | string) => String(v).replace(/[0-9]/g, (d) => BN[Number(d)])
const en = (v: string) => v.replace(/[০-৯]/g, (d) => String(BN.indexOf(d)))
const shift = (ymd: string, n: number) => {
  const [y, m, d] = ymd.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10)
}
const TONE: Record<string, string> = {
  bad: 'text-red-600 bg-red-50',
  warn: 'text-amber-700 bg-amber-50',
  good: 'text-emerald-700 bg-emerald-50',
  info: 'text-blue-700 bg-blue-50',
}
type Tab = 'district' | 'grid' | 'eco' | 'hl' | 'news'

export default function Deshchitro() {
  const [sheet, setSheet] = useState<DcSheet | null>(null)
  const [data, setData] = useState<DcData | null>(null)
  const [dirty, setDirty] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [tab, setTab] = useState<Tab>(() => (typeof window !== 'undefined' && window.innerWidth >= 1024 ? 'grid' : 'district'))
  const [open, setOpen] = useState<string>('')
  const [q, setQ] = useState('')
  const [dialog, setDialog] = useState<'' | 'prompt' | 'import'>('')

  const load = useCallback(async (date?: string) => {
    setBusy(true)
    setError('')
    try {
      const s = await DeshchitroApi.get(date)
      setSheet(s)
      setData(JSON.parse(JSON.stringify(s.data)))
      setDirty(false)
    } catch (e) {
      setError(describeError(e).message)
    } finally {
      setBusy(false)
    }
  }, [])

  useEffect(() => { load() }, [load])
  useEffect(() => {
    const h = (e: BeforeUnloadEvent) => { if (dirty) { e.preventDefault(); e.returnValue = '' } }
    window.addEventListener('beforeunload', h)
    return () => window.removeEventListener('beforeunload', h)
  }, [dirty])

  const go = (date: string) => {
    if (dirty && !window.confirm('সংরক্ষণ না করা পরিবর্তন হারাবে। অন্য দিনে যাবেন?')) return
    load(date)
  }
  const edit = (fn: (d: DcData) => void) => {
    setData((prev) => {
      if (!prev) return prev
      const next: DcData = JSON.parse(JSON.stringify(prev))
      fn(next)
      return next
    })
    setDirty(true)
  }
  const setInc = (dk: string, k: string, n: number) =>
    edit((d) => {
      n = Math.max(0, Math.min(99999, Math.round(n || 0)))
      if (dk === '_unk') { if (n) d.unk[k] = n; else delete d.unk[k]; return }
      d.inc[dk] = d.inc[dk] || {}
      if (n) d.inc[dk][k] = n
      else delete d.inc[dk][k]
    })

  const inds = useMemo(() => (sheet ? sheet.indicators.filter((i) => +i.on) : []), [sheet])
  const ecos = useMemo(() => (sheet ? sheet.economy.filter((e) => +e.on) : []), [sheet])
  const totals = useMemo(() => {
    const t: Record<string, number> = {}
    if (!data) return t
    inds.forEach((i) => { t[i.key] = data.unk[i.key] || 0 })
    Object.values(data.inc).forEach((v) => inds.forEach((i) => { t[i.key] += v[i.key] || 0 }))
    return t
  }, [data, inds])

  const save = async (status: 'draft' | 'publish') => {
    if (!sheet || !data) return
    if (status === 'publish' && !Object.keys(data.inc).length && !window.confirm('কোনো জেলায় একটি সংখ্যাও নেই। তবুও প্রকাশ করবেন?')) return
    if (status === 'draft' && sheet.exists && sheet.status === 'publish' && !window.confirm('এই দিনটি এখন প্রকাশিত। খসড়ায় ফেরালে পাঠক আর দেখবেন না। ফেরাবেন?')) return
    setBusy(true)
    setError('')
    try {
      const s = await DeshchitroApi.save(sheet.day, status, data)
      setSheet(s)
      setData(JSON.parse(JSON.stringify(s.data)))
      setDirty(false)
      setNotice(status === 'publish' ? (s.day >= s.today ? 'প্রকাশ নির্ধারিত — রাত ১২টায় সাইটে দেখা যাবে।' : 'প্রকাশিত হয়েছে — সাইটে দেখা যাচ্ছে।') : 'খসড়া সংরক্ষণ হয়েছে।')
      setTimeout(() => setNotice(''), 4000)
    } catch (e) {
      setError(describeError(e).message)
    } finally {
      setBusy(false)
    }
  }

  if (!sheet || !data) {
    return (
      <div>
        <PageHeader title="দেশচিত্র" subtitle="সারা দেশের দৈনিক হিসাব" />
        {error ? <Banner kind="error">{error}</Banner> : <div className="py-16 flex justify-center"><Spinner /></div>}
        {error && <Button onClick={() => load()}>আবার চেষ্টা করুন</Button>}
      </div>
    )
  }

  const geo = sheet.geo
  const divs = Object.keys(geo.divisions)
  const distsOf = (dv: string) => Object.keys(geo.districts).filter((k) => geo.districts[k][1] === dv)
  const isToday = sheet.day >= sheet.today
  const st = !sheet.exists ? ['নতুন', 'neutral'] : sheet.status === 'publish' ? (isToday ? ['রাত ১২টায় প্রকাশ হবে', 'blue'] : ['প্রকাশিত', 'emerald']) : ['খসড়া', 'amber']
  const distSum = (dk: string) => inds.reduce((s, i) => s + ((data.inc[dk] || {})[i.key] || 0), 0)

  const numInput = (dk: string, k: string, val: number | undefined, label: string) => (
    <input
      inputMode="numeric"
      aria-label={label}
      className={`w-14 text-center rounded-md border px-1 py-1 text-[13px] ${val ? 'bg-amber-50 border-amber-300 font-bold' : 'border-neutral-200'}`}
      value={val ? bn(val) : ''}
      onChange={(e) => setInc(dk, k, parseInt(en(e.target.value).replace(/[^0-9]/g, '') || '0', 10))}
    />
  )

  return (
    <div className="pb-24">
      <PageHeader
        title="দেশচিত্র — দৈনিক হিসাব"
        subtitle="রাত ১২টার পর আগের দিনের হিসাব দিন। চলতি দিনের হিসাব পাঠক দেখেন না; প্রকাশ দিলে দিন শেষে নিজে দেখা যাবে।"
        actions={sheet.pageUrl ? (
          <a href={`${sheet.pageUrl}${sheet.pageUrl.includes('?') ? '&' : '?'}dd=${sheet.day}`} target="_blank" rel="noopener" className="hidden sm:inline-flex items-center gap-1 text-[13px] text-brand-600 font-semibold">
            পাতায় দেখুন <IconExternalLink className="w-4 h-4" />
          </a>
        ) : undefined}
      />

      <div className="mb-3 rounded-2xl border border-blue-100 bg-gradient-to-r from-blue-50 to-amber-50 p-3 flex flex-wrap items-center gap-2">
        <span className="text-[13px] font-semibold text-blue-950 mr-auto">এআই দিয়ে দিনের তথ্য সংগ্রহ: প্রম্পট কপি করে এআই-তে দিন, তারপর তার ফাইল এক ক্লিকে ইমপোর্ট করুন।</span>
        <Button variant="secondary" size="sm" onClick={() => setDialog('prompt')}><IconSparkles className="w-4 h-4" /> এআই প্রম্পট</Button>
        <Button size="sm" onClick={() => setDialog('import')}><IconUpload className="w-4 h-4" /> ইমপোর্ট</Button>
      </div>
      {dialog === 'prompt' && <PromptDialog sheet={sheet} onClose={() => setDialog('')} />}
      {dialog === 'import' && (
        <ImportDialog
          sheet={sheet}
          data={data}
          onClose={() => setDialog('')}
          onApply={async (target, next, saveDraft) => {
            if (dirty && target.day !== sheet.day && !window.confirm('এই দিনের সংরক্ষণ না করা পরিবর্তন হারাবে। চালিয়ে যাবেন?')) return
            if (saveDraft) {
              const s = await DeshchitroApi.save(target.day, 'draft', next)
              setSheet(s)
              setData(JSON.parse(JSON.stringify(s.data)))
              setDirty(false)
              setNotice('ইমপোর্ট হয়ে খসড়া সংরক্ষণ হয়েছে। যাচাই করে প্রকাশ করুন।')
            } else {
              setSheet(target)
              setData(next)
              setDirty(true)
              setNotice('ইমপোর্ট শিটে বসেছে, এখনো সংরক্ষণ হয়নি।')
            }
            setTimeout(() => setNotice(''), 6000)
          }}
        />
      )}

      <Card className="p-3 mb-3 flex flex-wrap items-center gap-2">
        <Button variant="secondary" size="sm" onClick={() => go(shift(sheet.day, -1))} disabled={busy}>‹ আগের দিন</Button>
        <input type="date" className={`${inputClass} !w-auto`} value={sheet.day} max={sheet.today} onChange={(e) => e.target.value && go(e.target.value)} />
        <Button variant="secondary" size="sm" onClick={() => go(shift(sheet.day, 1))} disabled={busy || isToday}>পরের দিন ›</Button>
        <span className="font-bold text-[14.5px] text-neutral-900 ml-1">{sheet.label}</span>
        <Badge tone={st[1] as 'neutral'}>{st[0]}</Badge>
        {dirty && <Badge tone="amber">সংরক্ষণ বাকি</Badge>}
        {sheet.updatedAt && <span className="text-[12px] text-neutral-500">শেষ: {bn(sheet.updatedAt.slice(0, 16))}{sheet.updatedBy ? ` — ${sheet.updatedBy}` : ''}</span>}
      </Card>

      <div className="flex flex-wrap gap-1 mb-3" aria-label="এই মাস">
        {Array.from({ length: new Date(Date.UTC(+sheet.day.slice(0, 4), +sheet.day.slice(5, 7), 0)).getUTCDate() }, (_, i) => {
          const ymd = `${sheet.day.slice(0, 8)}${String(i + 1).padStart(2, '0')}`
          const s = sheet.month[ymd]
          return (
            <button
              key={ymd}
              disabled={ymd > sheet.today}
              onClick={() => go(ymd)}
              title={s === 'publish' ? 'প্রকাশিত' : s === 'draft' ? 'খসড়া' : 'হিসাব নেই'}
              className={`w-8 h-7 rounded-md text-[11.5px] border disabled:opacity-30 ${s === 'publish' ? 'bg-emerald-50 border-emerald-300' : s === 'draft' ? 'bg-amber-50 border-amber-300' : 'bg-white border-neutral-200'} ${ymd === sheet.day ? 'ring-2 ring-brand-600 font-bold' : ''}`}
            >
              {bn(i + 1)}
            </button>
          )
        })}
      </div>

      {isToday && <Banner kind="info">আজকের দিন এখনো শেষ হয়নি — যা দেবেন তা রাত ১২টার পর পাঠক দেখবেন (প্রকাশ দিলে)।</Banner>}
      {error && <Banner kind="error">{error}</Banner>}
      {notice && <Banner kind="success">{notice}</Banner>}

      <div className="flex gap-1 overflow-x-auto mb-3 border-b border-neutral-200">
        {([
          ['district', 'জেলা ধরে'],
          ['grid', 'ছক (সব জেলা)'],
          ['eco', 'অর্থনীতি'],
          ['hl', 'সারকথা ও ঘটনা'],
          ['news', `সেদিনের খবর (${bn(sheet.news.length)})`],
        ] as [Tab, string][]).map(([k, l]) => (
          <button key={k} onClick={() => setTab(k)} className={`px-3 py-2 text-[13.5px] font-semibold whitespace-nowrap border-b-2 -mb-px ${tab === k ? 'border-brand-600 text-brand-700' : 'border-transparent text-neutral-500'}`}>{l}</button>
        ))}
      </div>

      {/* national totals strip */}
      {(tab === 'district' || tab === 'grid') && (
        <div className="flex gap-2 overflow-x-auto pb-2 mb-2">
          {inds.map((i) => (
            <div key={i.key} className={`shrink-0 rounded-xl px-3 py-1.5 ${TONE[i.tone]}`}>
              <p className="text-[11px] font-medium">{i.short}</p>
              <p className="text-[17px] font-bold leading-tight">{bn(totals[i.key] || 0)}</p>
            </div>
          ))}
        </div>
      )}

      {tab === 'district' && (
        <div className="space-y-3">
          <input className={inputClass} placeholder="জেলা খুঁজুন…" value={q} onChange={(e) => setQ(e.target.value)} />
          {divs.map((dv) => {
            const ds = distsOf(dv).filter((dk) => !q || geo.districts[dk][0].includes(q))
            if (!ds.length) return null
            return (
              <Card key={dv} className="overflow-hidden">
                <p className="px-4 py-2 bg-blue-50/60 text-[13px] font-bold text-blue-900">{geo.divisions[dv]} বিভাগ</p>
                {ds.map((dk) => {
                  const sum = distSum(dk)
                  const isOpen = open === dk
                  return (
                    <div key={dk} className="border-t border-neutral-100">
                      <button className="w-full flex items-center gap-2 px-4 py-2.5 text-left" onClick={() => setOpen(isOpen ? '' : dk)} aria-expanded={isOpen}>
                        <span className="font-semibold text-[14px] text-neutral-900">{geo.districts[dk][0]}</span>
                        {sum > 0 && <Badge tone="amber">{bn(sum)}টি ঘটনা</Badge>}
                        {data.notes[dk] && <Badge tone="blue">নোট</Badge>}
                        <IconChevronDown className={`w-4 h-4 ml-auto text-neutral-400 transition ${isOpen ? 'rotate-180' : ''}`} />
                      </button>
                      {isOpen && (
                        <div className="px-4 pb-4 grid grid-cols-1 sm:grid-cols-2 gap-2">
                          {inds.map((i) => {
                            const v = (data.inc[dk] || {})[i.key] || 0
                            return (
                              <div key={i.key} className={`flex items-center gap-2 rounded-xl px-3 py-2 ${v ? TONE[i.tone] : 'bg-neutral-50 text-neutral-700'}`}>
                                <span className="text-[13px] flex-1">{i.label}</span>
                                <button className="w-9 h-9 rounded-lg bg-white border border-neutral-200 text-[18px] font-bold" onClick={() => setInc(dk, i.key, v - 1)} aria-label={`${i.label} কমান`}>−</button>
                                {numInput(dk, i.key, v, `${geo.districts[dk][0]} — ${i.label}`)}
                                <button className="w-9 h-9 rounded-lg bg-white border border-neutral-200 text-[18px] font-bold" onClick={() => setInc(dk, i.key, v + 1)} aria-label={`${i.label} বাড়ান`}>+</button>
                              </div>
                            )
                          })}
                          <textarea
                            className={`${inputClass} sm:col-span-2`}
                            rows={2}
                            maxLength={220}
                            placeholder="নোট (ঐচ্ছিক) — ম্যাপে জেলার ওপর কার্সার রাখলে পাঠক দেখবেন"
                            value={data.notes[dk] || ''}
                            onChange={(e) => edit((d) => { if (e.target.value.trim()) d.notes[dk] = e.target.value; else delete d.notes[dk] })}
                          />
                        </div>
                      )}
                    </div>
                  )
                })}
              </Card>
            )
          })}
          <Card className="p-4">
            <p className="font-semibold text-[14px] mb-2">জেলা অজানা / জাতীয়</p>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {inds.map((i) => (
                <label key={i.key} className="flex items-center gap-2 text-[13px]">
                  <span className="flex-1">{i.label}</span>
                  {numInput('_unk', i.key, data.unk[i.key], `জেলা অজানা — ${i.label}`)}
                </label>
              ))}
            </div>
          </Card>
        </div>
      )}

      {tab === 'grid' && (
        <Card className="overflow-auto max-h-[70vh]">
          <table className="text-[12.5px] border-separate border-spacing-0">
            <thead>
              <tr>
                <th className="sticky top-0 left-0 z-20 bg-neutral-50 px-3 py-2 text-left">জেলা</th>
                {inds.map((i) => (
                  <th key={i.key} className={`sticky top-0 z-10 bg-neutral-50 px-1 py-2 font-semibold max-w-[5.5rem] ${TONE[i.tone].split(' ')[0]}`} title={i.label}>{i.short}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {divs.map((dv) => [
                <tr key={dv}><td colSpan={inds.length + 1} className="sticky left-0 bg-blue-50 px-3 py-1 font-bold text-blue-900">{geo.divisions[dv]} বিভাগ</td></tr>,
                ...distsOf(dv).map((dk) => (
                  <tr key={dk} className="hover:bg-neutral-50">
                    <th className="sticky left-0 bg-white px-3 py-0.5 text-left font-medium whitespace-nowrap border-b border-neutral-100">{geo.districts[dk][0]}</th>
                    {inds.map((i) => (
                      <td key={i.key} className="px-1 py-0.5 border-b border-neutral-100 text-center">{numInput(dk, i.key, (data.inc[dk] || {})[i.key], `${geo.districts[dk][0]} — ${i.label}`)}</td>
                    ))}
                  </tr>
                )),
              ])}
              <tr>
                <th className="sticky left-0 bg-neutral-50 px-3 py-1 text-left italic">জেলা অজানা</th>
                {inds.map((i) => <td key={i.key} className="px-1 py-1 text-center bg-neutral-50">{numInput('_unk', i.key, data.unk[i.key], `জেলা অজানা — ${i.label}`)}</td>)}
              </tr>
            </tbody>
          </table>
        </Card>
      )}

      {tab === 'eco' && (
        <div>
          <div className="flex flex-wrap items-center gap-2 mb-3">
            <Button
              variant="secondary"
              size="sm"
              onClick={() => edit((d) => { ecos.forEach((e) => { if (d.eco[e.key] === undefined && sheet.prevEco[e.key] !== undefined) d.eco[e.key] = sheet.prevEco[e.key] }) })}
            >
              খালি ঘরে আগের মান বসান{sheet.prevDay ? ` (${bn(sheet.prevDay)})` : ''}
            </Button>
            <span className="text-[12px] text-neutral-500">মান না বদলালেও প্রতিদিন দিন।</span>
          </div>
          {Object.keys(sheet.ecoGroups).map((g) => {
            const rows = ecos.filter((e) => e.group === g)
            if (!rows.length) return null
            return (
              <div key={g} className="mb-4">
                <p className="font-bold text-[14px] mb-2">{sheet.ecoGroups[g]}{g === 'currency' ? ' (১ একক = টাকা)' : ''}</p>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                  {rows.map((e) => (
                    <Card key={e.key} className="p-3">
                      <p className="text-[12.5px] text-neutral-600 mb-1">{e.code && <b className="mr-1 px-1.5 rounded bg-blue-900 text-white text-[11px]">{e.code}</b>}{e.label}</p>
                      <div className="flex items-center gap-2">
                        <input
                          inputMode="decimal"
                          className={inputClass}
                          defaultValue={data.eco[e.key] !== undefined ? bn(data.eco[e.key]) : ''}
                          key={`${sheet.day}-${e.key}-${data.eco[e.key] ?? ''}`}
                          onBlur={(ev) => {
                            const raw = en(ev.target.value).replace(/[^0-9.\-]/g, '')
                            edit((d) => { if (raw === '' || isNaN(+raw)) delete d.eco[e.key]; else d.eco[e.key] = +raw })
                          }}
                        />
                        {e.unit && <span className="text-[12px] text-neutral-500 shrink-0">{e.unit}</span>}
                      </div>
                      <p className="text-[11.5px] text-neutral-400 mt-1">{sheet.prevEco[e.key] !== undefined ? `আগের হিসাব: ${bn(sheet.prevEco[e.key])}` : 'আগের হিসাব নেই'}</p>
                    </Card>
                  ))}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {tab === 'hl' && (
        <div className="space-y-4">
          <Card className="p-4">
            <p className="font-bold text-[14px] mb-2">দিনের সারকথা <span className="font-normal text-neutral-500 text-[12px]">(পাতার ওপরে দেখায়)</span></p>
            <textarea className={inputClass} rows={3} maxLength={600} value={data.summary} onChange={(e) => edit((d) => { d.summary = e.target.value })} placeholder="যেমন: গতকাল সড়কে প্রাণহানি আগের দিনের চেয়ে বেশি…" />
          </Card>
          <Card className="p-4">
            <p className="font-bold text-[14px] mb-2">উল্লেখযোগ্য ঘটনা <span className="font-normal text-neutral-500 text-[12px]">(সর্বোচ্চ ১৫টি)</span></p>
            {data.hl.map((h, i) => (
              <div key={i} className="grid grid-cols-1 sm:grid-cols-[1fr_10rem_1fr_auto] gap-2 mb-2">
                <input className={inputClass} maxLength={240} value={h.t} placeholder="এক লাইনে ঘটনা" onChange={(e) => edit((d) => { d.hl[i].t = e.target.value })} />
                <select className={inputClass} value={h.d} onChange={(e) => edit((d) => { d.hl[i].d = e.target.value })}>
                  <option value="">— জেলা —</option>
                  {divs.map((dv) => (
                    <optgroup key={dv} label={geo.divisions[dv]}>
                      {distsOf(dv).map((dk) => <option key={dk} value={dk}>{geo.districts[dk][0]}</option>)}
                    </optgroup>
                  ))}
                </select>
                <input className={inputClass} type="url" value={h.u} placeholder="খবরের লিংক (ঐচ্ছিক)" onChange={(e) => edit((d) => { d.hl[i].u = e.target.value })} />
                <Button variant="danger" size="sm" onClick={() => edit((d) => { d.hl.splice(i, 1) })}><IconX className="w-4 h-4" /></Button>
              </div>
            ))}
            {data.hl.length < 15 && <Button variant="secondary" size="sm" onClick={() => edit((d) => { d.hl.push({ t: '', u: '', d: '' }) })}><IconPlus className="w-4 h-4" /> ঘটনা যোগ</Button>}
          </Card>
          <Card className="p-4">
            <p className="font-bold text-[14px] mb-2">উৎস ও ভেতরের নোট <span className="font-normal text-neutral-500 text-[12px]">(পাঠক দেখেন না)</span></p>
            <textarea className={inputClass} rows={2} maxLength={600} value={data.sources} onChange={(e) => edit((d) => { d.sources = e.target.value })} />
          </Card>
        </div>
      )}

      {tab === 'news' && (
        <Card className="divide-y divide-neutral-100">
          <p className="px-4 py-2 text-[12.5px] text-neutral-500">এই দিনে প্রকাশিত খবর; শিরোনামের শব্দ দেখে সম্ভাব্য সূচক দেখানো হয়েছে — সংখ্যা নিজে যাচাই করুন।</p>
          {!sheet.news.length && <p className="px-4 py-6 text-center text-neutral-400 text-[13px]">এই দিনে কোনো খবর নেই।</p>}
          {sheet.news.map((n) => {
            const used = data.hl.some((h) => h.u === n.url)
            return (
              <div key={n.id} className="px-4 py-2.5 flex flex-wrap items-center gap-2">
                <span className="text-[11.5px] text-neutral-400 w-10">{bn(n.time)}</span>
                <a href={n.url} target="_blank" rel="noopener" className="flex-1 min-w-[12rem] text-[13.5px] font-medium text-neutral-900">{n.title}</a>
                {n.d && <Badge>{geo.districts[n.d]?.[0]}</Badge>}
                {n.tags.map((t) => { const i = sheet.indicators.find((x) => x.key === t); return i ? <Badge key={t} tone={i.tone === 'good' ? 'emerald' : i.tone === 'info' ? 'blue' : i.tone === 'warn' ? 'amber' : 'red'}>{i.short}</Badge> : null })}
                <Button size="sm" variant="secondary" disabled={used || data.hl.length >= 15} onClick={() => edit((d) => { d.hl.push({ t: n.title, u: n.url, d: n.d }) })}>{used ? 'যোগ হয়েছে' : 'ঘটনায় যোগ'}</Button>
              </div>
            )
          })}
        </Card>
      )}

      {/* sticky save bar */}
      <div className="fixed bottom-16 lg:bottom-4 left-0 right-0 lg:left-auto lg:right-6 z-30 px-4 lg:px-0 pointer-events-none">
        <div className="pointer-events-auto mx-auto lg:mx-0 max-w-md flex gap-2 rounded-2xl bg-white/95 backdrop-blur border border-neutral-200 shadow-lg p-2">
          <Button variant="secondary" className="flex-1" disabled={busy} onClick={() => save('draft')}>{sheet.exists && sheet.status === 'publish' ? 'খসড়ায় ফেরান' : 'খসড়া'}</Button>
          <Button className="flex-[2]" disabled={busy} onClick={() => save('publish')}>{busy ? 'সংরক্ষণ হচ্ছে…' : sheet.exists && sheet.status === 'publish' ? 'হালনাগাদ করুন' : isToday ? 'প্রকাশ (রাত ১২টায়)' : 'প্রকাশ করুন'}</Button>
        </div>
      </div>
    </div>
  )
}
