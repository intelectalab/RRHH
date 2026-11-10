'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useCompany } from '@/modules/session/CompanyProvider'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { formErrors } from '@/lib/errors'

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
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<Company[] | null>(null)
  const [catalog, setCatalog] = useState<Catalog | null>(null)
  const [editing, setEditing] = useState<Company | 'new' | null>(null)
  const [loadError, setLoadError] = useState(false)

  const load = useCallback(async () => {
    try {
      const res = await api.get<{ data: Company[] }>('/legal-entities?per_page=100')
      setRows(res.data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [])

  useEffect(() => {
    void load()
    void api.get<{ data: Catalog }>('/catalogs/organization').then((r) => setCatalog(r.data))
  }, [load])

  const regimeName = (code: string) => catalog?.labor_regimes.find((r) => r.code === code)?.name ?? code
  const active = rows?.filter((r) => r.status === 'active').length ?? 0

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
        sub={rows ? t('org.sub', { active, total: rows.length }) : undefined}
        tabs={[{ href: '/organizacion/empresas', label: t('org.tab_companies'), active: true }]}
        actions={can('org.legal_entities.create') && (
          <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('org.new')}</button>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="tabla-wrap">
            <table className="table">
              <thead>
                <tr><th>{t('org.col_name')}</th><th>{t('org.col_tax')}</th><th>{t('org.col_country')}</th><th>{t('org.col_currency')}</th><th>{t('org.col_regime')}</th><th>{t('org.col_status')}</th><th /></tr>
              </thead>
              <tbody>
                {loadError && <tr><td colSpan={7}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                {!loadError && rows === null && [1, 2].map((i) => <tr key={i}><td colSpan={7}><span className="esq" style={{ width: `${30 + i * 20}%` }} /></td></tr>)}
                {rows?.length === 0 && (
                  <tr><td colSpan={7}>
                    <EmptyState title={t('org.empty_title')} text={t('org.empty_text')} action={can('org.legal_entities.create') && (
                      <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing('new')}><Icon name="plus" /> {t('org.new')}</button>
                    )} />
                  </td></tr>
                )}
                {rows?.map((r) => (
                  <tr key={r.id}>
                    <td><b>{r.name}</b>{r.trade_name && <span className="sub2">{r.trade_name}</span>}</td>
                    <td>{r.tax_id}</td>
                    <td>{catalog?.countries.find((c) => c.code === r.country)?.name ?? r.country}</td>
                    <td>{r.currency}</td>
                    <td>{regimeName(r.payroll_regime)}</td>
                    <td><span className={`st ${r.status === 'active' ? 'ok' : 'gr'}`}>{t(r.status === 'active' ? 'org.status_active' : 'org.status_archived')}</span></td>
                    <td className="text-end nowrap">
                      {r.status === 'active' && can('org.legal_entities.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(r)}>{t('org.edit')}</button>}
                      {r.allowed_actions?.includes('archive') && can('org.legal_entities.archive') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r, 'archive')}>{t('org.archive')}</button>}
                      {r.allowed_actions?.includes('restore') && can('org.legal_entities.restore') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r, 'restore')}>{t('org.restore')}</button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
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
  })
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const regimes = catalog.labor_regimes.filter((r) => r.country === form.country)
  const taxLabel = catalog.tax_id_labels[form.country] ?? t('org.tax_id')
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const body = { ...form, trade_name: form.trade_name || null }
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
        {error && <div className="invalid-feedback" role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}
