import { useEffect, useState } from 'react'
import {
  AdClientsApi,
  CorrespondentsApi,
  SuspensionsApi,
  type AdClient,
  type Correspondent,
  type Suspension,
  type SuspensionDuration,
  type SuspensionType,
} from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { Badge, Banner, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, Table, Td, Th, inputClass } from '../components/ui'
import { IconPlus } from '../components/Icon'

const typeLabel: Record<SuspensionType, string> = {
  client_commission: 'নির্দিষ্ট ক্লায়েন্টের কমিশন স্থগিত',
  all_commission: 'সব কমিশন স্থগিত',
  news_submission: 'সংবাদ জমাদান স্থগিত',
  account: 'সম্পূর্ণ একাউন্ট সাসপেন্ড',
}
const durationLabel: Record<SuspensionDuration, string> = {
  '1m': '১ মাস',
  '3m': '৩ মাস',
  '6m': '৬ মাস',
  lifetime: 'স্থায়ী',
}

export default function Suspensions() {
  const { isPublisher } = useAuth()
  const [items, setItems] = useState<Suspension[]>([])
  const [correspondents, setCorrespondents] = useState<Correspondent[]>([])
  const [clients, setClients] = useState<AdClient[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showIssue, setShowIssue] = useState(false)
  const [saving, setSaving] = useState(false)

  const [correspondentId, setCorrespondentId] = useState('')
  const [type, setType] = useState<SuspensionType>('news_submission')
  const [duration, setDuration] = useState<SuspensionDuration>('1m')
  const [clientId, setClientId] = useState('')
  const [reason, setReason] = useState('')

  function load() {
    setLoading(true)
    Promise.all([SuspensionsApi.list(), CorrespondentsApi.list(), AdClientsApi.list()])
      .then(([s, c, cl]) => {
        setItems(s)
        setCorrespondents(c)
        setClients(cl)
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }

  useEffect(load, [])

  async function issue(e: React.FormEvent) {
    e.preventDefault()
    setSaving(true)
    try {
      await SuspensionsApi.issue({
        correspondent_id: Number(correspondentId),
        type,
        duration,
        client_id: type === 'client_commission' ? Number(clientId) : undefined,
        reason,
      })
      setShowIssue(false)
      setCorrespondentId(''); setReason(''); setClientId('')
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setSaving(false)
    }
  }

  async function lift(id: number) {
    try {
      await SuspensionsApi.lift(id)
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    }
  }

  async function reviewAppeal(id: number, verdict: 'upheld' | 'overturned') {
    try {
      await SuspensionsApi.reviewAppeal(id, verdict)
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    }
  }

  const needsPublisherSignOff = type === 'account' || duration === 'lifetime'

  return (
    <div>
      <PageHeader
        title="সাসপেনশন"
        subtitle="চার-স্তরের শাস্তি ব্যবস্থা — সম্পূর্ণ একাউন্ট ও স্থায়ী মেয়াদের সিদ্ধান্ত শুধু পাবলিশার নিতে পারবেন"
        actions={
          <Button onClick={() => setShowIssue(true)}>
            <IconPlus className="w-4 h-4" /> সাসপেনশন ইস্যু করুন
          </Button>
        }
      />
      {error && <Banner kind="error">{error}</Banner>}

      {loading ? (
        <Spinner className="mt-10" />
      ) : items.length === 0 ? (
        <Card><EmptyState>কোনো সাসপেনশন রেকর্ড নেই।</EmptyState></Card>
      ) : (
        <Card>
          <Table>
            <thead>
              <tr>
                <Th>সংবাদদাতা</Th>
                <Th>ধরন</Th>
                <Th>মেয়াদ</Th>
                <Th>কারণ</Th>
                <Th>অবস্থা</Th>
                <Th>আপিল</Th>
                <Th>কর্ম</Th>
              </tr>
            </thead>
            <tbody>
              {items.map((s) => (
                <tr key={s.id}>
                  <Td className="font-medium text-neutral-900">{s.correspondent_name ?? '—'}</Td>
                  <Td>{typeLabel[s.type]}</Td>
                  <Td>{durationLabel[s.duration]}</Td>
                  <Td className="max-w-xs truncate">{s.reason || '—'}</Td>
                  <Td>
                    <Badge tone={s.status === 'active' ? 'red' : s.status === 'lifted' ? 'neutral' : 'amber'}>
                      {s.status === 'active' ? 'সক্রিয়' : s.status === 'lifted' ? 'তুলে নেওয়া হয়েছে' : 'মেয়াদ শেষ'}
                    </Badge>
                  </Td>
                  <Td>
                    {s.appeal_status === 'pending' ? (
                      <Badge tone="amber">পেন্ডিং</Badge>
                    ) : s.appeal_status === 'upheld' ? (
                      <Badge tone="red">বহাল</Badge>
                    ) : s.appeal_status === 'overturned' ? (
                      <Badge tone="emerald">বাতিল হয়েছে</Badge>
                    ) : (
                      '—'
                    )}
                  </Td>
                  <Td>
                    <div className="flex gap-1.5">
                      {s.status === 'active' && (
                        <Button size="sm" variant="secondary" onClick={() => lift(s.id)}>তুলে নিন</Button>
                      )}
                      {isPublisher && s.appeal_status === 'pending' && (
                        <>
                          <Button size="sm" variant="success" onClick={() => reviewAppeal(s.id, 'overturned')}>বাতিল করুন</Button>
                          <Button size="sm" variant="danger" onClick={() => reviewAppeal(s.id, 'upheld')}>বহাল রাখুন</Button>
                        </>
                      )}
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {showIssue && (
        <Modal title="সাসপেনশন ইস্যু করুন" onClose={() => setShowIssue(false)}>
          <form onSubmit={issue}>
            <Field label="সংবাদদাতা" required>
              <select className={inputClass} value={correspondentId} onChange={(e) => setCorrespondentId(e.target.value)} required>
                <option value="">— নির্বাচন করুন —</option>
                {correspondents.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </Field>
            <Field label="ধরন" required>
              <select className={inputClass} value={type} onChange={(e) => setType(e.target.value as SuspensionType)}>
                {Object.entries(typeLabel).map(([k, v]) => (
                  <option key={k} value={k}>{v}</option>
                ))}
              </select>
            </Field>
            {type === 'client_commission' && (
              <Field label="ক্লায়েন্ট" required>
                <select className={inputClass} value={clientId} onChange={(e) => setClientId(e.target.value)} required>
                  <option value="">— নির্বাচন করুন —</option>
                  {clients.map((c) => (
                    <option key={c.id} value={c.id}>{c.business_name}</option>
                  ))}
                </select>
              </Field>
            )}
            <Field label="মেয়াদ" required>
              <select className={inputClass} value={duration} onChange={(e) => setDuration(e.target.value as SuspensionDuration)}>
                {Object.entries(durationLabel).map(([k, v]) => (
                  <option key={k} value={k}>{v}</option>
                ))}
              </select>
            </Field>
            <Field label="কারণ" required>
              <textarea className={inputClass} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} required />
            </Field>

            {needsPublisherSignOff && !isPublisher && (
              <Banner kind="error">সম্পূর্ণ একাউন্ট সাসপেনশন বা স্থায়ী মেয়াদের সিদ্ধান্ত শুধু পাবলিশার নিতে পারবেন।</Banner>
            )}

            <Button type="submit" disabled={saving || (needsPublisherSignOff && !isPublisher)} className="w-full">
              ইস্যু করুন
            </Button>
          </form>
        </Modal>
      )}
    </div>
  )
}
