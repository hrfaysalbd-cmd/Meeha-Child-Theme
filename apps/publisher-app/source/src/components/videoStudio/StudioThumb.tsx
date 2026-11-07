/**
 * Round 23, rebuilt in round 26 — the editor's "থাম্বনেইল" tab, made like
 * the photocard studio: 26 designs in collapsible families with live
 * previews, four shapes (16:9, 9:16, 1:1, 4:5), palettes, **highlighted**
 * words, headline size, logo corner, picture punch, and free layers (text,
 * burst badge, arrow, circle, box …) that are dragged on the preview. The
 * render puts the chosen shape on the post; "সব মাপ" downloads all four.
 */
import { useEffect, useRef, useState, type PointerEvent as RPointerEvent } from 'react'
import {
  LIMITS,
  THUMB_COLORS,
  THUMB_GROUPS,
  THUMB_LAYER_KINDS,
  THUMB_PALETTES,
  THUMB_SIZES,
  THUMB_TEMPLATES,
  type EditConfig,
  type ThumbLayer,
  type ThumbSize,
  type ThumbTemplate,
} from '../../lib/videoEdit/config'
import type { DrawEnv, Picture } from '../../lib/videoEdit/draw'
import { drawThumbnail, grabFrame, newLayer, thumbDims, thumbnailJpeg, thumbSize, thumbSource, type LayerBox } from '../../lib/videoEdit/thumb'
import { downloadBlob } from '../../lib/videoEdit/wav'
import { bnNumber } from '../ui'
import { IconPhotoPlus, IconTrash } from '../Icon'
import { ChoiceCard, Group, Section, Segmented, Slider, SwitchRow, TextInput, TimeField, useGroups } from './StudioFields'
import type { Setter } from './StudioPanels'

type Pic = Picture | null

/** A gallery card: one design drawn small with the current picture and text. */
function DesignCard({ env, pic, face, src, template, ratio, active, onPick, name, hint, isNew, tick }: {
  env: DrawEnv
  pic: Pic
  face: Pic
  src: number
  template: ThumbTemplate
  ratio: number
  active: boolean
  onPick: () => void
  name: string
  hint: string
  isNew?: boolean
  tick: number
}) {
  const ref = useRef<HTMLCanvasElement>(null)
  useEffect(() => {
    const c = ref.current
    if (!c) return
    const W = ratio >= 1 ? 384 : Math.round(384 * ratio)
    const H = Math.round(W / ratio)
    c.width = W
    c.height = H
    const ctx = c.getContext('2d')
    if (!ctx) return
    let live = true
    document.fonts.ready.then(() => {
      if (live) drawThumbnail(ctx, env, pic, face, W, H, src, { template, noLayers: true })
    })
    return () => {
      live = false
    }
  }, [env, pic, face, src, template, ratio, tick])
  return (
    <ChoiceCard
      active={active}
      onClick={onPick}
      title={name}
      hint={hint}
      badge={isNew ? 'নতুন' : undefined}
      testId={`thumb-${template}`}
      preview={
        <div className="bg-neutral-900 flex justify-center">
          <canvas ref={ref} className="block h-auto" style={{ aspectRatio: String(ratio), width: ratio >= 1 ? '100%' : `${Math.round(ratio * 100)}%` }} />
        </div>
      }
    />
  )
}

export default function ThumbPanel({
  cfg,
  set,
  env,
  mainUrl,
  range,
  playhead,
  face,
  onPickFace,
  getMain,
  wide = false,
}: {
  /** Round 29: shown under the preview, full width: preview and controls side by side. */
  wide?: boolean
  cfg: EditConfig
  set: Setter
  env: DrawEnv
  mainUrl: string
  range: { start: number; end: number }
  playhead: () => number | null
  face: Picture | null
  onPickFace: () => void
  /** The downloaded main video (for full-quality stills to download). */
  getMain: (signal: AbortSignal, report: (label: string, ratio: number) => void) => Promise<Blob>
}) {
  const t = cfg.thumb
  const patch = (p: Partial<EditConfig['thumb']>) => set((c) => ({ ...c, thumb: { ...c.thumb, ...p } }))
  const setLayer = (i: number, p: Partial<ThumbLayer>) => set((c) => ({ ...c, thumb: { ...c.thumb, layers: c.thumb.layers.map((l, j) => (j === i ? { ...l, ...p } : l)) } }))
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const boxes = useRef<LayerBox[]>([])
  const drag = useRef<{ i: number; dx: number; dy: number } | null>(null)
  const [tick, setTick] = useState(0)
  const [sel, setSel] = useState<number | null>(null)
  const [job, setJob] = useState<string | null>(null)
  const groups = useGroups('nil', THUMB_TEMPLATES.find((x) => x.id === t.template)?.group)
  const src = thumbSource(cfg, range.start, range.end)
  const { W, H } = thumbSize(cfg, env.L)
  const ratio = W / H
  const selLayer = sel !== null ? t.layers[sel] : undefined

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

  const v = videoRef.current
  const pic: Pic = v && v.videoWidth && v.readyState >= 2 ? { src: v, w: v.videoWidth, h: v.videoHeight } : null

  useEffect(() => {
    const c = canvasRef.current
    if (!c) return
    c.width = W
    c.height = H
    const ctx = c.getContext('2d')
    if (!ctx) return
    let live = true
    document.fonts.ready.then(() => {
      if (!live) return
      boxes.current = drawThumbnail(ctx, env, pic, face, W, H, src)
      if (sel !== null && boxes.current[sel]) {
        const b = boxes.current[sel]
        ctx.save()
        ctx.setLineDash([10, 8])
        ctx.lineWidth = 3
        ctx.strokeStyle = '#38BDF8'
        ctx.strokeRect(b.x - 6, b.y - 6, b.w + 12, b.h + 12)
        ctx.restore()
      }
    })
    return () => {
      live = false
    }
    // pic changes with tick
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [env, face, W, H, src, tick, sel])

  // ---- dragging layers on the preview ----
  const toCanvas = (e: RPointerEvent<HTMLCanvasElement>) => {
    const r = e.currentTarget.getBoundingClientRect()
    return { x: ((e.clientX - r.left) / r.width) * W, y: ((e.clientY - r.top) / r.height) * H }
  }
  const onDown = (e: RPointerEvent<HTMLCanvasElement>) => {
    const p = toCanvas(e)
    for (let i = boxes.current.length - 1; i >= 0; i--) {
      const b = boxes.current[i]
      if (p.x >= b.x - 10 && p.x <= b.x + b.w + 10 && p.y >= b.y - 10 && p.y <= b.y + b.h + 10) {
        const l = t.layers[i]
        drag.current = { i, dx: p.x - l.x * W, dy: p.y - l.y * H }
        setSel(i)
        e.currentTarget.setPointerCapture(e.pointerId)
        return
      }
    }
    setSel(null)
  }
  const onMove = (e: RPointerEvent<HTMLCanvasElement>) => {
    const d = drag.current
    if (!d) return
    const p = toCanvas(e)
    const x = Math.min(1, Math.max(0, (p.x - d.dx) / W))
    const y = Math.min(1, Math.max(0, (p.y - d.dy) / H))
    setLayer(d.i, { x: Math.round(x * 1000) / 1000, y: Math.round(y * 1000) / 1000 })
  }
  const onUp = () => {
    drag.current = null
  }

  const addLayer = (kind: ThumbLayer['kind']) => {
    if (t.layers.length >= LIMITS.thumbLayers) return
    const color = kind === 'text' ? '#FFC20E' : kind === 'burst' ? '#E53935' : '#E53935'
    set((c) => ({ ...c, thumb: { ...c.thumb, on: true, layers: [...c.thumb.layers, newLayer(kind, color)] } }))
    setSel(t.layers.length)
  }
  const removeLayer = (i: number) => {
    set((c) => ({ ...c, thumb: { ...c.thumb, layers: c.thumb.layers.filter((_, j) => j !== i) } }))
    setSel(null)
  }
  const copyLayer = (i: number) => {
    if (t.layers.length >= LIMITS.thumbLayers) return
    const l = t.layers[i]
    set((c) => ({ ...c, thumb: { ...c.thumb, layers: [...c.thumb.layers, { ...l, x: Math.min(1, l.x + 0.04), y: Math.min(1, l.y + 0.04) }] } }))
    setSel(t.layers.length)
  }

  // ---- downloads (full-quality picture from the downloaded file) ----
  async function download(sizes: ThumbSize[]) {
    if (job) return
    const ctrl = new AbortController()
    try {
      setJob('মূল ভিডিও আনা হচ্ছে…')
      const blob = await getMain(ctrl.signal, (label, r) => setJob(r > 0 ? `${label} ${bnNumber(Math.round(r * 100))}%` : label))
      setJob('ছবি তৈরি হচ্ছে…')
      const still = await grabFrame(blob, src)
      await document.fonts.ready
      for (const size of sizes) {
        const jpg = await thumbnailJpeg(env, still, face, src, size)
        if (jpg) {
          const d = thumbDims(size, env.L)
          downloadBlob(jpg, `meeha-thumbnail-${size.replace(':', 'x')}-${d.W}x${d.H}.jpg`)
          await new Promise((r) => setTimeout(r, 350))
        }
      }
      setJob(null)
    } catch (err) {
      setJob(`ডাউনলোড হয়নি: ${err instanceof Error ? err.message : 'অজানা সমস্যা'}`)
    }
  }

  return (
    <div className={wide ? 'grid md:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] gap-x-6 items-start' : ''}>
      <div className={wide ? 'md:sticky md:top-4' : ''}>
      <Section title="থাম্বনেইল ডিজাইন" hint="রেন্ডারের সময় পোস্টের থাম্বনেইল এই ডিজাইনে তৈরি হবে। প্রিভিউর উপর লেয়ার টেনে সরান।">
        <SwitchRow label="ডিজাইন করা থাম্বনেইল" hint="বন্ধ রাখলে ফ্রেমসহ ভিডিওর একটি দৃশ্য থাম্বনেইল হবে।" checked={t.on} onChange={(v) => patch({ on: v })} testId="thumb-on" />
        <div className={`mt-2 rounded-xl overflow-hidden border border-neutral-200 bg-neutral-900 flex justify-center ${t.on ? '' : 'opacity-40'}`}>
          <canvas
            ref={canvasRef}
            onPointerDown={onDown}
            onPointerMove={onMove}
            onPointerUp={onUp}
            onPointerCancel={onUp}
            className="block h-auto touch-none cursor-grab"
            style={{ aspectRatio: `${W} / ${H}`, width: ratio >= 1 ? '100%' : `min(100%, ${Math.round(420 * ratio)}px)` }}
            data-testid="thumb-canvas"
          />
        </div>
        <div className="mt-2 flex flex-wrap gap-1.5">
          <button type="button" onClick={() => download([t.size])} disabled={!!job} className="text-[12px] px-2.5 py-1.5 rounded-lg bg-neutral-100 text-neutral-700 disabled:opacity-50" data-testid="thumb-download">
            ⤓ এই মাপ ডাউনলোড
          </button>
          <button type="button" onClick={() => download(['16:9', '9:16', '1:1', '4:5'])} disabled={!!job} className="text-[12px] px-2.5 py-1.5 rounded-lg bg-brand-50 text-brand-700 font-semibold disabled:opacity-50" data-testid="thumb-download-all">
            ⤓ সব ৪ মাপ একসাথে
          </button>
          {job && <span className="text-[11.5px] text-neutral-500 self-center">{job}</span>}
        </div>
      </Section>
      </div>
      <div>

      <Section title="মাপ (রেশিও)" hint="পোস্টে যে মাপটি বসবে। প্রতিটি নকশা সব মাপে নিজে থেকে সাজে।">
        <Segmented value={t.size} options={THUMB_SIZES.map((s) => ({ id: s.id, name: `${s.name} · ${s.hint}` }))} onChange={(v) => patch({ size: v })} size="sm" />
      </Section>

      <Section title="লেখা" hint="শিরোনামে **শব্দ** লিখলে সেই শব্দ ফটোকার্ডের মতো রঙিন হবে।">
        <TextInput label="শিরোনাম" value={t.title} max={LIMITS.thumbTitle} placeholder={env.title} onChange={(v) => patch({ title: v })} multiline testId="thumb-title" />
        <TextInput label="ছোট লাইন / নাম (ঐচ্ছিক)" value={t.sub} max={LIMITS.thumbSub} placeholder="যেমন: বক্তার নাম, স্থান, তারিখ" onChange={(v) => patch({ sub: v })} testId="thumb-sub" />
        <TextInput label="ব্যাজ (ঐচ্ছিক)" value={t.badge} max={LIMITS.thumbBadge} placeholder="যেমন: এক্সক্লুসিভ, লাইভ, ভিডিও" onChange={(v) => patch({ badge: v })} testId="thumb-badge" />
      </Section>

      <Section title="নকশা" hint={`${bnNumber(THUMB_TEMPLATES.length)}টি নকশা, ভাগ করে সাজানো — শিরোনামে চাপ দিলে খোলে।`}>
        <div className="space-y-2">
          {THUMB_GROUPS.map((g) => {
            const items = THUMB_TEMPLATES.filter((x) => x.group === g.id)
            const open = groups.isOpen(g.id)
            return (
              <Group key={g.id} title={g.name} hint={g.hint} count={items.length} open={open} onToggle={() => groups.toggle(g.id)} hasSelected={items.some((x) => x.id === t.template)} testId={`thumb-group-${g.id}`}>
                {open && (
                  <div className={`grid ${wide ? (ratio >= 1 ? 'grid-cols-2 xl:grid-cols-3' : 'grid-cols-3 xl:grid-cols-4') : ratio >= 1 ? 'grid-cols-2' : 'grid-cols-3'} gap-2.5`}>
                    {items.map((x) => (
                      <DesignCard
                        key={x.id}
                        env={env}
                        pic={pic}
                        face={face}
                        src={src}
                        template={x.id}
                        ratio={ratio}
                        active={t.template === x.id}
                        onPick={() => patch({ template: x.id, on: true })}
                        name={x.name}
                        hint={x.hint}
                        isNew={x.isNew}
                        tick={tick}
                      />
                    ))}
                  </div>
                )}
              </Group>
            )
          })}
        </div>
      </Section>

      <Section title="রং ও স্টাইল">
        <p className="text-[12px] font-medium text-neutral-600 mb-1.5">রঙের প্যালেট</p>
        <div className="flex flex-wrap gap-1.5 mb-3">
          {THUMB_PALETTES.map((p) => (
            <button
              key={p.id}
              type="button"
              onClick={() => patch({ palette: p.id })}
              aria-pressed={t.palette === p.id}
              data-testid={`thumb-palette-${p.id}`}
              className={`flex items-center gap-1.5 rounded-lg border px-2 py-1 text-[11.5px] ${t.palette === p.id ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-neutral-200 text-neutral-600'}`}
            >
              {p.id !== 'auto' && (
                <span className="flex">
                  <span className="w-3 h-3 rounded-l-sm" style={{ background: p.dark }} />
                  <span className="w-3 h-3" style={{ background: p.mid }} />
                  <span className="w-3 h-3 rounded-r-sm" style={{ background: p.accent }} />
                </span>
              )}
              {p.name}
            </button>
          ))}
        </div>
        <Slider label="শিরোনামের আকার" value={Math.round(t.scale * 100)} min={75} max={135} step={5} onChange={(v) => patch({ scale: v / 100 })} format={(v) => `${bnNumber(v)}%`} />
        <p className="text-[12px] font-medium text-neutral-600 mt-3 mb-1.5">লোগো</p>
        <Segmented
          value={t.logo}
          options={[
            { id: 'auto', name: 'নকশা অনুযায়ী' },
            { id: 'tl', name: 'উপরে বামে' },
            { id: 'tr', name: 'উপরে ডানে' },
            { id: 'bl', name: 'নিচে বামে' },
            { id: 'br', name: 'নিচে ডানে' },
            { id: 'off', name: 'লোগো নেই' },
          ]}
          onChange={(v) => patch({ logo: v })}
          size="sm"
        />
        <p className="text-[12px] font-medium text-neutral-600 mt-3 mb-1.5">ছবির রং</p>
        <Segmented
          value={t.grade}
          options={[
            { id: 'none', name: 'যেমন আছে' },
            { id: 'pop', name: 'উজ্জ্বল' },
            { id: 'dark', name: 'গাঢ় (লেখা স্পষ্ট)' },
          ]}
          onChange={(v) => patch({ grade: v })}
          size="sm"
        />
      </Section>

      <Section title="লেয়ার (লেখা ও আকৃতি)" hint={`সর্বোচ্চ ${bnNumber(LIMITS.thumbLayers)}টি। প্রিভিউতে চাপ দিয়ে বাছুন, টেনে সরান।`}>
        <div className="flex flex-wrap gap-1.5">
          {THUMB_LAYER_KINDS.map((k) => (
            <button
              key={k.id}
              type="button"
              onClick={() => addLayer(k.id)}
              disabled={t.layers.length >= LIMITS.thumbLayers}
              data-testid={`thumb-add-${k.id}`}
              className="text-[12px] px-2.5 py-1.5 rounded-lg border border-dashed border-neutral-300 text-neutral-700 disabled:opacity-40"
            >
              + {k.name}
            </button>
          ))}
        </div>
        {t.layers.length > 0 && (
          <div className="mt-2 flex flex-wrap gap-1.5">
            {t.layers.map((l, i) => (
              <button
                key={i}
                type="button"
                onClick={() => setSel(i)}
                className={`text-[11.5px] px-2 py-1 rounded-md border ${sel === i ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-neutral-200 text-neutral-600'}`}
              >
                {bnNumber(i + 1)}. {THUMB_LAYER_KINDS.find((k) => k.id === l.kind)?.name}
                {l.text ? ` · ${l.text.slice(0, 10)}` : ''}
              </button>
            ))}
          </div>
        )}
        {selLayer && sel !== null && (
          <div className="mt-3 rounded-xl border border-neutral-200 p-3 space-y-2" data-testid="thumb-layer-editor">
            {(selLayer.kind === 'text' || selLayer.kind === 'burst') && (
              <TextInput label="লেখা" value={selLayer.text} max={LIMITS.thumbLayerText} onChange={(v) => setLayer(sel, { text: v })} testId="thumb-layer-text" />
            )}
            <div>
              <p className="text-[12px] font-medium text-neutral-600 mb-1">রং</p>
              <div className="flex gap-1.5">
                {THUMB_COLORS.map((col) => (
                  <button
                    key={col}
                    type="button"
                    aria-label={col}
                    onClick={() => setLayer(sel, { color: col })}
                    className={`w-7 h-7 rounded-full border-2 ${selLayer.color === col ? 'border-brand-500 ring-2 ring-brand-500/30' : 'border-neutral-200'}`}
                    style={{ background: col }}
                  />
                ))}
              </div>
            </div>
            {selLayer.kind === 'text' ? (
              <>
                <Slider label="আকার" value={Math.round(selLayer.h * 100)} min={3} max={25} onChange={(v) => setLayer(sel, { h: v / 100 })} format={(v) => bnNumber(v)} />
                <SwitchRow label="রঙিন পটভূমি" hint="বন্ধ করলে দাগ-দেওয়া (আউটলাইন) লেখা" checked={selLayer.plate} onChange={(v) => setLayer(sel, { plate: v })} />
              </>
            ) : (
              <>
                <Slider label="চওড়া" value={Math.round(selLayer.w * 100)} min={3} max={90} onChange={(v) => setLayer(sel, { w: v / 100 })} format={(v) => `${bnNumber(v)}%`} />
                <Slider label="উঁচু" value={Math.round(selLayer.h * 100)} min={3} max={90} onChange={(v) => setLayer(sel, { h: v / 100 })} format={(v) => `${bnNumber(v)}%`} />
              </>
            )}
            <Slider label="ঘোরানো" value={Math.round(selLayer.angle)} min={-180} max={180} step={2} onChange={(v) => setLayer(sel, { angle: v })} format={(v) => `${bnNumber(v)}°`} />
            <div className="flex gap-2">
              <button type="button" onClick={() => copyLayer(sel)} className="text-[12px] px-2.5 py-1.5 rounded-lg bg-neutral-100 text-neutral-700">
                কপি
              </button>
              <button type="button" onClick={() => removeLayer(sel)} className="text-[12px] px-2.5 py-1.5 rounded-lg bg-red-50 text-red-600" data-testid="thumb-layer-remove">
                মুছুন
              </button>
            </div>
          </div>
        )}
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
          <p className="text-[12px] font-medium text-neutral-600 mb-1">মুখের ছবি (মুখ, মুখোমুখি, সাক্ষাৎকার, প্রতিক্রিয়া, উক্তি নকশায় দেখাবে)</p>
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
          <p className="text-[11px] text-neutral-400 mt-1">ছবি না দিলে ভিডিওর দৃশ্যটিই বসবে।</p>
        </div>
      </Section>
      </div>
    </div>
  )
}
