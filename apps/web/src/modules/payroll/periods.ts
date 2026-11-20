/** PER-1 a PER-3 · Tipos y presentación de periodos, incidencias y corte de tiempo (el servidor decide todo). */

export type PeriodStatus = 'open' | 'incidents_closed' | 'calculated' | 'in_review' | 'approved' | 'closed' | 'paid' | 'accounted'
export type PeriodAction = 'close-incidents' | 'reopen-incidents' | 'submit' | 'approve' | 'reject' | 'close' | 'reopen' | 'reopen-calculation'

export type PayrollGroup = { id: string; code: string; name: string; legal_entity_id: string; legal_entity_name?: string; currency: string; payment_day?: number; cutoff_day?: number; status: string }

export type PayrollPeriod = {
  id: string
  payroll_group_id: string
  payroll_group_name?: string
  kind: string
  period_year: number
  period_number: number
  starts_on: string
  ends_on: string
  payment_date?: string
  attendance_from?: string
  attendance_to?: string
  status: PeriodStatus
  time_consumed_at?: string
  return_reason?: string
  reopen_reason?: string
  counts?: { incidents: Record<'pending' | 'approved' | 'applied' | 'rejected', number> }
  allowed_actions: PeriodAction[]
}

export type PayrollIncident = {
  id: string
  employment_id: string
  person_name?: string
  employee_code?: string
  concept_code?: string
  concept_name?: string
  quantity?: string
  amount?: { amount: string; currency: string }
  source: string
  status: 'pending' | 'approved' | 'applied' | 'rejected'
  notes?: string
  created_by_name?: string
  editable?: boolean
  allowed_actions: string[]
}

export type ConsumeResult = { employments: number; created: number; updated: number; removed: number; differences: number; cutoffs: number; missing_concepts: string[]; out_of_cutoff: { from: string; to: string } | null }

export const PERIOD_TONE: Record<PeriodStatus, string> = {
  open: 'tag-info',
  incidents_closed: 'tag-info',
  calculated: '',
  in_review: 'tag-warn',
  approved: 'tag-ok',
  closed: 'tag-ok',
  paid: 'tag-ok',
  accounted: 'tag-ok',
}

/** Acciones que piden motivo (devolver, reabrir y volver a calcular). */
export const NEEDS_REASON: PeriodAction[] = ['reject', 'reopen', 'reopen-calculation']

/** Etiqueta corta del periodo: «2026-11» o «2026-11 · Gratificación». */
export function periodCode(p: Pick<PayrollPeriod, 'period_year' | 'period_number'>): string {
  return `${p.period_year}-${String(p.period_number).padStart(2, '0')}`
}

/** Las incidencias de módulos (ausencias, vacaciones, diferencias) no se editan en planilla (RN-PER-09). */
export function isFromModule(i: Pick<PayrollIncident, 'source'>): boolean {
  return i.source !== 'manual' && i.source !== 'import'
}
