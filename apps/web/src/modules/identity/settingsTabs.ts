import type { Tab } from '@/ui/components/PageHeader'

export function settingsTabs(t: (k: string) => string, can: (p: string) => boolean, current: 'users' | 'roles' | 'audit' | 'sensitive'): Tab[] {
  const tabs: Tab[] = []
  if (can('identity.users.view')) tabs.push({ href: '/configuracion/usuarios', label: t('settings.tab_users'), active: current === 'users' })
  if (can('identity.roles.view')) tabs.push({ href: '/configuracion/roles', label: t('settings.tab_roles'), active: current === 'roles' })
  if (can('audit.logs.view')) tabs.push({ href: '/configuracion/auditoria', label: t('settings.tab_audit'), active: current === 'audit' })
  if (can('audit.sensitive_access.view')) tabs.push({ href: '/configuracion/accesos-sensibles', label: t('settings.tab_sensitive'), active: current === 'sensitive' })
  return tabs
}
