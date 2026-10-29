/**
 * Round 24 — ফটোকার্ড স্টুডিও in the publisher app.
 *
 * The same studio as wp-admin › ফটোকার্ড (same renderer, same designs, same
 * settings, frames and ad strips — newsroom-os 1.8.0 bridges the theme's
 * own functions), rebuilt for speed in the newsroom:
 *   - pick a post → title, category, date, author fill in, and ALL of the
 *     post's photos (featured + the ones inside the article) line up;
 *   - the photo's position / zoom / tone belongs to the photo, so every
 *     design and size crops it the same way; স্মার্ট ফোকাস finds the subject;
 *     the position can be kept for the site's own "ফটোকার্ড ডাউনলোড";
 *   - every design is a live thumbnail with the real photo and headline;
 *   - one click for all sizes, or one card per photo of the post (ZIP);
 *   - copy the image / caption, keep the card in the media library.
 *
 * Deep link: /app/photocard?post=123[&type=opinion]
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  PhotocardApi,
  describeError,
  type CardDates,
  type CardFrame,
  type CardQuote,
  type CardSource,
  type LibraryImage,
  type PhotocardKit,
  type StudioConfig,
} from '../lib/api'
import { loadCards, type MeehaCardsApi } from '../lib/photocard/cards'
import { imageById, imageByUrl, imageFromFile, loadImageElement } from '../lib/photocard/images'
import { applyLook, NO_LOOK } from '../lib/photocard/look'
import { smartFocus } from '../lib/photocard/smartFocus'
import {
  CTA_TYPES,
  FIELDS,
  POST_TYPES,
  SIZE_LABELS,
  TYPE_HINT,
  TYPE_ORDER,
  buildData,
  cardTitle,
  fieldsFromSource,
  initialFields,
  type CardType,
  type FieldValues,
  type PhotoInput,
} from '../lib/photocard/types'
import { makeZip, saveBlob } from '../lib/photocard/zip'
import { Banner, Card, ErrorRetry, PageHeader, Spinner, bnNumber, inputClass } from '../components/ui'
import { IconClipboard, IconCopy, IconDeviceFloppy, IconDownload, IconFileZip, IconShare } from '../components/Icon'
import MediaGallery from '../components/MediaGallery'
import PostPicker from '../components/photocard/PostPicker'
import PhotoPanel, { type PhotoItem } from '../components/photocard/PhotoPanel'
import DesignGrid, { type DesignRow } from '../components/photocard/DesignGrid'
import CardPreview from '../components/photocard/CardPreview'
import QuotePicker from '../components/photocard/QuotePicker'
import MadeCards from '../components/photocard/MadeCards'

/** Designs added in theme 2.4.3: offered even when the site still runs 2.4.2. */
const NEW_PRESETS: Partial<Record<CardType, string[]>> = { news: ['collage', 'chobi'] }
const OWN_PHOTO_TYPES: CardType[] = ['morning', 'day', 'birthday', 'condolence']
const PHOTO_NOTE: Partial<Record<CardType, string>> = {
  day: 'ছবি ঐচ্ছিক — না দিলে দিবসের নিজস্ব নকশা বসবে।',
  breaking: 'ছবি ঐচ্ছিক — না দিলে শিরোনাম বড় হয়ে পুরো কার্ডে বসবে।',
  birthday: 'যাঁর জন্মদিন তাঁর ছবি (গোল করে বসবে)। না দিলে নামের প্রথম অক্ষর।',
  condolence: 'ছবি নিজে থেকে সাদা-কালো হবে।',
  literature: '“জানালা ছবি”, “আধুনিক”, “ছবি পাশে” নকশায় ছবি বসে; কাগজের নকশায় লাগে না।',
  poem: '“ছবির ওপর” নকশায় ছবি বসে।',
}

type FrameText = { ink: string; pos: string; hide: Record<string, boolean> }
type Status = { kind: 'ok' | 'err' | 'info'; text: string } | null

/** A config built from the renderer alone (another theme, or an older plugin). */
function fallbackConfig(MC: MeehaCardsApi): StudioConfig {
  const types: StudioConfig['types'] = {}
  for (const t of TYPE_ORDER) {
    const tp = MC.templates[t]
    if (tp) types[t] = { label: tp.label, presets: tp.presets, photo: tp.photo, ad: tp.ad }
  }
  const qf: Record<string, string> = {}
  Object.entries(MC.quoteFrames).forEach(([k, v]) => (qf[k] = v.label))
  return {
    types,
    sizes: { '1080x1080': '', '1080x1350': '', '1200x630': '', '1080x1920': '' },
    frames: [],
    canUpload: false,
    canManage: false,
    canSave: true,
    ads: [],
    adDefault: false,
    today: new Date().toISOString().slice(0, 10),
    now: '',
    dates: { weekday: '', gregorian: '', bangla: '', hijri: '' },
    hijriOffset: 0,
    titles: ['শুভ সকাল'],
    condPre: ['ইন্না লিল্লাহি ওয়া ইন্না ইলাইহি রাজিউন'],
    defaults: {},
    categories: [],
    quoteFrames: qf,
    maxBytes: 1048576,
    days: [],
    newsDefault: 'classic',
    litLabel: 'সাহিত্য',
    storyCta: 'বিস্তারিত পড়ুন meeha.news-এ',
  }
}

function photoFromImage(img: CardSource['images'][number]): PhotoItem {
  return {
    key: `m${img.id}`,
    id: img.id,
    thumb: img.thumb,
    label: img.featured ? 'ফিচার ছবি' : img.caption || img.alt || 'গ্যালারির ছবি',
    featured: img.featured,
    fromPost: true,
    width: img.width,
    height: img.height,
    focus: img.focus ? { x: img.focus.x, y: img.focus.y } : { x: 50, y: 50 },
    zoom: img.focus?.zoom || 1,
    look: NO_LOOK,
    saved: img.focus,
  }
}

export default function PhotocardStudio() {
  const [params, setParams] = useSearchParams()
  const [kit, setKit] = useState<PhotocardKit | null>(null)
  const [MC, setMC] = useState<MeehaCardsApi | null>(null)
  const [bootErr, setBootErr] = useState<unknown>(null)
  const [bootTry, setBootTry] = useState(0)

  const [type, setType] = useState<CardType>(() => (TYPE_ORDER.includes(params.get('type') as CardType) ? (params.get('type') as CardType) : 'news'))
  const [size, setSize] = useState('1080x1080')
  const [fields, setFields] = useState<Record<CardType, FieldValues>>(() => initialFields(null))
  const [design, setDesign] = useState<Partial<Record<CardType, string>>>({})
  const [photos, setPhotos] = useState<PhotoItem[]>([])
  const [photoSel, setPhotoSel] = useState<Partial<Record<CardType, string | null>>>({})
  const [galleryKeys, setGalleryKeys] = useState<string[]>([])
  const [source, setSource] = useState<CardSource | null>(null)
  const [sourceBusy, setSourceBusy] = useState(false)
  const [status, setStatus] = useState<Status>(null)
  const [adOn, setAdOn] = useState(false)
  const [adId, setAdId] = useState('')
  const [ctaOn, setCtaOn] = useState(true)
  const [ctaText, setCtaText] = useState('বিস্তারিত কমেন্টে')
  const [storyCta, setStoryCta] = useState('')
  const [quoteFrame, setQuoteFrame] = useState('opinion')
  const [quoteId, setQuoteId] = useState<number | null>(null)
  const [authorPhoto, setAuthorPhoto] = useState('')
  const [speakerPhoto, setSpeakerPhoto] = useState('')
  const [dayTheme, setDayTheme] = useState('generic')
  const [dates, setDates] = useState<CardDates>({ weekday: '', gregorian: '', bangla: '', hijri: '' })
  const [dateYmd, setDateYmd] = useState('')
  const [hijri, setHijri] = useState(0)
  const [frameText, setFrameText] = useState<Record<string, FrameText>>({})
  const [focusSync, setFocusSync] = useState(true)
  const [card, setCard] = useState<HTMLCanvasElement | null>(null)
  const [renderBusy, setRenderBusy] = useState(false)
  const [renderErr, setRenderErr] = useState('')
  const [madeRefresh, setMadeRefresh] = useState(0)
  const [library, setLibrary] = useState<null | 'photo' | 'author'>(null)
  const [smartBusy, setSmartBusy] = useState(false)
  const [smartNote, setSmartNote] = useState('')
  const [working, setWorking] = useState('')
  const dragging = useRef(false)

  // ---------------------------------------------------------------- boot
  useEffect(() => {
    let live = true
    setBootErr(null)
    Promise.all([PhotocardApi.kit(), loadCards()])
      .then(async ([k, mc]) => {
        if (!live) return
        const c = k.cards
        const logo = c?.logo
        let logoCfg: Record<string, unknown> = { mode: 'vector' }
        if (logo?.mode === 'image' && logo.url) {
          const [u, l] = await Promise.all([imageByUrl(logo.url), logo.light?.url ? imageByUrl(logo.light.url) : Promise.resolve(null)])
          logoCfg = u ? { mode: 'image', url: u, ...(l ? { light: { url: l } } : {}) } : { mode: 'vector' }
        }
        mc.configure({
          logo: logoCfg,
          ...(c?.brand ? { brand: c.brand } : {}),
          ...(c?.watermark !== undefined ? { watermark: c.watermark } : {}),
          ...(c?.cta !== undefined ? { cta: c.cta } : {}),
          ...(c?.storyCta !== undefined ? { storyCta: c.storyCta } : {}),
          ...(c?.newsDefault ? { newsDefault: c.newsDefault } : {}),
        })
        const cfg = k.studio_config || fallbackConfig(mc)
        setKit(k)
        setMC(mc)
        setFields(initialFields(cfg))
        setAdOn(!!cfg.adDefault && cfg.ads.length > 0)
        setAdId(cfg.ads[0]?.id || '')
        const cta = c?.cta ?? 'বিস্তারিত কমেন্টে'
        setCtaText(cta || 'বিস্তারিত কমেন্টে')
        setCtaOn(cta.trim() !== '')
        setStoryCta(cfg.storyCta || '')
        setDates(cfg.dates)
        setDateYmd(cfg.today)
        setHijri(cfg.hijriOffset || 0)
        setDayTheme(cfg.days[0]?.theme || 'generic')
        if (!cfg.sizes['1080x1080']) setSize(Object.keys(cfg.sizes)[0] || '1080x1080')
      })
      .catch((e) => live && setBootErr(e))
    return () => {
      live = false
    }
  }, [bootTry])

  const cfg = useMemo<StudioConfig | null>(() => (kit && MC ? kit.studio_config || fallbackConfig(MC) : null), [kit, MC])

  // ---------------------------------------------------------------- post
  const pickPost = useCallback(
    async (post: string | number, suggest?: string) => {
      setSourceBusy(true)
      setStatus({ kind: 'info', text: 'তথ্য আনা হচ্ছে…' })
      try {
        const r = await PhotocardApi.source(post)
        setSource(r)
        setFields((prev) => fieldsFromSource(prev, r))
        const imgs = r.images.map(photoFromImage)
        setPhotos((prev) => [...imgs, ...prev.filter((p) => !p.fromPost && !imgs.some((i) => i.key === p.key))])
        const first = imgs[0]?.key || null
        setPhotoSel((prev) => {
          const next = { ...prev }
          for (const t of TYPE_ORDER) if (!OWN_PHOTO_TYPES.includes(t)) next[t] = first
          return next
        })
        setGalleryKeys(imgs.slice(1, 4).map((i) => i.key))
        if (r.author_photo) imageByUrl(r.author_photo).then((u) => setAuthorPhoto(u || ''))
        else setAuthorPhoto('')
        if (r.quote) {
          pickQuote(r.quote)
        }
        // A literature / poem / opinion post opens its own card type.
        const want = r.lit_genre ? (r.lit_genre === 'poem' ? 'poem' : 'literature') : suggest === 'opinion' ? 'opinion' : r.quote ? 'quote' : ''
        setType((t) => (want && (t === 'news' || POST_TYPES.includes(t)) ? (want as CardType) : t))
        setStatus({ kind: 'ok', text: imgs.length > 1 ? `তথ্য বসানো হয়েছে — পোস্টের ${bnNumber(imgs.length)}টি ছবি নিচে, যেকোনোটি বাছতে পারেন।` : 'তথ্য বসানো হয়েছে — দরকার হলে বদলে নিন।' })
        setParams((p) => {
          p.set('post', String(r.id))
          return p
        }, { replace: true })
        // Faces found → put them in the middle of every design straight away.
        const feat = imgs[0]
        if (feat && feat.id && !feat.saved) {
          imageById(feat.id).then(async (u) => {
            if (!u) return
            const g = await smartFocus(u).catch(() => null)
            if (g && g.how === 'face') {
              setPhotos((prev) => prev.map((p) => (p.key === feat.key && p.focus.x === 50 && p.focus.y === 50 ? { ...p, focus: { x: g.x, y: g.y } } : p)))
              setSmartNote(`ছবিতে ${bnNumber(g.faces || 1)}টি মুখ পাওয়া গেছে — সেগুলো মাঝখানে রাখা হয়েছে।`)
            }
          })
        }
      } catch (e) {
        setStatus({ kind: 'err', text: describeError(e).message })
      } finally {
        setSourceBusy(false)
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [setParams],
  )

  const bootPost = useRef(false)
  useEffect(() => {
    if (!cfg || bootPost.current) return
    bootPost.current = true
    const p = params.get('post')
    if (p) pickPost(p)
  }, [cfg, params, pickPost])

  function pickQuote(q: CardQuote) {
    setQuoteId(q.id)
    setFields((prev) => ({ ...prev, quote: { ...prev.quote, text: q.text, speaker: q.speaker, role: q.role, type_label: q.type_label, year: q.year } }))
    setQuoteFrame(q.frame || 'opinion')
    setDesign((d) => ({ ...d, quote: `b:${q.frame || 'opinion'}` }))
    if (q.photo) imageByUrl(q.photo).then((u) => setSpeakerPhoto(u || ''))
    else setSpeakerPhoto('')
  }

  // ---------------------------------------------------------------- derived
  const typeInfo = cfg?.types[type]
  const hasPhoto = !!typeInfo?.photo
  const selKey = photoSel[type] ?? null
  const photo = hasPhoto ? photos.find((p) => p.key === selKey) || null : null

  const rows = useMemo<DesignRow[]>(() => {
    if (!cfg || !MC) return []
    const presets: Record<string, string> = { ...(cfg.types[type]?.presets || {}) }
    for (const id of NEW_PRESETS[type] || []) if (!presets[id] && MC.templates[type]?.presets[id]) presets[id] = MC.templates[type].presets[id]
    const out: DesignRow[] = Object.entries(presets).map(([p, label]) => ({
      key: `b:${p}`,
      label,
      isNew: (NEW_PRESETS[type] || []).includes(p),
      tag: MC.isDeco(type, p) ? 'রেডিমেড' : undefined,
    }))
    for (const f of cfg.frames) {
      if (f.type === type && f.size === size) out.push({ key: `c:${f.id}`, label: f.label, tag: f.readymade ? 'রেডিমেড' : 'নিজের', canDelete: f.can_delete })
    }
    return out
  }, [cfg, MC, type, size])

  const framePreset = useCallback(
    (t: CardType) => {
      if (!cfg) return ''
      const presets = Object.keys(cfg.types[t]?.presets || {})
      if (t === 'quote') return quoteFrame
      if (t === 'day') return dayTheme
      if (t === 'news' && cfg.newsDefault && presets.includes(cfg.newsDefault) && !MC?.isDeco('news', cfg.newsDefault)) return cfg.newsDefault
      return presets[0] || ''
    },
    [cfg, MC, quoteFrame, dayTheme],
  )

  const defaultKey = useCallback(
    (t: CardType) => {
      if (t === 'quote') return `b:${quoteFrame}`
      if (t === 'day') return `b:${dayTheme}`
      if (t === 'news' && cfg?.newsDefault) return `b:${cfg.newsDefault}`
      return `b:${Object.keys(cfg?.types[t]?.presets || {})[0] || ''}`
    },
    [cfg, quoteFrame, dayTheme],
  )

  const designKey = (() => {
    const k = design[type]
    if (k && rows.some((r) => r.key === k)) return k
    const d = defaultKey(type)
    return rows.some((r) => r.key === d) ? d : rows[0]?.key || ''
  })()
  const curFrame: CardFrame | null = designKey.startsWith('c:') ? cfg?.frames.find((f) => f.id === designKey.slice(2)) || null : null
  const solo = !!(MC && curFrame && MC.isStandalone(type))
  const ft: FrameText = frameText[type] || { ink: 'auto', pos: 'bottom', hide: {} }
  const preset = designKey.startsWith('b:') ? designKey.slice(2) : ''
  const collage = type === 'news' && preset === 'collage'

  // Why the ad strip can't go on this card (empty = it can).
  const adWhy = (() => {
    if (!cfg || !typeInfo) return ''
    if (!typeInfo.ad) return 'শোক কার্ডে বিজ্ঞাপন স্ট্রিপ বসে না।'
    if (type === 'day' && !solo && dayTheme === 'matribhasha') return 'শহীদ দিবসের কার্ডে বিজ্ঞাপন স্ট্রিপ বসে না।'
    if (size === '1200x630') return 'লিংক (১২০০ × ৬৩০) মাপে বিজ্ঞাপন স্ট্রিপ নেই।'
    if (!cfg.ads.length) return 'কোনো বিজ্ঞাপন স্ট্রিপ সেট করা নেই — মিহা থিম › বিজ্ঞাপন (card_strip) থেকে যোগ করুন।'
    return ''
  })()

  // ---------------------------------------------------------------- render
  const resolvePhoto = useCallback(async (p: PhotoItem | null): Promise<PhotoInput | null> => {
    if (!p) return null
    const base = p.file || (p.id ? await imageById(p.id) : null)
    if (!base) return null
    const image = await applyLook(base, p.look)
    return { image, focus: p.focus, zoom: p.zoom }
  }, [])

  const compose = useCallback(
    async (t: CardType, sz: string, key: string, override?: PhotoItem | null) => {
      if (!cfg || !MC) throw new Error('studio not ready')
      const info = cfg.types[t]
      const ph = info?.photo ? await resolvePhoto(override !== undefined ? override : photos.find((p) => p.key === (photoSel[t] ?? null)) || null) : null
      const galleryItems = t === 'news' ? galleryKeys.map((k) => photos.find((p) => p.key === k)).filter((p): p is PhotoItem => !!p && p.key !== (override?.key ?? photoSel[t])) : []
      const gallery = (await Promise.all(galleryItems.slice(0, 3).map(resolvePhoto))).filter((g): g is PhotoInput => !!g)
      const postPhotos = photos.filter((p) => p.fromPost).length
      const o: Record<string, unknown> = { size: sz }
      let tKey = key
      if (tKey.startsWith('c:')) {
        const f = cfg.frames.find((x) => x.id === tKey.slice(2))
        if (f && f.size === sz) {
          o.frame = await imageByUrl(f.url)
          o.preset = framePreset(t)
          if (t === 'quote') o.builtinFrame = false
          if (MC.isStandalone(t)) {
            const x = frameText[t] || { ink: 'auto', pos: 'bottom', hide: {} }
            o.frameText = { ink: x.ink, pos: x.pos, hide: { ...x.hide } }
          }
        } else {
          tKey = `b:${framePreset(t)}`
        }
      }
      if (tKey.startsWith('b:')) o.preset = tKey.slice(2)
      const breakingVariant = t === 'breaking' ? String(o.preset || 'breaking') : 'breaking'
      if (adOn && info?.ad && sz !== '1200x630' && cfg.ads.length) {
        const ad = cfg.ads.find((a) => a.id === adId) || cfg.ads[0]
        const u = ad ? await imageByUrl(ad.url) : null
        o.ad = u ? { url: u } : true
      }
      const data = buildData(t, fields[t], {
        photo: ph,
        gallery,
        galleryMore: t === 'news' ? Math.max(0, postPhotos - 1 - gallery.length) : 0,
        cfg,
        dates,
        dayTheme,
        breakingVariant,
        quoteFrame,
        authorPhoto,
        speakerPhoto,
        cta: CTA_TYPES.includes(t) ? (ctaOn ? ctaText : '') : null,
        storyCta: sz === '1080x1920' ? storyCta : null,
      })
      return { data, opts: o }
    },
    [cfg, MC, photos, photoSel, galleryKeys, framePreset, frameText, adOn, adId, fields, dates, dayTheme, quoteFrame, authorPhoto, speakerPhoto, ctaOn, ctaText, storyCta, resolvePhoto],
  )

  const renderCard = useCallback(
    async (t: CardType, sz: string, key: string, override?: PhotoItem | null) => {
      const { data, opts } = await compose(t, sz, key, override)
      return MC!.render(t, data, opts)
    },
    [compose, MC],
  )

  const seq = useRef(0)
  useEffect(() => {
    if (!cfg || !MC || !designKey) return
    const mine = ++seq.current
    setRenderBusy(true)
    const t = setTimeout(
      async () => {
        try {
          const c = await renderCard(type, size, designKey)
          if (mine !== seq.current) return
          setCard(c)
          setRenderErr('')
        } catch (e) {
          if (mine === seq.current) setRenderErr(e instanceof Error ? e.message : 'কার্ড তৈরি করা যায়নি।')
        } finally {
          if (mine === seq.current) setRenderBusy(false)
        }
      },
      dragging.current ? 16 : 120,
    )
    return () => clearTimeout(t)
  }, [cfg, MC, type, size, designKey, renderCard])

  // Thumbnails redraw when the words / photo change (not while dragging).
  const thumbVersion = useMemo(() => {
    const f = fields[type]
    return JSON.stringify([size, photo?.key, photo?.look, Math.round((photo?.focus.x || 0) / 5), Math.round((photo?.focus.y || 0) / 5), photo?.zoom, f, galleryKeys, adOn, quoteFrame, dayTheme, authorPhoto, speakerPhoto, ctaOn && ctaText])
  }, [fields, type, size, photo, galleryKeys, adOn, quoteFrame, dayTheme, authorPhoto, speakerPhoto, ctaOn, ctaText])
  const drawThumb = useCallback((key: string) => renderCard(type, size, key), [renderCard, type, size])

  // ---------------------------------------------------------------- photo edits
  const patchPhoto = useCallback((key: string, patch: Partial<PhotoItem>) => {
    setPhotos((prev) => prev.map((p) => (p.key === key ? { ...p, ...patch } : p)))
  }, [])

  const onMove = useCallback(
    (dx: number, dy: number, done: boolean) => {
      if (!photo) return
      dragging.current = !done
      if (!dx && !dy) {
        if (done) setPhotos((prev) => [...prev]) // one last full-quality render
        return
      }
      const k = 1 / photo.zoom
      patchPhoto(photo.key, {
        focus: {
          x: Math.max(0, Math.min(100, Math.round((photo.focus.x - dx * k) * 10) / 10)),
          y: Math.max(0, Math.min(100, Math.round((photo.focus.y - dy * k) * 10) / 10)),
        },
      })
    },
    [photo, patchPhoto],
  )
  const onZoom = useCallback((z: number) => photo && patchPhoto(photo.key, { zoom: z }), [photo, patchPhoto])

  async function runSmart() {
    if (!photo) return
    setSmartBusy(true)
    setSmartNote('')
    try {
      const base = photo.file || (photo.id ? await imageById(photo.id) : null)
      const g = base ? await smartFocus(base) : null
      if (!g) {
        setSmartNote('এই ছবিতে বিষয় খুঁজে পাওয়া যায়নি — প্রিভিউতে টেনে ঠিক করুন।')
        return
      }
      patchPhoto(photo.key, { focus: { x: g.x, y: g.y } })
      setSmartNote(
        g.how === 'face'
          ? `${bnNumber(g.faces || 1)}টি মুখ পাওয়া গেছে — সেগুলো সব নকশার মাঝখানে রাখা হয়েছে।`
          : 'ছবির মূল অংশ (রং, খুঁটিনাটি, মানুষের ত্বক দেখে) মাঝখানে রাখা হয়েছে। ঠিক না হলে টেনে সরান।',
      )
    } finally {
      setSmartBusy(false)
    }
  }

  function addLibraryImage(img: LibraryImage) {
    const key = `m${img.id}`
    setPhotos((prev) => (prev.some((p) => p.key === key) ? prev : [...prev, { key, id: img.id, thumb: img.thumb || img.url, label: img.title || img.caption || 'গ্যালারির ছবি', focus: { x: 50, y: 50 }, zoom: 1, look: NO_LOOK }]))
    setPhotoSel((s) => ({ ...s, [type]: key }))
  }

  async function addDeviceImage(file: File) {
    const u = imageFromFile(file)
    const key = `f${Date.now()}`
    let w: number | null = null
    let h: number | null = null
    try {
      const im = await loadImageElement(u)
      w = im.naturalWidth
      h = im.naturalHeight
    } catch {
      setStatus({ kind: 'err', text: 'ছবিটি খোলা যায়নি — JPG, PNG বা WEBP দিন।' })
      return
    }
    setPhotos((prev) => [...prev, { key, file: u, thumb: u, label: file.name, width: w, height: h, focus: { x: 50, y: 50 }, zoom: 1, look: NO_LOOK }])
    setPhotoSel((s) => ({ ...s, [type]: key }))
  }

  function toggleGallery(key: string) {
    setGalleryKeys((g) => (g.includes(key) ? g.filter((k) => k !== key) : [...g, key].slice(-3)))
  }

  // ---------------------------------------------------------------- frames
  async function uploadFrame(file: File) {
    if (!cfg) return
    const [w, h] = size.split('x').map(Number)
    try {
      if (file.size > cfg.maxBytes) throw new Error(`ফাইলটি ${bnNumber(Math.round(cfg.maxBytes / 1048576))} এমবি-র বেশি।`)
      const im = await loadImageElement(URL.createObjectURL(file))
      if (im.naturalWidth !== w || im.naturalHeight !== h) {
        throw new Error(`ফ্রেমের মাপ ঠিক ${bnNumber(w)} × ${bnNumber(h)} পিক্সেল হতে হবে (এই ফাইলটি ${bnNumber(im.naturalWidth)} × ${bnNumber(im.naturalHeight)})।`)
      }
      setWorking('ফ্রেম আপলোড হচ্ছে…')
      const f = await PhotocardApi.uploadFrame(file, { type, size, label: file.name.replace(/\.png$/i, '') })
      setKit((k) => (k && k.studio_config ? { ...k, studio_config: { ...k.studio_config, frames: [...k.studio_config.frames, f] } } : k))
      setDesign((d) => ({ ...d, [type]: `c:${f.id}` }))
      setStatus({ kind: f.warning ? 'info' : 'ok', text: `ফ্রেম যোগ হয়েছে${f.warning ? ` — ${f.warning}` : '।'}` })
    } catch (e) {
      setStatus({ kind: 'err', text: e instanceof Error && !('status' in e) ? e.message : describeError(e).message })
    } finally {
      setWorking('')
    }
  }

  async function deleteFrame(key: string) {
    const f = cfg?.frames.find((x) => `c:${x.id}` === key)
    if (!f || !window.confirm(`“${f.label}” ফ্রেমটি মুছে ফেলবেন?`)) return
    try {
      await PhotocardApi.deleteFrame(f.id)
      setKit((k) => (k && k.studio_config ? { ...k, studio_config: { ...k.studio_config, frames: k.studio_config.frames.filter((x) => x.id !== f.id) } } : k))
      setStatus({ kind: 'ok', text: 'ফ্রেম মুছে ফেলা হয়েছে।' })
    } catch (e) {
      setStatus({ kind: 'err', text: describeError(e).message })
    }
  }

  // ---------------------------------------------------------------- dates
  async function changeDate(ymd: string, off: number) {
    setDateYmd(ymd)
    setHijri(off)
    try {
      setDates(await PhotocardApi.dates(ymd, off))
    } catch (e) {
      setStatus({ kind: 'err', text: `তারিখ আনা যায়নি: ${describeError(e).message}` })
    }
  }

  // ---------------------------------------------------------------- export
  const title = cardTitle(type, fields[type])
  const fileName = (ext: string, sz = size, extra = '') => {
    const base = MC ? MC.filename(type, title, type === 'morning' ? dateYmd : '', ext) : `meeha-card.${ext}`
    const tag = (sz !== '1080x1080' ? `-${sz}` : '') + extra
    return tag ? base.replace(new RegExp(`\\.${ext}$`), `${tag}.${ext}`) : base
  }

  async function syncFocusToSite(items: (PhotoItem | null | undefined)[]) {
    if (!focusSync || !kit?.focus_sync) return
    for (const p of items) {
      if (!p?.id) continue
      const now = { x: p.focus.x, y: p.focus.y, zoom: p.zoom }
      const was = p.saved || { x: 50, y: 50, zoom: 1 }
      if (now.x === was.x && now.y === was.y && now.zoom === was.zoom) continue
      try {
        const r = await PhotocardApi.saveFocus(p.id, now)
        patchPhoto(p.key, { saved: r.focus || { x: 50, y: 50, zoom: 1 } })
      } catch {
        /* the card itself is fine; only the remembered position failed */
      }
    }
  }

  async function currentCard() {
    return card && !renderBusy ? card : renderCard(type, size, designKey)
  }

  async function download(mime: 'image/png' | 'image/jpeg') {
    if (!MC) return
    setWorking('ডাউনলোড তৈরি হচ্ছে…')
    try {
      const c = await currentCard()
      saveBlob(await MC.toBlob(c, mime), fileName(mime === 'image/png' ? 'png' : 'jpg'))
      setStatus({ kind: 'ok', text: `${mime === 'image/png' ? 'PNG' : 'JPG'} ডাউনলোড হয়েছে।` })
      syncFocusToSite([photo])
    } catch (e) {
      setStatus({ kind: 'err', text: e instanceof Error ? e.message : 'ডাউনলোড করা যায়নি।' })
    } finally {
      setWorking('')
    }
  }

  async function downloadAllSizes() {
    if (!MC || !cfg) return
    const sizes = Object.keys(cfg.sizes)
    const files: { name: string; blob: Blob }[] = []
    try {
      for (const [i, sz] of sizes.entries()) {
        setWorking(`সব মাপ তৈরি হচ্ছে… ${bnNumber(i + 1)}/${bnNumber(sizes.length)}`)
        const c = await renderCard(type, sz, designKey)
        files.push({ name: fileName('png', sz), blob: await MC.toBlob(c, 'image/png') })
      }
      setWorking('জিপ তৈরি হচ্ছে…')
      saveBlob(await makeZip(files), fileName('zip').replace(/\.zip$/, '-sob-map.zip'))
      setStatus({ kind: 'ok', text: `${bnNumber(files.length)}টি মাপ একটি জিপ ফাইলে ডাউনলোড হয়েছে।` })
      syncFocusToSite([photo])
    } catch (e) {
      setStatus({ kind: 'err', text: e instanceof Error ? e.message : 'তৈরি করা যায়নি।' })
    } finally {
      setWorking('')
    }
  }

  const postPhotos = photos.filter((p) => p.fromPost)
  async function downloadAllPhotos() {
    if (!MC || postPhotos.length < 2) return
    const files: { name: string; blob: Blob }[] = []
    try {
      for (const [i, p] of postPhotos.entries()) {
        setWorking(`প্রতিটি ছবিতে কার্ড… ${bnNumber(i + 1)}/${bnNumber(postPhotos.length)}`)
        const c = await renderCard(type, size, designKey, p)
        files.push({ name: fileName('png', size, `-${i + 1}`), blob: await MC.toBlob(c, 'image/png') })
      }
      setWorking('জিপ তৈরি হচ্ছে…')
      saveBlob(await makeZip(files), fileName('zip').replace(/\.zip$/, '-sob-chobi.zip'))
      setStatus({ kind: 'ok', text: `পোস্টের ${bnNumber(files.length)}টি ছবিতে একই নকশার কার্ড — একটি জিপে ডাউনলোড হয়েছে।` })
      syncFocusToSite(postPhotos)
    } catch (e) {
      setStatus({ kind: 'err', text: e instanceof Error ? e.message : 'তৈরি করা যায়নি।' })
    } finally {
      setWorking('')
    }
  }

  async function copyImage() {
    if (!MC) return
    try {
      const c = await currentCard()
      const blob = await MC.toBlob(c, 'image/png')
      await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })])
      setStatus({ kind: 'ok', text: 'ছবি কপি হয়েছে — ফেসবুক/হোয়াটসঅ্যাপে সরাসরি পেস্ট করুন।' })
    } catch {
      setStatus({ kind: 'err', text: 'এই ব্রাউজারে ছবি কপি করা যায় না — ডাউনলোড করে নিন।' })
    }
  }

  const caption = useMemo(() => {
    const base = source?.summary || title
    return source?.permalink ? `${base}\n\n${source.permalink}` : base
  }, [source, title])

  async function copyCaption() {
    try {
      await navigator.clipboard.writeText(caption)
      setStatus({ kind: 'ok', text: 'ক্যাপশন কপি হয়েছে (সারাংশ + লিংক)।' })
    } catch {
      setStatus({ kind: 'err', text: 'ক্যাপশন কপি করা যায়নি।' })
    }
  }

  const canShare = typeof navigator !== 'undefined' && 'canShare' in navigator
  async function share() {
    if (!MC) return
    try {
      const c = await currentCard()
      const file = new File([await MC.toBlob(c, 'image/png')], fileName('png'), { type: 'image/png' })
      if (!navigator.canShare?.({ files: [file] })) throw new Error('no')
      await navigator.share({ files: [file], text: caption })
    } catch (e) {
      if ((e as Error)?.name !== 'AbortError') setStatus({ kind: 'err', text: 'এই ডিভাইসে সরাসরি শেয়ার করা যায় না — ডাউনলোড করে নিন।' })
    }
  }

  async function saveToMedia() {
    if (!MC) return
    setWorking('মিডিয়া লাইব্রেরিতে রাখা হচ্ছে…')
    try {
      const c = await currentCard()
      const m = await PhotocardApi.save(await MC.toBlob(c, 'image/png'), fileName('png'), {
        type,
        title: `${typeInfo?.label || 'ফটোকার্ড'} — ${title}`.slice(0, 180),
        alt: title.replace(/\*\*/g, '').slice(0, 180),
        post_id: source?.id,
      })
      setMadeRefresh((n) => n + 1)
      setStatus({ kind: 'ok', text: `মিডিয়া লাইব্রেরিতে রাখা হয়েছে (আইডি ${bnNumber(m.id)})।` })
      syncFocusToSite([photo])
    } catch (e) {
      setStatus({ kind: 'err', text: describeError(e).message })
    } finally {
      setWorking('')
    }
  }

  // ---------------------------------------------------------------- UI
  if (bootErr) return <ErrorRetry error={bootErr} onRetry={() => setBootTry((n) => n + 1)} />
  if (!cfg || !MC) return <Spinner className="mt-10" />

  const setField = (k: string, v: string | boolean) => setFields((prev) => ({ ...prev, [type]: { ...prev[type], [k]: v } }))
  const f = fields[type]
  const sizeInfo = SIZE_LABELS[size]
  const story = size === '1080x1920'
  const labels: Record<string, string> = {}
  Object.entries(cfg.types).forEach(([k, v]) => (labels[k] = v.label))

  function mark(k: string, el: HTMLTextAreaElement | null) {
    if (!el) return
    const a = el.selectionStart
    const b = el.selectionEnd
    const v = el.value
    if (a === b) {
      setStatus({ kind: 'info', text: 'আগে লেখার যে অংশ রঙিন করতে চান তা বাছুন, তারপর বোতাম চাপুন।' })
      el.focus()
      return
    }
    const sel = v.slice(a, b)
    const out = /^\*\*[\s\S]*\*\*$/.test(sel) ? sel.slice(2, -2) : `**${sel.replace(/\*\*/g, '').trim()}**`
    setField(k, v.slice(0, a) + out + v.slice(b))
  }

  const sectionNo = (() => {
    let n = 0
    return () => bnNumber(++n)
  })()

  return (
    <div className="max-w-[1320px]">
      <PageHeader title="ফটোকার্ড স্টুডিও" subtitle="খবর বাছুন, ছবি মিলিয়ে নিন, নকশা বাছুন — ডাউনলোড, কপি বা মিডিয়ায় রাখুন।" />

      {!kit?.studio && (
        <Banner kind="info">
          সাইটের থিমে ফটোকার্ড স্টুডিওর সেটিংস পাওয়া যায়নি, তাই বিল্ট-ইন নকশা দিয়ে চলছে (নিজের ফ্রেম, বিজ্ঞাপন স্ট্রিপ ও বিভাগ এখানে আসবে না)। মিহা নিউজ থিম চালু আছে কিনা দেখুন।
        </Banner>
      )}

      {/* Card type */}
      <div className="flex gap-2 overflow-x-auto pb-2 mb-4 -mx-1 px-1" role="tablist" aria-label="কার্ডের ধরন">
        {TYPE_ORDER.filter((t) => cfg.types[t]).map((t) => (
          <button
            key={t}
            type="button"
            role="tab"
            aria-selected={t === type}
            onClick={() => {
              setType(t)
              setParams((p) => {
                p.set('type', t)
                return p
              }, { replace: true })
            }}
            className={`shrink-0 text-left rounded-xl border px-3 py-2 transition ${
              t === type ? 'bg-brand-600 border-brand-600 text-white shadow' : 'bg-white border-neutral-200 text-neutral-700 hover:border-neutral-300'
            }`}
          >
            <b className="block text-[13px] font-semibold">{cfg.types[t].label}</b>
            <small className={`block text-[11px] ${t === type ? 'text-white/80' : 'text-neutral-400'}`}>{TYPE_HINT[t]}</small>
          </button>
        ))}
      </div>

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(340px,500px)] items-start">
        {/* Preview + export (first on phones) */}
        <div className="lg:order-2 lg:sticky lg:top-4 space-y-3">
          <Card className="p-3 sm:p-4">
            <div className="grid grid-cols-4 gap-1.5 mb-3" role="radiogroup" aria-label="মাপ">
              {Object.keys(cfg.sizes).map((sz) => {
                const s = SIZE_LABELS[sz]
                const on = sz === size
                return (
                  <button
                    key={sz}
                    type="button"
                    role="radio"
                    aria-checked={on}
                    onClick={() => setSize(sz)}
                    title={s?.hint}
                    className={`rounded-lg border px-1.5 py-1.5 text-center ${on ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-neutral-200 text-neutral-600 hover:border-neutral-300'}`}
                  >
                    <span className="mx-auto mb-1 block border-2 border-current rounded-[3px]" style={{ width: 22, height: Math.round(22 * (s?.ratio || 1)) > 30 ? 30 : Math.round(22 * (s?.ratio || 1)) }} />
                    <b className="block text-[12px] font-semibold">{s?.label || sz}</b>
                    <small className="block text-[10px] opacity-70">{bnNumber(sz.replace('x', '×'))}</small>
                  </button>
                )
              })}
            </div>
            <CardPreview
              card={card}
              busy={renderBusy}
              error={renderErr}
              interactive={!!photo}
              zoom={photo?.zoom || 1}
              onMove={onMove}
              onZoom={onZoom}
              story={story}
              meta={`${sizeInfo?.hint || ''}${adOn && !adWhy ? ' · বিজ্ঞাপনসহ' : ''}`}
            />
            <div className="grid grid-cols-2 gap-2 mt-3">
              <button type="button" disabled={!!working} onClick={() => download('image/png')} className="col-span-2 inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-[14.5px] py-2.5 disabled:opacity-60">
                <IconDownload className="w-5 h-5" /> PNG ডাউনলোড
              </button>
              <button type="button" disabled={!!working} onClick={() => download('image/jpeg')} className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-neutral-100 hover:bg-neutral-200 text-neutral-800 text-[13px] font-medium py-2 disabled:opacity-60">
                <IconDownload className="w-4 h-4" /> JPG (ছোট ফাইল)
              </button>
              <button type="button" disabled={!!working} onClick={downloadAllSizes} className="inline-flex items-center justify-center gap-1.5 rounded-lg bg-neutral-100 hover:bg-neutral-200 text-neutral-800 text-[13px] font-medium py-2 disabled:opacity-60" title="বর্গ, পোর্ট্রেট, লিংক, স্টোরি — একসাথে জিপে">
                <IconFileZip className="w-4 h-4" /> সব মাপে
              </button>
              {hasPhoto && POST_TYPES.includes(type) && postPhotos.length > 1 && (
                <button type="button" disabled={!!working} onClick={downloadAllPhotos} className="col-span-2 inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-50 hover:bg-brand-100 text-brand-700 text-[13px] font-medium py-2 disabled:opacity-60">
                  <IconFileZip className="w-4 h-4" /> পোস্টের {bnNumber(postPhotos.length)}টি ছবিতেই কার্ড (জিপ)
                </button>
              )}
              <button type="button" onClick={copyImage} className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-neutral-200 hover:bg-neutral-50 text-neutral-700 text-[12.5px] py-2">
                <IconCopy className="w-4 h-4" /> ছবি কপি
              </button>
              <button type="button" onClick={copyCaption} className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-neutral-200 hover:bg-neutral-50 text-neutral-700 text-[12.5px] py-2">
                <IconClipboard className="w-4 h-4" /> ক্যাপশন কপি
              </button>
              {cfg.canSave && (
                <button type="button" disabled={!!working} onClick={saveToMedia} className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-neutral-200 hover:bg-neutral-50 text-neutral-700 text-[12.5px] py-2 disabled:opacity-60">
                  <IconDeviceFloppy className="w-4 h-4" /> মিডিয়ায় রাখুন
                </button>
              )}
              {canShare && (
                <button type="button" onClick={share} className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-neutral-200 hover:bg-neutral-50 text-neutral-700 text-[12.5px] py-2 lg:hidden">
                  <IconShare className="w-4 h-4" /> শেয়ার
                </button>
              )}
            </div>
            <p role="status" aria-live="polite" className={`text-[12.5px] mt-2 min-h-[1.25rem] ${status?.kind === 'err' ? 'text-red-600' : status?.kind === 'ok' ? 'text-emerald-700' : 'text-neutral-500'}`}>
              {working || status?.text || ''}
            </p>
          </Card>
          <Card className="p-4 hidden lg:block">
            <h2 className="text-[13.5px] font-semibold text-neutral-800 mb-2">রাখা কার্ড</h2>
            <MadeCards refresh={madeRefresh} labels={labels} />
          </Card>
        </div>

        {/* Controls */}
        <div className="lg:order-1 space-y-4 min-w-0">
          {POST_TYPES.includes(type) && (
            <Section n={sectionNo()} title="খবর বাছুন" note="ঐচ্ছিক — বাছলে শিরোনাম, লেখক, বিভাগ, তারিখ আর পোস্টের সব ছবি নিজে থেকে আসবে।">
              {source && (
                <div className="flex items-center gap-2 rounded-xl bg-emerald-50 border border-emerald-100 px-2.5 py-2 mb-3">
                  <span className="text-[12px] text-emerald-800 min-w-0">
                    <b className="block truncate">{source.title}</b>
                    {[source.author_name, source.category, source.date_short].filter(Boolean).join(' · ')}
                  </span>
                </div>
              )}
              <PostPicker pickedId={source?.id || null} onPick={pickPost} busy={sourceBusy} />
            </Section>
          )}

          {type === 'quote' && (
            <Section n={sectionNo()} title="উক্তি বাছুন" note="বাছলে লেখা, বক্তা, পরিচয় ও ধরন অনুযায়ী ফ্রেম বসে যাবে; নিচে নিজেও লেখা যায়।">
              <QuotePicker pickedId={quoteId} onPick={pickQuote} />
            </Section>
          )}

          {hasPhoto && (
            <Section n={sectionNo()} title="ছবি" note={postPhotos.length ? 'ফিচার ছবি আর খবরের ভেতরের ছবি — যেটি চান বাছুন।' : 'খবর বাছলে তার ছবি এখানে আসবে; নইলে গ্যালারি বা ডিভাইস থেকে দিন।'}>
              <PhotoPanel
                photos={photos}
                selected={photo}
                optional={PHOTO_NOTE[type] || null}
                onSelect={(k) => setPhotoSel((s) => ({ ...s, [type]: k }))}
                onPatch={patchPhoto}
                onLibrary={() => setLibrary('photo')}
                onUpload={addDeviceImage}
                onSmart={runSmart}
                smartBusy={smartBusy}
                smartNote={smartNote}
                collage={collage}
                galleryKeys={galleryKeys}
                onToggleGallery={toggleGallery}
                focusSync={focusSync}
                onFocusSync={setFocusSync}
                canSync={!!kit?.focus_sync}
              />
            </Section>
          )}

          <Section n={sectionNo()} title="নকশা" note={solo ? 'নিজের ফ্রেম নিজেই পুরো নকশা — ছবি নিচে, লেখা ওপরে।' : undefined}>
            <DesignGrid
              rows={rows}
              selected={designKey}
              onSelect={(k) => {
                setDesign((d) => ({ ...d, [type]: k }))
                if (type === 'quote' && k.startsWith('b:')) setQuoteFrame(k.slice(2))
                if (type === 'day' && k.startsWith('b:')) setDayTheme(k.slice(2))
              }}
              draw={drawThumb}
              version={thumbVersion}
              ratio={sizeInfo?.ratio || 1}
              canUpload={cfg.canUpload}
              onUpload={uploadFrame}
              onDelete={deleteFrame}
              sizeLabel={`${sizeInfo?.label || ''} মাপ`}
              help={cfg.canUpload ? `নিজের ফ্রেম: স্বচ্ছ PNG, ঠিক ${bnNumber(size.replace('x', ' × '))} পিক্সেল।` : ''}
            />
            {solo && (
              <div className="mt-3 rounded-xl bg-neutral-50 p-3 space-y-2">
                <p className="text-[12.5px] font-semibold text-neutral-700">নিজের ফ্রেমের লেখা</p>
                <Radios
                  label="লেখার রং"
                  value={ft.ink}
                  options={[['auto', 'স্বয়ংক্রিয়'], ['light', 'সাদা'], ['dark', 'গাঢ়']]}
                  onChange={(v) => setFrameText((x) => ({ ...x, [type]: { ...ft, ink: v } }))}
                />
                <Radios
                  label="অবস্থান"
                  value={ft.pos}
                  options={[['bottom', 'নিচে'], ['middle', 'মাঝে'], ['top', 'ওপরে']]}
                  onChange={(v) => setFrameText((x) => ({ ...x, [type]: { ...ft, pos: v } }))}
                />
                <div className="flex flex-wrap gap-3">
                  {(MC.standaloneText[type] || []).concat([['logo', 'logo', 'লোগো']]).map((e) => (
                    <label key={e[0]} className="flex items-center gap-1.5 text-[12.5px] text-neutral-700">
                      <input
                        type="checkbox"
                        checked={!ft.hide[e[0]]}
                        onChange={(ev) => setFrameText((x) => ({ ...x, [type]: { ...ft, hide: { ...ft.hide, [e[0]]: !ev.target.checked } } }))}
                      />
                      {e[2]}
                    </label>
                  ))}
                </div>
              </div>
            )}
          </Section>

          <Section n={sectionNo()} title="লেখা">
            <div className="grid grid-cols-2 gap-x-3">
              {FIELDS[type].map((fd) => {
                const wrap = fd.half ? 'col-span-2 sm:col-span-1' : 'col-span-2'
                if (fd.kind === 'check') {
                  return (
                    <label key={fd.k} className={`${wrap} flex items-center gap-2 text-[13px] text-neutral-700 mb-3`}>
                      <input type="checkbox" checked={!!f[fd.k]} onChange={(e) => setField(fd.k, e.target.checked)} />
                      {fd.label}
                      {fd.k === 'show_photo' && (
                        <button type="button" onClick={() => setLibrary('author')} className="text-brand-600 text-[12px] ml-1">
                          ছবি বদলান
                        </button>
                      )}
                    </label>
                  )
                }
                return (
                  <div key={fd.k} className={`${wrap} mb-3`}>
                    <label className="block text-[12.5px] font-medium text-neutral-600 mb-1" htmlFor={`pc-${fd.k}`}>
                      {fd.label}
                    </label>
                    {fd.kind === 'textarea' ? (
                      <>
                        <textarea
                          id={`pc-${fd.k}`}
                          rows={fd.rows || 3}
                          maxLength={fd.max}
                          className={inputClass}
                          value={String(f[fd.k] ?? '')}
                          onChange={(e) => setField(fd.k, e.target.value)}
                          placeholder={fd.placeholder}
                        />
                        {fd.mark && (
                          <button
                            type="button"
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => mark(fd.k, document.getElementById(`pc-${fd.k}`) as HTMLTextAreaElement | null)}
                            className="text-[12px] text-brand-600 mt-1"
                          >
                            বাছাই করা লেখা রঙিন করুন / ফেরান
                          </button>
                        )}
                      </>
                    ) : fd.kind === 'select' ? (
                      <SelectFor
                        k={fd.k}
                        type={type}
                        cfg={cfg}
                        value={String(f[fd.k] ?? '')}
                        onChange={(v) => {
                          if (fd.k === 'day_pick') {
                            if (v.startsWith('f:')) {
                              const fr = cfg.frames.find((x) => x.id === v.slice(2))
                              if (fr) {
                                if (fr.size !== size) setSize(fr.size)
                                setDesign((d) => ({ ...d, day: `c:${fr.id}` }))
                              }
                              setField('day_pick', v)
                              return
                            }
                            const dd = cfg.days[Number(v)]
                            if (dd) {
                              setFields((prev) => ({ ...prev, day: { ...prev.day, day_pick: v, title: dd.title, subtitle: dd.subtitle, message: dd.message } }))
                              setDayTheme(dd.theme)
                              setDesign((d) => ({ ...d, day: `b:${dd.theme}` }))
                            }
                            return
                          }
                          setField(fd.k, v)
                        }}
                      />
                    ) : (
                      <input
                        id={`pc-${fd.k}`}
                        type="text"
                        maxLength={fd.max}
                        className={inputClass}
                        value={String(f[fd.k] ?? '')}
                        onChange={(e) => setField(fd.k, e.target.value)}
                        placeholder={fd.placeholder}
                      />
                    )}
                    {fd.k === 'title' && type === 'morning' && cfg.titles.length > 1 && (
                      <div className="flex flex-wrap gap-1.5 mt-1.5">
                        {cfg.titles.map((t) => (
                          <button key={t} type="button" onClick={() => setField('title', t)} className="px-2 py-0.5 rounded-full border border-neutral-200 text-[11.5px] text-neutral-600 hover:border-brand-400">
                            {t}
                          </button>
                        ))}
                      </div>
                    )}
                    {fd.hint && <p className="text-[11.5px] text-neutral-400 mt-1">{fd.hint}</p>}
                  </div>
                )
              })}
            </div>
            {CTA_TYPES.includes(type) && (
              <div className="flex items-center gap-2 pt-1">
                <label className="flex items-center gap-1.5 text-[12.5px] text-neutral-700 shrink-0">
                  <input type="checkbox" checked={ctaOn} onChange={(e) => setCtaOn(e.target.checked)} /> কার্ডে বোতাম
                </label>
                <input className={inputClass} value={ctaText} maxLength={40} onChange={(e) => setCtaText(e.target.value)} disabled={!ctaOn} aria-label="কার্ডের বোতামের লেখা" />
              </div>
            )}
          </Section>

          {type === 'morning' && (
            <Section n={sectionNo()} title="তারিখ">
              <div className="grid grid-cols-2 gap-3">
                <label className="block">
                  <span className="block text-[12.5px] font-medium text-neutral-600 mb-1">দিন</span>
                  <input type="date" className={inputClass} value={dateYmd} onChange={(e) => e.target.value && changeDate(e.target.value, hijri)} />
                </label>
                <label className="block">
                  <span className="block text-[12.5px] font-medium text-neutral-600 mb-1">হিজরি সমন্বয়</span>
                  <select className={inputClass} value={hijri} onChange={(e) => changeDate(dateYmd, Number(e.target.value))}>
                    <option value={-1}>−১ দিন</option>
                    <option value={0}>০ (বাংলাদেশ)</option>
                    <option value={1}>+১ দিন</option>
                  </select>
                </label>
              </div>
              <p className="text-[12px] text-neutral-500 mt-2">{[dates.weekday, dates.gregorian, dates.bangla, dates.hijri].filter(Boolean).join(' · ')}</p>
            </Section>
          )}

          <Section n={sectionNo()} title="বিজ্ঞাপন ও স্টোরি">
            <label className={`flex items-center gap-2 text-[13px] ${adWhy ? 'text-neutral-400' : 'text-neutral-700'}`}>
              <input type="checkbox" disabled={!!adWhy} checked={adOn && !adWhy} onChange={(e) => setAdOn(e.target.checked)} />
              নিচে বিজ্ঞাপন স্ট্রিপ (১০৮০ × ১২০)
            </label>
            {adWhy ? (
              <p className="text-[11.5px] text-neutral-400 mt-1">{adWhy}</p>
            ) : (
              cfg.ads.length > 1 && (
                <select className={`${inputClass} mt-2`} value={adId} disabled={!adOn} onChange={(e) => setAdId(e.target.value)} aria-label="বিজ্ঞাপন">
                  {cfg.ads.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.label}
                    </option>
                  ))}
                </select>
              )
            )}
            {story && (
              <label className="block mt-3">
                <span className="block text-[12.5px] font-medium text-neutral-600 mb-1">স্টোরির নিচের লেখা</span>
                <input className={inputClass} maxLength={60} value={storyCta} onChange={(e) => setStoryCta(e.target.value)} />
              </label>
            )}
          </Section>

          <Card className="p-4 lg:hidden">
            <h2 className="text-[13.5px] font-semibold text-neutral-800 mb-2">রাখা কার্ড</h2>
            <MadeCards refresh={madeRefresh} labels={labels} />
          </Card>
        </div>
      </div>

      {library && (
        <MediaGallery
          title={library === 'author' ? 'লেখকের ছবি বাছুন' : 'কার্ডের ছবি বাছুন'}
          onClose={() => setLibrary(null)}
          onSelect={(img) => {
            if (library === 'author') {
              imageById(img.id, 'medium').then((u) => {
                setAuthorPhoto(u || '')
                setFields((prev) => ({ ...prev, opinion: { ...prev.opinion, show_photo: true } }))
              })
            } else {
              addLibraryImage(img)
            }
            setLibrary(null)
          }}
        />
      )}
    </div>
  )
}

function Section({ n, title, note, children }: { n: string; title: string; note?: string; children: React.ReactNode }) {
  return (
    <Card className="p-4">
      <h2 className="flex items-center gap-2 text-[14.5px] font-semibold text-neutral-900">
        <span className="w-6 h-6 rounded-full bg-brand-600 text-white text-[12px] flex items-center justify-center">{n}</span>
        {title}
      </h2>
      {note && <p className="text-[12px] text-neutral-500 mt-1 mb-3 ml-8">{note}</p>}
      <div className={note ? '' : 'mt-3'}>{children}</div>
    </Card>
  )
}

function Radios({ label, value, options, onChange }: { label: string; value: string; options: [string, string][]; onChange: (v: string) => void }) {
  return (
    <div className="flex items-center gap-3 flex-wrap">
      <span className="text-[12px] text-neutral-500 w-16">{label}</span>
      {options.map(([v, l]) => (
        <label key={v} className="flex items-center gap-1 text-[12.5px] text-neutral-700">
          <input type="radio" checked={value === v} onChange={() => onChange(v)} />
          {l}
        </label>
      ))}
    </div>
  )
}

function SelectFor({ k, type, cfg, value, onChange }: { k: string; type: CardType; cfg: StudioConfig; value: string; onChange: (v: string) => void }) {
  if (k === 'category_id') {
    return (
      <select id={`pc-${k}`} className={inputClass} value={value} onChange={(e) => onChange(e.target.value)}>
        {!cfg.categories.length && <option value="">— বিভাগ পাওয়া যায়নি —</option>}
        {cfg.categories.map((c) => (
          <option key={c.id} value={c.id}>
            {c.name}
          </option>
        ))}
      </select>
    )
  }
  if (k === 'pre') {
    return (
      <select id={`pc-${k}`} className={inputClass} value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">— নেই —</option>
        {cfg.condPre.map((t) => (
          <option key={t} value={t}>
            {t}
          </option>
        ))}
      </select>
    )
  }
  if (k === 'day_pick') {
    const own = cfg.frames.filter((f) => f.type === 'day')
    return (
      <select id={`pc-${k}`} className={inputClass} value={value} onChange={(e) => onChange(e.target.value)}>
        <optgroup label="দিবসের তালিকা (বিল্ট-ইন নকশা)">
          {cfg.days.map((d, i) => (
            <option key={i} value={String(i)}>
              {d.title}
              {d.subtitle ? ` — ${d.subtitle}` : ''}
            </option>
          ))}
        </optgroup>
        {own.length > 0 && (
          <optgroup label="আপলোড করা ফ্রেম">
            {own.map((f) => (
              <option key={f.id} value={`f:${f.id}`}>
                {f.label} (নিজের ফ্রেম, {bnNumber(f.size.replace('x', ' × '))})
              </option>
            ))}
          </optgroup>
        )}
      </select>
    )
  }
  return <select id={`pc-${k}-${type}`} className={inputClass} value={value} onChange={(e) => onChange(e.target.value)} />
}
