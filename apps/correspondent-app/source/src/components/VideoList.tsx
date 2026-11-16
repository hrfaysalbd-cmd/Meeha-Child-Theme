import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { VideosApi, type Video } from '../lib/api'
import { Button, ErrorRetry, Spinner, StatusBadge, bnDate, bnDuration, bnNumber } from './ui'
import VideoPayment from './VideoPayment'
import StatusFilterChips, { statusGroup, type StatusFilter } from './StatusFilterChips'
import { useOptionalNotifications } from '../lib/notifications'
import { IconBrandYoutube, IconEye, IconHeart, IconMessage, IconPlayerPlay, IconShare, IconVideo } from './Icon'

const REFRESH_MS = 20_000

function VideoCard({ v, onOpen }: { v: Video; onOpen: () => void }) {
  const published = v.status === 'publish'
  const stats = [
    { icon: IconEye, value: v.stats.views, label: 'ভিউ' },
    { icon: IconHeart, value: v.stats.likes, label: 'লাইক' },
    { icon: IconMessage, value: v.stats.comments, label: 'মন্তব্য' },
    { icon: IconShare, value: v.stats.shares, label: 'শেয়ার' },
  ]
  return (
    <button onClick={onOpen} className="text-left rounded-xl border border-neutral-100 p-2.5 flex gap-3 active:bg-neutral-50">
      <div className="relative w-[72px] shrink-0 aspect-[9/16] rounded-lg overflow-hidden bg-neutral-900">
        {v.thumbnail ? (
          <>
            <img src={v.thumbnail} alt="" loading="lazy" className="w-full h-full object-cover" />
            <span className="absolute inset-0 flex items-center justify-center">
              <span className="w-7 h-7 rounded-full bg-black/45 text-white flex items-center justify-center">
                <IconPlayerPlay className="w-3.5 h-3.5 ml-px" fill="currentColor" />
              </span>
            </span>
          </>
        ) : (
          <span className="absolute inset-0 flex flex-col items-center justify-center gap-1 text-neutral-400">
            <IconVideo className="w-6 h-6" />
            <span className="text-[10px]">{v.video_url ? 'থাম্বনেইল নেই' : 'ভিডিও নেই'}</span>
          </span>
        )}
        {v.duration ? (
          <span className="absolute bottom-1 right-1 rounded bg-black/70 px-1 text-[10px] leading-4 text-white tabular-nums">
            {bnDuration(v.duration)}
          </span>
        ) : null}
        {v.source === 'youtube' && (
          <span className="absolute top-1 left-1 rounded bg-red-600 p-0.5 text-white" title="YouTube">
            <IconBrandYoutube className="w-3 h-3" />
          </span>
        )}
      </div>

      <div className="flex-1 min-w-0 py-0.5 flex flex-col">
        <div className="flex items-start justify-between gap-2 mb-1">
          <span className="text-[14.5px] font-medium text-neutral-900 line-clamp-2 leading-snug">{v.title || 'শিরোনামহীন'}</span>
          <span className="shrink-0">
            <StatusBadge status={v.status} rejected={v.rejected} />
          </span>
        </div>
        {v.rejected && v.reject_reason && <p className="text-[12px] text-red-600 mb-1 line-clamp-2">কারণ: {v.reject_reason}</p>}
        {v.excerpt && !v.rejected && <p className="text-[12px] text-neutral-500 mb-1 line-clamp-1">{v.excerpt}</p>}
        <div className="flex items-center justify-between gap-2 mt-auto">
          <p className="text-[12px] text-neutral-400">{bnDate(v.modified || v.date)}</p>
          <VideoPayment video={v} compact />
        </div>
        {published && (
          <div className="flex items-center gap-3 text-[12px] text-neutral-500 mt-1.5" aria-label="পরিসংখ্যান">
            {stats.map(({ icon: Icon, value, label }) => (
              <span key={label} className="flex items-center gap-1 tabular-nums" title={label}>
                <Icon className="w-3.5 h-3.5 text-neutral-400" /> {bnNumber(value)}
              </span>
            ))}
          </div>
        )}
      </div>
    </button>
  )
}

/** "আমার সংবাদ" → ভিডিও tab: the correspondent's own video submissions. */
export default function VideoList({
  status = 'all',
  onStatusChange,
}: {
  status?: StatusFilter
  onStatusChange?: (v: StatusFilter) => void
}) {
  const navigate = useNavigate()
  const notifVersion = useOptionalNotifications()?.version ?? 0
  const [items, setItems] = useState<Video[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<unknown>(null)

  const load = useCallback(() => {
    setLoading(true)
    setError(null)
    VideosApi.list()
      .then((data) => setItems(data))
      .catch((err) => setError(err ?? new Error('unknown')))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    load()
  }, [load])

  // Same background sync as the article list: an approval, rejection or new
  // views show up without leaving the tab. Quiet — a failed poll keeps the
  // list that is already on screen.
  useEffect(() => {
    const quiet = () => {
      if (document.visibilityState === 'visible') VideosApi.list().then(setItems).catch(() => {})
    }
    const interval = setInterval(quiet, REFRESH_MS)
    document.addEventListener('visibilitychange', quiet)
    return () => {
      clearInterval(interval)
      document.removeEventListener('visibilitychange', quiet)
    }
  }, [])

  useEffect(() => {
    if (notifVersion) VideosApi.list().then(setItems).catch(() => {})
  }, [notifVersion])

  if (loading) return <Spinner />
  if (error != null) return <ErrorRetry error={error} onRetry={load} />

  if (items.length === 0) {
    return (
      <div className="flex flex-col items-center text-center py-12 px-4">
        <span className="w-14 h-14 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center mb-3">
          <IconVideo className="w-7 h-7" />
        </span>
        <p className="text-[15px] font-semibold text-neutral-800 mb-1">এখনো কোনো ভিডিও জমা দেননি</p>
        <p className="text-[13px] text-neutral-400 mb-5 max-w-[260px]">
          মোবাইলে ধারণ করা ভিডিও আপলোড করুন — সম্পাদক অনুমোদন করলে "ভিডিও নিউজ"-এ প্রকাশিত হবে।
        </p>
        <Button full={false} className="px-6" onClick={() => navigate('/app/video/new')}>
          প্রথম ভিডিও জমা দিন
        </Button>
      </div>
    )
  }

  const shown = status === 'all' ? items : items.filter((v) => statusGroup(v) === status)
  return (
    <>
      {onStatusChange && <StatusFilterChips items={items} value={status} onChange={onStatusChange} />}
      {shown.length === 0 && <p className="text-[13px] text-neutral-400 py-10 text-center">এই অবস্থায় কোনো ভিডিও নেই।</p>}
      <div className="flex flex-col gap-2.5">
        {shown.map((v) => (
          <VideoCard key={v.id} v={v} onOpen={() => navigate(`/app/video/${v.id}`)} />
        ))}
      </div>
    </>
  )
}
