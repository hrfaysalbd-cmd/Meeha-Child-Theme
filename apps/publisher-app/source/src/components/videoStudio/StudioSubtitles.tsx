/**
 * Round 29 — subtitles: from an .srt / .vtt file, pasted SRT, or plain
 * text spread over the video; then fixed line by line. Many people watch
 * news reels with the sound off, so a line on screen keeps them watching.
 */
import { useRef, useState } from 'react'
import { LIMITS, SUB_STYLES, type EditConfig, type SubLine } from '../../lib/videoEdit/config'
import { looksTimed, parseTimed, spreadPlain, toSrt } from '../../lib/videoEdit/subtitles'
import { bnClock } from '../../lib/videoEdit/motion'
import { bnNumber } from '../ui'
import { IconPlus, IconTrash, IconUpload } from '../Icon'
import { Section, Segmented, Slider, SwitchRow, TimeField } from './StudioFields'
import type { Setter } from './StudioPanels'

const SHOW = 40

export default function SubtitlesSection({
  cfg,
  set,
  range,
  playhead,
}: {
  cfg: EditConfig
  set: Setter
  range: { start: number; end: number }
  playhead: () => number | null
}) {
  const st = cfg.subtitles
  const patch = (p: Partial<EditConfig['subtitles']>) => set((c) => ({ ...c, subtitles: { ...c.subtitles, ...p } }))
  const setItems = (f: (items: SubLine[]) => SubLine[]) =>
    set((c) => ({ ...c, subtitles: { ...c.subtitles, items: f(c.subtitles.items).sort((a, b) => a.at - b.at).slice(0, LIMITS.subtitles) } }))
  const [text, setText] = useState('')
  const [note, setNote] = useState('')
  const [all, setAll] = useState(false)
  const file = useRef<HTMLInputElement>(null)
  const here = () => Math.round((playhead() ?? range.start) * 10) / 10

  const load = (raw: string, from: 'file' | 'paste') => {
    let lines: SubLine[]
    if (looksTimed(raw)) lines = parseTimed(raw, LIMITS.subtitleText)
    else {
      // plain text: from the playhead (or the start) to the end of the news
      const a = playhead() ?? range.start
      lines = spreadPlain(raw, a > range.start + 0.5 && a < range.end - 2 ? a : range.start, range.end, LIMITS.subtitleText)
    }
    if (!lines.length) {
      setNote(from === 'file' ? 'ফাইলে কোনো সাবটাইটেল পাওয়া যায়নি।' : 'কোনো লেখা পাওয়া যায়নি।')
      return
    }
    setItems(() => lines)
    patch({ on: true })
    setNote(`${bnNumber(lines.length)}টি লাইন বসানো হয়েছে${looksTimed(raw) ? '' : ' — লেখার দৈর্ঘ্য অনুযায়ী সময় ভাগ করা হয়েছে, নিচে ঠিক করে নিন'}।`)
    setText('')
  }

  const download = () => {
    const blob = new Blob([toSrt(st.items)], { type: 'application/x-subrip' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'meeha-subtitles.srt'
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 30_000)
  }

  const shown = all ? st.items : st.items.slice(0, SHOW)
  return (
    <Section title="সাবটাইটেল" hint="অনেকে শব্দ বন্ধ রেখে রিল দেখেন — লেখা থাকলে শেষ পর্যন্ত দেখেন। SRT ফাইল দিন, অথবা লেখা পেস্ট করুন।">
      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          onClick={() => file.current?.click()}
          className="flex items-center gap-1.5 rounded-full border border-neutral-300 px-3.5 py-1.5 text-[12.5px] font-semibold text-neutral-700"
          data-testid="sub-file"
        >
          <IconUpload className="w-4 h-4" />
          SRT / VTT ফাইল
        </button>
        <button
          type="button"
          onClick={() => {
            const at = here()
            setItems((items) => [...items, { at, end: Math.min(range.end, at + 2.5), text: 'নতুন লাইন' }])
            patch({ on: true })
          }}
          className="flex items-center gap-1.5 rounded-full border border-neutral-300 px-3.5 py-1.5 text-[12.5px] font-semibold text-neutral-700"
          data-testid="sub-add"
        >
          <IconPlus className="w-4 h-4" />
          এখানে একটি লাইন
        </button>
        {st.items.length > 0 && (
          <button type="button" onClick={download} className="rounded-full bg-neutral-100 px-3.5 py-1.5 text-[12.5px] font-semibold text-neutral-700">
            ⤓ SRT ডাউনলোড
          </button>
        )}
        <input
          ref={file}
          type="file"
          accept=".srt,.vtt,.txt,text/plain"
          className="hidden"
          onChange={async (e) => {
            const f = e.target.files?.[0]
            e.target.value = ''
            if (f) load(await f.text(), 'file')
          }}
        />
      </div>
      <textarea
        value={text}
        onChange={(e) => setText(e.target.value)}
        rows={3}
        placeholder={'লেখা পেস্ট করুন — প্রতি লাইনে একটি সাবটাইটেল।\nSRT হলে সময়সহ বসবে।'}
        className="mt-2 w-full rounded-lg border border-neutral-200 px-2.5 py-2 text-[13px] focus:outline-none focus:ring-2 focus:ring-brand-600/20"
        data-testid="sub-text"
      />
      {text.trim() && (
        <button type="button" onClick={() => load(text, 'paste')} className="mt-1 rounded-lg bg-brand-600 text-white px-3 py-1.5 text-[12.5px] font-semibold" data-testid="sub-apply">
          {st.items.length ? 'আগেরগুলোর বদলে বসান' : 'সাবটাইটেল বসান'}
        </button>
      )}
      {note && <p className="text-[11.5px] text-neutral-500 mt-1.5">{note}</p>}

      {st.items.length > 0 && (
        <>
          <div className="mt-3">
            <SwitchRow label="সাবটাইটেল দেখান" checked={st.on} onChange={(v) => patch({ on: v })} testId="sub-on" />
            <Segmented value={st.style} options={SUB_STYLES.map((x) => ({ id: x.id, name: x.name }))} onChange={(v) => patch({ style: v })} size="sm" />
            <Slider label="লেখার আকার" value={Math.round(st.size * 100)} min={70} max={160} step={5} onChange={(v) => patch({ size: v / 100 })} format={(v) => `${bnNumber(v)}%`} />
            <Slider
              label="সময় মেলানো (সব লাইন আগে/পরে)"
              value={Math.round(st.shift * 10)}
              min={-100}
              max={100}
              step={1}
              onChange={(v) => patch({ shift: v / 10 })}
              format={(v) => (v === 0 ? 'ঠিক আছে' : `${v > 0 ? '+' : '−'}${bnNumber(Math.abs(v / 10).toFixed(1))} সে.`)}
            />
          </div>
          <div className="mt-2 flex items-center justify-between">
            <p className="text-[12px] font-semibold text-neutral-700">
              {bnNumber(st.items.length)}টি লাইন
            </p>
            <button type="button" onClick={() => setItems(() => [])} className="text-[11.5px] text-red-600 underline">
              সব মুছুন
            </button>
          </div>
          <div className="mt-1 flex flex-col gap-1.5" data-testid="sub-list">
            {shown.map((x, i) => (
              <div key={`${i}-${x.at}`} className="rounded-lg border border-neutral-200 p-2">
                <div className="flex items-end gap-2 flex-wrap">
                  <TimeField label="শুরু" value={x.at} min={0} max={range.end} onChange={(v) => setItems((items) => items.map((y, j) => (j === i ? { ...y, at: v, end: Math.max(y.end, v + 0.3) } : y)))} onPlayhead={() => setItems((items) => items.map((y, j) => (j === i ? { ...y, at: here(), end: Math.max(y.end, here() + 0.3) } : y)))} />
                  <TimeField label="শেষ" value={x.end} min={x.at + 0.3} max={range.end + 5} onChange={(v) => setItems((items) => items.map((y, j) => (j === i ? { ...y, end: v } : y)))} onPlayhead={() => setItems((items) => items.map((y, j) => (j === i ? { ...y, end: Math.max(y.at + 0.3, here()) } : y)))} />
                  <button type="button" aria-label="সরান" onClick={() => setItems((items) => items.filter((_, j) => j !== i))} className="ml-auto mb-1.5 text-red-500">
                    <IconTrash className="w-4 h-4" />
                  </button>
                </div>
                <input
                  value={x.text}
                  maxLength={LIMITS.subtitleText}
                  onChange={(e) => setItems((items) => items.map((y, j) => (j === i ? { ...y, text: e.target.value } : y)))}
                  className="mt-1.5 w-full rounded-md border border-neutral-200 px-2 py-1 text-[13px]"
                />
              </div>
            ))}
          </div>
          {st.items.length > SHOW && (
            <button type="button" onClick={() => setAll((v) => !v)} className="mt-1.5 text-[12px] text-brand-700 underline">
              {all ? 'কম দেখান' : `সবগুলো দেখান (আরও ${bnNumber(st.items.length - SHOW)}টি)`}
            </button>
          )}
          <p className="text-[11px] text-neutral-400 mt-1.5">সময় মূল ভিডিওর হিসাবে, এখন {bnClock(here())}। ক্যাপশন থাকলে সাবটাইটেল নিজে থেকে একটু উপরে সরে।</p>
        </>
      )}
    </Section>
  )
}
