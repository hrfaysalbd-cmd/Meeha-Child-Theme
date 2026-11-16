import { useSearchParams } from 'react-router-dom'
import { InboxPanel, type InboxTab } from '../components/InboxBox'

/** r35/r36 — /app/inbox: the full ইনবক্স (কিউ বক্স + চ্যাট বক্স). */
export default function Inbox() {
  const [params, setParams] = useSearchParams()
  const tab: InboxTab = params.get('tab') === 'chat' ? 'chat' : 'queue'
  const room = Number(params.get('room')) || null
  return (
    <div className={`${tab === 'chat' ? 'max-w-[1240px] -mx-4 sm:mx-auto' : 'max-w-[760px] mx-auto'}`}>
      <div className={`bg-white overflow-hidden ${tab === 'chat' ? 'sm:rounded-2xl sm:ring-1' : 'rounded-2xl ring-1'} ring-neutral-200/80`}>
        <InboxPanel
          full
          initialTab={tab}
          key={tab}
          roomId={room}
          onRoomChange={(id) => setParams(id ? { tab: 'chat', room: String(id) } : { tab: 'chat' }, { replace: !!room && !!id })}
        />
      </div>
    </div>
  )
}
