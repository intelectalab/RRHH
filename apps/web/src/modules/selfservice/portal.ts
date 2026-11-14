import type { ChangeField } from '@/modules/people/profile/types'
import type { HomeCard } from './types'

/** Destinos de la barra inferior del portal (mobile-first: 4–5 destinos). */
export const PORTAL_NAV = [
  { href: '/mi', key: 'portal.nav_home', icon: 'home', exact: true, permission: null, module: 'core' },
  { href: '/tiempo/vacaciones', key: 'portal.nav_vacations', icon: 'calendar', exact: false, permission: 'time.vacation_requests.view', module: 'time_off' },
  { href: '/tiempo/ausencias', key: 'portal.nav_absences', icon: 'file', exact: false, permission: 'time.absences.view', module: 'time_off' },
  { href: '/mi/perfil', key: 'portal.nav_profile', icon: 'user', exact: true, permission: 'people.employments.view', module: 'core' },
  { href: '/pendientes', key: 'portal.nav_pending', icon: 'inbox', exact: false, permission: null, module: 'base' },
] as const

export type PortalNavItem = (typeof PORTAL_NAV)[number]

/** Destinos visibles: módulo contratado y permiso (el servidor vuelve a validar al entrar). */
export function portalNav(modules: readonly string[], can: (p: string) => boolean): PortalNavItem[] {
  return PORTAL_NAV.filter((n) => modules.includes(n.module) && (n.permission === null || can(n.permission)))
}

/** Un usuario cuyo alcance sobre relaciones laborales es solo la propia entra directo a su portal. */
export function prefersPortal(scopes: Record<string, string>): boolean {
  return scopes['people.employments.view'] === 'own'
}

export function isActive(pathname: string, href: string, exact: boolean): boolean {
  return exact ? pathname === href : pathname === href || pathname.startsWith(`${href}/`)
}

/** Busca la tarjeta de un tipo con su forma tipada. */
export function cardOf<K extends HomeCard['key']>(cards: readonly HomeCard[], key: K): Extract<HomeCard, { key: K }> | undefined {
  return cards.find((c): c is Extract<HomeCard, { key: K }> => c.key === key)
}

/**
 * ESS-2 · Campos del formulario de cambio: solo los que la organización habilita en el portal, en el orden
 * de la ficha. Un campo con un cambio pendiente sigue visible, pero se marca como pendiente.
 */
export function proposableFields(all: readonly ChangeField[], editable: readonly string[] | undefined): ChangeField[] {
  if (!editable) return [...all]
  return all.filter((f) => editable.includes(f))
}

/** Un número de días del servidor (decimal en texto) sin ceros de relleno: «15.00» → «15». */
export function days(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '0'
  return value.includes('.') ? value.replace(/\.?0+$/, '') : value
}
