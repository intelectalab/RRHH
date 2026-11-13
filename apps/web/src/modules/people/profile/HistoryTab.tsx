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
import { Timeline } from '@/ui/components/Timeline'
import { ofEntity, type Employment, type HireOptions } from '../employment'
import { MOVEMENT_KINDS, type Assignment, type ProfileTabContext } from './types'

type Form = {
  valid_from: string; kind: string; reason: string; position_id: string; job_profile_id: string; org_unit_id: string
  site_id: string; cost_center_id: string; manager_employment_id: string; work_modality: string
}

/**
 * COL-5 · Historial de la asignación: cada tramo con su vigencia y el movimiento que lo abrió. Los datos
 * laborales cambian aquí, registrando un movimiento con fecha efectiva (la asignación vigente se cierra el
 * día anterior; nada se sobrescribe).
 */
export function HistoryTab({ header, reload, toast }: ProfileTabContext) {
  const { t, locale } = useT()
  const { can } = useSession()
  const [rows, setRows] = useState<Assignment[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [open, setOpen] = useState(false)
  const [options, setOptions] = useState<HireOptions | null>(null)
  const [managers, setManagers] = useState<Employment[]>([])
  const [form, setForm] = useState<Form | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const canMove = can('people.assignments.create') && can('people.employments.create') && ['pending', 'active', 'suspended', 'terminating'].includes(header.status)

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: Assignment[] }>(`/employments/${header.id}/assignments`)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [header.id])

  useEffect(() => { void load() }, [load])

  async function start() {
    const current = rows?.find((r) => r.valid_to === null) ?? rows?.[0]
    setErrors({})
    setGeneral(null)
    setForm({
      valid_from: localToday(), kind: '', reason: '', position_id: current?.position_id ?? '', job_profile_id: current?.job_profile_id ?? '',
      org_unit_id: current?.org_unit_id ?? '', site_id: current?.site_id ?? '', cost_center_id: current?.cost_center_id ?? '',
      manager_employment_id: current?.manager_employment_id ?? '', work_modality: current?.work_modality ?? 'onsite',
    })
    setOpen(true)
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
    if (!form) return
    const current = rows?.find((r) => r.valid_to === null) ?? rows?.[0]
    const body: Record<string, string | null> = { valid_from: form.valid_from }
    if (form.kind) body.kind = form.kind
    if (form.reason.trim()) body.reason = form.reason.trim()
    // Solo viaja lo que cambia: el resto se conserva de la asignación vigente.
    for (const key of ['position_id', 'job_profile_id', 'org_unit_id', 'site_id', 'cost_center_id', 'manager_employment_id', 'work_modality'] as const) {
      const before = (current?.[key] ?? '') as string
      if (form[key] !== before) body[key] = form[key] === '' ? null : form[key]
    }
    setBusy(true)
    setErrors({})
    setGeneral(null)
    try {
      await api.post(`/employments/${header.id}/assignments`, body)
      setOpen(false)
      toast(t('ficha.movement_saved'))
      await load()
      await reload()
    } catch (err) {
      const f = formErrors(err, t)
      setErrors(f.fields)
      setGeneral(f.general)
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

  return (
    <>
      <Card title={t('ficha.history_title')} context={t('ficha.history_help')}
        actions={canMove ? <button type="button" className="btn btn-primary btn-sm" onClick={() => void start()}>{t('ficha.new_movement')}</button> : undefined}>
        {items.length === 0 ? <p className="text-secondary">{t('ficha.history_empty')}</p> : <Timeline items={items} label={t('ficha.history_title')} />}
      </Card>
      {open && form && (
        <Modal
          title={t('ficha.new_movement')}
          onClose={() => setOpen(false)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setOpen(false)}>{t('org.cancel')}</button>
              <button type="submit" form="form-movimiento" className="btn btn-primary" disabled={busy} aria-busy={busy}>{t('ficha.save_movement')}</button>
            </>
          )}
        >
          <form id="form-movimiento" onSubmit={(e) => void submit(e)} noValidate>
            <p className="small text-secondary">{t('ficha.movement_help')}</p>
            {general && <Notice tone="error">{general}</Notice>}
            <div className="mb-3">
              <label className="form-label" htmlFor="mov-valid_from">{t('ficha.effective_on')}</label>
              <input id="mov-valid_from" type="date" className={`form-control${errors.valid_from ? ' is-invalid' : ''}`} value={form.valid_from}
                onChange={(e) => setForm((f) => (f ? { ...f, valid_from: e.target.value } : f))} required />
              {errors.valid_from && <div className="invalid-feedback" role="alert">{errors.valid_from}</div>}
            </div>
            {select('kind', t('ficha.movement_kind'), MOVEMENT_KINDS.map((k) => ({ value: k, label: t(`ficha.kind_${k}`) })), t('ficha.kind_auto'))}
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
              <textarea id="mov-reason" className="form-control" rows={2} value={form.reason} onChange={(e) => setForm((f) => (f ? { ...f, reason: e.target.value } : f))} />
            </div>
          </form>
        </Modal>
      )}
    </>
  )
}
