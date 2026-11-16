import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { AdminPostsApi, PostsApi, ApiError, type AdminPostListRow, type PostListFilter } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { Badge, Banner, Button, Card, EmptyState, PageHeader, Spinner, Table, Td, Th, bnNumber, inputClass } from '../components/ui'
import { IconCoin, IconExternalLink, IconEye, IconPencil, IconPhoto, IconTrash } from '../components/Icon'
import { DeleteConfirmModal, RowMenu } from '../components/PostManagement'
import { NEED_PUBLISHER_PERMISSION, bnDateTime, bnTime, usePostPermissions } from '../lib/postMeta'

const tabs: { value: PostListFilter; label: string }[] = [
  { value: 'all', label: 'সব' },
  { value: 'publish', label: 'প্রকাশিত' },
  { value: 'future', label: 'নির্ধারিত' },
  { value: 'pending', label: 'রিভিউতে' },
  { value: 'rejected', label: 'প্রত্যাখ্যাত' },
  { value: 'draft', label: 'ড্রাফট' },
]

function statusLabel(row: AdminPostListRow) {
  if (row.rejected) return <Badge tone="red">প্রত্যাখ্যাত</Badge>
  if (row.status === 'publish') return <Badge tone="emerald">প্রকাশিত</Badge>
  if (row.status === 'pending') return <Badge tone="amber">রিভিউতে</Badge>
  if (row.status === 'future') return <Badge tone="blue">নির্ধারিত</Badge>
  return <Badge tone="neutral">ড্রাফট</Badge>
}

// One row's inline payment editor — set/edit an amount, or confirm a
// pending (auto-applied default-rate) amount without changing it. Kept as
// its own small component so each row has its own input state.
function PaymentCell({
  row,
  isPublisher,
  onSaved,
}: {
  row: AdminPostListRow
  isPublisher: boolean
  onSaved: (patch: Partial<AdminPostListRow>) => void
}) {
  const [editing, setEditing] = useState(false)
  const [value, setValue] = useState(row.payment ? String(row.payment) : '')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  if (row.status !== 'publish') {
    return <span className="text-neutral-300">—</span>
  }

  if (!isPublisher) {
    return row.payment ? (
      <span className="font-medium text-neutral-700">
        ৳ {bnNumber(row.payment)}
        {row.payment_status === 'pending' && <span className="ml-1 text-amber-600 text-[11px]">(পেন্ডিং)</span>}
      </span>
    ) : (
      <span className="text-neutral-300">—</span>
    )
  }

  async function save() {
    setBusy(true)
    setErr('')
    try {
      const amount = Number(value) || 0
      const res = await AdminPostsApi.setPayment(row.id, amount)
      onSaved({ payment: res.amount, payment_status: res.amount ? 'approved' : null })
      setEditing(false)
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setBusy(false)
    }
  }

  async function confirm() {
    setBusy(true)
    setErr('')
    try {
      const res = await AdminPostsApi.confirmPayment(row.id)
      onSaved({ payment: res.amount, payment_status: res.payment_status })
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'নিশ্চিত করা যায়নি।')
    } finally {
      setBusy(false)
    }
  }

  if (editing) {
    return (
      <div className="flex items-center gap-1.5">
        <input
          type="number"
          min={0}
          autoFocus
          className={`${inputClass} w-24 py-1`}
          value={value}
          onChange={(e) => setValue(e.target.value)}
          placeholder="৫০"
        />
        <Button size="sm" disabled={busy} onClick={save}>
          {busy ? '…' : 'সংরক্ষণ'}
        </Button>
        <Button size="sm" variant="ghost" onClick={() => setEditing(false)}>
          বাতিল
        </Button>
        {err && <span className="text-red-600 text-[11px]">{err}</span>}
      </div>
    )
  }

  return (
    <div className="flex items-center gap-2">
      {row.payment ? (
        <span className="flex items-center gap-1 font-medium text-neutral-800">
          <IconCoin className="w-3.5 h-3.5 text-emerald-600" /> ৳ {bnNumber(row.payment)}
          {row.payment_status === 'pending' && <Badge tone="amber">পেন্ডিং</Badge>}
        </span>
      ) : (
        <span className="text-neutral-300">—</span>
      )}
      <button onClick={() => setEditing(true)} className="text-[11.5px] text-brand-600 font-medium">
        {row.payment ? 'পরিবর্তন' : 'সেট করুন'}
      </button>
      {row.payment_status === 'pending' && (
        <Button size="sm" variant="success" disabled={busy} onClick={confirm}>
          নিশ্চিত করুন
        </Button>
      )}
      {err && <span className="text-red-600 text-[11px]">{err}</span>}
    </div>
  )
}

export default function Posts() {
  const navigate = useNavigate()
  const location = useLocation()
  const { isPublisher } = useAuth()
  const perms = usePostPermissions()
  // A notice handed over by PostEditor after a delete (router state), shown
  // once and then dropped from history so a reload doesn't repeat it.
  const [notice, setNotice] = useState<string>(() => (location.state as { notice?: string } | null)?.notice ?? '')
  const [deleting, setDeleting] = useState<AdminPostListRow | null>(null)
  const [filter, setFilter] = useState<PostListFilter>('all')
  const [items, setItems] = useState<AdminPostListRow[]>([])
  const [page, setPage] = useState(1)
  const [pages, setPages] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  function load(f: PostListFilter, p: number) {
    setLoading(true)
    setError('')
    PostsApi.listAll(f, p)
      .then((res) => {
        setItems(res.items)
        setPages(res.pages || 1)
        setTotal(res.total)
      })
      .catch((e) => setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।'))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    if ((location.state as { notice?: string } | null)?.notice) {
      navigate(location.pathname, { replace: true, state: null })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    load(filter, page)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filter, page])

  function onTabChange(next: PostListFilter) {
    setFilter(next)
    setPage(1)
  }

  function patchRow(id: number, patch: Partial<AdminPostListRow>) {
    setItems((prev) => prev.map((r) => (r.id === id ? { ...r, ...patch } : r)))
  }

  async function confirmDelete() {
    if (!deleting) return
    const row = deleting
    await AdminPostsApi.remove(row.id)
    setItems((prev) => prev.filter((r) => r.id !== row.id))
    setTotal((t) => Math.max(0, t - 1))
    setDeleting(null)
    setNotice(`“${row.title || 'শিরোনামহীন'}” ট্র্যাশে সরানো হয়েছে — wp-admin থেকে ফিরিয়ে আনা যাবে।`)
  }

  return (
    <div>
      <PageHeader title="নিউজ লিস্ট" subtitle={`সব সংবাদদাতা ও স্টাফের সংবাদ — মোট ${bnNumber(total)}টি`} />
      {error && <Banner kind="error">{error}</Banner>}
      {notice && <Banner kind="success">{notice}</Banner>}

      <div className="flex gap-1.5 flex-wrap mb-4">
        {tabs.map((t) => (
          <button
            key={t.value}
            onClick={() => onTabChange(t.value)}
            className={`px-3 py-1.5 rounded-full text-[12.5px] font-medium ${
              filter === t.value ? 'bg-brand-600 text-white' : 'bg-neutral-100 text-neutral-600'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {loading ? (
        <Spinner className="mt-10" />
      ) : items.length === 0 ? (
        <Card><EmptyState>এই তালিকায় এখন কোনো সংবাদ নেই।</EmptyState></Card>
      ) : (
        <Card>
          <Table>
            <thead>
              <tr>
                <Th>শিরোনাম</Th>
                <Th>লেখক</Th>
                <Th>অবস্থা</Th>
                <Th>ভিউ</Th>
                <Th>পেমেন্ট</Th>
                <Th>তারিখ</Th>
                <Th>কর্ম</Th>
              </tr>
            </thead>
            <tbody>
              {items.map((row) => (
                <tr key={row.id}>
                  <Td className="font-medium text-neutral-900 max-w-[280px] truncate">{row.title || 'শিরোনামহীন'}</Td>
                  <Td>
                    {row.guest_author ? (
                      <span className="block" data-testid="guest-author-cell">
                        <span className="flex items-center gap-1.5">
                          <span className="truncate max-w-[180px] text-neutral-800">{row.guest_author.name}</span>
                          <Badge tone="amber">অতিথি</Badge>
                        </span>
                        {row.author && <span className="block text-[11px] text-neutral-400 truncate max-w-[220px]">পোস্ট করেছেন: {row.author.name}</span>}
                      </span>
                    ) : (
                      row.author?.name ?? '—'
                    )}
                  </Td>
                  <Td>{statusLabel(row)}</Td>
                  <Td>
                    {row.status === 'publish' ? (
                      <span className="flex items-center gap-1 text-neutral-500">
                        <IconEye className="w-3.5 h-3.5" /> {bnNumber(row.views)}
                      </span>
                    ) : (
                      '—'
                    )}
                  </Td>
                  <Td>
                    <PaymentCell row={row} isPublisher={isPublisher} onSaved={(patch) => patchRow(row.id, patch)} />
                  </Td>
                  <Td className="whitespace-nowrap">
                    <span className="block text-neutral-700">{bnDateTime(row.date ?? row.created_at, false)}</span>
                    <span className="block text-[11.5px] text-neutral-400">{bnTime(row.date ?? row.created_at)}</span>
                  </Td>
                  <Td>
                    <div className="flex items-center gap-1">
                      <Button size="sm" variant="secondary" onClick={() => navigate(`/app/write/${row.id}`)}>
                        <IconPencil className="w-3.5 h-3.5" />
                        এডিট
                      </Button>
                      <RowMenu
                        items={[
                          ...(row.permalink
                            ? [{ label: 'সাইটে দেখুন', icon: <IconExternalLink className="w-4 h-4" />, href: row.permalink }]
                            : []),
                          // Round 24 — straight into the studio with this post's words and photos.
                          { label: 'ফটোকার্ড বানান', icon: <IconPhoto className="w-4 h-4" />, onClick: () => navigate(`/app/photocard?post=${row.id}`) },
                          {
                            label: 'ডিলিট করুন',
                            icon: <IconTrash className="w-4 h-4" />,
                            danger: true,
                            disabled: !perms.deletePosts,
                            hint: perms.deletePosts ? 'ট্র্যাশে যাবে' : NEED_PUBLISHER_PERMISSION,
                            onClick: () => setDeleting(row),
                          },
                        ]}
                      />
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {deleting && (
        <DeleteConfirmModal kind="post" title={deleting.title} onConfirm={confirmDelete} onClose={() => setDeleting(null)} />
      )}

      {pages > 1 && (
        <div className="flex items-center justify-center gap-2 mt-4">
          <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            আগের
          </Button>
          <span className="text-[12.5px] text-neutral-500">
            {bnNumber(page)} / {bnNumber(pages)}
          </span>
          <Button size="sm" variant="secondary" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>
            পরের
          </Button>
        </div>
      )}
    </div>
  )
}
