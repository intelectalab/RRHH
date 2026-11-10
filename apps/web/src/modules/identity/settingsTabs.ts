import type { Tab } from '@/ui/components/PageHeader'

export function settingsTabs(t: (k: string) => string, can: (p: string) => boolean, current: 'users' | 'roles'): Tab[] {
  const tabs: Tab[] = []
  if (can('identity.users.view')) tabs.push({ href: '/configuracion/usuarios', label: t('settings.tab_users'), active: current === 'users' })
  if (can('identity.roles.view')) tabs.push({ href: '/configuracion/roles', label: t('settings.tab_roles'), active: current === 'roles' })
  return tabs
}
