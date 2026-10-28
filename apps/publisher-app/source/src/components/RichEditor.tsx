/**
 * Round 12 — the article body editor, rebuilt for phones first:
 *
 *  - Toolbar sticks to the top of the screen while scrolling a long article
 *    (under the mobile header); on a phone, while writing, a compact toolbar
 *    sits directly above the on-screen keyboard (positioned from
 *    `visualViewport`), so adding an image never means scrolling back up.
 *  - "+" block inserter on an empty line (left of the line on desktop, first
 *    button of the keyboard toolbar on phones): ছবি, গ্যালারি, হাইলাইট বক্স,
 *    উদ্ধৃতি, শিরোনাম ২/৩, তালিকা, নম্বর তালিকা, বিভাজক.
 *  - Bubble menu on selected text: বোল্ড, ইটালিক, লিংক, হাইলাইট (inline
 *    mark), হাইলাইট বক্স, শিরোনাম.
 *  - Highlight box / inline highlight (see lib/editorExtensions.ts) styled
 *    here like the live site.
 *  - Selected images can always be left (BlockEscape + trailing paragraph +
 *    the figure's own "উপরে/নিচে লিখুন" buttons).
 *  - Paste from Word / Google Docs / web is cleaned to the site's allow-list;
 *    pasted/dropped image FILES are uploaded to the media library and
 *    inserted as figures; remote images in pasted HTML are dropped (with a
 *    note).
 *  - Word/character count under the editor.
 *
 * Output HTML is unchanged for existing content (figure/figcaption markup,
 * headings, lists); new: <blockquote class="wp-block-quote …">, <mark class="m-mark">, <hr>.
 * The same component lives in correspondent-app — keep the two aligned.
 */
import { EditorContent, useEditor, useEditorState } from '@tiptap/react'
import { BubbleMenu } from '@tiptap/react/menus'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import { CharacterCount, Placeholder } from '@tiptap/extensions'
import { NodeSelection, type SelectionBookmark } from '@tiptap/pm/state'
import { useCallback, useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { MediaApi, ApiError } from '../lib/api'
import { FigureImage } from '../lib/figureImage'
import {
  BlockEscape,
  HighlightBox,
  MeehaMark,
  cleanPastedHtml,
  insertBlockAtom,
  toBnDigits,
} from '../lib/editorExtensions'
import MediaGallery from './MediaGallery'
import {
  IconBold,
  IconCheck,
  IconH2,
  IconH3,
  IconHighlight,
  IconHighlightBox,
  IconItalic,
  IconKeyboardHide,
  IconLibraryPhoto,
  IconLink,
  IconListBullet,
  IconListNumbers,
  IconPhotoPlus,
  IconPlus,
  IconQuote,
  IconRedo,
  IconSeparator,
  IconUndo,
  IconUnlink,
  IconX,
} from './Icon'

// ---------------------------------------------------------------------------

/** Phone / tablet layout: keyboard toolbar instead of the inline "+". */
function useCompact() {
  const query = '(max-width: 1023px), (pointer: coarse)'
  const [compact, setCompact] = useState(() => typeof window !== 'undefined' && window.matchMedia(query).matches)
  useEffect(() => {
    const mq = window.matchMedia(query)
    const on = () => setCompact(mq.matches)
    mq.addEventListener('change', on)
    return () => mq.removeEventListener('change', on)
  }, [])
  return compact
}

/** Pixels the on-screen keyboard (or browser chrome) covers at the bottom of the layout viewport. */
function useKeyboardInset(active: boolean) {
  const [inset, setInset] = useState(0)
  useEffect(() => {
    if (!active) return
    const vv = window.visualViewport
    if (!vv) return
    const update = () => setInset(Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop)))
    update()
    vv.addEventListener('resize', update)
    vv.addEventListener('scroll', update)
    window.addEventListener('resize', update)
    return () => {
      vv.removeEventListener('resize', update)
      vv.removeEventListener('scroll', update)
      window.removeEventListener('resize', update)
    }
  }, [active])
  return active ? inset : 0
}

// Keeps focus (and the phone keyboard) in the editor when a toolbar button is pressed.
const keepFocus = (e: React.PointerEvent | React.MouseEvent) => e.preventDefault()

function ToolButton({
  onClick,
  active,
  disabled,
  children,
  label,
  testId,
  wide,
}: {
  onClick: () => void
  active?: boolean
  disabled?: boolean
  children: ReactNode
  label: string
  testId?: string
  wide?: boolean
}) {
  return (
    <button
      type="button"
      onMouseDown={keepFocus}
      onClick={onClick}
      aria-label={label}
      title={label}
      aria-pressed={active}
      disabled={disabled}
      data-testid={testId}
      className={`re-btn ${wide ? 're-btn-wide' : ''} ${active ? 're-btn-on' : ''}`}
    >
      {children}
    </button>
  )
}

interface InsertItem {
  key: string
  label: string
  icon: ReactNode
  run: () => void
}

// ---------------------------------------------------------------------------

export default function RichEditor({
  content,
  onChange,
  placeholder = 'এখানে সংবাদের মূল লেখা শুরু করুন…',
}: {
  content: string
  onChange: (html: string) => void
  placeholder?: string
}) {
  const fileInput = useRef<HTMLInputElement>(null)
  const wrapRef = useRef<HTMLDivElement>(null)
  const bodyRef = useRef<HTMLDivElement>(null)
  const barRef = useRef<HTMLDivElement>(null)
  const [uploading, setUploading] = useState(0)
  const [error, setError] = useState('')
  const [info, setInfo] = useState('')
  const [galleryOpen, setGalleryOpen] = useState(false)
  const [insertOpen, setInsertOpen] = useState(false)
  const [linkOpen, setLinkOpen] = useState(false)
  const [linkValue, setLinkValue] = useState('')
  const [focused, setFocused] = useState(false)
  const [plusTop, setPlusTop] = useState<number | null>(null)
  const compact = useCompact()
  const kbInset = useKeyboardInset(compact && focused)
  const uploadRef = useRef<(files: File[]) => void>(() => {})

  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3] },
        // Only what the site's articles use survives (also on paste).
        code: false,
        codeBlock: false,
        strike: false,
        underline: false,
        blockquote: { HTMLAttributes: { class: 'wp-block-quote' } },
        link: { openOnClick: false, autolink: true, defaultProtocol: 'https', HTMLAttributes: { rel: 'noopener', target: null } },
        dropcursor: { color: '#025AB2', width: 3 },
        trailingNode: { node: 'paragraph' },
      }),
      // Plain images from before captions existed stay plain images…
      Image.configure({ HTMLAttributes: { class: 'rounded-lg my-2 max-w-full' } }),
      // …new ones are <figure> + editable caption (round 9 follow-up 5).
      FigureImage,
      HighlightBox,
      MeehaMark,
      BlockEscape,
      CharacterCount,
      Placeholder.configure({ placeholder }),
    ],
    content,
    onUpdate: ({ editor }) => onChange(editor.getHTML()),
    onFocus: () => setFocused(true),
    onBlur: ({ event }) => {
      const to = event.relatedTarget as globalThis.Node | null
      if (to && (wrapRef.current?.contains(to) || barRef.current?.contains(to))) return
      setFocused(false)
    },
    editorProps: {
      attributes: {
        class: 'prose-editor re-content',
        'aria-label': 'সংবাদের মূল লেখা',
        'aria-multiline': 'true',
        role: 'textbox',
        'data-testid': 'rich-editor',
      },
      // Keep the caret clear of the sticky top toolbar and the keyboard toolbar.
      scrollMargin: { top: 130, bottom: 120, left: 0, right: 0 },
      scrollThreshold: { top: 130, bottom: 120, left: 0, right: 0 },
      transformPastedHTML: (html) => {
        const res = cleanPastedHtml(html)
        if (res.droppedImages > 0) {
          setInfo(`পেস্ট করা লেখার ${toBnDigits(res.droppedImages)}টি ছবি বাদ দেওয়া হয়েছে — ছবি যোগ করতে “ছবি” বাটন ব্যবহার করুন।`)
        }
        return res.html
      },
      handlePaste: (_view, event) => {
        const files = Array.from(event.clipboardData?.files ?? []).filter((f) => f.type.startsWith('image/'))
        const html = event.clipboardData?.getData('text/html') ?? ''
        // A screenshot / copied image FILE (not an image inside copied text).
        if (files.length && !html.replace(/<img[^>]*>/gi, '').replace(/<[^>]+>/g, '').trim()) {
          uploadRef.current(files)
          return true
        }
        return false
      },
      handleDrop: (_view, event, _slice, moved) => {
        if (moved) return false
        const files = Array.from((event as DragEvent).dataTransfer?.files ?? []).filter((f) => f.type.startsWith('image/'))
        if (!files.length) return false
        event.preventDefault()
        uploadRef.current(files)
        return true
      },
    },
  })

  const st = useEditorState({
    editor,
    selector: ({ editor: e }) => {
      if (!e) return null
      const { selection } = e.state
      const { $from, empty } = selection
      const emptyLine =
        empty && !(selection instanceof NodeSelection) && $from.parent.type.name === 'paragraph' && $from.parent.content.size === 0
          ? $from.pos
          : null
      return {
        bold: e.isActive('bold'),
        italic: e.isActive('italic'),
        link: e.isActive('link'),
        mark: e.isActive('meehaMark'),
        box: e.isActive('highlightBox'),
        quote: e.isActive('blockquote'),
        h2: e.isActive('heading', { level: 2 }),
        h3: e.isActive('heading', { level: 3 }),
        ul: e.isActive('bulletList'),
        ol: e.isActive('orderedList'),
        canUndo: e.can().undo(),
        canRedo: e.can().redo(),
        words: e.storage.characterCount.words() as number,
        chars: e.storage.characterCount.characters() as number,
        emptyLine,
        hasSelection: !empty && !(selection instanceof NodeSelection),
      }
    },
  })

  // ---- images ----
  const uploadFiles = useCallback(
    async (files: File[]) => {
      if (!editor) return
      setError('')
      for (const file of files) {
        setUploading((n) => n + 1)
        try {
          const res = await MediaApi.upload(file)
          insertBlockAtom(editor, 'figureImage', { src: res.url, alt: res.alt ?? '', caption: res.caption ?? '', id: res.id })
        } catch (err) {
          setError(err instanceof ApiError ? err.message : 'ছবি আপলোড করা যায়নি।')
        } finally {
          setUploading((n) => n - 1)
        }
      }
    },
    [editor],
  )
  useEffect(() => {
    uploadRef.current = (files) => void uploadFiles(files)
  }, [uploadFiles])

  // Remembers where the caret was when the file picker / gallery opened.
  const savedSel = useRef<SelectionBookmark | null>(null)
  function rememberCaret() {
    if (editor) savedSel.current = editor.state.selection.getBookmark()
  }
  function restoreCaret() {
    if (!editor || !savedSel.current) return
    const sel = savedSel.current.resolve(editor.state.doc)
    savedSel.current = null
    editor.view.dispatch(editor.state.tr.setSelection(sel))
  }

  // ---- inline "+" position (desktop) ----
  useLayoutEffect(() => {
    if (!editor || compact || st?.emptyLine == null || !focused) {
      setPlusTop(null)
      return
    }
    try {
      const c = editor.view.coordsAtPos(st.emptyLine)
      const box = bodyRef.current?.getBoundingClientRect()
      if (!box) return
      setPlusTop(c.top - box.top + (c.bottom - c.top) / 2)
    } catch {
      setPlusTop(null)
    }
  }, [editor, compact, st?.emptyLine, focused, st?.words])

  // ---- keep the caret above the keyboard toolbar while typing on a phone ----
  // (ProseMirror only knows the layout viewport; the on-screen keyboard and
  // our toolbar cover its bottom, so scroll by what visualViewport says.)
  useEffect(() => {
    if (!editor || !compact) return
    let raf = 0
    const ensure = () => {
      cancelAnimationFrame(raf)
      raf = requestAnimationFrame(() => {
        if (!editor.view.hasFocus()) return
        try {
          const c = editor.view.coordsAtPos(editor.state.selection.head)
          const vv = window.visualViewport
          const barH = barRef.current?.offsetHeight ?? 56
          const visibleBottom = (vv ? vv.height + vv.offsetTop : window.innerHeight) - barH - 12
          const visibleTop = (vv ? vv.offsetTop : 0) + 64
          if (c.bottom > visibleBottom) window.scrollBy({ top: c.bottom - visibleBottom + 24 })
          else if (c.top < visibleTop) window.scrollBy({ top: c.top - visibleTop - 24 })
        } catch {
          /* position not rendered yet */
        }
      })
    }
    const later = () => setTimeout(ensure, 60)
    editor.on('selectionUpdate', ensure)
    editor.on('focus', later)
    window.visualViewport?.addEventListener('resize', later)
    // An inserted image grows when it finishes loading and pushes the caret down.
    const dom = editor.view.dom
    dom.addEventListener('load', later, true)
    return () => {
      dom.removeEventListener('load', later, true)
      cancelAnimationFrame(raf)
      editor.off('selectionUpdate', ensure)
      editor.off('focus', later)
      window.visualViewport?.removeEventListener('resize', later)
    }
  }, [editor, compact])

  // Closing menus with Escape.
  useEffect(() => {
    if (!insertOpen) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setInsertOpen(false)
        editor?.commands.focus()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [insertOpen, editor])

  if (!editor || !st) return null

  const chain = () => editor.chain().focus()

  function openLink() {
    if (!editor) return
    setLinkValue((editor.getAttributes('link').href as string) ?? '')
    setLinkOpen(true)
  }
  function applyLink() {
    if (!editor) return
    const v = linkValue.trim()
    if (!v) editor.chain().focus().extendMarkRange('link').unsetLink().run()
    else {
      const href = /^(https?:|mailto:|tel:)/i.test(v) ? v : `https://${v}`
      editor.chain().focus().extendMarkRange('link').setLink({ href }).run()
    }
    setLinkOpen(false)
  }

  const insertItems: InsertItem[] = [
    {
      key: 'image',
      label: 'ছবি আপলোড',
      icon: <IconPhotoPlus className="w-5 h-5" />,
      run: () => {
        rememberCaret()
        fileInput.current?.click()
      },
    },
    {
      key: 'gallery',
      label: 'গ্যালারি থেকে ছবি',
      icon: <IconLibraryPhoto className="w-5 h-5" />,
      run: () => {
        rememberCaret()
        setGalleryOpen(true)
      },
    },
    { key: 'box', label: 'হাইলাইট বক্স', icon: <IconHighlightBox className="w-5 h-5" />, run: () => chain().toggleHighlightBox().run() },
    { key: 'quote', label: 'উদ্ধৃতি', icon: <IconQuote className="w-5 h-5" />, run: () => chain().toggleBlockquote().run() },
    { key: 'h2', label: 'শিরোনাম ২', icon: <IconH2 className="w-5 h-5" />, run: () => chain().toggleHeading({ level: 2 }).run() },
    { key: 'h3', label: 'শিরোনাম ৩', icon: <IconH3 className="w-5 h-5" />, run: () => chain().toggleHeading({ level: 3 }).run() },
    { key: 'ul', label: 'তালিকা', icon: <IconListBullet className="w-5 h-5" />, run: () => chain().toggleBulletList().run() },
    { key: 'ol', label: 'নম্বর তালিকা', icon: <IconListNumbers className="w-5 h-5" />, run: () => chain().toggleOrderedList().run() },
    { key: 'hr', label: 'বিভাজক', icon: <IconSeparator className="w-5 h-5" />, run: () => chain().setHorizontalRule().run() },
  ]

  const insertMenu = (
    <div className="re-insert-menu" role="menu" aria-label="ব্লক যোগ করুন" data-testid="insert-menu">
      {insertItems.map((it) => (
        <button
          key={it.key}
          type="button"
          role="menuitem"
          onMouseDown={keepFocus}
          onClick={() => {
            setInsertOpen(false)
            it.run()
          }}
          data-testid={`insert-${it.key}`}
        >
          <span className="re-insert-icon">{it.icon}</span>
          {it.label}
        </button>
      ))}
    </div>
  )

  const mainButtons = (
    <>
      <ToolButton label="বোল্ড (Ctrl+B)" active={st.bold} onClick={() => chain().toggleBold().run()}>
        <IconBold className="w-[18px] h-[18px]" />
      </ToolButton>
      <ToolButton label="ইটালিক (Ctrl+I)" active={st.italic} onClick={() => chain().toggleItalic().run()}>
        <IconItalic className="w-[18px] h-[18px]" />
      </ToolButton>
      <ToolButton label="হাইলাইট (Ctrl+Shift+H)" active={st.mark} onClick={() => chain().toggleMeehaMark().run()} testId="tb-mark">
        <IconHighlight className="w-[18px] h-[18px]" />
      </ToolButton>
      <ToolButton label="লিংক" active={st.link} onClick={openLink}>
        <IconLink className="w-[18px] h-[18px]" />
      </ToolButton>
    </>
  )

  const blockButtons = (
    <>
      <ToolButton label="শিরোনাম ২" active={st.h2} onClick={() => chain().toggleHeading({ level: 2 }).run()}>
        <IconH2 className="w-[18px] h-[18px]" />
      </ToolButton>
      <ToolButton label="শিরোনাম ৩" active={st.h3} onClick={() => chain().toggleHeading({ level: 3 }).run()}>
        <IconH3 className="w-[18px] h-[18px]" />
      </ToolButton>
      <ToolButton label="হাইলাইট বক্স" active={st.box} onClick={() => chain().toggleHighlightBox().run()} testId="tb-box">
        <IconHighlightBox className="w-[18px] h-[18px]" />
      </ToolButton>
      <ToolButton label="উদ্ধৃতি" active={st.quote} onClick={() => chain().toggleBlockquote().run()}>
        <IconQuote className="w-[18px] h-[18px]" />
      </ToolButton>
      <ToolButton label="তালিকা" active={st.ul} onClick={() => chain().toggleBulletList().run()}>
        <IconListBullet className="w-[18px] h-[18px]" />
      </ToolButton>
      <ToolButton label="নম্বর তালিকা" active={st.ol} onClick={() => chain().toggleOrderedList().run()}>
        <IconListNumbers className="w-[18px] h-[18px]" />
      </ToolButton>
    </>
  )

  const imageButtons = (
    <>
      <ToolButton
        label="ছবি আপলোড করুন"
        onClick={() => {
          rememberCaret()
          fileInput.current?.click()
        }}
        testId="tb-upload"
      >
        <IconPhotoPlus className="w-[18px] h-[18px]" />
      </ToolButton>
      <ToolButton
        label="গ্যালারি থেকে ছবি"
        onClick={() => {
          rememberCaret()
          setGalleryOpen(true)
        }}
        testId="tb-gallery"
      >
        <IconLibraryPhoto className="w-[18px] h-[18px]" />
      </ToolButton>
    </>
  )

  const historyButtons = (
    <>
      <ToolButton label="আন্ডু (Ctrl+Z)" disabled={!st.canUndo} onClick={() => chain().undo().run()}>
        <IconUndo className="w-[18px] h-[18px]" />
      </ToolButton>
      <ToolButton label="রিডু (Ctrl+Shift+Z)" disabled={!st.canRedo} onClick={() => chain().redo().run()}>
        <IconRedo className="w-[18px] h-[18px]" />
      </ToolButton>
    </>
  )

  const showKeyboardBar = compact && focused
  const linkPanel = linkOpen && (
    <div className="re-link" role="dialog" aria-label="লিংক যোগ করুন" data-testid="link-panel">
      <input
        autoFocus
        type="url"
        inputMode="url"
        dir="ltr"
        value={linkValue}
        placeholder="https://…"
        onChange={(e) => setLinkValue(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault()
            applyLink()
          } else if (e.key === 'Escape') {
            setLinkOpen(false)
            editor.commands.focus()
          }
        }}
        aria-label="লিংকের ঠিকানা"
      />
      <button type="button" onClick={applyLink} aria-label="লিংক প্রয়োগ করুন" className="re-link-ok">
        <IconCheck className="w-4 h-4" />
      </button>
      {st.link && (
        <button
          type="button"
          aria-label="লিংক সরান"
          onClick={() => {
            editor.chain().focus().extendMarkRange('link').unsetLink().run()
            setLinkOpen(false)
          }}
        >
          <IconUnlink className="w-4 h-4" />
        </button>
      )}
      <button
        type="button"
        aria-label="বন্ধ করুন"
        onClick={() => {
          setLinkOpen(false)
          editor.commands.focus()
        }}
      >
        <IconX className="w-4 h-4" />
      </button>
    </div>
  )

  return (
    <div ref={wrapRef} className="rich-editor" data-compact={compact ? '1' : '0'} data-focused={focused ? '1' : '0'}>
      {/* Sticky top toolbar (static while the phone keyboard toolbar is showing). */}
      <div className={`re-toolbar ${showKeyboardBar ? 're-toolbar-static' : ''}`} role="toolbar" aria-label="লেখার টুলবার" data-testid="editor-toolbar">
        {mainButtons}
        <span className="re-sep" />
        {blockButtons}
        <span className="re-sep" />
        {imageButtons}
        <ToolButton label="বিভাজক" onClick={() => chain().setHorizontalRule().run()}>
          <IconSeparator className="w-[18px] h-[18px]" />
        </ToolButton>
        <span className="re-sep" />
        {historyButtons}
      </div>
      {linkOpen && !showKeyboardBar && <div className="re-link-wrap">{linkPanel}</div>}

      <input
        ref={fileInput}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        multiple
        className="hidden"
        data-testid="editor-image-input"
        onChange={(e) => {
          const files = Array.from(e.target.files ?? [])
          e.target.value = ''
          if (files.length) {
            restoreCaret()
            void uploadFiles(files)
          }
        }}
      />

      {uploading > 0 && <p className="px-4 pt-2 text-[12.5px] text-blue-700">ছবি আপলোড হচ্ছে…</p>}
      {error && <p className="px-4 pt-2 text-[12.5px] text-red-600">{error}</p>}
      {info && (
        <p className="px-4 pt-2 text-[12.5px] text-amber-700 flex items-start gap-2" data-testid="editor-info">
          <span className="flex-1">{info}</span>
          <button type="button" className="text-neutral-400" aria-label="বন্ধ করুন" onClick={() => setInfo('')}>
            <IconX className="w-4 h-4" />
          </button>
        </p>
      )}

      <div ref={bodyRef} className="re-body" onClick={(e) => {
        // Tapping the empty area under the last block puts the caret at the end.
        if (e.target === bodyRef.current) editor.commands.focus('end')
      }}>
        <EditorContent editor={editor} />
        {plusTop != null && (
          <div className="re-plus-wrap" style={{ top: plusTop }}>
            <button
              type="button"
              className={`re-plus ${insertOpen ? 're-plus-open' : ''}`}
              aria-label="ব্লক যোগ করুন"
              aria-expanded={insertOpen}
              data-testid="block-plus"
              onMouseDown={keepFocus}
              onClick={() => setInsertOpen((v) => !v)}
            >
              <IconPlus className="w-4 h-4" />
            </button>
            {insertOpen && <div className="re-insert-pop">{insertMenu}</div>}
          </div>
        )}
      </div>

      <div className="re-footer" data-testid="word-count">
        <span>শব্দ: {toBnDigits(st.words)}</span>
        <span>অক্ষর: {toBnDigits(st.chars)}</span>
      </div>

      <BubbleMenu
        editor={editor}
        options={{ placement: compact ? 'bottom' : 'top', offset: 8, flip: true, shift: { padding: 8 } }}
        shouldShow={({ editor: e, state }) => {
          const sel = state.selection
          if (sel.empty || sel instanceof NodeSelection || !e.isEditable || !e.view.hasFocus()) return false
          return state.doc.textBetween(sel.from, sel.to, ' ').trim().length > 0
        }}
      >
        <div className="re-bubble" data-testid="bubble-menu">
          {mainButtons}
          <ToolButton label="হাইলাইট বক্স" active={st.box} onClick={() => chain().toggleHighlightBox().run()} testId="bubble-box">
            <IconHighlightBox className="w-[18px] h-[18px]" />
          </ToolButton>
          <ToolButton label="শিরোনাম" active={st.h2} onClick={() => chain().toggleHeading({ level: 2 }).run()}>
            <IconH2 className="w-[18px] h-[18px]" />
          </ToolButton>
        </div>
      </BubbleMenu>

      {showKeyboardBar &&
        createPortal(
          <div
            ref={barRef}
            className="re-kbbar"
            style={{ bottom: kbInset }}
            role="toolbar"
            aria-label="লেখার টুলবার (কিবোর্ড)"
            data-testid="keyboard-toolbar"
            onMouseDown={keepFocus}
          >
            {insertOpen && <div className="re-kbsheet">{insertMenu}</div>}
            {linkOpen && <div className="re-kbsheet">{linkPanel}</div>}
            <div className="re-kbrow">
              <ToolButton label="ব্লক যোগ করুন" active={insertOpen} onClick={() => setInsertOpen((v) => !v)} testId="kb-plus">
                <IconPlus className="w-5 h-5" />
              </ToolButton>
              {imageButtons}
              {mainButtons}
              <ToolButton label="হাইলাইট বক্স" active={st.box} onClick={() => chain().toggleHighlightBox().run()} testId="kb-box">
                <IconHighlightBox className="w-[18px] h-[18px]" />
              </ToolButton>
              <ToolButton label="শিরোনাম ২" active={st.h2} onClick={() => chain().toggleHeading({ level: 2 }).run()}>
                <IconH2 className="w-[18px] h-[18px]" />
              </ToolButton>
              {historyButtons}
              <ToolButton
                label="কিবোর্ড বন্ধ করুন"
                onClick={() => {
                  setInsertOpen(false)
                  editor.commands.blur()
                  setFocused(false)
                }}
              >
                <IconKeyboardHide className="w-5 h-5" />
              </ToolButton>
            </div>
          </div>,
          document.body,
        )}

      {galleryOpen && (
        <MediaGallery
          title="সংবাদে ছবি যুক্ত করুন — গ্যালারি থেকে"
          onClose={() => {
            setGalleryOpen(false)
            restoreCaret()
          }}
          onSelect={(img) => {
            setGalleryOpen(false)
            restoreCaret()
            insertBlockAtom(editor, 'figureImage', { src: img.url, alt: img.alt, caption: img.caption, id: img.id })
          }}
        />
      )}
    </div>
  )
}

