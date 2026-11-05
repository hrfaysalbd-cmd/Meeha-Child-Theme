/**
 * Round 28 — keeps a finished render in the browser (IndexedDB) until it is
 * in the post, so a reload, a closed tab or a phone that drops the page does
 * not mean rendering again: the upload continues from the server's count.
 *
 * Everything here is best-effort. A private window or a full disk simply
 * means nothing is kept; the studio works the same without it.
 */

const DB = 'meeha-video-studio'
const STORE = 'renders'

export interface KeptRender {
  videoId: number
  blob: Blob
  mime: string
  ext: 'mp4' | 'webm'
  filename: string
  slug: string
  duration: number
  width: number
  height: number
  poster: Blob | null
  cfg: unknown
  /** The resumable upload session of `blob`, once one was opened. */
  session: { id: string } | null
  /** Set once the file is in the media library (only the post update is left). */
  attachmentId: number | null
  savedAt: number
}

function open(): Promise<IDBDatabase | null> {
  return new Promise((resolve) => {
    try {
      if (typeof indexedDB === 'undefined') return resolve(null)
      const req = indexedDB.open(DB, 1)
      req.onupgradeneeded = () => {
        if (!req.result.objectStoreNames.contains(STORE)) req.result.createObjectStore(STORE, { keyPath: 'videoId' })
      }
      req.onsuccess = () => resolve(req.result)
      req.onerror = () => resolve(null)
      req.onblocked = () => resolve(null)
    } catch {
      resolve(null)
    }
  })
}

async function run<T>(mode: IDBTransactionMode, fn: (s: IDBObjectStore) => IDBRequest | void): Promise<T | null> {
  const db = await open()
  if (!db) return null
  return new Promise<T | null>((resolve) => {
    try {
      const tx = db.transaction(STORE, mode)
      const req = fn(tx.objectStore(STORE))
      tx.oncomplete = () => {
        db.close()
        resolve(req ? ((req.result as T) ?? null) : null)
      }
      tx.onerror = tx.onabort = () => {
        db.close()
        resolve(null)
      }
    } catch {
      db.close()
      resolve(null)
    }
  })
}

/** Saves (or replaces) the render kept for a video. Returns false when the browser would not keep it. */
export async function keepRender(r: KeptRender): Promise<boolean> {
  const ok = await run<IDBValidKey>('readwrite', (s) => s.put(r))
  return ok !== null
}

/** Updates the upload state of the kept render without writing the file again. */
export async function updateKept(videoId: number, patch: Partial<Pick<KeptRender, 'session' | 'attachmentId'>>) {
  const cur = await keptRender(videoId)
  if (!cur) return
  await run('readwrite', (s) => s.put({ ...cur, ...patch }))
}

export async function keptRender(videoId: number): Promise<KeptRender | null> {
  const r = await run<KeptRender>('readonly', (s) => s.get(videoId))
  // a week is plenty; older ones only take space
  if (r && Date.now() - r.savedAt > 7 * 86400_000) {
    await dropKept(videoId)
    return null
  }
  return r && r.blob instanceof Blob ? r : null
}

export async function dropKept(videoId: number) {
  await run('readwrite', (s) => s.delete(videoId))
}
