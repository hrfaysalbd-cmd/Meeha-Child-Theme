import type { Video } from '../lib/api'
import { IconCoin } from './Icon'
import { bnNumber } from './ui'

/**
 * Round 12 (CONTRACT-R12 §C): a video's payment, shown exactly like an
 * article's — nothing at all when no payment is configured (not a zero),
 * amber + "পেন্ডিং" while the publisher hasn't confirmed an auto-applied
 * default rate, green once approved.
 */
export default function VideoPayment({ video, compact, className = '' }: { video: Video; compact?: boolean; className?: string }) {
  if (!video.payment) return null
  const pending = video.payment_status === 'pending'
  const tone = pending ? 'text-amber-600' : 'text-emerald-600'
  if (compact) {
    return (
      <span className={`flex items-center gap-1 text-[12px] font-medium ${tone} ${className}`} data-testid="video-payment">
        <IconCoin className="w-3.5 h-3.5" /> ৳ {bnNumber(video.payment)}
        {pending && <span className="text-[11px]">(পেন্ডিং)</span>}
      </span>
    )
  }
  return (
    <p className={`flex items-center gap-1.5 text-[13.5px] font-medium ${tone} ${className}`} data-testid="video-payment">
      <IconCoin className="w-4 h-4" /> এই ভিডিওর ইনকাম: ৳ {bnNumber(video.payment)}
      <span
        className={`ml-1 text-[11px] font-medium px-1.5 py-0.5 rounded-full ${
          pending ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'
        }`}
      >
        {pending ? 'পেন্ডিং' : 'অনুমোদিত'}
      </span>
    </p>
  )
}
