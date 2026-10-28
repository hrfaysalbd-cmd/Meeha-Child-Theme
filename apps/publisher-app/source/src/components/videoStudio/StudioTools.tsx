/**
 * Round 21.1 — the editor's "সম্পাদনা" tab: real editing tools on the main
 * video — cut parts out of the middle, colour and light, zoom / turn /
 * mirror, and blur or pixelate boxes (faces, number plates, documents).
 */
import { LIMITS, LOOK_FILTERS, keptRanges, type BlurBox, type EditConfig } from '../../lib/videoEdit/config'
import { bnClock } from '../../lib/videoEdit/motion'
import { bnNumber } from '../ui'
import { IconPlus, IconRotate, IconScissors, IconTrash } from '../Icon'
import { Section, Segmented, Slider, SwitchRow, TimeField } from './StudioFields'
import type { Setter } from './StudioPanels'

const pct = (v: number) => `${bnNumber(Math.round(v * 100))}%`

export default function ToolsPanel({
  cfg,
  set,
  range,
  playhead,
  selectedBlur,
  onSelectBlur,
}: {
  cfg: EditConfig
  set: Setter
  range: { start: number; end: number }
  playhead: () => number | null
  selectedBlur: number | null
  onSelectBlur: (i: number | null) => void
}) {
  const look = cfg.look
  const setLook = (patch: Partial<EditConfig['look']>) => set((c) => ({ ...c, look: { ...c.look, ...patch } }))
  const updateCut = (i: number, patch: Partial<EditConfig['cuts'][number]>) => set((c) => ({ ...c, cuts: c.cuts.map((x, j) => (j === i ? { ...x, ...patch } : x)) }))
  const updateBlur = (i: number, patch: Partial<BlurBox>) => set((c) => ({ ...c, blurs: c.blurs.map((x, j) => (j === i ? { ...x, ...patch } : x)) }))
  const kept = keptRanges(cfg.cuts, range.start, range.end)
  const keptLen = kept.reduce((n, r) => n + r.to - r.from, 0)
  const removed = Math.max(0, range.end - range.start - keptLen)

  function addCut() {
    const p = playhead() ?? range.start
    const from = Math.max(range.start, Math.min(p, range.end - 0.5))
    const to = Math.min(range.end, from + 3)
    set((c) => ({ ...c, cuts: [...c.cuts, { from: Math.round(from * 10) / 10, to: Math.round(to * 10) / 10 }] }))
  }
  function addBlur() {
    const p = playhead() ?? range.start
    set((c) => ({ ...c, blurs: [...c.blurs, { x: 0.35, y: 0.3, w: 0.3, h: 0.3, mode: 'blur', at: Math.round(p * 10) / 10, dur: null }] }))
    onSelectBlur(cfg.blurs.length)
  }
  const lookChanged = look.filter !== 'none' || look.brightness !== 1 || look.contrast !== 1 || look.saturation !== 1

  return (
    <div>
      <Section
        title="মাঝখান থেকে কাটুন"
        hint="প্রিভিউ থামিয়ে যে জায়গা বাদ দিতে চান সেখানে “এখান থেকে কাটুন” দিন, তারপর শেষ সময় ঠিক করুন। কাটা অংশ বাদ দিয়ে ভিডিও জোড়া লাগবে।"
      >
        <button
          type="button"
          onClick={addCut}
          disabled={cfg.cuts.length >= LIMITS.cuts}
          data-testid="add-cut"
          className="w-full flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-red-300 bg-red-50 text-red-700 py-2.5 text-[13px] font-semibold disabled:opacity-50"
        >
          <IconScissors className="w-4 h-4" />
          এখান থেকে কাটুন
        </button>
        {cfg.cuts.length > 0 && (
          <div className="flex flex-col gap-2 mt-2.5">
            {cfg.cuts.map((c, i) => (
              <div key={i} className="rounded-xl border border-neutral-200 p-2.5 flex items-end gap-3 flex-wrap" data-testid="cut-row">
                <TimeField
                  label="কোথা থেকে"
                  value={c.from}
                  min={0}
                  max={range.end}
                  onChange={(v) => updateCut(i, { from: v })}
                  onPlayhead={() => {
                    const p = playhead()
                    if (p !== null) updateCut(i, { from: p })
                  }}
                />
                <TimeField
                  label="কোথা পর্যন্ত"
                  value={c.to}
                  min={0}
                  max={range.end}
                  onChange={(v) => updateCut(i, { to: v })}
                  onPlayhead={() => {
                    const p = playhead()
                    if (p !== null) updateCut(i, { to: p })
                  }}
                />
                <button type="button" onClick={() => set((x) => ({ ...x, cuts: x.cuts.filter((_, j) => j !== i) }))} aria-label="সরান" className="ml-auto mb-1.5 text-red-500 hover:text-red-700">
                  <IconTrash className="w-4 h-4" />
                </button>
              </div>
            ))}
            <p className="text-[11.5px] text-neutral-500">
              বাদ যাচ্ছে {bnClock(removed)} · থাকছে {bnClock(keptLen)}
            </p>
          </div>
        )}
      </Section>

      <Section
        title="রং ও আলো"
        hint="অন্ধকারে তোলা ভিডিওর জন্য “রাতের ভিডিও”।"
        right={
          lookChanged ? (
            <button type="button" onClick={() => setLook({ filter: 'none', brightness: 1, contrast: 1, saturation: 1 })} className="text-[11.5px] px-2 py-1 rounded-md bg-neutral-100">
              রিসেট
            </button>
          ) : null
        }
      >
        <Segmented value={look.filter} options={LOOK_FILTERS} onChange={(v) => setLook({ filter: v })} size="sm" />
        <div className="mt-2">
          <Slider label="উজ্জ্বলতা" value={look.brightness} min={0.5} max={1.6} step={0.02} onChange={(v) => setLook({ brightness: v })} format={pct} />
          <Slider label="কনট্রাস্ট" value={look.contrast} min={0.5} max={1.6} step={0.02} onChange={(v) => setLook({ contrast: v })} format={pct} />
          <Slider label="রঙের গাঢ়তা" value={look.saturation} min={0} max={2} step={0.05} onChange={(v) => setLook({ saturation: v })} format={pct} />
        </div>
      </Section>

      <Section title="জুম, ঘোরানো ও উল্টানো" hint="ফোন কাত করে তোলা ভিডিও সোজা করুন, অথবা জুম করে আসল জায়গাটা বড় করুন।">
        <div className="flex flex-wrap gap-1.5 mb-2">
          {([0, 90, 180, 270] as const).map((r) => (
            <button
              key={r}
              type="button"
              onClick={() => setLook({ rotate: r })}
              aria-pressed={look.rotate === r}
              className={`flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-[12px] font-medium ${look.rotate === r ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-neutral-200 text-neutral-600'}`}
            >
              <IconRotate className="w-3.5 h-3.5" style={{ transform: `rotate(${r}deg)` }} />
              {r === 0 ? 'সোজা' : `${bnNumber(r)}°`}
            </button>
          ))}
        </div>
        <SwitchRow label="আয়নার মতো উল্টান" checked={look.flip} onChange={(v) => setLook({ flip: v })} />
        <Slider label="জুম" value={look.zoom} min={1} max={3} step={0.05} onChange={(v) => setLook({ zoom: v, ...(v <= 1.001 ? { panX: 0, panY: 0 } : {}) })} format={(v) => `${bnNumber(v.toFixed(2))}×`} />
        {look.zoom > 1.001 && (
          <>
            <Slider label="বামে ↔ ডানে" value={look.panX} min={-1} max={1} step={0.02} onChange={(v) => setLook({ panX: v })} format={(v) => bnNumber(Math.round(v * 100))} />
            <Slider label="উপরে ↕ নিচে" value={look.panY} min={-1} max={1} step={0.02} onChange={(v) => setLook({ panY: v })} format={(v) => bnNumber(Math.round(v * 100))} />
          </>
        )}
      </Section>

      <Section title="ঝাপসা / মোজাইক" hint="মুখ, গাড়ির নম্বর, ঠিকানা বা নথি ঢাকতে। বাক্সটি প্রিভিউতে টেনে সরান, নিচের ডান কোণ টেনে ছোট-বড় করুন।">
        <button
          type="button"
          onClick={addBlur}
          disabled={cfg.blurs.length >= LIMITS.blurs}
          data-testid="add-blur"
          className="w-full flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-neutral-300 bg-neutral-50 text-neutral-700 py-2.5 text-[13px] font-semibold disabled:opacity-50"
        >
          <IconPlus className="w-4 h-4" />
          ঝাপসা বাক্স যোগ করুন
        </button>
        {cfg.blurs.length > 0 && (
          <div className="flex flex-col gap-2 mt-2.5">
            {cfg.blurs.map((b, i) => (
              <div
                key={i}
                onClick={() => onSelectBlur(i)}
                className={`rounded-xl border p-2.5 cursor-pointer ${selectedBlur === i ? 'border-brand-500 ring-2 ring-brand-500/20' : 'border-neutral-200'}`}
                data-testid="blur-row"
              >
                <div className="flex items-center justify-between gap-2 mb-2">
                  <p className="text-[12.5px] font-semibold text-neutral-800">
                    বাক্স {bnNumber(i + 1)} {selectedBlur === i && <span className="text-[11px] font-normal text-brand-700">· প্রিভিউতে সরানো যাবে</span>}
                  </p>
                  <button
                    type="button"
                    onClick={(e) => {
                      e.stopPropagation()
                      set((c) => ({ ...c, blurs: c.blurs.filter((_, j) => j !== i) }))
                      onSelectBlur(null)
                    }}
                    aria-label="সরান"
                    className="text-red-500 hover:text-red-700"
                  >
                    <IconTrash className="w-4 h-4" />
                  </button>
                </div>
                <Segmented
                  value={b.mode}
                  options={[
                    { id: 'blur', name: 'ঝাপসা' },
                    { id: 'pixel', name: 'মোজাইক' },
                  ]}
                  onChange={(v) => updateBlur(i, { mode: v })}
                  size="sm"
                />
                <div className="flex items-end gap-3 flex-wrap mt-2">
                  <TimeField
                    label="কখন থেকে"
                    value={b.at}
                    min={0}
                    max={range.end}
                    onChange={(v) => updateBlur(i, { at: v })}
                    onPlayhead={() => {
                      const p = playhead()
                      if (p !== null) updateBlur(i, { at: p })
                    }}
                  />
                  {b.dur !== null && (
                    <label className="block">
                      <span className="block text-[11.5px] text-neutral-500 mb-1">কতক্ষণ (সেকেন্ড)</span>
                      <input
                        type="number"
                        min={0.5}
                        step={0.5}
                        value={b.dur}
                        onChange={(e) => updateBlur(i, { dur: Math.max(0.5, Number(e.target.value) || 0.5) })}
                        className="w-[84px] rounded-lg border border-neutral-200 px-2 py-1.5 text-[13px]"
                      />
                    </label>
                  )}
                </div>
                <SwitchRow label="শুরু থেকে শেষ পর্যন্ত" checked={b.dur === null} onChange={(v) => updateBlur(i, { dur: v ? null : 5 })} />
              </div>
            ))}
          </div>
        )}
      </Section>
    </div>
  )
}
