import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { PromptsApi, type Prompt, type PromptField, type PromptFieldType, type PromptRole, type PromptType } from '../lib/api'
import { useAuth } from '../lib/AuthContext'
import { Badge, Banner, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, Table, Td, Th, inputClass } from '../components/ui'
import { IconPlus, IconSparkles, IconX } from '../components/Icon'

const ROLE_LABELS: Record<PromptRole, string> = {
  correspondent: 'সংবাদদাতা',
  editor: 'এডিটর',
  publisher: 'পাবলিশার',
}
const ALL_ROLES: PromptRole[] = ['correspondent', 'editor', 'publisher']
const FIELD_TYPE_LABELS: Record<PromptFieldType, string> = {
  text: 'টেক্সট (এক লাইন)',
  textarea: 'টেক্সট (বড়)',
  select: 'প্রিসেট অপশন (স্টাইল/টোন ইত্যাদি)',
}

function emptyField(): PromptField {
  return { key: '', label: '', field_type: 'text', options: [] }
}

export default function Prompts() {
  const { isPublisher } = useAuth()

  const [items, setItems] = useState<Prompt[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [filter, setFilter] = useState<'all' | PromptType>('all')

  const [editing, setEditing] = useState<Prompt | null>(null)
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')

  // Form state
  const [type, setType] = useState<PromptType>('news')
  const [label, setLabel] = useState('')
  const [body, setBody] = useState('')
  const [visibleRoles, setVisibleRoles] = useState<PromptRole[]>(['correspondent'])
  const [fields, setFields] = useState<PromptField[]>([])

  function load() {
    setLoading(true)
    PromptsApi.listAll()
      .then(setItems)
      .catch((e) => setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।'))
      .finally(() => setLoading(false))
  }

  useEffect(load, [])

  function openCreate() {
    setEditing(null)
    setType('news')
    setLabel('')
    setBody('')
    setVisibleRoles(['correspondent'])
    setFields([])
    setFormError('')
    setShowForm(true)
  }

  function openEdit(p: Prompt) {
    setEditing(p)
    setType(p.type)
    setLabel(p.label)
    setBody(p.body)
    setVisibleRoles(p.visible_roles)
    setFields(p.fields.length ? p.fields : [])
    setFormError('')
    setShowForm(true)
  }

  function toggleRole(role: PromptRole) {
    setVisibleRoles((prev) => (prev.includes(role) ? prev.filter((r) => r !== role) : [...prev, role]))
  }

  function updateField(i: number, patch: Partial<PromptField>) {
    setFields((prev) => prev.map((f, idx) => (idx === i ? { ...f, ...patch } : f)))
  }

  function addField() {
    setFields((prev) => [...prev, emptyField()])
  }

  function removeField(i: number) {
    setFields((prev) => prev.filter((_, idx) => idx !== i))
  }

  function updateFieldOptions(i: number, raw: string) {
    // One option per line — simplest input for a Publisher to type preset
    // style/tone choices without a nested add/remove UI per option.
    const options = raw.split('\n').map((s) => s.trim()).filter(Boolean)
    updateField(i, { options })
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setFormError('')
    if (!label.trim() || !body.trim()) {
      setFormError('প্রোম্পট লেবেল ও প্রোম্পট টেক্সট আবশ্যক।')
      return
    }
    if (type === 'library') {
      const keys = fields.map((f) => f.key.trim()).filter(Boolean)
      if (keys.length === 0) {
        setFormError('লাইব্রেরি প্রোম্পটে অন্তত একটি কাস্টম ফিল্ড যুক্ত করুন।')
        return
      }
      const hasPlaceholder = keys.some((k) => body.includes(`{${k}}`))
      if (!hasPlaceholder) {
        setFormError(`প্রোম্পট টেক্সটে অন্তত একটি ফিল্ড শর্টকোড ব্যবহার করুন — যেমন {${keys[0]}}।`)
        return
      }
    }

    const payload = {
      type,
      label: label.trim(),
      body,
      visible_roles: visibleRoles,
      fields: type === 'library' ? fields.filter((f) => f.key.trim() && f.label.trim()) : [],
    }

    setSaving(true)
    try {
      if (editing) {
        await PromptsApi.update(editing.id, payload)
      } else {
        await PromptsApi.create(payload)
      }
      setShowForm(false)
      load()
    } catch (e) {
      setFormError(e instanceof Error ? e.message : 'সংরক্ষণ করা যায়নি।')
    } finally {
      setSaving(false)
    }
  }

  async function remove(p: Prompt) {
    if (!window.confirm(`"${p.label}" প্রোম্পটটি মুছে ফেলতে চান?`)) return
    try {
      await PromptsApi.remove(p.id)
      setItems((prev) => prev.filter((x) => x.id !== p.id))
    } catch (e) {
      setError(e instanceof Error ? e.message : 'মুছে ফেলা যায়নি।')
    }
  }

  const visible = items.filter((p) => filter === 'all' || p.type === filter)

  return (
    <div>
      <PageHeader
        title="প্রোম্পট"
        subtitle="এ.আই প্রোম্পট টেমপ্লেট তৈরি ও পরিচালনা করুন — প্রতিটি প্রোম্পট আলাদাভাবে কোন রোল দেখতে পারবে তা নির্ধারণ করুন"
        actions={
          <div className="flex items-center gap-2">
            <Link to="/app/prompt-library">
              <Button variant="secondary">
                <IconSparkles className="w-4 h-4" /> প্রোম্পট লাইব্রেরি
              </Button>
            </Link>
            {isPublisher && (
              <Button onClick={openCreate}>
                <IconPlus className="w-4 h-4" /> নতুন প্রোম্পট সেট করুন
              </Button>
            )}
          </div>
        }
      />
      {error && <Banner kind="error">{error}</Banner>}
      {!isPublisher && (
        <Banner kind="info">আপনি সব প্রোম্পট দেখতে ও কপি করতে পারবেন — নতুন প্রোম্পট যুক্ত বা পরিবর্তন শুধু পাবলিশার করতে পারবেন।</Banner>
      )}

      <div className="flex gap-2 mb-4">
        {(['all', 'news', 'library'] as const).map((f) => (
          <button
            key={f}
            onClick={() => setFilter(f)}
            className={`px-3 py-1.5 rounded-lg text-[12.5px] font-medium ${
              filter === f ? 'bg-brand-600 text-white' : 'bg-white border border-neutral-200 text-neutral-600'
            }`}
          >
            {f === 'all' ? 'সব প্রোম্পট' : f === 'news' ? 'নিউজ প্রোম্পট' : 'লাইব্রেরি প্রোম্পট'}
          </button>
        ))}
      </div>

      {loading ? (
        <Spinner className="mt-10" />
      ) : visible.length === 0 ? (
        <Card><EmptyState>এখনো কোনো প্রোম্পট যুক্ত করা হয়নি।</EmptyState></Card>
      ) : (
        <Card>
          <Table>
            <thead>
              <tr>
                <Th>লেবেল</Th>
                <Th>ধরন</Th>
                <Th>দৃশ্যমান রোল</Th>
                <Th>কাস্টম ফিল্ড</Th>
                <Th>কর্ম</Th>
              </tr>
            </thead>
            <tbody>
              {visible.map((p) => (
                <tr key={p.id}>
                  <Td className="font-medium text-neutral-900">{p.label}</Td>
                  <Td>
                    <Badge tone={p.type === 'news' ? 'blue' : 'amber'}>{p.type === 'news' ? 'নিউজ' : 'লাইব্রেরি'}</Badge>
                  </Td>
                  <Td>
                    {p.visible_roles.length === 0 ? (
                      <span className="text-neutral-300">শুধু স্টাফ</span>
                    ) : (
                      <div className="flex gap-1 flex-wrap">
                        {p.visible_roles.map((r) => (
                          <Badge key={r} tone="neutral">{ROLE_LABELS[r] ?? r}</Badge>
                        ))}
                      </div>
                    )}
                  </Td>
                  <Td>{p.fields.length > 0 ? `${p.fields.length} টি ফিল্ড` : '—'}</Td>
                  <Td>
                    <div className="flex gap-2">
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => {
                          navigator.clipboard.writeText(p.body).catch(() => {})
                        }}
                      >
                        কপি (র-টেক্সট)
                      </Button>
                      {isPublisher && (
                        <>
                          <Button size="sm" variant="secondary" onClick={() => openEdit(p)}>
                            সম্পাদনা
                          </Button>
                          <Button size="sm" variant="danger" onClick={() => remove(p)}>
                            মুছুন
                          </Button>
                        </>
                      )}
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {showForm && (
        <Modal title={editing ? 'প্রোম্পট সম্পাদনা' : 'নতুন প্রোম্পট সেট করুন'} onClose={() => setShowForm(false)}>
          <form onSubmit={submit}>
            {formError && <Banner kind="error">{formError}</Banner>}

            <Field label="প্রোম্পটের ধরন" required>
              <div className="flex gap-3">
                <label className="flex items-center gap-1.5 text-[13px]">
                  <input type="radio" checked={type === 'news'} onChange={() => setType('news')} className="accent-brand-600" />
                  নিউজ প্রোম্পট (পোস্ট টাইটেলের পাশে, {'{title}'}/{'{body}'}/{'{summary}'}/{'{category}'} শর্টকোড)
                </label>
              </div>
              <div className="flex gap-3 mt-1.5">
                <label className="flex items-center gap-1.5 text-[13px]">
                  <input type="radio" checked={type === 'library'} onChange={() => setType('library')} className="accent-brand-600" />
                  লাইব্রেরি প্রোম্পট (নিজস্ব কাস্টম ফিল্ড, পোস্টের সাথে সম্পর্কিত নয়)
                </label>
              </div>
            </Field>

            <Field label="প্রোম্পট লেবেল" required>
              <input className={inputClass} value={label} onChange={(e) => setLabel(e.target.value)} placeholder="যেমন: সারাংশ (এ.আই)" />
            </Field>

            <Field label="দৃশ্যমান রোল" required>
              <div className="flex gap-3 flex-wrap">
                {ALL_ROLES.map((r) => (
                  <label key={r} className="flex items-center gap-1.5 text-[13px]">
                    <input
                      type="checkbox"
                      checked={visibleRoles.includes(r)}
                      onChange={() => toggleRole(r)}
                      className="accent-brand-600"
                    />
                    {ROLE_LABELS[r]}
                  </label>
                ))}
              </div>
              <p className="text-[11.5px] text-neutral-400 mt-1">
                এডিটর ও পাবলিশার সবসময় সব প্রোম্পট দেখতে পারবেন — এই সিলেকশন মূলত সংবাদদাতার জন্য প্রোম্পট সীমিত করতে ব্যবহার করুন (যেমন সারাংশ শুধু
                সংবাদদাতার জন্য)। লাইব্রেরি-প্রোম্পটের ক্ষেত্রে এটি দিয়ে কোন রোল "প্রোম্পট লাইব্রেরি"-তে এটি দেখবে তা নির্ধারিত হয়।
              </p>
            </Field>

            {type === 'library' && (
              <div className="mb-4">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-[13px] font-medium text-neutral-600">কাস্টম ফিল্ড</span>
                  <button type="button" onClick={addField} className="flex items-center gap-1 text-[12px] font-medium text-brand-600">
                    <IconPlus className="w-3.5 h-3.5" /> ফিল্ড যুক্ত করুন
                  </button>
                </div>
                {fields.length === 0 && (
                  <p className="text-[12px] text-neutral-400 mb-2">
                    যেমন রিরাইট প্রোম্পটের জন্য: "মূল নিউজ ইউআরএল/টাইটেল" ও "মূল নিউজ" — নিচে যুক্ত করুন।
                  </p>
                )}
                <div className="flex flex-col gap-3">
                  {fields.map((f, i) => (
                    <div key={i} className="rounded-xl border border-neutral-100 p-3">
                      <div className="flex items-center gap-2 mb-2">
                        <input
                          className={inputClass}
                          placeholder="শর্টকোড কী (যেমন: original_url) — ইংরেজি, সংখ্যা, আন্ডারস্কোর"
                          value={f.key}
                          onChange={(e) => updateField(i, { key: e.target.value.replace(/[^a-zA-Z0-9_]/g, '').toLowerCase() })}
                        />
                        <button type="button" onClick={() => removeField(i)} className="text-neutral-400 hover:text-red-600 shrink-0">
                          <IconX className="w-4 h-4" />
                        </button>
                      </div>
                      <input
                        className={`${inputClass} mb-2`}
                        placeholder="ফিল্ড লেবেল (যেমন: মূল নিউজ ইউআরএল/টাইটেল)"
                        value={f.label}
                        onChange={(e) => updateField(i, { label: e.target.value })}
                      />
                      <select
                        className={`${inputClass} mb-2`}
                        value={f.field_type}
                        onChange={(e) => updateField(i, { field_type: e.target.value as PromptFieldType })}
                      >
                        {(Object.keys(FIELD_TYPE_LABELS) as PromptFieldType[]).map((ft) => (
                          <option key={ft} value={ft}>{FIELD_TYPE_LABELS[ft]}</option>
                        ))}
                      </select>
                      {f.field_type === 'select' && (
                        <textarea
                          className={inputClass}
                          rows={3}
                          placeholder={'প্রিসেট অপশন — প্রতি লাইনে একটি (যেমন: আনুষ্ঠানিক\nকৌতুকপূর্ণ\nনিরপেক্ষ)'}
                          value={(f.options ?? []).join('\n')}
                          onChange={(e) => updateFieldOptions(i, e.target.value)}
                        />
                      )}
                      {f.key && (
                        <p className="text-[11px] text-neutral-400 mt-1.5">
                          প্রোম্পট টেক্সটে ব্যবহার করুন: <code className="bg-neutral-100 px-1 rounded">{`{${f.key}}`}</code>
                        </p>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            )}

            <Field label="প্রোম্পট টেক্সট" required>
              <textarea
                className={`${inputClass} font-mono text-[12.5px]`}
                rows={16}
                value={body}
                onChange={(e) => setBody(e.target.value)}
                placeholder={
                  type === 'news'
                    ? 'শর্টকোড ব্যবহার করুন: {title} {body} {summary} {category}'
                    : 'উপরে যুক্ত করা কাস্টম ফিল্ডের শর্টকোড ব্যবহার করুন, যেমন {original_url}'
                }
              />
            </Field>

            <Button type="submit" disabled={saving} className="w-full">
              {saving ? 'সংরক্ষণ হচ্ছে...' : 'সংরক্ষণ করুন'}
            </Button>
          </form>
        </Modal>
      )}
    </div>
  )
}
