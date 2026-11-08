/**
 * r30 — প্রোফাইল ডেস্ক: types and API (newsroom-os 1.15.0, routes under /pd).
 *
 * The vault token (after PIN + OTP) lives in this tab's sessionStorage only:
 * closing the tab forgets it, and the server ends it after 15 idle minutes.
 */
import { ApiError, apiRequest as request, describeError, MediaApi, type UploadProgress, type UploadedFile } from './api'

export type EntityKind = 'person' | 'org'
export type Platform = 'facebook' | 'x' | 'youtube' | 'tiktok' | 'instagram' | 'telegram' | 'linkedin' | 'whatsapp' | 'website' | 'other'

export interface PdLink {
  id: number
  entity_id: number
  platform: Platform
  url: string
  handle: string
  label: string
  /** 0 unchecked, 1 verified, 2 fake / closed */
  verified: number
  /** new posts can be counted automatically (YouTube, Telegram, RSS) */
  auto: boolean
  feed_state: string
  feed_error: string
  last_checked: string | null
  watch: { posts: boolean; videos: boolean }
  unseen: { posts: number; videos: number }
  seen_at: string | null
  checked_today: boolean
}

export interface PdRef {
  id: number
  name: string
  kind: EntityKind
  category: string
}

export interface PdEntity {
  id: number
  kind: EntityKind
  category: string
  name_bn: string
  name_en: string
  name: string
  aliases: string
  title: string
  org_id: number
  parent_id: number
  country: string
  division: string
  district: string
  upazila: string
  tags: string[]
  importance: number
  status: string
  sensitivity: 'normal' | 'restricted'
  phone: string
  email: string
  photo: string
  photo_file_id: number
  bio: string
  created_by: number
  created_at: string
  updated_at: string
  can_edit: boolean
  can_delete: boolean
  links: PdLink[]
  org: PdRef | null
  parent: PdRef | null
  files?: number
}

export interface PdFile {
  id: number
  scope: 'entity' | 'vault_private' | 'vault_public'
  folder_id: number
  entity_id: number
  entity: PdRef | null
  name: string
  ext: string
  mime: string
  kind: 'image' | 'video' | 'audio' | 'doc' | 'other'
  size: number
  note: string
  source: string
  collected_on: string | null
  created_at: string
  owner: string
  owner_id: number
  url: string
  download: string
}

export interface PdFolder {
  id: number
  name: string
  parent_id: number
  count?: number
  owner?: string
}

export interface PdFeedItem {
  id: number
  link_id: number
  platform: Platform
  kind: 'post' | 'video'
  title: string
  url: string
  published_at: string
}

export interface PdEntityFull extends Omit<PdEntity, 'files'> {
  private_note: string
  groups: number[]
  members: { id: number; name: string; title: string; kind: EntityKind; category: string; importance: number }[]
  files: { folders: PdFolder[]; items: PdFile[]; vault: { count: number; open: boolean; items: PdFile[] } }
  recent_items: PdFeedItem[]
  creator: string
}

export interface PdVaultState {
  has_pin: boolean
  open: boolean
  locked_for: number
  idle_minutes: number
}

export interface PdMeta {
  categories: { key: string; kind: EntityKind; label: string }[]
  platforms: { key: Platform; label: string; auto: boolean }[]
  statuses: { key: string; label: string }[]
  tags: string[]
  districts: string[]
  countries: string[]
  totals: { person: number; org: number }
  is_boss: boolean
  vault: PdVaultState
  storage: { used_bytes: number; files: number; free_bytes: number | null; writable: boolean; exposure: 'ok' | 'exposed' | 'unknown'; checked_at: string | null }
  max_file: number
  unseen: number
}

export interface PdGroup {
  id: number
  name: string
  color: string
  note: string
  created_by: number
  members: { id: number; name: string; photo: string; links: PdLink[] }[]
}

export interface PdListParams {
  q?: string
  kind?: EntityKind | ''
  category?: string
  country?: string
  district?: string
  status?: string
  tag?: string
  importance?: number
  org?: number
  group?: number
  platform?: string
  alert?: boolean
  files?: boolean
  sort?: 'updated' | 'name' | 'importance'
  page?: number
  per_page?: number
}

export interface PdAlerts {
  entities: { id: number; name: string; photo: string; links: (PdLink & { new_items: PdFeedItem[] })[] }[]
  unseen: number
  last_run: string
  cron_ok: boolean
}

export interface PdOtp {
  otp_token: string
  method: 'sms' | 'email'
  destination: string
  expires_in: number
}

export interface PdVaultList {
  state: PdVaultState
  scope?: 'vault_private' | 'vault_public'
  trail?: { id: number; name: string }[]
  summary?: Record<string, { files: number; bytes: number }>
  folders?: PdFolder[]
  files?: PdFile[]
}

export interface PdAccessRow {
  id: number
  name: string
  email: string
  role: 'publisher' | 'editor'
  access: boolean
  fixed: boolean
  avatar: string
}

export interface PdAuditRow {
  id: number
  user: string
  action: string
  object_type: string
  object_id: number
  detail: string
  ip: string
  created_at: string
}

export type PdEntityInput = Partial<Omit<PdEntity, 'links' | 'org' | 'parent' | 'tags' | 'id'>> & {
  tags?: string[]
  private_note?: string
  links?: { url: string; platform?: string; label?: string }[]
  group_ids?: number[]
  force?: boolean
}

// ---- vault token (this tab only) ----

const VT_KEY = 'meeha-pd-vault'
let vaultToken: string | null = (() => {
  try {
    return sessionStorage.getItem(VT_KEY)
  } catch {
    return null
  }
})()

export function getVaultToken() {
  return vaultToken
}
export function setVaultToken(t: string | null) {
  vaultToken = t
  try {
    if (t) sessionStorage.setItem(VT_KEY, t)
    else sessionStorage.removeItem(VT_KEY)
  } catch {
    // private mode: memory only
  }
}
const vh = (): Record<string, string> => (vaultToken ? { 'X-NROS-Vault': vaultToken } : {})

function qs(p: Record<string, unknown>) {
  const u = new URLSearchParams()
  for (const [k, v] of Object.entries(p)) {
    if (v === undefined || v === null || v === '' || v === false || v === 0) continue
    u.set(k, v === true ? '1' : String(v))
  }
  const s = u.toString()
  return s ? `?${s}` : ''
}

export const ProfileDeskApi = {
  meta: () => request<PdMeta>('/pd/meta', { headers: vh() }),
  list: (p: PdListParams) =>
    request<{ items: PdEntity[]; total: number; page: number; pages: number }>(`/pd/entities${qs(p as Record<string, unknown>)}`),
  get: (id: number) => request<PdEntityFull>(`/pd/entities/${id}`, { headers: vh() }),
  create: (body: PdEntityInput) => request<PdEntityFull>('/pd/entities', { method: 'POST', body: body as Record<string, unknown> }),
  update: (id: number, body: PdEntityInput) =>
    request<PdEntityFull>(`/pd/entities/${id}`, { method: 'PATCH', body: body as Record<string, unknown>, headers: vh() }),
  remove: (id: number) => request<{ deleted: boolean }>(`/pd/entities/${id}`, { method: 'DELETE' }),

  addLinks: (id: number, urls: string) => request<{ added: number; links: PdLink[] }>(`/pd/entities/${id}/links`, { method: 'POST', body: { urls } }),
  addLink: (id: number, link: { url: string; platform?: string; label?: string }) =>
    request<{ added: number; links: PdLink[] }>(`/pd/entities/${id}/links`, { method: 'POST', body: link }),
  updateLink: (id: number, body: Partial<Pick<PdLink, 'url' | 'platform' | 'label' | 'handle' | 'verified'>> & { sort_order?: number }) =>
    request<PdLink>(`/pd/links/${id}`, { method: 'PATCH', body }),
  removeLink: (id: number) => request<{ deleted: boolean }>(`/pd/links/${id}`, { method: 'DELETE' }),
  watch: (id: number, w: { posts: boolean; videos: boolean }) => request<PdLink>(`/pd/links/${id}/watch`, { method: 'POST', body: w }),
  seen: (ids: number[]) => request<{ seen: number; unseen: number }>('/pd/links/seen', { method: 'POST', body: { ids }, retry: true }),
  alerts: () => request<PdAlerts>('/pd/alerts'),
  checkNow: () => request<{ checked: number; new: number; unseen: number }>('/pd/alerts/check', { method: 'POST', timeoutMs: 60_000 }),

  groups: () => request<PdGroup[]>('/pd/groups'),
  createGroup: (body: { name: string; color?: string; note?: string; entity_ids?: number[] }) =>
    request<{ id: number }>('/pd/groups', { method: 'POST', body }),
  updateGroup: (id: number, body: { name?: string; color?: string; note?: string; add?: number[]; remove?: number[]; order?: number[] }) =>
    request<{ id: number }>(`/pd/groups/${id}`, { method: 'PATCH', body }),
  removeGroup: (id: number) => request<{ deleted: boolean }>(`/pd/groups/${id}`, { method: 'DELETE' }),

  createFolder: (body: { scope: PdFile['scope']; entity_id?: number; parent_id?: number; name: string }) =>
    request<PdFolder>('/pd/folders', { method: 'POST', body, headers: vh() }),
  renameFolder: (id: number, name: string) => request<PdFolder>(`/pd/folders/${id}`, { method: 'PATCH', body: { name }, headers: vh() }),
  removeFolder: (id: number) => request<{ deleted: boolean; files: number }>(`/pd/folders/${id}`, { method: 'DELETE', headers: vh() }),
  updateFile: (id: number, body: Partial<Pick<PdFile, 'name' | 'note' | 'source' | 'collected_on' | 'folder_id' | 'entity_id'>>) =>
    request<PdFile>(`/pd/files/${id}`, { method: 'PATCH', body: body as Record<string, unknown>, headers: vh() }),
  removeFile: (id: number) => request<{ deleted: boolean }>(`/pd/files/${id}`, { method: 'DELETE', headers: vh() }),
  downloadLink: (id: number) => request<{ url: string }>(`/pd/files/${id}/download`, { method: 'POST', headers: vh() }),
  upload: (
    file: File,
    target: { scope: PdFile['scope']; entity_id?: number; folder_id?: number; note?: string; source?: string },
    opts: { onProgress?: (p: UploadProgress) => void; onStatus?: (m: string | null) => void; signal?: AbortSignal } = {},
  ) =>
    MediaApi.uploadResumable(file, {
      kind: 'pd',
      extra: { pd_scope: target.scope, pd_entity: target.entity_id ?? 0, pd_folder: target.folder_id ?? 0, pd_note: target.note ?? '', pd_source: target.source ?? '' },
      headers: vh(),
      ...opts,
    }) as Promise<UploadedFile & { file: PdFile; duplicate_of: number }>,

  vault: (scope: 'vault_private' | 'vault_public', folder_id = 0) =>
    request<PdVaultList>(`/pd/vault${qs({ scope, folder_id })}`, { headers: vh() }),
  unlock: (pin: string) => request<PdOtp>('/pd/vault/unlock', { method: 'POST', body: { pin } }),
  pinStart: () => request<PdOtp>('/pd/vault/pin/start', { method: 'POST' }),
  verify: (body: { otp_token: string; code: string; purpose: 'unlock' | 'pin'; new_pin?: string }) =>
    request<{ vault_token: string; idle_seconds: number }>('/pd/vault/verify', { method: 'POST', body }),
  lock: (all = false) => request<{ open: false }>('/pd/vault/lock', { method: 'POST', body: { all }, headers: vh(), retry: true }),

  access: () => request<PdAccessRow[]>('/pd/access'),
  setAccess: (user_id: number, access: boolean) => request<{ access: boolean }>('/pd/access', { method: 'POST', body: { user_id, access } }),
  audit: () => request<PdAuditRow[]>('/pd/audit'),
  storageTest: () => request<PdMeta['storage']>('/pd/storage/test', { method: 'POST', timeoutMs: 30_000 }),
}

// ---- helpers ----

const BN = '০১২৩৪৫৬৭৮৯'
export const bn = (n: number | string) => String(n).replace(/[0-9]/g, (d) => BN[Number(d)])

export function bnBytes(b: number) {
  if (b < 1024) return `${bn(b)} বাইট`
  if (b < 1048576) return `${bn((b / 1024).toFixed(0))} কেবি`
  if (b < 1073741824) return `${bn((b / 1048576).toFixed(1))} এমবি`
  return `${bn((b / 1073741824).toFixed(2))} জিবি`
}

export function bnAgo(mysql: string | null) {
  if (!mysql) return ''
  const t = new Date(mysql.replace(' ', 'T')).getTime()
  const s = Math.max(0, Math.round((Date.now() - t) / 1000))
  if (s < 60) return 'এইমাত্র'
  if (s < 3600) return `${bn(Math.floor(s / 60))} মিনিট আগে`
  if (s < 86400) return `${bn(Math.floor(s / 3600))} ঘণ্টা আগে`
  return `${bn(Math.floor(s / 86400))} দিন আগে`
}

/** Platform from a URL, the same rules as the server (for instant icons while typing). */
export function detectPlatform(url: string): Platform {
  let host = ''
  try {
    host = new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(url) ? url : `https://${url}`).hostname.toLowerCase()
  } catch {
    return 'other'
  }
  host = host.replace(/^(www|m|mobile|web|mbasic)\./, '')
  const map: Record<string, Platform> = {
    'facebook.com': 'facebook', 'fb.com': 'facebook', 'fb.watch': 'facebook',
    'x.com': 'x', 'twitter.com': 'x',
    'youtube.com': 'youtube', 'youtu.be': 'youtube',
    'tiktok.com': 'tiktok', 'instagram.com': 'instagram',
    't.me': 'telegram', 'telegram.me': 'telegram',
    'linkedin.com': 'linkedin', 'wa.me': 'whatsapp', 'whatsapp.com': 'whatsapp',
  }
  return map[host] ?? (host.includes('.') ? 'website' : 'other')
}

/**
 * Opens many links at once. A browser lets one click open ONE new tab; the
 * rest are blocked until the person allows pop-ups for this site (once).
 * Returns how many the browser actually opened.
 */
export function openMany(urls: string[]): number {
  let opened = 0
  for (const u of urls) {
    const w = window.open(u, '_blank')
    if (w) {
      // the opened site must not be able to reach back into this app
      try {
        w.opener = null
      } catch {
        // cross-origin already: nothing to do
      }
      opened++
    }
  }
  return opened
}

/** Error text for the desk: the server's own Bangla message where there is one. */
export function errText(e: unknown): string {
  if (e instanceof ApiError) return describeError(e).message
  if (e instanceof Error) return e.message
  return String(e)
}
