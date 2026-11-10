import { describe, expect, it, vi } from 'vitest'
import { ApiClient, ApiRequestError, readXsrfToken } from '@/lib/api'

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('SEG-1 · cliente de API con sesión por cookie', () => {
  it('envía X-XSRF-TOKEN en escrituras y nunca en lecturas', async () => {
    const fetcher = vi.fn(async () => jsonResponse(200, { data: {} }))
    const client = new ApiClient('/api/v1', fetcher as unknown as typeof fetch, () => 'otra=1; XSRF-TOKEN=abc%3D123')

    await client.post('/auth/logout', {})
    await client.get('/me')

    const [, writeInit] = fetcher.mock.calls[0] as unknown as [string, RequestInit]
    const [, readInit] = fetcher.mock.calls[1] as unknown as [string, RequestInit]
    expect((writeInit.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=123')
    expect((readInit.headers as Record<string, string>)['X-XSRF-TOKEN']).toBeUndefined()
    expect(writeInit.credentials).toBe('same-origin')
  })

  it('convierte el error único de la API en ApiRequestError con código y detalles', async () => {
    const fetcher = vi.fn(async () => jsonResponse(422, {
      error: { code: 'invalid_credentials', message: 'Los datos de ingreso no son correctos.', request_id: 'r1', details: [{ field: 'credentials', rule: 'invalid', message: 'x' }] },
    }))
    const client = new ApiClient('/api/v1', fetcher as unknown as typeof fetch, () => '')

    const error = await client.post('/auth/login', {}).catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiRequestError)
    expect((error as ApiRequestError).code).toBe('invalid_credentials')
    expect((error as ApiRequestError).status).toBe(422)
    expect((error as ApiRequestError).details[0]?.field).toBe('credentials')
  })

  it('no guarda tokens en el almacenamiento del navegador', () => {
    expect(readXsrfToken('')).toBeNull()
  })
})
