import { Component, type ErrorInfo, type ReactNode } from 'react'

interface Props {
  children: ReactNode
}

interface State {
  error: Error | null
}

// Same defense-in-depth pattern used in the correspondent app: an unhandled
// render error (an unexpected API response shape, a null a page didn't
// guard against) must never blank the whole dashboard with no explanation.
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error) {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // eslint-disable-next-line no-console
    console.error('Unhandled app error:', error, info.componentStack)
  }

  render() {
    if (this.state.error) {
      return (
        <div className="min-h-screen flex flex-col items-center justify-center text-center px-8 bg-neutral-50">
          <h2 className="text-[18px] font-bold text-neutral-900 mb-2">কিছু একটা সমস্যা হয়েছে</h2>
          <p className="text-[14px] text-neutral-500 mb-6 leading-relaxed max-w-sm">
            ড্যাশবোর্ড লোড করতে সমস্যা হয়েছে। আবার চেষ্টা করুন।
          </p>
          <button
            onClick={() => {
              this.setState({ error: null })
              window.location.href = '/'
            }}
            className="rounded-xl bg-brand-600 text-white px-5 py-2.5 text-[14px] font-semibold"
          >
            প্রথম পাতায় ফিরে যান
          </button>
        </div>
      )
    }
    return this.props.children
  }
}
