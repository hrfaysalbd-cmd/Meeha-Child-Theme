import react from '@vitejs/plugin-react'
import { defineConfig, type Plugin } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

/**
 * r31: every build gets an id. The web build writes it to /version.json, and
 * the app checks that file (never from cache) to notice a newer deploy and
 * reload itself, even when a phone browser or a host cache holds on to the
 * old index.html. `--mode native` builds the same screens for the Android
 * app (Capacitor): no service worker there, the files live inside the APK.
 */
const BUILD_ID = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14)

function versionFile(): Plugin {
  return {
    name: 'nros-version-file',
    apply: 'build',
    generateBundle() {
      this.emitFile({ type: 'asset', fileName: 'version.json', source: JSON.stringify({ build: BUILD_ID }) + '\n' })
    },
  }
}

// https://vite.dev/config/
export default defineConfig(({ mode }) => ({
  define: {
    __BUILD_ID__: JSON.stringify(BUILD_ID),
    __NATIVE__: JSON.stringify(mode === 'native'),
  },
  build: mode === 'native' ? { outDir: 'dist-native' } : undefined,
  resolve: mode === 'native' ? { alias: { 'virtual:pwa-register/react': '/src/lib/pwa-stub.ts' } } : undefined,
  plugins: [
    react(),
    versionFile(),
    mode !== 'native' && VitePWA({
      registerType: 'autoUpdate',
      // Registered explicitly in src/lib/PwaUpdater.tsx (via
      // virtual:pwa-register/react) instead of the auto-injected script, so
      // the app can force an update check on visibility change and reload
      // immediately once a new version is found — see that file for why.
      injectRegister: false,
      includeAssets: ['icons/*.svg'],
      manifest: {
        name: 'মিহা নিউজ সংবাদদাতা',
        short_name: 'সংবাদদাতা',
        description: 'মিহা নিউজ সংবাদদাতা অ্যাপ — সংবাদ লিখুন, পরিসংখ্যান দেখুন, আয় তুলুন',
        theme_color: '#b91c1c',
        background_color: '#ffffff',
        display: 'standalone',
        orientation: 'portrait',
        start_url: '/',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,ico}'],
        navigateFallbackDenylist: [/^\/wp-json\//, /^\/download\//],
        cleanupOutdatedCaches: true,
        // newsroom-os 1.20.0: push notices (chat, approvals) while the app is closed
        importScripts: ['push-sw.js'],
      },
    }),
  ],
  server: {
    host: true,
    port: 5173,
  },
}))
