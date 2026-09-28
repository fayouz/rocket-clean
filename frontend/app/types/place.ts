export interface Place {
  id: string
  name: string
  /** "local": created in Rocket Clean (standalone); "place": a place of Rocket Place. */
  source: 'local' | 'place'
}

export interface StockLine { id: string, name: string, level: 'ok' | 'low' | 'empty' }

export type CleaningStatus = 'todo' | 'in_progress' | 'done' | 'cancelled'

export interface CleaningTask {
  id: string
  placeId: string
  placeName: string
  label: string
  scheduledAt: string
  dueAt: string | null
  status: CleaningStatus
  late: boolean
  assignee: { id: string, email: string, name: string } | null
  externalRef: string | null
  notes: string | null
  checklist: { label: string, done: boolean }[]
  photos: { fileId: string, name: string, moment: 'before' | 'after' | 'damage', at: string }[]
  stockReports: { stockLevelId: string, item: string, level: 'ok' | 'low' | 'empty', at: string }[]
  startedAt: string | null
  completedAt: string | null
  /** Public view (secret link) only: stock levels of the place, and when the link expires. */
  stock?: StockLine[]
  expiresAt?: string
}

export interface CleaningAssignee { id: string, email: string, name: string }
