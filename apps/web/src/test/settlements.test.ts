import { describe, expect, it } from 'vitest'
import { visibleActions } from '@/modules/payroll/settlements'

describe('liquidaciones (LIQ-3)', () => {
  it('muestra solo las acciones permitidas por el estado y por los permisos del usuario', () => {
    const can = (p: string) => p === 'payroll.settlements.calculate'
    expect(visibleActions({ allowed_actions: ['calculate', 'submit', 'void'] }, can)).toEqual(['calculate', 'submit'])
  })

  it('no muestra acciones si el estado no admite ninguna', () => {
    expect(visibleActions({ allowed_actions: [] }, () => true)).toEqual([])
  })
})
