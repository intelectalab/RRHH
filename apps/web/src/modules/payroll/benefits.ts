/** BSO-2 y BSO-3 · Depósitos de CTS y distribución de utilidades: tipos y reglas de presentación (el cálculo es del servidor). */

import type { Money } from './review'

export type CtsDepositAction = 'deposit' | 'notify'

export type CtsDeposit = {
  id: string
  employment_id: string
  person_name?: string
  employee_code?: string
  period_code: string
  computable_months: number
  computable_days: number
  deposit_bank_code: string | null
  due_on: string | null
  deposited_on: string | null
  deposit_reference: string | null
  status: 'calculated' | 'approved' | 'deposited' | 'notified'
  allowed_actions: CtsDepositAction[]
  amount?: Money
  computable_remuneration?: Money
  last_gratification?: Money
}

export type DistributionAction = 'calculate' | 'approve' | 'mark-paid'

export type ProfitSharingItem = {
  employment_id: string
  person_name: string
  employee_code: string
  terminated: boolean
  days_worked: string
  cap_applied: boolean
  remunerations?: Money
  amount_by_days?: Money
  amount_by_remuneration?: Money
  amount?: Money
}

export type ProfitSharingDistribution = {
  id: string
  legal_entity_id: string
  fiscal_year: number
  activity: 'industria' | 'comercio_restaurantes' | 'otras_actividades'
  participation_rate: string | null
  workers_count: number | null
  total_days_worked: string | null
  status: 'draft' | 'calculated' | 'approved' | 'paid'
  allowed_actions: DistributionAction[]
  warnings: { code: string; message: string }[]
  net_income_before_tax?: Money | null
  total_amount?: Money | null
  items?: ProfitSharingItem[]
}

export const ACTIVITIES = ['industria', 'comercio_restaurantes', 'otras_actividades'] as const

export const DEPOSIT_TONE: Record<CtsDeposit['status'], string> = {
  calculated: 'wa', approved: 'in', deposited: 'ok', notified: 'ok',
}

export const DISTRIBUTION_TONE: Record<ProfitSharingDistribution['status'], string> = {
  draft: 'gr', calculated: 'wa', approved: 'ok', paid: 'ok',
}
