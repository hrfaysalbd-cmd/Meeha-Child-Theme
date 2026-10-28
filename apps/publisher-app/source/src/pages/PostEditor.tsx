import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  AdminPostsApi,
  CategoriesApi,
  MediaApi,
  PromptsApi,
  ApiError,
  type AdminPost,
  type Category,
  type RevisionEntry,
  type Prompt,
  type AdminPostInput,
  type LitSchema,
  type PostArea,
  type PostFlags,
  EditorialApi,
  EMPTY_AREA,
  EMPTY_FLAGS,
} from '../lib/api'
import { decodeSlug, usePostPermissions, initialDateInput, inputToWpDate } from '../lib/postMeta'
import { DeleteConfirmModal, PublishInfoCard } from '../components/PostManagement'
import type { AuthorValue } from '../components/AuthorPicker'
import GuestAuthorField, { guestKey, type GuestDraft } from '../components/GuestAuthorField'
import { fillPromptTemplate, stripHtmlForPrompt } from '../lib/promptTemplate'
import { useAuth } from '../lib/AuthContext'
import { Banner, Button, Card, Field, inputClass, Modal, PageHeader, Spinner, bnNumber } from '../components/ui'
import { IconHistory, IconLibraryPhoto, IconPhotoPlus } from '../components/Icon'
import MediaGallery from '../components/MediaGallery'
import RichEditor from '../components/RichEditor'
import CategoryPicker from '../components/CategoryPicker'
import { rememberCategory } from '../lib/categoryTree'
import PaymentPanel from '../components/PaymentPanel'
import {
  AreaPicker,
  Counter,
  EditorialFlags,
  LiteratureFields,
  Section,
  TagInput,
  TOPIC_MAX,
  areaSummary,
  flagsSummary,
  genreForCategory,
  isLiteratureCategory,
} from '../components/PostFields'

const bnDigits = (n: number) => String(n).replace(/[0-9]/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])

function featuredUploadLabel(percent: number) {
  if (percent >= 100) return 'সার্ভারে প্রস্তুত হচ্ছে…'
  return percent > 0 ? `আপলোড হচ্ছে… ${bnDigits(percent)}%` : 'ছবি প্রস্তুত হচ্ছে…'
}

const RECENT_CATEGORIES_KEY = 'nros_pub_recent_categories'

function statusLabel(status: string) {
  if (status === 'publish') return 'প্রকাশিত'
  if (status === 'future') return 'নির্ধারিত (শিডিউলড)'
  if (status === 'pending') return 'রিভিউতে (সংবাদদাতার জমা)'
  return 'ড্রাফট'
}

// Shared by two entry points: the sidebar's "লিখুন" (staff writing their own,
// no-review article) and the Queue's "এডিট করুন" (staff fully opening ANY
// article — including a correspondent's still-pending submission — for
// detailed edits, SEO fields, and image alt/caption, per the user's request
// that Publisher/Editor get the same full editing access the correspondent
// app never exposed).
export default function PostEditor() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { isPublisher, profile } = useAuth()
  const perms = usePostPermissions()
  const featuredInput = useRef<HTMLInputElement>(null)

  const [categories, setCategories] = useState<Category[]>([])
  const [loading, setLoading] = useState(!!id)
  const [post, setPost] = useState<AdminPost | null>(null)

  const [title, setTitle] = useState('')
  const [aiSummary, setAiSummary] = useState('')
  const [content, setContent] = useState('')
  const [categoryId, setCategoryId] = useState(0)

  // Round 12 — the theme's editorial fields. (সংক্ষিপ্তসার/excerpt is no
  // longer shown or sent; the API keeps whatever was stored.)
  const [tags, setTags] = useState<string[]>([])
  const [area, setArea] = useState<PostArea>(EMPTY_AREA)
  const [topic, setTopic] = useState('')
  const [flags, setFlags] = useState<PostFlags>(EMPTY_FLAGS)
  const [glanceText, setGlanceText] = useState('')
  const [lit, setLit] = useState<Record<string, string>>({})
  const [litTouched, setLitTouched] = useState(false)
  const [litSchema, setLitSchema] = useState<LitSchema | null>(null)
  // Sections start open on a desktop, folded on a phone (one long stack).
  const [wide] = useState(() => typeof window === 'undefined' || window.matchMedia('(min-width: 1024px)').matches)

  // Round 9 — every 'news'-type prompt visible to this staff member
  // (Editor/Publisher always see all of them — see NROS_Prompts), offered
  // next to the title. Replaces round 8's single staff_prompt_templates
  // Settings field.
  const [newsPrompts, setNewsPrompts] = useState<Prompt[]>([])
  const [promptMsg, setPromptMsg] = useState('')

  const [slug, setSlug] = useState('')
  const [seoTitle, setSeoTitle] = useState('')
  const [seoDescription, setSeoDescription] = useState('')
  const [focusKeyword, setFocusKeyword] = useState('')

  const [featuredImageId, setFeaturedImageId] = useState<number | null>(null)
  const [featuredImage, setFeaturedImage] = useState<string | null>(null)
  const [featuredAlt, setFeaturedAlt] = useState('')
  const [featuredCaption, setFeaturedCaption] = useState('')


  const [revisions, setRevisions] = useState<RevisionEntry[]>([])
  const [revisionsOpen, setRevisionsOpen] = useState(false)
  const [revisionsLoading, setRevisionsLoading] = useState(false)

  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const [uploadingFeatured, setUploadingFeatured] = useState(false)
  const [featuredProgress, setFeaturedProgress] = useState(0)
  const [featuredGalleryOpen, setFeaturedGalleryOpen] = useState(false)
  const [savingMeta, setSavingMeta] = useState(false)

  // Round 10 — "প্রকাশের তথ্য": publish date/time + author (+ delete).
  // Each is sent only when changed AND the viewer's profile.can allows it.
  const [dateInput, setDateInput] = useState('')
  const [origDateInput, setOrigDateInput] = useState('')
  const [author, setAuthor] = useState<AuthorValue | null>(
    profile ? { id: profile.id, name: profile.name, avatar: profile.avatar, role: profile.role } : null,
  )
  const [origAuthorId, setOrigAuthorId] = useState<number | null>(profile?.id ?? null)
  // Round 13 — অতিথি লেখক (null = none). Sent only when changed (or saved).
  const [guest, setGuest] = useState<GuestDraft | null>(null)
  const [origGuest, setOrigGuest] = useState<GuestDraft | null>(null)
  const [saveGuest, setSaveGuest] = useState(false)
  const [savingPublishInfo, setSavingPublishInfo] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)

  useEffect(() => {
    CategoriesApi.list().then(setCategories).catch(() => setCategories([]))
    EditorialApi.litSchema()
      .then(setLitSchema)
      .catch(() => setLitSchema(null))
    PromptsApi.listForViewer('news')
      .then(setNewsPrompts)
      .catch(() => setNewsPrompts([]))
  }, [])

  useEffect(() => {
    if (!id) return
    setLoading(true)
    AdminPostsApi.get(Number(id))
      .then((p) => {
        setPost(p)
        setTitle(p.title)
        setAiSummary(p.ai_summary)
        setContent(p.content)
        setCategoryId(p.category_id)
        setSlug(decodeSlug(p.slug))
        setSeoTitle(p.seo.title)
        setSeoDescription(p.seo.description)
        setFocusKeyword(p.seo.focus_keyword)
        setFeaturedImageId(p.featured_image_id)
        setFeaturedImage(p.featured_image)
        setFeaturedAlt(p.featured_image_alt)
        setFeaturedCaption(p.featured_image_caption)
        syncEditorial(p)
        syncPublishInfo(p)
      })
      .catch(() => setError('পোস্টটি পাওয়া যায়নি।'))
      .finally(() => setLoading(false))
  }, [id])

  function syncEditorial(p: AdminPost) {
    setTags(Array.isArray(p.tags) ? p.tags : [])
    setArea({ ...EMPTY_AREA, ...(p.area ?? {}) })
    setTopic(p.topic ?? '')
    setFlags({ ...EMPTY_FLAGS, ...(p.flags ?? {}) })
    setGlanceText(p.glance_text ?? '')
    setLit(p.lit && typeof p.lit === 'object' ? { ...p.lit } : {})
    setLitTouched(false)
  }

  function syncPublishInfo(p: AdminPost) {
    // Round 13: empty for an unpublished post with no chosen date (see initialDateInput).
    const d = initialDateInput(p)
    setDateInput(d)
    setOrigDateInput(d)
    setAuthor(p.author ? { id: p.author.id, name: p.author.name } : null)
    setOrigAuthorId(p.author?.id ?? null)
    const g = p.guest_author ? { ...p.guest_author } : null
    setGuest(g)
    setOrigGuest(g)
    setSaveGuest(false)
  }

  // Only the changed, permitted fields — never resend an unchanged date
  // (that would e.g. re-stamp a draft's date) or anything not allowed.
  function publishInfoPatch(): Pick<AdminPostInput, 'date' | 'author_id' | 'guest_author' | 'save_guest_author'> {
    const patch: Pick<AdminPostInput, 'date' | 'author_id' | 'guest_author' | 'save_guest_author'> = {}
    if (perms.changeDate && dateInput && dateInput !== origDateInput) patch.date = inputToWpDate(dateInput)
    if (perms.changeAuthor && author && author.id !== origAuthorId) patch.author_id = author.id
    // A half-typed guest (toggle on, no name yet) is not sent — save() warns instead.
    const guestReady = !guest || guest.name.trim() !== ''
    if (perms.changeAuthor && guestReady && (guestKey(guest) !== guestKey(origGuest) || (guest && saveGuest))) {
      patch.guest_author = guest ? { name: guest.name.trim(), title: guest.title.trim(), photo_id: guest.photo_id || 0 } : null
      if (guest && saveGuest) patch.save_guest_author = true
    }
    return patch
  }

  // Round 13 — the guest toggle is on but no name was typed.
  function guestMissingName() {
    return perms.changeAuthor && !!guest && guest.name.trim() === ''
  }
  const publishInfoDirty = Object.keys(publishInfoPatch()).length > 0

  const categoryName = categories.find((c) => c.id === categoryId)?.name ?? ''
  const showLiterature =
    !!litSchema && (isLiteratureCategory(litSchema, categoryId, categoryName) || (!!post?.is_literature && categoryId === post.category_id))
  const autoGenre =
    genreForCategory(litSchema, categoryId, categoryName) || (post && categoryId === post.category_id ? post.lit_genre_effective ?? '' : '')

  function fields(): AdminPostInput {
    return {
      title,
      ai_summary: aiSummary,
      tags,
      area,
      topic: topic.trim(),
      flags,
      glance_text: glanceText.trim(),
      // Literature meta only for literature posts (or when it was edited).
      ...(showLiterature || litTouched ? { lit } : {}),
      content,
      category_id: categoryId || undefined,
      slug: slug || undefined,
      seo_title: seoTitle,
      seo_description: seoDescription,
      focus_keyword: focusKeyword,
    }
  }

  // "নিউজ টাইটেল অথবা বডি যে কোন একটি ফাকা থাকা অবস্থায় প্রোমপ্ট তৈরি হবে
  // না" — same validation rule as the correspondent app's summary-prompt
  // button, applied here to whichever staff template was picked.
  async function generateStaffPrompt(promptId: number) {
    setPromptMsg('')
    const tpl = newsPrompts.find((t) => t.id === promptId)
    if (!tpl) return
    const bodyText = stripHtmlForPrompt(content)
    if (!title.trim() || !bodyText.trim()) {
      setPromptMsg('প্রম্পট তৈরির আগে শিরোনাম ও বিস্তারিত পূরণ করুন।')
      return
    }
    const text = fillPromptTemplate(tpl.body, { title, body: bodyText, summary: aiSummary, category: categoryName })
    try {
      await navigator.clipboard.writeText(text)
      setPromptMsg(`"${tpl.label}" কপি হয়েছে — এখন আপনার এ.আই টুলে পেস্ট করুন।`)
    } catch {
      setPromptMsg('কপি করা যায়নি — ব্রাউজারকে ক্লিপবোর্ড অনুমতি দিন।')
    }
  }

  async function save(status: 'draft' | 'publish') {
    setError('')
    setNotice('')
    if (!title.trim()) {
      setError('শিরোনাম দিন।')
      return
    }
    if (guestMissingName()) {
      setError('অতিথি লেখকের নাম দিন, অথবা “অতিথি লেখক” বন্ধ করুন।')
      return
    }
    setSaving(true)
    try {
      let saved: AdminPost
      const meta = publishInfoPatch()
      if (post) {
        saved = await AdminPostsApi.update(post.id, { ...fields(), status, ...meta })
      } else if (Object.keys(meta).length > 0) {
        // date/author_id are PATCH-only in the API: create as a draft first,
        // then apply them together with the chosen status — so a post meant
        // for a future time is never briefly live under "now".
        // The URL switches only after the PATCH has landed, so the editor's
        // reload-on-id effect can't race it and show the pre-PATCH state.
        const created = await AdminPostsApi.create({ ...fields(), status: 'draft' })
        setPost(created)
        try {
          saved = await AdminPostsApi.update(created.id, { status, ...meta })
        } finally {
          navigate(`/app/write/${created.id}`, { replace: true })
        }
      } else {
        saved = await AdminPostsApi.create({ ...fields(), status })
        navigate(`/app/write/${saved.id}`, { replace: true })
      }
      setPost(saved)
      if (categoryId) rememberCategory(RECENT_CATEGORIES_KEY, categoryId)
      syncPublishInfo(saved)
      if (saved.tags) syncEditorial(saved)
      setNotice(
        saved.status === 'future'
          ? 'নির্ধারিত সময়ে প্রকাশের জন্য সংরক্ষিত হয়েছে।'
          : status === 'publish'
            ? 'প্রকাশিত হয়েছে।'
            : 'ড্রাফট সংরক্ষিত হয়েছে।',
      )
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setSaving(false)
    }
  }

  // Saves just the date/author (without touching status or content).
  async function savePublishInfo() {
    if (!post) return
    if (guestMissingName()) {
      setError('অতিথি লেখকের নাম দিন, অথবা “অতিথি লেখক” বন্ধ করুন।')
      return
    }
    const meta = publishInfoPatch()
    if (Object.keys(meta).length === 0) return
    setError('')
    setNotice('')
    setSavingPublishInfo(true)
    try {
      const saved = await AdminPostsApi.update(post.id, meta)
      setPost(saved)
      syncPublishInfo(saved)
      setNotice(saved.status === 'future' ? 'সময় ও লেখক সংরক্ষিত হয়েছে — পোস্টটি নির্ধারিত সময়ে প্রকাশ হবে।' : 'সময় ও লেখক সংরক্ষিত হয়েছে।')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setSavingPublishInfo(false)
    }
  }

  async function deletePost() {
    if (!post) return
    await AdminPostsApi.remove(post.id)
    navigate('/app/posts', {
      replace: true,
      state: { notice: `“${post.title || 'শিরোনামহীন'}” ট্র্যাশে সরানো হয়েছে — wp-admin থেকে ফিরিয়ে আনা যাবে।` },
    })
  }

  // Uploads a new file, or (round 9 follow-up 5) re-uses an image picked
  // from the gallery, as the featured image.
  async function applyFeatured(source: { file: File } | { attachmentId: number }) {
    setError('')
    setUploadingFeatured(true)
    setFeaturedProgress(0)
    try {
      // Same pattern as the correspondent app: the featured image attaches
      // to a real post id, so an unsaved new article is saved as a draft
      // first, invisibly, so picking a cover photo "just works" before
      // staff has hit save.
      let target = post
      if (!target) {
        target = await AdminPostsApi.create({ ...fields(), status: 'draft' })
        setPost(target)
        navigate(`/app/write/${target.id}`, { replace: true })
      }
      const attachmentId = 'file' in source ? (await MediaApi.upload(source.file, undefined, (pr) => setFeaturedProgress(pr.percent))).id : source.attachmentId
      const updated = await AdminPostsApi.setFeaturedImage(target.id, attachmentId)
      setPost(updated)
      setFeaturedImageId(updated.featured_image_id)
      setFeaturedImage(updated.featured_image)
      setFeaturedAlt(updated.featured_image_alt)
      setFeaturedCaption(updated.featured_image_caption)
      setNotice('ফিচার ইমেজ যুক্ত হয়েছে।')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'ফিচার ইমেজ যুক্ত করা যায়নি।')
    } finally {
      setUploadingFeatured(false)
    }
  }

  const onPickFeatured = (file: File) => applyFeatured({ file })

  async function saveFeaturedMeta() {
    if (!featuredImageId) return
    setSavingMeta(true)
    try {
      await MediaApi.updateMeta(featuredImageId, { alt: featuredAlt, caption: featuredCaption })
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'ছবির তথ্য সংরক্ষণ করা যায়নি।')
    } finally {
      setSavingMeta(false)
    }
  }

  async function openRevisions() {
    if (!post) return
    setRevisionsOpen(true)
    setRevisionsLoading(true)
    try {
      const r = await AdminPostsApi.getRevisions(post.id)
      setRevisions(r)
    } catch {
      setRevisions([])
    } finally {
      setRevisionsLoading(false)
    }
  }

  if (loading) return <Spinner className="mt-10" />

  return (
    <div>
      <PageHeader
        title={post ? 'পোস্ট সম্পাদনা' : 'নতুন পোস্ট লিখুন'}
        subtitle={
          post?.author
            ? `লেখক: ${post.author.name}${post.guest_author ? ` · অতিথি লেখক: ${post.guest_author.name}` : ''}`
            : 'এটি সরাসরি প্রকাশ হবে — যাচাইয়ের প্রয়োজন নেই।'
        }
        actions={
          <Button variant="ghost" onClick={() => navigate(-1)}>
            ফিরে যান
          </Button>
        }
      />

      {error && <Banner kind="error">{error}</Banner>}
      {notice && <Banner kind="success">{notice}</Banner>}

      <div className="grid lg:grid-cols-[1fr_340px] gap-5">
        <Card className="p-3.5 sm:p-5 min-w-0">
          <div className="flex flex-col sm:flex-row sm:items-start sm:gap-2 mb-1">
            <div className="flex-1 min-w-0">
              <Field label="শিরোনাম" required>
                <input
                  className={inputClass}
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="সংবাদের শিরোনাম"
                />
              </Field>
            </div>
            {newsPrompts.length > 0 && (
              <div className="shrink-0 -mt-1.5 mb-3 sm:mb-0 sm:mt-0 sm:pt-6">
                <select
                  className="text-[12px] font-medium text-brand-700 bg-brand-50 border border-brand-100 rounded-lg px-2 py-2 cursor-pointer"
                  value=""
                  onChange={(e) => {
                    if (e.target.value) generateStaffPrompt(Number(e.target.value))
                    e.target.value = ''
                  }}
                >
                  <option value="">✨ প্রম্পট জেনারেট করুন</option>
                  {newsPrompts.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.label}
                    </option>
                  ))}
                </select>
              </div>
            )}
          </div>
          {promptMsg && <p className="text-[12px] text-neutral-500 mb-4">{promptMsg}</p>}

          {/* Round 18: SEO sits right under the headline it describes. */}
          <div className="mb-4">
            <Section title="এসইও সেটিংস" summary={seoTitle || focusKeyword || 'Rank Math SEO'} defaultOpen={false} testId="sec-seo">
              <p className="text-[11.5px] text-neutral-400 mb-3">Rank Math SEO প্লাগিনের ফিল্ড — সরাসরি এখান থেকে সেট হবে।</p>
              <Field label="স্লাগ (URL)">
                <input className={inputClass} value={slug} onChange={(e) => setSlug(e.target.value)} placeholder="url-slug" />
              </Field>
              <Field label="এসইও টাইটেল">
                <input className={inputClass} value={seoTitle} onChange={(e) => setSeoTitle(e.target.value)} />
              </Field>
              <Field label="মেটা ডিসক্রিপশন">
                <textarea className={inputClass} rows={3} value={seoDescription} onChange={(e) => setSeoDescription(e.target.value)} />
              </Field>
              <Field label="ফোকাস কি-ওয়ার্ড">
                <input className={inputClass} value={focusKeyword} onChange={(e) => setFocusKeyword(e.target.value)} />
              </Field>
            </Section>
          </div>

          <Field label="সারাংশ (এ.আই)">
            <textarea
              className={inputClass}
              rows={3}
              value={aiSummary}
              onChange={(e) => setAiSummary(e.target.value)}
              placeholder='ফ্রন্টএন্ডে "সারাংশ পড়ুন" বাটনে এই লেখাটি দেখাবে'
            />
          </Field>

          {/* Not a <label>: clicks inside the editor must never be forwarded to a toolbar button. */}
          <div className="mb-1">
            <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">
              বিস্তারিত <span className="text-brand-600">*</span>
            </span>
            <RichEditor content={content} onChange={setContent} />
          </div>

          {/* Round 18: literature details follow the body they describe. */}
          {showLiterature && litSchema && (
            <div className="mt-4">
              <Section
                title="সাহিত্য তথ্য"
                summary={(() => {
                  const g = litSchema.genres.find((x) => x.id === (lit.genre || autoGenre))
                  return g ? `ধরন: ${g.label}` : 'ট্যাপ করে খুলুন'
                })()}
                defaultOpen={false}
                testId="sec-lit"
              >
                <LiteratureFields
                  schema={litSchema}
                  lit={lit}
                  autoGenre={autoGenre}
                  coverUrl={post?.lit_cover_url}
                  onChange={(v) => {
                    setLit(v)
                    setLitTouched(true)
                  }}
                />
              </Section>
            </div>
          )}
        </Card>

        <div className="flex flex-col gap-5 min-w-0">
          {/* Round 18: editorial placement (lead, trending…) first in this column. */}
          <Section title="সম্পাদকীয়" summary={flagsSummary(flags)} defaultOpen testId="sec-flags">
            <EditorialFlags flags={flags} onChange={setFlags} glanceText={glanceText} onGlanceText={setGlanceText} title={title} trendingAt={post?.flag_times?.trending_at} />
          </Section>

          <Card className="p-5">
            <p className="text-[13px] font-semibold text-neutral-700 mb-3">প্রকাশনা</p>
            {post && (
              <p className="text-[12.5px] text-neutral-500 mb-3">
                বর্তমান অবস্থা: <span className="font-medium text-neutral-800">{statusLabel(post.status)}</span>
              </p>
            )}
            {post?.permalink && (
              <a href={post.permalink} target="_blank" rel="noreferrer" className="text-[12.5px] text-brand-600 underline block mb-3">
                প্রকাশিত পোস্টটি দেখুন
              </a>
            )}
            <div className="flex flex-col gap-2">
              <Button onClick={() => save('publish')} disabled={saving}>
                {saving ? 'হচ্ছে…' : post?.status === 'publish' || post?.status === 'future' ? 'আপডেট প্রকাশ করুন' : 'প্রকাশ করুন'}
              </Button>
              <Button variant="secondary" onClick={() => save('draft')} disabled={saving}>
                ড্রাফট সংরক্ষণ করুন
              </Button>
              {post && (
                <button
                  type="button"
                  onClick={openRevisions}
                  className="flex items-center justify-center gap-1.5 text-[12.5px] font-medium text-neutral-500 hover:text-brand-600 pt-1"
                >
                  <IconHistory className="w-4 h-4" />
                  এডিটোরিয়াল সংস্করণ ইতিহাস
                </button>
              )}
            </div>
          </Card>

          <PublishInfoCard
            kind="post"
            isNew={!post}
            floating={!post || !initialDateInput(post)}
            date={dateInput}
            onDateChange={setDateInput}
            canChangeDate={perms.changeDate}
            author={author}
            onAuthorChange={(a) => setAuthor({ id: a.id, name: a.name, avatar: a.avatar, role: a.role })}
            canChangeAuthor={perms.changeAuthor}
            metaDirty={publishInfoDirty}
            savingMeta={savingPublishInfo}
            onSaveMeta={savePublishInfo}
            canDelete={perms.deletePosts}
            onDeleteClick={() => setDeleteOpen(true)}
            authorExtra={
              perms.changeAuthor || guest ? (
                <GuestAuthorField value={guest} onChange={setGuest} save={saveGuest} onSaveChange={setSaveGuest} disabled={!perms.changeAuthor} />
              ) : null
            }
          />

          {post && (
            <PaymentPanel
              kind="post"
              amount={post.payment}
              status={post.payment_status}
              canEdit={isPublisher}
              onSave={(amount) => AdminPostsApi.setPayment(post.id, amount)}
              onConfirm={() => AdminPostsApi.confirmPayment(post.id)}
              testId="post-payment"
            />
          )}

          <Section title="ক্যাটেগরি ও টপিক" summary={[categoryName || 'ক্যাটেগরি নেই', topic].filter(Boolean).join(' · ')} defaultOpen testId="sec-category">
            <p className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">ক্যাটেগরি</p>
            <div className="mb-3.5">
              <CategoryPicker categories={categories} value={categoryId} onChange={setCategoryId} recentKey={RECENT_CATEGORIES_KEY} />
            </div>
            <div className="flex items-baseline justify-between mb-1.5">
              <label htmlFor="post-topic" className="text-[12.5px] font-medium text-neutral-600">
                টপিক
              </label>
              <Counter value={topic} max={TOPIC_MAX} />
            </div>
            <input
              id="post-topic"
              className={`${inputClass} min-h-[44px]`}
              value={topic}
              maxLength={TOPIC_MAX}
              onChange={(e) => setTopic(e.target.value)}
              placeholder={categoryName ? `খালি থাকলে: ${categoryName}` : 'শিরোনামের উপরে ছোট লেবেল'}
            />
            <p className="text-[11.5px] text-neutral-400 mt-1">শিরোনামের উপরে ছোট লেবেল হিসেবে দেখাবে; খালি থাকলে প্রধান ক্যাটেগরি।</p>
          </Section>

          <Section
            title="ট্যাগ"
            summary={tags.length ? tags.join(', ') : 'কোনো ট্যাগ নেই'}
            defaultOpen={wide}
            testId="sec-tags"
            badge={tags.length ? <span className="text-[11px] font-medium bg-neutral-100 text-neutral-600 rounded-full px-2">{bnNumber(tags.length)}</span> : null}
          >
            <TagInput value={tags} onChange={setTags} />
          </Section>

          <Section title="এলাকা" summary={areaSummary(area)} defaultOpen={wide} testId="sec-area">
            <AreaPicker value={area} onChange={setArea} />
          </Section>

          <Card className="p-5">
            <p className="text-[13px] font-semibold text-neutral-700 mb-3">ফিচার ইমেজ</p>
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
              <>
                <button
                  type="button"
                  onClick={() => featuredInput.current?.click()}
                  className="relative w-full h-36 rounded-xl overflow-hidden border border-neutral-200 mb-3"
                >
                  <img src={featuredImage} alt={featuredAlt} className="w-full h-full object-cover" />
                  {uploadingFeatured ? (
                    <span className="absolute inset-0 bg-black/45 text-white text-[13px] font-medium flex items-center justify-center">
                      {featuredUploadLabel(featuredProgress)}
                    </span>
                  ) : (
                    <span className="absolute bottom-2 right-2 bg-black/60 text-white text-[12px] px-2 py-1 rounded-lg">
                      বদলাতে ক্লিক করুন
                    </span>
                  )}
                </button>
                <Field label="ইমেজ অল্ট টেক্সট">
                  <input
                    className={inputClass}
                    value={featuredAlt}
                    onChange={(e) => setFeaturedAlt(e.target.value)}
                    onBlur={saveFeaturedMeta}
                    placeholder="ছবিতে কী দেখা যাচ্ছে"
                  />
                </Field>
                <Field label="ইমেজ ক্যাপশন">
                  <input
                    className={inputClass}
                    value={featuredCaption}
                    onChange={(e) => setFeaturedCaption(e.target.value)}
                    onBlur={saveFeaturedMeta}
                    placeholder="ছবির ক্যাপশন"
                  />
                </Field>
                {savingMeta && <p className="text-[11.5px] text-neutral-400">সংরক্ষণ হচ্ছে…</p>}
                <button
                  type="button"
                  onClick={() => setFeaturedGalleryOpen(true)}
                  disabled={uploadingFeatured}
                  className="mt-1 w-full flex items-center justify-center gap-1.5 rounded-lg bg-neutral-100 text-neutral-700 py-2 text-[12.5px] font-medium hover:bg-neutral-200"
                >
                  <IconLibraryPhoto className="w-4 h-4" />
                  গ্যালারি থেকে অন্য ছবি বেছে নিন
                </button>
              </>
            ) : (
              <div className="space-y-2">
                <button
                  type="button"
                  onClick={() => featuredInput.current?.click()}
                  disabled={uploadingFeatured}
                  className="w-full flex items-center justify-center gap-2 rounded-xl border border-dashed border-neutral-300 text-neutral-500 py-5 text-[13.5px]"
                >
                  <IconPhotoPlus className="w-5 h-5" />
                  {uploadingFeatured ? featuredUploadLabel(featuredProgress) : 'নতুন ছবি আপলোড করুন'}
                </button>
                <button
                  type="button"
                  onClick={() => setFeaturedGalleryOpen(true)}
                  disabled={uploadingFeatured}
                  className="w-full flex items-center justify-center gap-1.5 rounded-lg bg-neutral-100 text-neutral-700 py-2 text-[12.5px] font-medium hover:bg-neutral-200"
                >
                  <IconLibraryPhoto className="w-4 h-4" />
                  গ্যালারি থেকে বেছে নিন
                </button>
              </div>
            )}
            {featuredGalleryOpen && (
              <MediaGallery
                title="ফিচার ইমেজ — গ্যালারি থেকে বেছে নিন"
                onClose={() => setFeaturedGalleryOpen(false)}
                onSelect={(img) => {
                  setFeaturedGalleryOpen(false)
                  applyFeatured({ attachmentId: img.id })
                }}
              />
            )}
          </Card>
        </div>
      </div>

      {deleteOpen && post && (
        <DeleteConfirmModal kind="post" title={post.title} onConfirm={deletePost} onClose={() => setDeleteOpen(false)} />
      )}

      {revisionsOpen && (
        <Modal title="এডিটোরিয়াল সংস্করণ ইতিহাস" onClose={() => setRevisionsOpen(false)}>
          {revisionsLoading ? (
            <Spinner />
          ) : revisions.length === 0 ? (
            <p className="text-[13px] text-neutral-400 text-center py-6">এখনো কোনো সম্পাদনার ইতিহাস নেই।</p>
          ) : (
            <div className="flex flex-col gap-5 min-w-0">
              {revisions.map((r) => (
                <div key={r.id} className="border border-neutral-100 rounded-xl p-3">
                  <p className="text-[12px] text-neutral-500 mb-2">
                    <span className="font-medium text-neutral-700">{r.author ?? 'অজানা'}</span> সম্পাদনা করেছেন —{' '}
                    {new Date(r.date).toLocaleString('bn-BD')}
                  </p>
                  {r.title_diff && (
                    <div className="mb-2">
                      <p className="text-[11px] font-semibold text-neutral-500 mb-1">শিরোনাম</p>
                      <div className="text-[12px] overflow-x-auto wp-diff" dangerouslySetInnerHTML={{ __html: r.title_diff }} />
                    </div>
                  )}
                  {r.excerpt_diff && (
                    <div className="mb-2">
                      <p className="text-[11px] font-semibold text-neutral-500 mb-1">সংক্ষিপ্তসার</p>
                      <div className="text-[12px] overflow-x-auto wp-diff" dangerouslySetInnerHTML={{ __html: r.excerpt_diff }} />
                    </div>
                  )}
                  {r.content_diff && (
                    <div>
                      <p className="text-[11px] font-semibold text-neutral-500 mb-1">বিস্তারিত</p>
                      <div className="text-[12px] overflow-x-auto wp-diff" dangerouslySetInnerHTML={{ __html: r.content_diff }} />
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </Modal>
      )}
    </div>
  )
}
