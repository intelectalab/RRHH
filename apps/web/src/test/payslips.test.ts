import { describe, expect, it } from 'vitest'
import { PAYSLIP_TONE, pendingAck, type Payslip } from '@/modules/payroll/payslips'

const base = { id: '1', payroll_period_id: 'p', employment_id: 'e', number: 'B-1', kind: 'regular', version: 1 } as const

describe('BOL-2 · boletas en la interfaz', () => {
  it('el acuse pendiente sale de lo que indica el servidor', () => {
    expect(pendingAck({ ...base, status: 'published', requires_acknowledgement: true } as Payslip)).toBe(true)
    expect(pendingAck({ ...base, status: 'viewed', requires_acknowledgement: true } as Payslip)).toBe(true)
    expect(pendingAck({ ...base, status: 'acknowledged', requires_acknowledgement: true } as Payslip)).toBe(false)
    expect(pendingAck({ ...base, status: 'published', requires_acknowledgement: false } as Payslip)).toBe(false)
  })

  it('cada estado tiene su tono', () => {
    expect(Object.keys(PAYSLIP_TONE).sort()).toEqual(['acknowledged', 'generated', 'published', 'viewed', 'voided'])
  })
})
