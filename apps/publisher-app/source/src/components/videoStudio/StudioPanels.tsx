/**
 * Round 21 — the video editor's side panels: ফ্রেম, লোগো, ইন্ট্রো/আউট্রো,
 * বিজ্ঞাপন, ক্যাপশন, আকার ও অডিও. Each panel edits one part of the
 * EditConfig through `set` (an immutable updater), nothing else.
 */
import { useEffect, useRef, useState, type ReactNode } from 'react'
import {
  CAPTION_POSITIONS,
  CAPTION_PRESETS,
  CAPTION_STYLES,
  FOOTERS,
  FORMATS,
  FRAMES,
  FRAME_SIZES,
  INTROS,
  LIMITS,
  LOGO_STYLES,
  OUTROS,
  type Caption,
  type Corner,
  type EditConfig,
  type FrameId,
  type IntroId,
  type MediaRef,
  type OutroId,
} from '../../lib/videoEdit/config'
import { bnClock } from '../../lib/videoEdit/motion'
import type { VideoKit } from '../../lib/api'
import { bnNumber } from '../ui'
import { IconPlus, IconTrash, IconUsers, IconX } from '../Icon'
import PeoplePicker from './StudioPeople'
import { ChoiceCard, Section, Segmented, Slider, SwitchRow, TextInput, TimeField } from './StudioFields'

export type Setter = (fn: (c: EditConfig) => EditConfig) => void

/** Paints a small still of a design (supplied by the page, which owns the renderer). */
export function Thumb({ paint, ratio, deps }: { paint: (c: HTMLCanvasElement) => void; ratio: number; deps: unknown[] }) {
  const ref = useRef<HTMLCanvasElement>(null)
  useEffect(() => {
    if (ref.current) paint(ref.current)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps)
  return <canvas ref={ref} className="block w-full bg-neutral-900" style={{ aspectRatio: String(ratio) }} />
}

export type ThumbPainter = (kind: 'frame' | 'intro' | 'outro', id: string) => ((c: HTMLCanvasElement) => void) | null

// ---------------------------------------------------------------------------
// Frame
// ---------------------------------------------------------------------------

export function FramePanel({
  cfg,
  set,
  postTitle,
  thumb,
  thumbRatio,
  thumbKey,
  onPickAdImage,
}: {
  cfg: EditConfig
  set: Setter
  postTitle: string
  thumb: ThumbPainter
  thumbRatio: number
  thumbKey: string
  onPickAdImage: () => void
}) {
  const f = cfg.frame
  const setF = (patch: Partial<EditConfig['frame']>) => set((c) => ({ ...c, frame: { ...c.frame, ...patch } }))
  const options: { id: FrameId; name: string; hint: string }[] = [{ id: 'none', name: 'ফ্রেম নেই', hint: 'শুধু ভিডিও' }, ...FRAMES]
  return (
    <div>
      <Section title="এনিমেটেড ফ্রেম" hint="ক্লিক করলে পুরো ভিডিওর উপরে ও নিচে ফ্রেমের অংশ যোগ হবে।">
        <div className="grid grid-cols-2 gap-2.5">
          {options.map((o) => {
            const paint = o.id === 'none' ? null : thumb('frame', o.id)
            return (
              <ChoiceCard
                key={o.id}
                active={f.id === o.id}
                onClick={() => setF({ id: o.id })}
                title={o.name}
                hint={o.hint}
                testId={`frame-${o.id}`}
                preview={paint ? <Thumb paint={paint} ratio={thumbRatio} deps={[thumbKey, o.id]} /> : <div className="bg-neutral-100" style={{ aspectRatio: String(thumbRatio) }} />}
              />
            )
          })}
        </div>
      </Section>

      {f.id !== 'none' && (
        <>
          <Section title="ফ্রেমের আকার" hint="স্বয়ংক্রিয়: রিল (৯:১৬) ভিডিওতে ফ্রেম ও লেখা বড় হয়, কারণ ফোনে পুরো স্ক্রিনে দেখা হয়।">
            <Segmented value={f.size} options={FRAME_SIZES} onChange={(v) => setF({ size: v })} size="sm" />
          </Section>
          <Section title="উপরের অংশ">
            <TextInput label="শিরোনাম / নিজের লেখা" value={f.title} onChange={(v) => setF({ title: v })} max={LIMITS.title} placeholder={postTitle || 'ভিডিওর শিরোনাম'} testId="frame-title" />
            <TextInput label="ক্যাটেগরি / লেবেল (ঐচ্ছিক)" value={f.kicker} onChange={(v) => setF({ kicker: v })} max={LIMITS.kicker} placeholder="যেমন: জাতীয়, খেলা, ক্যাম্পাস" />
            <SwitchRow label="তারিখ দেখান" checked={f.date} onChange={(v) => setF({ date: v })} />
          </Section>
          <Section title="নিচের অংশ (ফুটার)" hint="স্ক্রল, বিজ্ঞাপন বা ব্র্যান্ডিং — ফুটার না চাইলে “ফুটার নেই”।">
            <Segmented value={f.footer} options={FOOTERS} onChange={(v) => setF({ footer: v })} size="sm" />
            <div className="mt-3">
              {f.footer === 'ticker' && (
                <TextInput label="চলমান স্ক্রলের লেখা" value={f.ticker} onChange={(v) => setF({ ticker: v })} max={LIMITS.ticker} placeholder="খালি রাখলে শিরোনামটিই চলবে" multiline />
              )}
              {f.footer === 'ad-text' && (
                <TextInput label="বিজ্ঞাপনের লেখা" value={f.adText} onChange={(v) => setF({ adText: v })} max={LIMITS.adText} placeholder="যেমন: রহমান ইলেকট্রনিক্স — ঈদ অফারে ২০% ছাড়, ফোন ০১৭…" multiline testId="ad-text" />
              )}
              {f.footer === 'ad-image' && (
                <div className="flex items-center gap-2">
                  {f.adImage ? (
                    <>
                      <img src={f.adImage.url} alt="" className="h-12 max-w-[200px] object-cover rounded-lg border border-neutral-200" />
                      <button type="button" onClick={onPickAdImage} className="text-[12px] px-2.5 py-1.5 rounded-lg bg-neutral-100">
                        বদলান
                      </button>
                      <button type="button" onClick={() => setF({ adImage: null })} className="text-[12px] px-2.5 py-1.5 rounded-lg bg-red-50 text-red-600">
                        সরান
                      </button>
                    </>
                  ) : (
                    <button type="button" onClick={onPickAdImage} className="text-[12.5px] px-3 py-2 rounded-lg border border-dashed border-neutral-300 text-neutral-600 w-full">
                      গ্যালারি থেকে ব্যানার ছবি বেছে নিন (চওড়া ছবি সবচেয়ে ভালো — প্রায় ৮:১)
                    </button>
                  )}
                </div>
              )}
            </div>
          </Section>
        </>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Logo
// ---------------------------------------------------------------------------

export function LogoPanel({ cfg, set, customLogo }: { cfg: EditConfig; set: Setter; customLogo: boolean }) {
  const l = cfg.logo
  const setL = (patch: Partial<EditConfig['logo']>) => set((c) => ({ ...c, logo: { ...c.logo, ...patch } }))
  const corners: { id: Corner; name: string }[] = [
    { id: 'tl', name: 'উপরে বামে' },
    { id: 'tr', name: 'উপরে ডানে' },
    { id: 'bl', name: 'নিচে বামে' },
    { id: 'br', name: 'নিচে ডানে' },
  ]
  return (
    <div>
      <SwitchRow
        label="এনিমেটেড লোগো"
        hint={`পুরো ভিডিও জুড়ে কোণে থাকবে, নির্দিষ্ট সময় পরপর এনিমেশন হবে।${customLogo ? ' (সেটিংসে দেওয়া লোগো)' : ''}`}
        checked={l.on}
        onChange={(v) => setL({ on: v })}
        testId="logo-on"
      />
      {l.on && (
        <div className="mt-3">
          <Section title="এনিমেশন">
            <div className="grid grid-cols-2 gap-2">
              {LOGO_STYLES.map((s) => (
                <ChoiceCard key={s.id} active={l.style === s.id} onClick={() => setL({ style: s.id })} title={s.name} hint={s.hint} />
              ))}
            </div>
          </Section>
          <Section title="কোণ">
            <div className="grid grid-cols-2 gap-1.5 w-[220px]">
              {corners.map((c) => (
                <button
                  key={c.id}
                  type="button"
                  onClick={() => setL({ corner: c.id })}
                  aria-pressed={l.corner === c.id}
                  className={`rounded-lg border px-2 py-2 text-[12px] font-medium ${l.corner === c.id ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-neutral-200 text-neutral-600'}`}
                >
                  {c.name}
                </button>
              ))}
            </div>
          </Section>
          <Slider label="আকার" value={l.size} min={3} max={14} step={0.5} onChange={(v) => setL({ size: v })} format={(v) => `${bnNumber(v)}%`} />
          {l.style !== 'static' && (
            <Slider label="কত সেকেন্ড পরপর এনিমেশন" value={l.every} min={6} max={60} onChange={(v) => setL({ every: v })} format={(v) => `${bnNumber(v)} সে.`} />
          )}
          <Slider label="স্বচ্ছতা" value={Math.round(l.opacity * 100)} min={30} max={100} step={5} onChange={(v) => setL({ opacity: v / 100 })} format={(v) => `${bnNumber(v)}%`} />
          <SwitchRow label="সাদা পটভূমি" hint="বন্ধ করলে সাদা লোগো, ছায়াসহ" checked={l.plate} onChange={(v) => setL({ plate: v })} />
        </div>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Intro / outro
// ---------------------------------------------------------------------------

function ClipChooser({ clip, onPick, onClear, label }: { clip: MediaRef | null; onPick: () => void; onClear?: () => void; label: string }) {
  return (
    <div className="mt-2 flex items-center gap-2 rounded-lg bg-neutral-50 border border-neutral-200 px-3 py-2">
      <p className="flex-1 min-w-0 text-[12.5px] text-neutral-700 truncate">{clip ? clip.title || `ভিডিও #${bnNumber(clip.id)}` : label}</p>
      <button type="button" onClick={onPick} className="text-[12px] px-2.5 py-1 rounded-md bg-white border border-neutral-200">
        {clip ? 'বদলান' : 'বেছে নিন'}
      </button>
      {clip && onClear && (
        <button type="button" onClick={onClear} aria-label="সরান" className="text-neutral-400">
          <IconX className="w-4 h-4" />
        </button>
      )}
    </div>
  )
}

export function IntroOutroPanel({
  cfg,
  set,
  postTitle,
  thumb,
  thumbRatio,
  thumbKey,
  kit,
  onPick,
}: {
  cfg: EditConfig
  set: Setter
  postTitle: string
  thumb: ThumbPainter
  thumbRatio: number
  thumbKey: string
  kit: VideoKit | null
  onPick: (which: 'intro' | 'outro') => void
}) {
  const intro = cfg.intro
  const outro = cfg.outro
  const introOpts = [{ id: 'none' as IntroId, name: 'ইন্ট্রো নেই', hint: '' }, ...INTROS]
  const outroOpts = [{ id: 'none' as OutroId, name: 'আউট্রো নেই', hint: '' }, ...OUTROS]
  const textIntro = ['breaking', 'headline', 'special', 'swipe', 'live', 'countdown'].includes(intro.id)
  const preview = (kind: 'intro' | 'outro', id: string): ReactNode => {
    const p = id === 'none' ? null : thumb(kind, id)
    return p ? <Thumb paint={p} ratio={thumbRatio} deps={[thumbKey, id]} /> : <div className="bg-neutral-100" style={{ aspectRatio: String(thumbRatio) }} />
  }
  return (
    <div>
      <Section title="ইন্ট্রো" hint="ক্লিক করলে ভিডিওর শুরুতে যোগ হবে।">
        <div className="grid grid-cols-2 gap-2.5">
          {introOpts.map((o) => (
            <ChoiceCard
              key={o.id}
              active={intro.id === o.id}
              testId={`intro-${o.id}`}
              onClick={() => {
                set((c) => ({ ...c, intro: { ...c.intro, id: o.id, clip: o.id === 'custom' ? c.intro.clip ?? kit?.intro ?? null : c.intro.clip } }))
                if (o.id === 'custom' && !intro.clip && !kit?.intro) onPick('intro')
              }}
              title={o.name}
              hint={o.hint}
              preview={preview('intro', o.id)}
            />
          ))}
        </div>
        {intro.id === 'custom' && <ClipChooser clip={intro.clip} label="ইন্ট্রো ভিডিও বেছে নিন" onPick={() => onPick('intro')} />}
        {textIntro && (
          <div className="mt-3">
            <TextInput label="ইন্ট্রোর লেখা" value={intro.text} onChange={(v) => set((c) => ({ ...c, intro: { ...c.intro, text: v } }))} max={LIMITS.introText} placeholder={cfg.frame.title || postTitle || 'শিরোনাম'} />
          </div>
        )}
      </Section>
      <Section title="আউট্রো" hint="ক্লিক করলে ভিডিওর শেষে যোগ হবে।">
        <div className="grid grid-cols-2 gap-2.5">
          {outroOpts.map((o) => (
            <ChoiceCard
              key={o.id}
              active={outro.id === o.id}
              testId={`outro-${o.id}`}
              onClick={() => {
                set((c) => ({ ...c, outro: { ...c.outro, id: o.id, clip: o.id === 'custom' ? c.outro.clip ?? kit?.outro ?? null : c.outro.clip } }))
                if (o.id === 'custom' && !outro.clip && !kit?.outro) onPick('outro')
              }}
              title={o.name}
              hint={o.hint}
              preview={preview('outro', o.id)}
            />
          ))}
        </div>
        {outro.id === 'custom' && <ClipChooser clip={outro.clip} label="আউট্রো ভিডিও বেছে নিন" onPick={() => onPick('outro')} />}
      </Section>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Ad breaks
// ---------------------------------------------------------------------------

export function AdsPanel({
  cfg,
  set,
  range,
  playhead,
  onAdd,
  onReplace,
}: {
  cfg: EditConfig
  set: Setter
  range: { start: number; end: number }
  playhead: () => number | null
  onAdd: () => void
  onReplace: (index: number) => void
}) {
  const update = (i: number, patch: Partial<EditConfig['ads'][number]>) =>
    set((c) => ({ ...c, ads: c.ads.map((a, j) => (j === i ? { ...a, ...patch } : a)) }))
  const remove = (i: number) => set((c) => ({ ...c, ads: c.ads.filter((_, j) => j !== i) }))
  const order = cfg.ads.map((a, i) => ({ a, i })).sort((x, y) => x.a.at - y.a.at)
  return (
    <div>
      <Section
        title="ভিডিওর মাঝে বিজ্ঞাপন"
        hint="প্রিভিউ থামিয়ে যেখানে বিজ্ঞাপন চান সেখানে “এখানে বিজ্ঞাপন যোগ করুন” দিন — ভিডিও সেখানে ভাগ হয়ে গ্যালারির বিজ্ঞাপন চলবে, তারপর সংবাদ আবার চলবে।"
      >
        <button
          type="button"
          onClick={onAdd}
          disabled={cfg.ads.length >= LIMITS.ads}
          data-testid="add-ad"
          className="w-full flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-amber-400 bg-amber-50 text-amber-800 py-2.5 text-[13px] font-semibold disabled:opacity-50"
        >
          <IconPlus className="w-4 h-4" />
          এখানে বিজ্ঞাপন যোগ করুন
        </button>
      </Section>
      {order.length === 0 ? (
        <p className="text-[12.5px] text-neutral-400 text-center py-4">এখনো কোনো বিজ্ঞাপন বিরতি নেই।</p>
      ) : (
        <div className="flex flex-col gap-2.5">
          {order.map(({ a, i }, k) => {
            const outside = a.at <= range.start + 0.25 || a.at >= range.end - 0.25
            return (
              <div key={i} className="rounded-xl border border-neutral-200 p-3" data-testid="ad-row">
                <div className="flex items-center justify-between gap-2 mb-2">
                  <p className="text-[12.5px] font-semibold text-neutral-800">বিজ্ঞাপন বিরতি {bnNumber(k + 1)}</p>
                  <button type="button" onClick={() => remove(i)} aria-label="সরান" className="text-red-500 hover:text-red-700">
                    <IconTrash className="w-4 h-4" />
                  </button>
                </div>
                <div className="flex items-end gap-3 flex-wrap">
                  <TimeField
                    label="মূল ভিডিওর কোন সময়ে"
                    value={a.at}
                    min={0}
                    max={range.end}
                    onChange={(v) => update(i, { at: v })}
                    onPlayhead={() => {
                      const p = playhead()
                      if (p !== null) update(i, { at: p })
                    }}
                  />
                  <div className="flex-1 min-w-[120px]">
                    <span className="block text-[11.5px] text-neutral-500 mb-1">বিজ্ঞাপন ক্লিপ</span>
                    <button type="button" onClick={() => onReplace(i)} className="w-full text-left text-[12.5px] rounded-lg border border-neutral-200 px-2.5 py-1.5 truncate hover:border-neutral-300">
                      {a.clip.title || `ভিডিও #${bnNumber(a.clip.id)}`}
                      {a.clip.duration ? <span className="text-neutral-400"> · {bnClock(a.clip.duration)}</span> : null}
                    </button>
                  </div>
                </div>
                <SwitchRow label="“বিজ্ঞাপন বিরতি” ও “সংবাদে ফিরছি” কার্ড" checked={a.bumper} onChange={(v) => update(i, { bumper: v })} />
                {outside && <p className="text-[11.5px] text-amber-700">এই সময়টা কাটছাঁটের বাইরে বা একদম শুরু/শেষে — বিজ্ঞাপনটি বসবে না।</p>}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Captions & credits
// ---------------------------------------------------------------------------

export function CaptionsPanel({
  cfg,
  set,
  range,
  playhead,
  defaultName,
  kit,
  onKit,
}: {
  cfg: EditConfig
  set: Setter
  range: { start: number; end: number }
  playhead: () => number | null
  defaultName: string
  kit: VideoKit | null
  onKit: (k: VideoKit) => void
}) {
  const [people, setPeople] = useState(false)
  const update = (i: number, patch: Partial<Caption>) => set((c) => ({ ...c, captions: c.captions.map((x, j) => (j === i ? { ...x, ...patch } : x)) }))
  const remove = (i: number) => set((c) => ({ ...c, captions: c.captions.filter((_, j) => j !== i) }))
  const add = (preset: (typeof CAPTION_PRESETS)[number]) => {
    const p = playhead()
    const at = preset.caption.dur === null ? range.start : p ?? range.start
    const text = preset.caption.style === 'nameplate' && !preset.caption.text ? defaultName : preset.caption.text
    set((c) => ({ ...c, captions: [...c.captions, { ...preset.caption, text: text || preset.caption.text, at }] }))
  }
  return (
    <div>
      <Section title="ক্যাপশন ও ক্রেডিট" hint="ভিডিওর উপর লেখা — কে পাঠিয়েছেন, কার বক্তব্য, সংগৃহীত কিনা। কখন থেকে কতক্ষণ দেখাবে তা নিচে ঠিক করুন।">
        {people && (
          <PeoplePicker
            kit={kit}
            onKit={onKit}
            onClose={() => setPeople(false)}
            onPick={(person) => {
              if (cfg.captions.length >= LIMITS.captions) return
              const at = playhead() ?? range.start
              set((c) => ({ ...c, captions: [...c.captions, { text: person.name, sub: person.role, style: 'nameplate', pos: 'bl', at, dur: 5 }] }))
              setPeople(false)
            }}
          />
        )}
        <div className="flex flex-wrap gap-1.5">
          <button
            type="button"
            onClick={() => setPeople((v) => !v)}
            disabled={cfg.captions.length >= LIMITS.captions}
            className="flex items-center gap-1 rounded-full bg-brand-600 text-white px-2.5 py-1.5 text-[12px] font-semibold disabled:opacity-50"
            data-testid="open-people"
          >
            <IconUsers className="w-3.5 h-3.5" />
            নামের তালিকা থেকে
          </button>
          {CAPTION_PRESETS.map((p) => (
            <button
              key={p.label}
              type="button"
              onClick={() => add(p)}
              disabled={cfg.captions.length >= LIMITS.captions}
              className="flex items-center gap-1 rounded-full border border-neutral-200 px-2.5 py-1.5 text-[12px] font-medium text-neutral-700 hover:border-brand-500 hover:text-brand-700 disabled:opacity-50"
            >
              <IconPlus className="w-3.5 h-3.5" />
              {p.label}
            </button>
          ))}
        </div>
      </Section>
      {cfg.captions.length === 0 ? (
        <p className="text-[12.5px] text-neutral-400 text-center py-4">এখনো কোনো ক্যাপশন নেই — উপরের বোতাম থেকে যোগ করুন।</p>
      ) : (
        <div className="flex flex-col gap-2.5">
          {cfg.captions.map((c, i) => (
            <div key={i} className="rounded-xl border border-neutral-200 p-3" data-testid="caption-row">
              <div className="flex items-center justify-between gap-2 mb-2">
                <Segmented value={c.style} options={CAPTION_STYLES} onChange={(v) => update(i, { style: v, pos: v === 'subtitle' || v === 'quote' ? 'bc' : c.pos === 'bc' ? 'bl' : c.pos })} size="sm" />
                <button type="button" onClick={() => remove(i)} aria-label="সরান" className="text-red-500 hover:text-red-700 shrink-0">
                  <IconTrash className="w-4 h-4" />
                </button>
              </div>
              <TextInput
                label={c.style === 'nameplate' ? 'নাম' : c.style === 'location' ? 'স্থান' : c.style === 'quote' ? 'বক্তব্য' : 'লেখা'}
                value={c.text}
                onChange={(v) => update(i, { text: v })}
                max={LIMITS.capText}
                multiline={c.style === 'quote'}
                placeholder={
                  c.style === 'nameplate' ? 'যেমন: আসিফ মাহমুদ' : c.style === 'location' ? 'যেমন: শাহবাগ, ঢাকা' : c.style === 'quote' ? 'যেমন: আমরা দ্রুত ব্যবস্থা নেব' : 'যেমন: ভিডিও: সংগৃহীত'
                }
              />
              {(c.style === 'nameplate' || c.style === 'quote') && (
                <TextInput
                  label={c.style === 'quote' ? 'বক্তার নাম ও পরিচয়' : 'পদবি / পরিচয়'}
                  value={c.sub}
                  onChange={(v) => update(i, { sub: v })}
                  max={LIMITS.capSub}
                  placeholder={c.style === 'quote' ? 'যেমন: মো. রহিম, জেলা প্রশাসক' : 'যেমন: ক্যাম্পাস সংবাদদাতা'}
                />
              )}
              <p className="text-[11.5px] text-neutral-500 mb-1">অবস্থান</p>
              <Segmented value={c.pos} options={CAPTION_POSITIONS} onChange={(v) => update(i, { pos: v })} size="sm" />
              <div className="flex items-end gap-3 flex-wrap mt-3">
                <TimeField
                  label="কখন থেকে (মূল ভিডিওর সময়)"
                  value={c.at}
                  min={0}
                  max={range.end}
                  onChange={(v) => update(i, { at: v })}
                  onPlayhead={() => {
                    const p = playhead()
                    if (p !== null) update(i, { at: p })
                  }}
                />
                {c.dur !== null && (
                  <label className="block">
                    <span className="block text-[11.5px] text-neutral-500 mb-1">কতক্ষণ (সেকেন্ড)</span>
                    <input
                      type="number"
                      min={1}
                      max={600}
                      step={0.5}
                      value={c.dur}
                      onChange={(e) => update(i, { dur: Math.max(1, Number(e.target.value) || 1) })}
                      className="w-[84px] rounded-lg border border-neutral-200 px-2 py-1.5 text-[13px]"
                    />
                  </label>
                )}
              </div>
              <SwitchRow label="পুরো ভিডিও জুড়ে দেখান" checked={c.dur === null} onChange={(v) => update(i, { dur: v ? null : 5 })} />
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Size, trim & sound
// ---------------------------------------------------------------------------

export type ReframeJob = null | { stage: 'running'; ratio: number; label: string } | { stage: 'done'; faces: boolean } | { stage: 'error'; message: string }

export function LayoutPanel({
  cfg,
  set,
  srcDuration,
  playhead,
  outputSize,
  reframe,
  onReframe,
  onCancelReframe,
}: {
  cfg: EditConfig
  set: Setter
  srcDuration: number
  playhead: () => number | null
  outputSize: string
  reframe: ReframeJob
  onReframe: () => void
  onCancelReframe: () => void
}) {
  const hasTrack = cfg.reframe.xs.length > 0
  const trimEnd = cfg.trim.end ?? srcDuration
  return (
    <div>
      <Section title="ভিডিওর আকার" hint={`আউটপুট: ${outputSize}`}>
        <div className="grid grid-cols-3 gap-2">
          {FORMATS.map((f) => (
            <ChoiceCard key={f.id} active={cfg.format === f.id} onClick={() => set((c) => ({ ...c, format: f.id }))} title={f.name} hint={f.hint} testId={`format-${f.id}`} />
          ))}
        </div>
        {cfg.format !== 'source' && (
          <div className="mt-3">
            <p className="text-[11.5px] text-neutral-500 mb-1">ভিডিও মাপে না মিললে</p>
            <Segmented
              value={cfg.fit}
              options={[
                { id: 'blur', name: 'পাশে ঝাপসা পটভূমি' },
                { id: 'cover', name: 'কেটে পুরো ভরাট' },
                { id: 'black', name: 'কালো পটভূমি' },
              ]}
              onChange={(v) => set((c) => ({ ...c, fit: v }))}
              size="sm"
            />
          </div>
        )}
        {cfg.format !== 'source' && (
          <div className="mt-3 rounded-xl border border-neutral-200 p-2.5" data-testid="reframe">
            <p className="text-[12.5px] font-semibold text-neutral-800">স্মার্ট রিফ্রেম</p>
            <p className="text-[11.5px] text-neutral-400 leading-snug mb-2">
              ১৬:৯ ভিডিও রিল (৯:১৬) বা বর্গ করলে কাটা ফ্রেম মানুষের মুখ ও নড়াচড়া অনুসরণ করে সরবে। চালু করলে “কেটে পুরো ভরাট” হয়।
            </p>
            {reframe?.stage === 'running' ? (
              <div>
                <p className="text-[12px] text-neutral-700 mb-1">{reframe.label}</p>
                <div className="h-2 rounded-full bg-neutral-100 overflow-hidden">
                  <div className="h-full bg-brand-600 transition-[width] duration-200" style={{ width: `${Math.round(reframe.ratio * 100)}%` }} />
                </div>
                <button type="button" onClick={onCancelReframe} className="mt-2 text-[11.5px] px-2 py-1 rounded-md bg-neutral-100">
                  বাতিল
                </button>
              </div>
            ) : hasTrack ? (
              <>
                <SwitchRow
                  label="ফ্রেম অনুসরণ করবে"
                  checked={cfg.reframe.on}
                  onChange={(v) => set((c) => ({ ...c, reframe: { ...c.reframe, on: v }, ...(v ? { fit: 'cover' as const } : {}) }))}
                  testId="reframe-on"
                />
                <div className="flex items-center justify-between gap-2">
                  <p className="text-[11px] text-neutral-400">
                    {reframe?.stage === 'done' ? (reframe.faces ? 'মুখ শনাক্ত করে অনুসরণ করা হচ্ছে।' : 'রং ও নড়াচড়া দেখে অনুসরণ করা হচ্ছে।') : 'বিশ্লেষণ করা আছে।'} প্রিভিউ চালিয়ে দেখে নিন।
                  </p>
                  <button type="button" onClick={onReframe} className="text-[11.5px] px-2 py-1 rounded-md bg-neutral-100 shrink-0">
                    আবার বিশ্লেষণ
                  </button>
                </div>
              </>
            ) : (
              <button
                type="button"
                onClick={onReframe}
                data-testid="reframe-run"
                className="w-full rounded-lg bg-brand-600 text-white py-2 text-[13px] font-semibold"
              >
                ভিডিও বিশ্লেষণ করে চালু করুন
              </button>
            )}
            {reframe?.stage === 'error' && <p className="text-[12px] text-red-700 mt-1.5">{reframe.message}</p>}
          </div>
        )}
        <div className="mt-3">
          <p className="text-[11.5px] text-neutral-500 mb-1">মান</p>
          <Segmented
            value={cfg.quality}
            options={[
              { id: '1080', name: '১০৮০p (সেরা)' },
              { id: '720', name: '৭২০p (দ্রুত, ছোট ফাইল)' },
            ]}
            onChange={(v) => set((c) => ({ ...c, quality: v }))}
            size="sm"
          />
        </div>
      </Section>
      <Section title="কাটছাঁট" hint="মূল ভিডিওর শুরু ও শেষ থেকে বাদ দিন।">
        <div className="flex items-end gap-4 flex-wrap">
          <TimeField
            label="শুরু"
            value={cfg.trim.start}
            min={0}
            max={Math.max(0, trimEnd - 0.5)}
            onChange={(v) => set((c) => ({ ...c, trim: { ...c.trim, start: v } }))}
            onPlayhead={() => {
              const p = playhead()
              if (p !== null) set((c) => ({ ...c, trim: { ...c.trim, start: Math.min(p, (c.trim.end ?? srcDuration) - 0.5) } }))
            }}
          />
          <TimeField
            label="শেষ"
            value={trimEnd}
            min={cfg.trim.start + 0.5}
            max={srcDuration}
            onChange={(v) => set((c) => ({ ...c, trim: { ...c.trim, end: v >= srcDuration - 0.05 ? null : v } }))}
            onPlayhead={() => {
              const p = playhead()
              if (p !== null) set((c) => ({ ...c, trim: { ...c.trim, end: Math.max(p, c.trim.start + 0.5) } }))
            }}
          />
          {(cfg.trim.start > 0 || cfg.trim.end !== null) && (
            <button type="button" onClick={() => set((c) => ({ ...c, trim: { start: 0, end: null } }))} className="text-[12px] px-2.5 py-1.5 rounded-lg bg-neutral-100">
              রিসেট
            </button>
          )}
        </div>
        <p className="text-[11.5px] text-neutral-400 mt-2">
          মূল ভিডিও {bnClock(srcDuration)} · কাটার পর {bnClock(Math.max(0, trimEnd - cfg.trim.start))}
        </p>
      </Section>
    </div>
  )
}
