/**
 * Round 30 — "the video is ready" signals for when the render or upload
 * runs in the background: a short chime, a browser notification (only
 * with permission, only when the tab is not in front) and a blinking tab
 * title until the user comes back.
 */

let ctx: AudioContext | null = null

/** Rising three-note chime (ok) or two falling notes (problem). */
export function chime(kind: 'ok' | 'error' = 'ok') {
  try {
    ctx = ctx ?? new AudioContext()
    const c = ctx
    void c.resume()
    const t0 = c.currentTime + 0.02
    const notes = kind === 'ok' ? [76, 81, 88] : [72, 67]
    notes.forEach((n, i) => {
      const t = t0 + i * 0.13
      const f = 440 * Math.pow(2, (n - 69) / 12)
      for (const [m, a] of [
        [1, 1],
        [2.76, 0.25],
      ] as const) {
        const o = c.createOscillator()
        o.type = 'sine'
        o.frequency.value = f * m
        const g = c.createGain()
        g.gain.setValueAtTime(0.0001, t)
        g.gain.linearRampToValueAtTime(0.16 * a, t + 0.008)
        g.gain.exponentialRampToValueAtTime(0.0001, t + (i === notes.length - 1 ? 1.1 : 0.5))
        o.connect(g).connect(c.destination)
        o.start(t)
        o.stop(t + 1.2)
      }
    })
  } catch {
    // no audio here: the other signals still work
  }
}

export const canNotify = () => typeof window !== 'undefined' && 'Notification' in window

export function notifyPermission(): NotificationPermission | 'unsupported' {
  return canNotify() ? Notification.permission : 'unsupported'
}

/** Asks once, from a click. */
export async function askNotify(): Promise<boolean> {
  if (!canNotify()) return false
  if (Notification.permission === 'granted') return true
  if (Notification.permission === 'denied') return false
  try {
    return (await Notification.requestPermission()) === 'granted'
  } catch {
    return false
  }
}

let flashTimer: number | null = null
let baseTitle = ''

function stopFlash() {
  if (flashTimer !== null) {
    window.clearInterval(flashTimer)
    flashTimer = null
    document.title = baseTitle
  }
}

/** Blinks the tab title until the tab is looked at again. */
export function flashTitle(text: string) {
  if (typeof document === 'undefined' || !document.hidden) return
  stopFlash()
  baseTitle = document.title
  let on = false
  flashTimer = window.setInterval(() => {
    on = !on
    document.title = on ? text : baseTitle
  }, 1000)
  const back = () => {
    if (document.hidden) return
    stopFlash()
    document.removeEventListener('visibilitychange', back)
    window.removeEventListener('focus', back)
  }
  document.addEventListener('visibilitychange', back)
  window.addEventListener('focus', back)
}

/** All signals together, as the settings allow. */
export function announce(opts: { title: string; body: string; ok: boolean; sound: boolean; browser: boolean; tag?: string }) {
  if (opts.sound) chime(opts.ok ? 'ok' : 'error')
  flashTitle(`${opts.ok ? '✅' : '⚠️'} ${opts.title}`)
  if (opts.browser && canNotify() && Notification.permission === 'granted' && document.hidden) {
    try {
      const n = new Notification(opts.title, { body: opts.body, tag: opts.tag ?? 'meeha-studio', icon: '/favicon.svg' })
      n.onclick = () => {
        window.focus()
        n.close()
      }
    } catch {
      // some browsers only allow notifications from a service worker
    }
  }
}
