'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { isActive, portalNav } from './portal'

/**
 * ESS-1 · Barra inferior del portal del colaborador (mobile-first): los destinos frecuentes a un toque, con
 * blancos de 44 px o más. En pantallas anchas se oculta y queda el menú lateral.
 */
export function PortalNav() {
  const { t } = useT()
  const { session, can } = useSession()
  const pathname = usePathname() ?? ''
  const items = portalNav([...session.modules, ...(session.modules_read_only ?? [])], can)
  if (items.length < 2) return null

  return (
    <nav className="portal-nav" aria-label={t('portal.nav_aria')}>
      {items.map((n) => {
        const on = isActive(pathname, n.href, n.exact)
        return (
          <Link key={n.href} href={n.href} className={`portal-nav-i${on ? ' on' : ''}`} aria-current={on ? 'page' : undefined}>
            <Icon name={n.icon} size={22} />
            <span>{t(n.key)}</span>
          </Link>
        )
      })}
    </nav>
  )
}
