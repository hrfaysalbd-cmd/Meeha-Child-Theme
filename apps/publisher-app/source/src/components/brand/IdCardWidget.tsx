/**
 * r43 — the ID card corner of the overview ("boss app"): orders waiting for
 * the desk, cards running out, and this month's card income.
 * Shows nothing on a server without the ID card module (before 1.26.0).
 */
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { IdAdminApi, bnMoney, type IdOrder, type IdSummary } from '../../brand/api'
import { bnDigits } from '../../brand/util'
import { Card } from '../ui'

export default function IdCardWidget({ className = '' }: { className?: string }) {
  const nav = useNavigate()
  const [sum, setSum] = useState<IdSummary | null>(null)
  const [orders, setOrders] = useState<IdOrder[]>([])
  useEffect(() => {
    let live = true
    IdAdminApi.summary()
      .then((s) => {
        if (!live) return
        setSum(s)
        return IdAdminApi.orders('review').then((r) => live && setOrders(r.items.slice(0, 4)))
      })
      .catch(() => undefined)
    return () => {
      live = false
    }
  }, [])
  if (!sum) return null
  const tiles: [string, number | string, string, string][] = [
    ['যাচাই বাকি', bnDigits(sum.review), 'orders&status=review', sum.review ? 'text-rose-600' : 'text-neutral-400'],
    ['পেমেন্টের অপেক্ষায়', bnDigits(sum.awaiting), 'orders&status=awaiting', 'text-amber-600'],
    ['প্রিন্টে', bnDigits(sum.printing), 'orders&status=approved', 'text-brand-600'],
    ['সক্রিয় কার্ড', bnDigits(sum.active), 'cards&status=active', 'text-emerald-600'],
    ['৩০ দিনে শেষ', bnDigits(sum.expiring), 'cards&status=expiring', sum.expiring ? 'text-amber-600' : 'text-neutral-400'],
    ['এ মাসের আয়', bnMoney(sum.income_month), 'orders&status=approved', 'text-neutral-900'],
  ]
  return (
    <Card className={`p-5 ${className}`}>
      <div className="flex items-center justify-between mb-3">
        <p className="text-[15px] font-bold text-neutral-900">🪪 আইডি কার্ড</p>
        <button onClick={() => nav('/app/id-cards')} className="text-[12.5px] font-semibold text-brand-600">
          ডেস্ক খুলুন →
        </button>
      </div>
      <div className="grid grid-cols-3 sm:grid-cols-6 gap-2">
        {tiles.map(([l, v, to, cls]) => (
          <button key={l} onClick={() => nav(`/app/id-cards?tab=${to}`)} className="rounded-xl bg-neutral-50 hover:bg-neutral-100 px-2.5 py-2 text-left">
            <p className={`text-[17px] font-bold tabular-nums leading-tight ${cls}`}>{v}</p>
            <p className="text-[11.5px] text-neutral-500">{l}</p>
          </button>
        ))}
      </div>
      {orders.length > 0 && (
        <ul className="mt-3 divide-y divide-neutral-100">
          {orders.map((o) => (
            <li key={o.id}>
              <button onClick={() => nav(`/app/id-cards?tab=orders&order=${o.id}`)} className="w-full flex items-center gap-2.5 py-2 text-left">
                {o.person?.avatar ? <img src={o.person.avatar} alt="" className="w-8 h-8 rounded-full object-cover" /> : <span className="w-8 h-8 rounded-full bg-neutral-200" />}
                <span className="flex-1 min-w-0">
                  <span className="block text-[13px] font-semibold text-neutral-800 truncate">{o.fields.name_bn || o.person?.name}</span>
                  <span className="block text-[11.5px] text-neutral-500 truncate">
                    {o.pkg?.name ?? o.pkg_key} · {bnMoney(o.amount)} · {o.pay_method_text}
                  </span>
                </span>
                <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-rose-50 text-rose-600">যাচাই করুন</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
