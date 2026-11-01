/**
 * Round 25 — "লেখা, তীর ও চিহ্ন": layers drawn over the design to point at
 * something in the photo — free text, arrows, circles / boxes / spotlight,
 * numbered pins and symbols, news stickers and fact-check stamps, a round
 * inset photo, and blur / pixelate to hide a face or a number plate.
 * Everything is moved by dragging it in the preview; the details are here.
 */
import { bnNumber, inputClass } from '../ui'
import { IconCopy, IconEye, IconPlus, IconTrash } from '../Icon'
import { LAYER_LABEL, SWATCHES, type Layer, type LayerKind } from '../../lib/photocard/style'

export interface InsetPhoto {
  key: string
  thumb: string
  label: string
}

const ADD: { t: LayerKind; label: string; icon: string; extra?: Partial<Layer> }[] = [
  { t: 'text', label: 'লেখা', icon: 'অ' },
  { t: 'arrow', label: 'তীর', icon: '➜' },
  { t: 'shape', label: 'গোল দাগ', icon: '◯' },
  { t: 'shape', label: 'চৌকো দাগ', icon: '▢', extra: { s: 'rect', w: 30, h: 20 } },
  { t: 'shape', label: 'স্পটলাইট', icon: '◉', extra: { s: 'spot', w: 30, h: 30 } },
  { t: 'mark', label: 'নম্বর', icon: '①' },
  { t: 'mark', label: 'পিন', icon: '📍', extra: { s: 'pin' } },
  { t: 'sticker', label: 'স্টিকার', icon: '🏷' },
  { t: 'inset', label: 'গোল ছবি', icon: '◐' },
  { t: 'blur', label: 'ঝাপসা', icon: '▒' },
]

export default function LayerPanel({
  layers,
  sel,
  onSelect,
  onAdd,
  onPatch,
  onRemove,
  onOrder,
  onDuplicate,
  photos,
  stickers,
  marks,
}: {
  layers: Layer[]
  sel: string | null
  onSelect: (id: string | null) => void
  onAdd: (t: LayerKind, extra?: Partial<Layer>) => void
  onPatch: (id: string, patch: Partial<Layer>, merge?: boolean) => void
  onRemove: (id: string) => void
  onOrder: (id: string, dir: -1 | 1) => void
  onDuplicate: (id: string) => void
  photos: InsetPhoto[]
  stickers: Record<string, string>
  marks: Record<string, string>
}) {
  const cur = layers.find((l) => l.id === sel) || null
  const p = (patch: Partial<Layer>, merge = false) => cur && onPatch(cur.id, patch, merge)

  const name = (l: Layer) => {
    if (l.t === 'text') return `“${(l.text || '').split('\n')[0].slice(0, 18)}”`
    if (l.t === 'sticker') return `স্টিকার: ${stickers[l.s || 'live'] || l.s}`
    if (l.t === 'mark') return `${marks[l.s || 'num'] || 'চিহ্ন'}${l.s === 'num' || !l.s ? ` ${bnNumber(l.n || 1)}` : ''}`
    if (l.t === 'shape') return l.s === 'rect' ? 'চৌকো দাগ' : l.s === 'spot' ? 'স্পটলাইট' : 'গোল দাগ'
    return LAYER_LABEL[l.t]
  }

  return (
    <div>
      <div className="grid grid-cols-5 gap-1.5" aria-label="লেয়ার যোগ করুন">
        {ADD.map((a, i) => (
          <button
            key={i}
            type="button"
            onClick={() => {
              if (a.t === 'inset' && !photos.length) return
              const extra: Partial<Layer> = { ...(a.extra || {}) }
              if (a.t === 'mark' && (!a.extra || !a.extra.s)) extra.n = layers.filter((l) => l.t === 'mark' && (l.s || 'num') === 'num').length + 1
              if (a.t === 'inset') extra.photoKey = photos[photos.length > 1 ? 1 : 0].key
              onAdd(a.t, extra)
            }}
            disabled={a.t === 'inset' && !photos.length}
            title={a.t === 'inset' && !photos.length ? 'আগে ওপরে একটি ছবি যোগ করুন' : `${a.label} যোগ করুন`}
            className="flex flex-col items-center gap-0.5 rounded-lg border border-neutral-200 bg-white hover:border-brand-500 hover:text-brand-700 py-1.5 text-neutral-700 disabled:opacity-40"
          >
            <span className="text-[17px] leading-none" aria-hidden="true">
              {a.icon}
            </span>
            <span className="text-[10.5px] font-medium">{a.label}</span>
          </button>
        ))}
      </div>

      {layers.length > 0 ? (
        <ul className="mt-3 divide-y divide-neutral-100 rounded-xl border border-neutral-200 overflow-hidden">
          {layers
            .map((l, i) => ({ l, i }))
            .reverse()
            .map(({ l, i }) => {
              const on = l.id === sel
              return (
                <li key={l.id} className={`flex items-center gap-1 px-2 py-1.5 ${on ? 'bg-brand-50' : 'bg-white'}`}>
                  <button type="button" onClick={() => onSelect(on ? null : l.id)} className={`flex-1 min-w-0 text-left text-[12.5px] truncate ${l.hidden ? 'text-neutral-400 line-through' : 'text-neutral-800'} ${on ? 'font-semibold' : ''}`}>
                    <span className="text-neutral-400 mr-1">{bnNumber(i + 1)}.</span>
                    {name(l)}
                  </button>
                  <Mini label="ওপরে আনুন" onClick={() => onOrder(l.id, 1)} disabled={i === layers.length - 1}>
                    ▲
                  </Mini>
                  <Mini label="নিচে পাঠান" onClick={() => onOrder(l.id, -1)} disabled={i === 0}>
                    ▼
                  </Mini>
                  <Mini label={l.hidden ? 'দেখান' : 'লুকান'} onClick={() => onPatch(l.id, { hidden: !l.hidden })}>
                    <IconEye className={`w-3.5 h-3.5 ${l.hidden ? 'opacity-40' : ''}`} />
                  </Mini>
                  <Mini label="কপি" onClick={() => onDuplicate(l.id)}>
                    <IconCopy className="w-3.5 h-3.5" />
                  </Mini>
                  <Mini label="মুছুন" onClick={() => onRemove(l.id)} danger>
                    <IconTrash className="w-3.5 h-3.5" />
                  </Mini>
                </li>
              )
            })}
        </ul>
      ) : (
        <p className="text-[11.5px] text-neutral-400 mt-2">ছবির কিছু চিহ্নিত করতে ওপরের যেকোনোটি যোগ করুন — তারপর প্রিভিউতে টেনে জায়গামতো বসান।</p>
      )}

      {cur && (
        <div className="mt-3 rounded-xl bg-neutral-50 p-3 space-y-2.5">
          <p className="text-[12px] font-semibold text-neutral-700 flex items-center gap-2">
            {LAYER_LABEL[cur.t]} সম্পাদনা
            <span className="font-normal text-neutral-400">প্রিভিউতে টেনে সরান · কোণের বিন্দু টেনে মাপ · Delete চাপলে মুছবে</span>
          </p>

          {cur.t === 'text' && (
            <>
              <textarea rows={2} className={`${inputClass} text-[13px]`} value={cur.text || ''} maxLength={140} onChange={(e) => p({ text: e.target.value }, true)} aria-label="লেখা" autoFocus />
              <Row label="ধরন">
                {(
                  [
                    ['box', 'বাক্স'],
                    ['pill', 'পিল'],
                    ['outline', 'ঘের'],
                    ['shadow', 'ছায়া'],
                    ['none', 'শুধু লেখা'],
                  ] as [Layer['bg'], string][]
                ).map(([v, l]) => (
                  <Chip key={v} on={(cur.bg || 'box') === v} onClick={() => p({ bg: v })}>
                    {l}
                  </Chip>
                ))}
              </Row>
              <Row label="অক্ষর">
                <Chip on={cur.fam !== 'serif'} onClick={() => p({ fam: 'sans' })}>
                  সাধারণ
                </Chip>
                <Chip on={cur.fam === 'serif'} onClick={() => p({ fam: 'serif' })}>
                  <span style={{ fontFamily: 'serif' }}>সেরিফ</span>
                </Chip>
                <Chip on={!!cur.bold} onClick={() => p({ bold: !cur.bold })}>
                  <b>মোটা</b>
                </Chip>
              </Row>
              <Swatches label="লেখার রং" value={cur.color} onPick={(c) => p({ color: c })} />
              {cur.bg !== 'none' && cur.bg !== 'shadow' && <Swatches label="পেছনের রং" value={cur.bgc} onPick={(c) => p({ bgc: c })} />}
              <Slider label="মাপ" min={20} max={120} value={cur.size || 46} onChange={(v) => p({ size: v }, true)} />
              <Slider label="ঘোরানো" min={-30} max={30} value={cur.rot || 0} onChange={(v) => p({ rot: v }, true)} unit="°" />
            </>
          )}

          {cur.t === 'arrow' && (
            <>
              <Swatches label="রং" value={cur.color} onPick={(c) => p({ color: c })} />
              <Slider label="পুরুত্ব" min={4} max={30} value={cur.size || 12} onChange={(v) => p({ size: v }, true)} />
              <Slider label="বাঁক" min={-100} max={100} value={Math.round((cur.curve || 0) * 100)} onChange={(v) => p({ curve: v / 100 }, true)} />
              <Row label="">
                <Chip on={!!cur.both} onClick={() => p({ both: !cur.both })}>
                  দুই মাথা
                </Chip>
                <Chip on={cur.outline !== false} onClick={() => p({ outline: cur.outline === false })}>
                  সাদা ঘের
                </Chip>
              </Row>
              <p className="text-[11px] text-neutral-400">প্রিভিউতে তীরের মাথা বা লেজের বিন্দু টেনে দিক বদলান।</p>
            </>
          )}

          {cur.t === 'shape' && (
            <>
              <Row label="আকার">
                {(
                  [
                    ['circle', 'গোল'],
                    ['rect', 'চৌকো'],
                    ['spot', 'স্পটলাইট'],
                  ] as [string, string][]
                ).map(([v, l]) => (
                  <Chip key={v} on={(cur.s || 'circle') === v} onClick={() => p({ s: v })}>
                    {l}
                  </Chip>
                ))}
              </Row>
              <Swatches label="রং" value={cur.color} onPick={(c) => p({ color: c })} />
              <Slider label="দাগ" min={2} max={24} value={cur.size || 8} onChange={(v) => p({ size: v }, true)} />
              {cur.s === 'spot' ? (
                <Slider label="বাকিটা অন্ধকার" min={20} max={90} value={Math.round((cur.dim ?? 0.6) * 100)} onChange={(v) => p({ dim: v / 100 }, true)} unit="%" />
              ) : (
                <Row label="">
                  <Chip on={!!cur.dash} onClick={() => p({ dash: !cur.dash })}>
                    ভাঙা দাগ
                  </Chip>
                  <Chip on={!!cur.fill} onClick={() => p({ fill: !cur.fill })}>
                    হালকা ভরাট
                  </Chip>
                </Row>
              )}
              <Slider label="চওড়া" min={4} max={95} value={Math.round(cur.w || 24)} onChange={(v) => p({ w: v }, true)} unit="%" />
              <Slider label="উঁচু" min={4} max={95} value={Math.round(cur.h || 24)} onChange={(v) => p({ h: v }, true)} unit="%" />
            </>
          )}

          {cur.t === 'mark' && (
            <>
              <div className="flex flex-wrap gap-1">
                {Object.entries(marks).map(([k, l]) => (
                  <Chip key={k} on={(cur.s || 'num') === k} onClick={() => p({ s: k })}>
                    {l}
                  </Chip>
                ))}
              </div>
              {(cur.s || 'num') === 'num' && <Slider label="নম্বর" min={1} max={20} value={cur.n || 1} onChange={(v) => p({ n: v })} />}
              <Swatches label="রং" value={cur.color} onPick={(c) => p({ color: c })} />
              <Slider label="মাপ" min={30} max={200} value={cur.size || 80} onChange={(v) => p({ size: v }, true)} />
            </>
          )}

          {cur.t === 'sticker' && (
            <>
              <div className="flex flex-wrap gap-1">
                {Object.entries(stickers).map(([k, l]) => (
                  <Chip key={k} on={(cur.s || 'live') === k} onClick={() => p({ s: k, text: '' })}>
                    {l}
                  </Chip>
                ))}
              </div>
              <input className={`${inputClass} !py-1.5 text-[12.5px]`} value={cur.text || ''} maxLength={24} onChange={(e) => p({ text: e.target.value }, true)} placeholder={`নিজের লেখা (না দিলে “${stickers[cur.s || 'live'] || ''}”)`} aria-label="স্টিকারের লেখা" />
              <Slider label="মাপ" min={20} max={90} value={cur.size || 40} onChange={(v) => p({ size: v }, true)} />
              <Slider label="ঘোরানো" min={-30} max={30} value={cur.rot ?? 0} onChange={(v) => p({ rot: v }, true)} unit="°" />
            </>
          )}

          {cur.t === 'inset' && (
            <>
              <div className="flex gap-1.5 overflow-x-auto pb-1">
                {photos.map((ph) => (
                  <button key={ph.key} type="button" onClick={() => p({ photoKey: ph.key })} title={ph.label} className={`shrink-0 w-14 h-14 rounded-full overflow-hidden border-2 ${cur.photoKey === ph.key ? 'border-brand-600' : 'border-transparent'}`}>
                    <img src={ph.thumb} alt="" className="w-full h-full object-cover" />
                  </button>
                ))}
              </div>
              <Row label="আকার">
                {(
                  [
                    ['circle', 'গোল'],
                    ['round', 'গোল কোণ'],
                    ['square', 'চৌকো'],
                  ] as [string, string][]
                ).map(([v, l]) => (
                  <Chip key={v} on={(cur.s || 'circle') === v} onClick={() => p({ s: v })}>
                    {l}
                  </Chip>
                ))}
              </Row>
              <Swatches label="রিং" value={cur.ring} onPick={(c) => p({ ring: c })} none />
              <Slider label="মাপ" min={12} max={60} value={cur.size || 28} onChange={(v) => p({ size: v }, true)} unit="%" />
              <Slider label="জুম" min={100} max={300} value={Math.round((cur.zoom || 1) * 100)} onChange={(v) => p({ zoom: v / 100 }, true)} unit="%" />
              <Slider label="ছবি ←→" min={0} max={100} value={cur.focus?.x ?? 50} onChange={(v) => p({ focus: { x: v, y: cur.focus?.y ?? 50 } }, true)} />
              <Slider label="ছবি ↑↓" min={0} max={100} value={cur.focus?.y ?? 50} onChange={(v) => p({ focus: { x: cur.focus?.x ?? 50, y: v } }, true)} />
              <input className={`${inputClass} !py-1.5 text-[12.5px]`} value={cur.label || ''} maxLength={30} onChange={(e) => p({ label: e.target.value }, true)} placeholder="নিচে নাম (ঐচ্ছিক)" aria-label="গোল ছবির নিচে নাম" />
            </>
          )}

          {cur.t === 'blur' && (
            <>
              <Row label="ধরন">
                <Chip on={cur.mode !== 'pixel'} onClick={() => p({ mode: 'blur' })}>
                  ঝাপসা
                </Chip>
                <Chip on={cur.mode === 'pixel'} onClick={() => p({ mode: 'pixel' })}>
                  পিক্সেল
                </Chip>
                <Chip on={cur.s !== 'rect'} onClick={() => p({ s: 'ellipse' })}>
                  গোল
                </Chip>
                <Chip on={cur.s === 'rect'} onClick={() => p({ s: 'rect' })}>
                  চৌকো
                </Chip>
              </Row>
              <Slider label="মাত্রা" min={6} max={40} value={cur.amount || 18} onChange={(v) => p({ amount: v }, true)} />
              <Slider label="চওড়া" min={4} max={95} value={Math.round(cur.w || 18)} onChange={(v) => p({ w: v }, true)} unit="%" />
              <Slider label="উঁচু" min={4} max={95} value={Math.round(cur.h || 16)} onChange={(v) => p({ h: v }, true)} unit="%" />
              <p className="text-[11px] text-neutral-400">মুখ, গাড়ির নম্বর বা ফোন নম্বর ঢাকতে।</p>
            </>
          )}
        </div>
      )}
    </div>
  )
}

function Mini({ label, onClick, children, disabled, danger }: { label: string; onClick: () => void; children: React.ReactNode; disabled?: boolean; danger?: boolean }) {
  return (
    <button type="button" onClick={onClick} disabled={disabled} aria-label={label} title={label} className={`w-7 h-7 shrink-0 rounded-md text-[10px] flex items-center justify-center hover:bg-neutral-100 disabled:opacity-25 ${danger ? 'text-red-600' : 'text-neutral-500'}`}>
      {children}
    </button>
  )
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center flex-wrap gap-1.5">
      {label && <span className="w-16 text-[11.5px] text-neutral-500">{label}</span>}
      {children}
    </div>
  )
}

function Chip({ on, onClick, children }: { on: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button type="button" onClick={onClick} aria-pressed={on} className={`px-2 py-0.5 rounded-full border text-[11.5px] ${on ? 'border-brand-600 bg-brand-50 text-brand-700 font-semibold' : 'border-neutral-200 bg-white text-neutral-600'}`}>
      {children}
    </button>
  )
}

function Swatches({ label, value, onPick, none }: { label: string; value?: string; onPick: (c: string) => void; none?: boolean }) {
  return (
    <div className="flex items-center gap-1.5 flex-wrap">
      <span className="w-16 text-[11.5px] text-neutral-500">{label}</span>
      {none && (
        <button type="button" onClick={() => onPick('none')} aria-label="নেই" title="নেই" className={`w-6 h-6 rounded-full border bg-white text-[10px] text-neutral-400 ${value === 'none' ? 'ring-2 ring-brand-600 ring-offset-1' : 'border-neutral-300'}`}>
          ✕
        </button>
      )}
      {SWATCHES.map((c) => (
        <button key={c} type="button" onClick={() => onPick(c)} aria-label={c} title={c} className={`w-6 h-6 rounded-full border border-black/10 ${(value || '').toUpperCase() === c ? 'ring-2 ring-brand-600 ring-offset-1' : ''}`} style={{ background: c }} />
      ))}
      <label className="w-6 h-6 rounded-full border border-dashed border-neutral-400 overflow-hidden relative cursor-pointer" title="অন্য রং">
        <IconPlus className="w-3.5 h-3.5 absolute inset-0 m-auto text-neutral-500" />
        <input type="color" value={/^#[0-9a-f]{6}$/i.test(value || '') ? value : '#FFC20E'} onChange={(e) => onPick(e.target.value.toUpperCase())} className="opacity-0 absolute inset-0 cursor-pointer" />
      </label>
    </div>
  )
}

function Slider({ label, min, max, value, onChange, unit = '' }: { label: string; min: number; max: number; value: number; onChange: (v: number) => void; unit?: string }) {
  return (
    <label className="flex items-center gap-2 text-[11.5px] text-neutral-600">
      <span className="w-16 text-neutral-500">{label}</span>
      <input type="range" min={min} max={max} value={value} onChange={(e) => onChange(Number(e.target.value))} className="flex-1" />
      <output className="w-10 text-right">
        {bnNumber(Math.round(value))}
        {unit}
      </output>
    </label>
  )
}
