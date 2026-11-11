'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useCompany } from '@/modules/session/CompanyProvider'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Duration = { parameter_code: string; value: string | number | null; unit: string | null; pending_validation: boolean; valid_from: string | null }
type Rules = {
  id: string; is_paid: boolean; deducts_pay: boolean; affects_attendance: boolean; unit: 'days' | 'hours'; max_quantity: string | null
  max_period: 'event' | 'month' | 'year'; min_notice_days: number | null; requires_document: boolean; document_grace_days: number | null
  requestable_by: 'employee' | 'manager' | 'hr'; payroll_concept_code: string | null; counts_as_worked_for_benefits: boolean
  legal_entity_ids: string[] | null; valid_from: string; valid_to: string | null
}
type AbsenceType = {
  id: string; code: string; name: string; category: string; is_system: boolean; is_confidential: boolean
  status: 'active' | 'archived'; legal_duration: Duration | null; rules: Rules | null
}
const CATEGORIES = ['medical_leave', 'maternity', 'paternity', 'personal', 'bereavement', 'union', 'compensatory', 'remote_work', 'unpaid', 'suspension', 'other'] as const
const REQUESTERS = ['employee', 'manager', 'hr'] as const
const PERIODS = ['event', 'month', 'year'] as const

/** Configuración → Tipos de ausencia (AUS-1). Las reglas cambian con vigencia; la API resuelve la fecha. */
export function AbsenceTypesScreen() {
  const { t } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [asOf, setAsOf] = useState(localToday())
  const [rows, setRows] = useState<AbsenceType[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [editing, setEditing] = useState<AbsenceType | 'new' | null>(null)
  const [archiving, setArchiving] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: AbsenceType[] }>(`/absence-types?as_of=${encodeURIComponent(asOf)}`)).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [asOf])
  useEffect(() => { void load() }, [load])

  async function archive(id: string) {
    try {
      await api.post(`/absence-types/${id}/archive`, {})
      toast(t('atype.archived'))
      await load()
    } catch (err) {
      toast(formErrors(err, t).general ?? t('common.load_error'))
    } finally {
      setArchiving(null)
    }
  }

  const canUpdate = can('time.absence_types.update')
  const canArchive = can('time.absence_types.archive')

  return (
    <>
      <PageHeader title={t('atype.title')} sub={t('atype.sub')} actions={<>
        <Link className="btn btn-sec" href="/configuracion">{t('cfg.back')}</Link>
        {can('time.absence_types.create') && <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('atype.new')}</button>}
      </>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="nota">{t('atype.help')}</div>
        <section className="card">
          <div className="param-form" style={{ margin: 16, maxWidth: 240 }}>
            <label className="form-label" htmlFor="at-as-of">{t('atype.as_of')}</label>
            <input id="at-as-of" type="date" className="form-control" value={asOf} onChange={(e) => e.target.value && setAsOf(e.target.value)} />
          </div>
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr>
                <th>{t('atype.name')}</th><th>{t('atype.category')}</th><th>{t('atype.pay')}</th><th>{t('atype.limit')}</th>
                <th>{t('atype.document')}</th><th>{t('atype.requester')}</th><th>{t('atype.legal_duration')}</th><th>{t('atype.origin')}</th><th><span className="visually-hidden">{t('atype.actions')}</span></th>
              </tr></thead>
              <tbody>
                {loadError && <tr><td colSpan={9}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                {!loadError && rows === null && <tr><td colSpan={9}><span className="esq" style={{ width: '40%' }} /></td></tr>}
                {rows?.length === 0 && <tr><td colSpan={9}><EmptyState title={t('atype.empty_title')} text={t('atype.empty_text')} /></td></tr>}
                {rows?.map((r) => (
                  <tr key={r.id}>
                    <td>
                      <b>{r.name}</b>
                      <span className="sub2">{r.code}{r.is_confidential ? ` · ${t('atype.confidential')}` : ''}</span>
                      {r.status === 'archived' && <span className="badge text-bg-secondary">{t('atype.status_archived')}</span>}
                    </td>
                    <td>{t(`atype.cat_${r.category}`)}</td>
                    <td>{r.rules === null ? '—' : t(r.rules.is_paid ? 'atype.paid' : 'atype.unpaid')}</td>
                    <td>{r.rules === null ? '—' : <LimitCell rules={r.rules} />}</td>
                    <td>{r.rules === null ? '—' : t(r.rules.requires_document ? 'atype.yes' : 'atype.no')}</td>
                    <td>{r.rules === null ? '—' : t(`atype.req_${r.rules.requestable_by}`)}</td>
                    <td><DurationCell duration={r.legal_duration} /></td>
                    <td>{t(r.is_system ? 'atype.origin_system' : 'atype.origin_own')}</td>
                    <td style={{ whiteSpace: 'nowrap' }}>
                      {r.status === 'active' && canUpdate && <button type="button" className="btn btn-sec btn-sm" onClick={() => setEditing(r)}>{t('atype.edit')}</button>}
                      {r.status === 'active' && canArchive && (archiving === r.id
                        ? <><button type="button" className="btn btn-pel-sec btn-sm" style={{ marginInlineStart: 8 }} onClick={() => void archive(r.id)}>{t('atype.archive_confirm')}</button>
                          <button type="button" className="btn btn-sec btn-sm" style={{ marginInlineStart: 8 }} onClick={() => setArchiving(null)}>{t('atype.cancel')}</button></>
                        : <button type="button" className="btn btn-sec btn-sm" style={{ marginInlineStart: 8 }} onClick={() => setArchiving(r.id)}>{t('atype.archive')}</button>)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {editing !== null && (
        <TypeForm type={editing === 'new' ? null : editing} onClose={() => setEditing(null)}
          onDone={async (msg) => { setEditing(null); toast(t(msg)); await load() }} />
      )}
      {toastNode}
    </>
  )
}

function LimitCell({ rules }: { rules: Rules }) {
  const { t } = useT()
  if (rules.max_quantity === null) return <span className="sub2">{t('atype.no_limit')}</span>
  return <span>{t(`atype.limit_${rules.unit}_${rules.max_period}`, { n: rules.max_quantity })}</span>
}

function DurationCell({ duration }: { duration: Duration | null }) {
  const { t, locale } = useT()
  if (duration === null) return <span className="sub2">{t('atype.no_parameter')}</span>
  return (
    <>
      {duration.value !== null && <span>{t('atype.days', { n: String(duration.value) })}</span>}
      {duration.pending_validation && <span className="badge text-bg-warning" style={{ marginInlineStart: duration.value !== null ? 8 : 0 }}>{t('atype.pending')}</span>}
      <span className="sub2">{duration.parameter_code}{duration.valid_from ? ` · ${formatDate(locale, duration.valid_from)}` : ''}</span>
    </>
  )
}

type Form = {
  code: string; name: string; category: string; effective_from: string
  is_paid: boolean; affects_attendance: boolean; unit: 'days' | 'hours'; max_quantity: string; max_period: 'event' | 'month' | 'year'
  min_notice_days: string; requires_document: boolean; document_grace_days: string; requestable_by: 'employee' | 'manager' | 'hr'
  payroll_concept_code: string; counts_as_worked_for_benefits: boolean; legal_entity_ids: string[]
}

function initial(type: AbsenceType | null): Form {
  const r = type?.rules
  return {
    code: type?.code ?? '', name: type?.name ?? '', category: type?.category ?? 'other', effective_from: localToday(),
    is_paid: r?.is_paid ?? true, affects_attendance: r?.affects_attendance ?? true, unit: r?.unit ?? 'days',
    max_quantity: r?.max_quantity ?? '', max_period: r?.max_period ?? 'year', min_notice_days: r?.min_notice_days?.toString() ?? '',
    requires_document: r?.requires_document ?? false, document_grace_days: r?.document_grace_days?.toString() ?? '',
    requestable_by: r?.requestable_by ?? 'employee', payroll_concept_code: r?.payroll_concept_code ?? '',
    counts_as_worked_for_benefits: r?.counts_as_worked_for_benefits ?? true, legal_entity_ids: r?.legal_entity_ids ?? [],
  }
}

function TypeForm({ type, onClose, onDone }: { type: AbsenceType | null; onClose: () => void; onDone: (msg: string) => Promise<void> }) {
  const { t } = useT()
  const { companies } = useCompany()
  const [form, setForm] = useState<Form>(() => initial(type))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const ownIdentity = type === null || !type.is_system
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => ({ ...f, [k]: v }))
  const num = (v: string) => (v.trim() === '' ? null : Number(v))

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const rules = {
      is_paid: form.is_paid, affects_attendance: form.affects_attendance, unit: form.unit, max_quantity: num(form.max_quantity),
      max_period: form.max_period, min_notice_days: num(form.min_notice_days), requires_document: form.requires_document,
      document_grace_days: num(form.document_grace_days), requestable_by: form.requestable_by,
      payroll_concept_code: form.payroll_concept_code.trim() || null, counts_as_worked_for_benefits: form.counts_as_worked_for_benefits,
      legal_entity_ids: form.legal_entity_ids.length ? form.legal_entity_ids : null,
    }
    const identity = ownIdentity ? { name: form.name, category: form.category, ...(type === null ? { code: form.code } : {}) } : {}
    try {
      if (type === null) await api.post('/absence-types', { ...identity, effective_from: form.effective_from, rules })
      else await api.request(`/absence-types/${type.id}`, 'PATCH', { ...identity, effective_from: form.effective_from, rules })
      await onDone(type === null ? 'atype.created' : 'atype.saved')
    } catch (err) {
      const r = formErrors(err, t)
      const fields: Record<string, string> = {}
      for (const [k, v] of Object.entries(r.fields)) fields[k.replace(/^rules\./, '').replace(/\.\d+$/, '')] = v
      setErrors(fields)
      setGeneral(r.general)
    }
  }

  const field = (k: 'code' | 'name' | 'max_quantity' | 'min_notice_days' | 'document_grace_days' | 'payroll_concept_code', label: string, kind: 'text' | 'number' = 'text') => (
    <div><label className="form-label" htmlFor={`at-${k}`}>{label}</label>
      <input id={`at-${k}`} type={kind} min={kind === 'number' ? 0 : undefined} className={`form-control${errors[k] ? ' is-invalid' : ''}`} value={form[k]} onChange={(e) => set(k, e.target.value)} />
      {errors[k] && <div className="invalid-feedback" role="alert">{errors[k]}</div>}</div>
  )
  const check = (k: 'is_paid' | 'affects_attendance' | 'requires_document' | 'counts_as_worked_for_benefits', label: string) => (
    <div className="form-check"><input id={`at-${k}`} type="checkbox" className="form-check-input" checked={form[k]} onChange={(e) => set(k, e.target.checked)} />
      <label className="form-check-label" htmlFor={`at-${k}`}>{label}</label></div>
  )

  return (
    <SidePanel title={t(type === null ? 'atype.new' : 'atype.edit_title', { name: type?.name ?? '' })} onClose={onClose} closeLabel={t('atype.cancel')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('atype.cancel')}</button>
        <button type="submit" form="at-form" className="btn btn-primary">{t('atype.save')}</button>
      </>}>
      <form id="at-form" className="param-form" onSubmit={submit} noValidate>
        {general && <div className="alert alert-danger" role="alert">{general}</div>}
        {type?.is_system && <div className="nota">{t('atype.system_note')}</div>}
        <div className="grid-param" style={{ gridTemplateColumns: '1fr 1fr' }}>
          {type === null && field('code', t('atype.code'))}
          {ownIdentity && field('name', t('atype.name'))}
          {ownIdentity && (
            <div><label className="form-label" htmlFor="at-category">{t('atype.category')}</label>
              <select id="at-category" className="form-select" value={form.category} onChange={(e) => set('category', e.target.value)}>
                {CATEGORIES.map((c) => <option key={c} value={c}>{t(`atype.cat_${c}`)}</option>)}
              </select></div>
          )}
          <div><label className="form-label" htmlFor="at-from">{t('atype.effective_from')}</label>
            <input id="at-from" type="date" className={`form-control${errors.effective_from ? ' is-invalid' : ''}`} value={form.effective_from} onChange={(e) => e.target.value && set('effective_from', e.target.value)} />
            {errors.effective_from && <div className="invalid-feedback" role="alert">{errors.effective_from}</div>}</div>
          <div><label className="form-label" htmlFor="at-unit">{t('atype.unit')}</label>
            <select id="at-unit" className="form-select" value={form.unit} onChange={(e) => set('unit', e.target.value as Form['unit'])}>
              <option value="days">{t('atype.unit_days')}</option><option value="hours">{t('atype.unit_hours')}</option>
            </select></div>
          {field('max_quantity', t('atype.max_quantity'), 'number')}
          <div><label className="form-label" htmlFor="at-period">{t('atype.max_period')}</label>
            <select id="at-period" className="form-select" value={form.max_period} onChange={(e) => set('max_period', e.target.value as Form['max_period'])}>
              {PERIODS.map((p) => <option key={p} value={p}>{t(`atype.period_${p}`)}</option>)}
            </select></div>
          {field('min_notice_days', t('atype.min_notice'), 'number')}
          <div><label className="form-label" htmlFor="at-req">{t('atype.requester')}</label>
            <select id="at-req" className="form-select" value={form.requestable_by} onChange={(e) => set('requestable_by', e.target.value as Form['requestable_by'])}>
              {REQUESTERS.map((p) => <option key={p} value={p}>{t(`atype.req_${p}`)}</option>)}
            </select></div>
          {field('payroll_concept_code', t('atype.concept'))}
          {field('document_grace_days', t('atype.grace'), 'number')}
          {check('is_paid', t('atype.paid'))}
          {check('requires_document', t('atype.document'))}
          {check('affects_attendance', t('atype.attendance'))}
          {check('counts_as_worked_for_benefits', t('atype.benefits'))}
        </div>
        {companies.length > 1 && (
          <fieldset style={{ marginTop: 16 }}>
            <legend className="form-label">{t('atype.companies')}</legend>
            <span className="sub2">{t('atype.companies_help')}</span>
            {companies.map((c) => (
              <div className="form-check" key={c.id}>
                <input id={`at-le-${c.id}`} type="checkbox" className="form-check-input" checked={form.legal_entity_ids.includes(c.id)}
                  onChange={(e) => set('legal_entity_ids', e.target.checked ? [...form.legal_entity_ids, c.id] : form.legal_entity_ids.filter((x) => x !== c.id))} />
                <label className="form-check-label" htmlFor={`at-le-${c.id}`}>{c.name}</label>
              </div>
            ))}
            {errors.legal_entity_ids && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{errors.legal_entity_ids}</div>}
          </fieldset>
        )}
      </form>
    </SidePanel>
  )
}
