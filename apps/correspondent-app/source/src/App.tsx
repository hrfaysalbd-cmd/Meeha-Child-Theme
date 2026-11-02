import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './lib/AuthContext'
import { Spinner } from './components/ui'
import { IconAlertTriangle, IconRefresh } from './components/Icon'
import Landing from './pages/Landing'
import Login from './pages/Login'
import Apply from './pages/Apply'
import AppShell from './layouts/AppShell'
import Home from './pages/Home'
import Write from './pages/Write'
import News from './pages/News'
import VideoEditor from './pages/VideoEditor'
import Earn from './pages/Earn'
import Profile from './pages/Profile'

function Root() {
  const { profile, loading } = useAuth()
  if (loading) {
    return (
      <div className="flex-1 flex items-center justify-center">
        <Spinner />
      </div>
    )
  }
  return <Navigate to={profile ? '/app' : '/landing'} replace />
}

// Round 9 follow-up 3: shown when the server couldn't be reached while
// resuming the session on app open. Previously that case fell through to the
// landing/login page, which looked exactly like being logged out even though
// the session itself was fine.
function ConnectionErrorScreen({ onRetry }: { onRetry: () => void }) {
  const offline = typeof navigator !== 'undefined' && navigator.onLine === false
  return (
    <div className="min-h-screen flex-1 flex flex-col items-center justify-center gap-3 px-6 text-center">
      <div className="w-12 h-12 rounded-full bg-red-50 text-red-500 flex items-center justify-center">
        <IconAlertTriangle className="w-6 h-6" />
      </div>
      <p className="text-[15px] font-semibold text-neutral-800">সার্ভারের সাথে সংযোগ করা যাচ্ছে না</p>
      <p className="text-[13px] leading-relaxed text-neutral-500 max-w-xs">
        {offline ? 'আপনার ডিভাইসে এখন ইন্টারনেট সংযোগ নেই।' : 'নেটওয়ার্ক দুর্বল, অথবা সার্ভার এই মুহূর্তে সাড়া দিচ্ছে না।'} আপনি
        লগ-আউট হননি — সংযোগ ফিরলে আবার চেষ্টা করুন।
      </p>
      <button
        onClick={onRetry}
        className="mt-1 flex items-center gap-1.5 px-4 py-2 rounded-xl bg-neutral-100 text-neutral-700 text-[13px] font-medium active:scale-[0.98]"
      >
        <IconRefresh className="w-4 h-4" />
        আবার চেষ্টা করুন
      </button>
    </div>
  )
}

export default function App() {
  const { connectionError, retryConnection } = useAuth()
  if (connectionError) return <ConnectionErrorScreen onRetry={retryConnection} />

  return (
    <Routes>
      <Route path="/" element={<Root />} />
      <Route path="/landing" element={<Landing />} />
      <Route path="/login" element={<Login />} />
      <Route path="/apply" element={<Apply />} />

      <Route path="/app" element={<AppShell />}>
        <Route index element={<Home />} />
        <Route path="write" element={<Write />} />
        <Route path="write/:id" element={<Write />} />
        <Route path="video/new" element={<VideoEditor />} />
        <Route path="video/:id" element={<VideoEditor />} />
        <Route path="news" element={<News />} />
        <Route path="earn" element={<Earn />} />
        <Route path="profile" element={<Profile />} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
