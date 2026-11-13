'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { StatusBadge, type StatusTone } from '@/ui/components/Status'
import { CHANGE_FIELDS, MARITAL, type ChangeField, type ChangeRequest } from './types'

export const REQUEST_TONE: Record<ChangeRequest['status'], StatusTone> = { pending: 'wa', approved: 'ok', rejected: 'er', cancelled: 'gr' }

/**
 * RN-COL-06 · El colaborador propone cambios de sus propios datos. Los de bajo riesgo se aplican en el acto;
 * los demás esperan a RRHH. Ve el estado de sus solicitudes y puede cancelar la pendiente.
 */
export function ChangeRequestPanel({ personId, toast, onChanged }: { personId: string; toast: (text: string, kind?: 'ok' | 'error') => void; onChanged: () => Promise<void> }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const [requests, setRequests] = useState<ChangeRequest[]>([])
  const [open, setOpen] = useState(false)
  const [values, setValues] = useState<Partial<Record<ChangeField, string>>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    if (!can('people.change_requests.view')) return
    try {
      setRequests((await api.get<{ data: ChangeRequest[] }>(`/profile-change-requests?person_id=${personId}`)).data)
    } catch {
      setRequests([])
    }
  }, [personId, can])

  useEffect(() => { void load() }, [load])

  if (!can('people.change_requests.create')) return null

  async function submit(e: FormEvent) {
    e.preventDefault()
    const changes = Object.fromEntries(Object.entries(values).filter(([, v]) => v !== undefined && v.trim() !== '').map(([k, v]) => [k, (v ?? '').trim()]))
    setBusy(true)
    setErrors({})
    setGeneral(null)
    try {
      const r = await api.post<{ data: ChangeRequest }>('/profile-change-requests', { changes })
      setOpen(false)
      setValues({})
      toast(r.data.status === 'pending' ? t('ficha.request_sent') : t('ficha.request_applied'))
      await load()
      await onChanged()
    } catch (err) {
      const f = formErrors(err, t)
      setErrors(Object.fromEntries(Object.entries(f.fields).map(([k, v]) => [k.replace(/^changes\./, ''), v])))
      setGeneral(f.general ?? f.fields.changes ?? null)
    } finally {
      setBusy(false)
    }
  }

  async function cancel(id: string) {
    try {
      await api.post(`/profile-change-requests/${id}/cancel`, {})
      toast(t('ficha.request_cancelled_toast'))
      await load()
    } catch (err) {
      toast(formErrors(err, t).general ?? t('auth.generic_error'), 'error')
    }
  }

  const field = (key: ChangeField) => {
    const type = key === 'birth_date' ? 'date' : key === 'personal_email' ? 'email' : key === 'mobile_phone' ? 'tel' : 'text'
    return (
      <div className="mb-3" key={key}>
        <label className="form-label" htmlFor={`cr-${key}`}>{t(`ficha.change_${key}`)}</label>
        {key === 'marital_status' ? (
          <select id={`cr-${key}`} className="form-select" value={values[key] ?? ''} onChange={(e) => setValues((v) => ({ ...v, [key]: e.target.value }))}>
            <option value="">{t('ficha.no_change')}</option>
            {MARITAL.map((m) => <option key={m} value={m}>{t(`ficha.marital_${m}`)}</option>)}
          </select>
        ) : (
          <input id={`cr-${key}`} type={type} className={`form-control${errors[key] ? ' is-invalid' : ''}`} value={values[key] ?? ''}
            placeholder={t('ficha.no_change')} onChange={(e) => setValues((v) => ({ ...v, [key]: e.target.value }))} />
        )}
        {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
      </div>
    )
  }

  return (
    <Card title={t('ficha.my_requests')} context={t('ficha.my_requests_help')}
      actions={<button type="button" className="btn btn-primary btn-sm" onClick={() => { setErrors({}); setGeneral(null); setOpen(true) }}>{t('ficha.propose_change')}</button>}>
      {requests.length === 0 ? <p className="text-secondary">{t('ficha.no_requests')}</p> : (
        <ul className="ficha-lista">
          {requests.map((r) => (
            <li key={r.id}>
              <div>
                <b>{[...r.applied_fields, ...r.pending_fields].map((f) => t(`ficha.change_${f}`)).join(', ')}</b>
                <span className="sub2">{formatDate(locale, r.created_at)} · <StatusBadge tone={REQUEST_TONE[r.status]}>{t(`ficha.request_${r.status}`)}</StatusBadge></span>
                {r.rejection_reason && <span className="sub2">{t('ficha.rejected_because', { reason: r.rejection_reason })}</span>}
              </div>
              {r.allowed_actions.includes('cancel') && (
                <div className="ficha-acc"><button type="button" className="btn btn-ter btn-sm" onClick={() => void cancel(r.id)}>{t('ficha.cancel_request')}</button></div>
              )}
            </li>
          ))}
        </ul>
      )}
      {open && (
        <Modal
          title={t('ficha.propose_change')}
          onClose={() => setOpen(false)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setOpen(false)}>{t('org.cancel')}</button>
              <button type="submit" form="form-cambio" className="btn btn-primary" disabled={busy} aria-busy={busy}>{t('ficha.send_request')}</button>
            </>
          )}
        >
          <form id="form-cambio" onSubmit={(e) => void submit(e)} noValidate>
            <p className="small text-secondary">{t('ficha.propose_help')}</p>
            {general && <Notice tone="error">{general}</Notice>}
            {CHANGE_FIELDS.map(field)}
          </form>
        </Modal>
      )}
    </Card>
  )
}
