// r30 — ফাইল বক্স: একটা প্রোফাইলের বা ভল্টের ফোল্ডার ও ফাইল। একসাথে অনেক ফাইল
// তোলা (অগ্রগতি সহ), ছবি/ভিডিও/অডিও/পিডিএফ এখানেই দেখা, নোট-সূত্র লেখা, সরানো, মোছা.
import { useRef, useState } from 'react'
import { ProfileDeskApi, bn, bnAgo, bnBytes, errText, type PdFile, type PdFolder, type PdRef } from '../../lib/profileDesk'
import { Banner, Button, Field, inputClass } from '../ui'
import { IconChevronRight, IconDownload, IconPencil, IconTrash, IconUpload, IconX } from '../Icon'
import { Overlay } from './bits'
import { EntityPicker } from './EntityForm'
import { IconFileText, IconFolder, IconFolderPlus, IconMusic, IconPlayerPlay } from './icons'

interface Job {
  key: string
  name: string
  size: number
  percent: number
  status: 'queued' | 'up' | 'done' | 'error'
  error?: string
  ctrl: AbortController
}

export default function FileBox({
  scope,
  entityId,
  folderId,
  folders,
  files,
  trail,
  allFolders,
  onNavigate,
  onReload,
  maxFile,
  emptyText,
}: {
  scope: PdFile['scope']
  entityId?: number
  folderId: number
  /** sub-folders of the current folder */
  folders: PdFolder[]
  /** files of the current folder */
  files: PdFile[]
  trail: { id: number; name: string }[]
  /** every folder (for "move to"); when missing only the current level is offered */
  allFolders?: PdFolder[]
  onNavigate: (folderId: number) => void
  onReload: () => void
  maxFile: number
  emptyText?: string
}) {
  const input = useRef<HTMLInputElement>(null)
  const [jobs, setJobs] = useState<Job[]>([])
  const [drag, setDrag] = useState(false)
  const [error, setError] = useState('')
  const [preview, setPreview] = useState<PdFile | null>(null)
  const [editing, setEditing] = useState<PdFile | null>(null)
  const [folderDlg, setFolderDlg] = useState<{ id?: number; name: string } | null>(null)
  const [filter, setFilter] = useState<'all' | PdFile['kind']>('all')

  async function uploadAll(list: FileList | File[]) {
    const arr = Array.from(list)
    if (!arr.length) return
    setError('')
    const fresh: Job[] = arr.map((f, i) => ({ key: `${Date.now()}-${i}-${f.name}`, name: f.name, size: f.size, percent: 0, status: 'queued', ctrl: new AbortController() }))
    setJobs((j) => [...j.filter((x) => x.status !== 'done'), ...fresh])
    const patch = (key: string, p: Partial<Job>) => setJobs((j) => j.map((x) => (x.key === key ? { ...x, ...p } : x)))
    // two at a time: quick for many small files, kind to a slow line
    let next = 0
    async function worker() {
      while (next < arr.length) {
        const i = next++
        const f = arr[i]
        const job = fresh[i]
        if (job.ctrl.signal.aborted) continue
        if (f.size > maxFile) {
          patch(job.key, { status: 'error', error: `সর্বোচ্চ ${bnBytes(maxFile)}` })
          continue
        }
        patch(job.key, { status: 'up' })
        try {
          await ProfileDeskApi.upload(f, { scope, entity_id: entityId, folder_id: folderId }, { onProgress: (p) => patch(job.key, { percent: p.percent }), signal: job.ctrl.signal })
          patch(job.key, { status: 'done', percent: 100 })
        } catch (e) {
          patch(job.key, { status: 'error', error: job.ctrl.signal.aborted ? 'বাতিল' : errText(e) })
        }
      }
    }
    await Promise.all([worker(), worker()])
    onReload()
    setTimeout(() => setJobs((j) => j.filter((x) => x.status !== 'done')), 2500)
  }

  async function act(fn: () => Promise<unknown>) {
    setError('')
    try {
      await fn()
      onReload()
    } catch (e) {
      setError(errText(e))
    }
  }

  async function download(f: PdFile) {
    try {
      const r = await ProfileDeskApi.downloadLink(f.id)
      const a = document.createElement('a')
      a.href = r.url
      a.rel = 'noopener'
      document.body.appendChild(a)
      a.click()
      a.remove()
    } catch (e) {
      setError(errText(e))
    }
  }

  const shown = filter === 'all' ? files : files.filter((f) => f.kind === filter)
  const kinds = Array.from(new Set(files.map((f) => f.kind)))
  const KIND_LABEL: Record<string, string> = { image: 'ছবি', video: 'ভিডিও', audio: 'অডিও', doc: 'নথি', other: 'অন্যান্য' }

  return (
    <div
      onDragOver={(e) => {
        e.preventDefault()
        setDrag(true)
      }}
      onDragLeave={() => setDrag(false)}
      onDrop={(e) => {
        e.preventDefault()
        setDrag(false)
        uploadAll(e.dataTransfer.files)
      }}
      className={`relative rounded-2xl ${drag ? 'ring-2 ring-brand-500 bg-brand-50/40' : ''}`}
    >
      <div className="flex flex-wrap items-center gap-2 mb-3">
        <nav className="flex items-center gap-1 text-[13px] min-w-0 flex-1">
          <button type="button" onClick={() => onNavigate(0)} className={`font-semibold ${trail.length ? 'text-brand-600 hover:underline' : 'text-neutral-900'}`}>
            সব ফাইল
          </button>
          {trail.map((t, i) => (
            <span key={t.id} className="flex items-center gap-1 min-w-0">
              <IconChevronRight className="w-3.5 h-3.5 text-neutral-400 shrink-0" />
              <button
                type="button"
                onClick={() => onNavigate(t.id)}
                className={`truncate ${i === trail.length - 1 ? 'font-semibold text-neutral-900' : 'text-brand-600 hover:underline'}`}
              >
                {t.name}
              </button>
            </span>
          ))}
        </nav>
        <Button size="sm" variant="secondary" onClick={() => setFolderDlg({ name: '' })}>
          <IconFolderPlus className="w-4 h-4" /> নতুন ফোল্ডার
        </Button>
        <Button size="sm" onClick={() => input.current?.click()} data-testid="pd-upload">
          <IconUpload className="w-4 h-4" /> ফাইল তুলুন
        </Button>
        <input
          ref={input}
          type="file"
          multiple
          className="hidden"
          onChange={(e) => {
            if (e.target.files) uploadAll(e.target.files)
            e.target.value = ''
          }}
        />
      </div>

      {error && (
        <div className="mb-3">
          <Banner kind="error">{error}</Banner>
        </div>
      )}

      {jobs.length > 0 && (
        <div className="mb-3 space-y-1.5">
          {jobs.map((j) => (
            <div key={j.key} className="flex items-center gap-2 rounded-lg bg-neutral-50 px-3 py-1.5 text-[12.5px]">
              <span className="truncate flex-1">{j.name}</span>
              <span className="text-neutral-400 shrink-0">{bnBytes(j.size)}</span>
              {j.status === 'error' ? (
                <span className="text-red-600 shrink-0">{j.error}</span>
              ) : j.status === 'done' ? (
                <span className="text-emerald-600 font-semibold shrink-0">✓ উঠেছে</span>
              ) : (
                <span className="w-28 h-1.5 rounded-full bg-neutral-200 overflow-hidden shrink-0">
                  <span className="block h-full bg-brand-600 transition-all" style={{ width: `${j.percent}%` }} />
                </span>
              )}
              {(j.status === 'up' || j.status === 'queued') && (
                <button type="button" className="text-neutral-400 hover:text-red-600" title="বাতিল" onClick={() => j.ctrl.abort()}>
                  <IconX className="w-3.5 h-3.5" />
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {kinds.length > 1 && (
        <div className="flex flex-wrap gap-1.5 mb-3">
          {(['all', ...kinds] as const).map((k) => (
            <button
              key={k}
              type="button"
              onClick={() => setFilter(k)}
              className={`px-2.5 py-1 rounded-full text-[12px] font-medium ${filter === k ? 'bg-neutral-900 text-white' : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'}`}
            >
              {k === 'all' ? `সব (${bn(files.length)})` : `${KIND_LABEL[k]} (${bn(files.filter((f) => f.kind === k).length)})`}
            </button>
          ))}
        </div>
      )}

      {folders.length === 0 && files.length === 0 ? (
        <button
          type="button"
          onClick={() => input.current?.click()}
          className="w-full rounded-2xl border-2 border-dashed border-neutral-200 py-10 text-center text-[13px] text-neutral-500 hover:border-brand-300 hover:bg-brand-50/30"
        >
          <IconUpload className="w-6 h-6 mx-auto mb-2 text-neutral-400" />
          {emptyText ?? 'এখনো কোনো ফাইল নেই।'} ফাইল এখানে টেনে আনুন বা চাপ দিন (সর্বোচ্চ {bnBytes(maxFile)})।
        </button>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-2.5">
          {folders.map((f) => (
            <div key={`f${f.id}`} className="group relative rounded-xl border border-neutral-200 bg-amber-50/40 hover:bg-amber-50 p-3">
              <button type="button" className="w-full text-left" onClick={() => onNavigate(f.id)}>
                <IconFolder className="w-8 h-8 text-amber-500" />
                <p className="mt-1.5 text-[13px] font-semibold text-neutral-800 truncate">{f.name}</p>
                <p className="text-[11.5px] text-neutral-500">
                  {f.count !== undefined ? `${bn(f.count)}টি ফাইল` : 'ফোল্ডার'}
                  {f.owner && scope === 'vault_public' ? ` · ${f.owner}` : ''}
                </p>
              </button>
              <div className="absolute top-2 right-2 hidden group-hover:flex gap-1">
                <button type="button" className="p-1 rounded bg-white shadow-sm text-neutral-500 hover:text-neutral-900" title="নাম বদলান" onClick={() => setFolderDlg({ id: f.id, name: f.name })}>
                  <IconPencil className="w-3.5 h-3.5" />
                </button>
                <button
                  type="button"
                  className="p-1 rounded bg-white shadow-sm text-neutral-500 hover:text-red-600"
                  title="মুছুন"
                  onClick={() => {
                    if (window.confirm(`"${f.name}" ফোল্ডার ও এর ভেতরের সব ফাইল মুছে যাবে। নিশ্চিত?`)) act(() => ProfileDeskApi.removeFolder(f.id))
                  }}
                >
                  <IconTrash className="w-3.5 h-3.5" />
                </button>
              </div>
            </div>
          ))}
          {shown.map((f) => (
            <div key={f.id} className="group relative rounded-xl border border-neutral-200 bg-white overflow-hidden">
              <button type="button" className="block w-full text-left" onClick={() => setPreview(f)} data-testid="pd-file">
                <Thumb f={f} />
                <div className="px-2.5 py-2">
                  <p className="text-[12.5px] font-semibold text-neutral-800 truncate" title={f.name}>
                    {f.name}
                  </p>
                  <p className="text-[11px] text-neutral-500 truncate">
                    {bnBytes(f.size)} · {bnAgo(f.created_at)}
                    {scope === 'vault_public' && f.owner ? ` · ${f.owner}` : ''}
                  </p>
                  {f.note && <p className="mt-0.5 text-[11px] text-neutral-600 line-clamp-2">{f.note}</p>}
                  {f.entity && scope !== 'entity' && <p className="mt-0.5 text-[11px] text-brand-700 truncate">↳ {f.entity.name}</p>}
                </div>
              </button>
              <div className="absolute top-1.5 right-1.5 hidden group-hover:flex gap-1">
                <button type="button" className="p-1 rounded bg-white/95 shadow-sm text-neutral-600 hover:text-neutral-900" title="নামিয়ে নিন" onClick={() => download(f)}>
                  <IconDownload className="w-3.5 h-3.5" />
                </button>
                <button type="button" className="p-1 rounded bg-white/95 shadow-sm text-neutral-600 hover:text-neutral-900" title="তথ্য বদলান" onClick={() => setEditing(f)}>
                  <IconPencil className="w-3.5 h-3.5" />
                </button>
                <button
                  type="button"
                  className="p-1 rounded bg-white/95 shadow-sm text-neutral-600 hover:text-red-600"
                  title="মুছুন"
                  onClick={() => {
                    if (window.confirm(`"${f.name}" মুছে যাবে, ফেরত আনা যাবে না। নিশ্চিত?`)) act(() => ProfileDeskApi.removeFile(f.id))
                  }}
                >
                  <IconTrash className="w-3.5 h-3.5" />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {drag && (
        <div className="pointer-events-none absolute inset-0 rounded-2xl flex items-center justify-center bg-brand-50/70 text-brand-700 font-semibold text-[14px]">
          ছেড়ে দিন, ফাইল উঠে যাবে
        </div>
      )}

      {preview && <Preview f={preview} onClose={() => setPreview(null)} onDownload={() => download(preview)} onEdit={() => (setEditing(preview), setPreview(null))} />}
      {editing && (
        <FileDetails
          f={editing}
          scope={scope}
          folders={allFolders ?? [...(trail.length > 1 ? [{ id: trail[trail.length - 2].id, name: `↑ ${trail[trail.length - 2].name}`, parent_id: 0 }] : []), ...(trail.length ? [{ id: trail[trail.length - 1].id, name: `• ${trail[trail.length - 1].name} (এখানেই)`, parent_id: 0 }] : []), ...folders]}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            onReload()
          }}
        />
      )}
      {folderDlg && (
        <Overlay onClose={() => setFolderDlg(null)}>
          <form
            className="p-5"
            onSubmit={(e) => {
              e.preventDefault()
              const name = folderDlg.name.trim()
              if (!name) return
              const id = folderDlg.id
              setFolderDlg(null)
              act(() => (id ? ProfileDeskApi.renameFolder(id, name) : ProfileDeskApi.createFolder({ scope, entity_id: entityId, parent_id: folderId, name })))
            }}
          >
            <h3 className="text-[15px] font-bold mb-3">{folderDlg.id ? 'ফোল্ডারের নাম বদলান' : 'নতুন ফোল্ডার'}</h3>
            <input autoFocus className={inputClass} value={folderDlg.name} onChange={(e) => setFolderDlg({ ...folderDlg, name: e.target.value })} placeholder="যেমন: ২০২৬ সমাবেশ" />
            <div className="mt-4 flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setFolderDlg(null)}>
                বাতিল
              </Button>
              <Button type="submit">রাখুন</Button>
            </div>
          </form>
        </Overlay>
      )}
    </div>
  )
}

function Thumb({ f }: { f: PdFile }) {
  if (f.kind === 'image') return <img src={f.url} alt="" loading="lazy" className="w-full h-28 object-cover bg-neutral-100" />
  const map = {
    video: { I: IconPlayerPlay, c: 'bg-neutral-900 text-white' },
    audio: { I: IconMusic, c: 'bg-violet-50 text-violet-600' },
    doc: { I: IconFileText, c: 'bg-blue-50 text-blue-600' },
    other: { I: IconFileText, c: 'bg-neutral-100 text-neutral-500' },
  }[f.kind] ?? { I: IconFileText, c: 'bg-neutral-100 text-neutral-500' }
  return (
    <div className={`w-full h-28 flex flex-col items-center justify-center ${map.c}`}>
      <map.I className="w-8 h-8" />
      <span className="mt-1 text-[11px] font-bold uppercase opacity-70">{f.ext}</span>
    </div>
  )
}

function Preview({ f, onClose, onDownload, onEdit }: { f: PdFile; onClose: () => void; onDownload: () => void; onEdit: () => void }) {
  const isPdf = f.mime === 'application/pdf'
  const isText = f.mime.startsWith('text/')
  return (
    <Overlay onClose={onClose} wide>
      <div className="flex items-center gap-2 px-4 py-3 border-b border-neutral-100 sticky top-0 bg-white z-10">
        <p className="font-semibold text-[14px] truncate flex-1">{f.name}</p>
        <Button size="sm" variant="secondary" onClick={onEdit}>
          <IconPencil className="w-3.5 h-3.5" /> তথ্য
        </Button>
        <Button size="sm" variant="secondary" onClick={onDownload}>
          <IconDownload className="w-3.5 h-3.5" /> নামান
        </Button>
        <button type="button" onClick={onClose} className="p-1 text-neutral-400 hover:text-neutral-800">
          <IconX className="w-5 h-5" />
        </button>
      </div>
      <div className="bg-neutral-950 flex items-center justify-center min-h-[200px]">
        {f.kind === 'image' ? (
          <img src={f.url} alt={f.name} className="max-h-[70vh] w-auto object-contain" />
        ) : f.kind === 'video' ? (
          <video src={f.url} controls autoPlay className="max-h-[70vh] w-full" />
        ) : f.kind === 'audio' ? (
          <audio src={f.url} controls autoPlay className="w-full m-6" />
        ) : isPdf || isText ? (
          <iframe src={f.url} title={f.name} className="w-full h-[70vh] bg-white" sandbox={isPdf ? undefined : ''} />
        ) : (
          <p className="text-neutral-300 text-[13px] py-14 px-6 text-center">এই ধরনের ফাইল এখানে দেখা যায় না। "নামান" চাপুন।</p>
        )}
      </div>
      <div className="px-4 py-3 text-[12.5px] text-neutral-600 space-y-1">
        <p>
          {bnBytes(f.size)} · তুলেছেন {f.owner || 'অজানা'} · {bnAgo(f.created_at)}
          {f.collected_on ? ` · সংগ্রহ: ${bn(f.collected_on)}` : ''}
        </p>
        {f.source && <p>সূত্র: {f.source}</p>}
        {f.note && <p className="whitespace-pre-wrap text-neutral-800">{f.note}</p>}
      </div>
    </Overlay>
  )
}

function FileDetails({
  f,
  scope,
  folders,
  onClose,
  onSaved,
}: {
  f: PdFile
  scope: PdFile['scope']
  folders: PdFolder[]
  onClose: () => void
  onSaved: () => void
}) {
  const [name, setName] = useState(f.name)
  const [note, setNote] = useState(f.note)
  const [source, setSource] = useState(f.source)
  const [collected, setCollected] = useState(f.collected_on ?? '')
  const [folder, setFolder] = useState(f.folder_id)
  const [entity, setEntity] = useState<PdRef | null>(f.entity)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function save() {
    setBusy(true)
    setError('')
    try {
      await ProfileDeskApi.updateFile(f.id, {
        name,
        note,
        source,
        collected_on: collected || null,
        folder_id: folder,
        ...(scope !== 'entity' ? { entity_id: entity?.id ?? 0 } : {}),
      })
      onSaved()
    } catch (e) {
      setError(errText(e))
      setBusy(false)
    }
  }
  return (
    <Overlay onClose={onClose}>
      <div className="p-5 space-y-3">
        <h3 className="text-[15px] font-bold">ফাইলের তথ্য</h3>
        {error && <Banner kind="error">{error}</Banner>}
        <Field label="নাম">
          <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} />
        </Field>
        <Field label="নোট (কী আছে, কেন রাখা)">
          <textarea className={`${inputClass} min-h-[80px]`} value={note} onChange={(e) => setNote(e.target.value)} />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="সূত্র">
            <input className={inputClass} value={source} onChange={(e) => setSource(e.target.value)} placeholder="কোথা থেকে পাওয়া" />
          </Field>
          <Field label="সংগ্রহের তারিখ">
            <input type="date" className={inputClass} value={collected} onChange={(e) => setCollected(e.target.value)} />
          </Field>
        </div>
        <Field label="ফোল্ডার">
          <select className={inputClass} value={folder} onChange={(e) => setFolder(Number(e.target.value))}>
            <option value={0}>— মূল জায়গা —</option>
            {folders
              .filter((x) => x.id !== 0)
              .map((x) => (
                <option key={x.id} value={x.id}>
                  {x.name}
                </option>
              ))}
          </select>
        </Field>
        {scope !== 'entity' && (
          <Field label="কার সাথে যুক্ত (ঐচ্ছিক)">
            <EntityPicker value={entity} onChange={setEntity} placeholder="প্রোফাইল খুঁজুন…" />
          </Field>
        )}
        <div className="flex justify-end gap-2 pt-1">
          <Button variant="secondary" onClick={onClose}>
            বাতিল
          </Button>
          <Button onClick={save} disabled={busy || !name.trim()}>
            রাখুন
          </Button>
        </div>
      </div>
    </Overlay>
  )
}
