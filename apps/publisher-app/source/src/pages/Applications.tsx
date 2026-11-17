import { useEffect, useState } from 'react'
import { ApplicationsApi, WRITER_GENRE_LABEL, WRITER_GROUP_LABEL, describeError, type Application } from '../lib/api'
import { Badge, Banner, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, bnDate, bnNumber, inputClass } from '../components/ui'
import { IconRefresh } from '../components/Icon'
import { useOptionalNotifications } from '../lib/notifications'

/** r47 — মিহা কলম: the উপাধি the publisher gives on approval */
const DEFAULT_WRITER_TITLE: Record<string, string> = {
  student: 'শিক্ষার্থী লেখক',
  teacher: 'অতিথি লেখক',
  professional: 'অতিথি লেখক',
  general: 'লেখক',
}
const WRITER_TITLES = ['লেখক', 'শিক্ষার্থী লেখক', 'অতিথি লেখক', 'কলামিস্ট', 'কবি', 'গল্পকার', 'ফিচার লেখক', 'ক্যাম্পাস লেখক']
const REPORTER_TITLES = ['জেলা প্রতিনিধি', 'উপজেলা প্রতিনিধি', 'নিজস্ব প্রতিবেদক', 'ক্যাম্পাস প্রতিনিধি', 'স্টাফ রিপোর্টার']

const statusTone: Record<string, 'amber' | 'emerald' | 'red'> = {
  pending: 'amber',
  approved: 'emerald',
  rejected: 'red',
}
const statusLabel: Record<string, string> = {
  pending: 'পেন্ডিং',
  approved: 'অনুমোদিত',
  rejected: 'প্রত্যাখ্যাত',
}
const fileLabel: Record<string, string> = {
  nid: 'এনআইডি',
  cv: 'সিভি',
  certificate: 'সনদ',
}

type Tab = 'pending' | 'approved' | 'rejected' | 'all'
const TABS: { value: Tab; label: string }[] = [
  { value: 'pending', label: 'পেন্ডিং' },
  { value: 'approved', label: 'অনুমোদিত' },
  { value: 'rejected', label: 'প্রত্যাখ্যাত' },
  { value: 'all', label: 'সব' },
]

// Round 18: pending applications first (they are the ones waiting on
// someone), each as a card with phone, full area and the KYC files — the
// old table had no phone, upazila or documents and was cramped on a phone.
// The list also refreshes by itself when a new application notification
// arrives.
export default function Applications() {
  const [items, setItems] = useState<Application[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [tab, setTab] = useState<Tab>('pending')
  const [busyId, setBusyId] = useState<number | null>(null)
  const [rejecting, setRejecting] = useState<Application | null>(null)
  const [reason, setReason] = useState('')
  const [opening, setOpening] = useState('')
  // r47 — approval sheet (উপাধি) and the journalist / মিহা কলম filter
  const [approving, setApproving] = useState<Application | null>(null)
  const [title, setTitle] = useState('')
  const [trackFilter, setTrackFilter] = useState<'all' | 'journalist' | 'writer'>('all')
  const notifVersion = useOptionalNotifications()?.version ?? 0

  function load(quiet = false) {
    if (!quiet) setLoading(true)
    ApplicationsApi.list()
      .then((rows) => {
        setItems(rows)
        setError('')
      })
      .catch((e) => !quiet && setError(describeError(e).message))
      .finally(() => setLoading(false))
  }

  useEffect(() => load(), [])
  useEffect(() => {
    if (notifVersion) load(true)
  }, [notifVersion])

  async function approve(app: Application, title = '') {
    setBusyId(app.id)
    try {
      await ApplicationsApi.approve(app.id, title)
      setItems((prev) => prev.map((i) => (i.id === app.id ? { ...i, status: 'approved' } : i)))
      setApproving(null)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setBusyId(null)
    }
  }

  async function submitReject() {
    if (!rejecting) return
    setBusyId(rejecting.id)
    try {
      await ApplicationsApi.reject(rejecting.id, reason)
      setItems((prev) => prev.map((i) => (i.id === rejecting.id ? { ...i, status: 'rejected', reject_reason: reason } : i)))
      setRejecting(null)
      setReason('')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setBusyId(null)
    }
  }

  async function openFile(app: Application, field: string) {
    // Opened before the await so a popup blocker treats it as the user's click.
    const win = window.open('', '_blank')
    setOpening(`${app.id}:${field}`)
    try {
      const blob = await ApplicationsApi.file(app.id, field)
      const url = URL.createObjectURL(blob)
      if (win) win.location.href = url
      else window.location.href = url
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch (e) {
      win?.close()
      setError(describeError(e).message)
    } finally {
      setOpening('')
    }
  }

  const counts: Record<Tab, number> = { pending: 0, approved: 0, rejected: 0, all: items.length }
  items.forEach((i) => counts[i.status]++)
  const shown = (tab === 'all' ? items : items.filter((i) => i.status === tab)).filter(
    (i) => trackFilter === 'all' || (i.track ?? 'journalist') === trackFilter,
  )
  const writerCount = items.filter((i) => i.track === 'writer' && i.status === 'pending').length

  return (
    <div>
      <PageHeader
        title="আবেদন"
        subtitle="সাংবাদিক ও মিহা কলমের লেখক — নতুন আবেদন পর্যালোচনা করুন"
        actions={
          <Button variant="secondary" size="sm" onClick={() => load()}>
            <IconRefresh className="w-4 h-4" /> রিফ্রেশ
          </Button>
        }
      />
      {error && <Banner kind="error">{error}</Banner>}

      <div className="flex gap-1.5 overflow-x-auto pb-1 mb-4" role="tablist">
        {TABS.map((t) => (
          <button
            key={t.value}
            role="tab"
            aria-selected={tab === t.value}
            onClick={() => setTab(t.value)}
            className={`shrink-0 px-3.5 py-1.5 rounded-full text-[13px] font-medium border ${
              tab === t.value ? 'bg-neutral-900 border-neutral-900 text-white' : 'bg-white border-neutral-200 text-neutral-600'
            }`}
          >
            {t.label} <span className={tab === t.value ? 'text-white/70' : 'text-neutral-400'}>{bnNumber(counts[t.value])}</span>
          </button>
        ))}
      </div>

      <div className="flex gap-1.5 mb-4">
        {(
          [
            ['all', 'সব'],
            ['journalist', '📰 সাংবাদিক'],
            ['writer', `✒️ মিহা কলম${writerCount ? ` (${bnNumber(writerCount)})` : ''}`],
          ] as const
        ).map(([v, l]) => (
          <button
            key={v}
            onClick={() => setTrackFilter(v)}
            className={`px-3 py-1 rounded-lg text-[12.5px] font-medium border ${trackFilter === v ? 'bg-brand-50 border-brand-500 text-brand-700' : 'bg-white border-neutral-200 text-neutral-600'}`}
          >
            {l}
          </button>
        ))}
      </div>

      {loading ? (
        <Spinner className="mt-10" />
      ) : shown.length === 0 ? (
        <Card>
          <EmptyState>{tab === 'pending' ? 'অপেক্ষমাণ কোনো আবেদন নেই।' : 'কোনো আবেদন নেই।'}</EmptyState>
        </Card>
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          {shown.map((app) => (
            <Card key={app.id} className="p-4">
              <div className="flex items-start justify-between gap-3 mb-2">
                <div className="min-w-0">
                  <p className="text-[15px] font-semibold text-neutral-900 truncate">{app.full_name}</p>
                  <p className="text-[12px] text-neutral-400">{bnDate(app.created_at)}</p>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <Badge tone={statusTone[app.status]}>{statusLabel[app.status]}</Badge>
                  <Badge tone={app.track === 'writer' ? 'blue' : 'neutral'}>{app.track === 'writer' ? '✒️ মিহা কলম' : '📰 সাংবাদিক'}</Badge>
                </div>
              </div>
              <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[13px] mb-3">
                <dt className="text-neutral-400">ইমেইল</dt>
                <dd className="text-neutral-800 break-all">
                  <a href={`mailto:${app.email}`} className="hover:underline">{app.email}</a>
                </dd>
                <dt className="text-neutral-400">ফোন</dt>
                <dd className="text-neutral-800">
                  {app.phone ? <a href={`tel:${app.phone}`} className="hover:underline">{app.phone}</a> : '—'}
                </dd>
                <dt className="text-neutral-400">এলাকা</dt>
                <dd className="text-neutral-800">{[app.division, app.district, app.upazila].filter(Boolean).join(' › ') || '—'}</dd>
                {app.track === 'writer' && (
                  <>
                    <dt className="text-neutral-400">পরিচয়</dt>
                    <dd className="text-neutral-800">{WRITER_GROUP_LABEL[app.writer_group ?? ''] ?? 'লেখক'}{app.designation ? ` · ${app.designation}` : ''}</dd>
                    {app.institution && (
                      <>
                        <dt className="text-neutral-400">প্রতিষ্ঠান</dt>
                        <dd className="text-neutral-800">{app.institution}</dd>
                      </>
                    )}
                    <dt className="text-neutral-400">লিখবেন</dt>
                    <dd className="text-neutral-800">
                      {(app.genres ?? '')
                        .split(',')
                        .filter(Boolean)
                        .map((g) => WRITER_GENRE_LABEL[g] ?? g)
                        .join(', ') || '—'}
                    </dd>
                    {app.pen_name && (
                      <>
                        <dt className="text-neutral-400">ছদ্মনাম</dt>
                        <dd className="text-neutral-800">{app.pen_name}</dd>
                      </>
                    )}
                    {app.sample && (
                      <>
                        <dt className="text-neutral-400">পরিচিতি</dt>
                        <dd className="text-neutral-800 whitespace-pre-line break-words">{app.sample}</dd>
                      </>
                    )}
                  </>
                )}
                {app.status === 'rejected' && app.reject_reason && (
                  <>
                    <dt className="text-neutral-400">কারণ</dt>
                    <dd className="text-red-600">{app.reject_reason}</dd>
                  </>
                )}
              </dl>
              {!!app.files?.length && (
                <div className="flex flex-wrap gap-1.5 mb-3">
                  {app.files.map((f) => (
                    <button
                      key={f}
                      type="button"
                      onClick={() => openFile(app, f)}
                      disabled={opening === `${app.id}:${f}`}
                      className="px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700 text-[12.5px] font-medium hover:bg-blue-100 disabled:opacity-60"
                    >
                      {opening === `${app.id}:${f}` ? 'খুলছে…' : `${fileLabel[f] ?? f} দেখুন`}
                    </button>
                  ))}
                </div>
              )}
              {app.status === 'pending' && (
                <div className="flex gap-2">
                  <Button
                    variant="success"
                    disabled={busyId === app.id}
                    onClick={() => {
                      setTitle(app.track === 'writer' ? DEFAULT_WRITER_TITLE[app.writer_group ?? ''] ?? 'লেখক' : '')
                      setApproving(app)
                    }}
                    className="flex-1"
                  >
                    অনুমোদন
                  </Button>
                  <Button variant="danger" disabled={busyId === app.id} onClick={() => setRejecting(app)} className="flex-1">
                    প্রত্যাখ্যান
                  </Button>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}

      {approving && (
        <Modal title={`অনুমোদন — ${approving.full_name}`} onClose={() => setApproving(null)}>
          <Field label="উপাধি (ওয়েবসাইটে নামের নিচে দেখাবে)">
            <input className={inputClass} value={title} onChange={(e) => setTitle(e.target.value)} placeholder={approving.track === 'writer' ? 'যেমন: শিক্ষার্থী লেখক' : 'যেমন: উপজেলা প্রতিনিধি'} />
          </Field>
          <div className="flex flex-wrap gap-1.5 mb-3">
            {(approving.track === 'writer' ? WRITER_TITLES : REPORTER_TITLES).map((t) => (
              <button
                key={t}
                type="button"
                onClick={() => setTitle(t)}
                className={`px-2.5 py-1 rounded-lg text-[12.5px] border ${title === t ? 'bg-brand-50 border-brand-500 text-brand-700 font-medium' : 'border-neutral-200'}`}
              >
                {t}
              </button>
            ))}
          </div>
          <p className="text-[12px] text-neutral-500 mb-3">
            {approving.track === 'writer'
              ? 'অনুমোদনের পর ইনি “মিহা কলম” ও নিজের গ্রুপের চ্যাটরুমে যুক্ত হবেন — সংবাদদাতাদের রুম বা রাত ৮টার রিমাইন্ডারে নয়।'
              : 'অনুমোদনের পর ইনি মিহা পরিবার ও নিজের বিভাগের রুমে যুক্ত হবেন।'}{' '}
            উপাধি পরে সংবাদদাতা তালিকা থেকেও বদলানো যাবে।
          </p>
          <Button onClick={() => void approve(approving, title.trim())} disabled={busyId === approving.id} className="w-full" data-testid="approve-confirm">
            অনুমোদন নিশ্চিত করুন
          </Button>
        </Modal>
      )}

      {rejecting && (
        <Modal title="প্রত্যাখ্যানের কারণ" onClose={() => setRejecting(null)}>
          <Field label="কারণ (ঐচ্ছিক)">
            <textarea className={inputClass} rows={4} value={reason} onChange={(e) => setReason(e.target.value)} />
          </Field>
          <Button onClick={submitReject} disabled={busyId === rejecting.id} className="w-full">
            প্রত্যাখ্যান নিশ্চিত করুন
          </Button>
        </Modal>
      )}
    </div>
  )
}
