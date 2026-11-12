'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { formErrors } from '@/lib/errors'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { useConsole, useConsoleTabs } from './ConsoleShell'
import { consoleApi, type ConsoleFeatureFlag, type ConsoleFlagOverride, type ConsoleTenant, type FlagTarget } from './consoleApi'

/** Orden en que se evalúan las reglas (RN-SAS-07); la activación por organización va antes que todas. */
const TARGETS: FlagTarget[] = ['plan', 'beta_group', 'country', 'percentage']

type RuleForm = { target_kind: FlagTarget; target_value: string; enabled: boolean }

/**
 * SAS-7 · Consola → Funciones: banderas de funcionalidad con reglas por plan, grupo beta, país y
 * porcentaje, y activación por organización. Cada cambio queda auditado y rige sin desplegar.
 */
export function ConsoleFeatureFlags() {
  const { t } = useT()
  const me = useConsole()
  const tabs = useConsoleTabs()
  const [flags, setFlags] = useState<ConsoleFeatureFlag[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [editing, setEditing] = useState<ConsoleFeatureFlag | 'new' | null>(null)
  const [overridesOf, setOverridesOf] = useState<ConsoleFeatureFlag | null>(null)
  const [error, setError] = useState<string | null>(null)
  const can = (p: string) => me.permissions.includes(p)

  const load = useCallback(() => {
    setFailed(false)
    consoleApi.get<{ data: ConsoleFeatureFlag[] }>('/feature-flags').then((r) => setFlags(r.data)).catch(() => setFailed(true))
  }, [])
  useEffect(load, [load])

  async function act(flag: ConsoleFeatureFlag, action: 'archive' | 'restore') {
    setError(null)
    try {
      await consoleApi.post(`/feature-flags/${flag.id}/${action}`, {})
      load()
    } catch (err) {
      setError(formErrors(err, t).general ?? t('console.load_error'))
    }
  }

  const ruleText = (r: RuleForm) => t(`console.flag_rule.${r.target_kind}`, { value: r.target_value }) + ' · ' + t(r.enabled ? 'console.flag_on' : 'console.flag_off')

  return (
    <>
      <PageHeader
        title={t('console.flags_title')}
        sub={t('console.flags_sub')}
        tabs={tabs}
        actions={can('platform.feature_flags.create') ? <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('console.flag_new')}</button> : undefined}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error" onDismiss={() => setError(null)}>{error}</Notice>}
        {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
        {!failed && !flags && <span className="esq" style={{ width: 320 }} />}
        {flags && flags.length === 0 && <EmptyState title={t('console.flags_empty_title')} text={t('console.flags_empty')} />}
        {flags && flags.length > 0 && (
          <section className="card">
            <div className="tabla-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">{t('console.flag_name')}</th>
                    <th scope="col">{t('console.tenant_status')}</th>
                    <th scope="col">{t('console.flag_default')}</th>
                    <th scope="col">{t('console.flag_rules')}</th>
                    <th scope="col" className="text-end">{t('console.flag_overrides')}</th>
                    <th scope="col"><span className="vh">{t('console.module_action')}</span></th>
                  </tr>
                </thead>
                <tbody>
                  {flags.map((f) => (
                    <tr key={f.id}>
                      <td>
                        <b>{f.name}</b>{f.is_beta && <> <Tag tone="in">{t('console.flag_beta')}</Tag></>}
                        <span className="sub2">{f.code}</span>
                      </td>
                      <td><StatusBadge tone={f.status === 'active' ? 'ok' : 'gr'}>{t(`console.flag_status.${f.status}`)}</StatusBadge></td>
                      <td>{t(f.default_enabled ? 'console.flag_on' : 'console.flag_off')}</td>
                      <td className="text-secondary">{f.rules.length === 0 ? t('console.flag_no_rules') : f.rules.map(ruleText).join('; ')}</td>
                      <td className="text-end">{f.overrides}</td>
                      <td className="text-end nowrap">
                        <button type="button" className="btn btn-sec btn-sm" onClick={() => setOverridesOf(f)}>{t('console.flag_tenants')}</button>{' '}
                        {can('platform.feature_flags.update') && (
                          <>
                            <button type="button" className="btn btn-sec btn-sm" onClick={() => setEditing(f)}>{t('console.edit')}</button>{' '}
                            {f.allowed_actions.map((a) => (
                              <button key={a} type="button" className="btn btn-sec btn-sm" onClick={() => void act(f, a as 'archive' | 'restore')}>{t(`console.flag_action.${a}`)}</button>
                            ))}
                          </>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
      </main>
      {editing && <FlagEditor flag={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); load() }} />}
      {overridesOf && <FlagTenants flag={overridesOf} canEdit={can('platform.feature_flags.update') && overridesOf.status === 'active'} onClose={() => { setOverridesOf(null); load() }} />}
    </>
  )
}

function FlagEditor({ flag, onClose, onDone }: { flag: ConsoleFeatureFlag | null; onClose: () => void; onDone: () => void }) {
  const { t } = useT()
  const [form, setForm] = useState({
    code: flag?.code ?? '', name: flag?.name ?? '', description: flag?.description ?? '',
    default_enabled: flag?.default_enabled ?? false, is_beta: flag?.is_beta ?? false,
  })
  const [rules, setRules] = useState<RuleForm[]>(flag?.rules ?? [])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setErrors({})
    setGeneral(null)
    const body = {
      name: form.name, description: form.description === '' ? null : form.description,
      default_enabled: form.default_enabled, is_beta: form.is_beta,
      rules: rules.map((r) => ({ ...r, target_value: r.target_value.trim() })),
    }
    try {
      if (flag) await consoleApi.request(`/feature-flags/${flag.id}`, 'PATCH', body)
      else await consoleApi.post('/feature-flags', { ...body, code: form.code })
      onDone()
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      const ruleError = Object.entries(r.fields).find(([k]) => k.startsWith('rules'))?.[1]
      setGeneral(ruleError ?? r.general)
    }
  }

  const setRule = (i: number, patch: Partial<RuleForm>) => setRules((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  return (
    <SidePanel
      title={flag ? t('console.flag_edit') : t('console.flag_new')}
      onClose={onClose}
      closeLabel={t('ui.close')}
      wide
      footer={(
        <>
          <button type="button" className="btn btn-sec" onClick={onClose}>{t('console.cancel')}</button>
          <button type="submit" form="ff-form" className="btn btn-primary">{t('console.save')}</button>
        </>
      )}
    >
      <form id="ff-form" onSubmit={submit} noValidate>
        {general && <Notice tone="error">{general}</Notice>}
        <div className="mb-3">
          <label className="form-label" htmlFor="ff-code">{t('console.flag_code')}</label>
          <input id="ff-code" className={`form-control${errors.code ? ' is-invalid' : ''}`} value={form.code} disabled={flag !== null} onChange={(e) => setForm((f) => ({ ...f, code: e.target.value }))} aria-describedby="ff-code-help" />
          <div id="ff-code-help" className="form-text">{t('console.flag_code_help')}</div>
          {errors.code && <div className="invalid-feedback" role="alert">{errors.code}</div>}
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="ff-name">{t('console.flag_name')}</label>
          <input id="ff-name" className={`form-control${errors.name ? ' is-invalid' : ''}`} value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} />
          {errors.name && <div className="invalid-feedback" role="alert">{errors.name}</div>}
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="ff-desc">{t('console.flag_description')}</label>
          <textarea id="ff-desc" className="form-control" rows={2} value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} />
        </div>
        <div className="form-check mb-2">
          <input id="ff-default" type="checkbox" className="form-check-input" checked={form.default_enabled} onChange={(e) => setForm((f) => ({ ...f, default_enabled: e.target.checked }))} />
          <label className="form-check-label" htmlFor="ff-default">{t('console.flag_default_help')}</label>
        </div>
        <div className="form-check mb-3">
          <input id="ff-beta" type="checkbox" className="form-check-input" checked={form.is_beta} onChange={(e) => setForm((f) => ({ ...f, is_beta: e.target.checked }))} />
          <label className="form-check-label" htmlFor="ff-beta">{t('console.flag_beta')}</label>
        </div>

        <h3 className="panel-sec">{t('console.flag_rules')}</h3>
        <p className="small text-secondary">{t('console.flag_order_help')}</p>
        {rules.map((r, i) => (
          <div key={i} className="ff-regla mb-2">
            <label className="vh" htmlFor={`ff-kind-${i}`}>{t('console.flag_target')}</label>
            <select id={`ff-kind-${i}`} className="form-select" value={r.target_kind} onChange={(e) => setRule(i, { target_kind: e.target.value as FlagTarget })}>
              {TARGETS.map((k) => <option key={k} value={k}>{t(`console.flag_target_kind.${k}`)}</option>)}
            </select>
            <label className="vh" htmlFor={`ff-value-${i}`}>{t('console.flag_value')}</label>
            <input id={`ff-value-${i}`} className="form-control" value={r.target_value} placeholder={t(`console.flag_value_hint.${r.target_kind}`)} onChange={(e) => setRule(i, { target_value: e.target.value })} />
            <label className="vh" htmlFor={`ff-on-${i}`}>{t('console.flag_result')}</label>
            <select id={`ff-on-${i}`} className="form-select" value={r.enabled ? '1' : '0'} onChange={(e) => setRule(i, { enabled: e.target.value === '1' })}>
              <option value="1">{t('console.flag_on')}</option>
              <option value="0">{t('console.flag_off')}</option>
            </select>
            <button type="button" className="btn btn-sec btn-sm" aria-label={t('console.flag_remove_rule')} onClick={() => setRules((rs) => rs.filter((_, j) => j !== i))}><Icon name="close" /></button>
          </div>
        ))}
        <button type="button" className="btn btn-sec btn-sm" onClick={() => setRules((rs) => [...rs, { target_kind: 'country', target_value: '', enabled: true }])}><Icon name="plus" /> {t('console.flag_add_rule')}</button>
      </form>
    </SidePanel>
  )
}

/** Activaciones explícitas por organización: prevalecen sobre las reglas (RN-SAS-07). */
function FlagTenants({ flag, canEdit, onClose }: { flag: ConsoleFeatureFlag; canEdit: boolean; onClose: () => void }) {
  const { t, locale } = useT()
  const [rows, setRows] = useState<ConsoleFlagOverride[] | null>(null)
  const [q, setQ] = useState('')
  const [found, setFound] = useState<ConsoleTenant[]>([])
  const [tenantId, setTenantId] = useState('')
  const [enabled, setEnabled] = useState(true)
  const [reason, setReason] = useState('')
  const [expires, setExpires] = useState('')
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(() => {
    consoleApi.get<{ data: ConsoleFlagOverride[] }>(`/feature-flags/${flag.id}/tenants`).then((r) => setRows(r.data)).catch(() => setError(t('console.load_error')))
  }, [flag.id, t])
  useEffect(load, [load])

  useEffect(() => {
    if (!canEdit || q.trim().length < 2) { setFound([]); return }
    const timer = setTimeout(() => {
      consoleApi.get<{ data: ConsoleTenant[] }>(`/tenants?q=${encodeURIComponent(q.trim())}&per_page=10`).then((r) => setFound(r.data)).catch(() => setFound([]))
    }, 250)
    return () => clearTimeout(timer)
  }, [q, canEdit])

  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setError(null)
    try {
      const r = await consoleApi.post<{ data: ConsoleFlagOverride[] }>(`/feature-flags/${flag.id}/tenants`, {
        tenant_id: tenantId, enabled, reason: reason.trim() === '' ? null : reason.trim(), expires_at: expires === '' ? null : `${expires}T23:59:59Z`,
      })
      setRows(r.data)
      setTenantId('')
      setQ('')
      setReason('')
      setExpires('')
    } catch (err) {
      const r = formErrors(err, t)
      setError(r.fields.expires_at ?? r.fields.tenant_id ?? r.general)
    }
  }

  async function remove(row: ConsoleFlagOverride) {
    setError(null)
    try {
      const r = await consoleApi.post<{ data: ConsoleFlagOverride[] }>(`/feature-flags/${flag.id}/tenants/${row.tenant_id}/remove`, {})
      setRows(r.data)
    } catch (err) {
      setError(formErrors(err, t).general ?? t('console.load_error'))
    }
  }

  return (
    <SidePanel title={t('console.flag_tenants_title', { name: flag.name })} onClose={onClose} closeLabel={t('ui.close')} wide>
      <p className="small text-secondary">{t('console.flag_tenants_help')}</p>
      {error && <Notice tone="error" onDismiss={() => setError(null)}>{error}</Notice>}
      {rows === null && !error && <span className="esq" style={{ width: 240 }} />}
      {rows && rows.length === 0 && <p className="small text-secondary">{t('console.flag_tenants_empty')}</p>}
      {rows && rows.length > 0 && (
        <ul className="lista-compacta mb-3">
          {rows.map((r) => (
            <li key={r.tenant_id} className="fila-entre">
              <span>
                <b>{r.tenant_name}</b> · {t(r.enabled ? 'console.flag_on' : 'console.flag_off')}{r.expired && <> <Tag tone="wa">{t('console.flag_expired')}</Tag></>}
                <span className="sub2">{[r.slug, r.country, r.reason, r.expires_at ? t('console.flag_until', { date: formatDateTime(locale, r.expires_at) }) : null].filter(Boolean).join(' · ')}</span>
              </span>
              {canEdit && <button type="button" className="btn btn-sec btn-sm" onClick={() => void remove(r)}>{t('console.flag_remove_tenant')}</button>}
            </li>
          ))}
        </ul>
      )}
      {canEdit && (
        <form onSubmit={save} noValidate>
          <h3 className="panel-sec">{t('console.flag_set_tenant')}</h3>
          <div className="mb-2">
            <label className="form-label" htmlFor="ft-q">{t('console.flag_find_tenant')}</label>
            <input id="ft-q" className="form-control" value={q} onChange={(e) => { setQ(e.target.value); setTenantId('') }} />
          </div>
          {found.length > 0 && (
            <div className="mb-2">
              <label className="form-label" htmlFor="ft-tenant">{t('console.flag_tenant')}</label>
              <select id="ft-tenant" className="form-select" value={tenantId} onChange={(e) => setTenantId(e.target.value)}>
                <option value="">{t('console.flag_choose_tenant')}</option>
                {found.map((x) => <option key={x.id} value={x.id}>{x.name} · {x.slug}</option>)}
              </select>
            </div>
          )}
          <div className="mb-2">
            <label className="form-label" htmlFor="ft-on">{t('console.flag_result')}</label>
            <select id="ft-on" className="form-select" value={enabled ? '1' : '0'} onChange={(e) => setEnabled(e.target.value === '1')}>
              <option value="1">{t('console.flag_on')}</option>
              <option value="0">{t('console.flag_off')}</option>
            </select>
          </div>
          <div className="mb-2">
            <label className="form-label" htmlFor="ft-reason">{t('console.reason')}</label>
            <input id="ft-reason" className="form-control" value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
          <div className="mb-3">
            <label className="form-label" htmlFor="ft-exp">{t('console.flag_expires')}</label>
            <input id="ft-exp" type="date" className="form-control" value={expires} onChange={(e) => setExpires(e.target.value)} />
          </div>
          <button type="submit" className="btn btn-primary" disabled={tenantId === ''}>{t('console.save')}</button>
        </form>
      )}
    </SidePanel>
  )
}
