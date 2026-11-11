import { defaultLocale, resolveLocale, type Locale } from './i18n'

/**
 * Idioma activo de la interfaz (CFG-6). Es un almacén único para toda la aplicación: al cambiarlo,
 * todos los componentes que usan useT se vuelven a pintar sin recargar ni volver a iniciar sesión.
 *
 * Orden: preferencia del usuario (GET /me) → último idioma usado en este navegador → idioma del
 * navegador → español. El servidor resuelve el mismo orden para sus mensajes y notificaciones.
 */
const STORAGE_KEY = 'gestia.locale'

let current: Locale | null = null
const listeners = new Set<() => void>()

function initial(): Locale {
  if (typeof window === 'undefined') return defaultLocale
  try {
    const saved = window.localStorage.getItem(STORAGE_KEY)
    if (saved) return resolveLocale(saved)
  } catch { /* sin almacenamiento: se usa el idioma del navegador */ }
  return resolveLocale(window.navigator?.language)
}

export function getLocale(): Locale {
  current ??= initial()
  return current
}

/** Idioma con el que se pinta en el servidor y en la primera pasada del cliente (evita desajustes). */
export function getServerLocale(): Locale {
  return defaultLocale
}

export function setLocale(locale: string | null | undefined): void {
  const next = resolveLocale(locale)
  if (next === current) return
  current = next
  try { window.localStorage.setItem(STORAGE_KEY, next) } catch { /* preferencia solo en memoria */ }
  if (typeof document !== 'undefined') document.documentElement.lang = next
  listeners.forEach((l) => l())
}

export function subscribeLocale(listener: () => void): () => void {
  listeners.add(listener)
  return () => { listeners.delete(listener) }
}

/** Solo para pruebas: vuelve al estado inicial. */
export function resetLocaleForTests(): void {
  current = null
  listeners.clear()
}
