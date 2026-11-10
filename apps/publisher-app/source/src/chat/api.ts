/**
 * r36 — চ্যাট বক্স: talks to /chat/* on newsroom-os 1.19.0.
 *
 * The same folder (src/chat) is used by the publisher and the correspondent
 * app; both export `apiRequest` from ../lib/api.
 */
import { apiRequest } from '../lib/api'

export type RoomKind = 'family' | 'staff' | 'area' | 'desk' | 'room'
export type LabelKey = 'urgent' | 'meeting' | 'notice' | 'task' | 'info' | 'general'

export interface ChatLabel {
  key: LabelKey
  name: string
  color: string
}

export interface Person {
  id: number
  name: string
  avatar: string
  role: string
  area: string
  online: boolean
}

export interface Member extends Person {
  admin: boolean
}

export interface RoomLast {
  id: number
  text: string
  label: string
  author: string
  at: string | null
}

export interface Room {
  id: number
  kind: RoomKind
  name: string
  about: string
  icon: string
  avatar: string
  online: boolean
  posting: 'all' | 'admins'
  archived: boolean
  members: number
  is_admin: boolean
  can_post: boolean
  muted: boolean
  muted_until: string | null
  unread: number
  mentions: number
  urgent: number
  last: RoomLast | null
  last_at: string | null
}

export interface ChatFile {
  id: number
  name: string
  kind: 'image' | 'audio' | 'video' | 'file'
  mime: string
  size: number
  duration: number
  url: string
  download: string
}

export interface Reaction {
  emoji: string
  count: number
  mine: boolean
  names: string[]
}

export interface Message {
  id: number
  kind: 'text' | 'system'
  author: Person | null
  mine: boolean
  body: string
  label: LabelKey | ''
  meeting_at: string | null
  reply: { id: number; author: string; text: string } | null
  files: ChatFile[]
  reactions: Reaction[]
  pinned: boolean
  mentions_me: boolean
  edited: boolean
  deleted: boolean
  can_edit: boolean
  can_delete: boolean
  can_purge: boolean
  at: string
}

export interface RoomsResponse {
  rooms: Room[]
  labels: ChatLabel[]
  reactions: string[]
  me: { id: number; staff: boolean; publisher: boolean }
}

export interface MessagesResponse {
  room: Room
  messages: Message[]
  changed: Message[]
  gone: number[]
  has_more: boolean
  seen: { id: number; read: number }[]
  typing: string[]
  pinned: number
  now: number
}

export interface ChatSummary {
  unread: number
  mentions: number
  urgent: number
  latest_id: number
  latest: {
    id: number
    room_id: number
    room: string
    icon: string
    author: string
    text: string
    label: string
    mention: boolean
  } | null
}

export interface MembersResponse {
  members: Member[]
  removed: Member[]
  is_admin: boolean
}

export interface SendInput {
  body: string
  label?: LabelKey | ''
  reply_to?: number
  file_ids?: number[]
  mentions?: number[]
  meeting_at?: string
}

export interface MessageQuery {
  after?: number
  before?: number
  changed_since?: number
  label?: string
  q?: string
  pinned?: boolean
}

function qs(o: Record<string, unknown>) {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(o)) if (v !== undefined && v !== '' && v !== false && v !== 0) p.set(k, v === true ? '1' : String(v))
  const s = p.toString()
  return s ? `?${s}` : ''
}

export const ChatApi = {
  summary: () => apiRequest<ChatSummary>('/chat/summary'),
  rooms: () => apiRequest<RoomsResponse>('/chat/rooms'),
  messages: (room: number, q: MessageQuery = {}) => apiRequest<MessagesResponse>(`/chat/rooms/${room}/messages${qs(q as Record<string, unknown>)}`),
  send: (room: number, input: SendInput) => apiRequest<Message>(`/chat/rooms/${room}/messages`, { method: 'POST', body: input as unknown as Record<string, unknown> }),
  edit: (id: number, body: string, label?: string) => apiRequest<Message>(`/chat/messages/${id}`, { method: 'PATCH', body: label === undefined ? { body } : { body, label } }),
  remove: (id: number, purge = false) => apiRequest<Message | { purged: true; id: number }>(`/chat/messages/${id}${purge ? '?purge=1' : ''}`, { method: 'DELETE' }),
  react: (id: number, emoji: string) => apiRequest<Message>(`/chat/messages/${id}/react`, { method: 'POST', body: { emoji } }),
  pin: (id: number) => apiRequest<Message>(`/chat/messages/${id}/pin`, { method: 'POST', body: {} }),
  read: (room: number, last_id: number) => apiRequest<{ ok: true }>(`/chat/rooms/${room}/read`, { method: 'POST', body: { last_id }, retry: true }),
  typing: (room: number) => apiRequest<{ ok: true }>(`/chat/rooms/${room}/typing`, { method: 'POST', body: {}, retry: true }),
  mute: (room: number, hours: number) => apiRequest<{ ok: true; muted: boolean }>(`/chat/rooms/${room}/mute`, { method: 'POST', body: { hours } }),
  members: (room: number) => apiRequest<MembersResponse>(`/chat/rooms/${room}/members`),
  addMembers: (room: number, user_ids: number[]) => apiRequest<MembersResponse>(`/chat/rooms/${room}/members`, { method: 'POST', body: { user_ids } }),
  setAdmin: (room: number, uid: number, admin: boolean) => apiRequest<MembersResponse>(`/chat/rooms/${room}/members/${uid}`, { method: 'PATCH', body: { admin } }),
  removeMember: (room: number, uid: number) => apiRequest<MembersResponse | { left: true }>(`/chat/rooms/${room}/members/${uid}`, { method: 'DELETE' }),
  createRoom: (input: { name: string; about?: string; icon?: string; member_ids?: number[]; posting?: 'all' | 'admins' }) =>
    apiRequest<{ id: number }>('/chat/rooms', { method: 'POST', body: input }),
  updateRoom: (room: number, input: Partial<{ name: string; about: string; icon: string; posting: 'all' | 'admins'; archived: boolean }>) =>
    apiRequest<{ ok: true }>(`/chat/rooms/${room}`, { method: 'PATCH', body: input }),
  openDesk: (user_id?: number) => apiRequest<{ id: number }>('/chat/desk', { method: 'POST', body: user_id ? { user_id } : {} }),
  people: (q: string) => apiRequest<{ people: Person[] }>(`/chat/people${qs({ q })}`),
  upload: (room: number, file: Blob, name: string, duration = 0) => {
    const fd = new FormData()
    fd.append('file', file, name)
    fd.append('room_id', String(room))
    if (duration) fd.append('duration', String(Math.round(duration)))
    return apiRequest<ChatFile>('/chat/files', { method: 'POST', body: fd, timeoutMs: 180_000 })
  },
}
