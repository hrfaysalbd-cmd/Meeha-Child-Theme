/**
 * r46.2 (Hafiz) — the publisher itself can attach several footage clips to one video news:
 * pick 2–3 files at once, add more later ("＋ আরও ভিডিও"), or take clips from the gallery.
 * Uploads run one after another (big files, shared line); each clip shrinks first when it is
 * over the server limit. The ids go to the editor, which sends `footage_ids` on save — after
 * that the "এক ভিডিওতে জোড়া লাগান" (FootageJoin) section appears, same as a correspondent's clips.
 */
import { useEffect, useRef, useState } from 'react'
import { MediaApi, type LibraryVideo, type VideoFootage } from '../../lib/api'
import { shrinkVideo } from '../../lib/videoEdit/compress'
import { bnFileSize } from '../../lib/postMeta'
import { Button, bnNumber } from '../ui'
import { IconSearch, IconUpload, IconX } from '../Icon'
import VideoLibraryPicker from './VideoLibraryPicker'

export interface FtItem {
  key: number
  name: string
  size: number
  status: 'queue' | 'shrink' | 'upload' | 'done' | 'error'
  percent: number
  id?: number
  file?: File
  msg?: string
}

let ftKey = 1

export default function FootagePanel({
  initial,
  mainId,
  maxBytes,
  accept,
  onChange,
  takeFiles,
  disabled,
}: {
  /** Clips already saved on the video (without the main clip). */
  initial: VideoFootage[]
  mainId: number | null
  maxBytes: number | null
  accept: string
  onChange: (ids: number[], busy: boolean) => void
  /** Files left over from a multi-pick on the main input — pulled once when the panel appears. */
  takeFiles?: () => File[]
  disabled?: boolean
}) {
  const [items, setItems] = useState<FtItem[]>(() =>
    initial.map((f) => ({ key: ftKey++, name: f.name || `ক্লিপ ${f.id}`, size: f.size, status: 'done' as const, percent: 100, id: f.id })),
  )
  const [libraryOpen, setLibraryOpen] = useState(false)
  const tookRef = useRef(false)
  const fileInput = useRef<HTMLInputElement>(null)
  const running = useRef(false)
  const jobs = useRef<{ key: number; file: File }[]>([])

  // Tell the editor which ids are ready and whether anything is still uploading.
  useEffect(() => {
    onChange(
      items.filter((i) => i.status === 'done' && i.id).map((i) => i.id!),
      items.some((i) => i.status === 'queue' || i.status === 'shrink' || i.status === 'upload'),
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [items])

  const patch = (key: number, p: Partial<FtItem>) => setItems((prev) => prev.map((i) => (i.key === key ? { ...i, ...p } : i)))

  // Extra files from one multi-pick on the main input.
  useEffect(() => {
    if (tookRef.current || !takeFiles) return
    tookRef.current = true
    const files = takeFiles()
    if (files.length) addFiles(files)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function runQueue() {
    if (running.current) return
    running.current = true
    try {
      while (jobs.current.length) {
        const { key, file } = jobs.current.shift()!
        try {
          let toSend: File = file
          if (maxBytes && file.size > maxBytes) {
            patch(key, { status: 'shrink', percent: 0 })
            try {
              const small = await shrinkVideo(file, Math.floor(maxBytes * 0.92), {
                onProgress: (r) => patch(key, { percent: Math.round(r * 100) }),
              })
              const base = file.name.replace(/\.[^.]+$/, '') || 'footage'
              toSend = new File([small.blob], `${base}.${small.ext}`, { type: small.mime })
            } catch {
              throw new Error(`ফাইলটি অনেক বড় (${bnFileSize(file.size)}) আর এই ব্রাউজারে ছোট করা গেল না।`)
            }
          }
          patch(key, { status: 'upload', percent: 0 })
          const res = await MediaApi.uploadResumable(toSend, {
            onProgress: (p) => patch(key, { percent: p.percent }),
          })
          patch(key, { status: 'done', percent: 100, id: res.id, file: undefined })
        } catch (err) {
          patch(key, { status: 'error', msg: err instanceof Error ? err.message : 'আপলোড হয়নি — আবার চেষ্টা করুন।' })
        }
      }
    } finally {
      running.current = false
      if (jobs.current.length) setTimeout(() => void runQueue(), 0)
    }
  }

  function addFiles(files: File[]) {
    if (!files.length) return
    const added = files.map((f) => ({ key: ftKey++, name: f.name, size: f.size, status: 'queue' as const, percent: 0, file: f }))
    setItems((prev) => [...prev, ...added])
    jobs.current.push(...added.map((a) => ({ key: a.key, file: a.file })))
    setTimeout(() => void runQueue(), 0)
  }

  function addLibrary(v: LibraryVideo) {
    setLibraryOpen(false)
    if (v.id === mainId || items.some((i) => i.id === v.id)) return
    setItems((prev) => [...prev, { key: ftKey++, name: v.title || `ক্লিপ ${v.id}`, size: v.size, status: 'done', percent: 100, id: v.id }])
  }

  const busy = items.some((i) => i.status === 'queue' || i.status === 'shrink' || i.status === 'upload')

  return (
    <div className="mt-3 rounded-xl border border-neutral-200 p-3" data-testid="footage-panel">
      <div className="flex items-center justify-between gap-3 flex-wrap mb-1">
        <p className="text-[13px] font-semibold text-neutral-900">
          আরও ভিডিও ফুটেজ {items.length > 0 && <span className="text-neutral-400 font-normal">({bnNumber(items.length)}টি)</span>}
        </p>
        <div className="flex items-center gap-1.5">
          <input
            ref={fileInput}
            type="file"
            accept={accept}
            multiple
            className="hidden"
            data-testid="footage-files"
            onChange={(e) => {
              addFiles(Array.from(e.target.files ?? []))
              e.target.value = ''
            }}
          />
          <Button size="sm" variant="secondary" disabled={disabled} onClick={() => fileInput.current?.click()} data-testid="footage-add">
            <IconUpload className="w-3.5 h-3.5" />＋ ফাইল
          </Button>
          <Button size="sm" variant="secondary" disabled={disabled} onClick={() => setLibraryOpen(true)}>
            <IconSearch className="w-3.5 h-3.5" />
            গ্যালারি থেকে
          </Button>
        </div>
      </div>
      <p className="text-[11.5px] text-neutral-500">
        একসাথে ২–৩টি ফাইলও বেছে নেওয়া যায়। মূল ভিডিওর পরে এগুলো ক্রমে বসবে; সেভ করলেই নিচে “এক ভিডিওতে জোড়া লাগান” অপশন আসবে।
      </p>
      {items.length > 0 && (
        <ul className="mt-2 space-y-1.5">
          {items.map((i) => (
            <li key={i.key} className="flex items-center gap-2 text-[12.5px]" data-testid="footage-item">
              <span className="min-w-0 flex-1 truncate text-neutral-800">
                {i.name} <span className="text-neutral-400">· {bnFileSize(i.size)}</span>
              </span>
              {i.status === 'done' ? (
                <span className="text-emerald-700 font-medium shrink-0">যুক্ত ✓</span>
              ) : i.status === 'error' ? (
                <span className="text-red-600 shrink-0 max-w-[45%] truncate" title={i.msg}>
                  {i.msg || 'হয়নি'}
                </span>
              ) : (
                <span className="text-neutral-500 shrink-0 tabular-nums">
                  {i.status === 'shrink' ? 'ছোট হচ্ছে' : i.status === 'queue' ? 'অপেক্ষায়' : 'আপলোড'} {i.status !== 'queue' ? `${bnNumber(i.percent)}%` : ''}
                </span>
              )}
              {i.status === 'error' && i.file && (
                <button type="button" className="text-brand-700 font-medium shrink-0" onClick={() => { patch(i.key, { status: 'queue', msg: undefined }); jobs.current.push({ key: i.key, file: i.file! }); setTimeout(() => void runQueue(), 0) }}>
                  আবার
                </button>
              )}
              <button
                type="button"
                aria-label="বাদ দিন"
                className="shrink-0 text-neutral-400 hover:text-red-600"
                onClick={() => { jobs.current = jobs.current.filter((j) => j.key !== i.key); setItems((prev) => prev.filter((x) => x.key !== i.key)) }}
                disabled={i.status === 'shrink' || i.status === 'upload'}
              >
                <IconX className="w-3.5 h-3.5" />
              </button>
            </li>
          ))}
        </ul>
      )}
      {busy && <p className="mt-1.5 text-[11.5px] text-amber-700">ফুটেজ আপলোড চলছে — শেষ হলে সেভ করুন।</p>}
      {libraryOpen && (
        <VideoLibraryPicker title="গ্যালারি থেকে ফুটেজ যোগ করুন" adsFirst={false} onSelect={addLibrary} onClose={() => setLibraryOpen(false)} />
      )}
    </div>
  )
}
