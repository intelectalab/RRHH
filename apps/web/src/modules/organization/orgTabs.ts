/** Pestañas de Organización → Estructura (empresas, sedes y centros de costo), según permisos. */
export function orgTabs(t: (k: string) => string, can: (p: string) => boolean, current: 'companies' | 'sites' | 'cost_centers') {
  const tabs: Array<{ href: string; label: string; active: boolean }> = []
  if (can('org.legal_entities.view')) tabs.push({ href: '/organizacion/empresas', label: t('org.tab_companies'), active: current === 'companies' })
  if (can('org.sites.view')) tabs.push({ href: '/organizacion/sedes', label: t('org.tab_sites'), active: current === 'sites' })
  if (can('org.cost_centers.view')) tabs.push({ href: '/organizacion/centros-de-costo', label: t('org.tab_cost_centers'), active: current === 'cost_centers' })
  return tabs
}
