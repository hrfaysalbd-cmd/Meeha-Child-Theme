/**
 * Round 12 — rich-editor building blocks (identical file in correspondent-app
 * and publisher-app; keep the two copies aligned).
 *
 *  - HighlightBox: the theme's "হাইলাইট বক্স" block, stored exactly as the
 *      theme styles it:
 *        <blockquote class="wp-block-quote is-style-meeha-highlight"><p>…</p></blockquote>
 *  - MeehaMark: inline highlight, stored as <mark class="m-mark">…</mark>.
 *  - BlockEscape: fixes the "cursor can't leave a selected image" trap —
 *      with an image/figure/divider selected, Enter / ArrowDown / ArrowRight
 *      / typing put the caret in a paragraph after it (creating one when
 *      needed), ArrowUp / ArrowLeft on the first block create one before it.
 *  - cleanPastedHtml(): turns Word / Google Docs / web HTML into the small
 *      allow-list the site uses (p, h2, h3, strong, em, a, mark, lists,
 *      blockquote) — no fonts, colours, classes, tables-as-layout or remote
 *      images.
 *  - insertBlockAtom(): inserts a figure (or any block atom) at the caret —
 *      splitting the paragraph when the caret is mid-text, replacing an empty
 *      line — and leaves the caret in the paragraph right after it.
 */
import { Extension, Mark, Node, mergeAttributes, type Editor } from '@tiptap/core'
import { NodeSelection, Plugin, PluginKey, TextSelection, type EditorState, type Transaction } from '@tiptap/pm/state'
import type { Node as PMNode } from '@tiptap/pm/model'

export const HIGHLIGHT_BOX_CLASS = 'wp-block-quote is-style-meeha-highlight'
export const INLINE_MARK_CLASS = 'm-mark'

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    highlightBox: {
      /** Wraps the selected paragraph(s) in a highlight box, or unwraps them. */
      toggleHighlightBox: () => ReturnType
    }
    meehaMark: {
      toggleMeehaMark: () => ReturnType
      unsetMeehaMark: () => ReturnType
    }
  }
}

export const HighlightBox = Node.create({
  name: 'highlightBox',
  group: 'block',
  content: 'paragraph+',
  defining: true,

  parseHTML() {
    // Checked before StarterKit's plain <blockquote> rule (priority 50).
    return [
      { tag: 'blockquote.is-style-meeha-highlight', priority: 60 },
      { tag: 'div.is-style-meeha-highlight', priority: 60 },
    ]
  },

  renderHTML({ HTMLAttributes }) {
    return ['blockquote', mergeAttributes(HTMLAttributes, { class: HIGHLIGHT_BOX_CLASS }), 0]
  },

  addCommands() {
    return {
      toggleHighlightBox:
        () =>
        ({ editor, chain }) => {
          if (editor.isActive(this.name)) return chain().lift(this.name).run()
          // Only paragraphs fit inside; a selected heading/quote is turned
          // into a paragraph first so the wrap never silently fails.
          if (chain().wrapIn(this.name).run()) return true
          return chain().clearNodes().wrapIn(this.name).run()
        },
    }
  },

  addKeyboardShortcuts() {
    return { 'Mod-Alt-h': () => this.editor.commands.toggleHighlightBox() }
  },
})

export const MeehaMark = Mark.create({
  name: 'meehaMark',

  parseHTML() {
    return [{ tag: 'mark' }]
  },

  renderHTML({ HTMLAttributes }) {
    return ['mark', mergeAttributes(HTMLAttributes, { class: INLINE_MARK_CLASS }), 0]
  },

  addCommands() {
    return {
      toggleMeehaMark:
        () =>
        ({ commands }) =>
          commands.toggleMark(this.name),
      unsetMeehaMark:
        () =>
        ({ commands }) =>
          commands.unsetMark(this.name),
    }
  },

  addKeyboardShortcuts() {
    return { 'Mod-Shift-h': () => this.editor.commands.toggleMeehaMark() }
  },
})

// ---------------------------------------------------------------------------
// Node-selection escape

function selectedBlockAtom(state: EditorState): { node: PMNode; from: number; to: number } | null {
  const sel = state.selection
  if (!(sel instanceof NodeSelection)) return null
  const node = sel.node
  if (!node.isBlock || !(node.isAtom || node.isLeaf)) return null
  return { node, from: sel.from, to: sel.to }
}

/**
 * Puts the caret in the textblock right after `pos`, creating a paragraph
 * when there is none (or, with `fresh`, unless the next one is empty).
 */
function caretAfter(tr: Transaction, pos: number, fresh = false): Transaction {
  const $pos = tr.doc.resolve(pos)
  const after = $pos.nodeAfter
  if (after && after.isTextblock && (!fresh || after.content.size === 0)) {
    return tr.setSelection(TextSelection.create(tr.doc, pos + 1)).scrollIntoView()
  }
  const paragraph = tr.doc.type.schema.nodes.paragraph.create()
  tr.insert(pos, paragraph)
  return tr.setSelection(TextSelection.create(tr.doc, pos + 1)).scrollIntoView()
}

function caretBefore(tr: Transaction, pos: number): Transaction {
  const $pos = tr.doc.resolve(pos)
  const before = $pos.nodeBefore
  if (before && before.isTextblock) {
    return tr.setSelection(TextSelection.create(tr.doc, pos - 1)).scrollIntoView()
  }
  const paragraph = tr.doc.type.schema.nodes.paragraph.create()
  tr.insert(pos, paragraph)
  return tr.setSelection(TextSelection.create(tr.doc, pos + 1)).scrollIntoView()
}

/** Commands also used by the figure's own "উপরে/নিচে লিখুন" buttons. */
export function moveCaretAfterNode(editor: Editor, nodePos: number, fresh = false) {
  const node = editor.state.doc.nodeAt(nodePos)
  if (!node) return
  editor.view.dispatch(caretAfter(editor.state.tr, nodePos + node.nodeSize, fresh))
  editor.view.focus()
}

export function moveCaretBeforeNode(editor: Editor, nodePos: number) {
  editor.view.dispatch(caretBefore(editor.state.tr, nodePos))
  editor.view.focus()
}

export const BlockEscape = Extension.create({
  name: 'blockEscape',

  addKeyboardShortcuts() {
    const after = (fresh = false) => {
      const hit = selectedBlockAtom(this.editor.state)
      if (!hit) return false
      this.editor.view.dispatch(caretAfter(this.editor.state.tr, hit.to, fresh))
      return true
    }
    const before = () => {
      const hit = selectedBlockAtom(this.editor.state)
      if (!hit) return false
      const $from = this.editor.state.doc.resolve(hit.from)
      // Only when nothing selectable sits above it — otherwise the default
      // arrow behaviour (move to the previous line) is what people expect.
      if ($from.nodeBefore) return false
      this.editor.view.dispatch(caretBefore(this.editor.state.tr, hit.from))
      return true
    }
    const downOrRight = () => {
      const hit = selectedBlockAtom(this.editor.state)
      if (!hit) return false
      const next = this.editor.state.doc.resolve(hit.to).nodeAfter
      // Next block is another image: let the default move select it.
      if (next && !next.isTextblock) return false
      return after()
    }
    return {
      // Enter = "new line below the image" (like the block editor).
      Enter: () => after(true),
      ArrowDown: downOrRight,
      ArrowRight: downOrRight,
      ArrowUp: before,
      ArrowLeft: before,
    }
  },

  addProseMirrorPlugins() {
    return [
      new Plugin({
        key: new PluginKey('blockEscape'),
        props: {
          // Typing while an image is selected used to REPLACE the image
          // with the typed letter; now the letter starts a new paragraph
          // after it and the image stays.
          handleTextInput(view, _from, _to, text) {
            const hit = selectedBlockAtom(view.state)
            if (!hit) return false
            const tr = caretAfter(view.state.tr, hit.to, true)
            tr.insertText(text)
            view.dispatch(tr)
            return true
          },
          handleDOMEvents: {
            // Some browsers deliver a typed character on a selected
            // (non-editable) node as beforeinput without a usable DOM
            // change — catch it here so it never replaces the image.
            // Chrome on Android: ProseMirror deliberately ignores Enter on
            // keydown there and waits for the DOM change — which a selected
            // non-editable image never produces, so Enter did nothing (the
            // phone "cursor stuck on the image" bug). Handle Enter here,
            // before ProseMirror's own keydown handler (ported from the
            // correspondent app's fix).
            keydown(view, event) {
              const hit = selectedBlockAtom(view.state)
              if (!hit) return false
              if (event.key === 'Enter' || event.keyCode === 13) {
                event.preventDefault()
                view.dispatch(caretAfter(view.state.tr, hit.to, true))
                return true
              }
              return false
            },
            // The same inputs as they arrive from soft keyboards / IMEs.
            beforeinput(view, event) {
              const e = event as InputEvent
              const hit = selectedBlockAtom(view.state)
              if (!hit) return false
              if (e.inputType === 'insertParagraph' || e.inputType === 'insertLineBreak') {
                e.preventDefault()
                view.dispatch(caretAfter(view.state.tr, hit.to, true))
                return true
              }
              if (e.inputType === 'insertText' && e.data) {
                e.preventDefault()
                const tr = caretAfter(view.state.tr, hit.to, true)
                tr.insertText(e.data)
                view.dispatch(tr)
                return true
              }
              if (e.inputType === 'deleteContentBackward' || e.inputType === 'deleteContentForward') {
                e.preventDefault()
                view.dispatch(view.state.tr.deleteSelection().scrollIntoView())
                return true
              }
              return false
            },
            // IME (Bengali keyboards) composition starting on a selected
            // image: move into a fresh paragraph below it first.
            compositionstart(view) {
              const hit = selectedBlockAtom(view.state)
              if (hit) view.dispatch(caretAfter(view.state.tr, hit.to, true))
              return false
            },
            // A click in the empty space under the last block puts the
            // caret at the end (in the trailing paragraph) instead of
            // re-selecting the image above it.
            mousedown(view, event) {
              if (event.target !== view.dom || event.button !== 0) return false
              const last = view.dom.lastElementChild
              if (!last || event.clientY <= last.getBoundingClientRect().bottom) return false
              event.preventDefault()
              const doc = view.state.doc
              const lastNode = doc.lastChild
              const tr = view.state.tr
              if (lastNode && lastNode.isTextblock) tr.setSelection(TextSelection.create(doc, doc.content.size - 1))
              else caretAfter(tr, doc.content.size)
              view.dispatch(tr.scrollIntoView())
              view.focus()
              return true
            },
          },
        },
      }),
    ]
  },
})

// ---------------------------------------------------------------------------
// Insert a block atom (figure) at the caret

export function insertBlockAtom(editor: Editor, type: string, attrs: Record<string, unknown>) {
  const { state, view } = editor
  const nodeType = state.schema.nodes[type]
  if (!nodeType) return
  const node = nodeType.create(attrs)
  const tr = state.tr
  const sel = tr.selection
  const { $from } = sel
  let insertAt: number
  if (sel instanceof NodeSelection) {
    // Another image/divider selected → right after it.
    insertAt = sel.to
    tr.insert(insertAt, node)
  } else if ($from.depth > 1) {
    // Caret inside a list / quote / highlight box, where a figure can't
    // live → right after that container.
    insertAt = $from.after(1)
    tr.insert(insertAt, node)
  } else if ($from.parent.isTextblock && $from.parent.content.size === 0) {
    // Empty line → the figure takes its place.
    insertAt = $from.before()
    tr.replaceWith(insertAt, $from.after(), node)
  } else {
    if (!sel.empty) tr.deleteSelection()
    const $pos = tr.selection.$from
    if ($pos.parentOffset === 0) {
      insertAt = $pos.before()
    } else if ($pos.parentOffset === $pos.parent.content.size) {
      insertAt = $pos.after()
    } else {
      // Mid-text → split the paragraph at the caret.
      tr.split($pos.pos)
      insertAt = $pos.pos + 1
    }
    tr.insert(insertAt, node)
  }
  caretAfter(tr, insertAt + node.nodeSize)
  view.dispatch(tr)
  view.focus()
}

// ---------------------------------------------------------------------------
// Paste cleanup

const DROP_TAGS = new Set([
  'SCRIPT', 'STYLE', 'META', 'LINK', 'TITLE', 'XML', 'IFRAME', 'OBJECT', 'EMBED', 'VIDEO', 'AUDIO', 'SVG', 'CANVAS',
  'FORM', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA', 'NOSCRIPT', 'TEMPLATE', 'HEAD',
])
const IMAGE_TAGS = new Set(['IMG', 'PICTURE', 'SOURCE'])

function isHighlightColour(v: string) {
  const c = v.trim().toLowerCase()
  if (!c) return false
  return !/^(transparent|none|inherit|initial|unset|white|#fff|#ffffff|rgb\(255,\s*255,\s*255\)|rgba\(0,\s*0,\s*0,\s*0\)|windowtext|auto)/.test(c)
}

function rename(el: Element, tag: string) {
  const doc = el.ownerDocument
  const repl = doc.createElement(tag)
  while (el.firstChild) repl.appendChild(el.firstChild)
  el.replaceWith(repl)
  return repl
}

function unwrap(el: Element) {
  const parent = el.parentNode
  if (!parent) return
  while (el.firstChild) parent.insertBefore(el.firstChild, el)
  parent.removeChild(el)
}

/**
 * @returns cleaned HTML plus how many images were dropped (pasted images
 *          would hot-link other sites or arrive as unusable data:/file: URLs —
 *          the editor asks people to add them through ছবি instead).
 */
export function cleanPastedHtml(html: string): { html: string; droppedImages: number } {
  // A copy from inside this same editor (ProseMirror marks it) is already clean.
  if (html.includes('data-pm-slice')) return { html, droppedImages: 0 }
  const doc = new DOMParser().parseFromString(html, 'text/html')
  let droppedImages = 0

  // Comments (Word's conditional comments) and XML namespaced junk.
  const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_COMMENT)
  const comments: globalThis.Node[] = []
  while (walker.nextNode()) comments.push(walker.currentNode)
  comments.forEach((c) => c.parentNode?.removeChild(c))

  // Depth-first, children before parents, over a static list.
  const all = Array.from(doc.body.querySelectorAll('*')).reverse()
  for (const el of all) {
    if (!el.isConnected) continue
    const tag = el.tagName.toUpperCase()
    if (DROP_TAGS.has(tag) || tag.includes(':')) {
      // o:p, v:shape, w:… (Word)
      if (tag.includes(':') && tag !== 'O:P' && el.textContent?.trim()) unwrap(el)
      else el.remove()
      continue
    }
    if (IMAGE_TAGS.has(tag)) {
      if (tag === 'IMG') droppedImages++
      el.remove()
      continue
    }
    const style = (el.getAttribute('style') || '').toLowerCase()
    // Word's fake list bullets ("·", "1.") live in mso-list:Ignore spans.
    if (/mso-list:\s*ignore/.test(style)) {
      el.remove()
      continue
    }
    if (tag === 'FIGURE' && !el.textContent?.trim()) {
      el.remove()
      continue
    }

    if (tag === 'SPAN' || tag === 'FONT' || ((tag === 'B' || tag === 'STRONG') && /font-weight:\s*(normal|400)/.test(style))) {
      const bold = /font-weight:\s*(bold|[6-9]00)/.test(style)
      const italic = /font-style:\s*italic/.test(style)
      const bg = /(?:^|;)\s*(?:background-color|background|mso-highlight)\s*:\s*([^;]+)/.exec(style)
      const mark = !!bg && isHighlightColour(bg[1])
      if (!bold && !italic && !mark) {
        unwrap(el)
        continue
      }
      let inner: Element = rename(el, mark ? 'mark' : bold ? 'strong' : 'em')
      if (mark && bold) {
        const s = doc.createElement('strong')
        while (inner.firstChild) s.appendChild(inner.firstChild)
        inner.appendChild(s)
        inner = s
      }
      if ((mark || bold) && italic) {
        const em = doc.createElement('em')
        while (inner.firstChild) em.appendChild(inner.firstChild)
        inner.appendChild(em)
      }
      continue
    }

    let cur: Element = el
    if (tag === 'H1') cur = rename(el, 'h2')
    else if (tag === 'H4' || tag === 'H5' || tag === 'H6') cur = rename(el, 'h3')
    else if (tag === 'B') cur = rename(el, 'strong')
    else if (tag === 'I') cur = rename(el, 'em')
    else if (tag === 'U' || tag === 'S' || tag === 'STRIKE' || tag === 'DEL' || tag === 'INS' || tag === 'SUP' || tag === 'SUB' || tag === 'SMALL' || tag === 'BIG' || tag === 'CODE' || tag === 'KBD' || tag === 'ABBR' || tag === 'CITE' || tag === 'Q' || tag === 'LABEL') {
      unwrap(el)
      continue
    } else if (tag === 'PRE') cur = rename(el, 'p')
    else if (tag === 'TD' || tag === 'TH' || tag === 'DT' || tag === 'DD') cur = rename(el, 'p')
    else if (tag === 'TABLE' || tag === 'TBODY' || tag === 'THEAD' || tag === 'TFOOT' || tag === 'TR' || tag === 'DL' || tag === 'SECTION' || tag === 'ARTICLE' || tag === 'HEADER' || tag === 'FOOTER' || tag === 'MAIN' || tag === 'ASIDE' || tag === 'NAV' || tag === 'CENTER') {
      unwrap(el)
      continue
    }

    // Attributes: keep only href on links and our own two classes.
    const keepClass =
      (cur.tagName === 'BLOCKQUOTE' && (cur.getAttribute('class') || '').includes('is-style-meeha-highlight')) ||
      (cur.tagName === 'MARK' && (cur.getAttribute('class') || '').includes(INLINE_MARK_CLASS))
    for (const attr of Array.from(cur.attributes)) {
      if (cur.tagName === 'A' && attr.name === 'href') continue
      if (attr.name === 'class' && keepClass) continue
      cur.removeAttribute(attr.name)
    }
    if (cur.tagName === 'A') {
      const href = cur.getAttribute('href') || ''
      if (!/^(https?:|mailto:|tel:)/i.test(href)) unwrap(cur)
    }
  }

  // Word / Docs empty spacer paragraphs.
  for (const p of Array.from(doc.body.querySelectorAll('p, h2, h3'))) {
    if (!p.textContent?.replace(/[\s ​]/g, '')) p.remove()
  }

  return { html: doc.body.innerHTML, droppedImages }
}

/** Bengali digits for counters. */
export function toBnDigits(n: number | string) {
  return String(n).replace(/[0-9]/g, (d) => '০১২৩৪৫৬৭৮৯'[Number(d)])
}
