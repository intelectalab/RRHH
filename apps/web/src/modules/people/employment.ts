import type { StatusTone } from '@/ui/components/Status'

/** COL-2 · COL-9 · Relación laboral tal como la devuelve /employments (los campos sensibles pueden faltar). */
export type Employment = {
  id: string
  person_id: string
  person_name: string | null
  document_number_masked: string | null
  legal_entity_id: string
  legal_entity_name: string | null
  employee_code: string
  kind: 'employee' | 'intern' | 'apprentice'
  worker_category: 'executive' | 'employee' | 'worker'
  hire_date: string
  seniority_date: string | null
  probation_ends_on: string | null
  termination_date: string | null
  status: EmploymentStatus
  rehire_of_employment_id: string | null
  work_email: string | null
  work_phone: string | null
  work_phone_ext: string | null
  job_profile_id: string | null
  job_profile_name: string | null
  position_id: string | null
  position_title: string | null
  org_unit_id: string | null
  org_unit_name: string | null
  site_id: string | null
  site_name: string | null
  cost_center_id: string | null
  cost_center_name: string | null
  manager_employment_id: string | null
  manager_name: string | null
  work_modality: 'onsite' | 'remote' | 'hybrid' | null
  labor_regime_code: string | null
  contract_type_id: string | null
  contract_end_date: string | null
  base_salary?: { amount: string; currency: string } | null
  /** COL-11 · Cese registrado (sin los revertidos); el detalle solo llega a quien puede cesar. */
  termination?: Termination | null
  /** COL-10 · Otras relaciones de la persona dentro del alcance de quien consulta. */
  other_employments?: RelatedEmployment[]
  allowed_actions?: string[]
  version?: number
}

export type Termination = {
  id: string
  status: string
  termination_reason_id: string
  reason_code: string
  reason_name: string
  category: string
  statutory_code: string | null
  initiated_by: 'employee' | 'employer' | 'mutual'
  notice_date: string | null
  last_working_day: string
  termination_date: string
  is_rehire_eligible: boolean | null
  detail?: string | null
}

export type RelatedEmployment = {
  id: string
  legal_entity_id: string
  legal_entity_name: string
  employee_code: string
  hire_date: string
  termination_date: string | null
  status: EmploymentStatus
  rehire_of_employment_id: string | null
}

export type TerminationReason = {
  id: string
  code: string
  name: string
  category: string
  is_voluntary: boolean
  statutory_code: string | null
  initiated_by: 'employee' | 'employer' | 'mutual'
}

export type EmploymentStatus = 'pending' | 'active' | 'suspended' | 'terminating' | 'terminated' | 'cancelled'

export const EMPLOYMENT_STATUSES: EmploymentStatus[] = ['pending', 'active', 'suspended', 'terminating', 'terminated', 'cancelled']

/** El color acompaña al texto del estado, nunca lo reemplaza. */
export const STATUS_TONE: Record<EmploymentStatus, StatusTone> = {
  pending: 'in', active: 'ok', suspended: 'wa', terminating: 'wa', terminated: 'gr', cancelled: 'gr',
}

export type HireOptions = {
  legal_entities: Array<{ id: string; name: string; currency: string }>
  job_profiles: Array<{ id: string; name: string; code: string }>
  positions: Array<{ id: string; code: string; title: string; legal_entity_id: string; job_profile_id: string; org_unit_id: string; site_id: string | null; cost_center_id: string | null; seats: number; status: string }>
  org_units: Array<{ id: string; name: string; legal_entity_id: string | null }>
  sites: Array<{ id: string; name: string; legal_entity_id: string | null }>
  cost_centers: Array<{ id: string; name: string; code: string; legal_entity_id: string | null }>
  contract_types: Array<{ id: string; code: string; name: string; category: string; requires_end_date: boolean; requires_objective_cause: boolean }>
  labor_regimes: Array<{ code: string; name: string }>
  entity_regimes: Array<{ legal_entity_id: string; labor_regime_code: string }>
  document_types: string[]
  rules: { position_required: boolean; automatic_code: boolean; register_sex: boolean }
}

/** Lo que pertenece a una empresa o es compartido (sin empresa). */
export function ofEntity<T extends { legal_entity_id: string | null }>(rows: T[], entity: string): T[] {
  return rows.filter((r) => r.legal_entity_id === null || r.legal_entity_id === entity)
}
