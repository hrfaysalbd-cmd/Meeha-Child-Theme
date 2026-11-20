/**
 * r48 — স্টোরেজ ম্যানেজার (publisher only).
 *
 * Meeha keeps finished videos on YouTube now; the server only holds a correspondent's upload until
 * the news is edited and its YouTube link is in. This page shows how much room the site uses, every
 * video file on the server with the news that use it, and lets the publisher delete the ones that are
 * safe to delete — the server re-checks each file and refuses any whose news still plays from it.
 * It also has one-click clean-ups for the server (half-finished uploads, expired transients, page and
 * object cache) and for this browser (the studio's kept renders, drafts and the remembered original).
 */
import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { StorageApi, type StorageOverview, type StorageVideo, type StorageVideoStatus, type StorageCleanTarget } from '../lib/api'
import { PageHeader, Card, Button, Banner, Spinner, ErrorRetry, Badge, Modal, bnNumber, bnDate } from '../components/ui'
import { IconTrash, IconRefresh, IconBrandYoutube, IconExternalLink, IconAlertTriangle, IconCheck } from '../components/Icon'
import { forgetOriginals } from '../lib/videoEdit/sessionFiles'

function fmtBytes(n: number | null | undefined) {
  if (n == null) return '—'
  if (n < 1024) return `${bnNumber(n)} B`
  const u = ['KB', 'MB', 'GB', 'TB']
  let v = n / 1024
  let i = 0
  while (v >= 1024 && i < u.length - 1) {
    v /= 1024
    i++
  }
  return `${bnNumber(v >= 100 ? Math.round(v) : Math.round(v * 10) / 10)} ${u[i]}`
}

/** Server messages come with Latin digits; show them in Bengali like the rest of the app. */
const bnDigits = (t: string) => t.replace(/[0-9]/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])

const STATUS: Record<StorageVideoStatus, { label: string; tone: 'emerald' | 'amber' | 'neutral' | 'blue'; hint: string }> = {
  on_youtube: { label: 'YouTube-এ আছে — মোছা যায়', tone: 'emerald', hint: 'যে খবরে এই ফাইল ছিল, সেটি এখন YouTube লিংক থেকে চলে।' },
  needs_link: { label: 'লিংক বাকি — মোছা যাবে না', tone: 'amber', hint: 'খবরটি এখনো সার্ভারের এই ফাইল থেকে চলে। আগে এডিট করে YouTube লিংক বসান।' },
  unused: { label: 'কোনো খবরে নেই', tone: 'neutral', hint: 'কোনো খবর এই ফাইল ব্যবহার করে না। আলাদা করে নিশ্চিত করে মোছা যায়।' },
  asset: { label: 'স্টুডিওর লাইব্রেরি', tone: 'blue', hint: 'বিজ্ঞাপন, ইন্ট্রো বা কোনো এডিটের অংশ — এখান থেকে মোছা যাবে না।' },
}
const ROLE: Record<string, string> = { main: 'খবরের ভিডিও', original: 'মূল ভিডিও', footage: 'ফুটেজ', previous: 'আগের ফাইল', hd: 'HD কপি' }

type Filter = 'all' | StorageVideoStatus
const FILTERS: [Filter, string][] = [
  ['all', 'সব'],
  ['on_youtube', 'মোছা যায়'],
  ['needs_link', 'লিংক বাকি'],
  ['unused', 'কোনো খবরে নেই'],
  ['asset', 'লাইব্রেরি'],
]

const CLEANS: { what: StorageCleanTarget; title: string; body: string }[] = [
  { what: 'upload_temp', title: 'অসমাপ্ত আপলোড মুছুন', body: 'মাঝপথে থেমে যাওয়া আপলোডের টুকরো (১ ঘণ্টার পুরনো)। চলমান আপলোডে হাত দেয় না।' },
  { what: 'transients', title: 'মেয়াদ পেরোনো অস্থায়ী তথ্য', body: 'ডাটাবেসে জমে থাকা পুরনো transient — সাইট একটু হালকা হয়।' },
  { what: 'page_cache', title: 'পাতার ক্যাশ খালি করুন', body: 'LiteSpeed / WP Rocket / W3TC / Super Cache থাকলে সব পাতা নতুন করে তৈরি হবে।' },
  { what: 'object_cache', title: 'অবজেক্ট ক্যাশ খালি করুন', body: 'Redis/Memcached থাকলে সেটি খালি হবে। কিছুক্ষণ সাইট সামান্য ধীর লাগতে পারে।' },
]

type Confirm = { ids: number[]; orphans: boolean; bytes: number; title: string }

export default function Storage() {
  const [data, setData] = useState<StorageOverview | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [loading, setLoading] = useState(false)
  const [filter, setFilter] = useState<Filter>('all')
  const [sort, setSort] = useState<'size' | 'date'>('size')
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [confirm, setConfirm] = useState<Confirm | null>(null)
  const [sure, setSure] = useState(false)
  const [busy, setBusy] = useState('')
  const [browser, setBrowser] = useState<{ usage: number; quota: number } | null>(null)

  const load = (refresh = false) => {
    setError('')
    setLoading(true)
    StorageApi.overview(refresh)
      .then((d) => {
        setData(d)
        setPicked(new Set())
      })
      .catch((e) => setError(e instanceof Error ? e.message : 'লোড হয়নি।'))
      .finally(() => setLoading(false))
  }
  const measureBrowser = () => {
    navigator.storage
      ?.estimate?.()
      .then((e) => setBrowser({ usage: e.usage ?? 0, quota: e.quota ?? 0 }))
      .catch(() => {})
  }
  useEffect(() => {
    load()
    measureBrowser()
  }, [])

  const rows = useMemo(() => {
    const list = (data?.videos ?? []).filter((v) => filter === 'all' || v.status === filter)
    return [...list].sort((a, b) => (sort === 'size' ? b.size - a.size : b.date.localeCompare(a.date)))
  }, [data, filter, sort])

  if (error && !data) return <ErrorRetry message={error} onRetry={() => load()} />
  if (!data) return <Spinner className="mt-16 mx-auto" />

  const deletable = (v: StorageVideo) => v.status === 'on_youtube' || v.status === 'unused'
  const pickedRows = data.videos.filter((v) => picked.has(v.id))
  const toggle = (id: number) =>
    setPicked((s) => {
      const n = new Set(s)
      if (n.has(id)) n.delete(id)
      else n.add(id)
      return n
    })

  const ask = (list: StorageVideo[], title: string) => {
    const ok = list.filter(deletable)
    if (!ok.length) return
    setSure(false)
    setConfirm({ ids: ok.map((v) => v.id), orphans: ok.some((v) => v.status === 'unused'), bytes: ok.reduce((s, v) => s + v.size, 0), title })
  }

  const runDelete = async () => {
    if (!confirm) return
    setBusy('delete')
    setError('')
    setNotice('')
    try {
      const r = await StorageApi.deleteVideos(confirm.ids, confirm.orphans)
      const skip = r.skipped.length ? ` ${bnNumber(r.skipped.length)} টি রাখা হলো: ${r.skipped.map((s) => s.why).filter((x, i, a) => a.indexOf(x) === i).join('; ')}।` : ''
      setNotice(`${bnNumber(r.deleted.length)} টি ভিডিও ফাইল মোছা হয়েছে — ${fmtBytes(r.freed)} জায়গা খালি হলো।${skip}`)
      setConfirm(null)
      load(true)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'মোছা যায়নি।')
    } finally {
      setBusy('')
    }
  }

  const runClean = async (what: StorageCleanTarget) => {
    setBusy(what)
    setError('')
    setNotice('')
    try {
      const r = await StorageApi.clean(what)
      setNotice(bnDigits(r.message))
      if (what === 'upload_temp' || what === 'transients') load(true)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'পরিষ্কার করা যায়নি।')
    } finally {
      setBusy('')
    }
  }

  const clearStudio = async () => {
    if (!window.confirm('এই ব্রাউজারে রাখা স্টুডিওর রেন্ডার, এডিটের খসড়া ও ডাউনলোড করা মূল ভিডিওর কপি মুছে যাবে। কম্পিউটারে সেভ করা ফাইলে হাত দেবে না। মুছবেন?')) return
    setBusy('studio')
    let drafts = 0
    try {
      forgetOriginals()
      for (const k of Object.keys(localStorage)) {
        if (k.startsWith('meeha-studio:v1:')) {
          localStorage.removeItem(k)
          drafts++
        }
      }
      await new Promise<void>((res) => {
        const req = indexedDB.deleteDatabase('meeha-video-studio')
        req.onsuccess = req.onerror = req.onblocked = () => res()
      })
      setNotice(`স্টুডিওর ব্রাউজার-ক্যাশ খালি হয়েছে (${bnNumber(drafts)} টি খসড়াসহ)।`)
    } catch {
      setError('ব্রাউজারের ক্যাশ পুরো খালি করা যায়নি।')
    } finally {
      setBusy('')
      measureBrowser()
    }
  }

  const clearAppCache = async () => {
    setBusy('app')
    try {
      if ('caches' in window) for (const k of await caches.keys()) await caches.delete(k)
    } finally {
      location.reload()
    }
  }

  const d = data.disk
  const usedPct = d.total && d.free != null ? Math.round(((d.total - d.free) / d.total) * 100) : null

  return (
    <div className="space-y-5" data-testid="storage-page">
      <PageHeader
        title="স্টোরেজ ম্যানেজার"
        subtitle="সার্ভারে কত জায়গা লাগছে, কোন ভিডিও YouTube-এ চলে গেছে বলে মোছা যায়, আর এক ক্লিকে ক্যাশ পরিষ্কার।"
        actions={
          <Button variant="secondary" size="sm" onClick={() => load(true)} disabled={loading} data-testid="storage-refresh">
            <IconRefresh className="w-3.5 h-3.5" /> {loading ? 'গোনা হচ্ছে…' : 'আবার গুনুন'}
          </Button>
        }
      />
      {notice && <Banner kind="success">{notice}</Banner>}
      {error && <Banner kind="error">{error}</Banner>}

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="storage-cards">
        <Card className="p-4">
          <p className="text-[12.5px] text-neutral-500">আপলোড ফোল্ডার (ছবি + ভিডিও)</p>
          <p className="text-[20px] font-bold">{fmtBytes(d.uploads.bytes)}</p>
          <p className="text-[12px] text-neutral-500">{bnNumber(d.uploads.files)} টি ফাইল{d.uploads.partial ? ' (আংশিক গোনা)' : ''}</p>
        </Card>
        <Card className="p-4">
          <p className="text-[12.5px] text-neutral-500">ভিডিও ফাইল</p>
          <p className="text-[20px] font-bold">{fmtBytes(data.sum.all)}</p>
          <p className="text-[12px] text-neutral-500">{bnNumber(data.count.all)} টি ভিডিও</p>
        </Card>
        <Card className="p-4 border-emerald-200">
          <p className="text-[12.5px] text-emerald-700">YouTube-এ চলে গেছে — মোছা যায়</p>
          <p className="text-[20px] font-bold text-emerald-700">{fmtBytes(data.sum.on_youtube)}</p>
          <p className="text-[12px] text-neutral-500">{bnNumber(data.count.on_youtube)} টি ভিডিও</p>
        </Card>
        <Card className="p-4">
          <p className="text-[12.5px] text-neutral-500">সার্ভারের ফাঁকা জায়গা</p>
          <p className="text-[20px] font-bold">{fmtBytes(d.free)}</p>
          <p className="text-[12px] text-neutral-500">
            {d.total ? `মোট ${fmtBytes(d.total)} · ${bnNumber(usedPct ?? 0)}% ভরা` : 'হোস্টিং এই তথ্য দেয় না'}
          </p>
          {usedPct != null && (
            <div className="mt-2 h-1.5 rounded-full bg-neutral-100 overflow-hidden">
              <div className={`h-full ${usedPct > 85 ? 'bg-red-500' : usedPct > 65 ? 'bg-amber-500' : 'bg-emerald-500'}`} style={{ width: `${usedPct}%` }} />
            </div>
          )}
        </Card>
      </div>
      <p className="text-[11.5px] text-neutral-400 -mt-2">
        ফোল্ডারের মাপ গোনা হয়েছে {bnDate(d.counted_at)} {bnDigits(d.counted_at.slice(11, 16))}-এ (৩০ মিনিট পরপর নতুন করে গোনা হয়)।
        {d.upload_temp.bytes > 0 && ` অসমাপ্ত আপলোডে ${fmtBytes(d.upload_temp.bytes)}।`}
      </p>

      {data.count.on_youtube > 0 && (
        <Card className="p-4 flex flex-wrap items-center gap-3 border-emerald-200 bg-emerald-50/40">
          <IconBrandYoutube className="w-6 h-6 text-red-600" />
          <p className="flex-1 min-w-[220px] text-[13px]">
            <b>{bnNumber(data.count.on_youtube)} টি ভিডিও</b>র খবর এখন YouTube লিংক থেকে চলে — সার্ভারে এগুলোর আর দরকার নেই।
          </p>
          <Button
            variant="destructive"
            onClick={() => ask(data.videos.filter((v) => v.status === 'on_youtube'), 'YouTube-এ চলে যাওয়া সব ভিডিও মুছবেন?')}
            data-testid="storage-delete-youtube"
          >
            <IconTrash className="w-4 h-4" /> সব মুছুন ({fmtBytes(data.sum.on_youtube)})
          </Button>
        </Card>
      )}

      <Card className="p-0 overflow-hidden">
        <div className="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-neutral-100">
          <p className="text-[13.5px] font-semibold mr-2">সার্ভারের ভিডিও</p>
          {FILTERS.map(([f, l]) => (
            <button
              key={f}
              type="button"
              onClick={() => setFilter(f)}
              data-testid={`storage-filter-${f}`}
              className={`rounded-full border px-3 py-1 text-[12.5px] ${filter === f ? 'border-brand-600 bg-brand-50 font-semibold' : 'border-neutral-200'}`}
            >
              {l} · {bnNumber(data.count[f])}
            </button>
          ))}
          <span className="flex-1" />
          <select className="rounded-lg border border-neutral-200 px-2 py-1 text-[12.5px]" value={sort} onChange={(e) => setSort(e.target.value as 'size' | 'date')}>
            <option value="size">বড় ফাইল আগে</option>
            <option value="date">নতুন আগে</option>
          </select>
          {pickedRows.length > 0 && (
            <Button size="sm" variant="danger" onClick={() => ask(pickedRows, `বাছাই করা ${bnNumber(pickedRows.length)} টি ভিডিও মুছবেন?`)} data-testid="storage-delete-picked">
              <IconTrash className="w-3.5 h-3.5" /> বাছাইগুলো মুছুন
            </Button>
          )}
        </div>
        {filter !== 'all' && <p className="px-4 pt-3 text-[12px] text-neutral-500">{STATUS[filter].hint}</p>}
        <ul className="divide-y divide-neutral-100" data-testid="storage-list">
          {rows.map((v) => {
            const st = STATUS[v.status]
            return (
              <li key={v.id} className="px-4 py-3 flex items-start gap-3" data-testid="storage-row" data-status={v.status}>
                <input
                  type="checkbox"
                  className="mt-1"
                  disabled={!deletable(v)}
                  checked={picked.has(v.id)}
                  onChange={() => toggle(v.id)}
                  title={deletable(v) ? 'বাছাই' : st.hint}
                />
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <a href={v.url} target="_blank" rel="noreferrer" className="text-[13px] font-medium truncate max-w-[420px] hover:underline">
                      {v.name}
                    </a>
                    <Badge tone={st.tone}>{st.label}</Badge>
                    {v.missing && <Badge tone="red">ফাইল নেই</Badge>}
                  </div>
                  <p className="text-[11.5px] text-neutral-500 mt-0.5">
                    {fmtBytes(v.size)} · {bnDate(v.date)}
                    {v.by && ` · ${v.by}`}
                  </p>
                  {v.news.length > 0 && (
                    <ul className="mt-1 space-y-0.5">
                      {v.news.map((n) => (
                        <li key={n.id} className="text-[12px] flex flex-wrap items-center gap-1.5">
                          {n.source === 'youtube' && n.youtube ? (
                            <IconCheck className="w-3.5 h-3.5 text-emerald-600" />
                          ) : (
                            <IconAlertTriangle className="w-3.5 h-3.5 text-amber-500" />
                          )}
                          <Link to={`/app/videos/${n.id}`} className="hover:underline truncate max-w-[360px]">
                            {n.title || `খবর #${bnNumber(n.id)}`}
                          </Link>
                          <span className="text-neutral-400">({n.roles.map((r) => ROLE[r] ?? r).join(', ')})</span>
                          {n.youtube && (
                            <a href={n.youtube} target="_blank" rel="noreferrer" className="text-red-600 inline-flex items-center gap-0.5">
                              YouTube <IconExternalLink className="w-3 h-3" />
                            </a>
                          )}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
                {deletable(v) && (
                  <Button size="sm" variant="danger" onClick={() => ask([v], `“${v.name}” মুছবেন?`)} data-testid="storage-delete-one">
                    <IconTrash className="w-3.5 h-3.5" /> মুছুন
                  </Button>
                )}
              </li>
            )
          })}
          {!rows.length && <li className="px-4 py-6 text-center text-[12.5px] text-neutral-400">এই ঘরে কোনো ভিডিও নেই।</li>}
        </ul>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="p-4">
          <p className="text-[13.5px] font-semibold mb-3">সার্ভার পরিষ্কার</p>
          <ul className="space-y-3">
            {CLEANS.map((c) => (
              <li key={c.what} className="flex items-start gap-3">
                <div className="flex-1 min-w-0">
                  <p className="text-[13px] font-medium">{c.title}</p>
                  <p className="text-[12px] text-neutral-500">{c.body}</p>
                </div>
                <Button size="sm" variant="secondary" onClick={() => void runClean(c.what)} disabled={!!busy} data-testid={`storage-clean-${c.what}`}>
                  {busy === c.what ? 'চলছে…' : 'চালান'}
                </Button>
              </li>
            ))}
          </ul>
        </Card>
        <Card className="p-4">
          <p className="text-[13.5px] font-semibold mb-1">এই কম্পিউটারের ব্রাউজার</p>
          <p className="text-[12px] text-neutral-500 mb-3">
            {browser
              ? `পাবলিশার অ্যাপ এই ব্রাউজারে ${fmtBytes(browser.usage)} জায়গা নিচ্ছে (সীমা প্রায় ${fmtBytes(browser.quota)})।`
              : 'ব্রাউজার জায়গার হিসাব দিচ্ছে না।'}
          </p>
          <ul className="space-y-3">
            <li className="flex items-start gap-3">
              <div className="flex-1 min-w-0">
                <p className="text-[13px] font-medium">স্টুডিওর ক্যাশ মুছুন</p>
                <p className="text-[12px] text-neutral-500">ব্রাউজারে রাখা রেন্ডার, এডিটের খসড়া আর মূল ভিডিওর কপি। কম্পিউটারের Downloads-এ সেভ করা ফাইল থাকবে।</p>
              </div>
              <Button size="sm" variant="secondary" onClick={() => void clearStudio()} disabled={!!busy} data-testid="storage-clear-studio">
                {busy === 'studio' ? 'চলছে…' : 'মুছুন'}
              </Button>
            </li>
            <li className="flex items-start gap-3">
              <div className="flex-1 min-w-0">
                <p className="text-[13px] font-medium">অ্যাপের ক্যাশ খালি করে নতুন করে খুলুন</p>
                <p className="text-[12px] text-neutral-500">নতুন ভার্সন আপলোডের পরও পুরনোটা দেখালে এটি চাপুন। লগইন থাকবে।</p>
              </div>
              <Button size="sm" variant="secondary" onClick={() => void clearAppCache()} disabled={!!busy}>
                {busy === 'app' ? 'চলছে…' : 'চালান'}
              </Button>
            </li>
          </ul>
        </Card>
      </div>

      {confirm && (
        <Modal title={confirm.title} onClose={() => setConfirm(null)}>
          <div className="space-y-3 text-[13px]" data-testid="storage-confirm">
            <p>
              {bnNumber(confirm.ids.length)} টি ফাইল সার্ভার থেকে চিরতরে মুছে যাবে, {fmtBytes(confirm.bytes)} জায়গা খালি হবে। মোছার আগে সার্ভার আবার দেখে নেবে — যে খবর এখনো
              এই ফাইল থেকে চলে, তার ফাইল মোছা হবে না।
            </p>
            {confirm.orphans && (
              <label className="flex items-start gap-2 rounded-lg bg-amber-50 p-2.5 text-amber-800">
                <input type="checkbox" className="mt-0.5" checked={sure} onChange={(e) => setSure(e.target.checked)} data-testid="storage-confirm-orphans" />
                এর মধ্যে এমন ফাইল আছে যা কোনো খবরে নেই (হয়তো বাতিল হওয়া বা পুরনো আপলোড)। আমি নিশ্চিত, এগুলোরও আর দরকার নেই।
              </label>
            )}
            <div className="flex justify-end gap-2 pt-1">
              <Button variant="secondary" onClick={() => setConfirm(null)}>
                না
              </Button>
              <Button variant="destructive" onClick={() => void runDelete()} disabled={busy === 'delete' || (confirm.orphans && !sure)} data-testid="storage-confirm-yes">
                <IconTrash className="w-4 h-4" /> {busy === 'delete' ? 'মোছা হচ্ছে…' : 'হ্যাঁ, মুছুন'}
              </Button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  )
}
