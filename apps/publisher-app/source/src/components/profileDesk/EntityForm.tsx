// r30 — নতুন প্রোফাইল / প্রোফাইল সম্পাদনা (ব্যক্তি অথবা সংস্থা).
import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { ApiError } from '../../lib/api'
import { ProfileDeskApi, detectPlatform, type PdEntity, type PdEntityFull, type PdEntityInput, type PdMeta, type PdRef } from '../../lib/profileDesk'
import { Banner, Button, Field, inputClass } from '../ui'
import { IconX } from '../Icon'
import { Overlay, PLATFORM_LABEL, Stars } from './bits'
import { PLATFORM_COLOR, PlatformIcon } from './icons'

const PERSON_CATS = ['politician', 'activist', 'celebrity', 'journalist', 'official', 'person']

export default function EntityForm({
  meta,
  entity,
  defaultKind = 'person',
  initialLinks = '',
  onClose,
  onSaved,
}: {
  meta: PdMeta
  entity?: PdEntityFull | null
  defaultKind?: 'person' | 'org'
  /** r31: a pasted link to start a new profile from */
  initialLinks?: string
  onClose: () => void
  onSaved: (e: PdEntityFull) => void
}) {
  const editing = !!entity
  const [category, setCategory] = useState(entity?.category ?? (defaultKind === 'org' ? 'party' : 'politician'))
  const kind = meta.categories.find((c) => c.key === category)?.kind ?? (PERSON_CATS.includes(category) ? 'person' : 'org')
  const [nameBn, setNameBn] = useState(entity?.name_bn ?? '')
  const [nameEn, setNameEn] = useState(entity?.name_en ?? '')
  const [aliases, setAliases] = useState(entity?.aliases ?? '')
  const [title, setTitle] = useState(entity?.title ?? '')
  const [org, setOrg] = useState<PdRef | null>(entity?.org ?? null)
  const [parent, setParent] = useState<PdRef | null>(entity?.parent ?? null)
  const [country, setCountry] = useState(entity?.country ?? 'বাংলাদেশ')
  const [district, setDistrict] = useState(entity?.district ?? '')
  const [upazila, setUpazila] = useState(entity?.upazila ?? '')
  const [tags, setTags] = useState((entity?.tags ?? []).join(', '))
  const [importance, setImportance] = useState(entity?.importance ?? 3)
  const [status, setStatus] = useState(entity?.status ?? 'active')
  const [sensitivity, setSensitivity] = useState<'normal' | 'restricted'>(entity?.sensitivity ?? 'normal')
  const [phone, setPhone] = useState(entity?.phone ?? '')
  const [email, setEmail] = useState(entity?.email ?? '')
  const [bio, setBio] = useState(entity?.bio ?? '')
  const [note, setNote] = useState(entity?.private_note ?? '')
  const [links, setLinks] = useState(initialLinks)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [similar, setSimilar] = useState<{ id: number; name: string; title: string }[] | null>(null)

  const pasted = useMemo(
    () =>
      links
        .split(/[\s,]+/)
        .map((s) => s.trim())
        .filter((s) => /\.[a-z]{2,}/i.test(s)),
    [links],
  )

  async function save(force = false) {
    setSaving(true)
    setError('')
    const body: PdEntityInput = {
      category,
      name_bn: nameBn.trim(),
      name_en: nameEn.trim(),
      aliases,
      title,
      org_id: kind === 'person' ? org?.id ?? 0 : 0,
      parent_id: kind === 'org' ? parent?.id ?? 0 : 0,
      country,
      district,
      upazila,
      tags: tags.split(/[,،\n]+/).map((t) => t.trim()).filter(Boolean),
      importance,
      status,
      sensitivity,
      phone,
      email,
      bio,
      private_note: note,
    }
    try {
      let saved: PdEntityFull
      if (editing && entity) {
        saved = await ProfileDeskApi.update(entity.id, body)
      } else {
        saved = await ProfileDeskApi.create({ ...body, links: pasted.map((url) => ({ url })), force })
      }
      onSaved(saved)
    } catch (e) {
      if (e instanceof ApiError && e.code === 'nros_pd_duplicate') {
        // the server sends the similar profiles with the 409
        setSimilar([])
        ProfileDeskApi.list({ q: nameBn || nameEn, per_page: 5 })
          .then((r) => setSimilar(r.items.map((i) => ({ id: i.id, name: i.name, title: i.title }))))
          .catch(() => setSimilar([]))
      } else setError(e instanceof Error ? e.message : 'সংরক্ষণ হয়নি।')
    } finally {
      setSaving(false)
    }
  }

  const persons = meta.categories.filter((c) => c.kind === 'person')
  const orgs = meta.categories.filter((c) => c.kind === 'org')

  return (
    <Overlay onClose={onClose} wide>
      <div className="flex items-center justify-between px-5 py-4 border-b border-neutral-100 sticky top-0 bg-white z-10">
        <h2 className="text-[15px] font-semibold text-neutral-900">{editing ? 'প্রোফাইল সম্পাদনা' : 'নতুন প্রোফাইল'}</h2>
        <button onClick={onClose} className="text-neutral-400 hover:text-neutral-700">
          <IconX className="w-5 h-5" />
        </button>
      </div>
      <form
        className="p-5"
        onSubmit={(e) => {
          e.preventDefault()
          void save(false)
        }}
      >
        {error && <Banner kind="error">{error}</Banner>}
        {similar && (
          <div className="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 mb-4 text-[13px] text-amber-800">
            <p className="font-semibold">এই নামে আগে থেকেই প্রোফাইল আছে মনে হচ্ছে:</p>
            <ul className="mt-1.5 space-y-1">
              {similar.map((s) => (
                <li key={s.id}>
                  <Link to={`/app/profile-desk/${s.id}`} className="underline" onClick={onClose}>
                    {s.name}
                  </Link>
                  {s.title ? ` — ${s.title}` : ''}
                </li>
              ))}
            </ul>
            <div className="mt-2.5 flex gap-2">
              <Button size="sm" onClick={() => void save(true)} disabled={saving}>
                তবুও নতুন বানান
              </Button>
              <Button size="sm" variant="secondary" onClick={() => setSimilar(null)}>
                ফিরে যান
              </Button>
            </div>
          </div>
        )}

        <div className="mb-4">
          <span className="block text-[12.5px] font-medium text-neutral-600 mb-1.5">ধরন</span>
          <div className="flex flex-wrap gap-1.5">
            {[...persons, ...orgs].map((c) => (
              <button
                key={c.key}
                type="button"
                onClick={() => setCategory(c.key)}
                className={`px-2.5 py-1 rounded-full text-[12.5px] border ${
                  category === c.key ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-neutral-600 border-neutral-200 hover:bg-neutral-50'
                }`}
              >
                {c.label}
              </button>
            ))}
          </div>
        </div>

        <div className="grid sm:grid-cols-2 gap-x-4">
          <Field label="নাম (বাংলা)" required>
            <input className={inputClass} value={nameBn} onChange={(e) => setNameBn(e.target.value)} placeholder={kind === 'org' ? 'যেমন: জাতীয় নাগরিক পার্টি' : 'যেমন: নাহিদ ইসলাম'} />
          </Field>
          <Field label="নাম (ইংরেজি)">
            <input className={inputClass} value={nameEn} onChange={(e) => setNameEn(e.target.value)} placeholder={kind === 'org' ? 'National Citizen Party' : 'Nahid Islam'} />
          </Field>
          <Field label="অন্য নাম / ডাকনাম / সংক্ষেপ (কমা দিয়ে)">
            <input className={inputClass} value={aliases} onChange={(e) => setAliases(e.target.value)} placeholder="এনসিপি, NCP" />
          </Field>
          <Field label={kind === 'org' ? 'পরিচয় (এক লাইনে)' : 'পদ / পরিচয়'}>
            <input className={inputClass} value={title} onChange={(e) => setTitle(e.target.value)} placeholder={kind === 'org' ? 'রাজনৈতিক দল' : 'আহ্বায়ক'} />
          </Field>
          {kind === 'person' ? (
            <Field label="দল / সংস্থা">
              <EntityPicker value={org} onChange={setOrg} kind="org" placeholder="দল বা সংস্থার নাম লিখুন" />
            </Field>
          ) : (
            <Field label="মূল সংস্থা (শাখা হলে)">
              <EntityPicker value={parent} onChange={setParent} kind="org" placeholder="যেমন: বাংলাদেশ পুলিশ" exclude={entity?.id} />
            </Field>
          )}
          <Field label="দেশ">
            <input className={inputClass} value={country} onChange={(e) => setCountry(e.target.value)} list="pd-countries" />
            <datalist id="pd-countries">
              {['বাংলাদেশ', 'ভারত', 'যুক্তরাষ্ট্র', 'যুক্তরাজ্য', 'পাকিস্তান', 'মিয়ানমার', 'চীন', ...meta.countries].filter((v, i, a) => a.indexOf(v) === i).map((c) => (
                <option key={c} value={c} />
              ))}
            </datalist>
          </Field>
          <Field label="জেলা">
            <input className={inputClass} value={district} onChange={(e) => setDistrict(e.target.value)} list="pd-districts" />
            <datalist id="pd-districts">
              {meta.districts.map((d) => (
                <option key={d} value={d} />
              ))}
            </datalist>
          </Field>
          <Field label="উপজেলা / এলাকা">
            <input className={inputClass} value={upazila} onChange={(e) => setUpazila(e.target.value)} />
          </Field>
          <Field label="ট্যাগ (কমা দিয়ে)">
            <input className={inputClass} value={tags} onChange={(e) => setTags(e.target.value)} placeholder="শীর্ষ নেতা, ঢাকা-১০" list="pd-tags" />
            <datalist id="pd-tags">
              {meta.tags.map((t) => (
                <option key={t} value={t} />
              ))}
            </datalist>
          </Field>
          <Field label="অবস্থা">
            <select className={inputClass} value={status} onChange={(e) => setStatus(e.target.value)}>
              {meta.statuses.map((s) => (
                <option key={s.key} value={s.key}>
                  {s.label}
                </option>
              ))}
            </select>
          </Field>
          <Field label="ফোন (অফিসিয়াল/প্রকাশ্য)">
            <input className={inputClass} value={phone} onChange={(e) => setPhone(e.target.value)} />
          </Field>
          <Field label="ইমেইল">
            <input className={inputClass} value={email} onChange={(e) => setEmail(e.target.value)} type="email" />
          </Field>
        </div>

        <div className="flex flex-wrap items-center gap-x-6 gap-y-3 mb-4">
          <div>
            <span className="block text-[12.5px] font-medium text-neutral-600 mb-1">গুরুত্ব</span>
            <Stars n={importance} onChange={setImportance} size={20} />
          </div>
          <div>
            <span className="block text-[12.5px] font-medium text-neutral-600 mb-1">কে দেখবে</span>
            <div className="flex gap-1.5">
              {(
                [
                  ['normal', 'ডেস্কের সবাই'],
                  ['restricted', 'শুধু পাবলিশার ও আমি'],
                ] as const
              ).map(([k, l]) => (
                <button
                  key={k}
                  type="button"
                  onClick={() => setSensitivity(k)}
                  className={`px-2.5 py-1 rounded-full text-[12.5px] border ${
                    sensitivity === k ? 'bg-neutral-900 text-white border-neutral-900' : 'bg-white text-neutral-600 border-neutral-200'
                  }`}
                >
                  {l}
                </button>
              ))}
            </div>
          </div>
        </div>

        {!editing && (
          <Field label="সোশ্যাল মিডিয়া ও অন্যান্য লিংক (একসাথে পেস্ট করুন, প্রতি লাইনে একটা)">
            <textarea
              className={`${inputClass} min-h-[84px] font-mono text-[12.5px]`}
              value={links}
              onChange={(e) => setLinks(e.target.value)}
              placeholder={'https://facebook.com/...\nhttps://youtube.com/@...\nhttps://x.com/...'}
            />
            {pasted.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1.5">
                {pasted.map((u) => {
                  const p = detectPlatform(u)
                  return (
                    <span key={u} className="inline-flex items-center gap-1 pl-1 pr-2 py-0.5 rounded-full text-[11.5px] bg-neutral-50 border border-neutral-200">
                      <PlatformIcon platform={p} className="w-3.5 h-3.5" style={{ color: PLATFORM_COLOR[p] }} />
                      {PLATFORM_LABEL[p]}
                    </span>
                  )
                })}
              </div>
            )}
          </Field>
        )}

        <Field label="পরিচিতি (প্রকাশ্য তথ্য, সূত্রসহ)">
          <textarea className={`${inputClass} min-h-[80px]`} value={bio} onChange={(e) => setBio(e.target.value)} />
        </Field>
        <Field label="গোপন নোট (সার্ভারে এনক্রিপ্ট করে রাখা হয়; সূত্রের পরিচয় এখানে না লিখে ভল্টে রাখুন)">
          <textarea className={`${inputClass} min-h-[70px] bg-amber-50/40`} value={note} onChange={(e) => setNote(e.target.value)} />
        </Field>

        <div className="flex justify-end gap-2 pt-2">
          <Button variant="secondary" onClick={onClose}>
            বাতিল
          </Button>
          <Button type="submit" disabled={saving || (!nameBn.trim() && !nameEn.trim())}>
            {saving ? 'সংরক্ষণ হচ্ছে…' : editing ? 'সংরক্ষণ' : 'প্রোফাইল বানান'}
          </Button>
        </div>
      </form>
    </Overlay>
  )
}

/** Type-ahead picker for another profile (an organisation for a person, a parent body for a branch). */
export function EntityPicker({
  value,
  onChange,
  kind,
  placeholder,
  exclude,
}: {
  value: PdRef | null
  onChange: (v: PdRef | null) => void
  kind?: 'person' | 'org'
  placeholder?: string
  exclude?: number
}) {
  const [q, setQ] = useState('')
  const [items, setItems] = useState<PdEntity[]>([])
  const [open, setOpen] = useState(false)
  useEffect(() => {
    if (!open) return
    const t = setTimeout(() => {
      ProfileDeskApi.list({ q, kind: kind ?? '', per_page: 8, sort: 'importance' })
        .then((r) => setItems(r.items.filter((i) => i.id !== exclude)))
        .catch(() => setItems([]))
    }, 200)
    return () => clearTimeout(t)
  }, [q, open, kind, exclude])
  if (value) {
    return (
      <div className={`${inputClass} flex items-center justify-between`}>
        <span className="truncate">{value.name}</span>
        <button type="button" className="text-neutral-400 hover:text-neutral-700" onClick={() => onChange(null)}>
          <IconX className="w-4 h-4" />
        </button>
      </div>
    )
  }
  return (
    <div className="relative">
      <input
        className={inputClass}
        value={q}
        placeholder={placeholder}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        onChange={(e) => setQ(e.target.value)}
      />
      {open && items.length > 0 && (
        <div className="absolute z-20 mt-1 w-full bg-white border border-neutral-200 rounded-xl shadow-lg max-h-56 overflow-y-auto">
          {items.map((i) => (
            <button
              key={i.id}
              type="button"
              className="w-full text-left px-3 py-2 hover:bg-neutral-50 text-[13px]"
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => {
                onChange({ id: i.id, name: i.name, kind: i.kind, category: i.category })
                setQ('')
                setOpen(false)
              }}
            >
              <span className="font-medium text-neutral-800">{i.name}</span>
              {i.title && <span className="text-neutral-400"> — {i.title}</span>}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
