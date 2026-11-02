import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { ApiError, AuthApi, setAccessToken, setSessionExpiredHandler, type LoginSuccess, type OtpChallenge, type Profile } from './api'

interface AuthState {
  profile: Profile | null
  loading: boolean // true while the initial silent-refresh/me check runs
  // Round 9 follow-up 3: the server couldn't be reached while resuming the
  // session (weak mobile signal, server down). The session may be perfectly
  // fine — App.tsx shows a "connection problem, try again" screen instead
  // of the landing page, which used to look exactly like being logged out.
  connectionError: boolean
  retryConnection: () => void
  // Resolves null when logged in, or the OTP challenge when the account
  // needs a one-time code first (round 12) — then call verifyOtp().
  login: (identifier: string, password: string) => Promise<OtpChallenge | null>
  verifyOtp: (otpToken: string, code: string) => Promise<void>
  resendOtp: (otpToken: string) => Promise<{ resendAfter: number; otpToken: string; destination?: string }>
  logout: () => Promise<void>
  refreshProfile: () => Promise<void>
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [profile, setProfile] = useState<Profile | null>(null)
  const [loading, setLoading] = useState(true)
  const [connectionError, setConnectionError] = useState(false)

  // On first load, try the refresh cookie silently so a correspondent who
  // already logged in doesn't have to log in again every time the app opens.
  //
  // Round 9 bug + fix history, for whoever reads this next: the refresh
  // cookie used to be a single .meeha.news-domain cookie *name* shared by
  // every subdomain app (by design, for eventual SSO). That meant the
  // browser only ever had ONE storage slot for it — logging into
  // publisher-app in the same browser silently overwrote that one slot with
  // the staff account's refresh token, so the next reload here would
  // silently resume as the *wrong* account. A first fix (this `me.roles`
  // check) stopped the correspondent shell from ever rendering for a
  // non-correspondent identity — correct, but it only detects the collision
  // after it already happened, so a phone switching between both apps would
  // still see a dropped session every few reloads.
  //
  // The real fix is on the backend (class-nros-auth.php): each app now gets
  // its OWN refresh-cookie *name* (nros_refresh_correspondent vs.
  // nros_refresh_staff), chosen per-request from the Origin header. Distinct
  // names are distinct browser storage slots, so correspondent-app and
  // publisher-app sessions on the same device can no longer collide at all
  // — there's nothing left to steal. The `me.roles` check below now stays
  // only as a defense-in-depth sanity check (e.g. if a correspondent account
  // is ever demoted server-side mid-session), not the primary guard.
  //
  // Round 9 follow-up 3: only a refresh the server actually *rejected* means
  // "not logged in". If the server simply couldn't be reached (the refresh
  // already retried itself a few times), the session is left untouched and
  // a connection-error screen with a retry button is shown instead.
  const bootstrap = useCallback(async () => {
    setLoading(true)
    setConnectionError(false)
    const outcome = await AuthApi.refresh()
    if (outcome === 'ok') {
      try {
        const me = await AuthApi.me()
        if (me.roles.includes('correspondent')) {
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

  // Round 8: when the api client's own proactive-refresh timer or a 401
  // retry eventually finds the session truly dead (not a one-off network
  // blip — the refresh call itself was rejected), drop back to a clean
  // logged-out state so AppShell's own guard redirects to the login/landing
  // screen, instead of every page quietly failing forever.
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

  async function resendOtp(otpToken: string) {
    const r = await AuthApi.resendOtp(otpToken)
    return {
      resendAfter: typeof r.resend_after === 'number' ? r.resend_after : 60,
      // the plugin may hand out a fresh token with the new code
      otpToken: typeof r.otp_token === 'string' && r.otp_token ? r.otp_token : otpToken,
      destination: r.destination,
    }
  }

  async function finishLogin(res: LoginSuccess) {
    // The backend's /auth/login accepts staff accounts too (one login
    // endpoint serves all three subdomain apps) — a reciprocal check to
    // publisher-app's own guard. Logging out here clears this app's own
    // refresh cookie again so a staff member who mistakenly logs in here
    // doesn't leave this app's cookie slot pointed at their account; since
    // each app now has its own cookie name, this can no longer affect any
    // other app's session either way.
    if (!res.user.roles.includes('correspondent')) {
      await AuthApi.logout().catch(() => {})
      setAccessToken(null)
      throw new Error('এই অ্যাপ শুধুমাত্র সংবাদদাতাদের জন্য। এডিটর/পাবলিশাররা publisher.meeha.news ব্যবহার করুন।')
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

  return (
    <AuthContext.Provider
      value={{
        profile,
        loading,
        connectionError,
        retryConnection: () => void bootstrap(),
        login,
        verifyOtp,
        resendOtp,
        logout,
        refreshProfile,
      }}
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
