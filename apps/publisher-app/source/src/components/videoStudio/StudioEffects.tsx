/**
 * Round 23 — the editor's "ইফেক্ট" tab: transitions between clips, slow /
 * fast motion, freeze frames with a line of text, arrow / circle stickers
 * and a second video (picture-in-picture or side by side).
 */
import { LIMITS, SPEED_RATES, STICKERS, STICKER_COLORS, TRANSITIONS, type EditConfig, type Freeze, type Pip, type SpeedRange, type Sticker } from '../../lib/videoEdit/config'
import { bnNumber } from '../ui'
import { IconPlus, IconTrash, IconVideo } from '../Icon'
import { Section, Segmented, Slider, SwitchRow, TextInput, TimeField } from './StudioFields'
import type { Setter } from './StudioPanels'

const r1 = (v: number) => Math.round(v * 10) / 10
const rateName = (r: number) => `${bnNumber(r)}×`

function DurField({ value, onChange, min = 0.5, max = 600, label = 'কতক্ষণ (সেকেন্ড)' }: { value: number; onChange: (v: number) => void; min?: number; max?: number; label?: string }) {
  return (
    <label className="block">
      <span className="block text-[11.5px] text-neutral-500 mb-1">{label}</span>
      <input
        type="number"
        min={min}
        max={max}
        step={0.5}
        value={value}
        onChange={(e) => onChange(Math.min(max, Math.max(min, Number(e.target.value) || min)))}
        className="w-[84px] rounded-lg border border-neutral-200 px-2 py-1.5 text-[13px]"
      />
    </label>
  )
}

function Trash({ onClick }: { onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation()
        onClick()
      }}
      aria-label="সরান"
      className="text-red-500 hover:text-red-700"
    >
      <IconTrash className="w-4 h-4" />
    </button>
  )
}

const addBtn = 'w-full flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-neutral-300 bg-neutral-50 text-neutral-700 py-2.5 text-[13px] font-semibold disabled:opacity-50'

export default function EffectsPanel({
  cfg,
  set,
  range,
  playhead,
  selectedSticker,
  onSelectSticker,
  onPickPip,
}: {
  cfg: EditConfig
  set: Setter
  range: { start: number; end: number }
  playhead: () => number | null
  selectedSticker: number | null
  onSelectSticker: (i: number | null) => void
  onPickPip: () => void
}) {
  const here = () => {
    const p = playhead() ?? range.start
    return r1(Math.max(range.start, Math.min(p, range.end - 0.5)))
  }
  const updateSpeed = (i: number, patch: Partial<SpeedRange>) => set((c) => ({ ...c, speeds: c.speeds.map((x, j) => (j === i ? { ...x, ...patch } : x)) }))
  const updateFreeze = (i: number, patch: Partial<Freeze>) => set((c) => ({ ...c, freezes: c.freezes.map((x, j) => (j === i ? { ...x, ...patch } : x)) }))
  const updateSticker = (i: number, patch: Partial<Sticker>) => set((c) => ({ ...c, stickers: c.stickers.map((x, j) => (j === i ? { ...x, ...patch } : x)) }))
  const updatePip = (patch: Partial<Pip>) => set((c) => (c.pip ? { ...c, pip: { ...c.pip, ...patch } } : c))
  const tr = cfg.transition
  const pip = cfg.pip

  function addSticker(kind: Sticker['kind']) {
    const square = kind === 'circle' || kind === 'check' || kind === 'cross'
    const st: Sticker = {
      kind,
      x: 0.38,
      y: 0.32,
      w: kind === 'arrow' ? 0.22 : kind === 'highlight' ? 0.4 : 0.24,
      h: kind === 'arrow' ? 0.14 : kind === 'highlight' ? 0.1 : square ? 0.36 : 0.3,
      angle: 0,
      color: kind === 'highlight' ? '#FACC15' : kind === 'check' ? '#22C55E' : '#EF4444',
      label: '',
      at: here(),
      dur: 4,
    }
    set((c) => ({ ...c, stickers: [...c.stickers, st] }))
    onSelectSticker(cfg.stickers.length)
  }

  return (
    <div>
      <Section title="ক্লিপের মাঝে ট্রানজিশন" hint="ইন্ট্রো, বিজ্ঞাপন ও আউট্রোর জোড়ায় — মীহার নীল-লাল রঙে।">
        <div className="grid grid-cols-2 gap-1.5">
          {TRANSITIONS.map((t) => (
            <button
              key={t.id}
              type="button"
              onClick={() => set((c) => ({ ...c, transition: { ...c.transition, id: t.id } }))}
              aria-pressed={tr.id === t.id}
              data-testid={`transition-${t.id}`}
              className={`text-left rounded-xl border px-2.5 py-2 ${tr.id === t.id ? 'border-brand-500 ring-2 ring-brand-500/20 bg-brand-50/40' : 'border-neutral-200 hover:border-neutral-300'}`}
            >
              <span className={`block text-[12.5px] font-semibold ${tr.id === t.id ? 'text-brand-700' : 'text-neutral-800'}`}>{t.name}</span>
              <span className="block text-[11px] text-neutral-400 leading-snug">{t.hint}</span>
            </button>
          ))}
        </div>
        {tr.id !== 'none' && (
          <div className="mt-2">
            <Slider
              label="সময়"
              value={tr.dur}
              min={0.3}
              max={1.5}
              step={0.1}
              onChange={(v) => set((c) => ({ ...c, transition: { ...c.transition, dur: v } }))}
              format={(v) => `${bnNumber(v.toFixed(1))} সে.`}
            />
            <SwitchRow
              label="মাঝখান থেকে কাটা জোড়াতেও"
              hint="“সম্পাদনা” থেকে কাটা অংশের জোড়ায় একই ট্রানজিশন।"
              checked={tr.cuts}
              onChange={(v) => set((c) => ({ ...c, transition: { ...c.transition, cuts: v } }))}
            />
          </div>
        )}
      </Section>

      <Section title="স্লো-মোশন ও দ্রুত চালানো" hint="প্রিভিউ থামিয়ে যোগ করুন, তারপর শেষ সময় ঠিক করুন। এই অংশে মূল ভিডিওর শব্দ বন্ধ থাকবে (মিউজিক চলবে)।">
        <button
          type="button"
          onClick={() => {
            const from = here()
            set((c) => ({ ...c, speeds: [...c.speeds, { from, to: r1(Math.min(range.end, from + 3)), rate: 0.5 }] }))
          }}
          disabled={cfg.speeds.length >= LIMITS.speeds}
          data-testid="add-speed"
          className={addBtn}
        >
          <IconPlus className="w-4 h-4" />
          এখান থেকে গতি বদলান
        </button>
        {cfg.speeds.length > 0 && (
          <div className="flex flex-col gap-2 mt-2.5">
            {cfg.speeds.map((r, i) => (
              <div key={i} className="rounded-xl border border-neutral-200 p-2.5" data-testid="speed-row">
                <div className="flex items-end gap-3 flex-wrap">
                  <TimeField label="কোথা থেকে" value={r.from} min={0} max={range.end} onChange={(v) => updateSpeed(i, { from: v })} onPlayhead={() => updateSpeed(i, { from: here() })} />
                  <TimeField label="কোথা পর্যন্ত" value={r.to} min={0} max={range.end} onChange={(v) => updateSpeed(i, { to: v })} onPlayhead={() => updateSpeed(i, { to: here() })} />
                  <span className="ml-auto mb-1.5">
                    <Trash onClick={() => set((c) => ({ ...c, speeds: c.speeds.filter((_, j) => j !== i) }))} />
                  </span>
                </div>
                <div className="flex flex-wrap gap-1 mt-2">
                  {SPEED_RATES.map((v) => (
                    <button
                      key={v}
                      type="button"
                      onClick={() => updateSpeed(i, { rate: v })}
                      aria-pressed={r.rate === v}
                      className={`rounded-lg border px-2 py-1 text-[12px] font-medium tabular-nums ${r.rate === v ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-neutral-200 text-neutral-600'}`}
                    >
                      {rateName(v)}
                    </button>
                  ))}
                </div>
                <p className="text-[11px] text-neutral-400 mt-1.5">
                  {r.rate < 1 ? 'স্লো-মোশন' : 'দ্রুত'} · এই অংশ ভিডিওতে চলবে {bnNumber(Math.max(0, (r.to - r.from) / r.rate).toFixed(1))} সেকেন্ড
                </p>
              </div>
            ))}
          </div>
        )}
      </Section>

      <Section title="ফ্রিজ ফ্রেম" hint="কোনো মুহূর্তে ছবি থামিয়ে লেখা দেখান — যেমন “এই সেই মুহূর্ত”। ছবি থেমে থাকার সময় ভিডিও সেটুকু লম্বা হবে।">
        <button
          type="button"
          onClick={() => set((c) => ({ ...c, freezes: [...c.freezes, { at: here(), dur: 2.5, text: '' }] }))}
          disabled={cfg.freezes.length >= LIMITS.freezes}
          data-testid="add-freeze"
          className={addBtn}
        >
          <IconPlus className="w-4 h-4" />
          এখানে ছবি থামান
        </button>
        {cfg.freezes.length > 0 && (
          <div className="flex flex-col gap-2 mt-2.5">
            {cfg.freezes.map((f, i) => (
              <div key={i} className="rounded-xl border border-neutral-200 p-2.5" data-testid="freeze-row">
                <div className="flex items-end gap-3 flex-wrap mb-2">
                  <TimeField label="কখন" value={f.at} min={0} max={range.end} onChange={(v) => updateFreeze(i, { at: v })} onPlayhead={() => updateFreeze(i, { at: here() })} />
                  <DurField value={f.dur} max={10} onChange={(v) => updateFreeze(i, { dur: v })} />
                  <span className="ml-auto mb-1.5">
                    <Trash onClick={() => set((c) => ({ ...c, freezes: c.freezes.filter((_, j) => j !== i) }))} />
                  </span>
                </div>
                <TextInput label="লেখা (ঐচ্ছিক)" value={f.text} max={LIMITS.freezeText} placeholder="যেমন: এই সেই মুহূর্ত" onChange={(v) => updateFreeze(i, { text: v })} testId="freeze-text" />
              </div>
            ))}
          </div>
        )}
      </Section>

      <Section title="তীর, বৃত্ত ও হাইলাইট" hint="ভিডিওর কোনো জিনিস চিহ্নিত করতে। স্টিকারটি প্রিভিউতে টেনে সরান, কোণ টেনে ছোট-বড় করুন।">
        <div className="flex flex-wrap gap-1.5">
          {STICKERS.map((k) => (
            <button
              key={k.id}
              type="button"
              onClick={() => addSticker(k.id)}
              disabled={cfg.stickers.length >= LIMITS.stickers}
              data-testid={`add-sticker-${k.id}`}
              className="flex items-center gap-1 rounded-lg border border-dashed border-neutral-300 bg-neutral-50 px-2.5 py-1.5 text-[12px] font-medium text-neutral-700 disabled:opacity-50"
            >
              <IconPlus className="w-3.5 h-3.5" />
              {k.name}
            </button>
          ))}
        </div>
        {cfg.stickers.length > 0 && (
          <div className="flex flex-col gap-2 mt-2.5">
            {cfg.stickers.map((st, i) => (
              <div
                key={i}
                onClick={() => onSelectSticker(i)}
                className={`rounded-xl border p-2.5 cursor-pointer ${selectedSticker === i ? 'border-brand-500 ring-2 ring-brand-500/20' : 'border-neutral-200'}`}
                data-testid="sticker-row"
              >
                <div className="flex items-center justify-between gap-2 mb-2">
                  <p className="text-[12.5px] font-semibold text-neutral-800">
                    {STICKERS.find((k) => k.id === st.kind)?.name} {selectedSticker === i && <span className="text-[11px] font-normal text-brand-700">· প্রিভিউতে সরানো যাবে</span>}
                  </p>
                  <Trash
                    onClick={() => {
                      set((c) => ({ ...c, stickers: c.stickers.filter((_, j) => j !== i) }))
                      onSelectSticker(null)
                    }}
                  />
                </div>
                <div className="flex items-center gap-1.5 mb-2">
                  {STICKER_COLORS.map((col) => (
                    <button
                      key={col}
                      type="button"
                      onClick={() => updateSticker(i, { color: col })}
                      aria-label={`রং ${col}`}
                      aria-pressed={st.color === col}
                      className={`w-6 h-6 rounded-full border ${st.color === col ? 'ring-2 ring-offset-1 ring-brand-500 border-white' : 'border-neutral-300'}`}
                      style={{ background: col }}
                    />
                  ))}
                </div>
                {st.kind === 'arrow' && (
                  <Slider label="তীরের দিক" value={st.angle} min={-180} max={180} step={5} onChange={(v) => updateSticker(i, { angle: v })} format={(v) => `${bnNumber(v)}°`} />
                )}
                <TextInput label="পাশে লেখা (ঐচ্ছিক)" value={st.label} max={LIMITS.stickerLabel} placeholder="যেমন: এখানেই আগুন লাগে" onChange={(v) => updateSticker(i, { label: v })} />
                <div className="flex items-end gap-3 flex-wrap">
                  <TimeField label="কখন থেকে" value={st.at} min={0} max={range.end} onChange={(v) => updateSticker(i, { at: v })} onPlayhead={() => updateSticker(i, { at: here() })} />
                  {st.dur !== null && <DurField value={st.dur} onChange={(v) => updateSticker(i, { dur: v })} />}
                </div>
                <SwitchRow label="শুরু থেকে শেষ পর্যন্ত" checked={st.dur === null} onChange={(v) => updateSticker(i, { dur: v ? null : 4 })} />
              </div>
            ))}
          </div>
        )}
      </Section>

      <Section title="ছবির ভেতর ছবি / পাশাপাশি" hint="রিপোর্টার ও ঘটনাস্থল একসাথে — লাইব্রেরি থেকে দ্বিতীয় ভিডিও বেছে নিন।">
        {!pip ? (
          <button type="button" onClick={onPickPip} data-testid="add-pip" className={addBtn}>
            <IconVideo className="w-4 h-4" />
            দ্বিতীয় ভিডিও বেছে নিন
          </button>
        ) : (
          <div className="rounded-xl border border-neutral-200 p-2.5" data-testid="pip-row">
            <div className="flex items-center justify-between gap-2 mb-2">
              <p className="text-[12.5px] font-semibold text-neutral-800 truncate">{pip.clip?.title || 'দ্বিতীয় ভিডিও'}</p>
              <div className="flex items-center gap-2 shrink-0">
                <button type="button" onClick={onPickPip} className="text-[11.5px] px-2 py-1 rounded-md bg-neutral-100">
                  বদলান
                </button>
                <Trash onClick={() => set((c) => ({ ...c, pip: null }))} />
              </div>
            </div>
            <Segmented
              value={pip.layout}
              options={[
                { id: 'pip', name: 'কোণে ছোট জানালা' },
                { id: 'split', name: 'পাশাপাশি' },
              ]}
              onChange={(v) => updatePip({ layout: v })}
              size="sm"
            />
            {pip.layout === 'pip' && (
              <div className="mt-2">
                <Segmented
                  value={pip.corner}
                  options={[
                    { id: 'tl', name: 'উপরে বামে' },
                    { id: 'tr', name: 'উপরে ডানে' },
                    { id: 'bl', name: 'নিচে বামে' },
                    { id: 'br', name: 'নিচে ডানে' },
                  ]}
                  onChange={(v) => updatePip({ corner: v })}
                  size="sm"
                />
                <Slider label="জানালার আকার" value={pip.size} min={0.2} max={0.5} step={0.02} onChange={(v) => updatePip({ size: v })} format={(v) => `${bnNumber(Math.round(v * 100))}%`} />
              </div>
            )}
            <div className="flex items-end gap-3 flex-wrap mt-2">
              <TimeField label="কখন থেকে" value={pip.at} min={0} max={range.end} onChange={(v) => updatePip({ at: v })} onPlayhead={() => updatePip({ at: here() })} />
              {pip.dur !== null && <DurField value={pip.dur} min={1} onChange={(v) => updatePip({ dur: v })} />}
            </div>
            <SwitchRow label="শেষ পর্যন্ত (বা দ্বিতীয় ভিডিও শেষ হওয়া পর্যন্ত)" checked={pip.dur === null} onChange={(v) => updatePip({ dur: v ? null : 10 })} />
            <p className="text-[12px] font-medium text-neutral-600 mt-2 mb-1">কোন ভিডিওর শব্দ</p>
            <Segmented
              value={pip.sound}
              options={[
                { id: 'main', name: 'মূল ভিডিও' },
                { id: 'pip', name: 'দ্বিতীয় ভিডিও' },
                { id: 'both', name: 'দুটোই' },
              ]}
              onChange={(v) => updatePip({ sound: v })}
              size="sm"
            />
            <div className="mt-3">
              <TextInput label="দ্বিতীয় ভিডিওর লেবেল" value={pip.label} max={LIMITS.pipLabel} placeholder="যেমন: ঘটনাস্থল" onChange={(v) => updatePip({ label: v })} />
              {pip.layout === 'split' && <TextInput label="মূল ভিডিওর লেবেল" value={pip.mainLabel} max={LIMITS.pipLabel} placeholder="যেমন: স্টুডিও" onChange={(v) => updatePip({ mainLabel: v })} />}
            </div>
          </div>
        )}
      </Section>
    </div>
  )
}
