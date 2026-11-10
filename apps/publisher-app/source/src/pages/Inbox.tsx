import { useSearchParams } from 'react-router-dom'
import { InboxPanel, type InboxTab } from '../components/InboxBox'

/** r35 — /app/inbox: the full ইনবক্স (কিউ বক্স + চ্যাট বক্স). */
export default function Inbox() {
  const [params] = useSearchParams()
  const tab: InboxTab = params.get('tab') === 'chat' ? 'chat' : 'queue'
  return (
    <div className="max-w-[760px] mx-auto">
      <div className="bg-white rounded-2xl ring-1 ring-neutral-200/80 overflow-hidden">
        <InboxPanel full initialTab={tab} key={tab} />
      </div>
    </div>
  )
}
