/** Pestañas de Personas → Directorio y Organigrama (ORG-6 · ORG-7). */
export function peopleTabs(t: (k: string) => string, current: 'directory' | 'chart') {
  return [
    { href: '/personas/directorio', label: t('people.tab_directory'), active: current === 'directory' },
    { href: '/personas/organigrama', label: t('people.tab_chart'), active: current === 'chart' },
  ]
}
