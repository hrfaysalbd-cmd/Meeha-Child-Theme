/**
 * r43 — /brand, /stationery and /idcard on newsroom-os 1.26.0.
 * Shared by both apps (each exports `apiRequest` from ../lib/api).
 */
import { apiRequest } from '../lib/api'
import type { BrandAssets, OrgInfo } from './util'
import type { CardData, CardStatus } from './idcard'
import type { VisitingData } from './visiting'

export interface Pkg {
  key: string
  name: string
  price: number
  renew_price: number
  items: string[]
  physical: boolean
  note: string
  popular: boolean
  active: boolean
}

export interface PayMethod {
  key: string
  label: string
  number: string
  kind: string
  help: string
}

export interface CardFields {
  name_bn: string
  name_en: string
  designation: string
  designation_en: string
  area_type: string
  division: string
  district: string
  upazila: string
  campus: string
  blood: string
  phone: string
  email: string
  emergency: string
  nid: string
  joined: string
  address: string
}

export interface PersonLite {
  id: number
  name: string
  avatar: string
  role: string
  area: string
}

export interface IdCard {
  id: number
  user_id: number
  card_no: string
  token: string
  verify_url: string
  status: Exclude<CardStatus, 'preview'>
  status_text: string
  template: string
  fields: CardFields
  area: string
  photo: string
  photo_data?: string
  pkg_key: string
  issued_at: string
  expires_at: string
  days_left: number
  auto_renew: boolean
  renewals: number
  scans: number
  cancel_reason: string
  person: PersonLite | null
}

export type OrderStatus = 'awaiting' | 'review' | 'approved' | 'rejected' | 'cancelled'
export type Fulfil = '' | 'printing' | 'shipped' | 'delivered'

export interface IdOrder {
  id: number
  user_id: number
  type: 'new' | 'renew'
  pkg_key: string
  pkg: Pkg | null
  amount: number
  status: OrderStatus
  fulfil: Fulfil
  pay_method: string
  pay_method_text: string
  pay_txn: string
  pay_from: string
  pay_note: string
  paid_at: string | null
  fields: CardFields
  photo: string
  photo_data?: string
  area: string
  ship_to: string
  staff_note: string
  reason: string
  auto: boolean
  decided_by: string
  decided_at: string | null
  created_at: string
  person: PersonLite | null
}

export interface IdPublicSettings {
  enabled: boolean
  packages: Pkg[]
  methods: PayMethod[]
  validity_months: number
  grace_days: number
  designations: string[]
  area_types: Record<string, string>
  back_terms: string[]
  apply_note: string
  template: string
}

export interface IdMe {
  card: IdCard | null
  orders: IdOrder[]
  settings: IdPublicSettings
  prefill: CardFields
  photo: string
  balance: number
  org: OrgInfo
  assets: BrandAssets
}

export interface IdSettings {
  enabled: boolean
  packages: Pkg[]
  methods: Record<string, { on: boolean; number: string; kind: string; help: string }>
  validity_months: number
  grace_days: number
  remind_days: number[]
  auto_renew_days: number
  auto_renew_default: boolean
  auto_approve_wallet: boolean
  card_prefix: string
  template: string
  designations: string[]
  back_terms: string[]
  apply_note: string
}

export interface IdSummary {
  review: number
  awaiting: number
  printing: number
  active: number
  expiring: number
  expired: number
  income_month: number
  income_total: number
}

export interface SavedDoc {
  id: number
  kind: string
  design: string
  title: string
  data: Record<string, string>
  shared: boolean
  owner_id: number
  by: string
  updated_at: string
}

export interface PayInput {
  pay_method?: string
  pay_txn?: string
  pay_from?: string
}

const qs = (o: Record<string, string | number | undefined>) => {
  const p = Object.entries(o).filter(([, v]) => v !== undefined && v !== '')
  return p.length ? '?' + p.map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`).join('&') : ''
}

export const BrandApi = {
  org: () => apiRequest<{ org: OrgInfo; defaults: OrgInfo; assets: BrandAssets }>('/brand/org'),
  saveOrg: (org: Partial<OrgInfo>) => apiRequest<{ ok: true; org: OrgInfo }>('/brand/org', { method: 'POST', body: { org } as Record<string, unknown> }),
  docs: (kind?: string) => apiRequest<{ items: SavedDoc[] }>(`/stationery${qs({ kind })}`),
  saveDoc: (doc: { id?: number; kind: string; design: string; title: string; data: Record<string, string>; shared?: boolean }) =>
    apiRequest<{ ok: true; item: SavedDoc }>(doc.id ? `/stationery/${doc.id}` : '/stationery', { method: 'POST', body: doc as unknown as Record<string, unknown> }),
  deleteDoc: (id: number) => apiRequest<{ ok: true }>(`/stationery/${id}`, { method: 'DELETE' }),
}

export const IdCardApi = {
  me: () => apiRequest<IdMe>('/idcard/me'),
  apply: (body: { package: string; fields: Partial<CardFields> & { photo?: string }; ship_to?: string } & PayInput) =>
    apiRequest<{ ok: true; order: IdOrder }>('/idcard/apply', { method: 'POST', body: body as unknown as Record<string, unknown> }),
  renew: (body: { package?: string } & PayInput) => apiRequest<{ ok: true; order: IdOrder }>('/idcard/renew', { method: 'POST', body: body as Record<string, unknown> }),
  pay: (id: number, body: PayInput) => apiRequest<{ ok: true; order: IdOrder }>(`/idcard/orders/${id}/pay`, { method: 'POST', body: body as Record<string, unknown> }),
  cancel: (id: number) => apiRequest<{ ok: true }>(`/idcard/orders/${id}/cancel`, { method: 'POST' }),
  autoRenew: (on: boolean) => apiRequest<{ ok: true; card: IdCard }>('/idcard/auto-renew', { method: 'POST', body: { on } }),
}

export const IdAdminApi = {
  summary: () => apiRequest<IdSummary>('/admin/idcards/summary'),
  orders: (status?: string, q?: string) => apiRequest<{ items: IdOrder[] }>(`/admin/idcards/orders${qs({ status, q })}`),
  order: (id: number) => apiRequest<{ order: IdOrder; card: IdCard | null; balance: number }>(`/admin/idcards/orders/${id}`),
  act: (id: number, body: { action: string; note?: string; reason?: string; fields?: Partial<CardFields> & { photo?: string }; template?: string; pay_method?: string }) =>
    apiRequest<{ order: IdOrder; card: IdCard | null; balance: number }>(`/admin/idcards/orders/${id}`, { method: 'POST', body: body as unknown as Record<string, unknown> }),
  cards: (status?: string, q?: string) => apiRequest<{ items: IdCard[] }>(`/admin/idcards/cards${qs({ status, q })}`),
  card: (id: number) => apiRequest<{ card: IdCard; orders: IdOrder[] }>(`/admin/idcards/cards/${id}`),
  cardAct: (id: number, body: Record<string, unknown>) => apiRequest<{ card: IdCard; orders: IdOrder[] }>(`/admin/idcards/cards/${id}`, { method: 'POST', body }),
  issue: (body: Record<string, unknown>) => apiRequest<{ ok: true; card: IdCard }>('/admin/idcards/issue', { method: 'POST', body }),
  settings: () => apiRequest<{ settings: IdSettings; defaults: IdSettings; methods: Record<string, string>; area_types: Record<string, string> }>('/admin/idcards/settings'),
  saveSettings: (settings: IdSettings) => apiRequest<{ settings: IdSettings }>('/admin/idcards/settings', { method: 'POST', body: { settings } as unknown as Record<string, unknown> }),
  people: (q: string) => apiRequest<{ items: (PersonLite & { card: { card_no: string; status: string; expires_at: string } | null; prefill: CardFields; photo: string })[] }>(`/admin/idcards/people${qs({ q })}`),
}

/** An issued card (or an order's fields) → what the card designs draw. */
export function cardDataOf(c: IdCard, photo?: string): CardData {
  return {
    name_bn: c.fields.name_bn,
    name_en: c.fields.name_en,
    designation: c.fields.designation,
    designation_en: c.fields.designation_en,
    area: c.area,
    card_no: c.card_no,
    issued: c.issued_at,
    expires: c.expires_at,
    blood: c.fields.blood,
    phone: c.fields.phone,
    emergency: c.fields.emergency,
    email: c.fields.email,
    photo: photo ?? c.photo_data ?? '',
    verify_url: c.verify_url,
    status: c.status,
  }
}

/** Area line the way the server writes it (for previews of an application). */
export function areaText(f: Partial<CardFields>): string {
  switch (f.area_type) {
    case 'upazila':
      return [f.upazila, f.district].filter(Boolean).join(', ')
    case 'district':
      return f.district ? `${f.district} জেলা` : ''
    case 'division':
      return f.division ? `${f.division} বিভাগ` : ''
    case 'campus':
      return f.campus ?? ''
    case 'staff':
      return 'প্রধান কার্যালয়'
  }
  return 'জাতীয়'
}

/** The visiting card a journalist gets with their ID card. */
export function visitingOf(c: IdCard, photo?: string): VisitingData {
  return {
    name_bn: c.fields.name_bn,
    name_en: c.fields.name_en,
    designation: c.fields.designation,
    designation_en: c.fields.designation_en,
    phone: c.fields.phone,
    email: c.fields.email,
    area: c.area,
    photo: photo ?? c.photo_data ?? '',
    show_photo: true,
    qr_url: c.verify_url,
    qr_label: 'যাচাই করুন',
  }
}

/** Fills the নিয়োগপত্র (stationery design 'appointment') from an issued card. */
export function appointmentOf(c: IdCard): Record<string, string> {
  return {
    name: c.fields.name_bn,
    designation: c.fields.designation,
    area: c.area,
    card_no: c.card_no,
    join: c.issued_at,
    valid: c.expires_at,
    address: c.fields.address || [c.fields.upazila, c.fields.district].filter(Boolean).join(', '),
  }
}

/** Does this package include a নিয়োগপত্র? */
export const hasLetter = (p?: Pkg | null) => !!p?.items.some((i) => i.includes('নিয়োগ'))

export const bnMoney = (n: number) => `${String(Math.round(n)).replace(/[0-9]/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])} টাকা`
