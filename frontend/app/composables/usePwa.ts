export interface InstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

/**
 * State of the installable app, filled by plugins/pwa.client.ts: network status, changes waiting to be sent,
 * install prompt (Android/Chrome) or instructions (iPhone/iPad Safari), and a counter bumped after a replay so
 * pages reload their data.
 */
export function usePwa() {
  const online = useState('pwa-online', () => true)
  const pending = useState('pwa-pending', () => 0)
  const synced = useState('pwa-synced', () => 0)
  const installEvent = useState<InstallPromptEvent | null>('pwa-install-event', () => null)
  const standalone = useState('pwa-standalone', () => false)
  const ios = useState('pwa-ios', () => false)

  const canInstall = computed(() => !standalone.value && (installEvent.value !== null || ios.value))

  /** Android/Chrome: the browser's own dialog. Returns false on iOS, where the page shows the instructions. */
  async function install(): Promise<boolean> {
    const event = installEvent.value
    if (!event) return false
    await event.prompt()
    const choice = await event.userChoice.catch(() => null)
    if (choice?.outcome === 'accepted') installEvent.value = null
    return true
  }

  return { online, pending, synced, installEvent, canInstall, ios, standalone, install }
}
