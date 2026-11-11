'use client'

import { useCallback, useEffect, useState } from 'react'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useConsoleTabs } from './ConsoleShell'
import { consoleApi, type ConsoleMetrics } from './consoleApi'

/** SAS-1 · Tablero de la consola: cifras agregadas (sin datos laborales de las organizaciones). */
export function ConsoleDashboard() {
  const { t, locale } = useT()
  const tabs = useConsoleTabs()
  const [data, setData] = useState<ConsoleMetrics | null>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(() => {
    setFailed(false)
    consoleApi.get<{ data: ConsoleMetrics }>('/metrics').then((r) => setData(r.data)).catch(() => setFailed(true))
  }, [])
  useEffect(load, [load])

  const nf = new Intl.NumberFormat(locale)
  const cards = data ? [
    { key: 'tenants_total', value: data.tenants.total },
    { key: 'tenants_new', value: data.tenants.new_last_30_days },
    { key: 'users_active', value: data.users.active },
    { key: 'console_users', value: data.console_users },
  ] : []
  const statuses = data ? Object.entries(data.tenants.by_status).sort((a, b) => b[1] - a[1]) : []

  return (
    <>
      <PageHeader title={t('console.dashboard_title')} sub={t('console.dashboard_sub')} tabs={tabs} />
      <main className="main" id="contenido" tabIndex={-1}>
        {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
        {!failed && !data && <span className="esq" style={{ width: 320 }} />}
        {data && (
          <>
            <div className="consola-cifras">
              {cards.map((c) => (
                <section key={c.key} className="card consola-cifra">
                  <div className="card-body">
                    <span className="small text-secondary">{t(`console.${c.key}`)}</span>
                    <b>{nf.format(c.value)}</b>
                  </div>
                </section>
              ))}
            </div>
            <section className="card">
              <div className="card-header">
                <h2>{t('console.by_status')}</h2>
                <span className="ctx">{t('console.generated_at', { time: formatDateTime(locale, data.generated_at) })}</span>
              </div>
              <div className="card-body">
                {statuses.length === 0 ? (
                  <EmptyState title={t('console.no_tenants')} text={t('console.dashboard_sub')} />
                ) : (
                  <table className="table">
                    <tbody>
                      {statuses.map(([status, n]) => (
                        <tr key={status}>
                          <td>{t(`console.status.${status}`)}</td>
                          <td className="text-end">{nf.format(n)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </div>
            </section>
          </>
        )}
      </main>
    </>
  )
}
