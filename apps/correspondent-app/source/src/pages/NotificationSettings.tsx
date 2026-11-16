import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { TopBar } from '../components/ui'
import { getPushPrefs, setPushPrefs, type PushPrefs } from '../chat/push'
import { PushSheet, usePush } from '../chat/PushSetup'
import { loadSoundPrefs, playSound, saveSoundPrefs, type SoundKind } from '../lib/sounds'

/**
 * r31 — নোটিফিকেশন সেটিংস. Everything starts switched on; a correspondent can
 * turn off any kind of phone notice (kept on the server, so it applies to
 * every phone) or any in-app sound (kept on this phone).
 */

function Switch({ on, onChange, label, hint, testid }: { on: boolean; onChange: (v: boolean) => void; label: string; hint?: string; testid?: string }) {
  return (
    <button type="button" role="switch" aria-checked={on} onClick={() => onChange(!on)} className="w-full flex items-center gap-3 py-3 text-left" data-testid={testid}>
      <span className="flex-1 min-w-0">
        <span className="block text-[14.5px] text-neutral-900">{label}</span>
        {hint && <span className="block text-[12px] text-neutral-500 leading-snug mt-0.5">{hint}</span>}
      </span>
      <span className={`relative w-11 h-6 shrink-0 rounded-full transition ${on ? 'bg-brand-600' : 'bg-neutral-300'}`}>
        <span className={`absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all ${on ? 'left-[22px]' : 'left-0.5'}`} />
      </span>
    </button>
  )
}

const PUSH_ROWS: [keyof PushPrefs, string, string][] = [
  ['chat', 'চ্যাট মেসেজ', 'ডেস্ক আর রুমের নতুন মেসেজ'],
  ['urgent', 'জরুরি মেসেজ', 'জরুরি লেবেলের মেসেজ, নিঃশব্দ রুম থেকেও আসে'],
  ['inbox', 'সংবাদ অনুমোদন, ফেরত ও অন্যান্য', 'আপনার সংবাদ/ভিডিও, আবেদন আর পেমেন্টের খবর'],
]
const SOUND_ROWS: [SoundKind, string][] = [
  ['chat', 'চ্যাট মেসেজের শব্দ'],
  ['urgent', 'জরুরি মেসেজের শব্দ'],
  ['inbox', 'অনুমোদন ও অন্যান্য খবরের শব্দ'],
]
const PUSH_STATE: Record<string, string> = {
  on: '✅ চালু আছে',
  off: 'বন্ধ, চালু করতে চাপুন',
  denied: 'ফোনের সেটিংসে বন্ধ করা',
  unsupported: 'এখানে চলে না',
  'ios-home': 'হোম স্ক্রিনে যোগ করতে হবে',
  webview: 'এই অ্যাপে চলে না',
}

export default function NotificationSettings() {
  const navigate = useNavigate()
  const { state, refresh } = usePush()
  const [sheet, setSheet] = useState(false)
  const [prefs, setPrefs] = useState<PushPrefs | null>(null)
  const [err, setErr] = useState('')
  const [sound, setSound] = useState(loadSoundPrefs)

  useEffect(() => {
    getPushPrefs()
      .then(setPrefs)
      .catch(() => setErr('সেটিংস আনা গেল না। ইন্টারনেট দেখে আবার খুলুন।'))
  }, [])

  const togglePush = async (k: keyof PushPrefs, v: boolean) => {
    if (!prefs) return
    const before = prefs
    setPrefs({ ...prefs, [k]: v })
    try {
      setPrefs(await setPushPrefs({ [k]: v }))
      setErr('')
    } catch {
      setPrefs(before)
      setErr('সেভ হলো না। ইন্টারনেট দেখে আবার চেষ্টা করুন।')
    }
  }
  const setSnd = (p: Partial<typeof sound>) => {
    const next = { ...sound, ...p }
    setSound(next)
    saveSoundPrefs(next)
  }

  return (
    <div className="pb-8" data-testid="notification-settings">
      <TopBar title="নোটিফিকেশন সেটিংস" onBack={() => navigate(-1)} />

      <div className="px-5 pt-4">
        <button type="button" onClick={() => setSheet(true)} className="w-full flex items-center gap-3 rounded-2xl bg-neutral-50 px-4 py-3.5 text-left" data-testid="phone-push-row">
          <span className="text-[22px]">📲</span>
          <span className="flex-1 min-w-0">
            <span className="block text-[14.5px] font-semibold text-neutral-900">ফোনে নোটিফিকেশন</span>
            <span className={`block text-[12.5px] ${state === 'on' ? 'text-emerald-700' : 'text-neutral-500'}`}>{state ? PUSH_STATE[state] : '…'}</span>
          </span>
          <span className="text-neutral-400">›</span>
        </button>

        <h2 className="mt-6 text-[13px] font-semibold text-neutral-500">ফোনে কী কী আসবে</h2>
        <div className="divide-y divide-neutral-100">
          {PUSH_ROWS.map(([k, l, h]) => (
            <Switch key={k} on={prefs ? prefs[k] : true} onChange={(v) => void togglePush(k, v)} label={l} hint={h} testid={`push-pref-${k}`} />
          ))}
        </div>
        {prefs && !prefs.urgent && <p className="text-[12px] text-amber-700 mt-1">জরুরি মেসেজ বন্ধ থাকলে ডেস্কের জরুরি নির্দেশনা দেরিতে চোখে পড়তে পারে।</p>}
        {err && <p className="text-[12.5px] text-red-600 mt-2">{err}</p>}

        <h2 className="mt-6 text-[13px] font-semibold text-neutral-500">অ্যাপ খোলা থাকলে শব্দ</h2>
        <div className="divide-y divide-neutral-100">
          {SOUND_ROWS.map(([k, l]) => (
            <div key={k} className="flex items-center gap-2">
              <div className="flex-1">
                <Switch on={sound[k]} onChange={(v) => setSnd({ [k]: v })} label={l} testid={`sound-${k}`} />
              </div>
              <button type="button" onClick={() => playSound(k, sound.volume)} className="shrink-0 rounded-full bg-neutral-100 px-3 py-1.5 text-[12.5px] text-brand-700 font-medium">
                ▶ শুনুন
              </button>
            </div>
          ))}
        </div>
        <label className="flex items-center gap-3 mt-3 text-[13.5px] text-neutral-700">
          <span>🔉</span>
          <input type="range" min={0} max={1} step={0.05} value={sound.volume} onChange={(e) => setSnd({ volume: Number(e.target.value) })} className="flex-1 accent-brand-600" aria-label="শব্দের মাত্রা" />
          <span>🔊</span>
        </label>
        <p className="mt-5 text-[11.5px] text-neutral-400 leading-relaxed">অ্যাপ বন্ধ থাকলে নোটিফিকেশন আসে ফোনের নিজের শব্দে। ফোনের Settings → Apps → মিহা সংবাদদাতা → Notifications থেকে প্রতিটি ধরনের শব্দ আলাদা করেও বদলানো যায়।</p>
      </div>

      <PushSheet
        open={sheet}
        onClose={() => {
          setSheet(false)
          refresh()
        }}
      />
    </div>
  )
}
