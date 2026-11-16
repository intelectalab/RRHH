import type { ChangeField } from '@/modules/people/profile/types'
import type { HomeCard } from './types'

/** Destinos de la barra inferior del portal (mobile-first: 4–5 destinos). */
export const PORTAL_NAV: ReadonlyArray<{ href: string; key: string; icon: 'home' | 'calendar' | 'file' | 'user' | 'inbox' | 'checkSquare'; exact: boolean; permission: string | readonly string[] | null; module: string }> = [
  { href: '/mi', key: 'portal.nav_home', icon: 'home', exact: true, permission: null, module: 'core' },
  { href: '/tiempo/vacaciones', key: 'portal.nav_vacations', icon: 'calendar', exact: false, permission: 'time.vacation_requests.view', module: 'time_off' },
  { href: '/tiempo/ausencias', key: 'portal.nav_absences', icon: 'file', exact: false, permission: 'time.absences.view', module: 'time_off' },
  // ESS-3 · ESS-4 · ESS-5: documentos y trámites (constancias y solicitudes a RRHH).
  { href: '/mi/documentos', key: 'portal.nav_documents', icon: 'file', exact: true, permission: 'documents.documents.view', module: 'core' },
  { href: '/mi/solicitudes', key: 'portal.nav_requests', icon: 'checkSquare', exact: false, permission: ['people.certificates.request', 'helpdesk.tickets.create'], module: 'core' },
  { href: '/mi/perfil', key: 'portal.nav_profile', icon: 'user', exact: true, permission: 'people.employments.view', module: 'core' },
  { href: '/pendientes', key: 'portal.nav_pending', icon: 'inbox', exact: false, permission: null, module: 'base' },
]

export type PortalNavItem = (typeof PORTAL_NAV)[number]

/** Destinos visibles: módulo contratado y permiso (el servidor vuelve a validar al entrar). */
export function portalNav(modules: readonly string[], can: (p: string) => boolean): PortalNavItem[] {
  return PORTAL_NAV.filter((n) => modules.includes(n.module) && allowed(n.permission, can))
}

/** Un destino con varios permisos se muestra si el usuario tiene cualquiera de ellos. */
function allowed(permission: string | readonly string[] | null, can: (p: string) => boolean): boolean {
  if (permission === null) return true
  return typeof permission === 'string' ? can(permission) : permission.some(can)
}

/** ESS-5 · Tono del estado de una solicitud a RRHH (la que espera al colaborador resalta). */
export function ticketTone(status: string): 'ok' | 'wa' | 'er' | 'in' | 'gr' {
  if (status === 'waiting_info') return 'wa'
  if (status === 'resolved') return 'ok'
  if (status === 'closed' || status === 'cancelled') return 'gr'
  return 'in'
}

/** ESS-4 · Tono del estado de una constancia. */
export function certificateTone(status: string): 'ok' | 'wa' | 'er' | 'in' | 'gr' {
  if (status === 'issued') return 'ok'
  if (status === 'rejected') return 'er'
  return 'wa'
}

/** ESS-5 · Un tipo que exige adjunto no se envía sin archivo (el servidor también lo valida). */
export function missingAttachment(category: { requires_attachment: boolean } | undefined, files: readonly File[]): boolean {
  return Boolean(category?.requires_attachment) && files.length === 0
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
