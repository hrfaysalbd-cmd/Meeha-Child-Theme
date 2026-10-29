import { Suspense, lazy } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './lib/AuthContext'
import { Spinner } from './components/ui'
import { IconAlertTriangle, IconRefresh } from './components/Icon'
import Login from './pages/Login'
import DashboardShell from './layouts/DashboardShell'
import Overview from './pages/Overview'
import PostEditor from './pages/PostEditor'
import Queue from './pages/Queue'
import Posts from './pages/Posts'
import Correspondents from './pages/Correspondents'
import Applications from './pages/Applications'
import AdClients from './pages/AdClients'
import Finance from './pages/Finance'
import Investigations from './pages/Investigations'
import Suspensions from './pages/Suspensions'
import Users from './pages/Users'
import Settings from './pages/Settings'
import Prompts from './pages/Prompts'
import PromptLibrary from './pages/PromptLibrary'
import Videos from './pages/Videos'
import VideoEditor from './pages/VideoEditor'
import Quotes from './pages/Quotes'

// Round 21 — the video editor pulls in the encoder (mediabunny): its own chunk.
const VideoStudio = lazy(() => import('./pages/VideoStudio'))
// Round 24 — ফটোকার্ড স্টুডিও (its renderer loads only when it opens).
const PhotocardStudio = lazy(() => import('./pages/PhotocardStudio'))

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { profile, loading } = useAuth()
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <Spinner />
      </div>
    )
  }
  if (!profile) return <Navigate to="/login" replace />
  return <>{children}</>
}

function RequirePublisher({ children }: { children: React.ReactNode }) {
  const { isPublisher } = useAuth()
  if (!isPublisher) return <Navigate to="/app" replace />
  return <>{children}</>
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
      <Route path="/login" element={<Login />} />

      <Route
        path="/app"
        element={
          <RequireAuth>
            <DashboardShell />
          </RequireAuth>
        }
      >
        <Route index element={<Overview />} />
        <Route path="write" element={<PostEditor />} />
        <Route path="write/:id" element={<PostEditor />} />
        <Route path="queue" element={<Queue />} />
        <Route path="posts" element={<Posts />} />
        <Route path="videos" element={<Videos />} />
        <Route path="videos/new" element={<VideoEditor />} />
        <Route path="videos/:id" element={<VideoEditor />} />
        <Route
          path="videos/:id/edit-video"
          element={
            <Suspense fallback={<Spinner className="mt-10" />}>
              <VideoStudio />
            </Suspense>
          }
        />
        <Route path="quotes" element={<Quotes />} />
        <Route
          path="photocard"
          element={
            <Suspense fallback={<Spinner className="mt-10" />}>
              <PhotocardStudio />
            </Suspense>
          }
        />
        <Route path="correspondents" element={<Correspondents />} />
        <Route path="applications" element={<Applications />} />
        <Route path="ad-clients" element={<AdClients />} />
        <Route path="finance" element={<Finance />} />
        <Route path="investigations" element={<Investigations />} />
        <Route path="suspensions" element={<Suspensions />} />
        <Route
          path="users"
          element={
            <RequirePublisher>
              <Users />
            </RequirePublisher>
          }
        />
        <Route path="settings" element={<Settings />} />
        <Route path="prompts" element={<Prompts />} />
        <Route path="prompt-library" element={<PromptLibrary />} />
      </Route>

      <Route path="/" element={<Navigate to="/app" replace />} />
      <Route path="*" element={<Navigate to="/app" replace />} />
    </Routes>
  )
}
