import { describe, expect, it } from 'vitest'
import { isFromModule, NEEDS_REASON, PERIOD_TONE, periodCode } from '@/modules/payroll/periods'
import es from '../../messages/es.json'

describe('PER-1 a PER-3 · periodos de planilla', () => {
  it('etiqueta el periodo con año y número de dos dígitos', () => {
    expect(periodCode({ period_year: 2026, period_number: 3 })).toBe('2026-03')
    expect(periodCode({ period_year: 2026, period_number: 11 })).toBe('2026-11')
  })

  it('pide motivo al devolver, reabrir y volver a calcular', () => {
    expect(NEEDS_REASON).toEqual(['reject', 'reopen', 'reopen-calculation'])
  })

  it('las incidencias de otros módulos no se editan en planilla (RN-PER-09)', () => {
    expect(isFromModule({ source: 'manual' })).toBe(false)
    expect(isFromModule({ source: 'import' })).toBe(false)
    expect(isFromModule({ source: 'absence' })).toBe(true)
    expect(isFromModule({ source: 'retroactive' })).toBe(true)
  })

  it('cada estado tiene tono y texto', () => {
    const per = es.per as Record<string, string>
    for (const status of Object.keys(PERIOD_TONE)) {
      expect(per[`status_${status}`]).toBeTruthy()
    }
  })
})
