/**
 * Round 26 — "ব্যাকগ্রাউন্ড মিউজিক কমান": pushes a song / music bed that
 * was recorded under someone's speech into the background, in the browser
 * (no server, no AI model to download).
 *
 * How (a spectral mask on a short-time FFT, 2048 points, 75 % overlap):
 *  1. Sustained notes: music holds a pitch for a long time, speech glides
 *     from syllable to syllable. Per frequency, a low percentile of the
 *     level over about a second is the "held" part (chords, pads, strings, hum).
 *  2. Beats: a drum hit is loud across many frequencies at one instant. The
 *     median across neighbouring frequencies is the "struck" part.
 *  3. What is neither (moving, harmonic, 80 Hz–8 kHz) is mostly the voice.
 *     Its share of the energy becomes a soft mask (Wiener-style), smoothed
 *     over time and frequency so it does not warble.
 *  4. Stereo files: the voice is nearly always in the centre while music is
 *     spread wide, so sound that differs between left and right is pushed
 *     down further. (A mono phone recording simply skips this step.)
 *
 * Honest limits: this lowers music, it cannot delete it the way a studio
 * stem would. Sung vocals are a voice too and stay, and very loud music
 * over a quiet voice can leave a "watery" sound at the strong level.
 */

export type MusicRemoveLevel = 'off' | 'low' | 'medium' | 'high'

const N = 2048
const HOP = N / 4
const BINS = N / 2 + 1
/** Held level: the 30th percentile over ±48 frames taken every 4th (≈ ±0.5 s at 48 kHz). */
const T_HALF = 48
const T_STRIDE = 4
const T_PCT = 0.3
/** Frequency median: ±9 bins (≈ ±210 Hz at 48 kHz). */
const F_HALF = 9

const SETTINGS: Record<Exclude<MusicRemoveLevel, 'off'>, { floor: number; held: number; struck: number; side: number; power: number }> = {
  low: { floor: 0.4, held: 0.9, struck: 0.7, side: 0.5, power: 0.8 },
  medium: { floor: 0.2, held: 1.25, struck: 1, side: 0.75, power: 1 },
  high: { floor: 0.08, held: 1.7, struck: 1.35, side: 0.95, power: 1.3 },
}

// ---- radix-2 FFT for N (in place) ----
const rev = new Uint32Array(N)
const cosT = new Float32Array(N / 2)
const sinT = new Float32Array(N / 2)
{
  const bits = Math.log2(N)
  for (let i = 0; i < N; i++) {
    let r = 0
    for (let b = 0; b < bits; b++) r |= ((i >> b) & 1) << (bits - 1 - b)
    rev[i] = r
  }
  for (let i = 0; i < N / 2; i++) {
    cosT[i] = Math.cos((2 * Math.PI * i) / N)
    sinT[i] = Math.sin((2 * Math.PI * i) / N)
  }
}

function fft(re: Float32Array, im: Float32Array, inverse: boolean) {
  for (let i = 0; i < N; i++) {
    const j = rev[i]
    if (j > i) {
      let t = re[i]
      re[i] = re[j]
      re[j] = t
      t = im[i]
      im[i] = im[j]
      im[j] = t
    }
  }
  const sgn = inverse ? 1 : -1
  for (let size = 2; size <= N; size <<= 1) {
    const half = size >> 1
    const step = N / size
    for (let i = 0; i < N; i += size) {
      for (let j = 0; j < half; j++) {
        const wr = cosT[j * step]
        const wi = sgn * sinT[j * step]
        const a = i + j
        const b = a + half
        const tr = re[b] * wr - im[b] * wi
        const ti = re[b] * wi + im[b] * wr
        re[b] = re[a] - tr
        im[b] = im[a] - ti
        re[a] += tr
        im[a] += ti
      }
    }
  }
  if (inverse) {
    for (let i = 0; i < N; i++) {
      re[i] /= N
      im[i] /= N
    }
  }
}

const WINDOW = (() => {
  const w = new Float32Array(N)
  for (let i = 0; i < N; i++) w[i] = 0.5 - 0.5 * Math.cos((2 * Math.PI * i) / N)
  return w
})()
const OLA = 1.5

/** The `pct` percentile of v[0..n) (v is reordered); 0.5 = median. */
function median(v: Float32Array, n: number, pct = 0.5): number {
  let lo = 0
  let hi = n - 1
  const k = Math.min(n - 1, Math.floor(n * pct))
  while (lo < hi) {
    const pivot = v[(lo + hi) >> 1]
    let i = lo
    let j = hi
    while (i <= j) {
      while (v[i] < pivot) i++
      while (v[j] > pivot) j--
      if (i <= j) {
        const t = v[i]
        v[i] = v[j]
        v[j] = t
        i++
        j--
      }
    }
    if (k <= j) hi = j
    else if (k >= i) lo = i
    else break
  }
  return v[k]
}

/** Per-bin weight: the voice lives between ~80 Hz and ~8 kHz. */
function bandWeights(sampleRate: number) {
  const w = new Float32Array(BINS)
  for (let k = 0; k < BINS; k++) {
    const f = (k * sampleRate) / N
    let x = 1
    if (f < 70) x = 0.15
    else if (f < 140) x = 0.15 + (0.85 * (f - 70)) / 70
    else if (f > 9000) x = 0.35
    else if (f > 6500) x = 1 - (0.65 * (f - 6500)) / 2500
    w[k] = x
  }
  return w
}

/**
 * Lowers the music in a contiguous stretch of audio (one array per
 * channel, same length). The first and last ~0.55 s only see part of their
 * surroundings, so callers pad both ends.
 */
export function removeMusic(channels: Float32Array[], sampleRate: number, level: Exclude<MusicRemoveLevel, 'off'>): Float32Array<ArrayBuffer>[] {
  const len = channels[0]?.length ?? 0
  const C = channels.length
  const outs = channels.map(() => new Float32Array(len))
  if (len < N * 2) {
    channels.forEach((c, i) => outs[i].set(c))
    return outs
  }
  const set = SETTINGS[level]
  const frames = Math.floor((len - N) / HOP) + 1
  // spectra of every frame and channel
  const RE = channels.map(() => new Float32Array(frames * BINS))
  const IM = channels.map(() => new Float32Array(frames * BINS))
  const mag = new Float32Array(frames * BINS)
  const re = new Float32Array(N)
  const im = new Float32Array(N)
  let sideE = 0
  let midE = 0
  for (let f = 0; f < frames; f++) {
    const start = f * HOP
    for (let c = 0; c < C; c++) {
      const src = channels[c]
      for (let i = 0; i < N; i++) {
        re[i] = src[start + i] * WINDOW[i]
        im[i] = 0
      }
      fft(re, im, false)
      RE[c].set(re.subarray(0, BINS), f * BINS)
      IM[c].set(im.subarray(0, BINS), f * BINS)
    }
    for (let k = 0; k < BINS; k++) {
      let p = 0
      for (let c = 0; c < C; c++) p += RE[c][f * BINS + k] ** 2 + IM[c][f * BINS + k] ** 2
      mag[f * BINS + k] = Math.sqrt(p / C)
      if (C >= 2) {
        const sr = RE[0][f * BINS + k] - RE[1][f * BINS + k]
        const si = IM[0][f * BINS + k] - IM[1][f * BINS + k]
        const mr = RE[0][f * BINS + k] + RE[1][f * BINS + k]
        const mi = IM[0][f * BINS + k] + IM[1][f * BINS + k]
        sideE += sr * sr + si * si
        midE += mr * mr + mi * mi
      }
    }
  }
  // a real stereo mix (not a mono phone recording copied to two channels)?
  const stereo = C >= 2 && sideE > midE * 0.02

  const weights = bandWeights(sampleRate)
  const mask = new Float32Array(frames * BINS)
  const tmp = new Float32Array(Math.max(2 * T_HALF + 1, 2 * F_HALF + 1))
  for (let f = 0; f < frames; f++) {
    for (let k = 0; k < BINS; k++) {
      const x = mag[f * BINS + k]
      if (x < 1e-9) {
        mask[f * BINS + k] = 1
        continue
      }
      // held (sustained over time)
      let n = 0
      for (let d = -T_HALF; d <= T_HALF; d += T_STRIDE) {
        const g = Math.min(frames - 1, Math.max(0, f + d))
        tmp[n++] = mag[g * BINS + k]
      }
      const held = median(tmp, n, T_PCT) * set.held
      // struck (broadband at this instant)
      n = 0
      for (let d = -F_HALF; d <= F_HALF; d++) {
        const kk = Math.min(BINS - 1, Math.max(0, k + d))
        tmp[n++] = mag[f * BINS + kk]
      }
      const struck = median(tmp, n) * set.struck
      const voice = Math.max(0, x - Math.max(held, struck * 0.8))
      const v2 = voice * voice
      let m = v2 / (v2 + held * held + struck * struck * 0.6 + 1e-12)
      m *= weights[k]
      if (stereo) {
        // centre-ness: 1 when left = right, 0 when they are opposite
        const i = f * BINS + k
        const lr = RE[0][i] ** 2 + IM[0][i] ** 2 + RE[1][i] ** 2 + IM[1][i] ** 2
        const mid = (RE[0][i] + RE[1][i]) ** 2 + (IM[0][i] + IM[1][i]) ** 2
        const centre = lr > 0 ? mid / (2 * lr) : 1
        m *= 1 - set.side + set.side * centre * centre
      }
      mask[f * BINS + k] = Math.pow(m, set.power)
    }
  }
  // smooth the mask: across neighbouring frequencies, then over time (fast up, slower down)
  const row = new Float32Array(BINS)
  const prev = new Float32Array(BINS).fill(1)
  for (let f = 0; f < frames; f++) {
    const o = f * BINS
    for (let k = 0; k < BINS; k++) row[k] = 0.25 * mask[o + Math.max(0, k - 1)] + 0.5 * mask[o + k] + 0.25 * mask[o + Math.min(BINS - 1, k + 1)]
    for (let k = 0; k < BINS; k++) {
      const v = row[k] > prev[k] ? 0.6 * row[k] + 0.4 * prev[k] : 0.35 * row[k] + 0.65 * prev[k]
      prev[k] = v
      mask[o + k] = set.floor + (1 - set.floor) * Math.min(1, v)
    }
  }
  // resynthesis
  for (let c = 0; c < C; c++) {
    const o = outs[c]
    for (let f = 0; f < frames; f++) {
      for (let k = 0; k < BINS; k++) {
        const g = mask[f * BINS + k]
        re[k] = RE[c][f * BINS + k] * g
        im[k] = IM[c][f * BINS + k] * g
      }
      for (let k = 1; k < N / 2; k++) {
        re[N - k] = re[k]
        im[N - k] = -im[k]
      }
      fft(re, im, true)
      const start = f * HOP
      for (let i = 0; i < N; i++) o[start + i] += (re[i] * WINDOW[i]) / OLA
    }
  }
  return outs
}
