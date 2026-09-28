/**
 * Web app manifest of Rocket Clean (installable on Android and iPhone). Served by /manifest.webmanifest, and by
 * /m/<token>/manifest.webmanifest for the public cleaning page, so that the installed app opens that cleaning.
 * Colours: emerald-600 (primary of app.config.ts), white background.
 */
export function pwaManifest(startUrl = '/menage') {
  return {
    id: '/',
    name: 'Rocket Clean',
    short_name: 'Clean',
    description: 'Les ménages de tes lieux : planning, checklist, photos et stock, depuis un téléphone.',
    lang: 'fr',
    dir: 'ltr',
    start_url: startUrl,
    // The whole origin, so the public page /m/<token> is part of the app too.
    scope: '/',
    display: 'standalone',
    orientation: 'portrait',
    theme_color: '#059669',
    background_color: '#ffffff',
    categories: ['productivity', 'business'],
    icons: [
      { src: '/icons/clean-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
      { src: '/icons/clean-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
      { src: '/icons/clean-maskable-192.png', sizes: '192x192', type: 'image/png', purpose: 'maskable' },
      { src: '/icons/clean-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
      { src: '/icon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' },
    ],
    shortcuts: [
      { name: 'Ménages du jour', short_name: 'Ménages', url: '/menage', icons: [{ src: '/icons/clean-192.png', sizes: '192x192' }] },
    ],
  }
}

export function sendManifest(event: Parameters<typeof setResponseHeader>[0], startUrl?: string) {
  setResponseHeader(event, 'Content-Type', 'application/manifest+json; charset=utf-8')
  setResponseHeader(event, 'Cache-Control', 'no-cache')
  return pwaManifest(startUrl)
}
