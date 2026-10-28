import { useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../lib/AuthContext'
import {
  IconBan,
  IconBuildingStore,
  IconDashboard,
  IconDotsVertical,
  IconFileCheck,
  IconInbox,
  IconListDetails,
  IconLogout,
  IconPencil,
  IconSettings,
  IconShieldSearch,
  IconSparkles,
  IconUserShield,
  IconUsers,
  IconVideo,
  IconQuote,
  IconWalletCoins,
  IconX,
} from '../components/Icon'
import { NotificationsProvider, useNotifications } from '../lib/notifications'
import NotificationBell, { NotificationToasts } from '../components/NotificationBell'
import type { NotificationCounts } from '../lib/api'

interface NavItem {
  to: string
  label: string
  icon: typeof IconDashboard
  publisherOnly?: boolean
  /** Round 18: live "waiting" count shown as a badge (from GET /notifications). */
  count?: keyof NotificationCounts
}

const NAV: NavItem[] = [
  { to: '/app', label: 'ওভারভিউ', icon: IconDashboard },
  { to: '/app/write', label: 'লিখুন', icon: IconPencil },
  { to: '/app/queue', label: 'এডিটোরিয়াল কিউ', icon: IconInbox, count: 'queue' },
  { to: '/app/posts', label: 'নিউজ লিস্ট', icon: IconListDetails },
  { to: '/app/videos', label: 'ভিডিও নিউজ', icon: IconVideo, count: 'videos' },
  { to: '/app/quotes', label: 'উক্তি', icon: IconQuote },
  { to: '/app/correspondents', label: 'সংবাদদাতা', icon: IconUsers },
  { to: '/app/applications', label: 'আবেদন', icon: IconFileCheck, count: 'applications' },
  { to: '/app/ad-clients', label: 'বিজ্ঞাপনদাতা', icon: IconBuildingStore },
  { to: '/app/finance', label: 'ফাইন্যান্স', icon: IconWalletCoins, count: 'withdrawals' },
  { to: '/app/investigations', label: 'তদন্ত', icon: IconShieldSearch },
  { to: '/app/suspensions', label: 'সাসপেনশন', icon: IconBan },
  { to: '/app/prompts', label: 'প্রোম্পট', icon: IconSparkles },
  { to: '/app/users', label: 'ইউজার ও রোল', icon: IconUserShield, publisherOnly: true },
  { to: '/app/settings', label: 'সেটিংস', icon: IconSettings },
]

// Round 8 — "ফিল লাইক এ্যপ": on mobile, the four most-used screens (the
// daily editorial loop: check the dashboard, clear the review queue, see
// the news list, write) sit in a fixed bottom tab bar like a native app,
// instead of every screen being one tap behind a hamburger drawer. The
// other eight screens (correspondents, applications, ad-clients, finance,
// investigations, suspensions, users, settings) are reached through the
// 5th "আরও" tab, which opens the same slide-over panel the desktop
// sidebar is built from — nothing there was removed, just reorganized by
// how often each screen gets opened.
const MOBILE_PRIMARY: NavItem[] = [
  { to: '/app', label: 'ওভারভিউ', icon: IconDashboard },
  { to: '/app/queue', label: 'কিউ', icon: IconInbox, count: 'queue' },
  { to: '/app/posts', label: 'নিউজ', icon: IconListDetails },
  { to: '/app/write', label: 'লিখুন', icon: IconPencil },
]

const bn = (n: number) => String(n).replace(/[0-9]/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])

function CountBadge({ n, className = '' }: { n?: number; className?: string }) {
  if (!n) return null
  return (
    <span className={`min-w-[18px] h-[18px] px-1 rounded-full bg-brand-600 text-white text-[10.5px] font-bold leading-[18px] text-center ${className}`}>
      {n > 99 ? '৯৯+' : bn(n)}
    </span>
  )
}

// Round 18: the provider lives above the shell so the sidebar badges, the
// bell and every page can read the same live notifications.
export default function DashboardShell() {
  const navigate = useNavigate()
  return (
    <NotificationsProvider enabled onOpen={(n) => n.link && navigate(n.link)}>
      <Shell />
    </NotificationsProvider>
  )
}

function Shell() {
  const { profile, logout, isPublisher } = useAuth()
  const { counts } = useNotifications()
  const navigate = useNavigate()
  const location = useLocation()
  const [mobileOpen, setMobileOpen] = useState(false)

  const items = NAV.filter((n) => !n.publisherOnly || isPublisher)
  // The "আরও" tab lights up when the current screen is one of the ones
  // tucked inside it, so the tab bar always shows where you are.
  // (Prefix match, so e.g. /app/write/12 counts as the "লিখুন" tab.)
  const overflowCount = items
    .filter((i) => i.count && !MOBILE_PRIMARY.some((m) => m.to === i.to))
    .reduce((sum, i) => sum + (counts?.[i.count!] ?? 0), 0)
  const isOverflowActive = !MOBILE_PRIMARY.some(({ to }) =>
    to === '/app' ? location.pathname === '/app' || location.pathname === '/app/' : location.pathname.startsWith(to),
  )

  async function onLogout() {
    await logout()
    navigate('/login')
  }

  const sidebar = (
    <nav className="flex flex-col h-full">
      <div className="px-5 py-5 flex items-center gap-2 border-b border-neutral-100">
        <div className="w-8 h-8 rounded-lg bg-brand-600 text-white flex items-center justify-center font-bold text-[14px]">মি</div>
        <div>
          <p className="text-[14px] font-bold text-neutral-900 leading-none">মিহা নিউজ</p>
          <p className="text-[11px] text-neutral-400 leading-none mt-0.5">পাবলিশার ড্যাশবোর্ড</p>
        </div>
      </div>

      <div className="flex-1 overflow-y-auto py-3 px-3">
        {items.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/app'}
            onClick={() => setMobileOpen(false)}
            className={({ isActive }) =>
              `flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-[13.5px] font-medium mb-0.5 transition ${
                isActive ? 'bg-brand-50 text-brand-700' : 'text-neutral-600 hover:bg-neutral-100'
              }`
            }
          >
            <item.icon className="w-[18px] h-[18px]" />
            <span className="flex-1">{item.label}</span>
            {item.count && <CountBadge n={counts?.[item.count]} />}
          </NavLink>
        ))}
      </div>

      <div className="px-3 py-3 border-t border-neutral-100">
        <div className="flex items-center gap-2.5 px-2 py-2 mb-1.5">
          <img src={profile?.avatar} alt="" className="w-9 h-9 rounded-full bg-neutral-100 object-cover" />
          <div className="min-w-0">
            <p className="text-[13px] font-semibold text-neutral-900 truncate">{profile?.name}</p>
            <p className="text-[11.5px] text-neutral-400">{isPublisher ? 'পাবলিশার' : 'এডিটর'}</p>
          </div>
        </div>
        <button
          onClick={onLogout}
          className="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-[13px] font-medium text-neutral-500 hover:bg-neutral-100"
        >
          <IconLogout className="w-[17px] h-[17px]" />
          লগআউট
        </button>
      </div>
    </nav>
  )

  return (
    <div className="min-h-screen flex bg-neutral-50">
      <div className="lg:hidden">
        <NotificationToasts position="top" />
      </div>
      <div className="hidden lg:block">
        <NotificationToasts position="bottom-right" />
      </div>
      {/* Desktop sidebar */}
      <aside className="hidden lg:flex lg:flex-col w-64 shrink-0 bg-white border-r border-neutral-200/80">{sidebar}</aside>

      {/* Mobile "আরও" overflow panel — same nav list as the desktop sidebar,
          opened from the bottom tab bar's 5th tab instead of a header
          hamburger. */}
      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-black/40" onClick={() => setMobileOpen(false)} />
          <div className="absolute inset-y-0 left-0 w-64 bg-white shadow-xl">{sidebar}</div>
        </div>
      )}

      <div className="flex-1 min-w-0 flex flex-col">
        {/* Topbar — on mobile this is now just a compact brand header; all
            navigation lives in the bottom tab bar below. */}
        <header className="lg:hidden sticky top-0 z-30 bg-white border-b border-neutral-200/80 px-4 py-3 flex items-center gap-2.5">
          <div className="w-7 h-7 rounded-lg bg-brand-600 text-white flex items-center justify-center font-bold text-[12.5px]">মি</div>
          <p className="flex-1 text-[15px] font-bold text-neutral-900">মিহা নিউজ ড্যাশবোর্ড</p>
          <NotificationBell placement="sheet" />
        </header>
        <header className="hidden lg:flex relative z-30 justify-end max-w-[1400px] w-full mx-auto px-8 pt-4">
          <div className="rounded-full bg-white shadow-sm ring-1 ring-neutral-200/80">
            <NotificationBell placement="dropdown" />
          </div>
        </header>

        <main className="flex-1 p-4 sm:p-6 lg:p-8 lg:pt-2 pb-[calc(76px+env(safe-area-inset-bottom))] lg:pb-8 max-w-[1400px] w-full mx-auto">
          <Outlet />
        </main>
      </div>

      {/* Mobile bottom tab bar */}
      <nav className="lg:hidden fixed bottom-0 left-0 right-0 z-30 bg-white border-t border-neutral-200/80 pb-[env(safe-area-inset-bottom)]">
        <div className="flex">
          {MOBILE_PRIMARY.map(({ to, label, icon: Icon, count }) => (
            <NavLink
              key={to}
              to={to}
              end={to === '/app'}
              onClick={() => setMobileOpen(false)}
              className={({ isActive }) =>
                `flex-1 flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-medium ${
                  isActive ? 'text-brand-600' : 'text-neutral-400'
                }`
              }
            >
              <span className="relative">
                <Icon className="w-[21px] h-[21px]" />
                {count && <CountBadge n={counts?.[count]} className="absolute -top-1.5 left-3.5" />}
              </span>
              {label}
            </NavLink>
          ))}
          <button
            onClick={() => setMobileOpen((v) => !v)}
            className={`flex-1 flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-medium ${
              mobileOpen || isOverflowActive ? 'text-brand-600' : 'text-neutral-400'
            }`}
          >
            <span className="relative">
              {mobileOpen ? <IconX className="w-[21px] h-[21px]" /> : <IconDotsVertical className="w-[21px] h-[21px]" />}
              {!mobileOpen && overflowCount > 0 && <span className="absolute -top-0.5 -right-1 w-2 h-2 rounded-full bg-brand-600" />}
            </span>
            আরও
          </button>
        </div>
      </nav>
    </div>
  )
}
