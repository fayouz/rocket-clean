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
    },
  },
  runtimeConfig: {
    public: {
      apiBase: 'http://localhost:9000',
      // Dashboard shortcuts.
      docsUrl: 'https://github.com/fayouz/rocket-clean/tree/develop/docs/content',
      changelogUrl: 'https://github.com/fayouz/rocket-clean/blob/develop/CHANGELOG.md',
    },
  },
})
