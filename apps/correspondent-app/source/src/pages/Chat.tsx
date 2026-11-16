import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import ChatBox from '../chat/ChatBox'

/**
 * r29 (with newsroom-os 1.19.0) — চ্যাট: মিহা পরিবার, my বিভাগ room, rooms
 * the desk added me to, and my direct line to the desk (এডিটর ও পাবলিশার).
 */
export default function Chat() {
  const [params, setParams] = useSearchParams()
  const room = Number(params.get('room')) || null
  const navigate = useNavigate()
  const box = useRef<HTMLDivElement>(null)
  const [kbH, setKbH] = useState<number | null>(null)
  // r46: when the phone's keyboard opens over the page (the Android app runs
  // edge to edge, so the page is not always made shorter), fit the chat into
  // the part of the screen that is still visible, so the box being typed in
  // stays above the keyboard.
  useEffect(() => {
    const vv = window.visualViewport
    if (!vv) return
    const fit = () => {
      const covered = window.innerHeight - vv.height
      if (covered > 120 && box.current) {
        const top = box.current.getBoundingClientRect().top
        setKbH(Math.max(200, Math.round(vv.height + vv.offsetTop - Math.max(0, top))))
      } else setKbH(null)
    }
    vv.addEventListener('resize', fit)
    vv.addEventListener('scroll', fit)
    return () => {
      vv.removeEventListener('resize', fit)
      vv.removeEventListener('scroll', fit)
    }
  }, [])
  return (
    <div ref={box} className="flex flex-col h-[calc(100dvh-64px-env(safe-area-inset-bottom))]" style={kbH ? { height: kbH } : undefined}>
      {!room && (
        <div className="flex items-center gap-2 px-4 pt-4 pb-1 bg-white">
          <h1 className="flex-1 text-[20px] font-bold text-neutral-900">চ্যাট</h1>
          <button type="button" onClick={() => navigate('/app/settings/notifications')} className="w-9 h-9 rounded-full hover:bg-neutral-100" aria-label="নোটিফিকেশন ও শব্দ">
            🔊
          </button>
        </div>
      )}
      <div className="flex-1 min-h-0">
        <ChatBox variant="page" roomId={room} onRoomChange={(id) => setParams(id ? { room: String(id) } : {}, { replace: !!room && !!id })} />
      </div>
    </div>
  )
}
