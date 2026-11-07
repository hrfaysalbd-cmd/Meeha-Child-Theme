/**
 * Round 27 — "ভয়েস যোগ করুন": record a voice-over from the microphone while
 * the preview plays, or upload a recorded file (MP3, M4A, WAV, OGG, WEBM).
 * Each voice is saved to the media library (resumable upload, so a weak
 * network does not lose it) and mixed into the export: the rest of the
 * sound dips while it speaks, and the voice clean-up EQ is on by default.
 */
import { useEffect, useRef, useState } from 'react'
import { MediaApi, describeError } from '../../lib/api'
import { LIMITS, type EditConfig, type VoiceOver } from '../../lib/videoEdit/config'
import { decodeVoice } from '../../lib/videoEdit/audio'
import { bnClock } from '../../lib/videoEdit/motion'
import { bnNumber } from '../ui'
import { IconMicrophone, IconTrash, IconUpload } from '../Icon'
import { Section, Slider, Switch, SwitchRow, TimeField } from './StudioFields'
import type { Setter } from './StudioPanels'

const pct = (v: number) => `${bnNumber(Math.round(v * 100))}%`
const AUDIO_MAX = 50 * 1024 * 1024
const MAX_RECORD_S = 15 * 60

type RecState =
  | null
  | { stage: 'asking' }
  | { stage: 'recording'; started: number; at: number }
  | { stage: 'uploading'; percent: number; note: string | null; label: string }
  | { stage: 'error'; message: string; retry?: { blob: Blob; name: string; at: number } }

function pickMime() {
  const options = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus']
  for (const m of options) if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported?.(m)) return m
  return ''
}

const stamp = () => {
  const d = new Date()
  const z = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}${z(d.getMonth() + 1)}${z(d.getDate())}-${z(d.getHours())}${z(d.getMinutes())}${z(d.getSeconds())}`
}

export default function VoicePanel({
  cfg,
  set,
  range,
  playhead,
  onRecordStart,
  onRecordStop,
  onFile,
}: {
  cfg: EditConfig
  set: Setter
  range: { start: number; end: number }
  playhead: () => number | null
  /** The preview starts playing from the playhead (silenced if asked). */
  onRecordStart: (opts: { play: boolean; mute: boolean }) => void
  onRecordStop: () => void
  /** A voice saved to the library: its file, so it need not be downloaded again. */
  onFile: (id: number, blob: Blob) => void
}) {
  const [rec, setRec] = useState<RecState>(null)
  const [playAlong, setPlayAlong] = useState(true)
  const [muteVideo, setMuteVideo] = useState(true)
  const [level, setLevel] = useState(0)
  const [now, setNow] = useState(0)
  const recorder = useRef<MediaRecorder | null>(null)
  const stream = useRef<MediaStream | null>(null)
  const meterCtx = useRef<AudioContext | null>(null)
  const raf = useRef(0)
  const upload = useRef<AbortController | null>(null)
  const session = useRef<{ id: string } | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const full = cfg.voices.length >= LIMITS.voices

  const here = () => {
    const p = playhead()
    return Math.round(Math.min(range.end, Math.max(range.start, p ?? range.start)) * 10) / 10
  }
  const update = (i: number, patch: Partial<VoiceOver>) => set((c) => ({ ...c, voices: c.voices.map((v, j) => (j === i ? { ...v, ...patch } : v)) }))

  function releaseMic() {
    cancelAnimationFrame(raf.current)
    stream.current?.getTracks().forEach((t) => t.stop())
    stream.current = null
    meterCtx.current?.close().catch(() => undefined)
    meterCtx.current = null
    setLevel(0)
  }

  useEffect(
    () => () => {
      if (recorder.current && recorder.current.state !== 'inactive') {
        recorder.current.onstop = null
        recorder.current.stop()
      }
      releaseMic()
      upload.current?.abort()
    },
    [],
  )

  // the timer while recording (and the automatic stop at 15 minutes)
  useEffect(() => {
    if (rec?.stage !== 'recording') return
    const t = setInterval(() => {
      const s = (performance.now() - rec.started) / 1000
      setNow(s)
      if (s >= MAX_RECORD_S) stopRecording()
    }, 200)
    return () => clearInterval(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rec])

  async function save(blob: Blob, name: string, at: number) {
    if (blob.size > AUDIO_MAX) {
      setRec({ stage: 'error', message: `ফাইলটি ${bnNumber(Math.round(blob.size / 1048576))} MB — ভয়েস ৫০ MB-এর বেশি হতে পারবে না।` })
      return
    }
    let duration: number | null = null
    try {
      duration = (await decodeVoice(blob)).duration
    } catch {
      setRec({ stage: 'error', message: 'এই ফাইলের শব্দ পড়া যাচ্ছে না। MP3, M4A, WAV বা OGG ফাইল দিন।' })
      return
    }
    const ctrl = new AbortController()
    upload.current = ctrl
    const label = 'ভয়েস সংরক্ষণ হচ্ছে…'
    setRec({ stage: 'uploading', percent: 0, note: null, label })
    try {
      const file = new File([blob], name, { type: blob.type || 'audio/webm' })
      const res = await MediaApi.uploadResumable(file, {
        kind: 'audio',
        signal: ctrl.signal,
        session: session.current,
        onSession: (s) => (session.current = s),
        onProgress: (p) => setRec((r) => (r?.stage === 'uploading' ? { ...r, percent: p.percent } : r)),
        onStatus: (note) => setRec((r) => (r?.stage === 'uploading' ? { ...r, note } : r)),
      })
      session.current = null
      onFile(res.id, blob)
      const title = name.replace(/\.[^.]+$/, '')
      set((c) => ({
        ...c,
        voices: [...c.voices, { clip: { id: res.id, url: res.url, title, duration }, at, vol: 1, duck: 0.3, clean: true, offset: 0, until: null }].slice(0, LIMITS.voices),
      }))
      setRec(null)
    } catch (err) {
      if (ctrl.signal.aborted) {
        session.current = null
        setRec(null)
        return
      }
      setRec({ stage: 'error', message: `ভয়েস সংরক্ষণ হয়নি: ${describeError(err).message}`, retry: { blob, name, at } })
    } finally {
      if (upload.current === ctrl) upload.current = null
    }
  }

  async function startRecording() {
    if (full || rec?.stage === 'recording') return
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      setRec({ stage: 'error', message: 'এই ব্রাউজারে রেকর্ড করা যায় না। Chrome বা Edge ব্যবহার করুন, অথবা রেকর্ড করা ফাইল আপলোড করুন।' })
      return
    }
    setRec({ stage: 'asking' })
    let s: MediaStream
    try {
      s = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } })
    } catch (err) {
      const name = err instanceof DOMException ? err.name : ''
      setRec({
        stage: 'error',
        message:
          name === 'NotAllowedError'
            ? 'মাইক্রোফোন ব্যবহারের অনুমতি দেওয়া হয়নি। ঠিকানার পাশের তালা চিহ্নে চাপ দিয়ে মাইক্রোফোন চালু করুন।'
            : name === 'NotFoundError'
              ? 'কোনো মাইক্রোফোন পাওয়া যায়নি।'
              : 'মাইক্রোফোন চালু করা যায়নি।',
      })
      return
    }
    stream.current = s
    // a small level meter, so the reporter sees the mic is picking them up
    try {
      const ctx = new AudioContext()
      meterCtx.current = ctx
      const an = ctx.createAnalyser()
      an.fftSize = 512
      ctx.createMediaStreamSource(s).connect(an)
      const data = new Float32Array(an.fftSize)
      const tick = () => {
        an.getFloatTimeDomainData(data)
        let peak = 0
        for (const x of data) peak = Math.max(peak, Math.abs(x))
        setLevel(peak)
        raf.current = requestAnimationFrame(tick)
      }
      tick()
    } catch {
      // the meter is optional
    }
    const mime = pickMime()
    const r = new MediaRecorder(s, mime ? { mimeType: mime, audioBitsPerSecond: 128_000 } : undefined)
    const parts: Blob[] = []
    const at = here()
    r.ondataavailable = (e) => {
      if (e.data.size) parts.push(e.data)
    }
    r.onstop = () => {
      releaseMic()
      onRecordStop()
      const type = r.mimeType || mime || 'audio/webm'
      const blob = new Blob(parts, { type })
      if (blob.size < 1000) {
        setRec({ stage: 'error', message: 'রেকর্ডে কোনো শব্দ পাওয়া যায়নি।' })
        return
      }
      const ext = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm'
      void save(blob, `meeha-voice-${stamp()}.${ext}`, at)
    }
    recorder.current = r
    r.start(1000)
    onRecordStart({ play: playAlong, mute: muteVideo })
    setNow(0)
    setRec({ stage: 'recording', started: performance.now(), at })
  }

  function stopRecording() {
    const r = recorder.current
    recorder.current = null
    if (r && r.state !== 'inactive') r.stop()
  }

  function pickFile(f: File | undefined) {
    if (!f || full) return
    void save(f, f.name, here())
  }

  const busy = rec?.stage === 'recording' || rec?.stage === 'uploading' || rec?.stage === 'asking'

  return (
    <Section title="ভয়েস যোগ করুন" hint="নিজের কণ্ঠ রেকর্ড করুন বা রেকর্ড করা ফাইল দিন। ভয়েস চলার সময় ভিডিওর বাকি শব্দ নিজে থেকে কমে যায়।">
      <div className="flex flex-wrap gap-2">
        {rec?.stage === 'recording' ? (
          <button
            type="button"
            onClick={stopRecording}
            data-testid="voice-stop"
            className="flex items-center gap-1.5 rounded-full bg-red-600 text-white px-4 py-2 text-[13px] font-semibold animate-pulse"
          >
            <span className="w-2.5 h-2.5 rounded-sm bg-white" />
            থামান · {bnClock(now)}
          </button>
        ) : (
          <button
            type="button"
            onClick={startRecording}
            disabled={busy || full}
            data-testid="voice-record"
            className="flex items-center gap-1.5 rounded-full bg-brand-600 text-white px-4 py-2 text-[13px] font-semibold disabled:opacity-40"
          >
            <IconMicrophone className="w-4 h-4" />
            {cfg.voices.length ? 'আরেকটি ভয়েস রেকর্ড' : 'রেকর্ড শুরু'}
          </button>
        )}
        <button
          type="button"
          onClick={() => fileInput.current?.click()}
          disabled={busy || full}
          data-testid="voice-upload"
          className="flex items-center gap-1.5 rounded-full border border-neutral-300 px-4 py-2 text-[13px] font-semibold text-neutral-700 disabled:opacity-40"
        >
          <IconUpload className="w-4 h-4" />
          {cfg.voices.length ? 'আরেকটি ফাইল' : 'ফাইল আপলোড'}
        </button>
        <input
          ref={fileInput}
          type="file"
          accept="audio/*,.mp3,.m4a,.wav,.ogg,.webm,.aac"
          className="hidden"
          onChange={(e) => {
            pickFile(e.target.files?.[0])
            e.target.value = ''
          }}
        />
      </div>

      {rec?.stage === 'recording' && (
        <div className="mt-2">
          <div className="h-2 rounded-full bg-neutral-200 overflow-hidden" aria-label="মাইকের শব্দ">
            <div className={`h-full transition-[width] duration-75 ${level > 0.9 ? 'bg-red-500' : 'bg-emerald-500'}`} style={{ width: `${Math.min(100, Math.round(level * 110))}%` }} />
          </div>
          <p className="text-[11.5px] text-neutral-500 mt-1">
            মূল ভিডিওর {bnClock(rec.at)} থেকে রেকর্ড হচ্ছে।{level > 0.9 ? ' খুব জোরে — মাইক একটু দূরে রাখুন।' : ''}
          </p>
        </div>
      )}
      {rec?.stage === 'asking' && <p className="text-[12px] text-neutral-500 mt-2">মাইক্রোফোনের অনুমতি চাওয়া হচ্ছে…</p>}
      {rec?.stage === 'uploading' && (
        <div className="mt-2">
          <div className="h-2 rounded-full bg-neutral-200 overflow-hidden">
            <div className="h-full bg-brand-600 transition-[width]" style={{ width: `${rec.percent}%` }} />
          </div>
          <div className="flex items-center justify-between mt-1">
            <p className="text-[11.5px] text-neutral-500">
              {rec.label} {bnNumber(rec.percent)}%{rec.note ? ` · ${rec.note}` : ''}
            </p>
            <button type="button" className="text-[11.5px] text-red-600 underline" onClick={() => upload.current?.abort()}>
              বাতিল
            </button>
          </div>
        </div>
      )}
      {rec?.stage === 'error' && (
        <div className="mt-2 rounded-lg bg-red-50 border border-red-200 p-2 text-[12px] text-red-700" role="alert">
          {rec.message}
          <div className="mt-1 flex gap-3">
            {rec.retry && (
              <button type="button" className="underline font-semibold" onClick={() => rec.retry && void save(rec.retry.blob, rec.retry.name, rec.retry.at)}>
                আবার চেষ্টা (যেখানে থেমেছিল সেখান থেকে)
              </button>
            )}
            <button type="button" className="underline" onClick={() => setRec(null)}>
              ঠিক আছে
            </button>
          </div>
        </div>
      )}

      {rec?.stage !== 'recording' && (
        <div className="mt-2">
          <SwitchRow label="রেকর্ডের সময় ভিডিও চলবে" hint="প্রিভিউ দেখে দেখে কথা বলুন। রেকর্ড শুরু হবে প্লেহেডের জায়গা থেকে।" checked={playAlong} onChange={setPlayAlong} />
          {playAlong && <SwitchRow label="রেকর্ডের সময় ভিডিওর শব্দ বন্ধ" hint="স্পিকারের শব্দ মাইকে ঢুকে না যাওয়ার জন্য। হেডফোন থাকলে চালু রাখতে পারেন।" checked={muteVideo} onChange={setMuteVideo} />}
        </div>
      )}

      {cfg.voices.length > 0 && (
        <div className="mt-3 flex flex-col gap-2">
          <p className="text-[12px] font-semibold text-neutral-700">
            ভিডিওতে ভয়েস ({bnNumber(cfg.voices.length)}/{bnNumber(LIMITS.voices)}) — আরও যোগ করতে উপরের “রেকর্ড” বা “ফাইল আপলোড” আবার চাপুন
          </p>
          {cfg.voices.map((v, i) => {
            const dur = v.clip.duration ?? null
            return (
              <div key={`${v.clip.id}-${i}`} className="rounded-xl border border-neutral-200 p-2.5" data-testid="voice-row">
                <div className="flex items-end gap-3 flex-wrap">
                  <div className="min-w-[110px] max-w-[180px]">
                    <span className="block text-[11.5px] text-neutral-500 mb-1">ভয়েস {bnNumber(i + 1)}</span>
                    <span className="block truncate text-[12.5px] font-semibold text-neutral-800" title={v.clip.title}>
                      {v.clip.title || 'ভয়েস'}
                      {dur ? ` · ${bnClock(dur)}` : ''}
                    </span>
                  </div>
                  <TimeField label="শুরু (ভিডিওর সময়)" value={v.at} min={range.start} max={range.end} onChange={(x) => update(i, { at: x, until: v.until !== null && v.until <= x ? null : v.until })} onPlayhead={() => update(i, { at: here() })} />
                  {v.until === null ? (
                    <div>
                      <span className="block text-[11.5px] text-neutral-500 mb-1">শেষ</span>
                      <button
                        type="button"
                        onClick={() => update(i, { until: Math.min(range.end, Math.round((v.at + Math.max(1, (dur ?? 10) - v.offset)) * 10) / 10) })}
                        className="rounded-lg border border-dashed border-neutral-300 px-2.5 py-1.5 text-[12px] text-neutral-600 hover:border-brand-400"
                        data-testid="voice-set-until"
                      >
                        পুরোটা · শেষ সময় দিন
                      </button>
                    </div>
                  ) : (
                    <div className="flex items-end gap-1">
                      <TimeField label="শেষ (ভিডিওর সময়)" value={v.until} min={v.at + 0.5} max={range.end} onChange={(x) => update(i, { until: x })} onPlayhead={() => update(i, { until: Math.max(v.at + 0.5, here()) })} />
                      <button type="button" onClick={() => update(i, { until: null })} className="mb-1.5 text-[11px] text-neutral-500 underline" title="ভয়েস পুরোটা বাজবে">
                        পুরোটা
                      </button>
                    </div>
                  )}
                  <button type="button" onClick={() => set((c) => ({ ...c, voices: c.voices.filter((_, j) => j !== i) }))} aria-label="সরান" className="ml-auto mb-1.5 text-red-500 hover:text-red-700">
                    <IconTrash className="w-4 h-4" />
                  </button>
                </div>
                <Slider label="ভয়েসের ভলিউম" value={Math.round(v.vol * 100)} min={0} max={200} step={5} onChange={(x) => update(i, { vol: x / 100 })} format={(x) => pct(x / 100)} />
                <Slider
                  label="ভয়েসের সময় বাকি শব্দ থাকবে"
                  value={Math.round(v.duck * 100)}
                  min={0}
                  max={100}
                  step={5}
                  onChange={(x) => update(i, { duck: x / 100 })}
                  format={(x) => (x === 0 ? 'বন্ধ' : x === 100 ? 'কমবে না' : pct(x / 100))}
                />
                <Slider
                  label="শুরুর অংশ বাদ"
                  value={Math.round(v.offset * 10)}
                  min={0}
                  max={Math.max(10, Math.min(600, Math.round((dur ?? 30) * 10) - 1))}
                  step={1}
                  onChange={(x) => update(i, { offset: x / 10 })}
                  format={(x) => `${bnNumber((x / 10).toFixed(1))} সেকেন্ড`}
                />
                <div className="flex items-center justify-between pt-1">
                  <span className="text-[12.5px] text-neutral-600">কণ্ঠ পরিষ্কার (EQ ও কম্প্রেসর)</span>
                  <Switch checked={v.clean} onChange={(x) => update(i, { clean: x })} label="কণ্ঠ পরিষ্কার" />
                </div>
              </div>
            )
          })}
          <p className="text-[11px] text-neutral-400">
            সময় মূল ভিডিওর হিসাবে, এখন: {bnClock(here())}। “শেষ” দিলে ভয়েস সেই সময়ে থেমে যাবে (নরমভাবে মিলিয়ে)। একাধিক ভয়েস একসাথে বা পরপর বাজতে পারে। প্রিভিউতে ভয়েস শোনা যায়; চূড়ান্ত মিক্স শুনতে নিচের “শুনে দেখুন” চাপুন। রেকর্ড শুরুতে সামান্য দেরি থাকলে “শুরুর অংশ বাদ” দিয়ে মিলিয়ে নিন।
          </p>
        </div>
      )}
      {full && <p className="text-[11.5px] text-amber-700 mt-2">সর্বোচ্চ {bnNumber(LIMITS.voices)}টি ভয়েস রাখা যায়।</p>}
    </Section>
  )
}
