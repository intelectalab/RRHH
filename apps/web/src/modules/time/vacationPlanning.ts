/**
 * VAC-5 · VAC-6 · Tipos del rol anual, las alertas de acumulación y los acuerdos de vacaciones. El servidor calcula
 * días, saldos y límites (regla 11); aquí solo se decide qué acciones mostrar y con qué color.
 */
export type ScheduleLineStatus = 'proposed' | 'confirmed' | 'declined' | 'converted' | 'withdrawn'
export type AgreementKind = 'fractionation' | 'advance' | 'sale'
export type AgreementStatus = 'draft' | 'issued' | 'active' | 'cancelled'

export type VacationSchedule = {
  id: string
  legal_entity_id: string
  year: number
  name: string
  status: 'open' | 'closed'
  counts: Record<ScheduleLineStatus, number>
  allowed_actions: string[]
}

export type ScheduleLine = {
  id: string
  employment_id: string
  person_name: string | null
  starts_on: string
  ends_on: string
  days: string | null
  status: ScheduleLineStatus
  response_note: string | null
  vacation_request_id: string | null
  allowed_actions: string[]
}

export type AccumulationAlert = {
  employment_id: string
  person_name: string | null
  periods_with_balance: number
  available_days: string
  overdue_periods: number
  expiring_periods: number
  since: string
  explanation: string
}

export type VacationAgreement = {
  id: string
  employment_id: string
  person_name: string | null
  kind: AgreementKind
  status: AgreementStatus
  days: string | null
  effective_on: string
  document_id: string | null
  signed_at: string | null
  payroll_concept_code: string | null
  allowed_actions: string[]
}

export const LINE_TONE: Record<ScheduleLineStatus, string> = {
  proposed: 'tag-info',
  confirmed: 'tag-ok',
  declined: 'tag-warn',
  converted: 'tag-ok',
  withdrawn: '',
}

export const AGREEMENT_TONE: Record<AgreementStatus, string> = {
  draft: '',
  issued: 'tag-info',
  active: 'tag-ok',
  cancelled: 'tag-warn',
}

/** Acciones de una línea que puede ejecutar quien mira: el titular confirma o declina; quien programa retira. */
export function lineActions(line: ScheduleLine, opts: { canSchedule: boolean; isOwn: boolean }): string[] {
  return line.allowed_actions.filter((a) => (a === 'withdraw' ? opts.canSchedule : opts.isOwn || opts.canSchedule))
}

/** Las alertas más urgentes primero: vencidas, luego por vencer, luego por más días acumulados. */
export function sortAlerts(alerts: AccumulationAlert[]): AccumulationAlert[] {
  return [...alerts].sort((a, b) =>
    b.overdue_periods - a.overdue_periods || b.expiring_periods - a.expiring_periods || Number(b.available_days) - Number(a.available_days))
}
