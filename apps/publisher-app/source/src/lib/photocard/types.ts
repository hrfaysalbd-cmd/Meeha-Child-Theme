/**
 * Round 24 — the twelve card types the theme's renderer knows, with the
 * form each one needs (the same fields as wp-admin › ফটোকার্ড › স্টুডিও) and
 * how a filled form becomes the renderer's `data`.
 */
import type { CardDates, CardSource, StudioConfig } from '../api'

export type CardType =
  | 'news' | 'feature' | 'category' | 'breaking' | 'opinion' | 'quote'
  | 'literature' | 'poem' | 'morning' | 'day' | 'birthday' | 'condolence'

export const TYPE_ORDER: CardType[] = [
  'news', 'breaking', 'feature', 'category', 'opinion', 'quote', 'literature', 'poem', 'morning', 'day', 'birthday', 'condolence',
]

/** One line under the type's name in the type picker. */
export const TYPE_HINT: Record<CardType, string> = {
  news: 'খবরের ছবি + শিরোনাম',
  breaking: 'লাল/নীল জরুরি কার্ড',
  feature: 'বড় ছবির ফিচার',
  category: 'বিভাগের রঙে',
  opinion: 'লম্বা লেখা, লেখকের ছবি',
  quote: 'বক্তার উক্তি',
  literature: 'গল্প, প্রবন্ধ',
  poem: 'কবিতার পঙ্‌ক্তি',
  morning: 'আজকের তারিখসহ',
  day: 'জাতীয় ও বিশেষ দিবস',
  birthday: 'গোল ছবি, শুভেচ্ছা',
  condolence: 'সাদা-কালো, বিজ্ঞাপন ছাড়া',
}

/** Types whose text comes from a news post (a picked post fills them). */
export const POST_TYPES: CardType[] = ['news', 'breaking', 'feature', 'category', 'opinion', 'literature', 'poem']
/** Types with a CTA pill ("বিস্তারিত কমেন্টে"). */
export const CTA_TYPES: CardType[] = ['news', 'opinion', 'feature', 'breaking', 'category']

export type FieldKind = 'text' | 'textarea' | 'select' | 'check'

export interface FieldDef {
  k: string
  label: string
  kind: FieldKind
  rows?: number
  max?: number
  hint?: string
  /** "** … **" highlight button under the box. */
  mark?: boolean
  placeholder?: string
  half?: boolean
}

const TITLE = (hint: string, rows = 3): FieldDef => ({ k: 'title', label: 'শিরোনাম', kind: 'textarea', rows, hint })

export const FIELDS: Record<CardType, FieldDef[]> = {
  news: [
    TITLE('লম্বা হলে লেখা নিজে ছোট হয়ে ৩–৪ লাইনে বসে।'),
    { k: 'category', label: 'বিভাগ', kind: 'text', max: 40, half: true },
    { k: 'date', label: 'তারিখ', kind: 'text', max: 40, half: true },
  ],
  breaking: [
    { k: 'time', label: 'সময়', kind: 'text', max: 20 },
    TITLE('ছবি না দিলে শিরোনাম বড় হয়ে পুরো কার্ডে বসে।'),
  ],
  feature: [
    { k: 'kicker', label: 'কিকার', kind: 'text', max: 40 },
    TITLE('বড় অক্ষরে ছবির ওপর বসে।'),
  ],
  category: [
    { k: 'category_id', label: 'বিভাগ', kind: 'select', hint: 'বিভাগের নাম লেবেল হয়, বিভাগের রং কার্ডের রং হয়।' },
    TITLE('বড় অক্ষরে ছবির ওপর বসে।'),
  ],
  opinion: [
    { k: 'title', label: 'লেখা / শিরোনাম', kind: 'textarea', rows: 5, mark: true, hint: 'পুরো একটি অনুচ্ছেদও দেওয়া যায় — লম্বা হলে পড়ার মাপে ছোট হয়ে বসে।' },
    { k: 'author_name', label: 'লেখকের নাম', kind: 'text', half: true },
    { k: 'author_title', label: 'উপাধি', kind: 'text', half: true },
    { k: 'show_photo', label: 'লেখকের ছবি দেখান', kind: 'check' },
    { k: 'label', label: 'লেবেল', kind: 'text', max: 30 },
  ],
  quote: [
    { k: 'text', label: 'উক্তি', kind: 'textarea', rows: 4, max: 400, mark: true },
    { k: 'speaker', label: 'বক্তা', kind: 'text', half: true },
    { k: 'role', label: 'পরিচয়/উপাধি', kind: 'text', half: true },
    { k: 'type_label', label: 'ধরনের লেবেল', kind: 'text', max: 30, half: true },
    { k: 'year', label: 'সাল (ইতিহাস ফ্রেমে জলছাপ)', kind: 'text', max: 8, half: true },
    { k: 'show_speaker', label: 'বক্তার ছবি দেখান', kind: 'check' },
  ],
  literature: [
    { k: 'label', label: 'লেবেল (ধরন)', kind: 'text', max: 30, placeholder: 'গল্প, প্রবন্ধ…', half: true },
    { k: 'author', label: 'লেখক', kind: 'text', half: true },
    TITLE('', 2),
    { k: 'excerpt', label: 'উদ্ধৃতি / অংশবিশেষ', kind: 'textarea', rows: 5, mark: true, hint: 'লেখার সবচেয়ে সুন্দর দুই-চার লাইন।' },
  ],
  poem: [
    { k: 'label', label: 'লেবেল', kind: 'text', max: 30, half: true },
    { k: 'author', label: 'কবি', kind: 'text', half: true },
    { k: 'title', label: 'কবিতার নাম', kind: 'text' },
    { k: 'poem', label: 'কবিতার পঙ্‌ক্তি', kind: 'textarea', rows: 8, hint: 'প্রতি লাইনে একটি পঙ্‌ক্তি; ফাঁকা লাইন = স্তবকের বিরতি।' },
  ],
  morning: [
    { k: 'title', label: 'শিরোনাম', kind: 'text' },
    { k: 'caption', label: 'ক্যাপশন', kind: 'text', max: 160 },
    { k: 'credit', label: 'ছবি: (আলোকচিত্রী)', kind: 'text', max: 60 },
  ],
  day: [
    { k: 'day_pick', label: 'দিবস বাছুন', kind: 'select', hint: 'তালিকা বদলানো যায়: মিহা থিম › ফটোকার্ড › দিবসের তালিকা।' },
    { k: 'title', label: 'শিরোনাম', kind: 'text', max: 80 },
    { k: 'subtitle', label: 'উপশিরোনাম / তারিখ', kind: 'text', max: 40 },
    { k: 'message', label: 'বার্তা', kind: 'textarea', rows: 2 },
  ],
  birthday: [
    { k: 'heading', label: 'শিরোনাম', kind: 'text', max: 40 },
    { k: 'name', label: 'নাম', kind: 'text', half: true },
    { k: 'role', label: 'পরিচয়', kind: 'text', half: true },
    { k: 'message', label: 'শুভেচ্ছা বার্তা', kind: 'textarea', rows: 2 },
  ],
  condolence: [
    { k: 'pre', label: 'প্রথম লাইন', kind: 'select' },
    { k: 'heading', label: 'শিরোনাম', kind: 'text', max: 40 },
    { k: 'name', label: 'নাম', kind: 'text', half: true },
    { k: 'role', label: 'পরিচয়', kind: 'text', half: true },
    { k: 'born', label: 'জন্ম', kind: 'text', placeholder: '১৯৪৮', half: true },
    { k: 'died', label: 'মৃত্যু', kind: 'text', half: true },
    { k: 'message', label: 'বার্তা', kind: 'textarea', rows: 2, hint: 'সর্বোচ্চ ২ লাইন। শোক কার্ডে বিজ্ঞাপন বসে না।' },
  ],
}

export type FieldValues = Record<string, string | boolean>

const bnDigits = (s: string) => s.replace(/[0-9]/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])

/** Starting values of every type's form (theme settings first, then the theme's own sample words). */
export function initialFields(cfg: StudioConfig | null): Record<CardType, FieldValues> {
  const d = cfg?.defaults || {}
  const greg = cfg?.dates?.gregorian || ''
  const day0 = cfg?.days?.[0]
  return {
    news: { title: '', category: '', date: greg },
    breaking: { time: bnDigits(cfg?.now || ''), title: '' },
    feature: { kicker: d.feature_kicker || 'মিহা স্পেশাল', title: '' },
    category: { category_id: cfg?.categories?.[0] ? String(cfg.categories[0].id) : '', title: '' },
    opinion: { title: '', author_name: '', author_title: '', show_photo: true, label: d.opinion_label || 'মতামত' },
    quote: { text: '', speaker: '', role: '', type_label: '', year: '', show_speaker: true },
    literature: { label: cfg?.litLabel || 'সাহিত্য', author: '', title: '', excerpt: '' },
    poem: { label: 'কবিতা', author: '', title: '', poem: '' },
    morning: { title: cfg?.titles?.[0] || 'শুভ সকাল', caption: '', credit: d.credit || 'মিহা নিউজ' },
    day: { day_pick: '0', title: day0?.title || '', subtitle: day0?.subtitle || '', message: day0?.message || '' },
    birthday: { heading: d.birthday_heading || 'শুভ জন্মদিন', name: '', role: '', message: d.birthday_message || '' },
    condolence: { pre: cfg?.condPre?.[0] || '', heading: d.condolence_heading || 'গভীর শোক', name: '', role: '', born: '', died: greg, message: d.condolence_message || '' },
  }
}

/** Fills the forms a post can fill (title, author, category, …). */
export function fieldsFromSource(prev: Record<CardType, FieldValues>, r: CardSource): Record<CardType, FieldValues> {
  const next = { ...prev }
  const date = r.date_short || r.date
  next.news = { ...prev.news, title: r.title, category: r.category || r.kicker, date }
  next.breaking = { ...prev.breaking, title: r.title, time: r.time || prev.breaking.time }
  next.feature = { ...prev.feature, title: r.title, kicker: r.kicker || r.category || prev.feature.kicker }
  next.category = { ...prev.category, title: r.title, category_id: r.category_id ? String(r.category_id) : prev.category.category_id }
  next.opinion = { ...prev.opinion, title: r.title, author_name: r.author_name, author_title: r.author_title }
  const label = r.lit_label || ''
  next.literature = {
    ...prev.literature,
    title: r.title,
    author: r.lit_writer || r.author_name,
    label: label && r.lit_genre !== 'poem' ? label : prev.literature.label,
    excerpt: r.lit_excerpt || r.summary || r.excerpt || '',
  }
  next.poem = {
    ...prev.poem,
    title: r.title,
    author: r.lit_writer || r.author_name,
    label: r.lit_genre === 'poem' && label ? label : prev.poem.label,
    poem: r.poem_lines?.length ? r.poem_lines.join('\n') : prev.poem.poem,
  }
  if (r.quote) {
    next.quote = { ...prev.quote, text: r.quote.text, speaker: r.quote.speaker, role: r.quote.role, type_label: r.quote.type_label, year: r.quote.year }
  }
  return next
}

const s = (v: string | boolean | undefined) => (typeof v === 'string' ? v : '')

export interface PhotoInput {
  image: string
  focus: { x: number; y: number }
  zoom: number
}

/** The renderer's `data` for a type. */
export function buildData(
  type: CardType,
  f: FieldValues,
  ctx: {
    photo: PhotoInput | null
    gallery: PhotoInput[]
    galleryMore: number
    cfg: StudioConfig | null
    dates: CardDates
    dayTheme: string
    breakingVariant: string
    quoteFrame: string
    authorPhoto: string
    speakerPhoto: string
    cta: string | null
    storyCta: string | null
  },
): Record<string, unknown> {
  const p = ctx.photo
  const ph = p ? { image: p.image, focus: p.focus, zoom: p.zoom } : { image: '', focus: { x: 50, y: 50 }, zoom: 1 }
  let d: Record<string, unknown>
  switch (type) {
    case 'news':
      d = { ...ph, title: s(f.title), category: s(f.category), date: s(f.date), gallery: ctx.gallery, gallery_more: ctx.galleryMore }
      break
    case 'breaking':
      d = { ...ph, variant: ctx.breakingVariant, time: s(f.time), title: s(f.title) }
      break
    case 'feature':
      d = { ...ph, kicker: s(f.kicker), title: s(f.title) }
      break
    case 'category': {
      const cat = ctx.cfg?.categories.find((c) => String(c.id) === s(f.category_id))
      d = { ...ph, category: cat?.name || '', color: cat?.color || '', title: s(f.title) }
      break
    }
    case 'opinion':
      d = { title: s(f.title), author_name: s(f.author_name), author_title: s(f.author_title), author_photo: ctx.authorPhoto, show_photo: !!f.show_photo, label: s(f.label) }
      break
    case 'quote':
      d = { text: s(f.text), speaker: s(f.speaker), role: s(f.role), type_label: s(f.type_label), year: s(f.year), frame: ctx.quoteFrame, speaker_photo: f.show_speaker ? ctx.speakerPhoto : '' }
      break
    case 'literature':
      d = { ...ph, label: s(f.label), title: s(f.title), author: s(f.author), excerpt: s(f.excerpt) }
      break
    case 'poem':
      d = { ...ph, label: s(f.label), title: s(f.title), author: s(f.author), poem: s(f.poem) }
      break
    case 'morning':
      d = { ...ph, title: s(f.title), caption: s(f.caption), credit: s(f.credit), dates: ctx.dates }
      break
    case 'day':
      d = { ...ph, title: s(f.title), subtitle: s(f.subtitle), message: s(f.message), theme: ctx.dayTheme }
      break
    case 'birthday':
      d = { ...ph, heading: s(f.heading), name: s(f.name), role: s(f.role), message: s(f.message) }
      break
    case 'condolence': {
      const dl = [s(f.born) ? `জন্ম: ${s(f.born)}` : '', s(f.died) ? `মৃত্যু: ${s(f.died)}` : ''].filter(Boolean).join(' | ')
      d = { ...ph, pre: s(f.pre), heading: s(f.heading), name: s(f.name), role: s(f.role), dates_line: dl, message: s(f.message) }
      break
    }
  }
  if (CTA_TYPES.includes(type)) d.cta = ctx.cta ?? ''
  if (ctx.storyCta !== null) d.story_cta = ctx.storyCta
  return d
}

/** The words a card is named and captioned by. */
export function cardTitle(type: CardType, f: FieldValues) {
  return s(f.title) || s(f.text) || s(f.name) || s(f.caption) || s(f.heading) || (type === 'day' ? 'দিবস' : 'ফটোকার্ড')
}

export const SIZE_LABELS: Record<string, { label: string; hint: string; ratio: number }> = {
  '1080x1080': { label: 'বর্গ', hint: 'ফেসবুক/ইনস্টাগ্রাম পোস্ট', ratio: 1 },
  '1080x1350': { label: 'পোর্ট্রেট', hint: 'ফিডে সবচেয়ে বড় দেখায়', ratio: 1350 / 1080 },
  '1200x630': { label: 'লিংক', hint: 'লিংক প্রিভিউ, টুইটার', ratio: 630 / 1200 },
  '1080x1920': { label: 'স্টোরি', hint: 'স্টোরি, রিলস, স্ট্যাটাস', ratio: 1920 / 1080 },
}
