'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { ErrorState } from '@/ui/components/States'
import { ofEntity, type Employment, type HireOptions } from './employment'
import { peopleTabs } from './peopleTabs'

type Match = { exists: boolean; document_number_masked: string; person: null | { id: string; full_name: string; employments: Array<{ id: string; legal_entity_name: string; employee_code: string; hire_date: string; termination_date: string | null; status: string }> } }
type Step = 'document' | 'person' | 'job' | 'contract' | 'review'
type Manager = { id: string; person_name: string | null; employee_code: string }

const STEPS: Step[] = ['document', 'person', 'job', 'contract', 'review']
/** A qué paso vuelve el formulario si la API observa un campo. */
const FIELD_STEP: Record<string, Step> = {
  document_type: 'document', document_number: 'document', person_id: 'document',
  first_names: 'person', paternal_surname: 'person', maternal_surname: 'person', birth_date: 'person', sex: 'person', personal_email: 'person', mobile_phone: 'person', person: 'person',
  contract_type_id: 'contract', contract_end_date: 'contract', contract_objective_cause: 'contract', base_salary: 'contract', salary_amount: 'contract', pay_frequency: 'contract',
}

const EMPTY = {
  document_type: 'dni', document_number: '',
  first_names: '', paternal_surname: '', maternal_surname: '', birth_date: '', sex: '', personal_email: '', mobile_phone: '',
  legal_entity_id: '', hire_date: '', employee_code: '', kind: 'employee', worker_category: 'employee',
  position_id: '', job_profile_id: '', org_unit_id: '', site_id: '', cost_center_id: '', manager_employment_id: '', work_modality: 'onsite',
  labor_regime_code: '', work_email: '', work_phone: '', work_phone_ext: '',
  contract_type_id: '', contract_end_date: '', contract_objective_cause: '', salary_amount: '', salary_currency: 'PEN', pay_frequency: 'monthly',
}
type Form = typeof EMPTY

/**
 * COL-2 · Registrar ingreso, por pasos: documento (evita duplicados, RN-COL-01), persona, datos laborales
 * (con la posición se completan puesto, unidad, sede y centro), contrato y sueldo, y resumen. Las reglas
 * (límite del plan, posición libre, régimen, contrato) las valida la API; aquí solo se guía.
 */
export function HireScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const router = useRouter()
  const [options, setOptions] = useState<HireOptions | null>(null)
  const [failed, setFailed] = useState(false)
  const [managers, setManagers] = useState<Manager[]>([])
  const [form, setForm] = useState<Form>({ ...EMPTY, hire_date: localToday() })
  const [step, setStep] = useState<Step>('document')
  const [match, setMatch] = useState<Match | null>(null)
  const [personId, setPersonId] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const canPersonal = can('people.people.personal_data.edit')
  const canSalary = can('people.employments.salary.edit')

  useEffect(() => {
    api.get<{ data: HireOptions }>('/employment-options').then((r) => {
      setOptions(r.data)
      const first = r.data.legal_entities[0]
      if (first) setForm((f) => ({ ...f, legal_entity_id: f.legal_entity_id || first.id, salary_currency: first.currency }))
    }).catch(() => setFailed(true))
    api.get<{ data: Manager[] }>('/employments?filter[status]=active&sort=person_name&per_page=100').then((r) => setManagers(r.data)).catch(() => setManagers([]))
  }, [])

  const entity = form.legal_entity_id
  const positions = useMemo(() => (options ? options.positions.filter((p) => p.legal_entity_id === entity) : []), [options, entity])
  const contractType = options?.contract_types.find((c) => c.id === form.contract_type_id)
  const entityRegime = options?.entity_regimes.find((r) => r.legal_entity_id === entity)?.labor_regime_code ?? ''

  const set = (key: keyof Form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  function choosePosition(id: string) {
    const p = positions.find((x) => x.id === id)
    setForm((f) => ({
      ...f, position_id: id,
      ...(p ? { job_profile_id: p.job_profile_id, org_unit_id: p.org_unit_id, site_id: p.site_id ?? f.site_id, cost_center_id: p.cost_center_id ?? f.cost_center_id } : {}),
    }))
  }

  function chooseEntity(id: string) {
    const currency = options?.legal_entities.find((e) => e.id === id)?.currency
    setForm((f) => ({ ...f, legal_entity_id: id, position_id: '', labor_regime_code: '', ...(currency ? { salary_currency: currency } : {}) }))
  }

  async function searchDocument(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setGeneral(null)
    try {
      const params = new URLSearchParams({ document_type: form.document_type, document_number: form.document_number })
      const res = await api.get<{ data: Match }>(`/people/document-match?${params.toString()}`)
      setMatch(res.data)
      if (!res.data.exists) {
        setPersonId(null)
        setStep('person')
      }
    } catch (err) {
      const f = formErrors(err, t)
      setErrors(f.fields)
      setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  function next(to: Step) {
    setErrors({})
    setGeneral(null)
    setStep(to)
  }

  function body(): Record<string, unknown> {
    const opt = (v: string) => (v.trim() === '' ? undefined : v.trim())
    return {
      ...(personId ? { person_id: personId } : {
        person: {
          document_type: form.document_type, document_number: form.document_number,
          first_names: form.first_names, paternal_surname: form.paternal_surname, maternal_surname: opt(form.maternal_surname),
          ...(canPersonal ? { birth_date: opt(form.birth_date), sex: opt(form.sex), personal_email: opt(form.personal_email), mobile_phone: opt(form.mobile_phone) } : {}),
        },
      }),
      legal_entity_id: form.legal_entity_id, hire_date: form.hire_date, employee_code: opt(form.employee_code),
      kind: form.kind, worker_category: form.worker_category,
      position_id: opt(form.position_id), job_profile_id: opt(form.job_profile_id), org_unit_id: opt(form.org_unit_id), site_id: opt(form.site_id),
      cost_center_id: opt(form.cost_center_id), manager_employment_id: opt(form.manager_employment_id), work_modality: form.work_modality,
      labor_regime_code: opt(form.labor_regime_code), work_email: opt(form.work_email), work_phone: opt(form.work_phone), work_phone_ext: opt(form.work_phone_ext),
      contract_type_id: form.contract_type_id, contract_end_date: opt(form.contract_end_date), contract_objective_cause: opt(form.contract_objective_cause),
      ...(canSalary && form.salary_amount.trim() !== '' ? { base_salary: { amount: form.salary_amount.trim(), currency: form.salary_currency }, pay_frequency: form.pay_frequency } : {}),
    }
  }

  async function submit() {
    setBusy(true)
    setErrors({})
    setGeneral(null)
    try {
      const res = await api.post<{ data: Employment }>('/employments', body())
      router.push(`/personas/colaboradores/${res.data.id}`)
    } catch (err) {
      const f = formErrors(err, t)
      const fields = Object.fromEntries(Object.entries(f.fields).map(([k, v]) => [k.replace(/^person\./, '').replace(/^base_salary.*/, 'salary_amount'), v]))
      setErrors(fields)
      setGeneral(f.general ?? (err instanceof ApiRequestError && Object.keys(fields).length > 0 ? t('emp.review_errors') : null))
      const firstField = Object.keys(fields)[0]
      if (firstField) setStep(FIELD_STEP[firstField] ?? 'job')
      setBusy(false)
    }
  }

  if (failed) return <main className="main" id="contenido"><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => window.location.reload()} /></main>
  if (!options) return <main className="main" id="contenido" aria-busy="true"><span className="esq" style={{ width: 240 }} /></main>

  const field = (key: string, label: string, input: ReactNode, help?: string) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`h-${key}`}>{label}</label>
      {input}
      {help && <p className="small text-secondary">{help}</p>}
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )
  const text = (key: keyof Form, extra: Record<string, string> = {}) => (
    <input id={`h-${key}`} className={`form-control${errors[key] ? ' is-invalid' : ''}`} value={form[key]} onChange={set(key)} {...extra} />
  )
  const select = (key: keyof Form, items: Array<{ value: string; label: string }>, empty?: string, onChange?: (v: string) => void) => (
    <select id={`h-${key}`} className={`form-select${errors[key] ? ' is-invalid' : ''}`} value={form[key]} onChange={(e) => (onChange ? onChange(e.target.value) : set(key)(e))}>
      {empty !== undefined && <option value="">{empty}</option>}
      {items.map((i) => <option key={i.value} value={i.value}>{i.label}</option>)}
    </select>
  )
  const nav = (back: Step | null, forward: ReactNode) => (
    <div className="d-flex gap-2 mt-3">
      {back && <button type="button" className="btn btn-sec" onClick={() => next(back)}>{t('common.previous')}</button>}
      {forward}
    </div>
  )
  const nextButton = (to: Step) => <button type="button" className="btn btn-primary" onClick={() => next(to)}>{t('common.next')}</button>
  const name = (list: Array<{ id: string; name?: string; title?: string }>, id: string) => {
    const item = list.find((x) => x.id === id)
    return item ? (item.name ?? item.title ?? '—') : '—'
  }
  const stepIndex = STEPS.indexOf(step)

  return (
    <>
      <PageHeader title={t('emp.hire_title')} sub={t('emp.hire_sub')} tabs={peopleTabs(t, 'employees', can)}
        actions={<Link className="btn btn-sec" href="/personas/colaboradores"><Icon name="chevronLeft" /> {t('emp.back')}</Link>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <ol className="pasos" aria-label={t('emp.steps')}>
          {STEPS.map((s, i) => (
            <li key={s} className={i === stepIndex ? 'on' : i < stepIndex ? 'hecho' : undefined} aria-current={i === stepIndex ? 'step' : undefined}>
              {t(`emp.step_${s}`)}
            </li>
          ))}
        </ol>
        {general && <Notice tone="error">{general}</Notice>}

        {step === 'document' && (
          <Card title={t('emp.step_document')}>
            <form onSubmit={searchDocument} noValidate>
              <p className="sub2">{t('emp.document_help')}</p>
              <div className="row g-2">
                <div className="col-md-4">{field('document_type', t('emp.f_document_type'), select('document_type', options.document_types.map((d) => ({ value: d, label: t(`emp.doc_${d}`) }))))}</div>
                <div className="col-md-8">{field('document_number', t('emp.f_document_number'), text('document_number', { inputMode: form.document_type === 'dni' ? 'numeric' : 'text', autoComplete: 'off' }))}</div>
              </div>
              <button type="submit" className="btn btn-primary" disabled={busy || form.document_number.trim() === ''} aria-busy={busy}>{t('emp.search_person')}</button>
            </form>
            {match?.exists && match.person && (
              <div className="mt-3">
                <Notice tone="warn" title={t('emp.person_exists_title', { name: match.person.full_name })}>
                  {t('emp.person_exists_text', { doc: match.document_number_masked })}
                </Notice>
                <ul className="lista-simple">
                  {match.person.employments.map((e) => (
                    <li key={e.id}>{t('emp.existing_relation', { company: e.legal_entity_name, code: e.employee_code, from: formatDate(locale, e.hire_date), status: t(`emp.status_${e.status}`) })}</li>
                  ))}
                </ul>
                <button type="button" className="btn btn-primary" onClick={() => { setPersonId(match.person?.id ?? null); next('job') }}>{t('emp.use_person')}</button>
              </div>
            )}
          </Card>
        )}

        {step === 'person' && (
          <Card title={t('emp.step_person')}>
            {field('first_names', t('emp.f_first_names'), text('first_names', { autoComplete: 'given-name' }))}
            <div className="row g-2">
              <div className="col-md-6">{field('paternal_surname', t('emp.f_paternal'), text('paternal_surname', { autoComplete: 'family-name' }))}</div>
              <div className="col-md-6">{field('maternal_surname', t('emp.f_maternal'), text('maternal_surname'))}</div>
            </div>
            {canPersonal ? (
              <fieldset className="cf-grupo">
                <legend>{t('emp.personal_data')}</legend>
                <div className="row g-2">
                  <div className="col-md-6">{field('birth_date', t('emp.f_birth_date'), text('birth_date', { type: 'date' }))}</div>
                  {options.rules.register_sex && <div className="col-md-6">{field('sex', t('emp.f_sex'), select('sex', ['female', 'male', 'unspecified'].map((v) => ({ value: v, label: t(`emp.sex_${v}`) })), t('emp.not_specified')))}</div>}
                </div>
                <div className="row g-2">
                  <div className="col-md-6">{field('personal_email', t('emp.f_personal_email'), text('personal_email', { type: 'email', autoComplete: 'off' }))}</div>
                  <div className="col-md-6">{field('mobile_phone', t('emp.f_mobile'), text('mobile_phone', { inputMode: 'tel', autoComplete: 'off' }))}</div>
                </div>
              </fieldset>
            ) : <p className="sub2">{t('emp.personal_data_hidden')}</p>}
            {nav('document', nextButton('job'))}
          </Card>
        )}

        {step === 'job' && (
          <Card title={t('emp.step_job')}>
            <div className="row g-2">
              <div className="col-md-6">{field('legal_entity_id', t('emp.f_company'), select('legal_entity_id', options.legal_entities.map((e) => ({ value: e.id, label: e.name })), undefined, chooseEntity))}</div>
              <div className="col-md-3">{field('hire_date', t('emp.f_hire_date'), text('hire_date', { type: 'date' }), form.hire_date > localToday() ? t('emp.future_help') : undefined)}</div>
              <div className="col-md-3">{field('employee_code', t('emp.f_code'), text('employee_code', { maxLength: '20' }), options.rules.automatic_code ? t('emp.code_auto') : undefined)}</div>
            </div>
            {field('position_id', t('emp.f_position'), select('position_id', positions.map((p) => ({ value: p.id, label: `${p.code} · ${p.title}` })), options.rules.position_required ? t('emp.choose') : t('emp.no_position'), choosePosition),
              options.rules.position_required ? t('emp.position_required_help') : t('emp.position_help'))}
            <div className="row g-2">
              <div className="col-md-6">{field('job_profile_id', t('emp.f_job'), select('job_profile_id', options.job_profiles.map((j) => ({ value: j.id, label: j.name })), t('emp.choose')))}</div>
              <div className="col-md-6">{field('org_unit_id', t('emp.f_unit'), select('org_unit_id', ofEntity(options.org_units, entity).map((u) => ({ value: u.id, label: u.name })), t('emp.choose')))}</div>
              <div className="col-md-6">{field('site_id', t('emp.f_site'), select('site_id', ofEntity(options.sites, entity).map((s) => ({ value: s.id, label: s.name })), t('emp.choose')))}</div>
              <div className="col-md-6">{field('cost_center_id', t('emp.f_cost_center'), select('cost_center_id', ofEntity(options.cost_centers, entity).map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` })), t('emp.none')))}</div>
              <div className="col-md-6">{field('manager_employment_id', t('emp.f_manager'), select('manager_employment_id', managers.map((m) => ({ value: m.id, label: `${m.person_name ?? '—'} · ${m.employee_code}` })), t('emp.manager_by_position')))}</div>
              <div className="col-md-6">{field('work_modality', t('emp.f_modality'), select('work_modality', ['onsite', 'remote', 'hybrid'].map((m) => ({ value: m, label: t(`emp.modality_${m}`) }))))}</div>
              <div className="col-md-6">{field('kind', t('emp.f_kind'), select('kind', ['employee', 'intern', 'apprentice'].map((k) => ({ value: k, label: t(`emp.kind_${k}`) }))))}</div>
              <div className="col-md-6">{field('worker_category', t('emp.f_category'), select('worker_category', ['executive', 'employee', 'worker'].map((k) => ({ value: k, label: t(`emp.category_${k}`) }))))}</div>
              <div className="col-md-6">{field('labor_regime_code', t('emp.f_regime'), select('labor_regime_code', options.labor_regimes.map((r) => ({ value: r.code, label: r.name })),
                entityRegime ? t('emp.regime_of_company', { name: options.labor_regimes.find((r) => r.code === entityRegime)?.name ?? entityRegime }) : t('emp.choose')))}</div>
            </div>
            <fieldset className="cf-grupo">
              <legend>{t('emp.work_contact')}</legend>
              <div className="row g-2">
                <div className="col-md-6">{field('work_email', t('emp.f_work_email'), text('work_email', { type: 'email' }))}</div>
                <div className="col-md-4">{field('work_phone', t('emp.f_work_phone'), text('work_phone', { inputMode: 'tel' }))}</div>
                <div className="col-md-2">{field('work_phone_ext', t('emp.f_work_ext'), text('work_phone_ext', { inputMode: 'numeric' }))}</div>
              </div>
            </fieldset>
            {nav(personId ? 'document' : 'person', nextButton('contract'))}
          </Card>
        )}

        {step === 'contract' && (
          <Card title={t('emp.step_contract')}>
            {field('contract_type_id', t('emp.f_contract_type'), select('contract_type_id', options.contract_types.map((c) => ({ value: c.id, label: c.name })), t('emp.choose')), t('emp.contract_help'))}
            {contractType?.requires_end_date && field('contract_end_date', t('emp.f_contract_end'), text('contract_end_date', { type: 'date' }))}
            {contractType?.requires_objective_cause && field('contract_objective_cause', t('emp.f_contract_cause'), (
              <textarea id="h-contract_objective_cause" className={`form-control${errors.contract_objective_cause ? ' is-invalid' : ''}`} rows={3} value={form.contract_objective_cause} onChange={set('contract_objective_cause')} />
            ))}
            {canSalary ? (
              <fieldset className="cf-grupo">
                <legend>{t('emp.salary')}</legend>
                <div className="row g-2">
                  <div className="col-md-5">{field('salary_amount', t('emp.f_salary'), text('salary_amount', { inputMode: 'decimal', placeholder: '0.00' }), t('emp.salary_help'))}</div>
                  <div className="col-md-3">{field('salary_currency', t('emp.f_currency'), text('salary_currency', { maxLength: '3' }))}</div>
                  <div className="col-md-4">{field('pay_frequency', t('emp.f_frequency'), select('pay_frequency', ['monthly', 'biweekly', 'weekly', 'daily', 'hourly'].map((p) => ({ value: p, label: t(`emp.freq_${p}`) }))))}</div>
                </div>
              </fieldset>
            ) : <p className="sub2">{t('emp.salary_hidden')}</p>}
            {nav('job', nextButton('review'))}
          </Card>
        )}

        {step === 'review' && (
          <Card title={t('emp.step_review')}>
            <dl className="ficha">
              <dt>{t('emp.f_person')}</dt><dd>{personId ? (match?.person?.full_name ?? '—') : [form.first_names, form.paternal_surname, form.maternal_surname].filter(Boolean).join(' ')}</dd>
              <dt>{t('emp.f_document')}</dt><dd>{`${t(`emp.doc_${form.document_type}`)} ${form.document_number}`}</dd>
              <dt>{t('emp.f_company')}</dt><dd>{name(options.legal_entities, form.legal_entity_id)}</dd>
              <dt>{t('emp.f_hire_date')}</dt><dd>{form.hire_date ? formatDate(locale, form.hire_date) : '—'}</dd>
              <dt>{t('emp.f_position')}</dt><dd>{form.position_id ? name(positions, form.position_id) : t('emp.no_position')}</dd>
              <dt>{t('emp.f_job')}</dt><dd>{name(options.job_profiles, form.job_profile_id)}</dd>
              <dt>{t('emp.f_unit')}</dt><dd>{name(options.org_units, form.org_unit_id)}</dd>
              <dt>{t('emp.f_site')}</dt><dd>{name(options.sites, form.site_id)}</dd>
              <dt>{t('emp.f_contract_type')}</dt><dd>{contractType?.name ?? '—'}</dd>
            </dl>
            <p className="sub2 mt-3">{form.hire_date > localToday() ? t('emp.review_pending') : t('emp.review_active')}</p>
            {nav('contract', <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void submit()}>{busy && <span className="spin" aria-hidden="true" />}{t('emp.submit')}</button>)}
          </Card>
        )}
      </main>
    </>
  )
}
