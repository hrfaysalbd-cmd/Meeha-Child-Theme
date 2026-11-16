/**
 * দেশচিত্র import (publisher r26.5): the "meeha-deshchitro" v1 JSON file that the AI research prompt produces,
 * turned into the sheet's data (district × indicator counts, notes, economy, highlights, summary, sources).
 *
 * Every incident in the file is one event with its own sources; the importer adds them up per district and
 * indicator, drops what fails the rules (wrong date, no source link, low confidence — each a choice in the
 * preview) and reports what it skipped, so the desk can check before saving.
 */
import type { DcData, DcMeeha, DcSheet } from './api'

/**
 * r26.6 — Meeha-first: the counts typed on the day's own Meeha News posts, laid into a sheet. Per cell the larger
 * number wins, so running it twice (or after the desk already typed a cell) never double counts.
 */
export function dcWithMeeha(base: DcData, meeha?: DcMeeha | null): DcData {
  const data: DcData = JSON.parse(JSON.stringify(base))
  if (!meeha || !meeha.n) return data
  Object.entries(meeha.inc).forEach(([dk, vals]) => {
    data.inc[dk] = data.inc[dk] || {}
    Object.entries(vals).forEach(([k, n]) => { data.inc[dk][k] = Math.max(data.inc[dk][k] || 0, n) })
  })
  Object.entries(meeha.unk).forEach(([k, n]) => { data.unk[k] = Math.max(data.unk[k] || 0, n) })
  return data
}

/** How many cells of `data` are below the Meeha counts (what "মিহার হিসাব বসান" would change). */
export function dcMeehaMissing(data: DcData, meeha?: DcMeeha | null): number {
  if (!meeha || !meeha.n) return 0
  let n = 0
  Object.entries(meeha.inc).forEach(([dk, vals]) => Object.entries(vals).forEach(([k, v]) => { if (((data.inc[dk] || {})[k] || 0) < v) n++ }))
  Object.entries(meeha.unk).forEach(([k, v]) => { if ((data.unk[k] || 0) < v) n++ })
  return n
}

const safeDecode = (u: string) => { try { return decodeURI(u) } catch { return u } }
const normUrl = (u: string) => safeDecode(u.trim()).replace(/^https?:\/\/(www\.)?/i, '').replace(/[?#].*$/, '').replace(/\/+$/, '').toLowerCase()

export const DC_FORMAT = 'meeha-deshchitro'
export const DC_FORMAT_VERSION = 1

export interface DcSourceRef { outlet?: string; url?: string; published_at?: string; type?: string }
export interface DcImportIncident {
  district?: string | null
  indicator?: string
  count?: number | string
  occurred_on?: string
  place?: string
  summary?: string
  confidence?: 'high' | 'medium' | 'low' | string
  sources?: DcSourceRef[]
}
export interface DcImportFile {
  format?: string
  version?: number
  date?: string
  generated_at?: string
  incidents?: DcImportIncident[]
  district_notes?: Record<string, string>
  economy?: Record<string, { value?: number | string | null; as_of?: string; source?: string; url?: string } | number | string | null>
  highlights?: { text?: string; district?: string | null; url?: string }[]
  summary?: string
  coverage_gaps?: string[]
  method_note?: string
}

export interface DcImportOptions {
  mode: 'replace' | 'add'
  requireSource: boolean
  includeLow: boolean
  strictDate: boolean
}

export interface DcImportRow {
  i: number
  district: string // resolved key, '' = unknown district
  districtRaw: string
  indicator: string // resolved key, '' = unknown
  indicatorRaw: string
  count: number
  summary: string
  sources: DcSourceRef[]
  confidence: string
  occurred: string
  skip: string // '' = counted, else the reason
}

export interface DcImportResult {
  date: string
  rows: DcImportRow[]
  data: DcData
  counted: number
  skipped: number
  totals: Record<string, number>
  eco: { key: string; value: number; source: string }[]
  ecoUnknown: string[]
  warnings: string[]
  gaps: string[]
}

/* ---------------------------------------------------------------- name matching */
// Old / alternative English spellings → district key (keys themselves are the current spellings).
const DIST_ALIASES: Record<string, string> = {
  chittagong: 'chattogram', ctg: 'chattogram', comilla: 'cumilla', jessore: 'jashore', barisal: 'barishal',
  bogra: 'bogura', coxsbazaar: 'coxsbazar', nawabganj: 'chapainawabganj', chapai: 'chapainawabganj',
  brahamanbaria: 'brahmanbaria', bbaria: 'brahmanbaria', maulvibazar: 'moulvibazar', moulvibazaar: 'moulvibazar',
  jhalokati: 'jhalakathi', jhalokathi: 'jhalakathi', netrakona: 'netrokona', kishorganj: 'kishoreganj',
  narsinghdi: 'narsingdi', narshingdi: 'narsingdi', laxmipur: 'lakshmipur', lakshmipura: 'lakshmipur',
  khagrachari: 'khagrachhari', khagrachori: 'khagrachhari', rangamati: 'rangamati', jaipurhat: 'joypurhat',
  sirajgonj: 'sirajganj', gopalgonj: 'gopalganj', kurigram: 'kurigram', panchagar: 'panchagarh', thakurgaon: 'thakurgaon',
  munshigonj: 'munshiganj', narayangonj: 'narayanganj', madaripur: 'madaripur', shariatpur: 'shariatpur', sariatpur: 'shariatpur',
  hobiganj: 'habiganj', sunamgonj: 'sunamganj', manikgonj: 'manikganj', kishoregonj: 'kishoreganj', dhakacity: 'dhaka',
}
const ascii = (s: string) => s.toLowerCase().replace(/district|zila|zilla|jela/g, '').replace(/[^a-z]/g, '')
const bnKey = (s: string) => s.replace(/জেলা|জেলায়|জেলার|মহানগর|শহর/g, '').replace(/[\s‌‍।,.:;\-()]/g, '')

export function districtResolver(sheet: DcSheet) {
  const byKey = new Map<string, string>()
  Object.keys(sheet.geo.districts).forEach((k) => {
    byKey.set(k, k)
    byKey.set(bnKey(sheet.geo.districts[k][0]), k)
  })
  return (raw: unknown): string => {
    if (raw === null || raw === undefined) return ''
    const s = String(raw).trim()
    if (!s) return ''
    const a = ascii(s)
    if (a && byKey.has(a)) return byKey.get(a) as string
    if (a && DIST_ALIASES[a]) return DIST_ALIASES[a]
    const b = bnKey(s)
    if (b && byKey.has(b)) return byKey.get(b) as string
    return ''
  }
}

export function indicatorResolver(sheet: DcSheet) {
  const m = new Map<string, string>()
  sheet.indicators.forEach((i) => {
    m.set(i.key.toLowerCase(), i.key)
    m.set(bnKey(i.label), i.key)
    m.set(bnKey(i.short), i.key)
  })
  return (raw: unknown): string => {
    const s = String(raw ?? '').trim()
    return m.get(s.toLowerCase()) || m.get(bnKey(s)) || ''
  }
}

const BN = '০১২৩৪৫৬৭৮৯'
const toNum = (v: unknown): number => {
  if (typeof v === 'number') return v
  const s = String(v ?? '').replace(/[০-৯]/g, (d) => String(BN.indexOf(d))).replace(/[,\s]/g, '')
  return s === '' ? NaN : Number(s)
}
const isUrl = (u: unknown) => typeof u === 'string' && /^https?:\/\/[^\s]+\.[^\s]+/.test(u.trim())

/* ---------------------------------------------------------------- parse */
/** Read the pasted text / file: accepts raw JSON or JSON inside a ```json … ``` block. */
export function readImportText(text: string): DcImportFile {
  let t = text.trim().replace(/^﻿/, '')
  const fence = t.match(/```(?:json)?\s*([\s\S]*?)```/i)
  if (fence) t = fence[1].trim()
  const first = t.indexOf('{')
  const last = t.lastIndexOf('}')
  if (first > 0 || (last > -1 && last < t.length - 1)) t = t.slice(first, last + 1)
  let j: unknown
  try {
    j = JSON.parse(t)
  } catch {
    throw new Error('ফাইলটি ঠিক JSON নয়। এআই-এর পুরো উত্তর থেকে শুধু JSON অংশটি দিন।')
  }
  if (!j || typeof j !== 'object' || Array.isArray(j)) throw new Error('ফাইলের ভেতরে একটি JSON অবজেক্ট থাকতে হবে।')
  const f = j as DcImportFile
  if (f.format && f.format !== DC_FORMAT) throw new Error(`এটি দেশচিত্রের ফাইল নয় (format: ${f.format})।`)
  return f
}

export function buildImport(f: DcImportFile, sheet: DcSheet, base: DcData, opt: DcImportOptions): DcImportResult {
  const date = /^\d{4}-\d{2}-\d{2}$/.test(String(f.date || '')) ? String(f.date) : sheet.day
  const dist = districtResolver(sheet)
  const ind = indicatorResolver(sheet)
  const warnings: string[] = []
  const data: DcData = JSON.parse(JSON.stringify(base))
  if (opt.mode === 'replace') {
    // Meeha-first: "replace" clears the desk's hand-typed numbers but always keeps Meeha's own counts.
    data.inc = JSON.parse(JSON.stringify(sheet.meeha?.inc || {}))
    data.unk = JSON.parse(JSON.stringify(sheet.meeha?.unk || {}))
  }
  const meehaUrls = new Set((sheet.meeha?.posts || []).map((p) => normUrl(p.url)))
  const rows: DcImportRow[] = []
  const add = (dk: string, k: string, n: number) => {
    if (dk) {
      data.inc[dk] = data.inc[dk] || {}
      data.inc[dk][k] = Math.min(99999, (data.inc[dk][k] || 0) + n)
    } else {
      data.unk[k] = Math.min(99999, (data.unk[k] || 0) + n)
    }
  }
  ;(Array.isArray(f.incidents) ? f.incidents : []).forEach((x, i) => {
    const dRaw = x.district === null || x.district === undefined ? '' : String(x.district)
    const iRaw = String(x.indicator ?? '')
    const dk = dist(dRaw)
    const k = ind(iRaw)
    const n = Math.round(toNum(x.count ?? 1))
    const sources = (Array.isArray(x.sources) ? x.sources : []).filter((s) => s && typeof s === 'object')
    const conf = String(x.confidence || 'medium').toLowerCase()
    const occurred = String(x.occurred_on || '')
    let skip = ''
    if (!k) skip = `অচেনা সূচক "${iRaw}"`
    else if (!Number.isFinite(n) || n < 1) skip = 'সংখ্যা নেই'
    else if (opt.requireSource && !sources.some((s) => isUrl(s.url))) skip = 'উৎসের লিংক নেই'
    else if (opt.strictDate && occurred && occurred !== date) skip = `অন্য দিনের ঘটনা (${occurred})`
    else if (!opt.includeLow && conf === 'low') skip = 'কম নিশ্চিত'
    else if (meehaUrls.size && sources.some((s) => isUrl(s.url) && meehaUrls.has(normUrl(String(s.url))))) skip = 'মিহা নিউজে আগেই গোনা'
    if (!skip && dRaw && !dk) warnings.push(`"${dRaw}" জেলা চেনা যায়নি — ঘটনাটি "জেলা অজানা"-তে গেছে।`)
    if (!skip) add(dk, k, n)
    rows.push({ i, district: dk, districtRaw: dRaw, indicator: k, indicatorRaw: iRaw, count: Number.isFinite(n) ? n : 0, summary: String(x.summary || ''), sources, confidence: conf, occurred, skip })
  })

  // District notes: explicit notes win; otherwise the first counted summaries of that district.
  const notes: Record<string, string> = {}
  Object.entries(f.district_notes || {}).forEach(([raw, v]) => {
    const dk = dist(raw)
    if (dk && v) notes[dk] = String(v).trim().slice(0, 220)
  })
  const auto: Record<string, string> = {}
  rows.filter((r) => !r.skip && r.district && r.summary).forEach((r) => {
    const next = auto[r.district] ? `${auto[r.district]} ${r.summary.trim()}` : r.summary.trim()
    if (next.length <= 220) auto[r.district] = next
  })
  Object.keys(auto).forEach((dk) => { if (!notes[dk]) notes[dk] = auto[dk] })
  if (opt.mode === 'replace') data.notes = {}
  Object.keys(notes).forEach((dk) => { if (opt.mode === 'replace' || !data.notes[dk]) data.notes[dk] = notes[dk] })

  // Economy.
  const ecoKeys = new Set(sheet.economy.map((e) => e.key))
  const eco: DcImportResult['eco'] = []
  const ecoUnknown: string[] = []
  Object.entries(f.economy || {}).forEach(([key, raw]) => {
    const v = raw && typeof raw === 'object' ? (raw as { value?: unknown }).value : raw
    const src = raw && typeof raw === 'object' ? String((raw as { source?: string }).source || '') : ''
    const n = toNum(v)
    if (!ecoKeys.has(key)) { ecoUnknown.push(key); return }
    if (v === null || v === undefined || !Number.isFinite(n)) return
    data.eco[key] = n
    eco.push({ key, value: n, source: src })
  })

  // Highlights (replace when the file has some).
  const hls = (Array.isArray(f.highlights) ? f.highlights : [])
    .filter((h) => h && String(h.text || '').trim())
    .slice(0, 15)
    .map((h) => ({ t: String(h.text).trim().slice(0, 240), u: isUrl(h.url) ? String(h.url).trim() : '', d: dist(h.district) }))
  if (hls.length) data.hl = opt.mode === 'add' ? [...data.hl, ...hls].slice(0, 15) : hls

  if (f.summary && String(f.summary).trim() && (opt.mode === 'replace' || !data.summary)) data.summary = String(f.summary).trim().slice(0, 600)

  // Internal sources note (never shown to readers): outlets used + gaps.
  const outlets = new Map<string, number>()
  rows.filter((r) => !r.skip).forEach((r) => r.sources.forEach((s) => {
    const o = (s.outlet || (isUrl(s.url) ? new URL(String(s.url)).hostname.replace(/^www\./, '') : '')).trim()
    if (o) outlets.set(o, (outlets.get(o) || 0) + 1)
  }))
  const gaps = (Array.isArray(f.coverage_gaps) ? f.coverage_gaps : []).map(String).filter(Boolean)
  const srcLine = `এআই ইমপোর্ট ${date}: ` + [...outlets.entries()].sort((a, b) => b[1] - a[1]).map(([o, c]) => `${o} (${c})`).join(', ') + (gaps.length ? ` | ঘাটতি: ${gaps.join('; ')}` : '')
  data.sources = (opt.mode === 'add' && data.sources ? `${data.sources}\n${srcLine}` : srcLine).slice(0, 600)

  if (f.date && f.date !== sheet.day) warnings.unshift(`ফাইলের তারিখ ${f.date}; ইমপোর্ট হবে সেই দিনের শিটে।`)
  if (f.version && f.version > DC_FORMAT_VERSION) warnings.push('ফাইলটি নতুন সংস্করণের; কিছু ঘর বাদ পড়তে পারে।')
  if (ecoUnknown.length) warnings.push(`অচেনা অর্থনীতি-কী বাদ: ${ecoUnknown.join(', ')}`)

  const totals: Record<string, number> = {}
  rows.filter((r) => !r.skip).forEach((r) => { totals[r.indicator] = (totals[r.indicator] || 0) + r.count })
  return {
    date, rows, data, totals, eco, ecoUnknown, warnings: [...new Set(warnings)], gaps,
    counted: rows.filter((r) => !r.skip).length,
    skipped: rows.filter((r) => r.skip).length,
  }
}

/* ---------------------------------------------------------------- template + prompt */
/** An empty file in the right shape (one example row per list, values blank). */
export function importTemplate(sheet: DcSheet, date: string): DcImportFile {
  const eco: DcImportFile['economy'] = {}
  sheet.economy.filter((e) => +e.on).forEach((e) => { eco[e.key] = { value: null, as_of: date, source: '', url: '' } })
  return {
    format: DC_FORMAT,
    version: DC_FORMAT_VERSION,
    date,
    generated_at: '',
    incidents: [{
      district: 'dhaka', indicator: sheet.indicators[0]?.key || 'khun', count: 1, occurred_on: date, place: '', summary: '', confidence: 'high',
      sources: [{ outlet: '', url: '', published_at: '', type: 'news' }],
    }],
    district_notes: {},
    economy: eco,
    highlights: [{ text: '', district: '', url: '' }],
    summary: '',
    coverage_gaps: [],
    method_note: '',
  }
}

// How each default indicator is counted (custom indicators fall back to "number of incidents").
const COUNT_RULES: Record<string, string> = {
  khun: 'number of PEOPLE killed in homicides (murder, beating to death, mob killing, killing in political or land clashes). Not accidents, not suicides.',
  sorok_nihoto: 'number of PEOPLE who died in road accidents (bus, truck, motorbike, auto-rickshaw, pedestrians hit). Include rail-crossing deaths. Count deaths reported that day, including those who died in hospital that day from a crash on this date.',
  sorok_ahoto: 'number of PEOPLE injured in road accidents, only when a figure is reported. Never guess.',
  dhorshon: 'number of VICTIMS of rape, attempted rape, gang rape, sexual assault and serious violence against women and children reported for this date.',
  chhintai: 'number of INCIDENTS of theft, mugging/snatching, robbery and dacoity.',
  attohotya: 'number of PEOPLE who died by suicide. Report factually, no method details in summaries.',
  dube: 'number of PEOPLE who drowned (ponds, rivers, boat or trawler capsize).',
  agun: 'number of fire INCIDENTS (buildings, markets, factories, slums, vehicles).',
  greftar: 'number of PEOPLE arrested in operations that were individually reported (named drives, RAB/police/DB press releases). Do NOT copy nationwide routine totals (e.g. "x arrested in country-wide drives") into one district; if only a national total is known put it with district null and say so in summary.',
  safolyo: 'number of positive INCIDENTS: rescue of abducted/missing people, recovery of stolen goods, major seizures (drugs, arms, smuggled goods), notable achievements.',
}

export function buildPrompt(sheet: DcSheet, date: string): string {
  const inds = sheet.indicators.filter((i) => +i.on)
  const ecos = sheet.economy.filter((e) => +e.on)
  const groups = sheet.ecoGroups
  const dists = Object.keys(sheet.geo.districts).map((k) => `${k}=${sheet.geo.districts[k][0]}`).join(', ')
  const indLines = inds.map((i) => `- "${i.key}" — ${i.label}: count the ${COUNT_RULES[i.key] || 'number of incidents reported for this date.'}`).join('\n')
  const ecoLines = ecos.map((e) => `- "${e.key}" — ${e.label}${e.code ? ` (${e.code})` : ''} [${groups[e.group] || e.group}; unit: ${e.unit || 'number'}]`).join('\n')
  const prev = (() => { const [y, m, d] = date.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d + 1)).toISOString().slice(0, 10) })()
  // r26.6 Meeha-first: what Meeha News' own reports already counted for this date (sheet.meeha is that day's list).
  const own = sheet.day === date && sheet.meeha && sheet.meeha.n ? sheet.meeha.posts : []
  const indShort = (k: string) => inds.find((i) => i.key === k)?.key || k
  const ownLines = own.map((p, i) => `[M${i + 1}] district=${p.d || 'null'}${p.dn ? ` (${p.dn}${p.up ? `, ${p.up}` : ''})` : ''} | ${Object.entries(p.c).map(([k, n]) => `${indShort(k)}=${n}`).join(', ')} | ${p.time} | "${p.title.replace(/"/g, "'")}" | ${safeDecode(p.url)}`).join('\n')
  const ownTot = own.length ? inds.filter((i) => sheet.meeha!.tot[i.key]).map((i) => `${i.key}=${sheet.meeha!.tot[i.key]}`).join(', ') : ''
  const meehaBlock = own.length
    ? `

MEEHA-FIRST: ALREADY RECORDED BY MEEHA NEWS (${own.length} reports; totals: ${ownTot})
Meeha News' own reporters already published these reports for ${date} and their counts are ALREADY in the sheet:
${ownLines}
Rules for this list:
- Your job is to find what is MISSING from it: other events, other districts, other indicators.
- Do NOT return any incident that is the same real-world event as an [M#] item (same district/area, same type, same victims or place), even when other outlets reported it too. Never cite a Meeha News URL as a source.
- If another credible outlet gives a DIFFERENT figure for an [M#] event (e.g. the death toll rose later), do not add an incident; write it in "coverage_gaps" as "UPDATE [M#]: <indicator> <new number> — <outlet> <url>" so the editor can correct it.
- "highlights": list only events that are NOT in this list (Meeha's own reports are shown on the page automatically).`
    : ''
  return `ROLE
You are the night data desk of Meeha News (মিহা নিউজ), a Bangladeshi news portal. You research, verify and structure facts. You never invent, estimate or "fill in" numbers.

TASK
Build the "দৈনিক দেশচিত্র" (daily national picture) for ${date} — the full calendar day 00:00–23:59 Bangladesh time (UTC+6). Search the public web for incidents that HAPPENED on ${date} in Bangladesh, plus the economy figures valid on ${date}. Reports published on ${date} or ${prev} usually describe ${date}; check the event date in each report.${own.length ? `
Meeha News has already recorded part of this day from its own reports (see MEEHA-FIRST below). Collect ONLY the incidents that are missing from that list; economy figures are still needed in full.` : ''}${meehaBlock}

WHERE TO LOOK (only publicly published material)
1. National and local news portals and newspapers, Bangla and English (national dailies, online portals, district/local portals, TV channel websites).
2. News agencies: BSS (Bangladesh Sangbad Sangstha), UNB and similar.
3. Administration and law enforcement press releases and official pages: Bangladesh Police and district police units, DMP and other metropolitan police, RAB media wing, Detective Branch (DB), Fire Service and Civil Defence, Coast Guard, BGB, district administrations, hospital briefings.
4. Human rights and social organisations: Ain o Salish Kendra (ASK), Bangladesh Mahila Parishad, Road Safety Foundation, Bangladesh Jatri Kalyan Samity and similar groups, when they publish day-level information.
5. Intelligence or security agencies ONLY through their own public statements or what reputable media quote from them. Never speculate about unpublished material.
6. Economy: Bangladesh Bank (exchange rates, reserve), Dhaka Stock Exchange (DSEX), Trading Corporation of Bangladesh (TCB daily market prices), BAJUS (gold price), Bangladesh Bureau of Statistics (inflation, latest monthly figure).
Search district by district and in Bangla as well as English (e.g. "${date} খুন", "সড়ক দুর্ঘটনা নিহত", "<জেলার নাম> ধর্ষণ"). Cover all 8 divisions.

INDICATORS AND HOW TO COUNT (use these exact keys)
${indLines}

RULES FOR INCIDENTS
- One entry in "incidents" = one real event. If several outlets report the same event, keep ONE entry and list every outlet in its "sources". Deduplicate carefully (same place, same victims, same time).
- "count" = the number defined above for that indicator (people or incidents). If one event fits two indicators (e.g. a road crash with 3 dead and 10 injured) make two entries, one per indicator, with the same sources.
- "district": one key from the list below. If the district cannot be determined, use null. Never guess a district.
- "occurred_on": the date the event happened (YYYY-MM-DD). Leave out events that did not happen on ${date}; deaths in hospital count on the day of death.
- "summary": one short factual Bangla sentence (max 140 characters): what, where (upazila/area), who (age/occupation if reported, no names of rape victims or minors).
- "confidence": "high" = official source or 2+ independent outlets; "medium" = one credible outlet; "low" = unclear, conflicting or social-media based. Low items are allowed but will be reviewed by an editor.
- Every incident MUST have at least one source with a working URL. No URL → do not include it.
- Do not include rumours, unconfirmed social media posts, or allegations presented as fact. Use neutral language ("অভিযোগ", "পুলিশ জানায়").

ECONOMY (use these exact keys; numbers only, no units, no commas)
${ecoLines}
- Exchange rates: taka per 1 unit of the foreign currency (Bangladesh Bank or commercial bank selling rate; say which in "source").
- Market prices: TCB Dhaka retail price; if a range is given use the midpoint and mention the range in "source".
- If a value for ${date} is not published, use null. Never repeat an older value as if it were new; if you use the latest available value, set "as_of" to its real date.

DISTRICT KEYS (key=Bangla name)
${dists}

ALSO GIVE
- "highlights": up to 10 of the most significant events of the day, one Bangla line each, with district key and the best source URL.
- "district_notes": optional, max 200 characters per district, only for districts with notable events.
- "summary": 2–3 neutral Bangla sentences on the day's overall picture (no figures you did not list).
- "coverage_gaps": honest list of what you could not cover (e.g. districts with no searchable local news, indicators with no data).
- "method_note": one line on how the data was gathered.

OUTPUT
Return ONLY one JSON object in a single \`\`\`json code block, exactly in this shape, and nothing else:
{
  "format": "${DC_FORMAT}",
  "version": ${DC_FORMAT_VERSION},
  "date": "${date}",
  "generated_at": "<ISO time you finished>",
  "incidents": [
    { "district": "dhaka", "indicator": "${inds[0]?.key || 'khun'}", "count": 1, "occurred_on": "${date}", "place": "<upazila/area>", "summary": "<Bangla sentence>", "confidence": "high", "sources": [ { "outlet": "<name>", "url": "https://…", "published_at": "<YYYY-MM-DD HH:MM>", "type": "news|police|rab|fire_service|admin|ngo|agency|hospital|other" } ] }
  ],
  "district_notes": { "<district key>": "<Bangla note>" },
  "economy": { "${ecos[0]?.key || 'usd'}": { "value": 0, "as_of": "${date}", "source": "<who published it>", "url": "https://…" } },
  "highlights": [ { "text": "<Bangla line>", "district": "<district key or null>", "url": "https://…" } ],
  "summary": "<Bangla>",
  "coverage_gaps": [ "<Bangla or English>" ],
  "method_note": "<one line>"
}
Before answering, check: valid JSON (double quotes, no comments, no trailing commas), only the keys above, every incident has a source URL and the date ${date}, no duplicates, no number you could not source. Accuracy is more important than completeness: an empty list is better than an invented one.`
}
