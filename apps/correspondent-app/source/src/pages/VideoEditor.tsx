import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  ApiError,
  CategoriesApi,
  MediaApi,
  VideosApi,
  describeError,
  formatBytes,
  isAllowedVideoFile,
  isUploadCancelled,
  videoTooLargeMessage,
  type Category,
  type Video,
  type VideoInput,
  type VideoOrientation,
  type VideoSource,
} from '../lib/api'
import { captureFrame, readVideoMeta, youtubeIdFrom, youtubeThumb, type VideoMeta } from '../lib/video'
import { Banner, Button, CharCount, ErrorRetry, Segmented, Spinner, TopBar, bnDuration, bnNumber, inputClass } from '../components/ui'
import VideoPayment from '../components/VideoPayment'
import CategoryPicker from '../components/CategoryPicker'
import { rememberCategory } from '../lib/categoryTree'
import WriteModeSwitch from '../components/WriteModeSwitch'
import {
  IconBrandYoutube,
  IconCamera,
  IconCheck,
  IconChevronDown,
  IconExternalLink,
  IconEye,
  IconHeart,
  IconLink,
  IconMessage,
  IconPhotoPlus,
  IconPlayerPlay,
  IconRectHorizontal,
  IconRectVertical,
  IconRefresh,
  IconShare,
  IconTrash,
  IconUpload,
  IconVideo,
  IconX,
} from '../components/Icon'

const TITLE_RECOMMENDED = 70
// r31: same chip picker as the news form; videos keep their own "recent" list
const RECENT_VIDEO_CATEGORIES_KEY = 'nros_recent_video_categories'
const EXCERPT_MAX = 300

type SaveTarget = 'draft' | 'pending' | 'save'

interface UploadState {
  state: 'uploading' | 'done' | 'error'
  name: string
  loaded: number
  total: number
  percent: number
  error?: string
  detail?: string
}

interface FieldErrors {
  title?: string
  video?: string
  excerpt?: string
}

function errorText(err: unknown, fallback: string) {
  if (err instanceof ApiError) return describeError(err).message
  return fallback
}

function SectionLabel({ children, required, right }: { children: ReactNode; required?: boolean; right?: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-2 mb-1.5">
      <span className="text-[13px] font-medium text-neutral-600">
        {children} {required && <span className="text-brand-600">*</span>}
      </span>
      {right}
    </div>
  )
}

function FieldError({ children }: { children?: string }) {
  if (!children) return null
  return <p className="text-[12.5px] text-red-600 mt-1.5">{children}</p>
}

const orientationLabel: Record<VideoOrientation, string> = {
  portrait: 'খাড়া (৯:১৬)',
  landscape: 'আড়াআড়ি (১৬:৯)',
}

export default function VideoEditor() {
  const { id } = useParams()
  const navigate = useNavigate()

  const topRef = useRef<HTMLDivElement>(null)
  const videoSectionRef = useRef<HTMLDivElement>(null)
  const titleSectionRef = useRef<HTMLDivElement>(null)
  const excerptSectionRef = useRef<HTMLDivElement>(null)
  const videoInput = useRef<HTMLInputElement>(null)
  const thumbInput = useRef<HTMLInputElement>(null)
  const previewRef = useRef<HTMLVideoElement>(null)

  const [loading, setLoading] = useState(!!id)
  const [loadError, setLoadError] = useState<unknown>(null)
  const [video, setVideo] = useState<Video | null>(null)
  const [categories, setCategories] = useState<Category[]>([])
  const [categoriesState, setCategoriesState] = useState<'loading' | 'ok' | 'error'>('loading')
  const [maxBytes, setMaxBytes] = useState<number | null>(null)

  // Round 12: correspondents only UPLOAD. 'youtube' only ever comes from an
  // older video loaded from the server — shown read-only, never re-sent.
  const [source, setSource] = useState<VideoSource>('upload')
  const [title, setTitle] = useState('')
  const [excerpt, setExcerpt] = useState('')
  const [categoryId, setCategoryId] = useState(0)
  const [slug, setSlug] = useState('')
  // Round 13: no SEO fields for correspondents — `seo` is never sent, so the
  // server keeps whatever SEO title/description/keyword the desk set.
  const [slugOpen, setSlugOpen] = useState(false)

  // Uploaded-file source. attachmentId/serverVideoUrl only ever hold a video
  // the server has actually accepted; a file still uploading lives in
  // localUrl + upload until it succeeds.
  const [attachmentId, setAttachmentId] = useState<number | null>(null)
  const [serverVideoUrl, setServerVideoUrl] = useState('')
  const [localUrl, setLocalUrl] = useState<string | null>(null)
  const [upload, setUpload] = useState<UploadState | null>(null)
  const abortRef = useRef<AbortController | null>(null)
  const pickedFileRef = useRef<File | null>(null)
  const pickSeq = useRef(0)
  const beforePick = useRef<{ orientation: VideoOrientation; duration: number | null; detected: VideoMeta | null } | null>(null)

  const [youtubeUrl, setYoutubeUrl] = useState('')
  const [ytPlaying, setYtPlaying] = useState(false)

  const [orientation, setOrientation] = useState<VideoOrientation>('portrait')
  const [detected, setDetected] = useState<VideoMeta | null>(null)
  // The browser couldn't read the picked file's metadata (e.g. an HEVC .mov
  // on a browser without HEVC) — the upload still works; ask for orientation.
  const [metaFailed, setMetaFailed] = useState(false)
  const [duration, setDuration] = useState<number | null>(null)

  const [thumbId, setThumbId] = useState<number | null>(null)
  const [thumbUrl, setThumbUrl] = useState<string | null>(null)
  const [thumbBusy, setThumbBusy] = useState(false)

  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  // Shown only while some field is still marked — fixing the last one hides it.
  const [validationMsg, setValidationMsg] = useState('')
  const [saving, setSaving] = useState<SaveTarget | null>(null)

  const ytId = youtubeIdFrom(youtubeUrl)
  const uploading = upload?.state === 'uploading'
  const isLocked = video?.status === 'publish' || video?.status === 'future'
  const isPending = video?.status === 'pending'

  const hydrate = useCallback((v: Video) => {
    setVideo(v)
    setSource(v.source)
    setTitle(v.title)
    setExcerpt(v.excerpt)
    setCategoryId(v.category_id ?? 0)
    setSlug(v.slug)
    setOrientation(v.orientation)
    setDuration(v.duration)
    // The server fills `thumbnail` with the i.ytimg.com picture for a YouTube
    // video without its own thumbnail — that one isn't "set", it's a default.
    setThumbId(v.thumbnail_id)
    setThumbUrl(v.thumbnail_id ? v.thumbnail : null)
    if (v.source === 'upload') {
      setAttachmentId(v.video_attachment_id)
      setServerVideoUrl(v.video_url)
    } else {
      setYoutubeUrl(v.video_url)
    }
  }, [])

  const loadCategories = useCallback(() => {
    setCategoriesState('loading')
    CategoriesApi.list()
      .then((c) => {
        setCategories(c)
        setCategoriesState('ok')
      })
      .catch(() => setCategoriesState('error'))
  }, [])

  // Supporting data — failures just leave the hint empty (categories can retry).
  useEffect(() => {
    loadCategories()
    MediaApi.videoLimits()
      .then((l) => setMaxBytes(l.max_bytes > 0 ? l.max_bytes : null))
      .catch(() => setMaxBytes(null))
  }, [loadCategories])

  // The video being edited. Skipped when `id` only just appeared because we
  // created this very video (the URL is swapped from /new to /{id}).
  const loadedIdRef = useRef<number | null>(null)
  const load = useCallback(() => {
    if (!id) return
    setLoading(true)
    setLoadError(null)
    VideosApi.get(Number(id))
      .then((v) => {
        loadedIdRef.current = v.id
        hydrate(v)
      })
      .catch((err) => setLoadError(err ?? new Error('unknown')))
      .finally(() => setLoading(false))
  }, [id, hydrate])

  useEffect(() => {
    if (id && loadedIdRef.current !== Number(id)) load()
  }, [id, load])

  // Free the local preview's memory when it is replaced or the page closes.
  useEffect(() => {
    if (!localUrl) return
    return () => URL.revokeObjectURL(localUrl)
  }, [localUrl])

  // Leaving the page abandons an unfinished upload (nothing to attach it to).
  useEffect(() => () => abortRef.current?.abort(), [])

  useEffect(() => {
    if (!uploading) return
    const warn = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [uploading])

  const confirmLeave = () => !uploading || window.confirm('ভিডিও আপলোড এখনো চলছে। এই পেজ ছেড়ে গেলে আপলোড বাতিল হবে। তবুও যাবেন?')

  function scrollToTop() {
    topRef.current?.scrollIntoView({ block: 'start', behavior: 'smooth' })
  }

  // ---- Video file ----

  async function startUpload(file: File) {
    const ctrl = new AbortController()
    abortRef.current = ctrl
    pickedFileRef.current = file
    setUpload({ state: 'uploading', name: file.name, loaded: 0, total: file.size, percent: 0 })
    try {
      const res = await MediaApi.uploadVideo(
        file,
        (p) => {
          if (abortRef.current === ctrl) setUpload((u) => (u && u.state === 'uploading' ? { ...u, ...p } : u))
        },
        { signal: ctrl.signal },
      )
      if (abortRef.current !== ctrl) return
      setAttachmentId(res.id)
      setServerVideoUrl(res.url)
      setUpload({ state: 'done', name: file.name, loaded: file.size, total: file.size, percent: 100 })
      beforePick.current = null // this file is now the post's video
      setFieldErrors((f) => ({ ...f, video: undefined }))
    } catch (err) {
      if (abortRef.current !== ctrl || isUploadCancelled(err)) return
      const d = describeError(err)
      setUpload((u) => ({
        state: 'error',
        name: file.name,
        loaded: u?.loaded ?? 0,
        total: file.size,
        percent: u?.percent ?? 0,
        error: d.message,
        detail: d.detail,
      }))
    } finally {
      if (abortRef.current === ctrl) abortRef.current = null
    }
  }

  function onPickVideo(file: File) {
    setError('')
    setNotice('')
    if (!isAllowedVideoFile(file)) {
      setFieldErrors((f) => ({ ...f, video: 'শুধু MP4, WebM বা MOV ফরম্যাটের ভিডিও আপলোড করা যাবে।' }))
      return
    }
    if (maxBytes && file.size > maxBytes) {
      setFieldErrors((f) => ({ ...f, video: videoTooLargeMessage(file.size, maxBytes) }))
      return
    }
    setFieldErrors((f) => ({ ...f, video: undefined }))

    // Replacing a file that is still uploading: drop the old upload.
    const previous = abortRef.current
    abortRef.current = null
    previous?.abort()
    // Remember what to fall back to if this pick is cancelled.
    if (!beforePick.current) beforePick.current = { orientation, duration, detected }

    const seq = ++pickSeq.current
    const url = URL.createObjectURL(file)
    setLocalUrl(url)
    setDetected(null)
    setMetaFailed(false)
    readVideoMeta(url).then((meta) => {
      if (pickSeq.current !== seq) return
      if (!meta) {
        setMetaFailed(true)
        return
      }
      setDetected(meta)
      setOrientation(meta.orientation)
      setDuration(meta.duration)
    })
    void startUpload(file)
  }

  function cancelUpload() {
    const ctrl = abortRef.current
    abortRef.current = null
    ctrl?.abort()
    pickSeq.current++
    pickedFileRef.current = null
    setUpload(null)
    setLocalUrl(null)
    setMetaFailed(false)
    // Back to whatever video (if any) this post already had.
    if (beforePick.current) {
      setOrientation(beforePick.current.orientation)
      setDuration(beforePick.current.duration)
      setDetected(beforePick.current.detected)
      beforePick.current = null
    }
  }

  function retryUpload() {
    if (pickedFileRef.current) void startUpload(pickedFileRef.current)
  }

  // ---- Thumbnail ----

  async function uploadThumb(file: File) {
    setError('')
    setThumbBusy(true)
    try {
      const res = await MediaApi.upload(file)
      setThumbId(res.id)
      setThumbUrl(res.url)
    } catch (err) {
      setError(errorText(err, 'থাম্বনেইল আপলোড করা যায়নি।'))
      scrollToTop()
    } finally {
      setThumbBusy(false)
    }
  }

  async function thumbFromFrame() {
    const el = previewRef.current
    if (!el) return
    const file = await captureFrame(el, `thumb-${Date.now()}.jpg`)
    if (!file) {
      setError('এই মুহূর্তের ফ্রেমটি নেওয়া যায়নি — ভিডিওটি একটু চালিয়ে আবার চেষ্টা করুন।')
      scrollToTop()
      return
    }
    await uploadThumb(file)
  }

  // ---- Save ----

  function validate(target: SaveTarget) {
    const needsVideo = target === 'pending' || (target === 'save' && isPending)
    const fe: FieldErrors = {}
    if (!title.trim()) fe.title = 'ভিডিওর সংক্ষিপ্ত শিরোনাম লিখুন।'
    if (excerpt.length > EXCERPT_MAX) fe.excerpt = `সংক্ষিপ্ত বিবরণ সর্বোচ্চ ${bnNumber(EXCERPT_MAX)} অক্ষরের হতে পারে।`
    if (source === 'upload') {
      if (uploading) fe.video = 'ভিডিও আপলোড শেষ হওয়া পর্যন্ত অপেক্ষা করুন।'
      else if (needsVideo && !attachmentId)
        fe.video = upload?.state === 'error' ? 'ভিডিও আপলোড সম্পন্ন হয়নি — আবার চেষ্টা করুন।' : 'একটি ভিডিও আপলোড করুন।'
    }
    setFieldErrors(fe)
    const first = fe.video ? videoSectionRef : fe.title ? titleSectionRef : fe.excerpt ? excerptSectionRef : null
    if (first) {
      setValidationMsg(
        target === 'pending' ? 'রিভিউয়ের জন্য জমা দেওয়ার আগে চিহ্নিত ঘরগুলো ঠিক করুন।' : 'সংরক্ষণের আগে চিহ্নিত ঘরগুলো ঠিক করুন।',
      )
      first.current?.scrollIntoView({ block: 'center', behavior: 'smooth' })
      return false
    }
    return true
  }

  function buildInput(target: SaveTarget): VideoInput {
    if (categoryId) rememberCategory(RECENT_VIDEO_CATEGORIES_KEY, categoryId)
    const body: VideoInput = {
      title: title.trim(),
      excerpt: excerpt.trim(),
      orientation,
      duration: source === 'upload' ? duration : null,
      thumbnail_id: thumbId,
      category_id: categoryId || null,
    }
    if (slug.trim()) body.slug = slug.trim()
    // An existing YouTube video keeps its source untouched (CONTRACT-R12 §C):
    // neither `source` nor `video_url` is sent for it.
    if (source === 'upload') {
      body.source = 'upload'
      if (attachmentId) body.video_attachment_id = attachmentId
    }
    if (target === 'draft') body.status = 'draft'
    if (target === 'pending') body.status = 'pending'
    return body
  }

  async function save(target: SaveTarget) {
    setError('')
    setNotice('')
    if (!validate(target)) return
    setSaving(target)
    try {
      const body = buildInput(target)
      const saved = video ? await VideosApi.update(video.id, body) : await VideosApi.create(body)
      loadedIdRef.current = saved.id
      hydrate(saved)
      if (!video) navigate(`/app/video/${saved.id}`, { replace: true })
      setNotice(
        target === 'pending'
          ? 'রিভিউয়ের জন্য জমা দেওয়া হয়েছে — সম্পাদক অনুমোদন করলে প্রকাশিত হবে।'
          : target === 'draft'
            ? 'ড্রাফট সংরক্ষিত হয়েছে।'
            : 'পরিবর্তন সংরক্ষিত হয়েছে।',
      )
    } catch (err) {
      setError(errorText(err, 'সংরক্ষণ করা যায়নি।'))
    } finally {
      setSaving(null)
      scrollToTop()
    }
  }

  // ---- Render ----

  const goBack = () => {
    if (confirmLeave()) navigate('/app/news?tab=video')
  }

  if (loading) return <Spinner className="mt-10" />

  if (loadError != null) {
    return (
      <div className="flex-1 flex flex-col">
        <TopBar title="ভিডিও নিউজ" onBack={goBack} />
        <ErrorRetry error={loadError} onRetry={load} />
      </div>
    )
  }

  const displayThumb = thumbUrl ?? (source === 'youtube' && ytId ? youtubeThumb(ytId) : null)
  const playableUrl = localUrl ?? serverVideoUrl
  const frameBox = orientation === 'portrait' ? 'mx-auto w-[min(100%,210px)] aspect-[9/16]' : 'w-full aspect-video'

  const youtubePreview = (id: string) => (
    <div className={`relative rounded-xl overflow-hidden bg-black ${frameBox}`}>
      {ytPlaying ? (
        <iframe
          title="YouTube প্রিভিউ"
          src={`https://www.youtube-nocookie.com/embed/${id}?autoplay=1&playsinline=1&rel=0`}
          allow="autoplay; encrypted-media; picture-in-picture"
          allowFullScreen
          className="absolute inset-0 w-full h-full"
        />
      ) : (
        <button type="button" onClick={() => setYtPlaying(true)} className="absolute inset-0 w-full h-full" aria-label="প্রিভিউ চালান">
          <img src={youtubeThumb(id)} alt="" className="w-full h-full object-cover" />
          <span className="absolute inset-0 flex items-center justify-center bg-black/20">
            <span className="w-14 h-14 rounded-full bg-black/60 text-white flex items-center justify-center">
              <IconPlayerPlay className="w-7 h-7 ml-0.5" fill="currentColor" />
            </span>
          </span>
        </button>
      )}
    </div>
  )

  const filePreview = (url: string) => (
    <div className={`rounded-xl overflow-hidden bg-black ${frameBox}`}>
      <video
        ref={previewRef}
        key={url}
        src={url}
        poster={displayThumb ?? undefined}
        controls
        playsInline
        preload="metadata"
        className="w-full h-full object-contain"
      />
    </div>
  )

  // ----- Published / scheduled: read-only -----
  if (video && isLocked) {
    const stats = [
      { icon: IconEye, label: 'ভিউ', value: video.stats.views },
      { icon: IconHeart, label: 'লাইক', value: video.stats.likes },
      { icon: IconMessage, label: 'মন্তব্য', value: video.stats.comments },
      { icon: IconShare, label: 'শেয়ার', value: video.stats.shares },
    ]
    const categoryName = categories.find((c) => c.id === video.category_id)?.name
    return (
      <div className="flex-1 flex flex-col">
        <TopBar title="ভিডিও নিউজ" onBack={goBack} />
        <div className="px-5 pt-5 pb-10">
          <Banner kind="info">
            {video.status === 'future'
              ? 'এই ভিডিওটি অনুমোদিত হয়েছে এবং নির্ধারিত সময়ে প্রকাশিত হবে — এখন আর সম্পাদনা করা যাবে না।'
              : 'এই ভিডিওটি প্রকাশিত হয়ে গেছে — আর সম্পাদনা করা যাবে না। কোনো সংশোধন প্রয়োজন হলে সম্পাদকের সাথে যোগাযোগ করুন।'}
          </Banner>

          <div className="mb-4">
            {video.source === 'youtube' && video.youtube_id
              ? youtubePreview(video.youtube_id)
              : video.video_url
                ? filePreview(video.video_url)
                : null}
          </div>

          <h2 className="text-[16.5px] font-bold text-neutral-900 mb-1.5">{video.title}</h2>
          {video.excerpt && <p className="text-[13.5px] text-neutral-600 leading-relaxed mb-3">{video.excerpt}</p>}
          <p className="text-[12px] text-neutral-400 mb-4">
            {[categoryName, orientationLabel[video.orientation], video.duration ? bnDuration(video.duration) : '']
              .filter(Boolean)
              .join(' · ')}
          </p>

          <VideoPayment video={video} className="mb-4" />

          {video.status === 'publish' && (
            <div className="grid grid-cols-4 gap-2 mb-5">
              {stats.map(({ icon: Icon, label, value }) => (
                <div key={label} className="rounded-xl bg-neutral-50 border border-neutral-100 py-2.5 flex flex-col items-center gap-0.5">
                  <Icon className="w-4 h-4 text-neutral-400" />
                  <span className="text-[15px] font-semibold text-neutral-900 tabular-nums">{bnNumber(value)}</span>
                  <span className="text-[11px] text-neutral-400">{label}</span>
                </div>
              ))}
            </div>
          )}

          {video.status === 'publish' && video.link && (
            <a
              href={video.link}
              target="_blank"
              rel="noreferrer"
              className="flex items-center justify-center gap-1.5 rounded-xl bg-brand-600 text-white py-3 text-[15px] font-semibold"
            >
              <IconExternalLink className="w-4 h-4" /> প্রকাশিত ভিডিওটি দেখুন
            </a>
          )}
        </div>
      </div>
    )
  }

  // ----- Editable (new / draft / rejected / pending) -----
  return (
    <div className="flex-1 flex flex-col">
      <TopBar title={video ? 'ভিডিও সম্পাদনা' : 'নতুন ভিডিও নিউজ'} onBack={goBack} />
      <div ref={topRef} className="scroll-mt-16" />
      {!video && <WriteModeSwitch mode="video" confirmLeave={confirmLeave} />}

      <div className="px-5 pt-4 pb-10">
        {error && <Banner kind="error">{error}</Banner>}
        {validationMsg && Object.values(fieldErrors).some(Boolean) && <Banner kind="error">{validationMsg}</Banner>}
        {notice && <Banner kind="success">{notice}</Banner>}
        {isPending && <Banner kind="info">ভিডিওটি সম্পাদকের রিভিউতে আছে — অনুমোদনের আগ পর্যন্ত সম্পাদনা করতে পারবেন।</Banner>}
        {video?.rejected && (
          <Banner kind="error">
            <span className="font-semibold">এই ভিডিওটি প্রত্যাখ্যাত হয়েছে।</span>
            {video.reject_reason ? ` কারণ: ${video.reject_reason}` : ''} প্রয়োজনীয় সংশোধন করে আবার রিভিউয়ের জন্য জমা দিন।
          </Banner>
        )}

        {video && <VideoPayment video={video} className="mb-4" />}

        {/* ---------- Video ---------- */}
        <div ref={videoSectionRef} className="mb-5 scroll-mt-20">
          <SectionLabel required>ভিডিও</SectionLabel>

          <input
            ref={videoInput}
            type="file"
            accept="video/mp4,video/webm,video/quicktime,.mp4,.m4v,.webm,.mov"
            className="hidden"
            data-testid="video-file"
            onChange={(e) => {
              const f = e.target.files?.[0]
              if (f) onPickVideo(f)
              e.target.value = ''
            }}
          />

          {source === 'upload' ? (
            <>
              {video?.source === 'youtube' && (
                <button
                  type="button"
                  onClick={() => {
                    abortRef.current?.abort()
                    setSource('youtube')
                    setFieldErrors((f) => ({ ...f, video: undefined }))
                  }}
                  className="mb-2 min-h-[44px] flex items-center gap-1.5 text-[13px] font-semibold text-neutral-600"
                >
                  <IconBrandYoutube className="w-4 h-4 text-red-600" /> আগের YouTube ভিডিওটিই রাখুন
                </button>
              )}
              {playableUrl ? (
                <div className="mb-3">{filePreview(playableUrl)}</div>
              ) : (
                <button
                  type="button"
                  onClick={() => videoInput.current?.click()}
                  className={`w-full flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed py-8 px-4 text-center ${
                    fieldErrors.video ? 'border-red-300 bg-red-50/40' : 'border-neutral-200 bg-neutral-50'
                  }`}
                >
                  <span className="w-12 h-12 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center">
                    <IconVideo className="w-6 h-6" />
                  </span>
                  <span className="text-[14.5px] font-semibold text-neutral-800">ভিডিও নির্বাচন করুন</span>
                  <span className="text-[12px] text-neutral-400">
                    MP4, WebM বা MOV{maxBytes ? ` · সর্বোচ্চ ${formatBytes(maxBytes)}` : ''}
                  </span>
                </button>
              )}

              {upload?.state === 'uploading' && (
                <div className="rounded-xl border border-neutral-200 p-3" data-testid="upload-progress">
                  <div className="flex items-center gap-2.5">
                    <span className="w-9 h-9 shrink-0 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center">
                      <IconUpload className="w-[18px] h-[18px]" />
                    </span>
                    <div className="flex-1 min-w-0">
                      <p className="text-[13px] font-medium text-neutral-800 truncate">{upload.name}</p>
                      <p className="text-[11.5px] text-neutral-400 tabular-nums">
                        {formatBytes(upload.loaded)} / {formatBytes(upload.total)}
                      </p>
                    </div>
                    <span className="text-[15px] font-bold text-brand-600 tabular-nums">{bnNumber(upload.percent)}%</span>
                    <button
                      type="button"
                      onClick={cancelUpload}
                      className="ml-1 w-8 h-8 shrink-0 rounded-full bg-neutral-100 text-neutral-600 flex items-center justify-center active:scale-95"
                      aria-label="আপলোড বাতিল করুন"
                    >
                      <IconX className="w-4 h-4" />
                    </button>
                  </div>
                  <div
                    className="mt-2.5 h-2 rounded-full bg-neutral-100 overflow-hidden"
                    role="progressbar"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={upload.percent}
                  >
                    <div className="h-full rounded-full bg-brand-600 transition-[width] duration-300" style={{ width: `${upload.percent}%` }} />
                  </div>
                  <p className="text-[11.5px] text-neutral-500 mt-2">
                    আপলোড চলছে — এই পেজ থেকে বের হবেন না। এর মধ্যে নিচের তথ্যগুলো পূরণ করে নিতে পারেন।
                  </p>
                </div>
              )}

              {upload?.state === 'error' && (
                <div className="rounded-xl border border-red-100 bg-red-50 p-3">
                  <p className="text-[13px] font-semibold text-red-700 mb-0.5">ভিডিও আপলোড হয়নি</p>
                  <p className="text-[12.5px] text-red-700">{upload.error}</p>
                  {upload.detail && (
                    <p dir="ltr" className="text-[10.5px] text-red-400 font-mono break-all mt-1">
                      {upload.detail}
                    </p>
                  )}
                  <div className="flex gap-2 mt-2.5">
                    <button
                      type="button"
                      onClick={retryUpload}
                      className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white text-red-700 text-[12.5px] font-semibold border border-red-200"
                    >
                      <IconRefresh className="w-3.5 h-3.5" /> আবার চেষ্টা করুন
                    </button>
                    <button type="button" onClick={cancelUpload} className="px-3 py-1.5 rounded-lg text-red-700 text-[12.5px] font-medium">
                      বাতিল
                    </button>
                  </div>
                </div>
              )}

              {playableUrl && !uploading && upload?.state !== 'error' && (
                <div className="flex items-center justify-between gap-2">
                  {upload?.state === 'done' ? (
                    <span className="flex items-center gap-1.5 text-[12.5px] font-medium text-emerald-600 min-w-0">
                      <IconCheck className="w-4 h-4 shrink-0" />
                      <span className="truncate">আপলোড সম্পন্ন · {formatBytes(upload.total)}</span>
                    </span>
                  ) : (
                    <span className="flex items-center gap-1.5 text-[12.5px] text-neutral-500">
                      <IconVideo className="w-4 h-4" /> আপলোড করা ভিডিও
                    </span>
                  )}
                  <button
                    type="button"
                    onClick={() => videoInput.current?.click()}
                    className="text-[12.5px] font-semibold text-brand-600 shrink-0"
                  >
                    অন্য ভিডিও নির্বাচন
                  </button>
                </div>
              )}
            </>
          ) : (
            <div data-testid="youtube-readonly">
              {ytId ? youtubePreview(ytId) : null}
              <p className="flex items-start gap-1.5 text-[12.5px] text-neutral-500 mt-2">
                <IconBrandYoutube className="w-4 h-4 shrink-0 text-red-600 mt-0.5" />
                <span>
                  এটি আগে জমা দেওয়া একটি YouTube ভিডিও — লিংকটি এখান থেকে বদলানো যাবে না। নতুন ভিডিও নিউজ শুধু আপলোড করে জমা দেওয়া যায়।
                </span>
              </p>
              <button
                type="button"
                onClick={() => {
                  setSource('upload')
                  setFieldErrors((f) => ({ ...f, video: undefined }))
                }}
                className="mt-2 min-h-[44px] flex items-center gap-1.5 text-[13px] font-semibold text-brand-600"
              >
                <IconUpload className="w-4 h-4" /> এর বদলে ভিডিও আপলোড করুন
              </button>
            </div>
          )}
          <FieldError>{fieldErrors.video}</FieldError>
        </div>

        {/* ---------- Orientation ---------- */}
        <div className="mb-5">
          <SectionLabel>ভিডিওর ধরন</SectionLabel>
          <Segmented
            ariaLabel="ভিডিওর ধরন"
            value={orientation}
            onChange={(v) => {
              setOrientation(v)
              setDetected(null)
              setMetaFailed(false)
            }}
            options={[
              { value: 'portrait', label: orientationLabel.portrait, icon: IconRectVertical },
              { value: 'landscape', label: orientationLabel.landscape, icon: IconRectHorizontal },
            ]}
          />
          <p className="text-[11.5px] text-neutral-400 mt-1.5" data-testid="orientation-hint">
            {detected && source === 'upload'
              ? `ভিডিও থেকে স্বয়ংক্রিয়ভাবে শনাক্ত: ${detected.orientation === 'portrait' ? 'খাড়া' : 'আড়াআড়ি'} · ${bnNumber(detected.width)}×${bnNumber(detected.height)}${detected.duration ? ` · ${bnDuration(detected.duration)}` : ''}`
              : source === 'upload' && metaFailed
                ? 'এই ব্রাউজারে ভিডিওর মাপ পড়া যায়নি — ভিডিওটি খাড়া না আড়াআড়ি, নিজে বেছে দিন।'
                : source === 'upload' && localUrl
                  ? 'ভিডিওর মাপ দেখা হচ্ছে…'
                  : source === 'upload' && serverVideoUrl
                    ? 'অন্য ভিডিও নির্বাচন করলে আবার স্বয়ংক্রিয়ভাবে শনাক্ত হবে।'
                    : source === 'upload'
                      ? 'ভিডিও নির্বাচন করলে স্বয়ংক্রিয়ভাবে শনাক্ত হবে।'
                : 'Shorts লিংক হলে খাড়া, নইলে আড়াআড়ি ধরা হয় — প্রয়োজনে বদলে দিন।'}
          </p>
        </div>

        {/* ---------- Title ---------- */}
        <div ref={titleSectionRef} className="mb-4 scroll-mt-20">
          <SectionLabel required right={<CharCount length={title.length} recommended={TITLE_RECOMMENDED} />}>
            সংক্ষিপ্ত শিরোনাম
          </SectionLabel>
          <input
            className={`${inputClass} ${fieldErrors.title ? 'border-red-300' : ''}`}
            value={title}
            onChange={(e) => {
              setTitle(e.target.value)
              if (e.target.value.trim()) setFieldErrors((f) => ({ ...f, title: undefined }))
            }}
            placeholder="ভিডিওর শিরোনাম"
            aria-label="সংক্ষিপ্ত শিরোনাম"
          />
          {fieldErrors.title ? (
            <FieldError>{fieldErrors.title}</FieldError>
          ) : (
            title.length > TITLE_RECOMMENDED && (
              <p className="text-[11.5px] text-amber-600 mt-1">
                {bnNumber(TITLE_RECOMMENDED)} অক্ষরের মধ্যে রাখলে ভিডিওর উপর পুরো শিরোনাম দেখা যাবে।
              </p>
            )
          )}
        </div>

        {/* ---------- Excerpt ---------- */}
        <div ref={excerptSectionRef} className="mb-4 scroll-mt-20">
          <SectionLabel right={<CharCount length={excerpt.length} max={EXCERPT_MAX} />}>সংক্ষিপ্ত বিবরণ</SectionLabel>
          <textarea
            className={`${inputClass} ${fieldErrors.excerpt ? 'border-red-300' : ''}`}
            rows={3}
            maxLength={EXCERPT_MAX}
            value={excerpt}
            onChange={(e) => setExcerpt(e.target.value)}
            placeholder="ভিডিওতে কী দেখা যাচ্ছে — দুই-এক লাইনে"
            aria-label="সংক্ষিপ্ত বিবরণ"
          />
          <FieldError>{fieldErrors.excerpt}</FieldError>
        </div>

        {/* ---------- Thumbnail ---------- */}
        <div className="mb-4">
          <SectionLabel>থাম্বনেইল</SectionLabel>
          <input
            ref={thumbInput}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            data-testid="thumb-file"
            onChange={(e) => {
              const f = e.target.files?.[0]
              if (f) void uploadThumb(f)
              e.target.value = ''
            }}
          />
          <div className="flex gap-3 items-start">
            <button
              type="button"
              onClick={() => thumbInput.current?.click()}
              disabled={thumbBusy}
              className={`relative shrink-0 rounded-xl overflow-hidden border border-neutral-200 bg-neutral-50 ${
                orientation === 'portrait' ? 'w-[88px] aspect-[9/16]' : 'w-[150px] aspect-video'
              }`}
              aria-label="থাম্বনেইল বদলান"
            >
              {displayThumb ? (
                <img src={displayThumb} alt="" className="w-full h-full object-cover" />
              ) : (
                <span className="absolute inset-0 flex items-center justify-center text-neutral-300">
                  <IconPhotoPlus className="w-7 h-7" />
                </span>
              )}
              {thumbBusy && (
                <span className="absolute inset-0 bg-white/70 flex items-center justify-center">
                  <span className="h-5 w-5 rounded-full border-2 border-brand-600 border-t-transparent animate-spin" />
                </span>
              )}
            </button>
            <div className="flex-1 min-w-0 flex flex-col gap-1.5 items-start">
              <button
                type="button"
                onClick={() => thumbInput.current?.click()}
                disabled={thumbBusy}
                className="flex items-center gap-1.5 text-[13px] font-semibold text-brand-600 disabled:opacity-50"
              >
                <IconPhotoPlus className="w-4 h-4" /> {thumbId ? 'ছবি বদলান' : 'ছবি আপলোড করুন'}
              </button>
              {source === 'upload' && localUrl && !metaFailed && (
                <button
                  type="button"
                  onClick={thumbFromFrame}
                  disabled={thumbBusy}
                  className="flex items-center gap-1.5 text-[13px] font-semibold text-brand-600 disabled:opacity-50"
                >
                  <IconCamera className="w-4 h-4" /> ভিডিওর এই মুহূর্ত থেকে নিন
                </button>
              )}
              {thumbId && (
                <button
                  type="button"
                  onClick={() => {
                    setThumbId(null)
                    setThumbUrl(null)
                  }}
                  className="flex items-center gap-1.5 text-[13px] font-medium text-neutral-500"
                >
                  <IconTrash className="w-4 h-4" /> সরিয়ে দিন
                </button>
              )}
              <p className="text-[11.5px] text-neutral-400 leading-snug">
                {!thumbId && source === 'youtube' && ytId
                  ? 'YouTube থেকে স্বয়ংক্রিয় থাম্বনেইল দেখানো হচ্ছে — চাইলে নিজের ছবি দিন।'
                  : source === 'upload' && localUrl && !metaFailed && !thumbId
                    ? 'প্রিভিউতে পছন্দের মুহূর্তে থামিয়ে "এই মুহূর্ত থেকে নিন" চাপুন।'
                    : 'JPG, PNG বা WebP — ভিডিওর অনুপাতে হলে ভালো দেখায়।'}
              </p>
            </div>
          </div>
        </div>

        {/* ---------- Category ---------- */}
        <div className="mb-4">
          <SectionLabel>বিষয় (ক্যাটেগরি)</SectionLabel>
          <CategoryPicker
            categories={categories}
            value={categoryId}
            onChange={setCategoryId}
            recentKey={RECENT_VIDEO_CATEGORIES_KEY}
            loading={categoriesState === 'loading'}
            error={categoriesState === 'error'}
            onRetry={loadCategories}
          />
        </div>

        {/* ---------- লিংক (স্লাগ) — round 13: the SEO fields are gone ---------- */}
        <div className="mb-5 rounded-xl border border-neutral-200">
          <button
            type="button"
            onClick={() => setSlugOpen((o) => !o)}
            aria-expanded={slugOpen}
            className="w-full flex items-center gap-2 px-3.5 py-3 text-left"
          >
            <IconLink className="w-4 h-4 text-neutral-500" />
            <span className="flex-1">
              <span className="block text-[13.5px] font-semibold text-neutral-800">লিংক (স্লাগ)</span>
              <span className="block text-[11.5px] text-neutral-400">ভিডিওর ওয়েব ঠিকানা — ঐচ্ছিক</span>
            </span>
            <IconChevronDown className={`w-4 h-4 text-neutral-400 transition-transform ${slugOpen ? 'rotate-180' : ''}`} />
          </button>
          {slugOpen && (
            <div className="px-3.5 pb-3.5 pt-1 border-t border-neutral-100">
              <div className="mt-3">
                <SectionLabel>স্লাগ (URL)</SectionLabel>
                <input
                  className={inputClass}
                  dir="ltr"
                  value={slug}
                  onChange={(e) => setSlug(e.target.value.replace(/\s+/g, '-'))}
                  placeholder="gazipur-flood-video"
                  aria-label="স্লাগ"
                />
                <p className="text-[11.5px] text-neutral-400 mt-1">
                  ইংরেজি ছোট হাতের অক্ষর ও হাইফেন দিয়ে লিখুন। ফাঁকা রাখলে শিরোনাম থেকে স্বয়ংক্রিয়ভাবে তৈরি হবে।
                </p>
              </div>
            </div>
          )}
        </div>

        {/* ---------- Actions ---------- */}
        <div className="flex flex-col gap-2.5">
          {isPending ? (
            <Button onClick={() => save('save')} disabled={!!saving}>
              {saving ? 'সংরক্ষণ হচ্ছে…' : 'পরিবর্তন সংরক্ষণ করুন'}
            </Button>
          ) : (
            <>
              <Button onClick={() => save('pending')} disabled={!!saving}>
                {saving === 'pending' ? 'জমা দেওয়া হচ্ছে…' : 'রিভিউয়ের জন্য জমা দিন'}
              </Button>
              <Button onClick={() => save('draft')} variant="secondary" disabled={!!saving}>
                {saving === 'draft' ? 'সংরক্ষণ হচ্ছে…' : 'ড্রাফট সেভ করুন'}
              </Button>
            </>
          )}
          {uploading && source === 'upload' && (
            <p className="text-[12px] text-neutral-400 text-center">আপলোড শেষ হলে জমা দিতে পারবেন।</p>
          )}
        </div>
      </div>
    </div>
  )
}
