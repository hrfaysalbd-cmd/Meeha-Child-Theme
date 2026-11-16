import { useNavigate } from 'react-router-dom'
import { Segmented } from './ui'
import { IconNews, IconVideo } from './Icon'

/**
 * Top of the "লিখুন" tab: switches between writing an article and
 * submitting a video, so video news needs no sixth bottom tab.
 * `confirmLeave` lets the video editor warn before an upload is abandoned.
 */
export default function WriteModeSwitch({
  mode,
  confirmLeave,
}: {
  mode: 'article' | 'video'
  confirmLeave?: () => boolean
}) {
  const navigate = useNavigate()
  return (
    <div className="px-5 pt-4">
      <Segmented
        ariaLabel="কী জমা দেবেন"
        value={mode}
        onChange={(v) => {
          if (confirmLeave && !confirmLeave()) return
          navigate(v === 'video' ? '/app/video/new' : '/app/write')
        }}
        options={[
          { value: 'article', label: 'সংবাদ', icon: IconNews },
          { value: 'video', label: 'ভিডিও', icon: IconVideo },
        ]}
      />
    </div>
  )
}
