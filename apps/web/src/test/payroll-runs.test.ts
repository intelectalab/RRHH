import { describe, expect, it } from 'vitest'
import es from '../../messages/es.json'
import en from '../../messages/en.json'
import pt from '../../messages/pt.json'
import { ALERT_TONE, canDiscard, canResume, isActive, RUN_TONE } from '@/modules/payroll/payrollRuns'

describe('NOM-8 · corridas de planilla', () => {
  it('sigue consultando el avance solo mientras la corrida está en cola o calculando', () => {
    expect(isActive({ status: 'queued' })).toBe(true)
    expect(isActive({ status: 'running' })).toBe(true)
    expect(isActive({ status: 'completed' })).toBe(false)
  })

  it('reanuda lo que falló o quedó a medias y no descarta lo aprobado o cerrado', () => {
    expect(canResume({ status: 'failed' })).toBe(true)
    expect(canResume({ status: 'completed' })).toBe(false)
    expect(canDiscard({ status: 'completed' })).toBe(true)
    expect(canDiscard({ status: 'approved' })).toBe(false)
    expect(canDiscard({ status: 'closed' })).toBe(false)
  })

  it('cada estado y alerta tiene su tono y su texto en los tres idiomas', () => {
    for (const status of Object.keys(RUN_TONE)) {
      for (const m of [es, en, pt]) expect((m.nrun as Record<string, string>)[`status_${status}`]).toBeTruthy()
    }
    for (const alert of Object.keys(ALERT_TONE)) {
      for (const m of [es, en, pt]) expect((m.myp as Record<string, string>)[`alert_${alert}`]).toBeTruthy()
    }
  })
})
