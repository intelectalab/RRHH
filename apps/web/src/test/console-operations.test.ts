import { describe, expect, it } from 'vitest'
import { bytesLabel, opsTone } from '@/modules/platform/ConsoleOperations'

describe('SAS-8 · SAS-9 · SAS-12 · consola de operación', () => {
  it('da un tono a cada estado de sesión, copia, restauración y componente', () => {
    expect(opsTone('active')).toBe('ok')
    expect(opsTone('completed')).toBe('ok')
    expect(opsTone('requested')).toBe('wa')
    expect(opsTone('degraded')).toBe('wa')
    expect(opsTone('failed')).toBe('er')
    expect(opsTone('down')).toBe('er')
    expect(opsTone('expired')).toBe('gr')
  })

  it('muestra el tamaño de la copia en MB con el formato del idioma', () => {
    expect(bytesLabel('es', 1572864)).toBe('1,5 MB')
    expect(bytesLabel('en', 1572864)).toBe('1.5 MB')
    expect(bytesLabel('es', null)).toBe('')
  })
})
