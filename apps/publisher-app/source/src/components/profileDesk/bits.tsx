// r30 — প্রোফাইল ডেস্কের ছোট অংশ: link chips with new-post badges, avatar,
// importance stars, category/status labels, the "open them all" bar.
import { useState } from 'react'
import { ProfileDeskApi, bn, openMany, type PdLink, type PdMeta, type Platform } from '../../lib/profileDesk'
import { useOptionalNotifications } from '../../lib/notifications'
import { IconPlayerPlay, IconStar, PLATFORM_COLOR, PlatformIcon } from './icons'

export const PLATFORM_LABEL: Record<Platform, string> = {
  facebook: 'ফেসবুক',
  x: 'এক্স',
  youtube: 'ইউটিউব',
  tiktok: 'টিকটক',
  instagram: 'ইনস্টাগ্রাম',
  telegram: 'টেলিগ্রাম',
  linkedin: 'লিংকডইন',
  whatsapp: 'হোয়াটসঅ্যাপ',
  website: 'ওয়েবসাইট',
  other: 'লিংক',
}

/** Platforms whose new posts can be counted automatically. */
export const AUTO_PLATFORMS: Platform[] = ['youtube', 'telegram', 'website']

export const PLATFORM_ORDER: Platform[] = ['facebook', 'x', 'youtube', 'tiktok', 'instagram', 'telegram', 'linkedin', 'whatsapp', 'website', 'other']

export function catLabel(meta: PdMeta | null, key: string) {
  return meta?.categories.find((c) => c.key === key)?.label ?? key
}
export function statusLabel(meta: PdMeta | null, key: string) {
  return meta?.statuses.find((c) => c.key === key)?.label ?? key
}

export const STATUS_TONE: Record<string, string> = {
  active: 'bg-emerald-50 text-emerald-700',
  arrested: 'bg-red-50 text-red-600',
  abroad: 'bg-blue-50 text-blue-700',
  fugitive: 'bg-amber-50 text-amber-700',
  inactive: 'bg-neutral-100 text-neutral-500',
  deceased: 'bg-neutral-200 text-neutral-600',
}

/** Calls the API to mark links as looked at, and refreshes the sidebar badge. */
export function useMarkSeen() {
  const notes = useOptionalNotifications()
  return (ids: number[]) => {
    if (!ids.length) return
    ProfileDeskApi.seen(ids)
      .then(() => notes?.refresh())
      .catch(() => {})
  }
}

/**
 * One platform icon. A number in a red dot = new posts since you last
 * looked; a small play badge with a number = new videos. Clicking opens the
 * link in a new tab and counts it as seen.
 */
export function LinkChip({
  link,
  size = 'md',
  onOpened,
  showLabel = false,
}: {
  link: PdLink
  size?: 'sm' | 'md' | 'lg'
  onOpened?: (link: PdLink) => void
  showLabel?: boolean
}) {
  const markSeen = useMarkSeen()
  const color = PLATFORM_COLOR[link.platform] ?? PLATFORM_COLOR.other
  const dim = size === 'sm' ? 'w-7 h-7' : size === 'lg' ? 'w-10 h-10' : 'w-8 h-8'
  const icon = size === 'sm' ? 'w-[15px] h-[15px]' : size === 'lg' ? 'w-5 h-5' : 'w-[17px] h-[17px]'
  const watched = link.watch.posts || link.watch.videos
  const manualDue = watched && !link.auto && !link.checked_today
  const fake = link.verified === 2
  function open(e: React.MouseEvent) {
    e.preventDefault()
    e.stopPropagation()
    window.open(link.url, '_blank', 'noopener,noreferrer')
    if (watched) markSeen([link.id])
    onOpened?.(link)
  }
  const title = `${PLATFORM_LABEL[link.platform]}${link.handle ? ' · ' + link.handle : ''}${link.label ? ' · ' + link.label : ''}${
    link.unseen.posts ? ` · ${bn(link.unseen.posts)}টি নতুন পোস্ট` : ''
  }${link.unseen.videos ? ` · ${bn(link.unseen.videos)}টি নতুন ভিডিও` : ''}${manualDue ? ' · আজ দেখা বাকি' : ''}${fake ? ' · ভুয়া/বন্ধ' : ''}`
  return (
    <a
      href={link.url}
      onClick={open}
      title={title}
      className={`relative inline-flex items-center gap-1.5 shrink-0 ${showLabel ? 'pr-2.5 rounded-full' : 'rounded-xl'} ${fake ? 'opacity-40' : ''} hover:brightness-95 active:scale-95 transition`}
      style={{ background: `${color}14` }}
    >
      <span className={`${dim} inline-flex items-center justify-center rounded-xl`} style={{ color }}>
        <PlatformIcon platform={link.platform} className={icon} />
      </span>
      {showLabel && <span className="text-[12px] font-medium text-neutral-700 max-w-[140px] truncate">{link.handle || PLATFORM_LABEL[link.platform]}</span>}
      {link.unseen.posts > 0 && (
        <span className="absolute -top-1.5 -right-1.5 min-w-[17px] h-[17px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold leading-[17px] text-center ring-2 ring-white">
          {link.unseen.posts > 99 ? '৯৯+' : bn(link.unseen.posts)}
        </span>
      )}
      {link.unseen.videos > 0 && (
        <span
          className={`absolute ${link.unseen.posts > 0 ? '-bottom-1.5' : '-top-1.5'} -right-1.5 h-[17px] pl-[3px] pr-1 rounded-full bg-neutral-900 text-white text-[10px] font-bold leading-[17px] inline-flex items-center gap-[1px] ring-2 ring-white`}
        >
          <IconPlayerPlay className="w-[9px] h-[9px]" fill="currentColor" />
          {link.unseen.videos > 99 ? '৯৯+' : bn(link.unseen.videos)}
        </span>
      )}
      {manualDue && !link.unseen.posts && !link.unseen.videos && (
        <span className="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-amber-400 ring-2 ring-white" />
      )}
    </a>
  )
}

export function LinkChips({ links, size, onOpened, max = 8 }: { links: PdLink[]; size?: 'sm' | 'md' | 'lg'; onOpened?: (l: PdLink) => void; max?: number }) {
  const sorted = [...links].sort((a, b) => PLATFORM_ORDER.indexOf(a.platform) - PLATFORM_ORDER.indexOf(b.platform))
  const shown = sorted.slice(0, max)
  return (
    <div className="flex flex-wrap items-center gap-2">
      {shown.map((l) => (
        <LinkChip key={l.id} link={l} size={size} onOpened={onOpened} />
      ))}
      {sorted.length > max && <span className="text-[11.5px] text-neutral-400">+{bn(sorted.length - max)}</span>}
    </div>
  )
}

export function Avatar({ name, photo, kind, size = 48 }: { name: string; photo?: string; kind?: string; size?: number }) {
  const [broken, setBroken] = useState(false)
  const initial = (name || '?').trim().slice(0, 1)
  if (photo && !broken) {
    return (
      <img
        src={photo}
        alt=""
        onError={() => setBroken(true)}
        className={`${kind === 'org' ? 'rounded-xl' : 'rounded-full'} object-cover bg-neutral-100 shrink-0`}
        style={{ width: size, height: size }}
      />
    )
  }
  return (
    <div
      className={`${kind === 'org' ? 'rounded-xl bg-indigo-50 text-indigo-600' : 'rounded-full bg-brand-50 text-brand-700'} flex items-center justify-center font-bold shrink-0`}
      style={{ width: size, height: size, fontSize: size * 0.42 }}
    >
      {initial}
    </div>
  )
}

export function Stars({ n, onChange, size = 14 }: { n: number; onChange?: (n: number) => void; size?: number }) {
  return (
    <span className="inline-flex items-center gap-0.5" title={`গুরুত্ব ${bn(n)}/৫`}>
      {[1, 2, 3, 4, 5].map((i) => (
        <button
          key={i}
          type="button"
          disabled={!onChange}
          onClick={() => onChange?.(i)}
          className={`${i <= n ? 'text-amber-400' : 'text-neutral-200'} ${onChange ? 'hover:scale-110' : 'cursor-default'}`}
        >
          <IconStar style={{ width: size, height: size }} fill="currentColor" />
        </button>
      ))}
    </span>
  )
}

/**
 * "সব খুলুন" per platform for a set of profiles (a group, or a search
 * result): one button per platform, opening every such link in new tabs.
 */
export function OpenAllBar({
  people,
  onOpened,
  compact = false,
}: {
  people: { links: PdLink[] }[]
  onOpened?: (ids: number[]) => void
  compact?: boolean
}) {
  const markSeen = useMarkSeen()
  const [blocked, setBlocked] = useState<{ platform: Platform; urls: string[]; ids: number[]; next: number } | null>(null)
  const byPlatform = new Map<Platform, PdLink[]>()
  for (const p of people) {
    for (const l of p.links) {
      if (l.verified === 2) continue
      const arr = byPlatform.get(l.platform) ?? []
      arr.push(l)
      byPlatform.set(l.platform, arr)
    }
  }
  const platforms = PLATFORM_ORDER.filter((p) => byPlatform.has(p))
  if (!platforms.length) return null

  function openAll(platform: Platform) {
    const links = byPlatform.get(platform) ?? []
    const urls = links.map((l) => l.url)
    const opened = openMany(urls)
    const ids = links.filter((l) => l.watch.posts || l.watch.videos).map((l) => l.id)
    markSeen(ids)
    onOpened?.(links.map((l) => l.id))
    if (opened < urls.length) setBlocked({ platform, urls, ids, next: opened })
    else setBlocked(null)
  }

  return (
    <div>
      <div className={`flex flex-wrap items-center gap-2 ${compact ? '' : 'mt-1'}`}>
        {!compact && <span className="text-[12px] text-neutral-500 mr-0.5">এক ক্লিকে সব খুলুন:</span>}
        {platforms.map((p) => {
          const n = byPlatform.get(p)!.length
          const unseen = byPlatform.get(p)!.reduce((s, l) => s + l.unseen.posts + l.unseen.videos, 0)
          return (
            <button
              key={p}
              type="button"
              onClick={() => openAll(p)}
              className="relative inline-flex items-center gap-1.5 pl-1.5 pr-2.5 py-1 rounded-full border border-neutral-200 bg-white hover:bg-neutral-50 text-[12.5px] font-medium text-neutral-700 active:scale-[0.98]"
              title={`${PLATFORM_LABEL[p]}: ${bn(n)}টি লিংক নতুন ট্যাবে খুলবে`}
            >
              <span className="w-6 h-6 rounded-full inline-flex items-center justify-center" style={{ color: PLATFORM_COLOR[p], background: `${PLATFORM_COLOR[p]}14` }}>
                <PlatformIcon platform={p} className="w-[14px] h-[14px]" />
              </span>
              {PLATFORM_LABEL[p]} ({bn(n)})
              {unseen > 0 && <span className="ml-0.5 min-w-[16px] h-4 px-1 rounded-full bg-red-600 text-white text-[10px] font-bold leading-4">{bn(unseen)}</span>}
            </button>
          )
        })}
      </div>
      {blocked && (
        <div className="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-[12.5px] text-amber-800">
          ব্রাউজার একবারে মাত্র {bn(blocked.next)}টি ট্যাব খুলতে দিয়েছে। ঠিকানার ঘরের ডান পাশে "পপ-আপ আটকানো হয়েছে" চিহ্নে চাপ দিয়ে এই সাইটের জন্য{' '}
          <b>সবসময় অনুমতি দিন</b> বেছে নিন, তারপর আবার চাপুন। এখনই একটা একটা করে খুলতে:
          <div className="mt-2 flex flex-wrap gap-1.5">
            <button
              type="button"
              className="px-2.5 py-1 rounded-lg bg-amber-600 text-white font-semibold"
              onClick={() => {
                const i = blocked.next
                if (i >= blocked.urls.length) return setBlocked(null)
                window.open(blocked.urls[i], '_blank', 'noopener,noreferrer')
                setBlocked(i + 1 >= blocked.urls.length ? null : { ...blocked, next: i + 1 })
              }}
            >
              পরেরটা খুলুন ({bn(blocked.next + 1)}/{bn(blocked.urls.length)})
            </button>
            <button type="button" className="px-2.5 py-1 rounded-lg bg-white border border-amber-200" onClick={() => setBlocked(null)}>
              বন্ধ করুন
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

export function Overlay({ children, onClose, wide = false }: { children: React.ReactNode; onClose: () => void; wide?: boolean }) {
  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 sm:px-4" onClick={onClose}>
      <div
        className={`bg-white w-full ${wide ? 'sm:max-w-3xl' : 'sm:max-w-xl'} max-h-[92vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl shadow-xl`}
        onClick={(e) => e.stopPropagation()}
      >
        {children}
      </div>
    </div>
  )
}
