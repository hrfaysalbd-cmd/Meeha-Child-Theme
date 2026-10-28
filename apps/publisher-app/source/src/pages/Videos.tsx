/**
 * Round 10 — ভিডিও নিউজ list: every author's videos by status tab, with
 * quick actions (approve/publish a pending video, reject with a required
 * reason, delete when allowed, open on site).
 */
import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { AdminVideosApi, ApiError, describeError, type Video, type VideoListFilter } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { PaymentStatusBadge } from '../components/PaymentPanel'
import { NEED_PUBLISHER_PERMISSION, bnDateTime, usePostPermissions } from '../lib/postMeta'
import { Banner, Button, Card, EmptyState, ErrorRetry, PageHeader, Spinner, bnNumber, inputClass } from '../components/ui'
import { IconCheck, IconCoin, IconExternalLink, IconPencil, IconPlus, IconTrash, IconX } from '../components/Icon'
import { DeleteConfirmModal, RowMenu } from '../components/PostManagement'
import { RejectVideoModal, VideoStats, VideoThumb } from '../components/VideoBits'

const TABS: { value: VideoListFilter; label: string }[] = [
  { value: 'all', label: 'সব' },
  { value: 'publish', label: 'প্রকাশিত' },
  { value: 'pending', label: 'রিভিউয়ের অপেক্ষায়' },
  { value: 'draft', label: 'ড্রাফট' },
]

const EMPTY: Record<VideoListFilter, string> = {
  all: 'এখনো কোনো ভিডিও নিউজ নেই।',
  publish: 'কোনো প্রকাশিত ভিডিও নেই।',
  pending: 'রিভিউয়ের অপেক্ষায় কোনো ভিডিও নেই।',
  draft: 'কোনো ড্রাফট ভিডিও নেই।',
}

// Whether a video still belongs in the tab after a quick action changed it.
function belongsTo(tab: VideoListFilter, v: Video) {
  if (tab === 'all') return true
  if (tab === 'publish') return v.status === 'publish' || v.status === 'future'
  return v.status === tab
}

// Round 12 — a published video's payment, inline on its card (same as the
// news list's payment cell): amount + পেন্ডিং/অনুমোদিত badge, set/change,
// and confirm for an auto-applied default-rate amount.
function VideoPaymentRow({ video, isPublisher, onSaved }: { video: Video; isPublisher: boolean; onSaved: (v: Partial<Video>) => void }) {
  const [editing, setEditing] = useState(false)
  const [value, setValue] = useState(video.payment ? String(video.payment) : '')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  if (video.payment === undefined || video.status !== 'publish') return null
  if (!isPublisher && !video.payment) return null

  async function run(fn: () => Promise<{ amount: number | null; payment_status?: 'pending' | 'approved' | null }>) {
    setBusy(true)
    setErr('')
    try {
      const res = await fn()
      onSaved({ payment: res.amount, payment_status: res.amount ? res.payment_status ?? 'approved' : null })
      setEditing(false)
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mt-2 rounded-lg bg-neutral-50 px-2.5 py-2" data-testid="video-payment-row">
      {editing ? (
        <div className="flex items-center gap-1.5">
          <input
            type="number"
            min={0}
            inputMode="decimal"
            autoFocus
            aria-label="ভিডিও পেমেন্টের পরিমাণ"
            className={`${inputClass} py-1.5 w-24`}
            value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder="৳"
          />
          <Button size="sm" disabled={busy} onClick={() => run(() => AdminVideosApi.setPayment(video.id, Number(value) || 0))}>
            {busy ? '…' : 'সংরক্ষণ'}
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setEditing(false)}>
            বাতিল
          </Button>
        </div>
      ) : (
        <div className="flex items-center gap-2 flex-wrap">
          <span className="flex items-center gap-1 text-[12.5px] font-medium text-neutral-700">
            <IconCoin className="w-3.5 h-3.5 text-emerald-600" />
            {video.payment ? `৳ ${bnNumber(video.payment)}` : 'পেমেন্ট নেই'}
          </span>
          <PaymentStatusBadge status={video.payment ? video.payment_status : null} />
          {isPublisher && (
            <button type="button" onClick={() => setEditing(true)} className="text-[11.5px] text-brand-600 font-medium ml-auto min-h-[32px]">
              {video.payment ? 'পরিবর্তন' : 'সেট করুন'}
            </button>
          )}
          {isPublisher && video.payment_status === 'pending' && video.payment ? (
            <Button size="sm" variant="success" disabled={busy} onClick={() => run(() => AdminVideosApi.confirmPayment(video.id))}>
              নিশ্চিত করুন
            </Button>
          ) : null}
        </div>
      )}
      {err && <p className="text-red-600 text-[11px] mt-1">{err}</p>}
    </div>
  )
}

export default function Videos() {
  const navigate = useNavigate()
  const location = useLocation()
  const perms = usePostPermissions()
  const { isPublisher } = useAuth()
  const [params, setParams] = useSearchParams()
  const tabParam = params.get('tab') as VideoListFilter | null
  const tab: VideoListFilter = TABS.some((t) => t.value === tabParam) ? (tabParam as VideoListFilter) : 'all'

  const [items, setItems] = useState<Video[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<unknown>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState<string>(() => (location.state as { notice?: string } | null)?.notice ?? '')
  const [busyId, setBusyId] = useState<number | null>(null)
  const [rejecting, setRejecting] = useState<Video | null>(null)
  const [deleting, setDeleting] = useState<Video | null>(null)

  useEffect(() => {
    if ((location.state as { notice?: string } | null)?.notice) {
      navigate(`${location.pathname}${location.search}`, { replace: true, state: null })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function load(t: VideoListFilter) {
    setLoading(true)
    setLoadError(null)
    AdminVideosApi.list(t)
      .then(setItems)
      .catch((e) => setLoadError(e ?? new Error('unknown')))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    load(tab)
  }, [tab])

  function selectTab(t: VideoListFilter) {
    setNotice('')
    setError('')
    setParams(t === 'all' ? {} : { tab: t }, { replace: true })
  }

  function applyChange(v: Video) {
    setItems((prev) => (belongsTo(tab, v) ? prev.map((x) => (x.id === v.id ? v : x)) : prev.filter((x) => x.id !== v.id)))
  }

  async function approve(v: Video) {
    setBusyId(v.id)
    setError('')
    setNotice('')
    try {
      const updated = await AdminVideosApi.update(v.id, { status: 'publish' })
      applyChange(updated)
      setNotice(updated.status === 'future' ? `“${v.title}” নির্ধারিত সময়ে প্রকাশ হবে।` : `“${v.title}” প্রকাশিত হয়েছে।`)
    } catch (err) {
      setError(describeError(err).message)
    } finally {
      setBusyId(null)
    }
  }

  async function confirmDelete() {
    if (!deleting) return
    const v = deleting
    await AdminVideosApi.remove(v.id)
    setItems((prev) => prev.filter((x) => x.id !== v.id))
    setDeleting(null)
    setNotice(`“${v.title || 'শিরোনামহীন'}” ট্র্যাশে সরানো হয়েছে — wp-admin থেকে ফিরিয়ে আনা যাবে।`)
  }

  return (
    <div>
      <PageHeader
        title="ভিডিও নিউজ"
        subtitle="সংবাদদাতা ও স্টাফের সব ভিডিও — রিভিউ, প্রকাশ ও পরিসংখ্যান"
        actions={
          <Button onClick={() => navigate('/app/videos/new')}>
            <IconPlus className="w-4 h-4" />
            নতুন ভিডিও
          </Button>
        }
      />
      {error && <Banner kind="error">{error}</Banner>}
      {notice && <Banner kind="success">{notice}</Banner>}

      <div className="flex gap-1.5 flex-wrap mb-4" role="tablist">
        {TABS.map((t) => (
          <button
            key={t.value}
            role="tab"
            aria-selected={tab === t.value}
            onClick={() => selectTab(t.value)}
            className={`px-3 py-1.5 rounded-full text-[12.5px] font-medium ${
              tab === t.value ? 'bg-brand-600 text-white' : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {loading ? (
        <Spinner className="mt-10" />
      ) : loadError != null ? (
        <Card>
          <ErrorRetry error={loadError} onRetry={() => load(tab)} />
        </Card>
      ) : items.length === 0 ? (
        <Card>
          <EmptyState>
            {EMPTY[tab]}
            {tab === 'all' && (
              <span className="block mt-3">
                <Link to="/app/videos/new" className="text-brand-600 font-medium">
                  প্রথম ভিডিওটি যুক্ত করুন →
                </Link>
              </span>
            )}
          </EmptyState>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4" data-testid="video-grid">
          {items.map((v) => (
            <Card key={v.id} className="overflow-hidden flex flex-col">
              <Link to={`/app/videos/${v.id}`} aria-label={v.title}>
                <VideoThumb video={v} />
              </Link>
              <div className="p-4 flex-1 flex flex-col" data-testid="video-card">
                <Link to={`/app/videos/${v.id}`} className="text-[14px] font-semibold text-neutral-900 line-clamp-2 hover:text-brand-700 mb-2">
                  {v.title || 'শিরোনামহীন'}
                </Link>
                <div className="flex items-center gap-2 mb-2 min-w-0">
                  {v.author?.avatar ? (
                    <img src={v.author.avatar} alt="" className="w-5 h-5 rounded-full object-cover bg-neutral-100 shrink-0" />
                  ) : null}
                  <span className="text-[12px] text-neutral-600 truncate">{v.author?.name ?? '—'}</span>
                  <span className="text-neutral-300">·</span>
                  <span className="text-[12px] text-neutral-400 whitespace-nowrap">{bnDateTime(v.date)}</span>
                </div>
                {v.rejected && v.reject_reason && (
                  <p className="text-[11.5px] text-red-600 bg-red-50 rounded-lg px-2 py-1 mb-2 line-clamp-2">কারণ: {v.reject_reason}</p>
                )}
                <div className="mt-auto pt-2 border-t border-neutral-100">
                  <VideoStats stats={v.stats} />
                </div>
                <VideoPaymentRow video={v} isPublisher={isPublisher} onSaved={(patch) => applyChange({ ...v, ...patch })} />
                {v.status === 'pending' && (
                  <div className="flex gap-2 mt-3">
                    <Button variant="success" size="sm" className="flex-1" disabled={busyId === v.id} onClick={() => approve(v)}>
                      <IconCheck className="w-3.5 h-3.5" />
                      অনুমোদন ও প্রকাশ
                    </Button>
                    <Button variant="danger" size="sm" className="flex-1" disabled={busyId === v.id} onClick={() => setRejecting(v)}>
                      <IconX className="w-3.5 h-3.5" />
                      প্রত্যাখ্যান
                    </Button>
                  </div>
                )}
                <div className="flex items-center gap-1.5 mt-2">
                  <Button variant="secondary" size="sm" className="flex-1" onClick={() => navigate(`/app/videos/${v.id}`)}>
                    <IconPencil className="w-3.5 h-3.5" />
                    এডিট
                  </Button>
                  <RowMenu
                    items={[
                      ...(v.status === 'publish' && v.link
                        ? [{ label: 'সাইটে দেখুন', icon: <IconExternalLink className="w-4 h-4" />, href: v.link }]
                        : []),
                      ...(v.status === 'publish' || v.status === 'future'
                        ? [
                            {
                              label: 'প্রত্যাখ্যান করুন',
                              icon: <IconX className="w-4 h-4" />,
                              hint: 'সাইট থেকে সরিয়ে কারণসহ ড্রাফটে ফেরত',
                              onClick: () => setRejecting(v),
                            },
                          ]
                        : []),
                      {
                        label: 'ডিলিট করুন',
                        icon: <IconTrash className="w-4 h-4" />,
                        danger: true,
                        disabled: !perms.deletePosts,
                        hint: perms.deletePosts ? 'ট্র্যাশে যাবে' : NEED_PUBLISHER_PERMISSION,
                        onClick: () => setDeleting(v),
                      },
                    ]}
                  />
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      {rejecting && (
        <RejectVideoModal
          video={rejecting}
          onClose={() => setRejecting(null)}
          onRejected={(v) => {
            applyChange(v)
            setRejecting(null)
            setNotice(`“${v.title}” প্রত্যাখ্যান করা হয়েছে — সংবাদদাতা কারণসহ দেখতে পাবেন।`)
          }}
        />
      )}
      {deleting && <DeleteConfirmModal kind="video" title={deleting.title} onConfirm={confirmDelete} onClose={() => setDeleting(null)} />}
    </div>
  )
}
