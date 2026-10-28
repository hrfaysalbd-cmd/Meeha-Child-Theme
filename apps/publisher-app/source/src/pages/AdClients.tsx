import { useEffect, useState } from 'react'
import { AdClientsApi, CorrespondentsApi, type AdClient, type Correspondent } from '../lib/api'
import { Badge, Banner, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, Table, Td, Th, bnDate, inputClass } from '../components/ui'
import { IconPlus } from '../components/Icon'

export default function AdClients() {
  const [items, setItems] = useState<AdClient[]>([])
  const [correspondents, setCorrespondents] = useState<Correspondent[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [saving, setSaving] = useState(false)

  const [businessName, setBusinessName] = useState('')
  const [contactName, setContactName] = useState('')
  const [phone, setPhone] = useState('')
  const [whatsapp, setWhatsapp] = useState('')
  const [address, setAddress] = useState('')
  const [referredBy, setReferredBy] = useState('')
  const [notes, setNotes] = useState('')

  function load() {
    setLoading(true)
    Promise.all([AdClientsApi.list(), CorrespondentsApi.list()])
      .then(([clients, corrs]) => {
        setItems(clients)
        setCorrespondents(corrs)
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }

  useEffect(load, [])

  async function createClient(e: React.FormEvent) {
    e.preventDefault()
    setSaving(true)
    try {
      await AdClientsApi.create({
        business_name: businessName,
        contact_name: contactName,
        phone,
        whatsapp,
        address,
        referred_by: referredBy ? Number(referredBy) : undefined,
        notes,
      })
      setShowCreate(false)
      setBusinessName(''); setContactName(''); setPhone(''); setWhatsapp(''); setAddress(''); setReferredBy(''); setNotes('')
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setSaving(false)
    }
  }

  async function toggleStatus(client: AdClient) {
    const nextStatus = client.status === 'active' ? 'inactive' : 'active'
    try {
      await AdClientsApi.update(client.id, { status: nextStatus })
      setItems((prev) => prev.map((c) => (c.id === client.id ? { ...c, status: nextStatus } : c)))
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    }
  }

  function referrerName(id: number | null) {
    if (!id) return '—'
    return correspondents.find((c) => c.id === id)?.name ?? `#${id}`
  }

  return (
    <div>
      <PageHeader
        title="বিজ্ঞাপনদাতা"
        subtitle="সংবাদদাতাদের মাধ্যমে রেফার করা বিজ্ঞাপনী প্রতিষ্ঠানের তালিকা"
        actions={
          <Button onClick={() => setShowCreate(true)}>
            <IconPlus className="w-4 h-4" /> নতুন ক্লায়েন্ট
          </Button>
        }
      />
      {error && <Banner kind="error">{error}</Banner>}

      {loading ? (
        <Spinner className="mt-10" />
      ) : items.length === 0 ? (
        <Card><EmptyState>এখনো কোনো ক্লায়েন্ট যুক্ত হয়নি।</EmptyState></Card>
      ) : (
        <Card>
          <Table>
            <thead>
              <tr>
                <Th>প্রতিষ্ঠান</Th>
                <Th>যোগাযোগ</Th>
                <Th>রেফারেন্স</Th>
                <Th>ডিলিং</Th>
                <Th>তারিখ</Th>
                <Th>অবস্থা</Th>
                <Th>কর্ম</Th>
              </tr>
            </thead>
            <tbody>
              {items.map((c) => (
                <tr key={c.id}>
                  <Td className="font-medium text-neutral-900">
                    {c.business_name}
                    {c.visiting_card && (
                      <a href={c.visiting_card} target="_blank" rel="noreferrer" className="block text-[11px] text-brand-600 font-normal">
                        ভিজিটিং কার্ড দেখুন
                      </a>
                    )}
                  </Td>
                  <Td>
                    {[c.contact_name, c.phone].filter(Boolean).join(' · ') || '—'}
                    {c.whatsapp && <span className="block text-[11.5px] text-neutral-400">WhatsApp: {c.whatsapp}</span>}
                  </Td>
                  <Td>{referrerName(c.referred_by)}</Td>
                  <Td>
                    {c.dealing_status === 'final' ? (
                      <Badge tone="emerald">ফাইনাল</Badge>
                    ) : c.dealing_status === 'interested' ? (
                      <Badge tone="amber">আগ্রহী</Badge>
                    ) : (
                      <span className="text-neutral-300">—</span>
                    )}
                  </Td>
                  <Td>{bnDate(c.created_at)}</Td>
                  <Td><Badge tone={c.status === 'active' ? 'emerald' : 'neutral'}>{c.status === 'active' ? 'সক্রিয়' : 'নিষ্ক্রিয়'}</Badge></Td>
                  <Td>
                    <Button size="sm" variant="secondary" onClick={() => toggleStatus(c)}>
                      {c.status === 'active' ? 'নিষ্ক্রিয় করুন' : 'সক্রিয় করুন'}
                    </Button>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {showCreate && (
        <Modal title="নতুন বিজ্ঞাপনদাতা ক্লায়েন্ট" onClose={() => setShowCreate(false)}>
          <form onSubmit={createClient}>
            <Field label="প্রতিষ্ঠানের নাম" required>
              <input className={inputClass} value={businessName} onChange={(e) => setBusinessName(e.target.value)} required />
            </Field>
            <Field label="যোগাযোগকারীর নাম">
              <input className={inputClass} value={contactName} onChange={(e) => setContactName(e.target.value)} />
            </Field>
            <Field label="ফোন">
              <input className={inputClass} value={phone} onChange={(e) => setPhone(e.target.value)} />
            </Field>
            <Field label="হোয়াটসঅ্যাপ">
              <input className={inputClass} value={whatsapp} onChange={(e) => setWhatsapp(e.target.value)} />
            </Field>
            <Field label="ঠিকানা">
              <textarea className={inputClass} rows={2} value={address} onChange={(e) => setAddress(e.target.value)} />
            </Field>
            <Field label="রেফার করেছেন (সংবাদদাতা)">
              <select className={inputClass} value={referredBy} onChange={(e) => setReferredBy(e.target.value)}>
                <option value="">— কেউ না —</option>
                {correspondents.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </Field>
            <Field label="নোট">
              <textarea className={inputClass} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
            </Field>
            <Button type="submit" disabled={saving} className="w-full">যুক্ত করুন</Button>
          </form>
        </Modal>
      )}
    </div>
  )
}
