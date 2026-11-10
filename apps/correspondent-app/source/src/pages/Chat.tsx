import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import ChatBox from '../chat/ChatBox'
import { loadSoundPrefs, playSound, saveSoundPrefs } from '../lib/sounds'

/**
 * r29 (with newsroom-os 1.19.0) — চ্যাট: মিহা পরিবার, my বিভাগ room, rooms
 * the desk added me to, and my direct line to the desk (এডিটর ও পাবলিশার).
 */
export default function Chat() {
  const [params, setParams] = useSearchParams()
  const room = Number(params.get('room')) || null
  const [prefs, setPrefs] = useState(loadSoundPrefs)
  const [showSound, setShowSound] = useState(false)
  const set = (p: Partial<typeof prefs>) => {
    const next = { ...prefs, ...p }
    setPrefs(next)
    saveSoundPrefs(next)
  }
  return (
    <div className="flex flex-col h-[calc(100dvh-64px-env(safe-area-inset-bottom))]">
      {!room && (
        <div className="flex items-center gap-2 px-4 pt-4 pb-1 bg-white">
          <h1 className="flex-1 text-[20px] font-bold text-neutral-900">চ্যাট</h1>
          <button type="button" onClick={() => setShowSound((v) => !v)} className="w-9 h-9 rounded-full hover:bg-neutral-100" aria-label="সাউন্ড">
            🔊
          </button>
        </div>
      )}
      {!room && showSound && (
        <div className="mx-4 mb-1 rounded-xl bg-neutral-50 px-3 py-2 space-y-1.5" data-testid="sound-settings">
          {(
            [
              ['chat', 'নতুন মেসেজের শব্দ'],
              ['urgent', 'জরুরি মেসেজের শব্দ'],
            ] as const
          ).map(([k, l]) => (
            <div key={k} className="flex items-center gap-2 text-[13px]">
              <label className="flex-1 flex items-center gap-2">
                <input type="checkbox" checked={prefs[k]} onChange={(e) => set({ [k]: e.target.checked })} /> {l}
              </label>
              <button type="button" onClick={() => playSound(k, prefs.volume)} className="text-brand-600 font-medium">
                ▶ শুনুন
              </button>
            </div>
          ))}
        </div>
      )}
      <div className="flex-1 min-h-0">
        <ChatBox variant="page" roomId={room} onRoomChange={(id) => setParams(id ? { room: String(id) } : {}, { replace: !!room && !!id })} />
      </div>
    </div>
  )
}
