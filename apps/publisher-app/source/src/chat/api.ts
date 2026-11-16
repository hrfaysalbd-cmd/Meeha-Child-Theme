/**
 * r36 — চ্যাট বক্স: talks to /chat/* on newsroom-os 1.19.0.
 *
 * The same folder (src/chat) is used by the publisher and the correspondent
 * app; both export `apiRequest` from ../lib/api.
 */
import { apiRequest } from '../lib/api'

/** 'dm' (1.26.0) — one Editor/Publisher to another, person to person */
export type RoomKind = 'family' | 'staff' | 'area' | 'desk' | 'room' | 'dm'
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
  /** মিহা সহকারী (automatic messages) */
  bot?: boolean
}

/** 1.23.0 — টু-ডু */
export interface Task {
  id: number
  room_id: number
  msg_id: number
  title: string
  assignee: Person | null
  by: Person
  urgent: boolean
  due_at: string | null
  late: boolean
  done: boolean
  done_at: string | null
  done_by: string
  mine: boolean
  can_done: boolean
  can_edit: boolean
  created_at: string
  room?: { id: number; name: string; icon: string }
}

export interface TaskInput {
  title?: string
  assignee_id?: number
  due_at?: string | null
  urgent?: boolean
  msg_id?: number
  done?: boolean
}

export interface TaskCount {
  open: number
  late: number
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
  /** open tasks in the room (1.23.0) */
  tasks?: number
  /** a desk line, seen by the desk: the correspondent (1.24.0) */
  peer?: Person | null
}

/** 1.24.0 — message templates */
export interface ChatTemplate {
  id: number
  key: string
  title: string
  body: string
  cat: string
  kind: string
  audience: 'staff' | 'correspondent' | 'all' | 'auto'
  label: LabelKey | ''
  personal: boolean
  builtin: boolean
  active: boolean
  uses: number
  can_edit: boolean
  changed: boolean
}

export interface TemplateCat {
  key: string
  name: string
  icon: string
}

export interface TemplatesResponse {
  templates: ChatTemplate[]
  cats: TemplateCat[]
  vars: string[]
  can_manage: boolean
  me: { name: string; area: string }
}

export interface TemplateInput {
  title?: string
  body?: string
  cat?: string
  label?: string
  audience?: string
  active?: boolean
  shared?: boolean
}

/** 1.24.0 — a news for “/” */
export interface NewsItem {
  id: number
  title: string
  url: string
  status: string
  status_l: string
  type: 'post' | 'video'
  date: string
  author: string
  author_id: number
  thumb: string
  fb: string
  share: string
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
  bot?: boolean
  tasks?: Task[]
  at: string
}

export interface RoomsResponse {
  rooms: Room[]
  labels: ChatLabel[]
  reactions: string[]
  me: { id: number; staff: boolean; publisher: boolean }
  my_tasks?: TaskCount
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
  tasks?: TaskCount | null
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
  due_at?: string
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
  syncRoom: (room: number) => apiRequest<MembersResponse & { added: number }>(`/chat/rooms/${room}/sync`, { method: 'POST', body: {} }),
  tasks: (room: number) => apiRequest<{ open: Task[]; done: Task[]; can_add: boolean }>(`/chat/rooms/${room}/tasks`),
  myTasks: (scope: 'mine' | 'made' = 'mine') => apiRequest<{ open: Task[]; done: Task[]; count: TaskCount }>(`/chat/tasks${qs({ scope: scope === 'made' ? 'made' : '' })}`),
  addTask: (room: number, input: TaskInput) => apiRequest<Task>(`/chat/rooms/${room}/tasks`, { method: 'POST', body: input as Record<string, unknown> }),
  updateTask: (id: number, input: TaskInput) => apiRequest<Task>(`/chat/tasks/${id}`, { method: 'PATCH', body: input as Record<string, unknown> }),
  deleteTask: (id: number) => apiRequest<{ deleted: true; id: number }>(`/chat/tasks/${id}`, { method: 'DELETE' }),
  templates: (manage = false) => apiRequest<TemplatesResponse>(`/chat/templates${manage ? '?manage=1' : ''}`),
  createTemplate: (input: TemplateInput) => apiRequest<ChatTemplate>('/chat/templates', { method: 'POST', body: input as Record<string, unknown> }),
  updateTemplate: (id: number, input: TemplateInput) => apiRequest<ChatTemplate>(`/chat/templates/${id}`, { method: 'PATCH', body: input as Record<string, unknown> }),
  deleteTemplate: (id: number) => apiRequest<{ id: number; deleted?: boolean; off?: boolean }>(`/chat/templates/${id}`, { method: 'DELETE' }),
  resetTemplate: (id: number) => apiRequest<ChatTemplate>(`/chat/templates/${id}/reset`, { method: 'POST', body: {} }),
  templateUsed: (id: number) => apiRequest<{ ok: true }>(`/chat/templates/${id}/used`, { method: 'POST', body: {}, retry: true }),
  posts: (room: number, q = '') => apiRequest<{ posts: NewsItem[]; focus: Person | null }>(`/chat/posts${qs({ room, q })}`),
  setFb: (postId: number, url: string) => apiRequest<NewsItem>(`/chat/posts/${postId}/fb`, { method: 'POST', body: { url } }),
  openDesk: (user_id?: number) => apiRequest<{ id: number }>('/chat/desk', { method: 'POST', body: user_id ? { user_id } : {} }),
  /** staff=true → only Editors/Publishers (1.26.0) */
  people: (q: string, staff = false) => apiRequest<{ people: Person[] }>(`/chat/people${qs({ q, staff: staff ? 1 : undefined })}`),
  /** ব্যক্তিগত মেসেজ — Editor/Publisher ↔ Editor/Publisher (1.26.0) */
  openDm: (user_id: number) => apiRequest<{ id: number }>('/chat/dm', { method: 'POST', body: { user_id } }),
  upload: (room: number, file: Blob, name: string, duration = 0) => {
    const fd = new FormData()
    fd.append('file', file, name)
    fd.append('room_id', String(room))
    if (duration) fd.append('duration', String(Math.round(duration)))
    return apiRequest<ChatFile>('/chat/files', { method: 'POST', body: fd, timeoutMs: 180_000 })
  },
}
