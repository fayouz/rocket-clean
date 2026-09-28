import { FetchError } from 'ofetch'

/** Message of an API error (API Platform "detail", Symfony "detail" or HttpException message). */
export function apiErrorMessage(error: unknown): string {
  if (error instanceof FetchError) {
    const data = error.data as { detail?: string, message?: string, 'hydra:description'?: string } | undefined
    return data?.detail || data?.['hydra:description'] || data?.message || error.statusMessage || error.message
  }
  return error instanceof Error ? error.message : String(error)
}

export const dayFr = (d: string) => new Date(d).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })
export const whenFr = (d: string) => new Date(d).toLocaleString('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
export const STOCK_LEVEL_LABEL: Record<string, string> = { ok: 'OK', low: 'Bas', empty: 'Vide' }
export const STOCK_LEVEL_COLOR: Record<string, 'success' | 'warning' | 'error'> = { ok: 'success', low: 'warning', empty: 'error' }
export const CLEANING_STATUS_LABEL: Record<string, string> = { todo: 'À faire', in_progress: 'En cours', done: 'Fait', cancelled: 'Annulé' }
export const CLEANING_STATUS_COLOR: Record<string, 'neutral' | 'info' | 'success' | 'error'> = { todo: 'neutral', in_progress: 'info', done: 'success', cancelled: 'error' }
export const PHOTO_MOMENT_LABEL: Record<string, string> = { before: 'Avant', after: 'Après', damage: 'Dégât' }
export const hourFr = (d: string) => new Date(d).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
