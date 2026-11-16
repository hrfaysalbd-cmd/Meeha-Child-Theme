/**
 * Round 46 — the উক্তি tab's own tools:
 *   - বক্তার ছবি: from the gallery or the device; smart focus finds the face,
 *     and the face position / zoom can be nudged — every উক্তি কার্ড crops
 *     the photo (circle, arch, hexagon, strip…) around that point;
 *   - ছবির রং: the photo's own colour as the card's accent (optional);
 *   - স্বয়ংক্রিয় কার্ড: the 4–6 designs that suit this quote and photo,
 *     drawn as a ready grid — pick one, download one, or all in a ZIP.
 */
import { useEffect, useRef, useState } from 'react'
import { bnNumber } from '../ui'
import { IconDownload, IconFileZip, IconLibraryPhoto, IconPhotoPlus, IconSparkles, IconX } from '../Icon'

export interface AutoCard {
  id: string
  label: string
  url: string
  canvas: HTMLCanvasElement
}

export default function QuoteKit({
  photo,
  focus,
  zoom,
  note,
  onLibrary,
  onUpload,
  onRemove,
  onFocus,
  onZoom,
  accentOn,
  accent,
  onAccent,
  onAuto,
  autoBusy,
  cards,
  selected,
  onUse,
  onSave,
  onSaveAll,
}: {
  photo: string
  focus: { x: number; y: number }
  zoom: number
  note: string
  onLibrary: () => void
  onUpload: (f: File) => void
  onRemove: () => void
  onFocus: (f: { x: number; y: number }) => void
  onZoom: (z: number) => void
  accentOn: boolean
  accent: string | null
  onAccent: (on: boolean) => void
  onAuto: () => void
  autoBusy: boolean
  cards: AutoCard[]
  selected: string
  onUse: (id: string) => void
  onSave: (c: AutoCard) => void
  onSaveAll: () => void
}) {
  const fileRef = useRef<HTMLInputElement>(null)
  const [open, setOpen] = useState(true)
  useEffect(() => {
    if (cards.length) setOpen(true)
  }, [cards])
  const btn = 'inline-flex items-center gap-1.5 rounded-lg border border-neutral-200 hover:bg-neutral-50 text-neutral-700 text-[12.5px] px-2.5 py-1.5'
  return (
    <div className="space-y-3">
      <div className="flex gap-3 items-start">
        <div className="w-20 h-20 shrink-0 rounded-full overflow-hidden bg-neutral-100 border border-neutral-200 relative">
          {photo ? (
            <img src={photo} alt="বক্তার ছবি" className="w-full h-full object-cover" style={{ objectPosition: `${focus.x}% ${focus.y}%`, transform: `scale(${zoom})` }} />
          ) : (
            <span className="absolute inset-0 flex items-center justify-center text-[11px] text-neutral-400 text-center px-1">ছবি নেই</span>
          )}
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-[12.5px] font-medium text-neutral-700 mb-1.5">বক্তার ছবি</p>
          <div className="flex flex-wrap gap-1.5">
            <button type="button" className={btn} onClick={onLibrary}>
              <IconLibraryPhoto className="w-4 h-4" /> গ্যালারি
            </button>
            <button type="button" className={btn} onClick={() => fileRef.current?.click()}>
              <IconPhotoPlus className="w-4 h-4" /> ডিভাইস থেকে
            </button>
            {photo && (
              <button type="button" className={`${btn} text-red-600`} onClick={onRemove}>
                <IconX className="w-4 h-4" /> সরান
              </button>
            )}
          </div>
          <input
            ref={fileRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            hidden
            onChange={(e) => {
              const f = e.target.files?.[0]
              if (f) onUpload(f)
              e.target.value = ''
            }}
          />
          {note && <p className="text-[11.5px] text-emerald-700 mt-1.5">{note}</p>}
        </div>
      </div>

      {photo && (
        <div className="grid grid-cols-3 gap-2 text-[11.5px] text-neutral-600">
          <label className="block">
            মুখ: ডানে-বাঁয়ে
            <input type="range" min={0} max={100} value={focus.x} onChange={(e) => onFocus({ ...focus, x: Number(e.target.value) })} className="w-full" />
          </label>
          <label className="block">
            ওপরে-নিচে
            <input type="range" min={0} max={100} value={focus.y} onChange={(e) => onFocus({ ...focus, y: Number(e.target.value) })} className="w-full" />
          </label>
          <label className="block">
            জুম
            <input type="range" min={100} max={200} value={Math.round(zoom * 100)} onChange={(e) => onZoom(Number(e.target.value) / 100)} className="w-full" />
          </label>
        </div>
      )}

      <label className="flex items-center gap-2 text-[12.5px] text-neutral-700">
        <input type="checkbox" checked={accentOn} disabled={!photo} onChange={(e) => onAccent(e.target.checked)} />
        ছবির রং থেকে অ্যাকসেন্ট (উক্তি কার্ডে)
        {accentOn && accent && <span className="inline-block w-4 h-4 rounded-full border border-neutral-300" style={{ background: accent }} title={accent} />}
        {accentOn && !accent && photo && <span className="text-[11px] text-neutral-400">ছবিতে স্পষ্ট রং নেই — হলুদই থাকছে</span>}
      </label>

      <div className="rounded-xl bg-brand-50 border border-brand-100 p-3">
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={onAuto}
            disabled={autoBusy}
            className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-[13px] font-semibold px-3 py-2 disabled:opacity-60"
          >
            <IconSparkles className="w-4 h-4" /> {autoBusy ? 'তৈরি হচ্ছে…' : 'স্বয়ংক্রিয় কার্ড'}
          </button>
          <span className="text-[11.5px] text-brand-800/80 leading-snug">লেখার দৈর্ঘ্য, ছবির গড়ন ও মুখের অবস্থান দেখে মানানসই ৬টি নকশা একসাথে।</span>
        </div>
        {cards.length > 0 && (
          <>
            <div className="flex items-center gap-2 mt-3">
              <button type="button" className="text-[12px] text-brand-700 font-medium" onClick={() => setOpen((o) => !o)}>
                {open ? 'লুকান' : `দেখান (${bnNumber(cards.length)})`}
              </button>
              <button type="button" onClick={onSaveAll} className="ml-auto inline-flex items-center gap-1 rounded-lg bg-white border border-brand-200 text-brand-700 text-[12px] font-medium px-2.5 py-1">
                <IconFileZip className="w-4 h-4" /> সব জিপে
              </button>
            </div>
            {open && (
              <ul className="grid grid-cols-2 sm:grid-cols-3 gap-2 mt-2">
                {cards.map((c) => (
                  <li key={c.id} className={`rounded-lg overflow-hidden border-2 bg-white ${selected === c.id ? 'border-brand-600' : 'border-transparent'}`}>
                    <button type="button" onClick={() => onUse(c.id)} className="block w-full" title="এই নকশা বাছুন">
                      <img src={c.url} alt={c.label} className="w-full h-auto block" />
                    </button>
                    <div className="flex items-center gap-1 px-1.5 py-1">
                      <span className="text-[11px] text-neutral-700 truncate flex-1">{c.label}</span>
                      <button type="button" onClick={() => onSave(c)} className="text-brand-600 p-0.5" title="PNG ডাউনলোড" aria-label={`ডাউনলোড: ${c.label}`}>
                        <IconDownload className="w-4 h-4" />
                      </button>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </div>
    </div>
  )
}
