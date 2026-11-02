import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { SubmissionsApi, type Submission } from '../lib/api'
import { ErrorRetry, Segmented, Spinner, StatusBadge, bnDate, bnNumber } from '../components/ui'
import { IconCoin, IconEye, IconNews, IconPlus, IconVideo } from '../components/Icon'
import VideoList from '../components/VideoList'
import NotificationBell from '../components/NotificationBell'
import { useOptionalNotifications } from '../lib/notifications'
import StatusFilterChips, { parseStatusFilter, statusGroup, type StatusFilter } from '../components/StatusFilterChips'

type NewsTab = 'article' | 'video'

/**
 * "আমার সংবাদ": the correspondent's articles and (round 10) videos, switched
 * with a segmented control. The choice lives in the URL (?tab=video) so the
 * video editor's back button and a reload both land on the right list.
 */
export default function News() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const tab: NewsTab = params.get('tab') === 'video' ? 'video' : 'article'
  const status = parseStatusFilter(params.get('status'))
  const setStatus = (v: StatusFilter) => {
    const next: Record<string, string> = {}
    if (tab === 'video') next.tab = 'video'
    if (v !== 'all') next.status = v
    setParams(next, { replace: true })
  }

  return (
    <div className="px-5 pt-5 pb-6">
      <div className="flex items-center justify-between mb-3">
        <h1 className="text-[18px] font-bold text-neutral-900">আমার সংবাদ</h1>
        <div className="flex items-center gap-1">
          <button
            onClick={() => navigate(tab === 'video' ? '/app/video/new' : '/app/write')}
            className="flex items-center gap-1 text-[13px] font-semibold text-brand-600 px-2 py-1"
          >
            <IconPlus className="w-4 h-4" /> {tab === 'video' ? 'নতুন ভিডিও' : 'নতুন'}
          </button>
          <NotificationBell placement="sheet" />
        </div>
      </div>

      <Segmented
        ariaLabel="তালিকা"
        className="mb-4"
        value={tab}
        onChange={(v) => setParams(v === 'video' ? { tab: 'video' } : {}, { replace: true })}
        options={[
          { value: 'article', label: 'সংবাদ', icon: IconNews },
          { value: 'video', label: 'ভিডিও', icon: IconVideo },
        ]}
      />

      {tab === 'video' ? (
        <VideoList status={status} onStatusChange={setStatus} />
      ) : (
        <ArticleList status={status} onStatusChange={setStatus} />
      )}
    </div>
  )
}

function ArticleList({ status, onStatusChange }: { status: StatusFilter; onStatusChange: (v: StatusFilter) => void }) {
  const navigate = useNavigate()
  const notifVersion = useOptionalNotifications()?.version ?? 0
  const [items, setItems] = useState<Submission[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<unknown>(null)

  function load() {
    setLoading(true)
    setError(null)
    SubmissionsApi.list()
      .then((data) => {
        setItems(data)
        setError(null)
      })
      // Previously missing entirely — a failed fetch (expired session,
      // dropped connection) left this list silently blank with no way to
      // recover short of a hard reload.
      .catch((err) => setError(err ?? new Error('unknown')))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    load()
  }, [])

  // A notification (approved / returned) means this list just changed.
  useEffect(() => {
    if (notifVersion) SubmissionsApi.list().then(setItems).catch(() => {})
  }, [notifVersion])

  const shown = status === 'all' ? items : items.filter((s) => statusGroup(s) === status)

  // Keeps "আমার সংবাদ" in sync automatically — e.g. a post an editor just
  // approved/rejected, or a payment a Publisher just set, shows up here
  // without the correspondent having to leave and reopen the tab.
  //
  // Round 9 follow-up: a correspondent reported setting payment on two
  // posts "at nearly the same time" and seeing only one reflected here.
  // Nothing in the save path itself turned out to be wrong (each post's
  // payment is its own upserted ledger row, keyed by that post's own id —
  // see NROS_Ledger::set_post_payment()), so the far more likely
  // explanation is exactly this: this list only refreshed in the
  // background once a minute, so checking two posts seconds apart could
  // easily land on either side of that window and look inconsistent.
  // Shortened to 20s so a correspondent actively checking "did my payment
  // show up yet" gets an answer within moments instead of up to a full
  // minute.
  useEffect(() => {
    const interval = setInterval(() => {
      if (document.visibilityState === 'visible') {
        SubmissionsApi.list().then(setItems).catch(() => {})
      }
    }, 20_000)
    function onVisible() {
      if (document.visibilityState === 'visible') {
        SubmissionsApi.list().then(setItems).catch(() => {})
      }
    }
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      clearInterval(interval)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [])

  return (
    <>
      {loading ? (
        <Spinner />
      ) : error != null ? (
        <ErrorRetry error={error} onRetry={load} />
      ) : items.length === 0 ? (
        <p className="text-[13px] text-neutral-400 py-10 text-center">এখনো কোনো সংবাদ লেখেননি।</p>
      ) : (
        <>
        <StatusFilterChips items={items} value={status} onChange={onStatusChange} />
        {shown.length === 0 && <p className="text-[13px] text-neutral-400 py-10 text-center">এই অবস্থায় কোনো সংবাদ নেই।</p>}
        <div className="flex flex-col gap-2.5">
          {shown.map((s) => (
            <button
              key={s.id}
              onClick={() => navigate(`/app/write/${s.id}`)}
              className="text-left rounded-xl border border-neutral-100 p-3.5"
            >
              <div className="flex items-start justify-between gap-2 mb-1.5">
                <span className="text-[14.5px] font-medium text-neutral-900 line-clamp-2">{s.title || 'শিরোনামহীন'}</span>
                <StatusBadge status={s.status} rejected={s.rejected} />
              </div>
              {s.rejected && s.reject_reason && (
                <p className="text-[12px] text-red-600 mb-1.5 line-clamp-2">কারণ: {s.reject_reason}</p>
              )}
              <div className="flex items-center gap-3 text-[12px] text-neutral-400">
                <span>{bnDate(s.updated_at)}</span>
                {s.status === 'publish' && (
                  <span className="flex items-center gap-1">
                    <IconEye className="w-3.5 h-3.5" /> {bnNumber(s.views)}
                  </span>
                )}
                {/* No payment configured on this post → show nothing at all,
                    per the requirement that an unset post displays no income. */}
                {!!s.payment && (
                  <span className={`flex items-center gap-1 font-medium ${s.payment_status === 'pending' ? 'text-amber-600' : 'text-emerald-600'}`}>
                    <IconCoin className="w-3.5 h-3.5" /> ৳ {bnNumber(s.payment)}
                    {s.payment_status === 'pending' && <span className="text-[11px]">(পেন্ডিং)</span>}
                  </span>
                )}
              </div>
            </button>
          ))}
        </div>
        </>
      )}
    </>
  )
}
