import { useEffect, useRef, useState } from 'react'
import { AppDownloadCard } from '../components/AppDownloadCard'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../lib/AuthContext'
import { AvatarApi, LedgerApi, AdLeadsApi, ApiError, type AdLead, type DealingStatus } from '../lib/api'
import { Banner, Button, Field, inputClass, Spinner } from '../components/ui'
import { IconBuildingStore, IconCamera, IconLogout, IconPlus } from '../components/Icon'

const emptyLeadForm = { business_name: '', contact_name: '', phone: '', whatsapp: '', address: '', dealing_status: '' as DealingStatus | '' }

function dealingLabel(status: DealingStatus | null) {
  if (status === 'final') return { label: 'ফাইনাল', cls: 'bg-emerald-50 text-emerald-700' }
  if (status === 'interested') return { label: 'আগ্রহী', cls: 'bg-amber-50 text-amber-700' }
  return null
}

const methods = [
  { value: 'bkash', label: 'বিকাশ' },
  { value: 'nagad', label: 'নগদ' },
  { value: 'rocket', label: 'রকেট' },
  { value: 'bank', label: 'ব্যাংক একাউন্ট' },
]

function InfoRow({ label, value }: { label: string; value: string }) {
  if (!value) return null
  return (
    <div className="flex items-center justify-between py-2.5 border-b border-neutral-100 last:border-0">
      <span className="text-[13px] text-neutral-500">{label}</span>
      <span className="text-[14px] text-neutral-900 font-medium">{value}</span>
    </div>
  )
}

export default function Profile() {
  const { profile, logout, refreshProfile } = useAuth()
  const navigate = useNavigate()
  const avatarInput = useRef<HTMLInputElement>(null)
  const cardInput = useRef<HTMLInputElement>(null)

  const [avatarBusy, setAvatarBusy] = useState(false)
  const [avatarError, setAvatarError] = useState('')

  const [leads, setLeads] = useState<AdLead[]>([])
  const [leadsLoading, setLeadsLoading] = useState(true)
  const [showLeadForm, setShowLeadForm] = useState(false)
  const [editingLeadId, setEditingLeadId] = useState<number | null>(null)
  const [leadForm, setLeadForm] = useState(emptyLeadForm)
  const [leadSaving, setLeadSaving] = useState(false)
  const [leadError, setLeadError] = useState('')
  const [cardTargetId, setCardTargetId] = useState<number | null>(null)
  const [cardBusyId, setCardBusyId] = useState<number | null>(null)

  const [loading, setLoading] = useState(true)
  const [method, setMethod] = useState('')
  const [last4, setLast4] = useState<string | null>(null)
  const [configured, setConfigured] = useState(false)

  const [number, setNumber] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [saving, setSaving] = useState(false)
  const [editingMethod, setEditingMethod] = useState(false)

  useEffect(() => {
    LedgerApi.getMethod()
      .then((m) => {
        setConfigured(m.configured)
        setLast4(m.account_last4)
        if (m.method) setMethod(m.method)
      })
      // Round 9 follow-up 4: this had no .catch(), so a failed load surfaced
      // as an "Uncaught (in promise)" error in the console. On failure the
      // section simply stays in its "not set yet" state.
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  function loadLeads() {
    AdLeadsApi.list()
      .then(setLeads)
      .catch(() => setLeads([]))
      .finally(() => setLeadsLoading(false))
  }

  useEffect(loadLeads, [])

  function startNewLead() {
    setEditingLeadId(null)
    setLeadForm(emptyLeadForm)
    setLeadError('')
    setShowLeadForm(true)
  }

  function startEditLead(lead: AdLead) {
    setEditingLeadId(lead.id)
    setLeadForm({
      business_name: lead.business_name,
      contact_name: lead.contact_name,
      phone: lead.phone,
      whatsapp: lead.whatsapp,
      address: lead.address,
      dealing_status: lead.dealing_status ?? '',
    })
    setLeadError('')
    setShowLeadForm(true)
  }

  async function saveLead() {
    setLeadError('')
    if (!leadForm.business_name.trim()) {
      setLeadError('প্রতিষ্ঠানের নাম দিন।')
      return
    }
    setLeadSaving(true)
    try {
      if (editingLeadId) {
        const updated = await AdLeadsApi.update(editingLeadId, leadForm)
        setLeads((prev) => prev.map((l) => (l.id === editingLeadId ? updated : l)))
      } else {
        const created = await AdLeadsApi.create(leadForm)
        setLeads((prev) => [created, ...prev])
      }
      setShowLeadForm(false)
      setEditingLeadId(null)
      setLeadForm(emptyLeadForm)
    } catch (err) {
      setLeadError(err instanceof ApiError ? err.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setLeadSaving(false)
    }
  }

  function onPickCard(id: number, file: File) {
    setCardBusyId(id)
    AdLeadsApi.uploadCard(id, file)
      .then((res) => {
        setLeads((prev) => prev.map((l) => (l.id === id ? { ...l, visiting_card: res.visiting_card } : l)))
      })
      .catch(() => {})
      .finally(() => setCardBusyId(null))
  }

  async function removeCard(id: number) {
    setCardBusyId(id)
    try {
      await AdLeadsApi.removeCard(id)
      setLeads((prev) => prev.map((l) => (l.id === id ? { ...l, visiting_card: null } : l)))
    } catch {
      // no-op — a harmless failure here just leaves the existing photo in place.
    } finally {
      setCardBusyId(null)
    }
  }

  async function saveMethod() {
    setError('')
    if (!method) {
      setError('একটি পদ্ধতি নির্বাচন করুন।')
      return
    }
    if (number.replace(/\s+/g, '').length < 4) {
      setError('একাউন্ট/নম্বর সঠিকভাবে দিন।')
      return
    }
    setSaving(true)
    try {
      await LedgerApi.saveMethod(method, number)
      const m = await LedgerApi.getMethod()
      setConfigured(m.configured)
      setLast4(m.account_last4)
      setEditingMethod(false)
      setNumber('')
      setNotice('উইথড্র পদ্ধতি সংরক্ষিত হয়েছে।')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setSaving(false)
    }
  }

  async function onLogout() {
    await logout()
    navigate('/landing', { replace: true })
  }

  async function onPickAvatar(file: File) {
    setAvatarError('')
    setAvatarBusy(true)
    try {
      await AvatarApi.upload(file)
      await refreshProfile()
    } catch (err) {
      setAvatarError(err instanceof ApiError ? err.message : 'ছবি যুক্ত করা যায়নি।')
    } finally {
      setAvatarBusy(false)
    }
  }

  async function onRemoveAvatar() {
    setAvatarError('')
    setAvatarBusy(true)
    try {
      await AvatarApi.remove()
      await refreshProfile()
    } catch (err) {
      setAvatarError(err instanceof ApiError ? err.message : 'ছবি মুছে ফেলা যায়নি।')
    } finally {
      setAvatarBusy(false)
    }
  }

  return (
    <div className="px-5 pt-5 pb-6">
      <AppDownloadCard className="mb-5" />
      <div className="flex flex-col items-center text-center mb-6">
        <input
          ref={avatarInput}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          className="hidden"
          onChange={(e) => {
            const f = e.target.files?.[0]
            if (f) onPickAvatar(f)
            e.target.value = ''
          }}
        />
        <button
          type="button"
          onClick={() => avatarInput.current?.click()}
          disabled={avatarBusy}
          className="relative w-20 h-20 rounded-full mb-3"
          aria-label="প্রোফাইল ছবি পরিবর্তন করুন"
        >
          <img src={profile?.avatar} alt="" className="w-20 h-20 rounded-full bg-neutral-100 object-cover" />
          <span className="absolute -bottom-0.5 -right-0.5 w-7 h-7 rounded-full bg-brand-600 text-white flex items-center justify-center border-2 border-white">
            <IconCamera className="w-3.5 h-3.5" />
          </span>
        </button>
        <h1 className="text-[17px] font-bold text-neutral-900">{profile?.name}</h1>
        {profile?.title && <p className="text-[13px] text-neutral-500">{profile.title}</p>}
        <div className="flex items-center gap-3 mt-2">
          <button onClick={() => avatarInput.current?.click()} disabled={avatarBusy} className="text-[12.5px] text-brand-600 font-medium">
            {avatarBusy ? 'হচ্ছে…' : 'ছবি পরিবর্তন করুন'}
          </button>
          <button onClick={onRemoveAvatar} disabled={avatarBusy} className="text-[12.5px] text-neutral-400 font-medium">
            ডিফল্টে ফিরুন
          </button>
        </div>
        {avatarError && <p className="text-[12px] text-red-600 mt-2">{avatarError}</p>}
      </div>

      {notice && <Banner kind="success">{notice}</Banner>}

      <p className="text-[13px] font-semibold text-neutral-700 mb-2">প্রোফাইল তথ্য</p>
      <div className="rounded-2xl border border-neutral-100 px-4 mb-6">
        <InfoRow label="ইমেইল" value={profile?.email ?? ''} />
        <InfoRow label="ফোন" value={profile?.phone ?? ''} />
        <InfoRow label="বিভাগ" value={profile?.division ?? ''} />
        <InfoRow label="জেলা" value={profile?.district ?? ''} />
        <InfoRow label="উপজেলা" value={profile?.upazila ?? ''} />
      </div>

      <input
        ref={cardInput}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        className="hidden"
        onChange={(e) => {
          const f = e.target.files?.[0]
          if (f && cardTargetId) onPickCard(cardTargetId, f)
          e.target.value = ''
        }}
      />

      <div className="flex items-center justify-between mb-2">
        <p className="text-[13px] font-semibold text-neutral-700">বিজ্ঞাপনদাতার তথ্য</p>
        {!showLeadForm && (
          <button onClick={startNewLead} className="flex items-center gap-1 text-[12.5px] text-brand-600 font-medium">
            <IconPlus className="w-3.5 h-3.5" /> নতুন
          </button>
        )}
      </div>

      {leadsLoading ? (
        <Spinner className="mb-4" />
      ) : (
        <div className="flex flex-col gap-2 mb-4">
          {leads.length === 0 && !showLeadForm && (
            <p className="text-[12.5px] text-neutral-400 py-2">এখনো কোনো বিজ্ঞাপনদাতার তথ্য যুক্ত করেননি।</p>
          )}
          {leads.map((lead) => {
            const dealing = dealingLabel(lead.dealing_status)
            return (
              <div key={lead.id} className="rounded-2xl border border-neutral-100 p-3.5">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-[14px] font-semibold text-neutral-900 flex items-center gap-1.5">
                      <IconBuildingStore className="w-4 h-4 text-neutral-400 shrink-0" />
                      <span className="truncate">{lead.business_name}</span>
                    </p>
                    <p className="text-[12.5px] text-neutral-500 mt-0.5">
                      {[lead.contact_name, lead.phone].filter(Boolean).join(' · ') || '—'}
                    </p>
                    {lead.whatsapp && <p className="text-[12px] text-neutral-400">WhatsApp: {lead.whatsapp}</p>}
                    {lead.address && <p className="text-[12px] text-neutral-400 mt-0.5">{lead.address}</p>}
                  </div>
                  {dealing && <span className={`shrink-0 text-[11px] font-medium px-2 py-0.5 rounded-full ${dealing.cls}`}>{dealing.label}</span>}
                </div>

                <div className="flex items-center gap-3 mt-2.5 pt-2.5 border-t border-neutral-100">
                  <button onClick={() => startEditLead(lead)} className="text-[12px] text-brand-600 font-medium">
                    এডিট করুন
                  </button>
                  <button
                    onClick={() => {
                      setCardTargetId(lead.id)
                      cardInput.current?.click()
                    }}
                    disabled={cardBusyId === lead.id}
                    className="text-[12px] text-neutral-500 font-medium"
                  >
                    {cardBusyId === lead.id ? 'হচ্ছে…' : lead.visiting_card ? 'কার্ড বদলান' : 'ভিজিটিং কার্ড যুক্ত করুন'}
                  </button>
                  {lead.visiting_card && (
                    <>
                      <a href={lead.visiting_card} target="_blank" rel="noreferrer" className="text-[12px] text-neutral-500 font-medium underline">
                        দেখুন
                      </a>
                      <button onClick={() => removeCard(lead.id)} disabled={cardBusyId === lead.id} className="text-[12px] text-red-500 font-medium">
                        মুছুন
                      </button>
                    </>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {showLeadForm && (
        <div className="rounded-2xl border border-neutral-100 p-4 mb-5">
          {leadError && <Banner kind="error">{leadError}</Banner>}
          <Field label="প্রতিষ্ঠানের নাম" required>
            <input
              className={inputClass}
              value={leadForm.business_name}
              onChange={(e) => setLeadForm({ ...leadForm, business_name: e.target.value })}
            />
          </Field>
          <Field label="যোগাযোগকারীর নাম">
            <input
              className={inputClass}
              value={leadForm.contact_name}
              onChange={(e) => setLeadForm({ ...leadForm, contact_name: e.target.value })}
            />
          </Field>
          <Field label="ফোন নাম্বার">
            <input className={inputClass} value={leadForm.phone} onChange={(e) => setLeadForm({ ...leadForm, phone: e.target.value })} />
          </Field>
          <Field label="হোয়াটসঅ্যাপ নাম্বার">
            <input className={inputClass} value={leadForm.whatsapp} onChange={(e) => setLeadForm({ ...leadForm, whatsapp: e.target.value })} />
          </Field>
          <Field label="ঠিকানা">
            <textarea
              className={inputClass}
              rows={2}
              value={leadForm.address}
              onChange={(e) => setLeadForm({ ...leadForm, address: e.target.value })}
            />
          </Field>
          <Field label="ডিলিং স্ট্যাটাস">
            <select
              className={inputClass}
              value={leadForm.dealing_status}
              onChange={(e) => setLeadForm({ ...leadForm, dealing_status: e.target.value as DealingStatus | '' })}
            >
              <option value="">নির্বাচন করুন</option>
              <option value="interested">আগ্রহী</option>
              <option value="final">ফাইনাল</option>
            </select>
          </Field>
          <div className="flex gap-2">
            <Button onClick={saveLead} disabled={leadSaving}>
              {leadSaving ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন'}
            </Button>
            <Button variant="secondary" onClick={() => setShowLeadForm(false)} full={false} className="px-5">
              বাতিল
            </Button>
          </div>
        </div>
      )}

      <div className="flex items-center justify-between mb-2">
        <p className="text-[13px] font-semibold text-neutral-700">উইথড্র পদ্ধতি</p>
        {!loading && configured && !editingMethod && (
          <button onClick={() => setEditingMethod(true)} className="text-[12.5px] text-brand-600 font-medium">
            পরিবর্তন করুন
          </button>
        )}
      </div>

      {loading ? (
        <Spinner />
      ) : configured && !editingMethod ? (
        <div className="rounded-2xl border border-neutral-100 p-3.5 mb-6 flex items-center justify-between">
          <span className="text-[14px] text-neutral-900 font-medium">
            {methods.find((m) => m.value === method)?.label ?? method}
          </span>
          <span className="text-[13px] text-neutral-400">····{last4}</span>
        </div>
      ) : (
        <div className="rounded-2xl border border-neutral-100 p-4 mb-6">
          {error && <Banner kind="error">{error}</Banner>}
          <Field label="পদ্ধতি" required>
            <select className={inputClass} value={method} onChange={(e) => setMethod(e.target.value)}>
              <option value="">নির্বাচন করুন</option>
              {methods.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </select>
          </Field>
          <Field label="একাউন্ট/মোবাইল নম্বর" required>
            <input className={inputClass} value={number} onChange={(e) => setNumber(e.target.value)} placeholder="01XXXXXXXXX" />
          </Field>
          <Button onClick={saveMethod} disabled={saving}>
            {saving ? 'সংরক্ষণ হচ্ছে…' : 'সংরক্ষণ করুন'}
          </Button>
        </div>
      )}

      <Button onClick={onLogout} variant="danger" className="flex items-center justify-center gap-2">
        <IconLogout className="w-4.5 h-4.5" />
        লগআউট
      </Button>
    </div>
  )
}
