'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Icon } from '@/ui/components/Icon'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { StatusBadge } from '@/ui/components/Status'
import { ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { STATUS_TONE, type Employment } from './employment'
import { TerminationActions, TerminationAndRelations } from './EmploymentTermination'
import { peopleTabs } from './peopleTabs'

/**
 * COL-2 · Resumen de la relación laboral recién registrada: datos laborales vigentes, contrato y sueldo
 * (este último solo si el permiso de campo lo envía), con las acciones de ME-01 que permite su estado.
 * La ficha 360 completa es COL-3.
 */
export function EmploymentScreen({ id }: { id: string }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [employment, setEmployment] = useState<Employment | null>(null)
  const [failed, setFailed] = useState(false)
  const [busy, setBusy] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      setEmployment((await api.get<{ data: Employment }>(`/employments/${id}`)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [id])

  useEffect(() => { void load() }, [load])

  async function activate() {
    setBusy(true)
    try {
      setEmployment((await api.post<{ data: Employment }>(`/employments/${id}/activate`, {})).data)
      toast(t('emp.activated'))
    } catch (e) {
      toast(formErrors(e, t).general ?? Object.values(formErrors(e, t).fields)[0] ?? t('auth.generic_error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  async function cancel() {
    setBusy(true)
    setError(null)
    try {
      await api.post(`/employments/${id}/cancel`, { reason })
      setCancelling(false)
      toast(t('emp.cancelled'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setError(f.fields.reason ?? f.general)
    } finally {
      setBusy(false)
    }
  }

  if (failed) return <main className="main" id="contenido"><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></main>
  if (!employment) return <main className="main" id="contenido" aria-busy="true"><span className="esq" style={{ width: 240 }} /></main>

  const actions = employment.allowed_actions ?? []
  const date = (v: string | null) => (v ? formatDate(locale, v) : '—')
  const row = (label: string, value: string | null | undefined) => (
    <><dt>{label}</dt><dd>{value || '—'}</dd></>
  )

  return (
    <>
      <PageHeader
        title={employment.person_name ?? t('emp.title')}
        sub={<>{employment.employee_code} · {employment.legal_entity_name} · <StatusBadge tone={STATUS_TONE[employment.status]}>{t(`emp.status_${employment.status}`)}</StatusBadge></>}
        tabs={peopleTabs(t, 'employees', can)}
        actions={(
          <>
            <Link className="btn btn-sec" href="/personas/colaboradores"><Icon name="chevronLeft" /> {t('emp.back')}</Link>
            {actions.includes('activate') && can('people.employments.activate') && (
              <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void activate()}>{t('emp.activate')}</button>
            )}
            {actions.includes('cancel') && can('people.employments.update') && (
              <button type="button" className="btn btn-sec" disabled={busy} onClick={() => { setReason(''); setError(null); setCancelling(true) }}>{t('emp.cancel')}</button>
            )}
            <TerminationActions employment={employment} onChanged={setEmployment} onToast={toast} />
          </>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {employment.status === 'pending' && (
          <Notice tone="info" title={t('emp.pending_title')}>{t('emp.pending_text', { date: date(employment.hire_date) })}</Notice>
        )}
        <div className="emp-resumen">
          <Card title={t('emp.section_job')}>
            <dl className="ficha">
              {row(t('emp.f_position'), employment.position_title ? `${employment.position_title}` : null)}
              {row(t('emp.f_job'), employment.job_profile_name)}
              {row(t('emp.f_unit'), employment.org_unit_name)}
              {row(t('emp.f_site'), employment.site_name)}
              {row(t('emp.f_cost_center'), employment.cost_center_name)}
              {row(t('emp.f_manager'), employment.manager_name)}
              {row(t('emp.f_modality'), employment.work_modality ? t(`emp.modality_${employment.work_modality}`) : null)}
            </dl>
          </Card>
          <Card title={t('emp.section_relation')}>
            <dl className="ficha">
              {row(t('emp.f_hire_date'), date(employment.hire_date))}
              {row(t('emp.f_kind'), t(`emp.kind_${employment.kind}`))}
              {row(t('emp.f_category'), t(`emp.category_${employment.worker_category}`))}
              {row(t('emp.f_regime'), employment.labor_regime_code ? t(`emp.regime_${employment.labor_regime_code}`) : null)}
              {row(t('emp.f_contract_end'), employment.contract_end_date ? date(employment.contract_end_date) : t('emp.no_end'))}
              {row(t('emp.f_document'), employment.document_number_masked)}
              {row(t('emp.f_work_email'), employment.work_email)}
              {row(t('emp.f_work_phone'), [employment.work_phone, employment.work_phone_ext].filter(Boolean).join(' · '))}
              {employment.base_salary && row(t('emp.f_salary'), `${employment.base_salary.currency} ${formatNumber(locale, employment.base_salary.amount)}`)}
            </dl>
          </Card>
        </div>
        <TerminationAndRelations employment={employment} />
      </main>
      {cancelling && (
        <Modal
          title={t('emp.cancel_title')}
          onClose={() => setCancelling(false)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setCancelling(false)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-danger" disabled={busy} aria-busy={busy} onClick={() => void cancel()}>{t('emp.cancel_confirm')}</button>
            </>
          )}
        >
          <p>{t('emp.cancel_text')}</p>
          <label className="form-label" htmlFor="emp-cancel-reason">{t('emp.cancel_reason')}</label>
          <textarea id="emp-cancel-reason" className={`form-control${error ? ' is-invalid' : ''}`} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
          {error && <div className="invalid-feedback" role="alert">{error}</div>}
        </Modal>
      )}
      {toastNode}
    </>
  )
}
