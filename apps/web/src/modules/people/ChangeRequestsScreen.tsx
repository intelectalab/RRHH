'use client'

import { Fragment, useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'
import { peopleTabs } from './peopleTabs'
import { REQUEST_TONE } from './profile/ChangeRequestPanel'
import type { ChangeRequest } from './profile/types'

const STATES = ['pending', 'approved', 'rejected', 'cancelled'] as const

/**
 * COL-4 · RN-COL-06 · RRHH revisa los cambios de datos que propusieron los colaboradores: ve el valor
 * propuesto de cada campo y aprueba (se aplica y se audita) o rechaza con motivo.
 */
export function ChangeRequestsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [status, setStatus] = useState<(typeof STATES)[number]>('pending')
  const [rows, setRows] = useState<ChangeRequest[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [rejecting, setRejecting] = useState<ChangeRequest | null>(null)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: ChangeRequest[] }>(`/profile-change-requests?status=${status}`)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [status])

  useEffect(() => { void load() }, [load])

  async function approve(r: ChangeRequest) {
    setBusy(true)
    try {
      await api.post(`/profile-change-requests/${r.id}/approve`, {})
      toast(t('ficha.request_approved_toast'))
      await load()
    } catch (e) {
      toast(formErrors(e, t).general ?? t('auth.generic_error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  async function reject() {
    if (!rejecting) return
    setBusy(true)
    setError(null)
    try {
      await api.post(`/profile-change-requests/${rejecting.id}/reject`, { reason })
      setRejecting(null)
      toast(t('ficha.request_rejected_toast'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setError(f.fields.reason ?? f.general)
    } finally {
      setBusy(false)
    }
  }

  const value = (field: string, v: string | null | undefined) => {
    if (v === null || v === undefined || v === '') return t('ficha.value_empty')
    if (field === 'marital_status') return t(`ficha.marital_${v}`)
    if (field === 'birth_date') return formatDate(locale, v)
    return v
  }

  return (
    <>
      <PageHeader title={t('ficha.requests_title')} sub={t('ficha.requests_sub')} tabs={peopleTabs(t, 'requests', can)} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="seg mb-3" role="group" aria-label={t('ficha.requests_filter')}>
          {STATES.map((s) => (
            <button key={s} type="button" className={status === s ? 'on' : undefined} aria-pressed={status === s} onClick={() => setStatus(s)}>{t(`ficha.request_${s}`)}</button>
          ))}
        </div>
        {failed && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
        {!failed && !rows && <span className="esq" style={{ width: 240 }} />}
        {!failed && rows && rows.length === 0 && <EmptyState title={t('ficha.requests_empty_title')} text={t('ficha.requests_empty_text')} />}
        {!failed && rows && rows.length > 0 && (
          <div className="ficha-solicitudes">
            {rows.map((r) => (
              <Card key={r.id} title={r.person_name ?? '—'}
                context={<>{formatDate(locale, r.created_at)} · <StatusBadge tone={REQUEST_TONE[r.status]}>{t(`ficha.request_${r.status}`)}</StatusBadge></>}
                actions={r.allowed_actions.includes('approve') && can('people.change_requests.approve') ? (
                  <>
                    <button type="button" className="btn btn-sec btn-sm" disabled={busy} onClick={() => { setReason(''); setError(null); setRejecting(r) }}>{t('ficha.reject')}</button>
                    <button type="button" className="btn btn-primary btn-sm" disabled={busy} onClick={() => void approve(r)}>{t('ficha.approve')}</button>
                  </>
                ) : undefined}>
                <dl className="ficha">
                  {r.pending_fields.map((f) => (
                    <Fragment key={f}><dt>{t(`ficha.change_${f}`)}</dt><dd>{value(f, r.changes[f as keyof ChangeRequest['changes']])}</dd></Fragment>
                  ))}
                </dl>
                {r.applied_fields.length > 0 && (
                  <p className="small text-secondary mt-2">{t('ficha.applied_now', { fields: r.applied_fields.map((f) => t(`ficha.change_${f}`)).join(', ') })}</p>
                )}
                {r.rejection_reason && <p className="small text-secondary mt-2">{t('ficha.rejected_because', { reason: r.rejection_reason })}</p>}
              </Card>
            ))}
          </div>
        )}
      </main>
      {rejecting && (
        <Modal
          title={t('ficha.reject_title')}
          onClose={() => setRejecting(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setRejecting(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-danger" disabled={busy} aria-busy={busy} onClick={() => void reject()}>{t('ficha.reject')}</button>
            </>
          )}
        >
          <label className="form-label" htmlFor="cr-reason">{t('ficha.reject_reason')}</label>
          <textarea id="cr-reason" className={`form-control${error ? ' is-invalid' : ''}`} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
          {error && <div className="invalid-feedback" role="alert">{error}</div>}
        </Modal>
      )}
      {toastNode}
    </>
  )
}
