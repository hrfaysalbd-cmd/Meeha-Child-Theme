import { NavLink, Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../lib/AuthContext'
import { IconChat, IconEdit, IconHome, IconNews, IconUser, IconWallet } from '../components/Icon'
import { ChatProvider, ChatToast, useChat } from '../chat/ChatContext'
import { Spinner } from '../components/ui'
import { NotificationsProvider } from '../lib/notifications'
import { NotificationToasts } from '../components/NotificationBell'

const tabs = [
  { to: '/app', label: 'হোম', icon: IconHome, end: true },
  { to: '/app/write', label: 'লিখুন', icon: IconEdit },
  { to: '/app/news', label: 'আমার সংবাদ', icon: IconNews },
  { to: '/app/chat', label: 'চ্যাট', icon: IconChat },
  { to: '/app/earn', label: 'আয়', icon: IconWallet },
  { to: '/app/profile', label: 'প্রোফাইল', icon: IconUser },
]

export default function AppShell() {
  const { profile, loading } = useAuth()
  const { pathname } = useLocation()
  const navigate = useNavigate()
  // The video editor is part of the "লিখুন" tab (its segmented control).
  const inVideoEditor = pathname.startsWith('/app/video')

  if (loading) {
    return (
      <div className="flex-1 flex items-center justify-center">
        <Spinner />
      </div>
    )
  }

  if (!profile) {
    return <Navigate to="/landing" replace />
  }

  // Round 18: live notifications (approved / returned posts) for the whole
  // logged-in app — the bell sits on হোম and আমার সংবাদ, toasts show anywhere.
  return (
    <NotificationsProvider enabled onOpen={(n) => n.link && navigate(n.link)}>
    <ChatProvider>
    <NotificationToasts position="top" />
    {!pathname.startsWith('/app/chat') && <ChatToast onOpen={(id) => navigate(`/app/chat?room=${id}`)} />}
    <div className="flex-1 flex flex-col min-h-0">
      <div className="flex-1 overflow-y-auto pb-[calc(64px+env(safe-area-inset-bottom))]">
        <Outlet />
      </div>

      <nav className="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-[480px] bg-white border-t border-black/5 pb-[env(safe-area-inset-bottom)] z-20">
        <div className="flex">
          {tabs.map(({ to, label, icon: Icon, end }) => (
            <NavLink
              key={to}
              to={to}
              end={end}
              className={({ isActive }) =>
                `flex-1 flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-medium ${
                  isActive || (inVideoEditor && to === '/app/write') ? 'text-brand-600' : 'text-neutral-400'
                }`
              }
            >
              <span className="relative">
                <Icon className="w-[22px] h-[22px]" />
                {to === '/app/chat' && <ChatBadge />}
              </span>
              {label}
            </NavLink>
          ))}
        </div>
      </nav>
    </div>
    </ChatProvider>
    </NotificationsProvider>
  )
}

/** r36 — unread chat messages on the চ্যাট tab (red when something is জরুরি). */
function ChatBadge() {
  const { unread, summary } = useChat()
  const n = unread || summary?.urgent || 0
  if (!n) return null
  return (
    <span className={`absolute -top-1.5 -right-2.5 min-w-[17px] h-[17px] px-1 rounded-full ${summary?.urgent ? 'bg-red-600' : 'bg-brand-600'} text-white text-[10px] font-bold leading-[17px] text-center ring-2 ring-white`}>
      {n > 99 ? '৯৯+' : String(n).replace(/\d/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])}
    </span>
  )
}
