// Client-side helpers for the video-news editor (round 10).
import type { VideoOrientation } from './api'

/**
 * Extracts the 11-character YouTube id from the URL shapes people actually
 * paste: youtube.com/watch?v=, m.youtube.com, youtu.be/, /shorts/, /embed/,
 * /live/ — mirrors the backend's parsing (CONTRACT §2 `youtube_id`).
 */
export function youtubeIdFrom(raw: string): string | null {
  const input = raw.trim()
  if (!input) return null
  let url: URL
  try {
    url = new URL(/^https?:\/\//i.test(input) ? input : `https://${input}`)
  } catch {
    return null
  }
  const host = url.hostname.replace(/^(www\.|m\.|music\.)/, '').toLowerCase()
  const valid = (id: string | null | undefined) => (id && /^[A-Za-z0-9_-]{11}$/.test(id) ? id : null)

  if (host === 'youtu.be') return valid(url.pathname.split('/')[1])
  if (host === 'youtube.com' || host === 'youtube-nocookie.com') {
    if (url.pathname === '/watch') return valid(url.searchParams.get('v'))
    const m = url.pathname.match(/^\/(shorts|embed|live|v)\/([^/?#]+)/)
    if (m) return valid(m[2])
  }
  return null
}

/** YouTube Shorts are vertical; everything else is assumed widescreen. */
export function youtubeOrientationGuess(raw: string): VideoOrientation {
  return /\/shorts\//i.test(raw) ? 'portrait' : 'landscape'
}

export function youtubeThumb(id: string) {
  return `https://i.ytimg.com/vi/${id}/hqdefault.jpg`
}

export interface VideoMeta {
  width: number
  height: number
  duration: number | null // whole seconds
  orientation: VideoOrientation
}

/**
 * Reads a local video's dimensions and duration from its metadata only (no
 * full decode) via an off-screen <video>. Resolves null if the browser can't
 * read it (e.g. an HEVC .mov on a browser without HEVC support) — the upload
 * can still go ahead; the user just picks the orientation by hand.
 */
export function readVideoMeta(objectUrl: string, timeoutMs = 10_000): Promise<VideoMeta | null> {
  return new Promise((resolve) => {
    const el = document.createElement('video')
    let settled = false
    const finish = (meta: VideoMeta | null) => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      el.removeAttribute('src')
      el.load()
      resolve(meta)
    }
    const timer = setTimeout(() => finish(null), timeoutMs)
    el.preload = 'metadata'
    el.muted = true
    el.playsInline = true
    el.onloadedmetadata = () => {
      const width = el.videoWidth
      const height = el.videoHeight
      if (!width || !height) return finish(null)
      const duration = Number.isFinite(el.duration) && el.duration > 0 ? Math.round(el.duration) : null
      finish({ width, height, duration, orientation: height >= width ? 'portrait' : 'landscape' })
    }
    el.onerror = () => finish(null)
    el.src = objectUrl
  })
}

/**
 * Grabs the frame currently shown in a <video> as a JPEG file, to use as the
 * thumbnail. Only works for same-origin/blob: sources (a cross-origin server
 * URL would taint the canvas), i.e. the freshly picked local file.
 */
export function captureFrame(el: HTMLVideoElement, name = 'thumbnail.jpg'): Promise<File | null> {
  return new Promise((resolve) => {
    const w = el.videoWidth
    const h = el.videoHeight
    if (!w || !h) return resolve(null)
    // Cap the long edge at 1280px — plenty for a thumbnail, small upload.
    const scale = Math.min(1, 1280 / Math.max(w, h))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(w * scale)
    canvas.height = Math.round(h * scale)
    const ctx = canvas.getContext('2d')
    if (!ctx) return resolve(null)
    try {
      ctx.drawImage(el, 0, 0, canvas.width, canvas.height)
      canvas.toBlob((blob) => resolve(blob ? new File([blob], name, { type: 'image/jpeg' }) : null), 'image/jpeg', 0.85)
    } catch {
      resolve(null)
    }
  })
}
