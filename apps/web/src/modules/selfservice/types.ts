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

/** ESS-3 · Mis documentos publicados. */
export type DocumentsCardData = {
  count: number
  recent: Array<{ id: string; title: string; issued_on: string | null }>
}

/** ESS-4 · Constancias en curso y la última emitida. */
export type CertificatesCardData = {
  pending_requests: number
  last_issued: { id: string; kind: string; kind_name: string; issued_at: string | null } | null
}

/** ESS-5 · Solicitudes a RRHH abiertas, las que esperan respuesta y las últimas. */
export type HrRequestsCardData = {
  open: number
  waiting_info: number
  latest: Array<{ id: string; number: string; subject: string; status: TicketStatus }>
}

type CardBase<K extends string, D> = { key: K; module: string; href: string; pending: number; data: D }

/** RN-ESS-02 · Solo llegan las tarjetas de los módulos contratados y con permiso. */
export type HomeCard =
  | CardBase<'profile', ProfileCardData>
  | CardBase<'vacations', VacationsCardData>
  | CardBase<'absences', AbsencesCardData>
  | CardBase<'documents', DocumentsCardData>
  | CardBase<'certificates', CertificatesCardData>
  | CardBase<'hr_requests', HrRequestsCardData>

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

/** ESS-4 · Constancias. */
export type CertificateKind = 'employment_certificate' | 'income_certificate' | 'vacation_certificate'
export type CertificateStatus = 'requested' | 'in_progress' | 'issued' | 'rejected'
export type CertificateType = { kind: CertificateKind; name: string; description: string; auto_issue: boolean }
export type CertificateRequest = {
  id: string
  employment_id: string
  employee_code: string | null
  person_name: string | null
  kind: CertificateKind
  kind_name: string
  purpose: string | null
  status: CertificateStatus
  auto_issued: boolean
  document_id: string | null
  issued_at: string | null
  decided_at: string | null
  rejection_reason: string | null
  approval: { id: string; status: string; level: number | null; pending_approvers: string[] } | null
  created_at: string | null
  allowed_actions: Array<'issue' | 'reject'>
}

/** ESS-5 · Solicitudes a RRHH (ME-31). */
export type TicketStatus = 'new' | 'in_progress' | 'waiting_info' | 'resolved' | 'closed' | 'cancelled'
export type TicketAction = 'assign' | 'request-info' | 'resolve' | 'close' | 'reopen'
export type TicketCategory = {
  id: string
  code: string
  name: string
  description: string | null
  default_assignee_role_id: string | null
  default_assignee_role_name: string | null
  requires_attachment: boolean
  is_confidential: boolean
  sort_order: number
  status: 'active' | 'archived'
}
export type TicketMessage = {
  id: string
  event: 'created' | 'assigned' | 'info_requested' | 'replied' | 'resolved' | 'reopened' | 'closed' | 'comment'
  body: string
  is_internal_note: boolean
  author_name: string | null
  mine: boolean
  has_file: boolean
  file_name: string | null
  created_at: string | null
}
export type Ticket = {
  id: string
  number: string
  category_id: string
  category_name: string | null
  person_name: string | null
  requester_name: string | null
  subject: string
  description: string | null
  status: TicketStatus
  priority: 'low' | 'normal' | 'high' | 'urgent'
  assignee_user_id: string | null
  assignee_name: string | null
  resolution_due_at: string | null
  overdue?: boolean
  allowed_actions: TicketAction[]
  can_message?: boolean
  messages?: TicketMessage[]
  created_at: string | null
  updated_at: string | null
}
