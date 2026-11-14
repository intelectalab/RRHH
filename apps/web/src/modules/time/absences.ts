/** AUS-2 · Ausencia (GET /absences/{id}). Días, horas y plazos los calcula el servidor (regla 11). */
export type AbsenceAttachment = { id: string; label: string | null; name: string; mime: string; size_bytes: number; uploaded_at: string }

export const ABSENCE_STATUSES = ['draft', 'pending', 'pending_document', 'approved', 'in_progress', 'completed', 'rejected', 'cancelled'] as const
export type AbsenceStatus = (typeof ABSENCE_STATUSES)[number]

export type Absence = {
  id: string
  employment_id: string
  person_id?: string | null
  person_name?: string | null
  employee_code?: string | null
  absence_type_id: string | null
  absence_type_name: string | null
  absence_type_category: string | null
  is_confidential: boolean
  unit: 'days' | 'hours'
  starts_on: string
  ends_on: string
  start_time: string | null
  end_time: string | null
  days: string | null
  hours: string | null
  calendar_days: string | null
  is_paid: boolean | null
  affects_attendance: boolean | null
  /** Motivo de un tipo no confidencial. */
  reason?: string | null
  /** RN-AUS-05: solo viaja con permiso de certificados médicos o al titular. */
  medical_reason?: string | null
  attachments?: AbsenceAttachment[]
  medical_attachments?: AbsenceAttachment[]
  status: AbsenceStatus
  registered_directly: boolean
  document_required: boolean
  document_due_on: string | null
  has_document: boolean
  exception_reason: string | null
  requested_by_name?: string | null
  submitted_at: string | null
  decided_at: string | null
  decision_reason: string | null
  cancelled_at: string | null
  cancellation_reason: string | null
  approval_request_id: string | null
  approval?: { status: string; pending_approvers: string[] } | null
  allowed_actions: string[]
  version: number
}

export type Issue = { code: string; status?: number; message: string }

/** POST /absences/simulate */
export type AbsenceSimulation = {
  unit: 'days' | 'hours'
  days: string | null
  hours: string | null
  calendar_days: number
  working_days: number
  holidays: string[]
  is_paid: boolean
  document_required: boolean
  document_grace_days: number | null
  issues: Issue[]
  warnings: Issue[]
  role: 'employee' | 'manager' | 'hr'
}

export const ABSENCE_TONE: Record<AbsenceStatus, string> = {
  draft: 'gr', pending: 'in', pending_document: 'wa', approved: 'ok', in_progress: 'ok', completed: 'gr', rejected: 'er', cancelled: 'gr',
}

/** Motivo y sustentos que el usuario puede ver, según el tipo sea confidencial o no. */
export function absenceReason(a: Absence): string | null {
  return a.medical_reason ?? a.reason ?? null
}

export function absenceFiles(a: Absence): AbsenceAttachment[] {
  return a.medical_attachments ?? a.attachments ?? []
}
