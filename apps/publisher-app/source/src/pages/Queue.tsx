import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { QueueApi, type QueueItem } from '../lib/api'
import { Banner, Button, Card, EmptyState, Modal, PageHeader, Spinner, Field, inputClass } from '../components/ui'
import { IconPencil } from '../components/Icon'
import { useOptionalNotifications } from '../lib/notifications'

export default function Queue() {
  const navigate = useNavigate()
  const [items, setItems] = useState<QueueItem[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [rejecting, setRejecting] = useState<QueueItem | null>(null)
  const [reason, setReason] = useState('')
  const [busyId, setBusyId] = useState<number | null>(null)
  const notifVersion = useOptionalNotifications()?.version ?? 0

  function load() {
    setLoading(true)
    QueueApi.list()
      .then(setItems)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }

  useEffect(load, [])

  // Round 18: a new-submission notification means the queue just grew.
  useEffect(() => {
    if (notifVersion) QueueApi.list().then(setItems).catch(() => {})
  }, [notifVersion])

  async function approve(id: number) {
    setBusyId(id)
    try {
      await QueueApi.approve(id)
      setItems((prev) => prev.filter((i) => i.id !== id))
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setBusyId(null)
    }
  }

  async function submitReject() {
    if (!rejecting) return
    setBusyId(rejecting.id)
    try {
      await QueueApi.reject(rejecting.id, reason)
      setItems((prev) => prev.filter((i) => i.id !== rejecting.id))
      setRejecting(null)
      setReason('')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div>
      <PageHeader title="এডিটোরিয়াল কিউ" subtitle="সব সংবাদদাতার রিভিউ-অপেক্ষিত সংবাদ" />
      {error && <Banner kind="error">{error}</Banner>}

      {loading ? (
        <Spinner className="mt-10" />
      ) : items.length === 0 ? (
        <Card><EmptyState>রিভিউ তালিকায় এখন কোনো সংবাদ নেই।</EmptyState></Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {items.map((item) => (
            <Card key={item.id} className="overflow-hidden flex flex-col">
              {item.featured_image && (
                <img src={item.featured_image} alt="" className="w-full h-36 object-cover" />
              )}
              <div className="p-4 flex-1 flex flex-col">
                <p className="text-[14px] font-semibold text-neutral-900 mb-1 line-clamp-2">{item.title || 'শিরোনামহীন'}</p>
                <p className="text-[12.5px] text-neutral-500 mb-2 line-clamp-3 flex-1">{item.excerpt}</p>
                <p className="text-[12px] text-neutral-400 mb-3">{item.author?.name ?? '—'}</p>
                <div className="flex gap-2 mb-2">
                  <Button variant="secondary" size="sm" onClick={() => navigate(`/app/write/${item.id}`)} className="flex-1">
                    <IconPencil className="w-3.5 h-3.5" />
                    এডিট করুন
                  </Button>
                </div>
                <div className="flex gap-2">
                  <Button variant="success" size="sm" disabled={busyId === item.id} onClick={() => approve(item.id)} className="flex-1">
                    অনুমোদন
                  </Button>
                  <Button variant="danger" size="sm" disabled={busyId === item.id} onClick={() => setRejecting(item)} className="flex-1">
                    প্রত্যাখ্যান
                  </Button>
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      {rejecting && (
        <Modal title="প্রত্যাখ্যানের কারণ" onClose={() => setRejecting(null)}>
          <Field label="কারণ লিখুন" required>
            <textarea className={inputClass} rows={4} value={reason} onChange={(e) => setReason(e.target.value)} />
          </Field>
          <p className="text-[11.5px] text-neutral-400 -mt-2 mb-3">
            এই কারণ সংবাদদাতার ড্যাশবোর্ডে দেখা যাবে — তিনি সংশোধন না করে আবার সাবমিট করতে পারবেন না।
          </p>
          <Button onClick={submitReject} disabled={busyId === rejecting.id || !reason.trim()} className="w-full">
            প্রত্যাখ্যান নিশ্চিত করুন
          </Button>
        </Modal>
      )}
    </div>
  )
}
