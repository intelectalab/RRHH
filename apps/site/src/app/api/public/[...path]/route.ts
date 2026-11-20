import { NextResponse, type NextRequest } from 'next/server'
import { apiBase } from '@/lib/catalog'
import { isAllowedPublicCall } from '@/lib/publicApi'

/**
 * WEB-5 a WEB-7 · Puente del servidor del sitio hacia /api/v1/public/*. Solo reenvía las rutas permitidas, el cuerpo,
 * la clave de idempotencia, el idioma y la IP de quien envía (para el antiabuso de la API).
 */
type Ctx = { params: Promise<{ path: string[] }> }

async function forward(request: NextRequest, ctx: Ctx): Promise<NextResponse> {
  const { path } = await ctx.params
  const joined = path.join('/')
  if (!isAllowedPublicCall(request.method, joined)) {
    return NextResponse.json({ error: { code: 'not_found' } }, { status: 404 })
  }
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': request.headers.get('accept-language') ?? 'es',
  }
  const key = request.headers.get('idempotency-key')
  if (key) headers['Idempotency-Key'] = key.slice(0, 200)
  const ip = request.headers.get('x-forwarded-for')?.split(',')[0]?.trim() ?? request.headers.get('x-real-ip')
  if (ip) headers['X-Forwarded-For'] = ip
  let body: string | undefined
  if (request.method !== 'GET') {
    headers['Content-Type'] = 'application/json'
    body = (await request.text()).slice(0, 20000)
  }
  const query = request.nextUrl.searchParams.get('from')
  const url = `${apiBase()}/v1/public/${joined}${query && /^\d{4}-\d{2}-\d{2}$/.test(query) ? `?from=${query}` : ''}`
  try {
    const res = await fetch(url, { method: request.method, headers, body, cache: 'no-store' })
    const json: unknown = await res.json().catch(() => null)
    return NextResponse.json(json, { status: res.status })
  } catch {
    return NextResponse.json({ error: { code: 'upstream_unavailable' } }, { status: 503 })
  }
}

export const GET = forward
export const POST = forward
export const PATCH = forward
