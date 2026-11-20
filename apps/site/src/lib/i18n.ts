import es from '@/content/es.json'
import en from '@/content/en.json'
import pt from '@/content/pt.json'

/**
 * WEB-1 · Idiomas del sitio. El español va sin prefijo (gestiahr.com/planilla, la ruta canónica de producción);
 * inglés y portugués llevan /en y /pt. El contenido (CMS) vive versionado en src/content/{es,en,pt}.json.
 */
export const LOCALES = ['es', 'en', 'pt'] as const
export type Locale = (typeof LOCALES)[number]
export const DEFAULT_LOCALE: Locale = 'es'

export type Content = typeof es

const CONTENT: Record<Locale, Content> = { es, en, pt }

export function isLocale(value: string): value is Locale {
  return (LOCALES as readonly string[]).includes(value)
}

export function content(lang: string): Content {
  return CONTENT[isLocale(lang) ? lang : DEFAULT_LOCALE]
}

/** Ruta pública de una página en un idioma: «/planilla» en español, «/en/planilla» en inglés. */
export function href(lang: string, path: string): string {
  const clean = path === '' || path === '/' ? '' : path.startsWith('/') ? path : `/${path}`
  if (!isLocale(lang) || lang === DEFAULT_LOCALE) return clean === '' ? '/' : clean
  return `/${lang}${clean}`
}

/** Locale interno que corresponde a una ruta pública (el middleware reescribe las rutas sin prefijo a /es). */
export function localeOfPath(pathname: string): Locale {
  const first = pathname.split('/')[1] ?? ''
  return isLocale(first) ? first : DEFAULT_LOCALE
}

/** Reemplaza {variables} de un texto del contenido. */
export function fill(text: string, vars: Record<string, string | number>): string {
  return text.replace(/\{(\w+)\}/g, (all, key: string) => (key in vars ? String(vars[key]) : all))
}
