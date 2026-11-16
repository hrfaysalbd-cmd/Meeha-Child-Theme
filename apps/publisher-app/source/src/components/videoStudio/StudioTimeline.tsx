/**
 * Round 21 — the editor's timeline strip: every part of the finished video
 * in order (ইন্ট্রো · সংবাদ · বিজ্ঞাপন · আউট্রো), caption markers, and a
 * draggable playhead. Round 23: the main video's sound as a waveform (after
 * cuts and speed changes), and slow / fast / frozen parts marked.
 */
import { useEffect, useRef, useState } from 'react'
import type { Waveform } from '../../lib/videoEdit/analyse'
import type { EditConfig } from '../../lib/videoEdit/config'
import { bnClock, bnDigits } from '../../lib/videoEdit/motion'
import { segSource, segSourceEnd, sourceToOutput, type Segment, type Timeline } from '../../lib/videoEdit/timeline'

const STYLE: Record<Segment['kind'], { bg: string; label: string }> = {
  intro: { bg: 'bg-violet-500', label: 'ইন্ট্রো' },
  main: { bg: 'bg-sky-600', label: 'সংবাদ' },
  ad: { bg: 'bg-amber-400', label: 'বিজ্ঞাপন' },
  card: { bg: 'bg-neutral-500', label: '' },
  outro: { bg: 'bg-violet-500', label: 'আউট্রো' },
}

function WaveCanvas({ tl, wave }: { tl: Timeline; wave: Waveform }) {
  const ref = useRef<HTMLCanvasElement>(null)
  const [w, setW] = useState(0)
  useEffect(() => {
    const c = ref.current
    if (!c) return
    const ro = new ResizeObserver(() => setW(c.offsetWidth))
    ro.observe(c)
    setW(c.offsetWidth)
    return () => ro.disconnect()
  }, [])
  useEffect(() => {
    const c = ref.current
    if (!c || !w) return
    const dpr = Math.min(2, window.devicePixelRatio || 1)
    const h = c.offsetHeight
    c.width = Math.round(w * dpr)
    c.height = Math.round(h * dpr)
    const ctx = c.getContext('2d')!
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
    ctx.clearRect(0, 0, w, h)
    ctx.fillStyle = 'rgba(255,255,255,0.78)'
    const total = Math.max(0.01, tl.duration)
    const { peaks, step } = wave
    for (const seg of tl.segments) {
      if (seg.kind !== 'main') continue
      const x0 = (seg.t0 / total) * w
      const x1 = ((seg.t0 + seg.dur) / total) * w
      for (let x = Math.floor(x0); x < x1; x += 2) {
        const a = Math.max(0, ((x - x0) / w) * total)
        const b = Math.min(seg.dur, ((x + 2 - x0) / w) * total)
        if (seg.rate !== 1) {
          // frozen / re-timed parts play without the original sound
          ctx.fillRect(x, h / 2 - 0.5, 1.5, 1)
          continue
        }
        let v = 0
        const i0 = Math.floor(segSource(seg, a) / step)
        const i1 = Math.max(i0, Math.floor(segSource(seg, b) / step))
        for (let i = i0; i <= i1 && i < peaks.length; i++) v = Math.max(v, peaks[i])
        const bh = Math.max(1, v * (h - 6))
        ctx.fillRect(x, (h - bh) / 2, 1.5, bh)
      }
    }
  }, [tl, wave, w])
  return <canvas ref={ref} className="absolute inset-0 w-full h-full pointer-events-none" data-testid="waveform" />
}

export default function StudioTimeline({
  tl,
  cfg,
  time,
  onSeek,
  wave = null,
}: {
  tl: Timeline
  cfg: EditConfig
  time: number
  onSeek: (t: number) => void
  wave?: Waveform | null
}) {
  const ref = useRef<HTMLDivElement>(null)
  const total = Math.max(0.01, tl.duration)
  const at = (clientX: number) => {
    const r = ref.current!.getBoundingClientRect()
    return Math.min(1, Math.max(0, (clientX - r.left) / r.width)) * total
  }
  // where a cut joins two parts of the video
  const joins = tl.segments.filter((s, i) => {
    const prev = tl.segments[i - 1]
    return s.kind === 'main' && prev?.kind === 'main' && Math.abs(segSourceEnd(prev) - s.clipStart) > 0.05
  })
  const marks = cfg.captions
    .map((c) => sourceToOutput(tl, c.at))
    .filter((t): t is number => t !== null)
  return (
    <div className="select-none">
      <div
        ref={ref}
        className={`relative ${wave ? 'h-16' : 'h-11'} rounded-lg overflow-hidden bg-neutral-200 cursor-pointer touch-none`}
        role="slider"
        aria-label="টাইমলাইন"
        aria-valuemin={0}
        aria-valuemax={total}
        aria-valuenow={time}
        data-testid="timeline"
        onPointerDown={(e) => {
          ;(e.target as HTMLElement).setPointerCapture?.(e.pointerId)
          onSeek(at(e.clientX))
        }}
        onPointerMove={(e) => {
          if (e.buttons & 1) onSeek(at(e.clientX))
        }}
      >
        <div className="absolute inset-0 flex">
          {tl.segments.map((s, i) => (
            <div
              key={i}
              className={`${STYLE[s.kind].bg} h-full border-r border-white/60 last:border-r-0 flex items-center justify-center overflow-hidden`}
              style={{ width: `${(s.dur / total) * 100}%` }}
              title={`${STYLE[s.kind].label || 'কার্ড'} · ${bnClock(s.dur)}`}
            >
              {(s.dur / total) * 100 > 9 && !(wave && s.kind === 'main') && <span className="text-[11px] font-semibold text-white/95 whitespace-nowrap px-1">{STYLE[s.kind].label}</span>}
            </div>
          ))}
        </div>
        {wave && <WaveCanvas tl={tl} wave={wave} />}
        {tl.segments.map((s, i) =>
          s.kind === 'main' && s.rate !== 1 ? (
            <span
              key={`r${i}`}
              className={`absolute top-0 h-3.5 px-1 text-[9.5px] leading-[14px] font-bold text-white overflow-hidden whitespace-nowrap ${s.rate === 0 ? 'bg-rose-600' : 'bg-indigo-600'}`}
              style={{ left: `${(s.t0 / total) * 100}%`, maxWidth: `${(s.dur / total) * 100}%` }}
              title={s.rate === 0 ? 'ফ্রিজ ফ্রেম' : s.rate < 1 ? 'স্লো-মোশন' : 'দ্রুত'}
            >
              {s.rate === 0 ? 'ফ্রিজ' : `${bnDigits(s.rate)}×`}
            </span>
          ) : null,
        )}
        {joins.map((s, i) => (
          <span key={`j${i}`} className="absolute top-0 bottom-0 w-[3px] -ml-[1.5px] bg-red-500" style={{ left: `${(s.t0 / total) * 100}%` }} title="এখানে কাটা অংশ বাদ গেছে" />
        ))}
        {marks.map((t, i) => (
          <span key={i} className="absolute bottom-0 w-1.5 h-2.5 -ml-[3px] rounded-t bg-white/90" style={{ left: `${(t / total) * 100}%` }} />
        ))}
        <span className="absolute top-0 bottom-0 w-0.5 -ml-px bg-red-600 shadow-[0_0_0_1px_rgba(255,255,255,0.7)]" style={{ left: `${(time / total) * 100}%` }}>
          <span className="absolute -top-0.5 -left-[5px] w-3 h-3 rounded-full bg-red-600 border-2 border-white" />
        </span>
      </div>
      <div className="flex justify-between text-[11px] text-neutral-400 mt-1 tabular-nums">
        <span>{bnClock(0)}</span>
        <span>মোট {bnClock(total)}</span>
      </div>
    </div>
  )
}
