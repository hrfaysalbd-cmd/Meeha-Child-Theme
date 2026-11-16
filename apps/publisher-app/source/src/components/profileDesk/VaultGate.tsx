// r30 — ভল্টের দরজা: পিন সেট করা (কোড দিয়ে), খোলা (পিন → কোড), তালা দেওয়া.
import { useState } from 'react'
import { ProfileDeskApi, bn, errText, setVaultToken, type PdOtp, type PdVaultState } from '../../lib/profileDesk'
import { Banner, Button, inputClass } from '../ui'
import { IconLock } from '../Icon'
import { IconShieldLock } from './icons'

const BN = '০১২৩৪৫৬৭৮৯'
const digitsOnly = (s: string) => s.replace(/[০-৯]/g, (d) => String(BN.indexOf(d))).replace(/\D/g, '')

type Step = { kind: 'pin' } | { kind: 'code'; otp: PdOtp; purpose: 'unlock' | 'pin' }

/**
 * Shown where vault files would be when the vault is shut. On success the
 * token is kept for this tab and onOpened() reloads whatever was behind it.
 */
export default function VaultGate({
  state,
  onOpened,
  compact = false,
}: {
  state: PdVaultState
  onOpened: () => void
  compact?: boolean
}) {
  const [step, setStep] = useState<Step | null>(state.has_pin ? { kind: 'pin' } : null)
  const [settingPin, setSettingPin] = useState(!state.has_pin)
  const [pin, setPin] = useState('')
  const [newPin, setNewPin] = useState('')
  const [newPin2, setNewPin2] = useState('')
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function run(fn: () => Promise<void>) {
    setBusy(true)
    setError('')
    try {
      await fn()
    } catch (e) {
      setError(errText(e))
    } finally {
      setBusy(false)
    }
  }

  const sendUnlock = () =>
    run(async () => {
      const otp = await ProfileDeskApi.unlock(digitsOnly(pin))
      setStep({ kind: 'code', otp, purpose: 'unlock' })
      setCode('')
    })

  const startPin = () =>
    run(async () => {
      const a = digitsOnly(newPin)
      if (a.length < 4 || a.length > 8) throw new Error('পিন ৪ থেকে ৮ সংখ্যার হতে হবে।')
      if (a !== digitsOnly(newPin2)) throw new Error('দুইবার লেখা পিন মেলেনি।')
      const otp = await ProfileDeskApi.pinStart()
      setStep({ kind: 'code', otp, purpose: 'pin' })
      setCode('')
    })

  const verify = () =>
    run(async () => {
      if (step?.kind !== 'code') return
      const r = await ProfileDeskApi.verify({
        otp_token: step.otp.otp_token,
        code: digitsOnly(code),
        purpose: step.purpose,
        new_pin: step.purpose === 'pin' ? digitsOnly(newPin) : undefined,
      })
      setVaultToken(r.vault_token)
      setPin('')
      setNewPin('')
      setNewPin2('')
      onOpened()
    })

  const err = error

  return (
    <div className={`rounded-2xl border border-neutral-200 bg-gradient-to-b from-neutral-50 to-white ${compact ? 'p-4' : 'p-6'} text-center`}>
      <div className="mx-auto w-12 h-12 rounded-2xl bg-neutral-900 text-white inline-flex items-center justify-center">
        <IconShieldLock className="w-6 h-6" />
      </div>
      <h3 className="mt-3 text-[15px] font-bold text-neutral-900">গোপন ভল্ট বন্ধ আছে</h3>
      <p className="mt-1 text-[12.5px] text-neutral-500 max-w-sm mx-auto">
        খুলতে লাগবে আপনার পিন আর ফোন/ইমেইলে আসা কোড। খোলার পর {bn(state.idle_minutes || 15)} মিনিট কিছু না করলে নিজে থেকে বন্ধ হয়ে যাবে।
      </p>

      {state.locked_for > 0 && (
        <div className="mt-3">
          <Banner kind="error">অনেকবার ভুল পিন। {bn(Math.ceil(state.locked_for / 60))} মিনিট পর আবার চেষ্টা করুন।</Banner>
        </div>
      )}
      {err && (
        <div className="mt-3 text-left">
          <Banner kind="error">{err}</Banner>
        </div>
      )}

      <div className="mt-4 max-w-xs mx-auto text-left">
        {step?.kind === 'code' ? (
          <form
            onSubmit={(e) => {
              e.preventDefault()
              verify()
            }}
          >
            <p className="text-[12.5px] text-neutral-600 mb-2">
              {step.otp.method === 'sms' ? 'এসএমএস' : 'ইমেইল'} পাঠানো হয়েছে: <b dir="ltr">{step.otp.destination}</b>
            </p>
            <input
              autoFocus
              inputMode="numeric"
              autoComplete="one-time-code"
              className={`${inputClass} text-center tracking-[0.4em] text-[18px] font-bold`}
              placeholder="কোড"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              data-testid="pd-otp"
            />
            <Button type="submit" className="w-full mt-2" disabled={busy || digitsOnly(code).length < 4}>
              <IconLock className="w-4 h-4" /> {step.purpose === 'pin' ? 'পিন সেট করে ভল্ট খুলুন' : 'ভল্ট খুলুন'}
            </Button>
            <button type="button" className="mt-2 w-full text-[12px] text-neutral-500 hover:text-neutral-800" onClick={() => setStep(settingPin ? null : { kind: 'pin' })}>
              পেছনে যান
            </button>
          </form>
        ) : settingPin ? (
          <form
            onSubmit={(e) => {
              e.preventDefault()
              startPin()
            }}
          >
            <p className="text-[12.5px] text-neutral-600 mb-2">{state.has_pin ? 'নতুন পিন দিন' : 'প্রথমবার: একটা পিন ঠিক করুন'} (৪–৮ সংখ্যা)</p>
            <input
              type="password"
              inputMode="numeric"
              autoComplete="new-password"
              className={`${inputClass} text-center tracking-[0.4em]`}
              placeholder="নতুন পিন"
              value={newPin}
              onChange={(e) => setNewPin(e.target.value)}
              data-testid="pd-newpin"
            />
            <input
              type="password"
              inputMode="numeric"
              autoComplete="new-password"
              className={`${inputClass} text-center tracking-[0.4em] mt-2`}
              placeholder="আবার লিখুন"
              value={newPin2}
              onChange={(e) => setNewPin2(e.target.value)}
              data-testid="pd-newpin2"
            />
            <Button type="submit" className="w-full mt-2" disabled={busy}>
              কোড পাঠান
            </Button>
            {state.has_pin && (
              <button
                type="button"
                className="mt-2 w-full text-[12px] text-neutral-500 hover:text-neutral-800"
                onClick={() => {
                  setSettingPin(false)
                  setStep({ kind: 'pin' })
                }}
              >
                পেছনে যান
              </button>
            )}
          </form>
        ) : (
          <form
            onSubmit={(e) => {
              e.preventDefault()
              sendUnlock()
            }}
          >
            <input
              autoFocus={!compact}
              type="password"
              inputMode="numeric"
              autoComplete="current-password"
              className={`${inputClass} text-center tracking-[0.4em] text-[18px]`}
              placeholder="পিন"
              value={pin}
              onChange={(e) => setPin(e.target.value)}
              data-testid="pd-pin"
            />
            <Button type="submit" className="w-full mt-2" disabled={busy || digitsOnly(pin).length < 4 || state.locked_for > 0}>
              কোড পাঠান
            </Button>
            <button
              type="button"
              className="mt-2 w-full text-[12px] text-neutral-500 hover:text-neutral-800"
              onClick={() => {
                setSettingPin(true)
                setStep(null)
              }}
            >
              পিন ভুলে গেছেন / বদলাবেন?
            </button>
          </form>
        )}
      </div>
    </div>
  )
}

/** Small "lock now" control for an open vault. */
export function VaultLockBar({ onLocked }: { onLocked: () => void }) {
  const [busy, setBusy] = useState(false)
  async function lock(all: boolean) {
    setBusy(true)
    try {
      await ProfileDeskApi.lock(all)
    } catch {
      // the token is dropped here anyway
    }
    setVaultToken(null)
    setBusy(false)
    onLocked()
  }
  return (
    <div className="flex flex-wrap items-center gap-2 rounded-xl bg-emerald-50 border border-emerald-100 px-3 py-2 text-[12.5px] text-emerald-800">
      <span className="font-semibold">ভল্ট খোলা</span>
      <span className="text-emerald-700/80">১৫ মিনিট কিছু না করলে নিজে বন্ধ হবে</span>
      <span className="flex-1" />
      <Button size="sm" variant="secondary" disabled={busy} onClick={() => lock(false)}>
        <IconLock className="w-3.5 h-3.5" /> এখনই বন্ধ
      </Button>
      <Button size="sm" variant="ghost" disabled={busy} onClick={() => lock(true)}>
        সব ডিভাইসে বন্ধ
      </Button>
    </div>
  )
}
