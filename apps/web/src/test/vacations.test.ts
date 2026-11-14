import { describe, expect, it } from 'vitest'
import en from '../../messages/en.json'
import es from '../../messages/es.json'
import pt from '../../messages/pt.json'
import { policyDays } from '@/modules/vacations/VacationPoliciesScreen'
import { BALANCE_COLUMNS } from '@/modules/vacations/VacationsTab'

const limit = (value: string | null, pending: boolean) => ({ days_per_year: { parameter_code: 'vacaciones_dias', value, verified: !pending, pending_validation: pending, legal_source: null } })

describe('vacaciones (VAC-1 · VAC-2)', () => {
  it('los días por año vienen de la política o del régimen; sin valor legal verificado queda «por validar»', () => {
    const rules = { days_per_year: '20' } as Parameters<typeof policyDays>[0]['rules']
    expect(policyDays({ rules, legal: limit('15', false) })).toEqual({ value: '20', source: 'policy', pending: false })
    expect(policyDays({ rules: null, legal: limit('15', false) })).toEqual({ value: '15', source: 'legal', pending: false })
    expect(policyDays({ rules: null, legal: limit(null, true) })).toEqual({ value: null, source: 'legal', pending: true })
  })

  it('cada columna del saldo tiene su texto en los tres idiomas', () => {
    for (const messages of [es, en, pt] as Array<{ vac: Record<string, string> }>) {
      for (const c of BALANCE_COLUMNS) expect(messages.vac[`col_${c}`]).toBeTruthy()
    }
  })
})
