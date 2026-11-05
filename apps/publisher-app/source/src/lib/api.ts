import { compressImageForUpload } from './imageCompress'

/**
 * API client for the newsroom-os plugin's REST API (nros/v1) — the same
 * backend as the correspondent app, but its OWN session: the backend gives
 * this app its own refresh-cookie name (nros_refresh_staff, chosen from the
 * Origin header) and every login is its own per-device server-side session,
 * so a correspondent session in the same browser, or this account on another
 * device, can never disturb it.
 *
 * Auth model: a short-lived (15 min) access token kept in memory plus a
 * rotating refresh token in an httpOnly cookie. Every request automatically
 * retries once after a silent refresh on a 401; network problems never log
 * anyone out (see "network resilience" below).
 *
 * Round 8: setAccessToken() also schedules a *proactive* refresh a couple
 * of minutes before the access token's own 15-minute lifetime runs out, so
 * a dashboard tab left open renews its session quietly in the background
 * instead of only reacting after a request has already failed. If a
 * refresh — proactive or reactive — ever comes back definitively
 * rejected, setSessionExpiredHandler()'s callback runs so the app can drop
 * back to a clean login screen instead of leaving stale, increasingly-
 * failing state on screen (this was the "ড্যাশবোর্ড ব্ল্যাংক হয়ে যায়"
 * complaint).
 */

export const API_BASE = import.meta.env.VITE_API_BASE || 'https://meeha.news/wp-json/nros/v1'

// Mirrors the backend's NROS_Auth::ACCESS_TTL (900s).
const PROACTIVE_REFRESH_MS = 13 * 60 * 1000
// Spreads tabs' background refreshes out over a minute. Since round 9
// follow-up 3 the backend tolerates simultaneous tab refreshes by design
// (rotation at most once a minute, previous id still accepted), so this is
// now just a cheap extra, not the thing preventing the race.
const PROACTIVE_REFRESH_JITTER_MS = 60 * 1000

let accessToken: string | null = null
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
  timeoutMs?: number // overrides the default timeout (a slow server-side step)
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
  const timeoutMs = opts.timeoutMs ?? (body instanceof FormData ? UPLOAD_TIMEOUT_MS : REQUEST_TIMEOUT_MS)
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
    const outcome = await refreshOnce()
    if (outcome === 'ok') {
      headers['Authorization'] = `Bearer ${accessToken}`
      res = await doFetch()
    } else if (outcome === 'rejected') {
      // The session is really gone (logged out elsewhere, expired, evicted)
      // — drop back to a clean login screen instead of failing forever.
      sessionExpiredHandler?.()
      throw new ApiError('সেশন শেষ হয়ে গেছে, আবার লগইন করুন।', 401, 'nros_session_expired', 'session', path)
    } else {
      // Couldn't reach the server to renew — NOT a logout.
      throw new ApiError('সার্ভারের সাথে সংযোগ করা যায়নি।', 0, 'nros_refresh_unavailable', 'network', path)
    }
  }

  let text: string
  try {
    text = await res.text()
  } catch (err) {
    throw connectionError(err, path)
  }

  return parseReply<T>(res.status, text, path)
}

// Shared by request() and uploadWithProgress(): turns a raw reply into the
// parsed JSON body, or the same ApiError shapes for a non-JSON / non-2xx reply.
function parseReply<T>(status: number, text: string, path: string): T {
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
    throw new ApiError(data.message || fallback, status, data.code, status >= 500 ? 'server' : 'client', path)
  }

  return data as T
}

/** GET a private file with the access token (one silent refresh on a 401). */
async function requestBlob(path: string): Promise<Blob> {
  const get = () =>
    fetchWithTimeout(
      `${API_BASE}${path}`,
      { headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : {}, credentials: 'include' },
      UPLOAD_TIMEOUT_MS,
    ).catch((err) => {
      throw connectionError(err, path)
    })
  let res = await get()
  if (res.status === 401 && (await refreshOnce()) === 'ok') res = await get()
  if (!res.ok) {
    const text = await res.text().catch(() => '')
    return parseReply<never>(res.status, text, path)
  }
  return res.blob()
}

// ---- Round 18: notifications ----

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

/** Live counts for the sidebar badges. `withdrawals` only for finance-visible staff. */
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

// ---- Round 10: uploads with real progress ----
//
// fetch() can't report upload progress, so large files (videos) go through
// XMLHttpRequest instead — but with the SAME auth behaviour as request():
// the in-memory access token, one silent refresh + retry on a 401, a
// definitively rejected session → sessionExpiredHandler, and the same
// ApiError shapes. Instead of a fixed timeout (a 150 MB video on mobile data
// legitimately takes minutes) the upload is abandoned only when NOTHING has
// moved for UPLOAD_STALL_MS. Never retried automatically (a POST).

const UPLOAD_STALL_MS = 90_000

export interface UploadProgress {
  loaded: number
  total: number
  /** 0–100 */
  percent: number
}

export const UPLOAD_ABORTED_CODE = 'nros_upload_aborted'

export function isAbortError(err: unknown) {
  return err instanceof ApiError && err.code === UPLOAD_ABORTED_CODE
}

function xhrOnce(
  path: string,
  form: FormData | Blob,
  onProgress?: (p: UploadProgress) => void,
  signal?: AbortSignal,
): Promise<{ status: number; text: string }> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(new ApiError('আপলোড বাতিল করা হয়েছে।', 0, UPLOAD_ABORTED_CODE, 'client', path))
      return
    }
    const xhr = new XMLHttpRequest()
    let stallTimer: ReturnType<typeof setTimeout> | null = null
    let stalled = false
    const armStall = () => {
      if (stallTimer) clearTimeout(stallTimer)
      stallTimer = setTimeout(() => {
        stalled = true
        xhr.abort()
      }, UPLOAD_STALL_MS)
    }
    const cleanup = () => {
      if (stallTimer) clearTimeout(stallTimer)
      signal?.removeEventListener('abort', onAbort)
    }
    const onAbort = () => xhr.abort()

    xhr.open('POST', `${API_BASE}${path}`)
    xhr.withCredentials = true
    xhr.setRequestHeader('Cache-Control', 'no-cache')
    if (accessToken) xhr.setRequestHeader('Authorization', `Bearer ${accessToken}`)
    if (form instanceof Blob) xhr.setRequestHeader('Content-Type', 'application/octet-stream')

    xhr.upload.onprogress = (e) => {
      armStall()
      if (e.lengthComputable && onProgress) {
        onProgress({ loaded: e.loaded, total: e.total, percent: Math.min(100, Math.round((e.loaded / e.total) * 100)) })
      }
    }
    xhr.onprogress = armStall
    xhr.onload = () => {
      cleanup()
      resolve({ status: xhr.status, text: xhr.responseText })
    }
    xhr.onerror = () => {
      cleanup()
      reject(connectionError(new Error('xhr error'), path))
    }
    xhr.onabort = () => {
      cleanup()
      reject(
        stalled
          ? new ApiError('আপলোড অনেকক্ষণ আটকে ছিল — সংযোগ পরীক্ষা করে আবার চেষ্টা করুন।', 0, 'nros_timeout', 'timeout', path)
          : new ApiError('আপলোড বাতিল করা হয়েছে।', 0, UPLOAD_ABORTED_CODE, 'client', path),
      )
    }
    signal?.addEventListener('abort', onAbort)
    armStall()
    xhr.send(form)
  })
}

export async function uploadWithProgress<T>(
  path: string,
  form: FormData | Blob,
  opts: { onProgress?: (p: UploadProgress) => void; signal?: AbortSignal } = {},
): Promise<T> {
  let reply = await xhrOnce(path, form, opts.onProgress, opts.signal)
  if (reply.status === 401) {
    const outcome = await refreshOnce()
    if (outcome === 'ok') {
      opts.onProgress?.({ loaded: 0, total: 0, percent: 0 })
      reply = await xhrOnce(path, form, opts.onProgress, opts.signal)
    } else if (outcome === 'rejected') {
      sessionExpiredHandler?.()
      throw new ApiError('সেশন শেষ হয়ে গেছে, আবার লগইন করুন।', 401, 'nros_session_expired', 'session', path)
    } else {
      throw new ApiError('সার্ভারের সাথে সংযোগ করা যায়নি।', 0, 'nros_refresh_unavailable', 'network', path)
    }
  }
  return parseReply<T>(reply.status, reply.text, path)
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

export type Role = 'administrator' | 'meeha_publisher' | 'editor' | 'correspondent' | string

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
  role: Role
  roles: Role[]
  can: {
    publisher: boolean
    manage_staff: boolean
    manage_options: boolean
    // Round 10 — effective post/video management rights for THIS user
    // (Publisher/Administrator always; Editor per Settings → এডিটরের অনুমতি).
    // Optional only so an older backend that omits them reads as "not allowed".
    delete_posts?: boolean
    change_post_date?: boolean
    change_post_author?: boolean
  }
}

// Round 10 — what the Publisher lets Editors do (Settings → এডিটরের অনুমতি).
export interface EditorPermissions {
  delete_posts: boolean
  change_date: boolean
  change_author: boolean
}

export const DEFAULT_EDITOR_PERMISSIONS: EditorPermissions = {
  delete_posts: false,
  change_date: true,
  change_author: true,
}

// Round 10 — GET /admin/authors row (author picker).
export interface AuthorOption {
  id: number
  name: string
  role: Role
  avatar: string
}

export interface QueueItem {
  id: number
  title: string
  excerpt: string
  featured_image: string | null
  category_id: number
  author: { id: number; name: string } | null
  created_at: string
}

export interface Application {
  id: number
  full_name: string
  email: string
  phone: string
  division: string
  district: string
  upazila: string
  status: 'pending' | 'approved' | 'rejected'
  reject_reason: string | null
  created_at: string
  // Round 18 (plugin 1.3.0+): which KYC files were sent ('nid', 'cv', 'certificate').
  files?: string[]
}

export interface Correspondent {
  id: number
  name: string
  email: string
  phone: string
  division: string
  suspensions: string[]
  // Round 7 — only present when the viewer has finance visibility (same
  // gate as every other money figure in this API).
  default_post_rate?: number | null
  // Round 12 — standing per-VIDEO rate (same visibility/permission as the post rate).
  default_video_payment?: number | null
}

export interface CorrespondentDetail extends Correspondent {
  district: string
  upazila: string
  post_counts: { draft: number; pending: number; publish: number }
  available_balance?: number
  monthly_salary?: number | null
  // Round 7 — a standing per-post rate (contract-based average), applied
  // automatically (as a pending payment awaiting confirmation) whenever
  // this correspondent's article is published and no explicit per-post
  // amount already exists. Present only alongside available_balance (both
  // gated by the same finance-visibility check on the backend).
  default_post_rate?: number | null
  default_video_payment?: number | null
}

export interface WithdrawRequest {
  id: number
  user_id: number
  display_name: string
  user_email: string
  amount: string
  method: string
  status: 'pending' | 'paid' | 'rejected'
  admin_note: string | null
  created_at: string
  updated_at: string
}

export type DealingStatus = 'interested' | 'final'

export interface AdClient {
  id: number
  business_name: string
  contact_name: string
  phone: string
  // Round 7 — these may be entered by the correspondent themselves (from
  // their own app's Profile page) rather than by staff.
  whatsapp?: string
  address?: string
  visiting_card?: string | null
  dealing_status?: DealingStatus | null
  referred_by: number | null
  status: 'active' | 'inactive'
  notes: string
  created_at: string
}

export interface InvestigationCase {
  id: number
  correspondent_id: number
  correspondent_name: string | null
  client_id: number | null
  client_name: string | null
  status: 'open' | 'no_violation' | 'violation_confirmed'
  summary: string
  evidence_notes: string | null
  resolution_notes: string | null
  created_at: string
  resolved_at: string | null
}

export type SuspensionType = 'client_commission' | 'all_commission' | 'news_submission' | 'account'
export type SuspensionDuration = '1m' | '3m' | '6m' | 'lifetime'

export interface Suspension {
  id: number
  correspondent_id: number
  correspondent_name: string | null
  case_id: number | null
  type: SuspensionType
  client_id: number | null
  duration: SuspensionDuration
  starts_at: string
  ends_at: string | null
  reason: string
  status: 'active' | 'lifted' | 'expired'
  appeal_status: 'pending' | 'upheld' | 'overturned' | null
  appeal_notes: string | null
  created_at: string
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

export interface StaffUser {
  id: number
  name: string
  email: string
  roles: Role[]
}

export interface Overview {
  correspondents: number
  pending_applications: number
  pending_queue: number
  pending_withdrawals: number
  open_cases: number
  active_suspensions: number
  active_clients: number
  finance_visible: boolean
  pending_withdrawal_amount?: number
  // Round 10 — correspondent videos awaiting review (optional for an older backend).
  pending_videos?: number
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

export type AnalyticsRange = 'today' | '3' | '7' | '15' | '30' | 'all'

export interface ViewTier {
  views: number
  amount: number
}

export interface Settings {
  editor_finance_visible: boolean
  seo_title: string
  seo_description: string
  view_tiers: ViewTier[]
  // Round 10 — writable by the Publisher only.
  editor_permissions: EditorPermissions
}

// Round 9 — the unified prompt-management system (প্রোম্পট menu), replacing
// round 8's single summary template + staff template list.
export type PromptType = 'news' | 'library'
export type PromptRole = 'correspondent' | 'editor' | 'publisher'
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
  visible_roles: PromptRole[]
  // Only meaningful for type === 'library' — the custom input fields this
  // prompt needs filled in before copying (e.g. a rewrite prompt's original
  // URL/title + body). News-type prompts always substitute the fixed
  // {title}/{body}/{summary}/{category} shortcodes instead.
  fields: PromptField[]
  sort_order: number
  created_at: string
  updated_at: string
}

export interface PromptInput {
  type?: PromptType
  label?: string
  body?: string
  visible_roles?: PromptRole[]
  fields?: PromptField[]
  sort_order?: number
}

export interface Seo {
  title: string
  description: string
  focus_keyword: string
}

export interface AdminPost {
  id: number
  title: string
  content: string
  excerpt: string
  // The theme's own "এ.আই সারাংশ" field (_meeha_summary postmeta) — see
  // the matching field in the correspondent app's api.ts for the full
  // explanation. Round 8.
  ai_summary: string
  // 'future' = scheduled: published with a future date (round 10).
  status: 'draft' | 'pending' | 'publish' | 'future'
  slug: string
  category_id: number
  featured_image: string | null
  featured_image_id: number | null
  featured_image_alt: string
  featured_image_caption: string
  seo: Seo
  payment: number | null
  // 'pending' = auto-applied from the correspondent's default rate, not
  // yet confirmed (not in their balance yet); 'approved' = set directly or
  // confirmed; null alongside a null payment.
  payment_status?: 'pending' | 'approved' | null
  author: { id: number; name: string } | null
  // Round 10 — post_date ('YYYY-MM-DD HH:MM:SS', site time). Optional only
  // for an older backend; created_at carries the same value there.
  date?: string
  // Round 13 — true for a draft/pending post whose publish date is not set
  // yet: `date` is then only the last save time and publishing stamps "now".
  date_floating?: boolean
  // Round 13 — "অতিথি লেখক": shown on the site instead of the WP author.
  guest_author?: GuestAuthor | null
  created_at: string
  updated_at: string
  permalink: string | null
  // Round 12 — the theme's "মিহা সম্পাদকীয়" fields (optional only so an
  // older backend that omits them still loads).
  tags?: string[]
  area?: PostArea
  topic?: string
  flags?: PostFlags
  glance_text?: string
  lit?: Record<string, string> | null
  lit_cover_url?: string | null
  lit_genre_effective?: string
  is_literature?: boolean
  // Unix seconds — the theme shows a trending pin for 24 h after trending_at.
  flag_times?: { breaking_at: number | null; trending_at: number | null }
  // Plugin 1.12.0 — দেশচিত্র counts typed on this news ({} = none).
  dc?: Record<string, number>
}

// ---- Round 12: editorial fields ----

export interface PostArea {
  division: string
  district: string
  upazila: string
}

export interface PostFlags {
  breaking: boolean
  trending: boolean
  investigation: boolean
  glance: boolean
}

export const EMPTY_AREA: PostArea = { division: '', district: '', upazila: '' }
export const EMPTY_FLAGS: PostFlags = { breaking: false, trending: false, investigation: false, glance: false }

export interface TagOption {
  id: number
  name: string
  count: number
}

export type LitFieldType = 'text' | 'textarea' | 'url' | 'number' | 'checkbox' | 'rating' | 'image' | string

export interface LitSchema {
  genres: { id: string; label: string; category_id?: number | null; category_ids?: number[] }[]
  fields: { key: string; label: string; type: LitFieldType; genres: string[] }[]
  // Category ids of the whole বাঙালি সাহিত্য-সংস্কৃতি tree, when the backend sends them.
  category_ids?: number[]
}

export interface AreasResponse {
  divisions: { name: string; districts: string[] }[]
  upazilas: Record<string, string[]>
  source: string
}

// Defensive reshaping (same as the correspondent app): a backend regression
// degrades to empty dropdowns instead of a crash.
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
    r.upazilas && typeof r.upazilas === 'object' && !Array.isArray(r.upazilas) ? (r.upazilas as Record<string, string[]>) : {}
  return { divisions, upazilas, source: typeof r.source === 'string' ? r.source : 'unavailable' }
}

let areasCache: Promise<AreasResponse> | null = null
let litSchemaCache: Promise<LitSchema> | null = null

export const EditorialApi = {
  searchTags: (search: string) =>
    request<TagOption[]>(`/admin/tags${search.trim() ? `?search=${encodeURIComponent(search.trim())}` : ''}`),
  areas: () => {
    if (!areasCache) {
      areasCache = request<unknown>('/areas', { auth: false })
        .then(normalizeAreas)
        .catch((e) => {
          areasCache = null
          throw e
        })
    }
    return areasCache
  },
  litSchema: () => {
    if (!litSchemaCache) {
      litSchemaCache = request<LitSchema>('/admin/lit-schema')
        .then((s) => ({
          genres: Array.isArray(s?.genres) ? s.genres : [],
          fields: Array.isArray(s?.fields) ? s.fields.map((f) => ({ ...f, genres: Array.isArray(f.genres) ? f.genres : [] })) : [],
          category_ids: Array.isArray(s?.category_ids) ? s.category_ids : undefined,
        }))
        .catch((e) => {
          litSchemaCache = null
          throw e
        })
    }
    return litSchemaCache
  },
}

// Round 7 — the lighter row shape GET /admin/posts/all returns, for the
// news-list screen (potentially hundreds of rows at once).
export interface AdminPostListRow {
  id: number
  title: string
  status: 'draft' | 'pending' | 'publish' | 'future'
  rejected: boolean
  author: { id: number; name: string } | null
  views: number
  payment: number | null
  payment_status: 'pending' | 'approved' | null
  date?: string // round 10, if the backend sends it; created_at is post_date too
  date_floating?: boolean // round 13
  guest_author?: GuestAuthor | null // round 13
  created_at: string
  updated_at: string
  permalink: string | null
}

// ---- Round 13: guest authors (অতিথি লেখক) ----

export interface GuestAuthor {
  name: string
  title: string
  photo_id: number
  photo_url: string | null
}

export interface GuestAuthorInput {
  name: string
  title: string
  photo_id: number
}

export interface SavedGuestAuthor extends GuestAuthor {
  id: string
}

export const GUEST_NAME_MAX = 80
export const GUEST_TITLE_MAX = 120

export const GuestAuthorsApi = {
  search: (search = '') =>
    request<SavedGuestAuthor[]>(`/admin/guest-authors${search.trim() ? `?search=${encodeURIComponent(search.trim())}` : ''}`),
  save: (data: GuestAuthorInput) =>
    request<SavedGuestAuthor>('/admin/guest-authors', { method: 'POST', body: data as unknown as Record<string, unknown> }),
  remove: (id: string) => request<{ ok: true }>(`/admin/guest-authors/${encodeURIComponent(id)}`, { method: 'DELETE' }),
}

export type PostListFilter = 'all' | 'publish' | 'future' | 'pending' | 'rejected' | 'draft'

export interface RevisionEntry {
  id: number
  author: string | null
  date: string
  title_diff: string
  excerpt_diff: string
  content_diff: string
}

// ---- Auth ----

export interface LoginSuccess {
  access_token: string
  expires_in: number
  user: Profile
}

// Round 12 — the theme requires a one-time code for this account: no tokens
// yet, the code was sent (email/SMS) and POST /auth/login/otp finishes the login.
export interface OtpChallenge {
  otp_required: true
  otp_token: string
  method: 'email' | 'sms'
  destination: string
  resend_after: number
  expires_in?: number
  message?: string
}

// Error codes after which the OTP step can't continue (back to the password step).
export const OTP_RESTART_CODES = new Set([
  'nros_otp_expired',
  'nros_otp_locked',
  'nros_otp_rate',
  'nros_otp_resend_limit',
  'nros_invalid_credentials',
  'nros_suspended',
])

export const AuthApi = {
  login: (identifier: string, password: string) =>
    request<LoginSuccess | OtpChallenge>('/auth/login', {
      method: 'POST',
      body: { identifier, password, app: 'publisher' },
      auth: false,
    }),
  loginOtp: (otp_token: string, code: string) =>
    request<LoginSuccess>('/auth/login/otp', {
      method: 'POST',
      body: { otp_token, code, app: 'publisher' },
      auth: false,
    }),
  resendOtp: (otp_token: string) =>
    request<Omit<OtpChallenge, 'otp_required'> & { ok: true }>('/auth/login/otp/resend', {
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

// ---- Overview / analytics / finance ----

export const OverviewApi = {
  get: () => request<Overview>('/admin/overview'),
}

export const AnalyticsApi = {
  site: (range: AnalyticsRange = '7') =>
    request<{ totals: AnalyticsTotals; trend: AnalyticsTrendPoint[]; range: AnalyticsRange }>(
      `/admin/analytics?range=${range}`
    ),
}

// ---- Round 20: smart analytics (newsroom-os 1.5.0) ----

export type InsightPeriod = 'today' | 'yesterday' | '7d' | 'this_month' | 'last_month' | 'all'

export interface InsightCard {
  key: InsightPeriod
  label: string
  published: { news: number; video: number; total: number }
  views: number
  prev_published?: number
  prev_views?: number
}

export interface InsightKpi {
  period: InsightPeriod
  label: string
  views: number
  shares: number
  reactions: number
  comments: number
  published: number
  avg_views_per_news: number
  prev_views?: number
  prev_shares?: number
  prev_reactions?: number
  prev_comments?: number
  prev_published?: number
}

export interface InsightDay {
  stat_date: string
  views: number
  reactions: number
  shares: number
  comments: number
  published: number
}

export interface InsightTopPost {
  id: number
  title: string
  url: string
  type: string
  views: number
  lifetime: number
  shares: number
  author: string
  category: string
  date: string
  thumb: string
}

export interface InsightHealth {
  theme_metrics: boolean
  live: boolean
  cron_lag: number
  cron_disabled: boolean
  lists_last_run: number
  rescued_at: number
}

export interface Insights {
  generated_at: number
  timezone: string
  cards: InsightCard[]
  kpi: InsightKpi
  trend: InsightDay[]
  hourly: { today: number[]; yesterday: number[]; hour: number }
  top_posts: InsightTopPost[]
  categories: Array<{ id: number; name: string; published: number; views: number }>
  sources: Record<string, number>
  health: InsightHealth
}

export interface Realtime {
  supported: boolean
  enabled: boolean
  window: number
  now: number
  last30: number
  today: number
  views_this_hour: number
  devices: Record<string, number>
  sources: Record<string, number>
  pages: Array<{ id: number; title: string; url: string; n: number }>
  time: number
}

export type ReporterFilter = 'all' | 'active' | 'zero'

export interface ReporterRow {
  id: number
  name: string
  avatar: string
  role: string
  role_label: string
  division: string
  published: number
  videos: number
  pending: number
  views: number
  total: number
  last_published: string | null
}

export interface ReportersReport {
  period: { key: string; label: string; start: string; end: string }
  filter: ReporterFilter
  summary: { reporters: number; active: number; zero: number; published: number; videos: number; pending: number }
  items: ReporterRow[]
}

export const InsightsApi = {
  get: (period: InsightPeriod, range: 7 | 30 | 90) => request<Insights>(`/admin/insights?period=${period}&range=${range}`),
  realtime: () => request<Realtime>('/admin/realtime'),
  reporters: (period: InsightPeriod | 'date', filter: ReporterFilter, date?: string) =>
    request<ReportersReport>(`/admin/reporters?period=${period}&filter=${filter}${date ? `&date=${date}` : ''}`),
}

export interface FinanceSummary {
  by_type: Array<{ type: string; total: string; type_label?: string }>
  // Round 12 — pending (unconfirmed) post/video payments.
  pending_by_type?: Array<{ type: string; total: string; type_label?: string; count: number }>
  paid_out: number
  pending_withdraw: number
}

export const FinanceApi = {
  summary: () => request<FinanceSummary>('/admin/finance-summary'),
}

// ---- Editorial queue ----

export const QueueApi = {
  list: () => request<QueueItem[]>('/admin/queue'),
  approve: (id: number) => request<{ ok: true }>(`/admin/queue/${id}/approve`, { method: 'POST' }),
  reject: (id: number, reason: string) =>
    request<{ ok: true }>(`/admin/queue/${id}/reject`, { method: 'POST', body: { reason } }),
}

// ---- Applications (KYC onboarding) ----

export const ApplicationsApi = {
  list: (status?: string) => request<Application[]>(`/applications${status ? `?status=${status}` : ''}`),
  // Round 18: the KYC files are private (staff-only, bearer token), so they
  // are fetched with the token and opened from a blob URL.
  file: (id: number, field: string) => requestBlob(`/applications/${id}/files/${field}`),
  approve: (id: number) => request<{ ok: true; user_id: number }>(`/applications/${id}/approve`, { method: 'POST' }),
  reject: (id: number, reason: string) =>
    request<{ ok: true }>(`/applications/${id}/reject`, { method: 'POST', body: { reason } }),
}

// ---- Correspondents ----

export const CorrespondentsApi = {
  list: () => request<Correspondent[]>('/admin/correspondents'),
  detail: (id: number) => request<CorrespondentDetail>(`/admin/correspondents/${id}`),
  // Publisher-only: switches a correspondent's WP role to "editor" in place
  // (same user id, no separate Editor subdomain/app — see NROS_Admin_API's
  // docblock). They then sign in here at publisher.meeha.news instead of
  // at correspondent.meeha.news.
  promoteEditor: (id: number) => request<{ ok: true }>(`/admin/correspondents/${id}/promote-editor`, { method: 'POST' }),
  // Publisher-only: sets (amount > 0) or clears (amount <= 0 / omitted) a
  // correspondent's optional fixed monthly salary. Takes effect from the
  // next payroll run onward.
  setSalary: (id: number, amount: number) =>
    request<{ ok: true; monthly_salary: number | null }>(`/admin/correspondents/${id}/salary`, {
      method: 'POST',
      body: { amount },
    }),
  // Round 7: sets (amount > 0) or clears (amount <= 0 / omitted) a
  // correspondent's standing per-post rate — applied automatically (as a
  // pending payment) the next time one of their articles publishes with no
  // explicit per-post amount already set.
  setDefaultRate: (id: number, amount: number) =>
    request<{ ok: true; default_post_rate: number | null }>(`/admin/correspondents/${id}/default-rate`, {
      method: 'POST',
      body: { amount },
    }),
  // Round 12: the same, for this correspondent's uploaded video news.
  setDefaultVideoRate: (id: number, amount: number) =>
    request<{ ok: true; default_video_payment: number | null }>(`/admin/correspondents/${id}/default-video-rate`, {
      method: 'POST',
      body: { amount },
    }),
}

// ---- Withdrawals / ledger (finance-gated on the backend) ----

export const WithdrawalsApi = {
  list: (status?: string) => request<WithdrawRequest[]>(`/admin/withdraw-requests${status ? `?status=${status}` : ''}`),
  markPaid: (id: number, note: string) =>
    request<{ ok: true }>(`/admin/withdraw-requests/${id}/mark-paid`, { method: 'POST', body: { note } }),
  addLedgerEntry: (user_id: number, type: string, amount: number, note: string) =>
    request<{ ok: true; id: number }>('/admin/ledger', { method: 'POST', body: { user_id, type, amount, note } }),
}

// ---- Ad clients ----

export const AdClientsApi = {
  list: () => request<AdClient[]>('/admin/ad-clients'),
  create: (data: {
    business_name: string
    contact_name?: string
    phone?: string
    whatsapp?: string
    address?: string
    referred_by?: number
    notes?: string
  }) => request<{ ok: true; id: number }>('/admin/ad-clients', { method: 'POST', body: data }),
  update: (id: number, data: Partial<Pick<AdClient, 'status' | 'notes'>>) =>
    request<{ ok: true }>(`/admin/ad-clients/${id}`, { method: 'PATCH', body: data as Record<string, unknown> }),
}

// ---- Investigations ----

export const InvestigationsApi = {
  list: (status?: string) => request<InvestigationCase[]>(`/admin/investigations${status ? `?status=${status}` : ''}`),
  open: (data: { correspondent_id: number; client_id?: number; summary: string }) =>
    request<{ ok: true; id: number }>('/admin/investigations', { method: 'POST', body: data }),
  resolve: (id: number, status: 'no_violation' | 'violation_confirmed', evidence_notes: string, resolution_notes: string) =>
    request<{ ok: true }>(`/admin/investigations/${id}/resolve`, {
      method: 'POST',
      body: { status, evidence_notes, resolution_notes },
    }),
}

// ---- Suspensions ----

export const SuspensionsApi = {
  list: (correspondent_id?: number) =>
    request<Suspension[]>(`/admin/suspensions${correspondent_id ? `?correspondent_id=${correspondent_id}` : ''}`),
  issue: (data: {
    correspondent_id: number
    type: SuspensionType
    duration: SuspensionDuration
    client_id?: number
    case_id?: number
    reason: string
  }) => request<{ ok: true; id: number }>('/admin/suspensions', { method: 'POST', body: data }),
  lift: (id: number) => request<{ ok: true }>(`/admin/suspensions/${id}/lift`, { method: 'POST' }),
  reviewAppeal: (id: number, verdict: 'upheld' | 'overturned') =>
    request<{ ok: true }>(`/admin/suspensions/${id}/review-appeal`, { method: 'POST', body: { verdict } }),
}

// ---- Users & roles (Publisher only) ----

export const UsersApi = {
  list: () => request<StaffUser[]>('/admin/users'),
  create: (data: { name: string; email: string; role: 'editor' | 'meeha_publisher' }) =>
    request<{ ok: true; user_id: number }>('/admin/users', { method: 'POST', body: data }),
  setRole: (id: number, role: 'editor' | 'meeha_publisher') =>
    request<{ ok: true }>(`/admin/users/${id}/role`, { method: 'POST', body: { role } }),
}

// ---- Categories (real WP taxonomy, for the post editor's category picker) ----

export const CategoriesApi = {
  list: () => request<Category[]>('/categories'),
}

// ---- Settings ----

export const SettingsApi = {
  get: () => request<Settings>('/admin/settings'),
  update: (data: Partial<Settings>) => request<{ ok: true }>('/admin/settings', { method: 'POST', body: data as Record<string, unknown> }),
}

// ---- Prompts (প্রোম্পট) ----
//
// GET /admin/prompts — every prompt, any staff role (Publisher manages;
// Editor views + copies only — enforced in the UI, since the backend's
// require_staff already allows both to read this).
// GET /prompts — role-filtered (used by PostEditor's title-adjacent
// dropdown and by the correspondent app), kept here too since the
// publisher-app's own editor also calls it.
export const PromptsApi = {
  listAll: (type?: PromptType) => request<Prompt[]>(`/admin/prompts${type ? `?type=${type}` : ''}`),
  listForViewer: (type?: PromptType) => request<Prompt[]>(`/prompts${type ? `?type=${type}` : ''}`),
  create: (data: PromptInput) => request<{ ok: true; id: number }>('/admin/prompts', { method: 'POST', body: data as Record<string, unknown> }),
  update: (id: number, data: PromptInput) =>
    request<{ ok: true }>(`/admin/prompts/${id}`, { method: 'PATCH', body: data as Record<string, unknown> }),
  remove: (id: number) => request<{ ok: true }>(`/admin/prompts/${id}`, { method: 'DELETE' }),
}

// ---- Round 19: উক্তি (the theme's quotes, post type meeha_photocard) ----
//
// GET/POST /admin/quotes, GET/PATCH/DELETE /admin/quotes/{id} (staff; delete
// needs the same delete_posts right as articles), GET/POST /admin/quote-types.
export type QuoteFrame = 'politics' | 'faith' | 'literature' | 'inspire' | 'history' | 'sports' | 'opinion'
export type QuoteStatus = 'publish' | 'draft'

export interface QuotePoll {
  enabled: boolean
  option_a: string
  option_b: string
  days: number
  total?: number | null
}

export interface Quote {
  id: number
  text: string
  speaker: string
  role: string
  source: string
  source_year: string
  year: string
  context: string
  type_id: number
  type_name: string
  frame: QuoteFrame
  photo_id: number
  photo_url: string
  status: string
  date: string
  modified: string
  author: string
  url: string
  poll: QuotePoll
}

export interface QuoteInput {
  text: string
  speaker: string
  role: string
  source: string
  source_year: string
  year: string
  context: string
  type_id: number
  photo_id: number
  status: QuoteStatus
  poll: QuotePoll
}

export interface QuoteType {
  id: number
  name: string
  frame: QuoteFrame
  count: number
}

export interface QuoteFrameInfo {
  id: QuoteFrame
  label: string
  swatch: string
}

export const QUOTE_TEXT_MAX = 600

export const QuotesApi = {
  list: (params: { search?: string; type?: number; status?: '' | QuoteStatus; page?: number }) => {
    const q = new URLSearchParams()
    if (params.search) q.set('search', params.search)
    if (params.type) q.set('type', String(params.type))
    if (params.status) q.set('status', params.status)
    if (params.page && params.page > 1) q.set('page', String(params.page))
    const qs = q.toString()
    return request<{ items: Quote[]; total: number; pages: number }>(`/admin/quotes${qs ? `?${qs}` : ''}`)
  },
  get: (id: number) => request<Quote>(`/admin/quotes/${id}`),
  create: (data: QuoteInput) => request<Quote>('/admin/quotes', { method: 'POST', body: data as unknown as Record<string, unknown> }),
  update: (id: number, data: QuoteInput) =>
    request<Quote>(`/admin/quotes/${id}`, { method: 'PATCH', body: data as unknown as Record<string, unknown> }),
  remove: (id: number) => request<{ ok: true }>(`/admin/quotes/${id}`, { method: 'DELETE' }),
  types: () => request<{ types: QuoteType[]; frames: QuoteFrameInfo[] }>('/admin/quote-types'),
  createType: (name: string, frame: QuoteFrame) =>
    request<QuoteType>('/admin/quote-types', { method: 'POST', body: { name, frame } }),
}

// ---- Media (image upload + alt/caption) ----

export const MediaApi = {
  // Round 18: the photo is shrunk in the browser first (a 3–12 MB phone
  // photo on slow data used to end in "সার্ভারের সাথে সংযোগ করা যায়নি"), then
  // sent with real progress and a stall timeout instead of a fixed 120 s
  // one, and a dropped connection is retried once automatically.
  upload: async (file: File, extra?: { alt?: string; caption?: string }, onProgress?: (p: UploadProgress) => void) => {
    const small = await compressImageForUpload(file)
    const form = new FormData()
    form.append('nros_media', small)
    if (extra?.alt) form.append('alt', extra.alt)
    if (extra?.caption) form.append('caption', extra.caption)
    type Uploaded = { id: number; url: string; alt: string; caption: string }
    try {
      return await uploadWithProgress<Uploaded>('/media', form, { onProgress })
    } catch (err) {
      if (!(err instanceof ApiError) || (err.kind !== 'network' && err.kind !== 'timeout')) throw err
      await sleep(2500)
      return uploadWithProgress<Uploaded>('/media', form, { onProgress })
    }
  },
  updateMeta: (id: number, data: { alt?: string; caption?: string }) =>
    request<{ id: number; alt: string; caption: string }>(`/media/${id}`, {
      method: 'PATCH',
      body: data as Record<string, unknown>,
    }),
  // Round 9 follow-up 5 — recent article images for the gallery picker
  // (20 per page, never beyond the 50 most recent; staff only).
  // Round 18: `search` finds older images too — by the image's own title,
  // caption, alt text or file name, or the headline of the post using it.
  library: (offset = 0, search = '') =>
    request<{ items: LibraryImage[]; has_more: boolean; total?: number }>(
      `/admin/media?offset=${offset}${search ? `&search=${encodeURIComponent(search)}` : ''}`,
    ),
  // Round 10 — video upload with real progress (multipart field "nros_video").
  uploadVideo: (file: File, onProgress?: (p: UploadProgress) => void, signal?: AbortSignal) => {
    const form = new FormData()
    form.append('nros_video', file)
    return uploadWithProgress<{ id: number; url: string; mime: string; size: number }>('/media/video', form, {
      onProgress,
      signal,
    })
  },
  videoLimits: () => request<{ max_bytes: number; allowed_mimes?: string[]; resumable?: { max_bytes: number; chunk_size: number } }>('/media/video-limits'),
  /**
   * Round 27 — resumable upload in pieces (newsroom-os 1.13.0+). A failed
   * piece is retried (after asking the server what it has) instead of the
   * whole file, and it waits for the connection to come back. Falls back to
   * the single-request upload on an older plugin.
   */
  uploadResumable: (file: File, opts: ResumableOpts = {}) => uploadResumable(file, opts),
}

export interface LibraryImage {
  id: number
  url: string
  thumb: string
  alt: string
  caption: string
  title: string
  date: string
}

// ---- Staff's own posts (Editor/Publisher write + full-edit any article) ----

export interface AdminPostInput {
  title?: string
  content?: string
  excerpt?: string
  ai_summary?: string
  status?: 'draft' | 'pending' | 'publish'
  category_id?: number
  slug?: string
  seo_title?: string
  seo_description?: string
  focus_keyword?: string
  topic?: string
  division?: string
  district?: string
  upazila?: string
  // Round 12
  tags?: string[]
  area?: PostArea
  flags?: Partial<PostFlags>
  glance_text?: string
  lit?: Record<string, string>
  // Plugin 1.12.0 — দেশচিত্র counts ({} clears them).
  dc?: Record<string, number>
  // Round 10 — PATCH only, and only when profile.can allows it.
  date?: string // 'YYYY-MM-DD HH:MM:SS', site time; future → scheduled
  author_id?: number
  // Round 13 — null removes the guest byline; same permission as author_id.
  guest_author?: GuestAuthorInput | null
  save_guest_author?: boolean
}

export const AdminPostsApi = {
  create: (data: AdminPostInput) => request<AdminPost>('/admin/posts', { method: 'POST', body: data as Record<string, unknown> }),
  listMine: () => request<AdminPost[]>('/admin/posts/mine'),
  get: (id: number) => request<AdminPost>(`/admin/posts/${id}`),
  update: (id: number, data: AdminPostInput) =>
    request<AdminPost>(`/admin/posts/${id}`, { method: 'PATCH', body: data as Record<string, unknown> }),
  setFeaturedImage: (id: number, attachmentId: number, extra?: { alt?: string; caption?: string }) =>
    request<AdminPost>(`/admin/posts/${id}/featured-image`, {
      method: 'POST',
      body: { attachment_id: attachmentId, ...extra } as Record<string, unknown>,
    }),
  // Publisher-only: sets (amount > 0) or clears (amount <= 0) this one
  // article's flat payment. Independent of, and stacks with, any view-tier
  // bonus the same post earns automatically.
  setPayment: (id: number, amount: number) =>
    request<{ ok: true; amount: number | null; payment_status: 'approved' }>(`/admin/posts/${id}/payment`, {
      method: 'POST',
      body: { amount },
    }),
  // Round 7: confirms an auto-applied default-rate payment (status
  // 'pending') WITHOUT changing its amount, moving it into the
  // correspondent's available balance.
  confirmPayment: (id: number) =>
    request<{ ok: true; amount: number | null; payment_status: 'approved' }>(`/admin/posts/${id}/payment/confirm`, {
      method: 'POST',
    }),
  getRevisions: (id: number) => request<RevisionEntry[]>(`/admin/posts/${id}/revisions`),
  // Round 10 — moves the post to the trash (restorable from wp-admin).
  remove: (id: number) => request<{ ok: true }>(`/admin/posts/${id}`, { method: 'DELETE' }),
}

// ---- Round 10: authors (author picker for posts + videos) ----

export const AuthorsApi = {
  search: (search = '') =>
    request<AuthorOption[]>(`/admin/authors${search.trim() ? `?search=${encodeURIComponent(search.trim())}` : ''}`),
}

// ---- Round 10: video news (meeha_video) ----

export type VideoStatus = 'draft' | 'pending' | 'publish' | 'future'
export type VideoSource = 'upload' | 'youtube'
export type VideoOrientation = 'portrait' | 'landscape'
export type VideoListFilter = 'all' | 'publish' | 'pending' | 'draft'

export interface Video {
  id: number
  title: string
  excerpt: string
  slug: string
  status: VideoStatus
  link: string
  date: string // post_date 'YYYY-MM-DD HH:MM:SS' (site time)
  date_floating?: boolean // round 13: draft/pending without a set date
  modified: string
  author: { id: number; name: string; avatar: string }
  source: VideoSource
  video_url: string
  video_attachment_id: number | null
  youtube_id: string | null
  orientation: VideoOrientation
  duration: number | null
  thumbnail: string | null
  thumbnail_id: number | null
  category_id: number | null
  seo: Seo
  stats: { views: number; likes: number; comments: number; shares: number }
  rejected: boolean
  reject_reason: string | null
  // Round 12 — per-video payment (correspondents' uploaded videos), same
  // semantics as an article's: 'pending' = auto-applied default rate.
  payment?: number | null
  payment_status?: 'pending' | 'approved' | null
  // Round 21 — set when the current file was made in the video editor.
  edit?: VideoEditInfo | null
}

export interface VideoEditInfo {
  original_attachment_id: number
  original_url: string
  config: Record<string, unknown> | null
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
  status?: 'draft' | 'pending' | 'publish'
  date?: string // staff only, 'YYYY-MM-DD HH:MM:SS'
  author_id?: number // staff only
  // Round 21 — staff only: record (or with null, forget) a video-editor edit.
  edit?: { original_attachment_id: number; config: Record<string, unknown> } | null
}

export const AdminVideosApi = {
  list: (status: VideoListFilter = 'all') => request<Video[]>(`/admin/videos?status=${status}`),
  get: (id: number) => request<Video>(`/admin/videos/${id}`),
  create: (data: VideoInput) => request<Video>('/admin/videos', { method: 'POST', body: data as Record<string, unknown> }),
  update: (id: number, data: VideoInput) =>
    request<Video>(`/admin/videos/${id}`, { method: 'PATCH', body: data as Record<string, unknown> }),
  reject: (id: number, reason: string) =>
    request<Video>(`/admin/videos/${id}/reject`, { method: 'POST', body: { reason } }),
  remove: (id: number) => request<{ ok: true }>(`/admin/videos/${id}`, { method: 'DELETE' }),
  // Round 12 — Publisher-only, mirroring AdminPostsApi.setPayment/confirmPayment.
  setPayment: (id: number, amount: number) =>
    request<{ ok: true; amount: number | null; payment_status: 'approved' | null }>(`/admin/videos/${id}/payment`, {
      method: 'POST',
      body: { amount },
    }),
  confirmPayment: (id: number) =>
    request<{ ok: true; amount: number | null; payment_status: 'approved' }>(`/admin/videos/${id}/payment/confirm`, {
      method: 'POST',
    }),
}

// ---- All posts (news list — every author, filterable by status) ----

export const PostsApi = {
  listAll: (filter: PostListFilter = 'all', page = 1) =>
    request<{ items: AdminPostListRow[]; total: number; pages: number }>(
      `/admin/posts/all?filter=${filter}&page=${page}`
    ),
}

// ---- Round 21: video editor (ভিডিও এডিট) ----

export interface LibraryVideo {
  id: number
  url: string
  title: string
  mime: string
  size: number
  duration: number | null
  width: number | null
  height: number | null
  thumb: string | null
  date: string
  is_ad: boolean
  edited_from: number | null
}

export interface VideoKitRef {
  id: number
  url: string
  title: string
  duration?: number | null
}

export interface VideoKitPreset {
  id: string
  name: string
  config: Record<string, unknown>
}

export interface VideoKit {
  logo: VideoKitRef | null
  intro: VideoKitRef | null
  outro: VideoKitRef | null
  website: string
  ticker: string
  ad_text: string
  presets: VideoKitPreset[]
  /** Round 23: saved name plates (guests, officials). */
  people: VideoKitPerson[]
  /** Round 23: every correspondent, as a ready name plate. */
  reporters: (VideoKitPerson & { id: number })[]
}

export interface VideoKitPerson {
  name: string
  role: string
}

export interface VideoKitInput {
  logo_id?: number
  intro_id?: number
  outro_id?: number
  website?: string
  ticker?: string
  ad_text?: string
}

export const VideoKitApi = {
  get: () => request<VideoKit>('/admin/video-kit'),
  save: (data: VideoKitInput) => request<VideoKit>('/admin/video-kit', { method: 'POST', body: data as Record<string, unknown> }),
  savePeople: (people: VideoKitPerson[]) =>
    request<VideoKit>('/admin/video-kit/people', { method: 'POST', body: { people } as unknown as Record<string, unknown> }),
  savePresets: (presets: VideoKitPreset[]) =>
    request<VideoKit>('/admin/video-kit/presets', { method: 'POST', body: { presets } as unknown as Record<string, unknown> }),
  library: (offset = 0, search = '', adsOnly = false) =>
    request<{ items: LibraryVideo[]; has_more: boolean; total: number }>(
      `/admin/media/videos?offset=${offset}${search ? `&search=${encodeURIComponent(search)}` : ''}${adsOnly ? '&ads=1' : ''}`,
    ),
  updateVideo: (id: number, data: { is_ad?: boolean; title?: string }) =>
    request<LibraryVideo>(`/admin/media/videos/${id}`, { method: 'PATCH', body: data as Record<string, unknown> }),
}

/**
 * Downloads a library file (video or image) through the API, with progress
 * — the editor needs the bytes from the same origin rules as the API (the
 * uploads folder itself sends no CORS headers). No overall timeout: only a
 * stall (no bytes for 60 s) fails it.
 */
export async function downloadMediaFile(id: number, onProgress?: (loaded: number, total: number) => void, signal?: AbortSignal): Promise<Blob> {
  const path = `/admin/media/${id}/file`
  const attempt = async (): Promise<Response> => {
    try {
      return await fetch(`${API_BASE}${path}`, {
        headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : {},
        credentials: 'include',
        signal,
      })
    } catch (err) {
      if (signal?.aborted) throw new ApiError('বাতিল করা হয়েছে।', 0, UPLOAD_ABORTED_CODE, 'client', path)
      throw connectionError(err, path)
    }
  }
  let res = await attempt()
  if (res.status === 401 && (await refreshOnce()) === 'ok') res = await attempt()
  if (!res.ok) {
    const text = await res.text().catch(() => '')
    return parseReply<never>(res.status, text, path)
  }
  const total = Number(res.headers.get('Content-Length')) || 0
  if (!res.body) return res.blob()
  const reader = res.body.getReader()
  const chunks: Uint8Array[] = []
  let loaded = 0
  let stall: ReturnType<typeof setTimeout> | null = null
  const arm = () => {
    if (stall) clearTimeout(stall)
    stall = setTimeout(() => reader.cancel('stall').catch(() => undefined), 60_000)
  }
  try {
    arm()
    for (;;) {
      const { done, value } = await reader.read()
      if (done) break
      chunks.push(value)
      loaded += value.byteLength
      onProgress?.(loaded, total)
      arm()
    }
  } catch (err) {
    if (signal?.aborted) throw new ApiError('বাতিল করা হয়েছে।', 0, UPLOAD_ABORTED_CODE, 'client', path)
    throw connectionError(err, path)
  } finally {
    if (stall) clearTimeout(stall)
  }
  if (total && loaded < total) throw new ApiError('ফাইল পুরোটা নামানো যায়নি — সংযোগ দেখে আবার চেষ্টা করুন।', 0, 'nros_network', 'network', path)
  return new Blob(chunks as BlobPart[], { type: res.headers.get('Content-Type') || 'application/octet-stream' })
}

// ---- Round 24: ফটোকার্ড স্টুডিও (theme studio, bridged by newsroom-os 1.8.0) ----

export interface CardDates {
  weekday: string
  gregorian: string
  bangla: string
  hijri: string
}

export interface CardFrame {
  id: string
  type: string
  size: string
  label: string
  url: string
  alpha: boolean
  by: string
  date: string
  can_delete: boolean
  readymade: boolean
  warning?: string
}

export interface CardAd {
  id: string
  label: string
  url: string
}

export interface CardTypeInfo {
  label: string
  presets: Record<string, string>
  photo: boolean
  ad: boolean
}

export interface StudioConfig {
  types: Record<string, CardTypeInfo>
  sizes: Record<string, string>
  frames: CardFrame[]
  canUpload: boolean
  canManage: boolean
  canSave: boolean
  ads: CardAd[]
  adDefault: boolean
  today: string
  now: string
  dates: CardDates
  hijriOffset: number
  titles: string[]
  condPre: string[]
  defaults: Record<string, string>
  categories: { id: number; name: string; color: string }[]
  quoteFrames: Record<string, string>
  maxBytes: number
  days: { title: string; subtitle: string; theme: string; message: string }[]
  newsDefault: string
  litLabel: string
  storyCta: string
}

export interface CardsLogo {
  mode: 'vector' | 'image'
  url?: string
  fallback?: string
  light?: { url: string; fallback?: string }
}

export interface PhotocardKit {
  studio: boolean
  theme_version: string
  /** Theme 2.4.3+: the site's own "ফটোকার্ড ডাউনলোড" uses the saved photo position. */
  focus_sync: boolean
  site: string
  cards?: {
    logo: CardsLogo
    brand?: { primary: string; accent: string }
    newsDefault?: string
    watermark?: string
    cta?: string
    storyCta?: string
  }
  studio_config?: StudioConfig
  /** newsroom-os 1.9.0: what the built-in Meeha house ads print. */
  house?: {
    social: [string, string, string][]
    campus: { title: string; url: string }
  }
}

/** Round 25: one file of the ad gallery (images, GIFs, videos kept as ads). */
export interface CardAdItem {
  id: number
  url: string
  thumb: string
  title: string
  mime: string
  kind: 'image' | 'gif' | 'video'
  width: number | null
  height: number | null
  date: string
}

export interface CardFocus {
  x: number
  y: number
  zoom: number
}

export interface CardImage {
  id: number
  url: string
  thumb: string
  width: number | null
  height: number | null
  alt: string
  caption: string
  featured: boolean
  focus: CardFocus | null
}

export interface CardQuote {
  id: number
  text: string
  speaker: string
  role: string
  type_label: string
  frame: string
  photo: string
  year: string
}

export interface CardSource {
  id: number
  post_type: string
  type: string
  status: string
  title: string
  author_name: string
  author_title: string
  author_photo: string
  category: string
  category_id: number
  category_color: string
  kicker: string
  permalink: string
  image: string
  image_id: number
  excerpt: string
  summary: string
  date: string
  date_short: string
  time: string
  is_breaking: boolean
  quote?: CardQuote
  lit_genre?: string
  lit_label?: string
  lit_writer?: string
  poem_lines?: string[]
  lit_excerpt?: string
  images: CardImage[]
}

export interface CardRecent {
  id: number
  title: string
  date: string
  thumb: string
  category: string
  type: string
}

export interface MadeCard {
  id: number
  url: string
  thumb: string
  type: string
  title: string
  post_id: number | null
  by: string
  date: string
}

export const PhotocardApi = {
  kit: () => request<PhotocardKit>('/admin/photocard/kit'),
  source: (post: string | number) => request<CardSource>(`/admin/photocard/source?post=${encodeURIComponent(String(post))}`),
  recent: (offset = 0, search = '') =>
    request<{ items: CardRecent[]; per: number; more: boolean }>(
      `/admin/photocard/recent?offset=${offset}${search ? `&search=${encodeURIComponent(search)}` : ''}`,
    ),
  quotes: (search = '', page = 1) =>
    request<{ items: CardQuote[]; total: number; pages: number }>(
      `/admin/photocard/quotes?page=${page}${search ? `&search=${encodeURIComponent(search)}` : ''}`,
    ),
  dates: (d: string, offset: number) => request<CardDates>(`/admin/photocard/dates?d=${encodeURIComponent(d)}&offset=${offset}`),
  /** The bytes of one of the site's images (the canvas needs them same-origin). */
  imageById: (id: number, size = 'large') => requestBlob(`/admin/photocard/file?id=${id}&size=${encodeURIComponent(size)}`),
  imageByUrl: (url: string) => requestBlob(`/admin/photocard/file?url=${encodeURIComponent(url)}`),
  saveFocus: (attachmentId: number, f: CardFocus) =>
    request<{ ok: true; focus: CardFocus | null }>('/admin/photocard/focus', {
      method: 'POST',
      body: { attachment_id: attachmentId, x: f.x, y: f.y, zoom: f.zoom },
    }),
  save: (blob: Blob, filename: string, data: { type: string; title: string; alt?: string; post_id?: number }) => {
    const form = new FormData()
    form.append('file', blob, filename)
    form.append('type', data.type)
    form.append('title', data.title)
    if (data.alt) form.append('alt', data.alt)
    if (data.post_id) form.append('post_id', String(data.post_id))
    return request<MadeCard>('/admin/photocard/save', { method: 'POST', body: form })
  },
  made: (page = 1, type = '') => request<{ items: MadeCard[]; total: number; pages: number }>(`/admin/photocard/made?page=${page}${type ? `&type=${type}` : ''}`),
  uploadFrame: (file: File, data: { type: string; size: string; label: string }) => {
    const form = new FormData()
    form.append('file', file, file.name)
    form.append('type', data.type)
    form.append('size', data.size)
    form.append('label', data.label)
    return request<CardFrame>('/admin/photocard/frames', { method: 'POST', body: form })
  },
  ads: (offset = 0, search = '') =>
    request<{ items: CardAdItem[]; has_more: boolean; total: number }>(
      `/admin/photocard/ads?offset=${offset}${search ? `&search=${encodeURIComponent(search)}` : ''}`,
    ),
  uploadAd: (file: File, title: string) => {
    const form = new FormData()
    form.append('file', file, file.name)
    form.append('title', title)
    return request<CardAdItem>('/admin/photocard/ads', { method: 'POST', body: form })
  },
  markAd: (id: number, isAd: boolean) => request<CardAdItem>(`/admin/photocard/ads/${id}`, { method: 'POST', body: { is_ad: isAd } }),
  deleteFrame: (id: string) => request<{ deleted: true }>(`/admin/photocard/frames/${encodeURIComponent(id)}`, { method: 'DELETE' }),
}

// ---- 1.11.0: live posts (লাইভ ইভেন্ট / অপরাধ তদন্ত লাইভ) ----

export type LiveType = 'event' | 'investigation'
export type LiveStatus = 'live' | 'paused' | 'ended'
export type LiveKind = 'update' | 'key' | 'breaking' | 'statement' | 'score' | 'document' | 'evidence' | 'milestone'
export interface LiveFact { label: string; value: string }
export interface LivePerson { name: string; role: string; status: string; share: number; note: string; photo: number }
export interface LiveVictim { name: string; age: string; detail: string; photo: number }
export interface LivePoint { text: string; weight: number }
export interface LiveConfig {
  type: LiveType
  status: LiveStatus
  started: number
  ended: number
  reporters: number[]
  moderate: boolean
  facts: LiveFact[]
  board: { on: boolean; a_name: string; a_score: string; b_name: string; b_score: string; note: string }
  case: {
    state: 'open' | 'stalled' | 'closed'
    stage: 'filed' | 'investigating' | 'arrests' | 'chargesheet' | 'trial' | 'verdict'
    case_no: string
    filed: string
    incident: string
    place: string
    summary: string
    victims: LiveVictim[]
    people: LivePerson[]
    show_share: boolean
    strengths: LivePoint[]
    weaknesses: LivePoint[]
    disclaimer: string
  }
}
export interface LivePostRow {
  id: number
  title: string
  link: string
  post_status: string
  type: LiveType
  status: LiveStatus
  started: number
  last: number
  count: number
  pending: number
  thumb: string
  reporters: number
}
export interface LiveEntry {
  id: number
  title: string
  content: string
  kind: LiveKind
  pinned: boolean
  source: string
  status: 'publish' | 'pending'
  time: number
  modified: number
  author: { id: number; name: string }
  can_edit: boolean
}
export interface LiveDetail {
  post: LivePostRow
  config: LiveConfig
  entries: LiveEntry[]
  reporters: { id: number; name: string }[]
  photos: Record<string, string>
  can_manage: boolean
  me: number
  kinds: { id: LiveKind; label: string; for: 'both' | 'event' | 'investigation' }[]
  default_disclaimer: string
}
export interface LiveEntryInput {
  title?: string
  content?: string
  kind?: LiveKind
  pinned?: boolean
  source?: string
  status?: 'publish' | 'pending'
  time?: number
}
export interface LiveCandidate { id: number; title: string; status: string; date: number; thumb: string; is_live: boolean }

export const LiveApi = {
  list: () => request<{ items: LivePostRow[] }>('/live'),
  get: (id: number) => request<LiveDetail>(`/live/${id}`),
  addEntry: (id: number, data: LiveEntryInput) =>
    request<LiveEntry>(`/live/${id}/entries`, { method: 'POST', body: data as Record<string, unknown> }),
  editEntry: (id: number, eid: number, data: LiveEntryInput) =>
    request<LiveEntry>(`/live/${id}/entries/${eid}`, { method: 'PATCH', body: data as Record<string, unknown> }),
  removeEntry: (id: number, eid: number) => request<{ ok: true }>(`/live/${id}/entries/${eid}`, { method: 'DELETE' }),
  save: (id: number, data: Partial<LiveConfig>) =>
    request<LiveDetail>(`/admin/live/${id}`, { method: 'POST', body: data as unknown as Record<string, unknown> }),
  disable: (id: number) => request<{ ok: true }>(`/admin/live/${id}`, { method: 'DELETE' }),
  candidates: (search = '') =>
    request<{ items: LiveCandidate[] }>(`/admin/live-candidates?search=${encodeURIComponent(search)}`),
}

// ---- দেশচিত্র (theme 2.4.7 + plugin 1.10.0): the national daily-figures sheet ----
// GET/POST/DELETE /admin/deshchitro — the theme stores and validates; the plugin only proxies (staff only).
export type DcTone = 'bad' | 'warn' | 'good' | 'info'
export interface DcIndicator { key: string; label: string; short: string; icon: string; tone: DcTone; on: number }
export interface DcEconomy { key: string; label: string; group: string; unit: string; dec: number; good: '' | 'up' | 'down'; code: string; on: number }
export interface DcHighlight { t: string; u: string; d: string }
export interface DcData {
  inc: Record<string, Record<string, number>>
  unk: Record<string, number>
  notes: Record<string, string>
  eco: Record<string, number>
  hl: DcHighlight[]
  summary: string
  sources: string
}
export interface DcNews { id: number; title: string; url: string; d: string; tags: string[]; time: string; c?: Record<string, number> }
// Theme 2.4.9: Meeha's own posts that carry দেশচিত্র counts for the day (district from the post's area).
export interface DcMeehaPost { id: number; title: string; url: string; day: string; time: string; d: string; dn: string; up: string; c: Record<string, number>; img: string }
export interface DcMeeha {
  inc: Record<string, Record<string, number>>
  unk: Record<string, number>
  tot: Record<string, number>
  n: number
  posts: DcMeehaPost[]
}
/** GET /admin/deshchitro/fields (plugin 1.12.0) — the দেশচিত্র box under the news editor. */
export interface DcFields {
  available: boolean
  indicators: Pick<DcIndicator, 'key' | 'label' | 'short' | 'icon' | 'tone'>[]
  keywords: Record<string, string>
  live?: boolean
  pageUrl?: string
}
export interface DcSheet {
  day: string
  today: string
  label: string
  exists: boolean
  status: 'draft' | 'publish'
  data: DcData
  updatedAt: string
  updatedBy: string
  prevEco: Record<string, number>
  prevDay: string
  month: Record<string, 'draft' | 'publish'>
  indicators: DcIndicator[]
  economy: DcEconomy[]
  ecoGroups: Record<string, string>
  geo: { districts: Record<string, [string, string]>; divisions: Record<string, string> }
  news: DcNews[]
  pageUrl: string
  meeha?: DcMeeha
}

const dcObj = <T,>(v: unknown): T => (v && typeof v === 'object' && !Array.isArray(v) ? v : {}) as T
/** PHP sends empty maps as [] — turn them into objects so keys survive JSON. */
function dcNormalize(s: DcSheet): DcSheet {
  const d = s.data
  d.inc = dcObj(d.inc)
  Object.keys(d.inc).forEach((k) => { d.inc[k] = dcObj(d.inc[k]) })
  d.unk = dcObj(d.unk)
  d.notes = dcObj(d.notes)
  d.eco = dcObj(d.eco)
  d.hl = Array.isArray(d.hl) ? d.hl : []
  s.prevEco = dcObj(s.prevEco)
  s.month = dcObj(s.month)
  if (s.meeha) {
    const m = s.meeha
    m.inc = dcObj(m.inc)
    Object.keys(m.inc).forEach((k) => { m.inc[k] = dcObj(m.inc[k]) })
    m.unk = dcObj(m.unk)
    m.tot = dcObj(m.tot)
    m.posts = (Array.isArray(m.posts) ? m.posts : []).map((p) => ({ ...p, c: dcObj(p.c) }))
  }
  s.news = (Array.isArray(s.news) ? s.news : []).map((n) => ({ ...n, c: dcObj(n.c) }))
  return s
}

let dcFieldsCache: Promise<DcFields> | null = null

export const DeshchitroApi = {
  get: (date?: string) => request<DcSheet>(`/admin/deshchitro${date ? `?date=${encodeURIComponent(date)}` : ''}`).then(dcNormalize),
  save: (date: string, status: 'draft' | 'publish', data: DcData) =>
    request<DcSheet>('/admin/deshchitro', { method: 'POST', body: { date, status, data } as unknown as Record<string, unknown> }).then(dcNormalize),
  remove: (date: string) => request<DcSheet>(`/admin/deshchitro?date=${encodeURIComponent(date)}`, { method: 'DELETE' }).then(dcNormalize),
  /** Cached per session; an older plugin (404) degrades to "not available" so the editor still works. */
  fields: (): Promise<DcFields> => {
    const p: Promise<DcFields> =
      dcFieldsCache ??
      request<DcFields>('/admin/deshchitro/fields')
        .then((f): DcFields => ({ ...f, available: !!f.available, indicators: Array.isArray(f.indicators) ? f.indicators : [], keywords: dcObj<Record<string, string>>(f.keywords) }))
        .catch((): DcFields => {
          dcFieldsCache = null
          return { available: false, indicators: [], keywords: {} }
        })
    dcFieldsCache = p
    return p
  },
}


// ---- Round 27: resumable uploads ----

export interface ResumableOpts {
  kind?: 'video' | 'audio'
  onProgress?: (p: UploadProgress) => void
  /** A line for the user while waiting/retrying ("নেটওয়ার্ক দুর্বল…"), null when going again. */
  onStatus?: (msg: string | null) => void
  signal?: AbortSignal
  /** An earlier session of this same file (resume after an error). */
  session?: { id: string } | null
  /** Called with the session as soon as it exists, so a later retry can resume it. */
  onSession?: (s: { id: string }) => void
}

interface UploadedFile {
  id: number
  url: string
  mime: string
  size: number
}

const pause = (ms: number, signal?: AbortSignal) =>
  new Promise<void>((resolve, reject) => {
    const t = setTimeout(done, ms)
    const onOnline = () => done()
    function done() {
      clearTimeout(t)
      window.removeEventListener('online', onOnline)
      signal?.removeEventListener('abort', onAbort)
      resolve()
    }
    function onAbort() {
      clearTimeout(t)
      window.removeEventListener('online', onOnline)
      reject(new ApiError('আপলোড বাতিল করা হয়েছে।', 0, UPLOAD_ABORTED_CODE, 'client'))
    }
    // coming back online ends the wait early
    window.addEventListener('online', onOnline)
    signal?.addEventListener('abort', onAbort)
  })

async function waitOnline(signal: AbortSignal | undefined, onStatus?: (m: string | null) => void) {
  while (typeof navigator !== 'undefined' && navigator.onLine === false) {
    onStatus?.('ইন্টারনেট সংযোগ নেই — সংযোগ ফিরলে আপলোড নিজে থেকেই আবার চলবে…')
    await pause(15000, signal)
  }
}

/** Errors worth retrying: no connection, timeouts, server hiccups (5xx, 429). */
function retryable(err: unknown) {
  if (!(err instanceof ApiError)) return true
  if (err.code === UPLOAD_ABORTED_CODE) return false
  return err.status === 0 || err.status >= 500 || err.status === 429 || err.status === 408 || err.kind === 'network' || err.kind === 'timeout' || err.kind === 'offline'
}

/**
 * Errors that will not go away by trying again: cancelled, logged out,
 * no permission for this upload, a file the server refuses. Anything else
 * (connection, timeouts, 5xx, a hosting firewall's odd 4xx) is retried.
 */
function fatalUpload(err: unknown) {
  if (!(err instanceof ApiError)) return false
  if (err.code === UPLOAD_ABORTED_CODE || err.kind === 'session') return true
  return ['nros_bad_file_type', 'nros_forbidden', 'nros_file_too_large', 'nros_upload_session_missing', 'nros_suspended', 'nros_video_daily_upload_cap'].includes(err.code ?? '')
}

async function uploadResumable(file: File, o: ResumableOpts): Promise<UploadedFile> {
  const { signal, onStatus, onProgress } = o
  const kind = o.kind ?? 'video'
  const report = (loaded: number) => onProgress?.({ loaded, total: file.size, percent: Math.min(100, Math.floor((loaded / file.size) * 100)) })
  // connection trouble is retried for as long as the page stays open;
  // only odd 4xx replies (a refusal, not the network) count toward a limit
  const MAX_ODD = 12
  let fails = 0
  let odd = 0
  const backoff = () => Math.min(30, 2 * Math.pow(1.6, Math.min(fails, 8)))
  const bump = (err: unknown) => {
    if (fatalUpload(err)) throw err
    if (!retryable(err) && ++odd > MAX_ODD) throw err
    fails++
  }
  /** Runs `fn`, retrying network trouble with growing waits. */
  async function withRetry<T>(fn: () => Promise<T>, what: string): Promise<T> {
    for (;;) {
      if (signal?.aborted) throw new ApiError('আপলোড বাতিল করা হয়েছে।', 0, UPLOAD_ABORTED_CODE, 'client')
      await waitOnline(signal, onStatus)
      try {
        return await fn()
      } catch (err) {
        if (err instanceof ApiError && err.status === 404) throw err
        bump(err)
        const wait = backoff()
        onStatus?.(`নেটওয়ার্ক দুর্বল — ${what} ${bnSeconds(Math.round(wait))} পরে আবার চেষ্টা হবে (আগের অংশ হারায়নি)…`)
        await pause(wait * 1000, signal)
      }
    }
  }
  type Status = { received: number; size: number; done: boolean; processing?: boolean }
  const status = (sid: string) => withRetry(() => request<Status>(`/media/upload-session/${sid}`), 'সংযোগ')

  // 1 — the session (a fresh one, or the earlier one of this file)
  let session: { id: string; chunk: number } | null = null
  if (o.session) {
    try {
      const st = await status(o.session.id)
      if (st.size === file.size) session = { id: o.session.id, chunk: 4 * 1048576 }
    } catch (err) {
      if (!(err instanceof ApiError) || err.status !== 404) throw err
    }
  }
  if (!session) {
    try {
      const s = await withRetry(
        () => request<{ id: string; chunk_size: number; received: number }>('/media/upload-session', { method: 'POST', body: { kind, name: file.name, size: file.size } }),
        'সংযোগ',
      )
      session = { id: s.id, chunk: Math.max(262144, Math.min(s.chunk_size || 4194304, 8 * 1048576)) }
    } catch (err) {
      // an older plugin without resumable uploads: one request, as before
      if (err instanceof ApiError && err.status === 404 && kind === 'video') {
        onStatus?.('সার্ভারের প্লাগইন পুরোনো — এই আপলোড ব্যর্থ হলে শুরু থেকে হবে। newsroom-os আপডেট করুন।')
        const form = new FormData()
        form.append('nros_video', file)
        return uploadWithProgress<UploadedFile>('/media/video', form, { onProgress, signal })
      }
      throw err
    }
    o.onSession?.({ id: session.id })
  }
  const id = session.id
  // smaller pieces on a shaky line lose less on each failure
  let chunk = Math.min(session.chunk, 4 * 1048576)
  let ceiling = session.chunk
  let st = await status(id)
  let received = st.received
  report(received)

  // 2 — the pieces (skipped when the server already has them all)
  while (!st.done && !st.processing && received < file.size) {
    const end = Math.min(file.size, received + chunk)
    const piece = file.slice(received, end)
    const from = received
    try {
      await waitOnline(signal, onStatus)
      const r = await uploadWithProgress<{ received: number }>(`/media/upload-session/${id}/chunk?offset=${from}`, piece, {
        onProgress: (p) => report(from + p.loaded),
        signal,
      })
      received = r.received
      onStatus?.(null)
      fails = Math.max(0, fails - 1)
      // a good run: grow the piece back up
      if (chunk < ceiling) chunk = Math.min(ceiling, chunk * 2)
    } catch (err) {
      if (err instanceof ApiError && err.status === 413) {
        // the host refuses bodies this big: use smaller pieces from now on
        ceiling = chunk = Math.max(262144, Math.floor(chunk / 2))
        onStatus?.('সার্ভার বড় অংশ নিচ্ছে না — ছোট অংশে পাঠানো হচ্ছে…')
      } else if (!(err instanceof ApiError && err.status === 409)) {
        bump(err)
        chunk = Math.max(262144, Math.floor(chunk / 2))
        const wait = backoff()
        onStatus?.(`নেটওয়ার্ক দুর্বল — ${bnSeconds(Math.round(wait))} পরে যেখানে থেমেছে সেখান থেকে আবার চলবে…`)
        await pause(wait * 1000, signal)
      }
      // ask the server what it really has (the piece may have arrived)
      st = await status(id)
      received = st.received
      report(received)
    }
  }

  // 3 — done: the server checks the file and puts it in the library. For a
  // big file that can take longer than one reply waits; a second "finish"
  // while the first still runs must not be taken as a failure, so on any
  // trouble we watch the status until the server says the file is in.
  report(file.size)
  for (let round = 0; ; round++) {
    onStatus?.(round === 0 ? 'সার্ভারে ফাইল যাচাই ও সংরক্ষণ হচ্ছে…' : 'সার্ভার এখনও ফাইলটি সংরক্ষণ করছে — অপেক্ষা করুন…')
    if (signal?.aborted) throw new ApiError('আপলোড বাতিল করা হয়েছে।', 0, UPLOAD_ABORTED_CODE, 'client')
    if (st.done || !st.processing) {
      try {
        await waitOnline(signal, onStatus)
        const res = await request<UploadedFile & { processing?: boolean }>(`/media/upload-session/${id}/finish`, { method: 'POST', body: {}, timeoutMs: 330_000 })
        if (!res.processing && res.id) {
          onStatus?.(null)
          return res
        }
      } catch (err) {
        if (fatalUpload(err)) throw err
        if (err instanceof ApiError && err.status === 409) {
          // the pieces were moved into the library by a finish still running
          // (older plugin), or a piece really is missing: the status says which
        } else bump(err)
      }
    }
    await pause(round < 6 ? 5000 : 10000, signal)
    st = await status(id)
    // a piece is truly missing (not just being saved): send the rest
    if (!st.done && !st.processing && st.received > 0 && st.received < file.size) {
      onStatus?.(null)
      return uploadResumable(file, { ...o, session: { id } })
    }
    // an older plugin reports 0 bytes while it saves; give it time
    if (round > 70) throw new ApiError('সার্ভার ফাইলটি সংরক্ষণ শেষ করেনি — একটু পরে “আবার চালু করুন” চাপুন।', 0, 'nros_finish_slow', 'timeout')
  }
}

const bnSeconds = (n: number) => `${String(n).replace(/[0-9]/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])} সেকেন্ড`
