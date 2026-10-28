/**
 * Round 21 — video editor ("ভিডিও এডিট"): the edit settings, their
 * defaults, and the catalogue of built-in frames / intros / outros.
 *
 * An EditConfig is plain JSON: it is saved on the video post (so an edit
 * can be reopened from the original file), in the browser (draft), and as
 * shared presets. normalizeConfig() accepts anything and returns a valid
 * config, so an old or hand-edited value can never break the editor.
 */

export type FormatId = 'source' | '16:9' | '9:16' | '1:1' | '4:5'
export type FitMode = 'blur' | 'cover' | 'black'
export type FrameId = 'none' | 'breaking' | 'news' | 'live' | 'special' | 'wave' | 'minimal' | 'exclusive'
export type FooterMode = 'none' | 'ticker' | 'ad-text' | 'ad-image' | 'brand'
export type IntroId = 'none' | 'meeha-logo' | 'breaking' | 'headline' | 'special' | 'custom'
export type OutroId = 'none' | 'meeha-social' | 'thanks' | 'custom'
export type LogoStyle = 'reveal' | 'spin' | 'pulse' | 'static'
export type Corner = 'tr' | 'tl' | 'br' | 'bl'
export type Quality = '720' | '1080'

/** A file from the media library (video clip or image). */
export interface MediaRef {
  id: number
  url: string
  title: string
  duration?: number | null
}

export interface AdBreak {
  /** Where in the ORIGINAL video the ad goes (seconds). */
  at: number
  clip: MediaRef
  /** Short "বিজ্ঞাপন বিরতি" / "সংবাদে ফিরছি" cards around the ad. */
  bumper: boolean
}

export type CaptionStyle = 'nameplate' | 'tag' | 'subtitle'
export type CaptionPos = 'bl' | 'br' | 'tl' | 'tr' | 'bc'

/**
 * A caption / credit on the video: a name plate ("আসিফ" / "ক্যাম্পাস
 * সংবাদদাতা"), a small credit tag ("ভিডিও: সংগৃহীত") or a subtitle line.
 */
export interface Caption {
  text: string
  /** Second line (name plate's designation). */
  sub: string
  style: CaptionStyle
  pos: CaptionPos
  /** Start in the ORIGINAL video (seconds). */
  at: number
  /** Seconds on screen; null = until the end of the video. */
  dur: number | null
}

export interface EditConfig {
  v: 1
  trim: { start: number; end: number | null }
  format: FormatId
  fit: FitMode
  quality: Quality
  frame: {
    id: FrameId
    title: string
    kicker: string
    date: boolean
    footer: FooterMode
    ticker: string
    adText: string
    adImage: MediaRef | null
  }
  logo: {
    on: boolean
    style: LogoStyle
    corner: Corner
    /** Logo height as % of the video's shorter side. */
    size: number
    /** Seconds between two animations (reveal / spin / pulse). */
    every: number
    plate: boolean
    opacity: number
  }
  intro: { id: IntroId; text: string; clip: MediaRef | null }
  outro: { id: OutroId; clip: MediaRef | null }
  ads: AdBreak[]
  captions: Caption[]
  audio: { main: number; clips: number; sfx: boolean }
}

export const DEFAULT_CONFIG: EditConfig = {
  v: 1,
  trim: { start: 0, end: null },
  format: 'source',
  fit: 'blur',
  quality: '1080',
  frame: { id: 'none', title: '', kicker: '', date: true, footer: 'ticker', ticker: '', adText: '', adImage: null },
  logo: { on: false, style: 'reveal', corner: 'tr', size: 6, every: 12, plate: true, opacity: 1 },
  intro: { id: 'none', text: '', clip: null },
  outro: { id: 'none', clip: null },
  ads: [],
  captions: [],
  audio: { main: 1, clips: 1, sfx: true },
}

// ---- catalogue ----

export interface FrameInfo {
  id: Exclude<FrameId, 'none'>
  name: string
  hint: string
  /** Default footer label for the ticker. */
  label: string
  swatch: [string, string]
}

export const FRAMES: FrameInfo[] = [
  { id: 'breaking', name: 'ব্রেকিং নিউজ', hint: 'লাল, জ্বলজ্বলে ব্যাজ ও চলমান স্ক্রল', label: 'সর্বশেষ', swatch: ['#C8102E', '#111827'] },
  { id: 'news', name: 'সংবাদ', hint: 'মিহা নীল, ক্যাটেগরি চিপ ও তারিখ', label: 'শিরোনাম', swatch: ['#025AB2', '#0B2746'] },
  { id: 'live', name: 'সরাসরি', hint: 'জ্বলা-নেভা লাল বিন্দু, গাঢ় পটভূমি', label: 'সরাসরি', swatch: ['#0F172A', '#DC2626'] },
  { id: 'special', name: 'বিশেষ প্রতিবেদন', hint: 'গাঢ় নীল ও সোনালি রেখা', label: 'বিশেষ', swatch: ['#0B1220', '#E8B33A'] },
  { id: 'wave', name: 'মিহা ঢেউ', hint: 'চলমান ঢেউ — থিমের ঢেউ ডিজাইন', label: 'শিরোনাম', swatch: ['#025AB2', '#CC2A1F'] },
  { id: 'exclusive', name: 'এক্সক্লুসিভ', hint: 'কালো ও হলুদ টেপ', label: 'এক্সক্লুসিভ', swatch: ['#0A0A0A', '#FACC15'] },
  { id: 'minimal', name: 'মিনিমাল সাদা', hint: 'সাদা পটভূমি, পরিষ্কার লেখা', label: 'শিরোনাম', swatch: ['#FFFFFF', '#025AB2'] },
]

export const FOOTERS: { id: FooterMode; name: string }[] = [
  { id: 'ticker', name: 'চলমান স্ক্রল' },
  { id: 'ad-text', name: 'বিজ্ঞাপন (লেখা)' },
  { id: 'ad-image', name: 'বিজ্ঞাপন (ব্যানার ছবি)' },
  { id: 'brand', name: 'মিহা নিউজ ব্র্যান্ডিং' },
  { id: 'none', name: 'ফুটার নেই' },
]

export interface SceneInfo<T extends string> {
  id: T
  name: string
  hint: string
  /** Fixed length for drawn scenes; clips use the file's length. */
  duration?: number
}

export const INTROS: SceneInfo<IntroId>[] = [
  { id: 'meeha-logo', name: 'মিহা লোগো এনিমেশন', hint: 'আপনার দেওয়া লোগো এনিমেশন (৩.৫ সেকেন্ড)' },
  { id: 'breaking', name: 'ব্রেকিং নিউজ', hint: 'লাল ফ্ল্যাশ, ঝাঁকুনি ও শিরোনাম', duration: 3 },
  { id: 'headline', name: 'শিরোনাম কার্ড', hint: 'লোগো, ক্যাটেগরি ও শিরোনাম লাইন ধরে', duration: 4 },
  { id: 'special', name: 'বিশেষ প্রতিবেদন', hint: 'সোনালি রেখা ও শিরোনাম', duration: 3.5 },
  { id: 'custom', name: 'গ্যালারি থেকে', hint: 'নিজের ইন্ট্রো ভিডিও' },
]

export const OUTROS: SceneInfo<OutroId>[] = [
  { id: 'meeha-social', name: 'মিহা সোশ্যাল আউট্রো', hint: 'আপনার দেওয়া আউট্রো — সোশ্যাল লিংকসহ (৮ সেকেন্ড)' },
  { id: 'thanks', name: 'ধন্যবাদ কার্ড', hint: 'লোগো, “দেখার জন্য ধন্যবাদ” ও ওয়েবসাইট', duration: 4 },
  { id: 'custom', name: 'গ্যালারি থেকে', hint: 'নিজের আউট্রো ভিডিও' },
]

export const LOGO_STYLES: { id: LogoStyle; name: string; hint: string }[] = [
  { id: 'reveal', name: 'রিভিল', hint: 'M বৃত্ত সবসময়, মাঝে মাঝে পুরো লোগো খুলে যায়' },
  { id: 'spin', name: 'কয়েন স্পিন', hint: 'M বৃত্ত মাঝে মাঝে ঘুরে ওঠে' },
  { id: 'pulse', name: 'ঝলক', hint: 'পুরো লোগো, মাঝে মাঝে আলোর ঝলক' },
  { id: 'static', name: 'স্থির', hint: 'পুরো লোগো, এনিমেশন ছাড়া' },
]

export const CAPTION_STYLES: { id: CaptionStyle; name: string; hint: string }[] = [
  { id: 'nameplate', name: 'নাম-পদবি পট্টি', hint: 'নাম + পদবি, দুই লাইনে' },
  { id: 'tag', name: 'ক্রেডিট ট্যাগ', hint: 'ছোট লেখা — যেমন “ভিডিও: সংগৃহীত”' },
  { id: 'subtitle', name: 'সাবটাইটেল', hint: 'নিচে মাঝখানে, কালো পটভূমিতে' },
]

export const CAPTION_POSITIONS: { id: CaptionPos; name: string }[] = [
  { id: 'bl', name: 'নিচে বামে' },
  { id: 'bc', name: 'নিচে মাঝে' },
  { id: 'br', name: 'নিচে ডানে' },
  { id: 'tl', name: 'উপরে বামে' },
  { id: 'tr', name: 'উপরে ডানে' },
]

/** One-click starting points for the captions panel. */
export const CAPTION_PRESETS: { label: string; caption: Omit<Caption, 'at'> }[] = [
  { label: 'ভিডিও: সংগৃহীত', caption: { text: 'ভিডিও: সংগৃহীত', sub: '', style: 'tag', pos: 'tl', dur: null } },
  { label: 'ভিডিও পাঠিয়েছেন…', caption: { text: 'ভিডিও পাঠিয়েছেন: ', sub: '', style: 'tag', pos: 'tl', dur: 6 } },
  { label: 'নাম ও পদবি', caption: { text: '', sub: 'ক্যাম্পাস সংবাদদাতা', style: 'nameplate', pos: 'bl', dur: 5 } },
  { label: 'সাবটাইটেল লাইন', caption: { text: '', sub: '', style: 'subtitle', pos: 'bc', dur: 4 } },
]

export const FORMATS: { id: FormatId; name: string; hint: string }[] = [
  { id: 'source', name: 'মূল মাপ', hint: 'ভিডিওর নিজের মাপ — ফ্রেম উপরে-নিচে যোগ হয়' },
  { id: '9:16', name: '৯:১৬', hint: 'রিলস / শর্টস' },
  { id: '1:1', name: '১:১', hint: 'ফেসবুক ফিড' },
  { id: '4:5', name: '৪:৫', hint: 'ফেসবুক / ইনস্টাগ্রাম' },
  { id: '16:9', name: '১৬:৯', hint: 'ইউটিউব / ওয়েবসাইট' },
]

/** Length of the cards around an ad break. */
export const AD_IN_CARD = 1.4
export const AD_OUT_CARD = 1.1

export const LIMITS = {
  title: 120,
  kicker: 30,
  ticker: 400,
  adText: 160,
  introText: 120,
  capText: 90,
  capSub: 70,
  ads: 6,
  captions: 12,
}

// ---- normalisation ----

const num = (v: unknown, def: number, min: number, max: number) => {
  const n = typeof v === 'number' ? v : typeof v === 'string' ? Number(v) : NaN
  return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : def
}
const str = (v: unknown, max: number, def = '') => (typeof v === 'string' ? v.slice(0, max) : def)
const bool = (v: unknown, def: boolean) => (typeof v === 'boolean' ? v : def)
const oneOf = <T extends string>(v: unknown, list: readonly T[], def: T): T => (list.includes(v as T) ? (v as T) : def)

function mediaRef(v: unknown): MediaRef | null {
  if (!v || typeof v !== 'object') return null
  const o = v as Record<string, unknown>
  const id = num(o.id, 0, 0, Number.MAX_SAFE_INTEGER)
  if (!id || typeof o.url !== 'string') return null
  return {
    id,
    url: o.url,
    title: str(o.title, 200),
    duration: typeof o.duration === 'number' && Number.isFinite(o.duration) ? o.duration : null,
  }
}

export function normalizeConfig(raw: unknown): EditConfig {
  const d = DEFAULT_CONFIG
  const o = (raw && typeof raw === 'object' ? raw : {}) as Record<string, any>
  const trim = o.trim ?? {}
  const frame = o.frame ?? {}
  const logo = o.logo ?? {}
  const intro = o.intro ?? {}
  const outro = o.outro ?? {}
  const audio = o.audio ?? {}
  const end = trim.end === null || trim.end === undefined ? null : num(trim.end, 0, 0, 86400)

  return {
    v: 1,
    trim: { start: num(trim.start, 0, 0, 86400), end: end && end > 0 ? end : null },
    format: oneOf(o.format, ['source', '16:9', '9:16', '1:1', '4:5'] as const, d.format),
    fit: oneOf(o.fit, ['blur', 'cover', 'black'] as const, d.fit),
    quality: oneOf(o.quality, ['720', '1080'] as const, d.quality),
    frame: {
      id: oneOf(frame.id, ['none', ...FRAMES.map((f) => f.id)] as FrameId[], d.frame.id),
      title: str(frame.title, LIMITS.title),
      kicker: str(frame.kicker, LIMITS.kicker),
      date: bool(frame.date, d.frame.date),
      footer: oneOf(frame.footer, FOOTERS.map((f) => f.id), d.frame.footer),
      ticker: str(frame.ticker, LIMITS.ticker),
      adText: str(frame.adText, LIMITS.adText),
      adImage: mediaRef(frame.adImage),
    },
    logo: {
      on: bool(logo.on, d.logo.on),
      style: oneOf(logo.style, LOGO_STYLES.map((s) => s.id), d.logo.style),
      corner: oneOf(logo.corner, ['tr', 'tl', 'br', 'bl'] as const, d.logo.corner),
      size: num(logo.size, d.logo.size, 3, 14),
      every: num(logo.every, d.logo.every, 6, 60),
      plate: bool(logo.plate, d.logo.plate),
      opacity: num(logo.opacity, d.logo.opacity, 0.3, 1),
    },
    intro: {
      id: oneOf(intro.id, ['none', ...INTROS.map((i) => i.id)] as IntroId[], d.intro.id),
      text: str(intro.text, LIMITS.introText),
      clip: mediaRef(intro.clip),
    },
    outro: {
      id: oneOf(outro.id, ['none', ...OUTROS.map((i) => i.id)] as OutroId[], d.outro.id),
      clip: mediaRef(outro.clip),
    },
    ads: (Array.isArray(o.ads) ? o.ads : [])
      .map((a: any) => {
        const clip = mediaRef(a?.clip)
        return clip ? { at: num(a.at, 0, 0, 86400), clip, bumper: bool(a.bumper, true) } : null
      })
      .filter(Boolean)
      .slice(0, LIMITS.ads) as AdBreak[],
    captions: (Array.isArray(o.captions) ? o.captions : [])
      .map((c: any) => ({
        text: str(c?.text, LIMITS.capText),
        sub: str(c?.sub, LIMITS.capSub),
        style: oneOf(c?.style, CAPTION_STYLES.map((x) => x.id), 'nameplate'),
        pos: oneOf(c?.pos, ['bl', 'br', 'tl', 'tr', 'bc'] as const, 'bl'),
        at: num(c?.at, 0, 0, 86400),
        dur: c?.dur === null ? null : num(c?.dur, 5, 1, 3600),
      }))
      .filter((c: Caption) => c.text.trim())
      .slice(0, LIMITS.captions),
    audio: {
      main: num(audio.main, d.audio.main, 0, 2),
      clips: num(audio.clips, d.audio.clips, 0, 2),
      sfx: bool(audio.sfx, d.audio.sfx),
    },
  }
}

/** Fields that describe one particular video (not a house style). */
export function styleOnly(cfg: EditConfig): EditConfig {
  return {
    ...cfg,
    trim: { start: 0, end: null },
    frame: { ...cfg.frame, title: '', ticker: '' },
    intro: { ...cfg.intro, text: '' },
    ads: [],
    captions: [],
  }
}

/** Whether anything at all would change the video. */
export function hasEdits(cfg: EditConfig, srcDuration: number | null): boolean {
  return (
    cfg.frame.id !== 'none' ||
    cfg.logo.on ||
    cfg.intro.id !== 'none' ||
    cfg.outro.id !== 'none' ||
    cfg.ads.length > 0 ||
    cfg.captions.length > 0 ||
    cfg.format !== 'source' ||
    cfg.trim.start > 0.05 ||
    (cfg.trim.end !== null && srcDuration !== null && cfg.trim.end < srcDuration - 0.05) ||
    cfg.audio.main !== 1
  )
}
