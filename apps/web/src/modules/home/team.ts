/** MSS-3 · MSS-4 · Utilidades de las pantallas del equipo (sin lógica legal: solo agrupan y enlazan lo que da la API). */

export type TeamKeyDateKind = 'birthday' | 'anniversary' | 'probation_end' | 'contract_end'

export type TeamKeyDate = {
  kind: TeamKeyDateKind
  date: string
  employment_id: string
  name: string | null
  is_direct: boolean
  years: number | null
  link: string
}

export type TeamKeyDatesMeta = { from: string; to: string; kinds: TeamKeyDateKind[]; birthdays_hidden: boolean; team_size: number }

export type TeamTab = 'members' | 'requests' | 'dates'

/** Pestañas de «Mi equipo»: integrantes, solicitudes del equipo (con el centro de aprobaciones) y fechas clave. */
export function teamTabs(t: (k: string) => string, can: (p: string) => boolean, current: TeamTab) {
  const tabs: Array<{ href: string; label: string; active: boolean }> = [{ href: '/mi-equipo', label: t('my_team.tab_members'), active: current === 'members' }]
  if (can('workflow.approval_requests.view')) tabs.push({ href: '/mi-equipo/solicitudes', label: t('my_team.tab_requests'), active: current === 'requests' })
  tabs.push({ href: '/mi-equipo/fechas', label: t('my_team.tab_dates'), active: current === 'dates' })
  return tabs
}

/** Etiqueta (clase de color) de cada tipo de fecha. */
export const KIND_TAG: Record<TeamKeyDateKind, string> = { birthday: 'in', anniversary: 'ok', probation_end: 'wa', contract_end: 'er' }

/** Agrupa las fechas por mes (AAAA-MM), en el orden en que llegan (la API ya las ordena). */
export function groupByMonth(items: TeamKeyDate[]): { month: string; items: TeamKeyDate[] }[] {
  const groups: { month: string; items: TeamKeyDate[] }[] = []
  for (const item of items) {
    const month = item.date.slice(0, 7)
    const last = groups[groups.length - 1]
    if (last && last.month === month) last.items.push(item)
    else groups.push({ month, items: [item] })
  }
  return groups
}

/** Acciones que el manager puede iniciar para su equipo según sus permisos (la API vuelve a validarlas). */
export function teamStartLinks(can: (code: string) => boolean): { key: string; href: string }[] {
  const links: { key: string; href: string }[] = []
  if (can('people.assignments.propose')) links.push({ key: 'my_team.start_movement', href: '/mi-equipo' })
  if (can('org.position_requests.create')) links.push({ key: 'my_team.start_position', href: '/organizacion/solicitudes-de-posicion' })
  return links
}
