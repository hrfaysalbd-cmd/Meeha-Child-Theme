// Round 18 — shrink phone photos before they are uploaded.
//
// A modern phone photo is 3–12 MB and 4000+ px wide. On a slow mobile
// connection that upload can outlast the request timeout, and on the server
// WordPress then spends a long time generating every thumbnail size — both
// surfaced in the apps as "সার্ভারের সাথে সংযোগ করা যায়নি". A news image is
// never shown wider than ~1600 px, so the photo is resized to at most
// MAX_EDGE px on its long side and re-encoded as JPEG, which typically gives
// 300–900 KB. Small files, GIFs (animation) and anything the browser can't
// decode are uploaded untouched.

const MAX_EDGE = 2048
const QUALITY = 0.85
const SKIP_BELOW_BYTES = 600 * 1024

function loadImage(file: File): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const img = new Image()
    img.onload = () => {
      URL.revokeObjectURL(url)
      resolve(img)
    }
    img.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('decode failed'))
    }
    img.src = url
  })
}

function toBlob(canvas: HTMLCanvasElement, type: string, quality: number): Promise<Blob | null> {
  return new Promise((resolve) => canvas.toBlob(resolve, type, quality))
}

/** True when a PNG actually uses transparency (so it must stay a PNG). */
function hasAlpha(ctx: CanvasRenderingContext2D, w: number, h: number) {
  const step = Math.max(1, Math.floor(Math.min(w, h) / 64))
  const data = ctx.getImageData(0, 0, w, h).data
  for (let y = 0; y < h; y += step) {
    for (let x = 0; x < w; x += step) {
      if (data[(y * w + x) * 4 + 3] < 250) return true
    }
  }
  return false
}

export async function compressImageForUpload(file: File): Promise<File> {
  if (!file.type.startsWith('image/') || file.type === 'image/gif' || file.type === 'image/svg+xml') return file
  if (file.size < SKIP_BELOW_BYTES) return file

  let img: HTMLImageElement
  try {
    img = await loadImage(file)
  } catch {
    return file
  }

  const scale = Math.min(1, MAX_EDGE / Math.max(img.naturalWidth, img.naturalHeight))
  const w = Math.max(1, Math.round(img.naturalWidth * scale))
  const h = Math.max(1, Math.round(img.naturalHeight * scale))
  const canvas = document.createElement('canvas')
  canvas.width = w
  canvas.height = h
  const ctx = canvas.getContext('2d')
  if (!ctx) return file

  ctx.drawImage(img, 0, 0, w, h)
  let type = 'image/jpeg'
  if (file.type === 'image/png') {
    try {
      if (hasAlpha(ctx, w, h)) type = 'image/png'
    } catch {
      type = 'image/png'
    }
  }
  if (type === 'image/jpeg') {
    // JPEG has no transparency: paint white under the photo.
    ctx.globalCompositeOperation = 'destination-over'
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, w, h)
  }

  const blob = await toBlob(canvas, type, QUALITY)
  if (!blob || blob.size >= file.size) return file

  const base = file.name.replace(/\.[^.]+$/, '') || 'photo'
  return new File([blob], `${base}.${type === 'image/png' ? 'png' : 'jpg'}`, { type, lastModified: Date.now() })
}

/**
 * Upload timeout scaled to the file and to a pessimistic mobile speed
 * (~12 KB/s), between 90 seconds and 15 minutes — a fixed 120 s timeout
 * cut off slow but working uploads.
 */
export function uploadTimeoutFor(bytes: number) {
  return Math.min(15 * 60_000, Math.max(90_000, Math.round((bytes / 12_000) * 1000) + 30_000))
}
