/**
 * Round 25 — ads on a card: a 1080 × 120 strip at the bottom, at the top, or
 * both. Each strip is picked from one gallery with three shelves — মিহার
 * নিজস্ব বিজ্ঞাপন (drawn by the renderer: social media, শিক্ষা ও ক্যাম্পাস,
 * website, YouTube, WhatsApp), the ad gallery (images, GIFs and videos kept
 * as ads in the media library — the video editor uses the same gallery), and
 * the theme's own card strips.
 */
import { useEffect, useRef, useState } from 'react'
import { adThumb } from '../../lib/photocard/houseAds'
import { PhotocardApi, describeError, type CardAdItem } from '../../lib/api'
import type { MeehaCardsApi } from '../../lib/photocard/cards'
import type { AdChoice, AdPos } from '../../lib/photocard/style'
import { bnNumber, inputClass } from '../ui'
import { IconAd, IconPlus, IconSearch, IconTrash, IconUpload, IconVideo, IconX } from '../Icon'

export default function AdPanel({
  on,
  onToggle,
  why,
  pos,
  onPos,
  bottom,
  top,
  onOpen,
  house,
  storyCta,
  onStoryCta,
  story,
}: {
  on: boolean
  onToggle: (v: boolean) => void
  why: string
  pos: AdPos
  onPos: (p: AdPos) => void
  bottom: AdChoice | null
  top: AdChoice | null
  onOpen: (slot: 'bottom' | 'top') => void
  house: Record<string, string>
  storyCta: string
  onStoryCta: (v: string) => void
  story: boolean
}) {
  const live = on && !why
  const slot = (s: 'bottom' | 'top', c: AdChoice | null) => (
    <div className="rounded-xl border border-neutral-200 bg-white p-2">
      <div className="flex items-center gap-2 mb-1.5">
        <b className="text-[12px] text-neutral-700">{s === 'bottom' ? 'নিচের বিজ্ঞাপন' : 'ওপরের বিজ্ঞাপন'}</b>
        <span className="text-[11px] text-neutral-400 truncate">{c ? c.label : 'বাছা হয়নি'}</span>
        <button type="button" onClick={() => onOpen(s)} className="ml-auto shrink-0 inline-flex items-center gap-1 rounded-lg bg-brand-600 text-white text-[11.5px] font-semibold px-2.5 py-1">
          <IconAd className="w-3.5 h-3.5" /> গ্যালারি থেকে বাছুন
        </button>
      </div>
      <button type="button" onClick={() => onOpen(s)} className="block w-full rounded-md overflow-hidden bg-neutral-100 ring-1 ring-black/5" style={{ aspectRatio: '1080 / 120' }} aria-label={`${s === 'bottom' ? 'নিচের' : 'ওপরের'} বিজ্ঞাপন বদলান`}>
        {adThumb(c, house) ? <img src={adThumb(c, house)} alt="" className="w-full h-full object-cover" /> : <span className="text-[11px] text-neutral-400">বিজ্ঞাপন বাছুন</span>}
      </button>
    </div>
  )
  return (
    <div className="space-y-3">
      <label className={`flex items-center gap-2 text-[13px] ${why ? 'text-neutral-400' : 'text-neutral-700'}`}>
        <input type="checkbox" disabled={!!why} checked={live} onChange={(e) => onToggle(e.target.checked)} />
        কার্ডে বিজ্ঞাপন স্ট্রিপ (১০৮০ × ১২০)
      </label>
      {why && <p className="text-[11.5px] text-neutral-400 -mt-2">{why}</p>}
      {live && (
        <>
          <div className="grid grid-cols-3 gap-1.5" role="radiogroup" aria-label="বিজ্ঞাপনের জায়গা">
            {(
              [
                ['bottom', 'নিচে', 'ছবির নিচে'],
                ['top', 'ওপরে', 'শিরোনামের ওপরে'],
                ['both', 'ওপরে ও নিচে', 'দুটো আলাদা'],
              ] as [AdPos, string, string][]
            ).map(([v, l, h]) => (
              <button key={v} type="button" role="radio" aria-checked={pos === v} onClick={() => onPos(v)} className={`rounded-lg border px-2 py-1.5 text-center ${pos === v ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-neutral-200 text-neutral-600'}`}>
                <span className="mx-auto mb-1 block w-7 h-8 rounded-[3px] border-2 border-current relative overflow-hidden" aria-hidden="true">
                  {(v === 'top' || v === 'both') && <i className="absolute inset-x-0 top-0 h-1.5 bg-current" />}
                  {(v === 'bottom' || v === 'both') && <i className="absolute inset-x-0 bottom-0 h-1.5 bg-current" />}
                </span>
                <b className="block text-[12px]">{l}</b>
                <small className="block text-[10px] opacity-70">{h}</small>
              </button>
            ))}
          </div>
          {(pos === 'top' || pos === 'both') && slot('top', top)}
          {(pos === 'bottom' || pos === 'both') && slot('bottom', bottom)}
          <p className="text-[11px] text-neutral-400">নিজের ফ্রেমের দিবস/জন্মদিন/উক্তি কার্ডে বিজ্ঞাপন সবসময় নিচে বসে।</p>
        </>
      )}
      {story && (
        <label className="block">
          <span className="block text-[12.5px] font-medium text-neutral-600 mb-1">স্টোরির নিচের লেখা</span>
          <input className={inputClass} maxLength={60} value={storyCta} onChange={(e) => onStoryCta(e.target.value)} />
        </label>
      )}
    </div>
  )
}

type Tab = 'house' | 'lib' | 'theme'

export function AdGallery({
  slot,
  MC,
  house,
  themeAds,
  current,
  onPick,
  onClose,
}: {
  slot: 'bottom' | 'top'
  MC: MeehaCardsApi
  house: Record<string, string>
  themeAds: { id: string; label: string; url: string }[]
  current: AdChoice | null
  onPick: (c: AdChoice) => void
  onClose: () => void
}) {
  const [tab, setTab] = useState<Tab>(current?.kind || 'house')
  const [items, setItems] = useState<CardAdItem[]>([])
  const [more, setMore] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [q, setQ] = useState('')
  const [loaded, setLoaded] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)

  async function load(offset: number, search = q) {
    setBusy(true)
    setErr('')
    try {
      const r = await PhotocardApi.ads(offset, search)
      setItems((prev) => (offset ? [...prev, ...r.items] : r.items))
      setMore(r.has_more)
      setLoaded(true)
    } catch (e) {
      setErr(describeError(e).message)
    } finally {
      setBusy(false)
    }
  }
  useEffect(() => {
    if (tab === 'lib' && !loaded) load(0)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab])
  useEffect(() => {
    const k = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', k)
    return () => window.removeEventListener('keydown', k)
  }, [onClose])

  async function upload(f: File) {
    setBusy(true)
    setErr('')
    try {
      const it = await PhotocardApi.uploadAd(f, f.name.replace(/\.[a-z0-9]+$/i, ''))
      setItems((prev) => [it, ...prev])
      pickLib(it)
    } catch (e) {
      setErr(describeError(e).message)
    } finally {
      setBusy(false)
    }
  }
  async function remove(it: CardAdItem) {
    if (!window.confirm(`“${it.title}” বিজ্ঞাপন গ্যালারি থেকে সরাবেন? (ফাইলটি মিডিয়া লাইব্রেরিতে থেকে যাবে)`)) return
    try {
      await PhotocardApi.markAd(it.id, false)
      setItems((prev) => prev.filter((x) => x.id !== it.id))
    } catch (e) {
      setErr(describeError(e).message)
    }
  }
  function pickLib(it: CardAdItem) {
    onPick({ kind: 'lib', id: it.id, label: it.title || 'বিজ্ঞাপন', image: it.url, thumb: it.thumb || (it.kind === 'video' ? '' : it.url), mediaKind: it.kind })
  }

  const tabs: [Tab, string][] = [
    ['house', 'মিহার নিজস্ব'],
    ['lib', 'বিজ্ঞাপন গ্যালারি'],
    ['theme', 'থিমের স্ট্রিপ'],
  ]
  const isOn = (c: AdChoice) => !!current && current.kind === c.kind && JSON.stringify(current) === JSON.stringify(c)

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/45 sm:px-4" role="dialog" aria-modal="true" aria-label="বিজ্ঞাপন গ্যালারি">
      <div className="bg-white w-full sm:max-w-3xl max-h-[90vh] rounded-t-2xl sm:rounded-2xl shadow-xl flex flex-col">
        <div className="flex items-center gap-2 px-4 py-3 border-b border-neutral-100">
          <h2 className="text-[15px] font-semibold text-neutral-900">বিজ্ঞাপন গ্যালারি — {slot === 'bottom' ? 'নিচের' : 'ওপরের'} স্ট্রিপ</h2>
          <button type="button" onClick={onClose} className="ml-auto text-neutral-400 hover:text-neutral-700" aria-label="বন্ধ">
            <IconX className="w-5 h-5" />
          </button>
        </div>
        <div className="flex gap-1 px-4 pt-3" role="tablist">
          {tabs.map(([t, l]) => (
            <button key={t} type="button" role="tab" aria-selected={tab === t} onClick={() => setTab(t)} className={`px-3 py-1.5 rounded-lg text-[13px] font-medium ${tab === t ? 'bg-brand-600 text-white' : 'text-neutral-600 hover:bg-neutral-100'}`}>
              {l}
            </button>
          ))}
        </div>
        <div className="p-4 overflow-y-auto">
          {tab === 'house' && (
            <>
              <p className="text-[12px] text-neutral-500 mb-2">মিহা নিউজের নিজের প্রচার — সাইটের সোশ্যাল লিংক ও ক্যাম্পাস সেটিংস থেকে নিজে তৈরি হয়।</p>
              <ul className="grid sm:grid-cols-2 gap-2">
                {Object.entries(MC.houseAds || {}).map(([k, l]) => {
                  const c: AdChoice = { kind: 'house', key: k, label: l }
                  return (
                    <li key={k}>
                      <button type="button" onClick={() => onPick(c)} className={`w-full text-left rounded-xl border-2 overflow-hidden ${isOn(c) ? 'border-brand-600' : 'border-neutral-200 hover:border-neutral-300'}`}>
                        <span className="block bg-neutral-100" style={{ aspectRatio: '1080 / 120' }}>
                          {house[k] && <img src={house[k]} alt="" className="w-full h-full object-cover" />}
                        </span>
                        <span className="block px-2 py-1 text-[12px] font-medium text-neutral-700">{l}</span>
                      </button>
                    </li>
                  )
                })}
              </ul>
            </>
          )}

          {tab === 'lib' && (
            <>
              <div className="flex gap-2 mb-3">
                <form
                  className="flex-1 flex gap-1.5"
                  onSubmit={(e) => {
                    e.preventDefault()
                    load(0)
                  }}
                >
                  <input className={`${inputClass} !py-1.5`} value={q} onChange={(e) => setQ(e.target.value)} placeholder="বিজ্ঞাপন খুঁজুন" aria-label="বিজ্ঞাপন খুঁজুন" />
                  <button type="submit" className="shrink-0 rounded-lg border border-neutral-200 px-2.5" aria-label="খুঁজুন">
                    <IconSearch className="w-4 h-4" />
                  </button>
                </form>
                <button type="button" disabled={busy} onClick={() => fileRef.current?.click()} className="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-neutral-900 text-white text-[12.5px] font-medium px-3 disabled:opacity-60">
                  <IconUpload className="w-4 h-4" /> আপলোড
                </button>
                <input
                  ref={fileRef}
                  type="file"
                  hidden
                  accept="image/png,image/jpeg,image/webp,image/gif,video/mp4,video/webm,video/quicktime"
                  onChange={(e) => {
                    const f = e.target.files?.[0]
                    if (f) upload(f)
                    e.target.value = ''
                  }}
                />
              </div>
              <p className="text-[11.5px] text-neutral-500 mb-2">
                ছবি, GIF বা ভিডিও — শুধু বিজ্ঞাপন হিসেবে চিহ্নিত ফাইল এখানে থাকে (ভিডিও এডিটরেও এগুলোই আসে)। সবচেয়ে ভালো মাপ ১০৮০ × ১২০; অন্য মাপ হলে মাঝখান থেকে কেটে বসবে। ভিডিও/GIF কার্ডে স্থির ছবি হয়ে বসে।
              </p>
              {err && <p className="text-[12.5px] text-red-600 mb-2">{err}</p>}
              {loaded && !items.length && !busy && (
                <div className="text-center py-8 text-neutral-500 text-[13px]">
                  <IconPlus className="w-6 h-6 mx-auto mb-1 text-neutral-400" />
                  গ্যালারিতে এখনো কোনো বিজ্ঞাপন নেই — ওপরের “আপলোড” দিয়ে যোগ করুন।
                </div>
              )}
              <ul className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                {items.map((it) => {
                  const on = current?.kind === 'lib' && current.id === it.id
                  return (
                    <li key={it.id} className="relative">
                      <button type="button" onClick={() => pickLib(it)} className={`w-full text-left rounded-xl border-2 overflow-hidden ${on ? 'border-brand-600' : 'border-neutral-200 hover:border-neutral-300'}`}>
                        <span className="block bg-neutral-100 aspect-[3/1] relative">
                          {it.thumb || it.kind !== 'video' ? <img src={it.thumb || it.url} alt="" className="w-full h-full object-cover" loading="lazy" /> : <IconVideo className="w-7 h-7 absolute inset-0 m-auto text-neutral-400" />}
                          <span className="absolute left-1 top-1 text-[9.5px] font-bold px-1.5 py-0.5 rounded bg-neutral-900/70 text-white uppercase">{it.kind === 'gif' ? 'GIF' : it.kind === 'video' ? 'ভিডিও' : 'ছবি'}</span>
                        </span>
                        <span className="block px-2 py-1 text-[11.5px] text-neutral-700 truncate">{it.title || `#${bnNumber(it.id)}`}</span>
                      </button>
                      <button type="button" onClick={() => remove(it)} className="absolute right-1 top-1 w-6 h-6 rounded-full bg-white/95 text-red-600 shadow flex items-center justify-center" aria-label={`গ্যালারি থেকে সরান: ${it.title}`} title="গ্যালারি থেকে সরান">
                        <IconTrash className="w-3.5 h-3.5" />
                      </button>
                    </li>
                  )
                })}
              </ul>
              {busy && <p className="text-center text-[12px] text-neutral-500 py-3">লোড হচ্ছে…</p>}
              {more && !busy && (
                <button type="button" onClick={() => load(items.length)} className="mt-3 w-full rounded-lg border border-neutral-200 py-2 text-[12.5px] text-neutral-700">
                  আরও দেখুন
                </button>
              )}
            </>
          )}

          {tab === 'theme' && (
            <>
              <p className="text-[12px] text-neutral-500 mb-2">মিহা থিম › বিজ্ঞাপন (card_strip) থেকে সেট করা স্ট্রিপ।</p>
              {!themeAds.length && <p className="text-[12.5px] text-neutral-400 py-6 text-center">থিমে কোনো কার্ড স্ট্রিপ সেট করা নেই।</p>}
              <ul className="grid sm:grid-cols-2 gap-2">
                {themeAds.map((a) => {
                  const c: AdChoice = { kind: 'theme', id: a.id, label: a.label, url: a.url }
                  return (
                    <li key={a.id}>
                      <button type="button" onClick={() => onPick(c)} className={`w-full text-left rounded-xl border-2 overflow-hidden ${isOn(c) ? 'border-brand-600' : 'border-neutral-200 hover:border-neutral-300'}`}>
                        <span className="block bg-neutral-100" style={{ aspectRatio: '1080 / 120' }}>
                          <img src={a.url} alt="" className="w-full h-full object-cover" />
                        </span>
                        <span className="block px-2 py-1 text-[12px] font-medium text-neutral-700">{a.label}</span>
                      </button>
                    </li>
                  )
                })}
              </ul>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
