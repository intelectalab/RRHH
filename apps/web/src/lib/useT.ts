'use client'

import { useEffect, useState } from 'react'
import { defaultLocale, resolveLocale, translate, type Locale } from './i18n'

/**
 * Traducción en componentes cliente. Se renderiza primero en el idioma por defecto
 * (igual que el servidor) y luego adopta el idioma del navegador, hasta que exista
 * la preferencia del usuario (GET /me).
 */
export function useT(): { t: (key: string, vars?: Record<string, string | number>) => string; locale: Locale } {
  const [locale, setLocale] = useState<Locale>(defaultLocale)

  useEffect(() => {
    setLocale(resolveLocale(navigator.language))
  }, [])

  return { t: (key, vars) => translate(locale, key, vars), locale }
}
