/**
 * r47 — নিউজ এলার্ট: what a visitor sees when they allow (or are asked to allow) web push,
 * managed from the publisher app — the ask-card's words and timing, the notification icon and
 * top line, which news goes out automatically (all / breaking / manual) with an hourly cap and
 * category filter, plus the subscriber count and a manual "এলার্ট পাঠান" for any published post.
 */
import { useEffect, useState } from 'react'
import { AlertsApi, MediaApi, PostsApi, type AlertsOverview, type AlertSettings, type AdminPostListRow } from '../lib/api'
import { PageHeader, Card, Button, Banner, Spinner, ErrorRetry, Badge, Field, inputClass, bnNumber, bnDate } from '../components/ui'
import { IconBolt, IconPhoto } from '../components/Icon'

export default function Alerts() {
  const [data, setData] = useState<AlertsOverview | null>(null)
  const [draft, setDraft] = useState<AlertSettings | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const [posts, setPosts] = useState<AdminPostListRow[]>([])
  const [sending, setSending] = useState(0)

  const load = () => {
    setError('')
    AlertsApi.get()
      .then((d) => {
        setData(d)
        setDraft(d.settings)
      })
      .catch((e) => setError(e instanceof Error ? e.message : 'লোড হয়নি।'))
    PostsApi.listAll('all', 1)
      .then((r) => setPosts(r.items.filter((p) => p.status === 'publish').slice(0, 8)))
      .catch(() => {})
  }
  useEffect(load, [])

  if (error && !data) return <ErrorRetry message={error} onRetry={load} />
  if (!data || !draft) return <Spinner className="mt-16 mx-auto" />

  const set = <K extends keyof AlertSettings>(k: K, v: AlertSettings[K]) => setDraft((d) => (d ? { ...d, [k]: v } : d))

  const pickIcon = () => {
    const input = document.createElement('input')
    input.type = 'file'
    input.accept = 'image/png,image/jpeg,image/webp'
    input.onchange = async () => {
      const f = input.files?.[0]
      if (!f) return
      try {
        const up = await MediaApi.upload(f, { alt: 'নোটিফিকেশন আইকন' })
        setDraft((d) => (d ? { ...d, icon: up.id, icon_url: up.url } : d))
      } catch (e) {
        setError(e instanceof Error ? e.message : 'আইকন আপলোড হয়নি।')
      }
    }
    input.click()
  }

  const save = async () => {
    setSaving(true)
    setError('')
    setNotice('')
    try {
      await AlertsApi.save(draft)
      setNotice('সংরক্ষণ হয়েছে — ওয়েবসাইটে এখন থেকে এই সেটিংসই চলবে।')
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সংরক্ষণ হয়নি।')
    } finally {
      setSaving(false)
    }
  }

  const sendAlert = async (p: AdminPostListRow) => {
    if (!window.confirm(`“${p.title}” — সব গ্রাহকের কাছে নিউজ এলার্ট যাবে। পাঠাবেন?`)) return
    setSending(p.id)
    setError('')
    try {
      const r = await AlertsApi.send(p.id)
      setNotice(`এলার্ট পাঠানো শুরু হয়েছে (${bnNumber(r.subscribers)} জন গ্রাহক)।`)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'পাঠানো যায়নি।')
    } finally {
      setSending(0)
    }
  }

  return (
    <div className="space-y-5" data-testid="alerts-page">
      <PageHeader
        title="নিউজ এলার্ট"
        subtitle="ভিজিটর অ্যালাউ দিলে কী দেখবে — কার্ডের ভাষা, আইকন, কোন খবরে এলার্ট যাবে — সবই এখান থেকে।"
        actions={
          <Badge tone={data.supported ? 'emerald' : 'red'}>
            {data.supported ? `${bnNumber(data.subscribers)} জন গ্রাহক` : 'পুশ সার্ভার প্রস্তুত নয়'}
          </Badge>
        }
      />
      {notice && <Banner kind="success">{notice}</Banner>}
      {error && <Banner kind="error">{error}</Banner>}

      <Card>
        <p className="text-[13.5px] font-semibold mb-3">“পেতে চান?” কার্ড</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="flex items-center gap-2 text-[13px] font-medium sm:col-span-2">
            <input type="checkbox" checked={draft.enabled} onChange={(e) => set('enabled', e.target.checked)} />
            নিউজ এলার্ট চালু (ভিজিটরকে কার্ড দেখাও)
          </label>
          <Field label="প্রশ্ন">
            <input className={inputClass} value={draft.question} onChange={(e) => set('question', e.target.value)} />
          </Field>
          <Field label="সম্মতির পর ধন্যবাদ">
            <input className={inputClass} value={draft.thanks} onChange={(e) => set('thanks', e.target.value)} />
          </Field>
          <Field label="সম্মতির বোতাম">
            <input className={inputClass} value={draft.yes} onChange={(e) => set('yes', e.target.value)} />
          </Field>
          <Field label="অসম্মতির বোতাম">
            <input className={inputClass} value={draft.no} onChange={(e) => set('no', e.target.value)} />
          </Field>
          <Field label="পাতা খোলার কত সেকেন্ড পর কার্ড">
            <input type="number" min={0} max={120} className={inputClass} value={draft.delay} onChange={(e) => set('delay', Number(e.target.value))} />
          </Field>
          <Field label="“না” বললে আবার জিজ্ঞেস (দিন)">
            <input type="number" min={1} max={365} className={inputClass} value={draft.snooze} onChange={(e) => set('snooze', Number(e.target.value))} />
          </Field>
        </div>
      </Card>

      <Card>
        <p className="text-[13.5px] font-semibold mb-3">নোটিফিকেশনে যা দেখাবে</p>
        <div className="flex flex-wrap items-start gap-4">
          <div>
            <p className="text-[12px] text-neutral-600 mb-1">আইকন (বর্গাকার, ১৯২×১৯২+)</p>
            {draft.icon_url ? (
              <img src={draft.icon_url} alt="" className="w-16 h-16 rounded-xl border border-neutral-200 object-cover" />
            ) : (
              <div className="w-16 h-16 rounded-xl border border-dashed border-neutral-300 grid place-items-center text-neutral-400 text-[11px]">নেই</div>
            )}
            <Button size="sm" variant="secondary" className="mt-1.5" onClick={pickIcon}>
              <IconPhoto className="w-3.5 h-3.5" /> বদলান
            </Button>
          </div>
          <div className="flex-1 min-w-[220px]">
            <Field label="নোটিফিকেশনের ওপরের লাইন">
              <input className={inputClass} value={draft.title_prefix} onChange={(e) => set('title_prefix', e.target.value)} />
            </Field>
            {/* A live mock of what the visitor's notification will look like. */}
            <div className="mt-3 rounded-xl border border-neutral-200 bg-neutral-50 p-3 flex gap-3 max-w-sm">
              {draft.icon_url ? <img src={draft.icon_url} alt="" className="w-10 h-10 rounded-lg object-cover" /> : <IconBolt className="w-10 h-10 text-neutral-300" />}
              <div className="min-w-0">
                <p className="text-[12.5px] font-semibold">{draft.title_prefix || 'মিহা নিউজ'}</p>
                <p className="text-[12px] text-neutral-600 truncate">ব্রেকিং: খবরের শিরোনাম এখানে দেখাবে</p>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <Card>
        <p className="text-[13.5px] font-semibold mb-3">কোন খবরে এলার্ট যাবে</p>
        <div className="space-y-2 text-[13px]">
          {(
            [
              ['all', 'প্রতিটি নতুন খবর'],
              ['breaking', 'শুধু ব্রেকিং চিহ্ন দেওয়া খবর'],
              ['off', 'স্বয়ংক্রিয় নয় — শুধু হাতে পাঠানো'],
            ] as const
          ).map(([v, l]) => (
            <label key={v} className="flex items-center gap-2">
              <input type="radio" name="auto" checked={draft.auto === v} onChange={() => set('auto', v)} /> {l}
            </label>
          ))}
        </div>
        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          <Field label="স্বয়ংক্রিয় এলার্ট ঘণ্টায় সর্বোচ্চ">
            <input type="number" min={1} max={30} className={inputClass} value={draft.hourly_cap} onChange={(e) => set('hourly_cap', Number(e.target.value))} />
          </Field>
        </div>
        <p className="text-[12px] text-neutral-600 mt-3 mb-1 font-medium">শুধু এই বিভাগগুলোর খবর (কিছু না বাছলে সব)</p>
        <div className="flex flex-wrap gap-2">
          {data.categories_all.map((c) => {
            const onIt = draft.categories.includes(c.id)
            return (
              <button
                key={c.id}
                type="button"
                className={`rounded-full border px-3 py-1 text-[12.5px] ${onIt ? 'border-brand-600 bg-brand-50 font-semibold' : 'border-neutral-200'}`}
                onClick={() => set('categories', onIt ? draft.categories.filter((x) => x !== c.id) : [...draft.categories, c.id])}
              >
                {c.name}
              </button>
            )
          })}
        </div>
      </Card>

      <Button onClick={() => void save()} disabled={saving} data-testid="alerts-save">
        {saving ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন'}
      </Button>

      <Card>
        <p className="text-[13.5px] font-semibold mb-1">হাতে এলার্ট পাঠান</p>
        <p className="text-[12px] text-neutral-500 mb-3">
          সাম্প্রতিক প্রকাশিত খবর — “পাঠান” চাপলেই সব গ্রাহকের কাছে যাবে (হাতে পাঠানোয় ঘণ্টার সীমা নেই)।
          {data.last_send && (
            <>
              {' '}
              সর্বশেষ: “{data.last_send.title}” — {bnNumber(data.last_send.sent)} জনকে{data.last_send.started ? `, ${bnDate(new Date(data.last_send.started * 1000).toISOString())}` : ''}।
            </>
          )}
        </p>
        <ul className="divide-y divide-neutral-100">
          {posts.map((p) => (
            <li key={p.id} className="py-2 flex items-center gap-3">
              <span className="min-w-0 flex-1 truncate text-[13px]">{p.title}</span>
              <Button size="sm" variant="secondary" onClick={() => void sendAlert(p)} disabled={sending !== 0}>
                <IconBolt className="w-3.5 h-3.5" />
                {sending === p.id ? 'যাচ্ছে…' : 'পাঠান'}
              </Button>
            </li>
          ))}
          {!posts.length && <li className="py-2 text-[12.5px] text-neutral-400">প্রকাশিত খবর নেই।</li>}
        </ul>
      </Card>
    </div>
  )
}
