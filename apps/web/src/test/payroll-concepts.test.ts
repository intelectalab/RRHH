import { describe, expect, it } from 'vitest'
import { affectedBases, suggestions, toggleBase, wordAt } from '@/modules/payroll/concepts'

describe('CPT · ayudas del editor de conceptos', () => {
  it('alterna una base sin tocar las demás', () => {
    const start = [{ base_code: 'essalud', affects: true, projection_kind: null, justification: 'x' }]
    const on = toggleBase(start, 'cts')
    expect(affectedBases(on)).toEqual(['essalud', 'cts'])
    expect(on[0].justification).toBe('x')
    expect(affectedBases(toggleBase(on, 'essalud'))).toEqual(['cts'])
  })

  it('sugiere variables, funciones, conceptos y bases por prefijo', () => {
    const src = { variables: ['sueldo', 'sueldo_tramos'], functions: ['max'], concepts: ['rem_basica'], bases: ['essalud'] }
    expect(suggestions('sue', src)).toEqual(['sueldo', 'sueldo_tramos'])
    expect(suggestions('base.', src)).toEqual(['base.essalud'])
    expect(suggestions('ma', src)).toEqual(['max('])
    expect(suggestions('', src)).toEqual([])
  })

  it('toma la palabra bajo el cursor', () => {
    expect(wordAt('max(base.ess', 12)).toBe('base.ess')
    expect(wordAt('sueldo * ', 9)).toBe('')
  })
})
