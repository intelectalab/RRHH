'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { useEffect, useState } from 'react'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { isActive, portalNav, splitNav } from './portal'

/**
 * ESS-1 · ESS-6 · Barra inferior del portal del colaborador (mobile-first): hasta cinco destinos a un toque,
 * con blancos de 44 px o más; si hay más, los últimos se abren desde «Más» para que a 360 px no haya
 * desplazamiento horizontal. En pantallas anchas se oculta y queda el menú lateral.
 */
export function PortalNav() {
  const { t } = useT()
  const { session, can } = useSession()
  const pathname = usePathname() ?? ''
  const [open, setOpen] = useState(false)
  useEffect(() => { setOpen(false) }, [pathname])
  const items = portalNav([...session.modules, ...(session.modules_read_only ?? [])], can)
  if (items.length < 2) return null
  const { bar, more } = splitNav(items)
  const moreActive = more.some((n) => isActive(pathname, n.href, n.exact))

  return (
    <nav className="portal-nav" aria-label={t('portal.nav_aria')}>
      {bar.map((n) => {
        const on = isActive(pathname, n.href, n.exact)
        return (
          <Link key={n.href} href={n.href} className={`portal-nav-i${on ? ' on' : ''}`} aria-current={on ? 'page' : undefined}>
            <Icon name={n.icon} size={22} />
            <span>{t(n.key)}</span>
          </Link>
        )
      })}
      {more.length > 0 && (
        <div className="portal-nav-more">
          <button type="button" className={`portal-nav-i${moreActive ? ' on' : ''}`} aria-expanded={open} aria-controls="portal-nav-mas" onClick={() => setOpen((v) => !v)}>
            <Icon name="menu" size={22} />
            <span>{t('portal.nav_more')}</span>
          </button>
          {open && (
            <ul id="portal-nav-mas" className="portal-nav-sheet">
              {more.map((n) => {
                const on = isActive(pathname, n.href, n.exact)
                return (
                  <li key={n.href}>
                    <Link href={n.href} className={on ? 'on' : undefined} aria-current={on ? 'page' : undefined}>
                      <Icon name={n.icon} size={20} /> {t(n.key)}
                    </Link>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      )}
    </nav>
  )
}
