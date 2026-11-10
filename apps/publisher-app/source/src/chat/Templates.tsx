import { useEffect, useMemo, useRef, useState } from 'react'
import { ChatApi, type ChatLabel, type ChatTemplate, type LabelKey, type NewsItem, type Room, type TemplateInput, type TemplatesResponse } from './api'
import { LabelChip, Sheet, Spinner, useConfirm } from './bits'
import { bnNum, fullWhen } from './util'

/**
 * 1.24.0 — message templates and the “/” news picker.
 *
 *   <TemplatePicker>  📋 in the composer: kinds as tabs, search, one tap fills
 *                     the box ({নাম}, {এলাকা}, {তারিখ} … filled; a news
 *                     fill-in opens <NewsPicker> first). “সাজান” manages them.
 *   <NewsPicker>      the room's correspondent's news first; words, a post
 *                     number or a pasted link find any news.
 *   newsText()        how a picked news goes into a message.
 */

const MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর']
const bnDate = (d = new Date()) => `${bnNum(d.getDate())} ${MONTHS[d.getMonth()]} ${bnNum(d.getFullYear())}`

const NEWS_VARS = ['খবর', 'লিংক', 'ফেসবুক']
const needsNews = (body: string) => NEWS_VARS.some((v) => body.includes(`{${v}}`))

/** A picked news as message text. */
export function newsText(n: NewsItem) {
  return n.url ? `“${n.title}”\n${n.url}` : `“${n.title}” (${n.status_l})`
}

/** Fills {…}; what is unknown or empty becomes [নাম] for the writer to complete. */
function fillVars(body: string, vars: Record<string, string>) {
  return body.replace(/\{([^{}\s]{1,20})\}/gu, (_, k: string) => (vars[k] ? vars[k] : `[${k.replace(/_/g, ' ')}]`))
}

// ---------------------------------------------------------------------------
// “/” news picker
// ---------------------------------------------------------------------------

export function NewsList({ room, q, staff, onPick, compact = false, active = -1 }: { room: Room; q: string; staff: boolean; onPick: (n: NewsItem) => void; compact?: boolean; active?: number }) {
  const [list, setList] = useState<NewsItem[] | null>(null)
  const [focus, setFocus] = useState<string>('')
  useEffect(() => {
    let on = true
    const t = setTimeout(
      () => {
        ChatApi.posts(room.id, q.trim())
          .then((r) => {
            if (!on) return
            setList(r.posts)
            setFocus(r.focus?.name ?? '')
          })
          .catch(() => on && setList([]))
      },
      q ? 280 : 0,
    )
    return () => {
      on = false
      clearTimeout(t)
    }
  }, [room.id, q])
  if (!list)
    return (
      <div className="p-4 flex justify-center">
        <Spinner />
      </div>
    )
  if (!list.length) return <p className="px-4 py-4 text-center text-[12.5px] text-neutral-500">{q ? 'এমন খবর পাওয়া যায়নি। পোস্ট নম্বর বা লিংকও দিতে পারেন।' : 'এখনও কোনো খবর নেই'}</p>
  return (
    <div data-testid="news-list">
      {!q && focus && <div className="px-3 pt-2 pb-1 text-[11px] font-semibold text-neutral-500">📰 {focus}-এর সাম্প্রতিক খবর আগে{staff ? ', তারপর সাইটের নতুন খবর' : ''}</div>}
      {list.map((n, i) => (
        <button
          key={n.id}
          type="button"
          onMouseDown={(e) => (e.preventDefault(), onPick(n))}
          className={`w-full flex items-center gap-2.5 px-3 ${compact ? 'py-1.5' : 'py-2.5'} text-left ${i === active ? 'bg-brand-50' : 'hover:bg-neutral-50'}`}
          data-testid={`news-${n.id}`}
        >
          {n.thumb ? (
            <img src={n.thumb} alt="" className="w-11 h-11 rounded-lg object-cover shrink-0 bg-neutral-100" />
          ) : (
            <span className="w-11 h-11 rounded-lg bg-neutral-100 flex items-center justify-center text-[18px] shrink-0">{n.type === 'video' ? '🎬' : '📰'}</span>
          )}
          <span className="min-w-0 flex-1">
            <span className="block text-[13.5px] font-medium text-neutral-900 line-clamp-2 leading-snug">{n.title}</span>
            <span className="block truncate text-[11px] text-neutral-500">
              <span className={n.status === 'publish' ? 'text-emerald-700' : 'text-amber-700'}>{n.status_l}</span> · #{bnNum(n.id)} · {n.author} · {fullWhen(n.date)}
            </span>
          </span>
        </button>
      ))}
    </div>
  )
}

/** Full picker (from a template): search + optional Facebook link for the desk. */
export function NewsPicker({ room, staff, onPick, onClose }: { room: Room; staff: boolean; onPick: (n: NewsItem) => void; onClose: () => void }) {
  const [q, setQ] = useState('')
  const [chosen, setChosen] = useState<NewsItem | null>(null)
  const [fb, setFb] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const done = async () => {
    if (!chosen) return
    let n = chosen
    if (staff && fb.trim() && fb.trim() !== chosen.fb) {
      setBusy(true)
      setErr('')
      try {
        n = await ChatApi.setFb(chosen.id, fb.trim())
      } catch (e) {
        setErr((e as Error).message)
        setBusy(false)
        return
      }
      setBusy(false)
    }
    onPick(n)
  }
  return (
    <Sheet open onClose={onClose} title={chosen ? 'খবরটি বসান' : '📰 কোন খবর?'} wide>
      {chosen ? (
        <div className="px-5 pb-5 space-y-3" data-testid="news-confirm">
          <div className="rounded-xl bg-neutral-50 ring-1 ring-neutral-200 p-3">
            <div className="text-[14px] font-semibold text-neutral-900">{chosen.title}</div>
            <div className="text-[11.5px] text-neutral-500 mt-0.5">
              {chosen.status_l} · #{bnNum(chosen.id)} · {chosen.author}
            </div>
            {chosen.url && <div className="text-[11.5px] text-blue-700 break-all mt-1">{chosen.url}</div>}
          </div>
          {staff && chosen.url && (
            <div>
              <div className="text-[12px] font-semibold text-neutral-600 mb-1">ফেসবুক পোস্টের লিংক (ইচ্ছে হলে)</div>
              <input value={fb} onChange={(e) => setFb(e.target.value)} placeholder="https://www.facebook.com/share/p/…" className="w-full rounded-xl border border-neutral-300 px-3 py-2 text-[13.5px]" data-testid="news-fb" />
              <p className="mt-1 text-[11.5px] text-neutral-400">দিলে খবরের সঙ্গে মনে রাখা হবে। না দিলে বন্ধুদের শেয়ার করার একটা লিংক বসবে।</p>
            </div>
          )}
          {err && <p className="text-[12.5px] text-red-600">{err}</p>}
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setChosen(null)} className="px-4 py-2 rounded-xl text-[13.5px] text-neutral-600">
              ← অন্য খবর
            </button>
            <button type="button" disabled={busy} onClick={() => void done()} className="px-5 py-2 rounded-xl bg-brand-600 text-white text-[14px] font-semibold" data-testid="news-use">
              {busy ? 'রাখা হচ্ছে…' : 'বসান'}
            </button>
          </div>
        </div>
      ) : (
        <>
          <div className="px-5 pb-2">
            <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="শিরোনামের শব্দ, পোস্ট নম্বর বা লিংক…" className="w-full rounded-xl bg-neutral-100 px-3 py-2.5 text-[14px] outline-none focus:ring-2 focus:ring-brand-200" data-testid="news-search" />
          </div>
          <div className="max-h-[56vh] overflow-y-auto pb-3">
            <NewsList
              room={room}
              q={q}
              staff={staff}
              onPick={(n) => {
                setChosen(n)
                setFb(n.fb)
              }}
            />
          </div>
        </>
      )}
    </Sheet>
  )
}

// ---------------------------------------------------------------------------
// Templates
// ---------------------------------------------------------------------------

const AUD: [string, string][] = [
  ['staff', 'ডেস্কের জন্য (সংবাদদাতাকে লিখতে)'],
  ['correspondent', 'সংবাদদাতার জন্য (ডেস্ককে লিখতে)'],
  ['all', 'সবার জন্য'],
]

function TemplateEditor({
  tpl,
  data,
  labels,
  onSaved,
  onClose,
}: {
  tpl: ChatTemplate | null
  data: TemplatesResponse
  labels: ChatLabel[]
  onSaved: () => void
  onClose: () => void
}) {
  const [title, setTitle] = useState(tpl?.title ?? '')
  const [body, setBody] = useState(tpl?.body ?? '')
  const [cat, setCat] = useState(tpl ? tpl.kind : 'publish')
  const [label, setLabel] = useState<string>(tpl?.label ?? '')
  const [aud, setAud] = useState<string>(tpl?.audience && tpl.audience !== 'auto' ? tpl.audience : 'staff')
  const [shared, setShared] = useState(tpl ? !tpl.personal : false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const ta = useRef<HTMLTextAreaElement>(null)
  const { confirm, node } = useConfirm()
  const isAuto = tpl?.audience === 'auto'
  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true)
    setErr('')
    try {
      await fn()
      onSaved()
      onClose()
    } catch (e) {
      setErr((e as Error).message || 'হলো না')
    } finally {
      setBusy(false)
    }
  }
  const insertVar = (v: string) => {
    const el = ta.current
    const at = el?.selectionStart ?? body.length
    const next = body.slice(0, at) + `{${v}}` + body.slice(el?.selectionEnd ?? at)
    setBody(next)
    setTimeout(() => {
      el?.focus()
      el?.setSelectionRange(at + v.length + 2, at + v.length + 2)
    }, 0)
  }
  const input: TemplateInput = { title, body, cat, label, ...(data.can_manage && shared && !isAuto ? { audience: aud } : {}) }
  return (
    <Sheet open onClose={onClose} title={tpl ? 'টেমপ্লেট বদলান' : 'নতুন টেমপ্লেট'} wide>
      <div className="px-5 pb-5 space-y-3 max-h-[78vh] overflow-y-auto" data-testid="tpl-editor">
        {isAuto && <p className="rounded-lg bg-violet-50 text-violet-800 text-[12px] px-3 py-2">🤖 এটা মিহা সহকারী নিজে থেকে পাঠায়। বন্ধ করলে আর পাঠাবে না।</p>}
        <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="টেমপ্লেটের নাম (যেমন: সংবাদ প্রকাশিত হয়েছে)" className="w-full rounded-xl border border-neutral-300 px-3 py-2.5 text-[14px]" data-testid="tpl-title" />
        <div>
          <textarea ref={ta} value={body} onChange={(e) => setBody(e.target.value)} rows={8} placeholder="মেসেজের লেখা…" className="w-full rounded-xl border border-neutral-300 px-3 py-2.5 text-[13.5px] leading-relaxed" data-testid="tpl-body" />
          <div className="mt-1.5 flex flex-wrap gap-1">
            <span className="text-[11.5px] text-neutral-500 self-center mr-1">বসান:</span>
            {data.vars.map((v) => (
              <button key={v} type="button" onClick={() => insertVar(v)} className="rounded-full bg-brand-50 text-brand-700 px-2 py-0.5 text-[11.5px] font-medium">
                {`{${v}}`}
              </button>
            ))}
          </div>
          <p className="mt-1 text-[11px] text-neutral-400">পাঠানোর সময় {'{নাম}'}, {'{এলাকা}'}, {'{তারিখ}'} নিজে বসে যাবে; {'{খবর}'} বা {'{লিংক}'} থাকলে আগে খবর বাছতে বলবে।</p>
        </div>
        {!isAuto && (
          <div className="grid grid-cols-2 gap-2">
            <label className="text-[12px] text-neutral-600">
              ধরন
              <select value={cat} onChange={(e) => setCat(e.target.value)} className="mt-1 w-full rounded-xl border border-neutral-300 px-2 py-2 text-[13.5px] bg-white">
                {data.cats
                  .filter((c) => c.key !== 'mine' && c.key !== 'auto')
                  .map((c) => (
                    <option key={c.key} value={c.key}>
                      {c.icon} {c.name}
                    </option>
                  ))}
              </select>
            </label>
            <label className="text-[12px] text-neutral-600">
              লেবেল
              <select value={label} onChange={(e) => setLabel(e.target.value)} className="mt-1 w-full rounded-xl border border-neutral-300 px-2 py-2 text-[13.5px] bg-white">
                <option value="">কোনোটা না</option>
                {labels
                  .filter((l) => l.key !== 'general')
                  .map((l) => (
                    <option key={l.key} value={l.key}>
                      {l.name}
                    </option>
                  ))}
              </select>
            </label>
          </div>
        )}
        {data.can_manage && !isAuto && (!tpl || !tpl.personal) && (
          <div className="rounded-xl bg-neutral-50 p-3 space-y-2">
            {!tpl && (
              <label className="flex items-center gap-2 text-[13px] text-neutral-700">
                <input type="checkbox" className="w-4 h-4 accent-brand-600" checked={shared} onChange={(e) => setShared(e.target.checked)} data-testid="tpl-shared" />
                পুরো দলের জন্য (শুধু আমার নয়)
              </label>
            )}
            {shared && (
              <select value={aud} onChange={(e) => setAud(e.target.value)} className="w-full rounded-xl border border-neutral-300 px-2 py-2 text-[13px] bg-white">
                {AUD.map(([k, l]) => (
                  <option key={k} value={k}>
                    {l}
                  </option>
                ))}
              </select>
            )}
          </div>
        )}
        {err && <p className="text-[12.5px] text-red-600">{err}</p>}
        <div className="flex flex-wrap items-center gap-2 pt-1">
          {tpl && !tpl.builtin && (
            <button
              type="button"
              onClick={async () => {
                if (await confirm(`“${tpl.title}” মুছবেন?`, 'মুছুন')) void run(() => ChatApi.deleteTemplate(tpl.id))
              }}
              className="text-[13px] text-red-600"
            >
              🗑️ মুছুন
            </button>
          )}
          {tpl?.builtin && tpl.changed && (
            <button type="button" onClick={() => void run(() => ChatApi.resetTemplate(tpl.id))} className="text-[13px] text-neutral-600">
              ↺ আগের লেখায় ফেরান
            </button>
          )}
          <span className="flex-1" />
          <button type="button" onClick={onClose} className="px-4 py-2 rounded-xl text-[13.5px] text-neutral-600">
            বাতিল
          </button>
          <button
            type="button"
            disabled={busy || !title.trim() || !body.trim()}
            onClick={() => void run(() => (tpl ? ChatApi.updateTemplate(tpl.id, input) : ChatApi.createTemplate({ ...input, shared: data.can_manage && shared })))}
            className="px-5 py-2 rounded-xl bg-brand-600 disabled:bg-neutral-300 text-white text-[14px] font-semibold"
            data-testid="tpl-save"
          >
            {busy ? 'রাখা হচ্ছে…' : 'রাখুন'}
          </button>
        </div>
      </div>
      {node}
    </Sheet>
  )
}

export function TemplatePicker({
  room,
  me,
  labels,
  onPick,
  onClose,
}: {
  room: Room
  me: { id: number; staff: boolean; publisher: boolean }
  labels: ChatLabel[]
  onPick: (text: string, label: LabelKey | '') => void
  onClose: () => void
}) {
  const [data, setData] = useState<TemplatesResponse | null>(null)
  const [err, setErr] = useState('')
  const [tab, setTab] = useState<string>('top')
  const [q, setQ] = useState('')
  const [manage, setManage] = useState(false)
  const [editing, setEditing] = useState<ChatTemplate | null | 'new'>(null)
  const [newsFor, setNewsFor] = useState<ChatTemplate | null>(null)
  const [preview, setPreview] = useState<ChatTemplate | null>(null)

  const load = (m = manage) =>
    ChatApi.templates(m)
      .then((r) => {
        setData(r)
        setErr('')
      })
      .catch((e: Error) => setErr(e.message))
  useEffect(() => {
    void load(manage)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [manage])

  const shown = useMemo(() => {
    let l = data?.templates ?? []
    if (!manage) l = l.filter((t) => t.active && t.audience !== 'auto')
    const qq = q.trim().toLowerCase()
    if (qq) l = l.filter((t) => t.title.toLowerCase().includes(qq) || t.body.toLowerCase().includes(qq))
    else if (tab === 'top') l = [...l].sort((a, b) => b.uses - a.uses).slice(0, manage ? 200 : 12)
    else l = l.filter((t) => t.cat === tab)
    return l
  }, [data, tab, q, manage])

  const cats = (data?.cats ?? []).filter((c) => (data?.templates ?? []).some((t) => t.cat === c.key && (manage || (t.active && t.audience !== 'auto'))))

  const apply = (t: ChatTemplate, n?: NewsItem) => {
    const peer = room.peer
    const vars: Record<string, string> = {
      নাম: me.staff ? (peer?.name ?? '') : '',
      আমার_নাম: data?.me.name ?? '',
      এলাকা: me.staff ? (peer?.area ?? '') : (data?.me.area ?? ''),
      তারিখ: bnDate(),
    }
    if (n) {
      vars['খবর'] = n.title
      vars['লিংক'] = n.url || `(${n.status_l})`
      vars['ফেসবুক'] = n.share || ''
    }
    let text = fillVars(t.body, vars)
    // a share line with nothing to share is dropped
    if (n && !n.share) text = text.replace(/\n?আপনার বন্ধুদের সাথে[^\n]*\n\[ফেসবুক\]\n?/u, '\n').replace(/\[ফেসবুক\]\n?/u, '')
    ChatApi.templateUsed(t.id).catch(() => {})
    onPick(text, t.label)
  }

  const choose = (t: ChatTemplate) => {
    if (needsNews(t.body)) setNewsFor(t)
    else apply(t)
  }

  return (
    <>
      <Sheet
        open
        onClose={onClose}
        wide
        title={
          <span className="flex items-center gap-2">
            {manage ? '✏️ টেমপ্লেট সাজান' : '📋 টেমপ্লেট'}
            <span className="flex-1" />
            <button type="button" onClick={() => setEditing('new')} className="rounded-lg bg-brand-600 text-white px-2.5 py-1 text-[12.5px] font-semibold" data-testid="tpl-new">
              + নতুন
            </button>
            <button type="button" onClick={() => setManage((v) => !v)} className={`rounded-lg px-2.5 py-1 text-[12.5px] font-semibold ${manage ? 'bg-neutral-900 text-white' : 'bg-neutral-100 text-neutral-700'}`} data-testid="tpl-manage">
              {manage ? '✓ শেষ' : 'সাজান'}
            </button>
          </span>
        }
      >
        <div className="px-5 pb-2 space-y-2" data-testid="tpl-picker">
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="টেমপ্লেট খুঁজুন…" className="w-full rounded-xl bg-neutral-100 px-3 py-2 text-[13.5px] outline-none focus:ring-2 focus:ring-brand-200" data-testid="tpl-search" />
          <div className="flex gap-1.5 overflow-x-auto -mx-1 px-1 pb-0.5">
            <button type="button" onClick={() => setTab('top')} className={`shrink-0 rounded-full px-3 py-1 text-[12.5px] font-medium ${tab === 'top' ? 'bg-neutral-900 text-white' : 'bg-neutral-100 text-neutral-600'}`}>
              {manage ? 'সব' : '⭐ বেশি ব্যবহৃত'}
            </button>
            {cats.map((c) => (
              <button key={c.key} type="button" onClick={() => setTab(c.key)} className={`shrink-0 rounded-full px-3 py-1 text-[12.5px] font-medium whitespace-nowrap ${tab === c.key ? 'bg-neutral-900 text-white' : 'bg-neutral-100 text-neutral-600'}`} data-testid={`tpl-tab-${c.key}`}>
                {c.icon} {c.name}
              </button>
            ))}
          </div>
        </div>
        <div className="max-h-[58vh] overflow-y-auto px-3 pb-4">
          {err ? (
            <p className="p-4 text-center text-[13px] text-red-600">{err}</p>
          ) : !data ? (
            <div className="p-8 flex justify-center">
              <Spinner />
            </div>
          ) : shown.length === 0 ? (
            <p className="p-6 text-center text-[13px] text-neutral-500">এখানে কোনো টেমপ্লেট নেই। “+ নতুন” দিয়ে নিজের টেমপ্লেট বানান।</p>
          ) : (
            <div className="grid gap-2">
              {shown.map((t) => (
                <div key={t.id} className={`rounded-2xl ring-1 ${t.active ? 'ring-neutral-200 bg-white' : 'ring-neutral-200 bg-neutral-50 opacity-70'} p-3`} data-testid={`tpl-${t.key || t.id}`}>
                  <div className="flex items-start gap-2">
                    <button type="button" onClick={() => (manage ? setPreview(t) : choose(t))} className="min-w-0 flex-1 text-left">
                      <span className="flex items-center gap-1.5 flex-wrap">
                        <span className="text-[14px] font-semibold text-neutral-900">{t.title}</span>
                        {t.label && <LabelChip label={t.label} labels={labels} small />}
                        {t.personal && <span className="text-[10.5px] rounded bg-amber-50 text-amber-700 px-1.5">নিজের</span>}
                        {t.audience === 'auto' && <span className="text-[10.5px] rounded bg-violet-50 text-violet-700 px-1.5">🤖 অটো</span>}
                        {manage && t.audience === 'correspondent' && <span className="text-[10.5px] rounded bg-sky-50 text-sky-700 px-1.5">সংবাদদাতার</span>}
                        {needsNews(t.body) && <span className="text-[10.5px] rounded bg-neutral-100 text-neutral-600 px-1.5">📰 খবর বাছবেন</span>}
                      </span>
                      <span className="mt-1 block text-[12.5px] text-neutral-600 whitespace-pre-line line-clamp-3">{t.body}</span>
                    </button>
                    {manage && t.can_edit ? (
                      <span className="flex flex-col items-end gap-1.5 shrink-0">
                        <label className="inline-flex items-center gap-1 text-[11.5px] text-neutral-600" title={t.active ? 'চালু' : 'বন্ধ'}>
                          <input
                            type="checkbox"
                            className="w-4 h-4 accent-emerald-600"
                            checked={t.active}
                            onChange={(e) => ChatApi.updateTemplate(t.id, { active: e.target.checked }).then(() => load(true))}
                            data-testid={`tpl-toggle-${t.key || t.id}`}
                          />
                          {t.active ? 'চালু' : 'বন্ধ'}
                        </label>
                        <button type="button" onClick={() => setEditing(t)} className="text-[12px] font-semibold text-brand-600">
                          ✏️ বদলান
                        </button>
                      </span>
                    ) : (
                      !manage && (
                        <button type="button" onClick={() => setPreview(t)} className="shrink-0 w-8 h-8 rounded-full hover:bg-neutral-100 text-neutral-500 text-[14px]" aria-label="পুরোটা দেখুন" title="পুরোটা দেখুন">
                          👁
                        </button>
                      )
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </Sheet>

      <Sheet open={!!preview} onClose={() => setPreview(null)} title={preview?.title} wide>
        {preview && (
          <div className="px-5 pb-5">
            <div className="rounded-xl bg-neutral-50 ring-1 ring-neutral-200 p-3 text-[13.5px] whitespace-pre-line leading-relaxed text-neutral-800">{preview.body}</div>
            <div className="mt-3 flex justify-end gap-2">
              {preview.can_edit && (
                <button type="button" onClick={() => (setEditing(preview), setPreview(null))} className="px-4 py-2 rounded-xl text-[13.5px] text-neutral-700">
                  ✏️ বদলান
                </button>
              )}
              {preview.audience !== 'auto' && (
                <button type="button" onClick={() => (choose(preview), setPreview(null))} className="px-5 py-2 rounded-xl bg-brand-600 text-white text-[14px] font-semibold">
                  ব্যবহার করুন
                </button>
              )}
            </div>
          </div>
        )}
      </Sheet>

      {editing && data && (
        <TemplateEditor tpl={editing === 'new' ? null : editing} data={data} labels={labels} onSaved={() => void load(manage)} onClose={() => setEditing(null)} />
      )}
      {newsFor && (
        <NewsPicker
          room={room}
          staff={me.staff}
          onClose={() => setNewsFor(null)}
          onPick={(n) => {
            const t = newsFor
            setNewsFor(null)
            apply(t, n)
          }}
        />
      )}
    </>
  )
}
