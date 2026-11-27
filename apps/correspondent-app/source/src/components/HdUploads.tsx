/**
 * r46 — the HD originals still going up in the background (after their quick
 * 720p copies). A small bar above the bottom menu, on every page.
 */
import { useState, useSyncExternalStore } from 'react'
import { hdQueue } from '../lib/clipPipeline'
import { HD_PREF_KEY, hdPref, type HdPref } from '../lib/videoTarget'
import { bnNumber } from './ui'

export default function HdUploads() {
  const jobs = useSyncExternalStore(hdQueue.subscribe, hdQueue.list)
  const [open, setOpen] = useState(false)
  const [pref, setPref] = useState<HdPref>(hdPref())
  const shown = jobs.filter((j) => j.state !== 'done' || open)
  if (!jobs.length || (!shown.length && !open)) return null
  const active = jobs.find((j) => j.state === 'sending')
  const waiting = jobs.filter((j) => j.state === 'waiting' || j.state === 'paused').length
  const failed = jobs.filter((j) => j.state === 'error').length
  const savePref = (p: HdPref) => {
    setPref(p)
    try {
      localStorage.setItem(HD_PREF_KEY, p)
    } catch {
      // ignore
    }
  }
  return (
    <div className="fixed left-1/2 -translate-x-1/2 bottom-[calc(72px+env(safe-area-inset-bottom))] z-40 w-[min(460px,calc(100%-16px))]" data-testid="hd-uploads">
      <div className="rounded-2xl bg-neutral-900/95 text-white shadow-xl px-3 py-2">
        <button type="button" onClick={() => setOpen((v) => !v)} className="w-full flex items-center gap-2 text-left">
          <span className="text-[11px] font-bold bg-white/15 rounded px-1.5 py-0.5">HD</span>
          <span className="flex-1 text-[12.5px] truncate">
            {active ? `আসল মানের ফাইল পাঠানো হচ্ছে ${bnNumber(active.percent)}%` : failed ? `${bnNumber(failed)}টি HD পাঠানো যায়নি` : waiting ? `${bnNumber(waiting)}টি HD অপেক্ষায়` : 'সব HD পৌঁছেছে ✓'}
            {waiting && active ? ` · আরও ${bnNumber(waiting)}টি` : ''}
          </span>
          <span className="text-[11px] text-white/60">{open ? '▲' : '▼'}</span>
        </button>
        {active && (
          <div className="mt-1.5 h-1 rounded-full bg-white/15 overflow-hidden">
            <div className="h-full bg-emerald-400" style={{ width: `${active.percent}%` }} />
          </div>
        )}
        {open && (
          <div className="mt-2 space-y-1.5">
            {jobs.map((j) => (
              <div key={j.key} className="flex items-center gap-2 text-[11.5px]">
                <span className="flex-1 truncate text-white/80">{j.name}</span>
                <span className="tabular-nums text-white/60">
                  {j.state === 'done' ? '✓' : j.state === 'sending' ? `${bnNumber(j.percent)}%` : j.state === 'error' ? ((j.tries ?? 0) < 6 ? 'আবার চেষ্টা হবে' : 'ব্যর্থ') : j.state === 'paused' ? 'Wi-Fi-র অপেক্ষায়' : 'অপেক্ষায়'}
                </span>
                {(j.state === 'error' || j.state === 'paused') && (
                  <button type="button" onClick={() => hdQueue.retry(j.key)} className="text-emerald-300 font-semibold">
                    আবার
                  </button>
                )}
                <button type="button" onClick={() => hdQueue.remove(j.key)} className="text-white/50" aria-label="সরান">
                  ×
                </button>
              </div>
            ))}
            <div className="flex items-center gap-1 pt-1 border-t border-white/10">
              <span className="text-[11px] text-white/60 mr-1">HD পাঠাবেন:</span>
              {(
                [
                  ['always', 'সবসময়'],
                  ['wifi', 'শুধু Wi-Fi-তে'],
                  ['never', 'পাঠাবেন না'],
                ] as [HdPref, string][]
              ).map(([k, l]) => (
                <button key={k} type="button" onClick={() => savePref(k)} className={`rounded-full px-2 py-0.5 text-[11px] ${pref === k ? 'bg-white text-neutral-900' : 'bg-white/10 text-white/80'}`}>
                  {l}
                </button>
              ))}
            </div>
            <p className="text-[10.5px] text-white/50">
              পাঠানো শেষ না হওয়া পর্যন্ত অ্যাপ সামনে খোলা রাখলে দ্রুত যায়। অ্যাপ বন্ধ হয়ে গেলেও ফাইলটি ফোনে রাখা থাকে — পরের বার অ্যাপ খুললে নিজে থেকেই আবার পাঠানো শুরু হবে।
              এডিটর এর মধ্যেই ৭২০p কপি দিয়ে কাজ শুরু করতে পারবেন।
            </p>
          </div>
        )}
      </div>
    </div>
  )
}
