import { describe, expect, it } from 'vitest'
import es from '../../messages/es.json'
import en from '../../messages/en.json'
import pt from '../../messages/pt.json'
import { NEEDS_REASON } from '@/modules/payroll/periods'
import { allConfirmed, BATCH_TONE, canDownload, isOpen, SEVERITY_TONE } from '@/modules/payroll/review'

describe('PER-7 · PER-10 · revisión y pago de la planilla', () => {
  it('solo una anomalía abierta se revisa o descarta', () => {
    expect(isOpen({ status: 'open' })).toBe(true)
    expect(isOpen({ status: 'reviewed' })).toBe(false)
    expect(isOpen({ status: 'dismissed' })).toBe(false)
  })

  it('«Marcar pagado» solo con todos los lotes vigentes confirmados; los anulados no cuentan', () => {
    expect(allConfirmed([])).toBe(false)
    expect(allConfirmed([{ status: 'confirmed' }, { status: 'cancelled' }])).toBe(true)
    expect(allConfirmed([{ status: 'confirmed' }, { status: 'generated' }])).toBe(false)
    expect(canDownload({ has_file: true, status: 'generated' })).toBe(true)
    expect(canDownload({ has_file: true, status: 'cancelled' })).toBe(false)
    expect(canDownload({ has_file: false, status: 'draft' })).toBe(false)
  })

  it('la complementaria pide motivo como la reapertura', () => {
    expect(NEEDS_REASON).toContain('complementary')
  })

  it('cada estado, severidad y acción tiene su texto en los tres idiomas', () => {
    for (const m of [es, en, pt]) {
      for (const s of Object.keys(BATCH_TONE)) expect((m.npay as Record<string, string>)[`status_${s}`]).toBeTruthy()
      for (const s of Object.keys(SEVERITY_TONE)) expect((m.nrev as Record<string, string>)[`severity_${s}`]).toBeTruthy()
      for (const a of ['simulate', 'complementary', 'mark-paid']) expect((m.per as Record<string, string>)[`action_${a}`]).toBeTruthy()
    }
  })
})
