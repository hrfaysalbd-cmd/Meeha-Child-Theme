import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { ApiError, AuthApi, setAccessToken, setSessionExpiredHandler, type LoginSuccess, type OtpChallenge, type Profile } from './api'

interface AuthState {
  profile: Profile | null
  loading: boolean
  // Round 9 follow-up 3: the server couldn't be reached while resuming the
  // session — App.tsx shows a retry screen instead of the login page.
  connectionError: boolean
  retryConnection: () => void
  // Resolves with an OtpChallenge when the account needs a one-time code
  // (round 12) — finish with verifyOtp(); otherwise the user is logged in.
  login: (identifier: string, password: string) => Promise<OtpChallenge | null>
  verifyOtp: (otpToken: string, code: string) => Promise<void>
  logout: () => Promise<void>
  refreshProfile: () => Promise<void>
  isPublisher: boolean
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [profile, setProfile] = useState<Profile | null>(null)
  const [loading, setLoading] = useState(true)
  const [connectionError, setConnectionError] = useState(false)

  // If the browser already carries this app's own refresh cookie (from a
  // previous login on publisher.meeha.news), silently resume the session
  // instead of asking for a password again.
  //
  // Round 9 follow-up: this used to read a single .meeha.news-domain cookie
  // shared with correspondent-app, so a correspondent logging in on the same
  // device could silently overwrite it with their own refresh token. The
  // backend (class-nros-auth.php) now gives each app its own cookie *name*
  // (nros_refresh_staff here, nros_refresh_correspondent for the other app),
  // chosen per-request from the Origin header, so the two can no longer
  // collide. The `me.can.manage_staff` check below stays only as
  // defense-in-depth, not the primary guard.
  //
  // Round 9 follow-up 3: only a refresh the server actually *rejected* means
  // "not logged in"; an unreachable server (already retried) keeps the
  // session and shows a connection-error screen with a retry button.
  const bootstrap = useCallback(async () => {
    setLoading(true)
    setConnectionError(false)
    const outcome = await AuthApi.refresh()
    if (outcome === 'ok') {
      try {
        const me = await AuthApi.me()
        if (me.can.manage_staff) {
          setProfile(me)
        } else {
          setAccessToken(null)
        }
      } catch (err) {
        if (err instanceof ApiError && err.kind !== 'client' && err.kind !== 'session') {
          setConnectionError(true)
        } else {
          setAccessToken(null)
        }
      }
    } else if (outcome === 'unavailable') {
      setConnectionError(true)
    }
    setLoading(false)
  }, [])

  useEffect(() => {
    bootstrap()
  }, [bootstrap])

  // Round 8: when the session is eventually found truly dead (the refresh
  // token itself invalid/expired — not a one-off network blip), drop back
  // to a clean logged-out state instead of every page quietly failing
  // forever. App.tsx's RequireAuth wrapper then redirects to /login.
  useEffect(() => {
    setSessionExpiredHandler(() => {
      setAccessToken(null)
      setProfile(null)
    })
    return () => setSessionExpiredHandler(null)
  }, [])

  async function login(identifier: string, password: string): Promise<OtpChallenge | null> {
    const res = await AuthApi.login(identifier, password)
    if ('otp_required' in res && res.otp_required) return res
    await finishLogin(res as LoginSuccess)
    return null
  }

  async function verifyOtp(otpToken: string, code: string) {
    const res = await AuthApi.loginOtp(otpToken, code)
    await finishLogin(res)
  }

  async function finishLogin(res: LoginSuccess) {

    // The backend's /auth/login accepts correspondent accounts too (so one
    // login endpoint serves all three subdomain apps), but a correspondent
    // has no business inside the business dashboard — send them away with a
    // clear message instead of showing an empty/broken UI.
    if (!res.user.can.manage_staff) {
      await AuthApi.logout().catch(() => {})
      setAccessToken(null)
      throw new Error('এই ড্যাশবোর্ড শুধুমাত্র এডিটর ও পাবলিশারদের জন্য। সংবাদদাতারা correspondent.meeha.news ব্যবহার করুন।')
    }

    setAccessToken(res.access_token)
    setProfile(res.user)
  }

  async function logout() {
    try {
      await AuthApi.logout()
    } finally {
      setAccessToken(null)
      setProfile(null)
    }
  }

  async function refreshProfile() {
    const me = await AuthApi.me()
    setProfile(me)
  }

  const isPublisher = !!profile?.can.publisher

  return (
    <AuthContext.Provider
      value={{ profile, loading, connectionError, retryConnection: () => void bootstrap(), login, verifyOtp, logout, refreshProfile, isPublisher }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
