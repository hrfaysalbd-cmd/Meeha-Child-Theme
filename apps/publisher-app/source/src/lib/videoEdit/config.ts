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
import type { MusicRemoveLevel } from './musicremove'
import { SFX_IDS, type SfxId } from './sfxlib'
import { HOUSE_ADS, type HouseAdId } from './houseads'

/** Round 26: a sound from the library, placed at a moment of the news (source seconds). */
export interface PlacedSound {
  id: SfxId
  at: number
  /** 0..1.5 */
  vol: number
}

export type FormatId = 'source' | '16:9' | '9:16' | '1:1' | '4:5'
export type FileSize = 'small' | 'balanced' | 'high'
export const FILE_SIZES: { id: FileSize; name: string; hint: string }[] = [
  { id: 'small', name: 'ছোট — দ্রুত আপলোড', hint: 'দুর্বল নেটে ভালো; সংবাদ ও কথার ভিডিওতে মান যথেষ্ট' },
  { id: 'balanced', name: 'মাঝারি (প্রস্তাবিত)', hint: 'মান ও আকারের ভারসাম্য' },
  { id: 'high', name: 'বড় — সর্বোচ্চ মান', hint: 'খেলা বা দ্রুত দৃশ্য; আপলোডে বেশি সময়' },
]
export type FitMode = 'blur' | 'cover' | 'black'
export type FrameId = 'none' | 'breaking' | 'news' | 'live' | 'special' | 'wave' | 'minimal' | 'exclusive' | 'sports' | 'election' | 'alert' | 'glass' | 'campus' | 'showbiz' | 'cinema' | 'music' | 'nil' | 'tv' | 'ujjol' | 'investigation' | 'interview' | 'economy' | 'national' | 'weather' | 'crime' | 'health' | 'tech' | 'world' | 'gold'
/** How big the frame bands, their text and the captions are (auto = bigger for reels). */
export type FrameSize = 'auto' | 's' | 'm' | 'l' | 'xl'
export type FrameMotion = 'lively' | 'calm'
export type FooterMode = 'none' | 'ticker' | 'ad-text' | 'ad-image' | 'brand'
export type IntroId = 'none' | 'meeha-logo' | 'breaking' | 'headline' | 'special' | 'swipe' | 'live' | 'countdown' | 'nil-reveal' | 'tv-open' | 'investigation' | 'sports' | 'showbiz' | 'shatter' | 'globe' | 'kinetic' | 'split' | 'custom'
export type OutroId = 'none' | 'meeha-social' | 'thanks' | 'follow' | 'nil-end' | 'endscreen' | 'credits' | 'subscribe' | 'staytuned' | 'thanks-burst' | 'custom'
export type LogoStyle = 'reveal' | 'spin' | 'pulse' | 'static'
export type Corner = 'tr' | 'tl' | 'br' | 'bl'
export type Quality = '720' | '1080'
/* brand music catalogue (the pieces themselves are in music.ts) */
export type MusicId = 'signature' | 'pulse' | 'calm' | 'showbiz' | 'desi' | 'investigation' | 'sports' | 'tech' | 'sad' | 'anthem' | 'morning' | 'cinematic' | 'election' | 'weather' | 'lofi' | 'village'

/** Round 26: the music library is shown in collapsible groups. */
export type MusicGroup = 'news' | 'human' | 'fun' | 'deep'
export const MUSIC_GROUPS: { id: MusicGroup; name: string; hint: string }[] = [
  { id: 'news', name: 'সংবাদ', hint: 'প্রতিদিনের খবর, ব্রেকিং, অর্থনীতি' },
  { id: 'deep', name: 'অনুসন্ধান ও খেলা', hint: 'টানটান রহস্য, দ্রুত ছন্দ' },
  { id: 'human', name: 'মানবিক ও ফিচার', hint: 'সাক্ষাৎকার, শোক, সকাল' },
  { id: 'fun', name: 'বিনোদন ও উৎসব', hint: 'শোবিজ, দেশি সুর, জাতীয় দিবস' },
]

export const MUSIC: { id: MusicId; name: string; hint: string; group: MusicGroup; isNew?: boolean }[] = [
  { id: 'signature', name: 'মীহা সিগনেচার', hint: 'সব খবরে মানানসই · উজ্জ্বল, স্থির ছন্দ', group: 'news' },
  { id: 'pulse', name: 'ব্রেকিং পালস', hint: 'জরুরি ও ব্রেকিং খবর · ঘড়ির টিকটিক', group: 'news' },
  { id: 'election', name: 'নির্বাচন ও রাজনীতি', hint: 'ভোট, সংসদ, রাজনীতি · মার্চিং স্নেয়ার ও স্ট্রিং', group: 'news', isNew: true },
  { id: 'tech', name: 'অর্থনীতি ও প্রযুক্তি', hint: 'বাজার, ব্যবসা, প্রযুক্তি · মারিম্বা ও প্রতিধ্বনি', group: 'news' },
  { id: 'investigation', name: 'অনুসন্ধান', hint: 'তদন্ত, অপরাধ, রহস্য · নিচু স্পন্দন ও হার্টবিট', group: 'deep' },
  { id: 'cinematic', name: 'সিনেমাটিক ডকুমেন্টারি', hint: 'বড় প্রতিবেদন, ডকুমেন্টারি · গভীর প্যাড ও টিম্পানি', group: 'deep', isNew: true },
  { id: 'sports', name: 'খেলার মাঠ', hint: 'খেলা ও হাইলাইটস · জোরালো বিট ও ব্রাস', group: 'deep' },
  { id: 'calm', name: 'শান্ত পটভূমি', hint: 'সাক্ষাৎকার, মানবিক ও ফিচার · নরম পিয়ানো', group: 'human' },
  { id: 'sad', name: 'শোক ও মানবিক', hint: 'দুর্ঘটনা, শোক, মানবিক গল্প · ধীর পিয়ানো', group: 'human' },
  { id: 'morning', name: 'সকাল ও জীবনধারা', hint: 'লাইফস্টাইল, ভ্রমণ, খাবার · গিটার ও শেকার', group: 'human' },
  { id: 'weather', name: 'আবহাওয়া ও প্রকৃতি', hint: 'আবহাওয়া, পরিবেশ, কৃষি · মারিম্বা ও বাঁশি', group: 'human', isNew: true },
  { id: 'lofi', name: 'লো-ফাই ফিচার', hint: 'ব্যাখ্যা, ফিচার, টিপস · নরম ই-পিয়ানো ও বিট', group: 'human', isNew: true },
  { id: 'showbiz', name: 'বিনোদন পপ', hint: 'বিনোদন, শোবিজ ও লাইফস্টাইল · হালকা পপ', group: 'fun' },
  { id: 'desi', name: 'দেশি সুর', hint: 'দেশ, সংস্কৃতি ও উৎসব · বাঁশি, দোতারা ও তবলা', group: 'fun' },
  { id: 'anthem', name: 'জাতীয় ও উৎসব', hint: 'জাতীয় দিবস, বিজয়, উৎসব · বড় কর্ড ও টিম্পানি', group: 'fun' },
  { id: 'village', name: 'গ্রামবাংলা', hint: 'গ্রাম, লোকজ সংস্কৃতি, মেলা · তার-বাদ্য, তবলা ও বাঁশি', group: 'fun', isNew: true },
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
  /** Round 26: lower a music bed recorded under the speech of the main video. */
  unmusic: MusicRemoveLevel
  /** Target loudness of the finished video (LUFS), or off. */
  loudness: 'off' | '-14' | '-16'
}

/** A file from the media library (video clip or image). */
/**
 * Round 27 — a recorded or uploaded voice laid over the video. It starts
 * at source second `at` of the main video (so it stays with its pictures
 * when intros/ads are added) and plays at normal speed.
 */
export interface VoiceOver {
  clip: MediaRef
  at: number
  /** 0..2 */
  vol: number
  /** How loud everything else stays while the voice speaks (0..1, 1 = no dip). */
  duck: number
  /** Voice clean-up (EQ + compressor). */
  clean: boolean
  /** Seconds skipped at the start of the recording. */
  offset: number
  /** Round 29: main-video second where the voice stops (null = plays to its end). */
  until: number | null
}

/** Round 29: one subtitle line (main-video seconds). */
export interface SubLine {
  at: number
  end: number
  text: string
}

export type SubStyle = 'box' | 'outline' | 'pop'
export const SUB_STYLES: { id: SubStyle; name: string; hint: string }[] = [
  { id: 'box', name: 'কালো পটভূমি', hint: 'টিভির মতো, সব দৃশ্যে পড়া যায়' },
  { id: 'outline', name: 'সাদা লেখা, কালো রেখা', hint: 'ছবি কম ঢাকে' },
  { id: 'pop', name: 'রিল স্টাইল', hint: 'হলুদ পটভূমি, বড় ও মোটা লেখা' },
]

/** Round 29: one-click starting looks for common news videos (only the design changes, never the text or edits). */
export const QUICK_LOOKS: { id: string; name: string; hint: string; apply: (c: EditConfig) => EditConfig }[] = [
  {
    id: 'breaking-reel',
    name: 'ব্রেকিং রিল',
    hint: '৯:১৬, ব্রেকিং ফ্রেম ও ইন্ট্রো, প্রগ্রেস বার',
    apply: (c) => ({ ...c, format: '9:16', frame: { ...c.frame, id: 'breaking', motion: 'lively' }, intro: { ...c.intro, id: 'breaking', cover: true }, outro: { ...c.outro, id: 'follow' }, progress: { ...c.progress, on: true, color: 'red' }, watermark: { ...c.watermark, on: true } }),
  },
  {
    id: 'news',
    name: 'সাধারণ সংবাদ',
    hint: '১৬:৯, মিহা ফ্রেম, শিরোনাম ইন্ট্রো',
    apply: (c) => ({ ...c, format: '16:9', frame: { ...c.frame, id: 'nil', motion: 'lively' }, intro: { ...c.intro, id: 'nil-reveal', cover: true }, outro: { ...c.outro, id: 'nil-end' }, progress: { ...c.progress, on: false }, watermark: { ...c.watermark, on: true } }),
  },
  {
    id: 'reel-lower',
    name: 'রিল — শিরোনাম নিচে',
    hint: '৯:১৬, টিভি ফ্রেম, শিরোনাম ছবির নিচে',
    apply: (c) => ({ ...c, format: '9:16', frame: { ...c.frame, id: 'tv', titlePos: 'bottom', motion: 'lively' }, intro: { ...c.intro, id: 'tv-open', cover: true }, outro: { ...c.outro, id: 'subscribe' }, progress: { ...c.progress, on: true, color: 'yellow' }, watermark: { ...c.watermark, on: true } }),
  },
  {
    id: 'interview',
    name: 'সাক্ষাৎকার',
    hint: 'সাক্ষাৎকার ফ্রেম, শান্ত অ্যানিমেশন',
    apply: (c) => ({ ...c, frame: { ...c.frame, id: 'interview', motion: 'calm' }, intro: { ...c.intro, id: 'headline', cover: true }, outro: { ...c.outro, id: 'credits' }, watermark: { ...c.watermark, on: true } }),
  },
  {
    id: 'sports',
    name: 'খেলা',
    hint: 'খেলার ফ্রেম, ইন্ট্রো ও মিউজিক',
    apply: (c) => ({ ...c, frame: { ...c.frame, id: 'sports', motion: 'lively' }, intro: { ...c.intro, id: 'sports', cover: true }, outro: { ...c.outro, id: 'follow' }, audio: { ...c.audio, music: 'sports' }, watermark: { ...c.watermark, on: true } }),
  },
]

export interface MediaRef {
  id: number
  url: string
  title: string
  duration?: number | null
}

export interface AdBreak {
  /** Where in the ORIGINAL video the ad goes (seconds). */
  at: number
  /** An ad clip from the gallery, or null for a Meeha house ad. */
  clip: MediaRef | null
  /** Round 26: a drawn Meeha house ad (houseads.ts) instead of a clip. */
  house: HouseAdId | null
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
  /** Round 26: found by "নীরব অংশ কাটুন" (can be removed together). */
  auto?: boolean
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

/** A stretch of the original video played faster or slower (source seconds). */
export interface SpeedRange {
  from: number
  to: number
  rate: number
  /**
   * Round 26 — slow motion as an action replay: the part plays first at
   * normal speed with its own sound, then again slowed down.
   */
  replay: boolean
  /** Round 26 — the part's own sound, slowed / sped up with the picture ('on'), or silent ('off'). */
  sound: 'on' | 'off'
}

/** A held frame: the picture stops at `at` (source s) for `dur` seconds, optionally with a line of text. */
export interface Freeze {
  at: number
  dur: number
  text: string
}

export type TransitionId = 'none' | 'fade' | 'slide' | 'zoom' | 'wipe' | 'flash' | 'dip'

export type StickerKind = 'arrow' | 'circle' | 'box' | 'highlight' | 'cross' | 'check' | 'focus'

/** A marker drawn over the main picture (fractions of the picture, like BlurBox). */
export interface Sticker {
  kind: StickerKind
  x: number
  y: number
  w: number
  h: number
  /** Arrow direction in degrees (0 = pointing right, 90 = down). */
  angle: number
  color: string
  label: string
  at: number
  dur: number | null
}

/** A second video shown with the main one: a corner window, or side by side. */
export interface Pip {
  clip: MediaRef | null
  layout: 'pip' | 'split'
  corner: Corner
  /** Window width as a fraction of the video box (pip layout). */
  size: number
  /** Main-video source seconds where it appears; dur null = to the end. */
  at: number
  dur: number | null
  sound: 'main' | 'pip' | 'both'
  label: string
  mainLabel: string
}

/** Where the picture's centre of interest is, sampled every `step` source seconds (0..1 of the picture). */
export interface Reframe {
  on: boolean
  step: number
  xs: number[]
  ys: number[]
}

export type ThumbTemplate =
  | 'bold'
  | 'breaking'
  | 'face'
  | 'minimal'
  // round 26
  | 'nil-bar'
  | 'nil-split'
  | 'nil-frame'
  | 'nil-quote'
  | 'nil-card'
  | 'top'
  | 'lower'
  | 'glass'
  | 'live'
  | 'alert'
  | 'exclusive'
  | 'duo'
  // round 27
  | 'nil-poster'
  | 'nil-circle'
  | 'urgent'
  | 'factcheck'
  | 'cinematic'
  | 'neon'
  | 'weather'
  | 'crime'
  | 'interview'
  | 'reaction'
  | 'question'
  | 'number'
  | 'shock'
  | 'pointer'
  | 'sports'
  | 'showbiz'
  | 'economy'
  | 'campus'

/** Round 26: the thumbnail's shape. 'video' = the video's own shape. */
export type ThumbSize = '16:9' | '9:16' | '1:1' | '4:5' | 'video'
export type ThumbPaletteId = 'auto' | 'nil' | 'red' | 'night' | 'green' | 'purple' | 'orange' | 'teal'
export type ThumbLogo = 'auto' | 'tl' | 'tr' | 'bl' | 'br' | 'off'
export type ThumbLayerKind = 'text' | 'burst' | StickerKind

/**
 * Round 26: a free layer on the thumbnail, dragged on the preview. x/y is
 * the centre as a fraction of the thumbnail; for text `h` is the font size
 * (fraction of the short side), for shapes w/h are the box.
 */
export interface ThumbLayer {
  kind: ThumbLayerKind
  x: number
  y: number
  w: number
  h: number
  angle: number
  color: string
  text: string
  /** Text: on a coloured plate (true) or outlined (false). */
  plate: boolean
}

export interface ThumbDesign {
  on: boolean
  /** Source second of the picture; null = early in the news. */
  at: number | null
  template: ThumbTemplate
  /** **শব্দ** = highlighted, like the photocards. */
  title: string
  badge: string
  face: MediaRef | null
  size: ThumbSize
  /** Round 26 */
  sub: string
  palette: ThumbPaletteId
  /** Headline size, 0.75–1.35. */
  scale: number
  logo: ThumbLogo
  /** Picture punch: none, brighter colours, or darker for text. */
  grade: 'none' | 'pop' | 'dark'
  layers: ThumbLayer[]
}

export interface EditConfig {
  v: 1
  trim: { start: number; end: number | null }
  format: FormatId
  fit: FitMode
  quality: Quality
  /** Round 27: file size vs picture quality (smaller = faster upload). */
  fileSize: FileSize
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
    /** Round 27: height of the top / bottom band (1 = standard, up to 2). */
    top: number
    /** Round 29: where the title band goes: above the picture, or under it (above the ticker). */
    titlePos: 'top' | 'bottom'
    bottom: number
    /** Round 27: 'lively' adds a light sweep and accent motion; 'calm' is the old look. */
    motion: FrameMotion
  }
  /** Round 28: the Meeha News watermark that fades from place to place over the news. */
  watermark: {
    on: boolean
    style: WatermarkStyle
    /** Height as % of the video's shorter side. */
    size: number
    /** Seconds it stays in one place (hop) / one lap (glide). */
    every: number
    opacity: number
    /** Show the site address under the logo. */
    site: boolean
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
  /** `cover` (round 29): the intro's finished design shows first for a moment, so the video never opens on black. */
  intro: { id: IntroId; text: string; clip: MediaRef | null; cover: boolean }
  outro: { id: OutroId; clip: MediaRef | null }
  ads: AdBreak[]
  captions: Caption[]
  /** Round 29: subtitles (typed, pasted or from an .srt file). */
  subtitles: { on: boolean; style: SubStyle; size: number; shift: number; items: SubLine[] }
  /** Round 29: a thin bar along the bottom of the picture showing how much of the news is left. */
  progress: { on: boolean; color: 'red' | 'yellow' | 'blue' | 'white' }
  audio: AudioSettings
  cuts: Cut[]
  look: Look
  blurs: BlurBox[]
  speeds: SpeedRange[]
  freezes: Freeze[]
  transition: { id: TransitionId; dur: number; cuts: boolean }
  stickers: Sticker[]
  pip: Pip | null
  reframe: Reframe
  thumb: ThumbDesign
  sounds: PlacedSound[]
  voices: VoiceOver[]
}

export const DEFAULT_LOOK: Look = { filter: 'none', brightness: 1, contrast: 1, saturation: 1, zoom: 1, panX: 0, panY: 0, rotate: 0, flip: false }

export const DEFAULT_CONFIG: EditConfig = {
  v: 1,
  trim: { start: 0, end: null },
  format: 'source',
  fit: 'blur',
  quality: '1080',
  fileSize: 'balanced',
  frame: { id: 'none', title: '', kicker: '', date: true, footer: 'ticker', ticker: '', adText: '', adImage: null, size: 'auto', top: 1, bottom: 1, motion: 'lively', titlePos: 'top' },
  logo: { on: false, style: 'reveal', corner: 'tr', size: 6, every: 12, plate: true, opacity: 1 },
  watermark: { on: true, style: 'hop', size: 6, every: 9, opacity: 0.42, site: true },
  intro: { id: 'none', text: '', clip: null, cover: true },
  outro: { id: 'none', clip: null },
  ads: [],
  captions: [],
  subtitles: { on: true, style: 'box', size: 1, shift: 0, items: [] },
  progress: { on: false, color: 'red' },
  audio: { main: 1, clips: 1, sfx: true, music: DEFAULT_MUSIC, musicVol: 0.25, duck: true, musicOver: 'all', voice: 'off', denoise: 'off', unmusic: 'off', loudness: '-14' },
  cuts: [],
  look: { filter: 'none', brightness: 1, contrast: 1, saturation: 1, zoom: 1, panX: 0, panY: 0, rotate: 0, flip: false },
  blurs: [],
  speeds: [],
  freezes: [],
  transition: { id: 'none', dur: 0.6, cuts: true },
  stickers: [],
  pip: null,
  reframe: { on: false, step: 0.5, xs: [], ys: [] },
  thumb: { on: true, at: null, template: 'nil-bar', title: '', badge: '', face: null, size: '16:9', sub: '', palette: 'auto', scale: 1, logo: 'auto', grade: 'none', layers: [] },
  sounds: [],
  voices: [],
}

// ---- catalogue ----

/** Round 26: frames, intros and outros sit in collapsible sections, like the photocard designs. */
export type DesignGroup = 'nil' | 'news' | 'breaking' | 'special' | 'topic' | 'brand' | 'clip'
export const DESIGN_GROUPS: { id: DesignGroup; name: string; hint: string }[] = [
  { id: 'nil', name: 'মিহা নীল (নতুন)', hint: 'ফটোকার্ডের মতো নীল-সোনালি নকশা' },
  { id: 'news', name: 'সংবাদ', hint: 'প্রতিদিনের খবর' },
  { id: 'breaking', name: 'ব্রেকিং ও সরাসরি', hint: 'জরুরি, লাইভ, এক্সক্লুসিভ' },
  { id: 'special', name: 'বিশেষ ও অনুসন্ধান', hint: 'অনুসন্ধান, সাক্ষাৎকার, অর্থনীতি, নির্বাচন' },
  { id: 'topic', name: 'খেলা, বিনোদন ও দেশ', hint: 'খেলা, শোবিজ, সিনেমা, ক্যাম্পাস, জাতীয়' },
  { id: 'brand', name: 'মিহা ব্র্যান্ড', hint: 'লোগো ও সোশ্যাল' },
  { id: 'clip', name: 'নিজের ভিডিও', hint: 'গ্যালারি থেকে' },
]

export interface FrameInfo {
  id: Exclude<FrameId, 'none'>
  name: string
  hint: string
  group: DesignGroup
  isNew?: boolean
  /** Default footer label for the ticker. */
  label: string
  swatch: [string, string]
}

export const FRAMES: FrameInfo[] = [
  { id: 'world', name: 'আন্তর্জাতিক', hint: 'নীল, ঘুরন্ত তারের গ্লোব', label: 'বিশ্ব', swatch: ['#071A44', '#FFC20E'], group: 'nil', isNew: true },
  { id: 'crime', name: 'অপরাধ', hint: 'কালো, লাল-নীল পুলিশ আলো, হলুদ টেপ', label: 'অপরাধ', swatch: ['#0A0A0A', '#FACC15'], group: 'breaking', isNew: true },
  { id: 'gold', name: 'প্রিমিয়াম সোনালি', hint: 'কালো ও সোনালি রেখা, ঝিলিক', label: 'বিশেষ', swatch: ['#0B0905', '#D6B45E'], group: 'special', isNew: true },
  { id: 'weather', name: 'আবহাওয়া', hint: 'আকাশি নীল, সূর্য ও ভেসে চলা মেঘ', label: 'আবহাওয়া', swatch: ['#0C4A8F', '#FFD65A'], group: 'topic', isNew: true },
  { id: 'health', name: 'স্বাস্থ্য', hint: 'টিল সবুজ, চলমান হৃৎস্পন্দন রেখা', label: 'স্বাস্থ্য', swatch: ['#065F5B', '#E11D48'], group: 'topic', isNew: true },
  { id: 'tech', name: 'প্রযুক্তি', hint: 'গাঢ় নীল, সার্কিটে চলমান আলো', label: 'প্রযুক্তি', swatch: ['#020617', '#22D3EE'], group: 'topic', isNew: true },
  { id: 'nil', name: 'মিহা নীল', hint: 'নীল গ্রেডিয়েন্ট, সোনালি রেখা ও চিপ', label: 'শিরোনাম', swatch: ['#0B2A6B', '#FFC20E'], group: 'nil' },
  { id: 'tv', name: 'টিভি নিউজ', hint: 'চ্যানেলের মতো সোনালি স্ল্যাব, স্ক্যান লাইন', label: 'সংবাদ', swatch: ['#1546B8', '#FFC20E'], group: 'nil' },
  { id: 'ujjol', name: 'উজ্জ্বল নীল', hint: 'উজ্জ্বল নীল, সোনালি চলমান রেখা ও ফুটার', label: 'শিরোনাম', swatch: ['#2E6BFF', '#FFC20E'], group: 'nil' },
  { id: 'investigation', name: 'অনুসন্ধান', hint: 'গাঢ় নীল-কালো, নথির গ্রিড ও স্ক্যানিং আলো', label: 'অনুসন্ধান', swatch: ['#05070D', '#FFC20E'], group: 'special' },
  { id: 'interview', name: 'সাক্ষাৎকার', hint: 'বেগুনি-নীল, বড় উদ্ধৃতি চিহ্ন', label: 'সাক্ষাৎকার', swatch: ['#3730A3', '#FFC20E'], group: 'special' },
  { id: 'economy', name: 'অর্থনীতি', hint: 'টিল সবুজ, চলমান বাজার-রেখা', label: 'বাজার', swatch: ['#0F766E', '#FFC20E'], group: 'special' },
  { id: 'national', name: 'জাতীয়', hint: 'সবুজ ও লাল সূর্য — জাতীয় দিবস, দেশের খবর', label: 'জাতীয়', swatch: ['#006A4E', '#F42A41'], group: 'topic' },
  { id: 'breaking', name: 'ব্রেকিং নিউজ', hint: 'লাল, জ্বলজ্বলে ব্যাজ ও চলমান স্ক্রল', label: 'সর্বশেষ', swatch: ['#C8102E', '#111827'], group: 'breaking' },
  { id: 'news', name: 'সংবাদ', hint: 'মিহা নীল, ক্যাটেগরি চিপ ও তারিখ', label: 'শিরোনাম', swatch: ['#025AB2', '#0B2746'], group: 'news' },
  { id: 'live', name: 'সরাসরি', hint: 'জ্বলা-নেভা লাল বিন্দু, গাঢ় পটভূমি', label: 'সরাসরি', swatch: ['#0F172A', '#DC2626'], group: 'breaking' },
  { id: 'special', name: 'বিশেষ প্রতিবেদন', hint: 'গাঢ় নীল ও সোনালি রেখা', label: 'বিশেষ', swatch: ['#0B1220', '#E8B33A'], group: 'special' },
  { id: 'wave', name: 'মিহা ঢেউ', hint: 'চলমান ঢেউ — থিমের ঢেউ ডিজাইন', label: 'শিরোনাম', swatch: ['#025AB2', '#CC2A1F'], group: 'news' },
  { id: 'exclusive', name: 'এক্সক্লুসিভ', hint: 'কালো ও হলুদ টেপ', label: 'এক্সক্লুসিভ', swatch: ['#0A0A0A', '#FACC15'], group: 'breaking' },
  { id: 'minimal', name: 'মিনিমাল সাদা', hint: 'সাদা পটভূমি, পরিষ্কার লেখা', label: 'শিরোনাম', swatch: ['#FFFFFF', '#025AB2'], group: 'news' },
  { id: 'sports', name: 'খেলা', hint: 'সবুজ মাঠ, দ্রুতগতির রেখা', label: 'খেলা', swatch: ['#065F46', '#A3E635'], group: 'topic' },
  { id: 'election', name: 'নির্বাচন', hint: 'গাঢ় বেগুনি ও কমলা, তারার ঝিলিক', label: 'নির্বাচন', swatch: ['#1E1B4B', '#F59E0B'], group: 'special' },
  { id: 'alert', name: 'জরুরি সতর্কতা', hint: 'কালো-লাল, জ্বলা-নেভা হলুদ সতর্ক রেখা', label: 'সতর্কতা', swatch: ['#111111', '#F59E0B'], group: 'breaking' },
  { id: 'glass', name: 'আধুনিক কাচ', hint: 'ভিডিওর ঝাপসা কাচের মতো ব্যাকগ্রাউন্ড', label: 'শিরোনাম', swatch: ['#1F2937', '#38BDF8'], group: 'news' },
  { id: 'campus', name: 'ক্যাম্পাস', hint: 'উজ্জ্বল সাদা-নীল, কমলা ফিতা', label: 'ক্যাম্পাস', swatch: ['#EFF6FF', '#F97316'], group: 'topic' },
  { id: 'showbiz', name: 'বিনোদন', hint: 'গোলাপি-বেগুনি, ঝিকমিক তারা ও আলো', label: 'বিনোদন', swatch: ['#BE185D', '#FDE047'], group: 'topic' },
  { id: 'cinema', name: 'সিনেমা', hint: 'কালো পর্দা, সোনালি স্পটলাইট ও চলমান ফিল্ম রিল', label: 'সিনেমা', swatch: ['#0A0A0A', '#FACC15'], group: 'topic' },
  { id: 'music', name: 'গান-বাজনা', hint: 'নীল-বেগুনি, তালে তালে ইকুয়ালাইজার', label: 'মিউজিক', swatch: ['#312E81', '#22D3EE'], group: 'topic' },
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
  group: DesignGroup
  isNew?: boolean
}

export const INTROS: SceneInfo<IntroId>[] = [
  { id: 'shatter', name: 'ব্রেকিং শক', hint: 'সাদা ঝলক, কাচের টুকরো জুড়ে লাল স্ল্যাব, ঝাঁকুনি', duration: 3.8, group: 'breaking', isNew: true },
  { id: 'globe', name: 'ঘুরন্ত গ্লোব', hint: 'ঘুরন্ত পৃথিবী, সোনালি কক্ষপথ, মাঝে লোগো', duration: 4, group: 'nil', isNew: true },
  { id: 'kinetic', name: 'কাইনেটিক শিরোনাম', hint: 'শিরোনামের প্রতিটি শব্দ রঙ বদলে ঝাঁপিয়ে আসে', duration: 3.8, group: 'news', isNew: true },
  { id: 'split', name: 'স্প্লিট রিভিল', hint: 'নীল-সোনালি দুই ভাগ সরে লোগো, তারপর শিরোনাম', duration: 3.4, group: 'nil', isNew: true },
  { id: 'nil-reveal', name: 'মিহা নীল রিভিল', hint: 'সোনালি রেখা থেকে লোগো, তারপর শিরোনাম', duration: 3.6, group: 'nil' },
  { id: 'tv-open', name: 'টিভি নিউজ ওপেনিং', hint: 'ঘুরন্ত বলয়, আলোর রেখা, লোয়ার-থার্ডে শিরোনাম', duration: 4, group: 'nil' },
  { id: 'meeha-logo', name: 'মিহা লোগো এনিমেশন', hint: 'আপনার দেওয়া লোগো এনিমেশন (৩.৫ সেকেন্ড)', group: 'brand' },
  { id: 'headline', name: 'শিরোনাম কার্ড', hint: 'লোগো, ক্যাটেগরি ও শিরোনাম লাইন ধরে', duration: 4, group: 'news' },
  { id: 'swipe', name: 'রঙিন সোয়াইপ', hint: 'রঙের প্যানেল সরে লোগো ও শিরোনাম', duration: 3.2, group: 'news' },
  { id: 'countdown', name: 'কাউন্টডাউন', hint: '৩ · ২ · ১ — তারপর শিরোনাম', duration: 3.6, group: 'news' },
  { id: 'breaking', name: 'ব্রেকিং নিউজ', hint: 'লাল ফ্ল্যাশ, ঝাঁকুনি ও শিরোনাম', duration: 3, group: 'breaking' },
  { id: 'live', name: 'সরাসরি', hint: 'রাডারের মতো লাল ঢেউ, “সরাসরি”', duration: 3, group: 'breaking' },
  { id: 'special', name: 'বিশেষ প্রতিবেদন', hint: 'সোনালি রেখা ও শিরোনাম', duration: 3.5, group: 'special' },
  { id: 'investigation', name: 'অনুসন্ধান', hint: 'অন্ধকার নথির গ্রিড, স্পটলাইট, টাইপ হওয়া শিরোনাম', duration: 4, group: 'special' },
  { id: 'sports', name: 'খেলা', hint: 'দৌড়ানো রেখা, ঝাঁপিয়ে আসা লেখা', duration: 3, group: 'topic' },
  { id: 'showbiz', name: 'বিনোদন', hint: 'গোলাপি-বেগুনি, ঝিকমিক তারা', duration: 3.2, group: 'topic' },
  { id: 'custom', name: 'গ্যালারি থেকে', hint: 'নিজের ইন্ট্রো ভিডিও', group: 'clip' },
]

export const OUTROS: SceneInfo<OutroId>[] = [
  { id: 'subscribe', name: 'সাবস্ক্রাইব করুন', hint: 'বড় বোতাম, কার্সার চাপ দেয়, বেল বাজে', duration: 5, group: 'nil', isNew: true },
  { id: 'staytuned', name: 'চোখ রাখুন', hint: 'ঘুরন্ত আলোর রশ্মি, লোগো নেমে আসে, ঠিকানা', duration: 4.5, group: 'nil', isNew: true },
  { id: 'thanks-burst', name: 'রঙিন ধন্যবাদ', hint: 'কনফেটি, “দেখার জন্য ধন্যবাদ”, লাইক · শেয়ার · কমেন্ট', duration: 4, group: 'news', isNew: true },
  { id: 'nil-end', name: 'মিহা নীল এন্ড কার্ড', hint: 'লোগো, “ফলো করুন”, সোশ্যাল আইকন ও ঠিকানা', duration: 5, group: 'nil' },
  { id: 'endscreen', name: 'ইউটিউব এন্ড স্ক্রিন', hint: 'ইউটিউবের “পরের ভিডিও” ও সাবস্ক্রাইবের জায়গা রেখে (২০ সে. পর্যন্ত লাগে না, ৮ সে.)', duration: 8, group: 'nil' },
  { id: 'credits', name: 'ক্রেডিট', hint: 'প্রতিবেদক, সম্পাদনা ও তারিখ', duration: 4.5, group: 'nil' },
  { id: 'meeha-social', name: 'মিহা সোশ্যাল আউট্রো', hint: 'আপনার দেওয়া আউট্রো — সোশ্যাল লিংকসহ (৮ সেকেন্ড)', group: 'brand' },
  { id: 'thanks', name: 'ধন্যবাদ কার্ড', hint: 'লোগো, “দেখার জন্য ধন্যবাদ” ও ওয়েবসাইট', duration: 4, group: 'news' },
  { id: 'follow', name: 'ফলো করুন', hint: 'লাইক · শেয়ার · ফলো বোতামে ট্যাপের এনিমেশন', duration: 4.5, group: 'news' },
  { id: 'custom', name: 'গ্যালারি থেকে', hint: 'নিজের আউট্রো ভিডিও', group: 'clip' },
]

export type WatermarkStyle = 'hop' | 'glide' | 'corners'
export const WATERMARK_STYLES: { id: WatermarkStyle; name: string; hint: string }[] = [
  { id: 'hop', name: 'জায়গা বদল', hint: 'এক জায়গায় ধীরে ভেসে মিলিয়ে যায়, অন্য জায়গায় ফুটে ওঠে' },
  { id: 'glide', name: 'ভেসে চলা', hint: 'পুরো ভিডিওর ওপর খুব ধীরে ঘুরে বেড়ায়' },
  { id: 'corners', name: 'কোণে কোণে', hint: 'চার কোণে পালা করে, টিভির মতো' },
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
  subtitles: 600,
  subtitleText: 160,
  cuts: 150,
  blurs: 8,
  speeds: 12,
  freezes: 8,
  stickers: 12,
  freezeText: 80,
  stickerLabel: 40,
  pipLabel: 30,
  thumbTitle: 90,
  thumbBadge: 20,
  thumbSub: 60,
  thumbLayers: 12,
  thumbLayerText: 40,
  sounds: 40,
  voices: 10,
  /** Reframe samples (2 per second → about 40 minutes). */
  reframe: 5000,
}

export const SPEED_RATES = [0.25, 0.5, 0.75, 1.5, 2, 3, 4]

export const TRANSITIONS: { id: TransitionId; name: string; hint: string }[] = [
  { id: 'none', name: 'সরাসরি কাট', hint: 'কোনো ট্রানজিশন নেই' },
  { id: 'fade', name: 'ফেড', hint: 'মীহা নীলে মিলিয়ে গিয়ে আবার ফুটে ওঠে' },
  { id: 'slide', name: 'স্লাইড', hint: 'নীল-লাল প্যানেল পর্দা ঢেকে সরে যায়' },
  { id: 'zoom', name: 'জুম', hint: 'জুম করে ঢুকে সাদা ঝলকে বদলায়' },
  { id: 'wipe', name: 'ওয়াইপ', hint: 'কোনাকুনি ব্র্যান্ড রেখা মুছে নিয়ে যায়' },
  { id: 'flash', name: 'সাদা ঝলক', hint: 'ছোট্ট সাদা ঝলক — কথার মাঝের কাটে সবচেয়ে মানানসই' },
  { id: 'dip', name: 'কালোয় মিলিয়ে', hint: 'এক পলক কালো হয়ে আবার ফিরে আসে' },
]

export const STICKERS: { id: StickerKind; name: string }[] = [
  { id: 'arrow', name: 'তীর' },
  { id: 'circle', name: 'বৃত্ত' },
  { id: 'box', name: 'বক্স' },
  { id: 'highlight', name: 'হাইলাইট' },
  { id: 'focus', name: 'স্পটলাইট' },
  { id: 'cross', name: 'ক্রস (ভুল)' },
  { id: 'check', name: 'টিক (সঠিক)' },
]

export const STICKER_COLORS = ['#EF4444', '#FACC15', '#22C55E', '#FFFFFF', '#025AB2']

export type ThumbGroup = 'nil' | 'news' | 'breaking' | 'face' | 'impact' | 'topic'
export const THUMB_GROUPS: { id: ThumbGroup; name: string; hint: string }[] = [
  { id: 'nil', name: 'মিহা নীল', hint: 'ফটোকার্ডের নীল-সোনালি নকশা' },
  { id: 'news', name: 'সংবাদ', hint: 'সাধারণ খবরের থাম্বনেইল' },
  { id: 'breaking', name: 'ব্রেকিং ও লাইভ', hint: 'জরুরি, সরাসরি, এক্সক্লুসিভ' },
  { id: 'face', name: 'মুখ ও সাক্ষাৎকার', hint: 'মানুষের ছবি সামনে' },
  { id: 'impact', name: 'নজরকাড়া (ইউটিউব ধাঁচ)', hint: 'প্রশ্ন, সংখ্যা, তীর, চমক' },
  { id: 'topic', name: 'বিষয়ভিত্তিক', hint: 'খেলা, বিনোদন, অর্থনীতি, শিক্ষা' },
]

export const THUMB_TEMPLATES: { id: ThumbTemplate; name: string; hint: string; group: ThumbGroup; isNew?: boolean }[] = [
  { id: 'nil-bar', name: 'নীল পট্টি', hint: 'নিচে নেভি পট্টি, সোনালি দাগ', group: 'nil' },
  { id: 'nil-split', name: 'নীল ভাগ', hint: 'একপাশে ছবি, অন্যপাশে লেখা', group: 'nil' },
  { id: 'nil-frame', name: 'নীল ফ্রেম', hint: 'ছবি ফ্রেমে, সোনালি কোণ', group: 'nil' },
  { id: 'nil-quote', name: 'নীল উক্তি', hint: 'বড় উদ্ধৃতি চিহ্ন, বক্তার নাম', group: 'nil' },
  { id: 'nil-card', name: 'সাদা কার্ড', hint: 'সাদা কার্ডে নেভি লেখা, মার্কার', group: 'nil' },
  { id: 'nil-poster', name: 'নীল পোস্টার', hint: 'নিচে তির্যক নীল অংশ, সোনালি দাগ', group: 'nil', isNew: true },
  { id: 'nil-circle', name: 'নীল বৃত্ত', hint: 'গোল ছবি, চারপাশে বলয়', group: 'nil', isNew: true },
  { id: 'cinematic', name: 'সিনেমাটিক', hint: 'উপর-নিচে কালো পট্টি, সিনেমার মতো', group: 'news', isNew: true },
  { id: 'bold', name: 'বড় শিরোনাম', hint: 'নিচে গাঢ় পট্টিতে বড় লেখা', group: 'news' },
  { id: 'top', name: 'উপরে শিরোনাম', hint: 'উপরে রঙিন পট্টি', group: 'news' },
  { id: 'lower', name: 'টিভি লোয়ার থার্ড', hint: 'টিভির মতো নামফলক', group: 'news' },
  { id: 'glass', name: 'কাচের বাক্স', hint: 'মাঝে ঝাপসা কাচে লেখা', group: 'news' },
  { id: 'minimal', name: 'মিনিমাল', hint: 'ছবির উপর হালকা লেখা', group: 'news' },
  { id: 'breaking', name: 'ব্রেকিং', hint: 'লাল ব্যানার, তির্যক রেখা', group: 'breaking' },
  { id: 'live', name: 'সরাসরি', hint: 'লাইভ চিহ্ন ও লাল পট্টি', group: 'breaking' },
  { id: 'alert', name: 'জরুরি সতর্কতা', hint: 'হলুদ-কালো সতর্ক দাগ', group: 'breaking' },
  { id: 'exclusive', name: 'এক্সক্লুসিভ', hint: 'কোণে সোনালি ফিতা', group: 'breaking' },
  { id: 'urgent', name: 'জরুরি বার্তা', hint: 'উপরে লাল “জরুরি” পট্টি, নিচে কালো', group: 'breaking', isNew: true },
  { id: 'face', name: 'মুখের ছবি', hint: 'গোল ফ্রেমে মুখ, পাশে শিরোনাম', group: 'face' },
  { id: 'duo', name: 'মুখোমুখি', hint: 'দুই ছবি, মাঝে “বনাম”', group: 'face' },
  { id: 'interview', name: 'সাক্ষাৎকার', hint: 'বড় মুখ, নাম ও উক্তি', group: 'face' },
  { id: 'reaction', name: 'প্রতিক্রিয়া', hint: 'হেলানো বড় ছবি, সাদা দাগ', group: 'face' },
  { id: 'question', name: 'বড় প্রশ্ন', hint: 'বিশাল “?” আর প্রশ্ন', group: 'impact' },
  { id: 'number', name: 'বড় সংখ্যা', hint: 'শিরোনামের সংখ্যা বিশাল করে', group: 'impact' },
  { id: 'shock', name: 'চমক', hint: 'দাগ-দেওয়া বড় লেখা, বিস্ফোরণ ব্যাজ', group: 'impact' },
  { id: 'pointer', name: 'তীর দিয়ে দেখানো', hint: 'লেখা থেকে তীর ছবির দিকে', group: 'impact' },
  { id: 'factcheck', name: 'ফ্যাক্টচেক', hint: 'সিলমোহর, সঠিক/ভুল চিহ্ন', group: 'impact', isNew: true },
  { id: 'neon', name: 'নিয়ন', hint: 'জ্বলজ্বলে নিয়ন রেখা ও লেখা', group: 'impact', isNew: true },
  { id: 'sports', name: 'খেলা', hint: 'তির্যক সবুজ দাগ, স্কোরের মতো ব্যাজ', group: 'topic' },
  { id: 'showbiz', name: 'বিনোদন', hint: 'গোলাপি-বেগুনি ঝলক', group: 'topic' },
  { id: 'economy', name: 'অর্থনীতি', hint: 'ঊর্ধ্বমুখী রেখা, সবুজ-সোনালি', group: 'topic' },
  { id: 'campus', name: 'শিক্ষা ও ক্যাম্পাস', hint: 'খাতার দাগ, নীল কালি', group: 'topic' },  { id: 'weather', name: 'আবহাওয়া', hint: 'আকাশি আভা, সূর্য ও মেঘ', group: 'topic', isNew: true },
  { id: 'crime', name: 'অপরাধ', hint: 'হলুদ পুলিশ টেপ, গাঢ় কোণ', group: 'topic', isNew: true },

]

export const THUMB_SIZES: { id: ThumbSize; name: string; hint: string }[] = [
  { id: '16:9', name: '১৬:৯', hint: 'YouTube, ওয়েবসাইট' },
  { id: '9:16', name: '৯:১৬', hint: 'রিল, শর্টস, স্টোরি' },
  { id: '1:1', name: '১:১', hint: 'ফেসবুক, ইনস্টাগ্রাম' },
  { id: '4:5', name: '৪:৫', hint: 'ফেসবুক/ইনস্টা পোস্ট' },
  { id: 'video', name: 'ভিডিওর মাপে', hint: 'ভিডিও যেমন' },
]

export const THUMB_PALETTES: { id: ThumbPaletteId; name: string; dark: string; mid: string; accent: string; hot: string }[] = [
  { id: 'auto', name: 'নকশার নিজের', dark: '#0B2A6B', mid: '#1546B8', accent: '#FFC20E', hot: '#E53935' },
  { id: 'nil', name: 'মিহা নীল', dark: '#0B2A6B', mid: '#1546B8', accent: '#FFC20E', hot: '#E53935' },
  { id: 'red', name: 'লাল', dark: '#3D0A0A', mid: '#C62828', accent: '#FFD54F', hot: '#FFD54F' },
  { id: 'night', name: 'কালো', dark: '#0A0A0A', mid: '#262626', accent: '#FACC15', hot: '#EF4444' },
  { id: 'green', name: 'সবুজ', dark: '#06301F', mid: '#0E7A4A', accent: '#FDE047', hot: '#EF4444' },
  { id: 'purple', name: 'বেগুনি', dark: '#23093F', mid: '#6D28D9', accent: '#F9A8D4', hot: '#F43F5E' },
  { id: 'orange', name: 'কমলা', dark: '#3A1600', mid: '#EA580C', accent: '#FFF3A3', hot: '#B91C1C' },
  { id: 'teal', name: 'সমুদ্র', dark: '#042F35', mid: '#0E7490', accent: '#FDE68A', hot: '#F43F5E' },
]

export const THUMB_LAYER_KINDS: { id: ThumbLayerKind; name: string }[] = [
  { id: 'text', name: 'লেখা' },
  { id: 'burst', name: 'বিস্ফোরণ ব্যাজ' },
  ...STICKERS.filter((s) => s.id !== 'focus'),
]
export const THUMB_COLORS = ['#FFC20E', '#E53935', '#FFFFFF', '#0B2A6B', '#1546B8', '#22C55E', '#111111', '#F472B6']

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
    fileSize: oneOf(o.fileSize, ['small', 'balanced', 'high'] as const, d.fileSize),
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
      top: num(frame.top, 1, 1, 2),
      titlePos: oneOf(frame.titlePos, ['top', 'bottom'] as const, 'top'),
      bottom: num(frame.bottom, 1, 1, 2),
      motion: oneOf(frame.motion, ['lively', 'calm'] as const, d.frame.motion),
    },
    watermark: {
      on: bool(o.watermark?.on, d.watermark.on),
      style: oneOf(o.watermark?.style, WATERMARK_STYLES.map((x) => x.id), d.watermark.style),
      size: num(o.watermark?.size, d.watermark.size, 3, 10),
      every: num(o.watermark?.every, d.watermark.every, 5, 30),
      opacity: num(o.watermark?.opacity, d.watermark.opacity, 0.15, 0.8),
      site: bool(o.watermark?.site, d.watermark.site),
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
      cover: bool(intro.cover, d.intro.cover),
    },
    outro: {
      id: oneOf(outro.id, ['none', ...OUTROS.map((i) => i.id)] as OutroId[], d.outro.id),
      clip: mediaRef(outro.clip),
    },
    ads: (Array.isArray(o.ads) ? o.ads : [])
      .map((a: any) => {
        const clip = mediaRef(a?.clip)
        const house = HOUSE_ADS.some((h) => h.id === a?.house) ? (a.house as HouseAdId) : null
        return clip || house ? { at: num(a.at, 0, 0, 86400), clip: house ? null : clip, house, bumper: bool(a.bumper, true) } : null
      })
      .filter(Boolean)
      .slice(0, LIMITS.ads) as AdBreak[],
    subtitles: {
      on: bool(o.subtitles?.on, d.subtitles.on),
      style: oneOf(o.subtitles?.style, SUB_STYLES.map((x) => x.id), d.subtitles.style),
      size: num(o.subtitles?.size, 1, 0.7, 1.6),
      shift: num(o.subtitles?.shift, 0, -30, 30),
      items: (Array.isArray(o.subtitles?.items) ? o.subtitles.items : [])
        .map((x: any): SubLine | null => {
          const text = str(x?.text, LIMITS.subtitleText).trim()
          const at = num(x?.at, 0, 0, 86400)
          const end = num(x?.end, at + 2, 0, 86400)
          return text && end > at ? { at, end, text } : null
        })
        .filter((x: SubLine | null): x is SubLine => !!x)
        .slice(0, LIMITS.subtitles),
    },
    progress: { on: bool(o.progress?.on, d.progress.on), color: oneOf(o.progress?.color, ['red', 'yellow', 'blue', 'white'] as const, d.progress.color) },
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
      unmusic: oneOf(audio.unmusic, ['off', 'low', 'medium', 'high'] as const, d.audio.unmusic),
      loudness: oneOf(audio.loudness, ['off', '-14', '-16'] as const, d.audio.loudness),
    },
    cuts: (Array.isArray(o.cuts) ? o.cuts : [])
      .map((c: any) => ({ from: num(c?.from, 0, 0, 86400), to: num(c?.to, 0, 0, 86400), ...(c?.auto === true ? { auto: true } : {}) }))
      .filter((c: Cut) => c.to - c.from >= 0.1)
      .slice(0, LIMITS.cuts),
    look: normalizeLook(o.look),
    speeds: (Array.isArray(o.speeds) ? o.speeds : [])
      .map((r: any) => {
        const rate = num(r?.rate, 1, 0.25, 4)
        return {
          from: num(r?.from, 0, 0, 86400),
          to: num(r?.to, 0, 0, 86400),
          rate,
          // an edit saved before round 26 keeps its length (no replay) but now has sound
          replay: rate < 1 && bool(r?.replay, false),
          sound: oneOf(r?.sound, ['on', 'off'] as const, rate < 1 ? 'on' : 'off'),
        }
      })
      .filter((r: SpeedRange) => r.to - r.from >= 0.2 && Math.abs(r.rate - 1) > 0.01)
      .slice(0, LIMITS.speeds),
    freezes: (Array.isArray(o.freezes) ? o.freezes : [])
      .map((f: any) => ({ at: num(f?.at, 0, 0, 86400), dur: num(f?.dur, 2, 0.5, 10), text: str(f?.text, LIMITS.freezeText) }))
      .slice(0, LIMITS.freezes),
    transition: {
      id: oneOf(o.transition?.id, TRANSITIONS.map((x) => x.id), d.transition.id),
      dur: num(o.transition?.dur, d.transition.dur, 0.3, 1.5),
      cuts: bool(o.transition?.cuts, d.transition.cuts),
    },
    stickers: (Array.isArray(o.stickers) ? o.stickers : [])
      .map((b: any) => {
        const w = num(b?.w, 0.2, 0.03, 1)
        const h = num(b?.h, 0.2, 0.03, 1)
        return {
          kind: oneOf(b?.kind, STICKERS.map((x) => x.id), 'circle'),
          x: num(b?.x, 0.4, 0, 1 - w),
          y: num(b?.y, 0.4, 0, 1 - h),
          w,
          h,
          angle: num(b?.angle, 0, -360, 360),
          color: STICKER_COLORS.includes(b?.color) ? b.color : STICKER_COLORS[0],
          label: str(b?.label, LIMITS.stickerLabel),
          at: num(b?.at, 0, 0, 86400),
          dur: b?.dur === null ? null : num(b?.dur, 4, 0.5, 86400),
        }
      })
      .slice(0, LIMITS.stickers),
    pip: normalizePip(o.pip),
    reframe: normalizeReframe(o.reframe),
    thumb: {
      on: bool(o.thumb?.on, d.thumb.on),
      at: o.thumb?.at === null || o.thumb?.at === undefined ? null : num(o.thumb.at, 0, 0, 86400),
      template: oneOf(o.thumb?.template, THUMB_TEMPLATES.map((x) => x.id), d.thumb.template),
      title: str(o.thumb?.title, LIMITS.thumbTitle),
      badge: str(o.thumb?.badge, LIMITS.thumbBadge),
      face: mediaRef(o.thumb?.face),
      size: oneOf(o.thumb?.size, THUMB_SIZES.map((x) => x.id), d.thumb.size),
      sub: str(o.thumb?.sub, LIMITS.thumbSub),
      palette: oneOf(o.thumb?.palette, THUMB_PALETTES.map((x) => x.id), d.thumb.palette),
      scale: num(o.thumb?.scale, 1, 0.75, 1.35),
      logo: oneOf(o.thumb?.logo, ['auto', 'tl', 'tr', 'bl', 'br', 'off'] as const, d.thumb.logo),
      grade: oneOf(o.thumb?.grade, ['none', 'pop', 'dark'] as const, d.thumb.grade),
      layers: (Array.isArray(o.thumb?.layers) ? o.thumb.layers : [])
        .filter((b: any) => b && typeof b === 'object')
        .map((b: any): ThumbLayer => ({
          kind: oneOf(b.kind, THUMB_LAYER_KINDS.map((x) => x.id), 'text'),
          x: num(b.x, 0.5, 0, 1),
          y: num(b.y, 0.5, 0, 1),
          w: num(b.w, 0.2, 0.02, 1),
          h: num(b.h, 0.08, 0.02, 1),
          angle: num(b.angle, 0, -360, 360),
          color: THUMB_COLORS.includes(b.color) ? b.color : THUMB_COLORS[0],
          text: str(b.text, LIMITS.thumbLayerText),
          plate: bool(b.plate, true),
        }))
        .slice(0, LIMITS.thumbLayers),
    },
    sounds: (Array.isArray(o.sounds) ? o.sounds : [])
      .filter((x: any) => SFX_IDS.includes(x?.id))
      .map((x: any) => ({ id: x.id as SfxId, at: num(x?.at, 0, 0, 86400), vol: num(x?.vol, 0.8, 0, 1.5) }))
      .slice(0, LIMITS.sounds),
    voices: (Array.isArray(o.voices) ? o.voices : [])
      .map((x: any): VoiceOver | null => {
        const clip = mediaRef(x?.clip)
        if (!clip) return null
        return { clip, at: num(x?.at, 0, 0, 86400), vol: num(x?.vol, 1, 0, 2), duck: num(x?.duck, 0.3, 0, 1), clean: bool(x?.clean, true), offset: num(x?.offset, 0, 0, 3600), until: x?.until == null ? null : num(x?.until, 0, 0, 86400) }
      })
      .filter((x: VoiceOver | null): x is VoiceOver => !!x)
      .slice(0, LIMITS.voices),
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

function normalizePip(raw: unknown): Pip | null {
  if (!raw || typeof raw !== 'object') return null
  const p = raw as Record<string, any>
  const clip = mediaRef(p.clip)
  if (!clip) return null
  return {
    clip,
    layout: oneOf(p.layout, ['pip', 'split'] as const, 'pip'),
    corner: oneOf(p.corner, ['tr', 'tl', 'br', 'bl'] as const, 'br'),
    size: num(p.size, 0.36, 0.2, 0.5),
    at: num(p.at, 0, 0, 86400),
    dur: p.dur === null || p.dur === undefined ? null : num(p.dur, 10, 1, 86400),
    sound: oneOf(p.sound, ['main', 'pip', 'both'] as const, 'main'),
    label: str(p.label, LIMITS.pipLabel),
    mainLabel: str(p.mainLabel, LIMITS.pipLabel),
  }
}

function normalizeReframe(raw: unknown): Reframe {
  const r = (raw && typeof raw === 'object' ? raw : {}) as Record<string, any>
  const arr = (v: unknown) =>
    (Array.isArray(v) ? v : [])
      .slice(0, LIMITS.reframe)
      .map((x) => (typeof x === 'number' && Number.isFinite(x) ? Math.min(1, Math.max(0, x)) : 0.5))
  const xs = arr(r.xs)
  const ys = arr(r.ys)
  const n = Math.min(xs.length, ys.length)
  return { on: bool(r.on, false) && n > 0, step: num(r.step, 0.5, 0.1, 5), xs: xs.slice(0, n), ys: ys.slice(0, n) }
}

/** The reframe focus (0..1 of the picture) at source second `src`, or null. */
export function reframeAt(r: Reframe, src: number): { x: number; y: number } | null {
  if (!r.on || !r.xs.length) return null
  const f = Math.max(0, src / r.step)
  const i = Math.min(r.xs.length - 1, Math.floor(f))
  const j = Math.min(r.xs.length - 1, i + 1)
  const k = Math.min(1, f - i)
  return { x: r.xs[i] + (r.xs[j] - r.xs[i]) * k, y: r.ys[i] + (r.ys[j] - r.ys[i]) * k }
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
    subtitles: { ...cfg.subtitles, items: [] },
    cuts: [],
    blurs: [],
    speeds: [],
    freezes: [],
    stickers: [],
    sounds: [],
    voices: [],
    pip: null,
    reframe: { on: false, step: 0.5, xs: [], ys: [] },
    thumb: { ...cfg.thumb, at: null, title: '', sub: '', face: null, layers: [] },
    // colour is a house style; zoom / rotation belong to one particular video
    look: { ...cfg.look, zoom: 1, panX: 0, panY: 0, rotate: 0, flip: false },
  }
}

/** Whether anything at all would change the video. */
export function hasEdits(cfg: EditConfig, srcDuration: number | null): boolean {
  return (
    cfg.frame.id !== 'none' ||
    cfg.logo.on ||
    cfg.watermark.on ||
    cfg.subtitles.items.length > 0 ||
    cfg.progress.on ||
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
    cfg.audio.unmusic !== 'off' ||
    cfg.cuts.length > 0 ||
    cfg.blurs.length > 0 ||
    cfg.speeds.length > 0 ||
    cfg.freezes.length > 0 ||
    cfg.stickers.length > 0 ||
    cfg.sounds.length > 0 ||
    cfg.voices.length > 0 ||
    cfg.pip !== null ||
    cfg.reframe.on ||
    lookFilter(cfg.look) !== '' ||
    cfg.look.zoom > 1.001 ||
    cfg.look.rotate !== 0 ||
    cfg.look.flip
  )
}
