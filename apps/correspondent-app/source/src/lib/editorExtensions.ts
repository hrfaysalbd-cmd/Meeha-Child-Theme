/**
 * Round 12 — extra Tiptap pieces for the rich editor (kept in step with the
 * publisher app's editor; see CONTRACT-R12 "Editor requirements").
 *
 *  - MeehaMark      inline highlight  → <mark class="m-mark">…</mark>
 *  - HighlightBox   block highlight   → <blockquote class="wp-block-quote is-style-meeha-highlight"><p>…</p></blockquote>
 *  - EditorBehaviour keyboard/text-input rules so a selected image (or any
 *                   other atom block) never traps the caret, plus paste
 *                   cleanup (Word / Google Docs / web → the allowed marks).
 *
 * Both highlight markups are what the meeha theme styles on the live site
 * (.m-prose .is-style-meeha-highlight / .m-mark) and what the plugin's
 * wp_kses allow-list keeps.
 */
import { Extension, Mark, Node, mergeAttributes, type Editor } from '@tiptap/core'
import { NodeSelection, Plugin, PluginKey, Selection, TextSelection, type EditorState, type Transaction } from '@tiptap/pm/state'
import type { Node as PMNode } from '@tiptap/pm/model'

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    meehaMark: {
      toggleMeehaMark: () => ReturnType
    }
    highlightBox: {
      toggleHighlightBox: () => ReturnType
    }
  }
}

export const MeehaMark = Mark.create({
  name: 'meehaMark',
  // A pasted <mark> (any class) becomes the theme's highlight.
  parseHTML() {
    return [{ tag: 'mark' }]
  },
  renderHTML() {
    return ['mark', { class: 'm-mark' }, 0]
  },
  addCommands() {
    return {
      toggleMeehaMark:
        () =>
        ({ commands }) =>
          commands.toggleMark(this.name),
    }
  },
  addKeyboardShortcuts() {
    return { 'Mod-Shift-h': () => this.editor.commands.toggleMeehaMark() }
  },
})

export const HIGHLIGHT_BOX_CLASS = 'wp-block-quote is-style-meeha-highlight'

export const HighlightBox = Node.create({
  name: 'highlightBox',
  group: 'block',
  content: 'paragraph+',
  defining: true,
  // Must win over StarterKit's plain blockquote for the highlight markup.
  priority: 200,
  parseHTML() {
    return [{ tag: 'blockquote.is-style-meeha-highlight', priority: 100 }]
  },
  renderHTML({ HTMLAttributes }) {
    return ['blockquote', mergeAttributes(HTMLAttributes, { class: HIGHLIGHT_BOX_CLASS }), 0]
  },
  addCommands() {
    return {
      toggleHighlightBox:
        () =>
        ({ state, chain }) => {
          if (isInNode(state, 'highlightBox')) return chain().lift(this.name).run()
          // Headings / quotes inside the selection become paragraphs first —
          // the box holds paragraphs only (that's what the theme styles).
          let c = chain()
          if (isInNode(state, 'blockquote')) c = c.lift('blockquote')
          return c.setParagraph().wrapIn(this.name).run()
        },
    }
  },
})

export function isInNode(state: EditorState, name: string) {
  const { $from } = state.selection
  for (let d = $from.depth; d > 0; d--) if ($from.node(d).type.name === name) return true
  return false
}

/** A block-level atom (figure image, legacy image, divider) selected as a node. */
export function selectedAtomBlock(state: EditorState): NodeSelection | null {
  const sel = state.selection
  if (sel instanceof NodeSelection && sel.node.isBlock && (sel.node.isAtom || sel.node.isLeaf)) return sel
  return null
}

/** Moves the caret into the textblock right after `pos`, creating a paragraph there if needed. */
export function caretAfter(state: EditorState, pos: number, text = ''): Transaction {
  const tr = state.tr
  const next = tr.doc.nodeAt(pos)
  if (next && next.isTextblock && next.content.size === 0 && !text) {
    return tr.setSelection(TextSelection.create(tr.doc, pos + 1)).scrollIntoView()
  }
  const para = state.schema.nodes.paragraph.create(null, text ? state.schema.text(text) : null)
  tr.insert(pos, para)
  return tr.setSelection(TextSelection.create(tr.doc, pos + 1 + para.content.size)).scrollIntoView()
}

function caretBefore(state: EditorState, pos: number): Transaction {
  const tr = state.tr
  const para = state.schema.nodes.paragraph.create()
  tr.insert(pos, para)
  return tr.setSelection(TextSelection.create(tr.doc, pos + 1)).scrollIntoView()
}

/** Leaves a selected figure/image/divider: down/right/enter → below, up/left → above. */
/** Enter on a selected image: a NEW paragraph right below it (or the empty one already there). */
export function newParagraphAfterAtom(editor: Editor) {
  const sel = selectedAtomBlock(editor.state)
  if (!sel) return false
  editor.view.dispatch(caretAfter(editor.state, sel.to))
  editor.view.focus()
  return true
}

export function leaveAtom(editor: Editor, dir: 'after' | 'before') {
  const sel = selectedAtomBlock(editor.state)
  if (!sel) return false
  const { state, view } = editor
  const { doc } = state
  if (dir === 'after') {
    const next = doc.nodeAt(sel.to)
    const inside = next && !next.isTextblock && !next.isAtom ? Selection.findFrom(doc.resolve(sel.to), 1, true) : null
    if (next && next.isTextblock) {
      view.dispatch(state.tr.setSelection(TextSelection.create(doc, sel.to + 1)).scrollIntoView())
    } else if (inside) {
      view.dispatch(state.tr.setSelection(inside).scrollIntoView())
    } else {
      view.dispatch(caretAfter(state, sel.to))
    }
  } else {
    const before = doc.resolve(sel.from).nodeBefore
    const inside = before && !before.isTextblock && !before.isAtom ? Selection.findFrom(doc.resolve(sel.from), -1, true) : null
    if (before && before.isTextblock) {
      view.dispatch(state.tr.setSelection(TextSelection.create(doc, sel.from - 1)).scrollIntoView())
    } else if (inside) {
      view.dispatch(state.tr.setSelection(inside).scrollIntoView())
    } else {
      view.dispatch(caretBefore(state, sel.from))
    }
  }
  view.focus()
  return true
}

// ---------------- Paste cleanup ----------------

const ALLOWED = new Set([
  'P', 'BR', 'STRONG', 'B', 'EM', 'I', 'A', 'H2', 'H3', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'MARK', 'HR',
])

/**
 * Word / Google Docs / web HTML → only what the editor (and the site) uses:
 * bold, italic, link, h2/h3, lists, blockquote, highlight. Styles, classes,
 * fonts, spans, tables, Office junk and remote images are dropped (images
 * are counted so the editor can tell the writer to upload them instead).
 */
export function cleanPastedHtml(html: string): { html: string; droppedImages: number } {
  let droppedImages = 0
  const src = html
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<\/?o:p[^>]*>/gi, '')
    .replace(/<\/?(?:w|v|m):[^>]*>/gi, '')
  const doc = new DOMParser().parseFromString(src, 'text/html')
  const out = document.createElement('div')

  const styleOf = (el: Element) => (el.getAttribute('style') || '').toLowerCase()
  const isBoldStyle = (s: string) => /font-weight\s*:\s*(bold|[6-9]00)/.test(s)
  const isItalicStyle = (s: string) => /font-style\s*:\s*italic/.test(s)

  function walk(node: globalThis.Node, parent: HTMLElement) {
    if (node.nodeType === 3) {
      parent.appendChild(document.createTextNode(node.textContent ?? ''))
      return
    }
    if (node.nodeType !== 1) return
    const el = node as Element
    let tag = el.tagName.toUpperCase()
    if (['SCRIPT', 'STYLE', 'META', 'LINK', 'TITLE', 'HEAD', 'NOSCRIPT', 'IFRAME', 'OBJECT', 'SVG', 'VIDEO', 'AUDIO', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA'].includes(tag)) return
    if (tag === 'IMG' || tag === 'PICTURE' || tag === 'FIGURE') {
      droppedImages += tag === 'FIGURE' ? el.querySelectorAll('img').length : tag === 'IMG' ? 1 : el.querySelectorAll('img').length
      // keep a figure's caption text as a paragraph
      if (tag === 'FIGURE') {
        const cap = el.querySelector('figcaption')
        if (cap?.textContent?.trim()) {
          const p = document.createElement('p')
          p.textContent = cap.textContent.trim()
          parent.appendChild(p)
        }
      }
      return
    }
    if (tag === 'H1') tag = 'H2'
    if (tag === 'H4' || tag === 'H5' || tag === 'H6') tag = 'H3'
    if (tag === 'DIV' || tag === 'SECTION' || tag === 'ARTICLE' || tag === 'TD' || tag === 'TH' || tag === 'DT' || tag === 'DD' || tag === 'PRE') tag = 'P'
    if (tag === 'TABLE' || tag === 'TBODY' || tag === 'THEAD' || tag === 'TR' || tag === 'TFOOT' || tag === 'MAIN' || tag === 'HEADER' || tag === 'FOOTER' || tag === 'NAV' || tag === 'ASIDE') {
      el.childNodes.forEach((c) => walk(c, parent))
      return
    }

    const style = styleOf(el)
    // Google Docs wraps the whole clipboard in <b style="font-weight:normal">.
    if (tag === 'B' && /font-weight\s*:\s*(normal|400)/.test(style)) {
      el.childNodes.forEach((c) => walk(c, parent))
      return
    }

    if (!ALLOWED.has(tag)) {
      // span/font/u/s/code/sup… → keep the text; bold/italic styles survive
      let target: HTMLElement = parent
      if (isBoldStyle(style)) {
        const s = document.createElement('strong')
        target.appendChild(s)
        target = s
      }
      if (isItalicStyle(style)) {
        const e = document.createElement('em')
        target.appendChild(e)
        target = e
      }
      el.childNodes.forEach((c) => walk(c, target))
      return
    }

    const clean = document.createElement(tag === 'B' ? 'strong' : tag === 'I' ? 'em' : tag.toLowerCase())
    if (tag === 'A') {
      const href = el.getAttribute('href') || ''
      if (/^(https?:|mailto:|tel:)/i.test(href)) clean.setAttribute('href', href)
    }
    if (tag === 'BLOCKQUOTE' && /is-style-meeha-highlight/.test(el.getAttribute('class') || '')) {
      clean.setAttribute('class', HIGHLIGHT_BOX_CLASS)
    }
    let inner: HTMLElement = clean
    if ((tag === 'P' || tag === 'LI') && isBoldStyle(style)) {
      const s = document.createElement('strong')
      clean.appendChild(s)
      inner = s
    }
    el.childNodes.forEach((c) => walk(c, inner))
    parent.appendChild(clean)
  }

  doc.body.childNodes.forEach((c) => walk(c, out))
  // Empty paragraphs from Word's spacing hacks
  out.querySelectorAll('p').forEach((p) => {
    if (!p.textContent?.replace(/ /g, ' ').trim() && !p.querySelector('br')) p.remove()
  })
  return { html: out.innerHTML, droppedImages }
}

export interface EditorBehaviourOptions {
  /** Called when pasted content contained images that were dropped. */
  onDroppedImages: (count: number) => void
  /** Called with image files pasted/dropped from the clipboard (screenshots, copied photos). */
  onImageFiles: (files: File[]) => void
}

export const EditorBehaviour = Extension.create<EditorBehaviourOptions>({
  name: 'meehaEditorBehaviour',
  priority: 1000,

  addOptions() {
    return { onDroppedImages: () => {}, onImageFiles: () => {} }
  },

  addKeyboardShortcuts() {
    return {
      Enter: () => newParagraphAfterAtom(this.editor),
      ArrowDown: () => leaveAtom(this.editor, 'after'),
      ArrowRight: () => leaveAtom(this.editor, 'after'),
      ArrowUp: () => leaveAtom(this.editor, 'before'),
      ArrowLeft: () => leaveAtom(this.editor, 'before'),
    }
  },

  addProseMirrorPlugins() {
    const opts = this.options
    const editor = this.editor
    return [
      new Plugin({
        key: new PluginKey('meehaEditorBehaviour'),
        props: {
          // Typing while an image is selected must not replace the image —
          // the text goes into a new paragraph below it instead.
          handleDOMEvents: {
            // Chrome on Android never handles Enter/Backspace/typing through
            // keydown (ProseMirror waits for the DOM change, which a selected
            // non-editable image never produces) — that is the "cursor stuck
            // on the image" bug. Handle those inputs here, before the browser.
            keydown(view, event) {
              if (!selectedAtomBlock(view.state)) return false
              if (event.key === 'Enter' || event.keyCode === 13) {
                event.preventDefault()
                return newParagraphAfterAtom(editor)
              }
              return false
            },
            beforeinput(view, event) {
              const sel = selectedAtomBlock(view.state)
              if (!sel) return false
              const e = event as InputEvent
              if (e.inputType === 'insertParagraph' || e.inputType === 'insertLineBreak') {
                e.preventDefault()
                view.dispatch(caretAfter(view.state, sel.to))
                return true
              }
              if (e.inputType === 'insertText' && e.data) {
                e.preventDefault()
                view.dispatch(caretAfter(view.state, sel.to, e.data))
                return true
              }
              if (e.inputType === 'deleteContentBackward' || e.inputType === 'deleteContentForward') {
                e.preventDefault()
                view.dispatch(view.state.tr.deleteSelection().scrollIntoView())
                return true
              }
              return false
            },
            compositionstart(view) {
              // IME (Bengali keyboards) typing on a selected image: start the
              // composition in a fresh paragraph below the image instead.
              const sel = selectedAtomBlock(view.state)
              if (sel) view.dispatch(caretAfter(view.state, sel.to))
              return false
            },
          },
          handleTextInput(view, _from, _to, text) {
            const sel = selectedAtomBlock(view.state)
            if (!sel) return false
            view.dispatch(caretAfter(view.state, sel.to, text))
            return true
          },
          transformPastedHTML(html) {
            const { html: cleaned, droppedImages } = cleanPastedHtml(html)
            if (droppedImages > 0) setTimeout(() => opts.onDroppedImages(droppedImages), 0)
            return cleaned
          },
          handlePaste(_view, event) {
            const files = Array.from(event.clipboardData?.files ?? []).filter((f) => /^image\/(jpeg|png|webp)$/.test(f.type))
            const html = event.clipboardData?.getData('text/html') ?? ''
            const text = event.clipboardData?.getData('text/plain') ?? ''
            if (files.length > 0 && !text.trim() && !/<(p|h\d|li)\b/i.test(html)) {
              opts.onImageFiles(files)
              return true
            }
            return false
          },
          handleDrop(_view, event, _slice, moved) {
            if (moved) return false
            const files = Array.from(event.dataTransfer?.files ?? []).filter((f) => /^image\/(jpeg|png|webp)$/.test(f.type))
            if (files.length === 0) return false
            event.preventDefault()
            opts.onImageFiles(files)
            return true
          },
        },
      }),
    ]
  },
})

/** Words + characters of the body text (Bengali-aware: whitespace separated). */
export function countText(doc: PMNode) {
  const text = doc.textBetween(0, doc.content.size, ' ', ' ')
  const words = text.split(/\s+/).filter((w) => /[\p{L}\p{N}]/u.test(w)).length
  const chars = text.replace(/\s/g, '').length
  return { words, chars }
}
