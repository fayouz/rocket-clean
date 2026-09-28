export interface Place {
  id: string
  name: string
  /** "local": created in Rocket Clean (standalone); "place": a place of Rocket Place. */
  source: 'local' | 'place'
}

export interface StockLine { id: string, name: string, level: 'ok' | 'low' | 'empty' }

export type CleaningType = 'rental' | 'personal' | 'maintenance'
export type CleaningOrigin = 'host' | 'pms' | 'place' | 'clean' | 'recurrence'

export type CleaningStatus = 'todo' | 'in_progress' | 'done' | 'cancelled'

export interface CleaningTask {
  id: string
  placeId: string
  placeName: string
  label: string
  type: CleaningType
  origin: CleaningOrigin
  /** Application that created it (app token). */
  originApp: string | null
  /** Cents. */
  cost: number | null
  /** Personal/maintenance cleaning overlapping an occupied period of the place. */
  conflict: boolean
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

export interface CleaningRecurrence {
  id: string
  placeId: string
  placeName: string
  type: CleaningType
  label: string
  frequency: 'weekly' | 'monthly'
  weekdays: number[]
  monthDay: number | null
  nth: number | null
  nthWeekday: number | null
  time: string
  durationMinutes: number
  assignee: CleaningAssignee | null
  checklist: string[]
  cost: number | null
  active: boolean
  startsOn: string
  endsOn: string | null
}

export interface OccupiedPeriod { from: string, until: string, externalRef: string | null }
