'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { ErrorState } from '@/ui/components/States'
import { UsageTable, useMetricLabel, type UsageRow } from './UsageTable'

type Usage = { metrics: UsageRow[]; alert_percent: number }

/**
 * SAS-6 · Configuración → Consumo del plan: colaboradores, administradores, empresas, almacenamiento y
 * llamadas a la API frente a los límites del plan, con aviso antes de llegar al tope.
 */
export function UsageScreen() {
  const { t } = useT()
  const { can } = useSession()
  const label = useMetricLabel()
  const [usage, setUsage] = useState<Usage | null>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(() => {
    setFailed(false)
    api.get<{ data: Usage }>('/usage').then((r) => setUsage(r.data)).catch(() => setFailed(true))
  }, [])
  useEffect(load, [load])

  const blocked = usage?.metrics.filter((m) => m.status === 'reached' || m.status === 'over') ?? []
  const near = usage?.metrics.filter((m) => m.status === 'warning') ?? []

  return (
    <>
      <PageHeader title={t('usage.title')} sub={t('usage.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <p className="mb-3"><Link href="/configuracion">{t('usage.back')}</Link></p>
        {failed && <ErrorState text={t('usage.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
        {!failed && usage === null && <span className="esq" style={{ width: 320 }} />}
        {usage && (
          <>
            {blocked.length > 0 && (
              <Notice tone="error" title={t('usage.blocked_title')}>
                {t('usage.blocked_text', { metrics: blocked.map((m) => label(m.metric)).join(', ') })}
              </Notice>
            )}
            {blocked.length === 0 && near.length > 0 && (
              <Notice tone="warn" title={t('usage.near_title')}>
                {t('usage.near_text', { metrics: near.map((m) => label(m.metric)).join(', '), percent: usage.alert_percent })}
              </Notice>
            )}
            <section className="card">
              <div className="card-header"><h2>{t('usage.section')}</h2></div>
              <UsageTable rows={usage.metrics} />
              <div className="card-body small text-secondary">
                {t('usage.help', { percent: usage.alert_percent })}
                {can('settings.parameters.view') && <> <Link href="/configuracion/parametros/limites">{t('usage.alert_setting')}</Link></>}
              </div>
            </section>
          </>
        )}
      </main>
    </>
  )
}
