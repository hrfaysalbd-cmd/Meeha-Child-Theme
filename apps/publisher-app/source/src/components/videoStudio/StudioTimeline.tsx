/**
 * Round 21 — the editor's timeline strip: every part of the finished video
 * in order (ইন্ট্রো · সংবাদ · বিজ্ঞাপন · আউট্রো), caption markers, and a
 * draggable playhead.
 */
import { useRef } from 'react'
import type { EditConfig } from '../../lib/videoEdit/config'
import { bnClock } from '../../lib/videoEdit/motion'
import { sourceToOutput, type Segment, type Timeline } from '../../lib/videoEdit/timeline'

const STYLE: Record<Segment['kind'], { bg: string; label: string }> = {
  intro: { bg: 'bg-violet-500', label: 'ইন্ট্রো' },
  main: { bg: 'bg-sky-600', label: 'সংবাদ' },
  ad: { bg: 'bg-amber-400', label: 'বিজ্ঞাপন' },
  card: { bg: 'bg-neutral-500', label: '' },
  outro: { bg: 'bg-violet-500', label: 'আউট্রো' },
}

export default function StudioTimeline({ tl, cfg, time, onSeek }: { tl: Timeline; cfg: EditConfig; time: number; onSeek: (t: number) => void }) {
  const ref = useRef<HTMLDivElement>(null)
  const total = Math.max(0.01, tl.duration)
  const at = (clientX: number) => {
    const r = ref.current!.getBoundingClientRect()
    return Math.min(1, Math.max(0, (clientX - r.left) / r.width)) * total
  }
  // where a cut joins two parts of the video
  const joins = tl.segments.filter((s, i) => {
    const prev = tl.segments[i - 1]
    return s.kind === 'main' && prev?.kind === 'main' && Math.abs(prev.clipStart + prev.dur - s.clipStart) > 0.05
  })
  const marks = cfg.captions
    .map((c) => sourceToOutput(tl, c.at))
    .filter((t): t is number => t !== null)
  return (
    <div className="select-none">
      <div
        ref={ref}
        className="relative h-11 rounded-lg overflow-hidden bg-neutral-200 cursor-pointer touch-none"
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
              {(s.dur / total) * 100 > 9 && <span className="text-[11px] font-semibold text-white/95 whitespace-nowrap px-1">{STYLE[s.kind].label}</span>}
            </div>
          ))}
        </div>
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
