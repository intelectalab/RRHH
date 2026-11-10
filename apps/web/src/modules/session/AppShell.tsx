'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useT } from '@/lib/useT'
import { Icon } from '@/ui/components/Icon'
import { CompanyProvider } from './CompanyProvider'
import { CompanySelector } from './CompanySelector'
import { SessionProvider, useSession } from './SessionProvider'

type ModuleLink = { href: string; key: string; visible: (can: (p: string) => boolean) => boolean }

/** Módulos de la barra. Se agregan aquí a medida que existen pantallas (sin botones sin función). */
const MODULES: ModuleLink[] = [
  { href: '/', key: 'shell.modules_home', visible: () => true },
  { href: '/organizacion/empresas', key: 'shell.modules_org', visible: (can) => can('org.legal_entities.view') },
  { href: '/configuracion', key: 'shell.modules_settings', visible: (can) => can('identity.users.view') || can('identity.roles.view') || can('audit.logs.view') },
]

function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('')
}

function Shell({ children }: { children: ReactNode }) {
  const { t } = useT()
  const { session, can, logout } = useSession()
  const pathname = usePathname()
  const [open, setOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)

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
                <button type="button" className="menu-item" role="menuitem" onClick={() => void logout()}>
                  <Icon name="signOut" /> {t('shell.logout')}
                </button>
              </div>
            )}
          </div>
        </div>
      </header>
      <nav className="modbar" aria-label={t('shell.modules')}>
        <div className="in">
          {MODULES.filter((m) => m.visible(can)).map((m) => (
            <Link key={m.href} href={m.href} className={active(m.href) ? 'on' : undefined} aria-current={active(m.href) ? 'page' : undefined}>
              {t(m.key)}
            </Link>
          ))}
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
