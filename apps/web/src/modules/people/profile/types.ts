import type { EmploymentStatus } from '../employment'

/** COL-3 · Cabecera de la ficha 360 (GET /employments/{id}/profile). La antigüedad la calcula el servidor. */
export type ProfileHeader = {
  id: string
  person_id: string
  person_name: string | null
  first_names: string | null
  has_photo: boolean
  employee_code: string
  legal_entity_id: string
  legal_entity_name: string | null
  job_profile_name: string | null
  position_title: string | null
  org_unit_name: string | null
  manager_employment_id: string | null
  manager_name: string | null
  status: EmploymentStatus
  hire_date: string
  termination_date: string | null
  seniority: Seniority | null
  is_self: boolean
  relations: Array<{ id: string; legal_entity_name: string; employee_code: string; status: EmploymentStatus; hire_date: string; termination_date: string | null }>
  allowed_actions: string[]
  version: number
}

export type Seniority = { from: string; to: string; years: number; months: number; days: number; total_days: number }

/** COL-5 · Dato laboral vigente: valor, nombre visible, desde cuándo rige y la historia a la que enlaza. */
export type WorkField = { value: string | null; label: string | null; since: string | null; history: 'assignments' | 'status' | null }

export type WorkData = {
  employment_id: string
  as_of: string
  fields: Record<WorkFieldKey, WorkField>
  seniority: Seniority | null
}

export const WORK_FIELDS = [
  'employee_code', 'legal_entity', 'site', 'org_unit', 'job_profile', 'position', 'manager', 'cost_center', 'work_modality',
  'hire_date', 'seniority_date', 'kind', 'worker_category', 'labor_regime', 'contract_type', 'schedule', 'status',
] as const

export type WorkFieldKey = (typeof WORK_FIELDS)[number]

/** COL-5 · Asignación con vigencia (historia de movimientos). */
export type Assignment = {
  id: string
  job_profile_id: string
  job_profile_name: string | null
  position_id: string | null
  position_title: string | null
  org_unit_id: string
  org_unit_name: string | null
  site_id: string
  site_name: string | null
  cost_center_id: string | null
  cost_center_name: string | null
  manager_employment_id: string | null
  manager_name: string | null
  work_modality: 'onsite' | 'remote' | 'hybrid'
  valid_from: string
  valid_to: string | null
  reason: string | null
  kind: MovementKind | 'hire' | null
}

export const MOVEMENT_KINDS = ['promotion', 'transfer', 'manager_change', 'cost_center_change', 'data_correction'] as const
export type MovementKind = (typeof MOVEMENT_KINDS)[number]

/** Campos de la asignación que cambia un movimiento de personal. */
export const MOVEMENT_FIELDS = ['position_id', 'job_profile_id', 'org_unit_id', 'site_id', 'cost_center_id', 'manager_employment_id', 'work_modality'] as const
export type MovementField = (typeof MOVEMENT_FIELDS)[number]

export const MOVEMENT_STATUSES = ['draft', 'pending_approval', 'approved', 'scheduled', 'applied', 'rejected', 'cancelled', 'replaced'] as const
export type MovementStatus = (typeof MOVEMENT_STATUSES)[number]

/** HIS-2 · HIS-4 · Movimiento de personal (GET /personnel-actions/{id}); el antes y el después traen su nombre. */
export type PersonnelAction = {
  id: string
  employment_id: string
  kind: MovementKind
  effective_on: string
  reason: string | null
  status: MovementStatus
  changes: Partial<Record<MovementField, string | null>>
  before: Partial<Record<MovementField, { value: string | null; label: string | null }>>
  after: Partial<Record<MovementField, { value: string | null; label: string | null }>>
  approval: { status: string; pending_approvers: string[] } | null
  decision_reason: string | null
  replaces_personnel_action_id: string | null
  replaced_by_personnel_action_id: string | null
  is_retroactive: boolean
  affected_from: string | null
  affected_to: string | null
  allowed_actions: Array<'edit' | 'cancel' | 'correct'>
  person_name?: string | null
  employee_code?: string | null
}

/** COL-4 · Persona (GET /people/{id}); los datos personales faltan sin permiso de campo. */
export type PersonData = {
  id: string
  first_names: string
  paternal_surname: string
  maternal_surname: string | null
  preferred_name: string | null
  full_name: string
  document_type: string
  document_number?: string
  document_number_masked: string | null
  birth_date?: string | null
  sex?: string | null
  nationality_code: string | null
  marital_status?: string | null
  personal_email?: string | null
  mobile_phone?: string | null
  version: number
}

export type Address = { id: string; kind: 'home' | 'mailing' | 'other'; address?: string; district_code?: string | null; country_code: string | null; valid_from: string | null; is_current: boolean }

export type EmergencyContact = { id: string; full_name?: string; relationship: string | null; phone?: string; sort_order: number | null }

export type SensitiveData = { id: string | null; disability?: string; blood_type?: string; consent: { id: string; status: string; version: string; channel: string; granted_at: string } | null }

export const CHANGE_FIELDS = ['first_names', 'paternal_surname', 'maternal_surname', 'preferred_name', 'birth_date', 'marital_status', 'nationality_code', 'personal_email', 'mobile_phone', 'address'] as const
export type ChangeField = (typeof CHANGE_FIELDS)[number]

/** RN-COL-06 · Solicitud de cambio de datos propuesta por el propio colaborador. */
export type ChangeRequest = {
  id: string
  person_id: string
  person_name: string | null
  changes: Partial<Record<ChangeField | 'sex', string | null>>
  applied_fields: string[]
  pending_fields: string[]
  status: 'pending' | 'approved' | 'rejected' | 'cancelled'
  requested_by_name: string | null
  reviewed_at: string | null
  rejection_reason: string | null
  allowed_actions: string[]
  created_at: string
}

export const MARITAL = ['single', 'married', 'cohabiting', 'divorced', 'widowed'] as const

/** Contexto que recibe cada pestaña de la ficha. */
export type ProfileTabContext = {
  header: ProfileHeader
  reload: () => Promise<void>
  toast: (text: string, kind?: 'ok' | 'error') => void
}
