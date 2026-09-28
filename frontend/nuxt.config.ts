import { fileURLToPath } from 'node:url'

export default defineNuxtConfig({
  // The Rocket core layer (npm "@rocket/core", from GitHub): layout, dashboard, accounts, applications, updates…
  // ROCKET_CORE_LAYER: a local checkout of rocket-core/nuxt, to work on both at once.
  extends: [
    process.env.ROCKET_CORE_LAYER || fileURLToPath(new URL('./node_modules/@rocket/core/nuxt', import.meta.url)),
  ],
  compatibilityDate: '2026-09-01',
  modules: ['@nuxt/eslint'],
  app: {
    head: {
      title: 'Rocket Clean',
      // Installable app (manifest served by server/routes, service worker public/sw.js, plugins/pwa.client.ts).
      link: [
        { rel: 'manifest', href: '/manifest.webmanifest', key: 'manifest' },
        { rel: 'icon', type: 'image/svg+xml', href: '/icon.svg' },
        { rel: 'apple-touch-icon', sizes: '180x180', href: '/apple-touch-icon.png' },
      ],
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1, viewport-fit=cover' },
        { name: 'theme-color', content: '#059669' },
        { name: 'mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-status-bar-style', content: 'default' },
        { name: 'apple-mobile-web-app-title', content: 'Clean' },
      ],
    },
  },
  runtimeConfig: {
    public: {
      // Service worker of the installable app: NUXT_PUBLIC_PWA=false switches it off (and unregisters it).
      pwa: true,
      apiBase: 'http://localhost:9000',
      // Dashboard shortcuts.
      docsUrl: 'https://github.com/fayouz/rocket-clean/tree/develop/docs/content',
      changelogUrl: 'https://github.com/fayouz/rocket-clean/blob/develop/CHANGELOG.md',
    },
  },
})
