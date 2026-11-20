import { describe, expect, it } from 'vitest'
import { DAY_TONE, monthOf } from '@/modules/time/periodSummary'

describe('AUS-6 · resumen del periodo', () => {
  it('el periodo por defecto es el mes completo de la fecha', () => {
    expect(monthOf('2026-02-15')).toEqual({ from: '2026-02-01', to: '2026-02-28' })
    expect(monthOf('2028-02-01')).toEqual({ from: '2028-02-01', to: '2028-02-29' })
    expect(monthOf('2026-12-31')).toEqual({ from: '2026-12-01', to: '2026-12-31' })
  })

  it('cada tipo de día tiene su color y el remoto se distingue del laborado', () => {
    expect(DAY_TONE.remote).not.toBe(DAY_TONE.worked)
    expect(Object.keys(DAY_TONE).sort()).toEqual(['absence', 'holiday', 'outside_employment', 'remote', 'rest_day', 'worked'])
  })
})
