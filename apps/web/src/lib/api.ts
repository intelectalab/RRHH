import type { paths, components } from '@contracts'

export type { paths, components }
export type Schema<T extends keyof components['schemas']> = components['schemas'][T]
export type ApiError = components['schemas']['Error']
export type PageMeta = components['schemas']['PageMeta']

/** Error de la API con el formato único (docs/08-api.md). */
export class ApiRequestError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: ReadonlyArray<{ field: string; rule: string; message: string }> = [],
  ) {
    super(message)
    this.name = 'ApiRequestError'
  }
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

/** Lee la cookie XSRF-TOKEN emitida por la API al iniciar sesión (doble envío). */
export function readXsrfToken(cookieString: string): string | null {
  for (const part of cookieString.split(';')) {
    const [name, ...rest] = part.trim().split('=')
    if (name === 'XSRF-TOKEN') return decodeURIComponent(rest.join('='))
  }
  return null
}

/**
 * Cliente HTTP tipado. La sesión viaja en una cookie HttpOnly del mismo origen
 * (ADR 0004): nunca se guardan tokens en el navegador.
 */
export class ApiClient {
  constructor(
    private readonly baseUrl: string = '/api/v1',
    private readonly fetcher: typeof fetch = (...args) => fetch(...args),
    private readonly cookies: () => string = () => (typeof document === 'undefined' ? '' : document.cookie),
  ) {}

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

  private async request<T>(endpoint: string, init: RequestInit): Promise<T> {
    const url = endpoint.startsWith('http') ? endpoint : `${this.baseUrl}${endpoint}`
    const method = (init.method ?? 'GET').toUpperCase()
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json, application/problem+json',
      ...(init.headers as Record<string, string> | undefined),
    }

    if (!SAFE_METHODS.has(method)) {
      const xsrf = readXsrfToken(this.cookies())
      if (xsrf) headers['X-XSRF-TOKEN'] = xsrf
    }

    const response = await this.fetcher(url, { ...init, method, headers, credentials: 'same-origin' })

    if (!response.ok) {
      let body: ApiError | undefined
      try {
        body = (await response.json()) as ApiError
      } catch {
        // cuerpo no JSON
      }
      throw new ApiRequestError(
        response.status,
        body?.error?.code ?? 'internal_error',
        body?.error?.message ?? `HTTP ${response.status}`,
        (body?.error as { details?: Array<{ field: string; rule: string; message: string }> } | undefined)?.details ?? [],
      )
    }

    return (await response.json()) as T
  }
}

export const api = new ApiClient()
