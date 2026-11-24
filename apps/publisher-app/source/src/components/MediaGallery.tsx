/**
 * Round 9 follow-up 5 — pick an already-uploaded image instead of uploading
 * it again (re-uploading after removing an image from a post creates a
 * duplicate in the media library). Used for inline article images
 * (RichEditor) and for the featured image (PostEditor).
 *
 * Round 18: a search box finds OLD images too — by the image's title,
 * caption, alt text or file name, or by the headline of the post that uses
 * it — and "আরও দেখুন" keeps paging (the old 50-image cap is gone).
 */
import { useEffect, useRef, useState } from 'react'
import { AdsApi, MediaApi, type LibraryImage } from '../lib/api'
import { ErrorRetry, Spinner, bnNumber } from './ui'
import { IconSearch, IconPhoto } from './Icon'

const SEARCH_DEBOUNCE_MS = 350

/**
 * r49 — `upload` adds a "নতুন ছবি আপলোড" button (the new file is selected at once), and
 * `source="ads"` opens on the ad gallery (every image ever used as an ad, in use first) with a
 * switch to all images; whatever is picked or uploaded there joins the ad gallery.
 */
export default function MediaGallery({
  title = 'গ্যালারি থেকে ছবি বেছে নিন',
  onSelect,
  onClose,
  upload = false,
  source = 'media',
}: {
  title?: string
  onSelect: (image: LibraryImage) => void
  onClose: () => void
  upload?: boolean
  source?: 'media' | 'ads'
}) {
  const [tab, setTab] = useState<'media' | 'ads'>(source)
  const [uploading, setUploading] = useState(false)
  const [upErr, setUpErr] = useState('')
  const adsMode = source === 'ads'
  const choose = (img: LibraryImage) => {
    if (adsMode) void AdsApi.libraryMark(img.id).catch(() => undefined)
    onSelect(img)
  }
  const pickFile = () => {
    const input = document.createElement('input')
    input.type = 'file'
    input.accept = 'image/png,image/jpeg,image/webp,image/gif'
    input.onchange = async () => {
      const f = input.files?.[0]
      if (!f) return
      if (adsMode && f.type === 'image/gif' && f.size > 150 * 1024) {
        setUpErr('GIF ছবিটি ১৫০ KB-এর বেশি — পাতা ভারী হয়ে যাবে। WebP বা ছোট GIF দিন।')
        return
      }
      setUploading(true)
      setUpErr('')
      try {
        const up = await MediaApi.upload(f, { alt: adsMode ? 'বিজ্ঞাপন' : '' })
        choose({ id: up.id, url: up.url, thumb: up.url, alt: up.alt, caption: up.caption, title: f.name, date: new Date().toISOString() })
      } catch (e) {
        setUpErr(e instanceof Error ? e.message : 'আপলোড হয়নি।')
      } finally {
        setUploading(false)
      }
    }
    input.click()
  }
  const [items, setItems] = useState<LibraryImage[]>([])
  const [hasMore, setHasMore] = useState(false)
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [error, setError] = useState<unknown>(null)
  const [query, setQuery] = useState('')
  const [search, setSearch] = useState('')
  const [total, setTotal] = useState<number | null>(null)
  // Ignores replies to an older search that arrive after a newer one.
  const seq = useRef(0)

  async function loadPage(offset: number, term = search) {
    const mine = ++seq.current
    if (offset === 0) setLoading(true)
    else setLoadingMore(true)
    setError(null)
    try {
      const res = tab === 'ads' ? await AdsApi.library(offset, term) : await MediaApi.library(offset, term)
      if (mine !== seq.current) return
      setItems((prev) => (offset === 0 ? res.items : [...prev, ...res.items]))
      setHasMore(res.has_more)
      setTotal(typeof res.total === 'number' ? res.total : null)
    } catch (err) {
      if (mine === seq.current) setError(err ?? new Error('unknown'))
    } finally {
      if (mine === seq.current) {
        setLoading(false)
        setLoadingMore(false)
      }
    }
  }

  useEffect(() => {
    const term = query.trim()
    const t = setTimeout(
      () => {
        setSearch(term)
        loadPage(0, term)
      },
      term === search ? 0 : SEARCH_DEBOUNCE_MS,
    )
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, tab])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" onClick={onClose}>
      <div
        className="bg-white rounded-2xl w-full max-w-3xl max-h-[85vh] flex flex-col shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between px-5 py-4 border-b border-neutral-100">
          <div>
            <h2 className="text-[15px] font-semibold text-neutral-900">{title}</h2>
            <p className="text-[12px] text-neutral-500">একই ছবি আবার আপলোড না করে এখান থেকে বেছে নিন — পুরোনো ছবি খুঁজতে নিচে লিখুন।</p>
          </div>
          <button onClick={onClose} aria-label="বন্ধ করুন" className="text-neutral-400 hover:text-neutral-700">
            <svg xmlns="http://www.w3.org/2000/svg" width={20} height={20} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
              <path d="M18 6l-12 12" />
              <path d="M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="px-5 pt-4">
          {(adsMode || upload) && (
            <div className="flex flex-wrap items-center gap-2 mb-3">
              {adsMode && (
                <div className="inline-flex rounded-xl bg-neutral-100 p-1 text-[12.5px]" role="tablist">
                  {(
                    [
                      ['ads', 'বিজ্ঞাপনের ছবি'],
                      ['media', 'সব ছবি'],
                    ] as const
                  ).map(([v, l]) => (
                    <button
                      key={v}
                      type="button"
                      role="tab"
                      aria-selected={tab === v}
                      onClick={() => setTab(v)}
                      className={`px-3 py-1.5 rounded-lg ${tab === v ? 'bg-white shadow-sm font-semibold text-neutral-900' : 'text-neutral-500'}`}
                    >
                      {l}
                    </button>
                  ))}
                </div>
              )}
              {upload && (
                <button
                  type="button"
                  onClick={pickFile}
                  disabled={uploading}
                  data-testid="gallery-upload"
                  className="ml-auto inline-flex items-center gap-1.5 rounded-xl bg-brand-600 text-white px-3.5 py-2 text-[13px] font-semibold hover:bg-brand-700 disabled:opacity-60"
                >
                  <IconPhoto className="w-4 h-4" />
                  {uploading ? 'আপলোড হচ্ছে…' : 'নতুন ছবি আপলোড'}
                </button>
              )}
            </div>
          )}
          {upErr && <p className="mb-2 text-[12.5px] text-red-600">{upErr}</p>}
          <div className="relative">
            <input
              type="search"
              autoFocus
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="ছবি খুঁজুন — ক্যাপশন, ফাইলের নাম বা সংবাদের শিরোনাম"
              className="w-full rounded-xl border border-neutral-200 bg-neutral-50 pl-9 pr-3 py-2.5 text-[14px] focus:outline-none focus:ring-2 focus:ring-brand-600/30 focus:border-brand-500"
            />
            <IconSearch className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400" />
          </div>
          {search && !loading && total !== null && (
            <p className="text-[12px] text-neutral-500 mt-2">“{search}” — {bnNumber(total)}টি ছবি পাওয়া গেছে</p>
          )}
        </div>

        <div className="p-5 overflow-y-auto">
          {loading ? (
            <Spinner />
          ) : error != null && items.length === 0 ? (
            <ErrorRetry error={error} onRetry={() => loadPage(0)} />
          ) : items.length === 0 ? (
            <p className="py-10 text-center text-[13px] text-neutral-400">
              {search
                ? `“${search}” দিয়ে কোনো ছবি পাওয়া যায়নি — অন্য শব্দ দিয়ে খুঁজুন।`
                : tab === 'ads'
                  ? 'বিজ্ঞাপনের গ্যালারি এখনো খালি — “নতুন ছবি আপলোড” চাপুন বা “সব ছবি” থেকে বেছে নিন।'
                  : 'এখনো কোনো ছবি আপলোড করা হয়নি।'}
            </p>
          ) : (
            <>
              <div className="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-2.5">
                {items.map((img) => (
                  <button
                    key={img.id}
                    type="button"
                    onClick={() => choose(img)}
                    title={img.caption || img.title}
                    className="group text-left rounded-xl overflow-hidden border border-neutral-200 hover:border-brand-500 hover:ring-2 hover:ring-brand-500/30 focus:outline-none focus:ring-2 focus:ring-brand-500"
                  >
                    <div className="aspect-square bg-neutral-100">
                      <img src={img.thumb} alt={img.alt} loading="lazy" className="w-full h-full object-cover" />
                    </div>
                    <p className="px-2 py-1.5 text-[11px] leading-tight text-neutral-500 truncate">
                      {img.caption || img.title || '—'}
                    </p>
                  </button>
                ))}
              </div>

              {error != null && <ErrorRetry error={error} onRetry={() => loadPage(items.length)} />}

              <div className="mt-4 flex flex-col items-center gap-1">
                {hasMore && (
                  <button
                    type="button"
                    onClick={() => loadPage(items.length)}
                    disabled={loadingMore}
                    className="px-4 py-2 rounded-xl bg-neutral-100 text-neutral-700 text-[13px] font-medium hover:bg-neutral-200 disabled:opacity-60"
                  >
                    {loadingMore ? 'লোড হচ্ছে…' : 'আরও দেখুন'}
                  </button>
                )}
                {!search && !hasMore && items.length > 0 && (
                  <p className="text-[11.5px] text-neutral-400">আরও পুরোনো ছবি পেতে উপরে খুঁজুন।</p>
                )}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
