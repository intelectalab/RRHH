import { ApiRequestError } from './api'

/**
 * Convierte un error de la API en mensajes junto a cada campo (UI-5).
 * Devuelve los errores por campo y un mensaje general para lo que no corresponde a un campo.
 */
export function formErrors(error: unknown, t: (k: string) => string): { fields: Record<string, string>; general: string | null } {
  if (!(error instanceof ApiRequestError)) return { fields: {}, general: t('auth.generic_error') }
  if (error.code === 'network_error') return { fields: {}, general: t('common.network_error') }
  const fields: Record<string, string> = {}
  for (const d of error.details) {
    const key = d.field.split('.')[0] ?? d.field
    fields[key] ??= d.message
    // También con la ruta completa (p. ej. custom_fields.zona) para campos anidados.
    if (d.field !== key) fields[d.field] ??= d.message
  }
  return { fields, general: Object.keys(fields).length > 0 ? null : error.message }
}
