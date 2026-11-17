/**
 * r46 — how one video clip goes up (used for the main video and for every
 * extra footage clip):
 *
 *   1. quick copy: on the phone, 720p at ~1.8 Mbit/s (480p on a slow line),
 *      like YouTube's "SD first";
 *   2. that copy goes up in several pieces at once (newsroom-os 1.28.0);
 *   3. the untouched original then goes up in the background as the HD file
 *      (see hdQueue below), linked to the quick copy on the server, so the
 *      editor can start at once and the studio renders from HD once it is in.
 *
 * Every step is timed, so a slow upload can be explained ("কোথায় সময় গেল").
 */
import { ApiError, MediaApi, formatBytes, videoTooLargeMessage, type UploadProgress } from './api'
import { canShrink, etaText, hdPref, onWifi, quickPlan } from './videoTarget'

export interface ClipStep {
  phase: 'check' | 'shrink' | 'send' | 'done'
  percent: number
  loaded: number
  total: number
  note?: string
  status?: string
}

export interface ClipResult {
  id: number
  url: string
  name: string
  size: number
  /** The original is going up separately as the HD file. */
  hdPending: boolean
  /** "ছোট করা ৪২ সে · পাঠানো ১ মি ১০ সে · ২.১ MB/s" */
  timing: string
}

const bn = (n: number | string) => String(n).replace(/\d/g, (d) => '০১২৩৪৫৬৭৮৯'[+d])
export function secsText(ms: number) {
  const s = Math.max(1, Math.round(ms / 1000))
  return s < 60 ? `${bn(s)} সে` : `${bn(Math.floor(s / 60))} মি ${bn(s % 60)} সে`
}

let lastSpeed: { at: number; bps: number | null } | null = null
/** One line-speed check per few minutes is enough for a batch of clips. */
async function speed(signal?: AbortSignal) {
  if (lastSpeed && Date.now() - lastSpeed.at < 5 * 60_000) return lastSpeed.bps
  const bps = await MediaApi.speedTest(signal)
  lastSpeed = { at: Date.now(), bps }
  return bps
}

/**
 * Prepares (quick copy) and uploads one clip. `serverMax` is the server's
 * limit for a normal upload; the original is checked against the HD limit.
 */
export async function uploadClip(file: File, serverMax: number, onStep: (s: ClipStep) => void, signal?: AbortSignal): Promise<ClipResult> {
  const t0 = performance.now()
  let toSend = file
  let note = ''
  let shrinkMs = 0
  let shrunk = false
  let bigOriginal = false
  onStep({ phase: 'check', percent: 0, loaded: 0, total: file.size })
  if (file.size > 12 * 1048576 && !canShrink() && serverMax > 0 && file.size > serverMax) {
    // no encoder on this phone at all — send the original under the HD cap
    bigOriginal = true
    note = 'এই ফোনে ছোট করা যায় না — আসল ফাইলটিই যাচ্ছে (বড় ফাইল, সময় লাগবে)'
  }
  if (file.size > 12 * 1048576 && canShrink()) {
    const bps = await speed(signal)
    const q = quickPlan(bps)
    try {
      const { planShrink, shrinkVideo, ShrinkCancelled } = await import('./videoShrink')
      try {
        const cap = serverMax > 0 ? serverMax * 0.92 : 180 * 1048576
        const plan = await planShrink(file, cap, q)
        const srcShort = plan ? Math.min(plan.width, plan.height) : 0
        if (plan && (plan.short < srcShort || plan.estimate < file.size * 0.8)) {
          onStep({ phase: 'shrink', percent: 0, loaded: 0, total: file.size, note: `${formatBytes(file.size)} → প্রায় ${formatBytes(plan.estimate)} · ${q.label}` })
          const s0 = performance.now()
          const out = await shrinkVideo(file, cap, { ...q, signal, onProgress: (r) => onStep({ phase: 'shrink', percent: Math.min(99, Math.floor(r * 100)), loaded: 0, total: file.size, note: `${formatBytes(file.size)} → প্রায় ${formatBytes(plan.estimate)} · ${q.label}` }) })
          shrinkMs = performance.now() - s0
          const base = file.name.replace(/\.[^.]+$/, '') || 'video'
          toSend = new File([out.blob], `${base}-meeha-${Math.min(out.width, out.height)}p.${out.ext}`, { type: out.mime })
          shrunk = true
          const eta = etaText(toSend.size, bps)
          note = `${formatBytes(file.size)} → ${formatBytes(toSend.size)} · ${bn(Math.min(out.width, out.height))}p${eta ? ' · ' + eta : ''}`
        }
      } catch (err) {
        if (err instanceof ShrinkCancelled || signal?.aborted) throw err
        const why = err instanceof Error && err.message ? err.message : ''
        // 1.28.2 — the phone could not make the quick copy (often an HEVC
        // recording it cannot decode): the untouched original goes up anyway,
        // up to the server's HD cap, and the desk shrinks it in the studio.
        if (serverMax > 0 && file.size > serverMax) bigOriginal = true
        note = `ছোট করা গেল না${why ? ` (${why})` : ''} — আসল ফাইলটিই যাচ্ছে${bigOriginal ? ' (বড় ফাইল, সময় লাগবে)' : ''}`
      }
    } catch (err) {
      if (signal?.aborted) throw err
      throw err
    }
  }
  if (!bigOriginal && serverMax > 0 && toSend.size > serverMax) throw new ApiError(videoTooLargeMessage(toSend.size, serverMax), 413, 'nros_video_too_large', 'client', '/media/video')

  const u0 = performance.now()
  onStep({ phase: 'send', percent: 0, loaded: 0, total: toSend.size, note })
  const res = await MediaApi.uploadVideo(toSend, (p: UploadProgress) => onStep({ phase: 'send', percent: p.percent, loaded: p.loaded, total: p.total, status: p.status, note }), { signal, original: bigOriginal })
  const sendMs = performance.now() - u0
  const rate = sendMs > 500 ? toSend.size / (sendMs / 1000) : 0
  const timing = [shrinkMs ? `ছোট করা ${secsText(shrinkMs)}` : '', `পাঠানো ${secsText(sendMs)}`, rate ? `${bn((rate / 1048576).toFixed(1))} MB/s` : '', `মোট ${secsText(performance.now() - t0)}`].filter(Boolean).join(' · ')
  onStep({ phase: 'done', percent: 100, loaded: toSend.size, total: toSend.size, note })
  if (shrunk) hdQueue.add(file, res.id)
  return { id: res.id, url: res.url, name: toSend.name, size: toSend.size, hdPending: shrunk && hdPref() !== 'never', timing }
}

// ---------------------------------------------------------------------------
// Background HD originals: one at a time, while the app stays open. Survives
// moving between pages (module state), not closing the app.
// ---------------------------------------------------------------------------

export interface HdJob {
  key: string
  name: string
  forId: number
  size: number
  percent: number
  state: 'waiting' | 'sending' | 'done' | 'error' | 'paused'
  error?: string
}

type Listener = () => void

class HdQueue {
  private jobs: (HdJob & { file: File })[] = []
  private listeners = new Set<Listener>()
  private busy = false
  private ctrl: AbortController | null = null
  private snapshot: HdJob[] = []

  subscribe = (l: Listener) => {
    this.listeners.add(l)
    return () => this.listeners.delete(l)
  }
  list = () => this.snapshot
  private emit() {
    this.snapshot = this.jobs.map(({ file: _f, ...j }) => j)
    this.listeners.forEach((l) => l())
  }
  add(file: File, forId: number) {
    if (hdPref() === 'never') return
    this.jobs.push({ key: `${forId}-${file.size}`, name: file.name, forId, size: file.size, percent: 0, state: 'waiting', file })
    this.emit()
    void this.run()
  }
  /** Removes a finished/failed job, or cancels one. */
  remove(key: string) {
    const j = this.jobs.find((x) => x.key === key)
    if (j?.state === 'sending') this.ctrl?.abort()
    this.jobs = this.jobs.filter((x) => x.key !== key)
    this.emit()
  }
  retry(key: string) {
    const j = this.jobs.find((x) => x.key === key)
    if (j && (j.state === 'error' || j.state === 'paused')) {
      j.state = 'waiting'
      j.error = undefined
      this.emit()
      void this.run()
    }
  }
  pending() {
    return this.jobs.some((j) => j.state === 'waiting' || j.state === 'sending')
  }
  private async run() {
    if (this.busy) return
    this.busy = true
    try {
      for (;;) {
        const j = this.jobs.find((x) => x.state === 'waiting')
        if (!j) return
        if (hdPref() === 'wifi' && onWifi() === false) {
          j.state = 'paused'
          j.error = 'Wi-Fi পেলে পাঠানো হবে'
          this.emit()
          const again = () => {
            if (onWifi() !== false) {
              ;(navigator as unknown as { connection?: EventTarget }).connection?.removeEventListener?.('change', again)
              this.retry(j.key)
            }
          }
          ;(navigator as unknown as { connection?: EventTarget }).connection?.addEventListener?.('change', again)
          continue
        }
        j.state = 'sending'
        this.emit()
        this.ctrl = new AbortController()
        try {
          await MediaApi.uploadVideo(
            j.file,
            (p) => {
              j.percent = p.percent
              this.emit()
            },
            { signal: this.ctrl.signal, hdFor: j.forId },
          )
          j.state = 'done'
          j.percent = 100
        } catch (err) {
          if (!this.jobs.includes(j)) continue
          j.state = 'error'
          j.error = err instanceof Error ? err.message : 'HD পাঠানো যায়নি'
        }
        this.emit()
      }
    } finally {
      this.busy = false
      this.ctrl = null
    }
  }
}

export const hdQueue = new HdQueue()
