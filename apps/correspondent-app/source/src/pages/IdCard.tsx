/**
 * r43 — মিহা আইডি কার্ড (correspondent app, /app/idcard).
 *
 *  • no card → choose a package → check/edit the details (from the profile)
 *    with a live preview → pay (bKash/Nagad/Rocket/bank with a transaction ID,
 *    from Meeha earnings, cash at the office, or later)
 *  • while the desk checks → a step tracker; pay now / cancel
 *  • approved → the virtual card (tap to turn over), PNG/JPG/PDF download,
 *    verify link, auto-renew, renew; two visiting cards made automatically,
 *    and the নিয়োগপত্র when the package includes one.
 *
 * There is no payment gateway yet: a payment is a transaction ID the desk
 * checks by hand (server: NROS_ID_Cards::mark_paid() is where a gateway
 * would plug in).
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  IdCardApi,
  appointmentOf,
  areaText,
  bnMoney,
  cardDataOf,
  hasLetter,
  visitingOf,
  type CardFields,
  type IdCard as Card,
  type IdMe,
  type IdOrder,
  type PayInput,
  type PayMethod,
  type Pkg,
} from '../brand/api'
import { cardBack, cardFront, cardPages, type CardContext, type CardData } from '../brand/idcard'
import { exportPages } from '../brand/export'
import { ExportBar, FlipCard, SvgBox, fileToDataUrl, useBrandFonts } from '../brand/ui'
import { ORG_FALLBACK, bnDate, bnDigits } from '../brand/util'
import { VC_TEMPLATES, visitingFront, visitingPages } from '../brand/visiting'
import { designOf } from '../brand/stationery'
import { MediaApi } from '../lib/api'
import { Banner, Button, Field, Spinner, TopBar, inputClass } from '../components/ui'

const BLOOD = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-']

const ORDER_STATUS: Record<string, string> = {
  awaiting: 'পেমেন্ট বাকি',
  review: 'ডেস্ক যাচাই করছে',
  approved: 'অনুমোদিত',
  rejected: 'প্রত্যাখ্যাত',
  cancelled: 'বাতিল',
}

function copy(text: string) {
  if (navigator.clipboard?.writeText) return navigator.clipboard.writeText(text).catch(() => undefined)
  const t = document.createElement('textarea')
  t.value = text
  document.body.appendChild(t)
  t.select()
  document.execCommand('copy')
  t.remove()
  return Promise.resolve()
}

/** the app scrolls inside its shell, not the window */
const toTop = (smooth = false) => document.getElementById('idcard-top')?.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' })

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

function previewOf(f: CardFields, photo: string, verifyBase: string): CardData {
  const d = new Date()
  const n = new Date(d)
  n.setFullYear(d.getFullYear() + 1)
  return {
    name_bn: f.name_bn || 'আপনার নাম',
    name_en: f.name_en,
    designation: f.designation || 'পদবি',
    designation_en: f.designation_en,
    area: areaText(f),
    card_no: 'MN-XX-XXXX',
    issued: iso(d),
    expires: iso(n),
    blood: f.blood,
    phone: f.phone,
    emergency: f.emergency,
    email: f.email,
    photo,
    verify_url: verifyBase || 'https://meeha.news/verify/',
    status: 'preview',
  }
}

export default function IdCardPage() {
  const nav = useNavigate()
  const fonts = useBrandFonts()
  const [me, setMe] = useState<IdMe | null>(null)
  const [err, setErr] = useState('')
  const [note, setNote] = useState('')
  const [mode, setMode] = useState<'' | 'apply' | 'renew'>('')

  const load = useCallback(() => {
    IdCardApi.me()
      .then((r) => {
        setMe({ ...r, org: { ...ORG_FALLBACK, ...r.org } })
        setErr('')
      })
      .catch((e: Error) => setErr(e.message || 'লোড করা গেল না'))
  }, [])
  useEffect(load, [load])

  const ctx = useMemo<CardContext | null>(() => (me ? { org: me.org, assets: me.assets, terms: me.settings.back_terms } : null), [me])

  const open = me?.orders.find((o) => o.status === 'awaiting' || o.status === 'review') ?? null
  const last = me?.orders[0] ?? null

  const done = (text: string) => {
    setMode('')
    setNote(text)
    load()
    toTop(true)
  }

  return (
    <div className="pb-10" id="idcard-top">
      <TopBar title="মিহা আইডি কার্ড" onBack={() => (mode ? setMode('') : nav('/app/profile'))} />
      <div className="px-4 pt-4">
        {err && !me ? (
          <div className="py-10 text-center">
            <Banner kind="error">{err}</Banner>
            <Button variant="secondary" onClick={load}>
              আবার চেষ্টা
            </Button>
          </div>
        ) : !me || !ctx || !fonts ? (
          <div className="py-16 flex justify-center">
            <Spinner />
          </div>
        ) : mode ? (
          <ApplyFlow me={me} ctx={ctx} renew={mode === 'renew'} onDone={done} />
        ) : (
          <>
            {note && <Banner kind="success">{note}</Banner>}
            {me.card && <CardView me={me} card={me.card} ctx={ctx} onRenew={() => setMode('renew')} busyOrder={!!open} />}
            {open && <OrderTracker me={me} o={open} onChange={load} />}
            {!open && last?.status === 'rejected' && (
              <div className="rounded-2xl bg-red-50 ring-1 ring-red-100 p-4 mb-4">
                <p className="text-[14px] font-semibold text-red-700">আগের আবেদনটি গ্রহণ করা যায়নি</p>
                {last.reason && <p className="text-[13px] text-red-700/90 mt-1">কারণ: {last.reason}</p>}
                <p className="text-[12px] text-red-700/70 mt-1">ঠিক করে আবার আবেদন করুন। পেমেন্ট আয় থেকে কেটে থাকলে তা ফেরত এসেছে।</p>
              </div>
            )}
            {!open && (!me.card || me.card.status === 'cancelled') && <Intro me={me} ctx={ctx} onStart={() => setMode('apply')} />}
            {me.orders.length > 0 && <History orders={me.orders} />}
          </>
        )}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// before applying
// ---------------------------------------------------------------------------

function Intro({ me, ctx, onStart }: { me: IdMe; ctx: CardContext; onStart: () => void }) {
  const s = me.settings
  const sample = previewOf(me.prefill, me.photo, me.org.verify_base ?? '')
  const tpl = s.template || 'royal'
  return (
    <div>
      <div className="rounded-3xl bg-gradient-to-br from-[#071a36] to-[#0d2f63] text-white p-5 mb-4 overflow-hidden relative">
        <p className="text-[12px] tracking-wide text-[#e6cf8f] font-semibold">MEEHA PRESS ID</p>
        <h2 className="text-[20px] font-bold leading-snug mt-1">আপনার নিজের প্রেস আইডি কার্ড</h2>
        <p className="text-[13px] text-white/75 mt-1.5">সামনের QR স্ক্যান করলেই যে কেউ দেখবেন আপনি মিহার যাচাই করা সাংবাদিক — নাম, পদবি, এলাকা আর মেয়াদসহ।</p>
        <div className="grid grid-cols-2 gap-3 mt-4 max-w-[260px] mx-auto">
          <SvgBox svg={cardFront(tpl, sample, ctx, { round: true })} />
          <SvgBox svg={cardBack(tpl, sample, ctx, { round: true })} />
        </div>
      </div>
      {!s.enabled ? (
        <Banner kind="info">এখন আইডি কার্ডের আবেদন বন্ধ আছে। চালু হলে এখানেই জানানো হবে।</Banner>
      ) : (
        <>
          <p className="text-[13px] font-semibold text-neutral-700 mb-2">প্যাকেজ</p>
          <div className="space-y-2.5 mb-4">
            {s.packages.filter((p) => p.active).map((p) => (
              <PkgRow key={p.key} p={p} />
            ))}
          </div>
          {s.apply_note && <p className="text-[12.5px] text-neutral-500 mb-4 whitespace-pre-line">{s.apply_note}</p>}
          <Button onClick={onStart}>আবেদন শুরু করুন</Button>
          <p className="text-[11.5px] text-neutral-400 text-center mt-2">
            মেয়াদ {bnDigits(s.validity_months)} মাস · তারপর রিনিউ (অটো-রিনিউ চালু রাখা যায়)
          </p>
        </>
      )}
    </div>
  )
}

function PkgRow({ p, picked, onPick, renew }: { p: Pkg; picked?: boolean; onPick?: () => void; renew?: boolean }) {
  const price = renew ? p.renew_price || p.price : p.price
  return (
    <button
      type="button"
      onClick={onPick}
      disabled={!onPick}
      className={`relative w-full text-left rounded-2xl p-4 ring-1 transition ${picked ? 'ring-2 ring-brand-600 bg-brand-50/50' : 'ring-neutral-200 bg-white'}`}
    >
      {p.popular && <span className="absolute -top-2 right-3 text-[10.5px] font-bold bg-amber-400 text-amber-950 px-2 py-0.5 rounded-full">জনপ্রিয়</span>}
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-[15px] font-bold text-neutral-900">{p.name}</p>
          <p className="text-[12.5px] text-neutral-600 mt-1 leading-relaxed">{p.items.join(' · ')}</p>
          {p.note && <p className="text-[11.5px] text-neutral-400 mt-1">{p.note}</p>}
        </div>
        <div className="text-right shrink-0">
          <p className="text-[17px] font-extrabold text-brand-700">{bnMoney(price)}</p>
          {!renew && p.renew_price > 0 && <p className="text-[11px] text-neutral-400">রিনিউ {bnMoney(p.renew_price)}</p>}
        </div>
      </div>
    </button>
  )
}

// ---------------------------------------------------------------------------
// apply / renew
// ---------------------------------------------------------------------------

function ApplyFlow({ me, ctx, renew, onDone }: { me: IdMe; ctx: CardContext; renew: boolean; onDone: (t: string) => void }) {
  const s = me.settings
  const pkgs = s.packages.filter((p) => p.active)
  const [step, setStep] = useState(0)
  const [pkgKey, setPkgKey] = useState(() => (renew && me.card ? me.card.pkg_key : (pkgs.find((p) => p.popular) ?? pkgs[0])?.key ?? ''))
  const pkg = pkgs.find((p) => p.key === pkgKey) ?? null
  const [f, setF] = useState<CardFields>(() => ({ ...me.prefill, ...(me.card && !renew ? me.card.fields : {}) }))
  const [photo, setPhoto] = useState(me.photo)
  const [photoPreview, setPhotoPreview] = useState('')
  const [photoBusy, setPhotoBusy] = useState(false)
  const [ship, setShip] = useState(() => me.prefill.address || [me.prefill.upazila, me.prefill.district].filter(Boolean).join(', '))
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)
  const amount = pkg ? (renew ? pkg.renew_price || pkg.price : pkg.price) : 0
  const set = (k: keyof CardFields, v: string) => setF((x) => ({ ...x, [k]: v }))
  const steps = renew ? ['প্যাকেজ', 'পেমেন্ট'] : ['প্যাকেজ', 'তথ্য', 'পেমেন্ট']
  const payStep = steps.length - 1

  async function pickPhoto(file: File) {
    setPhotoBusy(true)
    setErr('')
    try {
      setPhotoPreview(await fileToDataUrl(file, 700))
      const r = await MediaApi.upload(file)
      setPhoto(r.url)
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'ছবি আপলোড হয়নি।')
    } finally {
      setPhotoBusy(false)
    }
  }

  function next() {
    setErr('')
    const fail = (m: string) => (setErr(m), toTop(true))
    if (step === 0 && !pkg) return fail('একটি প্যাকেজ বেছে নিন।')
    if (!renew && step === 1) {
      if (!f.name_bn.trim() || !f.designation.trim()) return fail('নাম ও পদবি দিন।')
      if (!photo && !photoPreview) return fail('একটি পরিষ্কার ছবি দিন।')
      if (pkg?.physical && !ship.trim()) return fail('কিট কোথায় পাঠাবো — ঠিকানা দিন।')
    }
    setStep((x) => x + 1)
    toTop()
  }

  async function submit(pay: PayInput) {
    if (!pkg) return
    setBusy(true)
    setErr('')
    try {
      const r = renew
        ? await IdCardApi.renew({ package: pkg.key, ...pay })
        : await IdCardApi.apply({ package: pkg.key, fields: { ...f, photo }, ship_to: pkg.physical ? ship : '', ...pay })
      const o = r.order
      onDone(
        o.status === 'approved'
          ? '🎉 কার্ড তৈরি হয়ে গেছে!'
          : o.status === 'review'
            ? 'আবেদন জমা হয়েছে। ডেস্ক পেমেন্ট মিলিয়ে অনুমোদন দিলেই কার্ড এখানে চলে আসবে — নোটিফিকেশন পাবেন।'
            : 'আবেদন জমা হয়েছে। পেমেন্ট করে ট্রানজেকশন আইডি দিন — তারপর ডেস্ক যাচাই করবে।',
      )
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'জমা দেওয়া যায়নি।')
    } finally {
      setBusy(false)
    }
  }

  const tpl = me.card?.template || s.template || 'royal'
  const preview = previewOf(f, photoPreview || photo, me.org.verify_base ?? '')

  return (
    <div>
      <div className="flex items-center gap-2 mb-4">
        {steps.map((l, i) => (
          <div key={l} className="flex-1">
            <div className={`h-1.5 rounded-full ${i <= step ? 'bg-brand-600' : 'bg-neutral-200'}`} />
            <p className={`text-[11.5px] mt-1 ${i === step ? 'text-brand-700 font-semibold' : 'text-neutral-400'}`}>
              {bnDigits(i + 1)}. {l}
            </p>
          </div>
        ))}
      </div>
      {err && <Banner kind="error">{err}</Banner>}

      {step === 0 && (
        <>
          <p className="text-[15px] font-bold text-neutral-900 mb-3">{renew ? 'কোন প্যাকেজে রিনিউ করবেন?' : 'প্যাকেজ বেছে নিন'}</p>
          <div className="space-y-2.5 mb-5">
            {pkgs.map((p) => (
              <PkgRow key={p.key} p={p} renew={renew} picked={p.key === pkgKey} onPick={() => setPkgKey(p.key)} />
            ))}
          </div>
          {renew && me.card && <p className="text-[12.5px] text-neutral-500 mb-4">নতুন মেয়াদ শুরু হবে বর্তমান মেয়াদ শেষের দিন থেকে ({bnDate(me.card.expires_at)}), তাই আগে রিনিউ করলেও দিন হারাবেন না।</p>}
          <Button onClick={next}>পরের ধাপ</Button>
        </>
      )}

      {!renew && step === 1 && (
        <>
          <div className="rounded-3xl bg-neutral-100 p-4 mb-4">
            <p className="text-[12px] text-neutral-500 text-center mb-2">লাইভ প্রিভিউ — ট্যাপ করে উল্টে দেখুন</p>
            <div className="max-w-[200px] mx-auto">
              <FlipCard front={cardFront(tpl, preview, ctx, { round: true })} back={cardBack(tpl, preview, ctx, { round: true })} />
            </div>
          </div>

          <input
            ref={fileRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            onChange={(e) => {
              const file = e.target.files?.[0]
              if (file) void pickPhoto(file)
              e.target.value = ''
            }}
          />
          <div className="flex items-center gap-3 mb-4 rounded-2xl ring-1 ring-neutral-200 p-3">
            {photoPreview || photo ? <img src={photoPreview || photo} alt="" className="w-14 h-[70px] rounded-lg object-cover bg-neutral-100" /> : <div className="w-14 h-[70px] rounded-lg bg-neutral-100" />}
            <div className="flex-1 min-w-0">
              <p className="text-[13.5px] font-semibold text-neutral-800">কার্ডের ছবি</p>
              <p className="text-[11.5px] text-neutral-500">সামনে তাকানো, পরিষ্কার মুখ, হালকা জমিন। প্রোফাইলের ছবিই আগে থেকে বসানো।</p>
            </div>
            <Button full={false} variant="secondary" className="!py-2 !px-3 !text-[13px]" disabled={photoBusy} onClick={() => fileRef.current?.click()}>
              {photoBusy ? 'হচ্ছে…' : 'বদলান'}
            </Button>
          </div>

          <Field label="নাম (বাংলা)" required>
            <input className={inputClass} value={f.name_bn} onChange={(e) => set('name_bn', e.target.value)} />
          </Field>
          <Field label="নাম (ইংরেজি, বড় হাতে)">
            <input className={inputClass} value={f.name_en} placeholder="MD. ABDUL KARIM" onChange={(e) => set('name_en', e.target.value.toUpperCase())} />
          </Field>
          <Field label="পদবি" required>
            <select className={inputClass} value={s.designations.includes(f.designation) ? f.designation : '__other'} onChange={(e) => set('designation', e.target.value === '__other' ? '' : e.target.value)}>
              {s.designations.map((d) => (
                <option key={d}>{d}</option>
              ))}
              <option value="__other">অন্য পদবি…</option>
            </select>
            {!s.designations.includes(f.designation) && <input className={`${inputClass} mt-2`} value={f.designation} placeholder="পদবি লিখুন" onChange={(e) => set('designation', e.target.value)} />}
          </Field>
          <Field label="কোন এলাকার জন্য">
            <select className={inputClass} value={f.area_type} onChange={(e) => set('area_type', e.target.value)}>
              {Object.entries(s.area_types).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </Field>
          {(f.area_type === 'upazila' || f.area_type === 'district') && (
            <div className="grid grid-cols-2 gap-3">
              <Field label="জেলা">
                <input className={inputClass} value={f.district} onChange={(e) => set('district', e.target.value)} />
              </Field>
              {f.area_type === 'upazila' ? (
                <Field label="উপজেলা">
                  <input className={inputClass} value={f.upazila} onChange={(e) => set('upazila', e.target.value)} />
                </Field>
              ) : (
                <div />
              )}
            </div>
          )}
          {f.area_type === 'division' && (
            <Field label="বিভাগ">
              <input className={inputClass} value={f.division} onChange={(e) => set('division', e.target.value)} />
            </Field>
          )}
          {f.area_type === 'campus' && (
            <Field label="ক্যাম্পাস / প্রতিষ্ঠান">
              <input className={inputClass} value={f.campus} onChange={(e) => set('campus', e.target.value)} />
            </Field>
          )}
          <p className="-mt-2 mb-4 text-[12px] text-neutral-500">কার্ডে লেখা হবে: <b>{areaText(f) || '—'}</b></p>
          <div className="grid grid-cols-2 gap-3">
            <Field label="রক্তের গ্রুপ">
              <select className={inputClass} value={f.blood} onChange={(e) => set('blood', e.target.value)}>
                <option value="">—</option>
                {BLOOD.map((b) => (
                  <option key={b}>{b}</option>
                ))}
              </select>
            </Field>
            <Field label="ফোন">
              <input className={inputClass} inputMode="tel" value={f.phone} onChange={(e) => set('phone', e.target.value)} />
            </Field>
          </div>
          <Field label="জরুরি যোগাযোগ (নাম ও নম্বর)">
            <input className={inputClass} value={f.emergency} placeholder="যেমন: বাবা — 01XXXXXXXXX" onChange={(e) => set('emergency', e.target.value)} />
          </Field>
          <Field label="ইমেইল">
            <input className={inputClass} inputMode="email" value={f.email} onChange={(e) => set('email', e.target.value)} />
          </Field>
          <Field label="জাতীয় পরিচয়পত্র নম্বর (কার্ডে ছাপা হবে না, শুধু যাচাইয়ের জন্য)">
            <input className={inputClass} inputMode="numeric" value={f.nid} onChange={(e) => set('nid', e.target.value)} />
          </Field>
          {pkg?.physical && (
            <Field label="কিট পাঠানোর ঠিকানা" required>
              <textarea className={inputClass} rows={2} value={ship} onChange={(e) => setShip(e.target.value)} />
            </Field>
          )}
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => setStep(0)}>
              পেছনে
            </Button>
            <Button onClick={next} disabled={photoBusy}>
              পেমেন্টে যান
            </Button>
          </div>
        </>
      )}

      {step === payStep && pkg && (
        <>
          <div className="rounded-2xl bg-neutral-50 ring-1 ring-neutral-200 p-4 mb-4 flex items-center justify-between">
            <div>
              <p className="text-[12px] text-neutral-500">{renew ? 'রিনিউ' : 'প্যাকেজ'}</p>
              <p className="text-[15px] font-bold text-neutral-900">{pkg.name}</p>
            </div>
            <p className="text-[20px] font-extrabold text-brand-700">{bnMoney(amount)}</p>
          </div>
          <PayBox methods={s.methods} amount={amount} balance={me.balance} busy={busy} onPay={submit} allowLater />
          <button type="button" className="w-full mt-3 text-[13px] text-neutral-500" onClick={() => setStep(payStep - 1)}>
            ← পেছনে
          </button>
        </>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// payment
// ---------------------------------------------------------------------------

function PayBox({ methods, amount, balance, busy, onPay, allowLater }: { methods: PayMethod[]; amount: number; balance: number; busy: boolean; onPay: (p: PayInput) => void; allowLater?: boolean }) {
  const [m, setM] = useState(() => (methods.find((x) => x.key === 'wallet' && balance >= amount) ?? methods[0])?.key ?? '')
  const [txn, setTxn] = useState('')
  const [from, setFrom] = useState('')
  const [copied, setCopied] = useState('')
  const pm = methods.find((x) => x.key === m)
  const needsTxn = ['bkash', 'nagad', 'rocket', 'bank'].includes(m)
  const low = m === 'wallet' && balance < amount

  return (
    <div>
      <p className="text-[13px] font-semibold text-neutral-700 mb-2">কীভাবে দেবেন?</p>
      <div className="grid grid-cols-2 gap-2 mb-3">
        {methods.map((x) => (
          <button
            key={x.key}
            type="button"
            onClick={() => setM(x.key)}
            className={`rounded-xl px-3 py-2.5 text-left ring-1 ${m === x.key ? 'ring-2 ring-brand-600 bg-brand-50/60' : 'ring-neutral-200 bg-white'}`}
          >
            <span className="block text-[13.5px] font-semibold text-neutral-900">{x.label}</span>
            {x.key === 'wallet' && <span className={`block text-[11px] ${balance >= amount ? 'text-emerald-600' : 'text-neutral-400'}`}>ব্যালেন্স {bnMoney(balance)}</span>}
          </button>
        ))}
      </div>

      {pm && (
        <div className="rounded-2xl bg-white ring-1 ring-neutral-200 p-4 mb-3">
          {needsTxn && pm.number && (
            <>
              <p className="text-[12.5px] text-neutral-500">
                {pm.label} {pm.kind ? `(${pm.kind})` : ''} নম্বরে <b className="text-neutral-900">{bnMoney(amount)}</b> পাঠান
              </p>
              <div className="flex items-center gap-2 mt-2">
                <p className="flex-1 text-[20px] font-extrabold tracking-wide text-neutral-900 tabular-nums">{pm.number}</p>
                <button
                  type="button"
                  className="px-3 py-1.5 rounded-lg bg-neutral-100 text-[12.5px] font-semibold"
                  onClick={() => copy(pm.number.replace(/\s/g, '')).then(() => setCopied(pm.key))}
                >
                  {copied === pm.key ? '✓ কপি হয়েছে' : 'কপি'}
                </button>
              </div>
            </>
          )}
          {needsTxn && !pm.number && <p className="text-[12.5px] text-amber-700">এই পদ্ধতির নম্বর এখনো দেওয়া হয়নি — ডেস্কের সঙ্গে চ্যাটে কথা বলুন।</p>}
          {pm.help && <p className="text-[12px] text-neutral-500 mt-2 whitespace-pre-line">{pm.help}</p>}
          {m === 'wallet' && <p className="text-[12.5px] text-neutral-600">মিহা থেকে আপনার আয়ের ব্যালেন্স থেকে {bnMoney(amount)} কাটা হবে। আবেদন বাতিল/প্রত্যাখ্যাত হলে টাকা ফেরত আসবে।</p>}
          {low && <p className="text-[12.5px] text-red-600 mt-1">ব্যালেন্সে যথেষ্ট টাকা নেই।</p>}
          {m === 'cash' && !pm.help && <p className="text-[12.5px] text-neutral-600">অফিসে নগদ জমা দিন — ডেস্ক টাকা পেয়ে অনুমোদন দেবে।</p>}
          {needsTxn && (
            <div className="mt-3">
              <Field label="ট্রানজেকশন আইডি (TrxID)" required>
                <input className={`${inputClass} uppercase tracking-wider`} value={txn} placeholder="যেমন: 9JK7XXXXXX" onChange={(e) => setTxn(e.target.value)} />
              </Field>
              <Field label="যে নম্বর থেকে পাঠিয়েছেন">
                <input className={inputClass} inputMode="tel" value={from} placeholder="01XXXXXXXXX" onChange={(e) => setFrom(e.target.value)} />
              </Field>
            </div>
          )}
        </div>
      )}
      <Button disabled={busy || !m || low || (needsTxn && txn.replace(/[^A-Za-z0-9]/g, '').length < 6)} onClick={() => onPay({ pay_method: m, pay_txn: txn, pay_from: from })}>
        {busy ? 'জমা হচ্ছে…' : m === 'wallet' ? `আয় থেকে ${bnMoney(amount)} দিন` : 'জমা দিন'}
      </Button>
      {allowLater && (
        <button type="button" disabled={busy} className="w-full mt-2.5 py-2 text-[13px] font-medium text-brand-600" onClick={() => onPay({})}>
          পরে পেমেন্ট করব — আবেদনটি রেখে দিন
        </button>
      )}
      <p className="text-[11px] text-neutral-400 text-center mt-2">অনলাইন পেমেন্ট গেটওয়ে এখনো যুক্ত হয়নি — ডেস্ক ট্রানজেকশন আইডি মিলিয়ে অনুমোদন দেয়।</p>
    </div>
  )
}

// ---------------------------------------------------------------------------
// an open order
// ---------------------------------------------------------------------------

function OrderTracker({ me, o, onChange }: { me: IdMe; o: IdOrder; onChange: () => void }) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [paying, setPaying] = useState(o.status === 'awaiting')
  const steps = [
    { l: 'আবেদন', on: true },
    { l: 'পেমেন্ট', on: o.status !== 'awaiting' },
    { l: 'যাচাই', on: o.status === 'approved' },
    { l: 'কার্ড', on: o.status === 'approved' },
  ]
  async function pay(p: PayInput) {
    if (!p.pay_method) return setPaying(false)
    setBusy(true)
    setErr('')
    try {
      await IdCardApi.pay(o.id, p)
      onChange()
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'হয়নি।')
    } finally {
      setBusy(false)
    }
  }
  async function cancel() {
    if (!confirm('আবেদনটি বাতিল করবেন?')) return
    setBusy(true)
    try {
      await IdCardApi.cancel(o.id)
      onChange()
    } catch (e) {
      setErr(e instanceof Error ? e.message : 'হয়নি।')
    } finally {
      setBusy(false)
    }
  }
  return (
    <div className="rounded-3xl bg-white ring-1 ring-neutral-200 p-4 mb-4">
      <div className="flex items-center justify-between mb-3">
        <div>
          <p className="text-[12px] text-neutral-500">{o.type === 'renew' ? 'রিনিউ আবেদন' : 'নতুন কার্ডের আবেদন'} · {bnDate(o.created_at.slice(0, 10))}</p>
          <p className="text-[15px] font-bold text-neutral-900">
            {o.pkg?.name ?? o.pkg_key} · {bnMoney(o.amount)}
          </p>
        </div>
        <span className={`text-[11.5px] font-semibold px-2.5 py-1 rounded-full ${o.status === 'awaiting' ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700'}`}>{ORDER_STATUS[o.status]}</span>
      </div>
      <div className="flex items-center mb-3">
        {steps.map((s, i) => (
          <div key={s.l} className="flex-1 flex flex-col items-center relative">
            {i > 0 && <span className={`absolute top-3 right-1/2 w-full h-0.5 ${s.on ? 'bg-brand-600' : 'bg-neutral-200'}`} />}
            <span className={`relative z-[1] w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold ${s.on ? 'bg-brand-600 text-white' : 'bg-neutral-200 text-neutral-500'}`}>{s.on ? '✓' : bnDigits(i + 1)}</span>
            <span className="text-[11px] text-neutral-500 mt-1">{s.l}</span>
          </div>
        ))}
      </div>
      {o.status === 'review' && (
        <p className="text-[12.5px] text-neutral-600 bg-blue-50/60 rounded-xl px-3 py-2">
          {o.pay_method_text}
          {o.pay_txn ? ` · TrxID ${o.pay_txn}` : ''} — ডেস্ক মিলিয়ে দেখছে। অনুমোদন হলেই কার্ড এখানে আসবে, নোটিফিকেশনও পাবেন।
        </p>
      )}
      {err && <Banner kind="error">{err}</Banner>}
      {o.status === 'awaiting' && (paying ? <PayBox methods={me.settings.methods} amount={o.amount} balance={me.balance} busy={busy} onPay={pay} /> : <Button onClick={() => setPaying(true)}>এখন পেমেন্ট করুন</Button>)}
      <button type="button" disabled={busy} onClick={cancel} className="w-full mt-2 py-2 text-[12.5px] text-red-600">
        আবেদন বাতিল করুন
      </button>
    </div>
  )
}

// ---------------------------------------------------------------------------
// the card
// ---------------------------------------------------------------------------

function CardView({ me, card, ctx, onRenew, busyOrder }: { me: IdMe; card: Card; ctx: CardContext; onRenew: () => void; busyOrder: boolean }) {
  const [err, setErr] = useState('')
  const [auto, setAuto] = useState(card.auto_renew)
  const [copied, setCopied] = useState(false)
  const data = cardDataOf(card)
  const tpl = card.template || 'royal'
  const active = card.status === 'active'
  const pkg = me.settings.packages.find((p) => p.key === card.pkg_key) ?? null
  const letter = hasLetter(pkg)
  const soon = active && card.days_left <= 30
  const tone = active ? (soon ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700') : 'bg-red-50 text-red-700'

  async function toggleAuto() {
    const v = !auto
    setAuto(v)
    try {
      await IdCardApi.autoRenew(v)
    } catch (e) {
      setAuto(!v)
      setErr(e instanceof Error ? e.message : 'হয়নি।')
    }
  }

  function letterPages() {
    const d = designOf('appointment')
    const v = { ...d.defaults(me.org), ...appointmentOf(card) }
    v.ref = `${v.ref}${card.card_no}`
    return d.render(v, { org: me.org, assets: me.assets }, 0)
  }

  return (
    <div className="mb-5">
      <div className="rounded-3xl bg-gradient-to-b from-neutral-100 to-neutral-50 p-5 mb-3">
        <div className="max-w-[230px] mx-auto">
          <FlipCard front={cardFront(tpl, data, ctx, { round: true })} back={cardBack(tpl, data, ctx, { round: true })} />
        </div>
        <p className="text-[11.5px] text-neutral-400 text-center mt-2">ট্যাপ করে উল্টে দেখুন</p>
      </div>

      <div className="flex items-center justify-between rounded-2xl ring-1 ring-neutral-200 bg-white px-4 py-3 mb-3">
        <div>
          <p className="text-[12px] text-neutral-500">কার্ড নং {card.card_no}</p>
          <p className="text-[14px] font-semibold text-neutral-900">
            {active ? `মেয়াদ ${bnDate(card.expires_at)} পর্যন্ত` : card.status_text}
            {active && <span className="text-neutral-400 font-normal"> · আর {bnDigits(Math.max(0, card.days_left))} দিন</span>}
          </p>
          {card.cancel_reason && !active && <p className="text-[11.5px] text-red-600">{card.cancel_reason}</p>}
        </div>
        <span className={`text-[11.5px] font-semibold px-2.5 py-1 rounded-full ${tone}`}>{card.status_text}</span>
      </div>

      {err && <Banner kind="error">{err}</Banner>}

      {(soon || card.status === 'expired') && !busyOrder && (
        <div className="rounded-2xl bg-amber-50 ring-1 ring-amber-200 p-4 mb-3">
          <p className="text-[14px] font-semibold text-amber-900">{card.status === 'expired' ? 'কার্ডের মেয়াদ শেষ — QR স্ক্যানে এখন “মেয়াদ শেষ” দেখাচ্ছে' : 'মেয়াদ শেষ হয়ে আসছে'}</p>
          <p className="text-[12px] text-amber-800/80 mt-0.5">রিনিউ করলে নতুন মেয়াদ আগের শেষ দিন থেকে গোনা হবে।</p>
          <Button className="mt-3" onClick={onRenew}>
            রিনিউ করুন
          </Button>
        </div>
      )}

      {active && (
        <>
          <p className="text-[13px] font-semibold text-neutral-700 mb-2">ডাউনলোড</p>
          <ExportBar pages={() => cardPages(tpl, data, ctx, 0, true)} name={`meeha-id-${card.card_no}`} hidePrint onError={setErr} />
          <div className="flex gap-2 mt-3">
            <button
              type="button"
              className="flex-1 rounded-xl bg-neutral-100 py-2.5 text-[13px] font-semibold text-neutral-700"
              onClick={() => copy(card.verify_url).then(() => setCopied(true))}
            >
              {copied ? '✓ লিংক কপি হয়েছে' : '🔗 যাচাইয়ের লিংক কপি'}
            </button>
            <a href={card.verify_url} target="_blank" rel="noreferrer" className="flex-1 rounded-xl bg-neutral-100 py-2.5 text-[13px] font-semibold text-neutral-700 text-center">
              ✅ যাচাই পেজ দেখুন
            </a>
          </div>

          <label className="flex items-center gap-3 rounded-2xl ring-1 ring-neutral-200 bg-white px-4 py-3 mt-3">
            <span className="flex-1">
              <span className="block text-[14px] font-semibold text-neutral-900">অটো-রিনিউ</span>
              <span className="block text-[11.5px] text-neutral-500">মেয়াদ শেষের আগে আয়ের ব্যালেন্স থেকে রিনিউ ফি কেটে নিজে থেকে রিনিউ হবে{pkg ? ` (${bnMoney(pkg.renew_price || pkg.price)})` : ''}।</span>
            </span>
            <input type="checkbox" className="w-5 h-5 accent-brand-600" checked={auto} onChange={toggleAuto} />
          </label>
          {!soon && !busyOrder && (
            <button type="button" className="w-full mt-2 py-2 text-[12.5px] text-brand-600 font-medium" onClick={onRenew}>
              আগেভাগে রিনিউ করুন
            </button>
          )}

          <p className="text-[13px] font-semibold text-neutral-700 mt-5 mb-1">আপনার ভিজিটিং কার্ড</p>
          <p className="text-[12px] text-neutral-500 mb-3">কার্ডের তথ্য দিয়ে নিজে থেকেই তৈরি — প্রিন্টের দোকানে PDF দিলেই হবে (৯০×৫৪ মিমি)।</p>
          <div className="space-y-4">
            {VC_TEMPLATES.map((t) => (
              <div key={t.key} className="rounded-2xl ring-1 ring-neutral-200 bg-white p-3">
                <SvgBox svg={visitingFront(t.key, visitingOf(card), me.org)} />
                <div className="flex items-center justify-between mt-3 gap-2">
                  <p className="text-[13px] font-semibold text-neutral-800">{t.name}</p>
                  <div className="flex gap-2">
                    <button type="button" className="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-[12.5px] font-semibold" onClick={() => exportPages(visitingPages(t.key, visitingOf(card), me.org, 2), 'pdf', `visiting-${card.card_no}-${t.key}`).catch((e: Error) => setErr(e.message))}>
                      ⬇ PDF
                    </button>
                    <button type="button" className="px-3 py-1.5 rounded-lg bg-neutral-100 text-[12.5px] font-semibold" onClick={() => exportPages(visitingPages(t.key, visitingOf(card), me.org, 0), 'png', `visiting-${card.card_no}-${t.key}`).catch((e: Error) => setErr(e.message))}>
                      ⬇ PNG
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>

          {letter && (
            <div className="rounded-2xl ring-1 ring-neutral-200 bg-white p-4 mt-4">
              <p className="text-[14px] font-semibold text-neutral-900">📄 নিয়োগপত্র</p>
              <p className="text-[12px] text-neutral-500 mb-3">আপনার প্যাকেজে আছে — স্বাক্ষর ও সিলসহ A4।</p>
              <ExportBar pages={letterPages} name={`meeha-appointment-${card.card_no}`} hidePrint onError={setErr} />
            </div>
          )}
          <p className="text-[11px] text-neutral-400 text-center mt-4">কার্ডের তথ্যে ভুল থাকলে চ্যাটে ডেস্ককে জানান — ডেস্ক ঠিক করে দিলে এখানে নিজে থেকে বদলে যাবে।</p>
        </>
      )}
      {card.status === 'cancelled' && <p className="text-[12.5px] text-neutral-500">কার্ডটি বাতিল হয়েছে। চাইলে নিচে নতুন করে আবেদন করুন।</p>}
      {card.status === 'suspended' && <p className="text-[12.5px] text-neutral-500">কার্ডটি সাময়িকভাবে স্থগিত। বিস্তারিত জানতে ডেস্কের সঙ্গে চ্যাটে কথা বলুন।</p>}
    </div>
  )
}

function History({ orders }: { orders: IdOrder[] }) {
  return (
    <div className="mt-6">
      <p className="text-[13px] font-semibold text-neutral-700 mb-2">আবেদনের ইতিহাস</p>
      <div className="rounded-2xl ring-1 ring-neutral-200 bg-white divide-y divide-neutral-100">
        {orders.slice(0, 8).map((o) => (
          <div key={o.id} className="px-4 py-2.5 flex items-center justify-between gap-2">
            <div className="min-w-0">
              <p className="text-[13px] font-medium text-neutral-800 truncate">
                {o.type === 'renew' ? 'রিনিউ' : 'নতুন'} · {o.pkg?.name ?? o.pkg_key}
                {o.auto ? ' · অটো' : ''}
              </p>
              <p className="text-[11.5px] text-neutral-400">
                {bnDate(o.created_at.slice(0, 10))} · {bnMoney(o.amount)}
                {o.fulfil ? ` · ${o.fulfil === 'printing' ? 'প্রিন্টে' : o.fulfil === 'shipped' ? 'পাঠানো হয়েছে' : 'পৌঁছেছে'}` : ''}
              </p>
            </div>
            <span className="text-[11.5px] text-neutral-500 shrink-0">{ORDER_STATUS[o.status]}</span>
          </div>
        ))}
      </div>
    </div>
  )
}
