/**
 * r47 — শিক্ষা/ক্যাম্পাস: the campus run from the publisher app. At-a-glance stats and আজকের
 * নির্বাচিত পাঠ, the whole lesson pool with inline publish/draft, a lesson writer (publishes
 * directly, per the publisher's standing instruction), date-pinning a lesson, the CV design
 * list, and one button that installs the r47 content pack (১৫ পাঠ + ৫ সিভি ডিজাইন).
 */
import { useEffect, useMemo, useState } from 'react'
import { CampusApi, type CampusOverview, type CampusLesson, type CampusLessonInput } from '../lib/api'
import { PageHeader, Card, KpiCard, Button, Banner, Spinner, ErrorRetry, Badge, Field, inputClass, bnNumber, Modal } from '../components/ui'
import { IconPlus, IconPencil, IconTrash, IconSparkles, IconExternalLink } from '../components/Icon'

const EMPTY: CampusLessonInput = { title: '', excerpt: '', content: '', subject: '', minutes: 6, order: 50, status: 'publish', feature_date: '' }

function LessonForm({
  initial,
  subjects,
  onDone,
  onClose,
}: {
  initial: CampusLessonInput & { id?: number }
  subjects: { slug: string; name: string }[]
  onDone: () => void
  onClose: () => void
}) {
  const [draft, setDraft] = useState(initial)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')
  const set = <K extends keyof CampusLessonInput>(k: K, v: CampusLessonInput[K]) => setDraft((d) => ({ ...d, [k]: v }))

  const save = async () => {
    if (!draft.title?.trim()) {
      setErr('শিরোনাম লাগবে।')
      return
    }
    setSaving(true)
    setErr('')
    try {
      const body = { ...draft }
      // Editing loads without the body (the list is light); an empty editor must not wipe it.
      if (initial.id && !body.content?.trim()) delete body.content
      if (initial.id) await CampusApi.updateLesson(initial.id, body)
      else await CampusApi.createLesson(body)
      onDone()
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'সংরক্ষণ হয়নি।')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={initial.id ? 'পাঠ সম্পাদনা' : 'নতুন পাঠ'} onClose={onClose}>
      <div className="space-y-3">
        <Field label="শিরোনাম" required>
          <input className={inputClass} value={draft.title ?? ''} onChange={(e) => set('title', e.target.value)} />
        </Field>
        <Field label="সারাংশ (এক-দুই লাইন)">
          <textarea className={inputClass} rows={2} value={draft.excerpt ?? ''} onChange={(e) => set('excerpt', e.target.value)} />
        </Field>
        <Field label="পাঠের মূল লেখা (HTML চলবে: <h2>, <ul>, <p>)">
          <textarea className={inputClass + ' font-mono text-[12px]'} rows={10} value={draft.content ?? ''} onChange={(e) => set('content', e.target.value)} />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="বিষয়">
            <select className={inputClass} value={draft.subject ?? ''} onChange={(e) => set('subject', e.target.value)}>
              <option value="">— নেই —</option>
              {subjects.map((s) => (
                <option key={s.slug} value={s.slug}>
                  {s.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="পড়তে লাগবে (মিনিট)">
            <input type="number" min={1} className={inputClass} value={draft.minutes ?? 6} onChange={(e) => set('minutes', Number(e.target.value))} />
          </Field>
          <Field label="ক্রম">
            <input type="number" className={inputClass} value={draft.order ?? 50} onChange={(e) => set('order', Number(e.target.value))} />
          </Field>
          <Field label="নির্দিষ্ট দিনের পাঠ (ঐচ্ছিক)">
            <input type="date" className={inputClass} value={draft.feature_date ?? ''} onChange={(e) => set('feature_date', e.target.value)} />
          </Field>
        </div>
        <label className="flex items-center gap-2 text-[13px]">
          <input type="checkbox" checked={draft.status !== 'draft'} onChange={(e) => set('status', e.target.checked ? 'publish' : 'draft')} />
          এখনই প্রকাশ করুন
        </label>
        {err && <Banner kind="error">{err}</Banner>}
        <div className="flex gap-2">
          <Button onClick={() => void save()} disabled={saving}>
            {saving ? 'সংরক্ষণ হচ্ছে…' : initial.id ? 'সংরক্ষণ করুন' : 'পাঠ যোগ করুন'}
          </Button>
          <Button variant="secondary" onClick={onClose}>
            বাতিল
          </Button>
        </div>
      </div>
    </Modal>
  )
}

export default function Campus() {
  const [data, setData] = useState<CampusOverview | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [editing, setEditing] = useState<(CampusLessonInput & { id?: number }) | null>(null)
  const [busyId, setBusyId] = useState(0)
  const [seeding, setSeeding] = useState(false)
  const [subjectFilter, setSubjectFilter] = useState('')

  const load = () => {
    setError('')
    CampusApi.overview()
      .then(setData)
      .catch((e) => setError(e instanceof Error ? e.message : 'লোড হয়নি।'))
  }
  useEffect(load, [])

  const lessons = useMemo(
    () => (data?.lessons ?? []).filter((l) => !subjectFilter || l.subject === subjectFilter),
    [data, subjectFilter],
  )

  const toggle = async (l: CampusLesson) => {
    setBusyId(l.id)
    try {
      await CampusApi.updateLesson(l.id, { status: l.status === 'publish' ? 'draft' : 'publish' })
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'হয়নি।')
    } finally {
      setBusyId(0)
    }
  }

  const remove = async (l: CampusLesson) => {
    if (!window.confirm(`“${l.title}” মুছে ফেলবেন? (ট্র্যাশে যাবে)`)) return
    setBusyId(l.id)
    try {
      await CampusApi.deleteLesson(l.id)
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'হয়নি।')
    } finally {
      setBusyId(0)
    }
  }

  const seed = async () => {
    setSeeding(true)
    setNotice('')
    try {
      const r = await CampusApi.seed()
      setNotice(`কন্টেন্ট প্যাক বসেছে: নতুন ${bnNumber(r.lessons)}টি পাঠ, ${bnNumber(r.cv)}টি সিভি ডিজাইন। (আগে থেকেই থাকলে সংখ্যা ০ দেখাবে।)`)
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'হয়নি।')
    } finally {
      setSeeding(false)
    }
  }

  if (error && !data) return <ErrorRetry message={error} onRetry={load} />
  if (!data) return <Spinner className="mt-16 mx-auto" />

  return (
    <div className="space-y-5" data-testid="campus-page">
      <PageHeader
        title="শিক্ষা / ক্যাম্পাস"
        subtitle="আজকের নির্বাচিত পাঠ, পাঠের ভান্ডার, সিভি টেমপ্লেট — সব এখান থেকে।"
        actions={
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => void seed()} disabled={seeding}>
              <IconSparkles className="w-4 h-4" />
              {seeding ? 'বসছে…' : 'কন্টেন্ট প্যাক ইনস্টল'}
            </Button>
            <Button onClick={() => setEditing({ ...EMPTY })} data-testid="campus-new-lesson">
              <IconPlus className="w-4 h-4" /> নতুন পাঠ
            </Button>
          </div>
        }
      />
      {notice && <Banner kind="success">{notice}</Banner>}
      {error && <Banner kind="error">{error}</Banner>}

      <div className="grid gap-3 grid-cols-2 lg:grid-cols-4">
        <KpiCard label="প্রকাশিত পাঠ" value={bnNumber(data.stats.lessons_publish)} />
        <KpiCard label="খসড়া পাঠ" value={bnNumber(data.stats.lessons_draft)} />
        <KpiCard label="সিভি ডিজাইন" value={bnNumber(data.stats.cv_templates)} />
        <KpiCard label="প্রশ্নব্যাংক" value={bnNumber(data.stats.questions)} />
      </div>

      <Card>
        <div className="flex items-center justify-between gap-3 flex-wrap">
          <p className="text-[13.5px]">
            <span className="font-semibold">আজকের নির্বাচিত পাঠ:</span>{' '}
            {data.today?.title ? data.today.title : <span className="text-neutral-400">কিছু নেই — পাঠ প্রকাশ করলেই প্রতিদিন একটি ঘুরে ঘুরে দেখাবে।</span>}
          </p>
          {data.campus_url && (
            <a className="text-[12.5px] text-brand-700 font-medium inline-flex items-center gap-1" href={data.campus_url} target="_blank" rel="noreferrer">
              ক্যাম্পাস পাতা দেখুন <IconExternalLink className="w-3.5 h-3.5" />
            </a>
          )}
        </div>
        <p className="text-[12px] text-neutral-500 mt-1">কোনো পাঠে নির্দিষ্ট তারিখ বসালে সেদিন সেটিই দেখাবে; নইলে প্রকাশিত পাঠ থেকে প্রতিদিন একটি।</p>
      </Card>

      <Card>
        <div className="flex items-center justify-between gap-3 flex-wrap mb-2">
          <p className="text-[13.5px] font-semibold">পাঠের ভান্ডার ({bnNumber(lessons.length)}টি)</p>
          <select className={inputClass + ' !w-auto'} value={subjectFilter} onChange={(e) => setSubjectFilter(e.target.value)}>
            <option value="">সব বিষয়</option>
            {data.subjects.map((s) => (
              <option key={s.slug} value={s.slug}>
                {s.name}
              </option>
            ))}
          </select>
        </div>
        <ul className="divide-y divide-neutral-100">
          {lessons.map((l) => (
            <li key={l.id} className="py-2 flex items-center gap-2.5" data-testid="campus-lesson">
              <span className="min-w-0 flex-1">
                <span className="block text-[13px] font-medium truncate">{l.title}</span>
                <span className="block text-[11.5px] text-neutral-500">
                  {l.subject_name || 'বিষয় নেই'} · {bnNumber(l.minutes)} মিনিট
                  {l.feature_date && <> · 📌 {l.feature_date}</>}
                </span>
              </span>
              <Badge tone={l.status === 'publish' ? 'emerald' : 'amber'}>{l.status === 'publish' ? 'প্রকাশিত' : 'খসড়া'}</Badge>
              <Button size="sm" variant="secondary" onClick={() => void toggle(l)} disabled={busyId !== 0}>
                {l.status === 'publish' ? 'খসড়া করুন' : 'প্রকাশ করুন'}
              </Button>
              <Button
                size="sm"
                variant="secondary"
                onClick={() =>
                  setEditing({ id: l.id, title: l.title, excerpt: l.excerpt, content: '', subject: l.subject, minutes: l.minutes, order: l.order, status: l.status === 'publish' ? 'publish' : 'draft', feature_date: l.feature_date })
                }
              >
                <IconPencil className="w-3.5 h-3.5" />
              </Button>
              <Button size="sm" variant="secondary" onClick={() => void remove(l)} disabled={busyId !== 0}>
                <IconTrash className="w-3.5 h-3.5" />
              </Button>
            </li>
          ))}
          {!lessons.length && <li className="py-3 text-[12.5px] text-neutral-400">পাঠ নেই — “কন্টেন্ট প্যাক ইনস্টল” চাপুন বা নতুন পাঠ লিখুন।</li>}
        </ul>
      </Card>

      <Card>
        <p className="text-[13.5px] font-semibold mb-2">সিভি টেমপ্লেট ({bnNumber(data.cv.length)}টি)</p>
        <div className="flex flex-wrap gap-2">
          {data.cv.map((t) => (
            <span key={t.id} className="rounded-lg border border-neutral-200 px-3 py-1.5 text-[12.5px]">
              {t.name}
              <span className="text-neutral-400"> · {t.price > 0 ? `${bnNumber(t.price)} টাকা` : 'ফ্রি'} · {bnNumber(t.pages)} পাতা</span>
              {t.status !== 'publish' && <Badge tone="amber">খসড়া</Badge>}
            </span>
          ))}
          {!data.cv.length && <span className="text-[12.5px] text-neutral-400">ডিজাইন নেই — কন্টেন্ট প্যাক ইনস্টল করুন।</span>}
        </div>
        <p className="text-[11.5px] text-neutral-500 mt-2">ডিজাইনের HTML/CSS বদলাতে হলে wp-admin-এর সিভি টেমপ্লেট অংশে।</p>
      </Card>

      {editing && (
        <LessonForm
          initial={editing}
          subjects={data.subjects}
          onClose={() => setEditing(null)}
          onDone={() => {
            setEditing(null)
            load()
          }}
        />
      )}
    </div>
  )
}
