/**
 * Round 25 — "সাজ": the blue shade, the highlight style, the title size,
 * where the logo goes (and how big), a border, the photo credit, and one-click
 * looks (built in, or the user's own saved ones).
 */
import { useState } from 'react'
import { bnNumber, inputClass } from '../ui'
import { IconDeviceFloppy, IconTrash } from '../Icon'
import { BORDERS, STYLE_PRESETS, type CardStyle, type LogoPos, type MyPreset, type StylePreset } from '../../lib/photocard/style'

const PAL_COLORS: Record<string, [string, string, string]> = {
  royal: ['#0B2A6B', '#1546B8', '#FFC20E'],
  navy: ['#06122F', '#0D2C6E', '#FFC20E'],
  teal: ['#042F44', '#0A6C8C', '#FFD166'],
  indigo: ['#1F1266', '#5230CF', '#FFC20E'],
  sky: ['#0039C6', '#0098F5', '#FFE14D'],
}

const LOGO_GRID: [LogoPos, string][] = [
  ['tl', 'ওপরে বাঁয়ে'],
  ['tc', 'ওপরে মাঝে'],
  ['tr', 'ওপরে ডানে'],
  ['bl', 'নিচে বাঁয়ে'],
  ['bc', 'নিচে মাঝে'],
  ['br', 'নিচে ডানে'],
]

const TITLE_STEPS: [number, string][] = [
  [0.8, 'ছোট'],
  [0.9, 'একটু ছোট'],
  [1, 'স্বাভাবিক'],
  [1.1, 'একটু বড়'],
  [1.2, 'বড়'],
  [1.35, 'খুব বড়'],
]

export default function StylePanel({
  style,
  onChange,
  palettes,
  blueDesign,
  hasPhoto,
  onPreset,
  mine,
  onSaveMine,
  onApplyMine,
  onDeleteMine,
}: {
  style: CardStyle
  onChange: (patch: Partial<CardStyle>) => void
  palettes: Record<string, string>
  /** The chosen design is one of the new blue ones (the shade applies). */
  blueDesign: boolean
  hasPhoto: boolean
  onPreset: (p: StylePreset) => void
  mine: MyPreset[]
  onSaveMine: (label: string) => void
  onApplyMine: (p: MyPreset) => void
  onDeleteMine: (id: string) => void
}) {
  const [name, setName] = useState('')
  const chip = (on: boolean) =>
    `px-2.5 py-1 rounded-full border text-[12px] ${on ? 'border-brand-600 bg-brand-50 text-brand-700 font-semibold' : 'border-neutral-200 text-neutral-600 hover:border-neutral-300'}`

  return (
    <div className="space-y-4">
      {/* one-click looks */}
      <div>
        <p className="text-[12.5px] font-semibold text-neutral-700 mb-1.5">এক ক্লিকে সাজ</p>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
          {STYLE_PRESETS.map((p) => (
            <button key={p.id} type="button" onClick={() => onPreset(p)} className="text-left rounded-lg border border-neutral-200 hover:border-brand-400 px-2 py-1.5 bg-white" title={p.hint}>
              <b className="block text-[12px] text-neutral-800 truncate">{p.label}</b>
              <small className="block text-[10.5px] text-neutral-400 truncate">{p.hint}</small>
            </button>
          ))}
        </div>
        {mine.length > 0 && (
          <div className="mt-2">
            <p className="text-[11.5px] text-neutral-500 mb-1">আমার সাজ</p>
            <div className="flex flex-wrap gap-1.5">
              {mine.map((m) => (
                <span key={m.id} className="inline-flex items-center rounded-full border border-brand-100 bg-brand-50">
                  <button type="button" onClick={() => onApplyMine(m)} className="pl-2.5 pr-1 py-1 text-[12px] text-brand-700 font-medium">
                    {m.label}
                  </button>
                  <button type="button" onClick={() => onDeleteMine(m.id)} className="pr-2 pl-1 py-1 text-brand-500 hover:text-red-600" aria-label={`মুছুন: ${m.label}`}>
                    <IconTrash className="w-3.5 h-3.5" />
                  </button>
                </span>
              ))}
            </div>
          </div>
        )}
        <form
          className="flex gap-1.5 mt-2"
          onSubmit={(e) => {
            e.preventDefault()
            onSaveMine(name.trim() || `সাজ ${bnNumber(mine.length + 1)}`)
            setName('')
          }}
        >
          <input className={`${inputClass} !py-1.5 text-[12.5px]`} value={name} maxLength={30} onChange={(e) => setName(e.target.value)} placeholder="এই সাজের নাম (যেমন: রাজনীতি ব্রেকিং)" aria-label="সাজের নাম" />
          <button type="submit" className="shrink-0 inline-flex items-center gap-1 rounded-lg bg-neutral-900 text-white text-[12px] px-2.5">
            <IconDeviceFloppy className="w-4 h-4" /> রাখুন
          </button>
        </form>
        <p className="text-[11px] text-neutral-400 mt-1">নকশা, রং, লোগো, লেয়ার সব একসাথে মনে রাখে (এই ব্রাউজারে)।</p>
      </div>

      {/* blue shade */}
      <div>
        <p className="text-[12.5px] font-semibold text-neutral-700 mb-1.5">
          নীলের শেড {!blueDesign && <span className="font-normal text-neutral-400">— শুধু নতুন নীল নকশায় খাটে</span>}
        </p>
        <div className="flex flex-wrap gap-1.5">
          <button type="button" onClick={() => onChange({ palette: 'auto' })} className={chip(style.palette === 'auto')} title="বিভাগ দেখে: বিনোদন → নীল-বেগুনি, অর্থনীতি → টিল, বাকি → রয়্যাল">
            বিভাগ অনুযায়ী
          </button>
          {Object.entries(palettes).map(([k, label]) => {
            const c = PAL_COLORS[k] || ['#0B2A6B', '#1546B8', '#FFC20E']
            const on = style.palette === k
            return (
              <button key={k} type="button" onClick={() => onChange({ palette: k })} className={`${chip(on)} inline-flex items-center gap-1.5`}>
                <span className="inline-flex rounded-full overflow-hidden ring-1 ring-black/10" aria-hidden="true">
                  <i className="w-3 h-4" style={{ background: c[0] }} />
                  <i className="w-3 h-4" style={{ background: c[1] }} />
                  <i className="w-2 h-4" style={{ background: c[2] }} />
                </span>
                {label}
              </button>
            )
          })}
        </div>
        <div className="flex items-center gap-2 mt-2 text-[12px] text-neutral-600">
          <span className="w-20 text-neutral-500">রঙিন শব্দ</span>
          <button type="button" className={chip(style.hiStyle === 'text')} onClick={() => onChange({ hiStyle: 'text' })}>
            <span style={{ color: '#C99400' }} className="font-bold">হলুদ লেখা</span>
          </button>
          <button type="button" className={chip(style.hiStyle === 'marker')} onClick={() => onChange({ hiStyle: 'marker' })}>
            <span className="font-bold px-1" style={{ background: '#FFC20E', color: '#1B1400' }}>মার্কার</span>
          </button>
        </div>
      </div>

      {/* title size */}
      <div>
        <p className="text-[12.5px] font-semibold text-neutral-700 mb-1.5 flex items-center gap-2">
          শিরোনামের মাপ
          <output className="text-[11.5px] font-normal text-neutral-500">{bnNumber(Math.round(style.titleScale * 100))}%</output>
        </p>
        <div className="flex items-center gap-2">
          <button type="button" aria-label="শিরোনাম ছোট" onClick={() => onChange({ titleScale: Math.max(0.6, Math.round((style.titleScale - 0.05) * 100) / 100) })} className="w-8 h-8 rounded-lg border border-neutral-200 text-[13px] font-bold">
            অ−
          </button>
          <input type="range" min={60} max={150} step={5} value={Math.round(style.titleScale * 100)} onChange={(e) => onChange({ titleScale: Number(e.target.value) / 100 })} className="flex-1" aria-label="শিরোনামের মাপ" />
          <button type="button" aria-label="শিরোনাম বড়" onClick={() => onChange({ titleScale: Math.min(1.5, Math.round((style.titleScale + 0.05) * 100) / 100) })} className="w-8 h-8 rounded-lg border border-neutral-200 text-[16px] font-bold">
            অ+
          </button>
        </div>
        <div className="flex flex-wrap gap-1 mt-1.5">
          {TITLE_STEPS.map(([v, l]) => (
            <button key={v} type="button" className={chip(Math.abs(style.titleScale - v) < 0.01)} onClick={() => onChange({ titleScale: v })}>
              {l}
            </button>
          ))}
        </div>
        <p className="text-[11px] text-neutral-400 mt-1">লেখা বেশি লম্বা হলে কার্ড নিজেই একটু ছোট করে নেয়, যাতে কাটা না পড়ে।</p>
      </div>

      {/* logo */}
      <div>
        <p className="text-[12.5px] font-semibold text-neutral-700 mb-1.5">লোগো</p>
        <div className="flex gap-3 items-start">
          <div className="grid grid-cols-3 gap-1 w-[132px] shrink-0 p-1 rounded-lg bg-neutral-100" role="radiogroup" aria-label="লোগোর জায়গা">
            {LOGO_GRID.slice(0, 3).map(([v, l]) => (
              <LogoCell key={v} v={v} l={l} on={style.logoPos === v} onPick={() => onChange({ logoPos: v })} />
            ))}
            <span className="col-span-3 h-6 rounded bg-white/70 text-[10px] text-neutral-400 flex items-center justify-center">কার্ড</span>
            {LOGO_GRID.slice(3).map(([v, l]) => (
              <LogoCell key={v} v={v} l={l} on={style.logoPos === v} onPick={() => onChange({ logoPos: v })} />
            ))}
          </div>
          <div className="flex-1 space-y-1.5">
            <div className="flex flex-wrap gap-1.5">
              <button type="button" className={chip(style.logoPos === 'auto')} onClick={() => onChange({ logoPos: 'auto' })}>
                নকশার নিজের জায়গায়
              </button>
              <button type="button" className={chip(style.logoPos === 'hide')} onClick={() => onChange({ logoPos: 'hide' })}>
                লোগো লুকান
              </button>
            </div>
            <label className="flex items-center gap-2 text-[12px] text-neutral-600">
              <span className="w-12 text-neutral-500">মাপ</span>
              <input type="range" min={50} max={180} step={5} value={Math.round(style.logoScale * 100)} onChange={(e) => onChange({ logoScale: Number(e.target.value) / 100 })} className="flex-1" disabled={style.logoPos === 'hide'} />
              <output className="w-10 text-right">{bnNumber(Math.round(style.logoScale * 100))}%</output>
            </label>
            <label className="flex items-center gap-1.5 text-[12px] text-neutral-600">
              <input type="checkbox" checked={style.logoStyle === 'plate'} disabled={style.logoPos === 'auto' || style.logoPos === 'hide'} onChange={(e) => onChange({ logoStyle: e.target.checked ? 'plate' : 'auto' })} />
              সাদা পাতের ওপর (ব্যস্ত ছবিতে স্পষ্ট দেখায়)
            </label>
          </div>
        </div>
      </div>

      {/* border + credit */}
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <p className="text-[12.5px] font-semibold text-neutral-700 mb-1.5">বর্ডার</p>
          <div className="flex flex-wrap gap-1.5">
            {(Object.keys(BORDERS) as CardStyle['border'][]).map((b) => (
              <button key={b} type="button" className={`${chip(style.border === b)} inline-flex items-center gap-1`} onClick={() => onChange({ border: b })}>
                {BORDERS[b].color && <i className="w-3 h-3 rounded-sm ring-1 ring-black/15" style={{ background: BORDERS[b].color }} />}
                {BORDERS[b].label}
              </button>
            ))}
          </div>
        </div>
        <label className="block">
          <span className="block text-[12.5px] font-semibold text-neutral-700 mb-1.5">ছবির ক্রেডিট</span>
          <input className={`${inputClass} !py-1.5 text-[12.5px]`} value={style.credit} maxLength={60} disabled={!hasPhoto} onChange={(e) => onChange({ credit: e.target.value })} placeholder="যেমন: ছবি: সংগৃহীত / ফোকাস বাংলা" />
        </label>
      </div>
    </div>
  )
}

function LogoCell({ v, l, on, onPick }: { v: string; l: string; on: boolean; onPick: () => void }) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={on}
      aria-label={l}
      title={l}
      onClick={onPick}
      data-pos={v}
      className={`h-7 rounded flex items-center justify-center ${on ? 'bg-brand-600' : 'bg-white hover:bg-brand-50'}`}
    >
      <span className={`block w-5 h-2 rounded-sm ${on ? 'bg-amber-300' : 'bg-neutral-300'}`} />
    </button>
  )
}
