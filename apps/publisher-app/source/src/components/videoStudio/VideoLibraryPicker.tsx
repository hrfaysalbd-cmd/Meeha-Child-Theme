/**
 * Round 21 — pick a video from the media library ("ভিডিও গ্যালারি"): ad
 * clips for an ad break, or a custom intro/outro. Ads can be uploaded
 * right here and any clip can be marked / unmarked as an ad, so the
 * "বিজ্ঞাপন" tab becomes the newsroom's ad shelf.
 */
import { useEffect, useRef, useState } from 'react'
import { MediaApi, VideoKitApi, describeError, type LibraryVideo } from '../../lib/api'
import { bnDuration, bnFileSize } from '../../lib/postMeta'
import { VIDEO_ACCEPT, isAllowedVideo } from '../../lib/video'
import { ErrorRetry, Spinner, bnNumber } from '../ui'
import { IconAd, IconSearch, IconUpload, IconX } from '../Icon'

export default function VideoLibraryPicker({
  title,
  adsFirst = true,
  onSelect,
  onClose,
}: {
  title: string
  adsFirst?: boolean
  onSelect: (v: LibraryVideo) => void
  onClose: () => void
}) {
  const [tab, setTab] = useState<'ads' | 'all'>(adsFirst ? 'ads' : 'all')
  const [items, setItems] = useState<LibraryVideo[]>([])
  const [hasMore, setHasMore] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<unknown>(null)
  const [query, setQuery] = useState('')
  const [upload, setUpload] = useState<{ percent: number } | null>(null)
  const [uploadError, setUploadError] = useState('')
  const seq = useRef(0)
  const fileInput = useRef<HTMLInputElement>(null)
  const abortRef = useRef<AbortController | null>(null)

  async function load(offset: number) {
    const mine = ++seq.current
    if (offset === 0) setLoading(true)
    setError(null)
    try {
      const res = await VideoKitApi.library(offset, query.trim(), tab === 'ads')
      if (mine !== seq.current) return
      setItems((prev) => (offset === 0 ? res.items : [...prev, ...res.items]))
      setHasMore(res.has_more)
    } catch (err) {
      if (mine === seq.current) setError(err ?? new Error('unknown'))
    } finally {
      if (mine === seq.current) setLoading(false)
    }
  }

  useEffect(() => {
    const t = setTimeout(() => load(0), query ? 350 : 0)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, query])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('keydown', onKey)
      abortRef.current?.abort()
    }
  }, [onClose])

  async function uploadFile(file: File) {
    setUploadError('')
    if (!isAllowedVideo(file)) {
      setUploadError('শুধু MP4, WebM বা MOV ভিডিও দেওয়া যাবে।')
      return
    }
    const ctrl = new AbortController()
    abortRef.current = ctrl
    setUpload({ percent: 0 })
    try {
      const res = await MediaApi.uploadVideo(file, (p) => setUpload({ percent: p.percent }), ctrl.signal)
      const name = file.name.replace(/\.[^.]+$/, '')
      const row = await VideoKitApi.updateVideo(res.id, { is_ad: tab === 'ads', title: name })
      setItems((prev) => [row, ...prev])
    } catch (err) {
      if (!ctrl.signal.aborted) setUploadError(describeError(err).message)
    } finally {
      setUpload(null)
      abortRef.current = null
    }
  }

  async function toggleAd(v: LibraryVideo) {
    try {
      const row = await VideoKitApi.updateVideo(v.id, { is_ad: !v.is_ad })
      setItems((prev) => (tab === 'ads' && !row.is_ad ? prev.filter((x) => x.id !== v.id) : prev.map((x) => (x.id === v.id ? row : x))))
    } catch (err) {
      setUploadError(describeError(err).message)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" onClick={onClose}>
      <div className="bg-white rounded-2xl w-full max-w-3xl max-h-[88vh] flex flex-col shadow-xl" onClick={(e) => e.stopPropagation()} data-testid="video-library">
        <div className="flex items-center justify-between px-5 py-4 border-b border-neutral-100">
          <div>
            <h2 className="text-[15px] font-semibold text-neutral-900">{title}</h2>
            <p className="text-[12px] text-neutral-500">মিডিয়া লাইব্রেরির ভিডিও — বিজ্ঞাপন হিসেবে চিহ্নিত ক্লিপগুলো “বিজ্ঞাপন” ট্যাবে থাকে।</p>
          </div>
          <button onClick={onClose} aria-label="বন্ধ করুন" className="text-neutral-400 hover:text-neutral-700">
            <IconX className="w-5 h-5" />
          </button>
        </div>

        <div className="px-5 pt-4 flex flex-wrap items-center gap-2">
          <div className="inline-flex rounded-lg bg-neutral-100 p-1" role="tablist">
            {(
              [
                ['ads', 'বিজ্ঞাপন'],
                ['all', 'সব ভিডিও'],
              ] as const
            ).map(([id, label]) => (
              <button
                key={id}
                role="tab"
                aria-selected={tab === id}
                onClick={() => setTab(id)}
                className={`px-3 py-1.5 rounded-md text-[12.5px] font-medium ${tab === id ? 'bg-white shadow-sm text-neutral-900' : 'text-neutral-500'}`}
              >
                {label}
              </button>
            ))}
          </div>
          <div className="relative flex-1 min-w-[160px]">
            <input
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="ভিডিওর নাম দিয়ে খুঁজুন"
              className="w-full rounded-xl border border-neutral-200 bg-neutral-50 pl-9 pr-3 py-2 text-[13.5px] focus:outline-none focus:ring-2 focus:ring-brand-600/30"
            />
            <IconSearch className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400" />
          </div>
          <input
            ref={fileInput}
            type="file"
            accept={VIDEO_ACCEPT}
            className="hidden"
            onChange={(e) => {
              const f = e.target.files?.[0]
              if (f) uploadFile(f)
              e.target.value = ''
            }}
          />
          <button
            type="button"
            onClick={() => fileInput.current?.click()}
            disabled={!!upload}
            className="flex items-center gap-1.5 px-3 py-2 rounded-xl bg-brand-600 text-white text-[12.5px] font-semibold disabled:opacity-60"
          >
            <IconUpload className="w-4 h-4" />
            {upload ? `আপলোড ${bnNumber(upload.percent)}%` : tab === 'ads' ? 'নতুন বিজ্ঞাপন আপলোড' : 'ভিডিও আপলোড'}
          </button>
        </div>
        {uploadError && <p className="mx-5 mt-2 text-[12px] text-red-600">{uploadError}</p>}

        <div className="p-5 overflow-y-auto">
          {loading ? (
            <Spinner />
          ) : error != null && items.length === 0 ? (
            <ErrorRetry error={error} onRetry={() => load(0)} />
          ) : items.length === 0 ? (
            <p className="py-10 text-center text-[13px] text-neutral-400">
              {tab === 'ads' ? 'এখনো কোনো বিজ্ঞাপন ক্লিপ নেই — উপরে “নতুন বিজ্ঞাপন আপলোড” দিন, অথবা “সব ভিডিও” থেকে কোনো ক্লিপকে বিজ্ঞাপন হিসেবে চিহ্নিত করুন।' : 'কোনো ভিডিও পাওয়া যায়নি।'}
            </p>
          ) : (
            <>
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                {items.map((v) => (
                  <div key={v.id} className="rounded-xl border border-neutral-200 overflow-hidden flex flex-col">
                    <button type="button" onClick={() => onSelect(v)} className="relative aspect-video bg-neutral-900 group" title={v.title}>
                      {v.thumb ? (
                        <img src={v.thumb} alt="" className="w-full h-full object-cover" loading="lazy" />
                      ) : (
                        <video src={`${v.url}#t=1`} preload="metadata" muted playsInline className="w-full h-full object-cover pointer-events-none" />
                      )}
                      <span className="absolute inset-0 bg-brand-600/0 group-hover:bg-brand-600/20 transition" />
                      {v.duration ? (
                        <span className="absolute bottom-1.5 right-1.5 text-[11px] bg-black/70 text-white rounded px-1.5 py-0.5">{bnDuration(Math.round(v.duration))}</span>
                      ) : null}
                      {v.is_ad && <span className="absolute top-1.5 left-1.5 text-[10.5px] bg-amber-400 text-black font-semibold rounded px-1.5 py-0.5">বিজ্ঞাপন</span>}
                    </button>
                    <div className="px-2.5 py-2 flex-1 flex flex-col gap-1.5">
                      <p className="text-[12px] font-medium text-neutral-800 line-clamp-2">{v.title || `ভিডিও #${bnNumber(v.id)}`}</p>
                      <p className="text-[11px] text-neutral-400">{v.size ? bnFileSize(v.size) : ''}</p>
                      <div className="mt-auto flex gap-1.5">
                        <button type="button" onClick={() => onSelect(v)} className="flex-1 text-[12px] font-semibold rounded-lg bg-brand-600 text-white py-1.5">
                          বেছে নিন
                        </button>
                        <button
                          type="button"
                          onClick={() => toggleAd(v)}
                          title={v.is_ad ? 'বিজ্ঞাপনের তালিকা থেকে সরান' : 'বিজ্ঞাপন হিসেবে চিহ্নিত করুন'}
                          className={`px-2 rounded-lg ${v.is_ad ? 'bg-amber-100 text-amber-800' : 'bg-neutral-100 text-neutral-500'}`}
                        >
                          <IconAd className="w-4 h-4" />
                        </button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
              {hasMore && (
                <div className="mt-4 text-center">
                  <button onClick={() => load(items.length)} className="px-4 py-2 rounded-xl bg-neutral-100 text-neutral-700 text-[13px] font-medium hover:bg-neutral-200">
                    আরও দেখুন
                  </button>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  )
}
