import { useCallback, useEffect, useState } from 'react'
import { Sheet, Spinner } from './bits'
import { disablePush, enablePush, pushState, testPush, type PushState } from './push'
import { NATIVE_PUSH } from '../lib/pushConfig'
import { bnNum } from './util'

const HIDE_KEY = 'nros_push_banner_hidden'

export function usePush() {
  const [state, setState] = useState<PushState | null>(null)
  const refresh = useCallback(() => {
    pushState()
      .then(setState)
      .catch(() => setState('unsupported'))
  }, [])
  useEffect(refresh, [refresh])
  return { state, refresh, setState }
}

const HELP: Record<string, string> = {
  webview: 'আপনি অন্য একটা অ্যাপের ভেতর থেকে খুলেছেন, সেখানে নোটিফিকেশন ও মাইক চলে না। Chrome-এ meeha-র ঠিকানা খুলুন, বা মিহার নিজস্ব অ্যাপ ইনস্টল করুন।',
  'ios-home': 'iPhone-এ: নিচের Share (⬆️) চেপে “Add to Home Screen” দিন, তারপর হোম স্ক্রিনের মিহা আইকন থেকে খুলে এখানে চালু করুন (iOS ১৬.৪ বা নতুন লাগবে)।',
  unsupported: 'এই ব্রাউজারে পুশ নোটিফিকেশন চলে না। Chrome বা Edge-এ খুলুন।',
  denied: 'আগে “Block” দেওয়া হয়েছে। ঠিকানার পাশের 🔒 → Notifications → Allow করে পাতাটা আবার খুলুন।',
}
/** In the Android app the fixes are in the phone's settings. */
const NATIVE_HELP: Record<string, string> = {
  unsupported: 'এই APK-তে এখনো নোটিফিকেশন যুক্ত হয়নি। নতুন ভার্সন এলে চালু হবে।',
  denied: 'নোটিফিকেশন বন্ধ করা আছে। ফোনের Settings → Apps → মিহা সংবাদদাতা → Notifications চালু করুন, তারপর অ্যাপে ফিরে আসুন।',
}

/** The card at the top of the chat list until this device gets notices. */
export function PushBanner({ onOpen }: { onOpen: () => void }) {
  const { state } = usePush()
  const [hidden, setHidden] = useState(() => {
    const t = Number(localStorage.getItem(HIDE_KEY) || 0)
    return Date.now() - t < 7 * 86400000
  })
  if (!state || state === 'on' || hidden) return null
  return (
    <div className="mx-3 mb-2 rounded-2xl bg-gradient-to-r from-brand-600 to-red-500 text-white px-4 py-3 shadow-sm" data-testid="push-banner">
      <div className="flex items-start gap-3">
        <span className="text-[22px] leading-none mt-0.5">🔔</span>
        <div className="min-w-0 flex-1">
          <div className="text-[14px] font-bold">অ্যাপ বন্ধ থাকলেও মেসেজ পান</div>
          <div className="text-[12px] text-white/90 leading-snug mt-0.5">জরুরি খবর, ডেস্কের মেসেজ আর অনুমোদন ফোনে নোটিফিকেশন হয়ে আসবে।</div>
          <div className="mt-2 flex gap-2">
            <button type="button" onClick={onOpen} className="rounded-full bg-white text-brand-700 px-3.5 py-1.5 text-[12.5px] font-bold" data-testid="push-banner-on">
              চালু করুন
            </button>
            <button
              type="button"
              onClick={() => {
                localStorage.setItem(HIDE_KEY, String(Date.now()))
                setHidden(true)
              }}
              className="rounded-full px-3 py-1.5 text-[12.5px] text-white/90"
            >
              পরে
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

/** Settings sheet: on/off, a test notice, and help when this device can't. */
export function PushSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { state, refresh, setState } = usePush()
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  useEffect(() => {
    if (open) {
      setMsg('')
      refresh()
    }
  }, [open, refresh])

  const turnOn = async () => {
    setBusy(true)
    setMsg('')
    const r = await enablePush()
    setBusy(false)
    if (r === 'error') setMsg('চালু করা গেল না। ইন্টারনেট দেখে আবার চেষ্টা করুন।')
    else setState(r)
    if (r === 'on') {
      localStorage.removeItem(HIDE_KEY)
      setMsg('চালু হয়েছে ✅ নিচের “পরীক্ষা” চেপে দেখুন।')
    }
  }
  const turnOff = async () => {
    setBusy(true)
    await disablePush()
    setBusy(false)
    refresh()
  }
  const test = async () => {
    setBusy(true)
    setMsg('')
    try {
      const r = await testPush()
      setMsg(r.sent ? `পাঠানো হয়েছে (${bnNum(r.sent)}টি ডিভাইসে)। অ্যাপ সামনে খোলা থাকলে নোটিফিকেশন দেখাবে না, অ্যাপ থেকে বেরিয়ে দেখুন।` : 'পাঠানো গেল না। বন্ধ করে আবার চালু করুন।')
    } catch (e) {
      setMsg((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Sheet open={open} onClose={onClose} title="📲 ফোনে নোটিফিকেশন">
      <div className="px-5 pb-5" data-testid="push-sheet">
        {!state ? (
          <div className="py-6 flex justify-center">
            <Spinner />
          </div>
        ) : state === 'on' ? (
          <>
            <div className="rounded-xl bg-emerald-50 text-emerald-800 px-4 py-3 text-[13.5px]">✅ এই ডিভাইসে চালু আছে। অ্যাপ বন্ধ থাকলেও চ্যাট, জরুরি খবর আর ইনবক্সের নোটিফিকেশন আসবে।</div>
            <div className="mt-4 flex gap-2">
              <button type="button" disabled={busy} onClick={test} className="flex-1 rounded-xl bg-brand-600 text-white py-2.5 text-[14px] font-semibold" data-testid="push-test">
                পরীক্ষা পাঠান
              </button>
              <button type="button" disabled={busy} onClick={turnOff} className="rounded-xl border border-neutral-300 px-4 py-2.5 text-[14px] text-neutral-700">
                বন্ধ করুন
              </button>
            </div>
          </>
        ) : state === 'off' ? (
          <>
            <p className="text-[13.5px] text-neutral-700 leading-relaxed">চালু করলে ব্রাউজার অনুমতি চাইবে, তখন <b>Allow</b> দিন। প্রতিটি ফোন/কম্পিউটারে একবার করে চালু করতে হয়।</p>
            <button type="button" disabled={busy} onClick={turnOn} className="mt-4 w-full rounded-xl bg-brand-600 text-white py-3 text-[15px] font-bold" data-testid="push-enable">
              {busy ? 'চালু হচ্ছে…' : '🔔 নোটিফিকেশন চালু করুন'}
            </button>
          </>
        ) : (
          <p className="rounded-xl bg-amber-50 text-amber-900 px-4 py-3 text-[13.5px] leading-relaxed" data-testid="push-help">
            {(NATIVE_PUSH && NATIVE_HELP[state]) || HELP[state]}
          </p>
        )}
        {msg && <p className="mt-3 text-[13px] text-neutral-700">{msg}</p>}
        <p className="mt-4 text-[11.5px] text-neutral-400 leading-relaxed">নোটিফিকেশনের শব্দ ফোনের নিজের শব্দ হবে। অ্যাপ খোলা থাকলে মিহার নিজের শব্দ (চ্যাট/জরুরি) বাজে।</p>
      </div>
    </Sheet>
  )
}
