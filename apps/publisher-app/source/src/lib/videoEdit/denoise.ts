/**
 * Round 22: background-noise reduction for the main video's sound.
 *
 * This is a spectral noise gate: short-time FFT, a noise floor that follows
 * the quietest level in each frequency band (it drops fast and rises
 * slowly, so speech never becomes "noise"), and a Wiener gain with
 * decision-directed smoothing, which keeps the "musical noise" of plain
 * spectral subtraction down. The noise floor carries over between calls,
 * so the export can feed the clip a few seconds at a time.
 */

export type DenoiseLevel = 'off' | 'low' | 'medium' | 'high'

const N = 1024
const HOP = N / 4

const SETTINGS: Record<Exclude<DenoiseLevel, 'off'>, { over: number; floor: number }> = {
  low: { over: 1.2, floor: 0.3 },
  medium: { over: 1.8, floor: 0.16 },
  high: { over: 2.6, floor: 0.08 },
}

// ---- radix-2 FFT (in place) ----
const rev = new Uint32Array(N)
const cos = new Float32Array(N / 2)
const sin = new Float32Array(N / 2)
{
  const bits = Math.log2(N)
  for (let i = 0; i < N; i++) {
    let r = 0
    for (let b = 0; b < bits; b++) r |= ((i >> b) & 1) << (bits - 1 - b)
    rev[i] = r
  }
  for (let i = 0; i < N / 2; i++) {
    cos[i] = Math.cos((2 * Math.PI * i) / N)
    sin[i] = Math.sin((2 * Math.PI * i) / N)
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
        const wr = cos[j * step]
        const wi = sgn * sin[j * step]
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
// Hann analysis x Hann synthesis at 75 % overlap sums to 1.5.
const OLA = 1.5

export class Denoiser {
  private level: Exclude<DenoiseLevel, 'off'>
  private noise: Float32Array | null = null
  private smooth = new Float32Array(N / 2 + 1)
  private prevClean = new Float32Array(N / 2 + 1)
  private rise: number

  constructor(level: Exclude<DenoiseLevel, 'off'>, sampleRate: number) {
    this.level = level
    // the floor may climb about 2.5 dB per second
    const framesPerSec = sampleRate / HOP
    this.rise = Math.pow(10, 0.25 / framesPerSec)
  }

  /**
   * Cleans a contiguous stretch of audio (one array per channel, same
   * length). Returns new arrays of the same length. The first and last
   * N samples only get partial overlap, so callers pad both ends.
   */
  process(channels: Float32Array[]): Float32Array<ArrayBuffer>[] {
    const len = channels[0]?.length ?? 0
    const outs = channels.map(() => new Float32Array(len))
    if (len < N) {
      channels.forEach((c, i) => outs[i].set(c))
      return outs
    }
    const { over, floor } = SETTINGS[this.level]
    const bins = N / 2 + 1
    const res = channels.map(() => new Float32Array(N))
    const ims = channels.map(() => new Float32Array(N))
    const gain = new Float32Array(bins)
    const g2 = new Float32Array(bins)

    for (let start = 0; start + N <= len; start += HOP) {
      // spectra of every channel, power of their sum (the mask is shared)
      for (let c = 0; c < channels.length; c++) {
        const re = res[c]
        const im = ims[c]
        const src = channels[c]
        for (let i = 0; i < N; i++) {
          re[i] = src[start + i] * WINDOW[i]
          im[i] = 0
        }
        fft(re, im, false)
      }
      if (!this.noise) {
        this.noise = new Float32Array(bins)
        for (let k = 0; k < bins; k++) {
          let p = 0
          for (let c = 0; c < channels.length; c++) p += res[c][k] * res[c][k] + ims[c][k] * ims[c][k]
          this.noise[k] = p / channels.length
          this.smooth[k] = this.noise[k]
        }
      }
      const noise = this.noise
      for (let k = 0; k < bins; k++) {
        let p = 0
        for (let c = 0; c < channels.length; c++) p += res[c][k] * res[c][k] + ims[c][k] * ims[c][k]
        p /= channels.length
        // noise floor: follow the smoothed minimum
        const s = (this.smooth[k] = 0.85 * this.smooth[k] + 0.15 * p)
        noise[k] = s < noise[k] ? 0.7 * noise[k] + 0.3 * s : Math.min(s, noise[k] * this.rise)
        const nk = noise[k] * over * 1.6 + 1e-12
        const post = p / nk
        const prio = 0.96 * (this.prevClean[k] / nk) + 0.04 * Math.max(post - 1, 0)
        const g = prio / (1 + prio)
        g2[k] = g
        this.prevClean[k] = g * g * p
      }
      // smooth across neighbouring bands, then floor
      for (let k = 0; k < bins; k++) {
        const a = g2[Math.max(0, k - 1)]
        const b = g2[Math.min(bins - 1, k + 1)]
        gain[k] = Math.max(floor, 0.25 * a + 0.5 * g2[k] + 0.25 * b)
      }
      for (let c = 0; c < channels.length; c++) {
        const re = res[c]
        const im = ims[c]
        for (let k = 0; k < bins; k++) {
          re[k] *= gain[k]
          im[k] *= gain[k]
          if (k > 0 && k < N / 2) {
            re[N - k] = re[k]
            im[N - k] = -im[k]
          }
        }
        fft(re, im, true)
        const o = outs[c]
        for (let i = 0; i < N; i++) o[start + i] += (re[i] * WINDOW[i]) / OLA
      }
    }
    return outs
  }
}
