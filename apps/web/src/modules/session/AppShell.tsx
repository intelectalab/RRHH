'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useT } from '@/lib/useT'
import { applyTheme, storedTheme, type ThemeChoice } from '@/lib/theme'
import { Icon } from '@/ui/components/Icon'
import { CommandPalette } from './CommandPalette'
import { CompanyProvider } from './CompanyProvider'
import { CompanySelector } from './CompanySelector'
import { InboxButton, useInboxCount } from './InboxButton'
import { NotificationBell } from '@/modules/notifications/NotificationBell'
import { SessionProvider, useSession } from './SessionProvider'

/** module: código del módulo contratado (tenant_modules); visible: permisos que lo habilitan. */
type ModuleLink = { href: string; key: string; module: string; visible: (can: (p: string) => boolean) => boolean }

/** Módulos de la barra. Se agregan aquí a medida que existen pantallas (sin botones sin función). */
const MODULES: ModuleLink[] = [
  { href: '/', key: 'shell.modules_home', module: 'base', visible: () => true },
  { href: '/organizacion/empresas', key: 'shell.modules_org', module: 'base', visible: (can) => can('org.legal_entities.view') },
  { href: '/aprobaciones', key: 'shell.modules_approvals', module: 'base', visible: (can) => can('workflow.approval_requests.view') },
  { href: '/tareas', key: 'shell.modules_tasks', module: 'base', visible: (can) => can('workflow.tasks.view') },
  { href: '/configuracion', key: 'shell.modules_settings', module: 'base', visible: (can) => can('identity.users.view') || can('identity.roles.view') || can('workflow.approval_flows.view') || can('notifications.templates.view') || can('settings.parameters.view') || can('audit.logs.view') },
]

function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('')
}

function Shell({ children }: { children: ReactNode }) {
  const { t } = useT()
  const { session, can, logout } = useSession()
  const pathname = usePathname()
  const [open, setOpen] = useState(false)
  const [navOpen, setNavOpen] = useState(false)
  const [theme, setTheme] = useState<ThemeChoice>('system')
  const menuRef = useRef<HTMLDivElement>(null)
  const inbox = useInboxCount()

  useEffect(() => setTheme(storedTheme()), [])
  useEffect(() => setNavOpen(false), [pathname])

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [open])

  const active = (href: string) => (href === '/' ? pathname === '/' : pathname.startsWith(href.split('/').slice(0, 2).join('/')))

  return (
    <>
      <a href="#contenido" className="saltar">{t('shell.skip')}</a>
      <header className="topbar">
        <div className="in">
          <Link className="logo" href="/" aria-label={t('shell.home_aria')}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/logo-aynihr.svg" alt="" />
            <span><b>Ayni</b><span>HR</span></span>
          </Link>
          {session.tenant && <span className="org">{session.tenant.name}</span>}
          <CompanySelector />
          <div className="espacio" />
          <CommandPalette />
          <InboxButton counts={inbox} />
          <NotificationBell />
          <div className="menu" ref={menuRef}>
            <button type="button" className="userbtn" onClick={() => setOpen((v) => !v)} aria-expanded={open} aria-haspopup="menu" aria-label={t('shell.account', { name: session.user.display_name })}>
              <span className="av" aria-hidden="true">{initials(session.user.display_name)}</span>
              <span>
                <b>{session.user.display_name}</b>
                <small>{session.roles[0]?.name ?? ''}</small>
              </span>
            </button>
            {open && (
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
                <Link href="/cuenta/notificaciones" className="menu-item" role="menuitem" onClick={() => setOpen(false)}>
                  <Icon name="bell" /> {t('shell.my_notifications')}
                </Link>
                <button type="button" className="menu-item" role="menuitem" onClick={() => void logout()}>
                  <Icon name="signOut" /> {t('shell.logout')}
                </button>
              </div>
            )}
          </div>
        </div>
      </header>
      <nav className={`modbar${navOpen ? ' abierto' : ''}`} aria-label={t('shell.modules')}>
        <div className="in">
          <button type="button" className="modbar-toggle" aria-expanded={navOpen} aria-controls="modbar-lista" onClick={() => setNavOpen((v) => !v)}>
            <Icon name="menu" /> {t('shell.menu')}
          </button>
          <div className="modbar-lista" id="modbar-lista">
          {MODULES.filter((m) => session.modules.includes(m.module) && m.visible(can)).map((m) => (
            <Link key={m.href} href={m.href} className={active(m.href) ? 'on' : undefined} aria-current={active(m.href) ? 'page' : undefined}>
              {t(m.key)}
              {m.href === '/aprobaciones' && (inbox?.approval ?? 0) > 0 && <span className="cnt-mod" aria-label={t('shell.inbox_aria', { n: inbox?.approval ?? 0 })}>{inbox?.approval}</span>}
              {m.href === '/tareas' && (inbox?.task ?? 0) > 0 && <span className="cnt-mod" aria-label={t('shell.inbox_aria', { n: inbox?.task ?? 0 })}>{inbox?.task}</span>}
            </Link>
          ))}
          </div>
        </div>
      </nav>
      {children}
    </>
  )
}

export function AppShell({ children }: { children: ReactNode }) {
  return (
    <SessionProvider fallback={<div className="main" aria-busy="true"><span className="esq" style={{ width: 240 }} /></div>}>
      <CompanyProvider>
        <Shell>{children}</Shell>
      </CompanyProvider>
    </SessionProvider>
  )
}
