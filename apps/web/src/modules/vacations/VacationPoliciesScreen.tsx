'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, formatNumber, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useCompany } from '@/modules/session/CompanyProvider'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Rules = {
  id: string; days_per_year: string | null; counts_calendar_days: boolean; min_days_per_request: string | null
  allow_advance: boolean; max_advance_days: string | null; allow_sale: boolean; max_sale_days: string | null
  max_accumulated_periods: number | null; requires_hr_validation: boolean; valid_from: string; valid_to: string | null
}
type Limit = { parameter_code: string; value: string | number | null; verified: boolean; pending_validation: boolean; legal_source: string | null }
export type VacationPolicy = {
  id: string; code: string; name: string; legal_entity_id: string | null; legal_entity_name: string | null
  labor_regime_code: string | null; job_category_id: string | null; job_category_name: string | null
  status: 'active' | 'archived'; allowed_actions: string[]; rules: Rules | null; legal: Record<string, Limit>
}
type Regime = { code: string; country: string; name: string }

/** Texto de la columna «días por año»: los de la política o, si no los fija, los del régimen (o «por validar»). */
export function policyDays(p: Pick<VacationPolicy, 'rules' | 'legal'>): { value: string | null; source: 'policy' | 'legal'; pending: boolean } {
  if (p.rules?.days_per_year != null) return { value: p.rules.days_per_year, source: 'policy', pending: false }
  const legal = p.legal.days_per_year
  return { value: legal?.value == null ? null : String(legal.value), source: 'legal', pending: legal?.pending_validation ?? true }
}

/** Configuración → Políticas de vacaciones (VAC-1). Las reglas cambian con vigencia; la ley la valida el servidor. */
export function VacationPoliciesScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [asOf, setAsOf] = useState(localToday())
  const [rows, setRows] = useState<VacationPolicy[] | null>(null)
  const [regimes, setRegimes] = useState<Regime[]>([])
  const [loadError, setLoadError] = useState(false)
  const [editing, setEditing] = useState<VacationPolicy | 'new' | null>(null)
  const canUpdate = can('time.vacation_policies.update')

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: VacationPolicy[] }>(`/vacation-policies?as_of=${encodeURIComponent(asOf)}`)).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [asOf])
  useEffect(() => { void load() }, [load])
  useEffect(() => {
    void api.get<{ data: { labor_regimes: Regime[] } }>('/catalogs/organization').then((r) => setRegimes(r.data.labor_regimes)).catch(() => setRegimes([]))
  }, [])

  const regimeName = (code: string | null) => (code === null ? t('vpol.any') : regimes.find((r) => r.code === code)?.name ?? code)

  async function act(p: VacationPolicy, action: 'archive' | 'restore') {
    try {
      await api.post(`/vacation-policies/${p.id}/${action}`, {})
      toast(t(action === 'archive' ? 'vpol.archived' : 'vpol.restored'))
      await load()
    } catch (err) {
      toast(formErrors(err, t).general ?? t('common.load_error'))
    }
  }

  return (
    <>
      <PageHeader title={t('vpol.title')} sub={t('vpol.sub')} actions={<>
        <Link className="btn btn-sec" href="/configuracion">{t('cfg.back')}</Link>
        {canUpdate && <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('vpol.new')}</button>}
      </>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="nota">{t('vpol.help')}</div>
        <section className="card">
          <div className="param-form" style={{ margin: 16, maxWidth: 240 }}>
            <label className="form-label" htmlFor="vp-as-of">{t('vpol.as_of')}</label>
            <input id="vp-as-of" type="date" className="form-control" value={asOf} onChange={(e) => e.target.value && setAsOf(e.target.value)} />
          </div>
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr>
                <th>{t('vpol.name')}</th><th>{t('vpol.scope')}</th><th>{t('vpol.days')}</th><th>{t('vpol.options')}</th>
                <th>{t('vpol.since')}</th><th><span className="visually-hidden">{t('vpol.actions')}</span></th>
              </tr></thead>
              <tbody>
                {loadError && <tr><td colSpan={6}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                {!loadError && rows === null && <tr><td colSpan={6}><span className="esq" style={{ width: '40%' }} /></td></tr>}
                {rows?.length === 0 && <tr><td colSpan={6}><EmptyState title={t('vpol.empty_title')} text={t('vpol.empty_text')} /></td></tr>}
                {rows?.map((p) => {
                  const d = policyDays(p)
                  return (
                    <tr key={p.id}>
                      <td><b>{p.name}</b><span className="sub2">{p.code}</span>
                        {p.status === 'archived' && <span className="badge text-bg-secondary">{t('vpol.status_archived')}</span>}</td>
                      <td>{regimeName(p.labor_regime_code)}<span className="sub2">{p.legal_entity_name ?? t('vpol.all_companies')}{p.job_category_name ? ` · ${p.job_category_name}` : ''}</span></td>
                      <td>{d.value !== null && t('vpol.days_value', { n: formatNumber(locale, d.value) })}
                        {d.pending && <span className="badge text-bg-warning" style={{ marginInlineStart: d.value !== null ? 8 : 0 }}>{t('vpol.pending')}</span>}
                        <span className="sub2">{t(d.source === 'policy' ? 'vpol.source_policy' : 'vpol.source_legal')}</span></td>
                      <td>{p.rules === null ? '—' : [
                        p.rules.allow_advance ? t('vpol.opt_advance') : null,
                        p.rules.allow_sale ? t('vpol.opt_sale') : null,
                        p.rules.requires_hr_validation ? t('vpol.opt_hr') : null,
                      ].filter(Boolean).join(' · ') || t('vpol.opt_none')}</td>
                      <td>{p.rules ? formatDate(locale, p.rules.valid_from) : '—'}</td>
                      <td style={{ whiteSpace: 'nowrap' }}>
                        {canUpdate && p.status === 'active' && <button type="button" className="btn btn-sec btn-sm" onClick={() => setEditing(p)}>{t('vpol.edit')}</button>}
                        {canUpdate && p.allowed_actions.includes('archive') && <button type="button" className="btn btn-sec btn-sm" style={{ marginInlineStart: 8 }} onClick={() => void act(p, 'archive')}>{t('vpol.archive')}</button>}
                        {canUpdate && p.allowed_actions.includes('restore') && <button type="button" className="btn btn-sec btn-sm" style={{ marginInlineStart: 8 }} onClick={() => void act(p, 'restore')}>{t('vpol.restore')}</button>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {editing !== null && (
        <PolicyForm policy={editing === 'new' ? null : editing} regimes={regimes} onClose={() => setEditing(null)}
          onDone={async (msg, warnings) => { setEditing(null); toast(warnings.length > 0 ? `${t(msg)} ${warnings.join(' ')}` : t(msg)); await load() }} />
      )}
      {toastNode}
    </>
  )
}

type Form = {
  code: string; name: string; legal_entity_id: string; labor_regime_code: string; effective_from: string
  days_per_year: string; counts_calendar_days: boolean; min_days_per_request: string; allow_advance: boolean; max_advance_days: string
  allow_sale: boolean; max_sale_days: string; max_accumulated_periods: string; requires_hr_validation: boolean
}

function initial(p: VacationPolicy | null): Form {
  const r = p?.rules
  return {
    code: p?.code ?? '', name: p?.name ?? '', legal_entity_id: p?.legal_entity_id ?? '', labor_regime_code: p?.labor_regime_code ?? '',
    effective_from: localToday(), days_per_year: r?.days_per_year ?? '', counts_calendar_days: r?.counts_calendar_days ?? true,
    min_days_per_request: r?.min_days_per_request ?? '', allow_advance: r?.allow_advance ?? false, max_advance_days: r?.max_advance_days ?? '',
    allow_sale: r?.allow_sale ?? false, max_sale_days: r?.max_sale_days ?? '', max_accumulated_periods: r?.max_accumulated_periods?.toString() ?? '',
    requires_hr_validation: r?.requires_hr_validation ?? false,
  }
}

function PolicyForm({ policy, regimes, onClose, onDone }: {
  policy: VacationPolicy | null; regimes: Regime[]; onClose: () => void; onDone: (msg: string, warnings: string[]) => Promise<void>
}) {
  const { t } = useT()
  const { companies } = useCompany()
  const [form, setForm] = useState<Form>(() => initial(policy))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => ({ ...f, [k]: v }))
  const dec = (v: string) => (v.trim() === '' ? null : v.trim())

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    const body = {
      ...(policy === null ? { code: form.code } : {}),
      name: form.name,
      legal_entity_id: form.legal_entity_id || null,
      labor_regime_code: form.labor_regime_code || null,
      effective_from: form.effective_from,
      rules: {
        days_per_year: dec(form.days_per_year), counts_calendar_days: form.counts_calendar_days, min_days_per_request: dec(form.min_days_per_request),
        allow_advance: form.allow_advance, max_advance_days: form.allow_advance ? dec(form.max_advance_days) : null,
        allow_sale: form.allow_sale, max_sale_days: form.allow_sale ? dec(form.max_sale_days) : null,
        max_accumulated_periods: form.max_accumulated_periods.trim() === '' ? null : Number(form.max_accumulated_periods),
        requires_hr_validation: form.requires_hr_validation,
      },
    }
    try {
      const res = policy === null
        ? await api.post<{ meta?: { warnings?: Array<{ message: string }> } }>('/vacation-policies', body)
        : await api.request<{ meta?: { warnings?: Array<{ message: string }> } }>(`/vacation-policies/${policy.id}`, 'PATCH', body)
      await onDone(policy === null ? 'vpol.created' : 'vpol.saved', (res.meta?.warnings ?? []).map((w) => w.message))
    } catch (err) {
      const r = formErrors(err, t)
      const fields: Record<string, string> = {}
      for (const [k, v] of Object.entries(r.fields)) fields[k.replace(/^rules\./, '')] = v
      setErrors(fields)
      setGeneral(r.general)
    }
  }

  const field = (k: 'code' | 'name' | 'days_per_year' | 'min_days_per_request' | 'max_advance_days' | 'max_sale_days' | 'max_accumulated_periods', label: string, kind: 'text' | 'number' = 'text', help?: string) => (
    <div><label className="form-label" htmlFor={`vp-${k}`}>{label}</label>
      <input id={`vp-${k}`} type={kind} min={kind === 'number' ? 0 : undefined} step={kind === 'number' ? 'any' : undefined} className={`form-control${errors[k] ? ' is-invalid' : ''}`} value={form[k]} onChange={(e) => set(k, e.target.value)} />
      {help && <span className="sub2">{help}</span>}
      {errors[k] && <div className="invalid-feedback" role="alert">{errors[k]}</div>}</div>
  )
  const check = (k: 'counts_calendar_days' | 'allow_advance' | 'allow_sale' | 'requires_hr_validation', label: string) => (
    <div className="form-check"><input id={`vp-${k}`} type="checkbox" className="form-check-input" checked={form[k]} onChange={(e) => set(k, e.target.checked)} />
      <label className="form-check-label" htmlFor={`vp-${k}`}>{label}</label></div>
  )

  return (
    <SidePanel title={policy === null ? t('vpol.new') : t('vpol.edit_title', { name: policy.name })} onClose={onClose} closeLabel={t('common.cancel')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('common.cancel')}</button>
        <button type="submit" form="vp-form" className="btn btn-primary">{t('vpol.save')}</button>
      </>}>
      <form id="vp-form" className="param-form" onSubmit={submit} noValidate>
        {general && <div className="alert alert-danger" role="alert">{general}</div>}
        <div className="nota">{t('vpol.form_help')}</div>
        <div className="grid-param" style={{ gridTemplateColumns: '1fr 1fr' }}>
          {policy === null && field('code', t('vpol.code'))}
          {field('name', t('vpol.name'))}
          <div><label className="form-label" htmlFor="vp-regime">{t('vpol.regime')}</label>
            <select id="vp-regime" className="form-select" value={form.labor_regime_code} onChange={(e) => set('labor_regime_code', e.target.value)}>
              <option value="">{t('vpol.any')}</option>
              {regimes.map((r) => <option key={r.code} value={r.code}>{r.name}</option>)}
            </select>
            {errors.labor_regime_code && <div className="invalid-feedback d-block" role="alert">{errors.labor_regime_code}</div>}</div>
          <div><label className="form-label" htmlFor="vp-company">{t('vpol.company')}</label>
            <select id="vp-company" className="form-select" value={form.legal_entity_id} onChange={(e) => set('legal_entity_id', e.target.value)}>
              <option value="">{t('vpol.all_companies')}</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
            {errors.legal_entity_id && <div className="invalid-feedback d-block" role="alert">{errors.legal_entity_id}</div>}</div>
          <div><label className="form-label" htmlFor="vp-from">{t('vpol.effective_from')}</label>
            <input id="vp-from" type="date" className={`form-control${errors.effective_from ? ' is-invalid' : ''}`} value={form.effective_from} onChange={(e) => e.target.value && set('effective_from', e.target.value)} />
            {errors.effective_from && <div className="invalid-feedback" role="alert">{errors.effective_from}</div>}</div>
          {field('days_per_year', t('vpol.days_per_year'), 'number', t('vpol.days_per_year_help'))}
          {field('min_days_per_request', t('vpol.min_days'), 'number')}
          {field('max_accumulated_periods', t('vpol.max_periods'), 'number')}
          {check('counts_calendar_days', t('vpol.calendar_days'))}
          {check('requires_hr_validation', t('vpol.opt_hr'))}
          {check('allow_advance', t('vpol.opt_advance'))}
          {form.allow_advance && field('max_advance_days', t('vpol.max_advance'), 'number')}
          {check('allow_sale', t('vpol.opt_sale'))}
          {form.allow_sale && field('max_sale_days', t('vpol.max_sale'), 'number')}
        </div>
      </form>
    </SidePanel>
  )
}
