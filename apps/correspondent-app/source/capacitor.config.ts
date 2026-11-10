import type { CapacitorConfig } from '@capacitor/cli'

/**
 * r31 — মিহা সংবাদদাতা Android app.
 *
 * The screens are built with `npm run build:native` into dist-native/ and
 * packed inside the APK. They are served inside the app under the address
 * https://correspondent.meeha.news, the same as the website, so the site's
 * login cookie, CORS rules and links work unchanged; requests to meeha.news
 * (the API) go to the internet as usual.
 */
const config: CapacitorConfig = {
  appId: 'news.meeha.correspondent',
  appName: 'মিহা সংবাদদাতা',
  webDir: 'dist-native',
  server: {
    hostname: 'correspondent.meeha.news',
    androidScheme: 'https',
  },
  android: {
    // file inputs, camera and mic come from the WebView with Android's own prompts
    allowMixedContent: false,
    backgroundColor: '#ffffff',
  },
  plugins: {
    PushNotifications: {
      presentationOptions: ['badge', 'sound', 'alert'],
    },
  },
}

export default config
