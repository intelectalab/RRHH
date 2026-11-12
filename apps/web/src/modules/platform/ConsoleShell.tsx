'use client'

import { usePathname, useRouter } from 'next/navigation'
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { useT } from '@/lib/useT'
import { BrandMark } from '@/ui/components/BrandMark'
import { Icon } from '@/ui/components/Icon'
import type { Tab } from '@/ui/components/PageHeader'
import { ShellTitleProvider, useShellTitle } from '@/modules/session/ShellTitle'
import { consoleApi, type ConsoleMe } from './consoleApi'

const Ctx = createContext<ConsoleMe | null>(null)

/** Usuario de la consola con el segundo paso ya superado. */
export function useConsole(): ConsoleMe {
  const me = useContext(Ctx)
  if (!me) throw new Error('useConsole fuera de ConsoleShell')
  return me
}

/** Pestañas de la consola según los permisos del rol interno (el servidor también los valida). */
export function useConsoleTabs(): Tab[] {
  const { t } = useT()
  const me = useConsole()
  const pathname = usePathname()
  const tabs: Array<Tab & { perm: string }> = [
    { href: '/plataforma', label: t('console.tab_dashboard'), active: pathname === '/plataforma', perm: 'platform.metrics.view' },
    { href: '/plataforma/organizaciones', label: t('console.tab_tenants'), active: pathname.startsWith('/plataforma/organizaciones'), perm: 'platform.tenants.view' },
    { href: '/plataforma/planes', label: t('console.tab_plans'), active: pathname.startsWith('/plataforma/planes'), perm: 'platform.plans.view' },
    { href: '/plataforma/suscripciones', label: t('console.tab_subscriptions'), active: pathname.startsWith('/plataforma/suscripciones'), perm: 'platform.subscriptions.view' },
    { href: '/plataforma/funciones', label: t('console.tab_flags'), active: pathname.startsWith('/plataforma/funciones'), perm: 'platform.feature_flags.view' },
    { href: '/plataforma/auditoria', label: t('console.tab_audit'), active: pathname.startsWith('/plataforma/auditoria'), perm: 'platform.audit.view' },
  ]
  return tabs.filter((tab) => me.permissions.includes(tab.perm)).map(({ perm: _perm, ...tab }) => tab)
}

function Frame({ me, children }: { me: ConsoleMe; children: ReactNode }) {
  const { t } = useT()
  const router = useRouter()
  const title = useShellTitle()

  async function logout() {
    try { await consoleApi.post('/auth/logout', {}) } finally { router.replace('/plataforma/ingresar') }
  }

  return (
    <div className="consola">
      <a href="#contenido" className="saltar">{t('shell.skip')}</a>
      <header className="top">
        <span className="consola-logo logo" aria-hidden="true"><BrandMark /></span>
        <div className="top-t">
          <span className="crumb">{[t('console.crumb'), me.user.role_label].join(' · ')}</span>
          {title && <h1 className="ttl">{title}</h1>}
        </div>
        <span className="consola-user small text-secondary">{me.user.full_name}</span>
        <button type="button" className="btn btn-sec btn-sm" onClick={() => void logout()}>
          <Icon name="signOut" /> {t('console.logout')}
        </button>
      </header>
      <div className="app-content">{children}</div>
    </div>
  )
}

/**
 * SAS-1 · Armazón de la consola (docs/10 §3.12): el mismo de la aplicación sin barra lateral;
 * cabecera de una línea con miga, título y Salir, y pestañas con desplazamiento horizontal.
 */
export function ConsoleShell({ children }: { children: ReactNode }) {
  const router = useRouter()
  const [me, setMe] = useState<ConsoleMe | null>(null)

  useEffect(() => {
    consoleApi.get<{ data: ConsoleMe }>('/me')
      .then((r) => (r.data.mfa_step === 'ok' ? setMe(r.data) : router.replace('/plataforma/ingresar')))
      .catch(() => router.replace('/plataforma/ingresar'))
  }, [router])

  if (!me) return <div className="main" aria-busy="true"><span className="esq" style={{ width: 240 }} /></div>

  return (
    <Ctx.Provider value={me}>
      <ShellTitleProvider>
        <Frame me={me}>{children}</Frame>
      </ShellTitleProvider>
    </Ctx.Provider>
  )
}
