import { useNavigate } from 'react-router-dom'
import { IconEdit, IconUser } from '../components/Icon'

export default function Landing() {
  const navigate = useNavigate()

  return (
    <div className="flex-1 flex flex-col">
      <div className="flex-1 flex flex-col items-center justify-center px-8 text-center">
        <div className="w-16 h-16 rounded-2xl bg-brand-600 text-white flex items-center justify-center text-2xl font-bold mb-5 shadow-lg shadow-brand-600/30">
          মি
        </div>
        <h1 className="text-[22px] font-bold text-neutral-900 mb-1.5">মিহা নিউজ সংবাদদাতা</h1>
        <p className="text-[14px] text-neutral-500 leading-relaxed">
          এলাকার খবর পাঠান, প্রতিবেদন লিখুন, আপনার পরিসংখ্যান দেখুন এবং সম্মানী ও কমিশন আয় করুন।
        </p>
      </div>

      <div className="px-6 pb-10 flex flex-col gap-3">
        <button
          onClick={() => navigate('/apply')}
          className="w-full rounded-2xl bg-brand-600 text-white py-4 text-[16px] font-semibold flex items-center justify-center gap-2 shadow-lg shadow-brand-600/25 active:scale-[0.98] transition"
        >
          <IconEdit className="w-5 h-5" />
          সংবাদদাতা আবেদন
        </button>
        <button
          onClick={() => navigate('/login')}
          className="w-full rounded-2xl bg-neutral-100 text-neutral-800 py-4 text-[16px] font-semibold flex items-center justify-center gap-2 active:scale-[0.98] transition"
        >
          <IconUser className="w-5 h-5" />
          সংবাদদাতা লগইন
        </button>
        <p className="text-center text-[12px] text-neutral-400 mt-2">
          ইতিমধ্যে অনুমোদিত সংবাদদাতা হলে "লগইন" চাপুন। নতুন হলে "আবেদন" করুন — NID সহ প্রয়োজনীয় তথ্য দিয়ে।
        </p>
      </div>
    </div>
  )
}
