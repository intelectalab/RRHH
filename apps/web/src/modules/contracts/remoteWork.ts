/** CON-6 · Tipos y ayudas de presentación del teletrabajo (el plazo y el contenido mínimo los valida la API). */
export type RemoteWorkStatus = 'requested' | 'approved' | 'rejected' | 'pending_signature' | 'active' | 'ended'

/** Contenido del acuerdo que se completa en pantalla (la modalidad se elige al registrar la solicitud). */
export const REMOTE_WORK_FIELDS = ['work_place', 'schedule_description', 'disconnection_hours', 'supervision_mechanism', 'equipment_provided_by', 'expense_compensation'] as const

export type RemoteWork = {
  id: string
  employment_id: string
  person_name?: string
  employee_code?: string
  modality: 'total' | 'partial'
  starts_on: string
  ends_on?: string
  work_place?: string
  schedule_description?: string
  disconnection_hours?: number
  supervision_mechanism?: string
  equipment_provided_by?: 'employer' | 'worker' | 'mixed'
  expense_compensation?: { amount: string; currency: string }
  requested_by: 'employee' | 'employer'
  request_reason?: string
  requested_on?: string
  response_due_on?: string
  due_soon?: boolean
  overdue?: boolean
  status: RemoteWorkStatus
  missing_content?: string[]
  has_document?: boolean
  allowed_actions?: string[]
}

type Tone = 'ok' | 'wa' | 'er' | 'in' | 'gr'
const TONES: Record<RemoteWorkStatus, Tone> = { requested: 'wa', approved: 'in', rejected: 'er', pending_signature: 'in', active: 'ok', ended: 'gr' }

export function remoteWorkTone(status: RemoteWorkStatus): Tone {
  return TONES[status] ?? 'gr'
}
