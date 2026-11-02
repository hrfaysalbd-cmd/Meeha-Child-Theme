import { NavLink, Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../lib/AuthContext'
import { IconEdit, IconHome, IconNews, IconUser, IconWallet } from '../components/Icon'
import { Spinner } from '../components/ui'
import { NotificationsProvider } from '../lib/notifications'
import { NotificationToasts } from '../components/NotificationBell'

const tabs = [
  { to: '/app', label: 'হোম', icon: IconHome, end: true },
  { to: '/app/write', label: 'লিখুন', icon: IconEdit },
  { to: '/app/news', label: 'আমার সংবাদ', icon: IconNews },
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
    <NotificationToasts position="top" />
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
              <Icon className="w-[22px] h-[22px]" />
              {label}
            </NavLink>
          ))}
        </div>
      </nav>
    </div>
    </NotificationsProvider>
  )
}
