import type { TimelineTone } from '@/ui/components/Timeline'
import { FIELD_LABEL } from './movements'
import type { MovementField } from './types'

export const TIMELINE_TYPES = ['hire', 'movement', 'compensation', 'contract', 'status', 'termination'] as const
export type TimelineType = (typeof TIMELINE_TYPES)[number]

type Side = { value: string | null; label: string | null } | null
type Money = { amount: string; currency: string }

/** HIS-5 · Evento de GET /employments/{id}/timeline. `salary` solo viaja con el permiso de campo de sueldo. */
export type TimelineEvent = {
  id: string
  date: string
  type: TimelineType
  kind: string
  status: string
  args: Record<string, string | number | boolean | null>
  changes: Array<{ field: string; before: Side; after: Side }>
  reason: string | null
  ref_type: string
  ref_id: string
  salary?: { before: Money | null; after: Money } | null
}

type T = (k: string, v?: Record<string, string | number>) => string

/** Título del evento: cada tipo usa los nombres que ya tiene su módulo (movimientos, sueldo, adendas). */
export function timelineTitle(e: TimelineEvent, t: T): string {
  switch (e.type) {
    case 'movement': return t(`ficha.kind_${e.kind}`)
    case 'compensation': return t('tl.compensation', { reason: t(`comp.reason_${e.kind}`) })
    case 'contract': return e.kind.startsWith('addendum_') ? t(`ctr.kind_${e.kind.slice('addendum_'.length)}`) : t(`tl.kind_${e.kind}`)
    default: return t(`tl.kind_${e.kind}`)
  }
}

/** «Campo: antes → después» de un movimiento; el servidor manda los nombres y la modalidad se traduce aquí. */
export function timelineChangeLines(e: TimelineEvent, t: T): string[] {
  const shown = (field: string, side: Side) => {
    if (!side || side.value === null || side.value === '') return t('ficha.value_empty')
    if (field === 'work_modality') return t(`emp.modality_${side.value}`)
    return side.label ?? side.value
  }
  return e.changes.map((c) => t('mov.line', {
    field: c.field in FIELD_LABEL ? t(FIELD_LABEL[c.field as MovementField]) : c.field,
    before: shown(c.field, c.before),
    after: shown(c.field, c.after),
  }))
}

/** Tono del punto: programado o pendiente en ámbar, reemplazado o anulado en gris, el cese en rojo. */
export function timelineTone(e: TimelineEvent): TimelineTone {
  if (['scheduled', 'pending_approval', 'approved'].includes(e.status)) return 'wa'
  if (['replaced', 'cancelled', 'expired'].includes(e.status)) return 'gr'
  if (e.type === 'termination' || e.kind === 'cancelled') return 'er'
  return 'ok'
}
