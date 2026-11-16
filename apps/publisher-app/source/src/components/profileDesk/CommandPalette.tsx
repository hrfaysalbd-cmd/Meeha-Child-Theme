// r31 — Ctrl+K (Mac: ⌘K) যেকোনো পাতা থেকে প্রোফাইল খোঁজা: নাম/লিংক লিখুন, ↑↓ দিয়ে বাছুন,
// Enter = প্রোফাইল খুলুন, Shift+Enter = তাঁর সব লিংক নতুন ট্যাবে।
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ProfileDeskApi, openMany, type PdEntity } from '../../lib/profileDesk'
import { Avatar, LinkChips } from './bits'

export default function CommandPalette() {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [items, setItems] = useState<PdEntity[]>([])
  const [sel, setSel] = useState(0)
  const input = useRef<HTMLInputElement>(null)
  const navigate = useNavigate()

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        setOpen((o) => !o)
      } else if (e.key === 'Escape') setOpen(false)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])
  useEffect(() => {
    if (open) {
      setQ('')
      setSel(0)
      setTimeout(() => input.current?.focus(), 0)
    }
  }, [open])
  useEffect(() => {
    if (!open) return
    const t = setTimeout(() => {
      ProfileDeskApi.list(q.trim() ? { q, per_page: 8 } : { pinned: true, per_page: 8 })
        .then((r) => {
          setItems(r.items)
          setSel(0)
        })
        .catch(() => setItems([]))
    }, 150)
    return () => clearTimeout(t)
  }, [q, open])

  if (!open) return null
  function go(e: PdEntity, all: boolean) {
    setOpen(false)
    if (all) openMany(e.links.filter((l) => l.verified !== 2).map((l) => l.url))
    else navigate(`/app/profile-desk/${e.id}`)
  }
  return (
    <div className="fixed inset-0 z-[60] bg-black/40 flex items-start justify-center pt-[12vh] px-3" onClick={() => setOpen(false)}>
      <div className="w-full max-w-xl bg-white rounded-2xl shadow-2xl overflow-hidden" onClick={(e) => e.stopPropagation()} data-testid="pd-palette">
        <input
          ref={input}
          value={q}
          onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'ArrowDown') (e.preventDefault(), setSel((s) => Math.min(s + 1, items.length - 1)))
            else if (e.key === 'ArrowUp') (e.preventDefault(), setSel((s) => Math.max(s - 1, 0)))
            else if (e.key === 'Enter' && items[sel]) go(items[sel], e.shiftKey)
          }}
          placeholder="প্রোফাইল খুঁজুন: নাম, পদ, ফোন বা লিংক…"
          className="w-full px-5 py-4 text-[15px] border-b border-neutral-100 focus:outline-none"
        />
        <div className="max-h-[50vh] overflow-y-auto py-1">
          {!q.trim() && items.length > 0 && <p className="px-5 pt-2 pb-1 text-[11px] font-semibold text-neutral-400">পিন করা</p>}
          {items.map((e, i) => (
            <div
              key={e.id}
              onMouseEnter={() => setSel(i)}
              onClick={() => go(e, false)}
              className={`flex items-center gap-3 px-5 py-2 cursor-pointer ${i === sel ? 'bg-brand-50' : ''}`}
            >
              <Avatar name={e.name} photo={e.photo} kind={e.kind} size={32} />
              <div className="min-w-0 flex-1">
                <p className="text-[13.5px] font-semibold truncate">{e.name}</p>
                <p className="text-[11.5px] text-neutral-500 truncate">{[e.title, e.org?.name].filter(Boolean).join(' · ')}</p>
              </div>
              <div onClick={(x) => x.stopPropagation()}>
                <LinkChips links={e.links} size="sm" max={3} />
              </div>
            </div>
          ))}
          {q.trim() && !items.length && <p className="px-5 py-6 text-center text-[13px] text-neutral-400">কিছু পাওয়া যায়নি।</p>}
        </div>
        <p className="px-5 py-2 border-t border-neutral-100 text-[11px] text-neutral-400">
          Enter = প্রোফাইল খুলুন · Shift+Enter = সব লিংক নতুন ট্যাবে · Esc = বন্ধ
        </p>
      </div>
    </div>
  )
}
