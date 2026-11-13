/**
 * Pestañas de Personas: Colaboradores (COL-9, con people.employments.view), Directorio y Organigrama
 * (ORG-6 · ORG-7, con people.directory.view) y Cambios de datos (COL-4 · RN-COL-06, con
 * people.change_requests.approve).
 */
export function peopleTabs(t: (k: string) => string, current: 'employees' | 'directory' | 'chart' | 'requests', can: (p: string) => boolean = () => false) {
  return [
    ...(can('people.employments.view') ? [{ href: '/personas/colaboradores', label: t('people.tab_employees'), active: current === 'employees' }] : []),
    ...(current === 'employees' && !can('people.directory.view') ? [] : [
      { href: '/personas/directorio', label: t('people.tab_directory'), active: current === 'directory' },
      { href: '/personas/organigrama', label: t('people.tab_chart'), active: current === 'chart' },
    ]),
    ...(can('people.change_requests.approve') ? [{ href: '/personas/cambios-de-datos', label: t('people.tab_requests'), active: current === 'requests' }] : []),
  ]
}
