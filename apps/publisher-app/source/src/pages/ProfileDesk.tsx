/**
 * r30 — প্রোফাইল ডেস্ক (newsroom-os 1.15.0).
 *
 * Profiles of political figures, activists, media houses, parties and law-enforcement bodies with
 * their social links; one-click "open them all" per platform for a group or a search; new-post /
 * new-video counts on the platform icons for watched links (YouTube, Telegram, RSS: automatic;
 * Facebook, X, TikTok…: an "আজ দেখা বাকি" list); encrypted files under each profile; a PIN + OTP
 * vault (private and shared); access and log for the publisher.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import {
  ProfileDeskApi,
  bn,
  bnAgo,
  bnBytes,
  errText,
  getVaultToken,
  setVaultToken,
  type EntityKind,
  type PdAccessRow,
  type PdAlerts,
  type PdAuditRow,
  type PdEntity,
  type PdGroup,
  type PdListParams,
  type PdMeta,
  type PdRef,
  type PdVaultList,
} from '../lib/profileDesk'
import { ApiError } from '../lib/api'
import { Badge, Banner, Button, Card, PageHeader, Spinner, inputClass } from '../components/ui'
import { IconCheck, IconPlus, IconRefresh, IconSearch, IconTrash, IconX } from '../components/Icon'
import { Avatar, LinkChip, LinkChips, OpenAllBar, Overlay, PLATFORM_LABEL, PLATFORM_ORDER, STATUS_TONE, Stars, catLabel, statusLabel } from '../components/profileDesk/bits'
import EntityForm, { EntityPicker } from '../components/profileDesk/EntityForm'
import FileBox from '../components/profileDesk/FileBox'
import VaultGate, { VaultLockBar } from '../components/profileDesk/VaultGate'
import { IconAddressBook, IconBell, IconFilter, IconShieldLock, IconUsersGroup } from '../components/profileDesk/icons'

type Tab = 'profiles' | 'alerts' | 'groups' | 'vault' | 'admin'
const GROUP_COLORS = ['#DC2626', '#2563EB', '#059669', '#D97706', '#7C3AED', '#0891B2', '#DB2777', '#475569']

export default function ProfileDesk() {
  const [params, setParams] = useSearchParams()
  const tab = (params.get('tab') as Tab) || 'profiles'
  const [meta, setMeta] = useState<PdMeta | null>(null)
  const [denied, setDenied] = useState(false)
  const [error, setError] = useState('')
  const [creating, setCreating] = useState<EntityKind | null>(null)
  const navigate = useNavigate()

  const loadMeta = useCallback(() => {
    ProfileDeskApi.meta()
      .then((m) => {
        setMeta(m)
        // the server forgot this tab's token (idle / locked elsewhere)
        if (!m.vault.open && getVaultToken()) setVaultToken(null)
      })
      .catch((e) => {
        if (e instanceof ApiError && e.status === 403) setDenied(true)
        else setError(errText(e))
      })
  }, [])
  useEffect(loadMeta, [loadMeta])

  const setTab = (t: Tab) => {
    const p = new URLSearchParams(params)
    if (t === 'profiles') p.delete('tab')
    else p.set('tab', t)
    setParams(p, { replace: true })
  }

  if (denied)
    return (
      <div className="max-w-lg mx-auto mt-16 text-center">
        <IconShieldLock className="w-10 h-10 mx-auto text-neutral-400" />
        <h1 className="mt-3 text-[17px] font-bold">প্রোফাইল ডেস্কে আপনার প্রবেশাধিকার নেই</h1>
        <p className="mt-1 text-[13px] text-neutral-500">প্রকাশক "প্রবেশাধিকার" অংশ থেকে চালু করে দিলে এখানে আসতে পারবেন।</p>
      </div>
    )
  if (error && !meta) return <div className="mt-10 max-w-xl mx-auto"><Banner kind="error">{error}</Banner></div>
  if (!meta) return <Spinner className="mt-16" />

  const TABS: { key: Tab; label: string; icon: typeof IconBell; n?: number }[] = [
    { key: 'profiles', label: 'প্রোফাইল', icon: IconAddressBook, n: meta.totals.person + meta.totals.org },
    { key: 'alerts', label: 'এলার্ট', icon: IconBell, n: meta.unseen },
    { key: 'groups', label: 'গ্রুপ', icon: IconUsersGroup },
    { key: 'vault', label: 'গোপন ভল্ট', icon: IconShieldLock },
    ...(meta.is_boss ? [{ key: 'admin' as Tab, label: 'প্রবেশাধিকার ও লগ', icon: IconFilter }] : []),
  ]

  return (
    <div className="max-w-[1400px] mx-auto">
      <PageHeader
        title="প্রোফাইল ডেস্ক"
        subtitle={`${bn(meta.totals.person)} জন ব্যক্তি · ${bn(meta.totals.org)}টি সংস্থা — খুঁজুন, এক ক্লিকে খুলুন, নতুন পোস্টের খবর পান`}
        actions={
          <div className="flex gap-2">
            <Button variant="secondary" className="hidden sm:inline-flex" onClick={() => setCreating('org')}>
              <IconPlus className="w-4 h-4" /> সংস্থা
            </Button>
            <Button onClick={() => setCreating('person')} data-testid="pd-new">
              <IconPlus className="w-4 h-4" /> নতুন প্রোফাইল
            </Button>
          </div>
        }
      />
      <div className="flex gap-1 overflow-x-auto border-b border-neutral-200 mb-4 -mx-1 px-1">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={`shrink-0 inline-flex items-center gap-1.5 px-3 py-2 text-[13.5px] font-semibold border-b-2 -mb-px ${
              tab === t.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-neutral-500 hover:text-neutral-800'
            }`}
          >
            <t.icon className="w-4 h-4" />
            {t.label}
            {!!t.n && t.key === 'alerts' ? (
              <span className="min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10.5px] leading-[18px]">{bn(t.n)}</span>
            ) : t.n ? (
              <span className="text-[11.5px] text-neutral-400 font-medium">{bn(t.n)}</span>
            ) : null}
          </button>
        ))}
      </div>

      {tab === 'profiles' && <ProfilesTab meta={meta} />}
      {tab === 'alerts' && <AlertsTab onChanged={loadMeta} />}
      {tab === 'groups' && <GroupsTab />}
      {tab === 'vault' && <VaultTab meta={meta} onState={loadMeta} />}
      {tab === 'admin' && meta.is_boss && <AdminTab meta={meta} onChanged={loadMeta} />}

      {creating && (
        <EntityForm
          meta={meta}
          defaultKind={creating}
          onClose={() => setCreating(null)}
          onSaved={(e) => {
            setCreating(null)
            navigate(`/app/profile-desk/${e.id}`)
          }}
        />
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Profiles
// ---------------------------------------------------------------------------

function ProfilesTab({ meta }: { meta: PdMeta }) {
  const [f, setF] = useState<PdListParams>({ sort: 'importance', per_page: 30 })
  const [q, setQ] = useState('')
  const [items, setItems] = useState<PdEntity[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [pages, setPages] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [groups, setGroups] = useState<PdGroup[]>([])
  const [showFilters, setShowFilters] = useState(false)
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [groupDlg, setGroupDlg] = useState(false)
  const search = useRef<HTMLInputElement>(null)

  // "/" jumps to the search box from anywhere on the page
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== '/' || e.ctrlKey || e.metaKey) return
      const t = e.target as HTMLElement
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return
      e.preventDefault()
      search.current?.focus()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  useEffect(() => {
    const t = setTimeout(() => setF((x) => (x.q === q ? x : { ...x, q })), 250)
    return () => clearTimeout(t)
  }, [q])

  const loadGroups = useCallback(() => {
    ProfileDeskApi.groups().then(setGroups).catch(() => {})
  }, [])
  useEffect(loadGroups, [loadGroups])

  const load = useCallback(
    (p: number) => {
      setLoading(true)
      setError('')
      ProfileDeskApi.list({ ...f, page: p })
        .then((r) => {
          setItems((old) => (p === 1 ? r.items : [...old, ...r.items]))
          setTotal(r.total)
          setPage(r.page)
          setPages(r.pages)
        })
        .catch((e) => setError(errText(e)))
        .finally(() => setLoading(false))
    },
    [f],
  )
  useEffect(() => {
    load(1)
    setPicked(new Set())
  }, [load])

  const set = (patch: Partial<PdListParams>) => setF((x) => ({ ...x, ...patch }))
  const cats = meta.categories.filter((c) => !f.kind || c.kind === f.kind)
  const activeGroup = groups.find((g) => g.id === f.group)
  const activeCount = [f.category, f.district, f.country, f.status, f.tag, f.importance, f.platform, f.alert, f.files, f.group].filter(Boolean).length

  return (
    <div>
      {/* search + kind */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 min-w-[240px]">
          <IconSearch className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" />
          <input
            ref={search}
            className={`${inputClass} pl-9 pr-16 py-2.5`}
            placeholder="নাম, ডাকনাম, পদ, ফোন, লিংক বা হ্যান্ডেল লিখুন / পেস্ট করুন…"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            data-testid="pd-search"
          />
          <kbd className="absolute right-3 top-1/2 -translate-y-1/2 text-[11px] text-neutral-400 border border-neutral-200 rounded px-1.5">/</kbd>
        </div>
        <div className="inline-flex rounded-lg bg-neutral-100 p-0.5 text-[13px]">
          {([['', 'সব'], ['person', 'ব্যক্তি'], ['org', 'সংস্থা']] as const).map(([k, l]) => (
            <button
              key={k}
              type="button"
              onClick={() => set({ kind: k, category: f.kind === k ? f.category : '' })}
              className={`px-3 py-1.5 rounded-md font-medium ${(f.kind ?? '') === k ? 'bg-white shadow-sm text-neutral-900' : 'text-neutral-500'}`}
            >
              {l}
            </button>
          ))}
        </div>
        <Button variant={showFilters || activeCount ? 'primary' : 'secondary'} onClick={() => setShowFilters((s) => !s)}>
          <IconFilter className="w-4 h-4" /> ফিল্টার{activeCount ? ` (${bn(activeCount)})` : ''}
        </Button>
        <select className="rounded-lg border border-neutral-200 bg-white px-3 py-2 text-[13.5px]" value={f.sort} onChange={(e) => set({ sort: e.target.value as PdListParams['sort'] })}>
          <option value="importance">গুরুত্ব অনুযায়ী</option>
          <option value="updated">সর্বশেষ বদল</option>
          <option value="name">নাম অনুযায়ী</option>
        </select>
      </div>

      {/* category chips */}
      <div className="mt-3 flex gap-1.5 overflow-x-auto pb-1">
        <Chip on={!f.category} onClick={() => set({ category: '' })}>
          সব ধরন
        </Chip>
        {cats.map((c) => (
          <Chip key={c.key} on={f.category === c.key} onClick={() => set({ category: f.category === c.key ? '' : c.key })}>
            {c.label}
          </Chip>
        ))}
      </div>

      {showFilters && (
        <Card className="mt-3 p-3">
          <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2">
            <select className={inputClass} value={f.district ?? ''} onChange={(e) => set({ district: e.target.value })}>
              <option value="">সব জেলা</option>
              {meta.districts.map((d) => (
                <option key={d}>{d}</option>
              ))}
            </select>
            <select className={inputClass} value={f.country ?? ''} onChange={(e) => set({ country: e.target.value })}>
              <option value="">সব দেশ</option>
              {meta.countries.map((d) => (
                <option key={d}>{d}</option>
              ))}
            </select>
            <select className={inputClass} value={f.status ?? ''} onChange={(e) => set({ status: e.target.value })}>
              <option value="">যেকোনো অবস্থা</option>
              {meta.statuses.map((s) => (
                <option key={s.key} value={s.key}>
                  {s.label}
                </option>
              ))}
            </select>
            <select className={inputClass} value={f.tag ?? ''} onChange={(e) => set({ tag: e.target.value })}>
              <option value="">সব ট্যাগ</option>
              {meta.tags.map((d) => (
                <option key={d}>{d}</option>
              ))}
            </select>
            <select className={inputClass} value={f.platform ?? ''} onChange={(e) => set({ platform: e.target.value })}>
              <option value="">যেকোনো প্ল্যাটফর্ম</option>
              {PLATFORM_ORDER.map((p) => (
                <option key={p} value={p}>
                  {PLATFORM_LABEL[p]} আছে
                </option>
              ))}
            </select>
            <select className={inputClass} value={f.importance ?? 0} onChange={(e) => set({ importance: Number(e.target.value) })}>
              <option value={0}>যেকোনো গুরুত্ব</option>
              {[5, 4, 3].map((n) => (
                <option key={n} value={n}>
                  {bn(n)} তারা বা বেশি
                </option>
              ))}
            </select>
          </div>
          <div className="mt-2 flex flex-wrap items-center gap-3 text-[13px]">
            <label className="inline-flex items-center gap-1.5">
              <input type="checkbox" checked={!!f.alert} onChange={(e) => set({ alert: e.target.checked })} /> এলার্ট চালু আছে
            </label>
            <label className="inline-flex items-center gap-1.5">
              <input type="checkbox" checked={!!f.files} onChange={(e) => set({ files: e.target.checked })} /> ফাইল আছে
            </label>
            <span className="flex-1" />
            {activeCount > 0 && (
              <button type="button" className="text-brand-600 font-semibold" onClick={() => setF({ sort: f.sort, per_page: 30, kind: f.kind, q: f.q })}>
                সব ফিল্টার মুছুন
              </button>
            )}
          </div>
        </Card>
      )}

      {/* groups: one tap filters to the group and shows its "open all" bar */}
      {groups.length > 0 && (
        <div className="mt-3 flex items-center gap-1.5 overflow-x-auto pb-1">
          <IconUsersGroup className="w-4 h-4 text-neutral-400 shrink-0" />
          {groups.map((g) => (
            <button
              key={g.id}
              type="button"
              onClick={() => set({ group: f.group === g.id ? 0 : g.id })}
              className={`shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[12.5px] font-medium border ${
                f.group === g.id ? 'text-white border-transparent' : 'bg-white text-neutral-700 border-neutral-200 hover:bg-neutral-50'
              }`}
              style={f.group === g.id ? { background: g.color || '#111' } : undefined}
            >
              <span className="w-2 h-2 rounded-full" style={{ background: f.group === g.id ? '#fff' : g.color || '#999' }} />
              {g.name} <span className="opacity-70">{bn(g.members.length)}</span>
            </button>
          ))}
        </div>
      )}

      {activeGroup && (
        <Card className="mt-3 p-3 border-l-4" >
          <div className="flex items-center gap-2 mb-1">
            <p className="font-bold text-[14px]" style={{ color: activeGroup.color }}>
              {activeGroup.name}
            </p>
            <span className="text-[12px] text-neutral-500">{bn(activeGroup.members.length)} জন</span>
          </div>
          <OpenAllBar people={activeGroup.members} />
        </Card>
      )}

      {error && (
        <div className="mt-3">
          <Banner kind="error">{error}</Banner>
        </div>
      )}

      <div className="mt-4 flex flex-wrap items-center gap-2 text-[12.5px] text-neutral-500">
        <span>
          {bn(total)}টি ফলাফল{f.q ? ` "${f.q}"` : ''}
        </span>
        {!activeGroup && items.length > 1 && items.length <= 40 && (
          <span className="ml-2">
            <OpenAllBar people={items} compact />
          </span>
        )}
      </div>

      {picked.size > 0 && (
        <div className="sticky top-2 z-20 mt-2 flex flex-wrap items-center gap-2 rounded-xl bg-neutral-900 text-white px-3 py-2 text-[13px] shadow-lg">
          <span className="font-semibold">{bn(picked.size)}টি বাছাই</span>
          <Button size="sm" variant="secondary" onClick={() => setGroupDlg(true)}>
            <IconUsersGroup className="w-4 h-4" /> গ্রুপে যোগ করুন
          </Button>
          <OpenAllBar people={items.filter((i) => picked.has(i.id))} compact />
          <span className="flex-1" />
          <button type="button" className="text-white/70 hover:text-white" onClick={() => setPicked(new Set())}>
            <IconX className="w-4 h-4" />
          </button>
        </div>
      )}

      <div className="mt-3 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
        {items.map((e) => (
          <EntityCard
            key={e.id}
            e={e}
            meta={meta}
            picked={picked.has(e.id)}
            onPick={() =>
              setPicked((s) => {
                const n = new Set(s)
                if (n.has(e.id)) n.delete(e.id)
                else n.add(e.id)
                return n
              })
            }
          />
        ))}
      </div>
      {loading && <Spinner className="mt-6" />}
      {!loading && !items.length && !error && (
        <div className="mt-8 text-center text-[13.5px] text-neutral-500">
          {f.q || activeCount ? 'কিছু পাওয়া যায়নি। বানান একটু বদলে বা ফিল্টার কমিয়ে দেখুন।' : 'এখনো কোনো প্রোফাইল নেই। ডানে উপরে "নতুন প্রোফাইল" চাপুন।'}
        </div>
      )}
      {!loading && page < pages && (
        <div className="mt-4 text-center">
          <Button variant="secondary" onClick={() => load(page + 1)}>
            আরও দেখান ({bn(total - items.length)})
          </Button>
        </div>
      )}

      {groupDlg && (
        <AddToGroup
          groups={groups}
          ids={Array.from(picked)}
          onClose={() => setGroupDlg(false)}
          onDone={() => {
            setGroupDlg(false)
            setPicked(new Set())
            loadGroups()
          }}
        />
      )}
    </div>
  )
}

function Chip({ on, onClick, children }: { on: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`shrink-0 px-3 py-1 rounded-full text-[12.5px] font-medium ${on ? 'bg-neutral-900 text-white' : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'}`}
    >
      {children}
    </button>
  )
}

function EntityCard({ e, meta, picked, onPick }: { e: PdEntity; meta: PdMeta; picked: boolean; onPick: () => void }) {
  const unseen = e.links.reduce((s, l) => s + l.unseen.posts + l.unseen.videos, 0)
  return (
    <div className={`relative rounded-2xl border bg-white p-3.5 transition ${picked ? 'border-brand-500 ring-2 ring-brand-500/20' : 'border-neutral-200 hover:border-neutral-300 hover:shadow-sm'}`}>
      <button
        type="button"
        onClick={onPick}
        title="বাছাই করুন"
        className={`absolute top-3 right-3 w-5 h-5 rounded-md border inline-flex items-center justify-center ${picked ? 'bg-brand-600 border-brand-600 text-white' : 'border-neutral-300 bg-white text-transparent hover:border-neutral-400'}`}
      >
        <IconCheck className="w-3.5 h-3.5" />
      </button>
      <Link to={`/app/profile-desk/${e.id}`} className="flex gap-3 pr-6" data-testid="pd-card">
        <Avatar name={e.name} photo={e.photo} kind={e.kind} size={52} />
        <div className="min-w-0 flex-1">
          <p className="font-bold text-[14.5px] text-neutral-900 truncate">
            {e.name}
            {e.sensitivity === 'restricted' && <IconShieldLock className="inline w-3.5 h-3.5 ml-1 text-amber-600 -mt-0.5" />}
          </p>
          {e.name_en && e.name_bn && <p className="text-[11.5px] text-neutral-400 truncate">{e.name_en}</p>}
          <p className="text-[12.5px] text-neutral-600 truncate">
            {e.title}
            {e.title && e.org ? ' · ' : ''}
            {e.org?.name}
            {!e.title && !e.org && e.parent ? `↳ ${e.parent.name}` : ''}
          </p>
          <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
            <span className="px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600">{catLabel(meta, e.category)}</span>
            {e.status !== 'active' && <span className={`px-1.5 py-0.5 rounded ${STATUS_TONE[e.status] ?? 'bg-neutral-100'}`}>{statusLabel(meta, e.status)}</span>}
            {(e.district || (e.country && e.country !== 'বাংলাদেশ')) && <span className="text-neutral-500">{e.district || e.country}</span>}
            <Stars n={e.importance} size={11} />
            {!!e.files && <span className="text-neutral-500">📎 {bn(e.files)}</span>}
            {unseen > 0 && <span className="px-1.5 py-0.5 rounded bg-red-50 text-red-600 font-semibold">{bn(unseen)} নতুন</span>}
          </div>
        </div>
      </Link>
      {e.links.length > 0 && (
        <div className="mt-3 pt-3 border-t border-neutral-100">
          <LinkChips links={e.links} />
        </div>
      )}
      {e.tags.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1">
          {e.tags.slice(0, 5).map((t) => (
            <span key={t} className="text-[11px] text-brand-700 bg-brand-50 rounded px-1.5 py-0.5">
              #{t}
            </span>
          ))}
        </div>
      )}
    </div>
  )
}

function AddToGroup({ groups, ids, onClose, onDone }: { groups: PdGroup[]; ids: number[]; onClose: () => void; onDone: () => void }) {
  const [name, setName] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function run(fn: () => Promise<unknown>) {
    setBusy(true)
    try {
      await fn()
      onDone()
    } catch (e) {
      setError(errText(e))
      setBusy(false)
    }
  }
  return (
    <Overlay onClose={onClose}>
      <div className="p-5">
        <h3 className="text-[15px] font-bold">{bn(ids.length)}টি প্রোফাইল গ্রুপে যোগ</h3>
        {error && (
          <div className="mt-2">
            <Banner kind="error">{error}</Banner>
          </div>
        )}
        <div className="mt-3 space-y-1.5 max-h-64 overflow-y-auto">
          {groups.map((g) => (
            <button
              key={g.id}
              type="button"
              disabled={busy}
              onClick={() => run(() => ProfileDeskApi.updateGroup(g.id, { add: ids }))}
              className="w-full flex items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2 text-left text-[13.5px] hover:bg-neutral-50"
            >
              <span className="w-2.5 h-2.5 rounded-full" style={{ background: g.color || '#999' }} />
              <span className="flex-1 font-medium">{g.name}</span>
              <span className="text-neutral-400 text-[12px]">{bn(g.members.length)} জন</span>
            </button>
          ))}
        </div>
        <form
          className="mt-4 flex gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (name.trim()) run(() => ProfileDeskApi.createGroup({ name: name.trim(), color: GROUP_COLORS[groups.length % GROUP_COLORS.length], entity_ids: ids }))
          }}
        >
          <input className={inputClass} placeholder="নতুন গ্রুপের নাম, যেমন: এনসিপি শীর্ষ নেতা" value={name} onChange={(e) => setName(e.target.value)} />
          <Button type="submit" disabled={busy || !name.trim()}>
            বানান
          </Button>
        </form>
      </div>
    </Overlay>
  )
}

// ---------------------------------------------------------------------------
// Alerts
// ---------------------------------------------------------------------------

function AlertsTab({ onChanged }: { onChanged: () => void }) {
  const [data, setData] = useState<PdAlerts | null>(null)
  const [error, setError] = useState('')
  const [checking, setChecking] = useState(false)
  const [notice, setNotice] = useState('')
  const load = useCallback(() => {
    ProfileDeskApi.alerts()
      .then(setData)
      .catch((e) => setError(errText(e)))
  }, [])
  useEffect(load, [load])

  async function checkNow() {
    setChecking(true)
    setNotice('')
    setError('')
    try {
      const r = await ProfileDeskApi.checkNow()
      setNotice(`${bn(r.checked)}টি লিংক দেখা হলো, ${bn(r.new)}টি নতুন পোস্ট/ভিডিও।`)
      load()
      onChanged()
    } catch (e) {
      setError(errText(e))
    } finally {
      setChecking(false)
    }
  }

  if (!data) return error ? <Banner kind="error">{error}</Banner> : <Spinner className="mt-10" />

  const withNew = data.entities.filter((e) => e.links.some((l) => l.unseen.posts || l.unseen.videos))
  const manualDue = data.entities
    .map((e) => ({ ...e, links: e.links.filter((l) => !l.auto && !l.checked_today) }))
    .filter((e) => e.links.length)
  const autoOk = data.entities.filter((e) => !withNew.includes(e) && e.links.some((l) => l.auto))

  const refresh = () => {
    load()
    onChanged()
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <div className="text-[13px] text-neutral-600">
          {data.unseen ? (
            <span className="text-red-600 font-bold text-[15px]">{bn(data.unseen)}টি নতুন</span>
          ) : (
            <span className="font-semibold text-neutral-800">নতুন কিছু নেই</span>
          )}
          <span className="ml-2">· শেষ স্বয়ংক্রিয় খোঁজ: {data.last_run ? `${bnAgo(data.last_run)} (প্রতি ১৫ মিনিটে)` : 'এখনো হয়নি (সার্ভারের ক্রন চালু হলে প্রতি ১৫ মিনিটে চলবে)'}</span>
        </div>
        <span className="flex-1" />
        <Button variant="secondary" onClick={checkNow} disabled={checking}>
          <IconRefresh className={`w-4 h-4 ${checking ? 'animate-spin' : ''}`} /> এখনই খুঁজুন
        </Button>
      </div>
      {notice && <Banner kind="success">{notice}</Banner>}
      {error && <Banner kind="error">{error}</Banner>}
      {!data.cron_ok && <Banner kind="info">সার্ভারের স্বয়ংক্রিয় কাজ (WP-Cron) বন্ধ মনে হচ্ছে। তাহলে "এখনই খুঁজুন" চাপলেই কেবল নতুন পোস্ট ধরা পড়বে।</Banner>}

      {!data.entities.length && (
        <Card className="p-6 text-center text-[13.5px] text-neutral-600">
          কোনো লিংকে এলার্ট চালু নেই। যেকোনো প্রোফাইল খুলে লিংকের পাশে <b>🔔 পোস্ট</b> বা <b>▶ ভিডিও</b> টিক দিন।
          <p className="mt-2 text-[12.5px] text-neutral-500">
            ইউটিউব, টেলিগ্রাম পাবলিক চ্যানেল ও ওয়েবসাইটের (RSS) নতুন পোস্ট নিজে থেকে গোনা হয়। ফেসবুক, এক্স, টিকটক বাইরের কাউকে পোস্ট পড়তে দেয় না, তাই সেগুলো "আজ দেখা বাকি" তালিকায় আসে।
          </p>
        </Card>
      )}

      {withNew.length > 0 && (
        <section>
          <h3 className="text-[14px] font-bold mb-2">নতুন পোস্ট ও ভিডিও</h3>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
            {withNew.map((e) => (
              <Card key={e.id} className="p-3.5">
                <Link to={`/app/profile-desk/${e.id}`} className="flex items-center gap-2.5">
                  <Avatar name={e.name} photo={e.photo} size={36} />
                  <p className="font-bold text-[14px] truncate">{e.name}</p>
                </Link>
                <div className="mt-2 space-y-2">
                  {e.links
                    .filter((l) => l.unseen.posts || l.unseen.videos)
                    .map((l) => (
                      <div key={l.id} className="flex gap-2.5">
                        <div className="shrink-0 self-start">
                          <LinkChip link={l} onOpened={() => setTimeout(refresh, 600)} />
                        </div>
                        <ul className="flex-1 min-w-0 space-y-1">
                          {l.new_items.map((it) => (
                            <li key={it.id} className="text-[12.5px]">
                              <a href={it.url} target="_blank" rel="noopener noreferrer" className="hover:underline text-neutral-800 line-clamp-2">
                                {it.kind === 'video' ? '▶ ' : ''}
                                {it.title || '(শিরোনাম নেই)'}
                              </a>
                              <span className="text-[11px] text-neutral-400">{bnAgo(it.published_at)}</span>
                            </li>
                          ))}
                        </ul>
                      </div>
                    ))}
                </div>
              </Card>
            ))}
          </div>
        </section>
      )}

      {manualDue.length > 0 && (
        <section>
          <h3 className="text-[14px] font-bold">আজ দেখা বাকি</h3>
          <p className="text-[12.5px] text-neutral-500 mb-2">এই প্ল্যাটফর্মগুলোর নতুন পোস্ট বাইরে থেকে গোনা যায় না। একবার খুলে দেখলেই আজকের জন্য তালিকা থেকে সরে যাবে।</p>
          <Card className="p-3.5">
            <OpenAllBar people={manualDue} onOpened={() => setTimeout(refresh, 600)} />
            <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2.5">
              {manualDue.map((e) => (
                <div key={e.id} className="flex items-center gap-2">
                  <Link to={`/app/profile-desk/${e.id}`} className="text-[13px] font-semibold hover:underline">
                    {e.name}
                  </Link>
                  {e.links.map((l) => (
                    <LinkChip key={l.id} link={l} size="sm" onOpened={() => setTimeout(refresh, 600)} />
                  ))}
                </div>
              ))}
            </div>
          </Card>
        </section>
      )}

      {autoOk.length > 0 && (
        <section>
          <h3 className="text-[13px] font-semibold text-neutral-500 mb-2">নজরে আছে, নতুন কিছু নেই</h3>
          <div className="flex flex-wrap gap-2">
            {autoOk.map((e) => (
              <span key={e.id} className="inline-flex items-center gap-1.5 rounded-full border border-neutral-200 bg-white pl-1 pr-2 py-0.5 text-[12.5px]">
                <Avatar name={e.name} photo={e.photo} size={22} />
                <Link to={`/app/profile-desk/${e.id}`} className="hover:underline">
                  {e.name}
                </Link>
                {e.links
                  .filter((l) => l.feed_error)
                  .slice(0, 1)
                  .map((l) => (
                    <span key={l.id} className="text-amber-600" title={l.feed_error}>
                      ⚠
                    </span>
                  ))}
              </span>
            ))}
          </div>
        </section>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Groups
// ---------------------------------------------------------------------------

function GroupsTab() {
  const [groups, setGroups] = useState<PdGroup[] | null>(null)
  const [error, setError] = useState('')
  const [name, setName] = useState('')
  const [adding, setAdding] = useState<number | null>(null)
  const load = useCallback(() => {
    ProfileDeskApi.groups()
      .then(setGroups)
      .catch((e) => setError(errText(e)))
  }, [])
  useEffect(load, [load])
  async function act(fn: () => Promise<unknown>) {
    setError('')
    try {
      await fn()
      load()
    } catch (e) {
      setError(errText(e))
    }
  }
  if (!groups) return error ? <Banner kind="error">{error}</Banner> : <Spinner className="mt-10" />
  return (
    <div className="space-y-3">
      <p className="text-[13px] text-neutral-600">
        গ্রুপ বানিয়ে রাখলে (যেমন "এনসিপি শীর্ষ নেতা", "ডিএমপি কর্মকর্তা") এক ক্লিকে সবার ফেসবুক বা ইউটিউব একসাথে খোলা যায়। প্রোফাইলের তালিকায় টিক দিয়েও গ্রুপে যোগ করা যায়।
      </p>
      {error && <Banner kind="error">{error}</Banner>}
      <form
        className="flex gap-2 max-w-lg"
        onSubmit={(e) => {
          e.preventDefault()
          if (name.trim()) act(() => ProfileDeskApi.createGroup({ name: name.trim(), color: GROUP_COLORS[groups.length % GROUP_COLORS.length] })).then(() => setName(''))
        }}
      >
        <input className={inputClass} placeholder="নতুন গ্রুপের নাম" value={name} onChange={(e) => setName(e.target.value)} />
        <Button type="submit" disabled={!name.trim()}>
          <IconPlus className="w-4 h-4" /> গ্রুপ
        </Button>
      </form>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
        {groups.map((g) => (
          <Card key={g.id} className="p-4">
            <div className="flex items-center gap-2">
              <input
                type="color"
                value={g.color || '#475569'}
                onChange={(e) => act(() => ProfileDeskApi.updateGroup(g.id, { color: e.target.value }))}
                className="w-6 h-6 rounded border-0 p-0 bg-transparent cursor-pointer"
                title="রং"
              />
              <p className="font-bold text-[15px] flex-1 truncate">{g.name}</p>
              <span className="text-[12px] text-neutral-500">{bn(g.members.length)} জন</span>
              <button
                type="button"
                className="p-1 text-neutral-400 hover:text-neutral-800"
                title="নাম বদলান"
                onClick={() => {
                  const n = window.prompt('গ্রুপের নতুন নাম', g.name)
                  if (n && n.trim()) act(() => ProfileDeskApi.updateGroup(g.id, { name: n.trim() }))
                }}
              >
                ✎
              </button>
              <button
                type="button"
                className="p-1 text-neutral-400 hover:text-red-600"
                title="গ্রুপ মুছুন (প্রোফাইল থাকবে)"
                onClick={() => window.confirm(`"${g.name}" গ্রুপ মুছবেন? প্রোফাইলগুলো থাকবে।`) && act(() => ProfileDeskApi.removeGroup(g.id))}
              >
                <IconTrash className="w-4 h-4" />
              </button>
            </div>
            <div className="mt-2">
              <OpenAllBar people={g.members} />
            </div>
            <div className="mt-3 flex flex-wrap gap-1.5">
              {g.members.map((m) => (
                <span key={m.id} className="inline-flex items-center gap-1.5 rounded-full bg-neutral-50 border border-neutral-200 pl-0.5 pr-1.5 py-0.5 text-[12.5px]">
                  <Avatar name={m.name} photo={m.photo} size={22} />
                  <Link to={`/app/profile-desk/${m.id}`} className="hover:underline">
                    {m.name}
                  </Link>
                  <button type="button" className="text-neutral-400 hover:text-red-600" title="গ্রুপ থেকে সরান" onClick={() => act(() => ProfileDeskApi.updateGroup(g.id, { remove: [m.id] }))}>
                    <IconX className="w-3 h-3" />
                  </button>
                </span>
              ))}
              {adding === g.id ? (
                <div className="w-full mt-1">
                  <EntityPicker
                    value={null}
                    placeholder="নাম লিখে যোগ করুন…"
                    onChange={(v: PdRef | null) => {
                      if (v) act(() => ProfileDeskApi.updateGroup(g.id, { add: [v.id] }))
                    }}
                  />
                  <button type="button" className="mt-1 text-[12px] text-neutral-500" onClick={() => setAdding(null)}>
                    শেষ
                  </button>
                </div>
              ) : (
                <button type="button" className="text-[12.5px] text-brand-600 font-semibold px-2" onClick={() => setAdding(g.id)}>
                  + যোগ করুন
                </button>
              )}
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Vault
// ---------------------------------------------------------------------------

function VaultTab({ meta, onState }: { meta: PdMeta; onState: () => void }) {
  const [scope, setScope] = useState<'vault_private' | 'vault_public'>('vault_private')
  const [folder, setFolder] = useState(0)
  const [data, setData] = useState<PdVaultList | null>(null)
  const [error, setError] = useState('')
  const load = useCallback(() => {
    setError('')
    ProfileDeskApi.vault(scope, folder)
      .then((d) => {
        setData(d)
        if (!d.state.open && getVaultToken()) setVaultToken(null)
      })
      .catch((e) => setError(errText(e)))
  }, [scope, folder])
  useEffect(load, [load])

  if (!data) return error ? <Banner kind="error">{error}</Banner> : <Spinner className="mt-10" />
  if (!data.state.open)
    return (
      <div className="max-w-md mx-auto mt-4">
        <VaultGate
          state={data.state}
          onOpened={() => {
            load()
            onState()
          }}
        />
        <p className="mt-4 text-[12px] text-neutral-500 leading-relaxed">
          ভল্টের ফাইল সার্ভারে এনক্রিপ্ট করে রাখা হয়। পিন + কোড ছাড়া অ্যাপ থেকে খোলা যায় না। তবে মনে রাখবেন: সার্ভারের পুরো নিয়ন্ত্রণ যার হাতে (হোস্টিং অ্যাডমিন), তাত্ত্বিকভাবে সে চাবিও পেতে পারে।
          সবচেয়ে স্পর্শকাতর নথি আলাদা অফলাইন জায়গাতেও রাখুন।
        </p>
      </div>
    )
  const sum = data.summary ?? {}
  return (
    <div className="space-y-3">
      <VaultLockBar
        onLocked={() => {
          load()
          onState()
        }}
      />
      <div className="flex flex-wrap gap-2">
        {(
          [
            ['vault_private', 'আমার প্রাইভেট ভল্ট', 'শুধু আপনি দেখতে পান'],
            ['vault_public', 'শেয়ার্ড ভল্ট', 'প্রবেশাধিকার আছে এমন সবাই দেখেন ও রাখতে পারেন'],
          ] as const
        ).map(([k, l, d]) => (
          <button
            key={k}
            type="button"
            onClick={() => {
              setScope(k)
              setFolder(0)
            }}
            className={`text-left rounded-xl border px-3.5 py-2.5 min-w-[220px] ${scope === k ? 'border-neutral-900 bg-neutral-900 text-white' : 'border-neutral-200 bg-white hover:bg-neutral-50'}`}
          >
            <p className="font-bold text-[13.5px]">{l}</p>
            <p className={`text-[11.5px] ${scope === k ? 'text-white/70' : 'text-neutral-500'}`}>
              {d} · {bn(sum[k]?.files ?? 0)}টি ফাইল, {bnBytes(sum[k]?.bytes ?? 0)}
            </p>
          </button>
        ))}
      </div>
      {error && <Banner kind="error">{error}</Banner>}
      <Card className="p-4">
        <FileBox
          scope={scope}
          folderId={folder}
          folders={data.folders ?? []}
          files={data.files ?? []}
          trail={data.trail ?? []}
          onNavigate={setFolder}
          onReload={load}
          maxFile={meta.max_file}
          emptyText={scope === 'vault_private' ? 'আপনার প্রাইভেট ভল্ট খালি।' : 'শেয়ার্ড ভল্ট খালি।'}
        />
      </Card>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Access & log (publisher)
// ---------------------------------------------------------------------------

function AdminTab({ meta, onChanged }: { meta: PdMeta; onChanged: () => void }) {
  const [rows, setRows] = useState<PdAccessRow[] | null>(null)
  const [audit, setAudit] = useState<PdAuditRow[]>([])
  const [error, setError] = useState('')
  const [testing, setTesting] = useState(false)
  const [storage, setStorage] = useState(meta.storage)
  const load = useCallback(() => {
    ProfileDeskApi.access()
      .then(setRows)
      .catch((e) => setError(errText(e)))
    ProfileDeskApi.audit()
      .then(setAudit)
      .catch(() => {})
  }, [])
  useEffect(load, [load])
  const ACTION: Record<string, string> = useMemo(
    () => ({
      entity_create: 'প্রোফাইল বানিয়েছেন',
      entity_update: 'প্রোফাইল বদলেছেন',
      entity_delete: 'প্রোফাইল মুছেছেন',
      entity_view: 'স্পর্শকাতর প্রোফাইল দেখেছেন',
      file_upload: 'ফাইল তুলেছেন',
      file_download: 'ফাইল নামিয়েছেন',
      file_delete: 'ফাইল মুছেছেন',
      folder_delete: 'ফোল্ডার মুছেছেন',
      vault_unlock: 'ভল্ট খুলেছেন',
      vault_pin_set: 'পিন সেট করেছেন',
      vault_pin_fail: 'ভুল পিন দিয়েছেন',
      vault_pin_locked: 'পিন আটকে গেছে',
      vault_otp_fail: 'ভুল কোড দিয়েছেন',
      group_create: 'গ্রুপ বানিয়েছেন',
      access_grant: 'প্রবেশাধিকার দিয়েছেন',
      access_revoke: 'প্রবেশাধিকার তুলে নিয়েছেন',
      group_delete: 'গ্রুপ মুছেছেন',
    }),
    [],
  )
  async function test() {
    setTesting(true)
    try {
      setStorage(await ProfileDeskApi.storageTest())
      onChanged()
    } catch (e) {
      setError(errText(e))
    } finally {
      setTesting(false)
    }
  }
  return (
    <div className="space-y-4">
      {error && <Banner kind="error">{error}</Banner>}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="p-4 lg:col-span-2">
          <h3 className="font-bold text-[14px]">কারা প্রোফাইল ডেস্কে ঢুকতে পারবেন</h3>
          <p className="text-[12.5px] text-neutral-500 mb-3">প্রকাশকরা সবসময় পারেন। এডিটরদের এখান থেকে চালু করুন। বন্ধ করলে তার খোলা ভল্ট ও ফাইলের লিংক সাথে সাথে বাতিল হয়।</p>
          {!rows ? (
            <Spinner />
          ) : (
            <div className="divide-y divide-neutral-100">
              {rows.map((r) => (
                <div key={r.id} className="flex items-center gap-3 py-2">
                  <Avatar name={r.name} photo={r.avatar} size={32} />
                  <div className="flex-1 min-w-0">
                    <p className="text-[13.5px] font-semibold truncate">{r.name}</p>
                    <p className="text-[11.5px] text-neutral-500 truncate">
                      {r.role === 'publisher' ? 'প্রকাশক' : 'এডিটর'} · {r.email}
                    </p>
                  </div>
                  {r.fixed ? (
                    <Badge tone="emerald">সবসময়</Badge>
                  ) : (
                    <button
                      type="button"
                      role="switch"
                      aria-checked={r.access}
                      onClick={() =>
                        ProfileDeskApi.setAccess(r.id, !r.access)
                          .then(load)
                          .catch((e) => setError(errText(e)))
                      }
                      className={`w-11 h-6 rounded-full relative transition ${r.access ? 'bg-emerald-500' : 'bg-neutral-300'}`}
                    >
                      <span className={`absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all ${r.access ? 'left-[22px]' : 'left-0.5'}`} />
                    </button>
                  )}
                </div>
              ))}
            </div>
          )}
        </Card>
        <Card className="p-4">
          <h3 className="font-bold text-[14px]">ফাইল রাখার জায়গা</h3>
          <dl className="mt-2 text-[13px] space-y-1">
            <div className="flex justify-between">
              <dt className="text-neutral-500">ব্যবহৃত</dt>
              <dd className="font-semibold">
                {bnBytes(storage.used_bytes)} ({bn(storage.files)}টি)
              </dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-neutral-500">ডিস্কে খালি</dt>
              <dd className="font-semibold">{storage.free_bytes === null ? 'জানা যায়নি' : bnBytes(storage.free_bytes)}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-neutral-500">লেখা যায়</dt>
              <dd className="font-semibold">{storage.writable ? 'হ্যাঁ' : 'না'}</dd>
            </div>
          </dl>
          <div className="mt-3">
            {storage.exposure === 'ok' && <Banner kind="success">ফোল্ডারটা বাইরে থেকে সরাসরি খোলা যায় না।</Banner>}
            {storage.exposure === 'exposed' && (
              <Banner kind="error">
                সাবধান: ফাইলের ফোল্ডার ওয়েব থেকে সরাসরি খোলা যাচ্ছে (ফাইল এনক্রিপ্টেড, তবু বন্ধ করা দরকার)। Nginx-এ এটা যোগ করুন:
                <code className="block mt-1.5 p-2 rounded bg-white/70 text-[11.5px] text-neutral-800" dir="ltr">
                  location ^~ /wp-content/nros-private/ {'{'} deny all; {'}'}
                </code>
              </Banner>
            )}
            {storage.exposure === 'unknown' && <Banner kind="info">বাইরে থেকে খোলা যায় কি না এখনো পরীক্ষা হয়নি।</Banner>}
          </div>
          <Button size="sm" variant="secondary" className="mt-2" onClick={test} disabled={testing}>
            {testing ? 'পরীক্ষা চলছে…' : 'এখনই পরীক্ষা করুন'}
          </Button>
          {storage.checked_at && <p className="mt-1 text-[11px] text-neutral-400">শেষ পরীক্ষা {bnAgo(storage.checked_at)}</p>}
        </Card>
      </div>
      <Card className="p-4">
        <h3 className="font-bold text-[14px] mb-2">কে কখন কী করেছেন (সর্বশেষ ২০০)</h3>
        {!audit.length ? (
          <p className="text-[13px] text-neutral-500">এখনো কিছু নেই।</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-[12.5px]">
              <tbody className="divide-y divide-neutral-100">
                {audit.map((a) => (
                  <tr key={a.id}>
                    <td className="py-1.5 pr-3 whitespace-nowrap text-neutral-500">{bnAgo(a.created_at)}</td>
                    <td className="py-1.5 pr-3 font-semibold whitespace-nowrap">{a.user}</td>
                    <td className="py-1.5 pr-3">{ACTION[a.action] ?? a.action}</td>
                    <td className="py-1.5 pr-3 text-neutral-600 max-w-[340px] truncate">{a.detail}</td>
                    <td className="py-1.5 text-neutral-400 whitespace-nowrap" dir="ltr">
                      {a.ip}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
