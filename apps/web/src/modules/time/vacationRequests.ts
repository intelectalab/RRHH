/**
 * VAC-3 · Solicitud de vacaciones (GET /vacation-requests/{id}). VAC-4 · Calendario del equipo
 * (GET /team-calendar). Días, saldo, solapes, ausentes por día y cobertura los calcula el servidor
 * (regla 11): aquí solo se ordena lo recibido para dibujarlo.
 */
export const VACATION_REQUEST_STATUSES = ['draft', 'pending', 'hr_review', 'approved', 'in_progress', 'taken', 'rejected', 'cancelled'] as const
export type VacationRequestStatus = (typeof VACATION_REQUEST_STATUSES)[number]

export type VacationRequest = {
  id: string
  employment_id: string
  person_id?: string | null
  person_name?: string | null
  employee_code?: string | null
  starts_on: string
  ends_on: string
  days: string
  calendar_days: string | null
  advance_days: string | null
  advance: boolean
  kind: 'leave' | 'advance' | 'sale'
  status: VacationRequestStatus
  notes: string | null
  exception_reason: string | null
  requested_by_name?: string | null
  submitted_at: string | null
  decided_at: string | null
  decision_reason: string | null
  cancelled_at: string | null
  cancellation_reason: string | null
  started_at: string | null
  completed_at: string | null
  approval_request_id: string | null
  approval?: { status: string; pending_approvers: string[] } | null
  allowed_actions: string[]
  version: number
}

export type Issue = { code: string; status?: number; message: string }

export type CoverageRange = { from: string; to: string; count: number; limit: number; org_unit_id?: string; org_unit_name?: string | null }

/** POST /vacation-requests/simulate */
export type VacationSimulation = {
  days: string
  calendar_days: number
  working_days: number
  counts_calendar_days: boolean
  holidays: string[]
  balance: { available: string; advance_available: string; pending_validation: boolean }
  advance_days: string
  coverage: CoverageRange[]
  issues: Issue[]
  warnings: Issue[]
  role: 'employee' | 'manager' | 'hr'
}

export type TeamItem = {
  id: string | null
  source: 'vacation' | 'absence' | 'other'
  label: string
  status: string
  pending: boolean
  starts_on: string
  ends_on: string
  link: string | null
}

export type TeamPerson = {
  employment_id: string
  person_id: string
  person_name: string
  employee_code: string | null
  org_unit_id: string | null
  org_unit_name: string | null
  is_self: boolean
  items: TeamItem[]
}

export type TeamCalendar = {
  from: string
  to: string
  mode: 'team' | 'peers'
  people: TeamPerson[]
  days: Array<{ date: string; absent: number; coverage_exceeded: boolean }>
  alerts: CoverageRange[]
}

/** GET /vacation-requests/{id}/overlaps */
export type VacationOverlaps = {
  id: string
  starts_on: string
  ends_on: string
  org_unit_id: string | null
  people: Array<{ employment_id: string; person_name: string; items: TeamItem[] }>
  coverage: CoverageRange[]
}

export const VACATION_TONE: Record<VacationRequestStatus, string> = {
  draft: 'gr', pending: 'in', hr_review: 'in', approved: 'ok', in_progress: 'ok', taken: 'gr', rejected: 'er', cancelled: 'gr',
}

/**
 * Celda del calendario para una persona y un día: el primer elemento recibido que cubre la fecha (los
 * rangos ya vienen ordenados y sin solapes por persona desde el servidor). Solo compara fechas ISO.
 */
export function itemOn(items: TeamItem[], date: string): TeamItem | null {
  return items.find((i) => i.starts_on <= date && i.ends_on >= date) ?? null
}

/** Tono visual de una celda: pendiente, vacaciones, ausencia u otro (compañeros). */
export function cellTone(item: TeamItem | null): '' | 'pend' | 'vac' | 'abs' | 'other' {
  if (!item) return ''
  if (item.pending) return 'pend'
  if (item.source === 'vacation') return 'vac'
  if (item.source === 'absence') return 'abs'
  return 'other'
}
