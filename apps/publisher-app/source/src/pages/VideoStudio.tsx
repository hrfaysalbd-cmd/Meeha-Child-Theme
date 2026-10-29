/**
 * Round 21 — "ভিডিও এডিট": the video editor for a video news item
 * (route /app/videos/:id/edit-video).
 *
 * One click adds each element — animated corner logo, intro, outro,
 * animated news frame (header with title + footer with ticker / ad),
 * captions & credits, ad breaks from the video library — with a live
 * preview that is exactly what gets rendered. "রেন্ডার" builds an MP4 in
 * the browser (lib/videoEdit/render.ts), uploads it, and puts it on the
 * post; the original file and the settings are kept on the post so the
 * edit can be reopened or undone (NROS_Videos `edit`).
 */
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  AdminVideosApi,
  MediaApi,
  VideoKitApi,
  describeError,
  downloadMediaFile,
  type LibraryImage,
  type LibraryVideo,
  type Video,
  type VideoKit,
} from '../lib/api'
import { DEFAULT_CONFIG, FRAMES, INTROS, OUTROS, hasEdits, normalizeConfig, styleOnly, type EditConfig, type MediaRef } from '../lib/videoEdit/config'
import { BUILTIN_CLIPS, fetchBlob, loadFonts, loadLogo, type LogoAsset } from '../lib/videoEdit/assets'
import { drawOutputFrame, drawScene, makeScratch, type DrawEnv, type Picture } from '../lib/videoEdit/draw'
import { bnClock, bnDate } from '../lib/videoEdit/motion'
import { PreviewPlayer, probeUrl, type PreviewState } from '../lib/videoEdit/preview'
import { RenderCancelled, exportCapability, renderAudioPreview, renderEdit, type RenderProgress } from '../lib/videoEdit/render'
import { musicBuffer } from '../lib/videoEdit/music'
import { buildTimeline, computeLayout, outputToSource, trimRange, type ClipKey, type SceneId } from '../lib/videoEdit/timeline'
import { bnFileSize, bnDuration } from '../lib/postMeta'
import { Banner, Button, Card, Spinner, bnNumber } from '../components/ui'
import {
  IconAd,
  IconAdjustments,
  IconAspectRatio,
  IconArrowLeft,
  IconBadgeLogo,
  IconCheck,
  IconDeviceFloppy,
  IconDownload,
  IconLayoutRows,
  IconMovie,
  IconMusic,
  IconPlayerPause,
  IconPlayerPlay,
  IconPlayerSkipBack,
  IconPlus,
  IconRedo,
  IconTextCaption,
  IconTrash,
  IconUndo,
  IconWand,
  IconX,
} from '../components/Icon'
import MediaGallery from '../components/MediaGallery'
import VideoLibraryPicker from '../components/videoStudio/VideoLibraryPicker'
import StudioTimeline from '../components/videoStudio/StudioTimeline'
import { AdsPanel, CaptionsPanel, FramePanel, IntroOutroPanel, LayoutPanel, LogoPanel, type ThumbPainter } from '../components/videoStudio/StudioPanels'
import ToolsPanel from '../components/videoStudio/StudioTools'
import BlurOverlay from '../components/videoStudio/BlurOverlay'
import SoundPanel, { type AuditionState } from '../components/videoStudio/StudioSound'

type Tab = 'frame' | 'logo' | 'intro' | 'ads' | 'captions' | 'tools' | 'sound' | 'layout'

const TABS: { id: Tab; name: string; icon: (p: { className?: string }) => ReactNode }[] = [
  { id: 'frame', name: 'ফ্রেম', icon: IconLayoutRows },
  { id: 'logo', name: 'লোগো', icon: IconBadgeLogo },
  { id: 'intro', name: 'ইন্ট্রো/আউট্রো', icon: IconMovie },
  { id: 'captions', name: 'ক্যাপশন', icon: IconTextCaption },
  { id: 'ads', name: 'বিজ্ঞাপন', icon: IconAd },
  { id: 'tools', name: 'সম্পাদনা', icon: IconAdjustments },
  { id: 'sound', name: 'শব্দ ও মিউজিক', icon: IconMusic },
  { id: 'layout', name: 'আকার', icon: IconAspectRatio },
]

const draftKey = (videoId: number, originalId: number) => `meeha-studio:v1:${videoId}:${originalId}`
const STYLE_KEY = 'meeha-studio:last-style'

function readJson(key: string): unknown {
  try {
    const raw = localStorage.getItem(key)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}
function writeJson(key: string, value: unknown) {
  try {
    if (value === null) localStorage.removeItem(key)
    else localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // private mode / full storage: drafts are a convenience only
  }
}

type Job =
  | { stage: 'options' }
  | { stage: 'download'; ratio: number; label: string }
  | { stage: 'render'; p: RenderProgress }
  | { stage: 'upload'; percent: number }
  | { stage: 'saving' }
  | { stage: 'done'; url: string; size: number; attached: boolean; filename: string; tooLarge: boolean }
  | { stage: 'error'; message: string }

interface Loaded {
  video: Video
  kit: VideoKit | null
  logo: LogoAsset
  originalId: number
  mainUrl: string
  main: { duration: number; width: number; height: number }
  maxBytes: number | null
  restored: 'draft' | 'saved' | null
  initial: EditConfig
}

export default function VideoStudio() {
  const { id } = useParams()
  const videoId = Number(id)
  const navigate = useNavigate()

  const [loaded, setLoaded] = useState<Loaded | null>(null)
  const [loadError, setLoadError] = useState('')
  const [cfg, setCfg] = useState<EditConfig>(DEFAULT_CONFIG)
  const [tab, setTab] = useState<Tab>('frame')
  const [pstate, setPstate] = useState<PreviewState>({ time: 0, playing: false, waiting: false })
  const [durations, setDurations] = useState<Record<string, number>>({})
  const [adPic, setAdPic] = useState<Picture | null>(null)
  const [picker, setPicker] = useState<null | { kind: 'ad-add' } | { kind: 'ad-replace'; index: number } | { kind: 'intro' | 'outro' }>(null)
  const [imagePicker, setImagePicker] = useState(false)
  const [job, setJob] = useState<Job | null>(null)
  const [attach, setAttach] = useState(true)
  const [setThumb, setSetThumb] = useState(true)
  const [notice, setNotice] = useState('')
  const [presetMenu, setPresetMenu] = useState(false)
  const [kit, setKit] = useState<VideoKit | null>(null)
  const [stillTick, setStillTick] = useState(0)
  const [selectedBlur, setSelectedBlur] = useState<number | null>(null)
  const [audition, setAudition] = useState<AuditionState>(null)
  const auditionRef = useRef<{ ctrl: AbortController; src: AudioBufferSourceNode | null } | null>(null)

  const canvasRef = useRef<HTMLCanvasElement>(null)
  const playerRef = useRef<PreviewPlayer | null>(null)
  const scratch = useMemo(() => makeScratch(), [])
  const thumbScratch = useMemo(() => makeScratch(), [])
  const stillRef = useRef<HTMLVideoElement | null>(null)
  const history = useRef<{ past: EditConfig[]; future: EditConfig[]; last: number }>({ past: [], future: [], last: 0 })
  const abortRef = useRef<AbortController | null>(null)
  const blobCache = useRef(new Map<string, Blob>())
  const resultUrl = useRef<string | null>(null)
  const capability = useMemo(() => exportCapability(), [])

  // ---- load everything ----
  useEffect(() => {
    let cancelled = false
    ;(async () => {
      try {
        const [video, kitRes] = await Promise.all([AdminVideosApi.get(videoId), VideoKitApi.get().catch(() => null)])
        if (video.source !== 'upload' || !video.video_attachment_id) {
          throw new Error('শুধু আপলোড করা ভিডিও এডিট করা যায় — YouTube লিংকের ভিডিও এখানে এডিট হয় না।')
        }
        const originalId = video.edit?.original_attachment_id ?? video.video_attachment_id
        const mainUrl = video.edit?.original_url || video.video_url
        const [main, logo, limits] = await Promise.all([
          probeUrl(mainUrl),
          (async () => {
            await loadFonts()
            if (kitRes?.logo) {
              try {
                return await loadLogo(await downloadMediaFile(kitRes.logo.id))
              } catch {
                // fall back to the bundled logo
              }
            }
            return loadLogo()
          })(),
          MediaApi.videoLimits().catch(() => null),
        ])
        if (!main) throw new Error('ভিডিওটি এই ব্রাউজারে চালানো যাচ্ছে না (ফরম্যাট/কোডেক) — কম্পিউটারে Chrome বা Edge-এ খুলে চেষ্টা করুন।')

        const draft = readJson(draftKey(videoId, originalId)) as { config?: unknown } | null
        let initial: EditConfig
        let restored: Loaded['restored'] = null
        if (draft?.config) {
          initial = normalizeConfig(draft.config)
          restored = 'draft'
        } else if (video.edit?.config) {
          initial = normalizeConfig(video.edit.config)
          restored = 'saved'
        } else {
          const style = readJson(STYLE_KEY)
          initial = style ? normalizeConfig(style) : { ...DEFAULT_CONFIG }
          initial = { ...initial, frame: { ...initial.frame, ticker: kitRes?.ticker ?? '', adText: initial.frame.adText || kitRes?.ad_text || '' } }
        }
        if (cancelled) return
        setKit(kitRes)
        setCfg(initial)
        setLoaded({
          video,
          kit: kitRes,
          logo,
          originalId,
          mainUrl,
          main,
          maxBytes: limits && limits.max_bytes > 0 ? limits.max_bytes : null,
          restored,
          initial,
        })
      } catch (err) {
        if (!cancelled) setLoadError(err instanceof Error && !('status' in err) ? err.message : describeError(err).message)
      }
    })()
    return () => {
      cancelled = true
    }
  }, [videoId])

  // ---- config updates with undo ----
  const set = useCallback((fn: (c: EditConfig) => EditConfig) => {
    setCfg((prev) => {
      const next = fn(prev)
      if (next === prev) return prev
      const h = history.current
      const now = Date.now()
      if (now - h.last > 700) {
        h.past.push(prev)
        if (h.past.length > 80) h.past.shift()
      }
      h.last = now
      h.future = []
      return next
    })
  }, [])
  const undo = useCallback(() => {
    const h = history.current
    const prev = h.past.pop()
    if (!prev) return
    setCfg((cur) => {
      h.future.push(cur)
      return prev
    })
  }, [])
  const redo = useCallback(() => {
    const h = history.current
    const next = h.future.pop()
    if (!next) return
    setCfg((cur) => {
      h.past.push(cur)
      return next
    })
  }, [])

  // draft autosave
  useEffect(() => {
    if (!loaded) return
    const t = setTimeout(() => {
      const same = JSON.stringify(cfg) === JSON.stringify(loaded.initial)
      writeJson(draftKey(videoId, loaded.originalId), same && loaded.restored !== 'draft' ? null : { config: cfg, at: Date.now() })
    }, 600)
    return () => clearTimeout(t)
  }, [cfg, loaded, videoId])

  // ---- clip URLs + durations ----
  const urls = useMemo(() => {
    const u: Partial<Record<ClipKey, string>> = {}
    if (!loaded) return u
    u.main = loaded.mainUrl
    if (cfg.intro.id === 'meeha-logo') u.intro = BUILTIN_CLIPS['meeha-logo']
    else if (cfg.intro.id === 'custom' && cfg.intro.clip) u.intro = cfg.intro.clip.url
    if (cfg.outro.id === 'meeha-social') u.outro = BUILTIN_CLIPS['meeha-social']
    else if (cfg.outro.id === 'custom' && cfg.outro.clip) u.outro = cfg.outro.clip.url
    cfg.ads.forEach((a, i) => (u[`ad${i}`] = a.clip.url))
    return u
  }, [loaded, cfg.intro, cfg.outro, cfg.ads])

  useEffect(() => {
    for (const url of Object.values(urls)) {
      if (!url || durations[url] !== undefined) continue
      setDurations((d) => ({ ...d, [url]: -1 }))
      probeUrl(url).then((m) => setDurations((d) => ({ ...d, [url]: m ? m.duration : 0 })))
    }
  }, [urls, durations])

  const dur = (url?: string, fallback?: number | null) => {
    if (!url) return null
    const d = durations[url]
    return d && d > 0 ? d : fallback && fallback > 0 ? fallback : null
  }

  // ---- ad banner image ----
  useEffect(() => {
    const ref = cfg.frame.adImage
    if (!ref) {
      setAdPic(null)
      return
    }
    let cancelled = false
    downloadMediaFile(ref.id)
      .then((b) => createImageBitmap(b))
      .then((bmp) => !cancelled && setAdPic({ src: bmp, w: bmp.width, h: bmp.height }))
      .catch(() => !cancelled && setAdPic(null))
    return () => {
      cancelled = true
    }
  }, [cfg.frame.adImage])

  // ---- layout / timeline / draw env ----
  const L = useMemo(() => (loaded ? computeLayout(cfg, loaded.main.width, loaded.main.height) : null), [loaded, cfg])
  const tl = useMemo(() => {
    if (!loaded) return null
    return buildTimeline(cfg, {
      main: loaded.main.duration,
      intro: dur(urls.intro, cfg.intro.clip?.duration),
      outro: dur(urls.outro, cfg.outro.clip?.duration),
      ads: cfg.ads.map((a, i) => dur(urls[`ad${i}`], a.clip.duration)),
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loaded, cfg, urls, durations])

  const postTitle = loaded?.video.title ?? ''
  const env: DrawEnv | null = useMemo(() => {
    if (!loaded || !L || !tl) return null
    const title = cfg.frame.title.trim() || postTitle
    return {
      cfg,
      L,
      tl,
      logo: loaded.logo,
      adImage: adPic,
      title,
      introTitle: cfg.intro.text.trim() || title,
      kicker: cfg.frame.kicker,
      ticker: cfg.frame.ticker.trim() || title,
      dateText: bnDate(),
      website: kit?.website || 'meeha.news',
      scratch,
    }
  }, [loaded, L, tl, cfg, adPic, kit, postTitle, scratch])

  // ---- preview player ----
  useEffect(() => {
    if (!canvasRef.current || !loaded) return
    const p = new PreviewPlayer(canvasRef.current, setPstate)
    playerRef.current = p
    return () => {
      p.destroy()
      playerRef.current = null
    }
  }, [loaded])
  useEffect(() => {
    if (env) playerRef.current?.setEnv(env)
  }, [env])
  // the brand music is synthesised on first use; get the chosen piece ready early
  useEffect(() => {
    if (cfg.audio.music !== 'none') musicBuffer(cfg.audio.music).catch(() => undefined)
  }, [cfg.audio.music])
  useEffect(() => {
    playerRef.current?.setSources(urls)
  }, [urls])

  // a still of the main video for the design thumbnails
  useEffect(() => {
    if (!loaded) return
    const v = document.createElement('video')
    v.muted = true
    v.preload = 'auto'
    v.src = loaded.mainUrl
    v.onloadeddata = () => {
      v.currentTime = Math.min(loaded.main.duration * 0.3, 2)
    }
    v.onseeked = () => {
      stillRef.current = v
      setStillTick((n) => n + 1)
    }
    return () => {
      v.removeAttribute('src')
      v.load()
    }
  }, [loaded])

  const thumbRatio = L ? L.W / L.H : 16 / 9
  const thumbKey = `${stillTick}|${cfg.format}|${cfg.fit}|${cfg.frame.size}|${cfg.frame.title}|${cfg.frame.kicker}|${cfg.frame.footer}|${cfg.intro.text}|${L?.W}x${L?.H}|${kit?.website}|${JSON.stringify(cfg.look)}`

  const thumb: ThumbPainter = useCallback(
    (kind, id) => {
      if (!env || !loaded) return null
      return (canvas: HTMLCanvasElement) => {
        let c2 = cfg
        if (kind === 'frame') c2 = { ...cfg, frame: { ...cfg.frame, id: id as EditConfig['frame']['id'] }, logo: { ...cfg.logo, on: false }, captions: [] }
        const L2 = computeLayout(c2, loaded.main.width, loaded.main.height)
        const tl2 = buildTimeline({ ...c2, intro: { ...c2.intro, id: 'none' }, outro: { ...c2.outro, id: 'none' }, ads: [] }, { main: loaded.main.duration })
        const w = 360
        canvas.width = w
        canvas.height = Math.round((w * L2.H) / L2.W)
        const ctx = canvas.getContext('2d')!
        ctx.setTransform(w / L2.W, 0, 0, w / L2.W, 0, 0)
        const e2: DrawEnv = { ...env, cfg: c2, L: L2, tl: tl2, scratch: thumbScratch }
        const still = stillRef.current
        const pic: Picture | null = still && still.videoWidth ? { src: still, w: still.videoWidth, h: still.videoHeight } : null
        if (kind === 'frame') {
          const seg = tl2.segments[0]
          drawOutputFrame(ctx, e2, { ...seg, mainOffset: 3 - 0 }, seg.t0, pic)
        } else {
          const scene = (kind === 'intro' ? INTROS : OUTROS).find((x) => x.id === id)
          if (scene?.duration) drawScene(ctx, e2, id as SceneId, kind === 'intro' ? scene.duration * 0.55 : 1.8, scene.duration)
          else {
            // clip-based: a branded placeholder card
            const g = ctx.createLinearGradient(0, 0, L2.W, L2.H)
            g.addColorStop(0, id === 'meeha-logo' ? '#F1F6FC' : id === 'meeha-social' ? '#025AB2' : '#3F3F46')
            g.addColorStop(1, id === 'meeha-logo' ? '#DCE8F6' : id === 'meeha-social' ? '#0B2746' : '#18181B')
            ctx.fillStyle = g
            ctx.fillRect(0, 0, L2.W, L2.H)
            const lh = Math.min(L2.W, L2.H) * 0.12
            const lw = (loaded.logo.w / loaded.logo.h) * lh
            if (id === 'meeha-social') {
              ctx.fillStyle = '#fff'
              ctx.beginPath()
              ctx.roundRect(L2.W / 2 - lw / 2 - lh * 0.4, L2.H / 2 - lh * 0.9, lw + lh * 0.8, lh * 1.8, lh * 0.3)
              ctx.fill()
            }
            if (id !== 'custom') ctx.drawImage(loaded.logo.color, L2.W / 2 - lw / 2, L2.H / 2 - lh / 2, lw, lh)
            else {
              ctx.fillStyle = '#A1A1AA'
              ctx.font = `600 ${lh * 0.6}px "MeehaKit Sans", sans-serif`
              ctx.textAlign = 'center'
              ctx.textBaseline = 'middle'
              ctx.fillText('নিজের ভিডিও', L2.W / 2, L2.H / 2)
            }
          }
        }
        ctx.setTransform(1, 0, 0, 1, 0, 0)
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [env, loaded, thumbKey],
  )

  // ---- keyboard ----
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const tag = (e.target as HTMLElement)?.tagName
      const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !typing) {
        e.preventDefault()
        if (e.shiftKey) redo()
        else undo()
      } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y' && !typing) {
        e.preventDefault()
        redo()
      } else if (e.code === 'Space' && !typing && !job) {
        e.preventDefault()
        playerRef.current?.toggle()
      } else if (!typing && !job && (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === ',' || e.key === '.')) {
        // ← → one second (Shift: five), , . one frame
        const p = playerRef.current
        if (!p) return
        e.preventDefault()
        const step = e.key === ',' || e.key === '.' ? 1 / 30 : e.shiftKey ? 5 : 1
        p.pause()
        p.seek(p.time + (e.key === 'ArrowLeft' || e.key === ',' ? -step : step))
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [undo, redo, job])

  // leaving mid-render
  useEffect(() => {
    if (!job || job.stage === 'options' || job.stage === 'done' || job.stage === 'error') return
    const h = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', h)
    return () => window.removeEventListener('beforeunload', h)
  }, [job])

  useEffect(
    () => () => {
      abortRef.current?.abort()
      if (resultUrl.current) URL.revokeObjectURL(resultUrl.current)
    },
    [],
  )

  // ---- helpers ----
  const range = loaded ? trimRange(cfg, loaded.main.duration) : { start: 0, end: 0 }
  const playheadSrc = useCallback(() => (tl ? outputToSource(tl, playerRef.current?.time ?? 0) : null), [tl])
  const seekToSource = (src: number) => {
    if (!tl) return
    for (const s of tl.segments) if (s.kind === 'main' && src >= s.clipStart && src <= s.clipStart + s.dur) return playerRef.current?.seek(s.t0 + (src - s.clipStart))
  }

  function addAd(v: LibraryVideo, replaceIndex?: number) {
    const clip: MediaRef = { id: v.id, url: v.url, title: v.title, duration: v.duration }
    if (replaceIndex !== undefined) {
      set((c) => ({ ...c, ads: c.ads.map((a, i) => (i === replaceIndex ? { ...a, clip } : a)) }))
      return
    }
    const p = playheadSrc()
    const at = p !== null && p > range.start + 0.3 && p < range.end - 0.3 ? p : range.start + (range.end - range.start) / 2
    set((c) => ({ ...c, ads: [...c.ads, { at: Math.round(at * 10) / 10, clip, bumper: true }] }))
    setTab('ads')
  }

  async function getBlob(
    key: string,
    load: (onProgress: (l: number, t: number) => void) => Promise<Blob>,
    label: string,
    signal: AbortSignal,
    report: (label: string, ratio: number) => void,
  ) {
    const hit = blobCache.current.get(key)
    if (hit) return hit
    report(label, 0)
    const b = await load((l, t) => {
      if (!signal.aborted) report(label, t ? l / t : 0)
    })
    blobCache.current.set(key, b)
    return b
  }

  /** Downloads (once) every file the timeline plays. */
  async function collectClips(signal: AbortSignal, report: (label: string, ratio: number) => void, only?: Set<ClipKey>) {
    const clips: Partial<Record<ClipKey, Blob>> = {}
    if (!loaded) return clips
    const want = (k: ClipKey) => !only || only.has(k)
    if (want('main')) clips.main = await getBlob(`m${loaded.originalId}`, (p) => downloadMediaFile(loaded.originalId, p, signal), 'মূল ভিডিও নামানো হচ্ছে…', signal, report)
    const clipFor = async (key: ClipKey, url: string | undefined, ref: MediaRef | null, builtin: boolean, label: string) => {
      if (!url || !want(key)) return
      if (builtin) clips[key] = await getBlob(url, () => fetchBlob(url, signal), label, signal, report)
      else if (ref) clips[key] = await getBlob(`m${ref.id}`, (p) => downloadMediaFile(ref.id, p, signal), label, signal, report)
    }
    await clipFor('intro', urls.intro, cfg.intro.clip, cfg.intro.id === 'meeha-logo', 'ইন্ট্রো ভিডিও লোড হচ্ছে…')
    await clipFor('outro', urls.outro, cfg.outro.clip, cfg.outro.id === 'meeha-social', 'আউট্রো ভিডিও লোড হচ্ছে…')
    for (let i = 0; i < cfg.ads.length; i++) await clipFor(`ad${i}`, urls[`ad${i}`], cfg.ads[i].clip, false, `বিজ্ঞাপন ${bnNumber(i + 1)} লোড হচ্ছে…`)
    return clips
  }

  // ---- "শুনে দেখুন": the exact export soundtrack for 15 s from the playhead ----
  function stopAudition() {
    const a = auditionRef.current
    auditionRef.current = null
    if (a) {
      a.ctrl.abort()
      try {
        a.src?.stop()
      } catch {
        // already stopped
      }
    }
    playerRef.current?.setMuted(false)
    if (a?.src) playerRef.current?.pause()
    setAudition(null)
  }

  async function startAudition() {
    const player = playerRef.current
    if (!loaded || !tl || !player) return
    stopAudition()
    const ctrl = new AbortController()
    const mine = { ctrl, src: null as AudioBufferSourceNode | null }
    auditionRef.current = mine
    const from = Math.min(player.time, Math.max(0, tl.duration - 1))
    const seconds = Math.min(15, tl.duration - from)
    player.pause()
    try {
      const only = new Set(tl.segments.filter((s) => s.clip && s.t0 < from + seconds && s.t0 + s.dur > from).map((s) => s.clip as ClipKey))
      const clips = await collectClips(ctrl.signal, (label, ratio) => setAudition({ stage: 'loading', label: ratio > 0 ? `${label} ${bnNumber(Math.round(ratio * 100))}%` : label }), only)
      if (auditionRef.current !== mine) return
      setAudition({ stage: 'loading', label: 'সাউন্ড তৈরি হচ্ছে…' })
      const buf = await renderAudioPreview(cfg, tl, clips, from, seconds, ctrl.signal)
      const ctx = player.audioContext()
      if (auditionRef.current !== mine || !ctx) return
      await ctx.resume().catch(() => undefined)
      player.seek(from)
      player.setMuted(true)
      player.play()
      const src = ctx.createBufferSource()
      src.buffer = buf
      src.connect(ctx.destination)
      src.onended = () => {
        if (auditionRef.current === mine) stopAudition()
      }
      src.start()
      mine.src = src
      setAudition({ stage: 'playing', from, seconds })
    } catch (err) {
      if (auditionRef.current !== mine) return
      auditionRef.current = null
      player.setMuted(false)
      setAudition(null)
      if (!(err instanceof RenderCancelled) && !ctrl.signal.aborted) setNotice(`সাউন্ড তৈরি করা যায়নি: ${err instanceof Error && !('status' in err) ? err.message : describeError(err).message}`)
    }
  }

  async function startRender() {
    if (!loaded || !env || !tl || !L) return
    stopAudition()
    const ctrl = new AbortController()
    abortRef.current = ctrl
    const signal = ctrl.signal
    try {
      // 1 — files
      const clips = await collectClips(signal, (label, ratio) => setJob({ stage: 'download', ratio, label }))

      // 2 — render
      playerRef.current?.pause()
      const result = await renderEdit({
        cfg,
        tl,
        env: { ...env, scratch: makeScratch() },
        clips,
        maxBytes: attach ? loaded.maxBytes : null,
        posterAt: tl.mainStart + Math.min(2.5, tl.mainDuration / 3),
        signal,
        onProgress: (p) => setJob({ stage: 'render', p }),
      })
      const slug = (loaded.video.slug ? decodeURIComponent(loaded.video.slug) : 'meeha-video').slice(0, 60)
      const filename = `${slug}-edit.${result.ext}`
      if (resultUrl.current) URL.revokeObjectURL(resultUrl.current)
      resultUrl.current = URL.createObjectURL(result.blob)
      const tooLarge = !!loaded.maxBytes && result.blob.size > loaded.maxBytes
      writeJson(STYLE_KEY, styleOnly(cfg))

      if (!attach || tooLarge) {
        setJob({ stage: 'done', url: resultUrl.current, size: result.blob.size, attached: false, filename, tooLarge })
        return
      }

      // 3 — upload + attach
      setJob({ stage: 'upload', percent: 0 })
      const up = await MediaApi.uploadVideo(new File([result.blob], filename, { type: result.mime }), (p) => setJob({ stage: 'upload', percent: p.percent }), signal)
      setJob({ stage: 'saving' })
      let thumbId: number | undefined
      if (setThumb && result.poster) {
        try {
          const t = await MediaApi.upload(new File([result.poster], `${slug}-thumbnail.jpg`, { type: 'image/jpeg' }))
          thumbId = t.id
        } catch {
          // the video matters more than its thumbnail
        }
      }
      const saved = await AdminVideosApi.update(loaded.video.id, {
        source: 'upload',
        video_attachment_id: up.id,
        duration: Math.round(result.duration),
        orientation: result.height >= result.width ? 'portrait' : 'landscape',
        ...(thumbId ? { thumbnail_id: thumbId } : {}),
        edit: { original_attachment_id: loaded.originalId, config: cfg as unknown as Record<string, unknown> },
      })
      writeJson(draftKey(videoId, loaded.originalId), null)
      setLoaded((l) => (l ? { ...l, video: saved, restored: null, initial: cfg } : l))
      setJob({ stage: 'done', url: resultUrl.current, size: result.blob.size, attached: true, filename, tooLarge: false })
    } catch (err) {
      if (err instanceof RenderCancelled || signal.aborted) {
        setJob(null)
        setNotice('রেন্ডার বাতিল করা হয়েছে।')
        return
      }
      setJob({ stage: 'error', message: err instanceof Error && !('status' in err) ? err.message : describeError(err).message })
    } finally {
      if (abortRef.current === ctrl) abortRef.current = null
    }
  }

  // ---- presets ----
  async function savePreset() {
    setPresetMenu(false)
    const name = window.prompt('প্রিসেটের নাম দিন (যেমন: ব্রেকিং — রিলস)')?.trim()
    if (!name || !kit) return
    try {
      const next = await VideoKitApi.savePresets([
        ...kit.presets,
        { id: `p${Date.now().toString(36)}`, name: name.slice(0, 60), config: styleOnly(cfg) as unknown as Record<string, unknown> },
      ])
      setKit(next)
      setNotice(`“${name}” প্রিসেট সংরক্ষিত — সবাই ব্যবহার করতে পারবেন।`)
    } catch (err) {
      setNotice(describeError(err).message)
    }
  }
  function applyPreset(config: Record<string, unknown>) {
    const p = normalizeConfig(config)
    set((c) => ({
      ...p,
      trim: c.trim,
      frame: { ...p.frame, title: c.frame.title, ticker: c.frame.ticker || p.frame.ticker },
      intro: { ...p.intro, text: c.intro.text },
      ads: c.ads,
      captions: c.captions,
      cuts: c.cuts,
      blurs: c.blurs,
      look: { ...p.look, zoom: c.look.zoom, panX: c.look.panX, panY: c.look.panY, rotate: c.look.rotate, flip: c.look.flip },
    }))
    setPresetMenu(false)
  }
  async function deletePreset(pid: string) {
    if (!kit) return
    try {
      setKit(await VideoKitApi.savePresets(kit.presets.filter((p) => p.id !== pid)))
    } catch (err) {
      setNotice(describeError(err).message)
    }
  }

  // ---- render ----
  if (loadError) {
    return (
      <div>
        <BackLink id={videoId} />
        <Banner kind="error">{loadError}</Banner>
      </div>
    )
  }
  if (!loaded || !env || !tl || !L) {
    return (
      <div>
        <BackLink id={videoId} />
        <Spinner className="mt-10" />
        <p className="text-center text-[12.5px] text-neutral-400">এডিটর প্রস্তুত হচ্ছে…</p>
      </div>
    )
  }

  const edited = hasEdits(cfg, loaded.main.duration)
  const chips: { label: string; on: boolean; onClick: () => void; testId: string }[] = [
    {
      label: 'লোগো',
      on: cfg.logo.on,
      testId: 'chip-logo',
      onClick: () => {
        set((c) => ({ ...c, logo: { ...c.logo, on: !c.logo.on } }))
        setTab('logo')
      },
    },
    {
      label: 'ইন্ট্রো',
      on: cfg.intro.id !== 'none',
      testId: 'chip-intro',
      onClick: () => {
        // The newsroom's own intro (Settings → ভিডিও এডিটর কিট) wins over the built-in one.
        set((c) =>
          c.intro.id !== 'none'
            ? { ...c, intro: { ...c.intro, id: 'none' } }
            : kit?.intro
              ? { ...c, intro: { ...c.intro, id: 'custom', clip: kit.intro } }
              : { ...c, intro: { ...c.intro, id: 'meeha-logo' } },
        )
        setTab('intro')
      },
    },
    {
      label: 'আউট্রো',
      on: cfg.outro.id !== 'none',
      testId: 'chip-outro',
      onClick: () => {
        set((c) =>
          c.outro.id !== 'none'
            ? { ...c, outro: { ...c.outro, id: 'none' } }
            : kit?.outro
              ? { ...c, outro: { ...c.outro, id: 'custom', clip: kit.outro } }
              : { ...c, outro: { ...c.outro, id: 'meeha-social' } },
        )
        setTab('intro')
      },
    },
    {
      label: 'ফ্রেম',
      on: cfg.frame.id !== 'none',
      testId: 'chip-frame',
      onClick: () => {
        set((c) => ({ ...c, frame: { ...c.frame, id: c.frame.id === 'none' ? 'news' : 'none' } }))
        setTab('frame')
      },
    },
    { label: 'ক্যাপশন', on: cfg.captions.length > 0, testId: 'chip-captions', onClick: () => setTab('captions') },
    { label: 'মিউজিক', on: cfg.audio.music !== 'none', testId: 'chip-music', onClick: () => setTab('sound') },
    { label: 'বিজ্ঞাপন', on: cfg.ads.length > 0, testId: 'chip-ads', onClick: () => setPicker({ kind: 'ad-add' }) },
  ]
  const frameName = FRAMES.find((f) => f.id === cfg.frame.id)?.name

  return (
    <div className="pb-10">
      {/* header */}
      <div className="flex items-start justify-between gap-3 mb-4 flex-wrap">
        <div className="min-w-0">
          <BackLink id={videoId} />
          <h1 className="text-[21px] font-bold text-neutral-900 flex items-center gap-2">
            <IconWand className="w-6 h-6 text-brand-600" />
            ভিডিও এডিট
          </h1>
          <p className="text-[13px] text-neutral-500 truncate max-w-[60ch]">{loaded.video.title}</p>
        </div>
        <div className="flex items-center gap-1.5 flex-wrap">
          <button type="button" onClick={undo} disabled={!history.current.past.length} title="আগের অবস্থায় (Ctrl+Z)" className="p-2 rounded-lg bg-white border border-neutral-200 disabled:opacity-40">
            <IconUndo className="w-4 h-4" />
          </button>
          <button type="button" onClick={redo} disabled={!history.current.future.length} title="আবার করুন (Ctrl+Y)" className="p-2 rounded-lg bg-white border border-neutral-200 disabled:opacity-40">
            <IconRedo className="w-4 h-4" />
          </button>
          <div className="relative">
            <Button variant="secondary" onClick={() => setPresetMenu((v) => !v)}>
              <IconDeviceFloppy className="w-4 h-4" />
              প্রিসেট
            </Button>
            {presetMenu && (
              <div className="absolute right-0 mt-1.5 w-72 rounded-xl bg-white border border-neutral-200 shadow-lg z-30 p-2">
                {kit?.presets.length ? (
                  kit.presets.map((p) => (
                    <div key={p.id} className="flex items-center gap-1 rounded-lg hover:bg-neutral-50">
                      <button type="button" onClick={() => applyPreset(p.config)} className="flex-1 text-left px-2.5 py-2 text-[13px] text-neutral-800 truncate">
                        {p.name}
                      </button>
                      <button type="button" onClick={() => deletePreset(p.id)} aria-label="মুছুন" className="p-1.5 text-neutral-400 hover:text-red-600">
                        <IconTrash className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  ))
                ) : (
                  <p className="px-2.5 py-2 text-[12px] text-neutral-400">কোনো প্রিসেট নেই। নিজের স্টাইল সংরক্ষণ করলে পুরো টিম ব্যবহার করতে পারবে।</p>
                )}
                <button type="button" onClick={savePreset} disabled={!kit} className="mt-1 w-full flex items-center gap-1.5 px-2.5 py-2 rounded-lg bg-neutral-100 text-[12.5px] font-medium text-neutral-700 disabled:opacity-50">
                  <IconPlus className="w-4 h-4" />
                  বর্তমান স্টাইল প্রিসেট হিসেবে রাখুন
                </button>
              </div>
            )}
          </div>
          <Button onClick={() => setJob({ stage: 'options' })} disabled={!edited || !capability.ok}>
            <IconMovie className="w-4 h-4" />
            রেন্ডার করুন
          </Button>
        </div>
      </div>

      {!capability.ok && <Banner kind="error">{capability.reason}</Banner>}
      {loaded.restored === 'draft' && (
        <Banner kind="info">
          আগের অসমাপ্ত এডিট ফিরিয়ে আনা হয়েছে।{' '}
          <button
            type="button"
            className="underline font-medium"
            onClick={() => {
              writeJson(draftKey(videoId, loaded.originalId), null)
              const base = loaded.video.edit?.config ? normalizeConfig(loaded.video.edit.config) : DEFAULT_CONFIG
              set(() => base)
              setLoaded((l) => (l ? { ...l, restored: null, initial: base } : l))
            }}
          >
            নতুন করে শুরু করুন
          </button>
        </Banner>
      )}
      {loaded.restored === 'saved' && loaded.video.edit && (
        <Banner kind="info">এই ভিডিওটি আগে এডিট করা হয়েছিল — মূল (এডিটের আগের) ভিডিও আর আগের সেটিং দিয়ে খোলা হয়েছে। নতুন রেন্ডার আগেরটার জায়গা নেবে।</Banner>
      )}
      {notice && (
        <div className="rounded-xl border border-neutral-200 bg-white px-3.5 py-2.5 text-[13px] mb-4 flex items-center justify-between gap-3">
          <span>{notice}</span>
          <button onClick={() => setNotice('')} aria-label="বন্ধ" className="text-neutral-400">
            <IconX className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* one-click add */}
      <div className="flex flex-wrap gap-2 mb-4" data-testid="quick-add">
        {chips.map((c) => (
          <button
            key={c.label}
            type="button"
            onClick={c.onClick}
            data-testid={c.testId}
            aria-pressed={c.on}
            className={`flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-[13px] font-semibold border transition ${
              c.on ? 'bg-brand-600 border-brand-600 text-white' : 'bg-white border-neutral-200 text-neutral-700 hover:border-brand-500'
            }`}
          >
            {c.on ? <IconCheck className="w-4 h-4" /> : <IconPlus className="w-4 h-4" />}
            {c.label}
          </button>
        ))}
      </div>

      <div className="grid lg:grid-cols-[minmax(0,1fr)_400px] gap-5 items-start">
        {/* preview */}
        <Card className="p-4 lg:sticky lg:top-4">
          <div className="relative rounded-xl bg-[#0b0d12] flex items-center justify-center overflow-hidden" style={{ height: 'min(62vh, 640px)' }}>
            <canvas
              ref={canvasRef}
              onClick={() => playerRef.current?.toggle()}
              className="max-w-full max-h-full cursor-pointer"
              style={{ aspectRatio: `${L.W} / ${L.H}` }}
              data-testid="studio-canvas"
            />
            {tab === 'tools' && selectedBlur !== null && (
              <BlurOverlay canvas={canvasRef.current} cfg={cfg} L={L} picW={loaded.main.width} picH={loaded.main.height} index={selectedBlur} set={set} />
            )}
          </div>
          <div className="flex items-center gap-2 mt-3">
            <button
              type="button"
              onClick={() => playerRef.current?.toggle()}
              className="w-10 h-10 rounded-full bg-brand-600 text-white flex items-center justify-center shrink-0"
              aria-label={pstate.playing ? 'থামান' : 'চালান'}
              data-testid="play"
            >
              {pstate.playing ? <IconPlayerPause className="w-5 h-5" /> : <IconPlayerPlay className="w-5 h-5 ml-0.5" />}
            </button>
            <button type="button" onClick={() => playerRef.current?.seek(0)} className="w-9 h-9 rounded-full bg-neutral-100 text-neutral-700 flex items-center justify-center shrink-0" aria-label="শুরুতে যান">
              <IconPlayerSkipBack className="w-4 h-4" />
            </button>
            <span className="text-[13px] font-medium text-neutral-800 tabular-nums">
              {bnClock(pstate.time)} / {bnClock(tl.duration)}
            </span>
            {pstate.waiting && <span className="text-[11.5px] text-neutral-400">লোড হচ্ছে…</span>}
            <span className="ml-auto text-[11.5px] text-neutral-400 tabular-nums">
              {bnNumber(L.W)}×{bnNumber(L.H)}
              {frameName ? ` · ${frameName}` : ''}
            </span>
          </div>
          <div className="mt-3">
            <StudioTimeline tl={tl} cfg={cfg} time={pstate.time} onSeek={(t) => playerRef.current?.seek(t)} />
          </div>
          <p className="mt-2 text-[11.5px] text-neutral-400">
            স্পেসবার = চালান/থামান · ← → এক সেকেন্ড (Shift: পাঁচ) · , . এক ফ্রেম · প্রিভিউ যা দেখাচ্ছে, রেন্ডারে ঠিক তা-ই হবে।
          </p>
        </Card>

        {/* panels */}
        <Card className="overflow-hidden">
          <div className="flex overflow-x-auto border-b border-neutral-100 px-2" role="tablist">
            {TABS.map((t) => (
              <button
                key={t.id}
                role="tab"
                aria-selected={tab === t.id}
                onClick={() => setTab(t.id)}
                data-testid={`tab-${t.id}`}
                className={`flex flex-col items-center gap-0.5 px-2.5 py-2.5 text-[11.5px] font-medium whitespace-nowrap border-b-2 -mb-px ${
                  tab === t.id ? 'border-brand-600 text-brand-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'
                }`}
              >
                {t.icon({ className: 'w-5 h-5' })}
                {t.name}
              </button>
            ))}
          </div>
          <div className="p-4 max-h-none lg:max-h-[calc(100vh-180px)] lg:overflow-y-auto">
            {tab === 'frame' && (
              <FramePanel cfg={cfg} set={set} postTitle={postTitle} thumb={thumb} thumbRatio={thumbRatio} thumbKey={thumbKey} onPickAdImage={() => setImagePicker(true)} />
            )}
            {tab === 'logo' && <LogoPanel cfg={cfg} set={set} customLogo={!!kit?.logo} />}
            {tab === 'intro' && (
              <IntroOutroPanel cfg={cfg} set={set} postTitle={postTitle} thumb={thumb} thumbRatio={thumbRatio} thumbKey={thumbKey} kit={kit} onPick={(w) => setPicker({ kind: w })} />
            )}
            {tab === 'ads' && (
              <AdsPanel cfg={cfg} set={set} range={range} playhead={playheadSrc} onAdd={() => setPicker({ kind: 'ad-add' })} onReplace={(i) => setPicker({ kind: 'ad-replace', index: i })} />
            )}
            {tab === 'tools' && (
              <ToolsPanel cfg={cfg} set={set} range={range} playhead={playheadSrc} selectedBlur={selectedBlur} onSelectBlur={setSelectedBlur} />
            )}
            {tab === 'captions' && <CaptionsPanel cfg={cfg} set={set} range={range} playhead={playheadSrc} defaultName={loaded.video.author.name} />}
            {tab === 'layout' && (
              <LayoutPanel
                cfg={cfg}
                set={set}
                srcDuration={loaded.main.duration}
                playhead={playheadSrc}
                outputSize={`${bnNumber(L.W)}×${bnNumber(L.H)} · ${bnDuration(tl.duration) || bnClock(tl.duration)}`}
              />
            )}
            {tab === 'sound' && (
              <SoundPanel
                cfg={cfg}
                set={set}
                audioContext={() => playerRef.current?.audioContext() ?? null}
                onSample={() => {
                  stopAudition()
                  playerRef.current?.pause()
                }}
                audition={audition}
                onAudition={startAudition}
                onStopAudition={stopAudition}
              />
            )}
            {tab === 'captions' && cfg.captions.length > 0 && (
              <p className="mt-3 text-[11.5px] text-neutral-400">
                টিপ: প্রিভিউ থামিয়ে “এখানে” চাপলে সেই মুহূর্ত থেকে ক্যাপশন শুরু হবে।{' '}
                <button type="button" className="underline" onClick={() => seekToSource(cfg.captions[cfg.captions.length - 1].at)}>
                  শেষ ক্যাপশনে যান
                </button>
              </p>
            )}
          </div>
        </Card>
      </div>

      {picker && (
        <VideoLibraryPicker
          title={picker.kind === 'intro' ? 'ইন্ট্রো ভিডিও বেছে নিন' : picker.kind === 'outro' ? 'আউট্রো ভিডিও বেছে নিন' : 'বিজ্ঞাপন ভিডিও বেছে নিন'}
          adsFirst={picker.kind === 'ad-add' || picker.kind === 'ad-replace'}
          onClose={() => setPicker(null)}
          onSelect={(v) => {
            const ref: MediaRef = { id: v.id, url: v.url, title: v.title, duration: v.duration }
            if (picker.kind === 'ad-add') addAd(v)
            else if (picker.kind === 'ad-replace') addAd(v, picker.index)
            else if (picker.kind === 'intro') set((c) => ({ ...c, intro: { ...c.intro, id: 'custom', clip: ref } }))
            else set((c) => ({ ...c, outro: { ...c.outro, id: 'custom', clip: ref } }))
            setPicker(null)
          }}
        />
      )}
      {imagePicker && (
        <MediaGallery
          title="ফুটারের বিজ্ঞাপন ব্যানার — গ্যালারি থেকে বেছে নিন"
          onClose={() => setImagePicker(false)}
          onSelect={(img: LibraryImage) => {
            set((c) => ({ ...c, frame: { ...c.frame, footer: 'ad-image', adImage: { id: img.id, url: img.url, title: img.title } } }))
            setImagePicker(false)
          }}
        />
      )}
      {job && (
        <RenderDialog
          job={job}
          attach={attach}
          setAttach={setAttach}
          setThumb={setThumb}
          onSetThumb={setSetThumb}
          duration={tl.duration}
          size={`${bnNumber(L.W)}×${bnNumber(L.H)}`}
          onStart={startRender}
          onCancel={() => abortRef.current?.abort()}
          onClose={() => setJob(null)}
          onBack={() => navigate(`/app/videos/${videoId}`, { state: { notice: 'এডিট করা ভিডিও পোস্টে বসানো হয়েছে।' } })}
        />
      )}
    </div>
  )
}

function BackLink({ id }: { id: number }) {
  return (
    <Link to={`/app/videos/${id}`} className="inline-flex items-center gap-1 text-[12.5px] text-neutral-500 hover:text-neutral-800 mb-1">
      <IconArrowLeft className="w-4 h-4" />
      ভিডিও পোস্টে ফিরুন
    </Link>
  )
}

function RenderDialog({
  job,
  attach,
  setAttach,
  setThumb,
  onSetThumb,
  duration,
  size,
  onStart,
  onCancel,
  onClose,
  onBack,
}: {
  job: Job
  attach: boolean
  setAttach: (v: boolean) => void
  setThumb: boolean
  onSetThumb: (v: boolean) => void
  duration: number
  size: string
  onStart: () => void
  onCancel: () => void
  onClose: () => void
  onBack: () => void
}) {
  const busy = job.stage === 'download' || job.stage === 'render' || job.stage === 'upload' || job.stage === 'saving'
  const bar = (ratio: number) => (
    <div className="h-2.5 rounded-full bg-neutral-100 overflow-hidden">
      <div className="h-full bg-brand-600 transition-[width] duration-200" style={{ width: `${Math.round(Math.min(1, ratio) * 100)}%` }} />
    </div>
  )
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
      <div className="bg-white rounded-2xl w-full max-w-md shadow-xl p-5" data-testid="render-dialog">
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-[16px] font-semibold text-neutral-900">ভিডিও রেন্ডার</h2>
          {!busy && (
            <button onClick={onClose} aria-label="বন্ধ করুন" className="text-neutral-400 hover:text-neutral-700">
              <IconX className="w-5 h-5" />
            </button>
          )}
        </div>

        {job.stage === 'options' && (
          <>
            <p className="text-[13px] text-neutral-600 mb-3">
              {size} · দৈর্ঘ্য {bnClock(duration)} — ভিডিও আপনার ব্রাউজারেই তৈরি হবে (সার্ভারে চাপ পড়বে না)। সাধারণত ভিডিওর দৈর্ঘ্যের চেয়ে কম সময় লাগে।
            </p>
            <label className="flex items-start gap-2.5 py-2">
              <input type="checkbox" checked={attach} onChange={(e) => setAttach(e.target.checked)} className="mt-1 accent-[#b91c1c]" />
              <span>
                <span className="block text-[13.5px] text-neutral-800">ভিডিও পোস্টে বসিয়ে দিন</span>
                <span className="block text-[11.5px] text-neutral-400">মূল ভিডিও মুছবে না — পরে আবার এডিট বা মূলটিতে ফেরা যাবে।</span>
              </span>
            </label>
            {attach && (
              <label className="flex items-start gap-2.5 py-2">
                <input type="checkbox" checked={setThumb} onChange={(e) => onSetThumb(e.target.checked)} className="mt-1 accent-[#b91c1c]" />
                <span>
                  <span className="block text-[13.5px] text-neutral-800">থাম্বনেইলও নতুন করুন</span>
                  <span className="block text-[11.5px] text-neutral-400">ফ্রেমসহ সংবাদের একটি দৃশ্য থাম্বনেইল হবে।</span>
                </span>
              </label>
            )}
            <div className="flex gap-2 mt-4">
              <Button className="flex-1" onClick={onStart}>
                {attach ? 'রেন্ডার করে পোস্টে বসান' : 'রেন্ডার করে ডাউনলোড'}
              </Button>
              <Button variant="secondary" onClick={onClose}>
                বাতিল
              </Button>
            </div>
          </>
        )}

        {job.stage === 'download' && (
          <>
            <p className="text-[13px] text-neutral-700 mb-2">{job.label}</p>
            {bar(job.ratio)}
            <p className="text-[11.5px] text-neutral-400 mt-1.5">{bnNumber(Math.round(job.ratio * 100))}%</p>
          </>
        )}
        {job.stage === 'render' && (
          <>
            <p className="text-[13px] text-neutral-700 mb-2">
              {job.p.stage === 'prepare' ? 'প্রস্তুত হচ্ছে…' : job.p.stage === 'finish' ? 'ফাইল তৈরি শেষ হচ্ছে…' : `রেন্ডার হচ্ছে — ${bnClock(job.p.done)} / ${bnClock(job.p.total)}`}
            </p>
            {bar(job.p.ratio)}
            <p className="text-[11.5px] text-neutral-400 mt-1.5">
              {bnNumber(Math.round(job.p.ratio * 100))}%
              {job.p.eta !== null && job.p.stage === 'render' ? ` · আনুমানিক আর ${bnClock(job.p.eta)}` : ''} · এই ট্যাব বন্ধ করবেন না
            </p>
          </>
        )}
        {job.stage === 'upload' && (
          <>
            <p className="text-[13px] text-neutral-700 mb-2">{job.percent >= 100 ? 'সার্ভারে প্রসেস হচ্ছে…' : 'আপলোড হচ্ছে…'}</p>
            {bar(job.percent / 100)}
            <p className="text-[11.5px] text-neutral-400 mt-1.5">{bnNumber(job.percent)}%</p>
          </>
        )}
        {job.stage === 'saving' && <p className="text-[13px] text-neutral-700">পোস্টে বসানো হচ্ছে…</p>}
        {busy && (
          <div className="mt-4">
            <Button variant="secondary" onClick={onCancel}>
              বাতিল করুন
            </Button>
          </div>
        )}

        {job.stage === 'done' && (
          <>
            <video src={job.url} controls playsInline className="w-full max-h-[45vh] rounded-xl bg-black mb-3" data-testid="render-result" />
            {job.attached ? (
              <p className="text-[13px] text-emerald-700 mb-3">এডিট করা ভিডিও পোস্টে বসানো হয়েছে ({bnFileSize(job.size)})। মূল ভিডিও সংরক্ষিত আছে।</p>
            ) : job.tooLarge ? (
              <p className="text-[13px] text-amber-800 mb-3">ফাইলটি ({bnFileSize(job.size)}) সার্ভারের আপলোড সীমার চেয়ে বড় — “আকার ও শব্দ” থেকে ৭২০p বেছে আবার রেন্ডার করুন, অথবা ডাউনলোড করে নিন।</p>
            ) : (
              <p className="text-[13px] text-neutral-700 mb-3">ভিডিও তৈরি হয়েছে ({bnFileSize(job.size)})।</p>
            )}
            <div className="flex gap-2 flex-wrap">
              <a href={job.url} download={job.filename} className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-neutral-100 text-neutral-800 text-[13.5px] font-semibold hover:bg-neutral-200">
                <IconDownload className="w-4 h-4" />
                ডাউনলোড
              </a>
              {job.attached ? (
                <Button onClick={onBack}>ভিডিও পোস্টে ফিরুন</Button>
              ) : (
                <Button variant="secondary" onClick={onClose}>
                  এডিটরে ফিরুন
                </Button>
              )}
            </div>
          </>
        )}
        {job.stage === 'error' && (
          <>
            <p className="text-[13px] text-red-700 bg-red-50 border border-red-100 rounded-lg px-3 py-2 mb-3">{job.message}</p>
            <div className="flex gap-2">
              <Button onClick={onStart}>আবার চেষ্টা করুন</Button>
              <Button variant="secondary" onClick={onClose}>
                বন্ধ করুন
              </Button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}
