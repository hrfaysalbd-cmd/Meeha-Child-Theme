import { useEffect, useState, useSyncExternalStore } from 'react'
import { netSnapshot, onNet, pageLoaderShown } from '../lib/netActivity'

/**
 * r31 — the Meeha loading mark: the red "মি" tile breathing inside a turning
 * ring. Used for page loads and, small, as the slow-network / upload badge.
 */
export function MeehaMark({ size = 44, label, page }: { size?: number; label?: string; page?: boolean }) {
  useEffect(() => (page ? pageLoaderShown() : undefined), [page])
  return (
    <span className="inline-flex flex-col items-center gap-2" role="status" aria-label={label || 'লোড হচ্ছে'}>
      <span className="relative inline-block" style={{ width: size, height: size }}>
        <svg className="absolute inset-0 meeha-ring" viewBox="0 0 48 48" width={size} height={size} aria-hidden>
          <circle cx="24" cy="24" r="21" fill="none" stroke="#fde2e2" strokeWidth="3" />
          <circle cx="24" cy="24" r="21" fill="none" stroke="#b91c1c" strokeWidth="3" strokeLinecap="round" strokeDasharray="34 98" />
        </svg>
        <span
          className="absolute meeha-tile rounded-[28%] bg-brand-600 text-white font-bold flex items-center justify-center shadow-md shadow-brand-600/30"
          style={{ inset: size * 0.19, fontSize: size * 0.3 }}
        >
          মি
        </span>
      </span>
      {label && <span className="text-[12.5px] text-neutral-500">{label}</span>}
    </span>
  )
}

const snap = () => {
  const s = netSnapshot()
  return s.uploads > 0 ? 2 : s.loads > 0 && s.pageLoaders === 0 ? 1 : 0
}

/**
 * Floating badge at the top: appears only when something takes longer than
 * a moment (a quick answer shows nothing), says "আপলোড হচ্ছে…" for uploads.
 */
export function NetActivityBadge() {
  const state = useSyncExternalStore(onNet, snap, () => 0)
  const [show, setShow] = useState(0)
  useEffect(() => {
    if (!state) {
      const t = setTimeout(() => setShow(0), 250)
      return () => clearTimeout(t)
    }
    const t = setTimeout(() => setShow(state), state === 2 ? 0 : 700)
    return () => clearTimeout(t)
  }, [state])
  if (!show) return null
  return (
    <div className="pointer-events-none fixed left-1/2 -translate-x-1/2 top-[calc(10px+env(safe-area-inset-top))] z-[65]" data-testid="net-badge">
      <div className="flex items-center gap-2 rounded-full bg-white/95 backdrop-blur pl-1.5 pr-3.5 py-1.5 shadow-lg ring-1 ring-black/5">
        <MeehaMark size={26} />
        <span className="text-[12.5px] font-medium text-neutral-700">{show === 2 ? 'আপলোড হচ্ছে…' : 'লোড হচ্ছে…'}</span>
      </div>
    </div>
  )
}
