import type { paths, components } from '@contracts'

export type { paths, components }
export type Schema<T extends keyof components['schemas']> = components['schemas'][T]
export type ApiError = components['schemas']['Error']
export type PageMeta = components['schemas']['PageMeta']

/**
 * Cliente HTTP tipado que garantiza el uso de los contratos generados de OpenAPI.
 */
export class ApiClient {
  constructor(private readonly baseUrl: string = '/api/v1') {}

  async get<T>(endpoint: string, init?: RequestInit): Promise<T> {
    return this.request<T>(endpoint, { ...init, method: 'GET' })
  }

  async post<T>(endpoint: string, body?: unknown, init?: RequestInit): Promise<T> {
    return this.request<T>(endpoint, {
      ...init,
      method: 'POST',
      body: body !== undefined ? JSON.stringify(body) : undefined,
    })
  }

  private async request<T>(endpoint: string, init?: RequestInit): Promise<T> {
    const url = endpoint.startsWith('http') ? endpoint : `${this.baseUrl}${endpoint}`
    const response = await fetch(url, {
      ...init,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json, application/problem+json',
        ...init?.headers,
      },
    })

    if (!response.ok) {
      let errorBody: ApiError | undefined
      try {
        errorBody = (await response.json()) as ApiError
      } catch {
        // Ignora error al parsear cuerpo no JSON
      }

      const message = errorBody?.error?.message || `HTTP ${response.status} ${response.statusText}`
      throw new Error(message)
    }

    return (await response.json()) as T
  }
}

export const api = new ApiClient()

