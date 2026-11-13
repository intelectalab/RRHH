import { describe, expect, it } from 'vitest'
import es from '../../messages/es.json'
import { percentTotal } from '@/modules/people/fileDownload'

describe('COL-7 · suma de porcentajes de beneficiarios', () => {
  it('suma en centésimas exactas y acepta coma decimal', () => {
    expect(percentTotal(['33.33', '33,33', '33.34'])).toBe(100)
    expect(percentTotal(['50', '', 'x'])).toBe(50)
  })
})

describe('COL-6/7/8 · textos de las secciones', () => {
  it('tiene etiquetas para cada estado y valor enumerado que la API devuelve', () => {
    const m = es as unknown as Record<string, Record<string, string>>
    for (const s of ['pending_approval', 'active', 'rejected', 'cancelled']) expect(m.bank[`status_${s}`]).toBeTruthy()
    for (const s of ['pending', 'registered', 'deregistered']) expect(m.dep[`status_${s}`]).toBeTruthy()
    for (const s of ['afp', 'onp', 'none', 'other']) expect(m.ssec[`system_${s}`]).toBeTruthy()
    for (const s of ['essalud', 'eps', 'sis', 'other']) expect(m.ssec[`health_${s}`]).toBeTruthy()
    for (const s of ['life', 'risk_health', 'risk_pension', 'health_plan', 'other']) expect(m.ins[`kind_${s}`]).toBeTruthy()
  })
})
