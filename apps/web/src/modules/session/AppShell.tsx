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
import { NotificationBell } from '@/modules/notifications/NotificationBell'
import { SessionProvider, useSession } from './SessionProvider'
import { ShellTitleProvider, useShellTitle } from './ShellTitle'

type IconName = Parameters<typeof Icon>[0]['name']

/** module: código del módulo contratado (tenant_modules); visible: permisos que lo habilitan. */
type NavLink = { href: string; key: string; icon: IconName; module: string; visible: (can: (p: string) => boolean) => boolean; count?: 'approval' | 'task' }

/** Menú principal (docs/10 §3.12 y §4). Se agregan entradas a medida que existen pantallas (sin botones sin función). */
const MENU: NavLink[] = [
  { href: '/', key: 'shell.modules_home', icon: 'home', module: 'base', visible: () => true },
  { href: '/organizacion/empresas', key: 'shell.modules_org', icon: 'building', module: 'base', visible: (can) => can('org.legal_entities.view') },
  { href: '/aprobaciones', key: 'shell.modules_approvals', icon: 'inbox', module: 'base', visible: (can) => can('workflow.approval_requests.view'), count: 'approval' },
  { href: '/tareas', key: 'shell.modules_tasks', icon: 'checkSquare', module: 'base', visible: (can) => can('workflow.tasks.view'), count: 'task' },
]

const SETTINGS_PERMS = ['settings.catalogs.update', 'identity.users.view', 'identity.roles.view', 'workflow.approval_flows.view', 'workflow.workflows.view', 'notifications.templates.view', 'settings.parameters.view', 'time.holiday_calendars.view', 'audit.trash.view', 'settings.custom_fields.view', 'settings.exchange_rates.view', 'audit.logs.view', 'contracts.contract_types.view', 'time.absence_types.view', 'audit.activity.view']

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

  const active = (href: string) => (href === '/' ? pathname === '/' : pathname.startsWith(href.split('/').slice(0, 2).join('/')))
  const role = session.roles[0]?.name ?? ''
  const items = MENU.filter((m) => session.modules.includes(m.module) && m.visible(can))
  const showSettings = session.modules.includes('base') && SETTINGS_PERMS.some((p) => can(p))

  const navLink = (href: string, label: string, icon: IconName, count = 0) => (
    <Link key={href} href={href} className={`nav${active(href) ? ' on' : ''}`} aria-current={active(href) ? 'page' : undefined} title={min ? label : undefined}>
      <Icon name={icon} size={20} />
      <span className="l">{label}</span>
      {count > 0 && <span className="badge-n" aria-label={t('shell.inbox_aria', { n: count })}>{count}</span>}
    </Link>
  )

  return (
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
          <div className="side-sec">{t('shell.menu_section')}</div>
          {items.map((m) => navLink(m.href, t(m.key), m.icon, m.count ? (inbox?.[m.count] ?? 0) : 0))}
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
            <span className="crumb">{[session.tenant?.name, role].filter(Boolean).join(' · ')}</span>
            {title && <h1 className="ttl">{title}</h1>}
          </div>
          <CommandPalette />
          <CompanySelector />
          <InboxButton counts={inbox} />
          <NotificationBell />
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
                <button type="button" className="menu-item" role="menuitem" onClick={() => void logout()}>
                  <Icon name="signOut" /> {t('shell.logout')}
                </button>
              </div>
            )}
          </div>
        </header>
        <div className="app-content">{children}</div>
      </div>
    </div>
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
