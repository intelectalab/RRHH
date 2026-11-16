import { describe, expect, it } from 'vitest'
import { batchFinished, insertVariable, type GenerationBatch } from '@/modules/documents/templates'

describe('DOC-4 · editor de plantillas', () => {
  it('inserta la variable donde está el cursor (o reemplaza la selección) y deja el cursor detrás', () => {
    expect(insertVariable('Hola , gracias', 5, 5, 'empleado.nombres')).toEqual({ body: 'Hola {{empleado.nombres}}, gracias', caret: 25 })
    expect(insertVariable('Hola XXX', 5, 8, 'empresa.ruc')).toEqual({ body: 'Hola {{empresa.ruc}}', caret: 20 })
  })
})

describe('DOC-5 · avance del lote', () => {
  const batch = (status: GenerationBatch['status']) => ({ status }) as GenerationBatch
  it('se sigue consultando mientras está en cola o generando', () => {
    expect(batchFinished(batch('queued'))).toBe(false)
    expect(batchFinished(batch('running'))).toBe(false)
    expect(batchFinished(batch('completed'))).toBe(true)
    expect(batchFinished(batch('completed_with_errors'))).toBe(true)
    expect(batchFinished(batch('failed'))).toBe(true)
  })
})
