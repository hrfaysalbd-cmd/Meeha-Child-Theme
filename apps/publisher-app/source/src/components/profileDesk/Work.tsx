// r31 — প্রোফাইল ডেস্কের কাজ: ডেস্ক হোম, কাজের তালিকা (to-do), ঘটনাপঞ্জি,
// সম্পর্ক, "মিহায় প্রকাশিত" আর প্রোফাইল কতটা পূর্ণ।
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ProfileDeskApi,
  bn,
  bnDate,
  errText,
  type PdCompleteness,
  type PdCoverage,
  type PdEntity,
  type PdEvent,
  type PdHome,
  type PdMeta,
  type PdRef,
  type PdRelation,
  type PdTask,
} from '../../lib/profileDesk'
import { Banner, Button, Card, Spinner, inputClass } from '../ui'
import { IconCheck, IconPencil, IconPlus, IconTrash } from '../Icon'
import { Avatar, LinkChips, Overlay } from './bits'
import { EntityPicker } from './EntityForm'
import { IconCalendarEvent, IconChecklist, IconLink, IconNews, IconPin, IconRelation } from './icons'

// ---------------------------------------------------------------------------
// small helpers
// ---------------------------------------------------------------------------

const pad = (n: number) => String(n).padStart(2, '0')
const localDay = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
export const todayStr = () => localDay(new Date())

/** Quick due dates for a task. */
function duePresets() {
  const now = new Date()
  const tom = new Date(now.getTime() + 86400000)
  const fri = new Date(now)
  // end of this week = coming Thursday (Bangladesh week ends Thursday/Friday)
  fri.setDate(now.getDate() + ((4 - now.getDay() + 7) % 7 || 7))
  const inHour = new Date(now.getTime() + 3600000)
  return [
    { label: '১ ঘণ্টায়', v: `${localDay(inHour)}T${pad(inHour.getHours())}:${pad(inHour.getMinutes())}` },
    { label: 'আজ', v: `${localDay(now)}T23:59` },
    { label: 'কাল', v: `${localDay(tom)}T23:59` },
    { label: 'এই সপ্তাহে', v: `${localDay(fri)}T23:59` },
  ]
}

const PRIORITY: Record<number, { label: string; tone: string }> = {
  3: { label: 'জরুরি', tone: 'bg-red-50 text-red-600' },
  2: { label: 'সাধারণ', tone: 'bg-neutral-100 text-neutral-600' },
  1: { label: 'পরে হলেও চলবে', tone: 'bg-neutral-50 text-neutral-400' },
}

export const EVENT_TONE: Record<string, string> = {
  arrest: 'bg-red-500',
  case: 'bg-orange-500',
  verdict: 'bg-amber-500',
  position: 'bg-blue-600',
  statement: 'bg-neutral-500',
  rally: 'bg-emerald-600',
  meeting: 'bg-indigo-500',
  media: 'bg-cyan-600',
  travel: 'bg-violet-500',
  contact: 'bg-pink-500',
  note: 'bg-neutral-400',
  other: 'bg-neutral-300',
}

export const kindLabel = (list: { key: string; label: string }[] | undefined, k: string) => list?.find((x) => x.key === k)?.label ?? k

function hostOf(u: string) {
  try {
    return new URL(u).hostname.replace(/^www\./, '')
  } catch {
    return u
  }
}

// ---------------------------------------------------------------------------
// completeness
// ---------------------------------------------------------------------------

export function CompletenessBar({ c, onFix }: { c: PdCompleteness; onFix?: () => void }) {
  const tone = c.score >= 80 ? 'bg-emerald-500' : c.score >= 50 ? 'bg-amber-400' : 'bg-red-400'
  return (
    <div className="rounded-xl border border-neutral-200 px-3 py-2.5">
      <div className="flex items-center justify-between text-[12.5px]">
        <span className="font-semibold text-neutral-700">প্রোফাইল {bn(c.score)}% পূর্ণ</span>
        {onFix && c.missing.length > 0 && (
          <button type="button" onClick={onFix} className="text-brand-600 font-semibold hover:underline">
            পূরণ করুন
          </button>
        )}
      </div>
      <div className="mt-1.5 h-1.5 rounded-full bg-neutral-100 overflow-hidden">
        <div className={`h-full ${tone} rounded-full transition-all`} style={{ width: `${c.score}%` }} />
      </div>
      {c.missing.length > 0 && <p className="mt-1.5 text-[11.5px] text-neutral-500">বাকি: {c.missing.map((m) => m.label).join(' · ')}</p>}
    </div>
  )
}

export function ScoreDot({ score }: { score?: number }) {
  if (score === undefined) return null
  const tone = score >= 80 ? 'text-emerald-600' : score >= 50 ? 'text-amber-600' : 'text-red-500'
  return (
    <span className={`text-[11px] font-semibold ${tone}`} title="প্রোফাইল কতটা পূর্ণ">
      {bn(score)}%
    </span>
  )
}

// ---------------------------------------------------------------------------
// tasks (to-do)
// ---------------------------------------------------------------------------

export function TaskForm({
  meta,
  task,
  entity,
  sourceUrl,
  defaultTitle,
  onClose,
  onSaved,
}: {
  meta: PdMeta
  task?: PdTask
  entity?: PdRef | null
  sourceUrl?: string
  defaultTitle?: string
  onClose: () => void
  onSaved: (t: PdTask) => void
}) {
  const [title, setTitle] = useState(task?.title ?? defaultTitle ?? '')
  const [details, setDetails] = useState(task?.details ?? '')
  const [ent, setEnt] = useState<PdRef | null>(task?.entity ?? entity ?? null)
  const [due, setDue] = useState(task?.due_at ? task.due_at.slice(0, 16).replace(' ', 'T') : '')
  const [priority, setPriority] = useState<number>(task?.priority ?? 2)
  const [assignee, setAssignee] = useState<number>(task?.assignee?.id ?? 0)
  const [src, setSrc] = useState(task?.source_url ?? sourceUrl ?? '')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  async function save() {
    if (!title.trim()) return setErr('কাজের নাম লিখুন।')
    setBusy(true)
    setErr('')
    const body = { title: title.trim(), details, entity_id: ent?.id ?? 0, due_at: due, priority, assignee_id: assignee, source_url: src }
    try {
      const t = task ? await ProfileDeskApi.updateTask(task.id, body) : await ProfileDeskApi.createTask(body)
      onSaved(t)
    } catch (e) {
      setErr(errText(e))
      setBusy(false)
    }
  }
  return (
    <Overlay onClose={onClose}>
      <div className="p-5 space-y-3">
        <h2 className="text-[16px] font-bold">{task ? 'কাজ বদলান' : 'নতুন কাজ'}</h2>
        {err && <Banner kind="error">{err}</Banner>}
        <input
          className={inputClass}
          autoFocus
          value={title}
          placeholder="কী করতে হবে? যেমন: বক্তব্যের ভিডিও যাচাই করুন"
          onChange={(e) => setTitle(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && save()}
          data-testid="pd-task-title"
        />
        <textarea className={inputClass} rows={2} value={details} placeholder="বিস্তারিত (ইচ্ছা হলে)" onChange={(e) => setDetails(e.target.value)} />
        <div className="grid sm:grid-cols-2 gap-3">
          <label className="block text-[12.5px] text-neutral-600">
            কার সম্পর্কে
            <div className="mt-1">
              <EntityPicker value={ent} onChange={setEnt} placeholder="প্রোফাইল খুঁজুন (ইচ্ছা হলে)" />
            </div>
          </label>
          <label className="block text-[12.5px] text-neutral-600">
            কাকে দিচ্ছেন
            <select className={`${inputClass} mt-1`} value={assignee} onChange={(e) => setAssignee(Number(e.target.value))}>
              <option value={0}>নিজে করব</option>
              {meta.people
                .filter((p) => p.id !== meta.me)
                .map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
            </select>
          </label>
        </div>
        <div>
          <span className="text-[12.5px] text-neutral-600">কবের মধ্যে</span>
          <div className="mt-1 flex flex-wrap items-center gap-1.5">
            {duePresets().map((p) => (
              <button
                key={p.label}
                type="button"
                onClick={() => setDue(p.v)}
                className={`px-2.5 py-1 rounded-full text-[12px] border ${due === p.v ? 'bg-brand-600 text-white border-brand-600' : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50'}`}
              >
                {p.label}
              </button>
            ))}
            <input type="datetime-local" className={`${inputClass} !w-auto !py-1`} value={due} onChange={(e) => setDue(e.target.value)} />
            {due && (
              <button type="button" className="text-[12px] text-neutral-400 hover:text-neutral-700" onClick={() => setDue('')}>
                তারিখ ছাড়া
              </button>
            )}
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="text-[12.5px] text-neutral-600 mr-1">গুরুত্ব</span>
          {[3, 2, 1].map((p) => (
            <button
              key={p}
              type="button"
              onClick={() => setPriority(p)}
              className={`px-2.5 py-1 rounded-full text-[12px] ${priority === p ? 'ring-2 ring-brand-600 ' : ''}${PRIORITY[p].tone}`}
            >
              {PRIORITY[p].label}
            </button>
          ))}
        </div>
        <input className={inputClass} value={src} placeholder="সূত্রের লিংক (ইচ্ছা হলে)" onChange={(e) => setSrc(e.target.value)} />
        <div className="flex justify-end gap-2 pt-1">
          <Button variant="secondary" onClick={onClose}>
            বাতিল
          </Button>
          <Button onClick={save} disabled={busy} data-testid="pd-task-save">
            {busy ? 'রাখা হচ্ছে…' : 'রাখুন'}
          </Button>
        </div>
      </div>
    </Overlay>
  )
}

function TaskRow({ t, meta, onChanged, showEntity = true }: { t: PdTask; meta: PdMeta; onChanged: () => void; showEntity?: boolean }) {
  const [editing, setEditing] = useState(false)
  const [busy, setBusy] = useState(false)
  const done = t.status === 'done'
  async function toggle() {
    setBusy(true)
    try {
      await ProfileDeskApi.updateTask(t.id, { done: !done })
      onChanged()
    } catch (e) {
      alert(errText(e))
    }
    setBusy(false)
  }
  async function remove() {
    if (!confirm('কাজটি মুছবেন?')) return
    try {
      await ProfileDeskApi.removeTask(t.id)
      onChanged()
    } catch (e) {
      alert(errText(e))
    }
  }
  const forOther = t.assignee && t.assignee.id !== meta.me
  const fromOther = t.creator.id !== meta.me
  return (
    <div className={`group flex items-start gap-3 px-3 py-2.5 rounded-xl hover:bg-neutral-50 ${done ? 'opacity-60' : ''}`} data-testid="pd-task">
      <button
        type="button"
        disabled={busy || !t.can_edit}
        onClick={toggle}
        className={`mt-0.5 w-5 h-5 rounded-md border-2 shrink-0 inline-flex items-center justify-center ${done ? 'bg-emerald-500 border-emerald-500 text-white' : 'border-neutral-300 hover:border-brand-500'}`}
        title={done ? 'আবার খোলা করুন' : 'শেষ হয়েছে'}
      >
        {done && <IconCheck className="w-3.5 h-3.5" />}
      </button>
      <div className="min-w-0 flex-1">
        <p className={`text-[13.5px] font-medium text-neutral-900 ${done ? 'line-through' : ''}`}>{t.title}</p>
        {t.details && <p className="text-[12px] text-neutral-500 line-clamp-2">{t.details}</p>}
        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11.5px] text-neutral-500">
          {t.priority === 3 && !done && <span className={`px-1.5 rounded ${PRIORITY[3].tone}`}>জরুরি</span>}
          {t.due_at && (
            <span className={t.overdue ? 'text-red-600 font-semibold' : ''}>
              {t.overdue ? 'সময় পেরিয়েছে · ' : ''}
              {bnDate(t.due_at, true)}
            </span>
          )}
          {showEntity && t.entity && (
            <Link to={`/app/profile-desk/${t.entity.id}`} className="text-brand-700 hover:underline">
              {t.entity.name}
            </Link>
          )}
          {forOther && <span>→ {t.assignee!.name}</span>}
          {fromOther && <span>দিয়েছেন {t.creator.name}</span>}
          {done && t.done_by && <span>শেষ করেছেন {t.done_by}</span>}
          {t.source_url && (
            <a href={t.source_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-0.5 hover:text-brand-700">
              <IconLink className="w-3 h-3" /> {hostOf(t.source_url)}
            </a>
          )}
        </div>
      </div>
      <div className="flex gap-1 opacity-100 sm:opacity-0 group-hover:opacity-100 transition">
        {t.can_edit && (
          <button type="button" className="p-1 text-neutral-400 hover:text-neutral-700" onClick={() => setEditing(true)} title="বদলান">
            <IconPencil className="w-4 h-4" />
          </button>
        )}
        {t.can_delete && (
          <button type="button" className="p-1 text-neutral-400 hover:text-red-600" onClick={remove} title="মুছুন">
            <IconTrash className="w-4 h-4" />
          </button>
        )}
      </div>
      {editing && (
        <TaskForm
          meta={meta}
          task={t}
          onClose={() => setEditing(false)}
          onSaved={() => {
            setEditing(false)
            onChanged()
          }}
        />
      )}
    </div>
  )
}

export function TaskList({ tasks, meta, onChanged, showEntity, empty }: { tasks: PdTask[]; meta: PdMeta; onChanged: () => void; showEntity?: boolean; empty?: string }) {
  if (!tasks.length) return <p className="text-[13px] text-neutral-400 px-3 py-4">{empty ?? 'কোনো কাজ নেই।'}</p>
  return (
    <div className="divide-y divide-neutral-100">
      {tasks.map((t) => (
        <TaskRow key={t.id} t={t} meta={meta} onChanged={onChanged} showEntity={showEntity} />
      ))}
    </div>
  )
}

/** One-line "add a task" box: type and press Enter (due today by default off). */
export function QuickTask({ meta, entity, onAdded }: { meta: PdMeta; entity?: PdRef | null; onAdded: () => void }) {
  const [title, setTitle] = useState('')
  const [more, setMore] = useState(false)
  const [busy, setBusy] = useState(false)
  async function add() {
    if (!title.trim()) return
    setBusy(true)
    try {
      await ProfileDeskApi.createTask({ title: title.trim(), entity_id: entity?.id ?? 0 })
      setTitle('')
      onAdded()
    } catch (e) {
      alert(errText(e))
    }
    setBusy(false)
  }
  return (
    <div className="flex gap-2">
      <input
        className={inputClass}
        value={title}
        disabled={busy}
        placeholder="নতুন কাজ লিখে Enter চাপুন…"
        onChange={(e) => setTitle(e.target.value)}
        onKeyDown={(e) => e.key === 'Enter' && add()}
        data-testid="pd-quick-task"
      />
      <Button variant="secondary" onClick={() => setMore(true)} className="shrink-0">
        <IconPlus className="w-4 h-4" /> বিস্তারিত
      </Button>
      {more && (
        <TaskForm
          meta={meta}
          entity={entity}
          defaultTitle={title}
          onClose={() => setMore(false)}
          onSaved={() => {
            setMore(false)
            setTitle('')
            onAdded()
          }}
        />
      )}
    </div>
  )
}

function Pill({ on, onClick, children }: { on: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`px-3 py-1.5 rounded-full text-[12.5px] font-medium ${on ? 'bg-neutral-900 text-white' : 'bg-white border border-neutral-200 text-neutral-600 hover:bg-neutral-50'}`}
    >
      {children}
    </button>
  )
}

function Stat({ n, label, tone, onClick }: { n: number; label: string; tone: string; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className="flex-1 min-w-[120px] rounded-2xl border border-neutral-200 bg-white px-4 py-3 text-left hover:border-neutral-300">
      <p className={`text-[22px] font-bold ${n ? tone : 'text-neutral-300'}`}>{bn(n)}</p>
      <p className="text-[12px] text-neutral-500">{label}</p>
    </button>
  )
}

export function TasksTab({ meta, onChanged }: { meta: PdMeta; onChanged?: () => void }) {
  const [scope, setScope] = useState<'mine' | 'given' | 'all'>('mine')
  const [status, setStatus] = useState<'open' | 'done'>('open')
  const [due, setDue] = useState<'' | 'overdue' | 'today' | 'week'>('')
  const [q, setQ] = useState('')
  const [tasks, setTasks] = useState<PdTask[] | null>(null)
  const [err, setErr] = useState('')
  const load = useCallback(() => {
    ProfileDeskApi.tasks({ scope, status, due, q })
      .then((t) => {
        setTasks(t)
        setErr('')
      })
      .catch((e) => setErr(errText(e)))
  }, [scope, status, due, q])
  useEffect(() => {
    const t = setTimeout(load, q ? 250 : 0)
    return () => clearTimeout(t)
  }, [load, q])
  const changed = () => {
    load()
    onChanged?.()
  }
  return (
    <div className="grid lg:grid-cols-[1fr_280px] gap-4">
      <Card className="p-4">
        <QuickTask meta={meta} onAdded={changed} />
        <div className="mt-3 flex flex-wrap gap-1.5">
          <Pill on={scope === 'mine'} onClick={() => setScope('mine')}>আমার কাজ</Pill>
          <Pill on={scope === 'given'} onClick={() => setScope('given')}>যাঁদের দিয়েছি</Pill>
          {meta.is_boss && <Pill on={scope === 'all'} onClick={() => setScope('all')}>সবার</Pill>}
          <span className="w-px bg-neutral-200 mx-1" />
          <Pill on={status === 'open'} onClick={() => setStatus('open')}>বাকি</Pill>
          <Pill on={status === 'done'} onClick={() => setStatus('done')}>শেষ</Pill>
          {status === 'open' && (
            <select className={`${inputClass} !w-auto !py-1 !rounded-full`} value={due} onChange={(e) => setDue(e.target.value as typeof due)}>
              <option value="">যেকোনো সময়</option>
              <option value="overdue">সময় পেরিয়েছে</option>
              <option value="today">আজকের মধ্যে</option>
              <option value="week">এই সপ্তাহে</option>
            </select>
          )}
          <input className={`${inputClass} !w-40 !py-1 !rounded-full`} value={q} placeholder="কাজে খুঁজুন" onChange={(e) => setQ(e.target.value)} />
        </div>
        <div className="mt-2 -mx-3">
          {err && <Banner kind="error">{err}</Banner>}
          {!tasks ? <Spinner className="my-8" /> : <TaskList tasks={tasks} meta={meta} onChanged={changed} empty={status === 'open' ? 'কোনো বাকি কাজ নেই। 🎉' : 'এখনো কোনো কাজ শেষ হয়নি।'} />}
        </div>
      </Card>
      <Card className="p-4 text-[12.5px] text-neutral-600 space-y-2 h-fit">
        <p className="font-semibold text-neutral-800 flex items-center gap-1.5">
          <IconChecklist className="w-4 h-4" /> কাজ কীভাবে চলে
        </p>
        <p>কাউকে কাজ দিলে তিনি ঘণ্টায় খবর পান। শেষ করলে আপনি খবর পান।</p>
        <p>শেষ সময়ের এক ঘণ্টা আগে আর সময় পেরোলে আবার মনে করিয়ে দেওয়া হয়।</p>
        <p>প্রোফাইলের পাতা থেকে কাজ দিলে কাজটি সেই প্রোফাইলে যুক্ত থাকে। এলার্টের নতুন পোস্ট থেকে "এ নিয়ে খবর" চাপলেও কাজ হয়।</p>
      </Card>
    </div>
  )
}

// ---------------------------------------------------------------------------
// events (timeline)
// ---------------------------------------------------------------------------

export function EventForm({
  meta,
  event,
  entity,
  onClose,
  onSaved,
}: {
  meta: PdMeta
  event?: PdEvent
  entity?: PdRef | null
  onClose: () => void
  onSaved: (e: PdEvent) => void
}) {
  const [ent, setEnt] = useState<PdRef | null>(event?.entity ?? entity ?? null)
  const [kind, setKind] = useState(event?.kind ?? 'statement')
  const [date, setDate] = useState(event?.happened_on ?? todayStr())
  const [title, setTitle] = useState(event?.title ?? '')
  const [details, setDetails] = useState(event?.details ?? '')
  const [src, setSrc] = useState(event?.source_url ?? '')
  const [important, setImportant] = useState((event?.importance ?? 2) >= 3)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  async function save() {
    if (!ent) return setErr('কার ঘটনা, প্রোফাইল বেছে নিন।')
    if (!title.trim()) return setErr('কী ঘটেছে এক লাইনে লিখুন।')
    setBusy(true)
    setErr('')
    const body = { kind, happened_on: date, title: title.trim(), details, source_url: src, importance: important ? 3 : 2 }
    try {
      const v = event ? await ProfileDeskApi.updateEvent(event.id, body) : await ProfileDeskApi.createEvent({ entity_id: ent.id, ...body })
      onSaved(v)
    } catch (e) {
      setErr(errText(e))
      setBusy(false)
    }
  }
  return (
    <Overlay onClose={onClose}>
      <div className="p-5 space-y-3">
        <h2 className="text-[16px] font-bold">{event ? 'ঘটনা বদলান' : 'ঘটনা যোগ করুন'}</h2>
        {err && <Banner kind="error">{err}</Banner>}
        {!event && !entity && <EntityPicker value={ent} onChange={setEnt} placeholder="কার ঘটনা? প্রোফাইল খুঁজুন" />}
        <div className="flex flex-wrap gap-1.5">
          {meta.event_kinds.map((k) => (
            <button
              key={k.key}
              type="button"
              onClick={() => setKind(k.key)}
              className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[12px] border ${kind === k.key ? 'border-neutral-900 bg-neutral-900 text-white' : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50'}`}
            >
              <span className={`w-2 h-2 rounded-full ${EVENT_TONE[k.key] ?? 'bg-neutral-300'}`} />
              {k.label}
            </button>
          ))}
        </div>
        <div className="grid sm:grid-cols-[160px_1fr] gap-3">
          <input type="date" className={inputClass} value={date} onChange={(e) => setDate(e.target.value)} />
          <input className={inputClass} value={title} autoFocus placeholder="কী ঘটেছে, এক লাইনে" onChange={(e) => setTitle(e.target.value)} data-testid="pd-event-title" />
        </div>
        <textarea className={inputClass} rows={3} value={details} placeholder="বিস্তারিত (ইচ্ছা হলে)" onChange={(e) => setDetails(e.target.value)} />
        <input className={inputClass} value={src} placeholder="সূত্রের লিংক (খবর, ভিডিও, পোস্ট)" onChange={(e) => setSrc(e.target.value)} />
        <label className="flex items-center gap-2 text-[13px] text-neutral-700">
          <input type="checkbox" checked={important} onChange={(e) => setImportant(e.target.checked)} /> বড় ঘটনা (ঘটনাপঞ্জিতে মোটা দাগে)
        </label>
        <div className="flex justify-end gap-2 pt-1">
          <Button variant="secondary" onClick={onClose}>
            বাতিল
          </Button>
          <Button onClick={save} disabled={busy} data-testid="pd-event-save">
            {busy ? 'রাখা হচ্ছে…' : 'রাখুন'}
          </Button>
        </div>
      </div>
    </Overlay>
  )
}

/** Vertical timeline. */
export function Timeline({ events, meta, onChanged, showEntity = false }: { events: PdEvent[]; meta: PdMeta; onChanged: () => void; showEntity?: boolean }) {
  const [editing, setEditing] = useState<PdEvent | null>(null)
  if (!events.length) return <p className="text-[13px] text-neutral-400 py-3">এখনো কোনো ঘটনা যোগ হয়নি।</p>
  let lastYear = ''
  return (
    <div className="relative">
      <div className="absolute left-[7px] top-2 bottom-2 w-px bg-neutral-200" />
      {events.map((v) => {
        const y = v.happened_on.slice(0, 4)
        const showYear = y !== lastYear
        lastYear = y
        const canEdit = meta.is_boss || v.created_by === meta.me
        return (
          <div key={v.id}>
            {showYear && <p className="relative pl-6 pt-2 pb-1 text-[11.5px] font-bold text-neutral-400">{bn(y)}</p>}
            <div className="group relative pl-6 pb-3" data-testid="pd-event">
              <span className={`absolute left-0 top-1.5 w-[15px] h-[15px] rounded-full ring-4 ring-white ${EVENT_TONE[v.kind] ?? 'bg-neutral-300'}`} />
              <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                  <p className="text-[11.5px] text-neutral-500">
                    {bnDate(v.happened_on)} · {kindLabel(meta.event_kinds, v.kind)}
                    {showEntity && v.entity && (
                      <>
                        {' · '}
                        <Link to={`/app/profile-desk/${v.entity.id}`} className="text-brand-700 font-medium hover:underline">
                          {v.entity.name}
                        </Link>
                      </>
                    )}
                  </p>
                  <p className={`text-[13.5px] text-neutral-900 ${v.importance >= 3 ? 'font-bold' : 'font-medium'}`}>{v.title}</p>
                  {v.details && <p className="text-[12.5px] text-neutral-600 whitespace-pre-line">{v.details}</p>}
                  {v.source_url && (
                    <a href={v.source_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[11.5px] text-brand-700 hover:underline">
                      <IconLink className="w-3 h-3" /> সূত্র: {hostOf(v.source_url)}
                    </a>
                  )}
                </div>
                {canEdit && (
                  <div className="flex gap-1 sm:opacity-0 group-hover:opacity-100 print:hidden">
                    <button type="button" className="p-1 text-neutral-400 hover:text-neutral-700" onClick={() => setEditing(v)} title="বদলান">
                      <IconPencil className="w-3.5 h-3.5" />
                    </button>
                    <button
                      type="button"
                      className="p-1 text-neutral-400 hover:text-red-600"
                      title="মুছুন"
                      onClick={async () => {
                        if (!confirm('ঘটনাটি মুছবেন?')) return
                        try {
                          await ProfileDeskApi.removeEvent(v.id)
                          onChanged()
                        } catch (e) {
                          alert(errText(e))
                        }
                      }}
                    >
                      <IconTrash className="w-3.5 h-3.5" />
                    </button>
                  </div>
                )}
              </div>
            </div>
          </div>
        )
      })}
      {editing && (
        <EventForm
          meta={meta}
          event={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            onChanged()
          }}
        />
      )}
    </div>
  )
}

export function EventsTab({ meta }: { meta: PdMeta }) {
  const [kind, setKind] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [data, setData] = useState<{ items: PdEvent[]; total: number; pages: number } | null>(null)
  const [adding, setAdding] = useState(false)
  const load = useCallback(() => {
    ProfileDeskApi.events({ kind, from, to, q, page })
      .then(setData)
      .catch(() => setData({ items: [], total: 0, pages: 0 }))
  }, [kind, from, to, q, page])
  useEffect(() => {
    const t = setTimeout(load, q ? 250 : 0)
    return () => clearTimeout(t)
  }, [load, q])
  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-center gap-2">
        <select className={`${inputClass} !w-auto`} value={kind} onChange={(e) => (setKind(e.target.value), setPage(1))}>
          <option value="">সব ধরনের ঘটনা</option>
          {meta.event_kinds.map((k) => (
            <option key={k.key} value={k.key}>
              {k.label}
            </option>
          ))}
        </select>
        <input type="date" className={`${inputClass} !w-auto`} value={from} onChange={(e) => (setFrom(e.target.value), setPage(1))} title="থেকে" />
        <span className="text-neutral-400">–</span>
        <input type="date" className={`${inputClass} !w-auto`} value={to} onChange={(e) => (setTo(e.target.value), setPage(1))} title="পর্যন্ত" />
        <input className={`${inputClass} !w-48`} value={q} placeholder="ঘটনায় খুঁজুন" onChange={(e) => (setQ(e.target.value), setPage(1))} />
        <div className="flex-1" />
        <Button onClick={() => setAdding(true)} data-testid="pd-event-add">
          <IconPlus className="w-4 h-4" /> ঘটনা
        </Button>
      </div>
      <p className="mt-2 text-[12px] text-neutral-500">
        সব প্রোফাইলের ঘটনা, নতুনটা আগে।{data ? ` মোট ${bn(data.total)}টি।` : ''}
      </p>
      <div className="mt-3">{!data ? <Spinner className="my-8" /> : <Timeline events={data.items} meta={meta} onChanged={load} showEntity />}</div>
      {data && data.pages > 1 && (
        <div className="flex justify-center gap-2 mt-2">
          <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            আগের
          </Button>
          <span className="text-[12.5px] text-neutral-500 self-center">
            {bn(page)} / {bn(data.pages)}
          </span>
          <Button variant="secondary" size="sm" disabled={page >= data.pages} onClick={() => setPage(page + 1)}>
            পরের
          </Button>
        </div>
      )}
      {adding && (
        <EventForm
          meta={meta}
          onClose={() => setAdding(false)}
          onSaved={() => {
            setAdding(false)
            load()
          }}
        />
      )}
    </Card>
  )
}

// ---------------------------------------------------------------------------
// relations
// ---------------------------------------------------------------------------

export function Relations({ entityId, relations, meta, canEdit, onChange }: { entityId: number; relations: PdRelation[]; meta: PdMeta; canEdit: boolean; onChange: (r: PdRelation[]) => void }) {
  const [adding, setAdding] = useState(false)
  const [other, setOther] = useState<PdRef | null>(null)
  const [kind, setKind] = useState('ally')
  const [note, setNote] = useState('')
  const [err, setErr] = useState('')
  async function add() {
    if (!other) return setErr('কার সাথে সম্পর্ক, প্রোফাইল বেছে নিন।')
    try {
      const r = await ProfileDeskApi.addRelation({ a_id: entityId, b_id: other.id, kind, note })
      onChange(r.relations)
      setAdding(false)
      setOther(null)
      setNote('')
      setErr('')
    } catch (e) {
      setErr(errText(e))
    }
  }
  const byKind = new Map<string, PdRelation[]>()
  for (const r of relations) byKind.set(r.kind, [...(byKind.get(r.kind) ?? []), r])
  return (
    <div>
      {relations.length === 0 && !adding && <p className="text-[13px] text-neutral-400">কারো সাথে সম্পর্ক যোগ হয়নি।</p>}
      <div className="space-y-2">
        {[...byKind.entries()].map(([k, list]) => (
          <div key={k}>
            <p className="text-[11.5px] font-semibold text-neutral-500">{kindLabel(meta.relation_kinds, k)}</p>
            <div className="mt-1 flex flex-wrap gap-1.5">
              {list.map((r) => (
                <span key={r.id} className="group inline-flex items-center gap-1 pl-2.5 pr-1.5 py-1 rounded-full bg-neutral-100 text-[12.5px]" title={r.note}>
                  <Link to={`/app/profile-desk/${r.other.id}`} className="font-medium text-neutral-800 hover:text-brand-700">
                    {r.other.name}
                  </Link>
                  {r.note && <span className="text-neutral-400 max-w-[140px] truncate">· {r.note}</span>}
                  {canEdit && (
                    <button
                      type="button"
                      className="text-neutral-300 hover:text-red-600 print:hidden"
                      title="সম্পর্ক মুছুন"
                      onClick={async () => {
                        try {
                          await ProfileDeskApi.removeRelation(r.id)
                          onChange(relations.filter((x) => x.id !== r.id))
                        } catch (e) {
                          alert(errText(e))
                        }
                      }}
                    >
                      ×
                    </button>
                  )}
                </span>
              ))}
            </div>
          </div>
        ))}
      </div>
      {canEdit && !adding && (
        <button type="button" className="mt-2 text-[12.5px] font-semibold text-brand-600 hover:underline inline-flex items-center gap-1 print:hidden" onClick={() => setAdding(true)}>
          <IconRelation className="w-4 h-4" /> সম্পর্ক যোগ করুন
        </button>
      )}
      {adding && (
        <div className="mt-2 rounded-xl border border-neutral-200 p-3 space-y-2">
          {err && <Banner kind="error">{err}</Banner>}
          <EntityPicker value={other} onChange={setOther} exclude={entityId} placeholder="কার সাথে? নাম লিখুন" />
          <select className={inputClass} value={kind} onChange={(e) => setKind(e.target.value)}>
            {meta.relation_kinds.map((k) => (
              <option key={k.key} value={k.key}>
                {k.label}
              </option>
            ))}
          </select>
          <input className={inputClass} value={note} placeholder="ছোট নোট (যেমন: ভাই, সাবেক সহকারী)" onChange={(e) => setNote(e.target.value)} />
          <div className="flex gap-2 justify-end">
            <Button variant="secondary" size="sm" onClick={() => setAdding(false)}>
              বাতিল
            </Button>
            <Button size="sm" onClick={add} data-testid="pd-rel-save">
              যোগ করুন
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// coverage on Meeha
// ---------------------------------------------------------------------------

export function Coverage({ entityId }: { entityId: number }) {
  const [c, setC] = useState<PdCoverage | null>(null)
  const [err, setErr] = useState('')
  useEffect(() => {
    ProfileDeskApi.coverage(entityId)
      .then(setC)
      .catch((e) => setErr(errText(e)))
  }, [entityId])
  if (err) return <p className="text-[12.5px] text-red-600">{err}</p>
  if (!c) return <Spinner className="my-3" />
  if (!c.posts.length && !c.quotes.length)
    return <p className="text-[13px] text-neutral-400">মিহায় এই নামে ({c.names.join(', ') || '—'}) এখনো কোনো প্রকাশিত খবর বা উক্তি পাওয়া যায়নি।</p>
  return (
    <div className="space-y-1.5">
      {c.posts.map((p) => (
        <a key={p.id} href={p.url} target="_blank" rel="noopener noreferrer" className="flex items-start gap-2 text-[13px] hover:text-brand-700">
          <IconNews className="w-4 h-4 mt-0.5 text-neutral-400 shrink-0" />
          <span className="flex-1">{p.title}</span>
          <span className="text-[11px] text-neutral-400 shrink-0">{bnDate(p.date)}</span>
        </a>
      ))}
      {c.quotes.map((q) => (
        <a key={`q${q.id}`} href={q.url} target="_blank" rel="noopener noreferrer" className="flex items-start gap-2 text-[13px] hover:text-brand-700">
          <span className="w-4 text-center text-neutral-400 shrink-0">❝</span>
          <span className="flex-1 italic">{q.text}</span>
          <span className="text-[11px] text-neutral-400 shrink-0">{bnDate(q.date)}</span>
        </a>
      ))}
      <p className="text-[11px] text-neutral-400 pt-1">নাম মিলিয়ে খোঁজা: একই নামের অন্য কেউ হলে তাও আসতে পারে।</p>
    </div>
  )
}

// ---------------------------------------------------------------------------
// desk home
// ---------------------------------------------------------------------------

function MiniCard({ e }: { e: PdEntity }) {
  return (
    <div className="flex items-center gap-2.5 p-2 rounded-xl hover:bg-neutral-50">
      <Link to={`/app/profile-desk/${e.id}`} className="shrink-0">
        <Avatar name={e.name} photo={e.photo} kind={e.kind} size={38} />
      </Link>
      <div className="min-w-0 flex-1">
        <Link to={`/app/profile-desk/${e.id}`} className="block text-[13px] font-semibold text-neutral-900 truncate hover:text-brand-700">
          {e.name}
        </Link>
        <p className="text-[11.5px] text-neutral-500 truncate">{e.title || e.org?.name || ''}</p>
      </div>
      <div className="hidden sm:block">
        <LinkChips links={e.links} size="sm" max={3} />
      </div>
    </div>
  )
}

export function HomeTab({ meta, goto, onChanged }: { meta: PdMeta; goto: (tab: string) => void; onChanged: () => void }) {
  const [h, setH] = useState<PdHome | null>(null)
  const [err, setErr] = useState('')
  const load = useCallback(() => {
    ProfileDeskApi.home()
      .then(setH)
      .catch((e) => setErr(errText(e)))
  }, [])
  useEffect(load, [load])
  if (err) return <Banner kind="error">{err}</Banner>
  if (!h) return <Spinner className="my-12" />
  return (
    <div className="space-y-4" data-testid="pd-home">
      <div className="flex flex-wrap gap-3">
        <Stat n={h.counts.overdue} label="কাজের সময় পেরিয়েছে" tone="text-red-600" onClick={() => goto('tasks')} />
        <Stat n={h.counts.today} label="আজকের মধ্যে কাজ" tone="text-amber-600" onClick={() => goto('tasks')} />
        <Stat n={h.counts.my_open} label="আমার বাকি কাজ" tone="text-neutral-900" onClick={() => goto('tasks')} />
        <Stat n={h.counts.unseen} label="নতুন পোস্ট / ভিডিও" tone="text-red-600" onClick={() => goto('alerts')} />
        <Stat n={h.counts.given} label="অন্যদের দেওয়া কাজ বাকি" tone="text-blue-600" onClick={() => goto('tasks')} />
      </div>
      <div className="grid lg:grid-cols-[1.3fr_1fr] gap-4">
        <Card className="p-4">
          <div className="flex items-center justify-between mb-2">
            <h3 className="text-[14px] font-bold flex items-center gap-1.5">
              <IconChecklist className="w-4 h-4" /> আমার কাজ
            </h3>
            <button type="button" className="text-[12.5px] text-brand-600 font-semibold hover:underline" onClick={() => goto('tasks')}>
              সব কাজ →
            </button>
          </div>
          <QuickTask
            meta={meta}
            onAdded={() => {
              load()
              onChanged()
            }}
          />
          <div className="mt-2 -mx-3">
            <TaskList
              tasks={h.tasks.slice(0, 8)}
              meta={meta}
              onChanged={() => {
                load()
                onChanged()
              }}
              empty="কোনো বাকি কাজ নেই। ওপরে লিখে নতুন কাজ যোগ করুন।"
            />
          </div>
        </Card>
        <div className="space-y-4">
          <Card className="p-4">
            <h3 className="text-[14px] font-bold flex items-center gap-1.5 mb-1">
              <IconPin className="w-4 h-4" /> পিন করা
            </h3>
            {h.pinned.length ? h.pinned.map((e) => <MiniCard key={e.id} e={e} />) : <p className="text-[12.5px] text-neutral-400 py-2">যাঁদের প্রায়ই দেখেন, তাঁদের পাতায় "পিন" চাপুন। এখানে থাকবে।</p>}
          </Card>
          <Card className="p-4">
            <h3 className="text-[14px] font-bold mb-1">সম্প্রতি দেখেছেন</h3>
            {h.recent.length ? h.recent.slice(0, 6).map((e) => <MiniCard key={e.id} e={e} />) : <p className="text-[12.5px] text-neutral-400 py-2">এখনো কোনো প্রোফাইল খোলেননি।</p>}
          </Card>
        </div>
      </div>
      <div className="grid lg:grid-cols-[1.3fr_1fr] gap-4">
        <Card className="p-4">
          <div className="flex items-center justify-between mb-2">
            <h3 className="text-[14px] font-bold flex items-center gap-1.5">
              <IconCalendarEvent className="w-4 h-4" /> সাম্প্রতিক ঘটনা
            </h3>
            <button type="button" className="text-[12.5px] text-brand-600 font-semibold hover:underline" onClick={() => goto('events')}>
              ঘটনাপঞ্জি →
            </button>
          </div>
          <Timeline events={h.events} meta={meta} onChanged={load} showEntity />
        </Card>
        <Card className="p-4">
          <h3 className="text-[14px] font-bold mb-1">গুরুত্বপূর্ণ, কিন্তু অসম্পূর্ণ</h3>
          <p className="text-[11.5px] text-neutral-500 mb-2">৩ তারা বা বেশি গুরুত্বের প্রোফাইল, যেগুলোতে ছবি, পদ বা পরিচিতি নেই।</p>
          {h.weak.length === 0 && <p className="text-[12.5px] text-neutral-400">সব ঠিক আছে।</p>}
          {h.weak.map((w) => (
            <Link key={w.id} to={`/app/profile-desk/${w.id}`} className="flex items-center gap-2 py-1.5 text-[13px] hover:text-brand-700">
              <span className="font-medium flex-1 truncate">{w.name}</span>
              <span className="text-[11px] text-neutral-400 truncate max-w-[50%]">{w.missing.slice(0, 3).join(', ')}</span>
              <ScoreDot score={w.score} />
            </Link>
          ))}
        </Card>
      </div>
    </div>
  )
}
