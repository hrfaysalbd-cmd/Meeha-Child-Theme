/**
 * Round 24 — "ছবি": the post's own photos (featured first, then the photos
 * inside the article) in one strip, plus the media library and the device.
 * The picked photo's position (drag in the preview, or স্মার্ট ফোকাস), zoom
 * and tone belong to the photo, so every design and size crops it the same
 * way — and the position can be kept for the site's own photocard button.
 */
import { useRef } from 'react'
import type { CardFocus } from '../../lib/api'
import { LOOK_PRESETS, NO_LOOK, isPlain, type PhotoLook } from '../../lib/photocard/look'
import { bnNumber } from '../ui'
import { IconLibraryPhoto, IconRefresh, IconUpload, IconWand, IconX } from '../Icon'

export interface PhotoItem {
  key: string
  id?: number
  /** Device photo: blob: URL of the file itself. */
  file?: string
  thumb: string
  label: string
  featured?: boolean
  fromPost?: boolean
  width?: number | null
  height?: number | null
  focus: { x: number; y: number }
  zoom: number
  look: PhotoLook
  /** Position saved on the site (attachment meta), to tell when it changed. */
  saved?: CardFocus | null
}

const SLIDERS: { k: keyof Omit<PhotoLook, 'gray'>; label: string; min: number; max: number }[] = [
  { k: 'bright', label: 'উজ্জ্বলতা', min: -40, max: 40 },
  { k: 'contrast', label: 'কনট্রাস্ট', min: -40, max: 40 },
  { k: 'sat', label: 'রং', min: -100, max: 60 },
  { k: 'warm', label: 'উষ্ণতা', min: -30, max: 30 },
]

export default function PhotoPanel({
  photos,
  selected,
  optional,
  onSelect,
  onPatch,
  onLibrary,
  onUpload,
  onSmart,
  smartBusy,
  smartNote,
  collage,
  galleryKeys,
  onToggleGallery,
  focusSync,
  onFocusSync,
  canSync,
}: {
  photos: PhotoItem[]
  selected: PhotoItem | null
  optional: string | null
  onSelect: (key: string | null) => void
  onPatch: (key: string, patch: Partial<PhotoItem>) => void
  onLibrary: () => void
  onUpload: (file: File) => void
  onSmart: () => void
  smartBusy: boolean
  smartNote: string
  collage: boolean
  galleryKeys: string[]
  onToggleGallery: (key: string) => void
  focusSync: boolean
  onFocusSync: (v: boolean) => void
  canSync: boolean
}) {
  const fileRef = useRef<HTMLInputElement>(null)
  const small = selected?.width && selected.width < 1080
  const look = selected?.look || NO_LOOK
  const lookId = LOOK_PRESETS.find((p) => JSON.stringify(p.look) === JSON.stringify(look))?.id

  return (
    <div>
      <div className="flex gap-2 overflow-x-auto pb-1.5 -mx-1 px-1" role="listbox" aria-label="ছবি">
        {photos.map((p) => {
          const on = selected?.key === p.key
          const gi = galleryKeys.indexOf(p.key)
          return (
            <div key={p.key} className="relative shrink-0">
              <button
                type="button"
                role="option"
                aria-selected={on}
                onClick={() => onSelect(p.key)}
                title={p.label}
                className={`block w-[76px] h-[76px] rounded-xl overflow-hidden border-2 transition ${
                  on ? 'border-brand-600 ring-2 ring-brand-600/25' : 'border-transparent hover:border-neutral-300'
                }`}
              >
                <img src={p.thumb} alt={p.label} className="w-full h-full object-cover bg-neutral-100" />
              </button>
              {p.featured && (
                <span className="absolute left-1 top-1 text-[9.5px] font-bold px-1.5 py-0.5 rounded bg-brand-600 text-white">ফিচার</span>
              )}
              {collage && !on && (
                <button
                  type="button"
                  onClick={() => onToggleGallery(p.key)}
                  aria-pressed={gi > -1}
                  title={gi > -1 ? 'কোলাজ থেকে বাদ দিন' : 'কোলাজে যোগ করুন'}
                  className={`absolute right-1 bottom-1 w-6 h-6 rounded-full text-[11px] font-bold border-2 border-white shadow ${
                    gi > -1 ? 'bg-brand-600 text-white' : 'bg-white/90 text-neutral-500'
                  }`}
                >
                  {gi > -1 ? bnNumber(gi + 2) : '+'}
                </button>
              )}
            </div>
          )
        })}
        <button
          type="button"
          onClick={onLibrary}
          className="shrink-0 w-[76px] h-[76px] rounded-xl border-2 border-dashed border-neutral-300 text-neutral-500 hover:border-brand-400 hover:text-brand-600 flex flex-col items-center justify-center gap-1 text-[10.5px] font-medium"
        >
          <IconLibraryPhoto className="w-5 h-5" />
          গ্যালারি
        </button>
        <button
          type="button"
          onClick={() => fileRef.current?.click()}
          className="shrink-0 w-[76px] h-[76px] rounded-xl border-2 border-dashed border-neutral-300 text-neutral-500 hover:border-brand-400 hover:text-brand-600 flex flex-col items-center justify-center gap-1 text-[10.5px] font-medium"
        >
          <IconUpload className="w-5 h-5" />
          ডিভাইস
        </button>
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
      </div>

      {collage && (
        <p className="text-[12px] text-brand-700 bg-brand-50 rounded-lg px-2.5 py-1.5 mt-1.5">
          কোলাজ: বড় ঘরে বাছাই করা ছবি, বাকি ঘরে <b>+</b> চাপা ছবিগুলো (সর্বোচ্চ ৩টি)।
        </p>
      )}

      {!selected && <p className="text-[12.5px] text-neutral-500 mt-2">{optional || 'ছবি বাছাই করা হয়নি।'}</p>}

      {selected && (
        <div className="mt-3 space-y-3">
          <div className="flex items-center gap-2 flex-wrap">
            <button
              type="button"
              onClick={onSmart}
              disabled={smartBusy}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-brand-600 text-white text-[12.5px] font-semibold disabled:opacity-60"
            >
              <IconWand className="w-4 h-4" /> {smartBusy ? 'খোঁজা হচ্ছে…' : 'স্মার্ট ফোকাস'}
            </button>
            <button
              type="button"
              onClick={() => onPatch(selected.key, { focus: { x: 50, y: 50 }, zoom: 1 })}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-neutral-100 text-neutral-700 text-[12.5px] font-medium"
            >
              <IconRefresh className="w-4 h-4" /> মাঝখানে
            </button>
            <button
              type="button"
              onClick={() => onSelect(null)}
              className="inline-flex items-center gap-1 px-2 py-1.5 rounded-lg text-neutral-500 hover:text-red-600 text-[12.5px]"
            >
              <IconX className="w-4 h-4" /> ছবি ছাড়া
            </button>
          </div>
          <p className="text-[12px] text-neutral-500 leading-relaxed">
            {smartNote || 'প্রিভিউতে ছবি টেনে সরান; চাকা/পিঞ্চ বা নিচের স্লাইডারে জুম। তীর-চাবিতেও সরানো যায়।'}
            {small && <span className="text-amber-700"> ছবিটি ছোট ({bnNumber(selected.width!)} পিক্সেল চওড়া) — কার্ডে ঝাপসা দেখাতে পারে।</span>}
          </p>
          <label className="block">
            <span className="flex justify-between text-[12px] text-neutral-600 mb-1">
              <span>জুম</span>
              <output>{bnNumber(Math.round(selected.zoom * 100))}%</output>
            </span>
            <input
              type="range"
              min={100}
              max={250}
              value={Math.round(selected.zoom * 100)}
              onChange={(e) => onPatch(selected.key, { zoom: Number(e.target.value) / 100 })}
              className="w-full accent-brand-600"
            />
          </label>

          <div>
            <span className="block text-[12px] text-neutral-600 mb-1.5">ছবির টোন</span>
            <div className="flex flex-wrap gap-1.5">
              {LOOK_PRESETS.map((p) => (
                <button
                  key={p.id}
                  type="button"
                  onClick={() => onPatch(selected.key, { look: p.look })}
                  aria-pressed={lookId === p.id}
                  className={`px-2.5 py-1 rounded-full text-[12px] border ${
                    lookId === p.id ? 'bg-brand-600 border-brand-600 text-white' : 'border-neutral-200 text-neutral-700 hover:border-neutral-300'
                  }`}
                >
                  {p.label}
                </button>
              ))}
            </div>
            <details className="mt-2 group">
              <summary className="text-[12px] text-brand-600 cursor-pointer select-none">নিজে ঠিক করুন{!isPlain(look) && !lookId ? ' (বদলানো)' : ''}</summary>
              <div className="grid grid-cols-2 gap-x-3 gap-y-2 mt-2">
                {SLIDERS.map((s) => (
                  <label key={s.k} className="block">
                    <span className="flex justify-between text-[11.5px] text-neutral-500">
                      <span>{s.label}</span>
                      <output>{look[s.k] > 0 ? '+' : ''}{bnNumber(look[s.k])}</output>
                    </span>
                    <input
                      type="range"
                      min={s.min}
                      max={s.max}
                      value={look[s.k]}
                      onChange={(e) => onPatch(selected.key, { look: { ...look, [s.k]: Number(e.target.value) } })}
                      className="w-full accent-brand-600"
                    />
                  </label>
                ))}
                <label className="flex items-center gap-2 text-[12px] text-neutral-700">
                  <input type="checkbox" checked={look.gray} onChange={(e) => onPatch(selected.key, { look: { ...look, gray: e.target.checked } })} />
                  সাদা-কালো
                </label>
              </div>
            </details>
          </div>

          {canSync && selected.id && (
            <label className="flex items-start gap-2 text-[12px] text-neutral-600 bg-neutral-50 rounded-lg px-2.5 py-2">
              <input type="checkbox" className="mt-0.5" checked={focusSync} onChange={(e) => onFocusSync(e.target.checked)} />
              <span>
                ছবির এই অবস্থান মনে রাখুন — ডাউনলোড বা সেভ করলে সাইটের “ফটোকার্ড ডাউনলোড” বোতামও {selected.featured ? 'এই ফিচার ছবি' : 'এই ছবি'} একইভাবে কাটবে।
              </span>
            </label>
          )}
        </div>
      )}
    </div>
  )
}
