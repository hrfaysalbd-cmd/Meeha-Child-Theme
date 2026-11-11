/**
 * r43 — স্টেশনারি: লেটারহেড, নিয়োগপত্র, প্যাড, ভিজিটিং কার্ড, ইনভাইটেশন,
 * সনদ, খাম, ফিতা, টি-শার্ট, স্টিকার, কলম — every design editable, with a
 * mockup, PDF/JPG/PNG/print, a blank "raw" template, and saved copies.
 * মিহার তথ্য (the office details every design uses) is the third tab.
 */
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { BrandApi, IdAdminApi, appointmentOf, type IdCard, type SavedDoc } from '../brand/api'
import { DESIGNS, KINDS, designOf, type Ctx, type Design, type Values } from '../brand/stationery'
import { ExportBar, SvgBox, fileToDataUrl, useBrandFonts } from '../brand/ui'
import { exportPages } from '../brand/export'
import { ORG_FALLBACK, bnDigits, type BrandAssets, type OrgInfo } from '../brand/util'
import OrgInfoForm from '../components/brand/OrgInfoForm'
import { Banner, Button, Card, Field, PageHeader, Spinner, inputClass } from '../components/ui'

const CARD_KINDS = new Set(['visiting', 'idcard', 'sticker', 'lanyard', 'invitation'])

export default function Stationery() {
  const [sp, setSp] = useSearchParams()
  const tab = sp.get('tab') || 'gallery'
  const dKey = sp.get('d')
  const fonts = useBrandFonts()
  const [org, setOrg] = useState<OrgInfo | null>(null)
  const [assets, setAssets] = useState<BrandAssets>({})
  const [err, setErr] = useState('')
  const [saved, setSaved] = useState<SavedDoc[] | null>(null)

  useEffect(() => {
    BrandApi.org()
      .then((r) => {
        setOrg({ ...ORG_FALLBACK, ...r.org })
        setAssets(r.assets ?? {})
      })
      .catch((e) => {
        setErr(e.message)
        setOrg(ORG_FALLBACK)
      })
    BrandApi.docs()
      .then((r) => setSaved(r.items))
      .catch(() => setSaved([]))
  }, [])

  const ctx: Ctx | null = org ? { org, assets } : null
  const go = (p: Record<string, string | null>) => {
    const n = new URLSearchParams(sp)
    Object.entries(p).forEach(([k, v]) => (v === null ? n.delete(k) : n.set(k, v)))
    setSp(n)
  }

  if (!ctx || !fonts) return <Spinner className="mt-10" />

  if (dKey) {
    const docId = Number(sp.get('doc') || 0)
    const doc = docId ? saved?.find((s) => s.id === docId) : undefined
    if (docId && !saved) return <Spinner className="mt-10" />
    return (
      <Editor
        key={`${dKey}-${docId}-${sp.get('card') ?? ''}`}
        design={designOf(dKey)}
        ctx={ctx}
        doc={doc}
        cardId={Number(sp.get('card') || 0)}
        saved={saved ?? []}
        onBack={() => go({ d: null, doc: null, card: null })}
        onSaved={(d) => {
          setSaved((s) => [d, ...(s ?? []).filter((x) => x.id !== d.id)])
          go({ doc: String(d.id) })
        }}
      />
    )
  }

  return (
    <div className="max-w-6xl">
      <PageHeader title="স্টেশনারি" subtitle="মিহার সব প্রিন্ট ডিজাইন — এডিট করুন, মকআপ দেখুন, PDF/JPG নিন। অফিসের তথ্য একবার “মিহার তথ্য”-তে দিলেই সব ডিজাইনে বসে যায়।" />
      {err && <Banner kind="error">{err}</Banner>}
      <div className="flex gap-1.5 mb-5 overflow-x-auto">
        {[
          ['gallery', '🎨 ডিজাইন গ্যালারি'],
          ['saved', `💾 সংরক্ষিত ডিজাইন${saved?.length ? ` (${bnDigits(saved.length)})` : ''}`],
          ['org', '🏢 মিহার তথ্য'],
        ].map(([k, l]) => (
          <button key={k} onClick={() => go({ tab: k })} className={`px-3.5 py-2 rounded-xl text-[13px] font-semibold whitespace-nowrap ${tab === k ? 'bg-brand-600 text-white' : 'bg-white border border-neutral-200 text-neutral-600'}`}>
            {l}
          </button>
        ))}
      </div>
      {tab === 'org' && (
        <OrgInfoForm
          onSaved={(o, a) => {
            setOrg({ ...ORG_FALLBACK, ...o })
            setAssets(a)
          }}
        />
      )}
      {tab === 'saved' && <SavedList saved={saved} onOpen={(d) => go({ d: d.design, doc: String(d.id) })} onDelete={(id) => setSaved((s) => (s ?? []).filter((x) => x.id !== id))} />}
      {tab === 'gallery' && <Gallery ctx={ctx} onOpen={(k) => go({ d: k })} />}
    </div>
  )
}

function Gallery({ ctx, onOpen }: { ctx: Ctx; onOpen: (key: string) => void }) {
  const mocks = useMemo(() => DESIGNS.map((d) => ({ d, svg: d.mockup(d.defaults(ctx.org), ctx) })), [ctx])
  const [rawBusy, setRawBusy] = useState('')
  return (
    <div className="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
      {mocks.map(({ d, svg }) => {
        const kind = KINDS.find((k) => k.key === d.kind)
        return (
          <Card key={d.key} className="overflow-hidden flex flex-col">
            <button onClick={() => onOpen(d.key)} className="block bg-neutral-100 hover:opacity-95">
              <SvgBox svg={svg} shadow={false} />
            </button>
            <div className="p-3.5 flex-1 flex flex-col">
              <p className="text-[14.5px] font-bold text-neutral-900">
                {kind?.icon} {d.name}
              </p>
              <p className="text-[12.5px] text-neutral-500 mt-0.5 flex-1">{d.about}</p>
              <div className="flex gap-2 mt-3">
                <Button size="sm" onClick={() => onOpen(d.key)}>
                  এডিট করুন
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={!!rawBusy}
                  onClick={async () => {
                    setRawBusy(d.key)
                    try {
                      await exportPages(d.render(d.defaults(ctx.org), { ...ctx, raw: true }), 'pdf', `meeha-${d.key}-raw`)
                    } finally {
                      setRawBusy('')
                    }
                  }}
                >
                  {rawBusy === d.key ? 'তৈরি হচ্ছে…' : '⬇ খালি টেমপ্লেট'}
                </Button>
              </div>
            </div>
          </Card>
        )
      })}
    </div>
  )
}

function SavedList({ saved, onOpen, onDelete }: { saved: SavedDoc[] | null; onOpen: (d: SavedDoc) => void; onDelete: (id: number) => void }) {
  if (!saved) return <Spinner />
  if (!saved.length) return <p className="text-[13.5px] text-neutral-500">এখনো কোনো ডিজাইন সংরক্ষণ করা হয়নি। গ্যালারি থেকে একটি ডিজাইন খুলে “সংরক্ষণ” চাপুন।</p>
  return (
    <Card className="divide-y divide-neutral-100">
      {saved.map((d) => (
        <div key={d.id} className="flex items-center gap-3 px-4 py-3">
          <span className="text-xl">{KINDS.find((k) => k.key === d.kind)?.icon ?? '📄'}</span>
          <button onClick={() => onOpen(d)} className="flex-1 min-w-0 text-left">
            <p className="text-[14px] font-semibold text-neutral-900 truncate">{d.title || designOf(d.design).name}</p>
            <p className="text-[12px] text-neutral-500">
              {designOf(d.design).name} · {d.by} · {new Date(d.updated_at).toLocaleDateString('bn-BD')}
            </p>
          </button>
          <Button size="sm" variant="secondary" onClick={() => onOpen(d)}>
            খুলুন
          </Button>
          <Button
            size="sm"
            variant="danger"
            onClick={async () => {
              if (!confirm('ডিজাইনটি মুছে ফেলবেন?')) return
              await BrandApi.deleteDoc(d.id)
              onDelete(d.id)
            }}
          >
            মুছুন
          </Button>
        </div>
      ))}
    </Card>
  )
}

function Editor({ design, ctx, doc, cardId, saved, onBack, onSaved }: { design: Design; ctx: Ctx; doc?: SavedDoc; cardId: number; saved: SavedDoc[]; onBack: () => void; onSaved: (d: SavedDoc) => void }) {
  const navigate = useNavigate()
  const start = useMemo<Values>(() => {
    const base = design.defaults(ctx.org)
    if (doc) return { ...base, ...doc.data }
    // a running number for স্মারক নং (count of saved letters of this kind + 1)
    if (base.ref && base.ref.endsWith('/')) {
      const n = saved.filter((s) => s.kind === design.kind).length + 1
      base.ref = base.ref + bnDigits(String(n).padStart(3, '0'))
    }
    return base
  }, [design, ctx.org, doc, saved])
  const [v, setV] = useState<Values>(start)
  const [title, setTitle] = useState(doc?.title ?? '')
  const [view, setView] = useState<'design' | 'mockup'>('design')
  const [bleed, setBleed] = useState(false)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState<{ kind: 'error' | 'success' | 'info'; text: string } | null>(null)
  const [cards, setCards] = useState<IdCard[] | null>(null)
  const [cardQ, setCardQ] = useState('')

  useEffect(() => {
    if (design.key !== 'appointment' && design.key !== 'visiting' && design.key !== 'idcard') return
    IdAdminApi.cards('active')
      .then((r) => {
        setCards(r.items)
        if (cardId) {
          const c = r.items.find((x) => x.id === cardId)
          if (c) {
            setV((x) => ({ ...x, ...(design.key === 'appointment' ? appointmentOf(c) : { name_bn: c.fields.name_bn, name_en: c.fields.name_en, designation: c.fields.designation, phone: c.fields.phone, email: c.fields.email, area: c.area }) }))
            setMsg({ kind: 'info', text: `${c.fields.name_bn}-এর আইডি কার্ড থেকে তথ্য বসানো হয়েছে।` })
          }
        }
      })
      .catch(() => setCards([]))
  }, [design.key, cardId])

  const pages = useMemo(() => design.render(v, ctx, bleed && CARD_KINDS.has(design.kind) ? 3 : 0), [design, v, ctx, bleed])
  const mock = useMemo(() => (view === 'mockup' ? design.mockup(v, ctx) : ''), [design, v, ctx, view])
  const set = (k: string, val: string) => setV((x) => ({ ...x, [k]: val }))

  async function save(copy = false) {
    setBusy(true)
    setMsg(null)
    try {
      const r = await BrandApi.saveDoc({ id: copy ? undefined : doc?.id, kind: design.kind, design: design.key, title: title || `${design.name} — ${v.subject || v.name || v.name_bn || v.event || ''}`.trim(), data: v })
      onSaved(r.item)
      setMsg({ kind: 'success', text: 'সংরক্ষিত হয়েছে। “সংরক্ষিত ডিজাইন” থেকে যেকোনো সময় খুলে আবার এডিট করতে পারবেন।' })
    } catch (e) {
      setMsg({ kind: 'error', text: e instanceof Error ? e.message : 'সংরক্ষণ হয়নি।' })
    } finally {
      setBusy(false)
    }
  }

  const fileName = `meeha-${design.key}${v.name || v.name_bn ? '-' + (v.name || v.name_bn) : ''}`
  const filteredCards = (cards ?? []).filter((c) => !cardQ || c.fields.name_bn.includes(cardQ) || c.card_no.includes(cardQ.toUpperCase())).slice(0, 8)

  return (
    <div className="max-w-7xl">
      <div className="flex items-center gap-2 mb-4">
        <Button variant="ghost" size="sm" onClick={onBack}>
          ← গ্যালারি
        </Button>
        <h1 className="text-[19px] font-bold text-neutral-900">{design.name}</h1>
        <span className="text-[12.5px] text-neutral-500 hidden sm:inline">{design.about}</span>
      </div>
      {msg && <Banner kind={msg.kind}>{msg.text}</Banner>}
      <div className="grid lg:grid-cols-[minmax(320px,400px)_1fr] gap-5 items-start">
        <Card className="p-4 space-y-3 lg:sticky lg:top-3 lg:max-h-[calc(100vh-24px)] lg:overflow-y-auto">
          {design.presets && (
            <div>
              <p className="text-[12.5px] font-semibold text-neutral-600 mb-1.5">⚡ রেডিমেড</p>
              <div className="flex flex-wrap gap-1.5">
                {design.presets.map((p) => (
                  <button key={p.label} onClick={() => setV((x) => ({ ...x, ...p.values }))} className="px-2.5 py-1 rounded-full bg-brand-50 text-brand-700 text-[12px] font-medium hover:bg-brand-100">
                    {p.label}
                  </button>
                ))}
              </div>
            </div>
          )}
          {cards && cards.length > 0 && (
            <div className="rounded-xl bg-amber-50/60 border border-amber-100 p-2.5">
              <p className="text-[12.5px] font-semibold text-amber-800 mb-1">🪪 আইডি কার্ড থেকে পূরণ করুন</p>
              <input className={inputClass} placeholder="নাম বা কার্ড নম্বর" value={cardQ} onChange={(e) => setCardQ(e.target.value)} />
              {cardQ && (
                <div className="mt-1.5 space-y-1">
                  {filteredCards.map((c) => (
                    <button
                      key={c.id}
                      onClick={() => {
                        setV((x) => ({ ...x, ...(design.key === 'appointment' ? appointmentOf(c) : { name_bn: c.fields.name_bn, name_en: c.fields.name_en, designation: c.fields.designation, phone: c.fields.phone, email: c.fields.email, area: c.area, card_no: c.card_no, verify_url: c.verify_url, expires: c.expires_at, issued: c.issued_at, blood: c.fields.blood }) }))
                        setCardQ('')
                      }}
                      className="w-full text-left px-2 py-1.5 rounded-lg hover:bg-white text-[12.5px]"
                    >
                      <b>{c.fields.name_bn}</b> · {c.card_no} · {c.area}
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}
          {design.fields.map((f) => (
            <Field key={f.key} label={f.label}>
              {f.type === 'textarea' ? (
                <textarea rows={f.rows ?? 3} className={inputClass} value={v[f.key] ?? ''} placeholder={f.placeholder} onChange={(e) => set(f.key, e.target.value)} />
              ) : f.type === 'select' ? (
                <select className={inputClass} value={v[f.key] ?? ''} onChange={(e) => set(f.key, e.target.value)}>
                  {f.options?.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </select>
              ) : f.type === 'toggle' ? (
                <label className="flex items-center gap-2 text-[13px] text-neutral-700">
                  <input type="checkbox" checked={v[f.key] === '1'} onChange={(e) => set(f.key, e.target.checked ? '1' : '')} className="w-4 h-4 accent-brand-600" />
                  চালু
                </label>
              ) : f.type === 'image' ? (
                <div className="flex items-center gap-2">
                  {v[f.key] && <img src={v[f.key]} alt="" className="w-12 h-12 rounded-lg object-cover" />}
                  <label className="px-3 py-1.5 rounded-lg bg-neutral-100 text-[12.5px] font-semibold cursor-pointer">
                    {v[f.key] ? 'বদলান' : 'ছবি দিন'}
                    <input
                      type="file"
                      accept="image/*"
                      className="hidden"
                      onChange={async (e) => {
                        const file = e.target.files?.[0]
                        if (file) set(f.key, await fileToDataUrl(file, 800))
                        e.target.value = ''
                      }}
                    />
                  </label>
                  {v[f.key] && (
                    <button className="text-[12px] text-red-500" onClick={() => set(f.key, '')}>
                      সরান
                    </button>
                  )}
                </div>
              ) : (
                <input type={f.type === 'date' ? 'date' : 'text'} className={inputClass} value={v[f.key] ?? ''} placeholder={f.placeholder} onChange={(e) => set(f.key, e.target.value)} />
              )}
              {f.help && <p className="text-[11.5px] text-neutral-400 mt-1">{f.help}</p>}
            </Field>
          ))}
          <button onClick={() => setV(design.defaults(ctx.org))} className="text-[12.5px] text-neutral-500 underline">
            মিহার তথ্য থেকে আবার শুরু করুন
          </button>
        </Card>

        <div className="space-y-4 min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <div className="flex rounded-xl bg-neutral-100 p-1">
              {(
                [
                  ['design', 'ডিজাইন'],
                  ['mockup', 'মকআপ'],
                ] as const
              ).map(([k, l]) => (
                <button key={k} onClick={() => setView(k)} className={`px-3 py-1.5 rounded-lg text-[13px] font-semibold ${view === k ? 'bg-white shadow text-neutral-900' : 'text-neutral-500'}`}>
                  {l}
                </button>
              ))}
            </div>
            {CARD_KINDS.has(design.kind) && (
              <label className="flex items-center gap-1.5 text-[12.5px] text-neutral-600">
                <input type="checkbox" checked={bleed} onChange={(e) => setBleed(e.target.checked)} className="accent-brand-600" /> প্রিন্টারের জন্য ৩ মিমি ব্লিড
              </label>
            )}
          </div>
          <Card className="p-4 bg-neutral-50">
            {view === 'mockup' ? (
              <SvgBox svg={mock} shadow={false} className="rounded-xl overflow-hidden" />
            ) : (
              <div className={`grid gap-5 ${pages.length > 1 && pages[0].w < 150 ? 'sm:grid-cols-2' : ''}`}>
                {pages.map((p, i) => (
                  <div key={i} className={`mx-auto w-full ${p.w < 100 ? 'max-w-[340px]' : p.w > p.h ? 'max-w-[900px]' : 'max-w-[620px]'}`}>
                    {pages.length > 1 && <p className="text-[12px] text-neutral-500 mb-1">{p.name === 'front' ? 'সামনে' : p.name === 'back' ? 'পেছনে' : `পাতা ${bnDigits(i + 1)}`}</p>}
                    <SvgBox svg={p.svg} />
                  </div>
                ))}
              </div>
            )}
          </Card>
          <Card className="p-4 space-y-3">
            <p className="text-[13px] font-semibold text-neutral-700">ডাউনলোড</p>
            <ExportBar pages={() => (view === 'mockup' ? [{ svg: mock, w: 300, h: 200, name: 'mockup' }] : pages)} name={view === 'mockup' ? `${fileName}-mockup` : fileName} dpi={view === 'mockup' ? 150 : 300} onError={(m) => setMsg({ kind: 'error', text: m })} />
            <p className="text-[11.5px] text-neutral-400">PDF/JPG ৩০০ ডিপিআই (প্রেসে দেওয়ার মতো)। একদম ভেক্টর PDF চাইলে “প্রিন্ট” চাপুন → “Save as PDF”।</p>
            <div className="flex flex-wrap gap-2 pt-2 border-t border-neutral-100">
              <input className={`${inputClass} flex-1 min-w-[180px]`} placeholder="নাম দিয়ে সংরক্ষণ (যেমন: ডিসি অফিসে চিঠি)" value={title} onChange={(e) => setTitle(e.target.value)} />
              <Button onClick={() => save(false)} disabled={busy}>
                💾 {doc ? 'আপডেট' : 'সংরক্ষণ'}
              </Button>
              {doc && (
                <Button variant="secondary" onClick={() => save(true)} disabled={busy}>
                  নতুন কপি
                </Button>
              )}
              <Button variant="secondary" onClick={() => exportPages(design.render(design.defaults(ctx.org), { ...ctx, raw: true }), 'pdf', `meeha-${design.key}-raw`)}>
                ⬇ খালি টেমপ্লেট
              </Button>
              {design.key === 'appointment' && (
                <Button variant="ghost" onClick={() => navigate('/app/id-cards')}>
                  🪪 আইডি কার্ড ডেস্ক
                </Button>
              )}
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}
