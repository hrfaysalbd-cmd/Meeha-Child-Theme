/**
 * r48 — the original video, kept in this browser tab once it has been downloaded.
 *
 * The publisher now downloads the correspondent's original BEFORE editing (to keep it on their own
 * computer). The same bytes are remembered here, so the studio previews and renders from them
 * instead of streaming the file from the server again — the post page and the studio share this
 * module because the app never reloads between them. Nothing is written to disk by this module;
 * a page reload forgets it (then the studio offers “কম্পিউটার থেকে খুলুন”).
 */
const files = new Map<number, { blob: Blob; name: string }>()

export function rememberOriginal(attachmentId: number, blob: Blob, name: string) {
  // one big video at a time is plenty; drop the others so memory stays small
  files.clear()
  files.set(attachmentId, { blob, name })
}

export function recallOriginal(attachmentId: number) {
  return files.get(attachmentId) ?? null
}

export function forgetOriginals() {
  files.clear()
}

/** Saves a blob to the computer's Downloads folder under `name`. */
export function saveToComputer(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

/** File extension for a downloaded video blob. */
export function videoExt(blob: Blob, fallbackName = '') {
  const m = /\.([a-z0-9]{2,4})$/i.exec(fallbackName)
  if (blob.type.includes('webm')) return 'webm'
  if (blob.type.includes('quicktime')) return 'mov'
  if (blob.type.includes('mp4')) return 'mp4'
  return m ? m[1].toLowerCase() : 'mp4'
}
