/**
 * Round 12 — one payment box for an article OR a video (same semantics):
 * amount input + সংরক্ষণ (sets/overrides, approved), a পেন্ডিং/অনুমোদিত badge,
 * and "নিশ্চিত করুন" for an auto-applied default-rate amount awaiting
 * confirmation. Publisher edits; others see the amount read-only.
 */
import { useEffect, useState } from 'react'
import { ApiError } from '../lib/api'
import { Badge, Button, Card, bnNumber, inputClass } from './ui'

type Status = 'pending' | 'approved' | null | undefined

export function PaymentStatusBadge({ status }: { status: Status }) {
  if (status === 'pending') return <Badge tone="amber">পেন্ডিং</Badge>
  if (status === 'approved') return <Badge tone="emerald">অনুমোদিত</Badge>
  return null
}

export default function PaymentPanel({
  kind,
  amount,
  status,
  canEdit,
  onSave,
  onConfirm,
  testId,
}: {
  kind: 'post' | 'video'
  amount: number | null | undefined
  status: Status
  canEdit: boolean
  onSave: (amount: number) => Promise<{ amount: number | null; payment_status?: Status }>
  onConfirm: () => Promise<{ amount: number | null; payment_status?: Status }>
  testId?: string
}) {
  const [value, setValue] = useState(amount ? String(amount) : '')
  const [busy, setBusy] = useState<'' | 'save' | 'confirm'>('')
  const [msg, setMsg] = useState('')
  const [cur, setCur] = useState<{ amount: number | null; status: Status }>({ amount: amount ?? null, status })

  useEffect(() => {
    setCur({ amount: amount ?? null, status })
    setValue(amount ? String(amount) : '')
  }, [amount, status])

  const noun = kind === 'post' ? 'পোস্টের' : 'ভিডিওর'

  async function save() {
    setBusy('save')
    setMsg('')
    try {
      const res = await onSave(Number(value) || 0)
      setCur({ amount: res.amount, status: res.amount ? 'approved' : null })
      setValue(res.amount ? String(res.amount) : '')
      setMsg(res.amount ? 'সংরক্ষিত হয়েছে।' : 'পেমেন্ট সরানো হয়েছে।')
    } catch (err) {
      setMsg(err instanceof ApiError ? err.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setBusy('')
    }
  }

  async function confirm() {
    setBusy('confirm')
    setMsg('')
    try {
      const res = await onConfirm()
      setCur({ amount: res.amount, status: res.payment_status ?? 'approved' })
      setMsg('নিশ্চিত হয়েছে — সংবাদদাতার ব্যালেন্সে যুক্ত হয়েছে।')
    } catch (err) {
      setMsg(err instanceof ApiError ? err.message : 'নিশ্চিত করা যায়নি।')
    } finally {
      setBusy('')
    }
  }

  if (!canEdit && !cur.amount) return null

  return (
    <Card className="p-5">
      <div data-testid={testId}>
        <div className="flex items-center justify-between gap-2 mb-1">
          <p className="text-[13px] font-semibold text-neutral-700">{kind === 'post' ? 'পোস্ট পেমেন্ট' : 'ভিডিও পেমেন্ট'}</p>
          <PaymentStatusBadge status={cur.amount ? cur.status : null} />
        </div>
        {cur.amount ? (
          <p className="text-[20px] font-bold text-neutral-900 mb-1" data-testid="payment-amount">
            ৳ {bnNumber(cur.amount)}
          </p>
        ) : null}
        {cur.status === 'pending' && cur.amount ? (
          <p className="text-[11.5px] text-amber-700 bg-amber-50 rounded-lg px-2.5 py-2 mb-3">
            সংবাদদাতার ডিফল্ট রেট থেকে স্বয়ংক্রিয়ভাবে যুক্ত — নিশ্চিত না করা পর্যন্ত ব্যালেন্সে যোগ হবে না।
          </p>
        ) : (
          <p className="text-[11.5px] text-neutral-400 mb-3">
            সেট করলে এই নির্দিষ্ট {noun} জন্য এই পরিমাণ সংবাদদাতার ইনকামে যুক্ত হবে। খালি রাখলে কোনো ইনকাম দেখাবে না।
          </p>
        )}
        {canEdit && (
          <>
            {cur.status === 'pending' && cur.amount ? (
              <Button variant="success" disabled={!!busy} onClick={confirm} className="w-full mb-2.5">
                {busy === 'confirm' ? 'হচ্ছে…' : `৳ ${bnNumber(cur.amount)} নিশ্চিত করুন`}
              </Button>
            ) : null}
            <div className="flex items-center gap-2">
              <input
                type="number"
                min={0}
                inputMode="decimal"
                aria-label={`${kind === 'post' ? 'পোস্ট' : 'ভিডিও'} পেমেন্টের পরিমাণ`}
                className={`${inputClass} min-h-[44px]`}
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder="যেমন ৫০০"
              />
              <span className="text-[12.5px] text-neutral-400 shrink-0">৳</span>
            </div>
            <Button variant="secondary" size="sm" disabled={!!busy} onClick={save} className="w-full mt-2.5 min-h-[40px]">
              {busy === 'save' ? 'সংরক্ষণ হচ্ছে…' : cur.amount ? 'পরিমাণ বদলে সংরক্ষণ করুন' : 'পেমেন্ট সংরক্ষণ করুন'}
            </Button>
          </>
        )}
        {msg && <p className="text-[11.5px] text-neutral-500 mt-1.5" role="status">{msg}</p>}
      </div>
    </Card>
  )
}
