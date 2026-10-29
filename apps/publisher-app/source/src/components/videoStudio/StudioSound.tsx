/**
 * Round 22: the editor's "শব্দ ও মিউজিক" tab. It holds Meeha's brand music
 * bed (five pieces, looped under the whole video and dipping under speech),
 * voice clean-up and noise reduction for the main video, the volume
 * controls, and "শুনে দেখুন", which plays the exact mix the export will
 * make.
 */
import { useEffect, useRef, useState } from 'react'
import { MUSIC, type EditConfig, type MusicId } from '../../lib/videoEdit/config'
import { musicBuffer } from '../../lib/videoEdit/music'
import { bnNumber } from '../ui'
import { IconPlayerPause, IconPlayerPlay, IconVolume } from '../Icon'
import { Section, Segmented, Slider, SwitchRow } from './StudioFields'
import type { Setter } from './StudioPanels'

const pct = (v: number) => `${bnNumber(Math.round(v * 100))}%`

export type AuditionState = null | { stage: 'loading'; label: string } | { stage: 'playing'; from: number; seconds: number }

export default function SoundPanel({
  cfg,
  set,
  audioContext,
  onSample,
  audition,
  onAudition,
  onStopAudition,
}: {
  cfg: EditConfig
  set: Setter
  audioContext: () => AudioContext | null
  /** Called before a music sample plays (the preview pauses). */
  onSample: () => void
  audition: AuditionState
  onAudition: () => void
  onStopAudition: () => void
}) {
  const a = cfg.audio
  const setAudio = (patch: Partial<EditConfig['audio']>) => set((c) => ({ ...c, audio: { ...c.audio, ...patch } }))
  const [sample, setSample] = useState<{ id: MusicId; ready: boolean } | null>(null)
  const sampleSrc = useRef<AudioBufferSourceNode | null>(null)
  const wanted = useRef<MusicId | null>(null)

  const stopSample = () => {
    wanted.current = null
    try {
      sampleSrc.current?.stop()
    } catch {
      // already stopped
    }
    sampleSrc.current = null
    setSample(null)
  }
  useEffect(() => () => stopSample(), [])

  async function playSample(id: MusicId) {
    if (wanted.current === id) return stopSample()
    stopSample()
    onSample()
    wanted.current = id
    setSample({ id, ready: false })
    const ctx = audioContext()
    const buf = await musicBuffer(id)
    if (!ctx || wanted.current !== id) return
    await ctx.resume().catch(() => undefined)
    if (wanted.current !== id) return
    const src = ctx.createBufferSource()
    src.buffer = buf
    const g = ctx.createGain()
    g.gain.value = 0.8
    src.connect(g).connect(ctx.destination)
    src.onended = () => {
      if (sampleSrc.current !== src) return
      sampleSrc.current = null
      wanted.current = null
      setSample(null)
    }
    src.start()
    sampleSrc.current = src
    setSample({ id, ready: true })
  }

  return (
    <div>
      <Section title="ব্র্যান্ড মিউজিক" hint="মীহা নিউজের নিজস্ব সুর। ভিডিও যত বড়ই হোক, পুরোটা জুড়ে নিচু ভলিউমে বারবার বাজবে। প্রতিটি সুর শুরু হয় মীহার চার-নোটের সিগনেচার সুর দিয়ে।">
        <div className="space-y-1.5" role="radiogroup" aria-label="ব্র্যান্ড মিউজিক">
          {[{ id: 'none' as const, name: 'মিউজিক নেই', hint: 'শুধু ভিডিওর নিজের শব্দ' }, ...MUSIC].map((m) => {
            const on = a.music === m.id
            const playing = sample?.id === m.id
            return (
              <div
                key={m.id}
                className={`flex items-center gap-2 rounded-xl border px-3 py-2 ${on ? 'border-brand-600 bg-brand-50' : 'border-neutral-200 hover:border-neutral-300'}`}
              >
                <button type="button" role="radio" aria-checked={on} onClick={() => setAudio({ music: m.id })} className="flex-1 min-w-0 text-left" data-testid={`music-${m.id}`}>
                  <span className={`block text-[13px] font-semibold ${on ? 'text-brand-700' : 'text-neutral-800'}`}>
                    {m.name}
                    {m.id === 'signature' && <span className="ml-1.5 text-[10.5px] font-medium text-neutral-400">ডিফল্ট</span>}
                  </span>
                  <span className="block text-[11.5px] text-neutral-500 truncate">{m.hint}</span>
                </button>
                {m.id !== 'none' && (
                  <button
                    type="button"
                    onClick={() => playSample(m.id)}
                    className={`shrink-0 flex items-center gap-1 rounded-full px-2.5 py-1 text-[11.5px] font-medium ${playing ? 'bg-brand-600 text-white' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'}`}
                    aria-label={`${m.name} শুনুন`}
                    data-testid={`music-sample-${m.id}`}
                  >
                    {playing ? <IconPlayerPause className="w-3.5 h-3.5" /> : <IconPlayerPlay className="w-3.5 h-3.5" />}
                    {playing ? (sample?.ready ? 'থামান' : 'তৈরি হচ্ছে…') : 'শুনুন'}
                  </button>
                )}
              </div>
            )
          })}
        </div>
        {a.music !== 'none' && (
          <div className="mt-3">
            <Slider label="মিউজিকের ভলিউম" value={Math.round(a.musicVol * 100)} min={0} max={100} step={5} onChange={(v) => setAudio({ musicVol: v / 100 })} format={(v) => pct(v / 100)} />
            <SwitchRow label="কথার সময় মিউজিক আরও নিচু হবে" hint="কেউ কথা বললে মিউজিক নিজে থেকে কমে যায়, থামলে ধীরে ফিরে আসে" checked={a.duck} onChange={(v) => setAudio({ duck: v })} testId="music-duck" />
            <p className="text-[11.5px] text-neutral-500 mt-2 mb-1">কোথায় বাজবে</p>
            <Segmented
              value={a.musicOver}
              options={[
                { id: 'all', name: 'পুরো ভিডিও' },
                { id: 'main', name: 'শুধু মূল খবরে' },
              ]}
              onChange={(v) => setAudio({ musicOver: v })}
              size="sm"
            />
          </div>
        )}
      </Section>

      <Section title="কণ্ঠ ও নয়েজ" hint="মূল ভিডিওর কথা পরিষ্কার করে। প্রিভিউতে আসল ফল শুনতে নিচের “শুনে দেখুন” চাপুন।">
        <p className="text-[11.5px] text-neutral-500 mb-1">কণ্ঠ উন্নত করা</p>
        <Segmented
          value={a.voice}
          options={[
            { id: 'off', name: 'বন্ধ' },
            { id: 'clear', name: 'স্পষ্ট কণ্ঠ' },
            { id: 'strong', name: 'জোরালো (মাঠের রিপোর্ট)' },
          ]}
          onChange={(v) => setAudio({ voice: v })}
          size="sm"
        />
        <p className="text-[11px] text-neutral-400 mt-1">গুমগুম আওয়াজ কাটে, কণ্ঠ সামনে আনে, জোরে-আস্তে কথা সমান করে।</p>
        <p className="text-[11.5px] text-neutral-500 mt-3 mb-1">নয়েজ কমানো</p>
        <Segmented
          value={a.denoise}
          options={[
            { id: 'off', name: 'বন্ধ' },
            { id: 'low', name: 'হালকা' },
            { id: 'medium', name: 'মাঝারি' },
            { id: 'high', name: 'বেশি' },
          ]}
          onChange={(v) => setAudio({ denoise: v })}
          size="sm"
        />
        <p className="text-[11px] text-neutral-400 mt-1">ফ্যান, এসি, গাড়ি, বাতাস বা হিসহিস শব্দের মতো একটানা আওয়াজ কমায়। “বেশি” দিলে কণ্ঠ কিছুটা কৃত্রিম শোনাতে পারে।</p>
      </Section>

      <Section title="শব্দের মাত্রা">
        <p className="text-[11.5px] text-neutral-500 mb-1">লাউডনেস সমান করা</p>
        <Segmented
          value={a.loudness}
          options={[
            { id: 'off', name: 'বন্ধ' },
            { id: '-14', name: '−১৪ LUFS (সোশ্যাল)' },
            { id: '-16', name: '−১৬ LUFS (একটু নরম)' },
          ]}
          onChange={(v) => setAudio({ loudness: v })}
          size="sm"
        />
        <p className="text-[11px] text-neutral-400 mt-1 mb-2">রেন্ডারের আগে পুরো ভিডিওর শব্দ মেপে সব ভিডিও একই জোরে আনে, যাতে ফিডে কোনোটা খুব আস্তে বা খুব জোরে না শোনায়।</p>
        <Slider label="মূল ভিডিওর শব্দ" value={Math.round(a.main * 100)} min={0} max={100} step={5} onChange={(v) => setAudio({ main: v / 100 })} format={(v) => pct(v / 100)} />
        <Slider label="ইন্ট্রো/আউট্রো/বিজ্ঞাপনের শব্দ" value={Math.round(a.clips * 100)} min={0} max={100} step={5} onChange={(v) => setAudio({ clips: v / 100 })} format={(v) => pct(v / 100)} />
        <SwitchRow label="সাউন্ড ইফেক্ট" hint="ইন্ট্রো, ফ্রেম ও বিজ্ঞাপন কার্ডে হালকা হুশ/চাইম শব্দ" checked={a.sfx} onChange={(v) => setAudio({ sfx: v })} />
      </Section>

      <div className="rounded-xl bg-neutral-50 border border-neutral-200 p-3">
        <p className="text-[12.5px] font-semibold text-neutral-800 flex items-center gap-1.5">
          <IconVolume className="w-4 h-4 text-brand-600" />
          চূড়ান্ত সাউন্ড শুনে দেখুন
        </p>
        <p className="text-[11.5px] text-neutral-500 mt-0.5 mb-2">প্রিভিউ যেখানে আছে সেখান থেকে ১৫ সেকেন্ড, ঠিক রেন্ডারের মতো করে (মিউজিক, কণ্ঠ ও নয়েজ সহ)।</p>
        {audition?.stage === 'loading' ? (
          <button type="button" onClick={onStopAudition} className="text-[12.5px] px-3 py-1.5 rounded-lg bg-neutral-200 text-neutral-700" data-testid="audition">
            {audition.label} · বাতিল
          </button>
        ) : audition?.stage === 'playing' ? (
          <button type="button" onClick={onStopAudition} className="text-[12.5px] px-3 py-1.5 rounded-lg bg-brand-600 text-white" data-testid="audition">
            বাজছে… থামান
          </button>
        ) : (
          <button type="button" onClick={onAudition} className="text-[12.5px] px-3 py-1.5 rounded-lg bg-brand-600 text-white font-semibold" data-testid="audition">
            শুনে দেখুন
          </button>
        )}
      </div>
    </div>
  )
}
