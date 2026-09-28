import type { InstallPromptEvent } from '~/composables/usePwa'
import type { OfflineChange } from '~/utils/offline'

/**
 * Installable app: service worker (public/sw.js), install prompt, network status and replay of the changes made
 * offline. The service worker is only registered in production builds (never with "nuxt dev" or in tests), and can
 * be switched off with NUXT_PUBLIC_PWA=false; an old worker is then unregistered.
 */
export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()
  const toast = useToast()
  const api = useApi()
  const { online, pending, synced, installEvent, standalone, ios } = usePwa()

  // Install prompt (Android/Chrome/Edge): kept for the "Installer l'application" button.
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    installEvent.value = event as InstallPromptEvent
  })
  window.addEventListener('appinstalled', () => installEvent.value = null)
  standalone.value = window.matchMedia('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true
  ios.value = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

  // Replay of the changes made offline, in order; stops at the first network failure, drops refused ones.
  let flushing = false
  async function flush() {
    if (flushing || !navigator.onLine) return
    flushing = true
    let queue = offlineQueue()
    const start = queue.length
    try {
      while (queue.length) {
        const change = queue[0] as OfflineChange
        try {
          if (change.kind === 'api') await api(change.path, { method: change.method, body: change.body })
          else await $fetch(change.path, { baseURL: config.public.apiBase as string, method: change.method, body: change.body, headers: { Accept: 'application/json' } })
        }
        catch (error) {
          if (isNetworkError(error)) break
          toast.add({ title: 'Modification hors ligne refusée', description: apiErrorMessage(error), color: 'warning' })
        }
        queue = queue.slice(1)
        offlineSetQueue(queue)
      }
    }
    finally {
      flushing = false
    }
    if (start && !queue.length) {
      toast.add({ title: 'Modifications hors ligne envoyées', color: 'success', icon: 'i-lucide-cloud-check' })
    }
    if (start !== queue.length) synced.value++
  }

  online.value = navigator.onLine
  pending.value = offlineQueue().length
  window.addEventListener('online', () => {
    online.value = true
    flush()
  })
  window.addEventListener('offline', () => online.value = false)
  flush()

  if (!('serviceWorker' in navigator)) return
  if (import.meta.dev || import.meta.test || String(config.public.pwa) === 'false') {
    navigator.serviceWorker.getRegistrations().then(regs => regs.forEach(r => r.unregister())).catch(() => {})
    return
  }

  // A new version waits until the user reloads: the page may hold unsaved input.
  const hadController = navigator.serviceWorker.controller !== null
  let reloading = false
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!hadController || reloading) return
    reloading = true
    window.location.reload()
  })
  const offerUpdate = (worker: ServiceWorker) => toast.add({
    title: 'Nouvelle version disponible',
    description: 'Recharge pour en profiter.',
    icon: 'i-lucide-refresh-cw',
    duration: 0,
    actions: [{ label: 'Recharger', color: 'primary', onClick: () => worker.postMessage('SKIP_WAITING') }],
  })
  const version = encodeURIComponent(String(config.public.appVersion || 'dev'))
  navigator.serviceWorker.register(`/sw.js?v=${version}`, { scope: '/' }).then((registration) => {
    if (registration.waiting && hadController) offerUpdate(registration.waiting)
    registration.addEventListener('updatefound', () => {
      const worker = registration.installing
      worker?.addEventListener('statechange', () => {
        if (worker.state === 'installed' && navigator.serviceWorker.controller) offerUpdate(worker)
      })
    })
    // Deployed while the app stays open (phone kept on the checklist): check every hour.
    setInterval(() => registration.update().catch(() => {}), 3600_000)
  }).catch(() => {})
})
