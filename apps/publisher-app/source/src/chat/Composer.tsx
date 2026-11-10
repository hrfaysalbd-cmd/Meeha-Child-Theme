import { useEffect, useRef, useState, type MutableRefObject } from 'react'
import { ChatApi, type ChatFile, type ChatLabel, type LabelKey, type Member, type Room } from './api'
import { Avatar, LabelChip, Spinner } from './bits'
import type { LocalMsg } from './RoomView'
import { bnNum, duration, fileSize, isTouch, LABEL_ICON, localToIso } from './util'

interface Props {
  room: Room
  labels: ChatLabel[]
  members: Member[]
  me: { id: number }
  replyTo: LocalMsg | null
  editing: LocalMsg | null
  onCancelReply: () => void
  onCancelEdit: () => void
  onSend: (x: { body: string; label: LabelKey | ''; files: ChatFile[]; mentions: number[]; meeting_at: string }) => Promise<void>
  dropRef: MutableRefObject<(files: File[]) => void>
}

interface Pending {
  key: string
  name: string
  size: number
  preview?: string
  file?: ChatFile
  error?: string
}

const WEBVIEW_MIC = 'এই অ্যাপের ভেতর থেকে মাইক চালু করা যাচ্ছে না (অ্যাপটি ফোনের অনুমতি চায় না)। ভয়েস পাঠাতে Chrome-এ খুলুন বা মিহার নিজস্ব অ্যাপ ব্যবহার করুন।'
/** Our own Android app (Capacitor): the mic works there, Android asks for it. */
export const isNativeApp = () => !!(window as { Capacitor?: { isNativePlatform?: () => boolean } }).Capacitor?.isNativePlatform?.()
const NATIVE_MIC = 'মাইকের অনুমতি নেই। ফোনের Settings → Apps → মিহা সংবাদদাতা → Permissions → Microphone → Allow দিন।'
/** Android in-app web views (Facebook, simple "website to app" wrappers…) mark themselves with "; wv)". */
export const isWebView = () => !isNativeApp() && (/; wv\)/.test(navigator.userAgent) || /FBAN|FBAV|Instagram/.test(navigator.userAgent))

const draftKey = (id: number) => `nros_chat_draft_${id}`
const MAX_REC = 5 * 60

export default function Composer({ room, labels, members, me, replyTo, editing, onCancelReply, onCancelEdit, onSend, dropRef }: Props) {
  const [text, setText] = useState(() => localStorage.getItem(draftKey(room.id)) ?? '')
  const [label, setLabel] = useState<LabelKey | ''>('')
  const [meeting, setMeeting] = useState('')
  const [labelOpen, setLabelOpen] = useState(false)
  const [files, setFiles] = useState<Pending[]>([])
  const [mentions, setMentions] = useState<{ id: number; name: string }[]>([])
  const [mq, setMq] = useState<string | null>(null)
  const [mIndex, setMIndex] = useState(0)
  const [rec, setRec] = useState<{ secs: number; level: number; silent: boolean } | null>(null)
  const [sending, setSending] = useState(false)
  const ta = useRef<HTMLTextAreaElement>(null)
  const fileIn = useRef<HTMLInputElement>(null)
  const recorder = useRef<MediaRecorder | null>(null)
  const chunks = useRef<Blob[]>([])
  const recTimer = useRef<ReturnType<typeof setInterval> | null>(null)
  const recStart = useRef(0)
  const cancelRec = useRef(false)
  const lastTyping = useRef(0)

  // draft per room
  useEffect(() => {
    setText(localStorage.getItem(draftKey(room.id)) ?? '')
    setFiles([])
    setLabel('')
    setMentions([])
  }, [room.id])
  useEffect(() => {
    try {
      if (!editing) {
        if (text) localStorage.setItem(draftKey(room.id), text)
        else localStorage.removeItem(draftKey(room.id))
      }
    } catch {
      // private mode
    }
  }, [text, room.id, editing])

  // editing fills the box
  useEffect(() => {
    if (editing) {
      setText(editing.body)
      setLabel(editing.label)
      ta.current?.focus()
    }
  }, [editing])
  useEffect(() => {
    if (replyTo) ta.current?.focus()
  }, [replyTo])

  // grow with the text
  useEffect(() => {
    const el = ta.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = Math.min(160, el.scrollHeight) + 'px'
  }, [text])

  // ---- files ----
  const addFiles = (list: File[]) => {
    for (const f of list.slice(0, 10)) {
      const key = `${Date.now()}-${Math.random()}`
      const preview = f.type.startsWith('image/') ? URL.createObjectURL(f) : undefined
      if (f.size > 25 * 1024 * 1024) {
        setFiles((p) => [...p, { key, name: f.name, size: f.size, preview, error: 'ফাইল ২৫ এমবির বেশি' }])
        continue
      }
      setFiles((p) => [...p, { key, name: f.name, size: f.size, preview }])
      ChatApi.upload(room.id, f, f.name)
        .then((cf) => setFiles((p) => p.map((x) => (x.key === key ? { ...x, file: cf } : x))))
        .catch((e: Error) => setFiles((p) => p.map((x) => (x.key === key ? { ...x, error: e.message || 'আপলোড হয়নি' } : x))))
    }
  }
  dropRef.current = addFiles

  // ---- @mentions ----
  const onChangeText = (v: string) => {
    setText(v)
    const el = ta.current
    const caret = el?.selectionStart ?? v.length
    const before = v.slice(0, caret)
    const m = before.match(/(?:^|\s)@([\p{L}\p{M}\p{N}_.]*)$/u)
    setMq(m ? m[1] : null)
    setMIndex(0)
    if (v && Date.now() - lastTyping.current > 4000) {
      lastTyping.current = Date.now()
      ChatApi.typing(room.id).catch(() => {})
    }
  }
  const suggestions = mq === null ? [] : members.filter((m) => m.id !== me.id && m.name.toLowerCase().includes(mq.toLowerCase())).slice(0, 6)
  const pickMention = (m: Member) => {
    const el = ta.current
    const caret = el?.selectionStart ?? text.length
    const before = text.slice(0, caret).replace(/@([\p{L}\p{M}\p{N}_.]*)$/u, `@${m.name.replace(/\s+/g, '_')} `)
    const next = before + text.slice(caret)
    setText(next)
    setMentions((p) => (p.some((x) => x.id === m.id) ? p : [...p, { id: m.id, name: m.name }]))
    setMq(null)
    setTimeout(() => {
      el?.focus()
      el?.setSelectionRange(before.length, before.length)
    }, 0)
  }

  // ---- send ----
  const ready = files.filter((f) => f.file)
  const uploading = files.some((f) => !f.file && !f.error)
  const canSend = !sending && !uploading && (text.trim() !== '' || ready.length > 0)
  const submit = async () => {
    if (!canSend) return
    const body = text.trim()
    const ids = mentions.filter((m) => body.includes('@' + m.name.replace(/\s+/g, '_'))).map((m) => m.id)
    setSending(true)
    const payload = { body, label, files: ready.map((f) => f.file!), mentions: ids, meeting_at: label === 'meeting' ? localToIso(meeting) : '' }
    setText('')
    setFiles([])
    setMentions([])
    setLabel('')
    setMeeting('')
    try {
      localStorage.removeItem(draftKey(room.id))
    } catch {
      // ignore
    }
    try {
      await onSend(payload)
    } finally {
      setSending(false)
      ta.current?.focus()
    }
  }

  const onKey = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (suggestions.length) {
      if (e.key === 'ArrowDown') return e.preventDefault(), setMIndex((i) => (i + 1) % suggestions.length)
      if (e.key === 'ArrowUp') return e.preventDefault(), setMIndex((i) => (i - 1 + suggestions.length) % suggestions.length)
      if (e.key === 'Enter' || e.key === 'Tab') return e.preventDefault(), pickMention(suggestions[mIndex])
      if (e.key === 'Escape') return setMq(null)
    }
    if (e.key === 'Escape') {
      if (editing) onCancelEdit()
      else if (replyTo) onCancelReply()
    }
    if (e.key === 'Enter' && !e.shiftKey && !isTouch()) {
      e.preventDefault()
      void submit()
    }
  }

  // ---- voice note ----
  const startRec = async () => {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      alert(isWebView() ? WEBVIEW_MIC : 'এই ব্রাউজারে ভয়েস রেকর্ড চলে না। Chrome-এ খুলে চেষ্টা করুন।')
      return
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } })
      // MP4/AAC first: it plays on every phone (iPhone too); WebM/Opus where MP4 can't be recorded
      const type = ['audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/webm;codecs=opus', 'audio/webm', 'audio/ogg'].find((t) => MediaRecorder.isTypeSupported?.(t)) ?? ''
      // live level, so a dead microphone is noticed at once
      let peak = 0
      let level = 0
      let meterStop = () => {}
      try {
        const AC = window.AudioContext || (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
        if (AC) {
          const ac = new AC()
          const an = ac.createAnalyser()
          an.fftSize = 512
          ac.createMediaStreamSource(stream).connect(an)
          const buf = new Uint8Array(an.fftSize)
          const iv = setInterval(() => {
            an.getByteTimeDomainData(buf)
            let sum = 0
            for (const v of buf) sum += ((v - 128) / 128) ** 2
            level = Math.min(1, Math.sqrt(sum / buf.length) * 4)
            peak = Math.max(peak, level)
          }, 100)
          meterStop = () => {
            clearInterval(iv)
            void ac.close().catch(() => {})
          }
        } else peak = 1
      } catch {
        peak = 1
      }
      const r = new MediaRecorder(stream, type ? { mimeType: type, audioBitsPerSecond: 48000 } : undefined)
      chunks.current = []
      cancelRec.current = false
      r.ondataavailable = (e) => e.data.size && chunks.current.push(e.data)
      r.onstop = () => {
        stream.getTracks().forEach((t) => t.stop())
        meterStop()
        if (recTimer.current) clearInterval(recTimer.current)
        const secs = (Date.now() - recStart.current) / 1000
        setRec(null)
        if (cancelRec.current || secs < 0.8) return
        const mime = r.mimeType || type || 'audio/webm'
        const ext = mime.includes('mp4') ? 'mp4' : mime.includes('ogg') ? 'ogg' : 'webm'
        const blob = new Blob(chunks.current, { type: mime.split(';')[0] })
        const key = `v${Date.now()}`
        const name = `voice-${new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-')}.${ext}`
        setFiles((p) => [...p, { key, name: `🎙️ ভয়েস ${duration(secs)}`, size: blob.size }])
        ChatApi.upload(room.id, blob, name, secs)
          .then((cf) => setFiles((p) => p.map((x) => (x.key === key ? { ...x, file: cf } : x))))
          .catch((e: Error) => setFiles((p) => p.map((x) => (x.key === key ? { ...x, error: e.message || 'আপলোড হয়নি' } : x))))
      }
      recorder.current = r
      recStart.current = Date.now()
      r.start(500)
      setRec({ secs: 0, level: 0, silent: false })
      recTimer.current = setInterval(() => {
        const s = (Date.now() - recStart.current) / 1000
        setRec({ secs: s, level, silent: s > 2.5 && peak < 0.02 })
        if (s >= MAX_REC) r.stop()
      }, 250)
    } catch {
      alert(isNativeApp() ? NATIVE_MIC : isWebView() ? WEBVIEW_MIC : 'মাইক্রোফোন চালু করা গেল না। ব্রাউজারের ঠিকানার পাশের 🔒 চেপে মাইকের অনুমতি দিন।')
    }
  }
  const stopRec = (cancel: boolean) => {
    cancelRec.current = cancel
    if (recorder.current && recorder.current.state !== 'inactive') recorder.current.stop()
  }
  useEffect(() => () => stopRec(true), [])

  if (!room.can_post) {
    return (
      <div className="px-4 py-3 bg-white border-t border-neutral-200/80 text-center text-[13px] text-neutral-500" data-testid="composer-locked">
        {room.archived ? '🗄️ রুমটি আর্কাইভ করা, এখানে আর লেখা যায় না।' : '📢 এই রুমে শুধু অ্যাডমিন লিখতে পারেন।'}
      </div>
    )
  }

  const sel = labels.find((l) => l.key === label)

  return (
    <div className="relative bg-white border-t border-neutral-200/80 px-2 sm:px-3 pt-2 pb-[calc(8px+env(safe-area-inset-bottom))]" data-testid="composer">
      {/* @ suggestions */}
      {suggestions.length > 0 && (
        <div className="absolute left-3 right-3 bottom-full mb-1 z-10 rounded-xl bg-white shadow-xl ring-1 ring-black/5 overflow-hidden" data-testid="mention-list">
          {suggestions.map((m, i) => (
            <button
              key={m.id}
              type="button"
              onMouseDown={(e) => (e.preventDefault(), pickMention(m))}
              className={`w-full flex items-center gap-2.5 px-3 py-2 text-left ${i === mIndex ? 'bg-brand-50' : 'hover:bg-neutral-50'}`}
            >
              <Avatar p={m} size={28} />
              <span className="text-[13.5px] font-medium text-neutral-800">{m.name}</span>
              <span className="text-[11.5px] text-neutral-400">{m.role}</span>
            </button>
          ))}
        </div>
      )}

      {/* reply / edit */}
      {(replyTo || editing) && (
        <div className="flex items-start gap-2 mb-2 rounded-xl bg-neutral-100 px-3 py-2 border-l-[3px] border-brand-500" data-testid="reply-bar">
          <div className="min-w-0 flex-1">
            <div className="text-[12px] font-semibold text-brand-700">{editing ? '✏️ মেসেজ সম্পাদনা' : `↩ উত্তর: ${replyTo!.mine ? 'আপনি' : replyTo!.author?.name}`}</div>
            <div className="text-[12.5px] text-neutral-600 truncate">{(editing ?? replyTo)!.body || '📎 ফাইল'}</div>
          </div>
          <button
            type="button"
            onClick={() => {
              if (editing) {
                onCancelEdit()
                setText('')
                setLabel('')
              } else onCancelReply()
            }}
            className="text-neutral-400 text-[18px] leading-none"
            aria-label="বাতিল"
          >
            ×
          </button>
        </div>
      )}

      {/* files waiting */}
      {files.length > 0 && (
        <div className="flex gap-2 overflow-x-auto pb-2" data-testid="pending-files">
          {files.map((f) => (
            <div key={f.key} className={`relative shrink-0 rounded-xl ring-1 ${f.error ? 'ring-red-300 bg-red-50' : 'ring-neutral-200 bg-neutral-50'} overflow-hidden`}>
              {f.preview ? (
                <img src={f.preview} alt="" className="w-16 h-16 object-cover" />
              ) : (
                <div className="w-40 h-16 px-2.5 py-2">
                  <div className="text-[12px] font-medium text-neutral-800 truncate">{f.name}</div>
                  <div className="text-[11px] text-neutral-500">{f.error ?? (f.size ? fileSize(f.size) : '')}</div>
                </div>
              )}
              {!f.file && !f.error && (
                <div className="absolute inset-0 bg-white/60 flex items-center justify-center">
                  <Spinner className="w-5 h-5" />
                </div>
              )}
              <button type="button" onClick={() => setFiles((p) => p.filter((x) => x.key !== f.key))} className="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-black/60 text-white text-[12px] leading-5" aria-label="সরান">
                ×
              </button>
            </div>
          ))}
        </div>
      )}

      {/* chosen label (+ meeting time) */}
      {sel && (
        <div className="flex flex-wrap items-center gap-2 mb-2">
          <LabelChip label={sel.key} labels={labels} />
          {label === 'meeting' && (
            <label className="flex items-center gap-1.5 text-[12.5px] text-violet-800">
              সময়:
              <input type="datetime-local" value={meeting} onChange={(e) => setMeeting(e.target.value)} className="rounded-lg border border-violet-200 px-2 py-1 text-[12.5px]" data-testid="meeting-at" />
            </label>
          )}
          {label === 'urgent' && <span className="text-[11.5px] text-red-600">সবাই জোরালো শব্দে জানবেন, নিঃশব্দ রুমেও</span>}
          <button type="button" onClick={() => setLabel('')} className="text-[12px] text-neutral-400">
            সরান
          </button>
        </div>
      )}

      {rec ? (
        <div className="flex items-center gap-3 h-12 px-2" data-testid="recorder">
          <button type="button" onClick={() => stopRec(true)} className="w-10 h-10 rounded-full hover:bg-neutral-100 text-[18px]" aria-label="বাতিল">
            🗑️
          </button>
          <span className="w-3 h-3 rounded-full bg-red-600 animate-pulse shrink-0" />
          <span className="text-[15px] font-semibold text-neutral-800 tabular-nums">{duration(rec.secs)}</span>
          {rec.silent ? (
            <span className="flex-1 text-[12px] leading-snug text-red-600" data-testid="rec-silent">
              মাইক থেকে কোনো শব্দ আসছে না। অনুমতি বা মাইক দেখুন।
            </span>
          ) : (
            <span className="flex-1 flex items-center gap-[3px] h-6" title={`সর্বোচ্চ ${bnNum(5)} মিনিট`} data-testid="rec-meter">
              {Array.from({ length: 18 }, (_, i) => (
                <span key={i} className="w-[3px] rounded-full bg-brand-500 transition-all" style={{ height: `${Math.max(12, Math.min(100, rec.level * 100 * (0.55 + 0.45 * Math.sin(i * 1.7 + rec.secs * 9))))}%` }} />
              ))}
            </span>
          )}
          <button type="button" onClick={() => stopRec(false)} className="h-10 px-4 rounded-full bg-brand-600 text-white text-[14px] font-semibold" data-testid="rec-done">
            ✓ রাখুন
          </button>
        </div>
      ) : (
        <div className="flex items-end gap-1">
          <div className="relative">
            <button
              type="button"
              onClick={() => setLabelOpen((v) => !v)}
              className={`w-10 h-10 rounded-full text-[18px] ${label ? 'bg-neutral-100' : 'hover:bg-neutral-100'}`}
              title="লেবেল (জরুরি, মিটিং…)"
              aria-label="লেবেল"
              data-testid="label-btn"
            >
              🏷️
            </button>
            {labelOpen && (
              <>
                <div className="fixed inset-0 z-10" onClick={() => setLabelOpen(false)} />
                <div className="absolute bottom-12 left-0 z-20 w-56 rounded-2xl bg-white shadow-xl ring-1 ring-black/5 p-1.5" data-testid="label-menu">
                  {labels.map((l) => (
                    <button
                      key={l.key}
                      type="button"
                      onClick={() => {
                        setLabel(l.key === 'general' ? '' : l.key)
                        setLabelOpen(false)
                        ta.current?.focus()
                      }}
                      className={`w-full flex items-center gap-2.5 rounded-xl px-3 py-2 text-left text-[14px] hover:bg-neutral-50 ${label === l.key ? 'bg-neutral-100' : ''}`}
                    >
                      <span className="w-6 h-6 rounded-full flex items-center justify-center text-[13px]" style={{ background: `${l.color}22` }}>
                        {LABEL_ICON[l.key]}
                      </span>
                      <span className="font-medium" style={{ color: l.color }}>
                        {l.name}
                      </span>
                    </button>
                  ))}
                </div>
              </>
            )}
          </div>
          <button type="button" onClick={() => fileIn.current?.click()} className="w-10 h-10 rounded-full hover:bg-neutral-100 text-[18px]" title="ছবি/ফাইল" aria-label="ছবি বা ফাইল দিন" data-testid="attach-btn">
            📎
          </button>
          <input
            ref={fileIn}
            type="file"
            multiple
            hidden
            accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"
            onChange={(e) => {
              addFiles(Array.from(e.target.files || []))
              e.target.value = ''
            }}
            data-testid="file-input"
          />
          <textarea
            ref={ta}
            rows={1}
            value={text}
            onChange={(e) => onChangeText(e.target.value)}
            onKeyDown={onKey}
            onPaste={(e) => {
              const fs = Array.from(e.clipboardData.files || [])
              if (fs.length) {
                e.preventDefault()
                addFiles(fs)
              }
            }}
            placeholder={label === 'urgent' ? 'জরুরি কী হয়েছে লিখুন…' : room.kind === 'desk' ? 'ডেস্ককে লিখুন…' : 'মেসেজ লিখুন… @ দিয়ে ডাকুন'}
            title="@ লিখে নাম বাছলে তিনি আলাদা করে জানবেন"
            className="flex-1 min-w-0 resize-none rounded-2xl bg-neutral-100 px-4 py-2.5 text-[14.5px] leading-[1.45] outline-none focus:bg-white focus:ring-2 focus:ring-brand-200 max-h-40"
            data-testid="composer-input"
          />
          {canSend || text.trim() || files.length ? (
            <button
              type="button"
              onClick={() => void submit()}
              disabled={!canSend}
              className={`w-11 h-11 rounded-full text-white text-[18px] shrink-0 flex items-center justify-center transition ${canSend ? (label === 'urgent' ? 'bg-red-600' : 'bg-brand-600') + ' active:scale-95' : 'bg-neutral-300'}`}
              aria-label={editing ? 'সংরক্ষণ' : 'পাঠান'}
              data-testid="send-btn"
            >
              {sending || uploading ? <Spinner className="w-4 h-4 border-white/50 border-t-white" /> : editing ? '✓' : '➤'}
            </button>
          ) : (
            <button type="button" onClick={() => void startRec()} className="w-11 h-11 rounded-full bg-neutral-100 hover:bg-neutral-200 text-[18px] shrink-0" title="ভয়েস মেসেজ" aria-label="ভয়েস মেসেজ" data-testid="mic-btn">
              🎤
            </button>
          )}
        </div>
      )}
    </div>
  )
}
