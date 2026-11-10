import { useCallback, useEffect, useState } from 'react'
import { ChatApi, type Member, type Task, type TaskInput } from './api'
import { Avatar, Sheet, Spinner, useConfirm } from './bits'
import { bnNum, fullWhen, localToIso } from './util'

/**
 * 1.23.0 — টু-ডু.
 *
 *   <TasksPanel roomId=… />   a room's task list (over the room, like ⓘ)
 *   <MyTasks />               my tasks in every room (from the room list)
 *
 * Shared by the publisher and the correspondent app (src/chat).
 */

export interface TaskDraft {
  title: string
  msg_id?: number
  assignee_id?: number
}

const pad = (n: number) => (n < 10 ? `0${n}` : String(n))
const toLocalInput = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`

function quickTimes(): [string, Date][] {
  const now = new Date()
  const eve = new Date(now)
  eve.setHours(18, 0, 0, 0)
  const tmr = new Date(now)
  tmr.setDate(now.getDate() + 1)
  tmr.setHours(10, 0, 0, 0)
  const inTwo = new Date(now.getTime() + 2 * 3600_000)
  inTwo.setMinutes(Math.ceil(inTwo.getMinutes() / 15) * 15, 0, 0)
  const out: [string, Date][] = [['২ ঘণ্টার মধ্যে', inTwo]]
  if (eve.getTime() - now.getTime() > 3600_000) out.push(['আজ সন্ধ্যা ৬টা', eve])
  out.push(['কাল সকাল ১০টা', tmr])
  return out
}

/** "আজ, ৬:০০ PM পর্যন্ত" / red when late. */
export function DueChip({ t, small = false }: { t: Task; small?: boolean }) {
  if (!t.due_at) return null
  const ms = new Date(t.due_at).getTime() - Date.now()
  const tone = t.done ? 'bg-neutral-100 text-neutral-500' : t.late ? 'bg-red-100 text-red-700' : ms < 24 * 3600_000 ? 'bg-amber-100 text-amber-800' : 'bg-neutral-100 text-neutral-600'
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-[1px] ${small ? 'text-[10.5px]' : 'text-[11.5px]'} font-medium whitespace-nowrap ${tone}`}>
      {t.late && !t.done ? '⚠️ সময় পেরিয়েছে ·' : '⏰'} {fullWhen(t.due_at)}
    </span>
  )
}

function TaskRow({ t, showRoom, onToggle, onMore, onOpen }: { t: Task; showRoom?: boolean; onToggle: () => void; onMore?: () => void; onOpen?: () => void }) {
  return (
    <div className={`flex items-start gap-3 px-4 py-2.5 ${t.urgent && !t.done ? 'bg-red-50/60' : ''}`} data-testid={`task-${t.id}`}>
      <button
        type="button"
        disabled={!t.can_done}
        onClick={onToggle}
        className={`mt-0.5 w-6 h-6 shrink-0 rounded-full border-2 flex items-center justify-center text-[13px] transition ${t.done ? 'bg-emerald-500 border-emerald-500 text-white' : t.can_done ? 'border-neutral-300 hover:border-emerald-500' : 'border-neutral-200 bg-neutral-50'}`}
        aria-label={t.done ? 'আবার খুলুন' : 'শেষ হয়েছে'}
        title={t.can_done ? (t.done ? 'আবার খুলুন' : 'শেষ হয়েছে') : 'যাঁর কাজ বা যিনি দিয়েছেন, তিনি চিহ্ন দেবেন'}
        data-testid={`task-check-${t.id}`}
      >
        {t.done ? '✓' : ''}
      </button>
      <button type="button" onClick={onOpen} disabled={!onOpen} className="min-w-0 flex-1 text-left">
        <span className={`block text-[14px] leading-snug ${t.done ? 'line-through text-neutral-400' : 'text-neutral-900 font-medium'}`}>
          {t.urgent && !t.done && '🚨 '}
          {t.title}
        </span>
        <span className="mt-1 flex flex-wrap items-center gap-1.5 text-[11.5px] text-neutral-500">
          {t.assignee ? (
            <span className="inline-flex items-center gap-1">
              <Avatar p={t.assignee} size={16} />
              {t.mine ? 'আপনি' : t.assignee.name}
            </span>
          ) : (
            <span>👥 যে কেউ</span>
          )}
          <DueChip t={t} small />
          {showRoom && t.room && (
            <span className="truncate">
              · {t.room.icon} {t.room.name}
            </span>
          )}
          {t.done && t.done_by && <span>· শেষ করেছেন {t.done_by}</span>}
        </span>
      </button>
      {onMore && t.can_edit && (
        <button type="button" onClick={onMore} className="w-8 h-8 -mr-1 rounded-full hover:bg-neutral-200 text-neutral-500 shrink-0" aria-label="আরও" data-testid={`task-more-${t.id}`}>
          ⋯
        </button>
      )}
    </div>
  )
}

/** Add / change a task. */
function TaskForm({
  members,
  meId,
  initial,
  onSave,
  onClose,
  title,
}: {
  members: Member[]
  meId: number
  initial: TaskInput & { title: string }
  onSave: (x: TaskInput) => Promise<boolean>
  onClose: () => void
  title: string
}) {
  const [text, setText] = useState(initial.title)
  const [who, setWho] = useState<number>(initial.assignee_id ?? 0)
  const [due, setDue] = useState(initial.due_at ? toLocalInput(new Date(initial.due_at)) : '')
  const [urgent, setUrgent] = useState(!!initial.urgent)
  const [busy, setBusy] = useState(false)
  const people = [...members].filter((m) => !m.bot).sort((a, b) => (a.id === meId ? -1 : b.id === meId ? 1 : a.name.localeCompare(b.name)))
  const save = async () => {
    setBusy(true)
    const ok = await onSave({ title: text.trim(), assignee_id: who, due_at: due ? localToIso(due) : null, urgent })
    setBusy(false)
    if (ok) onClose()
  }
  return (
    <Sheet open onClose={onClose} title={title} wide>
      <div className="px-5 pb-5 space-y-3" data-testid="task-form">
        <textarea autoFocus value={text} onChange={(e) => setText(e.target.value)} rows={2} placeholder="কী করতে হবে? (যেমন: বন্যার ছবি ও ভিডিও পাঠান)" className="w-full rounded-xl border border-neutral-300 px-3 py-2.5 text-[14px]" data-testid="task-title" />
        <div>
          <div className="text-[12px] font-semibold text-neutral-500 mb-1.5">👤 কে করবেন</div>
          <select value={who} onChange={(e) => setWho(Number(e.target.value))} className="w-full rounded-xl border border-neutral-300 px-3 py-2.5 text-[14px] bg-white" data-testid="task-who">
            <option value={0}>👥 রুমের যে কেউ</option>
            {people.map((m) => (
              <option key={m.id} value={m.id}>
                {m.id === meId ? `${m.name} (আমি)` : m.name}
                {m.area ? ` · ${m.area}` : ''}
              </option>
            ))}
          </select>
        </div>
        <div>
          <div className="text-[12px] font-semibold text-neutral-500 mb-1.5">⏰ কখন পর্যন্ত</div>
          <div className="flex flex-wrap gap-1.5 mb-2">
            {quickTimes().map(([l, d]) => (
              <button key={l} type="button" onClick={() => setDue(toLocalInput(d))} className={`rounded-full border px-2.5 py-1 text-[12px] ${due === toLocalInput(d) ? 'bg-neutral-900 text-white border-neutral-900' : 'border-neutral-300 text-neutral-700'}`}>
                {l}
              </button>
            ))}
            {due && (
              <button type="button" onClick={() => setDue('')} className="rounded-full border border-neutral-300 px-2.5 py-1 text-[12px] text-neutral-500">
                সময় ছাড়া
              </button>
            )}
          </div>
          <input type="datetime-local" value={due} onChange={(e) => setDue(e.target.value)} className="w-full rounded-xl border border-neutral-300 px-3 py-2 text-[13.5px]" data-testid="task-due" />
          <p className="mt-1 text-[11.5px] text-neutral-400">সময়ের এক ঘণ্টা আগে মনে করিয়ে দেওয়া হবে; পেরিয়ে গেলে রুমে জানানো হবে।</p>
        </div>
        <label className="flex items-center gap-2 text-[13.5px] text-neutral-700">
          <input type="checkbox" className="w-4 h-4 accent-red-600" checked={urgent} onChange={(e) => setUrgent(e.target.checked)} />
          🚨 জরুরি কাজ (নিঃশব্দ থাকলেও নোটিফিকেশন যাবে)
        </label>
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 rounded-xl text-[13.5px] text-neutral-600">
            বাতিল
          </button>
          <button type="button" disabled={!text.trim() || busy} onClick={save} className="px-5 py-2 rounded-xl bg-brand-600 disabled:bg-neutral-300 text-white text-[14px] font-semibold" data-testid="task-save">
            {busy ? 'রাখা হচ্ছে…' : 'কাজ রাখুন'}
          </button>
        </div>
      </div>
    </Sheet>
  )
}

/** One room's টু-ডু, over the room. */
export function TasksPanel({
  roomId,
  roomName,
  members,
  meId,
  draft,
  onClose,
  onChanged,
  onJump,
}: {
  roomId: number
  roomName: string
  members: Member[]
  meId: number
  draft?: TaskDraft | null
  onClose: () => void
  onChanged: () => void
  onJump: (msgId: number) => void
}) {
  const [open, setOpen] = useState<Task[] | null>(null)
  const [done, setDone] = useState<Task[]>([])
  const [canAdd, setCanAdd] = useState(false)
  const [err, setErr] = useState('')
  const [form, setForm] = useState<{ edit?: Task; draft?: TaskDraft } | null>(draft ? { draft } : null)
  const [showDone, setShowDone] = useState(false)
  const [more, setMore] = useState<Task | null>(null)
  const [filter, setFilter] = useState<'all' | 'mine'>('all')
  const { confirm, node: confirmNode } = useConfirm()

  const load = useCallback(
    () =>
      ChatApi.tasks(roomId)
        .then((r) => {
          setOpen(r.open)
          setDone(r.done)
          setCanAdd(r.can_add)
        })
        .catch((e: Error) => setErr(e.message)),
    [roomId],
  )
  useEffect(() => {
    void load()
  }, [load])

  const run = async (fn: () => Promise<unknown>) => {
    setErr('')
    try {
      await fn()
      await load()
      onChanged()
      return true
    } catch (e) {
      setErr((e as Error).message || 'হলো না, আবার চেষ্টা করুন')
      return false
    }
  }

  const toggle = (t: Task) => {
    // feels instant; the list reloads after
    setOpen((l) => l?.map((x) => (x.id === t.id ? { ...x, done: !t.done } : x)) ?? l)
    void run(() => ChatApi.updateTask(t.id, { done: !t.done }))
  }

  const list = (open ?? []).filter((t) => filter === 'all' || t.mine)
  const mineN = (open ?? []).filter((t) => t.mine).length

  return (
    <div className="absolute inset-0 z-[20] flex justify-end" data-testid="tasks-panel">
      <div className="absolute inset-0 bg-black/25" onClick={onClose} />
      <div className="relative w-full sm:w-[400px] h-full bg-white shadow-2xl flex flex-col">
        <div className="flex items-center gap-2 px-3 py-2.5 border-b border-neutral-100">
          <button type="button" onClick={onClose} className="w-9 h-9 rounded-full hover:bg-neutral-100 text-[20px] text-neutral-600" aria-label="বন্ধ">
            ×
          </button>
          <span className="min-w-0 flex-1">
            <span className="block text-[15px] font-bold">✅ টু-ডু</span>
            <span className="block truncate text-[11.5px] text-neutral-500">{roomName}</span>
          </span>
          {canAdd && (
            <button type="button" onClick={() => setForm({})} className="h-9 px-3 rounded-xl bg-brand-600 text-white text-[13px] font-semibold" data-testid="task-add">
              + কাজ
            </button>
          )}
        </div>
        <div className="flex gap-1.5 px-4 pt-3 pb-1">
          {(
            [
              ['all', `সব (${bnNum(open?.length ?? 0)})`],
              ['mine', `আমার (${bnNum(mineN)})`],
            ] as const
          ).map(([k, l]) => (
            <button key={k} type="button" onClick={() => setFilter(k)} className={`rounded-full px-3 py-1 text-[12.5px] font-medium ${filter === k ? 'bg-neutral-900 text-white' : 'bg-neutral-100 text-neutral-600'}`}>
              {l}
            </button>
          ))}
        </div>
        {err && <div className="mx-4 mt-2 rounded-lg bg-red-50 px-3 py-2 text-[12.5px] text-red-700">{err}</div>}
        <div className="flex-1 overflow-y-auto pb-4">
          {!open ? (
            <div className="p-8 flex justify-center">
              <Spinner />
            </div>
          ) : list.length === 0 ? (
            <div className="px-8 py-10 text-center">
              <div className="text-[38px]">🎯</div>
              <p className="mt-1 text-[14px] font-semibold text-neutral-800">{filter === 'mine' ? 'আপনার কোনো কাজ বাকি নেই' : 'কোনো কাজ বাকি নেই'}</p>
              <p className="mt-1 text-[12.5px] text-neutral-500">“+ কাজ” চাপুন, অথবা মেসেজে “কাজ” লেবেল দিয়ে কাউকে @ দিয়ে লিখুন। কাজটা নিজে থেকেই এখানে চলে আসবে।</p>
            </div>
          ) : (
            <div className="divide-y divide-neutral-100">
              {list.map((t) => (
                <TaskRow key={t.id} t={t} onToggle={() => toggle(t)} onMore={() => setMore(t)} onOpen={t.msg_id ? () => onJump(t.msg_id) : undefined} />
              ))}
            </div>
          )}
          {done.length > 0 && (
            <div className="mt-2">
              <button type="button" onClick={() => setShowDone((v) => !v)} className="px-4 py-2 text-[12.5px] text-neutral-500">
                {showDone ? '▾' : '▸'} শেষ হয়েছে ({bnNum(done.length)})
              </button>
              {showDone && (
                <div className="divide-y divide-neutral-100">
                  {done.map((t) => (
                    <TaskRow key={t.id} t={t} onToggle={() => void run(() => ChatApi.updateTask(t.id, { done: false }))} onMore={() => setMore(t)} />
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      <Sheet open={!!more} onClose={() => setMore(null)} title={more?.title}>
        {more && (
          <div className="pb-3">
            <div className="px-6 pb-2 text-[12px] text-neutral-500">
              দিয়েছেন {more.by.name} · {fullWhen(more.created_at)}
            </div>
            <button type="button" onClick={() => (setForm({ edit: more }), setMore(null))} className="w-full text-left px-6 py-3 text-[14.5px] hover:bg-neutral-50">
              ✏️ বদলান (কাজ, কে করবেন, সময়)
            </button>
            {more.msg_id > 0 && (
              <button type="button" onClick={() => (onJump(more.msg_id), setMore(null))} className="w-full text-left px-6 py-3 text-[14.5px] hover:bg-neutral-50">
                💬 মূল মেসেজটি দেখুন
              </button>
            )}
            <button
              type="button"
              data-testid="task-delete"
              onClick={async () => {
                const t = more
                setMore(null)
                if (await confirm(`“${t.title}” কাজটি মুছবেন?`, 'মুছুন')) void run(() => ChatApi.deleteTask(t.id))
              }}
              className="w-full text-left px-6 py-3 text-[14.5px] text-red-600 hover:bg-neutral-50"
            >
              🗑️ কাজটি মুছুন
            </button>
          </div>
        )}
      </Sheet>

      {form && (
        <TaskForm
          title={form.edit ? 'কাজ বদলান' : 'নতুন কাজ'}
          members={members}
          meId={meId}
          initial={
            form.edit
              ? { title: form.edit.title, assignee_id: form.edit.assignee?.id ?? 0, due_at: form.edit.due_at, urgent: form.edit.urgent }
              : { title: form.draft?.title ?? '', assignee_id: form.draft?.assignee_id ?? 0 }
          }
          onClose={() => setForm(null)}
          onSave={(x) => run(() => (form.edit ? ChatApi.updateTask(form.edit.id, x) : ChatApi.addTask(roomId, { ...x, msg_id: form.draft?.msg_id })))}
        />
      )}
      {confirmNode}
    </div>
  )
}

/** My tasks in every room. */
export function MyTasks({ onClose, onOpenRoom }: { onClose: () => void; onOpenRoom: (roomId: number) => void }) {
  const [scope, setScope] = useState<'mine' | 'made'>('mine')
  const [data, setData] = useState<{ open: Task[]; done: Task[] } | null>(null)
  const [err, setErr] = useState('')
  const [showDone, setShowDone] = useState(false)
  const load = useCallback(() => {
    ChatApi.myTasks(scope)
      .then((r) => setData(r))
      .catch((e: Error) => setErr(e.message))
  }, [scope])
  useEffect(() => {
    setData(null)
    load()
  }, [load])
  const toggle = async (t: Task) => {
    try {
      await ChatApi.updateTask(t.id, { done: !t.done })
      load()
    } catch (e) {
      setErr((e as Error).message)
    }
  }
  return (
    <Sheet open onClose={onClose} title="✅ আমার কাজ" wide>
      <div className="px-5 pb-2 flex gap-1.5" data-testid="my-tasks">
        {(
          [
            ['mine', 'আমাকে দেওয়া'],
            ['made', 'আমি যা দিয়েছি'],
          ] as const
        ).map(([k, l]) => (
          <button key={k} type="button" onClick={() => setScope(k)} className={`rounded-full px-3 py-1 text-[12.5px] font-medium ${scope === k ? 'bg-neutral-900 text-white' : 'bg-neutral-100 text-neutral-600'}`}>
            {l}
          </button>
        ))}
      </div>
      {err && <div className="mx-5 mb-2 rounded-lg bg-red-50 px-3 py-2 text-[12.5px] text-red-700">{err}</div>}
      <div className="max-h-[62vh] overflow-y-auto pb-3">
        {!data ? (
          <div className="p-8 flex justify-center">
            <Spinner />
          </div>
        ) : data.open.length === 0 ? (
          <div className="px-8 py-8 text-center">
            <div className="text-[38px]">🎉</div>
            <p className="mt-1 text-[14px] font-semibold text-neutral-800">কোনো কাজ বাকি নেই</p>
            <p className="mt-1 text-[12.5px] text-neutral-500">রুমে কেউ আপনাকে কাজ দিলে এখানে দেখাবে, আর সময়ের আগে মনে করিয়ে দেওয়া হবে।</p>
          </div>
        ) : (
          <div className="divide-y divide-neutral-100">
            {data.open.map((t) => (
              <TaskRow key={t.id} t={t} showRoom onToggle={() => void toggle(t)} onOpen={() => onOpenRoom(t.room_id)} />
            ))}
          </div>
        )}
        {data && data.done.length > 0 && (
          <>
            <button type="button" onClick={() => setShowDone((v) => !v)} className="px-5 py-2 text-[12.5px] text-neutral-500">
              {showDone ? '▾' : '▸'} শেষ হয়েছে ({bnNum(data.done.length)})
            </button>
            {showDone && (
              <div className="divide-y divide-neutral-100">
                {data.done.map((t) => (
                  <TaskRow key={t.id} t={t} showRoom onToggle={() => void toggle(t)} onOpen={() => onOpenRoom(t.room_id)} />
                ))}
              </div>
            )}
          </>
        )}
      </div>
    </Sheet>
  )
}
