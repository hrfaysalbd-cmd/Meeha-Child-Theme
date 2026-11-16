import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../lib/AuthContext'
import { ApiError, AuthApi, OTP_RESTART_CODES, type OtpChallenge } from '../lib/api'
import { Banner, Button, Field, bnNumber, inputClass } from '../components/ui'
import { IconArrowLeft } from '../components/Icon'

const OTP_LEN = 6

/** Bengali (and other) digits → ASCII, everything else dropped. */
function normalizeCode(raw: string) {
  return raw
    .replace(/[০-৯]/g, (d) => String('০১২৩৪৫৬৭৮৯'.indexOf(d)))
    .replace(/\D/g, '')
    .slice(0, OTP_LEN)
}

function errorText(err: unknown, fallback: string) {
  return err instanceof Error && err.message ? err.message : fallback
}

export default function Login() {
  const { login, verifyOtp } = useAuth()
  const navigate = useNavigate()
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  // Round 12 — second step when the theme requires a one-time code.
  const [otp, setOtp] = useState<OtpChallenge | null>(null)
  const [code, setCode] = useState('')
  const [info, setInfo] = useState('')
  const [resendIn, setResendIn] = useState(0)
  const [resending, setResending] = useState(false)
  const codeRef = useRef<HTMLInputElement>(null)
  const submittedCode = useRef('')

  useEffect(() => {
    if (resendIn <= 0) return
    const t = setTimeout(() => setResendIn((s) => s - 1), 1000)
    return () => clearTimeout(t)
  }, [resendIn])

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      const challenge = await login(identifier, password)
      if (challenge) {
        setOtp(challenge)
        setCode('')
        submittedCode.current = ''
        setInfo(challenge.message || `${challenge.method === 'sms' ? 'আপনার মোবাইলে' : 'আপনার ইমেইলে'} (${challenge.destination}) ৬ সংখ্যার কোড পাঠানো হয়েছে।`)
        setResendIn(challenge.resend_after || 60)
        setTimeout(() => codeRef.current?.focus(), 50)
      } else {
        navigate('/app')
      }
    } catch (err) {
      setError(errorText(err, 'লগইন করা যায়নি।'))
    } finally {
      setLoading(false)
    }
  }

  async function submitCode(value: string) {
    if (!otp || value.length !== OTP_LEN || loading) return
    submittedCode.current = value
    setError('')
    setLoading(true)
    try {
      await verifyOtp(otp.otp_token, value)
      navigate('/app')
    } catch (err) {
      if (err instanceof ApiError && err.code && OTP_RESTART_CODES.has(err.code)) {
        backToPassword(err.message)
      } else {
        setError(errorText(err, 'কোড যাচাই করা যায়নি।'))
        setCode('')
        setTimeout(() => codeRef.current?.focus(), 30)
      }
    } finally {
      setLoading(false)
    }
  }

  async function resend() {
    if (!otp || resendIn > 0 || resending) return
    setResending(true)
    setError('')
    try {
      const res = await AuthApi.resendOtp(otp.otp_token)
      setOtp({ ...otp, ...res, otp_required: true, otp_token: res.otp_token || otp.otp_token })
      setResendIn(res.resend_after || 60)
      setInfo(res.message || 'নতুন কোড পাঠানো হয়েছে।')
      setCode('')
      submittedCode.current = ''
      codeRef.current?.focus()
    } catch (err) {
      if (err instanceof ApiError && err.code && OTP_RESTART_CODES.has(err.code)) backToPassword(err.message)
      else {
        setError(errorText(err, 'কোড পাঠানো যায়নি।'))
        const m = err instanceof ApiError ? /([০-৯0-9]+) সেকেন্ড/.exec(err.message) : null
        if (m) setResendIn(Number(normalizeCode(m[1])) || 30)
      }
    } finally {
      setResending(false)
    }
  }

  function backToPassword(message = '') {
    setOtp(null)
    setCode('')
    setInfo('')
    setResendIn(0)
    setError(message)
    setPassword('')
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-neutral-50 px-4">
      <div className="w-full max-w-sm">
        <div className="text-center mb-7">
          <div className="w-12 h-12 rounded-2xl bg-brand-600 text-white flex items-center justify-center font-bold text-[18px] mx-auto mb-3">
            মি
          </div>
          <h1 className="text-[19px] font-bold text-neutral-900">মিহা নিউজ ড্যাশবোর্ড</h1>
          <p className="text-[13px] text-neutral-500 mt-1">{otp ? 'দুই ধাপের যাচাই' : 'এডিটর ও পাবলিশার লগইন'}</p>
        </div>

        <div className="bg-white rounded-2xl border border-neutral-200/80 shadow-sm p-6">
          {error && (
            <div role="alert">
              <Banner kind="error">{error}</Banner>
            </div>
          )}
          {!otp ? (
            <form onSubmit={onSubmit}>
              <Field label="ইমেইল / ইউজারনেম" required>
                <input
                  className={inputClass}
                  value={identifier}
                  onChange={(e) => setIdentifier(e.target.value)}
                  autoComplete="username"
                  autoFocus
                  required
                />
              </Field>
              <Field label="পাসওয়ার্ড" required>
                <input
                  type="password"
                  className={inputClass}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="current-password"
                  required
                />
              </Field>
              <Button type="submit" disabled={loading} className="w-full mt-2">
                {loading ? 'লগইন হচ্ছে...' : 'লগইন করুন'}
              </Button>
            </form>
          ) : (
            <form
              onSubmit={(e) => {
                e.preventDefault()
                void submitCode(code)
              }}
              data-testid="otp-step"
            >
              {info && <p className="text-[13px] text-neutral-600 mb-4" data-testid="otp-info">{info}</p>}
              <label htmlFor="otp-code" className="block text-[12.5px] font-medium text-neutral-600 mb-2">
                ৬ সংখ্যার কোড
              </label>
              <div className="relative mb-2">
                {/* Six visual slots behind one real input (paste + SMS/email autofill friendly). */}
                <div className="absolute inset-0 grid grid-cols-6 gap-2 pointer-events-none" aria-hidden="true">
                  {Array.from({ length: OTP_LEN }).map((_, i) => (
                    <div
                      key={i}
                      className={`rounded-xl border-2 flex items-center justify-center text-[22px] font-bold text-neutral-900 ${
                        i === code.length && !loading ? 'border-brand-500 bg-white' : code[i] ? 'border-neutral-300 bg-white' : 'border-neutral-200 bg-neutral-50'
                      }`}
                    >
                      {code[i] ? bnNumber(code[i]) : ''}
                    </div>
                  ))}
                </div>
                <input
                  id="otp-code"
                  ref={codeRef}
                  data-testid="otp-input"
                  className="relative w-full h-14 bg-transparent text-transparent caret-transparent outline-none text-[22px] tracking-[1.6em] pl-3 selection:bg-transparent"
                  value={code}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  pattern="[0-9০-৯]*"
                  maxLength={OTP_LEN * 2}
                  enterKeyHint="go"
                  aria-describedby="otp-help"
                  disabled={loading}
                  onChange={(e) => {
                    const v = normalizeCode(e.target.value)
                    setCode(v)
                    if (v.length === OTP_LEN && v !== submittedCode.current) void submitCode(v)
                  }}
                  onPaste={(e) => {
                    const v = normalizeCode(e.clipboardData.getData('text'))
                    if (v) {
                      e.preventDefault()
                      setCode(v)
                      if (v.length === OTP_LEN) void submitCode(v)
                    }
                  }}
                />
              </div>
              <p id="otp-help" className="text-[11.5px] text-neutral-400 mb-4">
                কোডটি কিছুক্ষণের জন্য বৈধ। বাংলা বা ইংরেজি সংখ্যা — দুটোই চলবে।
              </p>
              <Button type="submit" disabled={loading || code.length !== OTP_LEN} className="w-full">
                {loading ? 'যাচাই হচ্ছে…' : 'যাচাই করে লগইন করুন'}
              </Button>
              <div className="flex items-center justify-between mt-4 gap-2">
                <button
                  type="button"
                  onClick={() => backToPassword()}
                  className="flex items-center gap-1 text-[12.5px] font-medium text-neutral-500 hover:text-neutral-800 min-h-[44px]"
                >
                  <IconArrowLeft className="w-4 h-4" />
                  ফিরে যান
                </button>
                <button
                  type="button"
                  onClick={resend}
                  disabled={resendIn > 0 || resending}
                  data-testid="otp-resend"
                  className="text-[12.5px] font-medium text-brand-600 disabled:text-neutral-400 min-h-[44px]"
                >
                  {resending ? 'পাঠানো হচ্ছে…' : resendIn > 0 ? `আবার কোড পাঠান (${bnNumber(resendIn)} সে.)` : 'আবার কোড পাঠান'}
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}
