/**
 * r31 — what the app is waiting for on the network, so a slow connection or
 * an upload shows the Meeha loading mark instead of a frozen-looking screen.
 * Background checks (chat/notification polling, push, version) don't count.
 */
const BACKGROUND = /^\/(chat\/summary|chat\/rooms\/\d+\/(read|typing)|notifications|push\/|app\/android|auth\/)|[?&](after|changed_since)=/

let loads = 0
let uploads = 0
const listeners = new Set<() => void>()
const emit = () => listeners.forEach((l) => l())

let pageLoaders = 0
export function netSnapshot() {
  return { loads, uploads, pageLoaders }
}
/** A page's own big loader is on screen: the small "লোড হচ্ছে" badge stays away. */
export function pageLoaderShown(): () => void {
  pageLoaders++
  emit()
  return () => {
    pageLoaders--
    emit()
  }
}
export function onNet(l: () => void) {
  listeners.add(l)
  return () => void listeners.delete(l)
}

/** Call when a request starts; call the returned function when it ends. */
export function trackRequest(path: string, method: string, body: unknown): () => void {
  if (BACKGROUND.test(path)) return () => {}
  const upload = method !== 'GET' && (body instanceof FormData || body instanceof Blob || body instanceof ArrayBuffer)
  if (upload) uploads++
  else loads++
  emit()
  let done = false
  return () => {
    if (done) return
    done = true
    if (upload) uploads--
    else loads--
    emit()
  }
}

/** Long uploads (video, via XMLHttpRequest) report themselves here. */
export function trackUpload(): () => void {
  uploads++
  emit()
  let done = false
  return () => {
    if (done) return
    done = true
    uploads--
    emit()
  }
}
