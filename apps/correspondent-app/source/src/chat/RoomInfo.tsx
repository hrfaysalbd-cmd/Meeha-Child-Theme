import { useEffect, useState } from 'react'
import { ChatApi, type Member, type Room } from './api'
import { Avatar, RoomIcon, Sheet, Spinner, useConfirm } from './bits'
import PeoplePicker from './PeoplePicker'
import { bnNum } from './util'

const ICONS = ['🏠', '🗞️', '📍', '👥', '📸', '🎥', '⚽', '🏛️', '💼', '🚨', '📢', '🗳️', '🌾', '🏥', '🎓', '⚖️']
const MUTES: [number, string][] = [
  [1, '১ ঘণ্টা'],
  [8, '৮ ঘণ্টা'],
  [24, '১ দিন'],
  [168, '১ সপ্তাহ'],
  [-1, 'সবসময়'],
]

interface Props {
  room: Room
  me: { id: number; staff: boolean; publisher: boolean }
  onClose: () => void
  onChanged: (r?: Room) => void
  onLeft: () => void
  onShowPinned: () => void
}

export default function RoomInfo({ room, me, onClose, onChanged, onLeft, onShowPinned }: Props) {
  const [members, setMembers] = useState<Member[] | null>(null)
  const [removed, setRemoved] = useState<Member[]>([])
  const [q, setQ] = useState('')
  const [edit, setEdit] = useState(false)
  const [name, setName] = useState(room.name)
  const [about, setAbout] = useState(room.about)
  const [icon, setIcon] = useState(room.icon)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [adding, setAdding] = useState(false)
  const [showRemoved, setShowRemoved] = useState(false)
  const [rowMenu, setRowMenu] = useState<Member | null>(null)
  const { confirm, node: confirmNode } = useConfirm()
  const isDesk = room.kind === 'desk'
  const admin = room.is_admin && !isDesk

  const load = () =>
    ChatApi.members(room.id)
      .then((r) => {
        setMembers(r.members)
        setRemoved(r.removed)
      })
      .catch((e: Error) => setErr(e.message))
  useEffect(() => {
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [room.id])

  const act = async (fn: () => Promise<unknown>) => {
    setBusy(true)
    setErr('')
    try {
      const r = await fn()
      if (r && typeof r === 'object' && 'members' in r) {
        const mr = r as unknown as { members: Member[]; removed: Member[] }
        setMembers(mr.members)
        setRemoved(mr.removed)
      }
      onChanged()
      return true
    } catch (e) {
      setErr((e as Error).message || 'হলো না, আবার চেষ্টা করুন')
      return false
    } finally {
      setBusy(false)
    }
  }

  const saveEdit = async () => {
    if (await act(() => ChatApi.updateRoom(room.id, { name, about, icon }))) {
      setEdit(false)
      onChanged({ ...room, name, about, icon })
    }
  }

  const list = (members ?? []).filter((m) => !q || m.name.toLowerCase().includes(q.toLowerCase()) || m.area.includes(q))

  return (
    <div className="absolute inset-0 z-[20] flex justify-end" data-testid="room-info">
      <div className="absolute inset-0 bg-black/25" onClick={onClose} />
      <div className="relative w-full sm:w-[380px] h-full bg-white shadow-2xl flex flex-col">
        <div className="flex items-center gap-2 px-3 py-2.5 border-b border-neutral-100">
          <button type="button" onClick={onClose} className="w-9 h-9 rounded-full hover:bg-neutral-100 text-[20px] text-neutral-600" aria-label="বন্ধ">
            ×
          </button>
          <span className="flex-1 text-[15px] font-bold">{isDesk ? 'আলাপের তথ্য' : 'রুমের তথ্য'}</span>
          {admin && !edit && (
            <button type="button" onClick={() => setEdit(true)} className="text-[13px] font-medium text-brand-600 px-2" data-testid="room-edit">
              ✏️ বদলান
            </button>
          )}
        </div>
        <div className="flex-1 overflow-y-auto">
          {/* identity */}
          <div className="px-5 pt-5 pb-4 text-center border-b border-neutral-100">
            {edit ? (
              <div className="space-y-2.5 text-left">
                <div className="flex flex-wrap gap-1">
                  {ICONS.map((i) => (
                    <button key={i} type="button" onClick={() => setIcon(i)} className={`w-9 h-9 rounded-xl text-[18px] ${icon === i ? 'bg-brand-50 ring-2 ring-brand-400' : 'hover:bg-neutral-100'}`}>
                      {i}
                    </button>
                  ))}
                </div>
                <input value={name} onChange={(e) => setName(e.target.value)} className="w-full rounded-xl border border-neutral-300 px-3 py-2 text-[14px]" placeholder="রুমের নাম" />
                <textarea value={about} onChange={(e) => setAbout(e.target.value)} rows={2} className="w-full rounded-xl border border-neutral-300 px-3 py-2 text-[13.5px]" placeholder="রুমটা কীসের জন্য" />
                <div className="flex gap-2 justify-end">
                  <button type="button" onClick={() => setEdit(false)} className="px-3 py-1.5 rounded-lg text-[13px] text-neutral-600">
                    বাতিল
                  </button>
                  <button type="button" disabled={busy || !name.trim()} onClick={saveEdit} className="px-4 py-1.5 rounded-lg bg-brand-600 text-white text-[13px] font-semibold">
                    সংরক্ষণ
                  </button>
                </div>
              </div>
            ) : (
              <>
                <div className="flex justify-center">
                  <RoomIcon icon={room.icon} size={72} />
                </div>
                <div className="mt-2.5 text-[17px] font-bold text-neutral-900">{room.name}</div>
                {room.about && <div className="mt-0.5 text-[13px] text-neutral-500">{room.about}</div>}
                {!isDesk && <div className="mt-1 text-[12px] text-neutral-400">{bnNum(room.members)} জন সদস্য</div>}
                {isDesk && <div className="mt-2 text-[12px] text-neutral-500 bg-neutral-50 rounded-xl px-3 py-2">🔒 এই আলাপ শুধু সংবাদদাতা নিজে এবং ডেস্কের এডিটর ও পাবলিশাররা দেখতে পান।</div>}
              </>
            )}
          </div>

          {err && <div className="mx-4 mt-3 rounded-lg bg-red-50 px-3 py-2 text-[12.5px] text-red-700">{err}</div>}

          {/* my settings */}
          <div className="px-4 py-3 border-b border-neutral-100">
            <div className="text-[12px] font-semibold text-neutral-500 mb-2">🔔 নোটিফিকেশন</div>
            {room.muted ? (
              <div className="flex items-center gap-2">
                <span className="flex-1 text-[13.5px] text-neutral-700">🔕 নিঃশব্দ করা আছে</span>
                <button type="button" onClick={() => act(() => ChatApi.mute(room.id, 0)).then(() => onChanged({ ...room, muted: false }))} className="text-[13px] font-semibold text-brand-600" data-testid="unmute">
                  শব্দ চালু করুন
                </button>
              </div>
            ) : (
              <div className="flex flex-wrap gap-1.5">
                <span className="text-[12.5px] text-neutral-500 mr-1 self-center">নিঃশব্দ করুন:</span>
                {MUTES.map(([h, l]) => (
                  <button key={h} type="button" onClick={() => act(() => ChatApi.mute(room.id, h)).then(() => onChanged({ ...room, muted: true }))} className="rounded-full border border-neutral-300 px-2.5 py-1 text-[12px] text-neutral-700 hover:bg-neutral-50" data-testid={`mute-${h}`}>
                    {l}
                  </button>
                ))}
              </div>
            )}
            <p className="mt-1.5 text-[11.5px] text-neutral-400">জরুরি লেবেলের মেসেজ আর আপনাকে @ দিয়ে ডাকলে নিঃশব্দ রুমেও জানবেন।</p>
            <button type="button" onClick={onShowPinned} className="mt-2.5 text-[13px] font-medium text-neutral-700">
              📌 পিন করা মেসেজগুলো দেখুন
            </button>
          </div>

          {/* admin settings */}
          {admin && (
            <div className="px-4 py-3 border-b border-neutral-100 space-y-2.5">
              <div className="text-[12px] font-semibold text-neutral-500">⚙️ অ্যাডমিন</div>
              <label className="flex items-center gap-3 cursor-pointer">
                <span className="flex-1">
                  <span className="block text-[13.5px] text-neutral-800">শুধু অ্যাডমিন লিখবেন</span>
                  <span className="block text-[11.5px] text-neutral-500">ঘোষণার রুমের মতো; অন্যরা পড়বেন ও প্রতিক্রিয়া দেবেন</span>
                </span>
                <input
                  type="checkbox"
                  className="w-5 h-5 accent-brand-600"
                  checked={room.posting === 'admins'}
                  onChange={(e) => act(() => ChatApi.updateRoom(room.id, { posting: e.target.checked ? 'admins' : 'all' })).then(() => onChanged({ ...room, posting: e.target.checked ? 'admins' : 'all' }))}
                  data-testid="posting-toggle"
                />
              </label>
              {room.kind === 'room' && (
                <button
                  type="button"
                  onClick={async () => {
                    if (!room.archived && !(await confirm('রুমটি আর্কাইভ করবেন? মেসেজগুলো থাকবে, কিন্তু আর লেখা যাবে না।', 'আর্কাইভ', false))) return
                    await act(() => ChatApi.updateRoom(room.id, { archived: !room.archived }))
                    onChanged({ ...room, archived: !room.archived })
                  }}
                  className="text-[13px] font-medium text-neutral-700"
                >
                  🗄️ {room.archived ? 'আর্কাইভ থেকে ফেরান' : 'রুম আর্কাইভ করুন'}
                </button>
              )}
            </div>
          )}

          {/* members */}
          <div className="px-4 pt-3 pb-1 flex items-center gap-2">
            <span className="flex-1 text-[12px] font-semibold text-neutral-500">👥 {isDesk ? 'আলাপে আছেন' : `সদস্য (${bnNum(members?.length ?? room.members)})`}</span>
            {admin && me.staff && (
              <button type="button" onClick={() => setAdding(true)} className="text-[13px] font-semibold text-brand-600" data-testid="add-members">
                + সদস্য যোগ
              </button>
            )}
          </div>
          {(members?.length ?? 0) > 8 && (
            <div className="px-4 pb-1">
              <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="সদস্য খুঁজুন…" className="w-full rounded-xl bg-neutral-100 px-3 py-2 text-[13.5px] outline-none" />
            </div>
          )}
          {!members ? (
            <div className="p-6 flex justify-center">
              <Spinner />
            </div>
          ) : (
            <div className="pb-2" data-testid="member-list">
              {list.map((m) => (
                <div key={m.id} className="flex items-center gap-3 px-4 py-2 hover:bg-neutral-50">
                  <Avatar p={m} size={38} />
                  <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-1.5">
                      <span className="truncate text-[14px] font-medium text-neutral-900">{m.id === me.id ? `${m.name} (আপনি)` : m.name}</span>
                      {m.admin && <span className="shrink-0 rounded bg-emerald-50 text-emerald-700 px-1.5 text-[10.5px] font-semibold">অ্যাডমিন</span>}
                    </span>
                    <span className="block truncate text-[11.5px] text-neutral-500">
                      {m.role}
                      {m.area ? ` · ${m.area}` : ''}
                      {m.online ? ' · অনলাইনে' : ''}
                    </span>
                  </span>
                  {admin && m.id !== me.id && (
                    <button type="button" onClick={() => setRowMenu(m)} className="w-8 h-8 rounded-full hover:bg-neutral-200 text-neutral-500" aria-label="আরও" data-testid={`member-more-${m.id}`}>
                      ⋯
                    </button>
                  )}
                </div>
              ))}
            </div>
          )}

          {admin && removed.length > 0 && (
            <div className="px-4 pb-3">
              <button type="button" onClick={() => setShowRemoved((v) => !v)} className="text-[12.5px] text-neutral-500">
                {showRemoved ? '▾' : '▸'} বাদ দেওয়া ({bnNum(removed.length)})
              </button>
              {showRemoved &&
                removed.map((m) => (
                  <div key={m.id} className="flex items-center gap-3 py-2">
                    <Avatar p={m} size={32} />
                    <span className="flex-1 text-[13px] text-neutral-600">{m.name}</span>
                    <button type="button" disabled={busy} onClick={() => act(() => ChatApi.addMembers(room.id, [m.id]))} className="text-[12.5px] font-semibold text-brand-600">
                      ফিরিয়ে আনুন
                    </button>
                  </div>
                ))}
            </div>
          )}

          {room.kind === 'room' && !room.is_admin && (
            <div className="px-4 py-4 border-t border-neutral-100">
              <button
                type="button"
                onClick={async () => {
                  if (!(await confirm('এই রুম ছেড়ে দেবেন?', 'ছেড়ে দিন'))) return
                  if (await act(() => ChatApi.removeMember(room.id, me.id))) onLeft()
                }}
                className="text-[13.5px] font-medium text-red-600"
              >
                🚪 রুম ছেড়ে দিন
              </button>
            </div>
          )}
        </div>
      </div>

      <Sheet open={!!rowMenu} onClose={() => setRowMenu(null)} title={rowMenu?.name}>
        {rowMenu && (
          <div className="pb-3">
            <button
              type="button"
              onClick={() => {
                const m = rowMenu
                setRowMenu(null)
                void act(() => ChatApi.setAdmin(room.id, m.id, !m.admin))
              }}
              className="w-full text-left px-6 py-3 text-[14.5px] hover:bg-neutral-50"
            >
              {rowMenu.admin ? '⬇️ অ্যাডমিন থেকে সরান' : '⭐ অ্যাডমিন বানান'}
            </button>
            {!(rowMenu.role === 'পাবলিশার' && !me.publisher) && (
              <button
                type="button"
                data-testid="member-remove"
                onClick={async () => {
                  const m = rowMenu
                  setRowMenu(null)
                  if (await confirm(`${m.name}-কে “${room.name}” থেকে বাদ দেবেন?\nপরে চাইলে আবার ফিরিয়ে আনা যাবে।`, 'বাদ দিন')) void act(() => ChatApi.removeMember(room.id, m.id))
                }}
                className="w-full text-left px-6 py-3 text-[14.5px] text-red-600 hover:bg-neutral-50"
              >
                ➖ রুম থেকে বাদ দিন
              </button>
            )}
          </div>
        )}
      </Sheet>

      {adding && (
        <PeoplePicker
          title="সদস্য যোগ করুন"
          multi
          exclude={(members ?? []).map((m) => m.id)}
          onClose={() => setAdding(false)}
          onPick={async (ids) => {
            setAdding(false)
            await act(() => ChatApi.addMembers(room.id, ids))
          }}
        />
      )}
      {confirmNode}
    </div>
  )
}
