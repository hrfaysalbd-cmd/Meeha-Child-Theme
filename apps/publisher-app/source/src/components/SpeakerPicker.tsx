/**
 * r49 — উক্তির বক্তা বাছাই: type a few letters and pick the speaker from Meeha's own people
 * (journalists, correspondents, মিহা কলম writers, staff), from প্রোফাইল ডেস্ক (CRM), or from an
 * earlier quote — name, পদবি and photo fill in at once. A new speaker can be saved to the CRM
 * from here (with the photo), so next time it is one tap.
 */
import { useEffect, useRef, useState } from 'react'
import { ApiError, QuotePeopleApi, type QuotePerson } from '../lib/api'
import { inputClass } from './ui'
import { IconSearch, IconUsers } from './Icon'

export interface PickedSpeaker {
  name: string
  role: string
  photo_id: number
  photo_url: string
  src: QuotePerson['src']
}

const GROUPS: { k: 'team' | 'crm' | 'past'; label: string }[] = [
  { k: 'team', label: 'মিহা পরিবার' },
  { k: 'crm', label: 'প্রোফাইল ডেস্ক' },
  { k: 'past', label: 'আগের উক্তি' },
]

export function SpeakerSearch({ onPick }: { onPick: (p: PickedSpeaker) => void }) {
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [res, setRes] = useState<{ team: QuotePerson[]; crm: QuotePerson[]; past: QuotePerson[] } | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const seq = useRef(0)
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const mine = ++seq.current
    const t = setTimeout(() => {
      QuotePeopleApi.search(q.trim())
        .then((r) => {
          if (mine === seq.current) setRes(r)
        })
        .catch((e) => {
          if (mine === seq.current) setErr(e instanceof Error ? e.message : 'খোঁজা যায়নি।')
        })
    }, 250)
    return () => clearTimeout(t)
  }, [q, open])

  useEffect(() => {
    const close = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [])

  const pick = async (p: QuotePerson) => {
    setErr('')
    let photo_id = p.photo_id
    let photo_url = p.photo
    if (p.src === 'crm') {
      photo_id = 0
      photo_url = ''
      if (p.has_photo && !p.restricted) {
        setBusy(true)
        try {
          const ph = await QuotePeopleApi.crmPhoto(p.id)
          photo_id = ph.id
          photo_url = ph.url
        } catch (e) {
          setErr(`ছবি আনা যায়নি: ${e instanceof Error ? e.message : ''}`)
        } finally {
          setBusy(false)
        }
      }
    }
    onPick({ name: p.name, role: p.role, photo_id, photo_url, src: p.src })
    setOpen(false)
    setQ('')
  }

  const total = res ? res.team.length + res.crm.length + res.past.length : 0

  return (
    <div className="relative mb-3" ref={box}>
      <div className="relative">
        <input
          className={inputClass + ' pl-9'}
          value={q}
          onChange={(e) => {
            setQ(e.target.value)
            setOpen(true)
          }}
          onFocus={() => setOpen(true)}
          placeholder="বক্তা খুঁজুন — মিহার সাংবাদিক, প্রোফাইল ডেস্ক বা আগের উক্তি"
          data-testid="speaker-search"
        />
        <IconSearch className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400" />
      </div>
      {busy && <p className="text-[11.5px] text-neutral-500 mt-1">ছবি আনা হচ্ছে…</p>}
      {err && <p className="text-[11.5px] text-red-600 mt-1">{err}</p>}
      {open && res && (
        <div className="absolute z-20 left-0 right-0 mt-1 max-h-80 overflow-y-auto rounded-xl border border-neutral-200 bg-white shadow-lg" data-testid="speaker-results">
          {total === 0 && <p className="px-3 py-3 text-[12.5px] text-neutral-400">কাউকে পাওয়া যায়নি — নিচে নাম লিখে দিন।</p>}
          {GROUPS.map((g) =>
            res[g.k].length ? (
              <div key={g.k}>
                <p className="px-3 pt-2 pb-1 text-[11px] font-bold text-neutral-400 uppercase tracking-wide">{g.label}</p>
                {res[g.k].map((p) => (
                  <button
                    key={`${p.src}-${p.id}-${p.name}`}
                    type="button"
                    onClick={() => void pick(p)}
                    className="w-full flex items-center gap-2.5 px-3 py-1.5 text-left hover:bg-brand-50"
                    data-testid="speaker-option"
                  >
                    {p.photo ? (
                      <img src={p.photo} alt="" className="w-8 h-8 rounded-full object-cover bg-neutral-100 shrink-0" />
                    ) : (
                      <span className="w-8 h-8 rounded-full bg-neutral-100 grid place-items-center shrink-0 text-neutral-400">
                        <IconUsers className="w-4 h-4" />
                      </span>
                    )}
                    <span className="min-w-0">
                      <span className="block text-[13px] font-medium text-neutral-900 truncate">{p.name}</span>
                      <span className="block text-[11.5px] text-neutral-500 truncate">
                        {[p.role, p.kind].filter(Boolean).join(' · ')}
                        {p.restricted && ' · সংরক্ষিত (ছবি আসবে না)'}
                      </span>
                    </span>
                  </button>
                ))}
              </div>
            ) : null,
          )}
        </div>
      )}
    </div>
  )
}

const CATS: [string, string][] = [
  ['person', 'অন্যান্য ব্যক্তি'],
  ['politician', 'রাজনীতিবিদ'],
  ['official', 'কর্মকর্তা'],
  ['journalist', 'সাংবাদিক'],
  ['celebrity', 'সেলেব্রেটি'],
  ['activist', 'অনলাইন অ্যাক্টিভিস্ট'],
]

/** "প্রোফাইল ডেস্কে যোগ করুন" for a speaker typed by hand. */
export function AddToCrm({ name, role, photoId }: { name: string; role: string; photoId: number }) {
  const [open, setOpen] = useState(false)
  const [cat, setCat] = useState('person')
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState<{ k: 'ok' | 'err' | 'dupe'; t: string } | null>(null)

  if (!name.trim()) return null

  const send = async (force = false) => {
    setBusy(true)
    setMsg(null)
    try {
      const r = await QuotePeopleApi.toCrm({ name: name.trim(), role: role.trim(), photo_id: photoId, category: cat, force })
      setMsg({ k: 'ok', t: r.warning || 'প্রোফাইল ডেস্কে যোগ হয়েছে ✓ — পরের বার খুঁজলেই পাবেন।' })
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        setMsg({ k: 'dupe', t: 'এই নামে প্রোফাইল ডেস্কে আগে থেকেই কেউ আছেন। ওপরের খোঁজে নামটি লিখে সেখান থেকে বেছে নিন, অথবা তবুও নতুন যোগ করুন।' })
      } else {
        setMsg({ k: 'err', t: e instanceof Error ? e.message : 'হয়নি।' })
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mb-3 -mt-1.5 text-[12px]">
      {!open ? (
        <button type="button" className="text-brand-600 font-medium" onClick={() => setOpen(true)} data-testid="speaker-to-crm">
          + “{name.trim()}”-কে প্রোফাইল ডেস্কে যোগ করুন
        </button>
      ) : (
        <div className="flex flex-wrap items-center gap-2 rounded-lg bg-neutral-50 px-2.5 py-2">
          <span className="text-neutral-600">ধরন</span>
          <select className={inputClass + ' !w-auto !py-1 text-[12.5px]'} value={cat} onChange={(e) => setCat(e.target.value)}>
            {CATS.map(([v, l]) => (
              <option key={v} value={v}>
                {l}
              </option>
            ))}
          </select>
          <button type="button" disabled={busy} onClick={() => void send(false)} className="rounded-lg bg-brand-600 text-white px-3 py-1.5 font-semibold disabled:opacity-60" data-testid="speaker-to-crm-save">
            {busy ? 'যোগ হচ্ছে…' : photoId ? 'ছবিসহ যোগ করুন' : 'যোগ করুন'}
          </button>
          {msg?.k === 'dupe' && (
            <button type="button" disabled={busy} onClick={() => void send(true)} className="rounded-lg bg-neutral-200 px-3 py-1.5 font-medium">
              তবুও নতুন যোগ করুন
            </button>
          )}
          <button type="button" className="text-neutral-500" onClick={() => setOpen(false)}>
            বন্ধ
          </button>
        </div>
      )}
      {msg && <p className={`mt-1 ${msg.k === 'ok' ? 'text-emerald-700' : msg.k === 'dupe' ? 'text-amber-700' : 'text-red-600'}`}>{msg.t}</p>}
    </div>
  )
}
