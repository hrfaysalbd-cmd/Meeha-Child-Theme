/**
 * Round 21 — Settings: the newsroom's "ভিডিও এডিটর কিট" — the logo, intro and
 * outro the video editor uses by default, plus the website line, default
 * ticker and ad text. Empty slots fall back to the built-in Meeha assets.
 * Publisher edits, everyone else sees it read-only.
 */
import { useEffect, useState } from 'react'
import { VideoKitApi, describeError, type VideoKit, type VideoKitInput, type VideoKitRef } from '../../lib/api'
import { bnDuration } from '../../lib/postMeta'
import { Banner, Button, Card, Spinner, inputClass } from '../ui'
import { IconMovie, IconX } from '../Icon'
import MediaGallery from '../MediaGallery'
import VideoLibraryPicker from './VideoLibraryPicker'

type Slot = 'logo' | 'intro' | 'outro'

const SLOTS: { id: Slot; label: string; fallback: string }[] = [
  { id: 'logo', label: 'লোগো (PNG, স্বচ্ছ ব্যাকগ্রাউন্ড)', fallback: 'বিল্ট-ইন মীহা লোগো' },
  { id: 'intro', label: 'নিজস্ব ইন্ট্রো ভিডিও', fallback: 'বিল্ট-ইন অ্যানিমেটেড লোগো ইন্ট্রো' },
  { id: 'outro', label: 'নিজস্ব আউট্রো ভিডিও', fallback: 'বিল্ট-ইন সোশ্যাল আউট্রো' },
]

export default function VideoKitCard({ canEdit }: { canEdit: boolean }) {
  const [kit, setKit] = useState<VideoKit | null>(null)
  const [refs, setRefs] = useState<Record<Slot, VideoKitRef | null>>({ logo: null, intro: null, outro: null })
  const [website, setWebsite] = useState('')
  const [ticker, setTicker] = useState('')
  const [adText, setAdText] = useState('')
  const [picking, setPicking] = useState<Slot | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  function sync(k: VideoKit) {
    setKit(k)
    setRefs({ logo: k.logo, intro: k.intro, outro: k.outro })
    setWebsite(k.website)
    setTicker(k.ticker)
    setAdText(k.ad_text)
  }

  useEffect(() => {
    VideoKitApi.get()
      .then(sync)
      .catch((err) => setError(describeError(err).message))
  }, [])

  async function save() {
    setSaving(true)
    setError('')
    setNotice('')
    try {
      const data: VideoKitInput = {
        logo_id: refs.logo?.id ?? 0,
        intro_id: refs.intro?.id ?? 0,
        outro_id: refs.outro?.id ?? 0,
        website: website.trim(),
        ticker: ticker.trim(),
        ad_text: adText.trim(),
      }
      sync(await VideoKitApi.save(data))
      setNotice('ভিডিও কিট সংরক্ষিত হয়েছে।')
    } catch (err) {
      setError(describeError(err).message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card className="p-5 mb-5 max-w-xl">
      <p className="text-[14px] font-semibold text-neutral-800 mb-1 flex items-center gap-1.5">
        <IconMovie className="w-4 h-4 text-brand-600" />
        ভিডিও এডিটর কিট
      </p>
      <p className="text-[12.5px] text-neutral-500 mb-4">
        ভিডিও এডিটরের লোগো, ইন্ট্রো ও আউট্রো এবং ফ্রেমের ডিফল্ট লেখা। খালি রাখলে মীহার বিল্ট-ইন ডিজাইন ব্যবহার হবে।
      </p>
      {error && <Banner kind="error">{error}</Banner>}
      {notice && <Banner kind="success">{notice}</Banner>}
      {!kit ? (
        !error && <Spinner />
      ) : (
        <>
          <div className="space-y-2.5 mb-4">
            {SLOTS.map((slot) => {
              const ref = refs[slot.id]
              return (
                <div key={slot.id} className="flex items-center gap-3 rounded-xl border border-neutral-200 p-2.5" data-testid={`kit-${slot.id}`}>
                  <div className="w-16 h-10 rounded-lg bg-neutral-900 overflow-hidden shrink-0 flex items-center justify-center">
                    {ref ? (
                      slot.id === 'logo' ? (
                        <img src={ref.url} alt="" className="max-w-full max-h-full object-contain" />
                      ) : (
                        <video src={`${ref.url}#t=1`} preload="metadata" muted playsInline className="w-full h-full object-cover" />
                      )
                    ) : (
                      <img src={`${import.meta.env.BASE_URL}video-kit/meeha-logo.png`} alt="" className="max-w-[80%] max-h-[70%] object-contain opacity-80" />
                    )}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="text-[12.5px] font-medium text-neutral-800">{slot.label}</p>
                    <p className="text-[11.5px] text-neutral-500 truncate">
                      {ref ? `${ref.title || `#${ref.id}`}${ref.duration ? ` · ${bnDuration(Math.round(ref.duration))}` : ''}` : slot.fallback}
                    </p>
                  </div>
                  {canEdit && (
                    <div className="flex items-center gap-1.5 shrink-0">
                      <Button size="sm" variant="secondary" onClick={() => setPicking(slot.id)}>
                        {ref ? 'বদলান' : 'বেছে নিন'}
                      </Button>
                      {ref && (
                        <button
                          type="button"
                          onClick={() => setRefs((r) => ({ ...r, [slot.id]: null }))}
                          className="p-1.5 text-neutral-400 hover:text-red-600"
                          aria-label="সরান — বিল্ট-ইন ব্যবহার হবে"
                          title="সরান — বিল্ট-ইন ব্যবহার হবে"
                        >
                          <IconX className="w-4 h-4" />
                        </button>
                      )}
                    </div>
                  )}
                </div>
              )
            })}
          </div>

          <label className="block mb-3">
            <span className="block text-[12.5px] font-medium text-neutral-600 mb-1">ওয়েবসাইট (ফ্রেমের ফুটারে দেখাবে)</span>
            <input className={inputClass} value={website} maxLength={60} onChange={(e) => setWebsite(e.target.value)} disabled={!canEdit} dir="ltr" placeholder="meeha.news" />
          </label>
          <label className="block mb-3">
            <span className="block text-[12.5px] font-medium text-neutral-600 mb-1">ডিফল্ট টিকার (চলমান লেখা)</span>
            <input className={inputClass} value={ticker} maxLength={240} onChange={(e) => setTicker(e.target.value)} disabled={!canEdit} placeholder="খালি রাখলে ভিডিওর শিরোনাম চলবে" />
          </label>
          <label className="block mb-4">
            <span className="block text-[12.5px] font-medium text-neutral-600 mb-1">ডিফল্ট বিজ্ঞাপনের লেখা (ফুটার)</span>
            <input className={inputClass} value={adText} maxLength={120} onChange={(e) => setAdText(e.target.value)} disabled={!canEdit} placeholder="যেমন: বিজ্ঞাপন দিতে যোগাযোগ: ০১৭…" />
          </label>

          {canEdit ? (
            <Button onClick={save} disabled={saving} data-testid="kit-save">
              {saving ? 'সংরক্ষণ হচ্ছে...' : 'ভিডিও কিট সংরক্ষণ'}
            </Button>
          ) : (
            <p className="text-[11.5px] text-neutral-400">এই সেটিংস শুধুমাত্র পাবলিশার পরিবর্তন করতে পারবেন।</p>
          )}
        </>
      )}

      {picking === 'logo' && (
        <MediaGallery
          title="লোগো বেছে নিন (স্বচ্ছ PNG সবচেয়ে ভালো)"
          onClose={() => setPicking(null)}
          onSelect={(img) => {
            setRefs((r) => ({ ...r, logo: { id: img.id, url: img.url, title: img.title ?? '' } }))
            setPicking(null)
          }}
        />
      )}
      {(picking === 'intro' || picking === 'outro') && (
        <VideoLibraryPicker
          title={picking === 'intro' ? 'ইন্ট্রো ভিডিও বেছে নিন' : 'আউট্রো ভিডিও বেছে নিন'}
          adsFirst={false}
          onClose={() => setPicking(null)}
          onSelect={(v) => {
            const slot = picking
            setRefs((r) => ({ ...r, [slot]: { id: v.id, url: v.url, title: v.title, duration: v.duration } }))
            setPicking(null)
          }}
        />
      )}
    </Card>
  )
}
