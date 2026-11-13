import { describe, expect, it } from 'vitest'
import es from '../../messages/es.json'
import { peopleTabs } from '@/modules/people/peopleTabs'
import { FIELD_LABEL, MOVEMENT_TONE, movementLines } from '@/modules/people/profile/movements'
import { MOVEMENT_FIELDS, MOVEMENT_STATUSES, type PersonnelAction } from '@/modules/people/profile/types'

const messages = es as unknown as Record<string, Record<string, string>>
const t = (k: string, v: Record<string, string | number> = {}) => {
  const [group, key] = k.split('.')
  const raw = messages[group]?.[key] ?? k
  return raw.replace(/\{(\w+)\}/g, (_, name: string) => String(v[name] ?? ''))
}

const action = (over: Partial<PersonnelAction> = {}): PersonnelAction => ({
  id: 'a1', employment_id: 'e1', kind: 'transfer', effective_on: '2026-12-01', reason: null, status: 'scheduled',
  changes: { org_unit_id: 'u2', work_modality: 'remote' },
  before: { org_unit_id: { value: 'u1', label: 'Ventas' }, work_modality: { value: 'onsite', label: null } },
  after: { org_unit_id: { value: 'u2', label: 'Operaciones' }, work_modality: { value: 'remote', label: null } },
  approval: null, decision_reason: null, replaces_personnel_action_id: null, replaced_by_personnel_action_id: null,
  is_retroactive: false, affected_from: null, affected_to: null, allowed_actions: ['edit', 'cancel'], ...over,
})

describe('movimientos de personal (HIS-2 · HIS-4)', () => {
  it('cada cambio se lee como antes → después con el nombre que da el servidor', () => {
    expect(movementLines(action(), t)).toEqual(['Unidad: Ventas → Operaciones', 'Modalidad: Presencial → Remoto'])
  })

  it('cada estado y campo tiene su texto y su tono', () => {
    for (const s of MOVEMENT_STATUSES) {
      expect(messages.mov[`status_${s}`], s).toBeTruthy()
      expect(MOVEMENT_TONE[s], s).toBeTruthy()
    }
    for (const f of MOVEMENT_FIELDS) expect(t(FIELD_LABEL[f]), f).not.toBe(FIELD_LABEL[f])
    for (const s of ['applied', 'scheduled', 'pending_approval']) expect(messages.mov[`saved_${s}`], s).toBeTruthy()
  })

  it('la pestaña Movimientos aparece con permiso de ver movimientos y colaboradores', () => {
    const can = (p: string) => ['people.assignments.view', 'people.employments.view'].includes(p)
    expect(peopleTabs(t, 'movements', can).map((x) => x.href)).toContain('/personas/movimientos')
    expect(peopleTabs(t, 'movements', () => false).map((x) => x.href)).not.toContain('/personas/movimientos')
  })
})
