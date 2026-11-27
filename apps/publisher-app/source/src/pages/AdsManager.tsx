/**
 * r47 — বিজ্ঞাপন ব্যবস্থাপনা: every ad placement on the website managed from the publisher app.
 *
 * r49 — rebuilt into five tabs:
 *  - জায়গা: every placement by page area. Images come from the ad gallery (no re-upload to
 *    change a picture), each slot can name its client, and shows whether it is live now.
 *  - ভাসমান ও পপ-আপ: the floating bottom bar, the desktop corner box and the pop-up (all with a
 *    close cross), with how often they come back and on which pages the pop-up opens.
 *  - ফটোকার্ড: the reader-side strip under "ফটোকার্ড ডাউনলোড" for news AND quote cards, plus the
 *    studio's strip rows.
 *  - রিপোর্ট: views and clicks per placement and per client (7/30/90 days, CSV for the client).
 *    r50: ইমপ্রেশন (shown) vs নিশ্চিত দেখা (viewable), people reached, tracking ids, each client's
 *    private results page (copy / WhatsApp / new link), off-site entries and the site audience.
 *  - মাপ ও নীতি: the full size guide (web, video, photocard), the "বিজ্ঞাপন" label style, the ad
 *    desk's contact, and one-click landing pages ([meeha_advertise], [meeha_ad_specs], app).
 */
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import {
  AdsApi,
  type AdGlobals,
  type AdImage,
  type AdReader,
  type AdSlot,
  type AdSlotPatch,
  type AdsOverview,
  type AdStats,
  type AdAudience,
  type AdInsightClient,
  type AdOffsiteEntry,
  type HouseCard,
  type LibraryImage,
} from '../lib/api'
import { PageHeader, Card, Button, Banner, Spinner, ErrorRetry, Badge, inputClass, bnNumber, KpiCard } from '../components/ui'
import {
  IconAd,
  IconChevronDown,
  IconChevronRight,
  IconPhoto,
  IconSparkles,
  IconTrash,
  IconPlus,
  IconDownload,
  IconExternalLink,
  IconEye,
  IconChartBar,
} from '../components/Icon'
import MediaGallery from '../components/MediaGallery'

const GROUPS: { id: AdSlot['group']; label: string }[] = [
  { id: 'header', label: 'হেডার (সব পাতায়)' },
  { id: 'home', label: 'প্রথম পাতা' },
  { id: 'article', label: 'খবরের ভেতরে ও পাশে' },
  { id: 'archive', label: 'বিভাগ পাতা' },
  { id: 'video', label: 'ভিডিও পাতা' },
  { id: 'glance', label: 'একনজর' },
  { id: 'quotes', label: 'উক্তি' },
  { id: 'lit', label: 'সাহিত্য' },
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

type Tab = 'slots' | 'floating' | 'cards' | 'report' | 'guide'
const TABS: { id: Tab; label: string }[] = [
  { id: 'slots', label: 'জায়গা' },
  { id: 'floating', label: 'ভাসমান ও পপ-আপ' },
  { id: 'cards', label: 'ফটোকার্ড' },
  { id: 'report', label: 'রিপোর্ট' },
  { id: 'guide', label: 'মাপ ও নীতি' },
]

function bnSize(bytes: number) {
  if (!bytes) return ''
  return bytes >= 1024 * 1024 ? `${bnNumber((bytes / 1024 / 1024).toFixed(1))} MB` : `${bnNumber(Math.round(bytes / 1024))} KB`
}

const errText = (e: unknown, d: string) => (e instanceof Error ? e.message : d)

function fromLibrary(img: LibraryImage): AdImage {
  return { id: img.id, url: img.url, w: 0, h: 0, bytes: 0, mime: '' }
}

/** Today in the site's sense is close enough to the browser's for a status badge. */
function today() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function slotStatus(s: AdSlot): { text: string; tone: 'neutral' | 'amber' | 'emerald' | 'red' | 'blue' } {
  if (!s.on) return { text: 'বন্ধ', tone: 'neutral' }
  const t = today()
  if (s.start && t < s.start) return { text: `শুরু ${bnNumber(s.start)}`, tone: 'blue' }
  if (s.end && t > s.end) return { text: 'মেয়াদ শেষ', tone: 'red' }
  if (s.live === false) return { text: 'চালু, কিন্তু কিছু দেওয়া নেই', tone: 'amber' }
  return { text: 'চলছে', tone: 'emerald' }
}

/** A picture box that picks from the ad gallery (with upload inside it). */
function ImagePick({
  label,
  hint,
  img,
  onPick,
  onClear,
  testid,
}: {
  label: string
  hint?: string
  img: AdImage | null
  onPick: (img: AdImage) => void
  onClear: () => void
  testid?: string
}) {
  const [open, setOpen] = useState(false)
  return (
    <div className="rounded-lg border border-dashed border-neutral-300 p-2 w-60" data-testid={testid}>
      <p className="text-[11.5px] font-medium text-neutral-600">{label}</p>
      {hint && <p className="text-[10.5px] text-neutral-400 mb-1">{hint}</p>}
      {img ? (
        <img src={img.url} alt="" className="max-h-28 max-w-full rounded border border-neutral-100 bg-[repeating-conic-gradient(#f3f4f6_0_25%,#fff_0_50%)] bg-[length:12px_12px]" />
      ) : (
        <p className="text-[12px] text-neutral-400 py-4 text-center">ছবি নেই</p>
      )}
      {img && (img.w > 0 || img.bytes > 0) && (
        <p className="text-[10.5px] text-neutral-500 mt-0.5 tabular-nums">
          {img.w > 0 && `${bnNumber(img.w)}×${bnNumber(img.h)} px`} {img.bytes > 0 && `· ${bnSize(img.bytes)}`}
        </p>
      )}
      <div className="mt-1.5 flex gap-1.5">
        <Button size="sm" variant="secondary" onClick={() => setOpen(true)} data-testid={testid ? `${testid}-pick` : undefined}>
          <IconPhoto className="w-3.5 h-3.5" />
          {img ? 'বদলান' : 'গ্যালারি / আপলোড'}
        </Button>
        {img && (
          <Button size="sm" variant="secondary" onClick={onClear}>
            <IconTrash className="w-3.5 h-3.5" />
          </Button>
        )}
      </div>
      {open && (
        <MediaGallery
          title="বিজ্ঞাপনের ছবি বেছে নিন"
          source="ads"
          upload
          onClose={() => setOpen(false)}
          onSelect={(i) => {
            onPick(fromLibrary(i))
            setOpen(false)
          }}
        />
      )}
    </div>
  )
}

/** One slot's editor card. */
function SlotEditor({
  slot,
  house,
  clients,
  onSaved,
}: {
  slot: AdSlot
  house: HouseCard[]
  clients: NonNullable<AdsOverview['clients']>
  onSaved: () => void
}) {
  const [openEd, setOpenEd] = useState(false)
  const [draft, setDraft] = useState<AdSlot>(slot)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')
  const [saved, setSaved] = useState(false)

  useEffect(() => setDraft(slot), [slot])

  const set = <K extends keyof AdSlot>(k: K, v: AdSlot[K]) => {
    setSaved(false)
    setDraft((d) => ({ ...d, [k]: v }))
  }
  const mobileOk = slot.mobile_ok ?? slot.size === 'leader'

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
        client: draft.client ?? 0,
      }
      await AdsApi.saveSlot(draft.id, patch)
      setSaved(true)
      onSaved()
    } catch (e) {
      setErr(errText(e, 'সংরক্ষণ হয়নি।'))
    } finally {
      setSaving(false)
    }
  }

  const st = slotStatus(slot)
  const what =
    slot.type === 'house'
      ? `হাউস কার্ড: ${house.find((h) => h.id === slot.house)?.label || slot.house || '—'}`
      : slot.type === 'image'
        ? slot.image
          ? 'ছবি'
          : 'ছবি নেই'
        : TYPE_LABELS[slot.type]
  const client = clients.find((c) => c.id === slot.client)

  return (
    <div className="rounded-xl border border-neutral-200 bg-white" data-testid={`ad-slot-${slot.id}`}>
      <button type="button" className="w-full flex items-center gap-3 px-4 py-3 text-left" onClick={() => setOpenEd((o) => !o)}>
        {openEd ? <IconChevronDown className="w-4 h-4 text-neutral-400 shrink-0" /> : <IconChevronRight className="w-4 h-4 text-neutral-400 shrink-0" />}
        {slot.image && slot.type === 'image' ? (
          <img src={slot.image.url} alt="" className="h-9 w-16 object-contain rounded border border-neutral-100 bg-neutral-50 shrink-0" />
        ) : (
          <span className="h-9 w-16 rounded border border-dashed border-neutral-200 shrink-0 grid place-items-center text-neutral-300">
            <IconAd className="w-4 h-4" />
          </span>
        )}
        <span className="min-w-0 flex-1">
          <span className="block text-[13.5px] font-semibold text-neutral-900">{slot.label}</span>
          <span className="block text-[11.5px] text-neutral-500">{slot.size_text}</span>
          {slot.on && (
            <span className="block text-[11.5px] text-neutral-600">
              {what}
              {client && ` · ${client.name}`}
              {slot.end && ` · শেষ ${bnNumber(slot.end)}`}
            </span>
          )}
        </span>
        <Badge tone={st.tone}>{st.text}</Badge>
      </button>
      {openEd && (
        <div className="border-t border-neutral-100 px-4 py-3 space-y-3">
          <div className="flex flex-wrap items-center gap-4">
            <label className="flex items-center gap-2 text-[13px] font-medium">
              <input type="checkbox" checked={draft.on} onChange={(e) => set('on', e.target.checked)} data-testid={`ad-on-${slot.id}`} /> চালু
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
            {clients.length > 0 && (
              <label className="flex items-center gap-2 text-[13px]">
                ক্লায়েন্ট
                <select
                  className={inputClass + ' !w-auto max-w-[220px]'}
                  value={draft.client ?? 0}
                  onChange={(e) => set('client', Number(e.target.value))}
                  data-testid={`ad-client-${slot.id}`}
                >
                  <option value={0}>— নেই / নিজস্ব —</option>
                  {clients.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </label>
            )}
          </div>

          {draft.type === 'image' && (
            <div className="space-y-2">
              <div className="flex flex-wrap gap-3">
                <ImagePick
                  label={mobileOk ? 'ছবি (ডেস্কটপ / সব)' : 'ছবি'}
                  hint={slot.size_text}
                  img={draft.image}
                  onPick={(i) => set('image', i)}
                  onClear={() => set('image', null)}
                  testid={`ad-img-${slot.id}`}
                />
                {mobileOk && (
                  <ImagePick
                    label="মোবাইল ছবি (ঐচ্ছিক)"
                    hint={slot.size === 'popup' ? '৩০০×২৫০ বা ৩২০×৪৮০' : '৩২০×৫০ বা ৩২০×১০০ — যেকোনো মাপ, কাটা পড়বে না'}
                    img={draft.image_m}
                    onPick={(i) => set('image_m', i)}
                    onClear={() => set('image_m', null)}
                  />
                )}
              </div>
              <p className="text-[11.5px] text-neutral-500">ছবির নিজের মাপেই বাক্স বসে, তাই ৩২০×৫০ বা ৩২০×১০০ — দুটোই পুরোটা দেখা যায়।</p>
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
                <input type="checkbox" checked={draft.label_on} onChange={(e) => set('label_on', e.target.checked)} /> “বিজ্ঞাপন” চিহ্ন
              </label>
            )}
          </div>
          <p className="text-[11px] text-neutral-400">শেষের তারিখ পেরোলে বিজ্ঞাপন নিজে থেকেই সরে যায়।</p>

          {err && <Banner kind="error">{err}</Banner>}
          <div className="flex items-center gap-3">
            <Button onClick={() => void save()} disabled={saving} data-testid={`ad-save-${slot.id}`}>
              {saving ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন'}
            </Button>
            {saved && <span className="text-[12.5px] text-emerald-700">সংরক্ষিত ✓</span>}
          </div>
        </div>
      )}
    </div>
  )
}

function Section({ title, sub, children }: { title: string; sub?: string; children: ReactNode }) {
  return (
    <section>
      <h2 className="text-[13px] font-bold text-neutral-500 uppercase tracking-wide">{title}</h2>
      {sub && <p className="text-[12px] text-neutral-500 mb-2">{sub}</p>}
      <div className={`space-y-2 ${sub ? '' : 'mt-2'}`}>{children}</div>
    </section>
  )
}

export default function AdsManager() {
  const [data, setData] = useState<AdsOverview | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [bulkBusy, setBulkBusy] = useState(false)
  const [tab, setTab] = useState<Tab>('slots')
  const [previewCard, setPreviewCard] = useState<HouseCard | null>(null)

  const load = () => {
    setError('')
    AdsApi.overview()
      .then(setData)
      .catch((e) => setError(errText(e, 'লোড হয়নি।')))
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
      setNotice(`${bnNumber(r.slots.length)}টি জায়গায় বিজ্ঞাপন চালু হলো — খালি স্লটে মিহার অ্যানিমেটেড হাউস কার্ড বসেছে। ভাসমান বার ও পপ-আপ নিজে থেকে চালু হয় না।`)
      load()
    } catch (e) {
      setError(errText(e, 'হয়নি।'))
    } finally {
      setBulkBusy(false)
    }
  }

  if (error && !data) return <ErrorRetry message={error} onRetry={load} />
  if (!data) return <Spinner className="mt-16 mx-auto" />

  const clients = data.clients ?? []
  const liveCount = data.slots.filter((s) => slotStatus(s).tone === 'emerald').length
  const stats = data.stats

  return (
    <div className="space-y-5" data-testid="ads-manager">
      {/* The house cards animate with this one stylesheet from the theme. */}
      {data.house_css && <span dangerouslySetInnerHTML={{ __html: data.house_css }} />}
      <PageHeader
        title="বিজ্ঞাপন ব্যবস্থাপনা"
        subtitle="ওয়েবসাইট, ভাসমান বার, পপ-আপ আর ফটোকার্ডের সব বিজ্ঞাপন — এক জায়গা থেকে।"
        actions={
          <Button onClick={() => void enableAll()} disabled={bulkBusy} data-testid="ads-enable-all">
            <IconSparkles className="w-4 h-4" />
            {bulkBusy ? 'চালু হচ্ছে…' : 'খালি জায়গায় হাউস বিজ্ঞাপন'}
          </Button>
        }
      />
      {notice && <Banner kind="success">{notice}</Banner>}
      {error && <Banner kind="error">{error}</Banner>}

      <div className="grid gap-3 grid-cols-2 lg:grid-cols-4">
        <KpiCard label="এখন চলছে" value={`${bnNumber(liveCount)} / ${bnNumber(data.slots.length)}`} icon={<IconAd className="w-5 h-5" />} />
        <KpiCard label="৭ দিনে নিশ্চিত দেখা" value={stats ? bnNumber(stats.imp) : '—'} icon={<IconEye className="w-5 h-5" />} tint="bg-emerald-50 text-emerald-600" />
        <KpiCard label="৭ দিনে ক্লিক" value={stats ? bnNumber(stats.clk) : '—'} icon={<IconChartBar className="w-5 h-5" />} tint="bg-amber-50 text-amber-600" />
        <KpiCard label="ক্লায়েন্ট" value={bnNumber(clients.filter((c) => c.status === 'active').length)} icon={<IconSparkles className="w-5 h-5" />} tint="bg-violet-50 text-violet-600" />
      </div>

      <div className="flex gap-1 overflow-x-auto border-b border-neutral-200" role="tablist">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            aria-selected={tab === t.id}
            data-testid={`ads-tab-${t.id}`}
            onClick={() => setTab(t.id)}
            className={`px-3.5 py-2 text-[13.5px] whitespace-nowrap border-b-2 -mb-px ${tab === t.id ? 'border-brand-600 text-brand-700 font-semibold' : 'border-transparent text-neutral-500 hover:text-neutral-800'}`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'slots' && (
        <>
          {GROUPS.map((g) => {
            const slots = grouped.get(g.id)
            if (!slots?.length) return null
            return (
              <Section key={g.id} title={g.label}>
                {slots.map((s) => (
                  <SlotEditor key={s.id} slot={s} house={data.house} clients={clients} onSaved={load} />
                ))}
              </Section>
            )
          })}
          <Card>
            <p className="text-[13.5px] font-semibold mb-1">মিহার হাউস কার্ড (অ্যানিমেটেড)</p>
            <p className="text-[12px] text-neutral-500 mb-3">
              ইউটিউব, অ্যাপ, টিপস, ফ্যাক্টচেক, ক্যাম্পাস — মিহার নিজের ফিচারের চলমান বিজ্ঞাপন। CSS অ্যানিমেশনে চলে, তাই হালকা ও সব স্ক্রিনে ঝকঝকে। খালি
              জায়গায় এগুলো বসিয়ে রাখুন, বিজ্ঞাপনদাতা এলে বদলে দিন।
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
        </>
      )}

      {tab === 'floating' && (
        <>
          {data.globals ? (
            <FloatingSettings globals={data.globals} onSaved={load} />
          ) : (
            <Banner kind="info">ভাসমান ও পপ-আপ বিজ্ঞাপনের জন্য প্লাগইন ১.৩১.০ আর থিম ২.৭.০ বসান।</Banner>
          )}
          {(grouped.get('floating') ?? []).length > 0 && (
            <Section title="ভাসমান ও পপ-আপ জায়গা" sub="প্রতিটিতে ক্রস (×) থাকে। পাঠক বন্ধ করলে ওপরে দেওয়া সময় পর্যন্ত আর দেখায় না। ফাঁকা ক্যানভাস পাতায় এগুলো দেখায় না।">
              {(grouped.get('floating') ?? []).map((s) => (
                <SlotEditor key={s.id} slot={s} house={data.house} clients={clients} onSaved={load} />
              ))}
            </Section>
          )}
        </>
      )}

      {tab === 'cards' && (
        <>
          {data.reader ? <ReaderCards reader={data.reader} clients={clients} onSaved={load} /> : <Banner kind="info">পাঠকের ফটোকার্ড বিজ্ঞাপনের সেটিংসের জন্য প্লাগইন ১.৩১.০ বসান।</Banner>}
          <StripEditor data={data} onSaved={load} />
        </>
      )}

      {tab === 'report' && <Report initial={data.stats ?? null} clients={clients} statsOn={data.globals?.stats_on ?? true} onToggle={load} />}

      {tab === 'guide' && <Guide data={data} onSaved={load} />}
    </div>
  )
}

/** Floating bar / corner box / pop-up timings. */
function FloatingSettings({ globals, onSaved }: { globals: AdGlobals; onSaved: () => void }) {
  const [g, setG] = useState(globals)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState<{ k: 'success' | 'error'; t: string } | null>(null)
  useEffect(() => setG(globals), [globals])
  const save = async () => {
    setBusy(true)
    setMsg(null)
    try {
      await AdsApi.saveGlobals({
        float_every: g.float_every,
        popup_delay: g.popup_delay,
        popup_every: g.popup_every,
        popup_pages: g.popup_pages,
        side_delay: g.side_delay ?? 8,
        lit_ads: g.lit_ads ?? 'gentle',
        utm_on: g.utm_on ?? true,
      })
      setMsg({ k: 'success', t: 'সংরক্ষিত ✓' })
      onSaved()
    } catch (e) {
      setMsg({ k: 'error', t: errText(e, 'হয়নি।') })
    } finally {
      setBusy(false)
    }
  }
  const num = (k: 'float_every' | 'popup_delay' | 'popup_every' | 'side_delay', v: string) => setG((x) => ({ ...x, [k]: Math.max(0, Number(v) || 0) }))
  return (
    <Card>
      <p className="text-[13.5px] font-semibold mb-3">কখন দেখাবে</p>
      <div className="grid gap-3 sm:grid-cols-2 text-[13px]">
        <label className="space-y-1">
          <span className="block font-medium">ভাসমান বার বন্ধ করলে আবার দেখাবে (ঘণ্টা পরে)</span>
          <input type="number" min={1} max={720} className={inputClass} value={g.float_every} onChange={(e) => num('float_every', e.target.value)} />
        </label>
        <label className="space-y-1">
          <span className="block font-medium">পপ-আপ খুলবে (পাতা খোলার কত সেকেন্ড পরে)</span>
          <input type="number" min={0} max={120} className={inputClass} value={g.popup_delay} onChange={(e) => num('popup_delay', e.target.value)} data-testid="popup-delay" />
        </label>
        <label className="space-y-1">
          <span className="block font-medium">একই পাঠককে পপ-আপ আবার দেখাবে (ঘণ্টা পরে)</span>
          <input type="number" min={1} max={720} className={inputClass} value={g.popup_every} onChange={(e) => num('popup_every', e.target.value)} />
        </label>
        <label className="space-y-1">
          <span className="block font-medium">ডান পাশের বিজ্ঞাপন আসবে (পাতা খোলার কত সেকেন্ড পরে)</span>
          <input type="number" min={0} max={120} className={inputClass} value={g.side_delay ?? 8} onChange={(e) => num('side_delay', e.target.value)} data-testid="side-delay" />
        </label>
        <label className="space-y-1">
          <span className="block font-medium">সাহিত্যের পাতায় বিজ্ঞাপন</span>
          <select className={inputClass} value={g.lit_ads ?? 'gentle'} onChange={(e) => setG((x) => ({ ...x, lit_ads: e.target.value as 'gentle' | 'none' }))}>
            <option value="gentle">হালকা — হেডার, লেখার শেষে ও ফুটারে</option>
            <option value="none">একদম নয়</option>
          </select>
        </label>
        <label className="flex items-center gap-2 sm:col-span-2">
          <input type="checkbox" checked={g.utm_on ?? true} onChange={(e) => setG((x) => ({ ...x, utm_on: e.target.checked }))} />
          <span>বিজ্ঞাপনের লিংকে ট্র্যাকিং আইডি যোগ হবে (utm_campaign=MH-…) — বিজ্ঞাপনদাতা নিজের Google Analytics-এ মিহা থেকে আসা ভিজিটর আলাদা দেখতে পাবেন</span>
        </label>
        <label className="space-y-1">
          <span className="block font-medium">পপ-আপ কোন পাতায়</span>
          <select className={inputClass} value={g.popup_pages} onChange={(e) => setG((x) => ({ ...x, popup_pages: e.target.value as AdGlobals['popup_pages'] }))}>
            <option value="all">সব পাতায়</option>
            <option value="single">শুধু খবরের পাতায়</option>
            <option value="home">শুধু প্রথম পাতায়</option>
          </select>
        </label>
      </div>
      <p className="mt-2 text-[11.5px] text-neutral-500">
        ডান পাশের বিজ্ঞাপন ফোনে পর্দার প্রায় অর্ধেক চওড়া, ডেস্কটপে ৩০০ px। পাঠক তীর চাপলে ডান পাশে ভিতরে ঢুকে যায় — একটা ছোট ট্যাব থাকে, চাপলে আবার বের হয়।
        ভিতরে ঢোকালে ওপরের “আবার দেখাবে” সময় পর্যন্ত পরের পাতাগুলোতেও ভিতরেই থাকে। পরামর্শ: পপ-আপ দিনে এক-দুবারের বেশি নয় (১২ ঘণ্টা বা বেশি)। বেশি দেখালে পাঠক বিরক্ত হন, আর Google-এর নিয়মে মোবাইলে পুরো পর্দা ঢাকা পপ-আপ র‍্যাঙ্কিং কমাতে পারে।
      </p>
      <div className="mt-3 flex items-center gap-3">
        <Button onClick={() => void save()} disabled={busy} data-testid="floating-save">
          {busy ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন'}
        </Button>
        {msg && <span className={`text-[12.5px] ${msg.k === 'success' ? 'text-emerald-700' : 'text-red-600'}`}>{msg.t}</span>}
      </div>
    </Card>
  )
}

/** The reader-side strip under "ফটোকার্ড ডাউনলোড" (news + quote cards). */
function ReaderCards({ reader, clients, onSaved }: { reader: AdReader; clients: NonNullable<AdsOverview['clients']>; onSaved: () => void }) {
  const [r, setR] = useState(reader)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState<{ k: 'success' | 'error'; t: string } | null>(null)
  useEffect(() => setR(reader), [reader])
  const save = async () => {
    setBusy(true)
    setMsg(null)
    try {
      await AdsApi.saveReader({
        news_on: r.news_on ? 1 : 0,
        news_image: r.news_image?.id ?? 0,
        quote_on: r.quote_on ? 1 : 0,
        quote_image: r.quote_image?.id ?? 0,
        news_client: r.news_client ?? 0,
        quote_client: r.quote_client ?? 0,
      })
      setMsg({ k: 'success', t: 'সংরক্ষিত ✓' })
      onSaved()
    } catch (e) {
      setMsg({ k: 'error', t: errText(e, 'হয়নি।') })
    } finally {
      setBusy(false)
    }
  }
  return (
    <Card>
      <p className="text-[13.5px] font-semibold mb-1">পাঠকের ফটোকার্ডে বিজ্ঞাপন</p>
      <p className="text-[12px] text-neutral-500 mb-3">
        খবর আর উক্তির পাতায় পাঠক “ফটোকার্ড ডাউনলোড” করলে কার্ডের নিচে এই স্ট্রিপ বসে — কার্ড যেখানেই শেয়ার হোক, বিজ্ঞাপন সঙ্গে যায়। মাপ ১০৮০×১২০ px।
      </p>
      {!r.available && <Banner kind="info">এর জন্য থিম ২.৭.০ লাগবে।</Banner>}
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <label className="flex items-center gap-2 text-[13px] font-medium">
            <input type="checkbox" checked={r.news_on} onChange={(e) => setR((x) => ({ ...x, news_on: e.target.checked }))} data-testid="reader-news-on" /> খবরের ফটোকার্ডে
          </label>
          <ImagePick
            label="স্ট্রিপের ছবি (খবর)"
            hint="১০৮০×১২০ px"
            img={r.news_image}
            onPick={(i) => setR((x) => ({ ...x, news_image: i }))}
            onClear={() => setR((x) => ({ ...x, news_image: null }))}
            testid="reader-news-img"
          />
          <ClientSelect clients={clients} value={r.news_client ?? 0} onChange={(v) => setR((x) => ({ ...x, news_client: v }))} />
        </div>
        <div className="space-y-2">
          <label className="flex items-center gap-2 text-[13px] font-medium">
            <input type="checkbox" checked={r.quote_on} onChange={(e) => setR((x) => ({ ...x, quote_on: e.target.checked }))} data-testid="reader-quote-on" /> উক্তির ফটোকার্ডে
          </label>
          <ImagePick
            label="স্ট্রিপের ছবি (উক্তি)"
            hint="১০৮০×১২০ px — না দিলে খবরের ছবিটিই বসে"
            img={r.quote_image}
            onPick={(i) => setR((x) => ({ ...x, quote_image: i }))}
            onClear={() => setR((x) => ({ ...x, quote_image: null }))}
          />
          <ClientSelect clients={clients} value={r.quote_client ?? 0} onChange={(v) => setR((x) => ({ ...x, quote_client: v }))} />
        </div>
      </div>
      <div className="mt-3 flex items-center gap-3">
        <Button onClick={() => void save()} disabled={busy} data-testid="reader-save">
          {busy ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন'}
        </Button>
        {msg && <span className={`text-[12.5px] ${msg.k === 'success' ? 'text-emerald-700' : 'text-red-600'}`}>{msg.t}</span>}
      </div>
    </Card>
  )
}

/** r50 — a client picker (0 = Meeha's own / none). */
function ClientSelect({ clients, value, onChange }: { clients: NonNullable<AdsOverview['clients']>; value: number; onChange: (v: number) => void }) {
  return (
    <label className="block space-y-1 text-[12.5px]">
      <span className="block text-neutral-600">বিজ্ঞাপনদাতা (তাঁর ফলাফলের পাতায় ডাউনলোড গোনা হবে)</span>
      <select className={inputClass} value={value} onChange={(e) => onChange(Number(e.target.value))}>
        <option value={0}>কেউ না / মিহার নিজের</option>
        {clients.map((c) => (
          <option key={c.id} value={c.id}>
            {c.name}
          </option>
        ))}
      </select>
    </label>
  )
}

/** Views and clicks, per placement and per client. */
function Report({
  initial,
  clients,
  statsOn,
  onToggle,
}: {
  initial: AdStats | null
  clients: NonNullable<AdsOverview['clients']>
  statsOn: boolean
  onToggle: () => void
}) {
  const [days, setDays] = useState(7)
  const [client, setClient] = useState(0)
  const [data, setData] = useState<AdStats | null>(initial)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    setBusy(true)
    setErr('')
    AdsApi.report(days)
      .then(setData)
      .catch((e) => setErr(errText(e, 'রিপোর্ট আসেনি।')))
      .finally(() => setBusy(false))
  }, [days])

  const csv = async () => {
    try {
      const r = await AdsApi.report(days, client, true)
      const blob = new Blob([r.csv ?? ''], { type: 'text/csv;charset=utf-8' })
      const a = document.createElement('a')
      a.href = URL.createObjectURL(blob)
      const name = client ? clients.find((c) => c.id === client)?.name || `client-${client}` : 'all'
      a.download = `meeha-ads-${name}-${days}d.csv`
      a.click()
      setTimeout(() => URL.revokeObjectURL(a.href), 4000)
    } catch (e) {
      setErr(errText(e, 'CSV হয়নি।'))
    }
  }

  const toggle = async () => {
    try {
      await AdsApi.saveGlobals({ stats_on: !statsOn })
      onToggle()
    } catch (e) {
      setErr(errText(e, 'হয়নি।'))
    }
  }

  const pct = (n: number, d = 2) => `${bnNumber(n.toFixed(d))}%`
  const daily = data?.daily ?? []
  const max = Math.max(1, ...daily.map((d) => d.srv ?? d.imp))
  const devTotal = (data?.devices ?? []).reduce((a, r) => a + r.imp, 0)
  const DEV: Record<string, string> = { mobile: 'মোবাইল', desktop: 'কম্পিউটার', tablet: 'ট্যাবলেট' }

  return (
    <div className="space-y-4" data-testid="ads-report">
      <Card>
        <div className="flex flex-wrap items-center gap-3">
          <div className="inline-flex rounded-xl bg-neutral-100 p-1 text-[12.5px]">
            {[7, 30, 90].map((d) => (
              <button
                key={d}
                type="button"
                onClick={() => setDays(d)}
                className={`px-3 py-1.5 rounded-lg ${days === d ? 'bg-white shadow-sm font-semibold' : 'text-neutral-500'}`}
              >
                {bnNumber(d)} দিন
              </button>
            ))}
          </div>
          <select className={inputClass + ' !w-auto'} value={client} onChange={(e) => setClient(Number(e.target.value))}>
            <option value={0}>সব বিজ্ঞাপন</option>
            {clients.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
          <Button variant="secondary" onClick={() => void csv()}>
            <IconDownload className="w-4 h-4" /> CSV
          </Button>
          {busy && <Spinner />}
          <label className="ml-auto flex items-center gap-2 text-[12.5px]">
            <input type="checkbox" checked={statsOn} onChange={() => void toggle()} /> গোনা চালু
          </label>
        </div>
        <p className="mt-2 text-[11.5px] text-neutral-500">
          <b>ইমপ্রেশন</b> = বিজ্ঞাপন পাতায় দেখানো হয়েছে। <b>নিশ্চিত দেখা</b> = অন্তত অর্ধেক অংশ টানা ১ সেকেন্ড পর্দায় ছিল। <b>মানুষ</b> = দিনে প্রতিটি ফোন/কম্পিউটার
          একবার। লগইন করা পাঠকও গোনা হন; সার্চ ইঞ্জিন ও বট বাদ। কোনো পাঠকের তথ্য, আইপি বা কুকি রাখা হয় না। সংখ্যা যা মাপা হয়েছে ঠিক তাই — বাড়িয়ে দেখানো হয় না।
        </p>
      </Card>
      {err && <Banner kind="error">{err}</Banner>}
      {data && (
        <>
          <div className="grid gap-3 grid-cols-2 lg:grid-cols-6">
            <KpiCard label="ইমপ্রেশন" value={bnNumber(data.srv ?? data.imp)} />
            <KpiCard label="নিশ্চিত দেখা" value={bnNumber(data.imp)} tint="bg-blue-50 text-blue-600" />
            <KpiCard label="মানুষ (আনুমানিক)" value={bnNumber(data.reach ?? 0)} tint="bg-violet-50 text-violet-600" />
            <KpiCard label="ক্লিক" value={bnNumber(data.clk)} tint="bg-orange-50 text-orange-600" />
            <KpiCard label="ক্লিক হার" value={pct(data.ctr ?? 0)} tint="bg-pink-50 text-pink-600" />
            <KpiCard label="দৃশ্যমানতা" value={pct(data.view_rate ?? 0, 0)} tint="bg-emerald-50 text-emerald-600" />
          </div>
          {daily.length > 0 && (
            <Card>
              <div className="flex flex-wrap items-center gap-4 mb-2 text-[12px]">
                <p className="text-[13px] font-semibold mr-auto">দিন অনুযায়ী</p>
                <span className="flex items-center gap-1.5">
                  <i className="inline-block w-3 h-3 rounded-sm bg-[#00b894]/40" /> ইমপ্রেশন
                </span>
                <span className="flex items-center gap-1.5">
                  <i className="inline-block w-3 h-3 rounded-sm bg-[#257de0]" /> নিশ্চিত দেখা
                </span>
              </div>
              <div className="flex items-end gap-1 h-32" aria-label="দিনভিত্তিক ইমপ্রেশন ও নিশ্চিত দেখা">
                {daily.map((d) => (
                  <div
                    key={d.day}
                    className="relative flex-1 min-w-[4px] h-full flex items-end"
                    title={`${d.day}: ${d.srv ?? d.imp} ইমপ্রেশন, ${d.imp} নিশ্চিত দেখা, ${d.clk} ক্লিক`}
                  >
                    <div className="absolute inset-x-0 bottom-0 rounded-t bg-[#00b894]/40" style={{ height: `${Math.max(2, ((d.srv ?? d.imp) / max) * 100)}%` }} />
                    <div className="absolute inset-x-[18%] bottom-0 rounded-t bg-[#257de0]" style={{ height: `${Math.max(d.imp ? 2 : 0, (d.imp / max) * 100)}%` }} />
                  </div>
                ))}
              </div>
            </Card>
          )}
          {devTotal > 0 && (
            <Card>
              <p className="text-[13px] font-semibold mb-2">ডিভাইস (নিশ্চিত দেখা)</p>
              <div className="flex h-7 gap-0.5 rounded-lg overflow-hidden text-[11.5px] text-white">
                {(data.devices ?? []).map((r, i) => (
                  <div key={r.val} className="grid place-items-center" style={{ flex: r.imp, background: ['#257de0', '#f47230', '#00b894'][i % 3] }} title={`${DEV[r.val] ?? r.val}: ${r.imp}`}>
                    {r.imp / devTotal >= 0.12 ? pct((100 * r.imp) / devTotal, 0) : ''}
                  </div>
                ))}
              </div>
              <div className="mt-2 flex flex-wrap gap-4 text-[12px]">
                {(data.devices ?? []).map((r, i) => (
                  <span key={r.val} className="flex items-center gap-1.5">
                    <i className="inline-block w-3 h-3 rounded-sm" style={{ background: ['#257de0', '#f47230', '#00b894'][i % 3] }} />
                    {DEV[r.val] ?? r.val} {pct((100 * r.imp) / devTotal, 0)}
                  </span>
                ))}
              </div>
            </Card>
          )}
          <Card className="overflow-x-auto">
            <p className="text-[13px] font-semibold mb-2">জায়গা অনুযায়ী</p>
            {data.slots.length === 0 ? (
              <p className="text-[12.5px] text-neutral-400 py-4">এই সময়ে কোনো হিসাব নেই।</p>
            ) : (
              <table className="w-full text-[12.5px]">
                <thead>
                  <tr className="text-left text-neutral-500">
                    <th className="py-1.5 pr-3">জায়গা ও ট্র্যাকিং আইডি</th>
                    <th className="py-1.5 pr-3 text-right">ইমপ্রেশন</th>
                    <th className="py-1.5 pr-3 text-right">নিশ্চিত দেখা</th>
                    <th className="py-1.5 pr-3 text-right">ক্লিক</th>
                    <th className="py-1.5 text-right">হার</th>
                  </tr>
                </thead>
                <tbody>
                  {data.slots.map((s) => (
                    <tr key={s.slot + ':' + (s.client ?? 0)} className="border-t border-neutral-100">
                      <td className="py-1.5 pr-3">
                        {s.label}
                        {s.tid && <code className="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-[11px] text-neutral-600">{s.tid}</code>}
                      </td>
                      <td className="py-1.5 pr-3 text-right tabular-nums">{bnNumber(s.srv ?? s.imp)}</td>
                      <td className="py-1.5 pr-3 text-right tabular-nums">{bnNumber(s.imp)}</td>
                      <td className="py-1.5 pr-3 text-right tabular-nums">{bnNumber(s.clk)}</td>
                      <td className="py-1.5 text-right tabular-nums">{pct(s.ctr)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Card>
        </>
      )}
      <InsightLinks />
    </div>
  )
}

const KIND_LABEL: Record<AdOffsiteEntry['kind'], string> = {
  video: 'ভিডিওর কোণে বিজ্ঞাপন',
  youtube: 'YouTube',
  facebook: 'Facebook',
  photocard: 'ফটোকার্ড',
  print: 'ছাপা',
  other: 'অন্যান্য',
}

/** r50 — each client's private results page, its off-site entries, and the site audience. */
function InsightLinks() {
  const [list, setList] = useState<AdInsightClient[] | null>(null)
  const [audience, setAudience] = useState<AdAudience | null>(null)
  const [err, setErr] = useState('')
  const [open, setOpen] = useState(0)
  const [copied, setCopied] = useState(0)
  const load = () => {
    AdsApi.insight()
      .then((r) => {
        setList(r.clients)
        setAudience(r.audience)
      })
      .catch((e) => setErr(errText(e, 'ক্লায়েন্টের তালিকা আসেনি।')))
  }
  useEffect(load, [])

  const make = async (c: AdInsightClient, rotate = false) => {
    try {
      const r = await AdsApi.insightLink(c.id, rotate)
      setList((l) => (l ? l.map((x) => (x.id === c.id ? { ...x, link: r.link } : x)) : l))
      return r.link
    } catch (e) {
      setErr(errText(e, 'লিংক হয়নি।'))
      return ''
    }
  }
  const copy = async (c: AdInsightClient) => {
    const link = c.link || (await make(c))
    if (!link) return
    try {
      await navigator.clipboard.writeText(link)
      setCopied(c.id)
      setTimeout(() => setCopied(0), 1800)
    } catch {
      window.prompt('লিংকটি কপি করুন', link)
    }
  }
  const wa = async (c: AdInsightClient) => {
    const link = c.link || (await make(c))
    if (!link) return
    const to = (c.whatsapp || c.phone || '').replace(/[^\d]/g, '')
    const text = `আসসালামু আলাইকুম। মিহা নিউজে আপনার বিজ্ঞাপনের ফলাফল এই লিংকে সবসময় সরাসরি দেখতে পাবেন (প্রতি মিনিটে হালনাগাদ হয়):\n${link}`
    window.open(`https://wa.me/${to.startsWith('0') ? '88' + to : to}?text=${encodeURIComponent(text)}`, '_blank', 'noopener')
  }

  return (
    <>
      <Card>
        <p className="text-[13.5px] font-semibold">ক্লায়েন্টের নিজস্ব ফলাফলের পাতা</p>
        <p className="text-[12px] text-neutral-500 mb-3">
          কোনো জায়গায় ক্লায়েন্ট বেছে সংরক্ষণ করলেই তাঁর গোপন লিংক তৈরি হয়ে যায়। লিংকে লগইন লাগে না; ইমপ্রেশন, নিশ্চিত দেখা, ক্লিক, ডিভাইস, সময়, সাইটের অংশ, আনুমানিক
          পূর্বাভাস ও পরামর্শ রঙিন চার্টে দেখায়, প্রতি মিনিটে হালনাগাদ হয়। “নতুন লিংক” দিলে পুরোনোটি আর কাজ করে না।
        </p>
        {err && <Banner kind="error">{err}</Banner>}
        {!list ? (
          <Spinner />
        ) : list.length === 0 ? (
          <p className="text-[12.5px] text-neutral-400">এখনো কোনো ক্লায়েন্ট নেই।</p>
        ) : (
          <ul className="divide-y divide-neutral-100" data-testid="insight-links">
            {list.map((c) => (
              <li key={c.id} className="py-2.5">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold text-[13.5px] mr-1">{c.name}</span>
                  {c.slots.length > 0 && <Badge tone="emerald">{bnNumber(c.slots.length)}টি জায়গা</Badge>}
                  {!c.active && !c.link && <span className="text-[11.5px] text-neutral-400">এখন কোনো জায়গা নেই</span>}
                  <span className="ml-auto flex flex-wrap gap-1.5">
                    {c.link && (
                      <a className="inline-flex items-center gap-1 rounded-lg border border-neutral-200 px-2.5 py-1 text-[12px]" href={c.link} target="_blank" rel="noopener noreferrer">
                        <IconExternalLink className="w-3.5 h-3.5" /> খুলুন
                      </a>
                    )}
                    <button type="button" className="rounded-lg border border-neutral-200 px-2.5 py-1 text-[12px]" onClick={() => void copy(c)}>
                      {copied === c.id ? 'কপি হয়েছে ✓' : c.link ? 'লিংক কপি' : 'লিংক তৈরি ও কপি'}
                    </button>
                    <button type="button" className="rounded-lg bg-[#128c4a] px-2.5 py-1 text-[12px] text-white" onClick={() => void wa(c)}>
                      WhatsApp-এ পাঠান
                    </button>
                    {c.link && (
                      <button
                        type="button"
                        className="rounded-lg border border-neutral-200 px-2.5 py-1 text-[12px] text-neutral-500"
                        onClick={() => {
                          if (window.confirm('নতুন লিংক দিলে আগের লিংক আর খুলবে না। চালিয়ে যাবেন?')) void make(c, true)
                        }}
                      >
                        নতুন লিংক
                      </button>
                    )}
                    <button type="button" className="rounded-lg border border-neutral-200 px-2.5 py-1 text-[12px]" onClick={() => setOpen((o) => (o === c.id ? 0 : c.id))}>
                      অন্যান্য প্রচার ({bnNumber(c.offsite.length)})
                    </button>
                  </span>
                </div>
                {c.slots.length > 0 && (
                  <div className="mt-1 flex flex-wrap gap-1.5">
                    {c.slots.map((s) => (
                      <code key={s.slot} className={`rounded px-1.5 py-0.5 text-[11px] ${s.live ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-500'}`} title={s.label}>
                        {s.tid}
                      </code>
                    ))}
                  </div>
                )}
                {open === c.id && (
                  <OffsiteEditor
                    client={c}
                    onSaved={(entries) => setList((l) => (l ? l.map((x) => (x.id === c.id ? { ...x, offsite: entries } : x)) : l))}
                  />
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
      <AudienceCard value={audience} onSaved={setAudience} />
    </>
  )
}

function OffsiteEditor({ client, onSaved }: { client: AdInsightClient; onSaved: (e: AdOffsiteEntry[]) => void }) {
  const blank: AdOffsiteEntry = { date: today(), kind: 'video', title: '', link: '', views: 0, clicks: 0, note: '' }
  const [rows, setRows] = useState<AdOffsiteEntry[]>(client.offsite)
  const [draft, setDraft] = useState<AdOffsiteEntry>(blank)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  const save = async (next: AdOffsiteEntry[]) => {
    setBusy(true)
    setMsg('')
    try {
      const r = await AdsApi.saveOffsite(client.id, next)
      setRows(r.entries)
      onSaved(r.entries)
      setMsg('সংরক্ষিত ✓')
    } catch (e) {
      setMsg(errText(e, 'হয়নি।'))
    } finally {
      setBusy(false)
    }
  }
  return (
    <div className="mt-3 rounded-xl bg-neutral-50 p-3 space-y-3" data-testid="offsite-editor">
      <p className="text-[12px] text-neutral-600">
        ভিডিওর কোণের বিজ্ঞাপন YouTube/Facebook-এ কতবার দেখা হলো, ছাপা বিজ্ঞাপন ইত্যাদি — নিজে বসান। ক্লায়েন্টের পাতায় এগুলো “প্রকাশকের দেওয়া হিসাব” নামে আলাদা দেখায়, ওয়েবসাইটের
        স্বয়ংক্রিয় হিসাবের সঙ্গে মেশানো হয় না। প্ল্যাটফর্মে যে সংখ্যা দেখাচ্ছে ঠিক সেটাই দিন।
      </p>
      {rows.length > 0 && (
        <ul className="space-y-1.5 text-[12.5px]">
          {rows.map((r, i) => (
            <li key={r.id ?? i} className="flex flex-wrap items-center gap-2 rounded-lg bg-white px-2.5 py-1.5">
              <Badge tone="blue">{KIND_LABEL[r.kind]}</Badge>
              <span className="font-medium">{r.title || r.link}</span>
              <span className="text-neutral-500">
                {r.date} · {bnNumber(r.views)} দেখা{r.clicks ? ` · ${bnNumber(r.clicks)} ক্লিক` : ''}
              </span>
              <button type="button" className="ml-auto text-red-600" aria-label="মুছুন" onClick={() => void save(rows.filter((_, k) => k !== i))} disabled={busy}>
                <IconTrash className="w-4 h-4" />
              </button>
            </li>
          ))}
        </ul>
      )}
      <div className="grid gap-2 sm:grid-cols-6 text-[12.5px]">
        <input type="date" className={inputClass + ' sm:col-span-1'} value={draft.date} onChange={(e) => setDraft({ ...draft, date: e.target.value })} />
        <select className={inputClass + ' sm:col-span-1'} value={draft.kind} onChange={(e) => setDraft({ ...draft, kind: e.target.value as AdOffsiteEntry['kind'] })}>
          {(Object.keys(KIND_LABEL) as AdOffsiteEntry['kind'][]).map((k) => (
            <option key={k} value={k}>
              {KIND_LABEL[k]}
            </option>
          ))}
        </select>
        <input className={inputClass + ' sm:col-span-2'} placeholder="শিরোনাম (যেমন ভিডিওর নাম)" value={draft.title} onChange={(e) => setDraft({ ...draft, title: e.target.value })} />
        <input className={inputClass + ' sm:col-span-2'} placeholder="লিংক (ঐচ্ছিক)" value={draft.link} onChange={(e) => setDraft({ ...draft, link: e.target.value })} />
        <input type="number" min={0} className={inputClass + ' sm:col-span-1'} placeholder="দেখা" value={draft.views || ''} onChange={(e) => setDraft({ ...draft, views: Number(e.target.value) || 0 })} />
        <input type="number" min={0} className={inputClass + ' sm:col-span-1'} placeholder="ক্লিক (ঐচ্ছিক)" value={draft.clicks || ''} onChange={(e) => setDraft({ ...draft, clicks: Number(e.target.value) || 0 })} />
        <input className={inputClass + ' sm:col-span-3'} placeholder="নোট (যেমন: YouTube Studio, ২৮ নভেম্বর পর্যন্ত)" value={draft.note} onChange={(e) => setDraft({ ...draft, note: e.target.value })} />
        <Button
          className="sm:col-span-1"
          disabled={busy || (!draft.title.trim() && !draft.link.trim())}
          onClick={() => {
            void save([draft, ...rows])
            setDraft(blank)
          }}
        >
          <IconPlus className="w-4 h-4" /> যোগ
        </Button>
      </div>
      {msg && <p className="text-[12px] text-neutral-600">{msg}</p>}
    </div>
  )
}

const AGES = ['13-17', '18-24', '25-34', '35-44', '45-54', '55+'] as const

function AudienceCard({ value, onSaved }: { value: AdAudience | null; onSaved: (a: AdAudience) => void }) {
  const empty: AdAudience = { on: false, male: 0, female: 0, ages: { '13-17': 0, '18-24': 0, '25-34': 0, '35-44': 0, '45-54': 0, '55+': 0 }, source: 'Google Analytics', updated: today() }
  const [a, setA] = useState<AdAudience>(value ?? empty)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  useEffect(() => setA(value ?? empty), [value]) // eslint-disable-line react-hooks/exhaustive-deps
  const save = async () => {
    setBusy(true)
    setMsg('')
    try {
      const r = await AdsApi.saveAudience(a)
      onSaved(r.audience)
      setMsg('সংরক্ষিত ✓')
    } catch (e) {
      setMsg(errText(e, 'হয়নি।'))
    } finally {
      setBusy(false)
    }
  }
  const n = (v: string) => Math.max(0, Math.min(100, Number(v) || 0))
  return (
    <Card>
      <p className="text-[13.5px] font-semibold">পাঠকের লিঙ্গ ও বয়স (সাইটের গড়)</p>
      <p className="text-[12px] text-neutral-500 mb-3">
        মিহা কোনো পাঠকের লিঙ্গ বা বয়স সংগ্রহ করে না, তাই এটা স্বয়ংক্রিয়ভাবে হয় না। Google Analytics-এ (Google signals চালু থাকলে) Demographics রিপোর্টে সাইটের পাঠকের হিসাব
        থাকে — সেখান থেকে সংখ্যাগুলো বসালে ক্লায়েন্টের পাতায় “মিহার সব পাঠকের গড়” লেবেলসহ দেখাবে। এটা নির্দিষ্ট বিজ্ঞাপনের দর্শকের হিসাব নয়, পাতাতেও তা বলা থাকে।
      </p>
      <label className="flex items-center gap-2 text-[13px] mb-3">
        <input type="checkbox" checked={a.on} onChange={(e) => setA({ ...a, on: e.target.checked })} /> ক্লায়েন্টের পাতায় দেখান
      </label>
      <div className="grid gap-2 grid-cols-2 sm:grid-cols-4 text-[12.5px]">
        <label className="space-y-1">
          <span className="block">পুরুষ %</span>
          <input type="number" min={0} max={100} className={inputClass} value={a.male} onChange={(e) => setA({ ...a, male: n(e.target.value) })} />
        </label>
        <label className="space-y-1">
          <span className="block">নারী %</span>
          <input type="number" min={0} max={100} className={inputClass} value={a.female} onChange={(e) => setA({ ...a, female: n(e.target.value) })} />
        </label>
        <label className="space-y-1">
          <span className="block">উৎস</span>
          <input className={inputClass} value={a.source} onChange={(e) => setA({ ...a, source: e.target.value })} />
        </label>
        <label className="space-y-1">
          <span className="block">যে তারিখের হিসাব</span>
          <input type="date" className={inputClass} value={a.updated} onChange={(e) => setA({ ...a, updated: e.target.value })} />
        </label>
        {AGES.map((k) => (
          <label key={k} className="space-y-1">
            <span className="block">{bnNumber(k)} বছর %</span>
            <input type="number" min={0} max={100} className={inputClass} value={a.ages[k]} onChange={(e) => setA({ ...a, ages: { ...a.ages, [k]: n(e.target.value) } })} />
          </label>
        ))}
      </div>
      <div className="mt-3 flex items-center gap-3">
        <Button onClick={() => void save()} disabled={busy}>
          {busy ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন'}
        </Button>
        {msg && <span className="text-[12.5px] text-neutral-600">{msg}</span>}
      </div>
    </Card>
  )
}

/** Size guide, label style, ad-desk contact and the landing pages. */
function Guide({ data, onSaved }: { data: AdsOverview; onSaved: () => void }) {
  const [g, setG] = useState<AdGlobals | null>(data.globals ?? null)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState<{ k: 'success' | 'error'; t: string } | null>(null)
  const [pageBusy, setPageBusy] = useState('')
  useEffect(() => setG(data.globals ?? null), [data.globals])

  const save = async () => {
    if (!g) return
    setBusy(true)
    setMsg(null)
    try {
      await AdsApi.saveGlobals({ label_style: g.label_style, ad_phone: g.ad_phone, ad_whatsapp: g.ad_whatsapp, ad_email: g.ad_email })
      setMsg({ k: 'success', t: 'সংরক্ষিত ✓' })
      onSaved()
    } catch (e) {
      setMsg({ k: 'error', t: errText(e, 'হয়নি।') })
    } finally {
      setBusy(false)
    }
  }

  const makePage = async (kind: 'advertise' | 'specs' | 'app') => {
    setPageBusy(kind)
    setMsg(null)
    try {
      const r = await AdsApi.createPage(kind)
      setMsg({ k: 'success', t: `পাতা তৈরি: ${r.page.url}${r.canvas ? ' (ফাঁকা ক্যানভাস, হেডার-ফুটার ছাড়া)' : ''}` })
      onSaved()
    } catch (e) {
      setMsg({ k: 'error', t: errText(e, 'হয়নি।') })
    } finally {
      setPageBusy('')
    }
  }

  const groups: { id: 'web' | 'video' | 'card'; label: string }[] = [
    { id: 'web', label: 'ওয়েবসাইট' },
    { id: 'video', label: 'ভিডিও' },
    { id: 'card', label: 'ফটোকার্ড ও স্পনসরড' },
  ]
  const specs = data.specs ?? []
  const pages = data.pages

  return (
    <div className="space-y-4">
      {g && (
        <Card>
          <p className="text-[13.5px] font-semibold mb-2">“বিজ্ঞাপন” চিহ্ন কীভাবে দেখাবে</p>
          <div className="grid gap-2 sm:grid-cols-2">
            {(
              [
                ['corner', 'ছবির কোণায় ছোট ⓘ — ছুঁলে/মাউস নিলে “বিজ্ঞাপন” লেখা আসে (পরামর্শ)'],
                ['side', 'ছবির ওপরে বাঁ পাশে ছোট লেখা'],
                ['top', 'ছবির ওপরে মাঝখানে ছোট লেখা'],
                ['none', 'কোনো চিহ্ন নয় (স্পনসর নীতির কারণে পরামর্শ নয়)'],
              ] as const
            ).map(([v, l]) => (
              <label key={v} className={`flex items-start gap-2 rounded-lg border px-3 py-2 text-[12.5px] cursor-pointer ${g.label_style === v ? 'border-brand-600 bg-brand-50' : 'border-neutral-200'}`}>
                <input type="radio" name="label_style" checked={g.label_style === v} onChange={() => setG({ ...g, label_style: v })} data-testid={`label-${v}`} />
                {l}
              </label>
            ))}
          </div>
          <p className="text-[13.5px] font-semibold mt-4 mb-2">বিজ্ঞাপন বিভাগের যোগাযোগ (ল্যান্ডিং পেজের বোতামে বসে)</p>
          <div className="grid gap-2 sm:grid-cols-3">
            <input className={inputClass} placeholder="ফোন (যেমন ০১৭…)" value={g.ad_phone} onChange={(e) => setG({ ...g, ad_phone: e.target.value })} />
            <input className={inputClass} placeholder="হোয়াটসঅ্যাপ (৮৮০১৭…)" value={g.ad_whatsapp} onChange={(e) => setG({ ...g, ad_whatsapp: e.target.value })} />
            <input className={inputClass} placeholder="ইমেইল" value={g.ad_email} onChange={(e) => setG({ ...g, ad_email: e.target.value })} />
          </div>
          <div className="mt-3 flex items-center gap-3">
            <Button onClick={() => void save()} disabled={busy} data-testid="guide-save">
              {busy ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন'}
            </Button>
          </div>
        </Card>
      )}

      {pages && (
        <Card>
          <p className="text-[13.5px] font-semibold mb-1">ল্যান্ডিং পাতা</p>
          <p className="text-[12px] text-neutral-500 mb-3">এক চাপে পাতা তৈরি হয় — হেডার-ফুটার ছাড়া পূর্ণ প্রস্থে (“পাতা — ফাঁকা ক্যানভাস” টেমপ্লেট)। আগে থাকলে সেটিকেই ক্যানভাসে বদলায়।</p>
          <div className="space-y-2">
            {(
              [
                ['advertise', 'বিজ্ঞাপন দিন — মিহা নিউজে কেন বিজ্ঞাপন', '[meeha_advertise]'],
                ['specs', 'বিজ্ঞাপনের মাপ ও নিয়ম (ডিজাইনারের জন্য)', '[meeha_ad_specs]'],
                ['app', 'মিহা সংবাদদাতা অ্যাপ', '[meeha_app_landing]'],
              ] as const
            ).map(([k, l, code]) => {
              const p = pages[k]
              return (
                <div key={k} className="flex flex-wrap items-center gap-2 rounded-lg border border-neutral-200 px-3 py-2">
                  <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-medium">{l}</span>
                    <code className="text-[11px] text-neutral-500">{code}</code>
                    {p && <span className="block text-[11.5px] text-neutral-500 truncate">{p.url}{p.canvas ? ' · ক্যানভাস' : ''}{p.status !== 'publish' ? ` · ${p.status}` : ''}</span>}
                  </span>
                  {p && (
                    <a href={p.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[12.5px] text-brand-700 font-medium">
                      <IconExternalLink className="w-3.5 h-3.5" /> খুলুন
                    </a>
                  )}
                  {(!p || !p.canvas) && (
                    <Button size="sm" variant={p ? 'secondary' : 'primary'} onClick={() => void makePage(k)} disabled={pageBusy !== ''} data-testid={`page-${k}`}>
                      {pageBusy === k ? 'হচ্ছে…' : p ? 'ক্যানভাসে বদলান' : 'পাতা তৈরি করুন'}
                    </Button>
                  )}
                </div>
              )
            })}
          </div>
        </Card>
      )}
      {msg && <Banner kind={msg.k}>{msg.t}</Banner>}

      <Card>
        <p className="text-[13.5px] font-semibold mb-2">সাইজ গাইড — বিজ্ঞাপনদাতা ও ডিজাইনারকে এই মাপগুলোই দিন</p>
        {specs.length > 0 ? (
          groups.map((gr) => {
            const rows = specs.filter((s) => s.group === gr.id)
            if (!rows.length) return null
            return (
              <div key={gr.id} className="mb-4 overflow-x-auto">
                <p className="text-[12px] font-bold text-neutral-500 uppercase tracking-wide mb-1">{gr.label}</p>
                <table className="w-full text-[12.5px] min-w-[620px]">
                  <thead>
                    <tr className="text-left text-neutral-500">
                      <th className="py-1.5 pr-3">জায়গা</th>
                      <th className="py-1.5 pr-3">ডেস্কটপ</th>
                      <th className="py-1.5 pr-3">মোবাইল</th>
                      <th className="py-1.5">ফাইল</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((r) => (
                      <tr key={r.id} className="border-t border-neutral-100 align-top">
                        <td className="py-1.5 pr-3">
                          <span className="font-medium">{r.name}</span>
                          <span className="block text-[11px] text-neutral-500">{r.where}</span>
                          {r.note && <span className="block text-[11px] text-neutral-400">{r.note}</span>}
                        </td>
                        <td className="py-1.5 pr-3 font-medium text-brand-700 tabular-nums whitespace-nowrap">{r.desktop}</td>
                        <td className="py-1.5 pr-3 font-medium text-brand-700 tabular-nums whitespace-nowrap">{r.mobile}</td>
                        <td className="py-1.5 text-neutral-600">{r.formats}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )
          })
        ) : (
          <ul className="grid gap-2 sm:grid-cols-2">
            {data.size_guide.map((x) => (
              <li key={x.name} className="rounded-lg bg-neutral-50 px-3 py-2">
                <span className="text-[13px] font-semibold">{x.name}</span>
                <span className="ml-2 text-[13px] text-brand-700 font-medium tabular-nums">{x.px}</span>
                <span className="block text-[11.5px] text-neutral-500">{x.note}</span>
              </li>
            ))}
          </ul>
        )}
        <p className="text-[11.5px] text-neutral-500">অ্যানিমেটেড GIF সর্বোচ্চ ১৫০ KB। বড় হলে WebP বা MP4 করুন।</p>
      </Card>
    </div>
  )
}

/** Photocard-strip rows (১০৮০×১২০ creatives used by the photocard studio). */
function StripEditor({ data, onSaved }: { data: AdsOverview; onSaved: () => void }) {
  const [rows, setRows] = useState(data.strip.map((r) => ({ label: r.label, image: r.image, link: r.link, on: r.on })))
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')
  const [picking, setPicking] = useState<number | null>(null)

  useEffect(() => setRows(data.strip.map((r) => ({ label: r.label, image: r.image, link: r.link, on: r.on }))), [data])

  const save = async () => {
    setSaving(true)
    setErr('')
    try {
      await AdsApi.saveStrip(rows.map((r) => ({ label: r.label, image: r.image?.id ?? 0, link: r.link, on: r.on ? 1 : 0 })))
      onSaved()
    } catch (e) {
      setErr(errText(e, 'সংরক্ষণ হয়নি।'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <p className="text-[13.5px] font-semibold mb-1">ফটোকার্ড স্টুডিওর বিজ্ঞাপন স্ট্রিপ (১০৮০×১২০)</p>
      <p className="text-[12px] text-neutral-500 mb-3">ডেস্ক ফটোকার্ড বানানোর সময় “নিচে বিজ্ঞাপন স্ট্রিপ” হিসেবে এগুলো বেছে নিতে পারে।</p>
      <div className="space-y-2">
        {rows.map((r, i) => (
          <div key={i} className="flex flex-wrap items-center gap-2 rounded-lg border border-neutral-200 p-2">
            <input type="checkbox" checked={r.on} onChange={(e) => setRows((rs) => rs.map((x, j) => (j === i ? { ...x, on: e.target.checked } : x)))} />
            {r.image ? <img src={r.image.url} alt="" className="h-8 rounded border border-neutral-100" /> : <span className="text-[12px] text-neutral-400">ছবি নেই</span>}
            <input className={inputClass + ' !w-36'} placeholder="নাম" value={r.label} onChange={(e) => setRows((rs) => rs.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))} />
            <input className={inputClass + ' flex-1 min-w-[160px]'} placeholder="লিংক" value={r.link} onChange={(e) => setRows((rs) => rs.map((x, j) => (j === i ? { ...x, link: e.target.value } : x)))} />
            <Button size="sm" variant="secondary" onClick={() => setPicking(i)}>
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
      {picking !== null && (
        <MediaGallery
          title="স্ট্রিপের ছবি (১০৮০×১২০)"
          source="ads"
          upload
          onClose={() => setPicking(null)}
          onSelect={(img) => {
            const i = picking
            setRows((rs) => rs.map((r, j) => (j === i ? { ...r, image: fromLibrary(img) } : r)))
            setPicking(null)
          }}
        />
      )}
    </Card>
  )
}
