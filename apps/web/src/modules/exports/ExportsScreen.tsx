'use client'

import { useCallback, useEffect, useState } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useDateTime } from '@/modules/session/useDateTime'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { canDownload, exportTone, hasPending, saveBlob } from './exports'

type ExportJob = Schema<'ExportJob'>

/**
 * UI-11 · Mis exportaciones: las propias (o las de la organización para auditoría), con su estado. Las que
 * están en cola se refrescan solas cada pocos segundos; al terminar llega además un aviso con enlace aquí.
 */
export function ExportsScreen() {
  const { t } = useT()
  const dateTime = useDateTime()
  const { toast, node } = useToast()
  const [rows, setRows] = useState<ExportJob[] | null>(null)
  const [error, setError] = useState(false)

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: ExportJob[] }>('/exports?per_page=50')).data)
      setError(false)
    } catch {
      setError(true)
    }
  }, [])

  useEffect(() => { void load() }, [load])
  useEffect(() => {
    if (!rows || !hasPending(rows)) return
    const id = window.setTimeout(() => void load(), 4000)
    return () => window.clearTimeout(id)
  }, [rows, load])

  async function download(job: ExportJob) {
    try {
      const { blob, filename } = await api.download(`/exports/${job.id}/download`)
      saveBlob(blob, filename)
      void load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('exports.error'), 'error')
    }
  }

  return (
    <>
      <PageHeader title={t('exports.title')} sub={t('exports.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {node}
        <section className="card">
          {error && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
          {!error && rows === null && <div style={{ padding: 16 }} aria-busy="true"><span className="esq" style={{ width: '60%' }} /></div>}
          {rows && rows.length === 0 && <EmptyState title={t('exports.empty_title')} text={t('exports.empty_text')} />}
          {rows && rows.length > 0 && (
            <div className="tabla-wrap">
              <table className="table">
                <thead>
                  <tr><th>{t('exports.col_resource')}</th><th>{t('exports.col_format')}</th><th>{t('exports.col_rows')}</th><th>{t('exports.col_created')}</th><th>{t('exports.col_status')}</th><th /></tr>
                </thead>
                <tbody aria-live="polite">
                  {rows.map((j) => (
                    <tr key={j.id}>
                      <td data-label={t('exports.col_resource')}>{t(`exports.resource_${j.resource}`)}{j.contains_sensitive ? <span className="tag ml-1">{t('exports.sensitive')}</span> : null}</td>
                      <td data-label={t('exports.col_format')}>{t(`exports.format_${j.format}`)}</td>
                      <td data-label={t('exports.col_rows')}>{j.row_count ?? '—'}</td>
                      <td data-label={t('exports.col_created')}>{j.created_at ? dateTime(j.created_at) : '—'}</td>
                      <td data-label={t('exports.col_status')}>
                        <span className={`st ${exportTone(j.status, j.expires_at)}`}>{t(`exports.status_${canDownload(j.status, j.expires_at) || j.status !== 'completed' ? j.status : 'expired'}`)}</span>
                      </td>
                      <td>
                        {canDownload(j.status, j.expires_at) && (
                          <button type="button" className="btn btn-ter btn-sm" onClick={() => void download(j)}>
                            <Icon name="download" /> {t('exports.download')}
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </main>
    </>
  )
}
