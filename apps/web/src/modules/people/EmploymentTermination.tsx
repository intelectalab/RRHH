'use client'

import Link from 'next/link'
import { useState, type ReactNode } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { StatusBadge } from '@/ui/components/Status'
import { STATUS_TONE, type Employment, type TerminationReason } from './employment'

type Warning = { code: string; message: string }

/**
 * COL-11 · Acciones del cese en la ficha de la relación: registrar el cese (fecha, motivo de la Tabla 17,
 * último día, aviso, quién lo decide, observaciones) y revertirlo antes de su fecha. El servidor valida todo
 * (periodo cerrado, pendientes, transición) y devuelve advertencias, que aquí solo se muestran.
 */
export function TerminationActions({ employment, onChanged, onToast }: {
  employment: Employment
  onChanged: (e: Employment) => void
  onToast: (text: string, tone?: 'error') => void
}) {
  const { t } = useT()
  const { can } = useSession()
  const actions = employment.allowed_actions ?? []
  const [open, setOpen] = useState<'terminate' | 'revert' | null>(null)
  const [reasons, setReasons] = useState<TerminationReason[]>([])
  const [form, setForm] = useState({ termination_reason_id: '', termination_date: '', last_working_day: '', notice_date: '', initiated_by: '', detail: '', is_rehire_eligible: '' })
  const [revertReason, setRevertReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function openTerminate() {
    setErrors({})
    setGeneral(null)
    setForm({ termination_reason_id: '', termination_date: '', last_working_day: '', notice_date: '', initiated_by: '', detail: '', is_rehire_eligible: '' })
    setOpen('terminate')
    try {
      setReasons((await api.get<{ data: TerminationReason[] }>('/termination-reasons')).data)
    } catch {
      setGeneral(t('common.load_error'))
    }
  }

  async function submit() {
    setBusy(true)
    setErrors({})
    setGeneral(null)
    const body: Record<string, unknown> = { termination_reason_id: form.termination_reason_id, termination_date: form.termination_date }
    for (const key of ['last_working_day', 'notice_date', 'initiated_by', 'detail'] as const) {
      if (form[key] !== '') body[key] = form[key]
    }
    if (form.is_rehire_eligible !== '') body.is_rehire_eligible = form.is_rehire_eligible === 'yes'
    try {
      const res = await api.post<{ data: Employment; meta?: { warnings?: Warning[] } }>(`/employments/${employment.id}/terminate`, body)
      setOpen(null)
      onChanged(res.data)
      const warnings = res.meta?.warnings ?? []
      onToast(warnings.length > 0 ? `${t('cese.registered')} ${warnings.map((w) => w.message).join(' ')}` : t('cese.registered'))
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields)
      setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  async function revert() {
    setBusy(true)
    setErrors({})
    setGeneral(null)
    try {
      const res = await api.post<{ data: Employment }>(`/employments/${employment.id}/cancel-termination`, { reason: revertReason })
      setOpen(null)
      onChanged(res.data)
      onToast(t('cese.reverted'))
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields)
      setGeneral(f.fields.reason ?? f.general)
    } finally {
      setBusy(false)
    }
  }

  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const field = (key: keyof typeof form, label: string, input: ReactNode) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`cese-${key}`}>{label}</label>
      {input}
      {errors[key] && <div className="invalid-feedback d-block" role="alert">{errors[key]}</div>}
    </div>
  )
  const cls = (key: string, base: string) => `${base}${errors[key] ? ' is-invalid' : ''}`

  return (
    <>
      {actions.includes('terminate') && can('people.employments.terminate') && (
        <button type="button" className="btn btn-sec" onClick={() => void openTerminate()}>{t('cese.terminate')}</button>
      )}
      {actions.includes('cancel-termination') && can('people.employments.cancel_termination') && (
        <button type="button" className="btn btn-sec" onClick={() => { setRevertReason(''); setErrors({}); setGeneral(null); setOpen('revert') }}>{t('cese.revert')}</button>
      )}
      {open === 'terminate' && (
        <Modal
          title={t('cese.terminate_title')}
          size="l"
          onClose={() => setOpen(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setOpen(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-danger" disabled={busy} aria-busy={busy} onClick={() => void submit()}>{t('cese.terminate_confirm')}</button>
            </>
          )}
        >
          <p>{t('cese.terminate_text')}</p>
          {general && <Notice tone="error">{general}</Notice>}
          {field('termination_reason_id', t('cese.f_reason'), (
            <select id="cese-termination_reason_id" className={cls('termination_reason_id', 'form-select')} value={form.termination_reason_id} onChange={set('termination_reason_id')}>
              <option value="">{t('cese.choose')}</option>
              {reasons.map((r) => <option key={r.id} value={r.id}>{r.statutory_code ? `${r.statutory_code} · ${r.name}` : r.name}</option>)}
            </select>
          ))}
          <div className="cese-fechas">
            {field('termination_date', t('cese.f_date'), <input id="cese-termination_date" type="date" className={cls('termination_date', 'form-control')} value={form.termination_date} onChange={set('termination_date')} />)}
            {field('last_working_day', t('cese.f_last_day'), <input id="cese-last_working_day" type="date" className={cls('last_working_day', 'form-control')} value={form.last_working_day} onChange={set('last_working_day')} />)}
            {field('notice_date', t('cese.f_notice'), <input id="cese-notice_date" type="date" className={cls('notice_date', 'form-control')} value={form.notice_date} onChange={set('notice_date')} />)}
          </div>
          {field('initiated_by', t('cese.f_initiated_by'), (
            <select id="cese-initiated_by" className={cls('initiated_by', 'form-select')} value={form.initiated_by} onChange={set('initiated_by')}>
              <option value="">{t('cese.by_reason')}</option>
              {(['employee', 'employer', 'mutual'] as const).map((v) => <option key={v} value={v}>{t(`cese.initiated_${v}`)}</option>)}
            </select>
          ))}
          {field('is_rehire_eligible', t('cese.f_rehire'), (
            <select id="cese-is_rehire_eligible" className={cls('is_rehire_eligible', 'form-select')} value={form.is_rehire_eligible} onChange={set('is_rehire_eligible')}>
              <option value="">{t('cese.rehire_unknown')}</option>
              <option value="yes">{t('cese.rehire_yes')}</option>
              <option value="no">{t('cese.rehire_no')}</option>
            </select>
          ))}
          {field('detail', t('cese.f_detail'), <textarea id="cese-detail" className={cls('detail', 'form-control')} rows={3} value={form.detail} onChange={set('detail')} />)}
        </Modal>
      )}
      {open === 'revert' && (
        <Modal
          title={t('cese.revert_title')}
          onClose={() => setOpen(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setOpen(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void revert()}>{t('cese.revert_confirm')}</button>
            </>
          )}
        >
          <p>{t('cese.revert_text')}</p>
          <label className="form-label" htmlFor="cese-revert-reason">{t('cese.revert_reason')}</label>
          <textarea id="cese-revert-reason" className={`form-control${general ? ' is-invalid' : ''}`} rows={3} value={revertReason} onChange={(e) => setRevertReason(e.target.value)} />
          {general && <div className="invalid-feedback" role="alert">{general}</div>}
        </Modal>
      )}
    </>
  )
}

/**
 * COL-10 · COL-11 · Datos del cese registrado y las demás relaciones de la persona (reingresos y relaciones
 * simultáneas en otras empresas del grupo), para pasar de una a otra.
 */
export function TerminationAndRelations({ employment }: { employment: Employment }) {
  const { t, locale } = useT()
  const term = employment.termination
  const others = employment.other_employments ?? []
  const date = (v: string | null | undefined) => (v ? formatDate(locale, v) : '—')
  const row = (label: string, value: string | null | undefined) => <><dt>{label}</dt><dd>{value || '—'}</dd></>

  return (
    <>
      {employment.status === 'terminating' && term && (
        <Notice tone="warn" title={t('cese.terminating_title')}>{t('cese.terminating_text', { date: date(term.termination_date) })}</Notice>
      )}
      {term && (
        <Card title={t('cese.section')}>
          <dl className="ficha">
            {row(t('cese.f_reason'), term.statutory_code ? `${term.statutory_code} · ${term.reason_name}` : term.reason_name)}
            {row(t('cese.f_date'), date(term.termination_date))}
            {row(t('cese.f_last_day'), date(term.last_working_day))}
            {row(t('cese.f_notice'), date(term.notice_date))}
            {row(t('cese.f_initiated_by'), t(`cese.initiated_${term.initiated_by}`))}
            {row(t('cese.f_rehire'), term.is_rehire_eligible === null ? null : t(term.is_rehire_eligible ? 'cese.rehire_yes' : 'cese.rehire_no'))}
            {term.detail !== undefined && row(t('cese.f_detail'), term.detail)}
          </dl>
        </Card>
      )}
      {others.length > 0 && (
        <Card title={t('cese.relations')}>
          <ul className="cese-relaciones">
            {others.map((o) => (
              <li key={o.id}>
                <Link href={`/personas/colaboradores/${o.id}`}>{o.legal_entity_name} · {o.employee_code}</Link>
                <span>{date(o.hire_date)} – {o.termination_date ? date(o.termination_date) : t('cese.current')}</span>
                <StatusBadge tone={STATUS_TONE[o.status]}>{t(`emp.status_${o.status}`)}</StatusBadge>
                {o.id === employment.rehire_of_employment_id && <small>{t('cese.rehire_of')}</small>}
              </li>
            ))}
          </ul>
        </Card>
      )}
    </>
  )
}
