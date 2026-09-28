import { FetchError } from 'ofetch'

/**
 * Offline helpers of the installable app, in localStorage (per device, never sent anywhere):
 * - a small cache of the last loaded data of a page (public cleaning page, shopping cart), kept 7 days;
 * - a queue of changes made without network, replayed in order when back online (plugins/pwa.client.ts).
 * Queued changes must be idempotent (they set a value, they never toggle), since a replay may happen twice.
 */
const MAX_AGE_MS = 7 * 24 * 3600 * 1000
const prefix = () => `rocket-${useAppConfig().rocket.id}:`

function read<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(prefix() + key)
    return raw ? JSON.parse(raw) as T : null
  }
  catch {
    return null
  }
}
function write(key: string, value: unknown) {
  try {
    if (value === null) localStorage.removeItem(prefix() + key)
    else localStorage.setItem(prefix() + key, JSON.stringify(value))
  }
  catch {
    // Storage full or disabled (private browsing): offline mode is simply unavailable.
  }
}

export function offlineSave(key: string, data: unknown) {
  write(`cache:${key}`, { at: Date.now(), data })
}
export function offlineLoad<T>(key: string): T | null {
  const entry = read<{ at: number, data: T }>(`cache:${key}`)
  if (!entry || Date.now() - entry.at > MAX_AGE_MS) return null
  return entry.data
}

/** A failure without an HTTP answer (no network, server unreachable): worth retrying later. */
export function isNetworkError(error: unknown): boolean {
  if (import.meta.client && !navigator.onLine) return true
  return error instanceof FetchError ? !error.statusCode : error instanceof TypeError
}

export interface OfflineChange {
  /** "public": no credentials (secret link); "api": sent with the current session at replay time. */
  kind: 'public' | 'api'
  path: string
  method: 'PATCH' | 'POST'
  body: Record<string, unknown>
  /** A later change with the same key replaces the earlier one (e.g. the same checklist point ticked twice). */
  key: string
}

export function offlineQueue(): OfflineChange[] {
  return read<OfflineChange[]>('queue') ?? []
}
export function offlineEnqueue(change: OfflineChange) {
  const queue = offlineQueue().filter(c => c.key !== change.key)
  queue.push(change)
  write('queue', queue)
  useState('pwa-pending', () => 0).value = queue.length
}
export function offlineSetQueue(queue: OfflineChange[]) {
  write('queue', queue.length ? queue : null)
  useState('pwa-pending', () => 0).value = queue.length
}
