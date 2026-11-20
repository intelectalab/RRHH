'use client'

import Link from 'next/link'
import { usePathname, useRouter } from 'next/navigation'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { api } from '@/lib/api'
import { locales, type Locale } from '@/lib/i18n'
import { useT } from '@/lib/useT'
import { applyTheme, storedTheme, type ThemeChoice } from '@/lib/theme'
import { BrandMark } from '@/ui/components/BrandMark'
import { BrandName } from '@/ui/components/BrandName'
import { Icon } from '@/ui/components/Icon'
import { CommandPalette } from './CommandPalette'
import { CompanyProvider } from './CompanyProvider'
import { CompanySelector } from './CompanySelector'
import { InboxButton, useInboxCount } from './InboxButton'
import { PlatformBanners } from './PlatformBanners'
import { QuickCreate } from './QuickCreate'
import { HelpButton, HelpProvider } from '@/modules/help/HelpCenter'
import { useInstallPrompt, useOnline } from '@/modules/pwa/pwa'
import { NotificationBell } from '@/modules/notifications/NotificationBell'
import { SessionProvider, useSession } from './SessionProvider'
import { ShellTitleProvider, useShellTitle } from './ShellTitle'

type IconName = Parameters<typeof Icon>[0]['name']

/** module: código del módulo contratado (tenant_modules); visible: permisos que lo habilitan. */
type NavGroup = 'home' | 'people' | 'time' | 'payroll'
type NavLink = { href: string; key: string; group: NavGroup; icon: IconName; module: string; visible: (can: (p: string) => boolean) => boolean; count?: 'approval' | 'task'; exact?: boolean }

/** UI-2 · Grupos de la barra lateral en el orden de docs/10 §4 (se agregan a medida que existen pantallas). */
const GROUPS: NavGroup[] = ['home', 'people', 'time', 'payroll']

/** Menú principal (docs/10 §3.12 y §4). Se agregan entradas a medida que existen pantallas (sin botones sin función). */
const MENU: NavLink[] = [
  { href: '/', key: 'shell.modules_home', group: 'home', icon: 'home', module: 'base', visible: () => true },
  { href: '/mi', key: 'shell.modules_portal', group: 'home', icon: 'user', module: 'core', visible: (can) => can('people.employments.view') },
  { href: '/organizacion/empresas', key: 'shell.modules_org', group: 'people', icon: 'building', module: 'base', visible: (can) => can('org.legal_entities.view') },
  { href: '/mi-equipo', key: 'shell.modules_my_team', group: 'people', icon: 'users', module: 'core', visible: (can) => can('people.team.view') },
  { href: '/personas/colaboradores', key: 'shell.modules_employees', group: 'people', icon: 'user', module: 'core', visible: (can) => can('people.employments.view'), exact: true },
  { href: '/personas/directorio', key: 'shell.modules_directory', group: 'people', icon: 'users', module: 'core', visible: (can) => can('people.directory.view'), exact: true },
  { href: '/personas/organigrama', key: 'shell.modules_org_chart', group: 'people', icon: 'orgChart', module: 'core', visible: (can) => can('people.directory.view'), exact: true },
  { href: '/primeros-pasos', key: 'shell.modules_setup', group: 'home', icon: 'checkSquare', module: 'core', visible: (can) => can('imports.setup.use') },
  { href: '/aprobaciones', key: 'shell.modules_approvals', group: 'home', icon: 'inbox', module: 'base', visible: (can) => can('workflow.approval_requests.view'), count: 'approval' },
  { href: '/tareas', key: 'shell.modules_tasks', group: 'home', icon: 'checkSquare', module: 'base', visible: (can) => can('workflow.tasks.view'), count: 'task' },
  { href: '/exportaciones', key: 'shell.modules_exports', group: 'home', icon: 'download', module: 'base', visible: (can) => can('reports.exports.view') },
  { href: '/documentos/vencimientos', key: 'shell.modules_documents', group: 'people', icon: 'file', module: 'core', visible: (can) => can('documents.documents.view') },
  { href: '/documentos/solicitudes', key: 'shell.modules_document_requests', group: 'people', icon: 'inbox', module: 'core', visible: (can) => can('documents.documents.request') },
  { href: '/documentos/plantillas', key: 'shell.modules_document_templates', group: 'people', icon: 'file', module: 'core', visible: (can) => can('documents.templates.view') },
  { href: '/documentos/firmas', key: 'shell.modules_signatures', group: 'people', icon: 'file', module: 'core', visible: (can) => can('documents.signature_requests.create') },
  { href: '/personas/renovaciones', key: 'shell.modules_renewals', group: 'people', icon: 'file', module: 'core', visible: (can) => can('contracts.contracts.bulk_renew') },
  { href: '/personas/teletrabajo', key: 'shell.modules_remote_work', group: 'people', icon: 'home', module: 'core', visible: (can) => can('contracts.remote_work.create') || can('contracts.remote_work.decide') },
  { href: '/personas/constancias', key: 'shell.modules_certificates', group: 'people', icon: 'file', module: 'core', visible: (can) => can('people.certificates.issue') },
  { href: '/personas/solicitudes', key: 'shell.modules_hr_requests', group: 'people', icon: 'inbox', module: 'core', visible: (can) => can('helpdesk.tickets.update') },
  { href: '/calendario', key: 'shell.modules_calendar', group: 'time', icon: 'calendar', module: 'base', visible: (can) => can('time.calendar.view') },
  { href: '/tiempo/ausencias', key: 'shell.modules_absences', group: 'time', icon: 'calendar', module: 'time_off', visible: (can) => can('time.absences.view') },
  { href: '/tiempo/vacaciones', key: 'shell.modules_vacations', group: 'time', icon: 'calendar', module: 'time_off', visible: (can) => can('time.vacation_requests.view') },
  { href: '/tiempo/vacaciones/programacion', key: 'shell.modules_vacation_planning', group: 'time', icon: 'calendar', module: 'time_off', visible: (can) => can('time.vacation_requests.view') },
  { href: '/tiempo/vacaciones/acuerdos', key: 'shell.modules_vacation_agreements', group: 'time', icon: 'file', module: 'time_off', visible: (can) => can('time.vacation_balances.adjust') },
  { href: '/tiempo/subsidios', key: 'shell.modules_subsidies', group: 'time', icon: 'file', module: 'time_off', visible: (can) => can('time.absences.manage_subsidy') },
  { href: '/tiempo/resumen-periodo', key: 'shell.modules_period_summary', group: 'time', icon: 'calendar', module: 'time_off', visible: (can) => can('time.absences.create_for_others') },
  { href: '/nomina/periodos', key: 'shell.modules_payroll_periods', group: 'payroll', icon: 'file', module: 'payroll', visible: (can) => can('payroll.periods.view') },
]

const SETTINGS_PERMS = ['settings.catalogs.update', 'identity.users.view', 'identity.roles.view', 'workflow.approval_flows.view', 'workflow.workflows.view', 'notifications.templates.view', 'settings.parameters.view', 'time.holiday_calendars.view', 'audit.trash.view', 'settings.custom_fields.view', 'settings.exchange_rates.view', 'audit.logs.view', 'contracts.contract_types.view', 'time.absence_types.view', 'time.vacation_policies.view', 'audit.activity.view', 'imports.imports.view', 'imports.setup.use', 'settings.organization.view']

const MIN_KEY = 'gestia.side_min'

function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('')
}

function Shell({ children }: { children: ReactNode }) {
  const { t, locale, setLocale } = useT()
  const { session, can, logout } = useSession()
  const pathname = usePathname()
  const router = useRouter()
  const title = useShellTitle()
  const [userOpen, setUserOpen] = useState(false)
  const [drawer, setDrawer] = useState(false)
  const [min, setMin] = useState(false)
  const [theme, setTheme] = useState<ThemeChoice>('system')
  const menuRef = useRef<HTMLDivElement>(null)
  const inbox = useInboxCount()
  const online = useOnline()
  const { install } = useInstallPrompt()

  useEffect(() => {
    setTheme(storedTheme())
    try { setMin(window.localStorage.getItem(MIN_KEY) === '1') } catch { /* sin almacenamiento: barra completa */ }
  }, [])
  useEffect(() => setDrawer(false), [pathname])
  // SEG-5: con la clave vencida o temporal, todo lleva a cambiarla primero.
  useEffect(() => {
    if (session.user.must_change_password && pathname !== '/cuenta/seguridad') router.replace('/cuenta/seguridad')
  }, [session.user.must_change_password, pathname, router])

  useEffect(() => {
    if (!userOpen) return
    const close = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) setUserOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [userOpen])

  useEffect(() => {
    if (!drawer) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setDrawer(false) }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [drawer])

  // CFG-6: el idioma cambia al instante; se guarda en la cuenta para la API y las notificaciones.
  async function changeLocale(next: Locale) {
    const previous = locale
    setLocale(next)
    try {
      await api.request('/me/preferences', 'PATCH', { locale: next })
    } catch {
      setLocale(previous)
    }
  }

  function toggleMin() {
    setMin((v) => {
      try { window.localStorage.setItem(MIN_KEY, v ? '0' : '1') } catch { /* preferencia solo en memoria */ }
      return !v
    })
  }

  const active = (href: string, exact = false) => (href === '/' ? pathname === '/' : exact ? pathname === href || pathname.startsWith(`${href}/`) : pathname.startsWith(href.split('/').slice(0, 2).join('/')))
  const role = session.roles[0]?.name ?? ''
  // SAS-5: el menú muestra los módulos activos y los retirados que siguen en solo lectura (RN-SAS-06).
  const visibleModules = [...session.modules, ...(session.modules_read_only ?? [])]
  const items = MENU.filter((m) => visibleModules.includes(m.module) && m.visible(can))
  const showSettings = session.modules.includes('base') && SETTINGS_PERMS.some((p) => can(p))
  // Miga de pan: organización · grupo de la pantalla abierta (en el inicio, el rol).
  const current = items.filter((m) => m.href !== '/' && active(m.href, m.exact)).sort((a, b) => b.href.length - a.href.length)[0]
  const crumb = [session.tenant?.name, current ? t(`shell.group_${current.group}`) : role].filter(Boolean).join(' · ')

  const navLink = (href: string, label: string, icon: IconName, count = 0, exact = false) => (
    <Link key={href} href={href} className={`nav${active(href, exact) ? ' on' : ''}`} aria-current={active(href, exact) ? 'page' : undefined} title={min ? label : undefined}>
      <Icon name={icon} size={20} />
      <span className="l">{label}</span>
      {count > 0 && <span className="badge-n" aria-label={t('shell.inbox_aria', { n: count })}>{count}</span>}
    </Link>
  )

  return (
    <HelpProvider>
    <div className={`app${min ? ' min' : ''}${drawer ? ' sopen' : ''}`}>
      <a href="#contenido" className="saltar">{t('shell.skip')}</a>
      <aside className="side" id="menu-principal" aria-label={t('shell.modules')}>
        <div className="brand">
          <Link className="logo" href="/" aria-label={t('shell.home_aria')}>
            <BrandMark />
            <BrandName />
          </Link>
          <button type="button" className="sbtn side-min" onClick={toggleMin} aria-label={t(min ? 'shell.expand_menu' : 'shell.collapse_menu')} aria-pressed={min}>
            <Icon name="sidebar" />
          </button>
          <button type="button" className="sbtn side-close" onClick={() => setDrawer(false)} aria-label={t('shell.close_menu')}>
            <Icon name="close" />
          </button>
        </div>
        <div className="side-user">
          <span className="av" aria-hidden="true">{initials(session.user.display_name)}</span>
          <div>
            <b>{session.user.display_name}</b>
            <span className="r">{role}</span>
          </div>
        </div>
        <nav className="side-nav">
          {GROUPS.map((g) => {
            const inGroup = items.filter((m) => m.group === g)
            if (inGroup.length === 0) return null
            return (
              <div key={g} className="side-grupo" role="group" aria-labelledby={`side-g-${g}`}>
                <div className="side-sec" id={`side-g-${g}`}>{t(`shell.group_${g}`)}</div>
                {inGroup.map((m) => navLink(m.href, t(m.key), m.icon, m.count ? (inbox?.[m.count] ?? 0) : 0, m.exact))}
              </div>
            )
          })}
        </nav>
        <div className="side-foot">
          <div className="side-sec">{t('shell.account_section')}</div>
          {navLink('/cuenta/seguridad', t('shell.account_security'), 'user')}
          {showSettings && navLink('/configuracion', t('shell.modules_settings'), 'sliders')}
          <button type="button" className="nav" onClick={() => void logout()} title={min ? t('shell.logout') : undefined}>
            <Icon name="signOut" size={20} />
            <span className="l">{t('shell.logout')}</span>
          </button>
        </div>
      </aside>
      {drawer && <div className="side-velo" onClick={() => setDrawer(false)} aria-hidden="true" />}

      <div className="app-main">
        <header className="top">
          <button type="button" className="ibtn top-menu" onClick={() => setDrawer(true)} aria-label={t('shell.open_menu')} aria-expanded={drawer} aria-controls="menu-principal">
            <Icon name="menu" />
          </button>
          <div className="top-t">
            <span className="crumb">{crumb}</span>
            {title && <h1 className="ttl">{title}</h1>}
          </div>
          <CommandPalette />
          <QuickCreate />
          <CompanySelector />
          <InboxButton counts={inbox} />
          <NotificationBell />
          <HelpButton />
          <div className="menu" ref={menuRef}>
            <button type="button" className="userbtn" onClick={() => setUserOpen((v) => !v)} aria-expanded={userOpen} aria-haspopup="menu" aria-label={t('shell.account', { name: session.user.display_name })}>
              <span className="av" aria-hidden="true">{initials(session.user.display_name)}</span>
              <span className="userbtn-t">
                <b>{session.user.display_name}</b>
                <small>{role}</small>
              </span>
            </button>
            {userOpen && (
              <div className="menu-lista" role="menu">
                <div className="menu-grupo" role="group" aria-label={t('shell.theme')}>
                  <span className="small text-secondary">{t('shell.theme')}</span>
                  <div className="seg">
                    {(['light', 'dark', 'system'] as const).map((c) => (
                      <button key={c} type="button" role="menuitemradio" aria-checked={theme === c} className={theme === c ? 'on' : undefined} onClick={() => { applyTheme(c); setTheme(c) }}>
                        {t(`shell.theme_${c}`)}
                      </button>
                    ))}
                  </div>
                </div>
                <div className="menu-grupo" role="group" aria-label={t('shell.language')}>
                  <span className="small text-secondary">{t('shell.language')}</span>
                  <div className="seg">
                    {locales.map((l) => (
                      <button key={l} type="button" role="menuitemradio" lang={l} aria-checked={locale === l} className={locale === l ? 'on' : undefined} onClick={() => void changeLocale(l)}>
                        {t(`shell.lang_${l}`)}
                      </button>
                    ))}
                  </div>
                </div>
                <Link href="/cuenta/seguridad" className="menu-item" role="menuitem" onClick={() => setUserOpen(false)}>
                  <Icon name="lock" /> {t('shell.account_security')}
                </Link>
                <Link href="/cuenta/notificaciones" className="menu-item" role="menuitem" onClick={() => setUserOpen(false)}>
                  <Icon name="bell" /> {t('shell.my_notifications')}
                </Link>
                {install && (
                  <button type="button" className="menu-item" role="menuitem" onClick={() => { setUserOpen(false); void install() }}>
                    <Icon name="download" /> {t('shell.install')}
                  </button>
                )}
                <button type="button" className="menu-item" role="menuitem" onClick={() => void logout()}>
                  <Icon name="signOut" /> {t('shell.logout')}
                </button>
              </div>
            )}
          </div>
        </header>
        {!online && <div className="offline-aviso" role="status">{t('shell.offline')}</div>}
        <PlatformBanners />
        <div className="app-content">{children}</div>
      </div>
    </div>
    </HelpProvider>
  )
}

export function AppShell({ children }: { children: ReactNode }) {
  return (
    <SessionProvider fallback={<div className="main" aria-busy="true"><span className="esq" style={{ width: 240 }} /></div>}>
      <CompanyProvider>
        <ShellTitleProvider>
          <Shell>{children}</Shell>
        </ShellTitleProvider>
      </CompanyProvider>
    </SessionProvider>
  )
}
