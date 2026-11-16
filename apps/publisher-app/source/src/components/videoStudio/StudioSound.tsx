/**
 * Round 22: the editor's "শব্দ ও মিউজিক" tab. It holds Meeha's brand music
 * bed (five pieces, looped under the whole video and dipping under speech),
 * voice clean-up and noise reduction for the main video, the volume
 * controls, and "শুনে দেখুন", which plays the exact mix the export will
 * make.
 */
import { useEffect, useMemo, useRef, useState } from 'react'
import { LIMITS, MUSIC, MUSIC_GROUPS, MUSIC_MOODS, MUSIC_MOOD_OF, type EditConfig, type MusicId, type MusicMood } from '../../lib/videoEdit/config'
import { musicBpm, musicBuffer, musicLength } from '../../lib/videoEdit/music'
import { sfxBuffer } from '../../lib/videoEdit/audio'
import { SFX, SFX_GROUPS, type SfxId } from '../../lib/videoEdit/sfxlib'
import { downloadBlob, wavBlob } from '../../lib/videoEdit/wav'
import { bnClock } from '../../lib/videoEdit/motion'
import { bnNumber } from '../ui'
import { IconDownload, IconPlayerPause, IconPlayerPlay, IconPlus, IconTrash, IconVolume } from '../Icon'
import { Section, Segmented, Slider, SwitchRow, TimeField } from './StudioFields'
import { Chip, SearchBox, StarButton, useAssetMemory } from './AssetBrowser'
import type { Setter } from './StudioPanels'

type SampleId = MusicId | `sfx:${SfxId}`
const fileName = (id: SampleId) => `meeha-${id.replace(':', '-')}.wav`

const pct = (v: number) => `${bnNumber(Math.round(v * 100))}%`

export type AuditionState = null | { stage: 'loading'; label: string } | { stage: 'playing'; from: number; seconds: number }

/** Round 46: peaks of a buffer for the little waveform (cached per buffer). */
const peakCache = new WeakMap<AudioBuffer, number[]>()
function peaks(buf: AudioBuffer, n = 72) {
  let p = peakCache.get(buf)
  if (p) return p
  const d = buf.getChannelData(0)
  const step = Math.max(1, Math.floor(d.length / n))
  p = []
  let max = 0
  for (let i = 0; i < n; i++) {
    let m = 0
    for (let j = i * step; j < Math.min(d.length, (i + 1) * step); j += 16) m = Math.max(m, Math.abs(d[j]))
    p.push(m)
    max = Math.max(max, m)
  }
  p = p.map((v) => v / (max || 1))
  peakCache.set(buf, p)
  return p
}

/** Waveform with a moving play head (the sample that is playing). */
function Waveform({ buf, ctx, startedAt }: { buf: AudioBuffer; ctx: AudioContext | null; startedAt: number }) {
  const ref = useRef<HTMLCanvasElement>(null)
  useEffect(() => {
    const cv = ref.current
    if (!cv) return
    const pk = peaks(buf)
    let raf = 0
    const draw = () => {
      const w = (cv.width = cv.clientWidth * 2)
      const h = (cv.height = 56)
      const g = cv.getContext('2d')!
      const prog = ctx ? Math.min(1, Math.max(0, (ctx.currentTime - startedAt) / buf.duration)) : 0
      const bw = w / pk.length
      pk.forEach((v, i) => {
        const bh = Math.max(3, v * (h - 6))
        g.fillStyle = i / pk.length <= prog ? '#025AB2' : '#CBD5E1'
        g.fillRect(i * bw + 1, (h - bh) / 2, Math.max(1, bw - 2), bh)
      })
      g.fillStyle = '#CC2A1F'
      g.fillRect(prog * w - 1, 0, 2, h)
      if (prog < 1) raf = requestAnimationFrame(draw)
    }
    draw()
    return () => cancelAnimationFrame(raf)
  }, [buf, ctx, startedAt])
  return <canvas ref={ref} className="block w-full h-7 mt-1.5" data-testid="sample-waveform" />
}

const secs = (n: number) => `${bnNumber(Math.round(n))} সে.`

export default function SoundPanel({
  cfg,
  set,
  audioContext,
  onSample,
  audition,
  onAudition,
  onStopAudition,
  range,
  playhead,
}: {
  cfg: EditConfig
  set: Setter
  audioContext: () => AudioContext | null
  /** Called before a music sample plays (the preview pauses). */
  onSample: () => void
  audition: AuditionState
  onAudition: () => void
  onStopAudition: () => void
  range: { start: number; end: number }
  playhead: () => number | null
}) {
  const a = cfg.audio
  const setAudio = (patch: Partial<EditConfig['audio']>) => set((c) => ({ ...c, audio: { ...c.audio, ...patch } }))
  const [sample, setSample] = useState<{ id: SampleId; ready: boolean; buf?: AudioBuffer; at?: number } | null>(null)
  const sampleSrc = useRef<AudioBufferSourceNode | null>(null)
  const wanted = useRef<SampleId | null>(null)
  const musicMem = useAssetMemory('music')
  const sfxMem = useAssetMemory('sfx')
  const [mq, setMq] = useState('')
  const [mCat, setMCat] = useState<'all' | 'fav' | 'recent' | 'new' | MusicMood | `g:${string}`>('all')
  const [sq, setSq] = useState('')
  const [sCat, setSCat] = useState<string>('all')
  const musicList = useMemo(() => {
    const q = mq.trim().toLowerCase()
    let l = MUSIC
    if (mCat === 'fav') l = musicMem.fav.map((id) => MUSIC.find((m) => m.id === id)).filter((m): m is (typeof MUSIC)[number] => !!m)
    else if (mCat === 'recent') l = musicMem.recent.map((id) => MUSIC.find((m) => m.id === id)).filter((m): m is (typeof MUSIC)[number] => !!m)
    else if (mCat === 'new') l = MUSIC.filter((m) => m.isNew)
    else if (mCat.startsWith('g:')) l = MUSIC.filter((m) => m.group === mCat.slice(2))
    else if (mCat !== 'all') l = MUSIC.filter((m) => MUSIC_MOOD_OF[m.id].includes(mCat as MusicMood))
    if (q) l = l.filter((m) => `${m.name} ${m.hint} ${m.id} ${MUSIC_MOOD_OF[m.id].map((x) => MUSIC_MOODS.find((y) => y.id === x)?.name).join(' ')}`.toLowerCase().includes(q))
    return l
  }, [mq, mCat, musicMem.fav, musicMem.recent])
  const sfxList = useMemo(() => {
    const q = sq.trim().toLowerCase()
    let l = SFX
    if (sCat === 'fav') l = sfxMem.fav.map((id) => SFX.find((x) => x.id === id)).filter((x): x is (typeof SFX)[number] => !!x)
    else if (sCat === 'recent') l = sfxMem.recent.map((id) => SFX.find((x) => x.id === id)).filter((x): x is (typeof SFX)[number] => !!x)
    else if (sCat === 'new') l = SFX.filter((x) => x.isNew)
    else if (sCat !== 'all') l = SFX.filter((x) => x.group === sCat)
    if (q) l = l.filter((x) => `${x.name} ${x.hint} ${x.id}`.toLowerCase().includes(q))
    return l
  }, [sq, sCat, sfxMem.fav, sfxMem.recent])
  const bufferOf = (id: SampleId) => (id.startsWith('sfx:') ? sfxBuffer(id.slice(4) as SfxId) : musicBuffer(id as MusicId))
  async function download(id: SampleId) {
    const buf = await bufferOf(id)
    downloadBlob(wavBlob(buf), fileName(id))
  }
  const here = () => {
    const p = playhead()
    return Math.round(Math.min(range.end, Math.max(range.start, p ?? range.start)) * 10) / 10
  }
  const placeSound = (id: SfxId) => {
    sfxMem.used(id)
    set((c) => ({ ...c, sounds: [...c.sounds, { id, at: here(), vol: 0.8 }].slice(0, LIMITS.sounds) }))
  }
  const updateSound = (i: number, patch: Partial<EditConfig['sounds'][number]>) => set((c) => ({ ...c, sounds: c.sounds.map((x, j) => (j === i ? { ...x, ...patch } : x)) }))

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

  async function playSample(id: SampleId) {
    if (wanted.current === id) return stopSample()
    stopSample()
    onSample()
    wanted.current = id
    setSample({ id, ready: false })
    const ctx = audioContext()
    const buf = await bufferOf(id)
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
    setSample({ id, ready: true, buf, at: ctx.currentTime })
  }

  const playBtn = (id: SampleId, label: string, testId?: string) => {
    const playing = sample?.id === id
    return (
      <button
        type="button"
        onClick={() => playSample(id)}
        className={`shrink-0 flex items-center gap-1 rounded-full px-2.5 py-1 text-[11.5px] font-medium ${playing ? 'bg-brand-600 text-white' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'}`}
        aria-label={`${label} শুনুন`}
        data-testid={testId}
      >
        {playing ? <IconPlayerPause className="w-3.5 h-3.5" /> : <IconPlayerPlay className="w-3.5 h-3.5" />}
        {playing ? (sample?.ready ? 'থামান' : 'তৈরি হচ্ছে…') : 'শুনুন'}
      </button>
    )
  }
  const dlBtn = (id: SampleId, label: string) => (
    <button type="button" onClick={() => download(id)} title="WAV ফাইল নামান" aria-label={`${label} ডাউনলোড`} className="shrink-0 w-7 h-7 rounded-full bg-neutral-100 text-neutral-600 hover:bg-neutral-200 flex items-center justify-center">
      <IconDownload className="w-3.5 h-3.5" />
    </button>
  )
  const wave = (id: SampleId) =>
    sample?.id === id && sample.ready && sample.buf ? <Waveform buf={sample.buf} ctx={audioContext()} startedAt={sample.at ?? 0} /> : null
  const musicRow = (m: { id: MusicId | 'none'; name: string; hint: string; isNew?: boolean }) => {
    const on = a.music === m.id
    const group = m.id === 'none' ? null : MUSIC_GROUPS.find((g) => g.id === MUSIC.find((x) => x.id === m.id)?.group)
    return (
      <div key={m.id} className={`rounded-xl border px-3 py-2 ${on ? 'border-brand-600 bg-brand-50' : 'border-neutral-200 hover:border-neutral-300'}`}>
        <div className="flex items-center gap-2">
          <button
            type="button"
            role="radio"
            aria-checked={on}
            onClick={() => {
              if (m.id !== 'none') musicMem.used(m.id)
              setAudio({ music: m.id })
            }}
            className="flex-1 min-w-0 text-left"
            data-testid={`music-${m.id}`}
          >
            <span className={`block text-[13px] font-semibold ${on ? 'text-brand-700' : 'text-neutral-800'}`}>
              {m.name}
              {m.id === 'signature' && <span className="ml-1.5 text-[10.5px] font-medium text-neutral-400">ডিফল্ট</span>}
              {m.isNew && <span className="ml-1.5 text-[9.5px] font-bold px-1.5 py-0.5 rounded bg-amber-400 text-amber-950">নতুন</span>}
            </span>
            <span className="block text-[11.5px] text-neutral-500 truncate">{m.hint}</span>
            {m.id !== 'none' && (
              <span className="block text-[10.5px] text-neutral-400 mt-0.5">
                {bnNumber(musicBpm(m.id))} BPM · লুপ {secs(musicLength(m.id))}
                {group ? ` · ${group.name}` : ''}
              </span>
            )}
          </button>
          {m.id !== 'none' && <StarButton on={musicMem.isFav(m.id)} onClick={() => musicMem.toggleFav(m.id)} label={m.name} />}
          {m.id !== 'none' && playBtn(m.id, m.name, `music-sample-${m.id}`)}
          {m.id !== 'none' && dlBtn(m.id, m.name)}
        </div>
        {m.id !== 'none' && wave(m.id)}
      </div>
    )
  }

  return (
    <div>
      <Section title="মিউজিক লাইব্রেরি" hint="মীহা নিউজের নিজস্ব ৪০টি সুর — এই অ্যাপের ভেতরেই তৈরি, কারও রেকর্ডিং বা স্যাম্পল নেই। ভিডিও যত বড়ই হোক, পুরোটা জুড়ে নিচু ভলিউমে বারবার বাজবে। প্রতিটি সুর শুরু হয় মীহার চার-নোটের সিগনেচার সুর দিয়ে। ⤓ দিয়ে WAV ফাইল নামানো যায়।">
        <div className="space-y-2 mb-2">
          <SearchBox value={mq} onChange={setMq} placeholder="সুর খুঁজুন — যেমন শোক, খেলা, ব্যবসা…" testId="music-search" />
          <div className="flex gap-1.5 overflow-x-auto pb-0.5">
            <Chip on={mCat === 'all'} onClick={() => setMCat('all')}>
              সব ({bnNumber(MUSIC.length)})
            </Chip>
            <Chip on={mCat === 'new'} onClick={() => setMCat('new')}>
              নতুন
            </Chip>
            <Chip on={mCat === 'fav'} onClick={() => setMCat('fav')} testId="music-fav">
              ★ প্রিয়
            </Chip>
            <Chip on={mCat === 'recent'} onClick={() => setMCat('recent')}>
              সাম্প্রতিক
            </Chip>
          </div>
          <div className="flex gap-1.5 overflow-x-auto pb-0.5" data-testid="music-moods">
            {MUSIC_MOODS.map((md) => (
              <Chip key={md.id} on={mCat === md.id} onClick={() => setMCat(mCat === md.id ? 'all' : md.id)} testId={`music-mood-${md.id}`}>
                {md.name}
              </Chip>
            ))}
          </div>
          <div className="flex gap-1.5 overflow-x-auto pb-0.5">
            {MUSIC_GROUPS.map((g) => (
              <Chip key={g.id} on={mCat === `g:${g.id}`} onClick={() => setMCat(mCat === `g:${g.id}` ? 'all' : `g:${g.id}`)} testId={`music-group-${g.id}`}>
                {g.name}
              </Chip>
            ))}
          </div>
        </div>
        <div className="space-y-1.5 max-h-[460px] overflow-y-auto pr-0.5" role="radiogroup" aria-label="ব্র্যান্ড মিউজিক">
          {mCat === 'all' && !mq && musicRow({ id: 'none', name: 'মিউজিক নেই', hint: 'শুধু ভিডিওর নিজের শব্দ' })}
          {musicList.map((m) => musicRow(m))}
          {musicList.length === 0 && <p className="text-[12px] text-neutral-500 text-center py-3">{mCat === 'fav' ? 'এখনও কোনো সুর প্রিয়তে নেই — ☆ চাপুন।' : 'কিছু পাওয়া যায়নি।'}</p>}
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

      <Section title="সাউন্ড লাইব্রেরি" hint="প্রিভিউ থামিয়ে যে মুহূর্তে শব্দ চান সেখানে “+ বসান” দিন। সব শব্দ মিহার নিজস্ব, অ্যাপেই তৈরি।">
        <div className="space-y-2 mb-2">
          <SearchBox value={sq} onChange={setSq} placeholder="শব্দ খুঁজুন — হুশ, ঘড়ি, ভিড়…" testId="sfx-search" />
          <div className="flex gap-1.5 overflow-x-auto pb-0.5">
            <Chip on={sCat === 'all'} onClick={() => setSCat('all')}>
              সব ({bnNumber(SFX.length)})
            </Chip>
            <Chip on={sCat === 'new'} onClick={() => setSCat('new')}>
              নতুন
            </Chip>
            <Chip on={sCat === 'fav'} onClick={() => setSCat('fav')} testId="sfx-fav">
              ★ প্রিয়
            </Chip>
            <Chip on={sCat === 'recent'} onClick={() => setSCat('recent')}>
              সাম্প্রতিক
            </Chip>
            {SFX_GROUPS.map((g) => (
              <Chip key={g.id} on={sCat === g.id} onClick={() => setSCat(sCat === g.id ? 'all' : g.id)} testId={`sfx-group-${g.id}`}>
                {g.name}
              </Chip>
            ))}
          </div>
        </div>
        <div className="space-y-1.5 max-h-[420px] overflow-y-auto pr-0.5">
          {sfxList.map((x) => (
            <div key={x.id} className="rounded-xl border border-neutral-200 px-3 py-2">
              <div className="flex items-center gap-2">
                <div className="flex-1 min-w-0">
                  <p className="text-[13px] font-semibold text-neutral-800">
                    {x.name}
                    {x.isNew && <span className="ml-1.5 text-[9.5px] font-bold px-1.5 py-0.5 rounded bg-amber-400 text-amber-950">নতুন</span>}
                  </p>
                  <p className="text-[11.5px] text-neutral-500 truncate">{x.hint}</p>
                  <p className="text-[10.5px] text-neutral-400">
                    {bnNumber(x.len.toFixed(1))} সে. · {SFX_GROUPS.find((g) => g.id === x.group)?.name}
                  </p>
                </div>
                <StarButton on={sfxMem.isFav(x.id)} onClick={() => sfxMem.toggleFav(x.id)} label={x.name} />
                {playBtn(`sfx:${x.id}`, x.name, `sfx-sample-${x.id}`)}
                <button
                  type="button"
                  onClick={() => placeSound(x.id)}
                  disabled={cfg.sounds.length >= LIMITS.sounds}
                  data-testid={`sfx-place-${x.id}`}
                  className="shrink-0 flex items-center gap-0.5 rounded-full px-2.5 py-1 text-[11.5px] font-semibold bg-brand-600 text-white disabled:opacity-40"
                >
                  <IconPlus className="w-3.5 h-3.5" />
                  বসান
                </button>
                {dlBtn(`sfx:${x.id}`, x.name)}
              </div>
              {wave(`sfx:${x.id}`)}
            </div>
          ))}
          {sfxList.length === 0 && <p className="text-[12px] text-neutral-500 text-center py-3">{sCat === 'fav' ? 'এখনও কোনো শব্দ প্রিয়তে নেই — ☆ চাপুন।' : 'কিছু পাওয়া যায়নি।'}</p>}
        </div>
        {cfg.sounds.length > 0 && (
          <div className="mt-3 flex flex-col gap-2">
            <p className="text-[12px] font-semibold text-neutral-700">ভিডিওতে বসানো শব্দ ({bnNumber(cfg.sounds.length)})</p>
            {cfg.sounds
              .map((x, i) => ({ x, i }))
              .sort((p, q) => p.x.at - q.x.at)
              .map(({ x, i }) => (
                <div key={i} className="rounded-xl border border-neutral-200 p-2.5" data-testid="sound-row">
                  <div className="flex items-end gap-3 flex-wrap">
                    <div className="min-w-[110px]">
                      <span className="block text-[11.5px] text-neutral-500 mb-1">শব্দ</span>
                      <span className="text-[12.5px] font-semibold text-neutral-800">{SFX.find((y) => y.id === x.id)?.name}</span>
                    </div>
                    <TimeField label="মূল ভিডিওর কোন সময়ে" value={x.at} min={range.start} max={range.end} onChange={(v) => updateSound(i, { at: v })} onPlayhead={() => updateSound(i, { at: here() })} />
                    <button type="button" onClick={() => set((c) => ({ ...c, sounds: c.sounds.filter((_, j) => j !== i) }))} aria-label="সরান" className="ml-auto mb-1.5 text-red-500 hover:text-red-700">
                      <IconTrash className="w-4 h-4" />
                    </button>
                  </div>
                  <Slider label="ভলিউম" value={Math.round(x.vol * 100)} min={10} max={150} step={10} onChange={(v) => updateSound(i, { vol: v / 100 })} format={(v) => pct(v / 100)} />
                </div>
              ))}
            <p className="text-[11px] text-neutral-400">সময় মূল ভিডিওর হিসাবে — কাটা অংশে পড়লে শব্দটি বাজবে না। এখন: {bnClock(here())}</p>
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
        <p className="text-[11.5px] text-neutral-500 mt-3 mb-1">ব্যাকগ্রাউন্ড মিউজিক কমানো</p>
        <Segmented
          value={a.unmusic}
          options={[
            { id: 'off', name: 'বন্ধ' },
            { id: 'low', name: 'হালকা' },
            { id: 'medium', name: 'মাঝারি' },
            { id: 'high', name: 'বেশি' },
          ]}
          onChange={(v) => setAudio({ unmusic: v })}
          size="sm"
        />
        <p className="text-[11px] text-neutral-400 mt-1">
          মূল ভিডিওতে কথার নিচে গান বা মিউজিক থাকলে সেটা পেছনে সরিয়ে কণ্ঠ সামনে আনে (টানা সুর, ড্রাম, বেস ও চওড়া স্টেরিও শব্দ কমায়)। পুরোপুরি মুছে ফেলা সম্ভব নয় — গানের কণ্ঠ থেকে যায়, আর “বেশি” দিলে কথায় হালকা জলজ আওয়াজ আসতে পারে। “শুনে দেখুন” দিয়ে মিলিয়ে নিন। নিজের ব্র্যান্ড মিউজিক এর পরে যোগ হয়, তাই সেটা কমে না।
        </p>
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
