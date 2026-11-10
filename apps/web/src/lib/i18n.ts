import es from '../../messages/es.json'
import en from '../../messages/en.json'
import pt from '../../messages/pt.json'

export const locales = ['es', 'en', 'pt'] as const
export type Locale = (typeof locales)[number]
export type Messages = typeof es

const catalogs: Record<Locale, Messages> = { es, en, pt }

export const defaultLocale: Locale = 'es'

function lookup(messages: unknown, key: string): string | undefined {
  let node: unknown = messages
  for (const part of key.split('.')) {
    if (typeof node !== 'object' || node === null) return undefined
    node = (node as Record<string, unknown>)[part]
  }
  return typeof node === 'string' ? node : undefined
}

/**
 * Traduce una clave «seccion.clave». Si falta en el idioma pedido, cae al español;
 * si tampoco existe, devuelve la clave (y la prueba de catálogos falla).
 */
export function translate(locale: Locale, key: string, vars: Record<string, string | number> = {}): string {
  const text = lookup(catalogs[locale], key) ?? lookup(catalogs[defaultLocale], key) ?? key
  return text.replace(/\{(\w+)\}/g, (_, name: string) => String(vars[name] ?? `{${name}}`))
}

export function resolveLocale(candidate: string | null | undefined): Locale {
  const short = (candidate ?? '').slice(0, 2).toLowerCase()
  return (locales as readonly string[]).includes(short) ? (short as Locale) : defaultLocale
}

export function catalog(locale: Locale): Messages {
  return catalogs[locale]
}
