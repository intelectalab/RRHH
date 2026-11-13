import type { StatusTone } from '@/ui/components/Status'
import type { MovementField, MovementStatus, PersonnelAction } from './types'

/** HIS-2 · HIS-4 · Color de cada estado del movimiento (el texto siempre dice el estado). */
export const MOVEMENT_TONE: Record<MovementStatus, StatusTone> = {
  draft: 'gr', pending_approval: 'wa', approved: 'in', scheduled: 'in', applied: 'ok', rejected: 'er', cancelled: 'gr', replaced: 'gr',
}

/** Clave de mensajes del campo de la asignación (los mismos que usa el ingreso). */
export const FIELD_LABEL: Record<MovementField, string> = {
  position_id: 'emp.f_position', job_profile_id: 'emp.f_job', org_unit_id: 'emp.f_unit', site_id: 'emp.f_site',
  cost_center_id: 'emp.f_cost_center', manager_employment_id: 'emp.f_manager', work_modality: 'emp.f_modality',
}

/**
 * «Antes → después» de cada campo que cambia. El valor viene con su nombre desde el servidor; la modalidad
 * se traduce aquí.
 */
export function movementLines(action: PersonnelAction, t: (k: string, v?: Record<string, string | number>) => string): string[] {
  const shown = (field: MovementField, side: 'before' | 'after') => {
    const v = action[side][field]
    if (!v || v.value === null || v.value === '') return t('ficha.value_empty')
    if (field === 'work_modality') return t(`emp.modality_${v.value}`)
    return v.label ?? v.value
  }
  return (Object.keys(action.after) as MovementField[]).map((field) => t('mov.line', {
    field: t(FIELD_LABEL[field]), before: shown(field, 'before'), after: shown(field, 'after'),
  }))
}
