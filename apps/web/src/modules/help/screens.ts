/**
 * AYU-3 · Clave de ayuda de cada pantalla por su ruta. Las claves son las de apps/api/resources/help/screens.json
 * (módulo y permisos que permiten abrirla); una prueba exige que ambas listas coincidan y que toda pantalla del
 * menú tenga su clave. La ruta más larga que coincide gana: /personas/colaboradores/123 → people.employees.
 */
export const HELP_SCREENS: ReadonlyArray<{ route: string; key: string }> = [
  { route: '/', key: 'home' },
  { route: '/pendientes', key: 'inbox' },
  { route: '/notificaciones', key: 'notifications' },
  { route: '/aprobaciones', key: 'approvals' },
  { route: '/tareas', key: 'tasks' },
  { route: '/exportaciones', key: 'exports' },
  { route: '/calendario', key: 'calendar' },
  { route: '/cuenta/seguridad', key: 'account.security' },
  { route: '/cuenta/notificaciones', key: 'account.notifications' },
  { route: '/configuracion', key: 'settings' },
  { route: '/configuracion/ayuda', key: 'settings.help' },
  { route: '/primeros-pasos', key: 'setup' },
  { route: '/organizacion', key: 'org' },
  { route: '/organizacion/empresas', key: 'org.companies' },
  { route: '/organizacion/posiciones', key: 'org.positions' },
  { route: '/organizacion/dotacion', key: 'org.positions' },
  { route: '/organizacion/escenarios', key: 'org.scenarios' },
  { route: '/organizacion/solicitudes-de-posicion', key: 'org.positions' },
  { route: '/personas/colaboradores', key: 'people.employees' },
  { route: '/personas/directorio', key: 'people.directory' },
  { route: '/personas/organigrama', key: 'people.org_chart' },
  { route: '/personas/contratos', key: 'people.contracts' },
  { route: '/personas/movimientos', key: 'people.movements' },
  { route: '/personas/cambios-de-datos', key: 'people.changes' },
  { route: '/personas/constancias', key: 'people.certificates' },
  { route: '/personas/teletrabajo', key: 'people.remote_work' },
  { route: '/personas/solicitudes', key: 'people.requests' },
  { route: '/mi-equipo', key: 'my_team' },
  { route: '/documentos/vencimientos', key: 'documents.expiry' },
  { route: '/documentos/solicitudes', key: 'documents.requests' },
  { route: '/documentos/plantillas', key: 'documents.templates' },
  { route: '/tiempo/vacaciones', key: 'time.vacations' },
  { route: '/tiempo/ausencias', key: 'time.absences' },
  { route: '/tiempo/subsidios', key: 'time.subsidies' },
  { route: '/mi', key: 'portal.home' },
  { route: '/mi/documentos', key: 'portal.documents' },
  { route: '/mi/perfil', key: 'portal.profile' },
  { route: '/mi/solicitudes', key: 'portal.requests' },
]

/** Clave de ayuda de la pantalla abierta, o null si la ruta no tiene ayuda propia. */
export function helpScreenFor(pathname: string): string | null {
  const path = pathname.split('?')[0].replace(/\/+$/, '') || '/'
  let best: { route: string; key: string } | null = null
  for (const s of HELP_SCREENS) {
    const match = s.route === '/' ? path === '/' : path === s.route || path.startsWith(`${s.route}/`)
    if (match && (best === null || s.route.length > best.route.length)) best = s
  }
  return best?.key ?? null
}
