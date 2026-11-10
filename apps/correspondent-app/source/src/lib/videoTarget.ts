/** r33: small helpers for the video shrink, kept out of the big encoder chunk. */

/** True where this phone/browser can re-encode (WebCodecs). */
export const canShrink = () => typeof (globalThis as { VideoEncoder?: unknown }).VideoEncoder === 'function'

/** The largest file a correspondent may pick when it will be made smaller first. */
export const PICK_MAX_BYTES = 1024 * 1048576

const MB = 1048576

/**
 * How big the file we send should be, from the measured upload speed
 * (bytes a second): about six minutes of sending, never under 25 MB or over
 * 180 MB, and always below the server's own limit.
 */
export function targetFor(speedBps: number | null, serverMax: number) {
  const hint = (navigator as { connection?: { downlink?: number; effectiveType?: string; saveData?: boolean } }).connection
  let bps = speedBps
  if (!bps && hint?.downlink) bps = (hint.downlink * 1e6) / 8 / 3 // upload is usually a third of download
  if (!bps) bps = 400_000
  let t = bps * 6 * 60
  if (hint?.saveData || hint?.effectiveType === '2g' || hint?.effectiveType === 'slow-2g') t = Math.min(t, 30 * MB)
  const cap = serverMax > 0 ? serverMax * 0.92 : 180 * MB
  // the floor keeps a long clip watchable; the server's limit always wins
  return Math.round(Math.min(cap, 180 * MB, Math.max(25 * MB, t)))
}

/** A spoken estimate: "প্রায় ৪ মিনিট". */
export function etaText(bytes: number, speedBps: number | null) {
  if (!speedBps) return ''
  const s = bytes / speedBps
  const bn = (n: number) => String(n).replace(/\d/g, (d) => '০১২৩৪৫৬৭৮৯'[+d])
  if (s < 90) return `প্রায় ${bn(Math.max(1, Math.round(s)))} সেকেন্ড`
  if (s < 3600) return `প্রায় ${bn(Math.round(s / 60))} মিনিট`
  return `প্রায় ${bn(Math.round((s / 3600) * 10) / 10)} ঘণ্টা`
}
