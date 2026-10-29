/**
 * Round 21 — video editor ("ভিডিও এডিট"): the edit settings, their
 * defaults, and the catalogue of built-in frames / intros / outros.
 *
 * An EditConfig is plain JSON: it is saved on the video post (so an edit
 * can be reopened from the original file), in the browser (draft), and as
 * shared presets. normalizeConfig() accepts anything and returns a valid
 * config, so an old or hand-edited value can never break the editor.
 */
import type { DenoiseLevel } from './denoise'

export type FormatId = 'source' | '16:9' | '9:16' | '1:1' | '4:5'
export type FitMode = 'blur' | 'cover' | 'black'
export type FrameId = 'none' | 'breaking' | 'news' | 'live' | 'special' | 'wave' | 'minimal' | 'exclusive' | 'sports' | 'election' | 'alert' | 'glass' | 'campus' | 'showbiz' | 'cinema' | 'music'
/** How big the frame bands, their text and the captions are (auto = bigger for reels). */
export type FrameSize = 'auto' | 's' | 'm' | 'l' | 'xl'
export type FooterMode = 'none' | 'ticker' | 'ad-text' | 'ad-image' | 'brand'
export type IntroId = 'none' | 'meeha-logo' | 'breaking' | 'headline' | 'special' | 'swipe' | 'live' | 'countdown' | 'custom'
export type OutroId = 'none' | 'meeha-social' | 'thanks' | 'follow' | 'custom'
export type LogoStyle = 'reveal' | 'spin' | 'pulse' | 'static'
export type Corner = 'tr' | 'tl' | 'br' | 'bl'
export type Quality = '720' | '1080'
/* brand music catalogue (the pieces themselves are in music.ts) */
export type MusicId = 'signature' | 'pulse' | 'calm' | 'showbiz' | 'desi'

export const MUSIC: { id: MusicId; name: string; hint: string }[] = [
  { id: 'signature', name: 'মীহা সিগনেচার', hint: 'সব খবরে মানানসই · উজ্জ্বল, স্থির ছন্দ' },
  { id: 'pulse', name: 'ব্রেকিং পালস', hint: 'জরুরি ও ব্রেকিং খবর · ঘড়ির টিকটিক' },
  { id: 'calm', name: 'শান্ত পটভূমি', hint: 'সাক্ষাৎকার, মানবিক ও ফিচার · নরম পিয়ানো' },
  { id: 'showbiz', name: 'বিনোদন পপ', hint: 'বিনোদন, শোবিজ ও লাইফস্টাইল · হালকা পপ' },
  { id: 'desi', name: 'দেশি সুর', hint: 'দেশ, সংস্কৃতি ও উৎসব · বাঁশি, দোতারা ও তবলা' },
]

export const DEFAULT_MUSIC: MusicId = 'signature'

export type MusicChoice = 'none' | MusicId
/** Voice clean-up for the main video: EQ + compression ("clear"), stronger for noisy field reports. */
export type VoiceMode = 'off' | 'clear' | 'strong'

export interface AudioSettings {
  main: number
  clips: number
  sfx: boolean
  /** Brand music bed, looped under the whole video. */
  music: MusicChoice
  /** Music level, 0..1 (before ducking). */
  musicVol: number
  /** Dip the music while someone is speaking. */
  duck: boolean
  /** Under everything, or only under the news itself (not intro / outro / ads). */
  musicOver: 'all' | 'main'
  voice: VoiceMode
  denoise: DenoiseLevel
}

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

export type CaptionStyle = 'nameplate' | 'tag' | 'subtitle' | 'quote' | 'location'
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

/** A part of the original video to leave out (source seconds). */
export interface Cut {
  from: number
  to: number
}

export type LookFilter = 'none' | 'vivid' | 'warm' | 'cool' | 'bw' | 'cinema' | 'fade' | 'night'

/** Picture adjustments for the main video (tools tab). */
export interface Look {
  filter: LookFilter
  /** 1 = unchanged. */
  brightness: number
  contrast: number
  saturation: number
  /** 1 = no zoom; the picture is enlarged around (panX, panY) ∈ [-1, 1]. */
  zoom: number
  panX: number
  panY: number
  rotate: 0 | 90 | 180 | 270
  flip: boolean
}

/**
 * A blurred / pixelated box over the main video (faces, number plates…).
 * x/y/w/h are fractions of the picture as shown, so the box follows the
 * picture when it is zoomed or rotated.
 */
export interface BlurBox {
  x: number
  y: number
  w: number
  h: number
  mode: 'blur' | 'pixel'
  /** Source seconds; dur null = until the end. */
  at: number
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
    size: FrameSize
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
  audio: AudioSettings
  cuts: Cut[]
  look: Look
  blurs: BlurBox[]
}

export const DEFAULT_LOOK: Look = { filter: 'none', brightness: 1, contrast: 1, saturation: 1, zoom: 1, panX: 0, panY: 0, rotate: 0, flip: false }

export const DEFAULT_CONFIG: EditConfig = {
  v: 1,
  trim: { start: 0, end: null },
  format: 'source',
  fit: 'blur',
  quality: '1080',
  frame: { id: 'none', title: '', kicker: '', date: true, footer: 'ticker', ticker: '', adText: '', adImage: null, size: 'auto' },
  logo: { on: false, style: 'reveal', corner: 'tr', size: 6, every: 12, plate: true, opacity: 1 },
  intro: { id: 'none', text: '', clip: null },
  outro: { id: 'none', clip: null },
  ads: [],
  captions: [],
  audio: { main: 1, clips: 1, sfx: true, music: DEFAULT_MUSIC, musicVol: 0.25, duck: true, musicOver: 'all', voice: 'off', denoise: 'off' },
  cuts: [],
  look: { filter: 'none', brightness: 1, contrast: 1, saturation: 1, zoom: 1, panX: 0, panY: 0, rotate: 0, flip: false },
  blurs: [],
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
  { id: 'sports', name: 'খেলা', hint: 'সবুজ মাঠ, দ্রুতগতির রেখা', label: 'খেলা', swatch: ['#065F46', '#A3E635'] },
  { id: 'election', name: 'নির্বাচন', hint: 'গাঢ় বেগুনি ও কমলা, তারার ঝিলিক', label: 'নির্বাচন', swatch: ['#1E1B4B', '#F59E0B'] },
  { id: 'alert', name: 'জরুরি সতর্কতা', hint: 'কালো-লাল, জ্বলা-নেভা হলুদ সতর্ক রেখা', label: 'সতর্কতা', swatch: ['#111111', '#F59E0B'] },
  { id: 'glass', name: 'আধুনিক কাচ', hint: 'ভিডিওর ঝাপসা কাচের মতো ব্যাকগ্রাউন্ড', label: 'শিরোনাম', swatch: ['#1F2937', '#38BDF8'] },
  { id: 'campus', name: 'ক্যাম্পাস', hint: 'উজ্জ্বল সাদা-নীল, কমলা ফিতা', label: 'ক্যাম্পাস', swatch: ['#EFF6FF', '#F97316'] },
  { id: 'showbiz', name: 'বিনোদন', hint: 'গোলাপি-বেগুনি, ঝিকমিক তারা ও আলো', label: 'বিনোদন', swatch: ['#BE185D', '#FDE047'] },
  { id: 'cinema', name: 'সিনেমা', hint: 'কালো পর্দা, সোনালি স্পটলাইট ও চলমান ফিল্ম রিল', label: 'সিনেমা', swatch: ['#0A0A0A', '#FACC15'] },
  { id: 'music', name: 'গান-বাজনা', hint: 'নীল-বেগুনি, তালে তালে ইকুয়ালাইজার', label: 'মিউজিক', swatch: ['#312E81', '#22D3EE'] },
]

export const FRAME_SIZES: { id: FrameSize; name: string; k: number }[] = [
  { id: 'auto', name: 'স্বয়ংক্রিয়', k: 0 },
  { id: 's', name: 'ছোট', k: 1 },
  { id: 'm', name: 'মাঝারি', k: 1.2 },
  { id: 'l', name: 'বড়', k: 1.4 },
  { id: 'xl', name: 'খুব বড়', k: 1.6 },
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
  { id: 'swipe', name: 'রঙিন সোয়াইপ', hint: 'রঙের প্যানেল সরে লোগো ও শিরোনাম', duration: 3.2 },
  { id: 'live', name: 'সরাসরি', hint: 'রাডারের মতো লাল ঢেউ, “সরাসরি”', duration: 3 },
  { id: 'countdown', name: 'কাউন্টডাউন', hint: '৩ · ২ · ১ — তারপর শিরোনাম', duration: 3.6 },
  { id: 'custom', name: 'গ্যালারি থেকে', hint: 'নিজের ইন্ট্রো ভিডিও' },
]

export const OUTROS: SceneInfo<OutroId>[] = [
  { id: 'meeha-social', name: 'মিহা সোশ্যাল আউট্রো', hint: 'আপনার দেওয়া আউট্রো — সোশ্যাল লিংকসহ (৮ সেকেন্ড)' },
  { id: 'thanks', name: 'ধন্যবাদ কার্ড', hint: 'লোগো, “দেখার জন্য ধন্যবাদ” ও ওয়েবসাইট', duration: 4 },
  { id: 'follow', name: 'ফলো করুন', hint: 'লাইক · শেয়ার · ফলো বোতামে ট্যাপের এনিমেশন', duration: 4.5 },
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
  { id: 'quote', name: 'উক্তি', hint: 'বড় উদ্ধৃতি চিহ্নসহ বক্তব্য + বক্তার নাম' },
  { id: 'location', name: 'স্থান', hint: 'লাল পিন আইকনসহ জায়গার নাম' },
]

export const LOOK_FILTERS: { id: LookFilter; name: string; css: string }[] = [
  { id: 'none', name: 'আসল', css: '' },
  { id: 'vivid', name: 'উজ্জ্বল', css: 'saturate(1.35) contrast(1.08)' },
  { id: 'warm', name: 'উষ্ণ', css: 'sepia(0.18) saturate(1.15) brightness(1.03)' },
  { id: 'cool', name: 'শীতল', css: 'hue-rotate(10deg) saturate(1.05) contrast(1.04)' },
  { id: 'cinema', name: 'সিনেমাটিক', css: 'contrast(1.15) saturate(0.85) brightness(0.96)' },
  { id: 'fade', name: 'পুরনো', css: 'sepia(0.35) contrast(0.9) brightness(1.05)' },
  { id: 'bw', name: 'সাদা-কালো', css: 'grayscale(1) contrast(1.1)' },
  { id: 'night', name: 'রাতের ভিডিও', css: 'brightness(1.35) contrast(1.12) saturate(1.1)' },
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
  { label: 'স্থান', caption: { text: '', sub: '', style: 'location', pos: 'tl', dur: 6 } },
  { label: 'উক্তি / বক্তব্য', caption: { text: '', sub: '', style: 'quote', pos: 'bc', dur: 6 } },
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
  cuts: 20,
  blurs: 8,
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
      size: oneOf(frame.size, FRAME_SIZES.map((x) => x.id), d.frame.size),
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
        pos: oneOf(c?.pos, ['bl', 'br', 'tl', 'tr', 'bc'] as const, c?.style === 'subtitle' || c?.style === 'quote' ? 'bc' : 'bl'),
        at: num(c?.at, 0, 0, 86400),
        dur: c?.dur === null ? null : num(c?.dur, 5, 1, 3600),
      }))
      .filter((c: Caption) => c.text.trim())
      .slice(0, LIMITS.captions),
    audio: {
      main: num(audio.main, d.audio.main, 0, 2),
      clips: num(audio.clips, d.audio.clips, 0, 2),
      sfx: bool(audio.sfx, d.audio.sfx),
      music: oneOf(audio.music, ['none', ...MUSIC.map((m) => m.id)] as const, d.audio.music),
      musicVol: num(audio.musicVol, d.audio.musicVol, 0, 1),
      duck: bool(audio.duck, d.audio.duck),
      musicOver: oneOf(audio.musicOver, ['all', 'main'] as const, d.audio.musicOver),
      voice: oneOf(audio.voice, ['off', 'clear', 'strong'] as const, d.audio.voice),
      denoise: oneOf(audio.denoise, ['off', 'low', 'medium', 'high'] as const, d.audio.denoise),
    },
    cuts: (Array.isArray(o.cuts) ? o.cuts : [])
      .map((c: any) => ({ from: num(c?.from, 0, 0, 86400), to: num(c?.to, 0, 0, 86400) }))
      .filter((c: Cut) => c.to - c.from >= 0.1)
      .slice(0, LIMITS.cuts),
    look: normalizeLook(o.look),
    blurs: (Array.isArray(o.blurs) ? o.blurs : [])
      .map((b: any) => {
        const w = num(b?.w, 0.3, 0.02, 1)
        const h = num(b?.h, 0.3, 0.02, 1)
        return {
          x: num(b?.x, 0.35, 0, 1 - w),
          y: num(b?.y, 0.35, 0, 1 - h),
          w,
          h,
          mode: oneOf(b?.mode, ['blur', 'pixel'] as const, 'blur'),
          at: num(b?.at, 0, 0, 86400),
          dur: b?.dur === null || b?.dur === undefined ? null : num(b.dur, 5, 0.5, 86400),
        }
      })
      .slice(0, LIMITS.blurs),
  }
}

function normalizeLook(raw: unknown): Look {
  const l = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>
  const d = DEFAULT_LOOK
  const rot = num(l.rotate, 0, 0, 270)
  return {
    filter: oneOf(l.filter, LOOK_FILTERS.map((f) => f.id), d.filter),
    brightness: num(l.brightness, 1, 0.5, 1.6),
    contrast: num(l.contrast, 1, 0.5, 1.6),
    saturation: num(l.saturation, 1, 0, 2),
    zoom: num(l.zoom, 1, 1, 3),
    panX: num(l.panX, 0, -1, 1),
    panY: num(l.panY, 0, -1, 1),
    rotate: ([0, 90, 180, 270] as const).find((r) => r === rot) ?? 0,
    flip: bool(l.flip, false),
  }
}

/** CSS filter string for the main video's look ('' = none). */
export function lookFilter(l: Look): string {
  const parts: string[] = []
  const preset = LOOK_FILTERS.find((f) => f.id === l.filter)?.css
  if (preset) parts.push(preset)
  if (Math.abs(l.brightness - 1) > 0.005) parts.push(`brightness(${l.brightness.toFixed(3)})`)
  if (Math.abs(l.contrast - 1) > 0.005) parts.push(`contrast(${l.contrast.toFixed(3)})`)
  if (Math.abs(l.saturation - 1) > 0.005) parts.push(`saturate(${l.saturation.toFixed(3)})`)
  return parts.join(' ')
}

/** The parts of [start, end] that stay after the cuts, in order. */
export function keptRanges(cuts: Cut[], start: number, end: number): { from: number; to: number }[] {
  const sorted = cuts
    .map((c) => ({ from: Math.max(start, Math.min(c.from, c.to)), to: Math.min(end, Math.max(c.from, c.to)) }))
    .filter((c) => c.to - c.from > 0.05)
    .sort((a, b) => a.from - b.from)
  const out: { from: number; to: number }[] = []
  let cursor = start
  for (const c of sorted) {
    if (c.from > cursor + 0.05) out.push({ from: cursor, to: c.from })
    cursor = Math.max(cursor, c.to)
  }
  if (end > cursor + 0.05) out.push({ from: cursor, to: end })
  return out.length ? out : [{ from: start, to: Math.min(end, start + 0.5) }]
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
    cuts: [],
    blurs: [],
    // colour is a house style; zoom / rotation belong to one particular video
    look: { ...cfg.look, zoom: 1, panX: 0, panY: 0, rotate: 0, flip: false },
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
    cfg.audio.main !== 1 ||
    cfg.audio.music !== 'none' ||
    cfg.audio.voice !== 'off' ||
    cfg.audio.denoise !== 'off' ||
    cfg.cuts.length > 0 ||
    cfg.blurs.length > 0 ||
    lookFilter(cfg.look) !== '' ||
    cfg.look.zoom > 1.001 ||
    cfg.look.rotate !== 0 ||
    cfg.look.flip
  )
}
