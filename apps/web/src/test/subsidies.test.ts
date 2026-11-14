import { describe, expect, it } from 'vitest'
import { translate } from '@/lib/i18n'
import { SUBSIDY_STATUSES } from '@/modules/time/absences'
import { SUBSIDY_ACTIONS, subsidyActionBody, subsidyActionFields, subsidyActions, subsidyCurrency, toggleExtension } from '@/modules/time/subsidies'

describe('AUS-3 · AUS-4 · subsidios y licencias de ley', () => {
  it('cada acción arma el cuerpo con los nombres del contrato y el monto como {amount, currency} en texto', () => {
    const v = { date: '2026-03-01', reference: ' EXP-1 ', amount: '1250.50', reason: ' Falta CITT ' }
    expect(subsidyActionBody('file', v, 'PEN')).toEqual({ filed_on: '2026-03-01', filing_reference: 'EXP-1', amount_requested: { amount: '1250.50', currency: 'PEN' } })
    expect(subsidyActionBody('observe', v, 'PEN')).toEqual({ reason: 'Falta CITT' })
    expect(subsidyActionBody('approve', { ...v, amount: '' }, 'PEN')).toEqual({})
    expect(subsidyActionBody('reimburse', v, null)).toEqual({ reimbursed_on: '2026-03-01', amount_reimbursed: { amount: '1250.50' } })
    expect(subsidyActionFields('reimburse').required).toEqual(['date', 'amount'])
    expect(subsidyActionFields('reject').fields).toEqual(['reason'])
  })

  it('solo se ofrecen las acciones que permite el servidor y la moneda sale de los montos registrados', () => {
    expect(subsidyActions({ allowed_actions: ['reject', 'approve', 'observe', 'cancel'] })).toEqual(['observe', 'approve', 'reject'])
    expect(subsidyCurrency({ amount_requested: null, amount_approved: { amount: '10.00', currency: 'PEN' }, amount_reimbursed: null })).toBe('PEN')
    expect(subsidyCurrency({ amount_requested: null, amount_approved: null, amount_reimbursed: null })).toBeNull()
  })

  it('las causales se alternan sin repetirse', () => {
    expect(toggleExtension([], 'multiple_birth')).toEqual(['multiple_birth'])
    expect(toggleExtension(['multiple_birth', 'child_disability'], 'multiple_birth')).toEqual(['child_disability'])
  })

  it('cada estado y acción del expediente tienen texto en los tres idiomas', () => {
    for (const locale of ['es', 'en', 'pt'] as const) {
      for (const s of SUBSIDY_STATUSES) expect(translate(locale, `sub.status_${s}`)).not.toBe(`sub.status_${s}`)
      for (const a of SUBSIDY_ACTIONS) {
        expect(translate(locale, `sub.action_${a}`)).not.toBe(`sub.action_${a}`)
        expect(translate(locale, `sub.done_${a}`)).not.toBe(`sub.done_${a}`)
      }
      expect(translate(locale, 'shell.modules_subsidies')).not.toBe('shell.modules_subsidies')
      expect(translate(locale, 'abs.cert_diagnosis')).not.toBe('abs.cert_diagnosis')
    }
  })
})
