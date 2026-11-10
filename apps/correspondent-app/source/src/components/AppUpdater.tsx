import { useEffect, useState } from 'react'
import { apiRequest } from '../lib/api'
import { installedBuild, openExternal, type AndroidRelease } from '../lib/native'

/**
 * r31 — Android app only. The screens live inside the APK, so a new version
 * means a new APK. On start and whenever the app comes back to the front,
 * this asks the site for the newest build and offers it; a build below the
 * site's minimum is shown as a must-update screen.
 */
export function AppUpdater() {
  const [rel, setRel] = useState<AndroidRelease | null>(null)
  const [must, setMust] = useState(false)
  const [hidden, setHidden] = useState(false)

  useEffect(() => {
    let off: (() => void) | undefined
    const check = async () => {
      try {
        const [mine, latest] = await Promise.all([installedBuild(), apiRequest<AndroidRelease>('/app/android', { auth: false })])
        if (latest.version_code > mine.code && latest.url) {
          setRel(latest)
          setMust(!!latest.min_code && mine.code < latest.min_code)
        } else setRel(null)
      } catch {
        // offline: next time
      }
    }
    void check()
    void import('@capacitor/app').then(async ({ App }) => {
      const h = await App.addListener('resume', () => void check())
      off = () => void h.remove()
    })
    return () => off?.()
  }, [])

  if (!rel || (hidden && !must)) return null
  const get = () => rel.url && void openExternal(rel.url)
  if (must) {
    return (
      <div className="fixed inset-0 z-[100] bg-white flex flex-col items-center justify-center px-8 text-center" data-testid="app-must-update">
        <div className="text-[44px]">⬆️</div>
        <h1 className="mt-3 text-[18px] font-bold text-neutral-900">অ্যাপের নতুন ভার্সন লাগবে</h1>
        <p className="mt-2 text-[14px] text-neutral-600 leading-relaxed">এই ভার্সনটা আর চলবে না। নতুন ভার্সন ({rel.version_name}) নামিয়ে ইনস্টল করুন, আপনার লগইন ও তথ্য থেকে যাবে।</p>
        <button type="button" onClick={get} className="mt-6 w-full max-w-[280px] rounded-xl bg-brand-600 text-white py-3 text-[15px] font-bold">
          নতুন ভার্সন নামান
        </button>
      </div>
    )
  }
  return (
    <div className="fixed z-[70] left-3 right-3 top-[calc(10px+env(safe-area-inset-top))] mx-auto max-w-[456px]" data-testid="app-update">
      <div className="flex items-center gap-3 rounded-2xl bg-neutral-900 text-white px-4 py-3 shadow-xl">
        <span className="text-[20px]">⬆️</span>
        <div className="min-w-0 flex-1">
          <div className="text-[13.5px] font-bold">নতুন ভার্সন এসেছে {rel.version_name && `(${rel.version_name})`}</div>
          {rel.notes && <div className="text-[12px] text-white/80 truncate">{rel.notes}</div>}
        </div>
        <button type="button" onClick={get} className="rounded-full bg-white text-neutral-900 px-3 py-1.5 text-[12.5px] font-bold">
          আপডেট
        </button>
        <button type="button" onClick={() => setHidden(true)} className="text-white/60 text-[18px] leading-none" aria-label="পরে">
          ×
        </button>
      </div>
    </div>
  )
}
