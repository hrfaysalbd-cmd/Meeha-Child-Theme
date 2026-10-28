import { useEffect, useState } from 'react'
import { ApiError, CorrespondentsApi, FinanceApi, WithdrawalsApi, type Correspondent, type FinanceSummary, type WithdrawRequest } from '../lib/api'
import { Badge, Banner, Button, Card, EmptyState, Field, KpiCard, Modal, PageHeader, Spinner, Table, Td, Th, bnDate, bnNumber, inputClass } from '../components/ui'
import { IconWalletCoins } from '../components/Icon'

const ledgerTypeLabel: Record<string, string> = {
  honorarium: 'সম্মানী',
  commission: 'কমিশন',
  bonus: 'বোনাস',
  adjustment: 'সমন্বয়',
  post_payment: 'পোস্ট পেমেন্ট',
  video_payment: 'ভিডিও পেমেন্ট',
  view_bonus: 'ভিউ বোনাস',
  salary: 'স্যালারি',
}

export default function Finance() {
  const [summary, setSummary] = useState<FinanceSummary | null>(null)
  const [withdrawals, setWithdrawals] = useState<WithdrawRequest[]>([])
  const [correspondents, setCorrespondents] = useState<Correspondent[]>([])
  const [loading, setLoading] = useState(true)
  const [forbidden, setForbidden] = useState(false)
  const [error, setError] = useState('')
  const [paying, setPaying] = useState<WithdrawRequest | null>(null)
  const [note, setNote] = useState('')
  const [showAddEntry, setShowAddEntry] = useState(false)
  const [saving, setSaving] = useState(false)

  const [entryUserId, setEntryUserId] = useState('')
  const [entryType, setEntryType] = useState('honorarium')
  const [entryAmount, setEntryAmount] = useState('')
  const [entryNote, setEntryNote] = useState('')

  function load() {
    setLoading(true)
    setForbidden(false)
    Promise.all([FinanceApi.summary(), WithdrawalsApi.list(), CorrespondentsApi.list()])
      .then(([s, w, c]) => {
        setSummary(s)
        setWithdrawals(w)
        setCorrespondents(c)
      })
      .catch((e) => {
        if (e instanceof ApiError && e.status === 403) {
          setForbidden(true)
        } else {
          setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
        }
      })
      .finally(() => setLoading(false))
  }

  useEffect(load, [])

  async function markPaid() {
    if (!paying) return
    setSaving(true)
    try {
      await WithdrawalsApi.markPaid(paying.id, note)
      setPaying(null)
      setNote('')
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setSaving(false)
    }
  }

  async function addEntry(e: React.FormEvent) {
    e.preventDefault()
    setSaving(true)
    try {
      await WithdrawalsApi.addLedgerEntry(Number(entryUserId), entryType, Number(entryAmount), entryNote)
      setShowAddEntry(false)
      setEntryUserId(''); setEntryAmount(''); setEntryNote('')
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <Spinner className="mt-16" />

  if (forbidden) {
    return (
      <div>
        <PageHeader title="ফাইন্যান্স" />
        <Banner kind="info">
          এই তথ্য দেখার অনুমতি আপনার নেই। পাবলিশার সেটিংস থেকে এডিটরের জন্য ফাইন্যান্স দৃশ্যমানতা চালু করলে এটি দেখা যাবে।
        </Banner>
      </div>
    )
  }

  return (
    <div>
      <PageHeader title="ফাইন্যান্স" subtitle="সম্মানী, কমিশন ও উইথড্র রিকোয়েস্ট ব্যবস্থাপনা" />
      {error && <Banner kind="error">{error}</Banner>}

      <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
        <KpiCard label="মোট পরিশোধিত" value={`৳ ${bnNumber(Math.round(summary?.paid_out ?? 0))}`} icon={<IconWalletCoins className="w-5 h-5" />} tint="bg-emerald-50 text-emerald-700" />
        <KpiCard label="পেন্ডিং উইথড্র" value={`৳ ${bnNumber(Math.round(summary?.pending_withdraw ?? 0))}`} icon={<IconWalletCoins className="w-5 h-5" />} tint="bg-amber-50 text-amber-700" />
        {summary?.by_type.map((t) => (
          <KpiCard key={t.type} label={t.type_label || ledgerTypeLabel[t.type] || t.type} value={`৳ ${bnNumber(Math.round(Number(t.total)))}`} tint="bg-neutral-100 text-neutral-700" />
        ))}
        {/* Round 12 — auto-applied (default-rate) post/video payments still awaiting confirmation. */}
        {summary?.pending_by_type?.map((t) => (
          <KpiCard
            key={`p-${t.type}`}
            label={`${t.type_label || ledgerTypeLabel[t.type] || t.type} — নিশ্চিতের অপেক্ষায় (${bnNumber(t.count)})`}
            value={`৳ ${bnNumber(Math.round(Number(t.total)))}`}
            tint="bg-amber-50 text-amber-700"
          />
        ))}
      </div>

      <div className="flex items-center justify-between mb-3">
        <p className="text-[14px] font-semibold text-neutral-800">উইথড্র রিকোয়েস্ট</p>
        <Button size="sm" variant="secondary" onClick={() => setShowAddEntry(true)}>লেজার এন্ট্রি যুক্ত করুন</Button>
      </div>

      {withdrawals.length === 0 ? (
        <Card><EmptyState>কোনো উইথড্র রিকোয়েস্ট নেই।</EmptyState></Card>
      ) : (
        <Card>
          <Table>
            <thead>
              <tr>
                <Th>সংবাদদাতা</Th>
                <Th>পরিমাণ</Th>
                <Th>পদ্ধতি</Th>
                <Th>তারিখ</Th>
                <Th>অবস্থা</Th>
                <Th>কর্ম</Th>
              </tr>
            </thead>
            <tbody>
              {withdrawals.map((w) => (
                <tr key={w.id}>
                  <Td className="font-medium text-neutral-900">{w.display_name}</Td>
                  <Td>৳ {bnNumber(w.amount)}</Td>
                  <Td>{w.method.toUpperCase()}</Td>
                  <Td>{bnDate(w.created_at)}</Td>
                  <Td>
                    <Badge tone={w.status === 'pending' ? 'amber' : w.status === 'paid' ? 'emerald' : 'red'}>
                      {w.status === 'pending' ? 'পেন্ডিং' : w.status === 'paid' ? 'পরিশোধিত' : 'প্রত্যাখ্যাত'}
                    </Badge>
                  </Td>
                  <Td>
                    {w.status === 'pending' ? (
                      <Button size="sm" variant="success" onClick={() => setPaying(w)}>পরিশোধিত চিহ্নিত করুন</Button>
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

      {paying && (
        <Modal title="পরিশোধিত চিহ্নিত করুন" onClose={() => setPaying(null)}>
          <Field label="নোট (ঐচ্ছিক)">
            <textarea className={inputClass} rows={3} value={note} onChange={(e) => setNote(e.target.value)} />
          </Field>
          <Button onClick={markPaid} disabled={saving} className="w-full">নিশ্চিত করুন</Button>
        </Modal>
      )}

      {showAddEntry && (
        <Modal title="লেজার এন্ট্রি যুক্ত করুন" onClose={() => setShowAddEntry(false)}>
          <form onSubmit={addEntry}>
            <Field label="সংবাদদাতা" required>
              <select className={inputClass} value={entryUserId} onChange={(e) => setEntryUserId(e.target.value)} required>
                <option value="">— নির্বাচন করুন —</option>
                {correspondents.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </Field>
            <Field label="ধরন" required>
              <select className={inputClass} value={entryType} onChange={(e) => setEntryType(e.target.value)}>
                {Object.entries(ledgerTypeLabel).map(([k, v]) => (
                  <option key={k} value={k}>{v}</option>
                ))}
              </select>
            </Field>
            <Field label="পরিমাণ (৳)" required>
              <input type="number" step="0.01" className={inputClass} value={entryAmount} onChange={(e) => setEntryAmount(e.target.value)} required />
            </Field>
            <Field label="নোট">
              <textarea className={inputClass} rows={3} value={entryNote} onChange={(e) => setEntryNote(e.target.value)} />
            </Field>
            <Button type="submit" disabled={saving} className="w-full">যুক্ত করুন</Button>
          </form>
        </Modal>
      )}
    </div>
  )
}
