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
import { ABSENCE_TONE, SUBSIDY_TONE, absenceFiles, absenceReason, type Absence, type AbsenceSimulation, type MedicalCertificate } from './absences'
import { toggleExtension } from './subsidies'

type Toast = (text: string, kind?: 'ok' | 'error') => void
type EmploymentOption = { id: string; person_name?: string | null; employee_code: string }
type TypeOption = { id: string; name: string; status: string; is_confidential: boolean; rules: { unit: 'days' | 'hours' } | null }

/** Cantidad tal como la calculó el servidor: «4 días» o «2.5 horas». */
export function AbsenceQuantity({ absence }: { absence: Pick<Absence, 'unit' | 'days' | 'hours'> }) {
  const { t, locale } = useT()
  const value = absence.unit === 'hours' ? absence.hours : absence.days
  if (value === null) return <>{'—'}</>
  return <>{t(absence.unit === 'hours' ? 'abs.hours_n' : 'abs.days_n', { n: formatNumber(locale, value) })}</>
}

export function AbsenceDates({ absence }: { absence: Pick<Absence, 'starts_on' | 'ends_on' | 'start_time' | 'end_time'> }) {
  const { t, locale } = useT()
  if (absence.start_time) return <>{t('abs.on_hours', { date: formatDate(locale, absence.starts_on), from: absence.start_time, to: absence.end_time ?? '' })}</>
  if (absence.starts_on === absence.ends_on) return <>{formatDate(locale, absence.starts_on)}</>
  return <>{t('abs.range', { from: formatDate(locale, absence.starts_on), to: formatDate(locale, absence.ends_on) })}</>
}

/**
 * AUS-2 · Registrar una ausencia en borrador. La simulación (días calendario y hábiles, feriados de la sede,
 * reglas del tipo y avisos) la hace el servidor mientras se completa el formulario.
 */
export function AbsenceFormPanel({ employmentId, onClose, onSaved }: { employmentId?: string; onClose: () => void; onSaved: (id: string) => void }) {
  const { t } = useT()
  const { can } = useSession()
  const [employments, setEmployments] = useState<EmploymentOption[]>([])
  const [types, setTypes] = useState<TypeOption[]>([])
  const [form, setForm] = useState({ employment_id: employmentId ?? '', absence_type_id: '', starts_on: '', ends_on: '', start_time: '', end_time: '', reason: '', exception_reason: '' })
  const [simulation, setSimulation] = useState<AbsenceSimulation | null>(null)
  const [extensions, setExtensions] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const type = types.find((x) => x.id === form.absence_type_id)
  const hours = type?.rules?.unit === 'hours'
  const hr = can('time.absences.create_for_others')

  useEffect(() => {
    void api.get<{ data: TypeOption[] }>('/absence-types?per_page=100').then((r) => setTypes(r.data.filter((x) => x.status === 'active'))).catch(() => setTypes([]))
    if (employmentId) return
    void api.get<{ data: EmploymentOption[] }>('/employments?filter[status]=active&sort=person_name&per_page=100')
      .then((r) => {
        setEmployments(r.data)
        if (r.data.length === 1) setForm((f) => ({ ...f, employment_id: r.data[0]?.id ?? '' }))
      })
      .catch(() => setEmployments([]))
  }, [employmentId])

  const body = () => ({
    employment_id: form.employment_id, absence_type_id: form.absence_type_id, starts_on: form.starts_on,
    // AUS-4: sin fecha de fin, el servidor propone la de la licencia de ley (o un solo día).
    ends_on: hours ? form.starts_on : (form.ends_on || null),
    start_time: hours ? form.start_time || null : null, end_time: hours ? form.end_time || null : null,
    reason: form.reason.trim() || null, exception_reason: form.exception_reason.trim() || null,
    legal_extensions: extensions,
  })

  // Simulación en el servidor (regla 11): se repite al cambiar el tipo, las fechas o las horas.
  const seq = useRef(0)
  useEffect(() => {
    if (!form.employment_id || !form.absence_type_id || !form.starts_on || (hours && (!form.start_time || !form.end_time))) {
      setSimulation(null)
      return
    }
    const n = ++seq.current
    const timer = window.setTimeout(() => {
      api.post<{ data: AbsenceSimulation }>('/absences/simulate', body())
        .then((r) => { if (n === seq.current) setSimulation(r.data) })
        .catch(() => { if (n === seq.current) setSimulation(null) })
    }, 300)
    return () => window.clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [form.employment_id, form.absence_type_id, form.starts_on, form.ends_on, form.start_time, form.end_time, form.exception_reason, hours, extensions])

  // Las causales son del tipo: al cambiarlo se vacían.
  useEffect(() => { setExtensions([]) }, [form.absence_type_id])

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    try {
      const res = await api.post<{ data: Absence }>('/absences', body())
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
      <label className="form-label" htmlFor={`ab-${key}`}>{label}</label>
      {key === 'absence_type_id' && <FieldHelp field="absence_type" />}
      {input}
      {help && <p className="small text-secondary">{help}</p>}
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )
  const cls = (key: string, base = 'form-control') => `${base}${errors[key] ? ' is-invalid' : ''}`

  return (
    <SidePanel
      title={t('abs.form_new')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('abs.close')}</button>
        <button type="submit" form="ausencia" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('abs.save_draft')}</button>
      </>}
    >
      <form id="ausencia" onSubmit={submit} noValidate>
        {!employmentId && field('employment_id', t('abs.employee'), (
          <select id="ab-employment_id" className={cls('employment_id', 'form-select')} value={form.employment_id} onChange={set('employment_id')}>
            <option value="">{t('abs.choose')}</option>
            {employments.map((m) => <option key={m.id} value={m.id}>{m.person_name ?? m.employee_code} · {m.employee_code}</option>)}
          </select>
        ))}
        {field('absence_type_id', t('abs.type'), (
          <select id="ab-absence_type_id" className={cls('absence_type_id', 'form-select')} value={form.absence_type_id} onChange={set('absence_type_id')}>
            <option value="">{t('abs.choose')}</option>
            {types.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
          </select>
        ))}
        <div className="row g-2">
          <div className="col-6">{field('starts_on', t(hours ? 'abs.date' : 'abs.starts_on'), <input id="ab-starts_on" type="date" className={cls('starts_on')} value={form.starts_on} onChange={set('starts_on')} />)}</div>
          {!hours && <div className="col-6">{field('ends_on', t('abs.ends_on'), <input id="ab-ends_on" type="date" className={cls('ends_on')} value={form.ends_on} min={form.starts_on || undefined} onChange={set('ends_on')} />)}</div>}
        </div>
        {hours && (
          <div className="row g-2">
            <div className="col-6">{field('start_time', t('abs.start_time'), <input id="ab-start_time" type="time" className={cls('start_time')} value={form.start_time} onChange={set('start_time')} />)}</div>
            <div className="col-6">{field('end_time', t('abs.end_time'), <input id="ab-end_time" type="time" className={cls('end_time')} value={form.end_time} onChange={set('end_time')} />)}</div>
          </div>
        )}
        {field('reason', t('abs.reason'), <textarea id="ab-reason" rows={2} className={cls('reason')} value={form.reason} onChange={set('reason')} />, type?.is_confidential ? t('abs.reason_confidential') : undefined)}
        {hr && field('exception_reason', t('abs.exception_reason'), <input id="ab-exception_reason" className={cls('exception_reason')} value={form.exception_reason} onChange={set('exception_reason')} />, t('abs.exception_help'))}
        {simulation?.legal_leave && simulation.legal_leave.extensions.length > 0 && (
          <fieldset className="mb-3">
            <legend className="form-label">{t('abs.legal_extensions')}</legend>
            {simulation.legal_leave.extensions.map((x) => (
              <div className="form-check" key={x.code}>
                <input id={`ab-ext-${x.code}`} type="checkbox" className="form-check-input" checked={extensions.includes(x.code)} onChange={() => setExtensions((l) => toggleExtension(l, x.code))} />
                <label className="form-check-label" htmlFor={`ab-ext-${x.code}`}>{x.label}{x.pending_validation && <span className="sub2"> · {t('abs.pending_validation')}</span>}</label>
              </div>
            ))}
            {errors.legal_extensions && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{errors.legal_extensions}</div>}
          </fieldset>
        )}
        {simulation && <SimulationSummary simulation={simulation} proposedEnd={!hours && !form.ends_on} />}
        {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}

function SimulationSummary({ simulation, proposedEnd }: { simulation: AbsenceSimulation; proposedEnd: boolean }) {
  const { t, locale } = useT()
  const plan = simulation.legal_leave
  const subsidy = simulation.subsidy
  return (
    <section className="mb-3" aria-label={t('abs.simulation')} aria-live="polite">
      <h3 className="h6">{t('abs.simulation')}</h3>
      <p className="mb-1"><AbsenceQuantity absence={simulation} />{simulation.unit === 'days' && <span className="sub2"> · {t('abs.calendar_working', { calendar: simulation.calendar_days, working: simulation.working_days })}</span>}</p>
      {simulation.holidays.length > 0 && <p className="small text-secondary mb-1">{t('abs.holidays', { dates: simulation.holidays.map((d) => formatDate(locale, d)).join(', ') })}</p>}
      <p className="small text-secondary">{t(simulation.is_paid ? 'abs.paid' : 'abs.unpaid')}{simulation.document_required ? ` · ${t('abs.document_required')}` : ''}</p>
      {proposedEnd && plan?.total_days && <p className="small mb-1">{t('abs.proposed_end', { date: formatDate(locale, simulation.ends_on), days: formatNumber(locale, plan.total_days) })}{plan.pending_validation && <span className="sub2"> · {t('abs.pending_validation')}</span>}</p>}
      {simulation.return_on && <p className="small mb-1">{t('abs.return_on_text', { date: formatDate(locale, simulation.return_on) })}</p>}
      {simulation.required_documents.length > 0 && <p className="small text-secondary mb-1">{t('abs.required_documents', { documents: simulation.required_documents.map((d) => d.label).join(', ') })}</p>}
      {subsidy && <p className="small mb-1">{t('abs.subsidy_preview', { employer: formatNumber(locale, subsidy.employer_paid_days), subsidized: formatNumber(locale, subsidy.subsidized_days), accumulated: formatNumber(locale, subsidy.accumulated_days) })}</p>}
      {simulation.issues.map((i) => <Notice key={i.code} tone="error">{i.message}</Notice>)}
      {simulation.warnings.map((w) => <Notice key={w.code} tone="warn">{w.message}</Notice>)}
    </section>
  )
}

function saveFile(blob: Blob, name: string) {
  const href = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = href
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(href)
}

/** AUS-2 · Detalle con sus acciones (enviar, registrar, cancelar, adjuntar sustento). Aprobar se hace en el centro de aprobaciones. */
export function AbsenceDetailPanel({ id, onClose, onChanged, toast }: { id: string; onClose: () => void; onChanged: () => void; toast: Toast }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const fmt = useDateTime()
  const [absence, setAbsence] = useState<Absence | null>(null)
  const [failed, setFailed] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const [reason, setReason] = useState('')
  const [warnings, setWarnings] = useState<string[]>([])
  const file = useRef<HTMLInputElement>(null)

  useEffect(() => {
    setFailed(false)
    void api.get<{ data: Absence }>(`/absences/${id}`).then((r) => setAbsence(r.data)).catch(() => setFailed(true))
  }, [id])

  async function act(action: 'submit' | 'register' | 'cancel') {
    setBusy(action)
    try {
      const res = await api.post<{ data: Absence; meta?: { warnings?: Array<{ message: string }> } }>(`/absences/${id}/${action}`, action === 'cancel' ? { reason: reason.trim() || null } : {})
      setAbsence(res.data)
      setWarnings((res.meta?.warnings ?? []).map((w) => w.message))
      toast(t(`abs.done_${action}`))
      onChanged()
    } catch (err) {
      toast(err instanceof ApiRequestError ? err.message : t('auth.generic_error'), 'error')
    } finally {
      setBusy(null)
    }
  }

  async function attach(list: FileList | null) {
    const chosen = list?.[0]
    if (!chosen) return
    setBusy('attach')
    const form = new FormData()
    form.append('file', chosen)
    try {
      const res = await api.postForm<{ data: Absence }>(`/absences/${id}/attachments`, form)
      setAbsence(res.data)
      toast(t('abs.done_attach'))
      onChanged()
    } catch (err) {
      toast(formErrors(err, t).fields.file ?? (err instanceof ApiRequestError ? err.message : t('auth.generic_error')), 'error')
    } finally {
      setBusy(null)
      if (file.current) file.current.value = ''
    }
  }

  const actions = absence?.allowed_actions ?? []
  const files = absence ? absenceFiles(absence) : []
  const text = absence ? absenceReason(absence) : null
  const footer = actions.some((a) => ['submit', 'register', 'cancel'].includes(a)) ? <>
    {actions.includes('cancel') && <button type="button" className="btn btn-sec" disabled={busy !== null} onClick={() => void act('cancel')}>{busy === 'cancel' && <span className="spin" aria-hidden="true" />}{t('abs.action_cancel')}</button>}
    {actions.includes('register') && <button type="button" className="btn btn-sec" disabled={busy !== null} onClick={() => void act('register')}>{busy === 'register' && <span className="spin" aria-hidden="true" />}{t('abs.action_register')}</button>}
    {actions.includes('submit') && <button type="button" className="btn btn-primary" disabled={busy !== null} onClick={() => void act('submit')}>{busy === 'submit' && <span className="spin" aria-hidden="true" />}{t('abs.action_submit')}</button>}
  </> : undefined

  return (
    <SidePanel title={absence?.absence_type_name ?? t('abs.detail')} onClose={onClose} closeLabel={t('common.close')} footer={footer}>
      {failed && <p role="alert">{t('abs.load_error')}</p>}
      {!absence && !failed && <span className="esq" style={{ width: 240 }} />}
      {absence && (
        <>
          {warnings.map((w) => <Notice key={w} tone="warn">{w}</Notice>)}
          {absence.status === 'pending_document' && absence.document_due_on && <Notice tone="warn">{t('abs.document_due', { date: formatDate(locale, absence.document_due_on) })}</Notice>}
          {absence.status === 'rejected' && absence.decision_reason && <Notice tone="error">{t('abs.rejected_reason', { reason: absence.decision_reason })}</Notice>}
          <dl className="ficha mb-3">
            <dt>{t('abs.col_status')}</dt><dd><span className={`st ${ABSENCE_TONE[absence.status]}`}>{t(`abs.status_${absence.status}`)}</span>{absence.registered_directly && <span className="sub2"> · {t('abs.registered_directly')}</span>}</dd>
            <dt>{t('abs.employee')}</dt><dd>{absence.person_name ?? '—'}{absence.employee_code && <span className="sub2"> · {absence.employee_code}</span>}</dd>
            <dt>{t('abs.col_dates')}</dt><dd><AbsenceDates absence={absence} /></dd>
            <dt>{t('abs.col_quantity')}</dt><dd><AbsenceQuantity absence={absence} />{absence.unit === 'days' && absence.calendar_days !== null && <span className="sub2"> · {t('abs.calendar_n', { n: formatNumber(locale, absence.calendar_days) })}</span>}</dd>
            {absence.return_on && <><dt>{t('abs.return_on')}</dt><dd>{formatDate(locale, absence.return_on)}</dd></>}
            {absence.is_paid !== null && <><dt>{t('abs.pay')}</dt><dd>{t(absence.is_paid ? 'abs.paid' : 'abs.unpaid')}</dd></>}
            {absence.employer_paid_days != null && absence.subsidized_days != null && <><dt>{t('abs.subsidy_split')}</dt><dd>{t('abs.subsidy_split_text', { employer: formatNumber(locale, absence.employer_paid_days), subsidized: formatNumber(locale, absence.subsidized_days) })}{absence.subsidy_status && <span className={`st ${SUBSIDY_TONE[absence.subsidy_status]}`}> {t(`sub.status_${absence.subsidy_status}`)}</span>}</dd></>}
            {(absence.required_documents ?? []).length > 0 && <><dt>{t('abs.required_documents_label')}</dt><dd>{(absence.required_documents ?? []).map((d) => d.label).join(', ')}</dd></>}
            {text && <><dt>{t('abs.reason')}</dt><dd>{text}</dd></>}
            {absence.exception_reason && <><dt>{t('abs.exception_reason')}</dt><dd>{absence.exception_reason}</dd></>}
            {absence.requested_by_name && <><dt>{t('abs.requested_by')}</dt><dd>{absence.requested_by_name}</dd></>}
            {absence.submitted_at && <><dt>{t('abs.submitted_at')}</dt><dd>{fmt(absence.submitted_at)}</dd></>}
            {absence.decided_at && <><dt>{t('abs.decided_at')}</dt><dd>{fmt(absence.decided_at)}</dd></>}
            {absence.cancellation_reason && <><dt>{t('abs.cancellation_reason')}</dt><dd>{absence.cancellation_reason}</dd></>}
          </dl>
          {absence.approval && (
            <section className="mb-3" aria-label={t('abs.approval_title')}>
              <h3 className="h6">{t('abs.approval_title')}</h3>
              <p className="mb-0">{absence.approval.pending_approvers.length > 0 ? t('abs.pending_with', { names: absence.approval.pending_approvers.join(', ') }) : t('abs.in_approval')}</p>
            </section>
          )}
          {(files.length > 0 || actions.includes('attach')) && (
            <section className="mb-3" aria-label={t('abs.documents')}>
              <h3 className="h6">{t('abs.documents')}</h3>
              {files.length === 0 && <p className="small text-secondary">{t('abs.no_documents')}</p>}
              <ul className="mb-2">
                {files.map((f) => (
                  <li key={f.id}>
                    <button type="button" className="btn btn-ter btn-sm" onClick={() => void api.download(`/absences/${id}/attachments/${f.id}`).then(({ blob }) => saveFile(blob, f.name)).catch(() => toast(t('abs.load_error'), 'error'))}>{f.label ?? f.name}</button>
                    <span className="sub2">{f.name}</span>
                  </li>
                ))}
              </ul>
              {actions.includes('attach') && (
                <>
                  <label className="form-label" htmlFor="ab-file">{t('abs.attach')}</label>
                  <input id="ab-file" ref={file} type="file" className="form-control" accept="application/pdf,image/png,image/jpeg" disabled={busy !== null} onChange={(e) => void attach(e.target.files)} />
                  <p className="small text-secondary">{t('abs.attach_help')}</p>
                </>
              )}
            </section>
          )}
          {absence.employer_paid_days !== undefined && can('time.absences.medical_certificate.view') && <MedicalCertificateSection absenceId={id} editable={can('time.absences.create_for_others') && !['rejected', 'cancelled'].includes(absence.status)} toast={toast} />}
          {actions.includes('cancel') && absence.status !== 'draft' && (
            <div className="mb-3">
              <label className="form-label" htmlFor="ab-cancel-reason">{t('abs.cancel_reason')}</label>
              <textarea id="ab-cancel-reason" className="form-control" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
            </div>
          )}
        </>
      )}
    </SidePanel>
  )
}

/**
 * AUS-3 · Certificado médico del descanso (CITT o particular). Solo se muestra con permiso de datos de salud;
 * el servidor no envía número ni diagnóstico sin él y registra cada lectura.
 */
function MedicalCertificateSection({ absenceId, editable, toast }: { absenceId: string; editable: boolean; toast: Toast }) {
  const { t, locale } = useT()
  const [cert, setCert] = useState<MedicalCertificate | null>(null)
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState({ kind: 'citt', certificate_number: '', issuer: '', diagnosis: '', issued_on: '', days_granted: '', is_work_related: false })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    void api.get<{ data: MedicalCertificate }>(`/absences/${absenceId}/medical-certificate`).then((r) => setCert(r.data)).catch(() => setCert(null))
  }, [absenceId])

  function edit() {
    setForm({
      kind: cert?.kind ?? 'citt', certificate_number: cert?.certificate_number ?? '', issuer: cert?.issuer ?? '', diagnosis: cert?.diagnosis ?? '',
      issued_on: cert?.issued_on ?? '', days_granted: cert?.days_granted == null ? '' : String(cert.days_granted), is_work_related: cert?.is_work_related ?? false,
    })
    setErrors({})
    setEditing(true)
  }

  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    try {
      const res = await api.request<{ data: MedicalCertificate }>(`/absences/${absenceId}/medical-certificate`, 'PUT', {
        kind: form.kind, certificate_number: form.certificate_number.trim() || null, issuer: form.issuer.trim() || null,
        diagnosis: form.diagnosis.trim() || null, issued_on: form.issued_on || null,
        days_granted: form.days_granted === '' ? null : Number(form.days_granted), is_work_related: form.is_work_related,
      })
      setCert(res.data)
      setEditing(false)
      toast(t('abs.cert_saved'))
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      if (general) toast(general, 'error')
    } finally {
      setBusy(false)
    }
  }

  const input = (key: 'certificate_number' | 'issuer' | 'issued_on' | 'days_granted', label: string, type = 'text') => (
    <div className="mb-2">
      <label className="form-label" htmlFor={`mc-${key}`}>{label}</label>
      <input id={`mc-${key}`} type={type} className={`form-control${errors[key] ? ' is-invalid' : ''}`} value={form[key]} onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.value }))} />
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )

  return (
    <section className="mb-3" aria-label={t('abs.cert_title')}>
      <h3 className="h6">{t('abs.cert_title')}</h3>
      {!editing && (
        <>
          {!cert?.id && <p className="small text-secondary">{t('abs.cert_empty')}</p>}
          {cert?.id && (
            <dl className="ficha mb-2">
              <dt>{t('abs.cert_kind')}</dt><dd>{t(`abs.cert_kind_${cert.kind ?? 'citt'}`)}{cert.validated_at && <span className="st ok"> {t('abs.cert_validated')}</span>}</dd>
              {cert.certificate_number && <><dt>{t('abs.cert_number')}</dt><dd>{cert.certificate_number}</dd></>}
              {cert.issuer && <><dt>{t('abs.cert_issuer')}</dt><dd>{cert.issuer}</dd></>}
              {cert.issued_on && <><dt>{t('abs.cert_issued_on')}</dt><dd>{formatDate(locale, cert.issued_on)}</dd></>}
              {cert.diagnosis && <><dt>{t('abs.cert_diagnosis')}</dt><dd>{cert.diagnosis}</dd></>}
              {cert.days_granted !== null && <><dt>{t('abs.cert_days')}</dt><dd>{t('abs.days_n', { n: String(cert.days_granted) })}</dd></>}
              <dt>{t('abs.cert_work_related')}</dt><dd>{t(cert.is_work_related ? 'common.yes' : 'common.no')}</dd>
            </dl>
          )}
          {cert?.days_mismatch && <Notice tone="warn">{t('abs.cert_mismatch', { granted: String(cert.days_granted ?? ''), days: String(cert.absence_calendar_days ?? '') })}</Notice>}
          {editable && <button type="button" className="btn btn-sec btn-sm" onClick={edit}>{t(cert?.id ? 'abs.cert_edit' : 'abs.cert_add')}</button>}
        </>
      )}
      {editing && (
        <form onSubmit={save} noValidate>
          <div className="mb-2">
            <label className="form-label" htmlFor="mc-kind">{t('abs.cert_kind')}</label>
            <select id="mc-kind" className="form-select" value={form.kind} onChange={(e) => setForm((f) => ({ ...f, kind: e.target.value }))}>
              <option value="citt">{t('abs.cert_kind_citt')}</option>
              <option value="private">{t('abs.cert_kind_private')}</option>
            </select>
          </div>
          {input('certificate_number', t('abs.cert_number'))}
          {input('issuer', t('abs.cert_issuer'))}
          {input('issued_on', t('abs.cert_issued_on'), 'date')}
          {input('days_granted', t('abs.cert_days'), 'number')}
          <div className="mb-2">
            <label className="form-label" htmlFor="mc-diagnosis">{t('abs.cert_diagnosis')}</label>
            <textarea id="mc-diagnosis" rows={2} className={`form-control${errors.diagnosis ? ' is-invalid' : ''}`} value={form.diagnosis} onChange={(e) => setForm((f) => ({ ...f, diagnosis: e.target.value }))} />
            <p className="small text-secondary">{t('abs.cert_diagnosis_help')}</p>
          </div>
          <div className="form-check mb-2">
            <input id="mc-work" type="checkbox" className="form-check-input" checked={form.is_work_related} onChange={(e) => setForm((f) => ({ ...f, is_work_related: e.target.checked }))} />
            <label className="form-check-label" htmlFor="mc-work">{t('abs.cert_work_related')}</label>
          </div>
          <button type="button" className="btn btn-sec btn-sm" onClick={() => setEditing(false)}>{t('abs.close')}</button>{' '}
          <button type="submit" className="btn btn-primary btn-sm" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('abs.cert_save')}</button>
        </form>
      )}
    </section>
  )
}
