/** Pestañas de Organización → Estructura (empresas, unidades, sedes, centros de costo, puestos, posiciones, solicitudes y dotación), según permisos. */
export type OrgTab = 'companies' | 'units' | 'sites' | 'cost_centers' | 'jobs' | 'positions' | 'position_requests' | 'headcount' | 'scenarios'

export function orgTabs(t: (k: string) => string, can: (p: string) => boolean, current: OrgTab, modules: string[] = []) {
  const tabs: Array<{ href: string; label: string; active: boolean }> = []
  const core = modules.includes('core')
  if (can('org.legal_entities.view')) tabs.push({ href: '/organizacion/empresas', label: t('org.tab_companies'), active: current === 'companies' })
  if (can('org.units.view') && core) tabs.push({ href: '/organizacion/unidades', label: t('org.tab_units'), active: current === 'units' })
  if (can('org.sites.view')) tabs.push({ href: '/organizacion/sedes', label: t('org.tab_sites'), active: current === 'sites' })
  if (can('org.cost_centers.view')) tabs.push({ href: '/organizacion/centros-de-costo', label: t('org.tab_cost_centers'), active: current === 'cost_centers' })
  if (can('org.job_profiles.view')) tabs.push({ href: '/organizacion/puestos', label: t('org.tab_jobs'), active: current === 'jobs' })
  if (can('org.positions.view') && core) tabs.push({ href: '/organizacion/posiciones', label: t('org.tab_positions'), active: current === 'positions' })
  if (can('org.position_requests.view') && core) tabs.push({ href: '/organizacion/solicitudes-de-posicion', label: t('org.tab_position_requests'), active: current === 'position_requests' })
  if (can('org.positions.view') && core) tabs.push({ href: '/organizacion/dotacion', label: t('org.tab_headcount'), active: current === 'headcount' })
  if (can('org.headcount_plans.view') && core) tabs.push({ href: '/organizacion/escenarios', label: t('org.tab_scenarios'), active: current === 'scenarios' })
  return tabs
}
