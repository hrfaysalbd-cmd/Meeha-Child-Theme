/**
 * Round 10 — video news helpers: YouTube URL parsing (same rules as the
 * backend: watch?v=, youtu.be/, /shorts/, /embed/), reading a picked file's
 * metadata (orientation + duration) in the browser, and grabbing a frame
 * from a playing <video> as a thumbnail.
 */
import type { VideoOrientation } from './api'

export const ALLOWED_VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/quicktime']
export const VIDEO_ACCEPT = [...ALLOWED_VIDEO_TYPES, '.mp4', '.webm', '.mov'].join(',')

const EXT_MIME: Record<string, string> = { mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', mov: 'video/quicktime' }

/**
 * The file's video mime type — from the browser, or (some OS/browser combos
 * report '' for .mov/.webm) guessed from the extension, as the server does.
 */
export function videoMimeOf(file: File): string {
  if (file.type) return file.type
  const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
  return EXT_MIME[ext] ?? ''
}

/** Whether the file is an accepted video type (server list when known). */
export function isAllowedVideo(file: File, allowed: string[] = ALLOWED_VIDEO_TYPES): boolean {
  return allowed.includes(videoMimeOf(file))
}

const YT_ID = /^[A-Za-z0-9_-]{11}$/

export function parseYouTubeId(raw: string): string | null {
  const input = raw.trim()
  if (!input) return null
  let url: URL
  try {
    url = new URL(/^https?:\/\//i.test(input) ? input : `https://${input}`)
  } catch {
    return null
  }
  const host = url.hostname.replace(/^(www\.|m\.|music\.)/, '').toLowerCase()
  let id: string | null = null
  if (host === 'youtu.be') {
    id = url.pathname.split('/')[1] ?? null
  } else if (host === 'youtube.com' || host === 'youtube-nocookie.com') {
    if (url.pathname === '/watch') id = url.searchParams.get('v')
    else {
      const m = /^\/(?:shorts|embed|live|v)\/([^/?#]+)/.exec(url.pathname)
      id = m ? m[1] : null
    }
  }
  return id && YT_ID.test(id) ? id : null
}

export function isYouTubeShorts(raw: string) {
  return /youtube\.com\/shorts\//i.test(raw)
}

export function youTubeThumb(id: string) {
  return `https://i.ytimg.com/vi/${id}/hqdefault.jpg`
}

export interface VideoFileMeta {
  width: number
  height: number
  duration: number | null
  orientation: VideoOrientation
}

/** Reads width/height/duration from a (blob) URL; null if the browser can't. */
export function readVideoMetadata(src: string, timeoutMs = 15_000): Promise<VideoFileMeta | null> {
  return new Promise((resolve) => {
    const el = document.createElement('video')
    el.preload = 'metadata'
    el.muted = true
    let done = false
    const finish = (meta: VideoFileMeta | null) => {
      if (done) return
      done = true
      clearTimeout(timer)
      el.removeAttribute('src')
      el.load()
      resolve(meta)
    }
    const timer = setTimeout(() => finish(null), timeoutMs)
    el.onloadedmetadata = () => {
      const width = el.videoWidth
      const height = el.videoHeight
      if (!width || !height) return finish(null)
      const duration = Number.isFinite(el.duration) && el.duration > 0 ? Math.round(el.duration) : null
      finish({ width, height, duration, orientation: height >= width ? 'portrait' : 'landscape' })
    }
    el.onerror = () => finish(null)
    el.src = src
  })
}

/** Captures the <video>'s current frame as a JPEG file (same-origin sources only). */
export function captureVideoFrame(video: HTMLVideoElement, name = 'video-thumbnail.jpg'): Promise<File | null> {
  return new Promise((resolve) => {
    if (!video.videoWidth || !video.videoHeight || video.readyState < 2) return resolve(null)
    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight
    const ctx = canvas.getContext('2d')
    if (!ctx) return resolve(null)
    try {
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
      canvas.toBlob((blob) => resolve(blob ? new File([blob], name, { type: 'image/jpeg' }) : null), 'image/jpeg', 0.88)
    } catch {
      resolve(null) // tainted canvas (cross-origin source)
    }
  })
}
