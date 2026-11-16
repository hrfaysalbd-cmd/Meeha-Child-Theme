/**
 * Round 23: loudness meter (ITU-R BS.1770 / EBU R128 integrated loudness).
 *
 * K-weighting (a high shelf and a high pass, coefficients for 48 kHz),
 * 400 ms blocks every 100 ms, an absolute gate at -70 LUFS and a relative
 * gate 10 LU under the ungated level. The export measures the whole mix
 * once, then plays it again with the gain that hits the target.
 */
import { SAMPLE_RATE } from './audio'

// BS.1770-4 K-weighting at 48 kHz
const SHELF = { b: [1.53512485958697, -2.69169618940638, 1.19839281085285], a: [-1.69065929318241, 0.73248077421585] }
const HIPASS = { b: [1, -2, 1], a: [-1.99004745483398, 0.99007225036621] }

class Biquad {
  private x1 = 0
  private x2 = 0
  private y1 = 0
  private y2 = 0
  private c: { b: number[]; a: number[] }
  constructor(c: { b: number[]; a: number[] }) {
    this.c = c
  }
  run(x: number) {
    const { b, a } = this.c
    const y = b[0] * x + b[1] * this.x1 + b[2] * this.x2 - a[0] * this.y1 - a[1] * this.y2
    this.x2 = this.x1
    this.x1 = x
    this.y2 = this.y1
    this.y1 = y
    return y
  }
}

const SUB = SAMPLE_RATE / 10 // 100 ms

export class LoudnessMeter {
  private filters = [0, 1].map(() => [new Biquad(SHELF), new Biquad(HIPASS)])
  private acc = 0
  private n = 0
  private subs: number[] = []
  private blocks: number[] = []

  /** Feeds one stereo stretch (48 kHz). */
  feed(L: Float32Array, R: Float32Array) {
    const [fl, fr] = this.filters
    for (let i = 0; i < L.length; i++) {
      const l = fl[1].run(fl[0].run(L[i]))
      const r = fr[1].run(fr[0].run(R[i]))
      this.acc += l * l + r * r
      if (++this.n === SUB) {
        this.subs.push(this.acc / SUB)
        this.acc = 0
        this.n = 0
        if (this.subs.length >= 4) {
          const k = this.subs.length
          this.blocks.push((this.subs[k - 1] + this.subs[k - 2] + this.subs[k - 3] + this.subs[k - 4]) / 4)
        }
      }
    }
  }

  /** Integrated loudness in LUFS (-Infinity for silence). */
  integrated(): number {
    const lufs = (z: number) => -0.691 + 10 * Math.log10(z)
    const abs = this.blocks.filter((z) => z > 0 && lufs(z) > -70)
    if (!abs.length) return -Infinity
    const mean = abs.reduce((a, z) => a + z, 0) / abs.length
    const rel = lufs(mean) - 10
    const gated = abs.filter((z) => lufs(z) > rel)
    if (!gated.length) return -Infinity
    return lufs(gated.reduce((a, z) => a + z, 0) / gated.length)
  }
}

/** Linear gain that brings `measured` LUFS to `target`, kept within ±12 dB. */
export function normalizeGain(measured: number, target: number) {
  if (!Number.isFinite(measured)) return 1
  const db = Math.max(-12, Math.min(12, target - measured))
  return Math.pow(10, db / 20)
}
