/**
 * r34 — ভিউ/ইনসাইট: website + Facebook Pages + YouTube channels + TikTok in one place
 * (newsroom-os 1.17.0, routes under /vi). Tokens never reach this app: the server
 * signs in with each platform, keeps the keys encrypted and saves every day itself.
 */
import { apiRequest as request } from './api'

export type ViPlatform = 'website' | 'facebook' | 'youtube' | 'tiktok'
export type ViPeriod = 'today' | '7d' | '30d' | '6m' | 'this_year' | 'last_year' | 'lifetime' | 'custom'
export type ViProvider = 'facebook' | 'google' | 'tiktok'

export const PERIODS: { key: ViPeriod; label: string }[] = [
  { key: 'today', label: 'আজ' },
  { key: '7d', label: '৭ দিন' },
  { key: '30d', label: '৩০ দিন' },
  { key: '6m', label: '৬ মাস' },
  { key: 'this_year', label: 'এ বছর' },
  { key: 'last_year', label: 'গত বছর' },
  { key: 'lifetime', label: 'লাইফটাইম' },
  { key: 'custom', label: 'কাস্টম' },
]

/** Brand-recognisable, and distinct from each other (website = Meeha green-teal). */
export const PLATFORM: Record<ViPlatform, { label: string; color: string; soft: string }> = {
  website: { label: 'ওয়েবসাইট', color: '#1baf7a', soft: 'bg-emerald-50 text-emerald-700' },
  facebook: { label: 'ফেসবুক', color: '#2a78d6', soft: 'bg-blue-50 text-blue-700' },
  youtube: { label: 'ইউটিউব', color: '#e0352b', soft: 'bg-red-50 text-red-700' },
  tiktok: { label: 'টিকটক', color: '#3a3a3a', soft: 'bg-neutral-100 text-neutral-800' },
}

export interface ViAccountRow {
  id: number
  name: string
  url: string
  avatar: string
  status: string
  views: number
  prev_views: number | null
  followers: number | null
  followers_change: number | null
  since: string | null
  partial: boolean
  last_sync: string | null
}

export interface ViPlatformSummary {
  key: ViPlatform
  views: number
  prev_views: number | null
  followers: number | null
  followers_change?: number | null
  since: string | null
  partial: boolean
  accounts: ViAccountRow[]
}

export interface ViSeriesPoint {
  key: string
  website: number
  facebook: number
  youtube: number
  tiktok: number
  total: number
}

export interface ViTopItem {
  title: string
  url: string
  thumb: string
  views: number
  date: string
  account: string
}

export interface ViOverview {
  range: {
    period: ViPeriod
    label: string
    from: string
    to: string
    prev_from: string | null
    prev_to: string | null
    granularity: 'day' | 'week' | 'month'
  }
  totals: { views: number; prev_views: number | null; followers: number; followers_change: number | null }
  platforms: ViPlatformSummary[]
  series: ViSeriesPoint[]
  top: Record<ViPlatform, ViTopItem[]>
  has_accounts: boolean
  generated_at: number
}

export interface ViLiveItem {
  title: string
  viewers: number
  url: string
}

export interface ViLive {
  total: number
  website: {
    supported: boolean
    enabled: boolean
    now: number
    last30: number
    today: number
    window: number
    devices: Record<string, number>
    pages: { id: number; title: string; url: string; n: number }[]
  }
  accounts: {
    id: number
    platform: ViPlatform
    name: string
    avatar: string
    supported: boolean
    live: boolean
    viewers: number
    items: ViLiveItem[]
    error: string
    checked: string
  }[]
  ttl: number
  time: string
}

export interface ViAccount {
  id: number
  platform: Exclude<ViPlatform, 'website'>
  ext_id: string
  name: string
  handle: string
  url: string
  avatar: string
  status: 'ok' | 'error' | 'expired' | 'disconnected' | string
  last_error: string
  warning: string
  last_sync: string | null
  backfill_done: boolean
  backfill_until: string | null
  data_since: string | null
  followers: number | null
  lifetime: number | null
  enabled: boolean
  connected: boolean
  refresh_expires: string | null
  sync_error?: string
}

export interface ViAppStatus {
  id: string
  has_secret: boolean
  ready: boolean
  redirect: string
  version?: string
}

export interface ViSetup {
  accounts: ViAccount[]
  apps: Record<ViProvider, ViAppStatus>
  settings: { editors: boolean }
  cron: { next: string | null; last: string | null }
  site: string
  privacy: string
}

export interface ViAppInput {
  id?: string
  secret?: string
  version?: string
  clear_secret?: boolean
}

export interface ViFbPage {
  id: string
  name: string
  link: string
  picture: string
  followers: number | null
  connected: boolean
}

export const ViApi = {
  overview: (period: ViPeriod, from = '', to = '') => {
    const q = new URLSearchParams({ period })
    if (period === 'custom') {
      q.set('from', from)
      q.set('to', to)
    }
    return request<ViOverview>(`/vi/overview?${q.toString()}`)
  },
  live: (fresh = false) => request<ViLive>(`/vi/live${fresh ? '?fresh=1' : ''}`),
  setup: () => request<ViSetup>('/vi/accounts'),
  saveSetup: (body: Partial<Record<ViProvider, ViAppInput>> & { editors?: boolean }) =>
    request<ViSetup>('/vi/setup', { method: 'POST', body }),
  connect: (provider: ViProvider, returnUrl: string) =>
    request<{ url: string }>('/vi/connect', { method: 'POST', body: { provider, return: returnUrl } }),
  fbPending: (key: string) => request<{ pages: ViFbPage[] }>(`/vi/facebook/pending?key=${encodeURIComponent(key)}`),
  fbAdd: (key: string, ids: string[]) =>
    request<{ added: ViAccount[] }>('/vi/facebook/pending', { method: 'POST', body: { key, ids }, timeoutMs: 120_000 }),
  patch: (id: number, body: { enabled: boolean }) => request<ViAccount>(`/vi/accounts/${id}`, { method: 'PATCH', body }),
  sync: (id: number) => request<ViAccount>(`/vi/accounts/${id}/sync`, { method: 'POST', timeoutMs: 120_000 }),
  remove: (id: number, purge: boolean) => request<ViAccount | { deleted: true }>(`/vi/accounts/${id}${purge ? '?purge=1' : ''}`, { method: 'DELETE' }),
}

/** Label for a series bucket key. */
export function bucketLabel(key: string, gran: 'day' | 'week' | 'month'): string {
  const BN = '০১২৩৪৫৬৭৮৯'
  const bn = (s: string | number) => String(s).replace(/[0-9]/g, (d) => BN[Number(d)])
  const M = ['জানু', 'ফেব্রু', 'মার্চ', 'এপ্রি', 'মে', 'জুন', 'জুলা', 'আগ', 'সেপ্টে', 'অক্টো', 'নভে', 'ডিসে']
  const [y, m, d] = key.split('-').map(Number)
  if (gran === 'month') return `${M[(m || 1) - 1]} ${bn(String(y).slice(2))}`
  return `${bn(d)} ${M[(m || 1) - 1]}`
}
