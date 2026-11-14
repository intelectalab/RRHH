'use client'

import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { HomeBoard } from '@/modules/home/HomeBoard'
import { HOME_VIEW_KEY, pickView, type HomeView } from '@/modules/home/homeView'
import { PageHeader } from '@/ui/components/PageHeader'
import { useSession } from './SessionProvider'

function remembered(): string | null {
  try {
    return window.localStorage.getItem(HOME_VIEW_KEY)
  } catch {
    return null
  }
}

/**
 * Inicio (/) según el rol (COL-12 · MSS-1): tablero de RRHH, tablero del manager o el inicio del
 * colaborador. Quien tiene más de una vista (un manager también es colaborador) puede alternarlas.
 */
export function HomeScreen() {
  const { t } = useT()
  const { session } = useSession()
  const [views, setViews] = useState<HomeView[]>([])
  const [view, setView] = useState<HomeView | null>(null)

  useEffect(() => {
    let alive = true
    api.get<{ data: { views: string[]; default: string } }>('/home')
      .then((res) => {
        if (!alive) return
        const chosen = pickView(res.data.views, res.data.default, remembered())
        setViews(res.data.views.filter((v): v is HomeView => v === 'hr' || v === 'manager' || v === 'employee'))
        setView(chosen)
      })
      .catch(() => {
        if (alive) setView('employee')
      })
    return () => {
      alive = false
    }
  }, [])

  const choose = (v: HomeView) => {
    setView(v)
    try {
      window.localStorage.setItem(HOME_VIEW_KEY, v)
    } catch {
      // Recordar la vista es una comodidad: sin almacenamiento se usa la vista por defecto.
    }
  }

  return (
    <>
      <PageHeader
        title={t('home.title', { name: session.user.display_name })}
        sub={session.tenant?.name}
        actions={views.length > 1 ? (
          <div className="seg inicio-vistas" role="group" aria-label={t('home_board.view_label')}>
            {views.map((v) => (
              <button key={v} type="button" className={view === v ? 'on' : undefined} aria-pressed={view === v} onClick={() => choose(v)}>
                {t(`home_board.view_${v}`)}
              </button>
            ))}
          </div>
        ) : undefined}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {(view === 'hr' || view === 'manager') && <HomeBoard audience={view} />}
        {view === 'employee' && (
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
        )}
      </main>
    </>
  )
}
