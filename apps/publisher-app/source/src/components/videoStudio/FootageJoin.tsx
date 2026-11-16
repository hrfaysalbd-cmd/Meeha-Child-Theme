/**
 * r46 — a correspondent sent several footage clips for one video news: this
 * panel (on the video page) puts them in order, picks a transition, shows
 * how it looks, and joins them into one file in the browser (join.ts). The
 * joined file goes up, becomes the video's main file (the footage list stays
 * as it is) and the editor opens in the studio.
 *
 * "স্বয়ংক্রিয় জোড়া" (remembered on this computer): the join starts by itself
 * as soon as the page opens, in sent order with a 0.6 s cross-fade, so the
 * desk can go straight to the studio.
 */
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminVideosApi, MediaApi, describeError, downloadMediaFile, isAbortError, type Video, type VideoFootage } from '../../lib/api'
import { bnDuration, bnFileSize } from '../../lib/postMeta'
import { exportCapability } from '../../lib/videoEdit/render'
import {
  DEFAULT_TRANSITION,
  DEFAULT_TRANSITION_DUR,
  TRANSITIONS,
  TRANSITION_MAX,
  TRANSITION_MIN,
  drawFitted,
  drawTransition,
  JoinCancelled,
  joinClips,
  planJoin,
  type TransitionKind,
} from '../../lib/videoEdit/join'
import { shrinkVideo } from '../../lib/videoEdit/compress'
import { Button, bnNumber } from '../ui'
import { IconMovie, IconPlayerPlay, IconWand, IconX } from '../Icon'

const AUTO_KEY = 'nros_pub_footage_auto'

interface Item {
  f: VideoFootage
  on: boolean
  trimStart: number
  trimEnd: number | null
  /** From the thumbnail's metadata. */
  dur: number | null
  w: number
  h: number
}

type Job =
  | { stage: 'download'; index: number; count: number; loaded: number; total: number }
  | { stage: 'render'; ratio: number; eta: number | null }
  | { stage: 'shrink'; ratio: number }
  | { stage: 'upload'; percent: number; eta: number | null; note?: string | null }
  | { stage: 'saving' }

type Done = { auto: boolean; at: number }

const fmtSec = (s: number) => bnNumber(s.toFixed(1))

function readAuto() {
  try {
    return localStorage.getItem(AUTO_KEY) !== '0'
  } catch {
    return true
  }
}

/** Is the video's main file one of the footage clips (not yet joined)? */
function mainIsFootage(v: Video) {
  const ids = new Set<number>()
  for (const f of v.footage ?? []) {
    ids.add(f.id)
    if (f.hd_id) ids.add(f.hd_id)
  }
  return !!v.video_attachment_id && ids.has(v.video_attachment_id)
}

export default function FootageJoin({ video, maxBytes, onSaved }: { video: Video; maxBytes: number | null; onSaved: (v: Video) => void }) {
  const navigate = useNavigate()
  const capability = useMemo(() => exportCapability(), [])
  const [items, setItems] = useState<Item[]>(() =>
    (video.footage ?? []).map((f) => ({ f, on: true, trimStart: 0, trimEnd: null, dur: null, w: 0, h: 0 })),
  )
  const [kind, setKind] = useState<TransitionKind>(DEFAULT_TRANSITION)
  const [tdur, setTdur] = useState(DEFAULT_TRANSITION_DUR)
  const [auto, setAuto] = useState(readAuto)
  const [job, setJob] = useState<Job | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState<Done | null>(null)
  const [joined, setJoined] = useState(() => !mainIsFootage(video))
  const [pair, setPair] = useState(0)
  const [dragIdx, setDragIdx] = useState<number | null>(null)
  const [trimOpen, setTrimOpen] = useState<number | null>(null)
  /** Settings changed after an automatic join: offer to join again. */
  const [changed, setChanged] = useState(false)
  const abortRef = useRef<AbortController | null>(null)
  const runId = useRef(0)

  const used = items.filter((x) => x.on)
  const running = job !== null
  const lengths = used.map((x) => Math.max(0, (x.trimEnd ?? x.dur ?? 0) - x.trimStart))
  const plan = used.every((x) => x.dur) ? planJoin(lengths, kind, tdur) : null
  const safePair = Math.min(pair, Math.max(0, used.length - 2))

  // the footage list changed on the server (another save): take it over
  const footageKey = (video.footage ?? []).map((f) => `${f.id}:${f.hd_id ?? ''}`).join(',')
  const lastKey = useRef(footageKey)
  useEffect(() => {
    if (lastKey.current === footageKey) return
    lastKey.current = footageKey
    setItems((cur) =>
      (video.footage ?? []).map((f) => {
        const old = cur.find((x) => x.f.id === f.id)
        return old ? { ...old, f } : { f, on: true, trimStart: 0, trimEnd: null, dur: null, w: 0, h: 0 }
      }),
    )
  }, [footageKey, video.footage])

  // don't close the tab while a join is running
  useEffect(() => {
    if (!running) return
    const h = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', h)
    return () => window.removeEventListener('beforeunload', h)
  }, [running])

  // leaving the page stops a running join
  useEffect(() => () => abortRef.current?.abort(), [])

  // স্বয়ংক্রিয় জোড়া: start as soon as the page opens (not when it is already joined)
  useEffect(() => {
    if (!readAuto() || !mainIsFootage(video) || !capability.ok) return
    const t = setTimeout(() => void run(true), 400)
    return () => {
      clearTimeout(t)
      abortRef.current?.abort()
    }
    // once, on open
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function edit(fn: (list: Item[]) => Item[]) {
    setItems(fn)
    if (done || joined) setChanged(true)
  }

  function move(from: number, to: number) {
    if (to < 0 || to >= items.length || from === to) return
    edit((list) => {
      const next = list.slice()
      const [x] = next.splice(from, 1)
      next.splice(to, 0, x)
      return next
    })
  }

  async function run(isAuto: boolean) {
    if (!capability.ok) {
      setError(capability.reason ?? 'এই ব্রাউজারে ভিডিও জোড়া দেওয়া যাবে না।')
      return
    }
    // read the latest list (an automatic start uses the sent order)
    const list = (isAuto ? (video.footage ?? []).map((f) => ({ f, on: true, trimStart: 0, trimEnd: null as number | null })) : items).filter((x) => x.on)
    if (list.length < 2) {
      setError('জোড়া দিতে অন্তত দুটি ফুটেজ রাখুন।')
      return
    }
    abortRef.current?.abort()
    const ac = new AbortController()
    abortRef.current = ac
    const my = ++runId.current
    const mine = () => my === runId.current
    const signal = ac.signal
    setError('')
    setDone(null)
    setChanged(false)
    try {
      // 1. the files — the full-quality original when it has arrived
      const blobs: Blob[] = []
      for (let i = 0; i < list.length; i++) {
        const f = list[i].f
        const id = f.hd_id && f.hd_url ? f.hd_id : f.id
        if (mine()) setJob({ stage: 'download', index: i, count: list.length, loaded: 0, total: f.size })
        blobs.push(await downloadMediaFile(id, (loaded, total) => mine() && setJob({ stage: 'download', index: i, count: list.length, loaded, total: total || f.size }), signal))
      }
      // 2. join
      if (mine()) setJob({ stage: 'render', ratio: 0, eta: null })
      const res = await joinClips({
        clips: blobs.map((blob, i) => ({ blob, trimStart: list[i].trimStart, trimEnd: list[i].trimEnd })),
        transition: isAuto ? DEFAULT_TRANSITION : kind,
        transitionDur: isAuto ? DEFAULT_TRANSITION_DUR : tdur,
        maxBytes,
        signal,
        onProgress: (p) => mine() && p.stage === 'render' && setJob({ stage: 'render', ratio: p.ratio, eta: p.eta }),
      })
      blobs.length = 0
      let out: { blob: Blob; mime: string; ext: string } = res
      // 3. too big for the server: a smaller copy
      if (maxBytes && out.blob.size > maxBytes) {
        if (mine()) setJob({ stage: 'shrink', ratio: 0 })
        out = await shrinkVideo(out.blob, Math.floor(maxBytes * 0.92), { signal, onProgress: (r) => mine() && setJob({ stage: 'shrink', ratio: r }) })
        if (out.blob.size > maxBytes) throw new Error('জোড়া দেওয়া ভিডিওটি ছোট করার পরও সার্ভারের সীমার চেয়ে বড় — কয়েকটি ফুটেজ ট্রিম করে বা বাদ দিয়ে আবার চেষ্টা করুন।')
      }
      // 4. upload, with time left
      const name = `${(video.slug || 'meeha-video').slice(0, 50)}-joined.${out.ext}`
      const samples: { t: number; b: number }[] = []
      if (mine()) setJob({ stage: 'upload', percent: 0, eta: null })
      const up = await MediaApi.uploadResumable(new File([out.blob], name, { type: out.mime }), {
        signal,
        onProgress: (p) => {
          const now = performance.now()
          samples.push({ t: now, b: p.loaded })
          while (samples.length > 2 && now - samples[0].t > 8000) samples.shift()
          const dt = (now - samples[0].t) / 1000
          const speed = dt > 1.5 && p.loaded > samples[0].b ? (p.loaded - samples[0].b) / dt : 0
          if (mine()) setJob((j) => ({ stage: 'upload', percent: p.percent, eta: speed ? (p.total - p.loaded) / speed : null, note: j?.stage === 'upload' ? j.note : null }))
        },
        onStatus: (note) => mine() && setJob((j) => (j?.stage === 'upload' ? { ...j, note } : { stage: 'upload', percent: 0, eta: null, note })),
      })
      // 5. the joined file becomes the video (the footage list stays)
      if (mine()) setJob({ stage: 'saving' })
      let thumbId: number | undefined
      if (!video.thumbnail_id && res.poster) {
        try {
          thumbId = (await MediaApi.upload(new File([res.poster], `${(video.slug || 'meeha-video').slice(0, 50)}-thumbnail.jpg`, { type: 'image/jpeg' }))).id
        } catch {
          // the video matters more than its thumbnail
        }
      }
      const saved = await AdminVideosApi.update(video.id, {
        source: 'upload',
        video_attachment_id: up.id,
        duration: Math.round(res.duration),
        orientation: res.height >= res.width ? 'portrait' : 'landscape',
        ...(thumbId ? { thumbnail_id: thumbId } : {}),
        // an older studio edit was made from one clip: start fresh from the joined file
        ...(video.edit ? { edit: null } : {}),
      })
      if (!mine()) return
      setJob(null)
      setJoined(true)
      onSaved(saved)
      if (isAuto) setDone({ auto: true, at: Date.now() })
      else navigate(`/app/videos/${video.id}/edit-video`)
    } catch (err) {
      if (!mine()) return
      setJob(null)
      if (err instanceof JoinCancelled || isAbortError(err) || signal.aborted) {
        setError('')
        return
      }
      setError(describeError(err).message)
    }
  }

  function cancel() {
    runId.current++
    abortRef.current?.abort()
    setJob(null)
  }

  if (items.length < 2) return null

  const progress =
    job?.stage === 'download'
      ? { label: `ফুটেজ নামানো হচ্ছে (${bnNumber(job.index + 1)}/${bnNumber(job.count)})… ${job.total ? `${bnFileSize(job.loaded)} / ${bnFileSize(job.total)}` : ''}`, ratio: (job.index + (job.total ? job.loaded / job.total : 0)) / job.count, eta: null }
      : job?.stage === 'render'
        ? { label: 'ফুটেজ জোড়া দেওয়া হচ্ছে…', ratio: job.ratio, eta: job.eta }
        : job?.stage === 'shrink'
          ? { label: 'আপলোডের জন্য ছোট করা হচ্ছে…', ratio: job.ratio, eta: null }
          : job?.stage === 'upload'
            ? { label: job.percent >= 100 ? 'সার্ভারে প্রসেস হচ্ছে…' : 'জোড়া দেওয়া ভিডিও আপলোড হচ্ছে…', ratio: job.percent / 100, eta: job.eta }
            : job?.stage === 'saving'
              ? { label: 'পোস্টে বসানো হচ্ছে…', ratio: 1, eta: null }
              : null

  const pa = used[safePair]
  const pb = used[safePair + 1]

  return (
    <div className="mt-4 rounded-xl border border-neutral-200 bg-white p-3.5" data-testid="footage-join">
      <div className="flex items-start justify-between gap-3 flex-wrap mb-3">
        <div className="min-w-0">
          <p className="text-[13.5px] font-semibold text-neutral-900 flex items-center gap-1.5">
            <IconMovie className="w-4 h-4 text-brand-600" />
            {bnNumber(items.length)}টি ফুটেজ এসেছে — জোড়া দিয়ে একটি ভিডিও করুন
          </p>
          <p className="text-[11.5px] text-neutral-500 leading-snug mt-0.5">
            ক্রম ঠিক করুন (টেনে আনুন বা ↑↓), দরকার না হলে বাদ দিন, তারপর ট্রানজিশন বেছে নিন।
            {plan ? ` মোট দৈর্ঘ্য হবে প্রায় ${bnDuration(plan.total)}।` : ''}
          </p>
        </div>
        <label className="flex items-center gap-1.5 text-[12px] text-neutral-700 shrink-0" data-testid="footage-auto">
          <input
            type="checkbox"
            checked={auto}
            onChange={(e) => {
              setAuto(e.target.checked)
              try {
                localStorage.setItem(AUTO_KEY, e.target.checked ? '1' : '0')
              } catch {
                // private mode
              }
            }}
            className="accent-[#b91c1c]"
          />
          স্বয়ংক্রিয় জোড়া
        </label>
      </div>

      {joined && !running && !error && (
        <div className="mb-3 rounded-lg bg-emerald-50 border border-emerald-100 px-3 py-2 flex items-center justify-between gap-2 flex-wrap" data-testid="footage-joined">
          <p className="text-[12px] text-emerald-800">
            {done?.auto ? 'ফুটেজগুলো নিজে থেকেই জোড়া দিয়ে পোস্টে বসানো হয়েছে (ক্রসফেড ০.৬ সে.)।' : 'জোড়া দেওয়া ভিডিও পোস্টে বসানো আছে।'}
            {changed ? ' সেটিং বদলেছেন — নতুন করে জোড়া দিতে নিচের বোতাম চাপুন।' : ''}
          </p>
          <Button size="sm" variant="success" onClick={() => navigate(`/app/videos/${video.id}/edit-video`)} data-testid="footage-go-studio">
            <IconWand className="w-3.5 h-3.5" />
            স্টুডিওতে যান
          </Button>
        </div>
      )}

      {/* clips */}
      <ol className="flex gap-2 overflow-x-auto pb-1" data-testid="footage-list">
        {items.map((x, i) => (
          <li
            key={x.f.id}
            draggable={!running}
            onDragStart={() => setDragIdx(i)}
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => {
              e.preventDefault()
              if (dragIdx !== null) move(dragIdx, i)
              setDragIdx(null)
            }}
            onDragEnd={() => setDragIdx(null)}
            className={`shrink-0 w-[132px] rounded-lg border p-1.5 transition ${x.on ? 'border-neutral-200 bg-neutral-50' : 'border-dashed border-neutral-300 bg-white opacity-60'} ${dragIdx === i ? 'ring-2 ring-brand-500' : ''}`}
            data-testid={`footage-item-${i}`}
          >
            <div className="relative aspect-video rounded-md overflow-hidden bg-black cursor-grab">
              <video
                src={`${x.f.url}#t=0.5`}
                preload="metadata"
                muted
                playsInline
                className="w-full h-full object-contain pointer-events-none"
                onLoadedMetadata={(e) => {
                  const v = e.currentTarget
                  const dur = Number.isFinite(v.duration) ? v.duration : null
                  setItems((list) => list.map((y) => (y.f.id === x.f.id ? { ...y, dur, w: v.videoWidth, h: v.videoHeight } : y)))
                }}
              />
              <span className="absolute top-1 left-1 rounded bg-black/70 text-white text-[10.5px] font-semibold px-1.5">{bnNumber(i + 1)}</span>
              {x.f.hd_url && <span className="absolute top-1 right-1 rounded bg-emerald-600 text-white text-[9.5px] font-bold px-1">HD</span>}
              {x.dur ? <span className="absolute bottom-1 right-1 rounded bg-black/70 text-white text-[10px] px-1">{bnDuration(Math.max(0, (x.trimEnd ?? x.dur) - x.trimStart)) || `${fmtSec(x.dur)} সে.`}</span> : null}
            </div>
            <p className="text-[10.5px] text-neutral-500 truncate mt-1" title={x.f.name}>
              {x.f.name || `ফুটেজ ${bnNumber(i + 1)}`}
            </p>
            <div className="flex items-center gap-1 mt-1">
              <button type="button" disabled={running || i === 0} onClick={() => move(i, i - 1)} className="w-6 h-6 rounded bg-white border border-neutral-200 text-[12px] disabled:opacity-30" aria-label="আগে নিন">
                ↑
              </button>
              <button type="button" disabled={running || i === items.length - 1} onClick={() => move(i, i + 1)} className="w-6 h-6 rounded bg-white border border-neutral-200 text-[12px] disabled:opacity-30" aria-label="পরে নিন">
                ↓
              </button>
              <button
                type="button"
                disabled={running}
                onClick={() => setTrimOpen(trimOpen === i ? null : i)}
                className={`h-6 px-1.5 rounded border text-[10.5px] ${trimOpen === i ? 'bg-brand-50 border-brand-200 text-brand-700' : 'bg-white border-neutral-200 text-neutral-600'}`}
              >
                ট্রিম
              </button>
              <button
                type="button"
                disabled={running}
                onClick={() => edit((list) => list.map((y, k) => (k === i ? { ...y, on: !y.on } : y)))}
                className="ml-auto h-6 px-1.5 rounded bg-white border border-neutral-200 text-[10.5px] text-neutral-600"
                data-testid={`footage-skip-${i}`}
              >
                {x.on ? 'বাদ দিন' : 'রাখুন'}
              </button>
            </div>
            {trimOpen === i && (
              <div className="mt-1.5 grid grid-cols-2 gap-1 text-[10.5px] text-neutral-600">
                <label>
                  শুরু (সে.)
                  <input
                    type="number"
                    min={0}
                    step={0.1}
                    value={x.trimStart}
                    onChange={(e) => {
                      const v = Math.max(0, Number(e.target.value) || 0)
                      edit((list) => list.map((y, k) => (k === i ? { ...y, trimStart: Math.min(v, (y.trimEnd ?? y.dur ?? v + 1) - 0.5) } : y)))
                    }}
                    className="w-full rounded border border-neutral-200 px-1 py-0.5"
                  />
                </label>
                <label>
                  শেষ (সে.)
                  <input
                    type="number"
                    min={0}
                    step={0.1}
                    value={x.trimEnd ?? (x.dur ? Number(x.dur.toFixed(1)) : '')}
                    onChange={(e) => {
                      const raw = e.target.value
                      edit((list) =>
                        list.map((y, k) => {
                          if (k !== i) return y
                          if (raw === '') return { ...y, trimEnd: null }
                          const v = Number(raw)
                          return { ...y, trimEnd: Math.max(y.trimStart + 0.5, y.dur ? Math.min(y.dur, v) : v) }
                        }),
                      )
                    }}
                    className="w-full rounded border border-neutral-200 px-1 py-0.5"
                  />
                </label>
              </div>
            )}
          </li>
        ))}
      </ol>

      {/* transition */}
      <p className="text-[12px] font-medium text-neutral-600 mt-3 mb-1.5">ট্রানজিশন</p>
      <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label="ট্রানজিশন" data-testid="footage-transitions">
        {TRANSITIONS.map((t) => (
          <button
            key={t.id}
            type="button"
            role="radio"
            aria-checked={kind === t.id}
            disabled={running}
            onClick={() => {
              setKind(t.id)
              if (done || joined) setChanged(true)
            }}
            className={`flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12px] transition ${kind === t.id ? 'border-brand-500 bg-brand-50 text-brand-700 font-semibold' : 'border-neutral-200 bg-white text-neutral-700 hover:border-neutral-300'}`}
          >
            <TransitionGlyph kind={t.id} />
            {t.label}
          </button>
        ))}
      </div>
      {kind !== 'cut' && (
        <label className="mt-2.5 flex items-center gap-2 text-[12px] text-neutral-600">
          সময়
          <input
            type="range"
            min={TRANSITION_MIN}
            max={TRANSITION_MAX}
            step={0.1}
            value={tdur}
            disabled={running}
            onChange={(e) => {
              setTdur(Number(e.target.value))
              if (done || joined) setChanged(true)
            }}
            className="flex-1 accent-[#b91c1c]"
            data-testid="footage-duration"
          />
          <span className="tabular-nums w-14 text-right">{fmtSec(tdur)} সে.</span>
        </label>
      )}

      {/* preview of one junction */}
      {pa && pb && pa.dur && pb.dur && (
        <div className="mt-3">
          <div className="flex items-center justify-between gap-2 mb-1.5">
            <p className="text-[12px] font-medium text-neutral-600 flex items-center gap-1">
              <IconPlayerPlay className="w-3.5 h-3.5" />
              প্রিভিউ — ফুটেজ {bnNumber(items.indexOf(pa) + 1)} → {bnNumber(items.indexOf(pb) + 1)}
            </p>
            {used.length > 2 && (
              <div className="flex gap-1">
                {used.slice(0, -1).map((_, k) => (
                  <button
                    key={k}
                    type="button"
                    onClick={() => setPair(k)}
                    className={`h-6 min-w-6 px-1.5 rounded text-[10.5px] border ${k === safePair ? 'bg-brand-600 text-white border-brand-600' : 'bg-white border-neutral-200 text-neutral-600'}`}
                  >
                    {bnNumber(k + 1)}→{bnNumber(k + 2)}
                  </button>
                ))}
              </div>
            )}
          </div>
          <PairPreview a={pa} b={pb} kind={kind} dur={kind === 'cut' ? 0 : tdur} first={used[0]} />
        </div>
      )}

      {/* run */}
      {progress && (
        <div className="mt-3 rounded-lg border border-neutral-200 p-2.5" data-testid="footage-progress">
          <div className="flex items-center justify-between gap-2 mb-1.5">
            <p className="text-[12px] text-neutral-700 min-w-0 truncate">
              {progress.label}
              {progress.eta != null && progress.eta >= 1 && <span className="text-neutral-400"> · আনুমানিক বাকি {bnDuration(progress.eta)}</span>}
            </p>
            <div className="flex items-center gap-2 shrink-0">
              <span className="text-[12px] font-semibold tabular-nums">{bnNumber(Math.round(progress.ratio * 100))}%</span>
              <Button size="sm" variant="secondary" onClick={cancel} data-testid="footage-cancel">
                <IconX className="w-3.5 h-3.5" />
                বাতিল
              </Button>
            </div>
          </div>
          <div className="h-2 rounded-full bg-neutral-100 overflow-hidden" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(progress.ratio * 100)}>
            <div className="h-full bg-brand-600 transition-[width] duration-200" style={{ width: `${Math.round(progress.ratio * 100)}%` }} />
          </div>
          {job?.stage === 'upload' && job.note && <p className="mt-1.5 text-[11.5px] text-amber-800">{job.note}</p>}
          <p className="mt-1.5 text-[11px] text-neutral-400">কাজ চলার সময় এই পাতা খোলা রাখুন — অন্য ঘরগুলো লেখা চালিয়ে যেতে পারেন।</p>
        </div>
      )}
      {error && <p className="mt-3 text-[12px] text-red-700 bg-red-50 border border-red-100 rounded-lg px-2.5 py-1.5">{error}</p>}
      {!capability.ok && <p className="mt-3 text-[12px] text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-2.5 py-1.5">{capability.reason}</p>}

      {!running && (
        <div className="mt-3 flex items-center justify-end gap-2 flex-wrap">
          <Button onClick={() => void run(false)} disabled={!capability.ok || used.length < 2} data-testid="footage-join-run">
            <IconWand className="w-4 h-4" />
            জোড়া দিন ও স্টুডিওতে নিন
          </Button>
        </div>
      )}
    </div>
  )
}

/** A tiny icon for each transition chip. */
function TransitionGlyph({ kind }: { kind: TransitionKind }) {
  const a = '#9ca3af'
  const b = '#b91c1c'
  const box = (children: ReactNode) => (
    <svg width="22" height="14" viewBox="0 0 22 14" aria-hidden>
      {children}
    </svg>
  )
  switch (kind) {
    case 'cut':
      return box(<><rect x="0" y="1" width="10" height="12" rx="1.5" fill={a} /><rect x="12" y="1" width="10" height="12" rx="1.5" fill={b} /></>)
    case 'crossfade':
      return box(<><rect x="0" y="1" width="14" height="12" rx="1.5" fill={a} /><rect x="8" y="1" width="14" height="12" rx="1.5" fill={b} opacity="0.6" /></>)
    case 'dipBlack':
    case 'dipWhite':
      return box(<><rect x="0" y="1" width="7" height="12" rx="1.5" fill={a} /><rect x="7.5" y="1" width="7" height="12" fill={kind === 'dipBlack' ? '#111' : '#fff'} stroke="#d4d4d4" /><rect x="15" y="1" width="7" height="12" rx="1.5" fill={b} /></>)
    case 'slideLeft':
      return box(<><rect x="0" y="1" width="9" height="12" rx="1.5" fill={a} /><rect x="9" y="1" width="13" height="12" rx="1.5" fill={b} /><path d="M17 7h-5m2-2l-2 2 2 2" stroke="#fff" strokeWidth="1.3" fill="none" /></>)
    case 'slideUp':
      return box(<><rect x="4" y="0" width="14" height="6" rx="1.5" fill={a} /><rect x="4" y="6" width="14" height="8" rx="1.5" fill={b} /></>)
    case 'wipe':
      return box(<><rect x="0" y="1" width="22" height="12" rx="1.5" fill={a} /><rect x="0" y="1" width="12" height="12" rx="1.5" fill={b} /><rect x="11.5" y="1" width="1.5" height="12" fill="#fff" /></>)
    case 'zoom':
      return box(<><rect x="0" y="1" width="22" height="12" rx="1.5" fill={a} /><rect x="6" y="4" width="10" height="6" rx="1" fill={b} /></>)
    case 'blur':
      return box(<><defs><filter id="tg-blur"><feGaussianBlur stdDeviation="1.4" /></filter></defs><rect x="2" y="2" width="18" height="10" rx="2" fill={b} filter="url(#tg-blur)" /></>)
  }
}

/**
 * Loops one junction: the last moment of clip A, the transition, the first
 * moment of clip B — drawn the same way the join draws it.
 */
function PairPreview({ a, b, kind, dur, first }: { a: Item; b: Item; kind: TransitionKind; dur: number; first: Item }) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const va = useRef<HTMLVideoElement>(null)
  const vb = useRef<HTMLVideoElement>(null)
  const aspect = first.w && first.h ? first.w / first.h : 16 / 9
  const PW = aspect >= 1 ? 480 : Math.round(480 * aspect)
  const PH = aspect >= 1 ? Math.round(480 / aspect) : 480
  const endA = a.trimEnd ?? a.dur ?? 0
  const startB = b.trimStart

  useEffect(() => {
    const cv = canvasRef.current
    const A = va.current
    const B = vb.current
    if (!cv || !A || !B) return
    const ctx = cv.getContext('2d')
    if (!ctx) return
    const mk = () => {
      const c = document.createElement('canvas')
      c.width = PW
      c.height = PH
      return c
    }
    const la = mk()
    const lb = mk()
    const ga = la.getContext('2d')!
    const gb = lb.getContext('2d')!
    const PRE = 1.2
    const POST = 1.4
    const d = Math.max(0, dur)
    const fromA = Math.max(a.trimStart, endA - d - PRE)
    let t0 = performance.now()
    let raf = 0
    let bStarted = false
    let alive = true
    const reset = () => {
      try {
        A.currentTime = fromA
        B.currentTime = startB
      } catch {
        // not ready yet
      }
      B.pause()
      bStarted = false
      A.play().catch(() => undefined)
      t0 = performance.now()
    }
    const paint = (g: CanvasRenderingContext2D, v: HTMLVideoElement) => {
      if (v.readyState >= 2) drawFitted(g, v, v.videoWidth, v.videoHeight, PW, PH)
    }
    const tick = () => {
      if (!alive) return
      const el = (performance.now() - t0) / 1000
      const pre = Math.min(PRE, endA - d - fromA)
      if (el >= pre - 0.05 && !bStarted) {
        bStarted = true
        B.play().catch(() => undefined)
      }
      if (el < pre) {
        paint(ctx, A)
      } else if (el < pre + d) {
        paint(ga, A)
        paint(gb, B)
        drawTransition(ctx, kind, la, lb, (el - pre) / Math.max(0.01, d), PW, PH)
      } else if (el < pre + d + POST) {
        if (!A.paused) A.pause()
        paint(ctx, B)
      } else {
        reset()
      }
      raf = requestAnimationFrame(tick)
    }
    const start = () => {
      reset()
      raf = requestAnimationFrame(tick)
    }
    if (A.readyState >= 1 && B.readyState >= 1) start()
    else {
      let n = 0
      const ready = () => {
        if (++n >= 2 || (A.readyState >= 1 && B.readyState >= 1)) {
          A.removeEventListener('loadedmetadata', ready)
          B.removeEventListener('loadedmetadata', ready)
          if (alive) start()
        }
      }
      A.addEventListener('loadedmetadata', ready)
      B.addEventListener('loadedmetadata', ready)
    }
    return () => {
      alive = false
      cancelAnimationFrame(raf)
      A.pause()
      B.pause()
    }
  }, [a.f.url, b.f.url, kind, dur, endA, startB, a.trimStart, PW, PH])

  return (
    <div className="rounded-lg overflow-hidden bg-black flex justify-center" data-testid="footage-preview">
      <canvas ref={canvasRef} width={PW} height={PH} className="max-h-[260px] w-auto max-w-full" />
      {/* no crossOrigin: the uploads folder sends no CORS headers; drawing a tainted canvas still shows */}
      <video ref={va} src={a.f.url} muted playsInline preload="auto" className="hidden" />
      <video ref={vb} src={b.f.url} muted playsInline preload="auto" className="hidden" />
    </div>
  )
}
