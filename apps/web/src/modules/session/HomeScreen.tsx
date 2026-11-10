'use client'

import { useT } from '@/lib/useT'
import { PageHeader } from '@/ui/components/PageHeader'
import { useSession } from './SessionProvider'

export function HomeScreen() {
  const { t } = useT()
  const { session } = useSession()

  return (
    <>
      <PageHeader title={t('home.title', { name: session.user.display_name })} sub={session.tenant?.name} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="grid-2">
          <section className="card">
            <div className="card-body">
              <p className="text-secondary" style={{ margin: 0 }}>{t('home.intro')}</p>
            </div>
          </section>
          <section className="bloque-plano">
            <div className="cab"><h2>{t('home.your_roles')}</h2></div>
            <p className="mb-3">{session.roles.length > 0 ? session.roles.map((r) => r.name).join(', ') : t('home.none')}</p>
            <div className="cab"><h2>{t('home.your_modules')}</h2></div>
            <p>{session.modules.length > 0 ? session.modules.join(', ') : t('home.none')}</p>
          </section>
        </div>
      </main>
    </>
  )
}
