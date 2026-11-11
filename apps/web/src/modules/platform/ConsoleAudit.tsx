'use client'

import { useCallback, useEffect, useState } from 'react'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useConsoleTabs } from './ConsoleShell'
import { consoleApi, type ConsoleAuditEvent } from './consoleApi'

const PER_PAGE = 25

/** Etiqueta de la acción; una acción aún sin traducir se muestra con su código. */
export function actionLabel(t: (k: string) => string, action: string): string {
  const key = `console.act.${action.replace(/^console\./, '')}`
  const label = t(key)
  return label === key ? action : label
}

/** SAS-1 · Auditoría propia de la consola (solo inserción). */
export function ConsoleAudit() {
  const { t, locale } = useT()
  const tabs = useConsoleTabs()
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<ConsoleAuditEvent[] | null>(null)
  const [total, setTotal] = useState(0)
  const [failed, setFailed] = useState(false)

  const load = useCallback(() => {
    setFailed(false)
    consoleApi.get<{ data: ConsoleAuditEvent[]; meta: { total: number } }>(`/audit-events?page=${page}&per_page=${PER_PAGE}`)
      .then((r) => { setRows(r.data); setTotal(r.meta.total) })
      .catch(() => setFailed(true))
  }, [page])
  useEffect(load, [load])

  const pages = Math.max(1, Math.ceil(total / PER_PAGE))
  const detail = (e: ConsoleAuditEvent) => {
    const reason = e.after?.reason
    return typeof reason === 'string' ? reason : ''
  }

  return (
    <>
      <PageHeader title={t('console.audit_title')} sub={t('console.audit_sub')} tabs={tabs} />
      <main className="main" id="contenido" tabIndex={-1}>
        {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
        {!failed && !rows && <span className="esq" style={{ width: 320 }} />}
        {rows && rows.length === 0 && <EmptyState title={t('console.audit_empty_title')} text={t('console.audit_empty')} />}
        {rows && rows.length > 0 && (
          <section className="card">
            <div className="tabla-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">{t('console.audit_when')}</th>
                    <th scope="col">{t('console.audit_who')}</th>
                    <th scope="col">{t('console.audit_action')}</th>
                    <th scope="col">{t('console.audit_detail')}</th>
                    <th scope="col">{t('console.audit_ip')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((e) => (
                    <tr key={e.id}>
                      <td>{formatDateTime(locale, e.occurred_at)}</td>
                      <td>{e.actor ? e.actor.name : <span className="text-secondary">{t('console.audit_system')}</span>}</td>
                      <td>{actionLabel(t, e.action)}</td>
                      <td className="text-secondary">{detail(e)}</td>
                      <td className="text-secondary">{e.ip ?? ''}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="card-body fila-entre">
              <span className="small text-secondary">{t('console.page', { page, pages })}</span>
              <div>
                <button type="button" className="btn btn-sec btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>{t('console.prev')}</button>{' '}
                <button type="button" className="btn btn-sec btn-sm" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>{t('console.next')}</button>
              </div>
            </div>
          </section>
        )}
      </main>
    </>
  )
}
