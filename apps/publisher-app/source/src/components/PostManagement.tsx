/**
 * Round 10 — post/video management UI shared by PostEditor, VideoEditor,
 * Posts and Videos: the "প্রকাশের তথ্য" card (date-time + author + danger
 * zone), the delete confirmation dialog and a small row overflow menu.
 * Every control is gated by the viewer's effective profile.can.* flag; a
 * control the viewer may not use stays visible but disabled, with a hint.
 */
import { useCallback, useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { describeError } from '../lib/api'
import { NEED_PUBLISHER_PERMISSION, bnDateTime, inputToWpDate, isFutureSiteTime, siteNowInput } from '../lib/postMeta'
import AuthorPicker, { type AuthorValue } from './AuthorPicker'
import { Button, Card, Modal, inputClass } from './ui'
import { IconCalendarClock, IconDotsVertical, IconLock, IconTrash } from './Icon'
import type { AuthorOption } from '../lib/api'

export function PermissionHint() {
  return (
    <p className="flex items-center gap-1 text-[11.5px] text-neutral-400 mt-1.5">
      <IconLock className="w-3.5 h-3.5" />
      {NEED_PUBLISHER_PERMISSION}
    </p>
  )
}

type Kind = 'post' | 'video'
const NOUN: Record<Kind, string> = { post: 'পোস্ট', video: 'ভিডিও' }

export function PublishInfoCard({
  kind,
  isNew,
  date,
  onDateChange,
  canChangeDate,
  author,
  onAuthorChange,
  canChangeAuthor,
  metaDirty,
  savingMeta,
  onSaveMeta,
  canDelete,
  onDeleteClick,
  floating,
  authorExtra,
}: {
  kind: Kind
  isNew: boolean
  // Round 13 — no publish date chosen yet (new, or draft/pending with a
  // floating date): an empty field means "the moment it is published".
  floating?: boolean
  // Round 13 — rendered under the author picker (the guest-author control).
  authorExtra?: ReactNode
  date: string // datetime-local value ('' = not set)
  onDateChange: (value: string) => void
  canChangeDate: boolean
  author: AuthorValue | null
  onAuthorChange: (a: AuthorOption) => void
  canChangeAuthor: boolean
  metaDirty?: boolean
  savingMeta?: boolean
  onSaveMeta?: () => void
  canDelete: boolean
  onDeleteClick?: () => void
}) {
  const noun = NOUN[kind]
  const future = isFutureSiteTime(date)

  return (
    <Card className="p-5" >
      <p className="text-[13px] font-semibold text-neutral-700 mb-3 flex items-center gap-1.5">
        <IconCalendarClock className="w-4 h-4 text-neutral-400" />
        প্রকাশের তথ্য
      </p>

      <div className="mb-4">
        <div className="flex items-center justify-between mb-1.5">
          <label htmlFor={`${kind}-date`} className="text-[12.5px] font-medium text-neutral-600">
            তারিখ ও সময়
          </label>
          {canChangeDate && (
            <span className="flex items-center gap-3">
              {(floating ?? isNew) && date && (
                <button type="button" onClick={() => onDateChange('')} className="text-[11.5px] font-medium text-neutral-500" data-testid="publish-date-clear">
                  খালি করুন
                </button>
              )}
              <button type="button" onClick={() => onDateChange(siteNowInput())} className="text-[11.5px] font-medium text-brand-600">
                এখনকার সময়
              </button>
            </span>
          )}
        </div>
        <input
          id={`${kind}-date`}
          type="datetime-local"
          data-testid="publish-date"
          className={`${inputClass} disabled:bg-neutral-50 disabled:text-neutral-500`}
          value={date}
          disabled={!canChangeDate}
          onChange={(e) => onDateChange(e.target.value)}
        />
        {canChangeDate ? (
          <>
            <p className="text-[11.5px] text-neutral-400 mt-1.5 leading-relaxed">
              সাইটের সময় (বাংলাদেশ)। ভবিষ্যতের সময় দিলে {noun}টি নির্ধারিত সময়ে প্রকাশ হবে।
              {(floating ?? isNew) && !date && ' খালি রাখলে প্রকাশের সময়ই তারিখ হবে।'}
            </p>
            {future && (
              <p className="mt-2 inline-flex items-center gap-1 rounded-full bg-blue-50 text-blue-700 text-[11.5px] font-medium px-2 py-0.5">
                নির্ধারিত প্রকাশ: {bnDateTime(inputToWpDate(date))}
              </p>
            )}
          </>
        ) : (
          <PermissionHint />
        )}
      </div>

      <div>
        <p className="text-[12.5px] font-medium text-neutral-600 mb-1.5">লেখক</p>
        <AuthorPicker value={author} onChange={onAuthorChange} disabled={!canChangeAuthor} />
        {!canChangeAuthor && <PermissionHint />}
        {authorExtra}
      </div>

      {!isNew && onSaveMeta && (canChangeDate || canChangeAuthor) && (
        <div className="mt-3">
          <Button variant="secondary" size="sm" className="w-full" disabled={!metaDirty || savingMeta} onClick={onSaveMeta}>
            {savingMeta ? 'সংরক্ষণ হচ্ছে…' : 'সময় ও লেখক সংরক্ষণ করুন'}
          </Button>
          <p className="text-[11px] text-neutral-400 mt-1 text-center">অথবা মূল সংরক্ষণ বাটনের সাথেও সংরক্ষিত হবে।</p>
        </div>
      )}

      {!isNew && (
        <div className="mt-5 pt-4 border-t border-dashed border-red-100">
          <p className="text-[12.5px] font-semibold text-red-700 mb-1">বিপজ্জনক এলাকা</p>
          <p className="text-[11.5px] text-neutral-500 mb-2.5">{noun}টি ট্র্যাশে সরানো হবে — wp-admin থেকে আবার ফিরিয়ে আনা যাবে।</p>
          <Button variant="danger" size="sm" className="w-full" disabled={!canDelete} onClick={onDeleteClick}>
            <IconTrash className="w-4 h-4" />
            {noun} ডিলিট করুন
          </Button>
          {!canDelete && <PermissionHint />}
        </div>
      )}
    </Card>
  )
}

export function DeleteConfirmModal({
  kind,
  title,
  onConfirm,
  onClose,
}: {
  kind: Kind
  title: string
  onConfirm: () => Promise<void>
  onClose: () => void
}) {
  const noun = NOUN[kind]
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function confirm() {
    setBusy(true)
    setError('')
    try {
      await onConfirm()
    } catch (err) {
      setError(describeError(err).message)
      setBusy(false)
    }
  }

  return (
    <Modal title={`${noun} ডিলিট করবেন?`} onClose={busy ? () => {} : onClose}>
      <div className="flex gap-3 mb-4">
        <div className="w-10 h-10 rounded-full bg-red-50 text-red-600 flex items-center justify-center shrink-0">
          <IconTrash className="w-5 h-5" />
        </div>
        <div className="min-w-0">
          <p className="text-[13.5px] font-semibold text-neutral-900 break-words">“{title || 'শিরোনামহীন'}”</p>
          <p className="text-[12.5px] text-neutral-500 mt-1 leading-relaxed">
            {noun}টি সাইট থেকে সরিয়ে WordPress-এর ট্র্যাশে রাখা হবে। প্রয়োজনে wp-admin → ট্র্যাশ থেকে আবার ফিরিয়ে আনা (Restore) যাবে।
          </p>
        </div>
      </div>
      {error && <p className="text-[12.5px] text-red-600 mb-3">{error}</p>}
      <div className="flex gap-2 justify-end">
        <Button variant="secondary" onClick={onClose} disabled={busy}>
          বাতিল
        </Button>
        <Button variant="destructive" onClick={confirm} disabled={busy}>
          <IconTrash className="w-4 h-4" />
          {busy ? 'ডিলিট হচ্ছে…' : 'হ্যাঁ, ডিলিট করুন'}
        </Button>
      </div>
    </Modal>
  )
}

export interface MenuItem {
  label: string
  icon?: ReactNode
  onClick?: () => void
  href?: string
  danger?: boolean
  disabled?: boolean
  hint?: string
}

/**
 * Overflow ("⋮") menu for a table row / card. Rendered with fixed
 * positioning so a table's horizontal-scroll container can't clip it.
 */
export function RowMenu({ items, label = 'আরও অপশন' }: { items: MenuItem[]; label?: string }) {
  const btnRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState<{ top: number; left: number } | null>(null)

  // Anchored to the button; follows it on scroll/resize (closes once the
  // button itself has scrolled out of view).
  const place = useCallback(() => {
    const btn = btnRef.current
    if (!btn) return
    const r = btn.getBoundingClientRect()
    if (r.bottom < 0 || r.top > window.innerHeight) {
      setOpen(false)
      return
    }
    const width = 224
    const menuH = menuRef.current?.offsetHeight ?? 120
    const below = r.bottom + 6 + menuH < window.innerHeight
    setPos({
      top: below ? r.bottom + 6 : Math.max(8, r.top - 6 - menuH),
      left: Math.max(8, Math.min(window.innerWidth - width - 8, r.right - width)),
    })
  }, [])

  useLayoutEffect(() => {
    if (open) place()
  }, [open, place])

  useEffect(() => {
    if (!open) return
    const close = () => setOpen(false)
    const onDown = (e: MouseEvent) => {
      const t = e.target as Node
      if (!menuRef.current?.contains(t) && !btnRef.current?.contains(t)) close()
    }
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && close()
    document.addEventListener('mousedown', onDown)
    window.addEventListener('keydown', onKey)
    window.addEventListener('resize', place)
    window.addEventListener('scroll', place, true)
    return () => {
      document.removeEventListener('mousedown', onDown)
      window.removeEventListener('keydown', onKey)
      window.removeEventListener('resize', place)
      window.removeEventListener('scroll', place, true)
    }
  }, [open, place])

  return (
    <>
      <button
        ref={btnRef}
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className={`w-8 h-8 rounded-lg flex items-center justify-center text-neutral-500 hover:bg-neutral-100 ${open ? 'bg-neutral-100' : ''}`}
      >
        <IconDotsVertical className="w-4 h-4" />
      </button>
      {open && (
        <div
          ref={menuRef}
          role="menu"
          style={{ top: pos?.top ?? -9999, left: pos?.left ?? -9999, width: 224 }}
          className="fixed z-50 rounded-xl border border-neutral-200 bg-white shadow-lg py-1"
        >
          {items.map((item) => {
            const cls = `w-full flex items-start gap-2 px-3 py-2 text-left text-[13px] ${
              item.disabled ? 'text-neutral-300 cursor-not-allowed' : item.danger ? 'text-red-600 hover:bg-red-50' : 'text-neutral-700 hover:bg-neutral-50'
            }`
            const body = (
              <>
                {item.icon && <span className="mt-0.5 shrink-0">{item.icon}</span>}
                <span className="min-w-0">
                  <span className="block">{item.label}</span>
                  {item.hint && <span className="block text-[11px] text-neutral-400">{item.hint}</span>}
                </span>
              </>
            )
            return item.href && !item.disabled ? (
              <a key={item.label} role="menuitem" href={item.href} target="_blank" rel="noreferrer" className={cls} onClick={() => setOpen(false)}>
                {body}
              </a>
            ) : (
              <button
                key={item.label}
                role="menuitem"
                type="button"
                disabled={item.disabled}
                className={cls}
                onClick={() => {
                  setOpen(false)
                  item.onClick?.()
                }}
              >
                {body}
              </button>
            )
          })}
        </div>
      )}
    </>
  )
}
