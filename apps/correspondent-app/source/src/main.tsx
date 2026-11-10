import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import './index.css'
import App from './App.tsx'
import { AuthProvider } from './lib/AuthContext'
import { ErrorBoundary } from './components/ErrorBoundary'
import { PwaUpdater } from './lib/PwaUpdater'
import { AppUpdater } from './components/AppUpdater'
import { NetActivityBadge } from './components/MeehaLoader'
import { IS_NATIVE, startNative } from './lib/native'

void startNative()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    {IS_NATIVE ? <AppUpdater /> : <PwaUpdater />}
    <NetActivityBadge />
    <ErrorBoundary>
      <BrowserRouter>
        <AuthProvider>
          <App />
        </AuthProvider>
      </BrowserRouter>
    </ErrorBoundary>
  </StrictMode>,
)
