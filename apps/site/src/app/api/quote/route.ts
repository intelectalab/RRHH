import { NextResponse, type NextRequest } from 'next/server'
import { apiBase } from '@/lib/catalog'

/**
 * WEB-4 · El selector de precios pide la cotización a la API pública de la plataforma a través del servidor del sitio:
 * el navegador nunca habla con la API ni calcula montos. Solo se reenvían los parámetros conocidos.
 */
export async function GET(request: NextRequest): Promise<NextResponse> {
  const query = new URLSearchParams()
  for (const key of ['plan', 'employees', 'cycle', 'addons']) {
    const value = request.nextUrl.searchParams.get(key)
    if (value !== null) query.set(key, value.slice(0, 500))
  }
  try {
    const res = await fetch(`${apiBase()}/v1/public/quote?${query.toString()}`, {
      headers: { Accept: 'application/json', 'Accept-Language': request.headers.get('accept-language') ?? 'es' },
      cache: 'no-store',
    })
    return NextResponse.json(await res.json(), { status: res.status })
  } catch {
    return NextResponse.json({ error: { code: 'upstream_unavailable' } }, { status: 503 })
  }
}
