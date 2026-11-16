import { describe, expect, it } from 'vitest'
import { translate } from '@/lib/i18n'
import { positionNode, type PositionNode } from '@/modules/people/positionChart'

const t = (key: string, vars?: Record<string, string | number>) => translate('es', key, vars)
const base: PositionNode = {
  id: 'p1', parent_id: null, code: 'GG-01', title: 'Gerente general', org_unit_name: 'Gerencia', chart_state: 'filled', seats: 1,
  occupants: [{ person_name: 'Julia Mendoza' }], branch: { approved_seats: 4, occupied_seats: 2 },
}

describe('POS-7 · organigrama por posiciones', () => {
  it('una plaza ocupada muestra a su ocupante y la rama con ocupadas sobre aprobadas', () => {
    const n = positionNode(base, t)
    expect(n.subtitle).toBe('Julia Mendoza')
    expect(n.detail).toBe('Rama: 2 de 4 ocupadas')
    expect(n.tone).toBeUndefined()
  })

  it('las vacantes, en reclutamiento y congeladas se distinguen con su estado escrito y su tono', () => {
    for (const state of ['vacant', 'recruiting', 'frozen'] as const) {
      const n = positionNode({ ...base, chart_state: state, occupants: [] }, t)
      expect(n.tone).toBe(state)
      expect(n.subtitle).toBe(t(`people.chart_state_${state}`))
    }
  })

  it('una plaza compartida con cupos libres lo indica junto a sus ocupantes', () => {
    const n = positionNode({ ...base, chart_state: 'vacant', seats: 3 }, t)
    expect(n.subtitle).toBe('Julia Mendoza · 2 cupo(s) libre(s)')
  })
})
