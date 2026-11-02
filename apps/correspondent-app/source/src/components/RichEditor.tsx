/**
 * The article body editor (Tiptap 3). Round 12 overhaul — mobile first:
 *
 *  - phones (coarse pointer): one compact toolbar docked at the BOTTOM of the
 *    editor, which NewsEditorOverlay keeps above the on-screen keyboard
 *    (visualViewport), so every tool is reachable at the writing position;
 *  - desktop: the same toolbar sticky at the top, plus a "+" in the left
 *    gutter of an empty line;
 *  - "+" block inserter: ছবি, হাইলাইট বক্স, উদ্ধৃতি, শিরোনাম ২/৩, তালিকা,
 *    নম্বর তালিকা, বিভাজক;
 *  - selection bubble: বোল্ড, ইটালিক, লিংক, হাইলাইট, হাইলাইট বক্স, শিরোনাম;
 *  - a selected image never traps the caret (see EditorBehaviour + the
 *    figure's own "ছবির নিচে লিখুন" button), trailing paragraph, gap cursor;
 *  - paste from Word / Google Docs / web is cleaned to the allowed marks,
 *    pasted remote images are dropped (with a hint), pasted image FILES are
 *    uploaded like picked ones.
 * Output HTML is unchanged WordPress markup (figure/figcaption as before,
 * <mark class="m-mark">, <blockquote class="wp-block-quote is-style-meeha-highlight">).
 */
import { EditorContent, useEditor, useEditorState, type Editor } from '@tiptap/react'
import { BubbleMenu } from '@tiptap/react/menus'
import StarterKit from '@tiptap/starter-kit'
import Image from '@tiptap/extension-image'
import Placeholder from '@tiptap/extension-placeholder'
import { NodeSelection } from '@tiptap/pm/state'
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { MediaApi, ApiError } from '../lib/api'
import { FigureImage } from '../lib/figureImage'
import { EditorBehaviour, HighlightBox, MeehaMark, caretAfter, countText, isInNode } from '../lib/editorExtensions'
import {
  IconBold,
  IconBox,
  IconH2,
  IconH3,
  IconHighlight,
  IconItalic,
  IconLink,
  IconLinkOff,
  IconListBullet,
  IconListNumbers,
  IconPhotoPlus,
  IconPlus,
  IconQuote,
  IconRedo,
  IconSeparator,
  IconUndo,
  IconX,
} from './Icon'

export interface EditorStats {
  words: number
  chars: number
  uploading: number
}

const ICON = 'w-5 h-5'

function useCoarsePointer() {
  const q = '(pointer: coarse)'
  const [coarse, setCoarse] = useState(() => typeof window !== 'undefined' && window.matchMedia(q).matches)
  useEffect(() => {
    const m = window.matchMedia(q)
    const on = () => setCoarse(m.matches)
    m.addEventListener('change', on)
    return () => m.removeEventListener('change', on)
  }, [])
  return coarse
}

// Keeps the editor focused (and the phone keyboard open) when a tool is tapped.
const keepFocus = (e: React.MouseEvent) => e.preventDefault()

function ToolButton({
  onClick,
  active,
  disabled,
  children,
  label,
  testId,
}: {
  onClick: () => void
  active?: boolean
  disabled?: boolean
  children: React.ReactNode
  label: string
  testId?: string
}) {
  return (
    <button
      type="button"
      onMouseDown={keepFocus}
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      title={label}
      aria-pressed={active ?? undefined}
      data-testid={testId}
      className={`w-11 h-11 shrink-0 flex items-center justify-center rounded-lg transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#025AB2] disabled:opacity-35 ${
        active ? 'bg-[#025AB2] text-white' : 'text-neutral-700 active:bg-neutral-200'
      }`}
    >
      {children}
    </button>
  )
}

type InsertKind = 'image' | 'box' | 'quote' | 'h2' | 'h3' | 'bullet' | 'ordered' | 'hr'

const INSERT_ITEMS: Array<{ kind: InsertKind; label: string; icon: (p: React.SVGProps<SVGSVGElement>) => React.ReactElement }> = [
  { kind: 'image', label: 'ছবি', icon: IconPhotoPlus },
  { kind: 'box', label: 'হাইলাইট বক্স', icon: IconBox },
  { kind: 'quote', label: 'উদ্ধৃতি', icon: IconQuote },
  { kind: 'h2', label: 'শিরোনাম ২', icon: IconH2 },
  { kind: 'h3', label: 'শিরোনাম ৩', icon: IconH3 },
  { kind: 'bullet', label: 'তালিকা', icon: IconListBullet },
  { kind: 'ordered', label: 'নম্বর তালিকা', icon: IconListNumbers },
  { kind: 'hr', label: 'বিভাজক', icon: IconSeparator },
]

function normalizeUrl(raw: string) {
  const v = raw.trim()
  if (!v) return ''
  if (/^(https?:|mailto:|tel:)/i.test(v)) return v
  return `https://${v.replace(/^\/+/, '')}`
}

export default function RichEditor({
  content,
  onChange,
  onStats,
}: {
  content: string
  onChange: (html: string) => void
  onStats?: (s: EditorStats) => void
}) {
  const coarse = useCoarsePointer()
  const fileInput = useRef<HTMLInputElement>(null)
  const scrollRef = useRef<HTMLDivElement>(null)
  const linkInputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(0)
  const [error, setError] = useState('')
  const [hint, setHint] = useState('')
  const [insertOpen, setInsertOpen] = useState(false)
  const [linkOpen, setLinkOpen] = useState(false)
  const [linkValue, setLinkValue] = useState('')
  const [plusTop, setPlusTop] = useState<number | null>(null)
  const [menuTop, setMenuTop] = useState(8)
  const uploadSeq = useRef(0)
  const editorRef = useRef<Editor | null>(null)
  const uploadFilesRef = useRef<(files: File[]) => void>(() => {})

  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3] },
        // Only the formatting the site uses (contract: bold, italic, link,
        // h2/h3, lists, blockquote, highlight) — pasted code/strike/underline
        // fall back to plain text.
        code: false,
        codeBlock: false,
        strike: false,
        underline: false,
        link: { openOnClick: false, autolink: true, defaultProtocol: 'https', HTMLAttributes: { rel: 'noopener noreferrer', target: null } },
        dropcursor: { color: '#025AB2', width: 3 },
      }),
      // Plain images from before captions existed stay plain images…
      Image.configure({ HTMLAttributes: { class: 'rounded-lg my-2 max-w-full' } }),
      // …new ones are <figure> + editable caption (round 9 follow-up 5).
      FigureImage,
      MeehaMark,
      HighlightBox,
      EditorBehaviour.configure({
        onDroppedImages: (n) =>
          setHint(`পেস্ট করা লেখার ${n.toLocaleString('bn-BD')}টি ছবি বাদ দেওয়া হয়েছে — ছবি দিতে "+" → ছবি চেপে আপলোড করুন।`),
        onImageFiles: (files) => uploadFilesRef.current(files),
      }),
      Placeholder.configure({ placeholder: 'এখানে সংবাদের মূল লেখা শুরু করুন…' }),
    ],
    content,
    onUpdate: ({ editor }) => onChange(editor.getHTML()),
    editorProps: {
      attributes: {
        class:
          'prose-editor min-h-[50vh] py-4 pr-4 text-[16px] leading-relaxed text-neutral-900 focus:outline-none',
        'aria-label': 'সংবাদের মূল লেখা',
        role: 'textbox',
        'aria-multiline': 'true',
        spellcheck: 'false',
      },
      scrollThreshold: 96,
      scrollMargin: 96,
    },
  })
  useEffect(() => {
    editorRef.current = editor
  }, [editor])

  const ui = useEditorState({
    editor,
    selector: ({ editor: e }) => {
      if (!e) return null
      const { selection } = e.state
      const parent = selection.$from.parent
      const emptyLine =
        selection.empty && parent.type.name === 'paragraph' && parent.content.size === 0 && selection.$from.depth === 1
      return {
        bold: e.isActive('bold'),
        italic: e.isActive('italic'),
        mark: e.isActive('meehaMark'),
        link: e.isActive('link'),
        h2: e.isActive('heading', { level: 2 }),
        h3: e.isActive('heading', { level: 3 }),
        bullet: e.isActive('bulletList'),
        ordered: e.isActive('orderedList'),
        quote: e.isActive('blockquote'),
        box: isInNode(e.state, 'highlightBox'),
        canUndo: e.can().undo(),
        canRedo: e.can().redo(),
        emptyLine,
        focused: e.isFocused,
        from: selection.from,
        ...countText(e.state.doc),
      }
    },
  })

  useEffect(() => {
    if (ui) onStats?.({ words: ui.words, chars: ui.chars, uploading })
  }, [ui?.words, ui?.chars, uploading]) // eslint-disable-line react-hooks/exhaustive-deps

  // Desktop: the "+" sits in the left gutter of the empty line.
  useLayoutEffect(() => {
    if (!editor || coarse || !ui?.emptyLine || !ui.focused) {
      setPlusTop(null)
      return
    }
    const box = scrollRef.current
    if (!box) return
    try {
      const c = editor.view.coordsAtPos(ui.from)
      const r = box.getBoundingClientRect()
      setPlusTop(c.top - r.top + box.scrollTop + (c.bottom - c.top) / 2 - 22)
    } catch {
      setPlusTop(null)
    }
  }, [editor, coarse, ui?.emptyLine, ui?.focused, ui?.from, ui?.words])

  // ---- Images ----
  const uploadFiles = useCallback(async (files: File[]) => {
    const ed = editorRef.current
    if (!ed) return
    setError('')
    for (const file of files) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
        setError('শুধু JPG, PNG বা WebP ছবি দেওয়া যাবে।')
        continue
      }
      const key = `up${++uploadSeq.current}`
      const localUrl = URL.createObjectURL(file)
      insertFigure(ed, { src: localUrl, alt: '', caption: '', id: null, uploadKey: key })
      setUploading((n) => n + 1)
      try {
        const res = await MediaApi.upload(file)
        updateFigure(ed, key, { src: res.url, alt: res.alt ?? '', caption: res.caption ?? '', id: res.id, uploadKey: null })
      } catch (err) {
        removeFigure(ed, key)
        setError(err instanceof ApiError ? err.message : 'ছবি আপলোড করা যায়নি।')
      } finally {
        setUploading((n) => n - 1)
        URL.revokeObjectURL(localUrl)
      }
    }
  }, [])
  useEffect(() => {
    uploadFilesRef.current = uploadFiles
  }, [uploadFiles])

  // ---- Commands ----
  function runInsert(kind: InsertKind) {
    if (!editor) return
    setInsertOpen(false)
    const c = editor.chain().focus()
    switch (kind) {
      case 'image':
        fileInput.current?.click()
        return
      case 'box':
        c.toggleHighlightBox().run()
        break
      case 'quote':
        if (isInNode(editor.state, 'highlightBox')) editor.chain().focus().lift('highlightBox').toggleBlockquote().run()
        else c.toggleBlockquote().run()
        break
      case 'h2':
        c.toggleHeading({ level: 2 }).run()
        break
      case 'h3':
        c.toggleHeading({ level: 3 }).run()
        break
      case 'bullet':
        c.toggleBulletList().run()
        break
      case 'ordered':
        c.toggleOrderedList().run()
        break
      case 'hr':
        c.setHorizontalRule().run()
        break
    }
  }

  function toggleInsert(fromGutter: boolean) {
    setLinkOpen(false)
    if (!insertOpen) {
      const box = scrollRef.current
      const viewTop = box?.scrollTop ?? 0
      const viewBottom = viewTop + (box?.clientHeight ?? 600)
      const MENU_H = 210
      let top = fromGutter && plusTop != null ? plusTop + 48 : viewTop + 8
      // near the bottom of the visible text → open above the "+"
      if (fromGutter && plusTop != null && top + MENU_H > viewBottom) top = Math.max(viewTop + 4, plusTop - MENU_H)
      setMenuTop(top)
    }
    setInsertOpen((v) => !v)
  }

  function openLink() {
    if (!editor) return
    setInsertOpen(false)
    setLinkValue((editor.getAttributes('link').href as string | undefined) ?? '')
    setLinkOpen(true)
    setTimeout(() => linkInputRef.current?.focus(), 0)
  }

  function applyLink() {
    if (!editor) return
    const href = normalizeUrl(linkValue)
    const chain = editor.chain().focus().extendMarkRange('link')
    if (!href) {
      chain.unsetLink().run()
    } else if (editor.state.selection.empty && !editor.isActive('link')) {
      editor
        .chain()
        .focus()
        .insertContent({ type: 'text', text: linkValue.trim(), marks: [{ type: 'link', attrs: { href } }] })
        .run()
    } else {
      chain.setLink({ href }).run()
    }
    setLinkOpen(false)
  }

  if (!editor || !ui) return null

  const toolbar = (
    <div
      role="toolbar"
      aria-label="লেখার টুলবার"
      data-testid={coarse ? 'editor-toolbar-bottom' : 'editor-toolbar-top'}
      className={`flex items-center gap-0.5 px-1.5 py-1 shrink-0 bg-white ${
        coarse
          ? 'overflow-x-auto overscroll-x-contain border-t border-neutral-200 shadow-[0_-2px_8px_rgba(0,0,0,0.04)] pb-[max(4px,env(safe-area-inset-bottom))]'
          : 'flex-wrap border-b border-neutral-100'
      }`}
    >
      <ToolButton label="যোগ করুন (ছবি, হাইলাইট বক্স…)" active={insertOpen} testId="tb-insert" onClick={() => toggleInsert(false)}>
        <IconPlus className={ICON} />
      </ToolButton>
      <ToolButton label="ছবি যুক্ত করুন" testId="tb-image" onClick={() => fileInput.current?.click()}>
        <IconPhotoPlus className={ICON} />
      </ToolButton>
      <ToolButton label="বোল্ড" active={ui.bold} onClick={() => editor.chain().focus().toggleBold().run()}>
        <IconBold className={ICON} />
      </ToolButton>
      <ToolButton label="ইটালিক" active={ui.italic} onClick={() => editor.chain().focus().toggleItalic().run()}>
        <IconItalic className={ICON} />
      </ToolButton>
      <ToolButton label="হাইলাইট" active={ui.mark} testId="tb-mark" onClick={() => editor.chain().focus().toggleMeehaMark().run()}>
        <IconHighlight className={ICON} />
      </ToolButton>
      <ToolButton label="হাইলাইট বক্স" active={ui.box} testId="tb-box" onClick={() => editor.chain().focus().toggleHighlightBox().run()}>
        <IconBox className={ICON} />
      </ToolButton>
      <ToolButton label="লিংক" active={ui.link || linkOpen} onClick={openLink}>
        <IconLink className={ICON} />
      </ToolButton>
      <ToolButton label="আন্ডু" disabled={!ui.canUndo} onClick={() => editor.chain().focus().undo().run()}>
        <IconUndo className={ICON} />
      </ToolButton>
      <ToolButton label="রিডু" disabled={!ui.canRedo} onClick={() => editor.chain().focus().redo().run()}>
        <IconRedo className={ICON} />
      </ToolButton>
      <span className="w-px h-6 bg-neutral-200 mx-0.5 shrink-0" />
      <ToolButton label="শিরোনাম" active={ui.h2} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>
        <IconH2 className={ICON} />
      </ToolButton>
      <ToolButton label="তালিকা" active={ui.bullet} onClick={() => editor.chain().focus().toggleBulletList().run()}>
        <IconListBullet className={ICON} />
      </ToolButton>
      <ToolButton label="উদ্ধৃতি" active={ui.quote} onClick={() => runInsert('quote')}>
        <IconQuote className={ICON} />
      </ToolButton>
    </div>
  )

  const insertMenu = insertOpen && (
    <div
      role="menu"
      aria-label="ব্লক যোগ করুন"
      data-testid="insert-menu"
      className={
        coarse
          ? 'shrink-0 border-t border-neutral-200 bg-white px-2 pt-2 pb-1 grid grid-cols-4 gap-1'
          : 'absolute z-20 left-2 w-[min(320px,calc(100%-16px))] rounded-xl border border-neutral-200 bg-white p-1.5 shadow-lg grid grid-cols-2 gap-1'
      }
      style={!coarse ? { top: menuTop } : undefined}
    >
      {INSERT_ITEMS.map(({ kind, label, icon: Icon }) => (
        <button
          key={kind}
          type="button"
          role="menuitem"
          onMouseDown={keepFocus}
          onClick={() => runInsert(kind)}
          className={`min-h-[44px] rounded-lg flex items-center gap-2 text-neutral-800 active:bg-neutral-100 hover:bg-neutral-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#025AB2] ${
            coarse ? 'flex-col justify-center gap-1 py-1.5 text-[11.5px]' : 'px-2.5 text-[13.5px]'
          }`}
        >
          <Icon className="w-5 h-5 text-[#025AB2] shrink-0" />
          <span className="leading-tight text-center">{label}</span>
        </button>
      ))}
    </div>
  )

  const linkPanel = linkOpen && (
    <form
      noValidate
      data-testid="link-panel"
      onSubmit={(e) => {
        e.preventDefault()
        applyLink()
      }}
      className="shrink-0 flex items-center gap-1.5 border-t border-b border-neutral-200 bg-neutral-50 px-2 py-1.5"
    >
      <input
        ref={linkInputRef}
        type="text"
        autoCapitalize="off"
        autoCorrect="off"
        inputMode="url"
        dir="ltr"
        value={linkValue}
        onChange={(e) => setLinkValue(e.target.value)}
        placeholder="https://…"
        aria-label="লিংকের ঠিকানা"
        className="flex-1 min-w-0 h-11 rounded-lg border border-neutral-300 bg-white px-3 text-[15px] focus:border-[#025AB2] focus:outline-none"
      />
      <button type="submit" className="h-11 px-3 rounded-lg bg-[#025AB2] text-white text-[13.5px] font-semibold shrink-0">
        যুক্ত করুন
      </button>
      {ui.link && (
        <button
          type="button"
          aria-label="লিংক সরান"
          onClick={() => {
            editor.chain().focus().extendMarkRange('link').unsetLink().run()
            setLinkOpen(false)
          }}
          className="w-11 h-11 rounded-lg text-neutral-600 flex items-center justify-center shrink-0"
        >
          <IconLinkOff className={ICON} />
        </button>
      )}
      <button
        type="button"
        aria-label="বন্ধ করুন"
        onClick={() => {
          setLinkOpen(false)
          editor.commands.focus()
        }}
        className="w-11 h-11 rounded-lg text-neutral-500 flex items-center justify-center shrink-0"
      >
        <IconX className="w-4 h-4" />
      </button>
    </form>
  )

  return (
    <div className="flex flex-col h-full min-h-0">
      {!coarse && toolbar}
      {!coarse && linkPanel}

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
          if (files.length) uploadFiles(files)
        }}
      />

      {(uploading > 0 || error || hint) && (
        <div className="shrink-0 px-4 py-1.5 text-[12.5px] border-b border-neutral-100 bg-white" aria-live="polite">
          {uploading > 0 && <p className="text-[#025AB2]">ছবি আপলোড হচ্ছে…</p>}
          {error && <p className="text-red-600">{error}</p>}
          {hint && (
            <p className="text-amber-700 flex items-start gap-2">
              <span className="flex-1">{hint}</span>
              <button type="button" className="font-semibold shrink-0" onClick={() => setHint('')}>
                ঠিক আছে
              </button>
            </p>
          )}
        </div>
      )}

      <div
        ref={scrollRef}
        className="relative flex-1 min-h-0 overflow-y-auto overflow-x-hidden"
        data-testid="editor-scroll"
        onMouseDown={(e) => {
          // Tapping the empty space below the text continues writing at the end
          // (TrailingNode guarantees a paragraph is there, even after an image).
          if (e.target === e.currentTarget) {
            e.preventDefault()
            editor.commands.focus('end')
          }
        }}
      >
        <div className={coarse ? 'pl-4' : 'pl-[52px]'}>
          <EditorContent editor={editor} />
        </div>
        {!coarse && plusTop != null && (
          <button
            type="button"
            onMouseDown={keepFocus}
            onClick={() => toggleInsert(true)}
            aria-label="ব্লক যোগ করুন"
            aria-expanded={insertOpen}
            data-testid="gutter-plus"
            className="absolute left-1.5 w-11 h-11 flex items-center justify-center"
            style={{ top: plusTop }}
          >
            <span className={`w-7 h-7 rounded-full border flex items-center justify-center transition ${insertOpen ? 'bg-[#025AB2] border-[#025AB2] text-white rotate-45' : 'border-neutral-300 text-neutral-500 bg-white hover:border-[#025AB2] hover:text-[#025AB2]'}`}>
              <IconPlus className="w-4 h-4" />
            </span>
          </button>
        )}
        {!coarse && insertMenu}
      </div>

      {coarse && linkPanel}
      {coarse && insertMenu}
      {coarse && toolbar}

      <BubbleMenu
        editor={editor}
        options={{ placement: coarse ? 'bottom' : 'top', offset: 8, flip: true, shift: { padding: 8 } }}
        shouldShow={({ editor: e, state, from, to }) =>
          e.isEditable && e.isFocused && from !== to && !(state.selection instanceof NodeSelection) && !linkOpen
        }
      >
        <div
          data-testid="bubble-menu"
          className="flex items-center gap-0.5 rounded-xl border border-neutral-200 bg-white p-1 shadow-lg"
        >
          <ToolButton label="বোল্ড" active={ui.bold} onClick={() => editor.chain().focus().toggleBold().run()}>
            <IconBold className={ICON} />
          </ToolButton>
          <ToolButton label="ইটালিক" active={ui.italic} onClick={() => editor.chain().focus().toggleItalic().run()}>
            <IconItalic className={ICON} />
          </ToolButton>
          <ToolButton label="লিংক" active={ui.link} onClick={openLink}>
            <IconLink className={ICON} />
          </ToolButton>
          <ToolButton label="হাইলাইট" active={ui.mark} testId="bubble-mark" onClick={() => editor.chain().focus().toggleMeehaMark().run()}>
            <IconHighlight className={ICON} />
          </ToolButton>
          <ToolButton label="হাইলাইট বক্স" active={ui.box} testId="bubble-box" onClick={() => editor.chain().focus().toggleHighlightBox().run()}>
            <IconBox className={ICON} />
          </ToolButton>
          <ToolButton label="শিরোনাম" active={ui.h2} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>
            <IconH2 className={ICON} />
          </ToolButton>
        </div>
      </BubbleMenu>
    </div>
  )
}

// ---- figure helpers (by uploadKey, so typing during an upload is fine) ----

function findFigure(editor: Editor, key: string): number | null {
  let found: number | null = null
  editor.state.doc.descendants((node, pos) => {
    if (found != null) return false
    if (node.type.name === 'figureImage' && node.attrs.uploadKey === key) {
      found = pos
      return false
    }
    return true
  })
  return found
}

function insertFigure(editor: Editor, attrs: Record<string, unknown>) {
  const { state } = editor
  const sel = state.selection
  const parent = sel.$from.parent
  const node = { type: 'figureImage', attrs }
  // Mid-text the paragraph is split and writing continues with its second
  // half; on an empty line (or after a selected block) writing continues in
  // a fresh empty paragraph right below the image.
  let midText = false
  if (sel instanceof NodeSelection) {
    editor.chain().focus().insertContentAt(sel.to, node).run()
  } else if (sel.empty && parent.isTextblock && parent.content.size === 0 && sel.$from.depth === 1) {
    editor.chain().focus().insertContentAt({ from: sel.$from.before(), to: sel.$from.after() }, node).run()
  } else {
    midText = sel.$from.parentOffset > 0 && sel.$from.parentOffset < parent.content.size
    editor.chain().focus().insertContent(node).run()
  }
  const pos = findFigure(editor, String(attrs.uploadKey))
  if (pos == null) return
  const fig = editor.state.doc.nodeAt(pos)
  if (!fig) return
  const end = pos + fig.nodeSize
  const next = editor.state.doc.nodeAt(end)
  if (midText && next && next.isTextblock) {
    editor.chain().setTextSelection(end + 1).scrollIntoView().run()
  } else {
    editor.view.dispatch(caretAfter(editor.state, end))
  }
}

function updateFigure(editor: Editor, key: string, attrs: Record<string, unknown>) {
  const pos = findFigure(editor, key)
  if (pos == null) return
  const node = editor.state.doc.nodeAt(pos)
  if (!node) return
  editor.view.dispatch(editor.state.tr.setNodeMarkup(pos, undefined, { ...node.attrs, ...attrs }))
}

function removeFigure(editor: Editor, key: string) {
  const pos = findFigure(editor, key)
  if (pos == null) return
  const node = editor.state.doc.nodeAt(pos)
  if (!node) return
  editor.view.dispatch(editor.state.tr.delete(pos, pos + node.nodeSize))
}
