import { describe, expect, it } from 'vitest'
import { remoteWorkTone, REMOTE_WORK_FIELDS } from '@/modules/contracts/remoteWork'
import { orgTabs } from '@/modules/organization/orgTabs'
import { lineChanges, scenarioTone } from '@/modules/organization/scenarios'

const t = (k: string) => k

describe('POS-5 · escenarios de dotación', () => {
  it('una posición real se mantiene, se congela o se quita; una plaza nueva se agrega o se quita', () => {
    expect(lineChanges({ position_id: 'p1' })).toEqual(['baseline', 'freeze', 'remove'])
    expect(lineChanges({ position_id: null })).toEqual(['add', 'remove'])
  })

  it('el estado aplicado se muestra como hecho y el borrador como neutro', () => {
    expect(scenarioTone('applied')).toBe('ok')
    expect(scenarioTone('draft')).toBe('gr')
  })

  it('la pestaña de escenarios aparece solo con su permiso y Core HR', () => {
    const can = (p: string) => p === 'org.headcount_plans.view'
    expect(orgTabs(t, can, 'scenarios', ['core']).map((x) => x.href)).toEqual(['/organizacion/escenarios'])
    expect(orgTabs(t, can, 'scenarios', [])).toEqual([])
  })
})

describe('CON-6 · teletrabajo', () => {
  it('el formulario cubre el contenido mínimo del acuerdo (la modalidad se elige al registrar)', () => {
    expect([...REMOTE_WORK_FIELDS]).toEqual(['work_place', 'schedule_description', 'disconnection_hours', 'supervision_mechanism', 'equipment_provided_by', 'expense_compensation'])
  })

  it('el rechazo se marca como error y lo vigente como hecho', () => {
    expect(remoteWorkTone('rejected')).toBe('er')
    expect(remoteWorkTone('active')).toBe('ok')
  })
})
