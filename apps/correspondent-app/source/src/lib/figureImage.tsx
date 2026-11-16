/**
 * Round 9 follow-up 5 — an article image WITH a caption, for the rich
 * editor (identical file in correspondent-app and publisher-app).
 *
 * Saved into the post body as WordPress's own image-with-caption markup:
 *
 *   <figure class="wp-block-image">
 *     <img src="…" alt="…" class="wp-image-123">
 *     <figcaption class="wp-element-caption">ক্যাপশন</figcaption>
 *   </figure>
 *
 * — the same HTML the block editor produces, so the theme shows the caption
 * under the image with no theme change, and `wp_kses_post()` (which the
 * plugin runs on every saved body) keeps it intact. `wp-image-123` carries
 * the attachment id, so re-opening a post still knows which media-library
 * attachment each image is.
 *
 * The caption is typed directly under the image in the editor. On leaving
 * the field it is also saved as the attachment's own WordPress caption
 * (PATCH /media/{id} → the attachment's post_excerpt), i.e. it becomes the
 * image's caption in the Media Library too.
 *
 * Plain `<img>` images inserted before this existed stay plain images
 * (handled by Tiptap's Image extension, still registered alongside).
 */
import { Node, NodeViewWrapper, ReactNodeViewRenderer, type ReactNodeViewProps } from '@tiptap/react'
import type { DOMOutputSpec } from '@tiptap/pm/model'
import { NodeSelection } from '@tiptap/pm/state'
import { useRef, useState } from 'react'
import { MediaApi } from './api'
import { caretAfter } from './editorExtensions'

export interface FigureImageAttrs {
  src: string
  alt?: string
  caption?: string
  id?: number | null
  uploadKey?: string | null
}

function FigureImageView({ node, updateAttributes, selected, editor, getPos, deleteNode }: ReactNodeViewProps) {
  const attrs = node.attrs as FigureImageAttrs
  const lastSaved = useRef((attrs.caption ?? '').trim())
  const [status, setStatus] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle')

  // Round 12: leaving the image must always be one tap/keypress away — the
  // caption field and the selected image both offer a way back to writing
  // (a paragraph right below the image, created if there is none).
  function continueBelow() {
    const pos = typeof getPos === 'function' ? getPos() : null
    if (pos == null) return
    const end = pos + node.nodeSize
    const { state, view } = editor
    const next = state.doc.nodeAt(end)
    if (next && next.isTextblock) {
      editor.chain().focus().setTextSelection(end + 1).scrollIntoView().run()
    } else {
      view.dispatch(caretAfter(state, end))
      view.focus()
    }
  }

  function selectSelf() {
    const pos = typeof getPos === 'function' ? getPos() : null
    if (pos == null) return
    editor.view.dispatch(editor.state.tr.setSelection(NodeSelection.create(editor.state.doc, pos)))
    editor.view.focus()
  }

  async function persistCaption(value: string) {
    const caption = value.trim()
    if (!attrs.id || caption === lastSaved.current) return
    setStatus('saving')
    try {
      await MediaApi.updateMeta(attrs.id, { caption })
      lastSaved.current = caption
      setStatus('saved')
    } catch {
      // The caption is still in the article body; only the Media Library
      // copy failed (e.g. someone else's upload). Say so, don't block.
      setStatus('error')
    }
  }

  return (
    <NodeViewWrapper as="figure" className="nros-figure my-3 relative" contentEditable={false}>
      {attrs.uploadKey && (
        <span className="absolute left-2 top-2 z-[1] rounded-md bg-black/65 px-2 py-1 text-[12px] font-medium text-white" data-testid="figure-uploading">
          ছবি আপলোড হচ্ছে…
        </span>
      )}
      <img
        src={attrs.src}
        alt={attrs.alt ?? ''}
        draggable={false}
        data-drag-handle
        className={`rounded-lg max-w-full ${selected ? 'ring-2 ring-[#025AB2] ring-offset-2' : ''}`}
      />
      {selected && editor.isEditable && (
        <span className="nros-figure-actions flex gap-2 mt-2" data-testid="figure-actions">
          <button
            type="button"
            onClick={continueBelow}
            className="min-h-[44px] flex-1 rounded-lg bg-[#025AB2] text-white text-[13.5px] font-semibold px-3 active:opacity-80"
          >
            ↓ ছবির নিচে লিখুন
          </button>
          <button
            type="button"
            onClick={() => deleteNode()}
            className="min-h-[44px] rounded-lg bg-neutral-100 text-red-700 text-[13.5px] font-semibold px-4 active:opacity-80"
          >
            ছবি মুছুন
          </button>
        </span>
      )}
      <input
        type="text"
        value={attrs.caption ?? ''}
        disabled={!editor.isEditable}
        onChange={(e) => {
          setStatus('idle')
          updateAttributes({ caption: e.target.value })
        }}
        onBlur={(e) => persistCaption(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === 'ArrowDown') {
            e.preventDefault()
            persistCaption(e.currentTarget.value)
            continueBelow()
          } else if (e.key === 'Escape' || (e.key === 'ArrowUp' && !e.currentTarget.value)) {
            e.preventDefault()
            selectSelf()
          }
        }}
        enterKeyHint="next"
        aria-label="ছবির ক্যাপশন"
        placeholder="ছবির ক্যাপশন লিখুন (ঐচ্ছিক)"
        className="mt-1.5 w-full rounded-lg border border-dashed border-neutral-300 bg-neutral-50 px-3 py-1.5 text-[13px] text-neutral-700 placeholder:text-neutral-400 focus:border-brand-500 focus:bg-white focus:outline-none"
      />
      {status === 'saving' && <span className="block mt-0.5 text-[11px] text-neutral-400">ক্যাপশন সংরক্ষণ হচ্ছে…</span>}
      {status === 'saved' && <span className="block mt-0.5 text-[11px] text-emerald-600">ছবির ক্যাপশন সংরক্ষিত হয়েছে</span>}
      {status === 'error' && (
        <span className="block mt-0.5 text-[11px] text-amber-600">
          সংবাদে ক্যাপশন থাকবে, তবে মিডিয়া লাইব্রেরিতে সংরক্ষণ করা যায়নি
        </span>
      )}
    </NodeViewWrapper>
  )
}

export const FigureImage = Node.create({
  name: 'figureImage',
  group: 'block',
  atom: true,
  draggable: true,
  selectable: true,

  addAttributes() {
    return {
      src: { default: null },
      alt: { default: '' },
      caption: { default: '' },
      id: { default: null },
      // Round 12: set while the picked file is still uploading (the node
      // shows the local preview meanwhile). Never written to the HTML.
      uploadKey: { default: null, rendered: false, parseHTML: () => null },
    }
  },

  parseHTML() {
    return [
      {
        tag: 'figure',
        getAttrs: (el) => {
          const dom = el as HTMLElement
          const img = dom.querySelector('img')
          if (!img) return false
          const idMatch = (img.getAttribute('class') ?? '').match(/wp-image-(\d+)/)
          return {
            src: img.getAttribute('src'),
            alt: img.getAttribute('alt') ?? '',
            caption: dom.querySelector('figcaption')?.textContent?.trim() ?? '',
            id: idMatch ? Number(idMatch[1]) : null,
          }
        },
      },
    ]
  },

  renderHTML({ node }) {
    const { src, alt, caption, id } = node.attrs as FigureImageAttrs
    const img: DOMOutputSpec = ['img', { src, alt: alt ?? '', class: id ? `wp-image-${id}` : null }]
    const text = (caption ?? '').trim()
    return text
      ? ['figure', { class: 'wp-block-image' }, img, ['figcaption', { class: 'wp-element-caption' }, text]]
      : ['figure', { class: 'wp-block-image' }, img]
  },

  addNodeView() {
    return ReactNodeViewRenderer(FigureImageView)
  },
})
