/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/react" />

/** Build id (yyyymmddhhmmss, UTC); the same value is in /version.json. */
declare const __BUILD_ID__: string
/** True in the Android app build (Capacitor, `vite build --mode native`). */
declare const __NATIVE__: boolean
