import { describe, expect, it } from 'vitest'
import { ApiClient, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'

const t = (k: string) => k

describe('UI-5 · errores del backend en el formulario', () => {
  it('coloca cada error junto a su campo', () => {
    const e = new ApiRequestError(422, 'validation_failed', 'Datos inválidos', [
      { field: 'tax_id', rule: 'invalid', message: 'El RUC no tiene un formato válido.' },
      { field: 'role_assignments.0.site_ids', rule: 'required', message: 'Indique una sede.' },
    ])
    expect(formErrors(e, t)).toEqual({ fields: { tax_id: 'El RUC no tiene un formato válido.', role_assignments: 'Indique una sede.' }, general: null })
  })

  it('un error sin campo se muestra como mensaje general', () => {
    expect(formErrors(new ApiRequestError(409, 'duplicate', 'Ya existe.'), t).general).toBe('Ya existe.')
  })

  it('sin red devuelve network_error y no pierde lo escrito (el formulario no se reinicia)', async () => {
    const client = new ApiClient('/api/v1', (() => Promise.reject(new TypeError('Failed to fetch'))) as unknown as typeof fetch, () => '')
    const error = await client.post('/legal-entities', { name: 'X' }).catch((e: unknown) => e)
    expect((error as ApiRequestError).code).toBe('network_error')
    expect(formErrors(error, t).general).toBe('common.network_error')
  })
})
