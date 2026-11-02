import { compressImageForUpload, uploadTimeoutFor } from './imageCompress'

/**
 * API client for the newsroom-os plugin's REST API (nros/v1).
 *
 * Auth model: a short-lived (15 min) access token kept in memory (never
 * localStorage, to limit XSS blast radius) plus a rotating refresh token
 * that lives in an httpOnly cookie set by the backend. The cookie's *name*
 * is this app's own (nros_refresh_correspondent, chosen server-side from the
 * Origin header) and each login is its own server-side session, so neither
 * another app nor another device of the same account can disturb it.
 *
 * Every request automatically retries once after a silent refresh if the
 * access token has expired (401 nros_no_token / nros_* token errors).
 *
 * Round 8: on top of that reactive retry, setAccessToken() now also
 * schedules a *proactive* refresh a couple of minutes before the access
 * token's own 15-minute lifetime runs out — so an open tab renews its
 * session quietly in the background and (combined with each page's own
 * auto-refresh polling) a correspondent who leaves the dashboard open
 * never actually hits an expired-token request in the first place. If a
 * refresh — proactive or reactive — ever comes back definitively rejected
 * (refresh token itself invalid/expired, not just a network blip),
 * setSessionExpiredHandler()'s callback runs so the app can drop back to a
 * clean login screen instead of leaving stale, increasingly-failing state
 * on screen.
 *
 * Tabs of the same browser share one refresh cookie, so their refreshes
 * can race. Since round 9 follow-up 3 the backend absorbs that by design
 * (a session rotates at most once a minute and still accepts the previous
 * id without rotating again — verified with a multi-worker test harness);
 * PROACTIVE_REFRESH_JITTER_MS remains as a cheap extra spreading of tabs'
 * background refreshes. Network problems never log anyone out any more —
 * see "network resilience" below.
 */

// Set at build time via .env (VITE_API_BASE). Falls back to the production
// API origin so a plain `npm run build` still points at the real site.
export const API_BASE = import.meta.env.VITE_API_BASE || 'https://meeha.news/wp-json/nros/v1'

// Mirrors the backend's NROS_Auth::ACCESS_TTL (900s) — refreshing ~2
// minutes ahead of that leaves comfortable margin for clock drift/slow
// requests while still renewing well before expiry.
const PROACTIVE_REFRESH_MS = 13 * 60 * 1000
// Up to an extra minute of random jitter on top of PROACTIVE_REFRESH_MS, so
// several tabs opened together don't all fire their proactive refresh in
// the same instant (see the docblock above). Still comfortably inside the
// 15-minute access-token lifetime.
const PROACTIVE_REFRESH_JITTER_MS = 60 * 1000

let accessToken: string | null = null
// When the current access token was received — lets a long video upload
// start with a freshly renewed token (see MediaApi.uploadVideo).
let accessTokenSetAt = 0
let refreshPromise: Promise<RefreshOutcome> | null = null
let refreshTimer: ReturnType<typeof setTimeout> | null = null
let sessionExpiredHandler: (() => void) | null = null

// ---- Round 9 follow-up 3: network resilience ----
//
// Before this, every failure looked the same: a one-second mobile-data
// dropout during /auth/refresh was treated exactly like a genuinely rejected
// session (→ logged out), any failed request showed the same generic "ডাটা
// লোড করতে সমস্যা হয়েছে", a hung request could spin forever, and a non-JSON
// reply (a PHP warning, a host error page) crashed JSON.parse with no clue
// left behind. Now:
//   - a refresh has three outcomes: 'ok', 'rejected' (the server answered 401
//     — the session really is gone → log out) and 'unavailable' (network /
//     timeout / 5xx / garbled reply → keep the session, report a connection
//     problem, try again later). Only 'rejected' ever logs anyone out.
//   - every request has a timeout; GETs retry automatically (twice, with
//     backoff) on network errors, timeouts and 502/503/504.
//   - every failure becomes an ApiError with a `kind`, the endpoint `path`,
//     the HTTP status and (for a non-JSON reply) a short snippet, which
//     describeError() turns into a specific message plus a one-line technical
//     detail the error screen shows — so a screenshot identifies the cause.
export type RefreshOutcome = 'ok' | 'rejected' | 'unavailable'

const REQUEST_TIMEOUT_MS = 20_000
const UPLOAD_TIMEOUT_MS = 120_000
const RETRY_DELAYS_MS = [700, 2000]
const PROACTIVE_RETRY_MS = 30_000
const RETRYABLE_STATUSES = new Set([502, 503, 504])

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

export function setSessionExpiredHandler(fn: (() => void) | null) {
  sessionExpiredHandler = fn
}

function scheduleProactiveRefresh(delayMs: number) {
  if (refreshTimer) clearTimeout(refreshTimer)
  refreshTimer = setTimeout(() => {
    refreshTimer = null
    refreshOnce().then((outcome) => {
      if (outcome === 'rejected') {
        sessionExpiredHandler?.()
      } else if (outcome === 'unavailable' && accessToken) {
        // Couldn't reach the server right now (phone asleep, tunnel, weak
        // signal) — that says nothing about the session itself, so keep it
        // and simply try again shortly.
        scheduleProactiveRefresh(PROACTIVE_RETRY_MS)
      }
      // 'ok': doRefresh → setAccessToken already scheduled the next one.
    })
  }, delayMs)
}

export function setAccessToken(token: string | null) {
  accessToken = token
  accessTokenSetAt = token ? Date.now() : 0

  if (refreshTimer) {
    clearTimeout(refreshTimer)
    refreshTimer = null
  }

  if (token) {
    scheduleProactiveRefresh(PROACTIVE_REFRESH_MS + Math.random() * PROACTIVE_REFRESH_JITTER_MS)
  }
}

export function getAccessToken() {
  return accessToken
}

export type ApiErrorKind = 'offline' | 'network' | 'timeout' | 'session' | 'server' | 'client'

export class ApiError extends Error {
  status: number
  code?: string
  kind: ApiErrorKind
  path?: string
  snippet?: string
  // The WP_Error `data` object of an error reply (e.g. OTP: restart, attempts_left, resend_after).
  data?: Record<string, unknown>
  constructor(message: string, status: number, code?: string, kind?: ApiErrorKind, path?: string, snippet?: string) {
    super(message)
    this.status = status
    this.code = code
    this.kind = kind ?? (status === 0 ? 'network' : status >= 500 ? 'server' : 'client')
    this.path = path
    this.snippet = snippet
  }
}

class RequestTimeout extends Error {}

async function fetchWithTimeout(url: string, init: RequestInit, timeoutMs: number) {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)
  try {
    return await fetch(url, { ...init, signal: controller.signal })
  } catch (err) {
    if (controller.signal.aborted) throw new RequestTimeout('timeout')
    throw err
  } finally {
    clearTimeout(timer)
  }
}

function connectionError(err: unknown, path: string) {
  if (err instanceof ApiError) return err
  if (err instanceof RequestTimeout) {
    return new ApiError('সার্ভার সময়মতো সাড়া দেয়নি।', 0, 'nros_timeout', 'timeout', path)
  }
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    return new ApiError('ইন্টারনেট সংযোগ নেই।', 0, 'nros_offline', 'offline', path)
  }
  return new ApiError('সার্ভারের সাথে সংযোগ করা যায়নি।', 0, 'nros_network', 'network', path)
}

// Strips markup from an unexpected (non-JSON) reply so the first words of a
// PHP warning or a host's error page fit on one line of the error screen.
function snippetOf(text: string) {
  return text
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .replace(/ ([:;,.!?])/g, '$1')
    .trim()
    .slice(0, 90)
}

async function doRefreshAttempt(): Promise<RefreshOutcome> {
  let res: Response
  try {
    res = await fetchWithTimeout(`${API_BASE}/auth/refresh`, { method: 'POST', credentials: 'include' }, REQUEST_TIMEOUT_MS)
  } catch {
    return 'unavailable'
  }
  if (res.status === 401) return 'rejected'
  if (!res.ok) return 'unavailable'
  try {
    const data = await res.json()
    if (!data || typeof data.access_token !== 'string') return 'unavailable'
    setAccessToken(data.access_token)
    return 'ok'
  } catch {
    return 'unavailable'
  }
}

// Retrying a refresh is safe: since round 9 follow-up 3 the backend accepts
// the previous refresh-token id without rotating again, so resending the
// same cookie after a lost response can't strand the session.
async function doRefresh(): Promise<RefreshOutcome> {
  let outcome = await doRefreshAttempt()
  for (const delay of RETRY_DELAYS_MS) {
    if (outcome !== 'unavailable') break
    await sleep(delay)
    outcome = await doRefreshAttempt()
  }
  return outcome
}

async function refreshOnce(): Promise<RefreshOutcome> {
  if (!refreshPromise) {
    refreshPromise = doRefresh().finally(() => {
      refreshPromise = null
    })
  }
  return refreshPromise
}

interface RequestOpts {
  method?: string
  body?: Record<string, unknown> | FormData
  auth?: boolean // defaults true
  retry?: boolean // allow automatic retries for a non-GET that is safe to repeat (e.g. logout)
}

async function request<T>(path: string, opts: RequestOpts = {}): Promise<T> {
  const { method = 'GET', body, auth = true, retry = false } = opts

  const headers: Record<string, string> = {
    // Belt-and-suspenders against any caching layer (hosting-level page
    // cache, a misconfigured CDN, etc.) ever serving a stale GET response
    // — the backend also sends matching no-store response headers on every
    // nros/v1 route (see class-nros-core.php).
    'Cache-Control': 'no-cache',
  }
  let payload: BodyInit | undefined

  if (body instanceof FormData) {
    payload = body
  } else if (body) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  if (auth && accessToken) {
    headers['Authorization'] = `Bearer ${accessToken}`
  }

  // Only reads are retried automatically — repeating a POST/PATCH/DELETE
  // whose reply was lost could apply it twice.
  const idempotent = method === 'GET' || retry
  const timeoutMs = body instanceof FormData ? UPLOAD_TIMEOUT_MS : REQUEST_TIMEOUT_MS
  const attempts = idempotent ? RETRY_DELAYS_MS.length + 1 : 1

  const doFetch = async (): Promise<Response> => {
    let lastErr: unknown
    for (let i = 0; i < attempts; i++) {
      if (i > 0) await sleep(RETRY_DELAYS_MS[i - 1])
      try {
        const res = await fetchWithTimeout(
          `${API_BASE}${path}`,
          { method, headers, body: payload, credentials: 'include' },
          timeoutMs,
        )
        if (RETRYABLE_STATUSES.has(res.status) && i < attempts - 1) continue
        return res
      } catch (err) {
        lastErr = err
      }
    }
    throw connectionError(lastErr, path)
  }

  let res = await doFetch()

  if (res.status === 401 && auth) {
    await renewAfterUnauthorized(path)
    headers['Authorization'] = `Bearer ${accessToken}`
    res = await doFetch()
  }

  let text: string
  try {
    text = await res.text()
  } catch (err) {
    throw connectionError(err, path)
  }

  return parseApiResponse<T>(res.status, text, path)
}

/**
 * Shared by request() and the XHR-based video upload: a request came back
 * 401, so silently renew the access token. Resolves when a fresh token is in
 * place (the caller then repeats its request once); otherwise throws the
 * right ApiError — a definitively rejected refresh logs the user out, an
 * unreachable server does NOT.
 */
async function renewAfterUnauthorized(path: string): Promise<void> {
  const outcome = await refreshOnce()
  if (outcome === 'ok') return
  if (outcome === 'rejected') {
    // The session is really gone (logged out elsewhere, expired, evicted)
    // — drop back to a clean login screen instead of failing forever.
    sessionExpiredHandler?.()
    throw new ApiError('সেশন শেষ হয়ে গেছে, আবার লগইন করুন।', 401, 'nros_session_expired', 'session', path)
  }
  // Couldn't reach the server to renew — NOT a logout.
  throw new ApiError('সার্ভারের সাথে সংযোগ করা যায়নি।', 0, 'nros_refresh_unavailable', 'network', path)
}

/** Turns a raw reply (status + body text) into data or a descriptive ApiError. */
function parseApiResponse<T>(status: number, text: string, path: string): T {
  const ok = status >= 200 && status < 300
  let data: { message?: string; code?: string } & Record<string, unknown> = {}
  if (text) {
    try {
      data = JSON.parse(text)
    } catch {
      throw new ApiError(
        ok ? 'সার্ভার থেকে অপ্রত্যাশিত উত্তর এসেছে।' : `সার্ভারে সমস্যা হয়েছে (HTTP ${status})।`,
        status,
        'nros_bad_response',
        'server',
        path,
        snippetOf(text),
      )
    }
  }

  if (!ok) {
    const fallback = status >= 500 ? `সার্ভারে সমস্যা হয়েছে (HTTP ${status})।` : 'একটি সমস্যা হয়েছে।'
    const e = new ApiError(data.message || fallback, status, data.code, status >= 500 ? 'server' : 'client', path)
    if (data.data && typeof data.data === 'object') e.data = data.data as Record<string, unknown>
    throw e
  }

  return data as T
}

/**
 * Turns any thrown value into what an error screen should say: a plain
 * Bengali `message`, plus a short technical `detail` (endpoint · status ·
 * code · snippet) shown in small print — enough for one screenshot to say
 * whether it was the phone's connection, the server, or the session.
 */
export function describeError(err: unknown): { message: string; detail: string } {
  if (err instanceof ApiError) {
    const kindLabel: Record<ApiErrorKind, string> = {
      offline: 'অফলাইন',
      network: 'নেটওয়ার্ক',
      timeout: 'টাইমআউট',
      session: 'সেশন',
      server: 'সার্ভার',
      client: 'রিকোয়েস্ট',
    }
    const messages: Partial<Record<ApiErrorKind, string>> = {
      offline: 'আপনার ডিভাইসে এখন ইন্টারনেট সংযোগ নেই। সংযোগ চালু করে আবার চেষ্টা করুন।',
      network: 'সার্ভারের সাথে সংযোগ করা যায়নি — সম্ভবত নেটওয়ার্ক দুর্বল, অথবা সার্ভার এই মুহূর্তে সাড়া দিচ্ছে না।',
      timeout: 'সার্ভার সময়মতো সাড়া দেয়নি। একটু পরে আবার চেষ্টা করুন।',
      session: 'সেশন শেষ হয়ে গেছে, আবার লগইন করুন।',
    }
    const detail = [
      err.path ? err.path.split('?')[0] : '',
      err.status ? `HTTP ${err.status}` : kindLabel[err.kind],
      err.code ?? '',
      err.snippet ? `“${err.snippet}”` : '',
    ]
      .filter(Boolean)
      .join(' · ')
    return { message: messages[err.kind] ?? err.message, detail }
  }
  const raw = err instanceof Error ? err.message : String(err)
  return { message: 'অপ্রত্যাশিত একটি সমস্যা হয়েছে।', detail: raw.slice(0, 120) }
}

// ---- Types ----

export interface Profile {
  id: number
  name: string
  email: string
  phone: string
  title: string
  division: string
  district: string
  upazila: string
  avatar: string
  // The backend always returns these (publisher-app uses them to gate its
  // own UI) — this app previously ignored them entirely. Now used to make
  // sure the account authenticated via the shared .meeha.news cookie is
  // actually a correspondent (see AuthContext.tsx) before showing this
  // app's dashboard.
  role: string
  roles: string[]
}

export interface Submission {
  id: number
  title: string
  content: string
  excerpt: string
  // The theme's own "এ.আই সারাংশ" field (_meeha_summary postmeta), shown
  // on the live site as the "সারাংশ পড়ুন" popup — separate from 'excerpt'
  // (the article's subtitle/teaser). Round 8.
  ai_summary: string
  status: 'draft' | 'pending' | 'publish'
  category_id: number
  topic: string
  division: string
  district: string
  upazila: string
  // Round 12 (CONTRACT-R12 §A): tag names and the area as one object — the
  // flat division/district/upazila keys above stay for older plugin versions.
  tags?: string[]
  area?: { division: string; district: string; upazila: string }
  views: number
  featured_image: string | null
  featured_image_id: number | null
  featured_image_alt: string
  featured_image_caption: string
  // Set by a Publisher per-post, or auto-applied from this correspondent's
  // default per-post rate; null means this post has no configured payment,
  // in which case it must show NO income anywhere (not zero).
  payment: number | null
  // 'pending' = auto-applied from a default rate, awaiting the Publisher's
  // confirmation — shown, but NOT YET part of /me/earnings' available
  // balance. 'approved' = set directly or confirmed. null alongside a null
  // payment.
  payment_status: 'pending' | 'approved' | null
  // True only for a draft that was sent back from the editorial queue and
  // hasn't been meaningfully edited since — see `reject_reason` and the
  // backend's submit_for_review() guard (resubmitting without a real edit
  // is rejected with a 409).
  rejected: boolean
  reject_reason: string | null
  created_at: string
  updated_at: string
  permalink: string | null
}

export interface RevisionEntry {
  id: number
  author: string | null
  date: string
  title_diff: string
  excerpt_diff: string
  content_diff: string
}

export interface Category {
  id: number
  name: string
  slug: string
  // Round 18 (plugin 1.3.0+): the tree and popularity, used by the category
  // picker. Older plugins send neither — the picker then shows a flat list.
  parent?: number
  count?: number
  is_default?: boolean
}

export interface AnalyticsTotals {
  views: number
  reactions: number
  shares: number
  comments: number
}

export interface AnalyticsTrendPoint {
  stat_date: string
  views: string
  reactions: string
  shares: string
  comments: string
}

export interface LedgerEntry {
  id: number
  type: 'honorarium' | 'commission' | 'bonus' | 'adjustment' | 'post_payment' | 'view_bonus' | 'salary' | 'video_payment' | 'video'
  amount: string
  type_label?: string
  post_id?: number | null
  status: string
  note: string
  created_at: string
}

export interface WithdrawRequest {
  id: number
  amount: string
  method: string
  status: 'pending' | 'paid' | 'rejected'
  created_at: string
  updated_at: string
}

// ---- Video news (round 10, see the shared CONTRACT §2) ----

export type VideoStatus = 'draft' | 'pending' | 'publish' | 'future'
export type VideoSource = 'upload' | 'youtube'
export type VideoOrientation = 'portrait' | 'landscape'

export interface Video {
  id: number
  title: string
  excerpt: string
  slug: string
  status: VideoStatus
  link: string
  date: string // post_date 'YYYY-MM-DD HH:MM:SS' (site local time)
  modified: string
  author: { id: number; name: string; avatar: string }
  source: VideoSource
  video_url: string // uploaded file URL, or the YouTube URL as pasted
  video_attachment_id: number | null
  youtube_id: string | null
  orientation: VideoOrientation
  duration: number | null
  thumbnail: string | null // for YouTube without a custom thumbnail: i.ytimg.com hqdefault
  thumbnail_id: number | null
  category_id: number | null
  seo: { title: string; description: string; focus_keyword: string }
  stats: { views: number; likes: number; comments: number; shares: number }
  rejected: boolean
  reject_reason: string | null
  // Round 12 (CONTRACT-R12 §C): per-video payment, same semantics as an
  // article's — null = no payment configured (show nothing, not zero);
  // 'pending' = default rate auto-applied, awaiting the publisher.
  payment?: number | null
  payment_status?: 'pending' | 'approved' | null
}

export interface VideoInput {
  title?: string
  excerpt?: string
  slug?: string
  source?: VideoSource
  video_attachment_id?: number
  video_url?: string
  thumbnail_id?: number | null
  category_id?: number | null
  orientation?: VideoOrientation
  duration?: number | null
  seo?: { title?: string; description?: string; focus_keyword?: string }
  status?: 'draft' | 'pending' // a correspondent may only use these two
}

export interface VideoUploadResult {
  id: number
  url: string
  mime: string
  size: number
}

export interface VideoLimits {
  max_bytes: number
}

export interface UploadProgress {
  loaded: number
  total: number
  percent: number // 0–100, integer
}

export interface AreasResponse {
  divisions: Array<{ name: string; districts: Array<{ name: string } | string> }>
  upazilas: Record<string, string[]>
  source: string
}

// ---- Auth ----

export interface LoginSuccess {
  access_token: string
  expires_in: number
  user: Profile
}

// Round 12 (CONTRACT-R12 §D): when the theme requires a one-time code for
// this account, /auth/login answers with this challenge instead of tokens.
export interface OtpChallenge {
  otp_required: true
  otp_token: string
  method: 'email' | 'sms'
  destination: string // masked, e.g. h***@gmail.com
  resend_after: number // seconds
  expires_in?: number
  message?: string
}

export const LOGIN_APP = 'correspondent'

export const AuthApi = {
  login: (identifier: string, password: string) =>
    request<LoginSuccess | OtpChallenge>('/auth/login', {
      method: 'POST',
      body: { identifier, password, app: LOGIN_APP },
      auth: false,
    }),
  loginOtp: (otp_token: string, code: string) =>
    request<LoginSuccess>('/auth/login/otp', {
      method: 'POST',
      body: { otp_token, code, app: LOGIN_APP },
      auth: false,
    }),
  resendOtp: (otp_token: string) =>
    request<{ ok: boolean; resend_after: number; otp_token?: string; destination?: string; method?: 'email' | 'sms' }>('/auth/login/otp/resend', {
      method: 'POST',
      body: { otp_token },
      auth: false,
    }),
  // Shares any refresh already in flight instead of starting a second one.
  refresh: () => refreshOnce(),
  // Waits for an in-flight refresh first (so it can't land after the logout
  // and set the cookie again), stops the background refresh timer, and is
  // retried on a network blip — logging out twice is harmless.
  logout: async () => {
    if (refreshPromise) await refreshPromise.catch(() => undefined)
    if (refreshTimer) {
      clearTimeout(refreshTimer)
      refreshTimer = null
    }
    return request<{ ok: true }>('/auth/logout', { method: 'POST', auth: false, retry: true })
  },
  me: () => request<Profile>('/me'),
}

// ---- Areas (public, pre-login) ----

// Normalizes the /areas response defensively: if the backend (or a future
// change to the theme's area-data functions) ever returns divisions as a
// keyed object instead of a plain array, calling .map()/.find() on it would
// throw and blank the whole screen (this happened once — a theme helper
// returned an associative array and the raw response was passed straight
// through). Coercing to a known-safe shape here means a backend regression
// degrades to "area dropdowns are empty" instead of crashing the app.
function normalizeAreas(raw: unknown): AreasResponse {
  const r = (raw ?? {}) as Record<string, unknown>

  const divisions = Array.isArray(r.divisions)
    ? r.divisions
        .filter((d): d is Record<string, unknown> => !!d && typeof d === 'object')
        .map((d) => ({
          name: typeof d.name === 'string' ? d.name : '',
          districts: Array.isArray(d.districts) ? d.districts.filter((x): x is string => typeof x === 'string') : [],
        }))
        .filter((d) => d.name !== '')
    : []

  const upazilas =
    r.upazilas && typeof r.upazilas === 'object' && !Array.isArray(r.upazilas)
      ? (r.upazilas as Record<string, string[]>)
      : {}

  return {
    divisions,
    upazilas,
    source: typeof r.source === 'string' ? r.source : 'unavailable',
  }
}

export const AreasApi = {
  get: async () => normalizeAreas(await request<unknown>('/areas', { auth: false })),
}

// ---- Applications (public KYC apply) ----

export const ApplicationsApi = {
  submit: (form: FormData) =>
    request<{ ok: true; application_id: number }>('/applications', {
      method: 'POST',
      body: form,
      auth: false,
    }),
}

// ---- Submissions ----

export const SubmissionsApi = {
  list: () => request<Submission[]>('/submissions'),
  get: (id: number) => request<Submission>(`/submissions/${id}`),
  create: (data: Partial<Submission>) =>
    request<Submission>('/submissions', { method: 'POST', body: data as Record<string, unknown> }),
  update: (id: number, data: Partial<Submission>) =>
    request<Submission>(`/submissions/${id}`, { method: 'PATCH', body: data as Record<string, unknown> }),
  submitForReview: (id: number) =>
    request<Submission>(`/submissions/${id}/submit`, { method: 'POST' }),
  requestEdit: (id: number, reason: string) =>
    request<{ ok: true; edit_request_id: number }>(`/submissions/${id}/edit-request`, {
      method: 'POST',
      body: { reason },
    }),
  setFeaturedImage: (id: number, attachmentId: number, extra?: { alt?: string; caption?: string }) =>
    request<Submission>(`/submissions/${id}/featured-image`, {
      method: 'POST',
      body: { attachment_id: attachmentId, ...extra },
    }),
  // Full edit-history timeline (every revision WordPress kept, diffed
  // against the one before it) — for the correspondent to see exactly
  // what an editor changed before publishing, for training.
  getRevisions: (id: number) => request<RevisionEntry[]>(`/submissions/${id}/revisions`),
}

// ---- Video news ----

export const VideosApi = {
  list: () => request<Video[]>('/videos'),
  get: (id: number) => request<Video>(`/videos/${id}`),
  create: (data: VideoInput) =>
    request<Video>('/videos', { method: 'POST', body: data as unknown as Record<string, unknown> }),
  // Refused with a 409 (Bengali message) once the video is published.
  update: (id: number, data: VideoInput) =>
    request<Video>(`/videos/${id}`, { method: 'PATCH', body: data as unknown as Record<string, unknown> }),
}

// ---- Categories ----

export const CategoriesApi = {
  list: () => request<Category[]>('/categories'),
}

// ---- Prompts (round 9) ----
// Publisher manages these in publisher-app's "প্রোম্পট" menu, each one
// independently role-visible. A correspondent only ever sees prompts whose
// visible_roles includes 'correspondent' — the backend (GET /prompts)
// enforces that filtering, not this client.

export type PromptType = 'news' | 'library'
export type PromptFieldType = 'text' | 'textarea' | 'select'

export interface PromptField {
  key: string
  label: string
  field_type: PromptFieldType
  options?: string[]
}

export interface Prompt {
  id: number
  type: PromptType
  label: string
  body: string
  visible_roles: string[]
  fields: PromptField[]
  sort_order: number
}

export const PromptsApi = {
  list: (type?: PromptType) => request<Prompt[]>(`/prompts${type ? `?type=${type}` : ''}`),
}

// ---- Media ----

export const MediaApi = {
  // Round 18: photos are shrunk on the phone first, then sent with real
  // progress, a timeout scaled to the file size and one automatic retry —
  // see uploadImageFile() below.
  upload: (file: File, extra?: { alt?: string; caption?: string }, onProgress?: (p: UploadProgress) => void) =>
    uploadImageFile(file, extra, onProgress),
  updateMeta: (id: number, data: { alt?: string; caption?: string }) =>
    request<{ id: number; alt: string; caption: string }>(`/media/${id}`, { method: 'PATCH', body: data as Record<string, unknown> }),
  // The real maximum a video upload may be: min(plugin cap, wp_max_upload_size()).
  videoLimits: () => request<VideoLimits>('/media/video-limits'),
  /**
   * Uploads a video (multipart field `nros_video`) with real upload progress
   * and cancellation — see uploadVideoFile() below.
   */
  uploadVideo: (file: File, onProgress?: (p: UploadProgress) => void, opts: { signal?: AbortSignal } = {}) =>
    uploadVideoFile(file, onProgress, opts.signal),
}

// ---- Video upload (XMLHttpRequest, for real progress) ----
//
// fetch() can't report upload progress, and a phone uploading a 100 MB clip
// over mobile data needs to see that something is happening — so this one
// endpoint goes through XMLHttpRequest. It deliberately shares request()'s
// auth plumbing: the same in-memory access token, the same 401 → silent
// refresh → one retry (renewAfterUnauthorized), and the same reply parsing
// and Bengali errors (parseApiResponse).

const VIDEO_UPLOAD_TIMEOUT_MS = 15 * 60 * 1000
// WordPress only checks the token once the WHOLE body has arrived, so a
// long upload that starts with an old token could finish just after it
// expired (and then have to go up a second time). Renewing first when the
// token is older than this gives the upload a full 15-minute token window.
const FRESH_TOKEN_FOR_UPLOAD_MS = 2 * 60 * 1000
export const ALLOWED_VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/quicktime']
const ALLOWED_VIDEO_EXT = /\.(mp4|m4v|webm|mov)$/i

const BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯']
const bnDigits = (s: string | number) => String(s).replace(/[0-9]/g, (d) => BN_DIGITS[Number(d)])

/** Bytes → "২৪.৫ এমবি" / "৮৫০ কেবি" (whole numbers lose the ".0"). */
export function formatBytes(bytes: number) {
  if (bytes < 1024 * 1024) return `${bnDigits(Math.max(0, Math.round(bytes / 1024)))} কেবি`
  const mb = bytes / (1024 * 1024)
  const rounded = mb >= 100 ? Math.round(mb) : Math.round(mb * 10) / 10
  return `${bnDigits(rounded)} এমবি`
}

export function isAllowedVideoFile(file: File) {
  return file.type ? ALLOWED_VIDEO_TYPES.includes(file.type) : ALLOWED_VIDEO_EXT.test(file.name)
}

export function videoTooLargeMessage(size: number, maxBytes: number) {
  return `ভিডিওটি অনেক বড় (${formatBytes(size)}) — সর্বোচ্চ ${formatBytes(maxBytes)} পর্যন্ত আপলোড করা যাবে। ভিডিওটি ছোট/কম্প্রেস করে আবার চেষ্টা করুন।`
}

export function isUploadCancelled(err: unknown) {
  return err instanceof ApiError && err.code === 'nros_upload_cancelled'
}

function cancelledError(path: string) {
  return new ApiError('আপলোড বাতিল করা হয়েছে।', 0, 'nros_upload_cancelled', 'client', path)
}

function xhrPost(
  path: string,
  form: FormData,
  onProgress: ((p: UploadProgress) => void) | undefined,
  signal: AbortSignal | undefined,
  timeoutMs: number = VIDEO_UPLOAD_TIMEOUT_MS,
): Promise<{ status: number; text: string }> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(cancelledError(path))
      return
    }
    const xhr = new XMLHttpRequest()
    const onAbortSignal = () => xhr.abort()
    const done = () => signal?.removeEventListener('abort', onAbortSignal)

    xhr.open('POST', `${API_BASE}${path}`)
    xhr.withCredentials = true
    xhr.timeout = timeoutMs
    xhr.setRequestHeader('Cache-Control', 'no-cache')
    if (accessToken) xhr.setRequestHeader('Authorization', `Bearer ${accessToken}`)

    xhr.upload.onprogress = (e) => {
      if (!onProgress || !e.lengthComputable || e.total <= 0) return
      onProgress({ loaded: e.loaded, total: e.total, percent: Math.min(100, Math.floor((e.loaded / e.total) * 100)) })
    }
    xhr.onload = () => {
      done()
      resolve({ status: xhr.status, text: xhr.responseText })
    }
    xhr.onerror = () => {
      done()
      reject(connectionError(new Error('xhr network error'), path))
    }
    xhr.ontimeout = () => {
      done()
      reject(connectionError(new RequestTimeout('timeout'), path))
    }
    xhr.onabort = () => {
      done()
      reject(cancelledError(path))
    }
    signal?.addEventListener('abort', onAbortSignal)
    xhr.send(form)
  })
}

async function uploadVideoFile(
  file: File,
  onProgress?: (p: UploadProgress) => void,
  signal?: AbortSignal,
): Promise<VideoUploadResult> {
  const path = '/media/video'
  if (!isAllowedVideoFile(file)) {
    throw new ApiError('শুধু MP4, WebM বা MOV ফরম্যাটের ভিডিও আপলোড করা যাবে।', 0, 'nros_bad_video_type', 'client', path)
  }

  // Ask the server for its real limit first — never waste the user's data
  // on a file that would be refused only after it has fully gone up. (This
  // request also goes through request()'s own 401 handling.)
  const { max_bytes } = await MediaApi.videoLimits()
  if (max_bytes > 0 && file.size > max_bytes) {
    throw new ApiError(videoTooLargeMessage(file.size, max_bytes), 413, 'nros_video_too_large', 'client', path)
  }

  if (!accessToken || Date.now() - accessTokenSetAt > FRESH_TOKEN_FOR_UPLOAD_MS) {
    const outcome = await refreshOnce()
    if (outcome === 'rejected') {
      sessionExpiredHandler?.()
      throw new ApiError('সেশন শেষ হয়ে গেছে, আবার লগইন করুন।', 401, 'nros_session_expired', 'session', path)
    }
    // 'unavailable': go ahead with the current token; a 401 is still handled below.
  }

  const send = () => {
    const form = new FormData()
    form.append('nros_video', file)
    onProgress?.({ loaded: 0, total: file.size, percent: 0 })
    return xhrPost(path, form, onProgress, signal)
  }

  let res = await send()
  if (res.status === 401) {
    await renewAfterUnauthorized(path)
    res = await send()
  }
  // A body-size limit enforced in front of PHP (LiteSpeed/nginx) answers
  // with an HTML 413 page, not the plugin's JSON — say what it means.
  if (res.status === 413) {
    let message = videoTooLargeMessage(file.size, max_bytes)
    try {
      const data = JSON.parse(res.text) as { message?: string }
      if (data.message) message = data.message
    } catch {
      // not JSON — keep our own message
    }
    throw new ApiError(message, 413, 'nros_video_too_large', 'client', path)
  }
  return parseApiResponse<VideoUploadResult>(res.status, res.text, path)
}

// ---- Image upload (round 18) ----
//
// "সার্ভারের সাথে সংযোগ করা যায়নি" on photo uploads came from three things:
// a 3–12 MB phone photo outlasting the fixed 120 s timeout on slow mobile
// data, WordPress taking long to build every thumbnail of a huge photo, and
// a server body-size limit answering without CORS headers (which a browser
// reports as a network error). Now the photo is resized on the phone first
// (compressImageForUpload — usually well under 1 MB), the timeout grows with
// the file size, the upload shows real progress, and a dropped connection or
// timeout is retried once automatically before an error is shown.

const IMAGE_RETRY_DELAY_MS = 2500

async function uploadImageFile(
  original: File,
  extra?: { alt?: string; caption?: string },
  onProgress?: (p: UploadProgress) => void,
): Promise<{ id: number; url: string; alt: string; caption: string }> {
  const path = '/media'
  const file = await compressImageForUpload(original)
  const timeoutMs = uploadTimeoutFor(file.size)

  // Same reasoning as video: the token is only checked once the whole body
  // has arrived, so a slow upload starts with a fresh one.
  if (accessToken && Date.now() - accessTokenSetAt > FRESH_TOKEN_FOR_UPLOAD_MS) {
    const outcome = await refreshOnce()
    if (outcome === 'rejected') {
      sessionExpiredHandler?.()
      throw new ApiError('সেশন শেষ হয়ে গেছে, আবার লগইন করুন।', 401, 'nros_session_expired', 'session', path)
    }
  }

  const send = () => {
    const form = new FormData()
    form.append('nros_media', file)
    if (extra?.alt) form.append('alt', extra.alt)
    if (extra?.caption) form.append('caption', extra.caption)
    onProgress?.({ loaded: 0, total: file.size, percent: 0 })
    return xhrPost(path, form, onProgress, undefined, timeoutMs)
  }
  const sendWithRetry = async () => {
    try {
      return await send()
    } catch (err) {
      if (!(err instanceof ApiError) || (err.kind !== 'network' && err.kind !== 'timeout')) throw err
      await sleep(IMAGE_RETRY_DELAY_MS)
      return send()
    }
  }

  let res = await sendWithRetry()
  if (res.status === 401) {
    await renewAfterUnauthorized(path)
    res = await sendWithRetry()
  }
  if (res.status === 413) {
    let message = `ছবিটি সার্ভারের সীমার চেয়ে বড় (${formatBytes(file.size)})। ছোট ছবি দিন।`
    try {
      const data = JSON.parse(res.text) as { message?: string }
      if (data.message) message = data.message
    } catch {
      // not JSON — keep our own message
    }
    throw new ApiError(message, 413, 'nros_image_too_large', 'client', path)
  }
  return parseApiResponse<{ id: number; url: string; alt: string; caption: string }>(res.status, res.text, path)
}

// ---- Notifications (round 18) ----

export interface AppNotification {
  id: number
  type: string
  title: string
  body: string
  link: string
  object_id: number
  read: boolean
  created_at: string
}

/** Live counts for the sidebar badges (staff only; absent for a correspondent). */
export interface NotificationCounts {
  queue?: number
  videos?: number
  applications?: number
  withdrawals?: number
}

export interface NotificationsPoll {
  items: AppNotification[]
  unread: number
  latest_id: number
  counts?: NotificationCounts
}

export const NotificationsApi = {
  poll: (after?: number) => request<NotificationsPoll>(`/notifications${after ? `?after=${after}` : ''}`),
  markRead: (body: { ids: number[] } | { all: true }) =>
    request<{ ok: true; unread: number }>('/notifications/read', { method: 'POST', body, retry: true }),
}

// ---- Ledger / earnings ----

export const LedgerApi = {
  earnings: () => request<{ available_balance: number; entries: LedgerEntry[] }>('/me/earnings'),
  getMethod: () =>
    request<{ method: string | null; account_last4: string | null; configured: boolean }>(
      '/me/withdraw-method'
    ),
  saveMethod: (method: string, account_number: string) =>
    request<{ ok: true }>('/me/withdraw-method', { method: 'POST', body: { method, account_number } }),
  listWithdrawRequests: () => request<WithdrawRequest[]>('/me/withdraw-requests'),
  requestWithdraw: (amount: number) =>
    request<{ ok: true; id: number }>('/me/withdraw-requests', { method: 'POST', body: { amount } }),
}

// ---- Profile picture ----

export const AvatarApi = {
  // Multipart field name is fixed on the backend: "nros_avatar".
  upload: (file: File) => {
    const form = new FormData()
    form.append('nros_avatar', file)
    return request<{ ok: true; avatar: string }>('/me/avatar', { method: 'POST', body: form })
  },
  // Reverts to the default (Gravatar) picture. Safe to call even if no
  // custom picture was ever uploaded — the backend just no-ops.
  remove: () => request<{ ok: true; avatar: string }>('/me/avatar', { method: 'DELETE' }),
}

// ---- Advertiser/ad-client leads (round 7 — correspondent self-entry) ----

export type DealingStatus = 'interested' | 'final'

export interface AdLead {
  id: number
  business_name: string
  contact_name: string
  phone: string
  whatsapp: string
  address: string
  visiting_card: string | null
  dealing_status: DealingStatus | null
  status: 'active' | 'inactive'
  created_at: string
  updated_at: string
}

export interface AdLeadInput {
  business_name: string
  contact_name?: string
  phone?: string
  whatsapp?: string
  address?: string
  dealing_status?: DealingStatus | ''
}

export const AdLeadsApi = {
  list: () => request<AdLead[]>('/me/ad-clients'),
  create: (data: AdLeadInput) => request<AdLead>('/me/ad-clients', { method: 'POST', body: data as unknown as Record<string, unknown> }),
  update: (id: number, data: Partial<AdLeadInput>) =>
    request<AdLead>(`/me/ad-clients/${id}`, { method: 'PATCH', body: data as unknown as Record<string, unknown> }),
  // Multipart field name is fixed on the backend: "nros_visiting_card".
  uploadCard: (id: number, file: File) => {
    const form = new FormData()
    form.append('nros_visiting_card', file)
    return request<{ ok: true; visiting_card: string }>(`/me/ad-clients/${id}/visiting-card`, { method: 'POST', body: form })
  },
  removeCard: (id: number) => request<{ ok: true }>(`/me/ad-clients/${id}/visiting-card`, { method: 'DELETE' }),
}

// ---- Analytics ----

export type AnalyticsRange = 'today' | '3' | '7' | '15' | '30' | 'all'

export const AnalyticsApi = {
  me: (range: AnalyticsRange = '7') =>
    request<{ totals: AnalyticsTotals; trend: AnalyticsTrendPoint[]; range: AnalyticsRange }>(
      `/me/analytics?range=${range}`
    ),
}
