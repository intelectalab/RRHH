import type { EmploymentStatus } from '@/modules/people/employment'
import type { Address, ChangeField, ChangeRequest, EmergencyContact, PersonData, ProfileHeader, Seniority, WorkData } from '@/modules/people/profile/types'
import type { Inbox } from '@/modules/workflow/InboxScreen'

/** ESS-1 · RN-ESS-04 · Relación laboral propia (el servidor solo devuelve las del usuario). */
export type OwnEmployment = {
  id: string
  person_id: string
  legal_entity_id: string
  legal_entity_name: string | null
  employee_code: string
  status: EmploymentStatus
  hire_date: string
  termination_date: string | null
  read_only: boolean
}

export type ProfileCardData = {
  person_name: string | null
  first_names: string | null
  job_profile_name: string | null
  position_title: string | null
  org_unit_name: string | null
  manager_name: string | null
  legal_entity_name: string | null
  seniority: Seniority | null
  pending_changes: number
}

export type VacationsCardData = {
  available_days: string
  scheduled_days: string
  overdue_days: string
  advance_available: string
  pending_validation: boolean
  pending_requests: number
  next: { id: string; starts_on: string; ends_on: string; days: string; status: string } | null
}

export type AbsencesCardData = {
  pending_requests: number
  upcoming: Array<{ id: string; type_name: string; starts_on: string; ends_on: string; status: string }>
}

type CardBase<K extends string, D> = { key: K; module: string; href: string; pending: number; data: D }

/** RN-ESS-02 · Solo llegan las tarjetas de los módulos contratados y con permiso. */
export type HomeCard =
  | CardBase<'profile', ProfileCardData>
  | CardBase<'vacations', VacationsCardData>
  | CardBase<'absences', AbsencesCardData>

export type MeHome = {
  employments: OwnEmployment[]
  employment: OwnEmployment | null
  pending: Inbox
  cards: HomeCard[]
}

/** ESS-2 · GET /me/employment. */
export type MeEmployment = {
  employments: OwnEmployment[]
  employment: OwnEmployment | null
  header?: ProfileHeader
  work_data?: WorkData
  person?: PersonData
  addresses?: Address[]
  emergency_contacts?: EmergencyContact[]
  change_requests?: ChangeRequest[]
  editable_fields?: ChangeField[]
  approval_fields?: string[]
  pending_fields?: string[]
  can_propose?: boolean
}
