'use client'

import { useCallback, useSyncExternalStore } from 'react'
import { translate, type Locale } from './i18n'
import { getLocale, getServerLocale, setLocale, subscribeLocale } from './locale'

/**
 * Traducción en componentes cliente (CFG-6). Todos comparten el idioma activo de lib/locale:
 * cambiarlo con setLocale repinta la aplicación al instante, sin recargar la sesión.
 */
export function useT(): { t: (key: string, vars?: Record<string, string | number>) => string; locale: Locale; setLocale: (l: string | null) => void } {
  const locale = useSyncExternalStore(subscribeLocale, getLocale, getServerLocale)
  const t = useCallback((key: string, vars?: Record<string, string | number>) => translate(locale, key, vars), [locale])

  return { t, locale, setLocale }
}
