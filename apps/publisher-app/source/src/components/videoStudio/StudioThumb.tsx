/**
 * Round 23 — the editor's "থাম্বনেইল" tab: a designed thumbnail (headline,
 * badge, face photo, Meeha logo) over a chosen scene, with a live preview.
 * The render puts it on the post instead of a plain frame.
 */
import { useEffect, useRef, useState } from 'react'
import { LIMITS, THUMB_TEMPLATES, type EditConfig } from '../../lib/videoEdit/config'
import type { DrawEnv, Picture } from '../../lib/videoEdit/draw'
import { drawThumbnail, thumbSize, thumbSource } from '../../lib/videoEdit/thumb'
import { IconPhotoPlus, IconTrash } from '../Icon'
import { Section, Segmented, SwitchRow, TextInput, TimeField } from './StudioFields'
import type { Setter } from './StudioPanels'

export default function ThumbPanel({
  cfg,
  set,
  env,
  mainUrl,
  range,
  playhead,
  face,
  onPickFace,
}: {
  cfg: EditConfig
  set: Setter
  env: DrawEnv
  mainUrl: string
  range: { start: number; end: number }
  playhead: () => number | null
  face: Picture | null
  onPickFace: () => void
}) {
  const t = cfg.thumb
  const patch = (p: Partial<EditConfig['thumb']>) => set((c) => ({ ...c, thumb: { ...c.thumb, ...p } }))
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const [tick, setTick] = useState(0)
  const src = thumbSource(cfg, range.start, range.end)
  const { W, H } = thumbSize(cfg, env.L)

  // a still of the chosen moment (display only: the preview video is cross-origin)
  useEffect(() => {
    const v = document.createElement('video')
    v.muted = true
    v.preload = 'auto'
    v.src = mainUrl
    v.onseeked = () => setTick((n) => n + 1)
    videoRef.current = v
    return () => {
      v.removeAttribute('src')
      v.load()
      videoRef.current = null
    }
  }, [mainUrl])
  useEffect(() => {
    const v = videoRef.current
    if (!v) return
    const go = () => {
      v.currentTime = src
    }
    if (v.readyState >= 1) go()
    else v.onloadedmetadata = go
  }, [src, mainUrl])

  useEffect(() => {
    const c = canvasRef.current
    const v = videoRef.current
    if (!c) return
    c.width = W
    c.height = H
    const ctx = c.getContext('2d')
    if (!ctx) return
    const pic: Picture | null = v && v.videoWidth && v.readyState >= 2 ? { src: v, w: v.videoWidth, h: v.videoHeight } : null
    document.fonts.ready.then(() => drawThumbnail(ctx, env, pic, face, W, H, src))
  }, [env, face, W, H, src, tick])

  return (
    <div>
      <Section title="থাম্বনেইল ডিজাইন" hint="রেন্ডারের সময় পোস্টের থাম্বনেইল এই ডিজাইনে তৈরি হবে।">
        <SwitchRow label="ডিজাইন করা থাম্বনেইল" hint="বন্ধ রাখলে ফ্রেমসহ ভিডিওর একটি দৃশ্য থাম্বনেইল হবে।" checked={t.on} onChange={(v) => patch({ on: v })} testId="thumb-on" />
        <div className={`mt-2 rounded-xl overflow-hidden border border-neutral-200 bg-neutral-900 ${t.on ? '' : 'opacity-40'}`}>
          <canvas ref={canvasRef} className="block w-full h-auto" style={{ aspectRatio: `${W} / ${H}`, maxHeight: 420, objectFit: 'contain' }} data-testid="thumb-canvas" />
        </div>
      </Section>

      <Section title="টেমপ্লেট">
        <div className="grid grid-cols-2 gap-1.5">
          {THUMB_TEMPLATES.map((x) => (
            <button
              key={x.id}
              type="button"
              onClick={() => patch({ template: x.id, on: true })}
              aria-pressed={t.template === x.id}
              data-testid={`thumb-${x.id}`}
              className={`text-left rounded-xl border px-2.5 py-2 ${t.template === x.id ? 'border-brand-500 ring-2 ring-brand-500/20 bg-brand-50/40' : 'border-neutral-200 hover:border-neutral-300'}`}
            >
              <span className={`block text-[12.5px] font-semibold ${t.template === x.id ? 'text-brand-700' : 'text-neutral-800'}`}>{x.name}</span>
              <span className="block text-[11px] text-neutral-400 leading-snug">{x.hint}</span>
            </button>
          ))}
        </div>
      </Section>

      <Section title="লেখা">
        <TextInput label="শিরোনাম" value={t.title} max={LIMITS.thumbTitle} placeholder={env.title} onChange={(v) => patch({ title: v })} multiline testId="thumb-title" />
        <TextInput label="ব্যাজ (ঐচ্ছিক)" value={t.badge} max={LIMITS.thumbBadge} placeholder="যেমন: এক্সক্লুসিভ, লাইভ, ভিডিও" onChange={(v) => patch({ badge: v })} testId="thumb-badge" />
      </Section>

      <Section title="ছবি" hint="ভিডিওর কোন মুহূর্তের দৃশ্য পেছনে থাকবে — প্রিভিউ থামিয়ে “এখানে” চাপুন।">
        <div className="flex items-end gap-3 flex-wrap">
          <TimeField
            label="দৃশ্যের সময়"
            value={src}
            min={range.start}
            max={range.end}
            onChange={(v) => patch({ at: v })}
            onPlayhead={() => {
              const p = playhead()
              if (p !== null) patch({ at: Math.round(p * 10) / 10 })
            }}
          />
          {t.at !== null && (
            <button type="button" onClick={() => patch({ at: null })} className="mb-0.5 text-[11.5px] px-2 py-1.5 rounded-lg bg-neutral-100 text-neutral-700">
              স্বয়ংক্রিয়
            </button>
          )}
        </div>
        <div className="mt-3">
          <p className="text-[12px] font-medium text-neutral-600 mb-1">মুখের ছবি {t.template === 'face' ? '' : '(“মুখের ছবি” টেমপ্লেটে দেখাবে)'}</p>
          {t.face ? (
            <div className="flex items-center gap-2">
              <p className="text-[12.5px] text-neutral-700 truncate flex-1">{t.face.title || 'ছবি'}</p>
              <button type="button" onClick={onPickFace} className="text-[11.5px] px-2 py-1 rounded-md bg-neutral-100">
                বদলান
              </button>
              <button type="button" onClick={() => patch({ face: null })} aria-label="সরান" className="text-red-500 hover:text-red-700">
                <IconTrash className="w-4 h-4" />
              </button>
            </div>
          ) : (
            <button
              type="button"
              onClick={onPickFace}
              data-testid="thumb-face"
              className="w-full flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-neutral-300 bg-neutral-50 text-neutral-700 py-2.5 text-[13px] font-semibold"
            >
              <IconPhotoPlus className="w-4 h-4" />
              গ্যালারি থেকে ছবি বেছে নিন
            </button>
          )}
          <p className="text-[11px] text-neutral-400 mt-1">ছবি না দিলে ভিডিওর দৃশ্যটিই গোল ফ্রেমে বসবে।</p>
        </div>
      </Section>

      <Section title="মাপ">
        <Segmented
          value={t.size}
          options={[
            { id: '16:9', name: 'YouTube (১৬:৯)' },
            { id: 'video', name: 'ভিডিওর মাপে' },
          ]}
          onChange={(v) => patch({ size: v })}
          size="sm"
        />
      </Section>
    </div>
  )
}
