'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useCompany } from '@/modules/session/CompanyProvider'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { formErrors } from '@/lib/errors'
import { CustomFieldsEditor, useCustomFields, type CustomValues } from '@/modules/customfields/CustomFieldsEditor'
import { ExportButton } from '@/modules/exports/ExportButton'
import { DataTable, type BulkAction, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { useDateTime } from '@/modules/session/useDateTime'
import { orgTabs } from './orgTabs'

type Company = Schema<'LegalEntity'> & { trade_name?: string | null; allowed_actions?: string[]; version?: number }
type Catalog = {
  countries: Array<{ code: string; name: string; default_currency: string }>
  currencies: Array<{ code: string; name: string; symbol: string }>
  labor_regimes: Array<{ code: string; country: string; name: string }>
  tax_id_labels: Record<string, string | null>
}

/** Organización → Empresas: razones sociales del grupo (TEN-5). */
export function CompaniesScreen() {
  const { t } = useT()
  const { can } = useSession()
  const company = useCompany()
  const fmtDate = useDateTime()
  const { toast, node: toastNode } = useToast()
  const [catalog, setCatalog] = useState<Catalog | null>(null)
  const [editing, setEditing] = useState<Company | 'new' | null>(null)
  const [reloadKey, setReloadKey] = useState(0)
  const [counts, setCounts] = useState<{ active: number; total: number } | null>(null)

  // UI-4: la tabla pide sus páginas al servidor; recargar es volver a pedir la página y los conteos.
  const load = useCallback(async () => {
    setReloadKey((k) => k + 1)
  }, [])

  useEffect(() => {
    void Promise.all([
      api.get<{ meta?: { total?: number | null } }>('/legal-entities?per_page=1'),
      api.get<{ meta?: { total?: number | null } }>('/legal-entities?per_page=1&filter[status]=active'),
    ]).then(([all, act]) => setCounts({ total: all.meta?.total ?? 0, active: act.meta?.total ?? 0 })).catch(() => setCounts(null))
  }, [reloadKey])

  useEffect(() => {
    void api.get<{ data: Catalog }>('/catalogs/organization').then((r) => setCatalog(r.data))
  }, [])

  const regimeName = (code: string) => catalog?.labor_regimes.find((r) => r.code === code)?.name ?? code

  const columns: Array<TableColumn<Company>> = [
    { key: 'name', label: t('org.col_name'), sort: 'name', hideable: false, width: 280, render: (r) => <><b>{r.name}</b>{r.trade_name && <span className="sub2">{r.trade_name}</span>}</> },
    { key: 'tax_id', label: t('org.col_tax'), sort: 'tax_id', render: (r) => r.tax_id },
    { key: 'country', label: t('org.col_country'), sort: 'country', render: (r) => catalog?.countries.find((c) => c.code === r.country)?.name ?? r.country },
    { key: 'currency', label: t('org.col_currency'), render: (r) => r.currency },
    { key: 'regime', label: t('org.col_regime'), render: (r) => regimeName(r.payroll_regime) },
    { key: 'status', label: t('org.col_status'), sort: 'status', render: (r) => <span className={`st ${r.status === 'active' ? 'ok' : 'gr'}`}>{t(r.status === 'active' ? 'org.status_active' : 'org.status_archived')}</span> },
    { key: 'created_at', label: t('org.col_created'), sort: 'created_at', hiddenByDefault: true, render: (r) => (r.created_at ? fmtDate(r.created_at) : '—') },
  ]

  const tableFilters: TableFilter[] = [
    { key: 'status', label: t('org.filter_status'), options: [{ value: 'active', label: t('org.status_active') }, { value: 'archived', label: t('org.status_archived') }] },
    ...(catalog ? [{ key: 'country', label: t('org.filter_country'), options: catalog.countries.map((c) => ({ value: c.code, label: c.name })) }] : []),
  ]

  // Acción masiva con operación real detrás (RN-UI-01): archivar las empresas elegidas que lo admiten.
  const bulkActions: Array<BulkAction<Company>> = can('org.legal_entities.archive') ? [{
    key: 'archive',
    label: t('org.bulk_archive'),
    danger: true,
    applies: (r) => Boolean(r.allowed_actions?.includes('archive')),
    run: async (rows) => {
      let done = 0
      for (const r of rows) {
        try { await api.post(`/legal-entities/${r.id}/archive`, {}); done++ } catch { /* se informa en el total */ }
      }
      await Promise.all([load(), company.reload()])
      return done
    },
  }] : []

  async function transition(row: Company, action: 'archive' | 'restore') {
    if (action === 'archive' && !window.confirm(t('org.confirm_archive', { name: row.name }))) return
    try {
      await api.post(`/legal-entities/${row.id}/${action}`, {})
      toast(t(action === 'archive' ? 'org.archived' : 'org.restored'))
      await Promise.all([load(), company.reload()])
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  return (
    <>
      <PageHeader
        title={t('org.title')}
        sub={counts ? t('org.sub', counts) : undefined}
        tabs={orgTabs(t, can, 'companies')}
        actions={(
          <>
            {can('org.legal_entities.create') && (
              <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('org.new')}</button>
            )}
          </>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <DataTable<Company>
          resource="legal_entities"
          endpoint="/legal-entities"
          defaultSort="name"
          searchLabel={t('org.search')}
          reloadKey={reloadKey}
          rowKey={(r) => r.id}
          columns={columns}
          filters={tableFilters}
          bulkActions={bulkActions}
          toolbar={(state) => <ExportButton resource="legal_entities" filters={{ q: state.q.trim(), status: state.filters.status ?? '' }} onError={(m) => toast(m, 'error')} />}
          empty={(
            <EmptyState title={t('org.empty_title')} text={t('org.empty_text')} action={can('org.legal_entities.create') && (
              <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing('new')}><Icon name="plus" /> {t('org.new')}</button>
            )} />
          )}
          rowActions={(r) => (
            <>
              {r.status === 'active' && can('org.legal_entities.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(r)}>{t('org.edit')}</button>}
              {r.allowed_actions?.includes('archive') && can('org.legal_entities.archive') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r, 'archive')}>{t('org.archive')}</button>}
              {r.allowed_actions?.includes('restore') && can('org.legal_entities.restore') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r, 'restore')}>{t('org.restore')}</button>}
            </>
          )}
        />
      </main>
      {editing !== null && catalog && (
        <CompanyPanel
          company={editing === 'new' ? null : editing}
          catalog={catalog}
          onClose={() => setEditing(null)}
          onSaved={async (created) => {
            setEditing(null)
            toast(t(created ? 'org.created' : 'org.saved'))
            await Promise.all([load(), company.reload()])
          }}
        />
      )}
      {toastNode}
    </>
  )
}

/** CFG-2 · Logo de la empresa: se sube y se quita al momento (no espera a «Guardar»). */
function LogoField({ company }: { company: Company }) {
  const { t } = useT()
  const [hasLogo, setHasLogo] = useState(Boolean(company.has_logo))
  const [stamp, setStamp] = useState(0)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [status, setStatus] = useState<string | null>(null)

  async function upload(file: File | undefined) {
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      await api.upload(`/legal-entities/${company.id}/logo`, file, file.name)
      setHasLogo(true)
      setStamp(Date.now())
      setStatus(t('org.logo_saved'))
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setError(fields.file ?? general)
    } finally {
      setBusy(false)
    }
  }

  async function remove() {
    setBusy(true)
    setError(null)
    try {
      await api.request(`/legal-entities/${company.id}/logo`, 'DELETE')
      setHasLogo(false)
      setStatus(t('org.logo_removed'))
    } catch (err) {
      setError(err instanceof ApiRequestError ? err.message : t('auth.generic_error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <fieldset className="cf-grupo">
      <legend>{t('org.logo')}</legend>
      <div className="logo-campo">
        <div className="logo-vista" aria-hidden={!hasLogo}>
          {hasLogo
            // La imagen sale de la API con la sesión del usuario: el optimizador de next/image no la alcanza.
            // eslint-disable-next-line @next/next/no-img-element
            ? <img src={`/api/v1/legal-entities/${company.id}/logo${stamp ? `?v=${stamp}` : ''}`} alt={t('org.logo_alt', { name: company.name })} />
            : <Icon name="building" size={28} />}
        </div>
        <div>
          <label className="btn btn-sec btn-sm" htmlFor="c-logo">{busy && <span className="spin" aria-hidden="true" />}{t(hasLogo ? 'org.logo_replace' : 'org.logo_upload')}</label>
          <input id="c-logo" type="file" className="vh" accept="image/png,image/jpeg,image/webp" disabled={busy} onChange={(e) => { void upload(e.target.files?.[0]); e.target.value = '' }} />
          {hasLogo && <button type="button" className="btn btn-ter btn-sm" onClick={() => void remove()} disabled={busy}>{t('org.logo_remove')}</button>}
          <p className="small text-secondary">{t('org.logo_help')}</p>
        </div>
      </div>
      {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      <span className="vh" role="status">{status}</span>
    </fieldset>
  )
}

function CompanyPanel({ company, catalog, onClose, onSaved }: { company: Company | null; catalog: Catalog; onClose: () => void; onSaved: (created: boolean) => Promise<void> }) {
  const { t } = useT()
  const [form, setForm] = useState({
    name: company?.name ?? '',
    trade_name: company?.trade_name ?? '',
    tax_id: company?.tax_id ?? '',
    country: company?.country ?? catalog.countries[0]?.code ?? '',
    currency: company?.currency ?? catalog.countries[0]?.default_currency ?? '',
    timezone: company?.timezone ?? 'America/Lima',
    payroll_regime: company?.payroll_regime ?? '',
    locale: company?.locale ?? '',
    tax_regime: company?.tax_regime ?? '',
    economic_activity_code: company?.economic_activity_code ?? '',
    address: company?.address ?? '',
    district_code: company?.district_code ?? '',
    legal_representative_name: company?.legal_representative_name ?? '',
  })
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const custom = useCustomFields('legal_entity')
  const [customValues, setCustomValues] = useState<CustomValues>({})
  useEffect(() => {
    if (company) void api.get<{ data: { custom_fields?: CustomValues } }>(`/legal-entities/${company.id}`).then((r) => setCustomValues(r.data.custom_fields ?? {}))
  }, [company])
  const [error, setError] = useState<string | null>(null)
  const regimes = catalog.labor_regimes.filter((r) => r.country === form.country)
  const taxLabel = catalog.tax_id_labels[form.country] ?? t('org.tax_id')
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    // CFG-2: un dato propio vacío vuelve a heredar el de la organización (se envía null).
    const optional = ['trade_name', 'locale', 'tax_regime', 'economic_activity_code', 'address', 'district_code', 'legal_representative_name'] as const
    const body = { ...form, ...Object.fromEntries(optional.map((k) => [k, form[k].trim() || null])), ...(custom.fields.length > 0 ? { custom_fields: customValues } : {}) }
    try {
      if (company) {
        const { country: _country, ...patch } = body
        void _country
        await api.request(`/legal-entities/${company.id}`, 'PATCH', patch, company.version !== undefined ? { 'If-Match': `"${company.version}"` } : undefined)
      } else {
        await api.post('/legal-entities', body)
      }
      await onSaved(company === null)
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      setError(general)
    } finally {
      setBusy(false)
    }
  }

  const field = (key: keyof typeof form, label: string, input: React.ReactNode) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`c-${key}`}>{label}</label>
      {input}
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )

  return (
    <SidePanel
      title={t(company ? 'org.form_edit' : 'org.form_new')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('org.cancel')}</button>
        <button type="submit" form="empresa" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('org.save')}</button>
      </>}
    >
      <form id="empresa" onSubmit={submit} noValidate>
        {field('name', t('org.name'), <input id="c-name" className={`form-control${errors.name ? ' is-invalid' : ''}`} value={form.name} onChange={set('name')} required />)}
        {field('trade_name', `${t('org.trade_name')} ${t('org.trade_optional')}`, <input id="c-trade_name" className="form-control" value={form.trade_name} onChange={set('trade_name')} />)}
        {field('country', t('org.country'), (
          <select id="c-country" className="form-select" value={form.country} onChange={set('country')} disabled={company !== null}>
            {catalog.countries.map((c) => <option key={c.code} value={c.code}>{c.name}</option>)}
          </select>
        ))}
        {field('tax_id', taxLabel, <input id="c-tax_id" className={`form-control${errors.tax_id ? ' is-invalid' : ''}`} inputMode="numeric" value={form.tax_id} onChange={set('tax_id')} required />)}
        {field('currency', t('org.currency'), (
          <select id="c-currency" className="form-select" value={form.currency} onChange={set('currency')}>
            {catalog.currencies.map((c) => <option key={c.code} value={c.code}>{c.code} · {c.name}</option>)}
          </select>
        ))}
        {field('timezone', t('org.timezone'), <input id="c-timezone" className={`form-control${errors.timezone ? ' is-invalid' : ''}`} value={form.timezone} onChange={set('timezone')} />)}
        {field('payroll_regime', t('org.regime'), (
          <select id="c-payroll_regime" className={`form-select${errors.payroll_regime ? ' is-invalid' : ''}`} value={form.payroll_regime} onChange={set('payroll_regime')} required>
            <option value="" disabled>—</option>
            {regimes.map((r) => <option key={r.code} value={r.code}>{r.name}</option>)}
          </select>
        ))}
        {company && <p className="small text-secondary">{t('org.regime_help')}</p>}

        <fieldset className="cf-grupo">
          <legend>{t('org.section_profile')}</legend>
          <p className="small text-secondary">{t('org.profile_help')}</p>
          {field('locale', t('org.locale'), (
            <select id="c-locale" className={`form-select${errors.locale ? ' is-invalid' : ''}`} value={form.locale} onChange={set('locale')}>
              <option value="">{t('org.locale_inherit')}</option>
              {(['es', 'en', 'pt'] as const).map((l) => <option key={l} value={l}>{t(`org.locale_${l}`)}</option>)}
            </select>
          ))}
          {field('tax_regime', t('org.tax_regime'), <input id="c-tax_regime" className={`form-control${errors.tax_regime ? ' is-invalid' : ''}`} value={form.tax_regime} onChange={set('tax_regime')} />)}
          {field('economic_activity_code', t('org.activity_code'), <input id="c-economic_activity_code" className={`form-control${errors.economic_activity_code ? ' is-invalid' : ''}`} inputMode="numeric" value={form.economic_activity_code} onChange={set('economic_activity_code')} />)}
          {field('address', t('org.address'), <input id="c-address" className={`form-control${errors.address ? ' is-invalid' : ''}`} value={form.address} onChange={set('address')} autoComplete="street-address" />)}
          {field('district_code', t('org.district_code'), <input id="c-district_code" className={`form-control${errors.district_code ? ' is-invalid' : ''}`} inputMode="numeric" value={form.district_code} onChange={set('district_code')} aria-describedby="c-district_help" />)}
          <p id="c-district_help" className="small text-secondary">{t('org.district_help')}</p>
          {field('legal_representative_name', t('org.legal_rep'), <input id="c-legal_representative_name" className={`form-control${errors.legal_representative_name ? ' is-invalid' : ''}`} value={form.legal_representative_name} onChange={set('legal_representative_name')} />)}
        </fieldset>

        {company && <LogoField company={company} />}

        <CustomFieldsEditor fields={custom.fields} values={customValues} onChange={setCustomValues} errors={errors} />
        {error && <div className="invalid-feedback" role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}
