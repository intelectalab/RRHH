'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { formErrors } from '@/lib/errors'
import { formatDate, formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { useConsole, useConsoleTabs } from './ConsoleShell'
import { tenantTone } from './ConsoleTenants'
import { consoleApi, type ConsoleStatusEvent, type ConsoleTenantDetail, type ConsoleTenantModule } from './consoleApi'

/** Acción de estado → ruta de la API, permiso que la habilita y si exige motivo. */
const ACTIONS: Record<string, { perm: string; reason: boolean; danger: boolean }> = {
  activate: { perm: 'platform.tenants.reactivate', reason: false, danger: false },
  reactivate: { perm: 'platform.tenants.reactivate', reason: false, danger: false },
  suspend: { perm: 'platform.tenants.suspend', reason: true, danger: true },
  block: { perm: 'platform.tenants.suspend', reason: true, danger: true },
  cancel: { perm: 'platform.tenants.cancel', reason: true, danger: true },
}

function moduleTone(state: ConsoleTenantModule['state']) {
  if (state === 'active' || state === 'trial') return 'ok' as const
  if (state === 'read_only') return 'wa' as const
  return 'gr' as const
}

/** SAS-2 · SAS-5 · Ficha de la organización: datos, empresas, consumo, estado de cuenta, módulos e historial. */
export function ConsoleTenantDetail({ id }: { id: string }) {
  const { t, locale } = useT()
  const me = useConsole()
  const tabs = useConsoleTabs()
  const [tenant, setTenant] = useState<ConsoleTenantDetail | null>(null)
  const [events, setEvents] = useState<ConsoleStatusEvent[]>([])
  const [failed, setFailed] = useState(false)
  const [action, setAction] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)
  const [moduleError, setModuleError] = useState<string | null>(null)
  const [busyModule, setBusyModule] = useState<string | null>(null)
  const can = (p: string) => me.permissions.includes(p)

  const load = useCallback(() => {
    setFailed(false)
    Promise.all([
      consoleApi.get<{ data: ConsoleTenantDetail }>(`/tenants/${id}`),
      consoleApi.get<{ data: ConsoleStatusEvent[] }>(`/tenants/${id}/status-events`),
    ]).then(([d, e]) => { setTenant(d.data); setEvents(e.data) }).catch(() => setFailed(true))
  }, [id])
  useEffect(load, [load])

  async function toggleModule(m: ConsoleTenantModule) {
    const verb = m.state === 'active' || m.state === 'trial' ? 'deactivate' : 'activate'
    setBusyModule(m.code)
    setModuleError(null)
    try {
      await consoleApi.post(`/tenants/${id}/modules/${m.code}/${verb}`, {})
      load()
    } catch (err) {
      setModuleError(formErrors(err, t).general ?? t('console.load_error'))
    } finally {
      setBusyModule(null)
    }
  }

  if (failed) return <main className="main"><ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} /></main>
  if (!tenant) return <main className="main" aria-busy="true"><span className="esq" style={{ width: 320 }} /></main>

  const actions = tenant.allowed_actions.filter((a) => ACTIONS[a] && can(ACTIONS[a].perm))
  const canModules = can('platform.subscriptions.update')
  const names = new Map(tenant.modules.map((m) => [m.code, m.name]))

  return (
    <>
      <PageHeader
        title={tenant.name}
        sub={<><StatusBadge tone={tenantTone(tenant.status)}>{t(`console.tstatus.${tenant.status}`)}</StatusBadge> <span className="text-secondary">{tenant.slug}</span></>}
        tabs={tabs}
        actions={(
          <div className="consola-acciones">
            {can('platform.tenants.update') && <button type="button" className="btn btn-sec" onClick={() => setEditing(true)}>{t('console.edit')}</button>}
            {actions.map((a) => (
              <button key={a} type="button" className={`btn ${ACTIONS[a]?.danger ? 'btn-sec' : 'btn-primary'}`} onClick={() => setAction(a)}>{t(`console.taction.${a}`)}</button>
            ))}
          </div>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <p className="mb-3"><Link href="/plataforma/organizaciones">{t('console.back_to_tenants')}</Link></p>
        {tenant.suspension_reason && (tenant.status === 'suspended' || tenant.status === 'blocked') && (
          <Notice tone="error" title={t(`console.tstatus.${tenant.status}`)}>{tenant.suspension_reason}</Notice>
        )}
        {tenant.status === 'cancelled' && tenant.retention_until && (
          <Notice tone="warn" title={t('console.tstatus.cancelled')}>{t('console.retention_note', { date: formatDate(locale, tenant.retention_until) })}</Notice>
        )}

        <div className="consola-cifras mb-3">
          <div className="card"><span className="small text-secondary">{t('console.usage_companies')}</span><b>{tenant.usage.legal_entities}</b></div>
          <div className="card"><span className="small text-secondary">{t('console.usage_users')}</span><b>{tenant.usage.active_users}</b><span className="sub2">{t('console.usage_users_total', { n: tenant.usage.users })}</span></div>
          <div className="card"><span className="small text-secondary">{t('console.usage_employees')}</span><b>{tenant.usage.employees}</b></div>
          <div className="card">
            <span className="small text-secondary">{t('console.account_balance')}</span>
            <b>{tenant.account.open_balance.length === 0 ? t('console.account_no_debt') : tenant.account.open_balance.map((m) => t('console.amount', { amount: m.amount, currency: m.currency })).join(' · ')}</b>
            <span className="sub2">{t('console.account_overdue', { n: tenant.account.overdue_invoices })}</span>
          </div>
        </div>

        <section className="card mb-3">
          <div className="card-header"><h2>{t('console.section_org')}</h2></div>
          <div className="card-body">
            <dl className="ficha">
              <dt>{t('console.tenant_country')}</dt><dd>{tenant.country}</dd>
              <dt>{t('console.tenant_currency')}</dt><dd>{tenant.currency}</dd>
              <dt>{t('console.tenant_timezone')}</dt><dd>{tenant.timezone}</dd>
              <dt>{t('console.tenant_locale')}</dt><dd>{t(`console.locale_${tenant.locale}`)}</dd>
              <dt>{t('console.tenant_trial_end')}</dt><dd>{tenant.trial_ends_on ? formatDate(locale, tenant.trial_ends_on) : t('console.none')}</dd>
              <dt>{t('console.account_plan')}</dt><dd>{tenant.account.subscription ? tenant.account.subscription.plan : t('console.account_no_plan')}</dd>
              <dt>{t('console.contact_name')}</dt><dd>{tenant.contact_name ?? t('console.none')}</dd>
              <dt>{t('console.contact_email')}</dt><dd>{tenant.contact_email ?? t('console.none')}</dd>
              <dt>{t('console.contact_phone')}</dt><dd>{tenant.contact_phone ?? t('console.none')}</dd>
            </dl>
          </div>
        </section>

        <section className="card mb-3">
          <div className="card-header"><h2>{t('console.section_companies')}</h2></div>
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th scope="col">{t('console.legal_name')}</th><th scope="col">{t('console.tax_id')}</th><th scope="col">{t('console.tenant_status')}</th></tr></thead>
              <tbody>
                {tenant.legal_entities.map((e) => (
                  <tr key={e.id}><td>{e.legal_name}</td><td>{e.tax_id}</td><td>{t(`console.lestatus.${e.status === 'active' ? 'active' : 'other'}`)}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>

        <section className="card mb-3">
          <div className="card-header"><h2>{t('console.section_modules')}</h2></div>
          {moduleError && <div className="card-body"><Notice tone="error" onDismiss={() => setModuleError(null)}>{moduleError}</Notice></div>}
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th scope="col">{t('console.module')}</th><th scope="col">{t('console.tenant_status')}</th><th scope="col">{t('console.module_depends')}</th>{canModules && <th scope="col"><span className="vh">{t('console.module_action')}</span></th>}</tr></thead>
              <tbody>
                {tenant.modules.map((m) => (
                  <tr key={m.code}>
                    <td><b>{m.name}</b>{m.is_core && <> <Tag>{t('console.module_core')}</Tag></>}</td>
                    <td>
                      <StatusBadge tone={moduleTone(m.state)}>{t(`console.mstate.${m.state}`)}</StatusBadge>
                      {m.state === 'read_only' && m.read_only_until && <span className="sub2">{t('console.module_read_only_until', { date: formatDate(locale, m.read_only_until) })}</span>}
                    </td>
                    <td className="text-secondary">{m.depends_on.map((c) => names.get(c) ?? c).join(', ')}</td>
                    {canModules && (
                      <td className="text-end">
                        {!m.is_core && (
                          <button type="button" className="btn btn-sec btn-sm" disabled={busyModule !== null} onClick={() => void toggleModule(m)}>
                            {t(m.state === 'active' || m.state === 'trial' ? 'console.module_deactivate' : 'console.module_activate')}
                          </button>
                        )}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>

        <section className="card">
          <div className="card-header"><h2>{t('console.section_history')}</h2></div>
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th scope="col">{t('console.audit_when')}</th><th scope="col">{t('console.tenant_status')}</th><th scope="col">{t('console.reason')}</th><th scope="col">{t('console.audit_who')}</th></tr></thead>
              <tbody>
                {events.map((e) => (
                  <tr key={e.id}>
                    <td>{formatDateTime(locale, e.occurred_at)}</td>
                    <td>{t(`console.tstatus.${e.to_status}`)}</td>
                    <td className="text-secondary">{e.reason && e.reason !== 'created' ? e.reason : ''}</td>
                    <td>{e.actor ?? t('console.audit_system')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {action && <ActionDialog id={id} action={action} onClose={() => setAction(null)} onDone={() => { setAction(null); load() }} />}
      {editing && <EditTenant tenant={tenant} onClose={() => setEditing(false)} onDone={() => { setEditing(false); load() }} />}
    </>
  )
}

function ActionDialog({ id, action, onClose, onDone }: { id: string; action: string; onClose: () => void; onDone: () => void }) {
  const { t } = useT()
  const spec = ACTIONS[action]
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function confirm(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await consoleApi.post(`/tenants/${id}/${action}`, reason.trim() ? { reason: reason.trim() } : {})
      onDone()
    } catch (err) {
      const r = formErrors(err, t)
      setError(r.fields.reason ?? r.general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title={t(`console.taction.${action}`)}
      onClose={onClose}
      tone={spec?.danger ? 'danger' : 'normal'}
      footer={(
        <>
          <button type="button" className="btn btn-sec" onClick={onClose}>{t('console.cancel')}</button>
          <button type="submit" form="ta-form" className="btn btn-primary" disabled={busy}>{t(`console.taction.${action}`)}</button>
        </>
      )}
    >
      <form id="ta-form" onSubmit={confirm} noValidate>
        <p>{t(`console.taction_help.${action}`)}</p>
        {spec?.reason && (
          <div className="mb-3">
            <label className="form-label" htmlFor="ta-reason">{t('console.reason')}</label>
            <textarea id="ta-reason" className={`form-control${error ? ' is-invalid' : ''}`} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
        )}
        {error && <Notice tone="error">{error}</Notice>}
      </form>
    </Modal>
  )
}

function EditTenant({ tenant, onClose, onDone }: { tenant: ConsoleTenantDetail; onClose: () => void; onDone: () => void }) {
  const { t } = useT()
  const [form, setForm] = useState({
    name: tenant.name, timezone: tenant.timezone, locale: tenant.locale,
    contact_name: tenant.contact_name ?? '', contact_email: tenant.contact_email ?? '', contact_phone: tenant.contact_phone ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    try {
      await consoleApi.request(`/tenants/${tenant.id}`, 'PATCH', Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v])))
      onDone()
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      setGeneral(r.general)
    }
  }

  const field = (k: keyof typeof form, label: string, type = 'text') => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`et-${k}`}>{label}</label>
      <input id={`et-${k}`} type={type} className={`form-control${errors[k] ? ' is-invalid' : ''}`} value={form[k]} onChange={(e) => setForm((f) => ({ ...f, [k]: e.target.value }))} />
      {errors[k] && <div className="invalid-feedback" role="alert">{errors[k]}</div>}
    </div>
  )

  return (
    <SidePanel
      title={t('console.edit')}
      onClose={onClose}
      closeLabel={t('ui.close')}
      footer={(
        <>
          <button type="button" className="btn btn-sec" onClick={onClose}>{t('console.cancel')}</button>
          <button type="submit" form="et-form" className="btn btn-primary">{t('console.save')}</button>
        </>
      )}
    >
      <form id="et-form" onSubmit={submit} noValidate>
        {general && <Notice tone="error">{general}</Notice>}
        {field('name', t('console.tenant_name'))}
        {field('timezone', t('console.tenant_timezone'))}
        <div className="mb-3">
          <label className="form-label" htmlFor="et-locale">{t('console.tenant_locale')}</label>
          <select id="et-locale" className="form-select" value={form.locale} onChange={(e) => setForm((f) => ({ ...f, locale: e.target.value }))}>
            {(['es', 'en', 'pt'] as const).map((l) => <option key={l} value={l}>{t(`console.locale_${l}`)}</option>)}
          </select>
        </div>
        {field('contact_name', t('console.contact_name'))}
        {field('contact_email', t('console.contact_email'), 'email')}
        {field('contact_phone', t('console.contact_phone'), 'tel')}
      </form>
    </SidePanel>
  )
}
