/**
 * r43 — আইডি কার্ড ডেস্ক (publisher app).
 *
 *  অর্ডার      — applications/renewals: check the payment, fix the details,
 *                approve (the card appears in the journalist's app at once),
 *                then প্রিন্টে → পাঠানো → পৌঁছেছে.
 *  কার্ড       — every issued card: expiry, renew, extend, cancel/suspend,
 *                print files (one card with bleed, or an A4 sheet of 9),
 *                নিয়োগপত্র and visiting card in one tap.
 *  নতুন কার্ড  — the Publisher issues a card directly (editors, office, free).
 *  প্যাকেজ     — packages, bKash/Nagad numbers, validity, reminders, auto-renew.
 */
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  BrandApi,
  IdAdminApi,
  areaText,
  bnMoney,
  cardDataOf,
  visitingOf,
  type CardFields,
  type IdCard,
  type IdOrder,
  type IdSettings,
  type IdSummary,
  type Pkg,
  type PersonLite,
} from '../brand/api'
import { cardBack, cardFront, cardPages, cardSheet, ID_TEMPLATES, type CardData } from '../brand/idcard'
import { exportPages, printPages } from '../brand/export'
import { ExportBar, FlipCard, SvgBox, useBrandFonts } from '../brand/ui'
import { ORG_FALLBACK, bnDate, bnDigits, type BrandAssets, type OrgInfo } from '../brand/util'
import { visitingPages } from '../brand/visiting'
import { useAuth } from '../lib/AuthContext'
import { Badge, Banner, Button, Card, EmptyState, Field, KpiCard, PageHeader, Spinner, inputClass } from '../components/ui'

const ORDER_TABS: [string, string][] = [
  ['review', 'অনুমোদনের অপেক্ষায়'],
  ['awaiting', 'পেমেন্ট বাকি'],
  ['printing', 'প্রিন্ট/ডেলিভারি'],
  ['approved', 'অনুমোদিত'],
  ['rejected', 'প্রত্যাখ্যাত'],
  ['all', 'সব'],
]
const CARD_TABS: [string, string][] = [
  ['active', 'সক্রিয়'],
  ['expiring', '৩০ দিনে মেয়াদ শেষ'],
  ['expired', 'মেয়াদ শেষ'],
  ['cancelled', 'বাতিল'],
  ['suspended', 'স্থগিত'],
  ['all', 'সব'],
]
const STATUS_TONE: Record<string, 'amber' | 'emerald' | 'red' | 'blue' | 'neutral'> = {
  review: 'blue',
  awaiting: 'amber',
  approved: 'emerald',
  rejected: 'red',
  cancelled: 'neutral',
  active: 'emerald',
  expired: 'amber',
  suspended: 'red',
}
const STATUS_TEXT: Record<string, string> = {
  review: 'অপেক্ষায়',
  awaiting: 'পেমেন্ট বাকি',
  approved: 'অনুমোদিত',
  rejected: 'প্রত্যাখ্যাত',
  cancelled: 'বাতিল',
  active: 'সক্রিয়',
  expired: 'মেয়াদ শেষ',
  suspended: 'স্থগিত',
}
const FULFIL_TEXT: Record<string, string> = { printing: '🖨️ প্রিন্টে', shipped: '📦 পাঠানো হয়েছে', delivered: '✅ পৌঁছেছে' }
const REJECT_REASONS = ['ট্রানজেকশন আইডি মেলেনি', 'টাকার পরিমাণ কম', 'ছবি অস্পষ্ট — নতুন ছবি দিন', 'তথ্য অসম্পূর্ণ', 'এখনো সংবাদদাতা হিসেবে যাচাই হয়নি']

interface Brand {
  org: OrgInfo
  assets: BrandAssets
  terms: string[]
}

function useBrand(): Brand | null {
  const [b, setB] = useState<Brand | null>(null)
  useEffect(() => {
    Promise.all([BrandApi.org(), IdAdminApi.settings()])
      .then(([o, s]) => setB({ org: { ...ORG_FALLBACK, ...o.org }, assets: o.assets ?? {}, terms: s.settings.back_terms }))
      .catch(() => setB({ org: ORG_FALLBACK, assets: {}, terms: [] }))
  }, [])
  return b
}

const Overlay = ({ title, onClose, children, wide }: { title: string; onClose: () => void; children: React.ReactNode; wide?: boolean }) => (
  <div className="fixed inset-0 z-50 bg-black/40 flex justify-end" onClick={onClose}>
    <div className={`h-full w-full ${wide ? 'max-w-4xl' : 'max-w-2xl'} bg-neutral-50 overflow-y-auto shadow-2xl`} onClick={(e) => e.stopPropagation()}>
      <div className="sticky top-0 z-10 bg-white border-b border-neutral-100 px-5 py-3.5 flex items-center justify-between">
        <h2 className="text-[16px] font-bold text-neutral-900">{title}</h2>
        <button onClick={onClose} className="text-neutral-400 hover:text-neutral-700 text-xl leading-none">
          ×
        </button>
      </div>
      <div className="p-5">{children}</div>
    </div>
  </div>
)

export default function IdCards() {
  const [sp, setSp] = useSearchParams()
  const { isPublisher } = useAuth()
  const tab = sp.get('tab') || 'orders'
  const fonts = useBrandFonts()
  const brand = useBrand()
  const [sum, setSum] = useState<IdSummary | null>(null)
  const loadSum = useCallback(() => {
    IdAdminApi.summary().then(setSum).catch(() => undefined)
  }, [])
  useEffect(loadSum, [loadSum])
  const go = (p: Record<string, string | null>) => {
    const n = new URLSearchParams(sp)
    Object.entries(p).forEach(([k, v]) => (v === null ? n.delete(k) : n.set(k, v)))
    setSp(n)
  }
  if (!brand || !fonts) return <Spinner className="mt-10" />
  return (
    <div className="max-w-6xl">
      <PageHeader title="আইডি কার্ড ডেস্ক" subtitle="সাংবাদিকদের কার্ডের আবেদন, পেমেন্ট, অনুমোদন, প্রিন্ট, মেয়াদ ও রিনিউ — এক জায়গায়।" />
      {sum && (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
          <KpiCard label="অনুমোদনের অপেক্ষায়" value={bnDigits(sum.review)} />
          <KpiCard label="পেমেন্ট বাকি" value={bnDigits(sum.awaiting)} />
          <KpiCard label="প্রিন্ট/ডেলিভারি" value={bnDigits(sum.printing)} />
          <KpiCard label="সক্রিয় কার্ড" value={bnDigits(sum.active)} />
          <KpiCard label="৩০ দিনে মেয়াদ শেষ" value={bnDigits(sum.expiring)} />
          <KpiCard label="এই মাসের আয়" value={bnMoney(sum.income_month)} />
        </div>
      )}
      <div className="flex gap-1.5 mb-5 overflow-x-auto">
        {(
          [
            ['orders', '📥 অর্ডার'],
            ['cards', '🪪 কার্ড'],
            ...(isPublisher ? [['issue', '➕ নতুন কার্ড']] : []),
            ['settings', '⚙️ প্যাকেজ ও সেটিংস'],
          ] as [string, string][]
        ).map(([k, l]) => (
          <button key={k} onClick={() => go({ tab: k, status: null, order: null, card: null })} className={`px-3.5 py-2 rounded-xl text-[13px] font-semibold whitespace-nowrap ${tab === k ? 'bg-brand-600 text-white' : 'bg-white border border-neutral-200 text-neutral-600'}`}>
            {l}
          </button>
        ))}
      </div>
      {tab === 'orders' && <Orders brand={brand} status={sp.get('status') || (sum && !sum.review && sum.awaiting ? 'awaiting' : 'review')} openId={Number(sp.get('order') || 0)} go={go} onChange={loadSum} />}
      {tab === 'cards' && <Cards brand={brand} status={sp.get('status') || 'active'} openId={Number(sp.get('card') || 0)} go={go} onChange={loadSum} />}
      {tab === 'issue' && isPublisher && <Issue brand={brand} onDone={(c) => go({ tab: 'cards', status: 'active', card: String(c.id) })} />}
      {tab === 'settings' && <Settings />}
    </div>
  )
}

// ---------------------------------------------------------------------------
// অর্ডার
// ---------------------------------------------------------------------------

function previewData(o: IdOrder, f: CardFields, photo: string): CardData {
  const d = new Date()
  const n = new Date(d)
  n.setFullYear(d.getFullYear() + 1)
  return {
    name_bn: f.name_bn,
    name_en: f.name_en,
    designation: f.designation,
    area: areaText(f) || o.area,
    card_no: 'MN-XX-XXXX',
    issued: d.toISOString().slice(0, 10),
    expires: n.toISOString().slice(0, 10),
    blood: f.blood,
    phone: f.phone,
    emergency: f.emergency,
    photo,
    verify_url: 'https://meeha.news/verify/',
    status: 'preview',
  }
}

function Orders({ brand, status, openId, go, onChange }: { brand: Brand; status: string; openId: number; go: (p: Record<string, string | null>) => void; onChange: () => void }) {
  const [items, setItems] = useState<IdOrder[] | null>(null)
  const [q, setQ] = useState('')
  const [err, setErr] = useState('')
  const load = useCallback(() => {
    setItems(null)
    IdAdminApi.orders(status, q)
      .then((r) => setItems(r.items))
      .catch((e) => setErr(e.message))
  }, [status, q])
  useEffect(load, [load])
  return (
    <>
      <div className="flex flex-wrap gap-1.5 mb-3">
        {ORDER_TABS.map(([k, l]) => (
          <button key={k} onClick={() => go({ status: k })} className={`px-3 py-1.5 rounded-full text-[12.5px] font-medium ${status === k ? 'bg-neutral-900 text-white' : 'bg-white border border-neutral-200 text-neutral-600'}`}>
            {l}
          </button>
        ))}
        <input className={`${inputClass} max-w-[220px] ml-auto`} placeholder="নাম / ট্রানজেকশন আইডি" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>
      {err && <Banner kind="error">{err}</Banner>}
      <Card>
        {!items ? (
          <Spinner className="my-8" />
        ) : !items.length ? (
          <EmptyState>এই তালিকায় কিছু নেই।</EmptyState>
        ) : (
          <div className="divide-y divide-neutral-100">
            {items.map((o) => (
              <button key={o.id} onClick={() => go({ order: String(o.id) })} className="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-neutral-50">
                <img src={o.person?.avatar} alt="" className="w-10 h-10 rounded-full object-cover bg-neutral-100" />
                <div className="flex-1 min-w-0">
                  <p className="text-[14px] font-semibold text-neutral-900 truncate">
                    {o.fields.name_bn} {o.type === 'renew' && <span className="text-[11.5px] text-brand-600">· রিনিউ{o.auto ? ' (অটো)' : ''}</span>}
                  </p>
                  <p className="text-[12px] text-neutral-500 truncate">
                    {o.fields.designation} · {o.area} · {o.pkg?.name ?? o.pkg_key} · {bnMoney(o.amount)}
                  </p>
                  {o.pay_method && (
                    <p className="text-[12px] text-neutral-400 truncate">
                      {o.pay_method_text}
                      {o.pay_txn ? ` · ${o.pay_txn}` : ''}
                      {o.pay_from ? ` · ${o.pay_from}` : ''}
                    </p>
                  )}
                </div>
                <div className="flex flex-col items-end gap-1">
                  <Badge tone={STATUS_TONE[o.status]}>{STATUS_TEXT[o.status]}</Badge>
                  {o.fulfil && <span className="text-[11.5px] text-neutral-500">{FULFIL_TEXT[o.fulfil]}</span>}
                  <span className="text-[11px] text-neutral-400">{bnDate(o.created_at)}</span>
                </div>
              </button>
            ))}
          </div>
        )}
      </Card>
      {openId > 0 && (
        <OrderPanel
          id={openId}
          brand={brand}
          onClose={() => go({ order: null })}
          onChange={() => {
            load()
            onChange()
          }}
        />
      )}
    </>
  )
}

function OrderPanel({ id, brand, onClose, onChange }: { id: number; brand: Brand; onClose: () => void; onChange: () => void }) {
  const navigate = useNavigate()
  const [o, setO] = useState<IdOrder | null>(null)
  const [card, setCard] = useState<IdCard | null>(null)
  const [balance, setBalance] = useState(0)
  const [f, setF] = useState<CardFields | null>(null)
  const [tpl, setTpl] = useState('royal')
  const [note, setNote] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState<{ kind: 'error' | 'success'; text: string } | null>(null)
  useEffect(() => {
    IdAdminApi.order(id)
      .then((r) => {
        setO(r.order)
        setF(r.order.fields)
        setCard(r.card)
        setBalance(r.balance)
        if (r.card) setTpl(r.card.template)
      })
      .catch((e) => setMsg({ kind: 'error', text: e.message }))
  }, [id])
  if (!o || !f) return <Overlay title="অর্ডার" onClose={onClose}>{msg ? <Banner kind={msg.kind}>{msg.text}</Banner> : <Spinner />}</Overlay>

  const ctx = { org: brand.org, assets: brand.assets, terms: brand.terms }
  const data = card && o.status === 'approved' ? cardDataOf(card, o.photo_data) : previewData(o, f, o.photo_data ?? '')
  const open = o.status === 'review' || o.status === 'awaiting'
  const setField = (k: keyof CardFields, v: string) => setF((x) => (x ? { ...x, [k]: v } : x))

  async function act(action: string, extra: Record<string, unknown> = {}) {
    setBusy(action)
    setMsg(null)
    try {
      const r = await IdAdminApi.act(id, { action, note, reason, fields: f ?? undefined, template: tpl, ...extra })
      setO(r.order)
      setCard(r.card)
      onChange()
      const t: Record<string, string> = { approve: 'অনুমোদন হয়েছে — কার্ডটি এখনই সাংবাদিকের অ্যাপে দেখা যাচ্ছে।', reject: 'প্রত্যাখ্যান করা হয়েছে; সাংবাদিককে জানানো হয়েছে।', remind: 'পেমেন্টের রিমাইন্ডার পাঠানো হয়েছে।' }
      setMsg({ kind: 'success', text: t[action] ?? 'হালনাগাদ হয়েছে; সাংবাদিককে জানানো হয়েছে।' })
    } catch (e) {
      setMsg({ kind: 'error', text: e instanceof Error ? e.message : 'হয়নি।' })
    } finally {
      setBusy('')
    }
  }

  return (
    <Overlay title={`${o.type === 'renew' ? 'রিনিউ' : 'নতুন কার্ড'} · #${bnDigits(o.id)}`} onClose={onClose} wide>
      {msg && <Banner kind={msg.kind}>{msg.text}</Banner>}
      <div className="grid md:grid-cols-[1fr_300px] gap-5">
        <div className="space-y-4">
          <Card className="p-4">
            <div className="flex items-center gap-3">
              <img src={o.person?.avatar} alt="" className="w-12 h-12 rounded-full object-cover" />
              <div className="flex-1">
                <p className="font-bold text-neutral-900">{o.person?.name}</p>
                <p className="text-[12.5px] text-neutral-500">{o.person?.role} · {o.person?.area}</p>
              </div>
              <Badge tone={STATUS_TONE[o.status]}>{STATUS_TEXT[o.status]}</Badge>
            </div>
            <div className="grid grid-cols-2 gap-2 mt-4 text-[13px]">
              <div className="rounded-xl bg-neutral-50 p-3">
                <p className="text-neutral-500 text-[12px]">প্যাকেজ</p>
                <p className="font-semibold">{o.pkg?.name ?? o.pkg_key} · {bnMoney(o.amount)}</p>
                <p className="text-[11.5px] text-neutral-500">{o.pkg?.items.join(' · ')}</p>
              </div>
              <div className={`rounded-xl p-3 ${o.pay_method ? 'bg-emerald-50' : 'bg-amber-50'}`}>
                <p className="text-neutral-500 text-[12px]">পেমেন্ট</p>
                {o.pay_method ? (
                  <>
                    <p className="font-semibold">{o.pay_method_text}</p>
                    {o.pay_txn && <p className="font-mono text-[13px] select-all">{o.pay_txn}</p>}
                    {o.pay_from && <p className="text-[12px] text-neutral-600">যে নম্বর থেকে: {o.pay_from}</p>}
                    {o.pay_method === 'wallet' && <p className="text-[11.5px] text-emerald-700">আয় থেকে কাটা হয়েছে (নিশ্চিত)</p>}
                  </>
                ) : (
                  <p className="font-semibold text-amber-700">এখনো দেননি</p>
                )}
              </div>
            </div>
            {o.ship_to && <p className="text-[12.5px] text-neutral-600 mt-3">📦 পাঠানোর ঠিকানা: {o.ship_to}</p>}
            <p className="text-[12px] text-neutral-400 mt-1">আয়ের ব্যালেন্স: {bnMoney(balance)}</p>
          </Card>

          <Card className="p-4">
            <p className="text-[14px] font-bold mb-3">কার্ডের তথ্য {open ? '(দরকার হলে ঠিক করে দিন)' : ''}</p>
            <div className="grid sm:grid-cols-2 gap-x-3">
              {(
                [
                  ['name_bn', 'নাম'],
                  ['name_en', 'নাম (ইংরেজি)'],
                  ['designation', 'পদবি'],
                  ['blood', 'রক্তের গ্রুপ'],
                  ['district', 'জেলা'],
                  ['upazila', 'উপজেলা'],
                  ['phone', 'মোবাইল'],
                  ['emergency', 'জরুরি যোগাযোগ'],
                ] as [keyof CardFields, string][]
              ).map(([k, l]) => (
                <Field key={k} label={l}>
                  <input className={inputClass} value={f[k] ?? ''} disabled={!open} onChange={(e) => setField(k, e.target.value)} />
                </Field>
              ))}
              <Field label="এলাকার ধরন">
                <select className={inputClass} value={f.area_type} disabled={!open} onChange={(e) => setField('area_type', e.target.value)}>
                  {[
                    ['national', 'জাতীয়'],
                    ['division', 'বিভাগীয়'],
                    ['district', 'জেলা'],
                    ['upazila', 'উপজেলা'],
                    ['campus', 'ক্যাম্পাস'],
                    ['staff', 'ডেস্ক/অফিস'],
                  ].map(([k, l]) => (
                    <option key={k} value={k}>
                      {l}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="ডিজাইন">
                <select className={inputClass} value={tpl} onChange={(e) => setTpl(e.target.value)}>
                  {ID_TEMPLATES.map((t) => (
                    <option key={t.key} value={t.key}>
                      {t.name}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
          </Card>

          {open && (
            <Card className="p-4 space-y-3">
              <Field label="সাংবাদিককে বার্তা (ঐচ্ছিক)">
                <input className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} placeholder="যেমন: কার্ড সোমবার কুরিয়ারে যাবে" />
              </Field>
              <div className="flex flex-wrap gap-2">
                {o.status === 'review' && (
                  <Button onClick={() => act('approve')} disabled={!!busy}>
                    {busy === 'approve' ? 'হচ্ছে…' : '✅ পেমেন্ট মিলেছে — অনুমোদন'}
                  </Button>
                )}
                {o.status === 'awaiting' && (
                  <>
                    <Button onClick={() => act('approve', { pay_method: 'cash' })} disabled={!!busy}>
                      💵 নগদ পেয়েছি — অনুমোদন
                    </Button>
                    <Button variant="secondary" onClick={() => act('approve', { pay_method: 'free' })} disabled={!!busy}>
                      🎁 ফ্রি দিন
                    </Button>
                    <Button variant="ghost" onClick={() => act('remind')} disabled={!!busy}>
                      🔔 পেমেন্টের রিমাইন্ডার
                    </Button>
                  </>
                )}
              </div>
              <div className="pt-3 border-t border-neutral-100">
                <p className="text-[12.5px] font-semibold text-neutral-600 mb-1.5">প্রত্যাখ্যানের কারণ</p>
                <div className="flex flex-wrap gap-1.5 mb-2">
                  {REJECT_REASONS.map((r) => (
                    <button key={r} onClick={() => setReason(r)} className={`px-2.5 py-1 rounded-full text-[12px] ${reason === r ? 'bg-red-600 text-white' : 'bg-red-50 text-red-700'}`}>
                      {r}
                    </button>
                  ))}
                </div>
                <div className="flex gap-2">
                  <input className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="নিজে লিখুন" />
                  <Button variant="danger" onClick={() => act('reject')} disabled={!!busy || !reason}>
                    প্রত্যাখ্যান
                  </Button>
                </div>
                {o.pay_method === 'wallet' && <p className="text-[11.5px] text-neutral-500 mt-1">প্রত্যাখ্যান করলে আয় থেকে কাটা টাকা নিজে ফেরত যাবে।</p>}
              </div>
            </Card>
          )}

          {o.status === 'approved' && (
            <Card className="p-4 space-y-3">
              <p className="text-[14px] font-bold">কিট পাঠানো</p>
              <div className="flex flex-wrap gap-2">
                {(['printing', 'shipped', 'delivered'] as const).map((s) => (
                  <Button key={s} variant={o.fulfil === s ? 'primary' : 'secondary'} size="sm" onClick={() => act(s)} disabled={!!busy}>
                    {FULFIL_TEXT[s]}
                  </Button>
                ))}
              </div>
              <input className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} placeholder="যেমন: সুন্দরবন কুরিয়ার, ট্র্যাকিং ১২৩৪৫" />
              {o.staff_note && <p className="text-[12px] text-neutral-500">শেষ বার্তা: {o.staff_note}</p>}
              {card && (
                <div className="flex flex-wrap gap-2 pt-2 border-t border-neutral-100">
                  <Button size="sm" variant="secondary" onClick={() => exportPages(cardPages(card.template, cardDataOf(card, o.photo_data), ctx, 2), 'pdf', `idcard-${card.card_no}-print`)}>
                    ⬇ প্রিন্ট ফাইল (২ মিমি ব্লিড)
                  </Button>
                  <Button size="sm" variant="secondary" onClick={() => navigate(`/app/stationery?d=appointment&card=${card.id}`)}>
                    📜 নিয়োগপত্র
                  </Button>
                  <Button size="sm" variant="secondary" onClick={() => exportPages(visitingPages('midnight', visitingOf(card, o.photo_data), brand.org, 2), 'pdf', `visiting-${card.card_no}`)}>
                    💳 ভিজিটিং কার্ড
                  </Button>
                </div>
              )}
            </Card>
          )}
          {o.status === 'rejected' && o.reason && <Banner kind="error">কারণ: {o.reason}</Banner>}
        </div>
        <div className="space-y-3">
          <p className="text-[12.5px] text-neutral-500">{o.status === 'approved' ? 'ইস্যু করা কার্ড' : 'কার্ড দেখতে যেমন হবে'} — ট্যাপ করে উল্টান</p>
          <FlipCard front={cardFront(tpl, data, ctx, { round: true })} back={cardBack(tpl, data, ctx, { round: true })} />
          {o.photo && (
            <a href={o.photo} target="_blank" rel="noreferrer" className="text-[12px] text-brand-600 underline">
              আসল ছবি দেখুন
            </a>
          )}
        </div>
      </div>
    </Overlay>
  )
}

// ---------------------------------------------------------------------------
// কার্ড
// ---------------------------------------------------------------------------

function Cards({ brand, status, openId, go, onChange }: { brand: Brand; status: string; openId: number; go: (p: Record<string, string | null>) => void; onChange: () => void }) {
  const [items, setItems] = useState<IdCard[] | null>(null)
  const [q, setQ] = useState('')
  const [pick, setPick] = useState<Set<number>>(new Set())
  const [sheetBusy, setSheetBusy] = useState(false)
  const [err, setErr] = useState('')
  const load = useCallback(() => {
    setItems(null)
    IdAdminApi.cards(status === 'all' ? '' : status, q)
      .then((r) => setItems(r.items))
      .catch((e) => setErr(e.message))
  }, [status, q])
  useEffect(load, [load])

  async function sheet(side: 'front' | 'back') {
    setSheetBusy(true)
    try {
      const chosen = (items ?? []).filter((c) => pick.has(c.id))
      // the list has no photos; fetch each card once for its photo
      const full = await Promise.all(chosen.map((c) => IdAdminApi.card(c.id).then((r) => r.card)))
      const ctx = { org: brand.org, assets: brand.assets, terms: brand.terms }
      const tpl = full[0]?.template ?? 'royal'
      const pages = []
      for (let i = 0; i < full.length; i += 9) {
        const group = full.slice(i, i + 9).map((c) => cardDataOf(c))
        pages.push({ svg: cardSheet(tpl, group, ctx, side), w: 210, h: 297, name: `${side}-${i / 9 + 1}` })
      }
      await exportPages(pages, 'pdf', `meeha-idcards-${side}-sheet`)
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'শিট বানানো যায়নি।')
    } finally {
      setSheetBusy(false)
    }
  }

  return (
    <>
      <div className="flex flex-wrap gap-1.5 mb-3">
        {CARD_TABS.map(([k, l]) => (
          <button key={k} onClick={() => go({ status: k })} className={`px-3 py-1.5 rounded-full text-[12.5px] font-medium ${status === k ? 'bg-neutral-900 text-white' : 'bg-white border border-neutral-200 text-neutral-600'}`}>
            {l}
          </button>
        ))}
        <input className={`${inputClass} max-w-[220px] ml-auto`} placeholder="নাম / কার্ড নম্বর" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>
      {err && <Banner kind="error">{err}</Banner>}
      {pick.size > 0 && (
        <div className="flex flex-wrap items-center gap-2 mb-3 rounded-xl bg-brand-50 px-3 py-2 text-[13px]">
          <b>{bnDigits(pick.size)}টি কার্ড বাছাই</b>
          <Button size="sm" onClick={() => sheet('front')} disabled={sheetBusy}>
            ⬇ A4 প্রিন্ট শিট — সামনে
          </Button>
          <Button size="sm" variant="secondary" onClick={() => sheet('back')} disabled={sheetBusy}>
            ⬇ পেছনে
          </Button>
          <button className="ml-auto text-neutral-500" onClick={() => setPick(new Set())}>
            বাছাই মুছুন
          </button>
        </div>
      )}
      <Card>
        {!items ? (
          <Spinner className="my-8" />
        ) : !items.length ? (
          <EmptyState>এই তালিকায় কোনো কার্ড নেই।</EmptyState>
        ) : (
          <div className="divide-y divide-neutral-100">
            {items.map((c) => (
              <div key={c.id} className="flex items-center gap-3 px-4 py-3 hover:bg-neutral-50">
                <input
                  type="checkbox"
                  className="accent-brand-600"
                  checked={pick.has(c.id)}
                  onChange={(e) =>
                    setPick((s) => {
                      const n = new Set(s)
                      if (e.target.checked) n.add(c.id)
                      else n.delete(c.id)
                      return n
                    })
                  }
                />
                <button onClick={() => go({ card: String(c.id) })} className="flex-1 flex items-center gap-3 text-left min-w-0">
                  <img src={c.person?.avatar} alt="" className="w-10 h-10 rounded-full object-cover bg-neutral-100" />
                  <div className="flex-1 min-w-0">
                    <p className="text-[14px] font-semibold truncate">
                      {c.fields.name_bn} <span className="font-mono text-[12px] text-neutral-400">{c.card_no}</span>
                    </p>
                    <p className="text-[12px] text-neutral-500 truncate">
                      {c.fields.designation} · {c.area} · {c.auto_renew ? '🔁 অটো-রিনিউ' : 'হাতে রিনিউ'} · {bnDigits(c.scans)} বার স্ক্যান
                    </p>
                  </div>
                  <div className="text-right">
                    <Badge tone={STATUS_TONE[c.status]}>{c.status_text}</Badge>
                    <p className={`text-[11.5px] mt-1 ${c.days_left < 30 ? 'text-amber-600 font-semibold' : 'text-neutral-400'}`}>
                      {bnDate(c.expires_at)} {c.status === 'active' && c.days_left < 60 ? `(${bnDigits(Math.max(0, c.days_left))} দিন)` : ''}
                    </p>
                  </div>
                </button>
              </div>
            ))}
          </div>
        )}
      </Card>
      {openId > 0 && (
        <CardPanel
          id={openId}
          brand={brand}
          onClose={() => go({ card: null })}
          onChange={() => {
            load()
            onChange()
          }}
        />
      )}
    </>
  )
}

function CardPanel({ id, brand, onClose, onChange }: { id: number; brand: Brand; onClose: () => void; onChange: () => void }) {
  const navigate = useNavigate()
  const { isPublisher } = useAuth()
  const [c, setC] = useState<IdCard | null>(null)
  const [orders, setOrders] = useState<IdOrder[]>([])
  const [f, setF] = useState<CardFields | null>(null)
  const [tpl, setTpl] = useState('royal')
  const [reason, setReason] = useState('')
  const [date, setDate] = useState('')
  const [busy, setBusy] = useState('')
  const [msg, setMsg] = useState<{ kind: 'error' | 'success'; text: string } | null>(null)
  const apply = (r: { card: IdCard; orders: IdOrder[] }) => {
    setC(r.card)
    setF(r.card.fields)
    setTpl(r.card.template)
    setOrders(r.orders)
  }
  useEffect(() => {
    IdAdminApi.card(id)
      .then(apply)
      .catch((e) => setMsg({ kind: 'error', text: e.message }))
  }, [id])
  if (!c || !f) return <Overlay title="কার্ড" onClose={onClose}>{msg ? <Banner kind={msg.kind}>{msg.text}</Banner> : <Spinner />}</Overlay>
  const ctx = { org: brand.org, assets: brand.assets, terms: brand.terms }
  const data = { ...cardDataOf(c), name_bn: f.name_bn, name_en: f.name_en, designation: f.designation, blood: f.blood, phone: f.phone, emergency: f.emergency, area: areaText(f) }
  async function act(action: string, extra: Record<string, unknown> = {}) {
    setBusy(action)
    setMsg(null)
    try {
      const r = await IdAdminApi.cardAct(id, { action, ...extra })
      apply(r)
      onChange()
      setMsg({ kind: 'success', text: 'হালনাগাদ হয়েছে।' })
    } catch (e) {
      setMsg({ kind: 'error', text: e instanceof Error ? e.message : 'হয়নি।' })
    } finally {
      setBusy('')
    }
  }
  return (
    <Overlay title={`${c.fields.name_bn} · ${c.card_no}`} onClose={onClose} wide>
      {msg && <Banner kind={msg.kind}>{msg.text}</Banner>}
      <div className="grid md:grid-cols-[1fr_300px] gap-5">
        <div className="space-y-4">
          <Card className="p-4">
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={STATUS_TONE[c.status]}>{c.status_text}</Badge>
              <span className="text-[13px] text-neutral-600">
                ইস্যু {bnDate(c.issued_at)} · মেয়াদ {bnDate(c.expires_at)} · রিনিউ {bnDigits(c.renewals)} বার · স্ক্যান {bnDigits(c.scans)} বার
              </span>
            </div>
            {c.cancel_reason && <p className="text-[12.5px] text-red-600 mt-1">কারণ: {c.cancel_reason}</p>}
            <a href={c.verify_url} target="_blank" rel="noreferrer" className="text-[12.5px] text-brand-600 underline mt-1 inline-block">
              যাচাই পাতা খুলুন ↗
            </a>
          </Card>
          <Card className="p-4">
            <p className="text-[14px] font-bold mb-3">কার্ডের তথ্য</p>
            <div className="grid sm:grid-cols-2 gap-x-3">
              {(
                [
                  ['name_bn', 'নাম'],
                  ['name_en', 'নাম (ইংরেজি)'],
                  ['designation', 'পদবি'],
                  ['blood', 'রক্তের গ্রুপ'],
                  ['district', 'জেলা'],
                  ['upazila', 'উপজেলা'],
                  ['phone', 'মোবাইল'],
                  ['emergency', 'জরুরি যোগাযোগ'],
                ] as [keyof CardFields, string][]
              ).map(([k, l]) => (
                <Field key={k} label={l}>
                  <input className={inputClass} value={f[k] ?? ''} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
                </Field>
              ))}
              <Field label="ডিজাইন">
                <select className={inputClass} value={tpl} onChange={(e) => setTpl(e.target.value)}>
                  {ID_TEMPLATES.map((t) => (
                    <option key={t.key} value={t.key}>
                      {t.name}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
            <Button onClick={() => act('edit', { fields: f, template: tpl })} disabled={!!busy}>
              সংরক্ষণ
            </Button>
          </Card>
          <Card className="p-4 space-y-3">
            <p className="text-[14px] font-bold">মেয়াদ ও অবস্থা</p>
            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="secondary" onClick={() => act('remind')} disabled={!!busy}>
                🔔 রিনিউয়ের রিমাইন্ডার
              </Button>
              <Button size="sm" variant="secondary" onClick={() => act('edit', { auto_renew: !c.auto_renew })} disabled={!!busy}>
                {c.auto_renew ? 'অটো-রিনিউ বন্ধ' : 'অটো-রিনিউ চালু'}
              </Button>
              {isPublisher && (
                <>
                  <Button size="sm" onClick={() => act('extend', { months: 12 })} disabled={!!busy}>
                    +১ বছর মেয়াদ
                  </Button>
                  <input type="date" className={`${inputClass} max-w-[160px]`} value={date} onChange={(e) => setDate(e.target.value)} />
                  <Button size="sm" variant="secondary" disabled={!date || !!busy} onClick={() => act('extend', { expires_at: date })}>
                    এই তারিখ পর্যন্ত
                  </Button>
                </>
              )}
            </div>
            {isPublisher && (
              <div className="flex flex-wrap gap-2 pt-2 border-t border-neutral-100">
                <input className={`${inputClass} flex-1`} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="বাতিল/স্থগিতের কারণ" />
                {c.status === 'cancelled' || c.status === 'suspended' ? (
                  <Button size="sm" variant="success" onClick={() => act('reactivate')} disabled={!!busy}>
                    আবার চালু
                  </Button>
                ) : (
                  <>
                    <Button size="sm" variant="danger" onClick={() => act('suspend', { reason })} disabled={!!busy}>
                      স্থগিত
                    </Button>
                    <Button size="sm" variant="destructive" onClick={() => confirm('কার্ডটি বাতিল করবেন? যাচাই পাতায় “বাতিল” দেখাবে।') && act('cancel', { reason })} disabled={!!busy}>
                      বাতিল
                    </Button>
                  </>
                )}
              </div>
            )}
          </Card>
          <Card className="p-4 space-y-2">
            <p className="text-[14px] font-bold">ফাইল</p>
            <p className="text-[12px] text-neutral-500">আইডি কার্ড — প্রিন্টারের জন্য (২ মিমি ব্লিড, সামনে + পেছনে):</p>
            <ExportBar pages={() => cardPages(tpl, data, ctx, 2)} name={`idcard-${c.card_no}`} />
            <div className="flex flex-wrap gap-2 pt-2">
              <Button size="sm" variant="secondary" onClick={() => navigate(`/app/stationery?d=appointment&card=${c.id}`)}>
                📜 নিয়োগপত্র
              </Button>
              <Button size="sm" variant="secondary" onClick={() => exportPages(visitingPages('midnight', visitingOf(c), brand.org, 2), 'pdf', `visiting-${c.card_no}`)}>
                💳 ভিজিটিং কার্ড (মিডনাইট)
              </Button>
              <Button size="sm" variant="secondary" onClick={() => exportPages(visitingPages('splash', visitingOf(c), brand.org, 2), 'pdf', `visiting-${c.card_no}-blue`)}>
                💳 ভিজিটিং কার্ড (ব্লু)
              </Button>
              <Button size="sm" variant="ghost" onClick={() => printPages(cardPages(tpl, data, ctx, 0), c.card_no)}>
                🖨️ সরাসরি প্রিন্ট
              </Button>
            </div>
          </Card>
          {orders.length > 0 && (
            <Card className="p-4">
              <p className="text-[14px] font-bold mb-2">অর্ডারের ইতিহাস</p>
              {orders.map((o) => (
                <p key={o.id} className="text-[12.5px] text-neutral-600 py-1 border-b border-neutral-50">
                  #{bnDigits(o.id)} · {o.type === 'renew' ? 'রিনিউ' : 'নতুন'} · {o.pkg?.name} · {bnMoney(o.amount)} · {o.pay_method_text} · {STATUS_TEXT[o.status]} · {bnDate(o.created_at)}
                </p>
              ))}
            </Card>
          )}
        </div>
        <div className="space-y-3">
          <FlipCard front={cardFront(tpl, data, ctx, { round: true })} back={cardBack(tpl, data, ctx, { round: true })} />
        </div>
      </div>
    </Overlay>
  )
}

// ---------------------------------------------------------------------------
// নতুন কার্ড (Publisher)
// ---------------------------------------------------------------------------

function Issue({ brand, onDone }: { brand: Brand; onDone: (c: IdCard) => void }) {
  const [q, setQ] = useState('')
  const [people, setPeople] = useState<(PersonLite & { card: { card_no: string; status: string; expires_at: string } | null; prefill: CardFields; photo: string })[]>([])
  const [who, setWho] = useState<(typeof people)[number] | null>(null)
  const [f, setF] = useState<CardFields | null>(null)
  const [pkgs, setPkgs] = useState<Pkg[]>([])
  const [pkg, setPkg] = useState('')
  const [method, setMethod] = useState('free')
  const [tpl, setTpl] = useState('royal')
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  useEffect(() => {
    IdAdminApi.settings().then((r) => {
      setPkgs(r.settings.packages)
      setTpl(r.settings.template || 'royal')
    })
  }, [])
  useEffect(() => {
    const t = setTimeout(() => IdAdminApi.people(q).then((r) => setPeople(r.items)).catch(() => undefined), 250)
    return () => clearTimeout(t)
  }, [q])
  const ctx = { org: brand.org, assets: brand.assets, terms: brand.terms }
  const preview = useMemo(() => {
    if (!f) return null
    const d = new Date()
    const n = new Date(d)
    n.setFullYear(d.getFullYear() + 1)
    return { name_bn: f.name_bn, name_en: f.name_en, designation: f.designation, area: areaText(f), card_no: 'MN-XX-XXXX', issued: d.toISOString().slice(0, 10), expires: n.toISOString().slice(0, 10), blood: f.blood, phone: f.phone, emergency: f.emergency, photo: '', verify_url: brand.org.verify_base || 'https://meeha.news/verify/', status: 'preview' as const }
  }, [f, brand.org.verify_base])
  async function issue() {
    if (!who || !f) return
    setBusy(true)
    setMsg('')
    try {
      const r = await IdAdminApi.issue({ user_id: who.id, fields: f, package: pkg, pay_method: method, template: tpl })
      onDone(r.card)
    } catch (e) {
      setMsg(e instanceof Error ? e.message : 'হয়নি।')
    } finally {
      setBusy(false)
    }
  }
  return (
    <div className="grid md:grid-cols-[1fr_300px] gap-5">
      <div className="space-y-4">
        {msg && <Banner kind="error">{msg}</Banner>}
        <Card className="p-4">
          <p className="text-[14px] font-bold mb-2">১. কার জন্য কার্ড?</p>
          <input className={inputClass} placeholder="নাম/ইমেইল লিখে খুঁজুন (এডিটর, পাবলিশার, সংবাদদাতা)" value={q} onChange={(e) => setQ(e.target.value)} />
          <div className="mt-2 max-h-64 overflow-y-auto divide-y divide-neutral-50">
            {people.map((p) => (
              <button
                key={p.id}
                onClick={() => {
                  setWho(p)
                  setF(p.prefill)
                }}
                className={`w-full flex items-center gap-3 px-2 py-2 text-left rounded-lg ${who?.id === p.id ? 'bg-brand-50' : 'hover:bg-neutral-50'}`}
              >
                <img src={p.avatar} alt="" className="w-9 h-9 rounded-full object-cover" />
                <div className="flex-1 min-w-0">
                  <p className="text-[13.5px] font-semibold truncate">{p.name}</p>
                  <p className="text-[12px] text-neutral-500">
                    {p.role} {p.area && `· ${p.area}`}
                  </p>
                </div>
                {p.card && <Badge tone={STATUS_TONE[p.card.status]}>{p.card.card_no}</Badge>}
              </button>
            ))}
          </div>
        </Card>
        {who && f && (
          <Card className="p-4">
            <p className="text-[14px] font-bold mb-3">২. কার্ডের তথ্য (প্রোফাইল থেকে বসানো — বদলাতে পারেন)</p>
            <div className="grid sm:grid-cols-2 gap-x-3">
              {(
                [
                  ['name_bn', 'নাম'],
                  ['name_en', 'নাম (ইংরেজি)'],
                  ['designation', 'পদবি'],
                  ['blood', 'রক্তের গ্রুপ'],
                  ['district', 'জেলা'],
                  ['upazila', 'উপজেলা'],
                  ['phone', 'মোবাইল'],
                  ['emergency', 'জরুরি যোগাযোগ'],
                ] as [keyof CardFields, string][]
              ).map(([k, l]) => (
                <Field key={k} label={l}>
                  <input className={inputClass} value={f[k] ?? ''} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
                </Field>
              ))}
              <Field label="এলাকার ধরন">
                <select className={inputClass} value={f.area_type} onChange={(e) => setF({ ...f, area_type: e.target.value })}>
                  {[
                    ['staff', 'ডেস্ক/অফিস'],
                    ['national', 'জাতীয়'],
                    ['division', 'বিভাগীয়'],
                    ['district', 'জেলা'],
                    ['upazila', 'উপজেলা'],
                    ['campus', 'ক্যাম্পাস'],
                  ].map(([k, l]) => (
                    <option key={k} value={k}>
                      {l}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
            <p className="text-[14px] font-bold mt-2 mb-2">৩. প্যাকেজ ও পেমেন্ট</p>
            <div className="grid sm:grid-cols-3 gap-3">
              <Field label="প্যাকেজ">
                <select className={inputClass} value={pkg} onChange={(e) => setPkg(e.target.value)}>
                  <option value="">অফিস থেকে (শুধু কার্ড)</option>
                  {pkgs.map((p) => (
                    <option key={p.key} value={p.key}>
                      {p.name} — {bnMoney(p.price)}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="পেমেন্ট">
                <select className={inputClass} value={method} onChange={(e) => setMethod(e.target.value)}>
                  <option value="free">ফ্রি / অফিস</option>
                  <option value="cash">নগদ পেয়েছি</option>
                  <option value="bkash">বিকাশে পেয়েছি</option>
                  <option value="nagad">নগদে (অ্যাপ) পেয়েছি</option>
                </select>
              </Field>
              <Field label="ডিজাইন">
                <select className={inputClass} value={tpl} onChange={(e) => setTpl(e.target.value)}>
                  {ID_TEMPLATES.map((t) => (
                    <option key={t.key} value={t.key}>
                      {t.name}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
            <Button onClick={issue} disabled={busy}>
              {busy ? 'ইস্যু হচ্ছে…' : '🪪 কার্ড ইস্যু করুন'}
            </Button>
            {who.card && <p className="text-[12px] text-amber-700 mt-2">এঁর আগে থেকে কার্ড আছে ({who.card.card_no}) — ইস্যু করলে একই নম্বরে তথ্য ও মেয়াদ হালনাগাদ হবে।</p>}
          </Card>
        )}
      </div>
      <div>{preview && <FlipCard front={cardFront(tpl, preview, ctx, { round: true })} back={cardBack(tpl, preview, ctx, { round: true })} />}</div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// প্যাকেজ ও সেটিংস
// ---------------------------------------------------------------------------

const PKG_PRESETS: Pkg[] = [
  { key: '', name: 'ডিজিটাল কার্ড', price: 299, renew_price: 199, items: ['ভার্চুয়াল আইডি কার্ড (অ্যাপে)', 'অটো ভিজিটিং কার্ড', 'যাচাই কিউআর'], physical: false, note: '', popular: false, active: true },
  { key: '', name: 'বেসিক কিট', price: 999, renew_price: 499, items: ['প্রিমিয়াম পিভিসি আইডি কার্ড', 'ফিতা (ল্যানইয়ার্ড)', 'নিয়োগপত্র', 'ভার্চুয়াল কার্ড + ভিজিটিং কার্ড'], physical: true, note: '', popular: true, active: true },
  { key: '', name: 'প্রো কিট', price: 1500, renew_price: 699, items: ['প্রিমিয়াম পিভিসি আইডি কার্ড', 'ফিতা (ল্যানইয়ার্ড)', 'নিয়োগপত্র', 'টি-শার্ট', 'প্রেস স্টিকার', 'কলম', 'ভার্চুয়াল কার্ড + ভিজিটিং কার্ড'], physical: true, note: '', popular: false, active: true },
  { key: '', name: 'প্রিমিয়াম কিট', price: 2500, renew_price: 999, items: ['প্রিমিয়াম পিভিসি আইডি কার্ড', 'ফিতা', 'নিয়োগপত্র', '২টি টি-শার্ট', 'প্রেস স্টিকার', 'কলম', 'নোট প্যাড', '১০০ ভিজিটিং কার্ড', 'প্রেস ভেস্ট/ক্যাপ'], physical: true, note: '', popular: false, active: true },
]

const METHOD_NAMES: Record<string, string> = { bkash: 'বিকাশ', nagad: 'নগদ', rocket: 'রকেট', bank: 'ব্যাংক', cash: 'অফিসে নগদ', wallet: 'মিহা আয় থেকে' }

function Settings() {
  const { isPublisher } = useAuth()
  const [s, setS] = useState<IdSettings | null>(null)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState<{ kind: 'error' | 'success'; text: string } | null>(null)
  useEffect(() => {
    IdAdminApi.settings()
      .then((r) => setS(r.settings))
      .catch((e) => setMsg({ kind: 'error', text: e.message }))
  }, [])
  if (!s) return msg ? <Banner kind="error">{msg.text}</Banner> : <Spinner />
  const ro = !isPublisher
  const setPkg = (i: number, p: Partial<Pkg>) => setS({ ...s, packages: s.packages.map((x, j) => (j === i ? { ...x, ...p } : x)) })
  async function save() {
    if (!s) return
    setBusy(true)
    setMsg(null)
    try {
      const r = await IdAdminApi.saveSettings(s)
      setS(r.settings)
      setMsg({ kind: 'success', text: 'সংরক্ষিত হয়েছে। সাংবাদিকরা অ্যাপে এখনই নতুন প্যাকেজ ও নম্বর দেখবেন।' })
    } catch (e) {
      setMsg({ kind: 'error', text: e instanceof Error ? e.message : 'হয়নি।' })
    } finally {
      setBusy(false)
    }
  }
  return (
    <div className="space-y-4 max-w-4xl">
      {ro && <Banner kind="info">সেটিংস বদলাতে পারেন শুধু পাবলিশার।</Banner>}
      {msg && <Banner kind={msg.kind}>{msg.text}</Banner>}
      <Card className="p-4">
        <div className="flex items-center justify-between mb-3">
          <p className="text-[15px] font-bold">প্যাকেজ</p>
          <label className="flex items-center gap-2 text-[13px]">
            <input type="checkbox" className="accent-brand-600" checked={s.enabled} disabled={ro} onChange={(e) => setS({ ...s, enabled: e.target.checked })} /> আবেদন চালু
          </label>
        </div>
        <div className="space-y-3">
          {s.packages.map((p, i) => (
            <div key={i} className={`rounded-xl border p-3 ${p.active ? 'border-neutral-200' : 'border-dashed border-neutral-300 opacity-70'}`}>
              <div className="grid sm:grid-cols-[1fr_110px_110px] gap-2">
                <input className={inputClass} value={p.name} disabled={ro} onChange={(e) => setPkg(i, { name: e.target.value })} placeholder="প্যাকেজের নাম" />
                <input className={inputClass} type="number" value={p.price} disabled={ro} onChange={(e) => setPkg(i, { price: Number(e.target.value) })} title="দাম" />
                <input className={inputClass} type="number" value={p.renew_price} disabled={ro} onChange={(e) => setPkg(i, { renew_price: Number(e.target.value) })} title="রিনিউ ফি" />
              </div>
              <textarea className={`${inputClass} mt-2`} rows={2} disabled={ro} value={p.items.join('\n')} onChange={(e) => setPkg(i, { items: e.target.value.split('\n') })} placeholder="প্রতি লাইনে একটি জিনিস" />
              <div className="flex flex-wrap items-center gap-4 mt-2 text-[12.5px] text-neutral-600">
                <span className="text-neutral-400">দাম · রিনিউ ফি (টাকা)</span>
                <label className="flex items-center gap-1.5">
                  <input type="checkbox" className="accent-brand-600" checked={p.physical} disabled={ro} onChange={(e) => setPkg(i, { physical: e.target.checked })} /> প্রিন্ট কিট পাঠাতে হবে
                </label>
                <label className="flex items-center gap-1.5">
                  <input type="checkbox" className="accent-brand-600" checked={p.popular} disabled={ro} onChange={(e) => setPkg(i, { popular: e.target.checked })} /> “জনপ্রিয়” ব্যাজ
                </label>
                <label className="flex items-center gap-1.5">
                  <input type="checkbox" className="accent-brand-600" checked={p.active} disabled={ro} onChange={(e) => setPkg(i, { active: e.target.checked })} /> চালু
                </label>
                {!ro && (
                  <button className="ml-auto text-red-500" onClick={() => setS({ ...s, packages: s.packages.filter((_, j) => j !== i) })}>
                    মুছুন
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
        {!ro && (
          <div className="flex flex-wrap gap-1.5 mt-3">
            <span className="text-[12.5px] text-neutral-500 mr-1">⚡ রেডিমেড যোগ করুন:</span>
            {PKG_PRESETS.map((p) => (
              <button key={p.name} onClick={() => setS({ ...s, packages: [...s.packages, { ...p }] })} className="px-2.5 py-1 rounded-full bg-brand-50 text-brand-700 text-[12px]">
                + {p.name} ({bnMoney(p.price)})
              </button>
            ))}
            <button onClick={() => setS({ ...s, packages: [...s.packages, { key: '', name: 'নতুন প্যাকেজ', price: 0, renew_price: 0, items: [], physical: true, note: '', popular: false, active: true }] })} className="px-2.5 py-1 rounded-full bg-neutral-100 text-[12px]">
              + খালি
            </button>
          </div>
        )}
      </Card>
      <Card className="p-4">
        <p className="text-[15px] font-bold mb-1">পেমেন্টের নম্বর</p>
        <p className="text-[12.5px] text-neutral-500 mb-3">এখনো অনলাইন গেটওয়ে নেই — সাংবাদিক এই নম্বরে টাকা পাঠিয়ে ট্রানজেকশন আইডি দেন, ডেস্ক মিলিয়ে অনুমোদন করে। “মিহা আয় থেকে” দিলে টাকা সঙ্গে সঙ্গে কাটা হয় (নিশ্চিত)।</p>
        <div className="space-y-2">
          {Object.entries(s.methods).map(([k, m]) => (
            <div key={k} className="grid sm:grid-cols-[130px_1fr_120px] gap-2 items-center">
              <label className="flex items-center gap-2 text-[13px] font-semibold">
                <input type="checkbox" className="accent-brand-600" checked={m.on} disabled={ro} onChange={(e) => setS({ ...s, methods: { ...s.methods, [k]: { ...m, on: e.target.checked } } })} /> {METHOD_NAMES[k] ?? k}
              </label>
              {k === 'cash' || k === 'wallet' ? (
                <input className={inputClass} value={m.help} disabled={ro} onChange={(e) => setS({ ...s, methods: { ...s.methods, [k]: { ...m, help: e.target.value } } })} placeholder="নির্দেশনা" />
              ) : (
                <input className={inputClass} value={m.number} disabled={ro} onChange={(e) => setS({ ...s, methods: { ...s.methods, [k]: { ...m, number: e.target.value } } })} placeholder={k === 'bank' ? 'ব্যাংক, হিসাবের নাম ও নম্বর' : '01XXXXXXXXX'} />
              )}
              {k !== 'cash' && k !== 'wallet' && <input className={inputClass} value={m.kind} disabled={ro} onChange={(e) => setS({ ...s, methods: { ...s.methods, [k]: { ...m, kind: e.target.value } } })} placeholder="পার্সোনাল/মার্চেন্ট" />}
            </div>
          ))}
        </div>
      </Card>
      <Card className="p-4">
        <p className="text-[15px] font-bold mb-3">মেয়াদ, রিনিউ ও অটোমেশন</p>
        <div className="grid sm:grid-cols-3 gap-3">
          <Field label="মেয়াদ (মাস)">
            <input type="number" className={inputClass} value={s.validity_months} disabled={ro} onChange={(e) => setS({ ...s, validity_months: Number(e.target.value) })} />
          </Field>
          <Field label="মেয়াদ শেষে বাতিলের আগে (দিন)">
            <input type="number" className={inputClass} value={s.grace_days} disabled={ro} onChange={(e) => setS({ ...s, grace_days: Number(e.target.value) })} />
          </Field>
          <Field label="অটো-রিনিউ (মেয়াদ শেষের কত দিন আগে)">
            <input type="number" className={inputClass} value={s.auto_renew_days} disabled={ro} onChange={(e) => setS({ ...s, auto_renew_days: Number(e.target.value) })} />
          </Field>
          <Field label="রিমাইন্ডার (দিন আগে, কমা দিয়ে)">
            <input className={inputClass} value={s.remind_days.join(', ')} disabled={ro} onChange={(e) => setS({ ...s, remind_days: e.target.value.split(/[,\s]+/).map(Number).filter((n) => n > 0) })} />
          </Field>
          <Field label="কার্ড নম্বরের শুরু">
            <input className={inputClass} value={s.card_prefix} disabled={ro} onChange={(e) => setS({ ...s, card_prefix: e.target.value })} />
          </Field>
          <Field label="ডিফল্ট ডিজাইন">
            <select className={inputClass} value={s.template} disabled={ro} onChange={(e) => setS({ ...s, template: e.target.value })}>
              {ID_TEMPLATES.map((t) => (
                <option key={t.key} value={t.key}>
                  {t.name}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <div className="space-y-2 text-[13px] text-neutral-700">
          <label className="flex items-center gap-2">
            <input type="checkbox" className="accent-brand-600" checked={s.auto_renew_default} disabled={ro} onChange={(e) => setS({ ...s, auto_renew_default: e.target.checked })} /> নতুন কার্ডে অটো-রিনিউ নিজে চালু থাকবে (মিহা আয়ের ব্যালেন্স থেকে)
          </label>
          <label className="flex items-center gap-2">
            <input type="checkbox" className="accent-brand-600" checked={s.auto_approve_wallet} disabled={ro} onChange={(e) => setS({ ...s, auto_approve_wallet: e.target.checked })} /> আয় থেকে পেমেন্ট করলে নতুন কার্ডও নিজে অনুমোদন হবে
          </label>
        </div>
      </Card>
      <Card className="p-4 grid sm:grid-cols-2 gap-4">
        <Field label="পদবির তালিকা (প্রতি লাইনে একটি)">
          <textarea rows={6} className={inputClass} value={s.designations.join('\n')} disabled={ro} onChange={(e) => setS({ ...s, designations: e.target.value.split('\n') })} />
        </Field>
        <Field label="কার্ডের পেছনের শর্ত (প্রতি লাইনে একটি, সর্বোচ্চ ৩)">
          <textarea rows={6} className={inputClass} value={s.back_terms.join('\n')} disabled={ro} onChange={(e) => setS({ ...s, back_terms: e.target.value.split('\n') })} />
        </Field>
        <div className="sm:col-span-2">
          <Field label="আবেদন পাতায় নোট">
            <textarea rows={2} className={inputClass} value={s.apply_note} disabled={ro} onChange={(e) => setS({ ...s, apply_note: e.target.value })} />
          </Field>
        </div>
      </Card>
      {!ro && (
        <div className="sticky bottom-3 flex justify-end">
          <Button onClick={save} disabled={busy} className="shadow-lg">
            {busy ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন'}
          </Button>
        </div>
      )}
      <Card className="p-4">
        <p className="text-[15px] font-bold mb-3">দুটি কার্ড ডিজাইন</p>
        <TemplateShow />
      </Card>
    </div>
  )
}

function TemplateShow() {
  const brand = useBrand()
  if (!brand) return <Spinner />
  const ctx = { org: brand.org, assets: brand.assets, terms: brand.terms }
  const d: CardData = { name_bn: 'মোহাম্মদ রফিকুল ইসলাম', name_en: 'Md. Rafiqul Islam', designation: 'জেলা প্রতিনিধি', area: 'কুমিল্লা জেলা', card_no: 'MN-26-0042', issued: '2026-11-11', expires: '2027-11-11', blood: 'B+', phone: '01711-000000', emergency: '01811-000000', photo: '', verify_url: (brand.org.verify_base || 'https://meeha.news/verify/') + 'SAMPLE', status: 'preview' }
  return (
    <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
      {ID_TEMPLATES.map((t) => (
        <div key={t.key} className="contents">
          <div>
            <SvgBox svg={cardFront(t.key, d, ctx, { round: true })} />
            <p className="text-[12.5px] text-center mt-2 font-semibold">{t.name} — সামনে</p>
          </div>
          <div>
            <SvgBox svg={cardBack(t.key, d, ctx, { round: true })} />
            <p className="text-[12.5px] text-center mt-2 text-neutral-500">পেছনে</p>
          </div>
        </div>
      ))}
    </div>
  )
}
