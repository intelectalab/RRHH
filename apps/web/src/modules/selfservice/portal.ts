import type { ChangeField } from '@/modules/people/profile/types'
import type { HomeCard } from './types'

/** Destinos de la barra inferior del portal (mobile-first: 4–5 destinos). */
export const PORTAL_NAV: ReadonlyArray<{ href: string; key: string; icon: 'home' | 'calendar' | 'file' | 'user' | 'inbox' | 'checkSquare'; exact: boolean; permission: string | readonly string[] | null; module: string }> = [
  { href: '/mi', key: 'portal.nav_home', icon: 'home', exact: true, permission: null, module: 'core' },
  { href: '/tiempo/vacaciones', key: 'portal.nav_vacations', icon: 'calendar', exact: false, permission: 'time.vacation_requests.view', module: 'time_off' },
  { href: '/tiempo/ausencias', key: 'portal.nav_absences', icon: 'file', exact: false, permission: 'time.absences.view', module: 'time_off' },
  // ESS-3 · ESS-4 · ESS-5: documentos y trámites (constancias y solicitudes a RRHH).
  { href: '/mi/documentos', key: 'portal.nav_documents', icon: 'file', exact: true, permission: 'documents.documents.view', module: 'core' },
  // BOL-2 · BOL-3: mis boletas de pago.
  { href: '/mi/boletas', key: 'portal.nav_payslips', icon: 'file', exact: true, permission: null, module: 'payroll' },
  // DOC-6: firmar desde el teléfono.
  { href: '/mi/firmas', key: 'portal.nav_signatures', icon: 'checkSquare', exact: true, permission: 'documents.signature_requests.sign', module: 'core' },
  { href: '/mi/solicitudes', key: 'portal.nav_requests', icon: 'checkSquare', exact: false, permission: ['people.certificates.request', 'helpdesk.tickets.create'], module: 'core' },
  { href: '/mi/perfil', key: 'portal.nav_profile', icon: 'user', exact: true, permission: 'people.employments.view', module: 'core' },
  { href: '/pendientes', key: 'portal.nav_pending', icon: 'inbox', exact: false, permission: null, module: 'base' },
]

export type PortalNavItem = (typeof PORTAL_NAV)[number]

/** ESS-6 · Destinos que caben en la barra inferior; con más, los últimos van a «Más» (sin desplazamiento a 360 px). */
export const PORTAL_NAV_MAX = 5

/** Reparte los destinos: los que van en la barra y los que se abren desde «Más». */
export function splitNav<T>(items: readonly T[], max: number = PORTAL_NAV_MAX): { bar: T[]; more: T[] } {
  if (items.length <= max) return { bar: [...items], more: [] }
  return { bar: items.slice(0, max - 1), more: items.slice(max - 1) }
}

/**
 * ESS-6 · Acciones del colaborador a un toque en su inicio. Es el punto de extensión del portal: cada
 * módulo suma la suya cuando se activa (marcar asistencia, ver boletas, responder encuestas…), atada a la
 * tarjeta que su módulo registra en el inicio (HomeCardRegistry): sin esa tarjeta, la acción no aparece.
 * `create` abre directamente el formulario de la pantalla (UI-8, `?crear=`).
 */
export type PortalAction = {
  key: string
  card: HomeCard['key']
  href: string
  icon: 'home' | 'calendar' | 'file' | 'user' | 'inbox' | 'checkSquare'
  permission: string | null
  /** false: también se ofrece en modo solo lectura (no escribe nada). */
  writes: boolean
}

export const PORTAL_ACTIONS: readonly PortalAction[] = [
  { key: 'portal.request_vacation', card: 'vacations', href: '/tiempo/vacaciones?crear=vacation_request', icon: 'calendar', permission: 'time.vacation_requests.create', writes: true },
  { key: 'portal.request_absence', card: 'absences', href: '/tiempo/ausencias?crear=absence', icon: 'file', permission: 'time.absences.create', writes: true },
  { key: 'portal.my_profile', card: 'profile', href: '/mi/perfil', icon: 'user', permission: null, writes: false },
  { key: 'portal.request_certificate', card: 'certificates', href: '/mi/solicitudes', icon: 'file', permission: null, writes: false },
  { key: 'portal.request_hr', card: 'hr_requests', href: '/mi/solicitudes', icon: 'checkSquare', permission: null, writes: true },
]

/** Acciones visibles: su tarjeta está en el inicio, hay permiso y, si escribe, la relación no es de solo lectura. */
export function portalActions(cards: readonly HomeCard[], can: (p: string) => boolean, readOnly: boolean, actions: readonly PortalAction[] = PORTAL_ACTIONS): PortalAction[] {
  const present = new Set(cards.map((c) => c.key))
  return actions.filter((a) => present.has(a.card) && (a.permission === null || can(a.permission)) && !(a.writes && readOnly))
}

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
