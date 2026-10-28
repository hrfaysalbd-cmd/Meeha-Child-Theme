import { useEffect, useState } from 'react'
import { UsersApi, type StaffUser } from '../lib/api'
import { Badge, Banner, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, Table, Td, Th, inputClass } from '../components/ui'
import { IconPlus } from '../components/Icon'

export default function Users() {
  const [items, setItems] = useState<StaffUser[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [saving, setSaving] = useState(false)

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState<'editor' | 'meeha_publisher'>('editor')

  function load() {
    setLoading(true)
    UsersApi.list()
      .then(setItems)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }

  useEffect(load, [])

  async function createUser(e: React.FormEvent) {
    e.preventDefault()
    setSaving(true)
    try {
      await UsersApi.create({ name, email, role })
      setShowCreate(false)
      setName(''); setEmail(''); setRole('editor')
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    } finally {
      setSaving(false)
    }
  }

  async function changeRole(user: StaffUser, nextRole: 'editor' | 'meeha_publisher') {
    try {
      await UsersApi.setRole(user.id, nextRole)
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'সমস্যা হয়েছে।')
    }
  }

  function roleOf(u: StaffUser) {
    if (u.roles.includes('administrator')) return 'administrator'
    if (u.roles.includes('meeha_publisher')) return 'meeha_publisher'
    return 'editor'
  }

  return (
    <div>
      <PageHeader
        title="ইউজার ও রোল"
        subtitle="এডিটর ও পাবলিশার একাউন্ট তৈরি ও পরিচালনা করুন — শুধুমাত্র পাবলিশারের জন্য"
        actions={
          <Button onClick={() => setShowCreate(true)}>
            <IconPlus className="w-4 h-4" /> নতুন ইউজার
          </Button>
        }
      />
      {error && <Banner kind="error">{error}</Banner>}

      {loading ? (
        <Spinner className="mt-10" />
      ) : items.length === 0 ? (
        <Card><EmptyState>কোনো ইউজার পাওয়া যায়নি।</EmptyState></Card>
      ) : (
        <Card>
          <Table>
            <thead>
              <tr>
                <Th>নাম</Th>
                <Th>ইমেইল</Th>
                <Th>রোল</Th>
                <Th>কর্ম</Th>
              </tr>
            </thead>
            <tbody>
              {items.map((u) => {
                const r = roleOf(u)
                return (
                  <tr key={u.id}>
                    <Td className="font-medium text-neutral-900">{u.name}</Td>
                    <Td>{u.email}</Td>
                    <Td>
                      <Badge tone={r === 'administrator' ? 'blue' : r === 'meeha_publisher' ? 'red' : 'neutral'}>
                        {r === 'administrator' ? 'অ্যাডমিনিস্ট্রেটর (IT/Dev)' : r === 'meeha_publisher' ? 'পাবলিশার' : 'এডিটর'}
                      </Badge>
                    </Td>
                    <Td>
                      {r === 'administrator' ? (
                        '—'
                      ) : r === 'editor' ? (
                        <Button size="sm" variant="secondary" onClick={() => changeRole(u, 'meeha_publisher')}>
                          পাবলিশার করুন
                        </Button>
                      ) : (
                        <Button size="sm" variant="secondary" onClick={() => changeRole(u, 'editor')}>
                          এডিটর করুন
                        </Button>
                      )}
                    </Td>
                  </tr>
                )
              })}
            </tbody>
          </Table>
        </Card>
      )}

      {showCreate && (
        <Modal title="নতুন ইউজার তৈরি করুন" onClose={() => setShowCreate(false)}>
          <form onSubmit={createUser}>
            <Field label="নাম" required>
              <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} required />
            </Field>
            <Field label="ইমেইল" required>
              <input type="email" className={inputClass} value={email} onChange={(e) => setEmail(e.target.value)} required />
            </Field>
            <Field label="রোল" required>
              <select className={inputClass} value={role} onChange={(e) => setRole(e.target.value as typeof role)}>
                <option value="editor">এডিটর</option>
                <option value="meeha_publisher">পাবলিশার</option>
              </select>
            </Field>
            <p className="text-[12px] text-neutral-400 mb-3">একটি পাসওয়ার্ড স্বয়ংক্রিয়ভাবে তৈরি হয়ে ইমেইলে পাঠানো হবে।</p>
            <Button type="submit" disabled={saving} className="w-full">তৈরি করুন</Button>
          </form>
        </Modal>
      )}
    </div>
  )
}
