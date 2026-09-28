import type { LinenLine } from '~/types/linen'

export const LINEN_STATES = ['clean', 'in_use', 'dirty', 'at_laundry', 'damaged', 'lost'] as const
export const LINEN_STATE_LABEL: Record<string, string> = { clean: 'Propre', in_use: 'En place', dirty: 'Sale', at_laundry: 'Blanchisserie', damaged: 'Abîmé', lost: 'Perdu' }
export const LINEN_STATE_COLOR: Record<string, 'success' | 'info' | 'warning' | 'secondary' | 'error' | 'neutral'> = { clean: 'success', in_use: 'info', dirty: 'warning', at_laundry: 'secondary', damaged: 'error', lost: 'neutral' }
export const LINEN_LOCATION_LABEL: Record<string, string> = { reserve: 'Réserve', logement: 'Logement' }
export const READINESS_LABEL: Record<string, string> = { ready: 'Prêt', tight: 'Juste', missing: 'Manque' }
export const READINESS_COLOR: Record<string, 'success' | 'warning' | 'error'> = { ready: 'success', tight: 'warning', missing: 'error' }
export const LINEN_USAGE_LABEL: Record<string, string> = { rental: 'Location', personal: 'Personnel' }
export const WASH_STEP_LABEL: Record<string, string> = { machine: 'Machine', sechage: 'Séchage', pliage: 'Pliage' }

/** "2 × Drap housse, 4 × Taie" from lines and the types' names. */
export function linenLines(lines: LinenLine[], names: Record<string, string>): string {
  return lines.map(l => `${l.qty} × ${names[l.typeId] ?? 'Linge'}`).join(', ')
}

/** Short random key making a report idempotent (the offline queue may replay it). */
export const linenKey = () => Math.random().toString(36).slice(2, 12) + Date.now().toString(36)
