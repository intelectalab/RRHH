'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'
import { remoteWorkTone, REMOTE_WORK_FIELDS, type RemoteWork } from './remoteWork'

type Option = { id: string; label: string }
const FILTERS = ['requested', 'approved', 'pending_signature', 'active', 'all'] as const

/**
 * Personas → Teletrabajo (CON-6): solicitudes con su plazo legal de respuesta (lo calcula la API) y acuerdos que solo
 * se emiten con el contenido mínimo completo (RN-CON-10). El estado cambia solo por acciones.
 */
export function RemoteWorkScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [filter, setFilter] = useState<(typeof FILTERS)[number]>('requested')
  const [rows, setRows] = useState<RemoteWork[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [open, setOpen] = useState<RemoteWork | null>(null)
  const [creating, setCreating] = useState(false)

  const load = useCallback(() => {
    setFailed(false)
    const q = filter === 'all' ? '' : `&filter[status]=${filter}`
    api.get<{ data: RemoteWork[] }>(`/remote-work-agreements?per_page=100${q}`).then((r) => setRows(r.data)).catch(() => setFailed(true))
  }, [filter])
  useEffect(load, [load])

  return (
    <>
      <PageHeader title={t('remote_work.title')} sub={t('remote_work.sub')}
        actions={can('contracts.remote_work.create') ? <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>{t('remote_work.new')}</button> : undefined} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="chips mb-3" role="group" aria-label={t('remote_work.filter')}>
          {FILTERS.map((f) => (
            <button key={f} type="button" className={`chip${filter === f ? ' on' : ''}`} aria-pressed={filter === f} onClick={() => setFilter(f)}>
              {f === 'all' ? t('remote_work.all') : t(`remote_work.status_${f}`)}
            </button>
          ))}
        </div>
        {failed ? <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={load} />
          : !rows ? <span className="esq" style={{ width: 320 }} />
            : rows.length === 0 ? <EmptyState title={t('remote_work.empty_title')} text={t('remote_work.empty_text')} />
              : (
                <section className="card">
                  <table className="table">
                    <thead><tr>
                      <th>{t('remote_work.employee')}</th><th>{t('remote_work.modality')}</th><th>{t('remote_work.starts_on')}</th>
                      <th>{t('remote_work.due_on')}</th><th>{t('remote_work.state')}</th>
                    </tr></thead>
                    <tbody>
                      {rows.map((r) => (
                        <tr key={r.id}>
                          <td><button type="button" className="btn-link" onClick={() => setOpen(r)}>{r.person_name ?? '—'}</button><span className="sub2"> {r.employee_code}</span></td>
                          <td>{t(`remote_work.modality_${r.modality}`)}</td>
                          <td>{formatDate(locale, r.starts_on)}</td>
                          <td>
                            {r.status === 'requested' && r.response_due_on ? (
                              <>
                                {formatDate(locale, r.response_due_on)}
                                {r.overdue ? <StatusBadge tone="er">{t('remote_work.overdue')}</StatusBadge> : r.due_soon ? <StatusBadge tone="wa">{t('remote_work.due_soon')}</StatusBadge> : null}
                              </>
                            ) : '—'}
                          </td>
                          <td><StatusBadge tone={remoteWorkTone(r.status)}>{t(`remote_work.status_${r.status}`)}</StatusBadge></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </section>
              )}
      </main>
      {creating && <CreatePanel onClose={() => setCreating(false)} onCreated={(r) => { setCreating(false); load(); setOpen(r); toast(t('remote_work.created')) }} />}
      {open && <AgreementPanel agreement={open} onChange={(r) => { setOpen(r); load() }} onClose={() => setOpen(null)} />}
      {toastNode}
    </>
  )
}

function CreatePanel({ onClose, onCreated }: { onClose: () => void; onCreated: (r: RemoteWork) => void }) {
  const { t } = useT()
  const [people, setPeople] = useState<Option[]>([])
  const [form, setForm] = useState({ employment_id: '', modality: 'partial', starts_on: '', requested_on: localToday(), requested_by: 'employee', request_reason: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    void api.get<{ data: Array<{ id: string; person_name?: string; employee_code?: string }> }>('/employments?per_page=100&filter[status]=active')
      .then((r) => setPeople(r.data.map((e) => ({ id: e.id, label: `${e.person_name ?? ''} ${e.employee_code ?? ''}`.trim() }))))
      .catch(() => setPeople([]))
  }, [])

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      const r = await api.post<{ data: RemoteWork }>('/remote-work-agreements', {
        employment_id: form.employment_id, modality: form.modality, starts_on: form.starts_on, requested_on: form.requested_on, requested_by: form.requested_by,
        ...(form.request_reason.trim() ? { request_reason: form.request_reason.trim() } : {}),
      })
      onCreated(r.data)
    } catch (err) {
      const f = formErrors(err, t)
      setErrors(f.fields)
      setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel title={t('remote_work.new')} onClose={onClose} closeLabel={t('common.close')} footer={
      <>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('common.cancel')}</button>
        <button type="submit" form="rw-new" className="btn btn-primary" disabled={busy}>{t('remote_work.register')}</button>
      </>
    }>
      <form id="rw-new" onSubmit={(e) => void submit(e)} noValidate>
        {general && <div className="alert alert-danger" role="alert">{general}</div>}
        <label className="form-label" htmlFor="rw-emp">{t('remote_work.employee')}</label>
        <select id="rw-emp" className="form-select" required value={form.employment_id} onChange={(e) => setForm({ ...form, employment_id: e.target.value })} aria-invalid={!!errors.employment_id}>
          <option value="">—</option>
          {people.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
        </select>
        {errors.employment_id && <div className="invalid-feedback d-block">{errors.employment_id}</div>}
        <label className="form-label mt-3" htmlFor="rw-mod">{t('remote_work.modality')}</label>
        <select id="rw-mod" className="form-select" value={form.modality} onChange={(e) => setForm({ ...form, modality: e.target.value })}>
          <option value="partial">{t('remote_work.modality_partial')}</option>
          <option value="total">{t('remote_work.modality_total')}</option>
        </select>
        <label className="form-label mt-3" htmlFor="rw-by">{t('remote_work.requested_by')}</label>
        <select id="rw-by" className="form-select" value={form.requested_by} onChange={(e) => setForm({ ...form, requested_by: e.target.value })}>
          <option value="employee">{t('remote_work.by_employee')}</option>
          <option value="employer">{t('remote_work.by_employer')}</option>
        </select>
        <label className="form-label mt-3" htmlFor="rw-req">{t('remote_work.requested_on')}</label>
        <input id="rw-req" type="date" className="form-control" value={form.requested_on} onChange={(e) => setForm({ ...form, requested_on: e.target.value })} />
        <p className="small text-secondary mt-1">{t('remote_work.due_help')}</p>
        <label className="form-label mt-3" htmlFor="rw-start">{t('remote_work.starts_on')}</label>
        <input id="rw-start" type="date" className="form-control" required value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value })} aria-invalid={!!errors.starts_on} />
        {errors.starts_on && <div className="invalid-feedback d-block">{errors.starts_on}</div>}
        <label className="form-label mt-3" htmlFor="rw-reason">{t('remote_work.reason')}</label>
        <textarea id="rw-reason" className="form-control" rows={3} maxLength={1000} value={form.request_reason} onChange={(e) => setForm({ ...form, request_reason: e.target.value })} />
      </form>
    </SidePanel>
  )
}

function AgreementPanel({ agreement, onChange, onClose }: { agreement: RemoteWork; onChange: (r: RemoteWork) => void; onClose: () => void }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [busy, setBusy] = useState(false)
  const [reason, setReason] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [signedOn, setSignedOn] = useState(localToday())
  const [content, setContent] = useState({
    work_place: agreement.work_place ?? '', schedule_description: agreement.schedule_description ?? '',
    disconnection_hours: agreement.disconnection_hours?.toString() ?? '', supervision_mechanism: agreement.supervision_mechanism ?? '',
    equipment_provided_by: agreement.equipment_provided_by ?? '', compensation: agreement.expense_compensation?.amount ?? '',
    currency: agreement.expense_compensation?.currency ?? 'PEN',
  })
  const editable = ['requested', 'approved', 'pending_signature'].includes(agreement.status) && can('contracts.remote_work.update')
  const allowed = agreement.allowed_actions ?? []

  async function run(fn: () => Promise<{ data: RemoteWork }>, ok: string) {
    setBusy(true)
    try {
      onChange((await fn()).data)
      toast(t(ok))
    } catch (err) {
      const f = formErrors(err, t)
      toast(f.general ?? Object.values(f.fields)[0] ?? t('common.error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  const save = (e: FormEvent) => {
    e.preventDefault()
    void run(() => api.request<{ data: RemoteWork }>(`/remote-work-agreements/${agreement.id}`, 'PATCH', {
      work_place: content.work_place || null, schedule_description: content.schedule_description || null,
      disconnection_hours: content.disconnection_hours ? Number(content.disconnection_hours) : null,
      supervision_mechanism: content.supervision_mechanism || null, equipment_provided_by: content.equipment_provided_by || null,
      expense_compensation: content.compensation !== '' ? { amount: content.compensation, currency: content.currency } : null,
    }), 'remote_work.saved')
  }
  const post = (action: string, body: Record<string, unknown> = {}) => run(() => api.post<{ data: RemoteWork }>(`/remote-work-agreements/${agreement.id}/${action}`, body), `remote_work.done_${action}`)
  const sign = () => {
    if (!file) return
    const form = new FormData()
    form.append('file', file)
    form.append('signed_on', signedOn)
    void run(() => api.postForm<{ data: RemoteWork }>(`/remote-work-agreements/${agreement.id}/register-signature`, form), 'remote_work.done_register-signature')
  }

  return (
    <SidePanel wide title={agreement.person_name ?? t('remote_work.title')} onClose={onClose} closeLabel={t('common.close')}>
      <p><StatusBadge tone={remoteWorkTone(agreement.status)}>{t(`remote_work.status_${agreement.status}`)}</StatusBadge>
        <span className="sub2"> {t(`remote_work.modality_${agreement.modality}`)} · {formatDate(locale, agreement.starts_on)}</span></p>
      {agreement.status === 'requested' && agreement.response_due_on && (
        <div className={`alert ${agreement.overdue ? 'alert-danger' : agreement.due_soon ? 'alert-warning' : 'alert-info'}`}>
          {t('remote_work.due_notice', { date: formatDate(locale, agreement.response_due_on) })}
        </div>
      )}
      {agreement.request_reason && <p className="small">{t('remote_work.reason')}: {agreement.request_reason}</p>}
      {(agreement.missing_content ?? []).length > 0 && ['approved', 'requested'].includes(agreement.status) && (
        <div className="alert alert-secondary">{t('remote_work.missing', { fields: (agreement.missing_content ?? []).map((f) => t(`remote_work.field_${f}`)).join(', ') })}</div>
      )}
      <form onSubmit={save} aria-labelledby="rw-content">
        <h3 id="rw-content" className="h6">{t('remote_work.content')}</h3>
        {REMOTE_WORK_FIELDS.map((f) => (
          <div key={f} className="mb-2">
            <label className="form-label" htmlFor={`rw-${f}`}>{t(`remote_work.field_${f}`)}</label>
            {f === 'equipment_provided_by' ? (
              <select id={`rw-${f}`} className="form-select" disabled={!editable} value={content.equipment_provided_by} onChange={(e) => setContent({ ...content, equipment_provided_by: e.target.value })}>
                <option value="">—</option>
                {['employer', 'worker', 'mixed'].map((x) => <option key={x} value={x}>{t(`remote_work.equipment_${x}`)}</option>)}
              </select>
            ) : f === 'disconnection_hours' ? (
              <input id={`rw-${f}`} type="number" min={1} max={24} className="form-control" disabled={!editable} value={content.disconnection_hours} onChange={(e) => setContent({ ...content, disconnection_hours: e.target.value })} />
            ) : f === 'expense_compensation' ? (
              <div className="d-flex gap-2">
                <input id={`rw-${f}`} inputMode="decimal" className="form-control" disabled={!editable} value={content.compensation} onChange={(e) => setContent({ ...content, compensation: e.target.value })} />
                <input aria-label={t('remote_work.currency')} className="form-control" style={{ maxWidth: 90 }} maxLength={3} disabled={!editable} value={content.currency} onChange={(e) => setContent({ ...content, currency: e.target.value.toUpperCase() })} />
              </div>
            ) : (
              <textarea id={`rw-${f}`} className="form-control" rows={2} disabled={!editable} value={content[f]} onChange={(e) => setContent({ ...content, [f]: e.target.value })} />
            )}
          </div>
        ))}
        {editable && <button type="submit" className="btn btn-sec" disabled={busy}>{t('common.save')}</button>}
      </form>
      <div className="mt-3 d-flex flex-wrap gap-2">
        {allowed.includes('approve') && can('contracts.remote_work.decide') && <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void post('approve')}>{t('remote_work.approve')}</button>}
        {allowed.includes('issue') && can('contracts.remote_work.issue') && <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void post('issue')}>{t('remote_work.issue')}</button>}
        {agreement.has_document && can('contracts.remote_work.view') && (
          <button type="button" className="btn btn-ter" onClick={() => void api.download(`/remote-work-agreements/${agreement.id}/document`).then(({ blob, filename }) => {
            const url = URL.createObjectURL(blob)
            const a = document.createElement('a')
            a.href = url
            a.download = filename
            a.click()
            URL.revokeObjectURL(url)
          })}>{t('remote_work.download')}</button>
        )}
      </div>
      {allowed.includes('reject') && can('contracts.remote_work.decide') && (
        <div className="mt-3">
          <label className="form-label" htmlFor="rw-reject">{t('remote_work.reject_reason')}</label>
          <textarea id="rw-reject" className="form-control" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          <button type="button" className="btn btn-danger mt-2" disabled={busy || !reason.trim()} onClick={() => void post('reject', { reason })}>{t('remote_work.reject')}</button>
        </div>
      )}
      {allowed.includes('register-signature') && can('contracts.remote_work.issue') && (
        <div className="mt-3">
          <h3 className="h6">{t('remote_work.signed_copy')}</h3>
          <p className="small text-secondary">{t('remote_work.signature_help')}</p>
          <input type="file" className="form-control" aria-label={t('remote_work.signed_copy')} accept="application/pdf,image/*" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          <label className="form-label mt-2" htmlFor="rw-signed">{t('remote_work.signed_on')}</label>
          <input id="rw-signed" type="date" className="form-control" value={signedOn} onChange={(e) => setSignedOn(e.target.value)} />
          <button type="button" className="btn btn-primary mt-2" disabled={busy || !file} onClick={sign}>{t('remote_work.register_signature')}</button>
        </div>
      )}
      {allowed.includes('end') && can('contracts.remote_work.issue') && (
        <div className="mt-3">
          <label className="form-label" htmlFor="rw-end">{t('remote_work.end_reason')}</label>
          <textarea id="rw-end" className="form-control" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          <button type="button" className="btn btn-sec mt-2" disabled={busy || !reason.trim()} onClick={() => void post('end', { ends_on: localToday(), reason })}>{t('remote_work.end')}</button>
        </div>
      )}
      {toastNode}
    </SidePanel>
  )
}
