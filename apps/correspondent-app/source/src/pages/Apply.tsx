import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { AreasApi, ApplicationsApi, ApiError, type AreasResponse, type WriterOptions } from '../lib/api'
import { Banner, Button, Field, inputClass, TopBar } from '../components/ui'
import { IconCheck, IconUpload } from '../components/Icon'

type FileField = 'nid' | 'cv' | 'certificate'
type Track = 'journalist' | 'writer'

// r47 — মিহা কলম: used when the server list cannot be fetched (same ids as the backend).
const WRITER_FALLBACK: WriterOptions = {
  name: 'মিহা কলম',
  groups: [
    { id: 'student', label: 'শিক্ষার্থী' },
    { id: 'teacher', label: 'শিক্ষক / অধ্যাপক' },
    { id: 'professional', label: 'পেশাজীবী' },
    { id: 'general', label: 'সাধারণ লেখক' },
  ],
  genres: [
    { id: 'feature', label: 'ফিচার' },
    { id: 'story', label: 'গল্প' },
    { id: 'poem', label: 'কবিতা' },
    { id: 'essay', label: 'প্রবন্ধ' },
    { id: 'opinion', label: 'মতামত / কলাম' },
    { id: 'travel', label: 'ভ্রমণ' },
    { id: 'kids', label: 'শিশু-কিশোর' },
    { id: 'campus', label: 'ক্যাম্পাস' },
  ],
}

const INSTITUTION_LABEL: Record<string, string> = {
  student: 'শিক্ষাপ্রতিষ্ঠানের নাম',
  teacher: 'শিক্ষাপ্রতিষ্ঠানের নাম',
  professional: 'প্রতিষ্ঠান / কর্মস্থল',
  general: 'প্রতিষ্ঠান (যদি থাকে)',
}
const DESIGNATION_LABEL: Record<string, string> = {
  student: 'শ্রেণি / বর্ষ ও বিভাগ',
  teacher: 'পদবি ও বিভাগ',
  professional: 'পেশা ও পদবি',
  general: 'পেশা (যদি বলতে চান)',
}

/** The first choice: which way in. */
function TrackChoice({ onPick }: { onPick: (t: Track) => void }) {
  const card = 'w-full text-left rounded-2xl border-2 border-neutral-200 bg-white p-4 hover:border-brand-500 active:scale-[0.99] transition'
  return (
    <div className="px-5 pt-6 pb-10 space-y-3">
      <p className="text-[15px] font-semibold text-neutral-900">আপনি মিহা নিউজে কীভাবে যুক্ত হতে চান?</p>
      <button type="button" className={card} onClick={() => onPick('journalist')} data-testid="track-journalist">
        <span className="block text-[17px] font-bold text-neutral-900">📰 সাংবাদিক হতে চাই</span>
        <span className="block text-[13px] text-neutral-500 mt-1">
          নিজের এলাকা থেকে খবর, ছবি ও ভিডিও পাঠাবেন — জেলা/উপজেলা প্রতিনিধি হিসেবে। এনআইডি লাগবে।
        </span>
      </button>
      <button type="button" className={card} onClick={() => onPick('writer')} data-testid="track-writer">
        <span className="block text-[17px] font-bold text-neutral-900">✒️ মিহা কলম — লেখা পাঠাতে চাই</span>
        <span className="block text-[13px] text-neutral-500 mt-1">
          ফিচার, গল্প, কবিতা, প্রবন্ধ, মতামত — শিক্ষার্থী, শিক্ষক-অধ্যাপক, পেশাজীবী সবার জন্য। লেখকদের আলাদা চ্যাটরুম থাকবে।
        </span>
      </button>
    </div>
  )
}

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
  const [params] = useSearchParams()
  const [areas, setAreas] = useState<AreasResponse | null>(null)
  // r47 — ?track=writer (from the landing page's "লেখা পাঠাতে চাই") skips the first choice
  const [track, setTrack] = useState<Track | null>(() => {
    const t = params.get('track')
    return t === 'writer' || t === 'journalist' ? t : null
  })
  const [wopts, setWopts] = useState<WriterOptions>(WRITER_FALLBACK)
  const [group, setGroup] = useState('student')
  const [institution, setInstitution] = useState('')
  const [designation, setDesignation] = useState('')
  const [penName, setPenName] = useState('')
  const [genres, setGenres] = useState<string[]>([])
  const [sample, setSample] = useState('')

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
    ApplicationsApi.writerOptions()
      .then((o) => o.groups?.length && setWopts(o))
      .catch(() => {})
  }, [])

  const writer = track === 'writer'

  const districts = areas?.divisions.find((d) => d.name === division)?.districts ?? []
  const upazilas = areas?.upazilas[district] ?? []

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError('')

    if (!writer && !files.nid) {
      setError('এনআইডি আপলোড আবশ্যক।')
      return
    }
    if (writer && (group === 'student' || group === 'teacher') && !institution.trim()) {
      setError('শিক্ষাপ্রতিষ্ঠানের নাম লিখুন।')
      return
    }
    if (writer && !genres.length) {
      setError('কী ধরনের লেখা পাঠাবেন — অন্তত একটি বেছে নিন।')
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
      form.append('track', writer ? 'writer' : 'journalist')
      if (writer) {
        form.append('writer_group', group)
        form.append('institution', institution)
        form.append('designation', designation)
        form.append('pen_name', penName)
        form.append('genres', genres.join(','))
        form.append('sample', sample)
      }
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
          {writer
            ? 'মিহা কলমে আপনার লেখক আবেদন যাচাই করা হবে। অনুমোদিত হলে লগইন তথ্য ইমেইলে পাঠানো হবে, আর আপনি লেখকদের চ্যাটরুমে যুক্ত হবেন।'
            : 'আপনার আবেদনটি যাচাই করা হবে। অনুমোদিত হলে লগইন তথ্য ইমেইলে পাঠানো হবে।'}
        </p>
        <Button onClick={() => navigate('/landing')} variant="secondary">
          হোমে ফিরুন
        </Button>
      </div>
    )
  }

  if (!track) {
    return (
      <div className="flex-1 flex flex-col">
        <TopBar title="মিহা নিউজে যুক্ত হোন" onBack={() => navigate('/landing')} />
        <TrackChoice onPick={setTrack} />
      </div>
    )
  }

  return (
    <div className="flex-1 flex flex-col">
      <TopBar title={writer ? 'মিহা কলম — লেখক আবেদন' : 'সংবাদদাতা আবেদন'} onBack={() => setTrack(null)} />
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

        {writer && (
          <div data-testid="writer-fields">
            <div className="h-px bg-neutral-100 my-4" />
            <p className="text-[13px] font-semibold text-neutral-700 mb-2">আপনি কে? (টিক দিন)</p>
            <div className="grid grid-cols-2 gap-2 mb-4">
              {wopts.groups.map((g) => (
                <label
                  key={g.id}
                  className={`flex items-center gap-2 rounded-xl border px-3 py-2.5 text-[14px] ${group === g.id ? 'border-brand-500 bg-brand-50 font-semibold' : 'border-neutral-200'}`}
                >
                  <input type="radio" name="wgroup" checked={group === g.id} onChange={() => setGroup(g.id)} />
                  {g.label}
                </label>
              ))}
            </div>

            <Field label={INSTITUTION_LABEL[group] ?? 'প্রতিষ্ঠান'} required={group === 'student' || group === 'teacher'}>
              <input className={inputClass} value={institution} onChange={(e) => setInstitution(e.target.value)} placeholder="যেমন: ঢাকা বিশ্ববিদ্যালয়" />
            </Field>
            <Field label={DESIGNATION_LABEL[group] ?? 'পদবি'}>
              <input className={inputClass} value={designation} onChange={(e) => setDesignation(e.target.value)} />
            </Field>

            <p className="text-[13px] font-semibold text-neutral-700 mb-2">কী লিখবেন? (একাধিক টিক দেওয়া যায়)</p>
            <div className="flex flex-wrap gap-2 mb-4">
              {wopts.genres.map((g) => {
                const on = genres.includes(g.id)
                return (
                  <label
                    key={g.id}
                    className={`flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13.5px] ${on ? 'border-brand-500 bg-brand-50 font-semibold' : 'border-neutral-200'}`}
                  >
                    <input type="checkbox" checked={on} onChange={() => setGenres((s) => (on ? s.filter((x) => x !== g.id) : [...s, g.id]))} />
                    {g.label}
                  </label>
                )
              })}
            </div>

            <Field label="লেখক নাম / ছদ্মনাম (ঐচ্ছিক)">
              <input className={inputClass} value={penName} onChange={(e) => setPenName(e.target.value)} />
            </Field>
            <Field label="আগের লেখার লিংক বা নিজের সম্পর্কে দুই লাইন (ঐচ্ছিক)">
              <textarea className={inputClass} rows={3} value={sample} onChange={(e) => setSample(e.target.value)} />
            </Field>
            <p className="text-[12px] text-neutral-500 -mt-1 mb-2">
              ওয়েবসাইটে আপনার নামের নিচে যে উপাধি দেখাবে (যেমন “শিক্ষার্থী লেখক”, “অতিথি লেখক”), তা পাবলিশার ঠিক করবেন।
            </p>
          </div>
        )}

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

        <p className="text-[13px] font-semibold text-neutral-700 mb-3">পরিচয় যাচাই{writer ? ' (ঐচ্ছিক, দিলে দ্রুত অনুমোদন)' : ' (KYC)'}</p>

        <FileRow
          label={writer ? 'এনআইডি বা শিক্ষার্থী/প্রতিষ্ঠানের পরিচয়পত্র' : 'জাতীয় পরিচয়পত্র (NID)'}
          required={!writer}
          file={files.nid}
          onChange={(f) => setFiles((s) => ({ ...s, nid: f }))}
        />
        <FileRow label={writer ? 'লেখক পরিচিতি / CV (যদি থাকে)' : 'জীবনবৃত্তান্ত (CV)'} file={files.cv} onChange={(f) => setFiles((s) => ({ ...s, cv: f }))} />
        {!writer && (
          <FileRow
            label="সাংবাদিকতা/প্রশিক্ষণ সার্টিফিকেট (যদি থাকে)"
            file={files.certificate}
            onChange={(f) => setFiles((s) => ({ ...s, certificate: f }))}
          />
        )}

        <div className="mt-5">
          <Button type="submit" disabled={submitting}>
            {submitting ? 'জমা হচ্ছে…' : 'আবেদন জমা দিন'}
          </Button>
        </div>
      </form>
    </div>
  )
}
