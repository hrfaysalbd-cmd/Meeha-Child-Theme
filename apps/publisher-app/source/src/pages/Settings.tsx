import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AvatarApi,
  ApiError,
  DEFAULT_EDITOR_PERMISSIONS,
  SettingsApi,
  type EditorPermissions,
  type Settings as SettingsData,
  type ViewTier,
} from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { Banner, Button, Card, Field, PageHeader, Spinner, inputClass } from '../components/ui'
import { IconCamera, IconLock, IconPlus, IconSparkles, IconX } from '../components/Icon'
import VideoKitCard from '../components/videoStudio/VideoKitCard'

const EDITOR_PERMISSION_ROWS: { key: keyof EditorPermissions; label: string; help: string }[] = [
  { key: 'delete_posts', label: 'পোস্ট ডিলিট', help: 'নিউজ ও ভিডিও ট্র্যাশে সরাতে পারবেন (wp-admin থেকে ফিরিয়ে আনা যায়)।' },
  { key: 'change_date', label: 'সময় পরিবর্তন', help: 'প্রকাশের তারিখ ও সময় বদলাতে বা ভবিষ্যতের সময়ে শিডিউল করতে পারবেন।' },
  { key: 'change_author', label: 'লেখক পরিবর্তন', help: 'পোস্ট বা ভিডিওর লেখক অন্য কোনো সংবাদদাতা/স্টাফ করতে পারবেন।' },
]

function Toggle({
  checked,
  onChange,
  disabled,
  label,
}: {
  checked: boolean
  onChange: (v: boolean) => void
  disabled?: boolean
  label: string
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={`relative w-10 h-6 rounded-full shrink-0 transition-colors disabled:opacity-60 disabled:cursor-not-allowed ${
        checked ? 'bg-brand-600' : 'bg-neutral-300'
      }`}
    >
      <span
        className={`absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform ${checked ? 'translate-x-4' : ''}`}
      />
    </button>
  )
}

export default function Settings() {
  const { profile, isPublisher, refreshProfile } = useAuth()
  const avatarInput = useRef<HTMLInputElement>(null)
  const [avatarBusy, setAvatarBusy] = useState(false)
  const [avatarError, setAvatarError] = useState('')

  const [data, setData] = useState<SettingsData | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  useEffect(() => {
    SettingsApi.get()
      .then((d) => setData({ ...d, editor_permissions: { ...DEFAULT_EDITOR_PERMISSIONS, ...(d.editor_permissions ?? {}) } }))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }, [])

  async function save() {
    if (!data) return
    setSaving(true)
    setSuccess('')
    setError('')
    try {
      // Drop any half-filled row (no views or no amount typed yet) rather
      // than sending it — the backend would otherwise reject the whole save
      // with a 400 over one still-empty row.
      const clean = (data.view_tiers ?? []).filter((t) => t.views > 0 && t.amount > 0)
      await SettingsApi.update({ ...data, view_tiers: clean })
      setSuccess('সংরক্ষিত হয়েছে।')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setSaving(false)
    }
  }

  function updateTier(index: number, patch: Partial<ViewTier>) {
    const tiers = [...(data?.view_tiers ?? [])]
    tiers[index] = { ...tiers[index], ...patch }
    setData((d) => (d ? { ...d, view_tiers: tiers } : d))
  }

  function addTier() {
    setData((d) => (d ? { ...d, view_tiers: [...(d.view_tiers ?? []), { views: 0, amount: 0 }] } : d))
  }

  function removeTier(index: number) {
    setData((d) => (d ? { ...d, view_tiers: (d.view_tiers ?? []).filter((_, i) => i !== index) } : d))
  }

  async function onPickAvatar(file: File) {
    setAvatarError('')
    setAvatarBusy(true)
    try {
      await AvatarApi.upload(file)
      await refreshProfile()
    } catch (err) {
      setAvatarError(err instanceof ApiError ? err.message : 'ছবি যুক্ত করা যায়নি।')
    } finally {
      setAvatarBusy(false)
    }
  }

  async function onRemoveAvatar() {
    setAvatarError('')
    setAvatarBusy(true)
    try {
      await AvatarApi.remove()
      await refreshProfile()
    } catch (err) {
      setAvatarError(err instanceof ApiError ? err.message : 'ছবি মুছে ফেলা যায়নি।')
    } finally {
      setAvatarBusy(false)
    }
  }

  if (loading || !data) return <Spinner className="mt-16" />

  return (
    <div>
      <PageHeader title="সেটিংস" subtitle="এডিটরের অনুমতি, ফাইন্যান্স দৃশ্যমানতা ও সাইট SEO" />
      {error && <Banner kind="error">{error}</Banner>}
      {success && <Banner kind="success">{success}</Banner>}

      <Card className="p-5 mb-5 max-w-xl">
        <p className="text-[14px] font-semibold text-neutral-800 mb-4">আপনার প্রোফাইল ছবি</p>
        <input
          ref={avatarInput}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          className="hidden"
          onChange={(e) => {
            const f = e.target.files?.[0]
            if (f) onPickAvatar(f)
            e.target.value = ''
          }}
        />
        <div className="flex items-center gap-4">
          <button
            type="button"
            onClick={() => avatarInput.current?.click()}
            disabled={avatarBusy}
            className="relative w-16 h-16 rounded-full shrink-0"
            aria-label="প্রোফাইল ছবি পরিবর্তন করুন"
          >
            <img src={profile?.avatar} alt="" className="w-16 h-16 rounded-full bg-neutral-100 object-cover" />
            <span className="absolute -bottom-0.5 -right-0.5 w-6 h-6 rounded-full bg-brand-600 text-white flex items-center justify-center border-2 border-white">
              <IconCamera className="w-3 h-3" />
            </span>
          </button>
          <div className="flex items-center gap-3">
            <button onClick={() => avatarInput.current?.click()} disabled={avatarBusy} className="text-[12.5px] font-medium text-brand-600">
              {avatarBusy ? 'হচ্ছে…' : 'ছবি পরিবর্তন করুন'}
            </button>
            <button onClick={onRemoveAvatar} disabled={avatarBusy} className="text-[12.5px] font-medium text-neutral-400">
              ডিফল্টে ফিরুন
            </button>
          </div>
        </div>
        {avatarError && <p className="text-[12px] text-red-600 mt-2">{avatarError}</p>}
      </Card>

      <Card className="p-5 mb-5 max-w-xl">
        <p className="text-[14px] font-semibold text-neutral-800 mb-1">এডিটরের জন্য ফাইন্যান্স দৃশ্যমানতা</p>
        <p className="text-[12.5px] text-neutral-500 mb-4">
          চালু থাকলে এডিটর সম্মানী/কমিশন টোটাল, উইথড্র রিকোয়েস্ট ও ব্যালেন্স দেখতে ও প্রসেস করতে পারবেন। বন্ধ থাকলে এই তথ্য শুধু পাবলিশার দেখতে পারবেন।
        </p>
        <label className="flex items-center gap-3 cursor-pointer">
          <input
            type="checkbox"
            checked={data.editor_finance_visible}
            disabled={!isPublisher}
            onChange={(e) => setData({ ...data, editor_finance_visible: e.target.checked })}
            className="w-5 h-5 accent-brand-600"
          />
          <span className="text-[13.5px] text-neutral-700">এডিটরকে ফাইন্যান্স তথ্য দেখার অনুমতি দিন</span>
        </label>
        {!isPublisher && <p className="text-[11.5px] text-neutral-400 mt-2">এই সেটিংস শুধুমাত্র পাবলিশার পরিবর্তন করতে পারবেন।</p>}
      </Card>

      <Card className="p-5 mb-5 max-w-xl">
        <p className="text-[14px] font-semibold text-neutral-800 mb-1">এডিটরের অনুমতি</p>
        <p className="text-[12.5px] text-neutral-500 mb-3">
          পাবলিশার সব সময় এগুলো করতে পারেন। এখানে ঠিক করুন এডিটর কী কী করতে পারবেন — নিউজ ও ভিডিও নিউজ দুটোতেই প্রযোজ্য।
        </p>
        <div className="divide-y divide-neutral-100" data-testid="editor-permissions">
          {EDITOR_PERMISSION_ROWS.map((row) => (
            <div key={row.key} className="flex items-center justify-between gap-4 py-3">
              <div className="min-w-0">
                <p className="text-[13.5px] font-medium text-neutral-800">{row.label}</p>
                <p className="text-[11.5px] text-neutral-400">{row.help}</p>
              </div>
              <Toggle
                label={row.label}
                checked={data.editor_permissions[row.key]}
                disabled={!isPublisher}
                onChange={(v) => setData({ ...data, editor_permissions: { ...data.editor_permissions, [row.key]: v } })}
              />
            </div>
          ))}
        </div>
        {!isPublisher && (
          <p className="flex items-center gap-1 text-[11.5px] text-neutral-400 mt-2">
            <IconLock className="w-3.5 h-3.5" />
            এই অনুমতিগুলো শুধুমাত্র পাবলিশার পরিবর্তন করতে পারবেন।
          </p>
        )}
      </Card>

      <Card className="p-5 mb-5 max-w-xl">
        <p className="text-[14px] font-semibold text-neutral-800 mb-4">সাইট SEO (মৌলিক)</p>
        <Field label="সাইট টাইটেল">
          <input
            className={inputClass}
            value={data.seo_title}
            disabled={!isPublisher}
            onChange={(e) => setData({ ...data, seo_title: e.target.value })}
          />
        </Field>
        <Field label="মেটা ডেসক্রিপশন">
          <textarea
            className={inputClass}
            rows={3}
            value={data.seo_description}
            disabled={!isPublisher}
            onChange={(e) => setData({ ...data, seo_description: e.target.value })}
          />
        </Field>
      </Card>

      <Card className="p-5 mb-5 max-w-xl">
        <p className="text-[14px] font-semibold text-neutral-800 mb-1">ভিউ-স্ল্যাব বোনাস</p>
        <p className="text-[12.5px] text-neutral-500 mb-4">
          একটি পোস্ট নির্দিষ্ট পরিমাণ ভিউ পেলে স্বয়ংক্রিয়ভাবে বোনাস যুক্ত হবে — প্রতিটি অতিক্রম করা স্ল্যাব আলাদাভাবে যুক্ত হয় (উদাহরণ: ১০ হাজার ভিউ ক্রস
          করলে ৫০ টাকা, তারপর ৫০ হাজার ক্রস করলে আরও ২০০ টাকা, ইত্যাদি)। এই পোস্টের নিজস্ব পেমেন্টের সাথেও যুক্ত হয়। প্রতিদিন একবার চেক হয়।
        </p>

        {(data.view_tiers ?? []).length === 0 && <p className="text-[12.5px] text-neutral-400 mb-3">এখনো কোনো স্ল্যাব যুক্ত করা হয়নি।</p>}

        <div className="flex flex-col gap-2 mb-3">
          {(data.view_tiers ?? []).map((tier, i) => (
            <div key={i} className="flex items-center gap-2">
              <input
                type="number"
                min={0}
                className={inputClass}
                placeholder="ভিউ সংখ্যা"
                disabled={!isPublisher}
                value={tier.views || ''}
                onChange={(e) => updateTier(i, { views: Number(e.target.value) })}
              />
              <span className="text-[12.5px] text-neutral-400 shrink-0">ভিউ =</span>
              <input
                type="number"
                min={0}
                className={inputClass}
                placeholder="টাকা"
                disabled={!isPublisher}
                value={tier.amount || ''}
                onChange={(e) => updateTier(i, { amount: Number(e.target.value) })}
              />
              <span className="text-[12.5px] text-neutral-400 shrink-0">৳</span>
              {isPublisher && (
                <button onClick={() => removeTier(i)} className="text-neutral-400 hover:text-red-600 shrink-0" aria-label="বাদ দিন">
                  <IconX className="w-4 h-4" />
                </button>
              )}
            </div>
          ))}
        </div>

        {isPublisher && (
          <button onClick={addTier} className="flex items-center gap-1 text-[12.5px] font-medium text-brand-600">
            <IconPlus className="w-4 h-4" />
            আরেকটি স্ল্যাব যুক্ত করুন
          </button>
        )}
      </Card>

      <VideoKitCard canEdit={isPublisher} />

      <Card className="p-5 mb-5 max-w-xl">
        <p className="text-[14px] font-semibold text-neutral-800 mb-1 flex items-center gap-1.5">
          <IconSparkles className="w-4 h-4 text-brand-600" />
          এ.আই প্রম্পট
        </p>
        <p className="text-[12.5px] text-neutral-500 mb-4">
          সারাংশ/এডিটোরিয়াল/এসইও প্রম্পট টেমপ্লেট এখন তাদের নিজস্ব "প্রোম্পট" মেনুতে সরানো হয়েছে — সেখানে প্রতিটি প্রম্পট আলাদাভাবে কোন রোল দেখতে
          পারবে তা নির্ধারণ করা যায়, এবং নিউজ-প্রম্পট ও লাইব্রেরি-প্রম্পট দুই ধরনের প্রম্পট যুক্ত করা যায়।
        </p>
        <Link to="/app/prompts" className="inline-flex items-center gap-1 text-[12.5px] font-semibold text-brand-600">
          প্রোম্পট মেনুতে যান →
        </Link>
      </Card>

      {isPublisher && (
        <Button onClick={save} disabled={saving}>
          {saving ? 'সংরক্ষণ হচ্ছে...' : 'সংরক্ষণ করুন'}
        </Button>
      )}
    </div>
  )
}
