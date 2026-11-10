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
  return (
    <div className="flex flex-col h-[calc(100dvh-64px-env(safe-area-inset-bottom))]">
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
