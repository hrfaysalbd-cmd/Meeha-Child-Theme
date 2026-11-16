import { lazy, Suspense, useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  AreasApi,
  CategoriesApi,
  MediaApi,
  SubmissionsApi,
  ApiError,
  type AreasResponse,
  type Category,
  type RevisionEntry,
  type Submission,
} from '../lib/api'
import { Banner, Button, Field, inputClass, TopBar, Spinner, bnNumber } from '../components/ui'
import { IconCoin, IconEdit, IconHistory, IconPhotoPlus } from '../components/Icon'
import WriteModeSwitch from '../components/WriteModeSwitch'
import CategoryPicker from '../components/CategoryPicker'
import { rememberCategory } from '../lib/categoryTree'

const RECENT_CATEGORIES_KEY = 'nros_recent_categories'

// Tiptap (the rich editor) is a sizable chunk of JS that only the Write
// screen needs — lazy-loading it keeps the initial bundle for Home/News/
// Earn/Profile small, and the editor only downloads when someone actually
// taps "নিউজ এডিটরে যান".
const NewsEditorOverlay = lazy(() => import('../components/NewsEditorOverlay'))

function districtName(d: { name: string } | string) {
  return typeof d === 'string' ? d : d.name
}

const bnDigit = (n: number) => String(n).replace(/[0-9]/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])

/** Before any bytes move (the photo is being shrunk), while it goes up, and once the server has it. */
function uploadLabel(percent: number) {
  if (percent >= 100) return 'সার্ভারে প্রস্তুত হচ্ছে…'
  return percent > 0 ? `আপলোড হচ্ছে… ${bnDigit(percent)}%` : 'ছবি প্রস্তুত হচ্ছে…'
}

function plainPreview(html: string, max = 140) {
  const text = html
    .replace(/<img[^>]*>/gi, ' [ছবি] ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
  if (!text) return ''
  return text.length > max ? text.slice(0, max) + '…' : text
}

export default function Write() {
  const { id } = useParams()
  const navigate = useNavigate()
  const featuredInput = useRef<HTMLInputElement>(null)

  const [areas, setAreas] = useState<AreasResponse | null>(null)
  const [categories, setCategories] = useState<Category[]>([])
  const [categoriesState, setCategoriesState] = useState<'loading' | 'ok' | 'error'>('loading')
  const [loading, setLoading] = useState(!!id)
  const [post, setPost] = useState<Submission | null>(null)
  // Snapshot of the text fields as loaded, so a rejected draft can tell
  // whether the correspondent has actually changed anything yet — the
  // backend enforces this too (a resubmit with no real edit gets a 409),
  // this is just so the button can explain itself instead of just failing.
  // Round 12: the সংক্ষিপ্তসার (excerpt) and সারাংশ (AI summary) fields are
  // gone from this app — they are neither shown nor sent, so values an older
  // draft already has stay untouched on the server. Round 13: the same for
  // ট্যাগ (`tags` is neither shown nor sent; the desk adds tags).
  const initialSnapshot = useRef<{ title: string; content: string } | null>(null)

  const [title, setTitle] = useState('')
  const [content, setContent] = useState('')
  const [categoryId, setCategoryId] = useState<number>(0)
  const [division, setDivision] = useState('')
  const [district, setDistrict] = useState('')
  const [upazila, setUpazila] = useState('')
  const [featuredImage, setFeaturedImage] = useState<string | null>(null)
  const [featuredImageId, setFeaturedImageId] = useState<number | null>(null)
  const [featuredCaption, setFeaturedCaption] = useState('')

  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const [uploadingFeatured, setUploadingFeatured] = useState(false)
  const [featuredProgress, setFeaturedProgress] = useState(0)
  const [reason, setReason] = useState('')
  const [requestingEdit, setRequestingEdit] = useState(false)
  const [showEditor, setShowEditor] = useState(false)

  const [revisions, setRevisions] = useState<RevisionEntry[]>([])
  const [revisionsLoading, setRevisionsLoading] = useState(false)
  const [revisionsLoaded, setRevisionsLoaded] = useState(false)

  useEffect(() => {
    AreasApi.get().then(setAreas).catch(() => setAreas({ divisions: [], upazilas: {}, source: 'unavailable' }))
    loadCategories()
  }, [])

  function loadCategories() {
    setCategoriesState('loading')
    CategoriesApi.list()
      .then((c) => {
        setCategories(c)
        setCategoriesState('ok')
      })
      .catch(() => setCategoriesState('error'))
  }

  useEffect(() => {
    if (!id) return
    setLoading(true)
    SubmissionsApi.get(Number(id))
      .then((s) => {
        setPost(s)
        setTitle(s.title)
        setContent(s.content)
        setCategoryId(s.category_id)
        // `area` (round 12) with the legacy flat keys as fallback.
        setDivision(s.area?.division ?? s.division ?? '')
        setDistrict(s.area?.district ?? s.district ?? '')
        setUpazila(s.area?.upazila ?? s.upazila ?? '')
        setFeaturedImage(s.featured_image)
        setFeaturedImageId(s.featured_image_id)
        setFeaturedCaption(s.featured_image_caption)
        initialSnapshot.current = { title: s.title, content: s.content }
      })
      .catch(() => setError('সংবাদটি পাওয়া যায়নি।'))
      .finally(() => setLoading(false))
  }, [id])

  const districts = areas?.divisions.find((d) => d.name === division)?.districts ?? []
  const upazilas = areas?.upazilas[district] ?? []
  const isLocked = post?.status === 'publish'
  const isPending = post?.status === 'pending'
  const hasEditedSinceReject =
    !initialSnapshot.current ||
    title !== initialSnapshot.current.title ||
    content !== initialSnapshot.current.content
  const resubmitBlocked = !!post?.rejected && !hasEditedSinceReject

  // Only the published (locked) view has anything to train on — an editor's
  // changes aren't "final" until the post goes live. Loaded once per post.
  useEffect(() => {
    if (!isLocked || !post || revisionsLoaded) return
    setRevisionsLoading(true)
    SubmissionsApi.getRevisions(post.id)
      .then(setRevisions)
      .catch(() => setRevisions([]))
      .finally(() => {
        setRevisionsLoading(false)
        setRevisionsLoaded(true)
      })
  }, [isLocked, post, revisionsLoaded])

  function fields() {
    return {
      title,
      content,
      category_id: categoryId || undefined,
      area: { division, district, upazila },
      // legacy keys, still read by older plugin versions
      division,
      district,
      upazila,
    }
  }

  async function saveDraft(): Promise<Submission | null> {
    setError('')
    setNotice('')
    if (!title.trim()) {
      setError('শিরোনাম দিন।')
      return null
    }
    setSaving(true)
    try {
      let saved: Submission
      if (post) {
        saved = await SubmissionsApi.update(post.id, fields())
      } else {
        saved = await SubmissionsApi.create(fields())
        navigate(`/app/write/${saved.id}`, { replace: true })
      }
      setPost(saved)
      if (categoryId) rememberCategory(RECENT_CATEGORIES_KEY, categoryId)
      setNotice('ড্রাফট সংরক্ষিত হয়েছে।')
      return saved
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'সংরক্ষণ করা যায়নি।')
      return null
    } finally {
      setSaving(false)
    }
  }

  async function submitForReview() {
    setError('')
    const saved = await saveDraft()
    if (!saved) return
    setSaving(true)
    try {
      const submitted = await SubmissionsApi.submitForReview(saved.id)
      setPost(submitted)
      setNotice('রিভিউতে পাঠানো হয়েছে — সম্পাদক অনুমোদন করলে প্রকাশিত হবে।')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'পাঠানো যায়নি।')
    } finally {
      setSaving(false)
    }
  }

  async function onPickFeatured(file: File) {
    setError('')
    setUploadingFeatured(true)
    setFeaturedProgress(0)
    try {
      // The featured image attaches to a real post id, so an unsaved new
      // article is saved as a draft first — invisibly, so picking a cover
      // photo "just works" even before the correspondent has hit save.
      let target = post
      if (!target) {
        target = await SubmissionsApi.create(fields())
        setPost(target)
        navigate(`/app/write/${target.id}`, { replace: true })
      }
      const uploaded = await MediaApi.upload(file, undefined, (p) => setFeaturedProgress(p.percent))
      const updated = await SubmissionsApi.setFeaturedImage(target.id, uploaded.id)
      setPost(updated)
      setFeaturedImage(updated.featured_image)
      setFeaturedImageId(updated.featured_image_id)
      setFeaturedCaption(updated.featured_image_caption)
      setNotice('ফিচার ইমেজ যুক্ত হয়েছে।')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'ফিচার ইমেজ যুক্ত করা যায়নি।')
    } finally {
      setUploadingFeatured(false)
    }
  }

  async function saveFeaturedCaption() {
    if (!featuredImageId) return
    try {
      await MediaApi.updateMeta(featuredImageId, { caption: featuredCaption })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'ক্যাপশান সংরক্ষণ করা যায়নি।')
    }
  }

  async function sendEditRequest() {
    if (!post || !reason.trim()) return
    setRequestingEdit(true)
    setError('')
    try {
      await SubmissionsApi.requestEdit(post.id, reason)
      setNotice('এডিট রিকোয়েস্ট পাঠানো হয়েছে।')
      setReason('')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'পাঠানো যায়নি।')
    } finally {
      setRequestingEdit(false)
    }
  }

  if (loading) return <Spinner className="mt-10" />

  return (
    <div className="flex-1 flex flex-col">
      <TopBar title={post ? 'সংবাদ সম্পাদনা' : 'নতুন সংবাদ'} onBack={() => navigate('/app/news')} />
      {!post && <WriteModeSwitch mode="article" />}

      <div className="px-5 pt-5 pb-10">
        {error && <Banner kind="error">{error}</Banner>}
        {notice && <Banner kind="success">{notice}</Banner>}

        {isLocked ? (
          <>
            <Banner kind="info">এই সংবাদটি প্রকাশিত হয়ে গেছে — সরাসরি সম্পাদনা করা যাবে না। সংশোধনের জন্য এডিট রিকোয়েস্ট পাঠান।</Banner>
            <h2 className="text-[16px] font-bold text-neutral-900 mb-2">{post.title}</h2>
            {post.permalink && (
              <a href={post.permalink} target="_blank" rel="noreferrer" className="text-[13px] text-brand-600 underline mb-3 block">
                প্রকাশিত সংবাদটি দেখুন
              </a>
            )}
            {/* No payment set on this post → show nothing, not a zero. */}
            {!!post.payment && (
              <p className="flex items-center gap-1.5 text-[13.5px] font-medium text-emerald-600 mb-5">
                <IconCoin className="w-4 h-4" /> এই পোস্টের ইনকাম: ৳ {bnNumber(post.payment)}
              </p>
            )}

            <div className="rounded-xl border border-neutral-100 p-3.5 mb-5">
              <p className="flex items-center gap-1.5 text-[13px] font-semibold text-neutral-700 mb-1">
                <IconHistory className="w-4 h-4" /> এডিটোরিয়াল সংস্করণ ইতিহাস
              </p>
              <p className="text-[11.5px] text-neutral-400 mb-3">
                প্রকাশের আগে সম্পাদক কী কী পরিবর্তন করেছেন — কোথায় ইম্প্রুভ হয়েছে তা এখানে দেখে শিখতে পারবেন।
              </p>
              {revisionsLoading ? (
                <Spinner />
              ) : revisions.length === 0 ? (
                <p className="text-[12.5px] text-neutral-400">এই পোস্টে কোনো সম্পাদনার ইতিহাস নেই।</p>
              ) : (
                <div className="flex flex-col gap-4">
                  {revisions.map((r) => (
                    <div key={r.id} className="border-t border-neutral-100 pt-3 first:border-t-0 first:pt-0">
                      <p className="text-[11.5px] text-neutral-500 mb-2">
                        <span className="font-medium text-neutral-700">{r.author ?? 'সম্পাদক'}</span> পরিবর্তন করেছেন —{' '}
                        {new Date(r.date).toLocaleString('bn-BD')}
                      </p>
                      {r.title_diff && (
                        <div className="mb-2">
                          <p className="text-[11px] font-semibold text-neutral-500 mb-1">শিরোনাম</p>
                          <div className="text-[11.5px] overflow-x-auto wp-diff" dangerouslySetInnerHTML={{ __html: r.title_diff }} />
                        </div>
                      )}
                      {r.excerpt_diff && (
                        <div className="mb-2">
                          <p className="text-[11px] font-semibold text-neutral-500 mb-1">সংক্ষিপ্তসার</p>
                          <div className="text-[11.5px] overflow-x-auto wp-diff" dangerouslySetInnerHTML={{ __html: r.excerpt_diff }} />
                        </div>
                      )}
                      {r.content_diff && (
                        <div>
                          <p className="text-[11px] font-semibold text-neutral-500 mb-1">বিস্তারিত</p>
                          <div className="text-[11.5px] overflow-x-auto wp-diff" dangerouslySetInnerHTML={{ __html: r.content_diff }} />
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>

            <Field label="সংশোধনের কারণ লিখুন" required>
              <textarea className={inputClass} rows={4} value={reason} onChange={(e) => setReason(e.target.value)} />
            </Field>
            <Button onClick={sendEditRequest} disabled={requestingEdit || !reason.trim()}>
              {requestingEdit ? 'পাঠানো হচ্ছে…' : 'এডিট রিকোয়েস্ট পাঠান'}
            </Button>
          </>
        ) : (
          <>
            {isPending && <Banner kind="info">সংবাদটি সম্পাদকের রিভিউতে আছে — অনুমোদনের আগ পর্যন্ত সম্পাদনা করতে পারবেন।</Banner>}
            {post?.rejected && (
              <Banner kind="error">
                এই সংবাদটি প্রত্যাখ্যাত হয়েছে{post.reject_reason ? ` — কারণ: ${post.reject_reason}` : ''}। সংশোধন করে আবার রিভিউতে পাঠান —
                কোনো পরিবর্তন না করে পুনরায় সাবমিট করা যাবে না।
              </Banner>
            )}

            <Field label="শিরোনাম" required>
              <input className={inputClass} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="সংবাদের শিরোনাম" />
            </Field>

            <Field label="বিস্তারিত" required>
              <button
                type="button"
                onClick={() => setShowEditor(true)}
                className="w-full text-left rounded-xl border border-neutral-200 bg-neutral-50 p-3.5"
              >
                {content && plainPreview(content) ? (
                  <p className="text-[14px] text-neutral-700 line-clamp-4 mb-3">{plainPreview(content, 220)}</p>
                ) : (
                  <p className="text-[14px] text-neutral-400 mb-3">এখনো কিছু লেখা হয়নি…</p>
                )}
                <span className="inline-flex items-center gap-1.5 text-[13.5px] font-semibold text-brand-600">
                  <IconEdit className="w-4 h-4" />
                  নিউজ এডিটরে যান
                </span>
              </button>
            </Field>

            <Field label="ফিচার ইমেজ">
              <input
                ref={featuredInput}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="hidden"
                onChange={(e) => {
                  const f = e.target.files?.[0]
                  if (f) onPickFeatured(f)
                  e.target.value = ''
                }}
              />
              {featuredImage ? (
                <button
                  type="button"
                  onClick={() => featuredInput.current?.click()}
                  disabled={uploadingFeatured}
                  className="relative w-full h-36 rounded-xl overflow-hidden border border-neutral-200"
                >
                  <img src={featuredImage} alt="" className="w-full h-full object-cover" />
                  {uploadingFeatured ? (
                    <span className="absolute inset-0 bg-black/45 text-white text-[13px] font-medium flex items-center justify-center">
                      {uploadLabel(featuredProgress)}
                    </span>
                  ) : (
                    <span className="absolute bottom-2 right-2 bg-black/60 text-white text-[12px] px-2 py-1 rounded-lg">
                      বদলাতে ট্যাপ করুন
                    </span>
                  )}
                </button>
              ) : (
                <button
                  type="button"
                  onClick={() => featuredInput.current?.click()}
                  disabled={uploadingFeatured}
                  className="w-full flex items-center justify-center gap-2 rounded-xl border border-dashed border-neutral-300 text-neutral-500 py-5 text-[13.5px]"
                >
                  <IconPhotoPlus className="w-5 h-5" />
                  {uploadingFeatured ? uploadLabel(featuredProgress) : 'কভার/ফিচার ইমেজ যুক্ত করুন'}
                </button>
              )}
            </Field>

            {featuredImage && (
              <Field label="ইমেজ ক্যাপশন">
                <input
                  className={inputClass}
                  value={featuredCaption}
                  onChange={(e) => setFeaturedCaption(e.target.value)}
                  onBlur={saveFeaturedCaption}
                  placeholder="ছবির ক্যাপশন (যদি থাকে)"
                />
              </Field>
            )}

            {/* Not a <Field>: Field is a <label>, and a label wrapping many
                buttons forwards a tap on empty space to the first button. */}
            <div className="mb-4">
              <p className="text-[13px] font-medium text-neutral-600 mb-1.5">বিষয় (ক্যাটেগরি)</p>
              <CategoryPicker
                categories={categories}
                value={categoryId}
                onChange={setCategoryId}
                recentKey={RECENT_CATEGORIES_KEY}
                loading={categoriesState === 'loading'}
                error={categoriesState === 'error'}
                onRetry={loadCategories}
              />
            </div>

            <p className="text-[13px] font-semibold text-neutral-700 mb-2 mt-1">এলাকা</p>
            <Field label="বিভাগ">
              <select
                className={inputClass}
                value={division}
                onChange={(e) => {
                  setDivision(e.target.value)
                  setDistrict('')
                  setUpazila('')
                }}
              >
                <option value="">নির্বাচন করুন</option>
                {areas?.divisions.map((d) => (
                  <option key={d.name} value={d.name}>
                    {d.name}
                  </option>
                ))}
              </select>
            </Field>

            <Field label="জেলা">
              <select
                className={inputClass}
                value={district}
                onChange={(e) => {
                  setDistrict(e.target.value)
                  setUpazila('')
                }}
                disabled={!division}
              >
                <option value="">নির্বাচন করুন</option>
                {districts.map((d) => {
                  const name = districtName(d)
                  return (
                    <option key={name} value={name}>
                      {name}
                    </option>
                  )
                })}
              </select>
            </Field>

            <Field label="উপজেলা">
              <select className={inputClass} value={upazila} onChange={(e) => setUpazila(e.target.value)} disabled={!district}>
                <option value="">নির্বাচন করুন</option>
                {upazilas.map((u) => (
                  <option key={u} value={u}>
                    {u}
                  </option>
                ))}
              </select>
            </Field>

            <div className="flex flex-col gap-2.5 mt-2">
              <Button onClick={saveDraft} variant="secondary" disabled={saving}>
                {saving ? 'সংরক্ষণ হচ্ছে…' : 'ড্রাফট সংরক্ষণ করুন'}
              </Button>
              {!isPending && (
                <>
                  <Button onClick={submitForReview} disabled={saving || resubmitBlocked}>
                    রিভিউতে পাঠান
                  </Button>
                  {resubmitBlocked && (
                    <p className="text-[12px] text-neutral-400 text-center">
                      প্রত্যাখ্যানের পর কিছু পরিবর্তন করুন (শিরোনাম বা বিস্তারিত), তারপর আবার পাঠাতে পারবেন।
                    </p>
                  )}
                </>
              )}
            </div>
          </>
        )}
      </div>

      {showEditor && (
        <Suspense
          fallback={
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30">
              <Spinner />
            </div>
          }
        >
          <NewsEditorOverlay
            initialContent={content}
            onClose={() => setShowEditor(false)}
            onDone={(html) => {
              setContent(html)
              setShowEditor(false)
            }}
          />
        </Suspense>
      )}
    </div>
  )
}
