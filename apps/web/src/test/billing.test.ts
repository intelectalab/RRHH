import { describe, expect, it } from 'vitest'
import { invoiceLabel, invoiceTone } from '@/modules/billing/billing'

describe('SAS-11 · comprobantes del SaaS', () => {
  it('muestra la serie y el número legal solo cuando el proveedor aceptó el comprobante', () => {
    const base = { number: 'GHR-2026-000001', einvoice: { status: 'not_sent' as const, series: null, number: null, acceptance_hash: null, accepted_at: null, message: null } }
    expect(invoiceLabel(base)).toBe('GHR-2026-000001')
    expect(invoiceLabel({ ...base, einvoice: { ...base.einvoice, status: 'accepted', series: 'F001', number: '00000007' } })).toBe('F001-00000007')
  })

  it('un observado o vencido se marca como error y uno pagado como correcto', () => {
    expect(invoiceTone('observed')).toBe('er')
    expect(invoiceTone('overdue')).toBe('er')
    expect(invoiceTone('paid')).toBe('ok')
    expect(invoiceTone('void')).toBe('gr')
  })
})
