import type { Schema } from '@/lib/api'

export type ApprovalTask = {
  id: string
  level: number
  level_name: string
  assignee_user_id: string | null
  assignee_name: string | null
  assignee_kind: 'user' | 'role'
  delegated_from_name: string | null
  status: 'pending' | 'approved' | 'rejected' | 'skipped' | 'cancelled' | 'expired'
  due_at: string | null
  completed_at: string | null
  comment: string | null
  mine: boolean
}

export type Approval = Omit<Schema<'ApprovalRequest'>, 'tasks'> & { tasks?: ApprovalTask[]; created_at?: string }

/** Avisa a la barra superior que cambió algún pendiente (recalcula el contador sin recargar). */
export const INBOX_CHANGED = 'ayni:inbox-changed'

export function notifyInboxChanged(): void {
  window.dispatchEvent(new Event(INBOX_CHANGED))
}

/** Etiqueta del tipo de trámite; los tipos que aún no tienen texto se muestran como «Otro trámite». */
export function typeLabel(t: (k: string) => string, type: string): string {
  const key = `wtype.${type}`
  const label = t(key)
  return label === key ? t('wtype.other') : label
}
