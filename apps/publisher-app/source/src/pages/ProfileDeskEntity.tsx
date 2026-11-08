/**
 * r30 — একটা প্রোফাইলের পুরো পাতা (/app/profile-desk/:id): লিংক ও এলার্ট, নতুন পোস্ট,
 * গ্রুপ, অধীনস্থ ব্যক্তি/শাখা, পরিচিতি, গোপন নোট, ফাইল ও ভল্টের ফাইল.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ProfileDeskApi, bn, bnAgo, bnBytes, detectPlatform, errText, type PdEntityFull, type PdGroup, type PdLink, type PdMeta } from '../lib/profileDesk'
import { Banner, Button, Card, Spinner, inputClass } from '../components/ui'
import { IconChevronRight, IconCopy, IconPencil, IconPlus, IconTrash, IconUpload } from '../components/Icon'
import { Avatar, LinkChip, OpenAllBar, PLATFORM_LABEL, STATUS_TONE, Stars, catLabel, statusLabel } from '../components/profileDesk/bits'
import EntityForm from '../components/profileDesk/EntityForm'
import FileBox from '../components/profileDesk/FileBox'
import VaultGate from '../components/profileDesk/VaultGate'
import { IconBell, IconPlayerPlay, IconShieldLock, PLATFORM_COLOR, PlatformIcon } from '../components/profileDesk/icons'

export default function ProfileDeskEntity() {
  const { id } = useParams()
  const eid = Number(id)
  const navigate = useNavigate()
  const [meta, setMeta] = useState<PdMeta | null>(null)
  const [e, setE] = useState<PdEntityFull | null>(null)
  const [groups, setGroups] = useState<PdGroup[]>([])
  const [error, setError] = useState('')
  const [editing, setEditing] = useState(false)
  const [folder, setFolder] = useState(0)
  const photoInput = useRef<HTMLInputElement>(null)
  const [photoBusy, setPhotoBusy] = useState(false)

  const load = useCallback(() => {
    ProfileDeskApi.get(eid)
      .then(setE)
      .catch((x) => setError(errText(x)))
  }, [eid])
  useEffect(() => {
    setE(null)
    setFolder(0)
    load()
    ProfileDeskApi.meta().then(setMeta).catch(() => {})
    ProfileDeskApi.groups().then(setGroups).catch(() => {})
  }, [load])

  async function act(fn: () => Promise<unknown>) {
    setError('')
    try {
      await fn()
      load()
    } catch (x) {
      setError(errText(x))
    }
  }

  async function setPhoto(file: File) {
    if (!e) return
    setPhotoBusy(true)
    setError('')
    try {
      const r = await ProfileDeskApi.upload(file, { scope: 'entity', entity_id: e.id, note: 'প্রোফাইল ছবি' })
      await ProfileDeskApi.update(e.id, { photo_file_id: r.file.id })
      load()
    } catch (x) {
      setError(errText(x))
    } finally {
      setPhotoBusy(false)
    }
  }

  const files = e?.files
  const trail = useMemo(() => {
    if (!files || !folder) return []
    const out: { id: number; name: string }[] = []
    let cur = files.folders.find((f) => f.id === folder)
    for (let i = 0; cur && i < 12; i++) {
      out.unshift({ id: cur.id, name: cur.name })
      const pid: number = cur.parent_id
      cur = pid ? files.folders.find((f) => f.id === pid) : undefined
    }
    return out
  }, [files, folder])

  if (!e) return error ? <div className="max-w-xl mx-auto mt-10"><Banner kind="error">{error}</Banner></div> : <Spinner className="mt-16" />

  const subFolders = e.files.folders.filter((f) => f.parent_id === folder).map((f) => ({ ...f, count: e.files.items.filter((x) => x.folder_id === f.id).length }))
  const folderFiles = e.files.items.filter((x) => x.folder_id === folder)
  const totalBytes = e.files.items.reduce((s, f) => s + f.size, 0)

  return (
    <div className="max-w-6xl mx-auto">
      <nav className="flex items-center gap-1 text-[12.5px] text-neutral-500 mb-3">
        <Link to="/app/profile-desk" className="hover:text-neutral-900">
          প্রোফাইল ডেস্ক
        </Link>
        <IconChevronRight className="w-3.5 h-3.5" />
        <span className="truncate text-neutral-800">{e.name}</span>
      </nav>

      {error && (
        <div className="mb-3">
          <Banner kind="error">{error}</Banner>
        </div>
      )}

      {/* header */}
      <Card className="p-4 sm:p-5">
        <div className="flex flex-col sm:flex-row gap-4">
          <div className="relative shrink-0 self-start">
            <Avatar name={e.name} photo={e.photo} kind={e.kind} size={96} />
            {e.can_edit && (
              <button
                type="button"
                onClick={() => photoInput.current?.click()}
                className="absolute -bottom-1 -right-1 w-8 h-8 rounded-full bg-white shadow border border-neutral-200 inline-flex items-center justify-center text-neutral-600 hover:text-neutral-900"
                title="ছবি বদলান"
                disabled={photoBusy}
              >
                {photoBusy ? <Spinner className="w-4 h-4" /> : <IconUpload className="w-4 h-4" />}
              </button>
            )}
            <input
              ref={photoInput}
              type="file"
              accept="image/jpeg,image/png,image/webp,image/gif"
              className="hidden"
              onChange={(x) => {
                const f = x.target.files?.[0]
                if (f) setPhoto(f)
                x.target.value = ''
              }}
            />
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex flex-wrap items-start gap-2">
              <div className="min-w-0 flex-1">
                <h1 className="text-[21px] font-bold text-neutral-900 leading-tight">
                  {e.name}
                  {e.sensitivity === 'restricted' && (
                    <span className="ml-2 align-middle inline-flex items-center gap-1 text-[11.5px] font-semibold text-amber-700 bg-amber-50 rounded px-1.5 py-0.5">
                      <IconShieldLock className="w-3.5 h-3.5" /> স্পর্শকাতর
                    </span>
                  )}
                </h1>
                {e.name_en && e.name_bn && <p className="text-[13px] text-neutral-500">{e.name_en}</p>}
                {e.aliases && <p className="text-[12.5px] text-neutral-500">অন্য নাম: {e.aliases}</p>}
              </div>
              {e.can_edit && (
                <Button variant="secondary" onClick={() => setEditing(true)}>
                  <IconPencil className="w-4 h-4" /> সম্পাদনা
                </Button>
              )}
              {e.can_delete && (
                <Button
                  variant="danger"
                  onClick={() => {
                    if (window.confirm(`"${e.name}" প্রোফাইল, এর লিংক ও এর ফাইলগুলো মুছে যাবে। ভল্টের ফাইল থাকবে। নিশ্চিত?`))
                      ProfileDeskApi.remove(e.id)
                        .then(() => navigate('/app/profile-desk'))
                        .catch((x) => setError(errText(x)))
                  }}
                >
                  <IconTrash className="w-4 h-4" />
                </Button>
              )}
            </div>
            <p className="mt-1.5 text-[14px] text-neutral-700">
              {e.title}
              {e.org && (
                <>
                  {e.title ? ' · ' : ''}
                  <Link to={`/app/profile-desk/${e.org.id}`} className="text-brand-700 hover:underline font-medium">
                    {e.org.name}
                  </Link>
                </>
              )}
              {e.parent && (
                <>
                  {e.title || e.org ? ' · ' : ''}
                  অধীন:{' '}
                  <Link to={`/app/profile-desk/${e.parent.id}`} className="text-brand-700 hover:underline font-medium">
                    {e.parent.name}
                  </Link>
                </>
              )}
            </p>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-[12px]">
              <span className="px-2 py-0.5 rounded bg-neutral-100 text-neutral-700">{catLabel(meta, e.category)}</span>
              <span className={`px-2 py-0.5 rounded ${STATUS_TONE[e.status] ?? 'bg-neutral-100'}`}>{statusLabel(meta, e.status)}</span>
              {[e.upazila, e.district, e.division, e.country].filter(Boolean).length > 0 && (
                <span className="text-neutral-600">{[e.upazila, e.district, e.country].filter(Boolean).join(', ')}</span>
              )}
              <Stars n={e.importance} size={13} />
              {e.tags.map((t) => (
                <Link key={t} to={`/app/profile-desk`} className="text-brand-700 bg-brand-50 rounded px-1.5 py-0.5">
                  #{t}
                </Link>
              ))}
            </div>
            {(e.phone || e.email) && (
              <div className="mt-2 flex flex-wrap gap-3 text-[13px]">
                {e.phone && <Copyable label="ফোন" value={e.phone} href={`tel:${e.phone}`} />}
                {e.email && <Copyable label="ইমেইল" value={e.email} href={`mailto:${e.email}`} />}
              </div>
            )}
            {e.links.length > 1 && (
              <div className="mt-3">
                <OpenAllBar people={[e]} onOpened={() => setTimeout(load, 600)} />
              </div>
            )}
          </div>
        </div>
      </Card>

      <div className="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="lg:col-span-2 space-y-4">
          <LinksCard e={e} onChanged={load} onError={setError} />

          {e.recent_items.length > 0 && (
            <Card className="p-4">
              <h3 className="font-bold text-[14px] mb-2">সাম্প্রতিক পোস্ট ও ভিডিও</h3>
              <ul className="divide-y divide-neutral-100">
                {e.recent_items.map((it) => (
                  <li key={it.id} className="py-2 flex gap-2.5 items-start">
                    <span className="w-6 h-6 shrink-0 rounded-md inline-flex items-center justify-center" style={{ color: PLATFORM_COLOR[it.platform], background: `${PLATFORM_COLOR[it.platform]}14` }}>
                      {it.kind === 'video' ? <IconPlayerPlay className="w-3.5 h-3.5" /> : <PlatformIcon platform={it.platform} className="w-3.5 h-3.5" />}
                    </span>
                    <a href={it.url} target="_blank" rel="noopener noreferrer" className="flex-1 min-w-0 text-[13px] text-neutral-800 hover:underline line-clamp-2">
                      {it.title || '(শিরোনাম নেই)'}
                    </a>
                    <span className="text-[11.5px] text-neutral-400 whitespace-nowrap">{bnAgo(it.published_at)}</span>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          <Card className="p-4">
            <div className="flex items-center gap-2 mb-3">
              <h3 className="font-bold text-[14px] flex-1">ফাইল ও নথি</h3>
              <span className="text-[12px] text-neutral-500">
                {bn(e.files.items.length)}টি · {bnBytes(totalBytes)}
              </span>
            </div>
            <FileBox
              scope="entity"
              entityId={e.id}
              folderId={folder}
              folders={subFolders}
              files={folderFiles}
              trail={trail}
              allFolders={e.files.folders}
              onNavigate={setFolder}
              onReload={load}
              maxFile={meta?.max_file ?? 2 * 1024 ** 3}
              emptyText={`${e.name}-এর ছবি, ভিডিও, অডিও বা নথি রাখুন।`}
            />
          </Card>

          <Card className="p-4">
            <div className="flex items-center gap-2 mb-2">
              <IconShieldLock className="w-4 h-4" />
              <h3 className="font-bold text-[14px] flex-1">ভল্টে এর সাথে যুক্ত ফাইল</h3>
              <span className="text-[12px] text-neutral-500">{bn(e.files.vault.count)}টি</span>
            </div>
            {!e.files.vault.count ? (
              <p className="text-[12.5px] text-neutral-500">
                কিছু নেই। গোপন নথি <Link to="/app/profile-desk?tab=vault" className="text-brand-600 font-semibold">ভল্টে</Link> তুলে "কার সাথে যুক্ত" ঘরে এই প্রোফাইল বেছে দিন।
              </p>
            ) : !e.files.vault.open ? (
              meta && <VaultGate state={meta.vault} onOpened={load} compact />
            ) : (
              <ul className="divide-y divide-neutral-100">
                {e.files.vault.items.map((f) => (
                  <li key={f.id} className="py-2 flex items-center gap-2 text-[13px]">
                    <a href={f.url} target="_blank" rel="noopener noreferrer" className="flex-1 min-w-0 truncate hover:underline font-medium">
                      {f.name}
                    </a>
                    <span className="text-[11.5px] text-neutral-500 whitespace-nowrap">
                      {f.scope === 'vault_private' ? 'প্রাইভেট' : 'শেয়ার্ড'} · {bnBytes(f.size)}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>

        <div className="space-y-4">
          <Card className="p-4">
            <h3 className="font-bold text-[14px] mb-2">গ্রুপ</h3>
            {!groups.length ? (
              <p className="text-[12.5px] text-neutral-500">
                কোনো গ্রুপ নেই। <Link to="/app/profile-desk?tab=groups" className="text-brand-600 font-semibold">গ্রুপ বানান</Link>
              </p>
            ) : (
              <div className="flex flex-wrap gap-1.5">
                {groups.map((g) => {
                  const on = e.groups.includes(g.id)
                  return (
                    <button
                      key={g.id}
                      type="button"
                      onClick={() => act(() => ProfileDeskApi.updateGroup(g.id, on ? { remove: [e.id] } : { add: [e.id] }))}
                      className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[12.5px] font-medium border ${on ? 'text-white border-transparent' : 'bg-white text-neutral-600 border-neutral-200 hover:bg-neutral-50'}`}
                      style={on ? { background: g.color || '#111' } : undefined}
                    >
                      {on ? '✓' : '+'} {g.name}
                    </button>
                  )
                })}
              </div>
            )}
          </Card>

          {e.members.length > 0 && (
            <Card className="p-4">
              <h3 className="font-bold text-[14px] mb-2">
                {e.kind === 'org' ? 'এর সাথে যুক্ত' : 'অধীনে'} ({bn(e.members.length)})
              </h3>
              <ul className="space-y-1.5">
                {e.members.map((m) => (
                  <li key={m.id}>
                    <Link to={`/app/profile-desk/${m.id}`} className="flex items-center gap-2 text-[13px] hover:bg-neutral-50 rounded-lg px-1 py-0.5">
                      <Avatar name={m.name} kind={m.kind} size={26} />
                      <span className="min-w-0 flex-1">
                        <span className="font-semibold block truncate">{m.name}</span>
                        {m.title && <span className="text-[11.5px] text-neutral-500 block truncate">{m.title}</span>}
                      </span>
                      <Stars n={m.importance} size={10} />
                    </Link>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          {e.bio && (
            <Card className="p-4">
              <h3 className="font-bold text-[14px] mb-1.5">পরিচিতি</h3>
              <p className="text-[13px] text-neutral-700 whitespace-pre-wrap leading-relaxed">{e.bio}</p>
            </Card>
          )}
          {e.private_note && (
            <Card className="p-4 border-amber-200 bg-amber-50/40">
              <h3 className="font-bold text-[14px] mb-1.5 flex items-center gap-1.5">
                <IconShieldLock className="w-4 h-4 text-amber-600" /> গোপন নোট
              </h3>
              <p className="text-[13px] text-neutral-800 whitespace-pre-wrap leading-relaxed">{e.private_note}</p>
              <p className="mt-2 text-[11px] text-neutral-500">সার্ভারে এনক্রিপ্ট করে রাখা।</p>
            </Card>
          )}
          <p className="text-[11.5px] text-neutral-400 px-1">
            বানিয়েছেন {e.creator || 'অজানা'} · {bnAgo(e.created_at)} · শেষ বদল {bnAgo(e.updated_at)}
          </p>
        </div>
      </div>

      {editing && meta && (
        <EntityForm
          meta={meta}
          entity={e}
          onClose={() => setEditing(false)}
          onSaved={(x) => {
            setEditing(false)
            setE(x)
            load()
          }}
        />
      )}
    </div>
  )
}

function Copyable({ label, value, href }: { label: string; value: string; href: string }) {
  const [done, setDone] = useState(false)
  return (
    <span className="inline-flex items-center gap-1.5">
      <span className="text-neutral-500">{label}:</span>
      <a href={href} className="font-medium text-neutral-800 hover:underline" dir="ltr">
        {value}
      </a>
      <button
        type="button"
        className="text-neutral-400 hover:text-neutral-800"
        title="কপি"
        onClick={() => {
          navigator.clipboard?.writeText(value).then(() => {
            setDone(true)
            setTimeout(() => setDone(false), 1200)
          })
        }}
      >
        {done ? '✓' : <IconCopy className="w-3.5 h-3.5" />}
      </button>
    </span>
  )
}

function LinksCard({ e, onChanged, onError }: { e: PdEntityFull; onChanged: () => void; onError: (m: string) => void }) {
  const [adding, setAdding] = useState(false)
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const detected = text
    .split(/[\s,]+/)
    .map((s) => s.trim())
    .filter((s) => /\.[a-z]{2,}/i.test(s))

  async function act(fn: () => Promise<unknown>) {
    onError('')
    try {
      await fn()
      onChanged()
    } catch (x) {
      onError(errText(x))
    }
  }
  async function add() {
    setBusy(true)
    await act(() => ProfileDeskApi.addLinks(e.id, text))
    setBusy(false)
    setText('')
    setAdding(false)
  }

  return (
    <Card className="p-4">
      <div className="flex items-center gap-2 mb-1">
        <h3 className="font-bold text-[14px] flex-1">সোশ্যাল মিডিয়া ও লিংক</h3>
        {e.can_edit && (
          <Button size="sm" variant="secondary" onClick={() => setAdding((a) => !a)}>
            <IconPlus className="w-4 h-4" /> লিংক
          </Button>
        )}
      </div>
      <p className="text-[12px] text-neutral-500 mb-3">
        <IconBell className="inline w-3.5 h-3.5 -mt-0.5" /> টিক দিলে নতুন পোস্টের সংখ্যা আইকনের ওপর দেখাবে (ইউটিউব, টেলিগ্রাম, ওয়েবসাইট: নিজে থেকে)। ফেসবুক/এক্স/টিকটকে "প্রতিদিন দেখব" দিলে আজ না
        দেখা পর্যন্ত এলার্ট পাতায় থাকবে।
      </p>

      {adding && (
        <div className="mb-3 rounded-xl bg-neutral-50 p-3">
          <textarea
            autoFocus
            className={`${inputClass} min-h-[70px]`}
            placeholder="এক বা একাধিক লিংক পেস্ট করুন (প্রতি লাইনে একটা)"
            value={text}
            onChange={(x) => setText(x.target.value)}
          />
          {detected.length > 0 && (
            <div className="mt-2 flex flex-wrap gap-1.5">
              {detected.map((u, i) => {
                const p = detectPlatform(u)
                return (
                  <span key={i} className="inline-flex items-center gap-1 text-[11.5px] rounded-full px-2 py-0.5" style={{ color: PLATFORM_COLOR[p], background: `${PLATFORM_COLOR[p]}14` }}>
                    <PlatformIcon platform={p} className="w-3 h-3" /> {PLATFORM_LABEL[p]}
                  </span>
                )
              })}
            </div>
          )}
          <div className="mt-2 flex justify-end gap-2">
            <Button size="sm" variant="secondary" onClick={() => setAdding(false)}>
              বাতিল
            </Button>
            <Button size="sm" onClick={add} disabled={busy || !detected.length}>
              যোগ করুন
            </Button>
          </div>
        </div>
      )}

      {!e.links.length ? (
        <p className="text-[13px] text-neutral-500">কোনো লিংক নেই।</p>
      ) : (
        <ul className="divide-y divide-neutral-100">
          {e.links.map((l) => (
            <LinkRow key={l.id} l={l} canEdit={e.can_edit} act={act} />
          ))}
        </ul>
      )}
    </Card>
  )
}

function LinkRow({ l, canEdit, act }: { l: PdLink; canEdit: boolean; act: (fn: () => Promise<unknown>) => Promise<void> }) {
  const watched = l.watch.posts || l.watch.videos
  const canVideos = l.platform === 'youtube' || l.platform === 'telegram'
  return (
    <li className="py-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5">
      <LinkChip link={l} onOpened={() => setTimeout(() => act(async () => {}), 600)} />
      <div className="min-w-0 flex-1 basis-48">
        <p className="text-[13px] font-semibold text-neutral-800 truncate">
          {PLATFORM_LABEL[l.platform]}
          {l.handle && <span className="font-normal text-neutral-500"> · {l.handle}</span>}
          {l.label && <span className="font-normal text-neutral-500"> · {l.label}</span>}
        </p>
        <a href={l.url} target="_blank" rel="noopener noreferrer" className="block text-[11.5px] text-neutral-400 truncate hover:underline" dir="ltr">
          {l.url}
        </a>
        {watched && (
          <p className="text-[11px] mt-0.5">
            {l.auto ? (
              l.feed_error ? (
                <span className="text-amber-600">⚠ {l.feed_error}</span>
              ) : l.last_checked ? (
                <span className="text-neutral-500">শেষ খোঁজ {bnAgo(l.last_checked)}</span>
              ) : (
                <span className="text-neutral-500">শিগগির প্রথম খোঁজ হবে</span>
              )
            ) : l.checked_today ? (
              <span className="text-emerald-600">✓ আজ দেখা হয়েছে</span>
            ) : (
              <span className="text-amber-600">আজ দেখা বাকি</span>
            )}
          </p>
        )}
      </div>
      <div className="flex flex-wrap items-center gap-1.5 text-[12px]">
        {l.auto ? (
          <>
            <Toggle on={l.watch.posts} onClick={() => act(() => ProfileDeskApi.watch(l.id, { posts: !l.watch.posts, videos: l.watch.videos }))}>
              🔔 পোস্ট
            </Toggle>
            {canVideos && (
              <Toggle on={l.watch.videos} onClick={() => act(() => ProfileDeskApi.watch(l.id, { posts: l.watch.posts, videos: !l.watch.videos }))}>
                ▶ ভিডিও
              </Toggle>
            )}
          </>
        ) : (
          <Toggle on={watched} onClick={() => act(() => ProfileDeskApi.watch(l.id, { posts: !watched, videos: false }))}>
            🔔 প্রতিদিন দেখব
          </Toggle>
        )}
        {canEdit && (
          <>
            <select
              className="rounded-md border border-neutral-200 bg-white px-1.5 py-1 text-[11.5px]"
              value={l.verified}
              onChange={(x) => act(() => ProfileDeskApi.updateLink(l.id, { verified: Number(x.target.value) }))}
              title="যাচাই"
            >
              <option value={0}>যাচাই হয়নি</option>
              <option value={1}>✓ আসল</option>
              <option value={2}>✗ ভুয়া/বন্ধ</option>
            </select>
            <button
              type="button"
              className="p-1 text-neutral-400 hover:text-red-600"
              title="লিংক মুছুন"
              onClick={() => window.confirm('লিংকটা মুছবেন?') && act(() => ProfileDeskApi.removeLink(l.id))}
            >
              <IconTrash className="w-4 h-4" />
            </button>
          </>
        )}
      </div>
    </li>
  )
}

function Toggle({ on, onClick, children }: { on: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={on}
      className={`px-2 py-1 rounded-md border font-medium ${on ? 'bg-neutral-900 border-neutral-900 text-white' : 'bg-white border-neutral-200 text-neutral-600 hover:bg-neutral-50'}`}
    >
      {children}
    </button>
  )
}
