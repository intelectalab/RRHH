'use client'

import { useCallback, useEffect, useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { FileViewer } from '@/modules/selfservice/FileViewer'
import { certificateTone } from '@/modules/selfservice/portal'
import type { CertificateRequest, CertificateStatus } from '@/modules/selfservice/types'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { PageHeader } from '@/ui/components/PageHeader'
import { SkeletonBlock } from '@/ui/components/Skeleton'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'

const FILTERS: Array<CertificateStatus | 'all'> = ['requested', 'in_progress', 'issued', 'rejected', 'all']

/**
 * ESS-4 · Constancias pedidas desde el portal: RRHH emite o rechaza las que esperan (sin flujo de aprobación);
 * las que están en aprobación se deciden en el centro de aprobaciones. El alcance lo aplica el servidor.
 */
export function CertificatesAdminScreen() {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const [filter, setFilter] = useState<CertificateStatus | 'all'>('requested')
  const [rows, setRows] = useState<CertificateRequest[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const [rejecting, setRejecting] = useState<CertificateRequest | null>(null)
  const [reason, setReason] = useState('')
  const [viewing, setViewing] = useState<CertificateRequest | null>(null)

  const load = useCallback(async () => {
    try {
      const q = filter === 'all' ? '' : `?filter[status]=${filter}`
      setRows((await api.get<{ data: CertificateRequest[] }>(`/certificate-requests${q}`)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [filter])

  useEffect(() => { void load() }, [load])

  async function act(r: CertificateRequest, action: 'issue' | 'reject') {
    setBusy(r.id)
    try {
      await api.post(`/certificate-requests/${r.id}/${action}`, action === 'reject' ? { reason } : {})
      toast(t(action === 'issue' ? 'certificates.issued_ok' : 'certificates.rejected_ok'))
      setRejecting(null)
      setReason('')
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('common.error'), 'error')
    } finally {
      setBusy(null)
    }
  }

  return (
    <>
      <PageHeader title={t('certificates.admin_title')} sub={t('certificates.admin_sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="chips mb-3" role="group" aria-label={t('certificates.filter')}>
          {FILTERS.map((f) => (
            <button key={f} type="button" className={`chip${filter === f ? ' on' : ''}`} aria-pressed={filter === f} onClick={() => setFilter(f)}>
              {f === 'all' ? t('certificates.all') : t(`certificates.status_${f}`)}
            </button>
          ))}
        </div>
        {failed ? <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />
          : !rows ? <SkeletonBlock height={240} />
            : rows.length === 0 ? <EmptyState title={t('certificates.admin_empty_title')} text={t('certificates.admin_empty_text')} />
              : (
                <Card flush>
                  <table className="table">
                    <thead><tr>
                      <th>{t('certificates.employee')}</th><th>{t('certificates.kind')}</th><th>{t('certificates.requested_on')}</th>
                      <th>{t('certificates.state')}</th><th><span className="visually-hidden">{t('certificates.actions')}</span></th>
                    </tr></thead>
                    <tbody>
                      {rows.map((r) => (
                        <tr key={r.id}>
                          <td><b>{r.person_name ?? '—'}</b><span className="sub2"> {r.employee_code}</span></td>
                          <td>{r.kind_name}{r.purpose && <span className="sub2"> · {r.purpose}</span>}</td>
                          <td>{formatDate(locale, r.created_at)}</td>
                          <td><StatusBadge tone={certificateTone(r.status)}>{t(`certificates.status_${r.status}`)}</StatusBadge>{r.auto_issued && <span className="sub2"> {t('certificates.instant')}</span>}</td>
                          <td className="text-end">
                            {r.allowed_actions.includes('issue') && <button type="button" className="btn btn-primary btn-sm" disabled={busy === r.id} onClick={() => void act(r, 'issue')}>{t('certificates.issue')}</button>}{' '}
                            {r.allowed_actions.includes('reject') && <button type="button" className="btn btn-sec btn-sm" disabled={busy === r.id} onClick={() => { setReason(''); setRejecting(r) }}>{t('certificates.reject')}</button>}
                            {r.status === 'issued' && <button type="button" className="btn btn-ter btn-sm" onClick={() => setViewing(r)}>{t('portal.open')}</button>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </Card>
              )}
      </main>
      {rejecting && (
        <Modal title={t('certificates.reject')} onClose={() => setRejecting(null)} tone="danger" footer={
          <>
            <button type="button" className="btn btn-sec" onClick={() => setRejecting(null)}>{t('common.cancel')}</button>
            <button type="button" className="btn btn-danger" disabled={!reason.trim() || busy !== null} onClick={() => void act(rejecting, 'reject')}>{t('certificates.reject')}</button>
          </>
        }>
          <label className="form-label" htmlFor="cert-reason">{t('certificates.reason')}</label>
          <textarea id="cert-reason" className="form-control" rows={3} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
        </Modal>
      )}
      {viewing && <FileViewer url={`/certificate-requests/${viewing.id}/file?inline=true`} title={viewing.kind_name} onClose={() => setViewing(null)} />}
      {toastNode}
    </>
  )
}
