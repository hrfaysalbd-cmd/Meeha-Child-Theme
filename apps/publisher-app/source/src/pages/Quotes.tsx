/**
 * Round 19 — "উক্তি": every quote on the site (the theme's উক্তি section),
 * searchable by text or speaker, filterable by type and status, plus adding
 * and editing a quote with the same fields as wp-admin: the quote, speaker,
 * পরিচয়, type (each type has its card frame), speaker photo, source, year,
 * context and the agree/disagree poll. A new type can be added right from the
 * form. Deleting needs the same right as deleting an article.
 */
import { useEffect, useMemo, useRef, useState } from 'react'
import {
  MediaApi,
  QUOTE_TEXT_MAX,
  QuotesApi,
  describeError,
  type Quote,
  type QuoteFrame,
  type QuoteFrameInfo,
  type QuoteInput,
  type QuoteStatus,
  type QuoteType,
} from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import MediaGallery from '../components/MediaGallery'
import { Badge, Banner, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, bnDate, bnNumber, inputClass } from '../components/ui'
import { IconExternalLink, IconLibraryPhoto, IconPhotoPlus, IconPlus, IconQuote, IconSearch, IconX } from '../components/Icon'

const SEARCH_DEBOUNCE_MS = 350

const EMPTY: QuoteInput = {
  text: '',
  speaker: '',
  role: '',
  source: '',
  source_year: '',
  year: '',
  context: '',
  type_id: 0,
  photo_id: 0,
  status: 'publish',
  poll: { enabled: false, option_a: '', option_b: '', days: 0 },
}

function toInput(q: Quote): QuoteInput {
  return {
    text: q.text,
    speaker: q.speaker,
    role: q.role,
    source: q.source,
    source_year: q.source_year,
    year: q.year,
    context: q.context,
    type_id: q.type_id,
    photo_id: q.photo_id,
    status: q.status === 'draft' ? 'draft' : 'publish',
    poll: { enabled: q.poll.enabled, option_a: q.poll.option_a, option_b: q.poll.option_b, days: q.poll.days },
  }
}

export default function Quotes() {
  const { profile } = useAuth()
  const canDelete = !!profile?.can.delete_posts

  const [items, setItems] = useState<Quote[]>([])
  const [total, setTotal] = useState(0)
  const [pages, setPages] = useState(1)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [loadingMore, setLoadingMore] = useState(false)
  const [error, setError] = useState('')

  const [types, setTypes] = useState<QuoteType[]>([])
  const [frames, setFrames] = useState<QuoteFrameInfo[]>([])

  const [query, setQuery] = useState('')
  const [search, setSearch] = useState('')
  const [typeFilter, setTypeFilter] = useState(0)
  const [status, setStatus] = useState<'' | QuoteStatus>('')

  const [editing, setEditing] = useState<Quote | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const [notice, setNotice] = useState('')
  const seq = useRef(0)

  const swatch = useMemo(() => {
    const m: Record<string, string> = {}
    frames.forEach((f) => (m[f.id] = f.swatch))
    return m
  }, [frames])

  function loadTypes() {
    return QuotesApi.types()
      .then((r) => {
        setTypes(r.types)
        setFrames(r.frames)
      })
      .catch(() => {})
  }

  async function load(p = 1) {
    const mine = ++seq.current
    if (p === 1) setLoading(true)
    else setLoadingMore(true)
    setError('')
    try {
      const r = await QuotesApi.list({ search, type: typeFilter, status, page: p })
      if (mine !== seq.current) return
      setItems((prev) => (p === 1 ? r.items : [...prev, ...r.items]))
      setTotal(r.total)
      setPages(r.pages)
      setPage(p)
    } catch (e) {
      if (mine === seq.current) setError(describeError(e).message)
    } finally {
      if (mine === seq.current) {
        setLoading(false)
        setLoadingMore(false)
      }
    }
  }

  useEffect(() => {
    loadTypes()
  }, [])

  useEffect(() => {
    const t = setTimeout(() => setSearch(query.trim()), SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(t)
  }, [query])

  useEffect(() => {
    load(1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, typeFilter, status])

  function openCreate() {
    setEditing(null)
    setFormOpen(true)
  }

  function onSaved(q: Quote, created: boolean) {
    setFormOpen(false)
    setNotice(created ? 'উক্তি যোগ হয়েছে।' : 'উক্তি হালনাগাদ হয়েছে।')
    if (created) {
      load(1)
    } else {
      setItems((prev) => prev.map((x) => (x.id === q.id ? q : x)))
    }
    loadTypes()
  }

  async function remove(q: Quote) {
    if (!window.confirm('এই উক্তিটি মুছে ফেলবেন? (ওয়ার্ডপ্রেসের ট্র্যাশে যাবে, সেখান থেকে ফেরানো যায়)')) return
    try {
      await QuotesApi.remove(q.id)
      setItems((prev) => prev.filter((x) => x.id !== q.id))
      setTotal((t) => Math.max(0, t - 1))
      setNotice('উক্তিটি ট্র্যাশে পাঠানো হয়েছে।')
    } catch (e) {
      setError(describeError(e).message)
    }
  }

  return (
    <div>
      <PageHeader
        title="উক্তি"
        subtitle="সাইটের সব উক্তি — খুঁজুন, সম্পাদনা করুন বা নতুন উক্তি যোগ করুন"
        actions={
          <Button onClick={openCreate}>
            <IconPlus className="w-4 h-4" /> নতুন উক্তি
          </Button>
        }
      />
      {error && <Banner kind="error">{error}</Banner>}
      {notice && (
        <div onClick={() => setNotice('')}>
          <Banner kind="success">{notice}</Banner>
        </div>
      )}

      <div className="flex flex-col md:flex-row gap-2 mb-4">
        <div className="relative flex-1">
          <IconSearch className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" />
          <input
            className={`${inputClass} pl-9 min-h-[42px]`}
            placeholder="উক্তি বা বক্তার নাম দিয়ে খুঁজুন"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            data-testid="quote-search"
          />
        </div>
        <select
          className={`${inputClass} md:w-56 min-h-[42px]`}
          value={typeFilter}
          onChange={(e) => setTypeFilter(Number(e.target.value))}
          data-testid="quote-type-filter"
        >
          <option value={0}>সব ধরন</option>
          {types.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name} ({bnNumber(t.count)})
            </option>
          ))}
        </select>
        <div className="flex gap-1.5">
          {(['', 'publish', 'draft'] as const).map((s) => (
            <button
              key={s || 'all'}
              onClick={() => setStatus(s)}
              className={`px-3 min-h-[42px] rounded-lg text-[12.5px] font-medium ${
                status === s ? 'bg-brand-600 text-white' : 'bg-white border border-neutral-200 text-neutral-600'
              }`}
            >
              {s === '' ? 'সব' : s === 'publish' ? 'প্রকাশিত' : 'খসড়া'}
            </button>
          ))}
        </div>
      </div>

      {loading && items.length === 0 ? (
        <Spinner className="mt-10" />
      ) : items.length === 0 ? (
        <Card>
          <EmptyState>{search || typeFilter || status ? 'এই খোঁজে কোনো উক্তি পাওয়া যায়নি।' : 'এখনো কোনো উক্তি নেই — "নতুন উক্তি" দিয়ে শুরু করুন।'}</EmptyState>
        </Card>
      ) : (
        <>
          <p className="text-[12.5px] text-neutral-500 mb-2">মোট {bnNumber(total)} টি উক্তি</p>
          <div className={`grid gap-3 transition-opacity ${loading ? 'opacity-50' : ''}`} data-testid="quote-list" aria-busy={loading}>
            {items.map((q) => (
              <Card key={q.id} className="p-0 overflow-hidden">
                <div className="flex">
                  <div className="w-1.5 shrink-0" style={{ background: swatch[q.frame] || '#025AB2' }} aria-hidden="true" />
                  <div className="flex-1 min-w-0 p-4 flex gap-3">
                    {q.photo_url ? (
                      <img src={q.photo_url} alt="" className="w-12 h-12 rounded-full object-cover shrink-0 bg-neutral-100" />
                    ) : (
                      <div className="w-12 h-12 rounded-full bg-neutral-100 text-neutral-400 flex items-center justify-center shrink-0">
                        <IconQuote className="w-5 h-5" />
                      </div>
                    )}
                    <div className="flex-1 min-w-0">
                      <p className="text-[14.5px] leading-relaxed text-neutral-900 line-clamp-3">“{q.text}”</p>
                      {(q.speaker || q.role) && (
                        <p className="text-[12.5px] text-neutral-600 mt-1">
                          — {q.speaker}
                          {q.role ? `, ${q.role}` : ''}
                        </p>
                      )}
                      <div className="flex flex-wrap items-center gap-1.5 mt-2">
                        {q.type_name && <Badge tone="blue">{q.type_name}</Badge>}
                        {q.status === 'publish' ? <Badge tone="emerald">প্রকাশিত</Badge> : <Badge tone="amber">খসড়া</Badge>}
                        {q.poll.enabled && <Badge tone="neutral">ভোট{typeof q.poll.total === 'number' ? ` · ${bnNumber(q.poll.total)}` : ''}</Badge>}
                        <span className="text-[11.5px] text-neutral-400">{bnDate(q.date)}{q.author ? ` · ${q.author}` : ''}</span>
                      </div>
                      <div className="flex flex-wrap gap-2 mt-3">
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => {
                            setEditing(q)
                            setFormOpen(true)
                          }}
                        >
                          সম্পাদনা
                        </Button>
                        {q.url && (
                          <a
                            href={q.url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[12.5px] font-semibold text-brand-600 hover:bg-brand-50"
                          >
                            <IconExternalLink className="w-3.5 h-3.5" /> সাইটে দেখুন
                          </a>
                        )}
                        {canDelete && (
                          <Button size="sm" variant="danger" onClick={() => remove(q)}>
                            মুছুন
                          </Button>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              </Card>
            ))}
          </div>
          {page < pages && (
            <div className="flex justify-center mt-4">
              <Button variant="secondary" disabled={loadingMore} onClick={() => load(page + 1)}>
                {loadingMore ? 'লোড হচ্ছে…' : 'আরও দেখুন'}
              </Button>
            </div>
          )}
        </>
      )}

      {formOpen && (
        <QuoteForm
          quote={editing}
          types={types}
          frames={frames}
          onTypeCreated={(t) => setTypes((prev) => [...prev, t].sort((a, b) => a.name.localeCompare(b.name, 'bn')))}
          onClose={() => setFormOpen(false)}
          onSaved={onSaved}
        />
      )}
    </div>
  )
}

function QuoteForm({
  quote,
  types,
  frames,
  onTypeCreated,
  onClose,
  onSaved,
}: {
  quote: Quote | null
  types: QuoteType[]
  frames: QuoteFrameInfo[]
  onTypeCreated: (t: QuoteType) => void
  onClose: () => void
  onSaved: (q: Quote, created: boolean) => void
}) {
  const [v, setV] = useState<QuoteInput>(() => (quote ? toInput(quote) : { ...EMPTY, type_id: types.find((t) => t.frame === 'opinion')?.id || types[0]?.id || 0 }))
  const [photoUrl, setPhotoUrl] = useState(quote?.photo_url || '')
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')
  const [uploading, setUploading] = useState(false)
  const [galleryOpen, setGalleryOpen] = useState(false)
  const [newType, setNewType] = useState<{ name: string; frame: QuoteFrame } | null>(null)
  const [typeSaving, setTypeSaving] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)

  const set = (patch: Partial<QuoteInput>) => setV((prev) => ({ ...prev, ...patch }))
  const setPoll = (patch: Partial<QuoteInput['poll']>) => setV((prev) => ({ ...prev, poll: { ...prev.poll, ...patch } }))
  const chosen = types.find((t) => t.id === v.type_id)
  const len = v.text.trim().length

  async function upload(file: File) {
    setUploading(true)
    setFormError('')
    try {
      const res = await MediaApi.upload(file, { alt: v.speaker })
      set({ photo_id: res.id })
      setPhotoUrl(res.url)
    } catch (e) {
      setFormError(describeError(e).message)
    } finally {
      setUploading(false)
    }
  }

  async function addType() {
    if (!newType || !newType.name.trim()) return
    setTypeSaving(true)
    setFormError('')
    try {
      const t = await QuotesApi.createType(newType.name.trim(), newType.frame)
      onTypeCreated(t)
      set({ type_id: t.id })
      setNewType(null)
    } catch (e) {
      setFormError(describeError(e).message)
    } finally {
      setTypeSaving(false)
    }
  }

  async function submit(status: QuoteStatus) {
    setFormError('')
    if (!v.text.trim()) {
      setFormError('উক্তিটি লিখুন।')
      return
    }
    if (len > QUOTE_TEXT_MAX) {
      setFormError(`উক্তি সর্বোচ্চ ${bnNumber(QUOTE_TEXT_MAX)} অক্ষরের হতে পারে।`)
      return
    }
    if (v.poll.enabled && (!v.poll.option_a.trim() || !v.poll.option_b.trim())) {
      setFormError('ভোট চালু করলে দুটি অপশনই লিখুন (যেমন: একমত / দ্বিমত)।')
      return
    }
    setSaving(true)
    try {
      const payload = { ...v, status }
      const q = quote ? await QuotesApi.update(quote.id, payload) : await QuotesApi.create(payload)
      onSaved(q, !quote)
    } catch (err) {
      setFormError(describeError(err).message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal title={quote ? 'উক্তি সম্পাদনা' : 'নতুন উক্তি'} onClose={onClose}>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          void submit('publish')
        }}
        data-testid="quote-form"
      >
        {formError && <Banner kind="error">{formError}</Banner>}

        <label className="block mb-3.5">
          <span className="flex items-baseline justify-between text-[12.5px] font-medium text-neutral-600 mb-1.5">
            <span>
              উক্তি <span className="text-brand-600">*</span>
            </span>
            <span className={`text-[11px] ${len > QUOTE_TEXT_MAX ? 'text-red-600' : 'text-neutral-400'}`}>
              {bnNumber(len)}/{bnNumber(QUOTE_TEXT_MAX)}
            </span>
          </span>
          <textarea
            className={`${inputClass} text-[15px] leading-relaxed`}
            rows={4}
            value={v.text}
            onChange={(e) => set({ text: e.target.value })}
            placeholder="উক্তিটি এখানে লিখুন…"
            data-testid="quote-text"
            autoFocus
          />
          <span className="block text-[11px] text-neutral-400 mt-1">৯০ অক্ষরের মধ্যে হলে কার্ডে সবচেয়ে ভালো দেখায়; বড় উক্তিও চলবে।</span>
        </label>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-3">
          <Field label="বক্তা">
            <input className={inputClass} value={v.speaker} onChange={(e) => set({ speaker: e.target.value })} placeholder="যেমন: কাজী নজরুল ইসলাম" data-testid="quote-speaker" />
          </Field>
          <Field label="পরিচয় / উপাধি">
            <input className={inputClass} value={v.role} onChange={(e) => set({ role: e.target.value })} placeholder="যেমন: জাতীয় কবি" />
          </Field>
        </div>

        <div className="mb-3.5">
          <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">উক্তির ধরন (কার্ডের ফ্রেম)</span>
          <div className="flex gap-2">
            <select className={inputClass} value={v.type_id} onChange={(e) => set({ type_id: Number(e.target.value) })} data-testid="quote-type">
              <option value={0}>— ধরন বাছুন —</option>
              {types.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
            {chosen && (
              <span
                className="w-10 shrink-0 rounded-lg border border-black/10"
                style={{ background: frames.find((f) => f.id === chosen.frame)?.swatch }}
                title={frames.find((f) => f.id === chosen.frame)?.label}
                aria-hidden="true"
              />
            )}
          </div>
          {!newType ? (
            <button type="button" onClick={() => setNewType({ name: '', frame: 'opinion' })} className="mt-1.5 flex items-center gap-1 text-[12px] font-medium text-brand-600">
              <IconPlus className="w-3.5 h-3.5" /> নতুন ধরন যোগ করুন
            </button>
          ) : (
            <div className="mt-2 rounded-xl border border-neutral-100 p-3">
              <input
                className={`${inputClass} mb-2`}
                placeholder="ধরনের নাম (যেমন: বিজ্ঞান)"
                value={newType.name}
                onChange={(e) => setNewType({ ...newType, name: e.target.value })}
                data-testid="quote-newtype-name"
              />
              <p className="text-[12px] text-neutral-500 mb-1.5">কোন ফ্রেমে দেখাবে</p>
              <div className="flex flex-wrap gap-1.5 mb-2">
                {frames.map((f) => (
                  <button
                    type="button"
                    key={f.id}
                    onClick={() => setNewType({ ...newType, frame: f.id })}
                    className={`flex items-center gap-1.5 px-2 py-1 rounded-lg border text-[12px] ${newType.frame === f.id ? 'border-brand-600 ring-2 ring-brand-600/20' : 'border-neutral-200'}`}
                  >
                    <span className="w-4 h-4 rounded border border-black/10" style={{ background: f.swatch }} />
                    {f.label}
                  </button>
                ))}
              </div>
              <div className="flex gap-2">
                <Button size="sm" onClick={addType} disabled={typeSaving || !newType.name.trim()}>
                  {typeSaving ? 'যোগ হচ্ছে…' : 'ধরন যোগ করুন'}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setNewType(null)}>
                  বাতিল
                </Button>
              </div>
            </div>
          )}
        </div>

        <div className="mb-3.5">
          <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">বক্তার ছবি (ঐচ্ছিক)</span>
          <input
            ref={fileRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            onChange={(e) => {
              const f = e.target.files?.[0]
              if (f) upload(f)
              e.target.value = ''
            }}
          />
          <div className="flex items-center gap-2.5 flex-wrap">
            {photoUrl ? (
              <img src={photoUrl} alt="" className="w-12 h-12 rounded-full object-cover bg-neutral-100" />
            ) : (
              <div className="w-12 h-12 rounded-full bg-neutral-100 text-neutral-400 flex items-center justify-center">
                <IconQuote className="w-5 h-5" />
              </div>
            )}
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
                onClick={() => {
                  set({ photo_id: 0 })
                  setPhotoUrl('')
                }}
                className="inline-flex items-center gap-1 rounded-lg px-2 min-h-[40px] text-[12.5px] text-neutral-500 hover:text-red-600"
              >
                <IconX className="w-4 h-4" /> সরান
              </button>
            )}
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-3">
          <Field label="উৎস (বই, ভাষণ, সাক্ষাৎকার…)">
            <input className={inputClass} value={v.source} onChange={(e) => set({ source: e.target.value })} />
          </Field>
          <Field label="উৎসের সাল">
            <input className={inputClass} inputMode="numeric" value={v.source_year} onChange={(e) => set({ source_year: e.target.value })} />
          </Field>
        </div>
        {chosen?.frame === 'history' && (
          <Field label="বছর (ইতিহাস ফ্রেমের জলছাপ)">
            <input className={inputClass} inputMode="numeric" value={v.year} onChange={(e) => set({ year: e.target.value })} placeholder="যেমন ১৯৫২" />
          </Field>
        )}
        <Field label="প্রসঙ্গ (সংক্ষেপে)">
          <textarea className={inputClass} rows={2} value={v.context} onChange={(e) => set({ context: e.target.value })} />
        </Field>

        <div className="rounded-xl border border-neutral-100 p-3 mb-4">
          <label className="flex items-center gap-2 text-[13px] font-medium text-neutral-700">
            <input type="checkbox" className="w-[18px] h-[18px] accent-brand-600" checked={v.poll.enabled} onChange={(e) => setPoll({ enabled: e.target.checked })} />
            এই উক্তিতে ভোট চালু করুন
          </label>
          {v.poll.enabled && (
            <div className="grid grid-cols-2 gap-2 mt-2.5">
              <input className={inputClass} placeholder="অপশন ক (যেমন: একমত)" value={v.poll.option_a} onChange={(e) => setPoll({ option_a: e.target.value })} />
              <input className={inputClass} placeholder="অপশন খ (যেমন: দ্বিমত)" value={v.poll.option_b} onChange={(e) => setPoll({ option_b: e.target.value })} />
              <input
                className={`${inputClass} col-span-2`}
                type="number"
                min={0}
                max={365}
                placeholder="মেয়াদ (দিন) — খালি রাখলে থিমের ডিফল্ট"
                value={v.poll.days || ''}
                onChange={(e) => setPoll({ days: Math.max(0, Math.min(365, Number(e.target.value) || 0)) })}
              />
              {quote?.poll.enabled && typeof quote.poll.total === 'number' && (
                <p className="col-span-2 text-[11.5px] text-neutral-500">এখন পর্যন্ত মোট ভোট: {bnNumber(quote.poll.total)}</p>
              )}
            </div>
          )}
        </div>

        <div className="flex gap-2">
          <Button type="submit" disabled={saving || uploading} className="flex-1">
            {saving ? 'সংরক্ষণ হচ্ছে…' : quote?.status === 'publish' ? 'হালনাগাদ করুন' : 'প্রকাশ করুন'}
          </Button>
          <Button type="button" variant="secondary" disabled={saving || uploading} onClick={() => void submit('draft')}>
            খসড়া রাখুন
          </Button>
        </div>
      </form>

      {galleryOpen && (
        <MediaGallery
          title="বক্তার ছবি — গ্যালারি থেকে বেছে নিন"
          onClose={() => setGalleryOpen(false)}
          onSelect={(img) => {
            setGalleryOpen(false)
            set({ photo_id: img.id })
            setPhotoUrl(img.thumb || img.url)
          }}
        />
      )}
    </Modal>
  )
}
