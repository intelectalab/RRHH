'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { StatusBadge } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'
import { REQUEST_TONE, type DocumentRequestRow } from './templates'

/**
 * DOC-3 · Portal del colaborador: los documentos que le piden (pendientes, rechazados con su observación y los que
 * están en revisión) y la entrega desde aquí. El servidor solo devuelve las solicitudes propias (alcance «propio»).
 */
export function MyDocumentRequests({ employmentId }: { employmentId?: string | null }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<DocumentRequestRow[] | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  const load = useCallback(async () => {
    const params = new URLSearchParams({ 'filter[status]': 'requested,rejected,submitted', per_page: '20' })
    if (employmentId) params.set('filter[employment_id]', employmentId)
    try {
      setRows((await api.get<{ data: DocumentRequestRow[] }>(`/document-requests?${params.toString()}`)).data)
    } catch {
      setRows([])
    }
  }, [employmentId])
  useEffect(() => { if (can('documents.documents.view')) void load() }, [load, can])

  async function upload(r: DocumentRequestRow, file: File | undefined) {
    if (!file) return
    setBusy(r.id)
    try {
      const form = new FormData()
      form.append('file', file)
      await api.postForm(`/document-requests/${r.id}/submit`, form)
      toast(t('mydreq.sent'))
      await load()
    } catch (e) {
      toast(formErrors(e, t).general ?? Object.values(formErrors(e, t).fields)[0] ?? t('auth.generic_error'), 'error')
    } finally {
      setBusy(null)
    }
  }

  if (!rows || rows.length === 0) return null

  return (
    <>
      <Card title={t('mydreq.title')}>
        <ul className="portal-list">
          {rows.map((r) => (
            <li key={r.id}>
              <div className="sec-fila">
                <div>
                  <b>{r.title}</b>
                  <span className="sub2">{r.due_on ? t('mydreq.due', { date: formatDate(locale, r.due_on) }) : t('mydreq.no_due')}</span>
                  {r.instructions && <span className="small text-secondary d-block">{r.instructions}</span>}
                  {r.status === 'rejected' && r.rejection_reason && <span className="small text-danger d-block">{t('mydreq.observed', { reason: r.rejection_reason })}</span>}
                </div>
                <div className="sec-acciones">
                  <StatusBadge tone={r.overdue ? 'er' : REQUEST_TONE[r.status]}>{r.overdue ? t('dreq.overdue') : t(`mydreq.status_${r.status}`)}</StatusBadge>
                  {r.allowed_actions.includes('submit') && (
                    <label className="btn btn-sec btn-sm mb-0" aria-busy={busy === r.id}>
                      {t('mydreq.upload')}
                      <input type="file" className="visually-hidden" accept="application/pdf,image/png,image/jpeg" disabled={busy !== null}
                        onChange={(e) => void upload(r, e.target.files?.[0])} />
                    </label>
                  )}
                </div>
              </div>
            </li>
          ))}
        </ul>
      </Card>
      {toastNode}
    </>
  )
}
