import { useEffect, useState } from 'react'
import { CorrespondentsApi, InvestigationsApi, type Correspondent, type InvestigationCase } from '../lib/api'
import { Badge, Banner, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, Table, Td, Th, bnDate, inputClass } from '../components/ui'
import { IconPlus } from '../components/Icon'

const statusTone: Record<string, 'amber' | 'emerald' | 'red'> = {
  open: 'amber',
  no_violation: 'emerald',
  violation_confirmed: 'red',
}
const statusLabel: Record<string, string> = {
  open: 'চলমান',
  no_violation: 'লঙ্ঘন পাওয়া যায়নি',
  violation_confirmed: 'লঙ্ঘন নিশ্চিত',
}

export default function Investigations() {
  const [items, setItems] = useState<InvestigationCase[]>([])
  const [correspondents, setCorrespondents] = useState<Correspondent[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showOpen, setShowOpen] = useState(false)
  const [resolving, setResolving] = useState<InvestigationCase | null>(null)
  const [saving, setSaving] = useState(false)

  const [correspondentId, setCorrespondentId] = useState('')
  const [summary, setSummary] = useState('')
  const [verdict, setVerdict] = useState<'no_violation' | 'violation_confirmed'>('no_violation')
  const [evidenceNotes, setEvidenceNotes] = useState('')
  const [resolutionNotes, setResolutionNotes] = useState('')

  function load() {
    setLoading(true)
    Promise.all([InvestigationsApi.list(), CorrespondentsApi.list()])
      .then(([cases, corrs]) => {
        setItems(cases)
        setCorrespondents(corrs)
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }

  useEffect(load, [])

  async function openCase(e: React.FormEvent) {
    e.preventDefault()
    setSaving(true)
    try {
      await InvestigationsApi.open({ correspondent_id: Number(correspondentId), summary })
      setShowOpen(false)
      setCorrespondentId(''); setSummary('')
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setSaving(false)
    }
  }

  async function submitResolve() {
    if (!resolving) return
    setSaving(true)
    try {
      await InvestigationsApi.resolve(resolving.id, verdict, evidenceNotes, resolutionNotes)
      setResolving(null)
      setEvidenceNotes(''); setResolutionNotes('')
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="তদন্ত"
        subtitle="বিজ্ঞাপনদাতার সাথে যোগসাজশ/সংবাদ চাপা দেওয়ার অভিযোগ — সত্য প্রতিবেদনের জন্য কখনো তদন্ত খোলা হয় না"
        actions={
          <Button onClick={() => setShowOpen(true)}>
            <IconPlus className="w-4 h-4" /> কেস খুলুন
          </Button>
        }
      />
      {error && <Banner kind="error">{error}</Banner>}

      {loading ? (
        <Spinner className="mt-10" />
      ) : items.length === 0 ? (
        <Card><EmptyState>কোনো তদন্ত কেস নেই।</EmptyState></Card>
      ) : (
        <Card>
          <Table>
            <thead>
              <tr>
                <Th>সংবাদদাতা</Th>
                <Th>ক্লায়েন্ট</Th>
                <Th>সারসংক্ষেপ</Th>
                <Th>তারিখ</Th>
                <Th>অবস্থা</Th>
                <Th>কর্ম</Th>
              </tr>
            </thead>
            <tbody>
              {items.map((c) => (
                <tr key={c.id}>
                  <Td className="font-medium text-neutral-900">{c.correspondent_name ?? '—'}</Td>
                  <Td>{c.client_name ?? '—'}</Td>
                  <Td className="max-w-xs truncate">{c.summary || '—'}</Td>
                  <Td>{bnDate(c.created_at)}</Td>
                  <Td><Badge tone={statusTone[c.status]}>{statusLabel[c.status]}</Badge></Td>
                  <Td>
                    {c.status === 'open' ? (
                      <Button size="sm" variant="secondary" onClick={() => setResolving(c)}>সমাধান করুন</Button>
                    ) : (
                      '—'
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {showOpen && (
        <Modal title="নতুন তদন্ত কেস খুলুন" onClose={() => setShowOpen(false)}>
          <form onSubmit={openCase}>
            <Field label="সংবাদদাতা" required>
              <select className={inputClass} value={correspondentId} onChange={(e) => setCorrespondentId(e.target.value)} required>
                <option value="">— নির্বাচন করুন —</option>
                {correspondents.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </Field>
            <Field label="সারসংক্ষেপ / অভিযোগ" required>
              <textarea className={inputClass} rows={4} value={summary} onChange={(e) => setSummary(e.target.value)} required />
            </Field>
            <Button type="submit" disabled={saving} className="w-full">কেস খুলুন</Button>
          </form>
        </Modal>
      )}

      {resolving && (
        <Modal title="তদন্তের ফলাফল" onClose={() => setResolving(null)}>
          <Field label="ফলাফল">
            <select className={inputClass} value={verdict} onChange={(e) => setVerdict(e.target.value as typeof verdict)}>
              <option value="no_violation">লঙ্ঘন পাওয়া যায়নি</option>
              <option value="violation_confirmed">লঙ্ঘন নিশ্চিত হয়েছে</option>
            </select>
          </Field>
          <Field label="প্রমাণ/নোট">
            <textarea className={inputClass} rows={3} value={evidenceNotes} onChange={(e) => setEvidenceNotes(e.target.value)} />
          </Field>
          <Field label="সিদ্ধান্তের কারণ">
            <textarea className={inputClass} rows={3} value={resolutionNotes} onChange={(e) => setResolutionNotes(e.target.value)} />
          </Field>
          <Button onClick={submitResolve} disabled={saving} className="w-full">সমাধান নিশ্চিত করুন</Button>
        </Modal>
      )}
    </div>
  )
}
