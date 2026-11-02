import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
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
        navigateFallbackDenylist: [/^\/wp-json\//],
        cleanupOutdatedCaches: true,
      },
    }),
  ],
  server: {
    host: true,
    port: 5173,
  },
})
