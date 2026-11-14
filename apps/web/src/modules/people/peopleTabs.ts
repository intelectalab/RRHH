/**
 * Pestañas de Personas: Colaboradores (COL-9, con people.employments.view), Directorio y Organigrama
 * (ORG-6 · ORG-7, con people.directory.view), Movimientos (HIS-4, con people.assignments.view) y Cambios de
 * datos (COL-4 · RN-COL-06, con people.change_requests.approve) y Contratos por vencer (CON-4, con
 * contracts.contracts.view).
 */
export function peopleTabs(t: (k: string) => string, current: 'employees' | 'directory' | 'chart' | 'movements' | 'requests' | 'contracts', can: (p: string) => boolean = () => false) {
  return [
    ...(can('people.employments.view') ? [{ href: '/personas/colaboradores', label: t('people.tab_employees'), active: current === 'employees' }] : []),
    ...(current === 'employees' && !can('people.directory.view') ? [] : [
      { href: '/personas/directorio', label: t('people.tab_directory'), active: current === 'directory' },
      { href: '/personas/organigrama', label: t('people.tab_chart'), active: current === 'chart' },
    ]),
    ...(can('people.assignments.view') && can('people.employments.view') ? [{ href: '/personas/movimientos', label: t('people.tab_movements'), active: current === 'movements' }] : []),
    ...(can('contracts.contracts.view') && can('people.employments.view') ? [{ href: '/personas/contratos', label: t('people.tab_contracts'), active: current === 'contracts' }] : []),
    ...(can('people.change_requests.approve') ? [{ href: '/personas/cambios-de-datos', label: t('people.tab_requests'), active: current === 'requests' }] : []),
  ]
}
