/**
 * 1.11.0 — লাইভ: every live post (লাইভ ইভেন্ট / অপরাধ তদন্ত লাইভ), running ones first, and starting a new one —
 * either a brand-new post (title, category, short intro) or an already-written post switched to live.
 * Each live post opens in its console (LiveConsole) where updates are added minute by minute.
 */
import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  AdminPostsApi,
  CategoriesApi,
  LiveApi,
  describeError,
  type Category,
  type LiveCandidate,
  type LivePostRow,
  type LiveType,
} from '../lib/api'
import CategoryPicker from '../components/CategoryPicker'
import { Badge, Banner, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, bnNumber, inputClass } from '../components/ui'
import { IconPlus, IconSearch, IconExternalLink } from '../components/Icon'

export function liveAgo(ts: number) {
  if (!ts) return ''
  const s = Math.max(0, Math.floor(Date.now() / 1000) - ts)
  if (s < 60) return 'এইমাত্র'
  const u: [number, string][] = [[86400, 'দিন'], [3600, 'ঘণ্টা'], [60, 'মিনিট']]
  for (const [sec, l] of u) if (s >= sec) return `${bnNumber(Math.floor(s / sec))} ${l} আগে`
  return 'এইমাত্র'
}

export const LIVE_STATUS: Record<string, { label: string; tone: 'red' | 'amber' | 'neutral' }> = {
  live: { label: 'লাইভ', tone: 'red' },
  paused: { label: 'বিরতি', tone: 'amber' },
  ended: { label: 'শেষ', tone: 'neutral' },
}
export const LIVE_TYPE: Record<LiveType, string> = { event: 'লাইভ ইভেন্ট', investigation: 'অপরাধ তদন্ত লাইভ' }

export default function Live() {
  const [items, setItems] = useState<LivePostRow[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [creating, setCreating] = useState(false)

  function load() {
    setLoading(true)
    LiveApi.list()
      .then((r) => setItems(r.items))
      .catch((e) => setError(describeError(e).message))
      .finally(() => setLoading(false))
  }
  useEffect(load, [])

  return (
    <div className="max-w-5xl">
      <PageHeader
        title="লাইভ"
        subtitle="খেলা, আন্দোলন বা যেকোনো চলমান ঘটনা, আর অপরাধ তদন্ত — একটি পোস্টেই প্রতি মুহূর্তের আপডেট। পাঠকের পাতায় আপডেট নিজে থেকেই চলে যায়।"
        actions={
          <Button onClick={() => setCreating(true)}>
            <IconPlus className="w-4 h-4" /> নতুন লাইভ শুরু
          </Button>
        }
      />
      {error && <Banner kind="error">{error}</Banner>}
      {loading ? (
        <Spinner className="mt-10" />
      ) : items.length === 0 ? (
        <EmptyState>এখনো কোনো লাইভ পোস্ট নেই। “নতুন লাইভ শুরু” চাপুন।</EmptyState>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          {items.map((it) => (
            <Link key={it.id} to={`/app/live/${it.id}`} className="block">
              <Card className="p-4 h-full hover:border-brand-500/60 transition">
                <div className="flex gap-3">
                  {it.thumb ? (
                    <img src={it.thumb} alt="" className="w-16 h-16 rounded-lg object-cover shrink-0" />
                  ) : (
                    <div className={`w-16 h-16 rounded-lg shrink-0 ${it.type === 'investigation' ? 'bg-[#0B2A6B]' : 'bg-brand-600'}`} />
                  )}
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-1.5 mb-1">
                      <LiveBadge status={it.status} />
                      <Badge tone="blue">{LIVE_TYPE[it.type]}</Badge>
                      {it.pending > 0 && <Badge tone="amber">{bnNumber(it.pending)}টি অনুমোদনের অপেক্ষায়</Badge>}
                      {it.post_status !== 'publish' && <Badge>প্রকাশিত নয়</Badge>}
                    </div>
                    <p className="font-semibold text-[14.5px] text-neutral-900 leading-snug line-clamp-2">{it.title}</p>
                    <p className="text-[12px] text-neutral-500 mt-1">
                      {bnNumber(it.count)}টি আপডেট{it.last ? ` · সর্বশেষ ${liveAgo(it.last)}` : ''}
                      {it.reporters ? ` · ${bnNumber(it.reporters)} জন সংবাদদাতা` : ''}
                    </p>
                  </div>
                </div>
              </Card>
            </Link>
          ))}
        </div>
      )}
      {creating && <NewLiveModal onClose={() => setCreating(false)} />}
    </div>
  )
}

export function LiveBadge({ status }: { status: string }) {
  const s = LIVE_STATUS[status] ?? LIVE_STATUS.ended
  const cls =
    s.tone === 'red' ? 'bg-red-600 text-white' : s.tone === 'amber' ? 'bg-amber-300 text-[#0B2A6B]' : 'bg-neutral-200 text-neutral-600'
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11.5px] font-bold ${cls}`}>
      <span className={`w-1.5 h-1.5 rounded-full bg-current ${status === 'live' ? 'animate-pulse' : ''}`} />
      {s.label}
    </span>
  )
}

function NewLiveModal({ onClose }: { onClose: () => void }) {
  const nav = useNavigate()
  const [mode, setMode] = useState<'new' | 'existing'>('new')
  const [type, setType] = useState<LiveType>('event')
  const [title, setTitle] = useState('')
  const [intro, setIntro] = useState('')
  const [categoryId, setCategoryId] = useState(0)
  const [categories, setCategories] = useState<Category[]>([])
  const [catLoading, setCatLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [cands, setCands] = useState<LiveCandidate[]>([])
  const [picked, setPicked] = useState(0)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    CategoriesApi.list()
      .then(setCategories)
      .catch(() => setCategories([]))
      .finally(() => setCatLoading(false))
  }, [])
  useEffect(() => {
    if (mode !== 'existing') return
    const t = setTimeout(() => {
      LiveApi.candidates(search.trim())
        .then((r) => setCands(r.items))
        .catch(() => setCands([]))
    }, 300)
    return () => clearTimeout(t)
  }, [mode, search])

  async function start() {
    setError('')
    setSaving(true)
    try {
      let id = picked
      if (mode === 'new') {
        if (!title.trim()) throw new Error('শিরোনাম দিন।')
        if (!categoryId) throw new Error('ক্যাটেগরি বেছে নিন।')
        const html = intro
          .split(/\n{2,}/)
          .map((p) => p.trim())
          .filter(Boolean)
          .map((p) => `<p>${p.replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c] as string).replace(/\n/g, '<br>')}</p>`)
          .join('')
        const post = await AdminPostsApi.create({ title: title.trim(), content: html || '<p></p>', status: 'publish', category_id: categoryId })
        id = post.id
      } else if (!id) {
        throw new Error('কোন পোস্টটি লাইভ হবে, বেছে নিন।')
      }
      await LiveApi.save(id, { type, status: 'live' })
      nav(`/app/live/${id}`)
    } catch (e) {
      setError(e instanceof Error && !('status' in e) ? e.message : describeError(e).message)
      setSaving(false)
    }
  }

  return (
    <Modal title="নতুন লাইভ শুরু" onClose={onClose}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-2">
          {(['event', 'investigation'] as LiveType[]).map((t) => (
            <button
              key={t}
              type="button"
              onClick={() => setType(t)}
              className={`text-left rounded-xl border p-3 transition ${type === t ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-600/20' : 'border-neutral-200 hover:border-neutral-300'}`}
            >
              <p className="font-semibold text-[14px] text-neutral-900">{LIVE_TYPE[t]}</p>
              <p className="text-[12px] text-neutral-500 mt-0.5 leading-snug">
                {t === 'event' ? 'খেলা, আন্দোলন, নির্বাচন — স্কোরবোর্ড ও মূল তথ্যসহ' : 'কেস স্ট্যাটাস, ভিক্টিম, যাদের নাম এসেছে, নথি ও প্রমাণ'}
              </p>
            </button>
          ))}
        </div>
        <div className="flex gap-1 rounded-lg bg-neutral-100 p-1 text-[13px] font-semibold">
          <button type="button" onClick={() => setMode('new')} className={`flex-1 rounded-md py-1.5 ${mode === 'new' ? 'bg-white shadow-sm text-neutral-900' : 'text-neutral-500'}`}>
            নতুন পোস্ট
          </button>
          <button type="button" onClick={() => setMode('existing')} className={`flex-1 rounded-md py-1.5 ${mode === 'existing' ? 'bg-white shadow-sm text-neutral-900' : 'text-neutral-500'}`}>
            আগের পোস্ট লাইভ করুন
          </button>
        </div>
        {mode === 'new' ? (
          <>
            <Field label="শিরোনাম" required>
              <input className={inputClass} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="যেমন: বাংলাদেশ বনাম ভারত — সরাসরি" />
            </Field>
            <Field label="ক্যাটেগরি" required>
              <CategoryPicker categories={categories} value={categoryId} onChange={setCategoryId} recentKey="nros_pub_recent_categories" loading={catLoading} />
            </Field>
            <Field label="শুরুর কথা / প্রেক্ষাপট">
              <textarea className={`${inputClass} min-h-[90px]`} value={intro} onChange={(e) => setIntro(e.target.value)} placeholder="ঘটনার সংক্ষিপ্ত প্রেক্ষাপট। পরে পুরো পোস্ট এডিটরেও বদলাতে পারবেন।" />
            </Field>
            <p className="text-[12px] text-neutral-500">পোস্টটি সঙ্গে সঙ্গে প্রকাশ হবে। ফিচার ছবি, এসইও ইত্যাদি পরে “লিখুন” পাতায় যোগ করা যাবে।</p>
          </>
        ) : (
          <div>
            <div className="relative mb-2">
              <IconSearch className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" />
              <input className={`${inputClass} pl-9`} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="শিরোনাম বা পোস্ট আইডি দিয়ে খুঁজুন" />
            </div>
            <div className="max-h-64 overflow-y-auto space-y-1">
              {cands.map((c) => (
                <button
                  key={c.id}
                  type="button"
                  disabled={c.is_live}
                  onClick={() => setPicked(c.id)}
                  className={`w-full text-left flex items-center gap-2 rounded-lg border px-2.5 py-2 text-[13px] ${picked === c.id ? 'border-brand-600 bg-brand-50' : 'border-neutral-200'} disabled:opacity-50`}
                >
                  {c.thumb ? <img src={c.thumb} alt="" className="w-9 h-9 rounded object-cover" /> : <span className="w-9 h-9 rounded bg-neutral-100" />}
                  <span className="flex-1 min-w-0 line-clamp-2">{c.title}</span>
                  {c.is_live && <Badge tone="red">লাইভ</Badge>}
                </button>
              ))}
              {cands.length === 0 && <p className="text-[12.5px] text-neutral-500 py-3 text-center">কিছু পাওয়া যায়নি।</p>}
            </div>
          </div>
        )}
        {error && <Banner kind="error">{error}</Banner>}
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>বাতিল</Button>
          <Button onClick={start} disabled={saving}>{saving ? 'শুরু হচ্ছে…' : 'লাইভ শুরু করুন'}</Button>
        </div>
      </div>
    </Modal>
  )
}

export function ViewOnSite({ href }: { href: string }) {
  return (
    <a href={href} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-[12.5px] font-semibold text-brand-600 hover:underline">
      সাইটে দেখুন <IconExternalLink className="w-3.5 h-3.5" />
    </a>
  )
}
