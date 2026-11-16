/**
 * r43 — মিহার তথ্য: saved once, used by every card, letter and print file.
 * Publisher edits; Editors see it read-only.
 */
import { useEffect, useRef, useState } from 'react'
import { BrandApi } from '../../brand/api'
import { withOrg, type BrandAssets, type OrgInfo } from '../../brand/util'
import { MediaApi } from '../../lib/api'
import { logo, mark, setLogoStyle } from '../../brand/logo'
import { useAuth } from '../../lib/AuthContext'
import { Banner, Button, Card, Field, Spinner, inputClass } from '../ui'

type Key = keyof OrgInfo

const GROUPS: { title: string; rows: { k: Key; label: string; area?: boolean; ph?: string }[] }[] = [
  {
    title: 'নাম ও পরিচয়',
    rows: [
      { k: 'name_bn', label: 'নাম (বাংলা)' },
      { k: 'name_en', label: 'নাম (ইংরেজি)' },
      { k: 'tagline_bn', label: 'ট্যাগলাইন (বাংলা)' },
      { k: 'tagline_en', label: 'ট্যাগলাইন (ইংরেজি)' },
      { k: 'reg_no', label: 'নিবন্ধন / অনুমোদন নম্বর', ph: 'যেমন: তথ্য মন্ত্রণালয় নিবন্ধন নং …' },
    ],
  },
  {
    title: 'যোগাযোগ ও অফিস',
    rows: [
      { k: 'phone', label: 'ফোন', ph: '01XXX-XXXXXX' },
      { k: 'phone2', label: 'দ্বিতীয় ফোন' },
      { k: 'whatsapp', label: 'হোয়াটসঅ্যাপ' },
      { k: 'email', label: 'ইমেইল' },
      { k: 'email2', label: 'দ্বিতীয় ইমেইল' },
      { k: 'website', label: 'ওয়েবসাইট' },
      { k: 'address_bn', label: 'অফিসের ঠিকানা (বাংলা)', area: true },
      { k: 'address_en', label: 'অফিসের ঠিকানা (ইংরেজি)', area: true },
      { k: 'office_hours', label: 'অফিস সময়' },
      { k: 'facebook', label: 'ফেসবুক পেজ লিংক' },
      { k: 'youtube', label: 'ইউটিউব লিংক' },
    ],
  },
  {
    title: 'দায়িত্বপ্রাপ্ত ও স্বাক্ষর',
    rows: [
      { k: 'editor_name', label: 'সম্পাদকের নাম' },
      { k: 'editor_title', label: 'সম্পাদকের পদবি' },
      { k: 'publisher_name', label: 'প্রকাশকের নাম' },
      { k: 'publisher_title', label: 'প্রকাশকের পদবি' },
      { k: 'signer_name', label: 'কার্ড/চিঠিতে স্বাক্ষরকারী' },
      { k: 'signer_title', label: 'স্বাক্ষরকারীর পদবি' },
    ],
  },
]

const IMAGES: { k: Key; label: string; help: string }[] = [
  { k: 'signature_url', label: 'স্বাক্ষর (PNG, স্বচ্ছ জমিন)', help: 'সাদা কাগজে সই করে ছবি তুলুন; স্বচ্ছ PNG হলে সবচেয়ে সুন্দর দেখায়।' },
  { k: 'seal_url', label: 'অফিস সিল (PNG)', help: 'গোল সিলের স্বচ্ছ PNG।' },
]

export default function OrgInfoForm({ onSaved }: { onSaved?: (o: OrgInfo, a: BrandAssets) => void }) {
  const { isPublisher } = useAuth()
  const [org, setOrg] = useState<OrgInfo | null>(null)
  const [assets, setAssets] = useState<BrandAssets>({})
  const [busy, setBusy] = useState(false)
  const [upl, setUpl] = useState<Key | ''>('')
  const [msg, setMsg] = useState<{ kind: 'error' | 'success'; text: string } | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)
  const target = useRef<Key>('signature_url')

  useEffect(() => {
    BrandApi.org()
      .then((r) => {
        setOrg(withOrg(r.org))
        setAssets(r.assets ?? {})
      })
      .catch((e) => setMsg({ kind: 'error', text: e.message }))
  }, [])

  if (!org) return msg ? <Banner kind="error">{msg.text}</Banner> : <Spinner className="mt-6" />

  const set = (k: Key, v: string) => {
    if (k === 'logo_style') setLogoStyle(v)
    setOrg((o) => (o ? { ...o, [k]: v } : o))
  }

  async function save() {
    if (!org) return
    setBusy(true)
    setMsg(null)
    try {
      await BrandApi.saveOrg(org)
      const r = await BrandApi.org()
      setOrg(withOrg(r.org))
      setAssets(r.assets ?? {})
      onSaved?.(r.org, r.assets ?? {})
      setMsg({ kind: 'success', text: 'সংরক্ষিত হয়েছে — এখন থেকে সব কার্ড, চিঠি ও ডিজাইনে এই তথ্য বসবে।' })
    } catch (e) {
      setMsg({ kind: 'error', text: e instanceof Error ? e.message : 'সংরক্ষণ হয়নি।' })
    } finally {
      setBusy(false)
    }
  }

  async function onFile(f: File) {
    const k = target.current
    setUpl(k)
    try {
      const r = await MediaApi.upload(f)
      set(k, r.url)
      setMsg({ kind: 'success', text: 'ছবি আপলোড হয়েছে — “সংরক্ষণ করুন” চাপুন।' })
    } catch (e) {
      setMsg({ kind: 'error', text: e instanceof Error ? e.message : 'আপলোড হয়নি।' })
    } finally {
      setUpl('')
    }
  }

  const ro = !isPublisher
  return (
    <div className="space-y-4">
      {ro && <Banner kind="info">মিহার তথ্য বদলাতে পারেন শুধু পাবলিশার। আপনি দেখতে পারছেন।</Banner>}
      {msg && <Banner kind={msg.kind}>{msg.text}</Banner>}
      <input
        ref={fileRef}
        type="file"
        accept="image/png,image/jpeg,image/webp"
        className="hidden"
        onChange={(e) => {
          const f = e.target.files?.[0]
          if (f) onFile(f)
          e.target.value = ''
        }}
      />
      <Card className="p-4">
        <p className="text-[14px] font-bold text-neutral-800 mb-1">লোগো</p>
        <p className="text-[12.5px] text-neutral-500 mb-3">সব কার্ড, প্যাড, চিঠি ও ডিজাইনে এই লোগো বসবে — হালকা জমিনে নীল, গাঢ় জমিনে সাদা।</p>
        <div className="grid sm:grid-cols-2 gap-3">
          {(
            [
              ['simple', 'Meeha News (গোল অক্ষর)'],
              ['serif', 'Meehanews (স্ল্যাব অক্ষর)'],
            ] as const
          ).map(([k, l]) => {
            setLogoStyle(k)
            const light = logo(6, 6, 188, org.color_primary || '#025AB2')
            const dark = logo(6, 6, 188, '#ffffff')
            setLogoStyle(org.logo_style)
            const on = (org.logo_style || 'simple') === k
            return (
              <button
                key={k}
                type="button"
                disabled={ro}
                onClick={() => set('logo_style', k)}
                className={`rounded-xl p-3 text-left ring-1 transition ${on ? 'ring-2 ring-brand-600 bg-brand-50/40' : 'ring-neutral-200 bg-white'}`}
              >
                <svg viewBox="0 0 200 46" className="w-full h-auto" dangerouslySetInnerHTML={{ __html: light }} />
                <svg viewBox="0 0 200 46" className="w-full h-auto mt-2 rounded-lg bg-[#0b2b57]" dangerouslySetInnerHTML={{ __html: dark }} />
                <span className="block mt-2 text-[12.5px] font-semibold text-neutral-700">
                  {on ? '✓ ' : ''}
                  {l}
                </span>
              </button>
            )
          })}
        </div>
        <div className="flex items-center gap-2 mt-3 text-[12px] text-neutral-500">
          <svg viewBox="0 0 24 24" className="w-6 h-6" dangerouslySetInnerHTML={{ __html: mark(0, 0, 24, org.color_primary || '#025AB2') }} />
          ছোট জায়গায় (আইকন, সিল, কোণে) গোল “M” চিহ্নটা বসে।
        </div>
      </Card>
      {GROUPS.map((g) => (
        <Card key={g.title} className="p-4">
          <p className="text-[14px] font-bold text-neutral-800 mb-3">{g.title}</p>
          <div className="grid sm:grid-cols-2 gap-3">
            {g.rows.map((r) => (
              <div key={r.k} className={r.area ? 'sm:col-span-2' : ''}>
                <Field label={r.label}>
                  {r.area ? (
                    <textarea rows={2} className={inputClass} value={String(org[r.k] ?? '')} disabled={ro} placeholder={r.ph} onChange={(e) => set(r.k, e.target.value)} />
                  ) : (
                    <input className={inputClass} value={String(org[r.k] ?? '')} disabled={ro} placeholder={r.ph} onChange={(e) => set(r.k, e.target.value)} />
                  )}
                </Field>
              </div>
            ))}
          </div>
        </Card>
      ))}
      <Card className="p-4">
        <p className="text-[14px] font-bold text-neutral-800 mb-3">স্বাক্ষর, সিল ও রং</p>
        <div className="grid sm:grid-cols-2 gap-4">
          {IMAGES.map((im) => {
            const prev = im.k === 'signature_url' ? assets.signature : assets.seal
            const url = String(org[im.k] ?? '')
            return (
              <div key={im.k} className="rounded-xl border border-neutral-200 p-3">
                <p className="text-[13px] font-semibold text-neutral-700">{im.label}</p>
                <p className="text-[12px] text-neutral-500 mb-2">{im.help}</p>
                <div className="h-20 rounded-lg bg-[repeating-conic-gradient(#f1f5f9_0%_25%,#fff_0%_50%)] [background-size:16px_16px] flex items-center justify-center overflow-hidden">
                  {url ? <img src={prev || url} alt="" className="max-h-20 max-w-full object-contain" /> : <span className="text-[12px] text-neutral-400">এখনো নেই</span>}
                </div>
                {!ro && (
                  <div className="flex gap-2 mt-2">
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={!!upl}
                      onClick={() => {
                        target.current = im.k
                        fileRef.current?.click()
                      }}
                    >
                      {upl === im.k ? 'আপলোড হচ্ছে…' : url ? 'বদলান' : 'আপলোড'}
                    </Button>
                    {url && (
                      <Button size="sm" variant="danger" onClick={() => set(im.k, '')}>
                        সরান
                      </Button>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </div>
        <div className="grid grid-cols-3 gap-3 mt-4">
          {(
            [
              ['color_primary', 'মূল রং (নীল)'],
              ['color_accent', 'এক্সেন্ট (লাল)'],
              ['color_gold', 'গোল্ড'],
            ] as [Key, string][]
          ).map(([k, l]) => (
            <label key={k} className="flex items-center gap-2 text-[12.5px] text-neutral-600">
              <input type="color" disabled={ro} value={String(org[k] || '#000000')} onChange={(e) => set(k, e.target.value)} className="w-9 h-9 rounded-lg border border-neutral-200" />
              {l}
            </label>
          ))}
        </div>
      </Card>
      {!ro && (
        <div className="sticky bottom-3 flex justify-end">
          <Button onClick={save} disabled={busy} className="shadow-lg">
            {busy ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন'}
          </Button>
        </div>
      )}
    </div>
  )
}
