'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, type StatusTone } from '@/ui/components/Status'
import { useConsole, useConsoleTabs } from './ConsoleShell'
import { consoleApi, type ConsoleTenant, type ConsoleTenantStatus } from './consoleApi'

const PER_PAGE = 25
const STATUSES: ConsoleTenantStatus[] = ['trial', 'active', 'grace_period', 'suspended', 'blocked', 'cancelled']

/** Color del estado de una organización (el texto siempre lo dice). */
export function tenantTone(status: string): StatusTone {
  if (status === 'active') return 'ok'
  if (status === 'trial') return 'in'
  if (status === 'grace_period') return 'wa'
  if (status === 'suspended' || status === 'blocked') return 'er'
  return 'gr'
}

/** SAS-2 · Organizaciones: lista con estado y consumo; alta con su primera empresa y administrador. */
export function ConsoleTenants() {
  const { t, locale } = useT()
  const me = useConsole()
  const tabs = useConsoleTabs()
  const router = useRouter()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<ConsoleTenant[] | null>(null)
  const [total, setTotal] = useState(0)
  const [failed, setFailed] = useState(false)
  const [creating, setCreating] = useState(false)

  const load = useCallback(() => {
    setFailed(false)
    const params = new URLSearchParams({ page: String(page), per_page: String(PER_PAGE) })
    if (q.trim()) params.set('q', q.trim())
    if (status) params.set('filter[status]', status)
    consoleApi.get<{ data: ConsoleTenant[]; meta: { total: number } }>(`/tenants?${params.toString()}`)
      .then((r) => { setRows(r.data); setTotal(r.meta.total) })
      .catch(() => setFailed(true))
  }, [page, q, status])
  useEffect(load, [load])

  const pages = Math.max(1, Math.ceil(total / PER_PAGE))
  const canCreate = me.permissions.includes('platform.tenants.create')

  return (
    <>
      <PageHeader
        title={t('console.tenants_title')}
        sub={t('console.tenants_sub')}
        tabs={tabs}
        actions={canCreate ? <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}><Icon name="plus" /> {t('console.tenant_new')}</button> : undefined}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="filtros" role="search">
          <label className="vh" htmlFor="tn-q">{t('console.tenant_search')}</label>
          <input id="tn-q" type="search" className="form-control" placeholder={t('console.tenant_search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} />
          <label className="vh" htmlFor="tn-status">{t('console.tenant_status')}</label>
          <select id="tn-status" className="form-select" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">{t('console.tenant_all_status')}</option>
            {STATUSES.map((s) => <option key={s} value={s}>{t(`console.tstatus.${s}`)}</option>)}
          </select>
        </div>
        {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
        {!failed && !rows && <span className="esq" style={{ width: 320 }} />}
        {rows && rows.length === 0 && <EmptyState title={t('console.tenants_empty_title')} text={t('console.tenants_empty')} />}
        {rows && rows.length > 0 && (
          <section className="card">
            <div className="tabla-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">{t('console.tenant_name')}</th>
                    <th scope="col">{t('console.tenant_status')}</th>
                    <th scope="col" className="text-end">{t('console.usage_companies')}</th>
                    <th scope="col" className="text-end">{t('console.usage_users')}</th>
                    <th scope="col" className="text-end">{t('console.usage_employees')}</th>
                    <th scope="col">{t('console.tenant_trial_end')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((o) => (
                    <tr key={o.id}>
                      <td>
                        <Link href={`/plataforma/organizaciones/${o.id}`}><b>{o.name}</b></Link>
                        <span className="sub2">{o.slug}</span>
                      </td>
                      <td><StatusBadge tone={tenantTone(o.status)}>{t(`console.tstatus.${o.status}`)}</StatusBadge></td>
                      <td className="text-end">{o.usage.legal_entities}</td>
                      <td className="text-end">{o.usage.active_users}</td>
                      <td className="text-end">{o.usage.employees}</td>
                      <td>{o.trial_ends_on ? formatDate(locale, o.trial_ends_on) : ''}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="card-body fila-entre">
              <span className="small text-secondary">{t('console.page', { page, pages })}</span>
              <div>
                <button type="button" className="btn btn-sec btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>{t('console.prev')}</button>{' '}
                <button type="button" className="btn btn-sec btn-sm" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>{t('console.next')}</button>
              </div>
            </div>
          </section>
        )}
      </main>
      {creating && <NewTenant onClose={() => setCreating(false)} onCreated={(id) => router.push(`/plataforma/organizaciones/${id}`)} />}
    </>
  )
}

type Form = {
  name: string; slug: string; country: string; currency: string; timezone: string; locale: string; trial_ends_on: string
  contact_name: string; contact_email: string; contact_phone: string; admin_name: string; admin_email: string
  legal_name: string; tax_id: string; payroll_regime: string
}

const EMPTY: Form = {
  name: '', slug: '', country: 'PE', currency: 'PEN', timezone: 'America/Lima', locale: 'es', trial_ends_on: '',
  contact_name: '', contact_email: '', contact_phone: '', admin_name: '', admin_email: '',
  legal_name: '', tax_id: '', payroll_regime: 'pe_general',
}

function NewTenant({ onClose, onCreated }: { onClose: () => void; onCreated: (id: string) => void }) {
  const { t } = useT()
  const [form, setForm] = useState<Form>(EMPTY)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setGeneral(null)
    try {
      const body = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
      const r = await consoleApi.post<{ data: { id: string } }>('/tenants', body)
      onCreated(r.data.id)
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      setGeneral(r.general)
    } finally {
      setBusy(false)
    }
  }

  const field = (k: keyof Form, label: string, type = 'text', help?: string) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`nt-${k}`}>{label}</label>
      <input id={`nt-${k}`} type={type} className={`form-control${errors[k] ? ' is-invalid' : ''}`} value={form[k]}
        onChange={(e) => setForm((f) => ({ ...f, [k]: e.target.value }))} aria-describedby={help ? `nt-${k}-h` : undefined} />
      {help && <div id={`nt-${k}-h`} className="sub2">{help}</div>}
      {errors[k] && <div className="invalid-feedback" role="alert">{errors[k]}</div>}
    </div>
  )

  return (
    <SidePanel
      title={t('console.tenant_new')}
      onClose={onClose}
      closeLabel={t('ui.close')}
      wide
      footer={(
        <>
          <button type="button" className="btn btn-sec" onClick={onClose}>{t('console.cancel')}</button>
          <button type="submit" form="nt-form" className="btn btn-primary" disabled={busy}>{t('console.tenant_create')}</button>
        </>
      )}
    >
      <form id="nt-form" onSubmit={submit} noValidate>
        {general && <Notice tone="error">{general}</Notice>}
        <h3 className="panel-sec">{t('console.section_org')}</h3>
        {field('name', t('console.tenant_name'))}
        {field('slug', t('console.tenant_slug'), 'text', t('console.tenant_slug_help'))}
        <div className="form-2col">
          {field('country', t('console.tenant_country'))}
          {field('currency', t('console.tenant_currency'))}
          {field('timezone', t('console.tenant_timezone'))}
          <div className="mb-3">
            <label className="form-label" htmlFor="nt-locale">{t('console.tenant_locale')}</label>
            <select id="nt-locale" className="form-select" value={form.locale} onChange={(e) => setForm((f) => ({ ...f, locale: e.target.value }))}>
              {(['es', 'en', 'pt'] as const).map((l) => <option key={l} value={l}>{t(`console.locale_${l}`)}</option>)}
            </select>
          </div>
        </div>
        {field('trial_ends_on', t('console.tenant_trial_end'), 'date', t('console.tenant_trial_help'))}
        <h3 className="panel-sec">{t('console.section_contact')}</h3>
        {field('contact_name', t('console.contact_name'))}
        <div className="form-2col">
          {field('contact_email', t('console.contact_email'), 'email')}
          {field('contact_phone', t('console.contact_phone'), 'tel')}
        </div>
        <h3 className="panel-sec">{t('console.section_company')}</h3>
        {field('legal_name', t('console.legal_name'))}
        <div className="form-2col">
          {field('tax_id', t('console.tax_id'))}
          {field('payroll_regime', t('console.payroll_regime'))}
        </div>
        <h3 className="panel-sec">{t('console.section_admin')}</h3>
        <p className="small text-secondary">{t('console.admin_help')}</p>
        {field('admin_name', t('console.admin_name'))}
        {field('admin_email', t('console.admin_email'), 'email')}
      </form>
    </SidePanel>
  )
}
