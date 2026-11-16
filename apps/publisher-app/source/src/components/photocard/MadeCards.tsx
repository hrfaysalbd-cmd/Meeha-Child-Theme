/**
 * Round 24 — "রাখা কার্ড": the cards kept in the media library (from here
 * or from wp-admin's studio), newest first.
 */
import { useEffect, useState } from 'react'
import { PhotocardApi, type MadeCard } from '../../lib/api'
import { bnDate } from '../ui'
import { IconExternalLink } from '../Icon'

export default function MadeCards({ refresh, labels }: { refresh: number; labels: Record<string, string> }) {
  const [items, setItems] = useState<MadeCard[] | null>(null)
  const [page, setPage] = useState(1)
  const [pages, setPages] = useState(1)

  useEffect(() => {
    let live = true
    PhotocardApi.made(page)
      .then((r) => {
        if (!live) return
        setItems(r.items)
        setPages(r.pages || 1)
      })
      .catch(() => live && setItems([]))
    return () => {
      live = false
    }
  }, [page, refresh])

  if (items === null) return <p className="text-[12.5px] text-neutral-400">আনা হচ্ছে…</p>
  if (!items.length) return <p className="text-[12.5px] text-neutral-400">এখনো কোনো কার্ড রাখা হয়নি। “মিডিয়ায় রাখুন” চাপলে এখানে জমা থাকবে।</p>
  return (
    <div>
      <ul className="grid grid-cols-4 sm:grid-cols-6 gap-2">
        {items.map((c) => (
          <li key={c.id}>
            <a href={c.url} target="_blank" rel="noopener noreferrer" className="group block relative rounded-lg overflow-hidden border border-neutral-200" title={`${labels[c.type] || c.type} — ${c.title}${c.by ? ` · ${c.by}` : ''} · ${bnDate(c.date)}`}>
              <img src={c.thumb} alt={c.title} loading="lazy" className="w-full aspect-square object-cover bg-neutral-100" />
              <span className="absolute inset-x-0 bottom-0 bg-black/55 text-white text-[10px] px-1 py-0.5 truncate">{labels[c.type] || c.type}</span>
              <IconExternalLink className="w-3.5 h-3.5 absolute right-1 top-1 text-white drop-shadow opacity-0 group-hover:opacity-100" />
            </a>
          </li>
        ))}
      </ul>
      {pages > 1 && (
        <div className="flex justify-between mt-2 text-[12.5px]">
          <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="text-brand-600 disabled:text-neutral-300">‹ নতুনগুলো</button>
          <button type="button" disabled={page >= pages} onClick={() => setPage(page + 1)} className="text-brand-600 disabled:text-neutral-300">পুরোনোগুলো ›</button>
        </div>
      )}
    </div>
  )
}
