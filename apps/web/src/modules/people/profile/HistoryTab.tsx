'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { Timeline } from '@/ui/components/Timeline'
import { ofEntity, type Employment, type HireOptions } from '../employment'
import { EmploymentTimelineCard } from './EmploymentTimelineCard'
import { MOVEMENT_TONE, movementLines } from './movements'
import { MOVEMENT_FIELDS, MOVEMENT_KINDS, type Assignment, type MovementField, type PersonnelAction, type ProfileTabContext } from './types'

type Form = Record<MovementField, string> & { effective_on: string; kind: string; reason: string }

type Mode = { kind: 'new' } | { kind: 'edit'; action: PersonnelAction } | { kind: 'correct'; action: PersonnelAction }

/**
 * COL-5 · HIS-2 · HIS-4 · Historial de la asignación y movimientos de personal. Los datos laborales cambian
 * solo con un movimiento con fecha efectiva: con fecha futura queda programado (se edita o anula antes de su
 * fecha), los tipos que la organización somete a aprobación o lo que propone un jefe esperan el flujo, y un
 * movimiento aplicado se corrige (el original queda reemplazado, nunca se borra).
 * HIS-5 · Arriba, la línea de tiempo laboral consolidada (ingreso, movimientos, sueldo, contratos, estados y
 * cese) que arma el servidor con los permisos de cada tipo; la gestión de movimientos sigue debajo.
 */
export function HistoryTab(ctx: ProfileTabContext) {
  const { can } = useSession()
  return (
    <>
      <EmploymentTimelineCard header={ctx.header} />
      {can('people.assignments.view') && <MovementsHistory {...ctx} />}
    </>
  )
}

function MovementsHistory({ header, reload, toast }: ProfileTabContext) {
  const { t, locale } = useT()
  const { can } = useSession()
  const [rows, setRows] = useState<Assignment[] | null>(null)
  const [actions, setActions] = useState<PersonnelAction[]>([])
  const [failed, setFailed] = useState(false)
  const [mode, setMode] = useState<Mode | null>(null)
  const [options, setOptions] = useState<HireOptions | null>(null)
  const [managers, setManagers] = useState<Employment[]>([])
  const [form, setForm] = useState<Form | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [cancelling, setCancelling] = useState<PersonnelAction | null>(null)
  const [cancelReason, setCancelReason] = useState('')
  const open = ['pending', 'active', 'suspended', 'terminating'].includes(header.status)
  const canMove = (can('people.assignments.create') || can('people.assignments.propose')) && open
  const pending = actions.some((a) => ['pending_approval', 'approved', 'scheduled'].includes(a.status))

  const load = useCallback(async () => {
    try {
      const [assignments, movements] = await Promise.all([
        api.get<{ data: Assignment[] }>(`/employments/${header.id}/assignments`),
        api.get<{ data: PersonnelAction[] }>(`/employments/${header.id}/personnel-actions`),
      ])
      setRows(assignments.data)
      setActions(movements.data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [header.id])

  useEffect(() => { void load() }, [load])

  async function start(next: Mode) {
    const current = rows?.find((r) => r.valid_to === null) ?? rows?.[0]
    const base: Form = {
      effective_on: localToday(), kind: '', reason: '', position_id: current?.position_id ?? '', job_profile_id: current?.job_profile_id ?? '',
      org_unit_id: current?.org_unit_id ?? '', site_id: current?.site_id ?? '', cost_center_id: current?.cost_center_id ?? '',
      manager_employment_id: current?.manager_employment_id ?? '', work_modality: current?.work_modality ?? 'onsite',
    }
    if (next.kind === 'edit') {
      base.effective_on = next.action.effective_on
      base.reason = next.action.reason ?? ''
      for (const [field, value] of Object.entries(next.action.changes)) base[field as MovementField] = value ?? ''
    }
    setErrors({})
    setGeneral(null)
    setForm(base)
    setMode(next)
    if (!options) {
      try {
        setOptions((await api.get<{ data: HireOptions }>('/employment-options')).data)
        setManagers((await api.get<{ data: Employment[] }>('/employments?filter[status]=active&sort=person_name&per_page=100')).data.filter((m) => m.id !== header.id))
      } catch {
        setGeneral(t('common.load_error'))
      }
    }
  }

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (!form || !mode) return
    const current = rows?.find((r) => r.valid_to === null) ?? rows?.[0]
    // Solo viaja lo que cambia frente a la asignación vigente: el resto se conserva.
    const changes: Record<string, string | null> = {}
    for (const key of MOVEMENT_FIELDS) {
      const before = (current?.[key] ?? '') as string
      if (form[key] !== before) changes[key] = form[key] === '' ? null : form[key]
    }
    setBusy(true)
    setErrors({})
    setGeneral(null)
    try {
      if (mode.kind === 'correct') {
        await api.post(`/personnel-actions/${mode.action.id}/correct`, { reason: form.reason.trim(), changes })
        toast(t('mov.corrected'))
      } else if (mode.kind === 'edit') {
        await api.request(`/personnel-actions/${mode.action.id}`, 'PATCH', { effective_on: form.effective_on, reason: form.reason.trim() || null, changes })
        toast(t('mov.edited'))
      } else {
        const body: Record<string, unknown> = { effective_on: form.effective_on, changes }
        if (form.kind) body.kind = form.kind
        if (form.reason.trim()) body.reason = form.reason.trim()
        // RRHH registra; un jefe propone (siempre pasa por aprobación).
        const path = can('people.assignments.create') ? 'personnel-actions' : 'personnel-actions/propose'
        const saved = await api.post<{ data: PersonnelAction }>(`/employments/${header.id}/${path}`, body)
        toast(t(`mov.saved_${saved.data.status}`))
      }
      setMode(null)
      await load()
      await reload()
    } catch (err) {
      const f = formErrors(err, t)
      const fields: Record<string, string> = {}
      for (const [k, v] of Object.entries(f.fields)) fields[k.replace(/^changes\./, '')] = v
      setErrors(fields)
      setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  async function cancel() {
    if (!cancelling) return
    setBusy(true)
    setGeneral(null)
    try {
      await api.post(`/personnel-actions/${cancelling.id}/cancel`, { reason: cancelReason.trim() })
      setCancelling(null)
      toast(t('mov.cancelled'))
      await load()
    } catch (err) {
      const f = formErrors(err, t)
      setGeneral(f.fields.reason ?? f.general)
    } finally {
      setBusy(false)
    }
  }

  if (failed) return <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />
  if (!rows) return <span className="esq" style={{ width: 240 }} />

  const items = rows.map((r) => ({
    id: r.id,
    title: r.kind ? t(`ficha.kind_${r.kind}`) : t('ficha.kind_unknown'),
    when: r.valid_to ? t('ficha.range', { from: formatDate(locale, r.valid_from), to: formatDate(locale, r.valid_to) }) : t('ficha.since', { date: formatDate(locale, r.valid_from) }),
    text: [r.job_profile_name, r.position_title, r.org_unit_name, r.site_name, r.manager_name ? t('ficha.reports_to', { name: r.manager_name }) : null].filter(Boolean).join(' · '),
    tone: r.valid_to === null ? ('ok' as const) : ('gr' as const),
  }))

  const entity = header.legal_entity_id
  // Las opciones solo traen posiciones libres: la que ya ocupa se agrega para no perderla al guardar.
  const currentRow = rows.find((r) => r.valid_to === null) ?? rows[0]
  const free = options ? ofEntity(options.positions, entity).map((p) => ({ value: p.id, label: `${p.code} · ${p.title}` })) : []
  const positionOptions = currentRow?.position_id && !free.some((p) => p.value === currentRow.position_id)
    ? [{ value: currentRow.position_id, label: currentRow.position_title ?? currentRow.position_id }, ...free]
    : free
  const select = (key: keyof Form, label: string, list: Array<{ value: string; label: string }>, empty?: string) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`mov-${key}`}>{label}</label>
      <select id={`mov-${key}`} className={`form-select${errors[key] ? ' is-invalid' : ''}`} value={form?.[key] ?? ''}
        onChange={(e) => setForm((f) => (f ? { ...f, [key]: e.target.value } : f))}>
        {empty !== undefined && <option value="">{empty}</option>}
        {list.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )
  const titleFor = (m: Mode) => (m.kind === 'correct' ? t('mov.correct_title') : m.kind === 'edit' ? t('mov.edit_title') : t('ficha.new_movement'))

  return (
    <>
      <Card title={t('mov.title')} context={t('mov.help')}
        actions={canMove ? <button type="button" className="btn btn-primary btn-sm" disabled={pending} title={pending ? t('mov.pending_hint') : undefined} onClick={() => void start({ kind: 'new' })}>{t('ficha.new_movement')}</button> : undefined}>
        {actions.length === 0 ? <p className="text-secondary">{t('mov.empty')}</p> : (
          <ul className="mov-lista">
            {actions.map((a) => (
              <li key={a.id} className="mov-item">
                <div className="mov-cab">
                  <b>{t(`ficha.kind_${a.kind}`)}</b>
                  <StatusBadge tone={MOVEMENT_TONE[a.status]}>{t(`mov.status_${a.status}`)}</StatusBadge>
                  <span className="small text-secondary">{t('mov.effective', { date: formatDate(locale, a.effective_on) })}</span>
                </div>
                <ul className="mov-cambios small">
                  {movementLines(a, t).map((line) => <li key={line}>{line}</li>)}
                </ul>
                {a.reason && <p className="small text-secondary">{t('mov.reason', { reason: a.reason })}</p>}
                {a.is_retroactive && a.affected_from && a.affected_to && (
                  <p className="small text-secondary">{t('mov.retroactive', { from: formatDate(locale, a.affected_from), to: formatDate(locale, a.affected_to) })}</p>
                )}
                {a.status === 'pending_approval' && a.approval && a.approval.pending_approvers.length > 0 && (
                  <p className="small text-secondary">{t('mov.waiting', { names: a.approval.pending_approvers.join(', ') })}</p>
                )}
                {a.decision_reason && <p className="small text-secondary">{t('mov.decision', { reason: a.decision_reason })}</p>}
                {a.status === 'replaced' && <p className="small text-secondary">{t('mov.replaced_note')}</p>}
                <div className="mov-acciones">
                  {a.allowed_actions.includes('edit') && can('people.assignments.create') && (
                    <button type="button" className="btn btn-ter btn-sm" onClick={() => void start({ kind: 'edit', action: a })}>{t('mov.edit')}</button>
                  )}
                  {a.allowed_actions.includes('cancel') && can('people.assignments.cancel') && (
                    <button type="button" className="btn btn-ter btn-sm" onClick={() => { setCancelReason(''); setGeneral(null); setCancelling(a) }}>{t('mov.cancel')}</button>
                  )}
                  {a.allowed_actions.includes('correct') && can('people.assignments.correct') && !pending && (
                    <button type="button" className="btn btn-ter btn-sm" onClick={() => void start({ kind: 'correct', action: a })}>{t('mov.correct')}</button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <Card title={t('ficha.history_title')} context={t('ficha.history_help')}>
        {items.length === 0 ? <p className="text-secondary">{t('ficha.history_empty')}</p> : <Timeline items={items} label={t('ficha.history_title')} />}
      </Card>
      {mode && form && (
        <Modal
          title={titleFor(mode)}
          onClose={() => setMode(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setMode(null)}>{t('org.cancel')}</button>
              <button type="submit" form="form-movimiento" className="btn btn-primary" disabled={busy} aria-busy={busy}>{mode.kind === 'correct' ? t('mov.correct') : t('ficha.save_movement')}</button>
            </>
          )}
        >
          <form id="form-movimiento" onSubmit={(e) => void submit(e)} noValidate>
            <p className="small text-secondary">{mode.kind === 'correct' ? t('mov.correct_help') : t('mov.form_help')}</p>
            {general && <Notice tone="error">{general}</Notice>}
            {mode.kind !== 'correct' && (
              <div className="mb-3">
                <label className="form-label" htmlFor="mov-effective_on">{t('ficha.effective_on')}</label>
                <input id="mov-effective_on" type="date" className={`form-control${errors.effective_on ? ' is-invalid' : ''}`} value={form.effective_on}
                  onChange={(e) => setForm((f) => (f ? { ...f, effective_on: e.target.value } : f))} required />
                {errors.effective_on && <div className="invalid-feedback" role="alert">{errors.effective_on}</div>}
                {form.effective_on > localToday() && <div className="form-text">{t('mov.future_hint')}</div>}
              </div>
            )}
            {mode.kind === 'new' && select('kind', t('ficha.movement_kind'), MOVEMENT_KINDS.filter((k) => k !== 'data_correction').map((k) => ({ value: k, label: t(`ficha.kind_${k}`) })), t('ficha.kind_auto'))}
            {options && (
              <>
                {select('position_id', t('emp.f_position'), positionOptions, t('emp.no_position'))}
                {select('job_profile_id', t('emp.f_job'), options.job_profiles.map((j) => ({ value: j.id, label: j.name })))}
                {select('org_unit_id', t('emp.f_unit'), ofEntity(options.org_units, entity).map((u) => ({ value: u.id, label: u.name })))}
                {select('site_id', t('emp.f_site'), ofEntity(options.sites, entity).map((s) => ({ value: s.id, label: s.name })))}
                {select('cost_center_id', t('emp.f_cost_center'), ofEntity(options.cost_centers, entity).map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` })), t('emp.none'))}
                {select('manager_employment_id', t('emp.f_manager'), managers.map((m) => ({ value: m.id, label: `${m.person_name ?? '—'} · ${m.employee_code}` })), t('emp.manager_by_position'))}
              </>
            )}
            {select('work_modality', t('emp.f_modality'), ['onsite', 'remote', 'hybrid'].map((m) => ({ value: m, label: t(`emp.modality_${m}`) })))}
            <div className="mb-3">
              <label className="form-label" htmlFor="mov-reason">{t('ficha.reason')}</label>
              <textarea id="mov-reason" className={`form-control${errors.reason ? ' is-invalid' : ''}`} rows={2} value={form.reason}
                onChange={(e) => setForm((f) => (f ? { ...f, reason: e.target.value } : f))} required={mode.kind === 'correct'} />
              {errors.reason && <div className="invalid-feedback" role="alert">{errors.reason}</div>}
            </div>
          </form>
        </Modal>
      )}
      {cancelling && (
        <Modal
          title={t('mov.cancel_title')}
          onClose={() => setCancelling(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setCancelling(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-danger" disabled={busy} aria-busy={busy} onClick={() => void cancel()}>{t('mov.cancel_confirm')}</button>
            </>
          )}
        >
          <p>{t('mov.cancel_text')}</p>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="mov-cancel-reason">{t('ficha.reason')}</label>
          <textarea id="mov-cancel-reason" className="form-control" rows={3} value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} />
        </Modal>
      )}
    </>
  )
}
