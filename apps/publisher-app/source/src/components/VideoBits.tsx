/** Round 10 — small pieces shared by Videos and VideoEditor. */
import { useState } from 'react'
import { AdminVideosApi, describeError, type Video } from '../lib/api'
import { bnDuration } from '../lib/postMeta'
import { Badge, Button, Field, Modal, bnNumber, inputClass } from './ui'
import { IconBrandYoutube, IconEye, IconHeart, IconMessage, IconShare, IconVideo } from './Icon'

export function VideoStatusBadge({ video }: { video: Pick<Video, 'status' | 'rejected'> }) {
  if (video.rejected && video.status === 'draft') return <Badge tone="red">প্রত্যাখ্যাত</Badge>
  if (video.status === 'publish') return <Badge tone="emerald">প্রকাশিত</Badge>
  if (video.status === 'future') return <Badge tone="blue">নির্ধারিত</Badge>
  if (video.status === 'pending') return <Badge tone="amber">রিভিউয়ের অপেক্ষায়</Badge>
  return <Badge tone="neutral">ড্রাফট</Badge>
}

/** 16:9 thumbnail tile; portrait art is letterboxed over a blurred copy. */
export function VideoThumb({ video }: { video: Video }) {
  const portrait = video.orientation === 'portrait'
  return (
    <div className="relative aspect-video bg-neutral-900 overflow-hidden">
      {video.thumbnail ? (
        portrait ? (
          <>
            <img src={video.thumbnail} alt="" aria-hidden className="absolute inset-0 w-full h-full object-cover scale-110 blur-xl opacity-50" />
            <img src={video.thumbnail} alt="" loading="lazy" className="relative h-full mx-auto object-contain" />
          </>
        ) : (
          <img src={video.thumbnail} alt="" loading="lazy" className="w-full h-full object-cover" />
        )
      ) : (
        <div className="w-full h-full flex flex-col items-center justify-center gap-1 text-neutral-500 bg-gradient-to-br from-neutral-800 to-neutral-900">
          <IconVideo className="w-8 h-8" />
          <span className="text-[11px]">থাম্বনেইল নেই</span>
        </div>
      )}
      <div className="absolute top-2 left-2">
        <VideoStatusBadge video={video} />
      </div>
      {video.source === 'youtube' && (
        <span className="absolute top-2 right-2 flex items-center gap-1 rounded-full bg-black/60 text-white text-[10.5px] font-medium px-1.5 py-0.5">
          <IconBrandYoutube className="w-3.5 h-3.5" /> YouTube
        </span>
      )}
      <div className="absolute bottom-2 right-2 flex items-center gap-1">
        <span className="rounded bg-black/65 text-white text-[10.5px] font-medium px-1.5 py-0.5">
          {portrait ? 'পোর্ট্রেট' : 'ল্যান্ডস্কেপ'}
        </span>
        {video.duration ? (
          <span className="rounded bg-black/65 text-white text-[10.5px] font-medium px-1.5 py-0.5">{bnDuration(video.duration)}</span>
        ) : null}
      </div>
    </div>
  )
}

export function VideoStats({ stats }: { stats: Video['stats'] }) {
  const items = [
    { icon: IconEye, value: stats.views, label: 'ভিউ' },
    { icon: IconHeart, value: stats.likes, label: 'লাইক' },
    { icon: IconMessage, value: stats.comments, label: 'কমেন্ট' },
    { icon: IconShare, value: stats.shares, label: 'শেয়ার' },
  ]
  return (
    <div className="grid grid-cols-4 gap-1 text-neutral-500">
      {items.map(({ icon: Icon, value, label }) => (
        <span key={label} title={label} className="flex items-center gap-1 text-[12px]">
          <Icon className="w-3.5 h-3.5 shrink-0" aria-label={label} />
          {bnNumber(value ?? 0)}
        </span>
      ))}
    </div>
  )
}

/** "১২/১০০" counter; amber near the limit, red past it. */
export function CharCount({ value, max, testId }: { value: string; max: number; testId?: string }) {
  const n = [...value].length
  const tone = n > max ? 'text-red-600 font-medium' : n > max * 0.9 ? 'text-amber-600' : 'text-neutral-400'
  return (
    <span data-testid={testId} className={`text-[11.5px] tabular-nums ${tone}`}>
      {bnNumber(n)}/{bnNumber(max)}
    </span>
  )
}

/** Reject with a REQUIRED reason (POST /admin/videos/{id}/reject). */
export function RejectVideoModal({
  video,
  onClose,
  onRejected,
}: {
  video: Video
  onClose: () => void
  onRejected: (v: Video) => void
}) {
  const [reason, setReason] = useState('')
  const [touched, setTouched] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const missing = !reason.trim()

  async function submit() {
    setTouched(true)
    if (missing) return
    setBusy(true)
    setError('')
    try {
      onRejected(await AdminVideosApi.reject(video.id, reason.trim()))
    } catch (err) {
      setError(describeError(err).message)
      setBusy(false)
    }
  }

  return (
    <Modal title="ভিডিও প্রত্যাখ্যানের কারণ" onClose={busy ? () => {} : onClose}>
      <p className="text-[12.5px] text-neutral-500 mb-3 break-words">“{video.title || 'শিরোনামহীন'}”</p>
      <Field label="কারণ লিখুন" required>
        <textarea
          className={`${inputClass} ${touched && missing ? 'border-red-300 focus:border-red-400 focus:ring-red-500/20' : ''}`}
          rows={4}
          autoFocus
          data-testid="reject-reason"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          onBlur={() => setTouched(true)}
          placeholder="যেমন: ভিডিওর শব্দ অস্পষ্ট, শিরোনাম তথ্যের সাথে মিলছে না…"
        />
      </Field>
      {touched && missing && <p className="text-[12px] text-red-600 -mt-2 mb-3">প্রত্যাখ্যানের কারণ লিখতে হবে।</p>}
      <p className="text-[11.5px] text-neutral-400 -mt-1 mb-3">
        ভিডিওটি ড্রাফটে ফিরে যাবে এবং এই কারণ সংবাদদাতার অ্যাপে দেখা যাবে।
      </p>
      {error && <p className="text-[12.5px] text-red-600 mb-3">{error}</p>}
      <div className="flex gap-2 justify-end">
        <Button variant="secondary" onClick={onClose} disabled={busy}>
          বাতিল
        </Button>
        <Button variant="destructive" onClick={submit} disabled={busy || missing}>
          {busy ? 'হচ্ছে…' : 'প্রত্যাখ্যান নিশ্চিত করুন'}
        </Button>
      </div>
    </Modal>
  )
}
