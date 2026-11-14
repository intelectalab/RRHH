import type { ReactNode } from 'react'
import { AppShell } from '@/modules/session/AppShell'
import { PortalNav } from '@/modules/selfservice/PortalNav'

/** ESS-1 · Portal del colaborador: el mismo marco de la aplicación con la barra inferior para móvil. */
export default function PortalLayout({ children }: { children: ReactNode }) {
  return (
    <AppShell>
      <div className="portal">{children}</div>
      <PortalNav />
    </AppShell>
  )
}
