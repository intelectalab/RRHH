'use client'

import { FieldHelp } from '@/modules/help/HelpCenter'
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useDateTime } from '@/modules/session/useDateTime'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { SidePanel } from '@/ui/components/SidePanel'
import { VACATION_TONE, type VacationOverlaps, type VacationRequest, type VacationSimulation } from './vacationRequests'

type Toast = (text: string, kind?: 'ok' | 'error') => void
type EmploymentOption = { id: string; person_name?: string | null; employee_code: string }

export function VacationDates({ request }: { request: Pick<VacationRequest, 'starts_on' | 'ends_on'> }) {
  const { t, locale } = useT()
  if (request.starts_on === request.ends_on) return <>{formatDate(locale, request.starts_on)}</>
  return <>{t('vreq.range', { from: formatDate(locale, request.starts_on), to: formatDate(locale, request.ends_on) })}</>
}

/**
 * VAC-3 · Registrar una solicitud de vacaciones en borrador. El servidor cuenta los días según la política
 * (calendario o hábiles con los feriados de la sede), revisa el saldo, la anticipación, los solapes y la
 * cobertura del equipo mientras se completa el formulario.
 */
export function VacationRequestFormPanel({ employmentId, onClose, onSaved }: { employmentId?: string; onClose: () => void; onSaved: (id: string) => void }) {
  const { t } = useT()
  const { can } = useSession()
  const [employments, setEmployments] = useState<EmploymentOption[]>([])
  const [form, setForm] = useState({ employment_id: employmentId ?? '', starts_on: '', ends_on: '', notes: '', exception_reason: '' })
  const [simulation, setSimulation] = useState<VacationSimulation | null>(null)
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const hr = can('time.vacation_requests.validate')

  useEffect(() => {
    if (employmentId) return
    void api.get<{ data: EmploymentOption[] }>('/employments?filter[status]=active&sort=person_name&per_page=100')
      .then((r) => {
        setEmployments(r.data)
        if (r.data.length === 1) setForm((f) => ({ ...f, employment_id: r.data[0]?.id ?? '' }))
      })
      .catch(() => setEmployments([]))
  }, [employmentId])

  const body = () => ({
    employment_id: form.employment_id, starts_on: form.starts_on, ends_on: form.ends_on || form.starts_on,
    notes: form.notes.trim() || null, exception_reason: form.exception_reason.trim() || null,
  })

  // Simulación en el servidor (regla 11): se repite al cambiar la persona o las fechas.
  const seq = useRef(0)
  useEffect(() => {
    if (!form.employment_id || !form.starts_on) {
      setSimulation(null)
      return
    }
    const n = ++seq.current
    const timer = window.setTimeout(() => {
      api.post<{ data: VacationSimulation }>('/vacation-requests/simulate', body())
        .then((r) => { if (n === seq.current) setSimulation(r.data) })
        .catch(() => { if (n === seq.current) setSimulation(null) })
    }, 300)
    return () => window.clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form.employment_id, form.starts_on, form.ends_on, form.exception_reason])

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    try {
      const res = await api.post<{ data: VacationRequest }>('/vacation-requests', body())
      onSaved(res.data.id)
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      setError(general)
    } finally {
      setBusy(false)
    }
  }

  const field = (key: string, label: string, input: ReactNode, help?: string) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`vr-${key}`}>{label}</label>
      {key === 'starts_on' && <FieldHelp field="dates" />}
      {input}
      {help && <p className="small text-secondary">{help}</p>}
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )
  const cls = (key: string, base = 'form-control') => `${base}${errors[key] ? ' is-invalid' : ''}`

  return (
    <SidePanel
      title={t('vreq.form_new')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('vreq.close')}</button>
        <button type="submit" form="vacaciones" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('vreq.save_draft')}</button>
      </>}
    >
      <form id="vacaciones" onSubmit={submit} noValidate>
        {!employmentId && field('employment_id', t('vreq.employee'), (
          <select id="vr-employment_id" className={cls('employment_id', 'form-select')} value={form.employment_id} onChange={set('employment_id')}>
            <option value="">{t('vreq.choose')}</option>
            {employments.map((m) => <option key={m.id} value={m.id}>{m.person_name ?? m.employee_code} · {m.employee_code}</option>)}
          </select>
        ))}
        <div className="row g-2">
          <div className="col-6">{field('starts_on', t('vreq.starts_on'), <input id="vr-starts_on" type="date" className={cls('starts_on')} value={form.starts_on} onChange={set('starts_on')} />)}</div>
          <div className="col-6">{field('ends_on', t('vreq.ends_on'), <input id="vr-ends_on" type="date" className={cls('ends_on')} value={form.ends_on} min={form.starts_on || undefined} onChange={set('ends_on')} />)}</div>
        </div>
        {field('notes', t('vreq.notes'), <textarea id="vr-notes" rows={2} className={cls('notes')} value={form.notes} onChange={set('notes')} />)}
        {hr && field('exception_reason', t('vreq.exception_reason'), <input id="vr-exception_reason" className={cls('exception_reason')} value={form.exception_reason} onChange={set('exception_reason')} />, t('vreq.exception_help'))}
        {simulation && <SimulationSummary simulation={simulation} />}
        {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}

function SimulationSummary({ simulation }: { simulation: VacationSimulation }) {
  const { t, locale } = useT()
  const n = (v: string) => formatNumber(locale, v)
  return (
    <section className="mb-3" aria-label={t('vreq.simulation')} aria-live="polite">
      <h3 className="h6">{t('vreq.simulation')}</h3>
      <p className="mb-1">
        <b>{t('vreq.days_n', { n: n(simulation.days) })}</b>
        <span className="sub2"> · {t(simulation.counts_calendar_days ? 'vreq.counts_calendar' : 'vreq.counts_working', { calendar: simulation.calendar_days, working: simulation.working_days })}</span>
      </p>
      {simulation.holidays.length > 0 && <p className="small text-secondary mb-1">{t('vreq.holidays', { dates: simulation.holidays.map((d) => formatDate(locale, d)).join(', ') })}</p>}
      <p className="small text-secondary">{t('vreq.balance_line', { available: n(simulation.balance.available), advance: n(simulation.balance.advance_available) })}</p>
      {simulation.issues.map((i) => <Notice key={i.code} tone="error">{i.message}</Notice>)}
      {simulation.warnings.map((w) => <Notice key={w.code} tone="warn">{w.message}</Notice>)}
    </section>
  )
}

/** VAC-3 · Detalle con sus acciones (enviar, registrar, cancelar) y los cruces del equipo (VAC-4). Aprobar se hace en el centro de aprobaciones. */
export function VacationRequestDetailPanel({ id, onClose, onChanged, toast }: { id: string; onClose: () => void; onChanged: () => void; toast: Toast }) {
  const { t, locale } = useT()
  const fmt = useDateTime()
  const [request, setRequest] = useState<VacationRequest | null>(null)
  const [overlaps, setOverlaps] = useState<VacationOverlaps | null>(null)
  const [failed, setFailed] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const [reason, setReason] = useState('')
  const [warnings, setWarnings] = useState<string[]>([])

  useEffect(() => {
    setFailed(false)
    void api.get<{ data: VacationRequest }>(`/vacation-requests/${id}`).then((r) => setRequest(r.data)).catch(() => setFailed(true))
    void api.get<{ data: VacationOverlaps }>(`/vacation-requests/${id}/overlaps`).then((r) => setOverlaps(r.data)).catch(() => setOverlaps(null))
  }, [id])

  async function act(action: 'submit' | 'register' | 'cancel') {
    setBusy(action)
    try {
      const res = await api.post<{ data: VacationRequest; meta?: { warnings?: Array<{ message: string }> } }>(`/vacation-requests/${id}/${action}`, action === 'cancel' ? { reason: reason.trim() || null } : {})
      setRequest(res.data)
      setWarnings((res.meta?.warnings ?? []).map((w) => w.message))
      toast(t(`vreq.done_${action}`))
      onChanged()
    } catch (err) {
      toast(err instanceof ApiRequestError ? err.message : t('auth.generic_error'), 'error')
    } finally {
      setBusy(null)
    }
  }

  const actions = request?.allowed_actions ?? []
  const footer = actions.some((a) => ['submit', 'register', 'cancel'].includes(a)) ? <>
    {actions.includes('cancel') && <button type="button" className="btn btn-sec" disabled={busy !== null} onClick={() => void act('cancel')}>{busy === 'cancel' && <span className="spin" aria-hidden="true" />}{t('vreq.action_cancel')}</button>}
    {actions.includes('register') && <button type="button" className="btn btn-sec" disabled={busy !== null} onClick={() => void act('register')}>{busy === 'register' && <span className="spin" aria-hidden="true" />}{t('vreq.action_register')}</button>}
    {actions.includes('submit') && <button type="button" className="btn btn-primary" disabled={busy !== null} onClick={() => void act('submit')}>{busy === 'submit' && <span className="spin" aria-hidden="true" />}{t('vreq.action_submit')}</button>}
  </> : undefined

  return (
    <SidePanel title={request ? t('vreq.detail_title', { name: request.person_name ?? '' }) : t('vreq.detail')} onClose={onClose} closeLabel={t('common.close')} footer={footer}>
      {failed && <p role="alert">{t('vreq.load_error')}</p>}
      {!request && !failed && <span className="esq" style={{ width: 240 }} />}
      {request && (
        <>
          {warnings.map((w) => <Notice key={w} tone="warn">{w}</Notice>)}
          {request.status === 'rejected' && request.decision_reason && <Notice tone="error">{t('vreq.rejected_reason', { reason: request.decision_reason })}</Notice>}
          <dl className="ficha mb-3">
            <dt>{t('vreq.col_status')}</dt><dd><span className={`st ${VACATION_TONE[request.status]}`}>{t(`vreq.status_${request.status}`)}</span></dd>
            <dt>{t('vreq.employee')}</dt><dd>{request.person_name ?? '—'}{request.employee_code && <span className="sub2"> · {request.employee_code}</span>}</dd>
            <dt>{t('vreq.col_dates')}</dt><dd><VacationDates request={request} /></dd>
            <dt>{t('vreq.col_days')}</dt><dd>{t('vreq.days_n', { n: formatNumber(locale, request.days) })}{request.calendar_days !== null && <span className="sub2"> · {t('vreq.calendar_n', { n: formatNumber(locale, request.calendar_days) })}</span>}</dd>
            {request.advance && request.advance_days !== null && <><dt>{t('vreq.advance')}</dt><dd>{t('vreq.days_n', { n: formatNumber(locale, request.advance_days) })}</dd></>}
            {request.notes && <><dt>{t('vreq.notes')}</dt><dd>{request.notes}</dd></>}
            {request.exception_reason && <><dt>{t('vreq.exception_reason')}</dt><dd>{request.exception_reason}</dd></>}
            {request.requested_by_name && <><dt>{t('vreq.requested_by')}</dt><dd>{request.requested_by_name}</dd></>}
            {request.submitted_at && <><dt>{t('vreq.submitted_at')}</dt><dd>{fmt(request.submitted_at)}</dd></>}
            {request.decided_at && <><dt>{t('vreq.decided_at')}</dt><dd>{fmt(request.decided_at)}</dd></>}
            {request.cancellation_reason && <><dt>{t('vreq.cancellation_reason')}</dt><dd>{request.cancellation_reason}</dd></>}
          </dl>
          {request.approval && (
            <section className="mb-3" aria-label={t('vreq.approval_title')}>
              <h3 className="h6">{t('vreq.approval_title')}</h3>
              <p className="mb-0">{request.approval.pending_approvers.length > 0 ? t('vreq.pending_with', { names: request.approval.pending_approvers.join(', ') }) : t('vreq.in_approval')}</p>
            </section>
          )}
          {overlaps && (
            <section className="mb-3" aria-label={t('vreq.overlaps_title')}>
              <h3 className="h6">{t('vreq.overlaps_title')}</h3>
              {overlaps.coverage.map((c) => <Notice key={c.from} tone="warn">{t('vreq.coverage_alert', { from: formatDate(locale, c.from), to: formatDate(locale, c.to), count: c.count, limit: c.limit })}</Notice>)}
              {overlaps.people.length === 0 && <p className="small text-secondary">{t('vreq.overlaps_none')}</p>}
              <ul className="mb-0">
                {overlaps.people.map((p) => (
                  <li key={p.employment_id}>
                    <b>{p.person_name}</b>
                    {p.items.map((i, k) => <span key={k} className="sub2"> · {i.label} {t('vreq.range', { from: formatDate(locale, i.starts_on), to: formatDate(locale, i.ends_on) })}{i.pending ? ` (${t('vreq.pending')})` : ''}</span>)}
                  </li>
                ))}
              </ul>
            </section>
          )}
          {actions.includes('cancel') && request.status !== 'draft' && (
            <div className="mb-3">
              <label className="form-label" htmlFor="vr-cancel-reason">{t('vreq.cancel_reason')}</label>
              <textarea id="vr-cancel-reason" className="form-control" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
            </div>
          )}
        </>
      )}
    </SidePanel>
  )
}
