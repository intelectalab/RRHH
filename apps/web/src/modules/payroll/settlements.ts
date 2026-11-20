/** LIQ-1 a LIQ-4 · Liquidación de beneficios sociales: tipos y reglas de presentación (el cálculo es del servidor). */

import type { Money } from './review'

export type SettlementStatus = 'draft' | 'calculated' | 'pending_approval' | 'approved' | 'paid' | 'documents_delivered' | 'voided'
export type SettlementAction = 'calculate' | 'submit' | 'approve' | 'return' | 'mark-paid' | 'deliver-documents' | 'void'
export type DueStatus = 'no_deadline' | 'on_time' | 'due_soon' | 'overdue' | 'paid_on_time' | 'paid_late'
export type DeductionKind = 'loan_balance' | 'advance_balance'

export type SettlementLine = {
  id?: string
  kind: string
  concept_code: string
  concept_name: string
  concept_kind: string
  quantity?: string | null
  amount: Money
  explanation: string
}

export type SettlementNotice = { code: string; message: string }

export type SettlementDocument = { id: string; kind: string; title: string; document_id: string | null; status: string; signed_at?: string | null; delivered_at?: string | null }

export type Settlement = {
  id: string
  employment_id: string
  employee_name?: string
  employee_code?: string
  termination_date: string
  reason_code?: string
  reason_name?: string
  status: SettlementStatus
  severance_applies: boolean
  include_pending_salary: boolean
  deductions?: { kind: DeductionKind; amount: string; description?: string | null }[]
  lines?: SettlementLine[]
  totals?: { earnings: string; deductions: string; net: string; employer_contributions: string }
  total?: Money
  due_on?: string
  due_at?: string
  due_status?: DueStatus
  paid_on?: string
  return_reason?: string
  errors?: SettlementNotice[]
  warnings?: SettlementNotice[]
  documents?: SettlementDocument[]
  allowed_actions: SettlementAction[]
}

export type SimulationScenario = {
  termination_date: string
  status: string
  lines: SettlementLine[]
  total: Money
  errors: SettlementNotice[]
  warnings: SettlementNotice[]
}

export type Simulation = { employment_id: string; scenarios: SimulationScenario[]; comparison: { net_difference: Money } | null }

export const SETTLEMENT_TONE: Record<SettlementStatus, string> = {
  draft: 'gr', calculated: 'wa', pending_approval: 'wa', approved: 'in', paid: 'ok', documents_delivered: 'ok', voided: 'gr',
}

export const DUE_TONE: Record<DueStatus, string> = {
  no_deadline: 'gr', on_time: 'in', due_soon: 'wa', overdue: 'er', paid_on_time: 'ok', paid_late: 'er',
}

/** Permiso que exige cada acción (la API lo vuelve a validar). */
export const ACTION_PERMISSION: Record<SettlementAction, string> = {
  calculate: 'payroll.settlements.calculate', submit: 'payroll.settlements.calculate', approve: 'payroll.settlements.approve',
  return: 'payroll.settlements.approve', 'mark-paid': 'payroll.settlements.mark_paid', 'deliver-documents': 'payroll.settlements.mark_paid',
  void: 'payroll.settlements.void',
}

/** Acciones que piden un motivo. */
export const NEEDS_REASON: SettlementAction[] = ['return', 'void']

export function visibleActions(s: Pick<Settlement, 'allowed_actions'>, can: (p: string) => boolean): SettlementAction[] {
  return s.allowed_actions.filter((a) => can(ACTION_PERMISSION[a]))
}
