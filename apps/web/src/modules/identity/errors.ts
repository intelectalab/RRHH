import { ApiRequestError } from '@/lib/api'

/** Clave de mensaje para un error de las pantallas de acceso. */
export function authErrorKey(error: unknown): string {
  if (!(error instanceof ApiRequestError)) return 'auth.generic_error'
  switch (error.code) {
    case 'rate_limited':
      return 'auth.rate_limited'
    case 'tenant_suspended':
      return 'auth.tenant_suspended'
    case 'missing_tenant_context':
      return 'auth.missing_tenant'
    default:
      return 'auth.generic_error'
  }
}
