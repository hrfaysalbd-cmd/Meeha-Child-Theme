/**
 * r46 — more footage for the same news. After the first video is up a ＋
 * button appears; several clips can also be picked at once. Each clip goes
 * through the same quick-copy upload as the main video (one at a time, so
 * the line is not split), and the editor gets them all, in this order, to
 * join with transitions in the publisher's studio.
 */
import { useEffect, useRef, useState, type MutableRefObject } from 'react'
import { isAllowedVideoFile, formatBytes, describeError } from '../lib/api'
import { uploadClip, type ClipStep } from '../lib/clipPipeline'
import { bnNumber } from './ui'
import { IconPlus, IconX, IconCheck, IconRefresh } from './Icon'

export interface ExtraClip {
  key: string
  file: File
  name: string
  thumb?: string
  state: 'waiting' | 'working' | 'done' | 'error'
  step?: ClipStep
  id?: number
  timing?: string
  error?: string
}

interface Props {
  /** The main video is ready (the ＋ shows from then on). */
  mainReady: boolean
  serverMax: number
  /** Ids already saved on the post (beyond the main video), shown as done. */
  initialIds?: { id: number; url: string; name: string }[]
  onChange: (ids: number[], busy: boolean) => void
  /** Lets the main picker hand over clips 2…n of a multi-select. */
  addRef: MutableRefObject<((files: File[]) => void) | null>
}

const MAX_CLIPS = 19

export default function FootageExtras({ mainReady, serverMax, initialIds, onChange, addRef }: Props) {
  const [clips, setClips] = useState<ExtraClip[]>(() =>
    (initialIds ?? []).map((x) => ({ key: `s${x.id}`, file: new File([], x.name), name: x.name, state: 'done' as const, id: x.id, thumb: undefined })),
  )
  const input = useRef<HTMLInputElement>(null)
  const ctrl = useRef<AbortController | null>(null)
  const working = useRef(false)

  const add = (files: File[]) => {
    const ok = files.filter(isAllowedVideoFile)
    setClips((p) => [
      ...p,
      ...ok.slice(0, Math.max(0, MAX_CLIPS - p.length)).map((f) => ({ key: `${Date.now()}-${Math.random()}`, file: f, name: f.name, state: 'waiting' as const, thumb: URL.createObjectURL(f) })),
    ])
  }
  addRef.current = add

  // one clip at a time
  useEffect(() => {
    if (working.current) return
    const next = clips.find((c) => c.state === 'waiting')
    if (!next) return
    working.current = true
    const c = new AbortController()
    ctrl.current = c
    const set = (patch: Partial<ExtraClip>) => setClips((p) => p.map((x) => (x.key === next.key ? { ...x, ...patch } : x)))
    set({ state: 'working' })
    uploadClip(next.file, serverMax, (step) => set({ step }), c.signal)
      .then((r) => set({ state: 'done', id: r.id, timing: r.timing + (r.hdPending ? ' · HD পেছনে যাচ্ছে' : '') }))
      .catch((err) => {
        if (c.signal.aborted) return
        set({ state: 'error', error: describeError(err).message })
      })
      .finally(() => {
        working.current = false
        ctrl.current = null
        // wake the effect for the next waiting clip
        setClips((p) => [...p])
      })
  }, [clips, serverMax])

  useEffect(() => () => ctrl.current?.abort(), [])

  const ids = clips.filter((c) => c.state === 'done' && c.id).map((c) => c.id!)
  const busy = clips.some((c) => c.state === 'waiting' || c.state === 'working')
  const sig = ids.join(',') + (busy ? '+' : '')
  useEffect(() => {
    onChange(ids, busy)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sig])

  const move = (i: number, d: -1 | 1) =>
    setClips((p) => {
      const j = i + d
      if (j < 0 || j >= p.length) return p
      const n = [...p]
      ;[n[i], n[j]] = [n[j], n[i]]
      return n
    })
  const remove = (k: string) => {
    const c = clips.find((x) => x.key === k)
    if (c?.state === 'working') ctrl.current?.abort()
    setClips((p) => p.filter((x) => x.key !== k))
  }

  if (!mainReady && clips.length === 0) return null

  return (
    <div className="mt-3 rounded-xl border border-neutral-200 p-3" data-testid="footage-extras">
      <div className="flex items-center gap-2 mb-2">
        <p className="flex-1 text-[13px] font-semibold text-neutral-800">
          আরও ফুটেজ {clips.length > 0 && <span className="text-neutral-400 font-normal">({bnNumber(clips.length + 1)}টি ক্লিপ মিলিয়ে)</span>}
        </p>
        <input
          ref={input}
          type="file"
          multiple
          accept="video/mp4,video/webm,video/quicktime,.mp4,.m4v,.webm,.mov"
          className="hidden"
          data-testid="footage-file"
          onChange={(e) => {
            add(Array.from(e.target.files || []))
            e.target.value = ''
          }}
        />
      </div>
      {clips.length > 0 && (
        <ol className="space-y-2 mb-2">
          {clips.map((c, i) => (
            <li key={c.key} className={`flex items-center gap-2.5 rounded-lg p-2 ${c.state === 'error' ? 'bg-red-50' : 'bg-neutral-50'}`} data-testid="footage-item">
              <span className="w-6 text-center text-[12px] font-bold text-neutral-400">{bnNumber(i + 2)}</span>
              {c.thumb ? <video src={c.thumb} muted preload="metadata" className="w-12 h-12 rounded-md object-cover bg-black" /> : <span className="w-12 h-12 rounded-md bg-neutral-200" />}
              <div className="flex-1 min-w-0">
                <p className="text-[12.5px] font-medium text-neutral-800 truncate">{c.name}</p>
                <p className="text-[11px] text-neutral-500 tabular-nums">
                  {c.state === 'waiting'
                    ? 'লাইনে আছে…'
                    : c.state === 'working'
                      ? c.step?.phase === 'shrink'
                        ? `ছোট হচ্ছে ${bnNumber(c.step.percent)}% · ${c.step.note ?? ''}`
                        : c.step?.phase === 'send'
                          ? `পাঠানো ${bnNumber(c.step.percent)}% · ${formatBytes(c.step.loaded)} / ${formatBytes(c.step.total)}${c.step.status ? ' · ' + c.step.status : ''}`
                          : 'প্রস্তুত হচ্ছে…'
                      : c.state === 'done'
                        ? c.timing ?? 'পৌঁছেছে'
                        : c.error}
                </p>
                {c.state === 'working' && (
                  <div className="mt-1 h-1.5 rounded-full bg-neutral-200 overflow-hidden">
                    <div className={`h-full ${c.step?.phase === 'shrink' ? 'bg-amber-500' : 'bg-brand-600'}`} style={{ width: `${c.step?.percent ?? 0}%` }} />
                  </div>
                )}
              </div>
              {c.state === 'done' && <IconCheck className="w-4 h-4 text-emerald-600 shrink-0" />}
              {c.state === 'error' && (
                <button type="button" onClick={() => setClips((p) => p.map((x) => (x.key === c.key ? { ...x, state: 'waiting', error: undefined } : x)))} className="w-8 h-8 rounded-full bg-white text-red-700 flex items-center justify-center" aria-label="আবার চেষ্টা">
                  <IconRefresh className="w-4 h-4" />
                </button>
              )}
              <div className="flex flex-col">
                <button type="button" onClick={() => move(i, -1)} disabled={i === 0} className="w-7 h-5 text-[11px] text-neutral-500 disabled:opacity-30" aria-label="ওপরে">
                  ▲
                </button>
                <button type="button" onClick={() => move(i, 1)} disabled={i === clips.length - 1} className="w-7 h-5 text-[11px] text-neutral-500 disabled:opacity-30" aria-label="নিচে">
                  ▼
                </button>
              </div>
              <button type="button" onClick={() => remove(c.key)} className="w-8 h-8 rounded-full bg-white text-neutral-500 flex items-center justify-center" aria-label="সরান">
                <IconX className="w-4 h-4" />
              </button>
            </li>
          ))}
        </ol>
      )}
      {clips.length < MAX_CLIPS && (
        <button
          type="button"
          onClick={() => input.current?.click()}
          disabled={!mainReady}
          className="w-full min-h-[48px] flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-brand-200 bg-brand-50/50 text-brand-700 text-[13.5px] font-semibold disabled:opacity-50"
          data-testid="footage-add"
        >
          <span className="w-7 h-7 rounded-full bg-brand-600 text-white flex items-center justify-center">
            <IconPlus className="w-4 h-4" />
          </span>
          আরও ফুটেজ যোগ করুন (একসাথে কয়েকটা বাছতে পারবেন)
        </button>
      )}
      <p className="text-[11px] text-neutral-500 mt-2">ক্লিপগুলো এই ক্রমে জোড়া লাগবে। এডিটর ট্রানজিশন দিয়ে মিলিয়ে ফাইনাল ভিডিও বানাবেন।</p>
    </div>
  )
}
