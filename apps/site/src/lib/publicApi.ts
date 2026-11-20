/**
 * WEB-5 a WEB-7 · Puente del sitio con la API pública. El navegador llama a /api/public/… en el mismo sitio y el
 * servidor del sitio reenvía solo las rutas de esta lista a /api/v1/public/… (ninguna otra ruta de la plataforma).
 */
const ALLOWED: { method: string; pattern: RegExp }[] = [
  { method: 'GET', pattern: /^ruc\/\d{11}$/ },
  { method: 'GET', pattern: /^subdomains\/[a-z0-9-]{1,40}$/ },
  { method: 'POST', pattern: /^signups$/ },
  { method: 'PATCH', pattern: /^signups\/[A-Za-z0-9_-]{20,200}$/ },
  { method: 'POST', pattern: /^signups\/[A-Za-z0-9_-]{20,200}\/complete$/ },
  { method: 'POST', pattern: /^leads$/ },
  { method: 'GET', pattern: /^demo-slots$/ },
  { method: 'POST', pattern: /^demo-bookings$/ },
  { method: 'GET', pattern: /^demo-bookings\/[A-Za-z0-9_-]{20,200}$/ },
  { method: 'POST', pattern: /^demo-bookings\/[A-Za-z0-9_-]{20,200}\/cancel$/ },
]

export function isAllowedPublicCall(method: string, path: string): boolean {
  return ALLOWED.some((rule) => rule.method === method.toUpperCase() && rule.pattern.test(path))
}

export type ApiError = { code: string; rule?: string; fields: Record<string, string> }
export type ApiResult<T> = { ok: true; status: number; data: T } | { ok: false; status: number; error: ApiError }

/** Lee el error de la API (error.code, error.meta.rule, error.details[].field). */
export function parseError(status: number, body: unknown): ApiError {
  const error = (body as { error?: { code?: string; meta?: { rule?: string }; details?: { field?: string; message?: string }[] } } | null)?.error
  const fields: Record<string, string> = {}
  for (const d of error?.details ?? []) {
    if (d.field) fields[d.field] = d.message ?? ''
  }
  return { code: error?.code ?? (status >= 500 ? 'upstream_unavailable' : 'error'), rule: error?.meta?.rule, fields }
}

/** Llamada desde el navegador a través del puente del sitio. */
export async function publicCall<T>(method: string, path: string, body?: unknown, idempotencyKey?: string): Promise<ApiResult<T>> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey
  try {
    const res = await fetch(`/api/public/${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) })
    const json: unknown = await res.json().catch(() => null)
    if (res.ok) return { ok: true, status: res.status, data: (json as { data: T }).data }
    return { ok: false, status: res.status, error: parseError(res.status, json) }
  } catch {
    return { ok: false, status: 503, error: { code: 'upstream_unavailable', fields: {} } }
  }
}

/** Clave de idempotencia por envío de formulario: reintentar el mismo envío no duplica el lead. */
export function newKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

/**
 * Prueba antiabuso. El captcha real se conecta en la API por su puerto (CaptchaPort); en desarrollo el adaptador falso
 * acepta cualquier valor. Cuando se elija el proveedor, su widget entrega aquí el token.
 */
export function captchaToken(): string {
  return 'site'
}

/** Página de origen y campaña (RN-WEB-09). */
export function origin(): { source_page: string; utm: Record<string, string> | null } {
  if (typeof window === 'undefined') return { source_page: '', utm: null }
  const params = new URLSearchParams(window.location.search)
  const utm: Record<string, string> = {}
  for (const k of ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']) {
    const v = params.get(k)
    if (v) utm[k] = v.slice(0, 200)
  }
  return { source_page: window.location.pathname.slice(0, 500), utm: Object.keys(utm).length ? utm : null }
}

export const EMPLOYEE_RANGES = ['1-10', '11-50', '51-200', '201-500', '501+'] as const
export const LABOR_REGIMES = ['pe_general', 'pe_micro', 'pe_pequena'] as const
