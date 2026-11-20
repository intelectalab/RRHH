import { NextResponse, type NextRequest } from 'next/server'
import { DEFAULT_LOCALE, isLocale } from '@/lib/i18n'

/**
 * WEB-1 · Las rutas en español no llevan prefijo (gestiahr.com/planilla); por dentro se sirven desde /es. Fuera de
 * producción toda respuesta lleva X-Robots-Tag: noindex para que el entorno de prueba no se indexe.
 */
export function middleware(request: NextRequest): NextResponse {
  const { pathname } = request.nextUrl
  const first = pathname.split('/')[1] ?? ''
  let response: NextResponse
  if (first === DEFAULT_LOCALE) {
    // /es/precios → /precios: una sola URL canónica por página.
    const url = request.nextUrl.clone()
    url.pathname = pathname.slice(DEFAULT_LOCALE.length + 1) || '/'
    response = NextResponse.redirect(url, 308)
  } else if (isLocale(first)) {
    response = NextResponse.next()
  } else {
    const url = request.nextUrl.clone()
    url.pathname = `/${DEFAULT_LOCALE}${pathname === '/' ? '' : pathname}`
    response = NextResponse.rewrite(url)
  }
  if (process.env.SITE_ENV !== 'production') {
    response.headers.set('X-Robots-Tag', 'noindex, nofollow')
  }
  return response
}

export const config = {
  matcher: ['/((?!api|_next|favicon.svg|apple-touch-icon.png|logo-gestiahr.svg|og-gestiahr.png|robots.txt|sitemap.xml).*)'],
}
