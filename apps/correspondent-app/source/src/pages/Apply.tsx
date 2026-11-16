import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AreasApi, ApplicationsApi, ApiError, type AreasResponse } from '../lib/api'
import { Banner, Button, Field, inputClass, TopBar } from '../components/ui'
import { IconCheck, IconUpload } from '../components/Icon'

type FileField = 'nid' | 'cv' | 'certificate'

function districtName(d: { name: string } | string) {
  return typeof d === 'string' ? d : d.name
}

function FileRow({
  label,
  required,
  file,
  onChange,
}: {
  label: string
  required?: boolean
  file: File | null
  onChange: (f: File | null) => void
}) {
  return (
    <Field label={label} required={required}>
      <label
        className={`flex items-center gap-2.5 rounded-xl border px-3.5 py-2.5 cursor-pointer ${
          file ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-neutral-200 bg-neutral-50 text-neutral-500'
        }`}
      >
        {file ? <IconCheck className="w-4.5 h-4.5 shrink-0" /> : <IconUpload className="w-4.5 h-4.5 shrink-0" />}
        <span className="text-[14px] truncate flex-1">{file ? file.name : 'ফাইল নির্বাচন করুন (PDF/JPG/PNG, সর্বোচ্চ ৫ এমবি)'}</span>
        <input
          type="file"
          accept=".pdf,.jpg,.jpeg,.png"
          className="hidden"
          onChange={(e) => onChange(e.target.files?.[0] ?? null)}
        />
      </label>
    </Field>
  )
}

export default function Apply() {
  const navigate = useNavigate()
  const [areas, setAreas] = useState<AreasResponse | null>(null)

  const [fullName, setFullName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [division, setDivision] = useState('')
  const [district, setDistrict] = useState('')
  const [upazila, setUpazila] = useState('')
  const [files, setFiles] = useState<Record<FileField, File | null>>({ nid: null, cv: null, certificate: null })

  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [done, setDone] = useState(false)

  useEffect(() => {
    AreasApi.get().then(setAreas).catch(() => setAreas({ divisions: [], upazilas: {}, source: 'unavailable' }))
  }, [])

  const districts = areas?.divisions.find((d) => d.name === division)?.districts ?? []
  const upazilas = areas?.upazilas[district] ?? []

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError('')

    if (!files.nid) {
      setError('এনআইডি আপলোড আবশ্যক।')
      return
    }

    setSubmitting(true)
    try {
      const form = new FormData()
      form.append('full_name', fullName)
      form.append('email', email)
      form.append('phone', phone)
      form.append('division', division)
      form.append('district', district)
      form.append('upazila', upazila)
      ;(Object.keys(files) as FileField[]).forEach((k) => {
        if (files[k]) form.append(k, files[k]!)
      })

      await ApplicationsApi.submit(form)
      setDone(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'আবেদন পাঠানো যায়নি, আবার চেষ্টা করুন।')
    } finally {
      setSubmitting(false)
    }
  }

  if (done) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center text-center px-8">
        <div className="w-16 h-16 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mb-4">
          <IconCheck className="w-8 h-8" />
        </div>
        <h2 className="text-[18px] font-bold text-neutral-900 mb-2">আবেদন জমা হয়েছে</h2>
        <p className="text-[14px] text-neutral-500 leading-relaxed mb-8">
          আপনার আবেদনটি যাচাই করা হবে। অনুমোদিত হলে লগইন তথ্য ইমেইলে পাঠানো হবে।
        </p>
        <Button onClick={() => navigate('/landing')} variant="secondary">
          হোমে ফিরুন
        </Button>
      </div>
    )
  }

  return (
    <div className="flex-1 flex flex-col">
      <TopBar title="সংবাদদাতা আবেদন" onBack={() => navigate('/landing')} />
      <form onSubmit={onSubmit} className="flex-1 px-5 pt-5 pb-10">
        {error && <Banner kind="error">{error}</Banner>}

        <Field label="পূর্ণ নাম" required>
          <input className={inputClass} value={fullName} onChange={(e) => setFullName(e.target.value)} required />
        </Field>

        <Field label="ইমেইল" required>
          <input className={inputClass} type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </Field>

        <Field label="ফোন নম্বর" required>
          <input className={inputClass} value={phone} onChange={(e) => setPhone(e.target.value)} required />
        </Field>

        <div className="grid grid-cols-1 gap-0">
          <Field label="বিভাগ">
            <select
              className={inputClass}
              value={division}
              onChange={(e) => {
                setDivision(e.target.value)
                setDistrict('')
                setUpazila('')
              }}
            >
              <option value="">নির্বাচন করুন</option>
              {areas?.divisions.map((d) => (
                <option key={d.name} value={d.name}>
                  {d.name}
                </option>
              ))}
            </select>
          </Field>

          <Field label="জেলা">
            <select
              className={inputClass}
              value={district}
              onChange={(e) => {
                setDistrict(e.target.value)
                setUpazila('')
              }}
              disabled={!division}
            >
              <option value="">নির্বাচন করুন</option>
              {districts.map((d) => {
                const name = districtName(d)
                return (
                  <option key={name} value={name}>
                    {name}
                  </option>
                )
              })}
            </select>
          </Field>

          <Field label="উপজেলা">
            <select className={inputClass} value={upazila} onChange={(e) => setUpazila(e.target.value)} disabled={!district}>
              <option value="">নির্বাচন করুন</option>
              {upazilas.map((u) => (
                <option key={u} value={u}>
                  {u}
                </option>
              ))}
            </select>
          </Field>
        </div>

        <div className="h-px bg-neutral-100 my-4" />

        <p className="text-[13px] font-semibold text-neutral-700 mb-3">পরিচয় যাচাই (KYC)</p>

        <FileRow label="জাতীয় পরিচয়পত্র (NID)" required file={files.nid} onChange={(f) => setFiles((s) => ({ ...s, nid: f }))} />
        <FileRow label="জীবনবৃত্তান্ত (CV)" file={files.cv} onChange={(f) => setFiles((s) => ({ ...s, cv: f }))} />
        <FileRow
          label="সাংবাদিকতা/প্রশিক্ষণ সার্টিফিকেট (যদি থাকে)"
          file={files.certificate}
          onChange={(f) => setFiles((s) => ({ ...s, certificate: f }))}
        />

        <div className="mt-5">
          <Button type="submit" disabled={submitting}>
            {submitting ? 'জমা হচ্ছে…' : 'আবেদন জমা দিন'}
          </Button>
        </div>
      </form>
    </div>
  )
}
