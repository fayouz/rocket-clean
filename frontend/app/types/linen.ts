// Linen module ("Linge", API /api/linen/*): kept apart from the cleaning types so it can leave as Rocket Laundry.
export type LinenState = 'clean' | 'in_use' | 'dirty' | 'at_laundry' | 'damaged' | 'lost'
export type LinenLocation = 'reserve' | 'logement'
export type LinenUsage = 'rental' | 'personal'
export interface LinenLine { typeId: string, qty: number }
export interface LinenType { id: string, name: string, stockItemId: string | null, weightGrams: number | null, position: number }
export interface LinenKit { id: string, name: string, lines: LinenLine[] }
export interface LinenNeed { placeId: string, kitId: string, kitName: string, units: number, kitsPerUnit: number, parLevel: number }
export type LinenStates = Record<LinenState, number>
export interface LinenSummary {
  placeId: string
  placeName: string
  states: LinenStates
  byLocation: Record<LinenLocation, LinenStates>
  types: { typeId: string, name: string, states: LinenStates, total: number }[]
  kits: (LinenNeed & { cleanKits: number })[]
}
export interface LinenMovement {
  id: string
  placeId: string
  typeId: string
  typeName: string
  from: LinenState | null
  to: LinenState | null
  qty: number
  reason: string
  externalRef: string | null
  origin: 'clean' | 'host' | 'linen'
  usage: LinenUsage
  createdBy: string | null
  createdAt: string
}
export type Readiness = 'ready' | 'tight' | 'missing'
export interface LinenArrival {
  from: string
  until: string
  externalRef: string | null
  status: Readiness
  kits: { kitId: string, kitName: string, required: number, needed: number, available: number, status: Readiness }[]
}
export interface LinenAlert { type: 'kits' | 'batch_overdue' | 'losses', level: 'error' | 'warning', placeId: string, placeName: string, message: string, at: string | null, link: string }
export interface LinenWash {
  id: string
  placeId: string
  placeName: string
  label: string
  scheduledAt: string
  status: 'todo' | 'in_progress' | 'done' | 'cancelled'
  steps: Record<'machine' | 'sechage' | 'pliage', boolean>
  lines: LinenLine[]
  usage: LinenUsage
  assignee: { id: string, email: string, name: string } | null
  completedAt: string | null
}
export interface Laundry { id: string, name: string, orderEmail: string | null, pricing: 'kg' | 'piece', pricePerKg: number | null, piecePrices: Record<string, number>, turnaroundDays: number, active: boolean }
export interface LinenBatch {
  id: string
  laundry: { id: string, name: string }
  placeId: string
  placeName: string
  usage: LinenUsage
  status: 'sent' | 'returned'
  sentLines: LinenLine[]
  weightGrams: number | null
  sentAt: string
  expectedAt: string
  overdue: boolean
  returnedLines: LinenLine[]
  damagedLines: LinenLine[]
  discrepancies: { typeId: string, typeName: string, missing: number, damaged: number }[]
  returnedAt: string | null
  cost: number | null
  note: string | null
  emailSentAt: string | null
}
export interface CleaningLinenView { kits: LinenKit[], types: LinenType[], movements: LinenMovement[] }
