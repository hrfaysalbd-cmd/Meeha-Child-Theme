/**
 * r47 — বিজ্ঞাপন ব্যবস্থাপনা: every ad placement on the website managed from the publisher app.
 * Groups the theme's slots by page area, edits each one in place (image / animated house card /
 * HTML / shortcode / GAM, device + dates), previews the house cards live, manages the photocard
 * strip, carries the full size guide, and has one button that switches every empty slot to a
 * fitting house card ("প্রত্যেক জায়গায় বিজ্ঞাপন চালু").
 */
import { useEffect, useMemo, useState } from 'react'
import {
  AdsApi,
  MediaApi,
  type AdSlot,
  type AdSlotPatch,
  type AdsOverview,
  type HouseCard,
} from '../lib/api'
import { PageHeader, Card, Button, Banner, Spinner, ErrorRetry, Badge, inputClass, bnNumber } from '../components/ui'
import { IconAd, IconChevronDown, IconChevronRight, IconPhoto, IconSparkles, IconTrash, IconPlus } from '../components/Icon'

const GROUPS: { id: AdSlot['group']; label: string }[] = [
  { id: 'header', label: 'হেডার (সব পাতায়)' },
  { id: 'home', label: 'প্রথম পাতা' },
  { id: 'article', label: 'খবরের ভেতরে ও পাশে' },
  { id: 'archive', label: 'বিভাগ পাতা' },
  { id: 'footer', label: 'ফুটার (সব পাতায়)' },
  { id: 'other', label: 'অন্যান্য' },
]

const TYPE_LABELS: Record<AdSlot['type'], string> = {
  image: 'ছবি + লিংক',
  house: 'হাউস কার্ড (অ্যানিমেটেড)',
  html: 'HTML কোড',
  shortcode: 'শর্টকোড',
  gam: 'Google Ad Manager',
}

function bnSize(bytes: number) {
  if (!bytes) return ''
  return bytes >= 1024 * 1024 ? `${bnNumber((bytes / 1024 / 1024).toFixed(1))} MB` : `${bnNumber(Math.round(bytes / 1024))} KB`
}

/** One slot's editor card. */
function SlotEditor({ slot, house, onSaved }: { slot: AdSlot; house: HouseCard[]; onSaved: () => void }) {
  const [openEd, setOpenEd] = useState(false)
  const [draft, setDraft] = useState<AdSlot>(slot)
  const [saving, setSaving] = useState(false)
  const [uploading, setUploading] = useState<'' | 'image' | 'image_m'>('')
  const [err, setErr] = useState('')

  useEffect(() => setDraft(slot), [slot])

  const set = <K extends keyof AdSlot>(k: K, v: AdSlot[K]) => setDraft((d) => ({ ...d, [k]: v }))

  const pickImage = (which: 'image' | 'image_m') => {
    const input = document.createElement('input')
    input.type = 'file'
    input.accept = 'image/png,image/jpeg,image/webp,image/gif'
    input.onchange = async () => {
      const f = input.files?.[0]
      if (!f) return
      if (f.type === 'image/gif' && f.size > 150 * 1024) {
        setErr(`GIF-টি ${bnSize(f.size)} — সর্বোচ্চ ১৫০ KB। WebP বা ছোট GIF দিন।`)
        return
      }
      setUploading(which)
      setErr('')
      try {
        const up = await MediaApi.upload(f, { alt: draft.alt || 'বিজ্ঞাপন' })
        setDraft((d) => ({ ...d, [which]: { id: up.id, url: up.url, w: 0, h: 0, bytes: f.size, mime: f.type } }))
      } catch (e) {
        setErr(e instanceof Error ? e.message : 'ছবি আপলোড হয়নি।')
      } finally {
        setUploading('')
      }
    }
    input.click()
  }

  const save = async () => {
    setSaving(true)
    setErr('')
    try {
      const patch: AdSlotPatch = {
        on: draft.on ? 1 : 0,
        type: draft.type,
        house: draft.house,
        image: draft.image?.id ?? 0,
        image_m: draft.image_m?.id ?? 0,
        link: draft.link,
        alt: draft.alt,
        device: draft.device,
        start: draft.start,
        end: draft.end,
        label: draft.label_on ? 1 : 0,
        code: draft.code,
        shortcode: draft.shortcode,
        gam: draft.gam,
      }
      await AdsApi.saveSlot(draft.id, patch)
      onSaved()
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'সংরক্ষণ হয়নি।')
    } finally {
      setSaving(false)
    }
  }

  const summary = !slot.on
    ? 'বন্ধ'
    : slot.type === 'house'
      ? `হাউস কার্ড: ${house.find((h) => h.id === slot.house)?.label || slot.house || '—'}`
      : slot.type === 'image'
        ? slot.image
          ? 'ছবি দেওয়া আছে'
          : 'ছবি দেওয়া নেই'
        : TYPE_LABELS[slot.type]

  return (
    <div className="rounded-xl border border-neutral-200 bg-white" data-testid={`ad-slot-${slot.id}`}>
      <button type="button" className="w-full flex items-center gap-3 px-4 py-3 text-left" onClick={() => setOpenEd((o) => !o)}>
        {openEd ? <IconChevronDown className="w-4 h-4 text-neutral-400 shrink-0" /> : <IconChevronRight className="w-4 h-4 text-neutral-400 shrink-0" />}
        <span className="min-w-0 flex-1">
          <span className="block text-[13.5px] font-semibold text-neutral-900">{slot.label}</span>
          <span className="block text-[11.5px] text-neutral-500">{slot.size_text}</span>
        </span>
        <Badge tone={slot.on ? 'emerald' : 'neutral'}>{summary}</Badge>
      </button>
      {openEd && (
        <div className="border-t border-neutral-100 px-4 py-3 space-y-3">
          <div className="flex flex-wrap items-center gap-4">
            <label className="flex items-center gap-2 text-[13px] font-medium">
              <input type="checkbox" checked={draft.on} onChange={(e) => set('on', e.target.checked)} /> চালু
            </label>
            <label className="flex items-center gap-2 text-[13px]">
              ধরন
              <select className={inputClass + ' !w-auto'} value={draft.type} onChange={(e) => set('type', e.target.value as AdSlot['type'])}>
                {Object.entries(TYPE_LABELS).map(([v, l]) => (
                  <option key={v} value={v}>
                    {l}
                  </option>
                ))}
              </select>
            </label>
            <label className="flex items-center gap-2 text-[13px]">
              ডিভাইস
              <select className={inputClass + ' !w-auto'} value={draft.device} onChange={(e) => set('device', e.target.value as AdSlot['device'])}>
                <option value="all">সব</option>
                <option value="mobile">শুধু মোবাইল</option>
                <option value="desktop">শুধু ডেস্কটপ</option>
              </select>
            </label>
          </div>

          {draft.type === 'image' && (
            <div className="space-y-2">
              <div className="flex flex-wrap gap-3">
                {(['image', ...(draft.size === 'leader' ? (['image_m'] as const) : [])] as ('image' | 'image_m')[]).map((which) => {
                  const img = draft[which]
                  return (
                    <div key={which} className="rounded-lg border border-dashed border-neutral-300 p-2 w-56">
                      <p className="text-[11.5px] font-medium text-neutral-600 mb-1">
                        {which === 'image' ? 'ছবি (ডেস্কটপ / সব)' : 'মোবাইল ছবি (৩২০×১০০)'}
                      </p>
                      {img ? (
                        <img src={img.url} alt="" className="max-h-24 rounded border border-neutral-100" />
                      ) : (
                        <p className="text-[12px] text-neutral-400 py-4 text-center">ছবি নেই</p>
                      )}
                      <div className="mt-1.5 flex gap-1.5">
                        <Button size="sm" variant="secondary" onClick={() => pickImage(which)} disabled={uploading !== ''}>
                          <IconPhoto className="w-3.5 h-3.5" />
                          {uploading === which ? 'আপলোড…' : img ? 'বদলান' : 'আপলোড'}
                        </Button>
                        {img && (
                          <Button size="sm" variant="secondary" onClick={() => set(which, null)}>
                            <IconTrash className="w-3.5 h-3.5" />
                          </Button>
                        )}
                      </div>
                    </div>
                  )
                })}
              </div>
              <input className={inputClass} placeholder="লিংক (https://…)" value={draft.link} onChange={(e) => set('link', e.target.value)} />
              <input className={inputClass} placeholder="ছবির বিকল্প লেখা" value={draft.alt} onChange={(e) => set('alt', e.target.value)} />
            </div>
          )}

          {draft.type === 'house' && (
            <div className="flex flex-wrap gap-2">
              {house.map((h) => (
                <button
                  key={h.id}
                  type="button"
                  onClick={() => set('house', h.id)}
                  className={`rounded-lg border px-3 py-2 text-left text-[12.5px] ${draft.house === h.id ? 'border-brand-600 bg-brand-50 font-semibold' : 'border-neutral-200 bg-white'}`}
                >
                  {h.label}
                  <span className="block text-[11px] text-neutral-500 font-normal">{h.line}</span>
                </button>
              ))}
            </div>
          )}

          {draft.type === 'html' && (
            <textarea className={inputClass + ' font-mono text-[12px]'} rows={4} placeholder="<script>…</script> বা যেকোনো HTML" value={draft.code} onChange={(e) => set('code', e.target.value)} />
          )}
          {draft.type === 'shortcode' && (
            <input className={inputClass} placeholder='[adinserter block="1"]' value={draft.shortcode} onChange={(e) => set('shortcode', e.target.value)} />
          )}
          {draft.type === 'gam' && (
            <input className={inputClass} placeholder={`/1234567/meeha_${draft.id}`} value={draft.gam} onChange={(e) => set('gam', e.target.value)} />
          )}

          <div className="flex flex-wrap items-center gap-3 text-[12.5px]">
            <label className="flex items-center gap-1.5">
              শুরু <input type="date" className={inputClass + ' !w-auto'} value={draft.start} onChange={(e) => set('start', e.target.value)} />
            </label>
            <label className="flex items-center gap-1.5">
              শেষ <input type="date" className={inputClass + ' !w-auto'} value={draft.end} onChange={(e) => set('end', e.target.value)} />
            </label>
            {draft.type !== 'house' && (
              <label className="flex items-center gap-1.5">
                <input type="checkbox" checked={draft.label_on} onChange={(e) => set('label_on', e.target.checked)} /> “বিজ্ঞাপন” লেবেল
              </label>
            )}
          </div>

          {err && <Banner kind="error">{err}</Banner>}
          <Button onClick={() => void save()} disabled={saving || uploading !== ''} data-testid={`ad-save-${slot.id}`}>
            {saving ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন'}
          </Button>
        </div>
      )}
    </div>
  )
}

export default function AdsManager() {
  const [data, setData] = useState<AdsOverview | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [bulkBusy, setBulkBusy] = useState(false)
  const [guideOpen, setGuideOpen] = useState(false)
  const [previewCard, setPreviewCard] = useState<HouseCard | null>(null)

  const load = () => {
    setError('')
    AdsApi.overview()
      .then(setData)
      .catch((e) => setError(e instanceof Error ? e.message : 'লোড হয়নি।'))
  }
  useEffect(load, [])

  const grouped = useMemo(() => {
    const m = new Map<string, AdSlot[]>()
    for (const s of data?.slots ?? []) {
      m.set(s.group, [...(m.get(s.group) ?? []), s])
    }
    return m
  }, [data])

  const enableAll = async () => {
    setBulkBusy(true)
    setNotice('')
    try {
      const r = await AdsApi.enableHouseEverywhere()
      setNotice(`${bnNumber(r.slots.length)}টি জায়গায় বিজ্ঞাপন চালু হলো — খালি স্লটে মিহার অ্যানিমেটেড হাউস কার্ড বসেছে।`)
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'হয়নি।')
    } finally {
      setBulkBusy(false)
    }
  }

  if (error && !data) return <ErrorRetry message={error} onRetry={load} />
  if (!data) return <Spinner className="mt-16 mx-auto" />

  return (
    <div className="space-y-5" data-testid="ads-manager">
      {/* The house cards animate with this one stylesheet from the theme. */}
      {data.house_css && <span dangerouslySetInnerHTML={{ __html: data.house_css }} />}
      <PageHeader
        title="বিজ্ঞাপন ব্যবস্থাপনা"
        subtitle="ওয়েবসাইটের প্রতিটি বিজ্ঞাপন জায়গা — হেডার, প্রথম পাতা, খবরের ভেতরে, বিভাগ পাতা, ফুটার — এখান থেকেই চালান।"
        actions={
          <Button onClick={() => void enableAll()} disabled={bulkBusy} data-testid="ads-enable-all">
            <IconSparkles className="w-4 h-4" />
            {bulkBusy ? 'চালু হচ্ছে…' : 'প্রত্যেক জায়গায় বিজ্ঞাপন চালু করুন'}
          </Button>
        }
      />
      {notice && <Banner kind="success">{notice}</Banner>}
      {error && <Banner kind="error">{error}</Banner>}

      <Card>
        <button type="button" className="w-full flex items-center gap-2 text-left" onClick={() => setGuideOpen((o) => !o)}>
          {guideOpen ? <IconChevronDown className="w-4 h-4 text-neutral-400" /> : <IconChevronRight className="w-4 h-4 text-neutral-400" />}
          <IconAd className="w-4 h-4 text-brand-600" />
          <span className="text-[13.5px] font-semibold">সাইজ গাইড — বিজ্ঞাপনদাতাকে এই মাপগুলোই দিন</span>
        </button>
        {guideOpen && (
          <ul className="mt-3 grid gap-2 sm:grid-cols-2">
            {data.size_guide.map((g) => (
              <li key={g.name} className="rounded-lg bg-neutral-50 px-3 py-2">
                <span className="text-[13px] font-semibold">{g.name}</span>
                <span className="ml-2 text-[13px] text-brand-700 font-medium tabular-nums">{g.px}</span>
                <span className="block text-[11.5px] text-neutral-500">{g.note}</span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <p className="text-[13.5px] font-semibold mb-1">মিহার হাউস কার্ড (অ্যানিমেটেড)</p>
        <p className="text-[12px] text-neutral-500 mb-3">
          ইউটিউব, অ্যাপ, টিপস, ফ্যাক্টচেক, ক্যাম্পাস — মিহার নিজের ফিচারের চলমান বিজ্ঞাপন। GIF-এর দরকার নেই: এগুলো CSS অ্যানিমেশনে চলে, তাই
          ওজনহীন ও সব স্ক্রিনে ঝকঝকে। যেকোনো স্লটে “হাউস কার্ড” ধরন বেছে বসান।
        </p>
        <div className="flex flex-wrap gap-2">
          {data.house.map((h) => (
            <button
              key={h.id}
              type="button"
              className={`rounded-lg border px-3 py-1.5 text-[12.5px] ${previewCard?.id === h.id ? 'border-brand-600 bg-brand-50 font-semibold' : 'border-neutral-200'}`}
              onClick={() => setPreviewCard((p) => (p?.id === h.id ? null : h))}
            >
              {h.label}
            </button>
          ))}
        </div>
        {previewCard && (
          <div className="mt-3 space-y-3">
            <div style={{ height: 90 }} dangerouslySetInnerHTML={{ __html: previewCard.preview.leader }} />
            <div style={{ width: 300, height: 250 }} dangerouslySetInnerHTML={{ __html: previewCard.preview.mpu }} />
          </div>
        )}
      </Card>

      {GROUPS.map((g) => {
        const slots = grouped.get(g.id)
        if (!slots?.length) return null
        return (
          <section key={g.id}>
            <h2 className="text-[13px] font-bold text-neutral-500 uppercase tracking-wide mb-2">{g.label}</h2>
            <div className="space-y-2">
              {slots.map((s) => (
                <SlotEditor key={s.id} slot={s} house={data.house} onSaved={load} />
              ))}
            </div>
          </section>
        )
      })}

      <StripEditor data={data} onSaved={load} />
    </div>
  )
}

/** Photocard-strip rows (১০৮০×১২০ creatives used by the photocard studio). */
function StripEditor({ data, onSaved }: { data: AdsOverview; onSaved: () => void }) {
  const [rows, setRows] = useState(data.strip.map((r) => ({ label: r.label, image: r.image, link: r.link, on: r.on })))
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => setRows(data.strip.map((r) => ({ label: r.label, image: r.image, link: r.link, on: r.on }))), [data])

  const upload = (i: number) => {
    const input = document.createElement('input')
    input.type = 'file'
    input.accept = 'image/png,image/jpeg,image/webp,image/gif'
    input.onchange = async () => {
      const f = input.files?.[0]
      if (!f) return
      setErr('')
      try {
        const up = await MediaApi.upload(f, { alt: 'বিজ্ঞাপন স্ট্রিপ' })
        setRows((rs) => rs.map((r, j) => (j === i ? { ...r, image: { id: up.id, url: up.url, w: 0, h: 0, bytes: f.size, mime: f.type } } : r)))
      } catch (e) {
        setErr(e instanceof Error ? e.message : 'আপলোড হয়নি।')
      }
    }
    input.click()
  }

  const save = async () => {
    setSaving(true)
    setErr('')
    try {
      await AdsApi.saveStrip(rows.map((r) => ({ label: r.label, image: r.image?.id ?? 0, link: r.link, on: r.on ? 1 : 0 })))
      onSaved()
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'সংরক্ষণ হয়নি।')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <p className="text-[13.5px] font-semibold mb-1">ফটোকার্ডের বিজ্ঞাপন স্ট্রিপ (১০৮০×১২০)</p>
      <p className="text-[12px] text-neutral-500 mb-3">ফটোকার্ড স্টুডিওতে “নিচে বিজ্ঞাপন স্ট্রিপ” হিসেবে এগুলো বেছে নেওয়া যায়।</p>
      <div className="space-y-2">
        {rows.map((r, i) => (
          <div key={i} className="flex flex-wrap items-center gap-2 rounded-lg border border-neutral-200 p-2">
            <input type="checkbox" checked={r.on} onChange={(e) => setRows((rs) => rs.map((x, j) => (j === i ? { ...x, on: e.target.checked } : x)))} />
            {r.image ? <img src={r.image.url} alt="" className="h-8 rounded border border-neutral-100" /> : <span className="text-[12px] text-neutral-400">ছবি নেই</span>}
            <input className={inputClass + ' !w-36'} placeholder="নাম" value={r.label} onChange={(e) => setRows((rs) => rs.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))} />
            <input className={inputClass + ' flex-1 min-w-[160px]'} placeholder="লিংক" value={r.link} onChange={(e) => setRows((rs) => rs.map((x, j) => (j === i ? { ...x, link: e.target.value } : x)))} />
            <Button size="sm" variant="secondary" onClick={() => upload(i)}>
              <IconPhoto className="w-3.5 h-3.5" /> ছবি
            </Button>
            <Button size="sm" variant="secondary" onClick={() => setRows((rs) => rs.filter((_, j) => j !== i))}>
              <IconTrash className="w-3.5 h-3.5" />
            </Button>
          </div>
        ))}
      </div>
      <div className="mt-3 flex gap-2">
        <Button variant="secondary" onClick={() => setRows((rs) => [...rs, { label: '', image: null, link: '', on: true }])} disabled={rows.length >= 20}>
          <IconPlus className="w-4 h-4" /> নতুন স্ট্রিপ
        </Button>
        <Button onClick={() => void save()} disabled={saving}>
          {saving ? 'সংরক্ষণ হচ্ছে…' : 'স্ট্রিপ সংরক্ষণ করুন'}
        </Button>
      </div>
      {err && (
        <div className="mt-2">
          <Banner kind="error">{err}</Banner>
        </div>
      )}
    </Card>
  )
}
