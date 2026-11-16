/**
 * Round 30 — the "show / hide" bar at the top of the studio: tick what you
 * use, the rest is hidden. Kept per browser, so every desk can keep the
 * studio as simple or as full as it likes.
 */
import { useState } from 'react'

export interface StudioUi {
  /** Tabs that are hidden. */
  hiddenTabs: string[]
  chips: boolean
  timeline: boolean
  tips: boolean
  thumb: boolean
  /** Chime when a render or upload ends. */
  sound: boolean
  /** Browser notification when the tab is not in front. */
  browser: boolean
  /** The bar itself folded to one line. */
  folded: boolean
}

export const UI_KEY = 'meeha-studio:ui'
export const DEFAULT_UI: StudioUi = { hiddenTabs: [], chips: true, timeline: true, tips: true, thumb: true, sound: true, browser: true, folded: true }

export function readUi(): StudioUi {
  try {
    const raw = JSON.parse(localStorage.getItem(UI_KEY) ?? 'null') as Partial<StudioUi> | null
    if (!raw || typeof raw !== 'object') return DEFAULT_UI
    const b = (v: unknown, d: boolean) => (typeof v === 'boolean' ? v : d)
    return {
      hiddenTabs: Array.isArray(raw.hiddenTabs) ? raw.hiddenTabs.filter((x): x is string => typeof x === 'string') : [],
      chips: b(raw.chips, true),
      timeline: b(raw.timeline, true),
      tips: b(raw.tips, true),
      thumb: b(raw.thumb, true),
      sound: b(raw.sound, true),
      browser: b(raw.browser, true),
      folded: b(raw.folded, true),
    }
  } catch {
    return DEFAULT_UI
  }
}

export function writeUi(ui: StudioUi) {
  try {
    localStorage.setItem(UI_KEY, JSON.stringify(ui))
  } catch {
    // private mode: the choice lasts for this visit only
  }
}

function Tick({ label, checked, onChange, disabled, testId }: { label: string; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean; testId?: string }) {
  return (
    <label
      className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12px] font-medium cursor-pointer select-none whitespace-nowrap ${
        checked ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-neutral-200 bg-white text-neutral-500'
      } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
    >
      <input type="checkbox" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} className="accent-[#025AB2] w-3.5 h-3.5" data-testid={testId} />
      {label}
    </label>
  )
}

export default function StudioViewBar({
  ui,
  setUi,
  tabs,
  notifyState,
  onAskNotify,
}: {
  ui: StudioUi
  setUi: (f: (u: StudioUi) => StudioUi) => void
  tabs: { id: string; name: string }[]
  notifyState: NotificationPermission | 'unsupported'
  onAskNotify: () => void
}) {
  const [, force] = useState(0)
  const shownTabs = tabs.filter((t) => !ui.hiddenTabs.includes(t.id))
  const hiddenCount = ui.hiddenTabs.filter((id) => tabs.some((t) => t.id === id)).length + (['chips', 'timeline', 'tips', 'thumb'] as const).filter((k) => !ui[k]).length
  return (
    <div className="mb-4 rounded-xl border border-neutral-200 bg-white px-3 py-2" data-testid="view-bar">
      <div className="flex items-center gap-2 flex-wrap">
        <button
          type="button"
          onClick={() => setUi((u) => ({ ...u, folded: !u.folded }))}
          className="text-[12.5px] font-semibold text-neutral-800 flex items-center gap-1.5"
          aria-expanded={!ui.folded}
          data-testid="view-bar-toggle"
        >
          <span aria-hidden>☑</span>
          স্টুডিওতে কী কী দেখাবে
          <span className="text-[11.5px] font-normal text-neutral-500">
            {hiddenCount ? `(${hiddenCount}টি লুকানো)` : '(সব দেখাচ্ছে)'} · {ui.folded ? 'বদলান ▼' : 'বন্ধ ▲'}
          </span>
        </button>
        {!ui.folded && (
          <button
            type="button"
            onClick={() => setUi((u) => ({ ...DEFAULT_UI, sound: u.sound, browser: u.browser, folded: false }))}
            className="ml-auto text-[11.5px] text-brand-700 underline"
          >
            সব দেখান
          </button>
        )}
      </div>
      {!ui.folded && (
        <div className="mt-2 space-y-2">
          <div>
            <p className="text-[11px] text-neutral-400 mb-1">ট্যাব (অন্তত একটি থাকবে)</p>
            <div className="flex flex-wrap gap-1.5">
              {tabs.map((t) => {
                const on = !ui.hiddenTabs.includes(t.id)
                return (
                  <Tick
                    key={t.id}
                    label={t.name}
                    checked={on}
                    disabled={on && shownTabs.length <= 1}
                    testId={`view-tab-${t.id}`}
                    onChange={(v) => setUi((u) => ({ ...u, hiddenTabs: v ? u.hiddenTabs.filter((x) => x !== t.id) : [...u.hiddenTabs, t.id] }))}
                  />
                )
              })}
            </div>
          </div>
          <div>
            <p className="text-[11px] text-neutral-400 mb-1">অংশ</p>
            <div className="flex flex-wrap gap-1.5">
              <Tick label="এক ক্লিকে যোগ (চিপ)" checked={ui.chips} onChange={(v) => setUi((u) => ({ ...u, chips: v }))} testId="view-chips" />
              <Tick label="টাইমলাইন" checked={ui.timeline} onChange={(v) => setUi((u) => ({ ...u, timeline: v }))} testId="view-timeline" />
              <Tick label="কীবোর্ড টিপস" checked={ui.tips} onChange={(v) => setUi((u) => ({ ...u, tips: v }))} testId="view-tips" />
              <Tick label="থাম্বনেইল স্টুডিও" checked={ui.thumb} onChange={(v) => setUi((u) => ({ ...u, thumb: v }))} testId="view-thumb" />
            </div>
          </div>
          <div>
            <p className="text-[11px] text-neutral-400 mb-1">ভিডিও তৈরি বা আপলোড শেষ হলে</p>
            <div className="flex flex-wrap gap-1.5 items-center">
              <Tick label="শব্দ বাজবে" checked={ui.sound} onChange={(v) => setUi((u) => ({ ...u, sound: v }))} testId="view-sound" />
              <Tick
                label="ব্রাউজার নোটিফিকেশন"
                checked={ui.browser && notifyState === 'granted'}
                disabled={notifyState === 'unsupported' || notifyState === 'denied'}
                testId="view-browser"
                onChange={(v) => {
                  setUi((u) => ({ ...u, browser: v }))
                  if (v && notifyState !== 'granted') {
                    onAskNotify()
                    setTimeout(() => force((x) => x + 1), 500)
                  }
                }}
              />
              {notifyState === 'denied' && <span className="text-[11px] text-neutral-400">ব্রাউজারে নোটিফিকেশন বন্ধ করা আছে — ঠিকানার পাশের তালা চিহ্ন থেকে চালু করা যায়।</span>}
              {notifyState === 'unsupported' && <span className="text-[11px] text-neutral-400">এই ব্রাউজারে নোটিফিকেশন নেই, শব্দ ও ট্যাবের লেখা জ্বলবে।</span>}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
