import { useEffect, useState } from 'react'
import { PromptsApi, type Prompt } from '../lib/api'
import { fillPromptTemplate } from '../lib/promptTemplate'
import { Banner, Card, EmptyState, PageHeader, Spinner, inputClass } from '../components/ui'
import { IconSparkles } from '../components/Icon'

// Round 9 — "প্রোম্পট লাইব্রেরি": library-type prompts (not tied to any open
// post) rendered as fillable forms. The Publisher decides, per prompt,
// which roles see it here via visible_roles — the backend (GET /prompts)
// already filters to what this viewer is allowed to see, so everything
// returned here is shown.
export default function PromptLibrary() {
  const [items, setItems] = useState<Prompt[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [values, setValues] = useState<Record<number, Record<string, string>>>({})
  const [copiedId, setCopiedId] = useState<number | null>(null)

  useEffect(() => {
    PromptsApi.listForViewer('library')
      .then(setItems)
      .catch((e) => setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।'))
      .finally(() => setLoading(false))
  }, [])

  function setFieldValue(promptId: number, key: string, value: string) {
    setValues((prev) => ({ ...prev, [promptId]: { ...prev[promptId], [key]: value } }))
  }

  async function copy(p: Prompt) {
    setCopiedId(null)
    const text = fillPromptTemplate(p.body, values[p.id] ?? {})
    try {
      await navigator.clipboard.writeText(text)
      setCopiedId(p.id)
      setTimeout(() => setCopiedId((id) => (id === p.id ? null : id)), 2500)
    } catch {
      setError('কপি করা যায়নি — ব্রাউজারকে ক্লিপবোর্ড অনুমতি দিন।')
    }
  }

  return (
    <div>
      <PageHeader title="প্রোম্পট লাইব্রেরি" subtitle="নির্দিষ্ট কাজের জন্য প্রস্তুত প্রোম্পট — ফিল্ড পূরণ করে কপি করুন" />
      {error && <Banner kind="error">{error}</Banner>}

      {loading ? (
        <Spinner className="mt-10" />
      ) : items.length === 0 ? (
        <Card><EmptyState>এখনো কোনো লাইব্রেরি প্রোম্পট আপনার জন্য যুক্ত করা হয়নি।</EmptyState></Card>
      ) : (
        <div className="grid md:grid-cols-2 gap-4">
          {items.map((p) => (
            <Card key={p.id} className="p-5">
              <p className="text-[14px] font-semibold text-neutral-800 mb-3 flex items-center gap-1.5">
                <IconSparkles className="w-4 h-4 text-brand-600" />
                {p.label}
              </p>

              <div className="flex flex-col gap-3 mb-3">
                {p.fields.map((f) => (
                  <label key={f.key} className="block">
                    <span className="block text-[12.5px] font-medium text-neutral-600 mb-1">{f.label}</span>
                    {f.field_type === 'textarea' ? (
                      <textarea
                        className={inputClass}
                        rows={4}
                        value={values[p.id]?.[f.key] ?? ''}
                        onChange={(e) => setFieldValue(p.id, f.key, e.target.value)}
                      />
                    ) : f.field_type === 'select' ? (
                      <select
                        className={inputClass}
                        value={values[p.id]?.[f.key] ?? ''}
                        onChange={(e) => setFieldValue(p.id, f.key, e.target.value)}
                      >
                        <option value="">— নির্বাচন করুন —</option>
                        {(f.options ?? []).map((opt) => (
                          <option key={opt} value={opt}>{opt}</option>
                        ))}
                      </select>
                    ) : (
                      <input
                        className={inputClass}
                        value={values[p.id]?.[f.key] ?? ''}
                        onChange={(e) => setFieldValue(p.id, f.key, e.target.value)}
                      />
                    )}
                  </label>
                ))}
              </div>

              <button
                type="button"
                onClick={() => copy(p)}
                className="w-full text-center text-[13px] font-semibold text-white bg-brand-600 rounded-xl py-2.5"
              >
                {copiedId === p.id ? 'কপি হয়েছে ✓' : 'প্রোম্পট কপি করুন'}
              </button>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}
