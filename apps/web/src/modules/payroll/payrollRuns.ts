/**
 * NOM-6 · NOM-8 · NOM-10 · Tipos y ayudas de presentación del cálculo de planilla. Todo monto, avance y
 * explicación lo calcula el servidor; aquí solo se elige qué mostrar (regla 11).
 */
export type Money = { amount: string; currency: string }

export type PayrollRun = {
  id: string
  payroll_period_id: string
  run_number: number
  kind: 'simulation' | 'calculation' | 'recalculation'
  status: 'queued' | 'running' | 'completed' | 'failed' | 'discarded' | 'approved' | 'closed'
  progress: { total: number; calculated: number; failed: number; pending: number; percent: number }
  failure_reason: string | null
  totals: { earnings: string; deductions: string; net: string; employer_cost: string; currency?: string } | null
  created_at: string
}

export type RunEmployment = {
  id: string
  employment_id: string
  employee_name: string
  employee_code: string | null
  status: 'calculated' | 'error' | 'excluded'
  errors: { code: string; message: string }[]
  totals?: { earnings: Money; deductions: Money; net: Money; employer_cost: Money } | null
}

export type Explanation = {
  concept_code: string
  formula: string | null
  source: 'formula' | 'incidents' | 'tramos' | 'retroactive'
  version: { concept_version_id: string | null; valid_from: string | null }
  parameters: { code: string; value: string | null; valid_from: string | null; pending_validation: boolean }[]
  steps: string[]
  tramos: (Explanation & { index: number; from: string; to: string; regime: string; amount: string })[]
}

export type RunLine = {
  id: string
  concept_code: string
  concept_name: string
  kind: string
  origin: 'calculated' | 'retroactive' | 'incident' | 'manual' | 'carryover'
  amount?: Money
  explanation?: Explanation
}

export type SmallBusinessStatus = {
  legal_entity_id: string
  labor_regime_code: string | null
  requires_accreditation: boolean
  accreditation_status: string | null
  accredited_on: string | null
  registry_number: string | null
  grace_period_ends_on: string | null
  consecutive_years_required: number | null
  consecutive_years_exceeding: number
  years: { fiscal_year: number; sales_in_uit: string; cap_in_uit: string | null; exceeds: boolean }[]
  alert: 'none' | 'exit_risk' | 'permanence_due' | 'permanence'
  proposed_regime_for_new_hires: string | null
}

export type AnnualFigure = { id: string; legal_entity_id: string; fiscal_year: number; net_sales: Money; uit_value: Money; sales_in_uit: number; average_headcount: number | null }

/** Tono de la etiqueta de estado de una corrida. */
export const RUN_TONE: Record<PayrollRun['status'], string> = {
  queued: 'gr', running: 'in', completed: 'ok', failed: 'er', discarded: 'gr', approved: 'ok', closed: 'gr',
}

/** Una corrida sigue trabajando mientras está en cola o en curso: la pantalla vuelve a consultar su avance. */
export function isActive(run: Pick<PayrollRun, 'status'>): boolean {
  return run.status === 'queued' || run.status === 'running'
}

/** Solo se reanuda lo que falló o quedó a medias; solo se descarta lo que no se aprobó ni cerró. */
export function canResume(run: Pick<PayrollRun, 'status'>): boolean {
  return run.status === 'failed' || run.status === 'running' || run.status === 'queued'
}

export function canDiscard(run: Pick<PayrollRun, 'status'>): boolean {
  return !['approved', 'closed', 'discarded'].includes(run.status)
}

/** Tono de la alerta del régimen micro o pequeña empresa (RN-PE-MYP-12). */
export const ALERT_TONE: Record<SmallBusinessStatus['alert'], 'ok' | 'warn' | 'error' | 'info'> = {
  none: 'ok', exit_risk: 'warn', permanence_due: 'error', permanence: 'info',
}
