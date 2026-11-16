import { useEffect, useState } from 'react'
import { IS_NATIVE } from '../lib/native'

/**
 * r31 — on an Android phone's browser: "install the Meeha app" (only once the
 * APK is on the site, i.e. /download/android.json answers).
 */
export function AppDownloadCard({ className = '' }: { className?: string }) {
  const [info, setInfo] = useState<{ url: string; version_name?: string } | null>(null)
  useEffect(() => {
    if (IS_NATIVE || !/Android/i.test(navigator.userAgent)) return
    fetch(`/download/android.json?t=${Date.now()}`, { cache: 'no-store' })
      .then((r) => (r.ok ? r.json() : null))
      .then((j) => j?.url && setInfo({ url: j.url, version_name: j.version_name }))
      .catch(() => {})
  }, [])
  if (!info) return null
  return (
    <a href={info.url} className={`flex items-center gap-3 rounded-2xl border border-brand-600/20 bg-brand-50 px-4 py-3 ${className}`} data-testid="app-download">
      <span className="w-10 h-10 shrink-0 rounded-xl bg-brand-600 text-white flex items-center justify-center font-bold">মি</span>
      <span className="min-w-0 flex-1 text-left">
        <span className="block text-[14px] font-bold text-neutral-900">মিহা সংবাদদাতা অ্যাপ নামান</span>
        <span className="block text-[12px] text-neutral-600 leading-snug">Android অ্যাপে নোটিফিকেশন, মাইক আর ক্যামেরা সরাসরি চলে{info.version_name ? ` · ${info.version_name}` : ''}</span>
      </span>
      <span className="text-brand-700 text-[13px] font-bold">APK ⬇</span>
    </a>
  )
}
