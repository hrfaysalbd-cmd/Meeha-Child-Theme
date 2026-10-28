/**
 * Round 13 — "অতিথি লেখক" (guest author) control under the author picker of
 * the "প্রকাশের তথ্য" card. A guest's name / পদবি / photo replace the WordPress
 * author on the site's bylines; the post still belongs to (and pays) its WP
 * author. Saved guests (GET /admin/guest-authors) can be searched and reused;
 * "ভবিষ্যতের জন্য সংরক্ষণ করুন" stores the typed guest in that list on save.
 * Same permission as changing the author (profile.can.change_post_author).
 */
import { useEffect, useRef, useState } from 'react'
import {
  GUEST_NAME_MAX,
  GUEST_TITLE_MAX,
  GuestAuthorsApi,
  MediaApi,
  describeError,
  type GuestAuthor,
  type SavedGuestAuthor,
} from '../lib/api'
import MediaGallery from './MediaGallery'
import { bnNumber, inputClass } from './ui'
import { IconLibraryPhoto, IconPhotoPlus, IconSearch, IconTrash, IconX } from './Icon'

export type GuestDraft = GuestAuthor

export const EMPTY_GUEST: GuestDraft = { name: '', title: '', photo_id: 0, photo_url: null }

/** Comparable key of a guest value (null = no guest). */
export function guestKey(g: GuestDraft | null | undefined): string {
  if (!g) return ''
  return JSON.stringify([g.name.trim().replace(/\s+/g, ' '), g.title.trim().replace(/\s+/g, ' '), g.photo_id || 0])
}

const SEARCH_DEBOUNCE_MS = 300

function GuestPhoto({ url, name, size = 40 }: { url: string | null; name: string; size?: number }) {
  const [broken, setBroken] = useState(false)
  const style = { width: size, height: size }
  if (url && !broken) {
    return <img src={url} alt="" style={style} onError={() => setBroken(true)} className="rounded-full object-cover bg-neutral-100 shrink-0" />
  }
  return (
    <span style={style} aria-hidden className="rounded-full bg-amber-50 text-amber-700 text-[13px] font-semibold flex items-center justify-center shrink-0">
      {name.trim().charAt(0) || '?'}
    </span>
  )
}

export default function GuestAuthorField({
  value,
  onChange,
  save,
  onSaveChange,
  disabled,
}: {
  value: GuestDraft | null // null = no guest (toggle off)
  onChange: (value: GuestDraft | null) => void
  save: boolean
  onSaveChange: (save: boolean) => void
  disabled?: boolean
}) {
  const on = value !== null
  const fileRef = useRef<HTMLInputElement>(null)
  const seq = useRef(0)
  const [query, setQuery] = useState('')
  const [saved, setSaved] = useState<SavedGuestAuthor[]>([])
  const [loading, setLoading] = useState(false)
  const [listError, setListError] = useState('')
  const [uploading, setUploading] = useState(false)
  const [photoError, setPhotoError] = useState('')
  const [galleryOpen, setGalleryOpen] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null)

  // Saved guests: loaded when the section is on, re-searched (debounced) on typing.
  useEffect(() => {
    if (!on || disabled) return
    const mySeq = ++seq.current
    const timer = setTimeout(
      () => {
        setLoading(true)
        setListError('')
        GuestAuthorsApi.search(query)
          .then((rows) => mySeq === seq.current && setSaved(rows))
          .catch((err) => mySeq === seq.current && (setSaved([]), setListError(describeError(err).message)))
          .finally(() => mySeq === seq.current && setLoading(false))
      },
      query ? SEARCH_DEBOUNCE_MS : 0,
    )
    return () => clearTimeout(timer)
  }, [on, query, disabled])

  const v = value ?? EMPTY_GUEST
  const set = (patch: Partial<GuestDraft>) => onChange({ ...v, ...patch })

  async function upload(file: File) {
    setPhotoError('')
    setUploading(true)
    try {
      const res = await MediaApi.upload(file)
      set({ photo_id: res.id, photo_url: res.url })
    } catch (err) {
      setPhotoError(describeError(err).message)
    } finally {
      setUploading(false)
    }
  }

  async function removeSaved(id: string) {
    try {
      await GuestAuthorsApi.remove(id)
      setSaved((rows) => rows.filter((r) => r.id !== id))
    } catch (err) {
      setListError(describeError(err).message)
    } finally {
      setConfirmDelete(null)
    }
  }

  return (
    <div className="mt-3 rounded-xl border border-neutral-200 bg-neutral-50/60" data-testid="guest-author">
      <label className={`flex items-center gap-3 px-3 min-h-[48px] ${disabled ? 'cursor-not-allowed' : 'cursor-pointer'}`}>
        <input
          type="checkbox"
          role="switch"
          aria-checked={on}
          data-testid="guest-toggle"
          className="peer sr-only"
          checked={on}
          disabled={disabled}
          onChange={(e) => onChange(e.target.checked ? { ...EMPTY_GUEST } : null)}
        />
        <span
          aria-hidden
          className={`relative w-10 h-6 rounded-full transition-colors shrink-0 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-600/40 ${on ? 'bg-brand-600' : 'bg-neutral-300'} ${disabled ? 'opacity-60' : ''}`}
        >
          <span className={`absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform ${on ? 'translate-x-4' : ''}`} />
        </span>
        <span className="min-w-0">
          <span className="block text-[13px] font-semibold text-neutral-800">অতিথি লেখক</span>
          <span className="block text-[11.5px] text-neutral-500 leading-snug">
            {on ? 'সাইটে লেখকের জায়গায় এই নাম দেখাবে' : 'বাইরের কারো লেখা? নাম, পদবি ও ছবি দিন'}
          </span>
        </span>
      </label>

      {on && (
        <div className="px-3 pb-3 pt-1 border-t border-neutral-200/80">
          {!disabled && (
            <div className="mb-3">
              <div className="flex items-center gap-2 rounded-lg border border-neutral-200 bg-white px-2.5 focus-within:ring-2 focus-within:ring-brand-600/20 focus-within:border-brand-500">
                <IconSearch className="w-4 h-4 text-neutral-400 shrink-0" />
                <input
                  className="w-full py-2.5 text-[13.5px] bg-transparent focus:outline-none placeholder:text-neutral-400"
                  placeholder="সংরক্ষিত অতিথি লেখক খুঁজুন"
                  aria-label="সংরক্ষিত অতিথি লেখক খুঁজুন"
                  data-testid="guest-search"
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                />
              </div>
              {listError ? (
                <p className="text-[12px] text-red-600 mt-1.5">{listError}</p>
              ) : saved.length > 0 ? (
                <ul className="mt-1.5 max-h-48 overflow-y-auto rounded-lg border border-neutral-200 bg-white divide-y divide-neutral-100" data-testid="guest-saved">
                  {saved.map((g) => (
                    <li key={g.id} className="flex items-center gap-2 pr-1">
                      <button
                        type="button"
                        className="flex-1 min-w-0 flex items-center gap-2.5 px-2.5 py-2 min-h-[44px] text-left hover:bg-neutral-50"
                        onClick={() => {
                          onChange({ name: g.name, title: g.title, photo_id: g.photo_id, photo_url: g.photo_url })
                          onSaveChange(false)
                          setQuery('')
                        }}
                      >
                        <GuestPhoto url={g.photo_url} name={g.name} size={32} />
                        <span className="min-w-0">
                          <span className="block text-[13px] font-medium text-neutral-900 truncate">{g.name}</span>
                          {g.title && <span className="block text-[11.5px] text-neutral-500 truncate">{g.title}</span>}
                        </span>
                      </button>
                      {confirmDelete === g.id ? (
                        <span className="flex items-center gap-1 shrink-0">
                          <button type="button" className="text-[12px] font-medium text-red-600 px-2 min-h-[36px]" onClick={() => removeSaved(g.id)}>
                            মুছুন
                          </button>
                          <button type="button" className="text-[12px] text-neutral-500 px-1.5 min-h-[36px]" onClick={() => setConfirmDelete(null)}>
                            বাতিল
                          </button>
                        </span>
                      ) : (
                        <button
                          type="button"
                          aria-label={`${g.name} — তালিকা থেকে মুছুন`}
                          title="সংরক্ষিত তালিকা থেকে মুছুন"
                          className="w-9 h-9 rounded-lg flex items-center justify-center text-neutral-400 hover:text-red-600 hover:bg-red-50 shrink-0"
                          onClick={() => setConfirmDelete(g.id)}
                        >
                          <IconTrash className="w-4 h-4" />
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-[11.5px] text-neutral-400 mt-1.5">
                  {loading ? 'খোঁজা হচ্ছে…' : query ? 'এই নামে সংরক্ষিত কেউ নেই — নিচে নতুন করে লিখুন।' : 'এখনো কোনো অতিথি লেখক সংরক্ষিত নেই।'}
                </p>
              )}
            </div>
          )}

          <div className="flex items-baseline justify-between mb-1">
            <label htmlFor="guest-name" className="text-[12.5px] font-medium text-neutral-600">
              লেখকের নাম <span className="text-brand-600">*</span>
            </label>
            <span className="text-[11px] text-neutral-400">{bnNumber(v.name.length)}/{bnNumber(GUEST_NAME_MAX)}</span>
          </div>
          <input
            id="guest-name"
            data-testid="guest-name"
            className={`${inputClass} min-h-[44px] mb-2.5`}
            value={v.name}
            maxLength={GUEST_NAME_MAX}
            disabled={disabled}
            onChange={(e) => set({ name: e.target.value })}
            placeholder="যেমন: ড. আনিসুর রহমান"
          />
          <div className="flex items-baseline justify-between mb-1">
            <label htmlFor="guest-title" className="text-[12.5px] font-medium text-neutral-600">
              টাইটেল / পদবি
            </label>
            <span className="text-[11px] text-neutral-400">{bnNumber(v.title.length)}/{bnNumber(GUEST_TITLE_MAX)}</span>
          </div>
          <input
            id="guest-title"
            data-testid="guest-title"
            className={`${inputClass} min-h-[44px] mb-2.5`}
            value={v.title}
            maxLength={GUEST_TITLE_MAX}
            disabled={disabled}
            onChange={(e) => set({ title: e.target.value })}
            placeholder="যেমন: অধ্যাপক, অর্থনীতি বিভাগ, ঢাকা বিশ্ববিদ্যালয়"
          />

          <p className="text-[12.5px] font-medium text-neutral-600 mb-1">ছবি (ঐচ্ছিক)</p>
          <input
            ref={fileRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            data-testid="guest-photo-file"
            onChange={(e) => {
              const f = e.target.files?.[0]
              if (f) upload(f)
              e.target.value = ''
            }}
          />
          <div className="flex items-center gap-2.5 flex-wrap">
            <GuestPhoto url={v.photo_url} name={v.name} size={48} />
            {!disabled && (
              <div className="flex gap-1.5 flex-wrap">
                <button
                  type="button"
                  disabled={uploading}
                  onClick={() => fileRef.current?.click()}
                  className="inline-flex items-center gap-1 rounded-lg bg-white border border-neutral-200 px-2.5 min-h-[40px] text-[12.5px] font-medium text-neutral-700 hover:bg-neutral-50"
                >
                  <IconPhotoPlus className="w-4 h-4" />
                  {uploading ? 'আপলোড হচ্ছে…' : 'আপলোড'}
                </button>
                <button
                  type="button"
                  disabled={uploading}
                  onClick={() => setGalleryOpen(true)}
                  className="inline-flex items-center gap-1 rounded-lg bg-white border border-neutral-200 px-2.5 min-h-[40px] text-[12.5px] font-medium text-neutral-700 hover:bg-neutral-50"
                >
                  <IconLibraryPhoto className="w-4 h-4" />
                  গ্যালারি
                </button>
                {v.photo_id > 0 && (
                  <button
                    type="button"
                    onClick={() => set({ photo_id: 0, photo_url: null })}
                    aria-label="ছবি সরান"
                    className="inline-flex items-center gap-1 rounded-lg px-2 min-h-[40px] text-[12.5px] text-neutral-500 hover:text-red-600"
                  >
                    <IconX className="w-4 h-4" />
                    সরান
                  </button>
                )}
              </div>
            )}
          </div>
          {photoError && <p className="text-[12px] text-red-600 mt-1.5">{photoError}</p>}

          {!disabled && (
            <label className="flex items-center gap-2.5 mt-3 min-h-[40px] cursor-pointer">
              <input
                type="checkbox"
                data-testid="guest-save"
                className="w-[18px] h-[18px] accent-brand-600"
                checked={save}
                onChange={(e) => onSaveChange(e.target.checked)}
              />
              <span className="text-[12.5px] text-neutral-700">ভবিষ্যতের জন্য সংরক্ষণ করুন</span>
            </label>
          )}
          <p className="text-[11px] text-neutral-400 mt-1.5 leading-relaxed">
            পোস্টটি ও এর পেমেন্ট উপরের লেখকের নামেই থাকবে; সাইটে শুধু অতিথি লেখকের নাম, পদবি ও ছবি দেখাবে।
          </p>
        </div>
      )}

      {galleryOpen && (
        <MediaGallery
          title="অতিথি লেখকের ছবি — গ্যালারি থেকে বেছে নিন"
          onClose={() => setGalleryOpen(false)}
          onSelect={(img) => {
            setGalleryOpen(false)
            set({ photo_id: img.id, photo_url: img.thumb || img.url })
          }}
        />
      )}
    </div>
  )
}
