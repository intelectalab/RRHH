import { describe, expect, it } from 'vitest'
import { formatNumber } from '@/lib/format'
import { usageTone } from '@/modules/usage/UsageTable'

describe('SAS-6 · consumo del plan', () => {
  it('muestra cantidades decimales con los separadores del idioma, sin redondear', () => {
    expect(formatNumber('es-PE', '1250.5')).toBe('1,250.5')
    expect(formatNumber('pt-BR', '1250.75')).toBe('1.250,75')
    expect(formatNumber('en', '12345678901234567890')).toBe('12,345,678,901,234,567,890')
    expect(formatNumber('es', 'no es número')).toBe('no es número')
  })

  it('el tono acompaña al estado que calcula la API', () => {
    expect(usageTone('ok')).toBe('ok')
    expect(usageTone('warning')).toBe('wa')
    expect(usageTone('reached')).toBe('er')
    expect(usageTone('over')).toBe('er')
  })
})
