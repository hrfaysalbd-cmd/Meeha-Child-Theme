/**
 * Round 46 — "অটো স্টাইল": picks a fitting look for a news video from its
 * category name and title (Bengali or English keywords): music bed, frame,
 * intro, outro and name-plate design, and turns on music ducking under
 * speech. Pure functions; only the design changes, never text or edits.
 */
import { DEFAULT_CONFIG, type EditConfig, type FrameId, type IntroId, type LowerThirdId, type MusicId, type OutroId } from './config'

export type AutoTopic = 'sports' | 'investigation' | 'politics' | 'weather' | 'condolence' | 'showbiz' | 'business' | 'court' | 'accident' | 'health' | 'education' | 'tech' | 'festive' | 'national' | 'agri' | 'world' | 'breaking' | 'talk' | 'general'

interface Look {
  name: string
  music: MusicId
  frame: FrameId
  intro: IntroId
  outro: OutroId
  lowerThird: LowerThirdId
}

export const AUTO_LOOKS: Record<AutoTopic, Look> = {
  sports: { name: 'খেলা', music: 'matchday', frame: 'stadium', intro: 'i-scoreboard', outro: 'follow', lowerThird: 'lt-sports' },
  investigation: { name: 'অনুসন্ধান', music: 'probe', frame: 'investigation', intro: 'investigation', outro: 'credits', lowerThird: 'lt-gold' },
  politics: { name: 'রাজনীতি ও নির্বাচন', music: 'ballot', frame: 'vote', intro: 'i-election', outro: 'o-tri', lowerThird: 'lt-tri' },
  weather: { name: 'আবহাওয়া', music: 'nature', frame: 'weather', intro: 'i-weather', outro: 'o-wave', lowerThird: 'lt-glass' },
  condolence: { name: 'শোক', music: 'condolence', frame: 'minimal', intro: 'i-mourn', outro: 'o-mourn', lowerThird: 'lt-mourn' },
  showbiz: { name: 'বিনোদন', music: 'showbiz', frame: 'glamour', intro: 'showbiz', outro: 'thanks-burst', lowerThird: 'lt-reel' },
  business: { name: 'অর্থনীতি', music: 'corporate', frame: 'budget', intro: 'i-budget', outro: 'o-website', lowerThird: 'lt-split' },
  court: { name: 'আদালত', music: 'weighty', frame: 'court', intro: 'i-court', outro: 'credits', lowerThird: 'lt-gold' },
  accident: { name: 'দুর্ঘটনা', music: 'tension', frame: 'accident', intro: 'i-ticker', outro: 'o-next', lowerThird: 'lt-breaking' },
  health: { name: 'স্বাস্থ্য', music: 'calm', frame: 'medical', intro: 'headline', outro: 'o-next', lowerThird: 'lt-glass' },
  education: { name: 'শিক্ষা', music: 'upbeat', frame: 'education', intro: 'swipe', outro: 'subscribe', lowerThird: 'lt-nil' },
  tech: { name: 'প্রযুক্তি', music: 'digital', frame: 'tech', intro: 'globe', outro: 'o-social-grid', lowerThird: 'lt-glass' },
  festive: { name: 'উৎসব', music: 'eid', frame: 'religion', intro: 'i-eid', outro: 'thanks-burst', lowerThird: 'lt-reel' },
  national: { name: 'জাতীয় দিবস', music: 'victory', frame: 'national', intro: 'i-tri-burst', outro: 'o-tri', lowerThird: 'lt-tri' },
  agri: { name: 'কৃষি', music: 'village', frame: 'agri', intro: 'headline', outro: 'o-wave', lowerThird: 'lt-nil' },
  world: { name: 'আন্তর্জাতিক', music: 'documentary', frame: 'world', intro: 'globe', outro: 'staytuned', lowerThird: 'lt-tv' },
  breaking: { name: 'ব্রেকিং', music: 'urgent', frame: 'breaking', intro: 'shatter', outro: 'follow', lowerThird: 'lt-breaking' },
  talk: { name: 'টকশো ও বক্তব্য', music: 'talkshow', frame: 'signal', intro: 'headline', outro: 'follow', lowerThird: 'lt-tv' },
  general: { name: 'সাধারণ সংবাদ', music: 'bulletin', frame: 'nil', intro: 'nil-reveal', outro: 'nil-end', lowerThird: 'lt-nil' },
}

/** Keyword lists, checked in this order (the first topic with a hit wins; category name hits count double). */
const KEYWORDS: [AutoTopic, string[]][] = [
  ['condolence', ['মৃত্যু', 'শোক', 'নিহত', 'প্রয়াত', 'মারা গেছেন', 'ইন্তেকাল', 'জানাজা', 'স্মরণ', 'শ্রদ্ধাঞ্জলি', 'death', 'dies', 'died', 'obituary', 'condolence', 'tribute']],
  ['breaking', ['ব্রেকিং', 'জরুরি', 'সর্বশেষ', 'breaking', 'urgent', 'alert']],
  ['accident', ['দুর্ঘটনা', 'সংঘর্ষ', 'অগ্নিকাণ্ড', 'আগুন', 'ডুবি', 'বিস্ফোরণ', 'উদ্ধার', 'ভবন ধস', 'accident', 'crash', 'fire', 'collision', 'rescue', 'explosion']],
  ['investigation', ['অনুসন্ধান', 'তদন্ত', 'অপরাধ', 'দুর্নীতি', 'জালিয়াতি', 'চাঁদাবাজি', 'গ্রেপ্তার', 'হত্যা', 'মাদক', 'ধর্ষণ', 'পুলিশ', 'র‍্যাব', 'investigation', 'crime', 'corruption', 'fraud', 'arrest', 'police', 'murder']],
  ['court', ['আদালত', 'রায়', 'মামলা', 'হাইকোর্ট', 'সুপ্রিম কোর্ট', 'আইন', 'বিচার', 'জামিন', 'court', 'verdict', 'law', 'judge', 'bail', 'trial']],
  ['politics', ['নির্বাচন', 'ভোট', 'রাজনীতি', 'সংসদ', 'দল', 'প্রার্থী', 'মনোনয়ন', 'ইসি', 'সরকার', 'মন্ত্রী', 'আওয়ামী', 'বিএনপি', 'জামায়াত', 'election', 'vote', 'politic', 'parliament', 'minister', 'candidate', 'poll']],
  ['business', ['অর্থনীতি', 'বাজেট', 'বাজার', 'ব্যবসা', 'বাণিজ্য', 'শেয়ার', 'ব্যাংক', 'দাম', 'মূল্য', 'রপ্তানি', 'আমদানি', 'টাকা', 'ডলার', 'রেমিট্যান্স', 'economy', 'budget', 'business', 'market', 'stock', 'bank', 'price', 'trade', 'finance']],
  ['weather', ['আবহাওয়া', 'বন্যা', 'ঘূর্ণিঝড়', 'বৃষ্টি', 'ঝড়', 'শৈত্যপ্রবাহ', 'তাপপ্রবাহ', 'জলোচ্ছ্বাস', 'পরিবেশ', 'জলবায়ু', 'weather', 'flood', 'cyclone', 'rain', 'storm', 'climate', 'heatwave']],
  ['sports', ['খেলা', 'ক্রিকেট', 'ফুটবল', 'ম্যাচ', 'টুর্নামেন্ট', 'বিশ্বকাপ', 'গোল', 'উইকেট', 'সাকিব', 'তামিম', 'অলিম্পিক', 'sport', 'cricket', 'football', 'match', 'tournament', 'goal', 'world cup']],
  ['showbiz', ['বিনোদন', 'সিনেমা', 'চলচ্চিত্র', 'নাটক', 'অভিনেতা', 'অভিনেত্রী', 'গান', 'তারকা', 'শোবিজ', 'entertainment', 'showbiz', 'cinema', 'film', 'movie', 'celebrity', 'music']],
  ['festive', ['ঈদ', 'পূজা', 'উৎসব', 'রমজান', 'ইফতার', 'বড়দিন', 'বৈশাখ', 'শুভেচ্ছা', 'ধর্ম', 'eid', 'puja', 'festival', 'ramadan', 'christmas', 'religion']],
  ['national', ['বিজয় দিবস', 'স্বাধীনতা', 'মুক্তিযুদ্ধ', 'শহীদ', 'জাতীয় দিবস', 'একুশে', 'ভাষা দিবস', 'victory day', 'independence', 'liberation', 'martyr']],
  ['health', ['স্বাস্থ্য', 'হাসপাতাল', 'চিকিৎসা', 'ডেঙ্গু', 'রোগ', 'ডাক্তার', 'টিকা', 'করোনা', 'health', 'hospital', 'medical', 'dengue', 'disease', 'doctor', 'vaccine']],
  ['education', ['শিক্ষা', 'ক্যাম্পাস', 'বিশ্ববিদ্যালয়', 'পরীক্ষা', 'ফল', 'এসএসসি', 'এইচএসসি', 'ভর্তি', 'শিক্ষার্থী', 'স্কুল', 'কলেজ', 'education', 'campus', 'university', 'exam', 'student', 'school', 'college']],
  ['tech', ['প্রযুক্তি', 'ডিজিটাল', 'মোবাইল', 'ইন্টারনেট', 'এআই', 'সাইবার', 'অ্যাপ', 'স্টার্টআপ', 'tech', 'digital', 'internet', 'ai', 'cyber', 'app', 'startup', 'mobile']],
  ['agri', ['কৃষি', 'কৃষক', 'ধান', 'ফসল', 'চাষ', 'গ্রাম', 'মৎস্য', 'agri', 'farmer', 'crop', 'rice', 'village']],
  ['world', ['আন্তর্জাতিক', 'বিশ্ব', 'ভারত', 'যুক্তরাষ্ট্র', 'চীন', 'মিয়ানমার', 'রোহিঙ্গা', 'গাজা', 'ইউক্রেন', 'world', 'international', 'global', 'india', 'usa', 'china']],
  ['talk', ['টকশো', 'সাক্ষাৎকার', 'বক্তব্য', 'আলোচনা', 'ভাষণ', 'মতামত', 'talk show', 'interview', 'speech', 'opinion', 'debate']],
]

/** Which topic fits the category name and title best. */
export function detectTopic(categoryName: string, title: string): AutoTopic {
  const cat = (categoryName || '').toLowerCase()
  const ttl = (title || '').toLowerCase()
  let best: AutoTopic = 'general'
  let bestScore = 0
  for (const [topic, words] of KEYWORDS) {
    let score = 0
    for (const w of words) {
      // short Latin words ("ai", "app") only as whole words
      const latin = /^[a-z ]+$/.test(w)
      const has = (txt: string) => (latin && w.length <= 3 ? new RegExp(`\\b${w}\\b`).test(txt) : txt.includes(w))
      if (has(cat)) score += 2
      if (has(ttl)) score += 1
    }
    if (score > bestScore) {
      best = topic
      bestScore = score
    }
  }
  return best
}

/**
 * The design changes "অটো স্টাইল" makes for this video (merge into a config).
 * `base` supplies the current values of the parts that are not changed.
 */
export function autoStyle(categoryName: string, title: string, base: EditConfig = DEFAULT_CONFIG): Partial<EditConfig> {
  const look = AUTO_LOOKS[detectTopic(categoryName, title)]
  const calm = look.lowerThird === 'lt-mourn'
  return {
    frame: { ...base.frame, id: look.frame, motion: calm ? 'calm' : base.frame.motion },
    intro: { ...base.intro, id: look.intro, cover: true },
    outro: { ...base.outro, id: look.outro },
    audio: {
      ...base.audio,
      music: look.music,
      duck: true,
      musicVol: Math.min(base.audio.musicVol, calm ? 0.25 : 0.35),
    },
    // name plates already on the video switch to the matching design
    captions: base.captions.map((c) => (c.style === 'nameplate' || c.style.startsWith('lt-') ? { ...c, style: look.lowerThird } : c)),
  }
}

/** The lower-third design auto style would use (for new name plates). */
export function autoLowerThird(categoryName: string, title: string): LowerThirdId {
  return AUTO_LOOKS[detectTopic(categoryName, title)].lowerThird
}

/** Convenience: cfg with auto style applied. */
export function applyAutoStyle(cfg: EditConfig, categoryName: string, title: string): EditConfig {
  return { ...cfg, ...autoStyle(categoryName, title, cfg) }
}
