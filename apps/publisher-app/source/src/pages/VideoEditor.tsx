/**
 * Round 10 — create / edit a video news item (meeha_video).
 * Routes: /app/videos/new and /app/videos/:id.
 *
 * Source is either an uploaded file (POST /media/video with real progress
 * and cancel; orientation + duration read from the file in the browser) or
 * a YouTube link. Date/author/delete follow the same profile.can.* gates as
 * articles.
 */
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import {
  AdminVideosApi,
  CategoriesApi,
  MediaApi,
  describeError,
  isAbortError,
  type Category,
  type Video,
  type VideoInput,
  type VideoOrientation,
  type VideoSource,
} from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { bnDuration, bnFileSize, decodeSlug, initialDateInput, inputToWpDate, usePostPermissions } from '../lib/postMeta'
import {
  VIDEO_ACCEPT,
  ALLOWED_VIDEO_TYPES,
  captureVideoFrame,
  isAllowedVideo,
  isYouTubeShorts,
  parseYouTubeId,
  readVideoMetadata,
  youTubeThumb,
} from '../lib/video'
import { Banner, Button, Card, PageHeader, Spinner, bnNumber, inputClass } from '../components/ui'
import {
  IconBrandYoutube,
  IconExternalLink,
  IconFrame,
  IconLibraryPhoto,
  IconPhotoPlus,
  IconPlayerPlay,
  IconRectangleHorizontal,
  IconRectangleVertical,
  IconUpload,
  IconWand,
  IconX,
} from '../components/Icon'
import MediaGallery from '../components/MediaGallery'
import { DeleteConfirmModal, PublishInfoCard } from '../components/PostManagement'
import type { AuthorValue } from '../components/AuthorPicker'
import { CharCount, RejectVideoModal, VideoStatusBadge } from '../components/VideoBits'
import PaymentPanel from '../components/PaymentPanel'

const TITLE_MAX = 100
const EXCERPT_MAX = 300
const SEO_TITLE_MAX = 60
const META_DESC_MAX = 160

type UploadState =
  | { status: 'idle' }
  | { status: 'uploading'; percent: number; loaded: number; total: number }
  | { status: 'error'; message: string }

type FieldErrors = Partial<Record<'title' | 'excerpt' | 'video', string>>

function FieldLabel({ htmlFor, label, required, right }: { htmlFor: string; label: string; required?: boolean; right?: ReactNode }) {
  return (
    <div className="flex items-end justify-between gap-2 mb-1.5">
      <label htmlFor={htmlFor} className="text-[12.5px] font-medium text-neutral-600">
        {label} {required && <span className="text-brand-600">*</span>}
      </label>
      {right}
    </div>
  )
}

function FieldError({ children }: { children?: string }) {
  return children ? <p className="text-[12px] text-red-600 mt-1.5">{children}</p> : null
}

export default function VideoEditor() {
  const { id } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const { profile, isPublisher } = useAuth()
  const perms = usePostPermissions()

  const fileInput = useRef<HTMLInputElement>(null)
  const thumbInput = useRef<HTMLInputElement>(null)
  const previewVideo = useRef<HTMLVideoElement>(null)
  const abortRef = useRef<AbortController | null>(null)
  const pendingFile = useRef<File | null>(null)
  const localUrlRef = useRef<string | null>(null)
  const justCreatedId = useRef<number | null>(null)
  const titleRef = useRef<HTMLInputElement>(null)
  const sourceRef = useRef<HTMLDivElement>(null)

  const [categories, setCategories] = useState<Category[]>([])
  const [maxBytes, setMaxBytes] = useState<number | null>(null)
  const [allowedMimes, setAllowedMimes] = useState<string[]>(ALLOWED_VIDEO_TYPES)
  const [loading, setLoading] = useState(!!id)
  const [loadError, setLoadError] = useState('')
  const [video, setVideo] = useState<Video | null>(null)

  const [title, setTitle] = useState('')
  const [excerpt, setExcerpt] = useState('')
  const [categoryId, setCategoryId] = useState(0)
  const [orientation, setOrientation] = useState<VideoOrientation>('portrait')
  const [orientationAuto, setOrientationAuto] = useState(false)
  const [orientationTouched, setOrientationTouched] = useState(false)
  const [duration, setDuration] = useState<number | null>(null)

  const [source, setSource] = useState<VideoSource>('upload')
  const [attachmentId, setAttachmentId] = useState<number | null>(null)
  const [videoUrl, setVideoUrl] = useState('')
  const [localUrl, setLocalUrl] = useState<string | null>(null)
  const [fileInfo, setFileInfo] = useState<{ name: string; size: number } | null>(null)
  const [upload, setUpload] = useState<UploadState>({ status: 'idle' })
  const [dragOver, setDragOver] = useState(false)
  // The browser couldn't decode the picked file (e.g. an iPhone HEVC .mov on
  // desktop Chrome): it still uploads fine, but there's no preview/metadata.
  const [metaFailed, setMetaFailed] = useState(false)
  const [youtubeUrl, setYoutubeUrl] = useState('')
  const [ytPlaying, setYtPlaying] = useState(false)

  const [thumbnailId, setThumbnailId] = useState<number | null>(null)
  const [thumbnailUrl, setThumbnailUrl] = useState<string | null>(null)
  const [thumbBusy, setThumbBusy] = useState(false)
  const [galleryOpen, setGalleryOpen] = useState(false)

  const [slug, setSlug] = useState('')
  const [seoTitle, setSeoTitle] = useState('')
  const [seoDescription, setSeoDescription] = useState('')
  const [focusKeyword, setFocusKeyword] = useState('')

  const [dateInput, setDateInput] = useState('')
  const [origDateInput, setOrigDateInput] = useState('')
  const [author, setAuthor] = useState<AuthorValue | null>(null)
  const [origAuthorId, setOrigAuthorId] = useState<number | null>(null)

  const [saving, setSaving] = useState<null | 'draft' | 'pending' | 'publish' | 'meta'>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  // Round 21 — video editor: opening it (may save first) / a new file replaces an edit.
  const [studioBusy, setStudioBusy] = useState(false)
  const [clearEdit, setClearEdit] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [rejectOpen, setRejectOpen] = useState(false)

  const youtubeId = parseYouTubeId(youtubeUrl)
  const uploading = upload.status === 'uploading'

  function setLocalPreview(url: string | null) {
    if (localUrlRef.current) URL.revokeObjectURL(localUrlRef.current)
    localUrlRef.current = url
    setLocalUrl(url)
  }

  // Abort an in-flight upload and free the blob preview when leaving.
  useEffect(
    () => () => {
      abortRef.current?.abort()
      if (localUrlRef.current) URL.revokeObjectURL(localUrlRef.current)
    },
    [],
  )

  useEffect(() => {
    CategoriesApi.list().then(setCategories).catch(() => setCategories([]))
    MediaApi.videoLimits()
      .then((r) => {
        setMaxBytes(r.max_bytes > 0 ? r.max_bytes : null)
        if (r.allowed_mimes?.length) setAllowedMimes(r.allowed_mimes)
      })
      .catch(() => setMaxBytes(null))
  }, [])

  function populate(v: Video | null) {
    setVideo(v)
    setTitle(v?.title ?? '')
    setExcerpt(v?.excerpt ?? '')
    setCategoryId(v?.category_id ?? 0)
    setOrientation(v?.orientation ?? 'portrait')
    setOrientationAuto(false)
    setOrientationTouched(!!v)
    setDuration(v?.duration ?? null)
    setSource(v?.source ?? 'upload')
    setAttachmentId(v?.source === 'upload' ? v.video_attachment_id : null)
    setVideoUrl(v?.source === 'upload' ? v.video_url : '')
    setYoutubeUrl(v?.source === 'youtube' ? v.video_url : '')
    setYtPlaying(false)
    setFileInfo(null)
    setLocalPreview(null)
    setMetaFailed(false)
    setUpload({ status: 'idle' })
    setThumbnailId(v?.thumbnail_id ?? null)
    setThumbnailUrl(v?.thumbnail ?? null)
    setSlug(decodeSlug(v?.slug))
    setSeoTitle(v?.seo.title ?? '')
    setSeoDescription(v?.seo.description ?? '')
    setFocusKeyword(v?.seo.focus_keyword ?? '')
    setClearEdit(false)
    syncPublishInfo(v)
    setFieldErrors({})
  }

  function syncPublishInfo(v: Video | null) {
    // Round 13: empty for an unpublished video with no chosen date (see initialDateInput).
    const d = initialDateInput(v)
    setDateInput(d)
    setOrigDateInput(d)
    const a: AuthorValue | null = v
      ? { id: v.author.id, name: v.author.name, avatar: v.author.avatar }
      : profile
        ? { id: profile.id, name: profile.name, avatar: profile.avatar, role: profile.role }
        : null
    setAuthor(a)
    setOrigAuthorId(a?.id ?? null)
  }

  useEffect(() => {
    if (!id) {
      // /app/videos/new (also when coming here from an existing video).
      const inflight = abortRef.current
      abortRef.current = null
      inflight?.abort()
      populate(null)
      setError('')
      setNotice('')
      setLoading(false)
      return
    }
    // Just created here: the form already holds everything — no reload.
    if (justCreatedId.current === Number(id)) {
      justCreatedId.current = null
      return
    }
    setLoading(true)
    setLoadError('')
    AdminVideosApi.get(Number(id))
      .then((v) => {
        populate(v)
        // A message handed over by the video editor ("এডিট করা ভিডিও বসানো হয়েছে").
        const handed = (location.state as { notice?: string } | null)?.notice
        if (handed) {
          setNotice(handed)
          navigate(location.pathname, { replace: true, state: null })
        }
      })
      .catch((err) => setLoadError(describeError(err).message))
      .finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  // ---- video source ----

  async function startUpload(file: File) {
    setFieldErrors((f) => ({ ...f, video: undefined }))
    setError('')
    pendingFile.current = file
    if (!isAllowedVideo(file, allowedMimes)) {
      setUpload({ status: 'error', message: 'শুধু MP4, WebM বা MOV ভিডিও আপলোড করা যাবে।' })
      return
    }
    if (maxBytes && file.size > maxBytes) {
      setUpload({
        status: 'error',
        message: `ফাইলটি অনেক বড় (${bnFileSize(file.size)}) — সর্বোচ্চ ${bnFileSize(maxBytes)} পর্যন্ত আপলোড করা যাবে।`,
      })
      return
    }

    abortRef.current?.abort()
    const ctrl = new AbortController()
    abortRef.current = ctrl
    pendingFile.current = file

    const previous = { orientation, orientationAuto, duration }
    const url = URL.createObjectURL(file)
    setLocalPreview(url)
    setFileInfo({ name: file.name, size: file.size })
    setUpload({ status: 'uploading', percent: 0, loaded: 0, total: file.size })

    setMetaFailed(false)
    readVideoMetadata(url).then((meta) => {
      if (ctrl.signal.aborted || localUrlRef.current !== url) return
      setMetaFailed(!meta)
      if (!meta) return
      setOrientation(meta.orientation)
      setOrientationAuto(true)
      setDuration(meta.duration)
    })

    try {
      const res = await MediaApi.uploadVideo(
        file,
        (p) => {
          if (!ctrl.signal.aborted) setUpload({ status: 'uploading', percent: p.percent, loaded: p.loaded, total: p.total || file.size })
        },
        ctrl.signal,
      )
      if (ctrl.signal.aborted) return
      setAttachmentId(res.id)
      setVideoUrl(res.url)
      setClearEdit(true)
      setUpload({ status: 'idle' })
      pendingFile.current = null
    } catch (err) {
      // Superseded by a newer pick, or the form was reset: not ours to undo.
      if (abortRef.current !== ctrl) return
      // Cancelled or failed: fall back to whatever video was there before.
      setLocalPreview(null)
      setFileInfo(null)
      setMetaFailed(false)
      setOrientation(previous.orientation)
      setOrientationAuto(previous.orientationAuto)
      setDuration(previous.duration)
      setUpload(isAbortError(err) ? { status: 'idle' } : { status: 'error', message: describeError(err).message })
    } finally {
      if (abortRef.current === ctrl) abortRef.current = null
    }
  }

  function cancelUpload() {
    abortRef.current?.abort()
  }

  function onYoutubeChange(value: string) {
    setYoutubeUrl(value)
    setYtPlaying(false)
    setFieldErrors((f) => ({ ...f, video: undefined }))
    if (!orientationTouched && parseYouTubeId(value)) {
      setOrientation(isYouTubeShorts(value) ? 'portrait' : 'landscape')
      setOrientationAuto(true)
    }
  }

  // ---- thumbnail ----

  async function uploadThumb(file: File) {
    setThumbBusy(true)
    setError('')
    try {
      const res = await MediaApi.upload(file)
      setThumbnailId(res.id)
      setThumbnailUrl(res.url)
    } catch (err) {
      setError(describeError(err).message)
    } finally {
      setThumbBusy(false)
    }
  }

  async function thumbFromFrame() {
    const el = previewVideo.current
    if (!el) return
    const file = await captureVideoFrame(el, `${(title || 'video').slice(0, 30)}-thumbnail.jpg`)
    if (!file) {
      setError('এই মুহূর্তের ফ্রেম নেওয়া যায়নি — ভিডিওটি একটু চালিয়ে পছন্দের জায়গায় থামিয়ে আবার চেষ্টা করুন।')
      return
    }
    await uploadThumb(file)
  }

  // ---- save ----

  function publishInfoPatch(): Pick<VideoInput, 'date' | 'author_id'> {
    const patch: Pick<VideoInput, 'date' | 'author_id'> = {}
    if (perms.changeDate && dateInput && dateInput !== origDateInput) patch.date = inputToWpDate(dateInput)
    if (perms.changeAuthor && author && author.id !== origAuthorId) patch.author_id = author.id
    return patch
  }
  const publishInfoDirty = Object.keys(publishInfoPatch()).length > 0

  function validate(): boolean {
    const errs: FieldErrors = {}
    if (!title.trim()) errs.title = 'ভিডিওর শিরোনাম দিন।'
    else if ([...title.trim()].length > TITLE_MAX) errs.title = `শিরোনাম ${bnNumber(TITLE_MAX)} অক্ষরের মধ্যে রাখুন।`
    if ([...excerpt.trim()].length > EXCERPT_MAX) errs.excerpt = `সংক্ষিপ্ত বিবরণ ${bnNumber(EXCERPT_MAX)} অক্ষরের মধ্যে রাখুন।`
    if (uploading) errs.video = 'ভিডিও আপলোড শেষ হওয়া পর্যন্ত অপেক্ষা করুন।'
    else if (source === 'upload' && !attachmentId) errs.video = 'একটি ভিডিও ফাইল আপলোড করুন।'
    else if (source === 'youtube' && !youtubeId) errs.video = 'সঠিক একটি YouTube লিংক দিন।'
    setFieldErrors(errs)
    if (errs.video) sourceRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    else if (errs.title) titleRef.current?.focus()
    return Object.keys(errs).length === 0
  }

  function body(status: 'draft' | 'pending' | 'publish'): VideoInput {
    return {
      title: title.trim(),
      excerpt: excerpt.trim(),
      ...(slug.trim() ? { slug: slug.trim() } : {}),
      source,
      ...(source === 'upload' ? { video_attachment_id: attachmentId ?? undefined } : { video_url: youtubeUrl.trim() }),
      thumbnail_id: thumbnailId,
      category_id: categoryId || null,
      orientation,
      duration: source === 'upload' ? duration : null,
      seo: { title: seoTitle.trim(), description: seoDescription.trim(), focus_keyword: focusKeyword.trim() },
      status,
      ...publishInfoPatch(),
      // A different file (or YouTube) replaces the edited one: forget the edit record.
      ...(video?.edit && (clearEdit || source !== 'upload') ? { edit: null } : {}),
    }
  }

  // ---- Round 21: video editor (ফ্রেম, লোগো, ইন্ট্রো, বিজ্ঞাপন…) ----

  const fileIsSaved = !!video && video.source === 'upload' && video.video_attachment_id === attachmentId

  async function openStudio() {
    if (source !== 'upload' || !attachmentId || uploading) return
    setError('')
    setNotice('')
    if (fileIsSaved && video) {
      navigate(`/app/videos/${video.id}/edit-video`)
      return
    }
    // The editor works on the saved post, so a new file is saved first (as a draft for a new video).
    if (!validate()) {
      setError('এডিটরে যাওয়ার আগে শিরোনাম দিন — ভিডিওটি ড্রাফট হিসেবে সংরক্ষণ হবে।')
      return
    }
    setStudioBusy(true)
    try {
      const saved = video
        ? await AdminVideosApi.update(video.id, {
            source: 'upload',
            video_attachment_id: attachmentId,
            duration,
            orientation,
            ...(video.edit ? { edit: null } : {}),
          })
        : await AdminVideosApi.create(body('draft'))
      navigate(`/app/videos/${saved.id}/edit-video`)
    } catch (err) {
      setError(describeError(err).message)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      setStudioBusy(false)
    }
  }

  async function revertEdit() {
    const edit = video?.edit
    if (!video || !edit) return
    if (!window.confirm('এডিট করা ভিডিও সরিয়ে মূল ভিডিওটি আবার পোস্টে বসাবেন? এডিট করা ফাইলটি মিডিয়া লাইব্রেরিতে থেকে যাবে।')) return
    setError('')
    setNotice('')
    setSaving('meta')
    try {
      const meta = edit.original_url ? await readVideoMetadata(edit.original_url) : null
      const saved = await AdminVideosApi.update(video.id, {
        source: 'upload',
        video_attachment_id: edit.original_attachment_id,
        ...(meta ? { duration: meta.duration, orientation: meta.orientation } : {}),
        edit: null,
      })
      populate(saved)
      setNotice('মূল ভিডিওটি আবার পোস্টে বসানো হয়েছে।')
    } catch (err) {
      setError(describeError(err).message)
    } finally {
      setSaving(null)
    }
  }

  async function save(status: 'draft' | 'pending' | 'publish') {
    setError('')
    setNotice('')
    if (!validate()) {
      setError('কিছু তথ্য বাকি বা ভুল আছে — চিহ্নিত ঘরগুলো দেখুন।')
      return
    }
    setSaving(status)
    try {
      let saved: Video
      if (video) {
        saved = await AdminVideosApi.update(video.id, body(status))
      } else {
        saved = await AdminVideosApi.create(body(status))
        justCreatedId.current = saved.id
        navigate(`/app/videos/${saved.id}`, { replace: true })
      }
      setVideo(saved)
      setSlug(decodeSlug(saved.slug))
      setThumbnailId(saved.thumbnail_id)
      setThumbnailUrl(saved.thumbnail)
      syncPublishInfo(saved)
      setNotice(
        saved.status === 'future'
          ? 'সংরক্ষিত হয়েছে — ভিডিওটি নির্ধারিত সময়ে প্রকাশ হবে।'
          : saved.status === 'publish'
            ? video?.status === 'publish'
              ? 'ভিডিও আপডেট হয়েছে।'
              : 'ভিডিও প্রকাশিত হয়েছে।'
            : saved.status === 'pending'
              ? 'সংরক্ষিত হয়েছে — রিভিউয়ের অপেক্ষায় আছে।'
              : 'ড্রাফট সংরক্ষিত হয়েছে।',
      )
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch (err) {
      setError(describeError(err).message)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      setSaving(null)
    }
  }

  async function savePublishInfo() {
    if (!video) return
    const meta = publishInfoPatch()
    if (Object.keys(meta).length === 0) return
    setError('')
    setNotice('')
    setSaving('meta')
    try {
      const saved = await AdminVideosApi.update(video.id, meta)
      setVideo(saved)
      syncPublishInfo(saved)
      setNotice(saved.status === 'future' ? 'সময় ও লেখক সংরক্ষিত হয়েছে — ভিডিওটি নির্ধারিত সময়ে প্রকাশ হবে।' : 'সময় ও লেখক সংরক্ষিত হয়েছে।')
    } catch (err) {
      setError(describeError(err).message)
    } finally {
      setSaving(null)
    }
  }

  async function deleteVideo() {
    if (!video) return
    await AdminVideosApi.remove(video.id)
    navigate('/app/videos', {
      replace: true,
      state: { notice: `“${video.title || 'শিরোনামহীন'}” ট্র্যাশে সরানো হয়েছে — wp-admin থেকে ফিরিয়ে আনা যাবে।` },
    })
  }

  if (loading) return <Spinner className="mt-10" />
  if (loadError) {
    return (
      <div>
        <PageHeader title="ভিডিও সম্পাদনা" actions={<Button variant="ghost" onClick={() => navigate('/app/videos')}>ভিডিও তালিকা</Button>} />
        <Banner kind="error">{loadError}</Banner>
      </div>
    )
  }

  const status = video?.status
  const isLive = status === 'publish' || status === 'future'
  const isPending = status === 'pending'
  const previewSrc = localUrl ?? (source === 'upload' ? videoUrl : '')
  const portrait = orientation === 'portrait'
  const frameBox = portrait ? 'aspect-[9/16] w-full max-w-[250px] mx-auto' : 'aspect-video w-full'
  const busy = saving !== null

  return (
    <div>
      <PageHeader
        title={video ? 'ভিডিও সম্পাদনা' : 'নতুন ভিডিও নিউজ'}
        subtitle={video ? `লেখক: ${video.author.name}` : 'আপলোড করা ভিডিও বা YouTube লিংক দিয়ে ভিডিও নিউজ তৈরি করুন।'}
        actions={
          <Button variant="ghost" onClick={() => navigate('/app/videos')}>
            ভিডিও তালিকা
          </Button>
        }
      />

      {error && <Banner kind="error">{error}</Banner>}
      {notice && <Banner kind="success">{notice}</Banner>}

      <div className="grid lg:grid-cols-[1fr_340px] gap-5">
        <div className="flex flex-col gap-5 min-w-0">
          <Card className="p-5">
            <div ref={sourceRef}>
              <p className="text-[13px] font-semibold text-neutral-700 mb-3">
                ভিডিও <span className="text-brand-600">*</span>
              </p>
              <div className="inline-flex rounded-lg bg-neutral-100 p-1 mb-4" role="tablist" aria-label="ভিডিওর উৎস">
                {(
                  [
                    ['upload', 'ফাইল আপলোড', IconUpload],
                    ['youtube', 'YouTube লিংক', IconBrandYoutube],
                  ] as const
                ).map(([value, label, Icon]) => (
                  <button
                    key={value}
                    type="button"
                    role="tab"
                    aria-selected={source === value}
                    onClick={() => {
                      setSource(value)
                      setFieldErrors((f) => ({ ...f, video: undefined }))
                    }}
                    className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[12.5px] font-medium transition ${
                      source === value ? 'bg-white text-neutral-900 shadow-sm' : 'text-neutral-500 hover:text-neutral-700'
                    }`}
                  >
                    <Icon className="w-4 h-4" />
                    {label}
                  </button>
                ))}
              </div>

              <input
                ref={fileInput}
                type="file"
                accept={VIDEO_ACCEPT}
                className="hidden"
                data-testid="video-file"
                onChange={(e) => {
                  const f = e.target.files?.[0]
                  if (f) startUpload(f)
                  e.target.value = ''
                }}
              />

              {source === 'upload' ? (
                <>
                  {previewSrc ? (
                    <div className={`${frameBox} rounded-xl overflow-hidden bg-black`}>
                      <video
                        ref={previewVideo}
                        key={previewSrc}
                        src={previewSrc}
                        controls
                        playsInline
                        preload="metadata"
                        className="w-full h-full object-contain"
                        data-testid="video-preview"
                      />
                    </div>
                  ) : (
                    <button
                      type="button"
                      onClick={() => fileInput.current?.click()}
                      onDragOver={(e) => {
                        e.preventDefault()
                        setDragOver(true)
                      }}
                      onDragLeave={() => setDragOver(false)}
                      onDrop={(e) => {
                        e.preventDefault()
                        setDragOver(false)
                        const f = e.dataTransfer.files?.[0]
                        if (f) startUpload(f)
                      }}
                      className={`w-full rounded-xl border-2 border-dashed py-10 px-4 flex flex-col items-center gap-2 text-center transition ${
                        dragOver ? 'border-brand-500 bg-brand-50/60' : 'border-neutral-300 hover:border-neutral-400 bg-neutral-50/50'
                      } ${fieldErrors.video ? 'border-red-300' : ''}`}
                    >
                      <span className="w-12 h-12 rounded-full bg-white border border-neutral-200 flex items-center justify-center text-brand-600">
                        <IconUpload className="w-6 h-6" />
                      </span>
                      <span className="text-[13.5px] font-medium text-neutral-800">ভিডিও ফাইল বেছে নিন বা এখানে টেনে আনুন</span>
                      <span className="text-[12px] text-neutral-500">
                        MP4, WebM বা MOV{maxBytes ? ` · সর্বোচ্চ ${bnFileSize(maxBytes)}` : ''} · পোর্ট্রেট (৯:১৬) ভিডিও সবচেয়ে ভালো দেখায়
                      </span>
                    </button>
                  )}

                  {upload.status === 'uploading' && (
                    <div className="mt-3 rounded-xl border border-neutral-200 p-3" data-testid="upload-progress">
                      <div className="flex items-center justify-between gap-3 mb-2">
                        <p className="text-[12.5px] text-neutral-700 min-w-0 truncate">
                          {upload.percent >= 100 ? 'সার্ভারে প্রসেস হচ্ছে…' : 'আপলোড হচ্ছে…'}{' '}
                          <span className="text-neutral-400">
                            {bnFileSize(upload.loaded)} / {bnFileSize(upload.total)}
                          </span>
                        </p>
                        <div className="flex items-center gap-2 shrink-0">
                          <span className="text-[12.5px] font-semibold text-neutral-900 tabular-nums">{bnNumber(upload.percent)}%</span>
                          <Button size="sm" variant="secondary" onClick={cancelUpload}>
                            <IconX className="w-3.5 h-3.5" />
                            বাতিল
                          </Button>
                        </div>
                      </div>
                      <div
                        className="h-2 rounded-full bg-neutral-100 overflow-hidden"
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={upload.percent}
                      >
                        <div
                          className={`h-full bg-brand-600 transition-[width] duration-200 ${upload.percent >= 100 ? 'animate-pulse' : ''}`}
                          style={{ width: `${upload.percent}%` }}
                        />
                      </div>
                    </div>
                  )}

                  {upload.status === 'error' && (
                    <div className="mt-3 rounded-xl bg-red-50 border border-red-100 px-3 py-2.5 flex items-center justify-between gap-3">
                      <p className="text-[12.5px] text-red-700">{upload.message}</p>
                      {pendingFile.current && isAllowedVideo(pendingFile.current, allowedMimes) && (!maxBytes || pendingFile.current.size <= maxBytes) && (
                        <Button size="sm" variant="secondary" onClick={() => pendingFile.current && startUpload(pendingFile.current)}>
                          আবার চেষ্টা
                        </Button>
                      )}
                    </div>
                  )}

                  {metaFailed && localUrl && (
                    <p className="mt-3 text-[12px] text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2" data-testid="meta-failed">
                      এই ব্রাউজারে ভিডিওটির প্রিভিউ দেখানো যাচ্ছে না (ফরম্যাট/কোডেক) — আপলোড ঠিকই হবে। ডান পাশে অরিয়েন্টেশন নিজে বেছে নিন।
                    </p>
                  )}

                  {previewSrc && upload.status !== 'uploading' && (
                    <div className="mt-3 flex items-center justify-between gap-3 flex-wrap">
                      <p className="text-[12px] text-neutral-500 min-w-0 truncate">
                        {fileInfo ? `${fileInfo.name} · ${bnFileSize(fileInfo.size)}` : 'আপলোড করা ভিডিও'}
                        {duration ? ` · দৈর্ঘ্য ${bnDuration(duration)}` : ''}
                      </p>
                      <Button size="sm" variant="secondary" onClick={() => fileInput.current?.click()}>
                        <IconUpload className="w-3.5 h-3.5" />
                        অন্য ভিডিও দিন
                      </Button>
                    </div>
                  )}

                  {attachmentId && upload.status !== 'uploading' && (
                    <div className="mt-3 rounded-xl border border-brand-100 bg-gradient-to-r from-brand-50 to-white p-3 flex items-center justify-between gap-3 flex-wrap" data-testid="studio-entry">
                      <div className="min-w-0">
                        <p className="text-[13px] font-semibold text-neutral-900">
                          {video?.edit && fileIsSaved && !clearEdit ? 'এই ভিডিওটি এডিটরে তৈরি' : 'ভিডিও এডিট করুন'}
                        </p>
                        <p className="text-[11.5px] text-neutral-500 leading-snug">
                          {video?.edit && fileIsSaved && !clearEdit
                            ? 'আবার এডিট করলে মূল ভিডিও থেকেই নতুন করে তৈরি হবে — আগের সেটিংস রাখা আছে।'
                            : 'ব্রেকিং/সংবাদ ফ্রেম, অ্যানিমেটেড লোগো, ইন্ট্রো-আউট্রো, বিজ্ঞাপন বিরতি ও ক্যাপশন — এক ক্লিকে।'}
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        {video?.edit && fileIsSaved && !clearEdit && (
                          <Button size="sm" variant="secondary" onClick={revertEdit} disabled={!!saving} data-testid="studio-revert">
                            মূল ভিডিওতে ফিরুন
                          </Button>
                        )}
                        <Button size="sm" onClick={openStudio} disabled={studioBusy || !!saving} data-testid="studio-open">
                          <IconWand className="w-3.5 h-3.5" />
                          {studioBusy ? 'সংরক্ষণ হচ্ছে…' : video?.edit && fileIsSaved && !clearEdit ? 'আবার এডিট করুন' : 'এডিট ভিডিও'}
                        </Button>
                      </div>
                    </div>
                  )}
                </>
              ) : (
                <>
                  <FieldLabel htmlFor="yt-url" label="YouTube লিংক" />
                  <input
                    id="yt-url"
                    className={`${inputClass} ${fieldErrors.video ? 'border-red-300' : ''}`}
                    value={youtubeUrl}
                    onChange={(e) => onYoutubeChange(e.target.value)}
                    placeholder="https://www.youtube.com/watch?v=… বা https://youtu.be/…"
                    inputMode="url"
                    dir="ltr"
                  />
                  {youtubeUrl.trim() && !youtubeId && (
                    <p className="text-[12px] text-amber-700 mt-1.5">লিংকটি চেনা যাচ্ছে না — youtube.com/watch?v=…, youtu.be/…, /shorts/… বা /embed/… লিংক দিন।</p>
                  )}
                  {youtubeId && (
                    <div className={`${frameBox} mt-3 rounded-xl overflow-hidden bg-black relative`}>
                      {ytPlaying ? (
                        <iframe
                          src={`https://www.youtube-nocookie.com/embed/${youtubeId}?autoplay=1`}
                          title="YouTube প্রিভিউ"
                          allow="autoplay; encrypted-media; picture-in-picture"
                          allowFullScreen
                          className="w-full h-full"
                        />
                      ) : (
                        <button type="button" onClick={() => setYtPlaying(true)} className="w-full h-full relative group" aria-label="প্রিভিউ চালান">
                          <img src={youTubeThumb(youtubeId)} alt="" className="w-full h-full object-cover opacity-90 group-hover:opacity-100" />
                          <span className="absolute inset-0 flex items-center justify-center">
                            <span className="w-14 h-14 rounded-full bg-black/60 text-white flex items-center justify-center">
                              <IconPlayerPlay className="w-6 h-6 ml-0.5" />
                            </span>
                          </span>
                        </button>
                      )}
                    </div>
                  )}
                </>
              )}
              <FieldError>{fieldErrors.video}</FieldError>
            </div>
          </Card>

          <Card className="p-5">
            <div className="mb-4">
              <FieldLabel htmlFor="v-title" label="শিরোনাম" required right={<CharCount value={title} max={TITLE_MAX} testId="title-count" />} />
              <input
                id="v-title"
                ref={titleRef}
                className={`${inputClass} ${fieldErrors.title ? 'border-red-300' : ''}`}
                value={title}
                onChange={(e) => {
                  setTitle(e.target.value)
                  if (fieldErrors.title) setFieldErrors((f) => ({ ...f, title: undefined }))
                }}
                placeholder="ছোট ও স্পষ্ট শিরোনাম"
              />
              <FieldError>{fieldErrors.title}</FieldError>
            </div>
            <div>
              <FieldLabel htmlFor="v-excerpt" label="সংক্ষিপ্ত বিবরণ" right={<CharCount value={excerpt} max={EXCERPT_MAX} />} />
              <textarea
                id="v-excerpt"
                className={`${inputClass} ${fieldErrors.excerpt ? 'border-red-300' : ''}`}
                rows={3}
                value={excerpt}
                onChange={(e) => {
                  setExcerpt(e.target.value)
                  if (fieldErrors.excerpt) setFieldErrors((f) => ({ ...f, excerpt: undefined }))
                }}
                placeholder="ভিডিওতে কী আছে — দুই-এক লাইনে"
              />
              <FieldError>{fieldErrors.excerpt}</FieldError>
            </div>
          </Card>

          <Card className="p-5">
            <p className="text-[13px] font-semibold text-neutral-700 mb-1">এসইও সেটিংস</p>
            <p className="text-[11.5px] text-neutral-400 mb-3">Rank Math SEO প্লাগিনের ফিল্ড — সরাসরি এখান থেকে সেট হবে।</p>
            <div className="mb-3.5">
              <FieldLabel htmlFor="v-seo-title" label="SEO শিরোনাম" right={<CharCount value={seoTitle} max={SEO_TITLE_MAX} />} />
              <input id="v-seo-title" className={inputClass} value={seoTitle} onChange={(e) => setSeoTitle(e.target.value)} placeholder={title || 'খালি রাখলে মূল শিরোনাম'} />
            </div>
            <div className="mb-3.5">
              <FieldLabel htmlFor="v-meta" label="মেটা বিবরণ" right={<CharCount value={seoDescription} max={META_DESC_MAX} testId="meta-count" />} />
              <textarea id="v-meta" className={inputClass} rows={3} value={seoDescription} onChange={(e) => setSeoDescription(e.target.value)} placeholder="সার্চ রেজাল্টে দেখানো বিবরণ (~১৬০ অক্ষর)" />
            </div>
            <div className="grid sm:grid-cols-2 gap-x-4">
              <div className="mb-3.5">
                <FieldLabel htmlFor="v-focus" label="ফোকাস কীওয়ার্ড" />
                <input id="v-focus" className={inputClass} value={focusKeyword} onChange={(e) => setFocusKeyword(e.target.value)} />
              </div>
              <div className="mb-1">
                <FieldLabel htmlFor="v-slug" label="স্লাগ (URL)" />
                <div className="flex items-stretch">
                  <span className="flex items-center rounded-l-lg border border-r-0 border-neutral-200 bg-neutral-50 px-2 text-[12px] text-neutral-400" dir="ltr">
                    /video/
                  </span>
                  <input
                    id="v-slug"
                    className={`${inputClass} rounded-l-none`}
                    value={slug}
                    onChange={(e) => setSlug(e.target.value)}
                    placeholder="url-slug"
                    dir="ltr"
                  />
                </div>
              </div>
            </div>
          </Card>
        </div>

        <div className="flex flex-col gap-5">
          <Card className="p-5">
            <p className="text-[13px] font-semibold text-neutral-700 mb-3">প্রকাশনা</p>
            {video && (
              <div className="flex items-center gap-2 mb-3 text-[12.5px] text-neutral-500">
                বর্তমান অবস্থা: <VideoStatusBadge video={video} />
              </div>
            )}
            {video?.rejected && video.reject_reason && (
              <p className="text-[12px] text-red-700 bg-red-50 border border-red-100 rounded-lg px-2.5 py-2 mb-3">
                প্রত্যাখ্যানের কারণ: {video.reject_reason}
              </p>
            )}
            {isPending && (
              <p className="text-[12px] text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-2.5 py-2 mb-3">
                সংবাদদাতার জমা দেওয়া ভিডিও — যাচাই করে প্রকাশ বা প্রত্যাখ্যান করুন।
              </p>
            )}
            {isLive && video?.link && (
              <a href={video.link} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-[12.5px] text-brand-600 underline mb-3">
                <IconExternalLink className="w-3.5 h-3.5" />
                সাইটে দেখুন
              </a>
            )}
            <div className="flex flex-col gap-2">
              {isLive ? (
                <>
                  <Button onClick={() => save('publish')} disabled={busy || uploading}>
                    {saving === 'publish' ? 'হচ্ছে…' : 'আপডেট করুন'}
                  </Button>
                  <Button variant="secondary" onClick={() => save('draft')} disabled={busy || uploading}>
                    {saving === 'draft' ? 'হচ্ছে…' : 'ড্রাফটে ফিরিয়ে নিন'}
                  </Button>
                  <Button variant="danger" onClick={() => setRejectOpen(true)} disabled={busy}>
                    প্রত্যাখ্যান করুন (সাইট থেকে সরান)
                  </Button>
                </>
              ) : (
                <>
                  <Button onClick={() => save('publish')} disabled={busy || uploading}>
                    {saving === 'publish' ? 'হচ্ছে…' : isPending ? 'অনুমোদন ও প্রকাশ করুন' : 'প্রকাশ করুন'}
                  </Button>
                  {isPending ? (
                    <>
                      <Button variant="secondary" onClick={() => save('pending')} disabled={busy || uploading}>
                        {saving === 'pending' ? 'হচ্ছে…' : 'পরিবর্তন সংরক্ষণ (রিভিউতে রেখে)'}
                      </Button>
                      <Button variant="danger" onClick={() => setRejectOpen(true)} disabled={busy}>
                        প্রত্যাখ্যান করুন
                      </Button>
                    </>
                  ) : (
                    <Button variant="secondary" onClick={() => save('draft')} disabled={busy || uploading}>
                      {saving === 'draft' ? 'হচ্ছে…' : 'সেভ ড্রাফট'}
                    </Button>
                  )}
                </>
              )}
              {uploading && <p className="text-[11.5px] text-neutral-400 text-center">আপলোড শেষ হলে সংরক্ষণ করা যাবে।</p>}
            </div>
          </Card>

          <PublishInfoCard
            kind="video"
            isNew={!video}
            floating={!video || !initialDateInput(video)}
            date={dateInput}
            onDateChange={setDateInput}
            canChangeDate={perms.changeDate}
            author={author}
            onAuthorChange={(a) => setAuthor({ id: a.id, name: a.name, avatar: a.avatar, role: a.role })}
            canChangeAuthor={perms.changeAuthor}
            metaDirty={publishInfoDirty}
            savingMeta={saving === 'meta'}
            onSaveMeta={savePublishInfo}
            canDelete={perms.deletePosts}
            onDeleteClick={() => setDeleteOpen(true)}
          />

          {/* Round 12 — per-video payment (correspondents' raw video news), same as articles. */}
          {video && video.payment !== undefined && (
            <PaymentPanel
              kind="video"
              amount={video.payment}
              status={video.payment_status}
              canEdit={isPublisher}
              onSave={(amount) => AdminVideosApi.setPayment(video.id, amount)}
              onConfirm={() => AdminVideosApi.confirmPayment(video.id)}
              testId="video-payment"
            />
          )}

          <Card className="p-5">
            <p className="text-[13px] font-semibold text-neutral-700 mb-3">ক্যাটেগরি ও ফরম্যাট</p>
            <select className={`${inputClass} mb-4`} value={categoryId} onChange={(e) => setCategoryId(Number(e.target.value))} aria-label="ক্যাটেগরি">
              <option value={0}>কোনো ক্যাটেগরি নয়</option>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
            <div className="flex items-center justify-between mb-1.5">
              <p className="text-[12.5px] font-medium text-neutral-600">অরিয়েন্টেশন</p>
              {orientationAuto && <span className="text-[11px] text-emerald-700 bg-emerald-50 rounded-full px-2 py-0.5">স্বয়ংক্রিয়ভাবে শনাক্ত</span>}
            </div>
            <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="অরিয়েন্টেশন">
              {(
                [
                  ['portrait', 'পোর্ট্রেট', '৯:১৬', IconRectangleVertical],
                  ['landscape', 'ল্যান্ডস্কেপ', '১৬:৯', IconRectangleHorizontal],
                ] as const
              ).map(([value, label, ratio, Icon]) => (
                <button
                  key={value}
                  type="button"
                  role="radio"
                  aria-checked={orientation === value}
                  onClick={() => {
                    setOrientation(value)
                    setOrientationAuto(false)
                    setOrientationTouched(true)
                  }}
                  className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-left transition ${
                    orientation === value ? 'border-brand-500 bg-brand-50/60 text-brand-700' : 'border-neutral-200 text-neutral-600 hover:border-neutral-300'
                  }`}
                >
                  <Icon className="w-5 h-5 shrink-0" />
                  <span>
                    <span className="block text-[12.5px] font-medium">{label}</span>
                    <span className="block text-[11px] opacity-70">{ratio}</span>
                  </span>
                </button>
              ))}
            </div>
          </Card>

          <Card className="p-5">
            <p className="text-[13px] font-semibold text-neutral-700 mb-3">থাম্বনেইল</p>
            <input
              ref={thumbInput}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="hidden"
              onChange={(e) => {
                const f = e.target.files?.[0]
                if (f) uploadThumb(f)
                e.target.value = ''
              }}
            />
            {thumbnailUrl ? (
              <div className="relative rounded-xl overflow-hidden border border-neutral-200 mb-3 bg-neutral-900">
                <img src={thumbnailUrl} alt="" className={`w-full ${portrait ? 'h-56 object-contain' : 'aspect-video object-cover'}`} />
                {!thumbnailId && source === 'youtube' && (
                  <span className="absolute bottom-2 left-2 bg-black/60 text-white text-[11px] px-2 py-0.5 rounded">YouTube থেকে স্বয়ংক্রিয়</span>
                )}
                {thumbnailId && (
                  <button
                    type="button"
                    onClick={() => {
                      setThumbnailId(null)
                      setThumbnailUrl(source === 'youtube' && youtubeId ? youTubeThumb(youtubeId) : null)
                    }}
                    aria-label="থাম্বনেইল সরান"
                    className="absolute top-2 right-2 w-7 h-7 rounded-full bg-black/60 text-white flex items-center justify-center hover:bg-black/80"
                  >
                    <IconX className="w-4 h-4" />
                  </button>
                )}
              </div>
            ) : source === 'youtube' && youtubeId ? (
              <p className="text-[11.5px] text-neutral-500 mb-3">থাম্বনেইল না দিলে YouTube-এর থাম্বনেইল ব্যবহার হবে।</p>
            ) : (
              <p className="text-[11.5px] text-neutral-500 mb-3">ভালো থাম্বনেইল ভিডিওতে ক্লিক বাড়ায়।</p>
            )}
            <div className="flex flex-col gap-2">
              <button
                type="button"
                onClick={() => thumbInput.current?.click()}
                disabled={thumbBusy}
                className="w-full flex items-center justify-center gap-2 rounded-lg border border-dashed border-neutral-300 text-neutral-600 py-2.5 text-[12.5px] font-medium hover:border-neutral-400 disabled:opacity-60"
              >
                <IconPhotoPlus className="w-4 h-4" />
                {thumbBusy ? 'যুক্ত হচ্ছে…' : thumbnailId ? 'নতুন ছবি আপলোড করুন' : 'ছবি আপলোড করুন'}
              </button>
              <button
                type="button"
                onClick={() => setGalleryOpen(true)}
                disabled={thumbBusy}
                className="w-full flex items-center justify-center gap-1.5 rounded-lg bg-neutral-100 text-neutral-700 py-2 text-[12.5px] font-medium hover:bg-neutral-200 disabled:opacity-60"
              >
                <IconLibraryPhoto className="w-4 h-4" />
                গ্যালারি থেকে বেছে নিন
              </button>
              {source === 'upload' && localUrl && (
                <button
                  type="button"
                  onClick={thumbFromFrame}
                  disabled={thumbBusy}
                  className="w-full flex items-center justify-center gap-1.5 rounded-lg bg-neutral-100 text-neutral-700 py-2 text-[12.5px] font-medium hover:bg-neutral-200 disabled:opacity-60"
                >
                  <IconFrame className="w-4 h-4" />
                  ভিডিওর বর্তমান ফ্রেম ব্যবহার করুন
                </button>
              )}
            </div>
            {galleryOpen && (
              <MediaGallery
                title="থাম্বনেইল — গ্যালারি থেকে বেছে নিন"
                onClose={() => setGalleryOpen(false)}
                onSelect={(img) => {
                  setGalleryOpen(false)
                  setThumbnailId(img.id)
                  setThumbnailUrl(img.url)
                }}
              />
            )}
          </Card>
        </div>
      </div>

      {deleteOpen && video && (
        <DeleteConfirmModal kind="video" title={video.title} onConfirm={deleteVideo} onClose={() => setDeleteOpen(false)} />
      )}
      {rejectOpen && video && (
        <RejectVideoModal
          video={video}
          onClose={() => setRejectOpen(false)}
          onRejected={(v) => {
            setRejectOpen(false)
            setVideo(v)
            setNotice('ভিডিওটি প্রত্যাখ্যান করা হয়েছে — সংবাদদাতা কারণসহ দেখতে পাবেন।')
          }}
        />
      )}
    </div>
  )
}
