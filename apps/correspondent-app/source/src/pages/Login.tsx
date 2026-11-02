import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../lib/AuthContext'
import { ApiError, type OtpChallenge } from '../lib/api'
import { Banner, Button, Field, inputClass, TopBar, bnNumber } from '../components/ui'

const OTP_LEN = 6

/** Bengali (and Arabic-Indic) digits → ASCII, everything else dropped. */
function digitsOnly(raw: string) {
  return raw
    .replace(/[০-৯]/g, (d) => String('০১২৩৪৫৬৭৮৯'.indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
    .replace(/\D/g, '')
    .slice(0, OTP_LEN)
}

function mmss(sec: number) {
  const m = Math.floor(sec / 60)
  const s = sec % 60
  return `${bnNumber(m)}:${bnNumber(String(s).padStart(2, '0'))}`
}

const OTP_FALLBACK: Record<string, string> = {
  nros_otp_invalid: 'কোডটি সঠিক নয়। আবার চেষ্টা করুন।',
  nros_otp_expired: 'কোডের মেয়াদ শেষ হয়ে গেছে — আবার লগইন করুন।',
  nros_otp_locked: 'অনেকবার ভুল কোড দেওয়া হয়েছে — কিছুক্ষণ পরে আবার লগইন করুন।',
}

function otpErrorText(err: unknown) {
  if (err instanceof ApiError) {
    let msg = err.message || (err.code ? OTP_FALLBACK[err.code] : '') || 'যাচাই করা যায়নি, আবার চেষ্টা করুন।'
    const left = err.data?.attempts_left
    if (err.code === 'nros_otp_invalid' && typeof left === 'number' && !/[০-৯0-9]/.test(msg)) {
      msg += ` আর ${bnNumber(left)} বার চেষ্টা করতে পারবেন।`
    }
    return msg
  }
  return err instanceof Error ? err.message : 'যাচাই করা যায়নি, আবার চেষ্টা করুন।'
}

/** The plugin flags errors after which this code can't be used any more (expired, locked, rate-limited). */
function mustRestart(err: unknown) {
  return err instanceof ApiError && (err.data?.restart === true || err.code === 'nros_otp_locked' || err.code === 'nros_otp_expired')
}

// Minimal typing for the WebOTP API (Android Chrome reads an SMS code).
type OtpCredential = Credential & { code?: string }

export default function Login() {
  const navigate = useNavigate()
  const { login, verifyOtp, resendOtp } = useAuth()
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [loading, setLoading] = useState(false)

  // ---- OTP step (round 12) ----
  const [challenge, setChallenge] = useState<OtpChallenge | null>(null)
  const [code, setCode] = useState('')
  const [resendIn, setResendIn] = useState(0)
  const [locked, setLocked] = useState(false)
  const otpRef = useRef<HTMLInputElement>(null)
  const submittingRef = useRef(false)

  useEffect(() => {
    if (resendIn <= 0) return
    const t = setTimeout(() => setResendIn((n) => n - 1), 1000)
    return () => clearTimeout(t)
  }, [resendIn])

  // Android Chrome: read the code straight from the SMS when one is used.
  useEffect(() => {
    if (!challenge || challenge.method !== 'sms' || !('OTPCredential' in window)) return
    const ac = new AbortController()
    ;(navigator.credentials.get({ otp: { transport: ['sms'] }, signal: ac.signal } as CredentialRequestOptions) as Promise<OtpCredential | null>)
      .then((cred) => {
        const c = digitsOnly(cred?.code ?? '')
        if (c.length === OTP_LEN) {
          setCode(c)
          void submitOtp(c)
        }
      })
      .catch(() => {})
    return () => ac.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [challenge])

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setNotice('')
    setLoading(true)
    try {
      const otp = await login(identifier, password)
      if (otp) {
        setChallenge(otp)
        setCode('')
        setLocked(false)
        setResendIn(Math.max(0, Math.round(otp.resend_after || 0)))
        setTimeout(() => otpRef.current?.focus(), 50)
      } else {
        navigate('/app', { replace: true })
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'লগইন করা যায়নি, আবার চেষ্টা করুন।')
    } finally {
      setLoading(false)
    }
  }

  async function submitOtp(value = code) {
    if (!challenge || submittingRef.current) return
    setError('')
    setNotice('')
    if (value.length !== OTP_LEN) {
      setError(`${bnNumber(OTP_LEN)} সংখ্যার কোডটি লিখুন।`)
      return
    }
    submittingRef.current = true
    setLoading(true)
    try {
      await verifyOtp(challenge.otp_token, value)
      navigate('/app', { replace: true })
    } catch (err) {
      if (mustRestart(err)) {
        restartWith(otpErrorText(err))
        return
      }
      setError(otpErrorText(err))
      setCode('')
      otpRef.current?.focus()
    } finally {
      submittingRef.current = false
      setLoading(false)
    }
  }

  async function onResend() {
    if (!challenge || resendIn > 0) return
    setError('')
    setNotice('')
    try {
      const r = await resendOtp(challenge.otp_token)
      setChallenge({ ...challenge, otp_token: r.otpToken, destination: r.destination ?? challenge.destination })
      setResendIn(Math.max(0, Math.round(r.resendAfter)))
      setCode('')
      setNotice('নতুন কোড পাঠানো হয়েছে।')
      otpRef.current?.focus()
    } catch (err) {
      if (mustRestart(err)) {
        restartWith(otpErrorText(err))
        return
      }
      const wait = err instanceof ApiError ? Number(err.data?.resend_after) : NaN
      if (Number.isFinite(wait) && wait > 0) setResendIn(Math.round(wait))
      setError(otpErrorText(err))
    }
  }

  // Back to the password step, keeping the identifier, with the reason shown there.
  function restartWith(message: string) {
    setChallenge(null)
    setCode('')
    setNotice('')
    setLocked(false)
    setResendIn(0)
    setPassword('')
    setError(message)
  }

  function backToCredentials() {
    setChallenge(null)
    setCode('')
    setError('')
    setNotice('')
    setLocked(false)
    setResendIn(0)
  }

  if (challenge) {
    const where = challenge.method === 'sms' ? 'ফোনে এসএমএস' : 'ইমেইলে'
    return (
      <div className="flex-1 flex flex-col">
        <TopBar title="কোড যাচাই" onBack={backToCredentials} />
        <form
          onSubmit={(e) => {
            e.preventDefault()
            void submitOtp()
          }}
          className="flex-1 flex flex-col px-5 pt-6"
          data-testid="otp-step"
        >
          {error && <Banner kind="error">{error}</Banner>}
          {notice && !error && <Banner kind="success">{notice}</Banner>}

          <p className="text-[14px] text-neutral-700 leading-relaxed mb-5">
            নিরাপত্তার জন্য আপনার {where}
            {challenge.destination ? (
              <>
                {' '}(<span dir="ltr" className="font-semibold">{challenge.destination}</span>)
              </>
            ) : null}{' '}
            একটি {bnNumber(OTP_LEN)} সংখ্যার কোড পাঠানো হয়েছে। কোডটি নিচে লিখুন।
          </p>

          <label className="block mb-4">
            <span className="block text-[13px] font-medium text-neutral-600 mb-1.5">যাচাই কোড</span>
            <input
              ref={otpRef}
              className={`${inputClass} text-center text-[26px] font-semibold tracking-[0.45em] tabular-nums py-3`}
              value={code}
              onChange={(e) => {
                const v = digitsOnly(e.target.value)
                setCode(v)
                setError('')
                if (v.length === OTP_LEN) void submitOtp(v)
              }}
              onPaste={(e) => {
                const v = digitsOnly(e.clipboardData.getData('text'))
                if (v) {
                  e.preventDefault()
                  setCode(v)
                  if (v.length === OTP_LEN) void submitOtp(v)
                }
              }}
              inputMode="numeric"
              pattern="[0-9০-৯]*"
              autoComplete="one-time-code"
              enterKeyHint="go"
              maxLength={OTP_LEN * 2}
              placeholder="••••••"
              aria-label={`${bnNumber(OTP_LEN)} সংখ্যার যাচাই কোড`}
              disabled={locked}
              required
            />
          </label>

          <Button type="submit" disabled={loading || locked || code.length !== OTP_LEN}>
            {loading ? 'যাচাই হচ্ছে…' : 'যাচাই করে লগইন করুন'}
          </Button>

          <div className="flex items-center justify-between mt-5 text-[13.5px]">
            <button type="button" onClick={backToCredentials} className="min-h-[44px] text-neutral-600 font-medium">
              ← ফিরে যান
            </button>
            <button
              type="button"
              onClick={onResend}
              disabled={resendIn > 0 || locked}
              className="min-h-[44px] font-semibold text-brand-600 disabled:text-neutral-400"
              data-testid="otp-resend"
            >
              {resendIn > 0 ? `আবার কোড পাঠান (${mmss(resendIn)})` : 'আবার কোড পাঠান'}
            </button>
          </div>
          <p className="text-[12px] text-neutral-400 mt-3">
            কোড না পেলে স্প্যাম/প্রমোশন ফোল্ডার দেখুন। কোডটি কিছুক্ষণ পরেই মেয়াদোত্তীর্ণ হয়ে যায়।
          </p>
        </form>
      </div>
    )
  }

  return (
    <div className="flex-1 flex flex-col">
      <TopBar title="সংবাদদাতা লগইন" onBack={() => navigate('/landing')} />
      <form onSubmit={onSubmit} className="flex-1 flex flex-col px-5 pt-6">
        {error && <Banner kind="error">{error}</Banner>}

        <Field label="ইমেইল, ইউজারনেম অথবা ফোন নম্বর" required>
          <input
            className={inputClass}
            value={identifier}
            onChange={(e) => setIdentifier(e.target.value)}
            placeholder="you@example.com"
            autoComplete="username"
            required
          />
        </Field>

        <Field label="পাসওয়ার্ড" required>
          <input
            className={inputClass}
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            required
          />
        </Field>

        <div className="mt-2">
          <Button type="submit" disabled={loading}>
            {loading ? 'লগইন হচ্ছে…' : 'লগইন করুন'}
          </Button>
        </div>

        <p className="text-center text-[13px] text-neutral-500 mt-6">
          এখনো সংবাদদাতা হননি?{' '}
          <button type="button" onClick={() => navigate('/apply')} className="text-brand-600 font-semibold">
            আবেদন করুন
          </button>
        </p>
      </form>
    </div>
  )
}
