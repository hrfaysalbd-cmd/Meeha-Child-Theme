import { useEffect, useRef, useState } from 'react'
import { apiRequest } from '../lib/api'
import { installDownloaded, installedBuild, openExternal, openInstallSettings, updateInApp, type AndroidRelease } from '../lib/native'

/**
 * r31 — Android app only. The screens live inside the APK, so a new version
 * means a new APK. On start and whenever the app comes back to the front,
 * this asks the site for the newest build and offers it; a build below the
 * site's minimum is shown as a must-update screen.
 *
 * r35 — the update downloads inside the app (no outside browser) and opens
 * Android's own installer. Android always shows its install screen — no app
 * can install silently — and the very first time asks once to allow
 * installs from this app; after that it is one tap.
 */
type Step = 'idle' | 'loading' | 'permission' | 'installing' | 'error'

export function AppUpdater() {
  const [rel, setRel] = useState<AndroidRelease | null>(null)
  const [must, setMust] = useState(false)
  const [hidden, setHidden] = useState(false)
  const [step, setStep] = useState<Step>('idle')
  const [pct, setPct] = useState(0)
  const [err, setErr] = useState('')
  const stepRef = useRef<Step>('idle')
  stepRef.current = step

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
      const h = await App.addListener('resume', () => {
        // back from Android's "allow installs" screen: carry on
        if (stepRef.current === 'permission') {
          void installDownloaded()
            .then((r) => setStep(r === 'permission' ? 'permission' : 'installing'))
            .catch(() => setStep('error'))
        } else void check()
      })
      off = () => void h.remove()
    })
    return () => off?.()
  }, [])

  if (!rel || (hidden && !must)) return null

  const get = async () => {
    if (!rel.url) return
    setErr('')
    setPct(0)
    setStep('loading')
    try {
      const r = await updateInApp(rel, setPct)
      setStep(r === 'permission' ? 'permission' : 'installing')
    } catch (e) {
      const msg = e instanceof Error ? e.message : ''
      if (/not implemented|UNIMPLEMENTED/i.test(msg)) {
        // an older APK without the in-app installer: the browser, as before
        setStep('idle')
        void openExternal(rel.url)
        return
      }
      setErr(msg || 'ডাউনলোড হয়নি')
      setStep('error')
    }
  }

  const body =
    step === 'loading' ? (
      <div className="w-full">
        <div className="h-2 rounded-full bg-white/20 overflow-hidden">
          <div className="h-full bg-white rounded-full transition-[width]" style={{ width: `${Math.round(pct * 100)}%` }} />
        </div>
        <p className="mt-1.5 text-[12px] opacity-80">নামছে… {String(Math.round(pct * 100)).replace(/\d/g, (d) => '০১২৩৪৫৬৭৮৯'[+d])}%</p>
      </div>
    ) : step === 'permission' ? (
      <div>
        <p className="text-[12.5px] leading-relaxed opacity-90">প্রথমবার Android একবার অনুমতি চায়: পরের স্ক্রিনে “এই উৎস থেকে অনুমতি দিন” চালু করে ফিরে আসুন, ইনস্টল নিজেই শুরু হবে।</p>
        <button type="button" onClick={() => void openInstallSettings()} className="mt-2 rounded-full bg-white text-neutral-900 px-3 py-1.5 text-[12.5px] font-bold">
          অনুমতি দিন
        </button>
      </div>
    ) : step === 'installing' ? (
      <div>
        <p className="text-[12.5px] opacity-90">Android-এর স্ক্রিনে “আপডেট” চাপুন। স্ক্রিনটা বন্ধ হয়ে গেলে নিচে চাপুন।</p>
        <button type="button" onClick={() => void installDownloaded().then((r) => setStep(r === 'permission' ? 'permission' : 'installing'))} className="mt-2 rounded-full bg-white text-neutral-900 px-3 py-1.5 text-[12.5px] font-bold">
          আবার ইনস্টল খুলুন
        </button>
      </div>
    ) : step === 'error' ? (
      <div>
        <p className="text-[12.5px] opacity-90">আপডেট নামানো যায়নি: {err}</p>
        <div className="mt-2 flex gap-2">
          <button type="button" onClick={() => void get()} className="rounded-full bg-white text-neutral-900 px-3 py-1.5 text-[12.5px] font-bold">
            আবার চেষ্টা
          </button>
          <button type="button" onClick={() => rel.url && void openExternal(rel.url)} className="rounded-full bg-white/15 px-3 py-1.5 text-[12.5px] font-semibold">
            ব্রাউজারে নামান
          </button>
        </div>
      </div>
    ) : null

  if (must) {
    return (
      <div className="fixed inset-0 z-[100] bg-white flex flex-col items-center justify-center px-8 text-center" data-testid="app-must-update">
        <div className="text-[44px]">⬆️</div>
        <h1 className="mt-3 text-[18px] font-bold text-neutral-900">অ্যাপের নতুন ভার্সন লাগবে</h1>
        <p className="mt-2 text-[14px] text-neutral-600 leading-relaxed">এই ভার্সনটা আর চলবে না। নতুন ভার্সন ({rel.version_name}) নামিয়ে ইনস্টল করুন, আপনার লগইন ও তথ্য থেকে যাবে।</p>
        {step === 'idle' ? (
          <button type="button" onClick={() => void get()} className="mt-6 w-full max-w-[280px] rounded-xl bg-brand-600 text-white py-3 text-[15px] font-bold">
            আপডেট করুন
          </button>
        ) : (
          <div className="mt-6 w-full max-w-[300px] rounded-2xl bg-neutral-900 text-white p-4 text-left">{body}</div>
        )}
      </div>
    )
  }
  return (
    <div className="fixed z-[70] left-3 right-3 top-[calc(10px+env(safe-area-inset-top))] mx-auto max-w-[456px]" data-testid="app-update">
      <div className="rounded-2xl bg-neutral-900 text-white px-4 py-3 shadow-xl">
        <div className="flex items-center gap-3">
          <span className="text-[20px]">⬆️</span>
          <div className="min-w-0 flex-1">
            <div className="text-[13.5px] font-bold">নতুন ভার্সন এসেছে {rel.version_name && `(${rel.version_name})`}</div>
            {rel.notes && step === 'idle' && <div className="text-[12px] text-white/80 truncate">{rel.notes}</div>}
          </div>
          {step === 'idle' && (
            <button type="button" onClick={() => void get()} className="rounded-full bg-white text-neutral-900 px-3 py-1.5 text-[12.5px] font-bold">
              আপডেট
            </button>
          )}
          {step !== 'loading' && (
            <button type="button" onClick={() => setHidden(true)} className="text-white/60 text-[18px] leading-none" aria-label="পরে">
              ×
            </button>
          )}
        </div>
        {body && <div className="mt-2.5">{body}</div>}
      </div>
    </div>
  )
}
