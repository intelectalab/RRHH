'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag, type StatusTone } from '@/ui/components/Status'
import { useConsole, useConsoleTabs } from './ConsoleShell'
import { consoleApi, type ConsoleAddon, type ConsoleModuleInfo, type ConsoleMoney, type ConsolePlan } from './consoleApi'

export function planTone(status: string): StatusTone {
  if (status === 'active') return 'ok'
  if (status === 'draft') return 'in'
  return 'gr'
}

/** Monto tal como lo envía la API ({amount, currency}); sin precio, el texto lo dice. */
export function useMoney() {
  const { t } = useT()
  return (m: ConsoleMoney | undefined) => (m ? t('console.amount', { amount: m.amount, currency: m.currency }) : t('console.no_price'))
}

/**
 * SAS-3 · Planes y complementos. Los nombres son libres y el precio lo fija el operador; editar un plan
 * con suscriptores crea otra versión y la anterior se conserva para ellos (RN-SAS-11).
 */
export function ConsolePlans() {
  const { t } = useT()
  const me = useConsole()
  const tabs = useConsoleTabs()
  const money = useMoney()
  const [plans, setPlans] = useState<ConsolePlan[] | null>(null)
  const [catalog, setCatalog] = useState<ConsoleModuleInfo[]>([])
  const [addons, setAddons] = useState<ConsoleAddon[]>([])
  const [failed, setFailed] = useState(false)
  const [editing, setEditing] = useState<ConsolePlan | 'new' | null>(null)
  const [editingAddon, setEditingAddon] = useState<ConsoleAddon | null>(null)
  const [versionsOf, setVersionsOf] = useState<ConsolePlan | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const can = (p: string) => me.permissions.includes(p)

  const load = useCallback(() => {
    setFailed(false)
    Promise.all([
      consoleApi.get<{ data: ConsolePlan[]; meta: { modules: ConsoleModuleInfo[] } }>('/plans'),
      consoleApi.get<{ data: ConsoleAddon[] }>('/addons'),
    ]).then(([p, a]) => { setPlans(p.data); setCatalog(p.meta.modules); setAddons(a.data) }).catch(() => setFailed(true))
  }, [])
  useEffect(load, [load])

  async function run(path: string, done?: string) {
    setError(null)
    setNotice(null)
    try {
      await consoleApi.post(path, {})
      if (done) setNotice(done)
      load()
    } catch (err) {
      setError(formErrors(err, t).general ?? t('console.load_error'))
    }
  }

  const names = new Map(catalog.map((m) => [m.code, m.name]))
  // Lista corta: los primeros módulos y cuántos más (el detalle está en el editor).
  const moduleNames = (codes: string[]) => {
    const list = codes.map((c) => names.get(c) ?? c)
    return list.length <= 5 ? list.join(', ') : t('console.modules_more', { list: list.slice(0, 4).join(', '), n: list.length - 4 })
  }

  return (
    <>
      <PageHeader
        title={t('console.plans_title')}
        sub={t('console.plans_sub')}
        tabs={tabs}
        actions={can('platform.plans.create') ? <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('console.plan_new')}</button> : undefined}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error" onDismiss={() => setError(null)}>{error}</Notice>}
        {notice && <Notice tone="ok" onDismiss={() => setNotice(null)}>{notice}</Notice>}
        {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
        {!failed && !plans && <span className="esq" style={{ width: 320 }} />}
        {plans && plans.length === 0 && <EmptyState title={t('console.plans_empty_title')} text={t('console.plans_empty')} />}
        {plans && plans.length > 0 && (
          <section className="card mb-3">
            <div className="tabla-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">{t('console.plan_name')}</th>
                    <th scope="col">{t('console.tenant_status')}</th>
                    <th scope="col">{t('console.plan_price')}</th>
                    <th scope="col">{t('console.plan_modules')}</th>
                    <th scope="col" className="text-end">{t('console.plan_subscribers')}</th>
                    <th scope="col"><span className="vh">{t('console.module_action')}</span></th>
                  </tr>
                </thead>
                <tbody>
                  {plans.map((p) => (
                    <tr key={p.id}>
                      <td>
                        <b>{p.name}</b>{!p.is_public && <> <Tag>{t('console.plan_private')}</Tag></>}
                        <span className="sub2">{t('console.plan_code_revision', { code: p.code, revision: p.revision })}</span>
                      </td>
                      <td><StatusBadge tone={planTone(p.status)}>{t(`console.pstatus.${p.status}`)}</StatusBadge></td>
                      <td>{money(p.price)}<span className="sub2">{t(`console.cycle.${p.billing_cycle}`)}</span></td>
                      <td className="text-secondary">{moduleNames(p.modules)}</td>
                      <td className="text-end">{p.subscribers}</td>
                      <td className="text-end"><div className="consola-acciones consola-acciones-fila">
                        {can('platform.plans.update') && <button type="button" className="btn btn-sec btn-sm" onClick={() => setEditing(p)}>{t('console.edit')}</button>}
                        {can('platform.plans.duplicate') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(`/plans/${p.id}/duplicate`, t('console.plan_duplicated'))}>{t('console.plan_duplicate')}</button>}
                        {p.allowed_actions.includes('activate') && can('platform.plans.activate') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(`/plans/${p.id}/activate`)}>{t('console.plan_activate')}</button>}
                        {p.allowed_actions.includes('retire') && can('platform.plans.retire') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(`/plans/${p.id}/retire`)}>{t('console.plan_retire')}</button>}
                        {p.revision > 1 && <button type="button" className="btn btn-sec btn-sm" onClick={() => setVersionsOf(p)}>{t('console.plan_versions')}</button>}
                      </div></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}

        <section className="card">
          <div className="card-header"><h2>{t('console.addons_title')}</h2></div>
          <div className="card-body"><p className="small text-secondary mb-0">{t('console.addons_sub')}</p></div>
          <div className="tabla-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th scope="col">{t('console.plan_name')}</th>
                  <th scope="col">{t('console.tenant_status')}</th>
                  <th scope="col">{t('console.plan_price')}</th>
                  <th scope="col">{t('console.plan_modules')}</th>
                  <th scope="col"><span className="vh">{t('console.module_action')}</span></th>
                </tr>
              </thead>
              <tbody>
                {addons.map((a) => (
                  <tr key={a.id}>
                    <td><b>{a.name}</b><span className="sub2">{t(`console.addon_kind.${a.kind}`)}</span></td>
                    <td><StatusBadge tone={planTone(a.status)}>{t(`console.pstatus.${a.status}`)}</StatusBadge></td>
                    <td>{money(a.price)}<span className="sub2">{t(`console.cycle.${a.billing_cycle}`)}</span></td>
                    <td className="text-secondary">{moduleNames(a.modules)}</td>
                    <td className="text-end"><div className="consola-acciones consola-acciones-fila">
                      {can('platform.plans.update') && <button type="button" className="btn btn-sec btn-sm" onClick={() => setEditingAddon(a)}>{t('console.edit')}</button>}
                      {a.allowed_actions.includes('activate') && can('platform.plans.activate') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(`/addons/${a.id}/activate`)}>{t('console.plan_activate')}</button>}
                      {a.allowed_actions.includes('retire') && can('platform.plans.retire') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(`/addons/${a.id}/retire`)}>{t('console.plan_retire')}</button>}
                    </div></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {editing && (
        <PlanEditor
          plan={editing === 'new' ? null : editing}
          catalog={catalog}
          onClose={() => setEditing(null)}
          onDone={(saved) => {
            setEditing(null)
            setNotice(editing !== 'new' && saved.id !== editing.id ? t('console.plan_new_revision', { revision: saved.revision }) : null)
            load()
          }}
        />
      )}
      {editingAddon && <AddonEditor addon={editingAddon} onClose={() => setEditingAddon(null)} onDone={() => { setEditingAddon(null); load() }} />}
      {versionsOf && <PlanVersions plan={versionsOf} onClose={() => setVersionsOf(null)} />}
    </>
  )
}

type Limit = { code: string; value: string; unlimited: boolean }

function PlanEditor({ plan, catalog, onClose, onDone }: { plan: ConsolePlan | null; catalog: ConsoleModuleInfo[]; onClose: () => void; onDone: (p: ConsolePlan) => void }) {
  const { t } = useT()
  const [form, setForm] = useState({
    code: plan?.code ?? '', name: plan?.name ?? '', description: plan?.description ?? '',
    billing_cycle: plan?.billing_cycle ?? 'monthly', amount: plan?.price?.amount ?? '', currency: plan?.price?.currency ?? 'PEN',
    trial_days: plan?.trial_days === null || plan?.trial_days === undefined ? '' : String(plan.trial_days), is_public: plan?.is_public ?? true,
    features: (plan?.features ?? []).join(', '),
  })
  const [modules, setModules] = useState<string[]>(plan?.modules ?? catalog.filter((m) => m.is_core).map((m) => m.code))
  const [limits, setLimits] = useState<Limit[]>((plan?.limits ?? [{ code: 'employees', value: null, unlimited: false }]).map((l) => ({ code: l.code, value: l.value ?? '', unlimited: l.unlimited })))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  function toggle(code: string) {
    setModules((list) => (list.includes(code) ? list.filter((c) => c !== code) : [...list, code]))
  }

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setGeneral(null)
    const body: Record<string, unknown> = {
      name: form.name, description: form.description || null, billing_cycle: form.billing_cycle,
      price: form.amount.trim() ? { amount: form.amount.trim(), currency: form.currency.trim().toUpperCase() } : null,
      trial_days: form.trial_days === '' ? null : Number(form.trial_days), is_public: form.is_public,
      modules,
      limits: limits.filter((l) => l.code.trim()).map((l) => ({ code: l.code.trim(), value: l.unlimited ? null : l.value.trim(), unlimited: l.unlimited })),
      features: form.features.split(',').map((f) => f.trim()).filter(Boolean),
    }
    try {
      const r = plan
        ? await consoleApi.request<{ data: ConsolePlan }>(`/plans/${plan.id}`, 'PATCH', body)
        : await consoleApi.post<{ data: ConsolePlan }>('/plans', { ...body, code: form.code.trim() })
      onDone(r.data)
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      setGeneral(r.general)
    } finally {
      setBusy(false)
    }
  }

  const input = (k: 'code' | 'name' | 'description' | 'amount' | 'currency' | 'trial_days' | 'features', label: string, opts: { type?: string; help?: string; error?: string } = {}) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`pl-${k}`}>{label}</label>
      <input id={`pl-${k}`} type={opts.type ?? 'text'} className={`form-control${errors[opts.error ?? k] ? ' is-invalid' : ''}`} value={form[k]}
        onChange={(e) => setForm((f) => ({ ...f, [k]: e.target.value }))} aria-describedby={opts.help ? `pl-${k}-h` : undefined} />
      {opts.help && <div id={`pl-${k}-h`} className="sub2">{opts.help}</div>}
      {errors[opts.error ?? k] && <div className="invalid-feedback" role="alert">{errors[opts.error ?? k]}</div>}
    </div>
  )

  return (
    <SidePanel
      title={plan ? t('console.plan_edit', { name: plan.name }) : t('console.plan_new')}
      onClose={onClose}
      closeLabel={t('ui.close')}
      wide
      footer={(
        <>
          <button type="button" className="btn btn-sec" onClick={onClose}>{t('console.cancel')}</button>
          <button type="submit" form="pl-form" className="btn btn-primary" disabled={busy}>{t('console.save')}</button>
        </>
      )}
    >
      <form id="pl-form" onSubmit={submit} noValidate>
        {general && <Notice tone="error">{general}</Notice>}
        {plan && plan.subscribers > 0 && <Notice tone="info">{t('console.plan_in_use', { n: plan.subscribers })}</Notice>}
        {!plan && input('code', t('console.plan_code'), { help: t('console.plan_code_help') })}
        {input('name', t('console.plan_name'))}
        {input('description', t('console.plan_description'))}
        <div className="form-2col">
          {input('amount', t('console.plan_price'), { help: t('console.plan_price_help'), error: 'price.amount' })}
          {input('currency', t('console.tenant_currency'), { error: 'price.currency' })}
          <div className="mb-3">
            <label className="form-label" htmlFor="pl-cycle">{t('console.plan_cycle')}</label>
            <select id="pl-cycle" className="form-select" value={form.billing_cycle} onChange={(e) => setForm((f) => ({ ...f, billing_cycle: e.target.value as 'monthly' | 'annual' }))}>
              {(['monthly', 'annual'] as const).map((c) => <option key={c} value={c}>{t(`console.cycle.${c}`)}</option>)}
            </select>
          </div>
          {input('trial_days', t('console.plan_trial_days'), { type: 'number' })}
        </div>
        <div className="form-check mb-3">
          <input id="pl-public" type="checkbox" className="form-check-input" checked={form.is_public} onChange={(e) => setForm((f) => ({ ...f, is_public: e.target.checked }))} />
          <label className="form-check-label" htmlFor="pl-public">{t('console.plan_public')}</label>
        </div>

        <h3 className="panel-sec">{t('console.plan_modules')}</h3>
        <p className="small text-secondary">{t('console.plan_modules_help')}</p>
        {errors.modules && <Notice tone="error">{errors.modules}</Notice>}
        <fieldset className="consola-modulos mb-3">
          <legend className="vh">{t('console.plan_modules')}</legend>
          {catalog.map((m) => (
            <div key={m.code} className="form-check">
              <input id={`pl-m-${m.code}`} type="checkbox" className="form-check-input" checked={m.is_core || modules.includes(m.code)} disabled={m.is_core} onChange={() => toggle(m.code)} />
              <label className="form-check-label" htmlFor={`pl-m-${m.code}`}>
                {m.name}
                {m.depends_on.length > 0 && <span className="sub2">{t('console.module_requires', { modules: m.depends_on.map((d) => catalog.find((c) => c.code === d)?.name ?? d).join(', ') })}</span>}
              </label>
            </div>
          ))}
        </fieldset>

        <h3 className="panel-sec">{t('console.plan_limits')}</h3>
        {limits.map((l, i) => (
          <div key={i} className="form-2col mb-2">
            <div>
              <label className="form-label" htmlFor={`pl-l-${i}`}>{t('console.limit_code')}</label>
              <input id={`pl-l-${i}`} className={`form-control${errors[`limits.${i}`] ? ' is-invalid' : ''}`} value={l.code} onChange={(e) => setLimits((ls) => ls.map((x, j) => (j === i ? { ...x, code: e.target.value } : x)))} />
              {errors[`limits.${i}`] && <div className="invalid-feedback" role="alert">{errors[`limits.${i}`]}</div>}
            </div>
            <div>
              <label className="form-label" htmlFor={`pl-lv-${i}`}>{t('console.limit_value')}</label>
              <input id={`pl-lv-${i}`} className="form-control" inputMode="decimal" value={l.value} disabled={l.unlimited} onChange={(e) => setLimits((ls) => ls.map((x, j) => (j === i ? { ...x, value: e.target.value } : x)))} />
              <div className="form-check">
                <input id={`pl-lu-${i}`} type="checkbox" className="form-check-input" checked={l.unlimited} onChange={(e) => setLimits((ls) => ls.map((x, j) => (j === i ? { ...x, unlimited: e.target.checked } : x)))} />
                <label className="form-check-label" htmlFor={`pl-lu-${i}`}>{t('console.limit_unlimited')}</label>
              </div>
            </div>
          </div>
        ))}
        <button type="button" className="btn btn-sec btn-sm mb-3" onClick={() => setLimits((ls) => [...ls, { code: '', value: '', unlimited: false }])}><Icon name="plus" /> {t('console.limit_add')}</button>

        {input('features', t('console.plan_features'), { help: t('console.plan_features_help') })}
      </form>
    </SidePanel>
  )
}

function AddonEditor({ addon, onClose, onDone }: { addon: ConsoleAddon; onClose: () => void; onDone: () => void }) {
  const { t } = useT()
  const [form, setForm] = useState({ name: addon.name, amount: addon.price?.amount ?? '', currency: addon.price?.currency ?? 'PEN', billing_cycle: addon.billing_cycle })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    try {
      await consoleApi.request(`/addons/${addon.id}`, 'PATCH', {
        name: form.name, billing_cycle: form.billing_cycle,
        price: form.amount.trim() ? { amount: form.amount.trim(), currency: form.currency.trim().toUpperCase() } : null,
      })
      onDone()
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      setGeneral(r.general)
    }
  }

  return (
    <SidePanel
      title={t('console.addon_edit', { name: addon.name })}
      onClose={onClose}
      closeLabel={t('ui.close')}
      footer={(
        <>
          <button type="button" className="btn btn-sec" onClick={onClose}>{t('console.cancel')}</button>
          <button type="submit" form="ad-form" className="btn btn-primary">{t('console.save')}</button>
        </>
      )}
    >
      <form id="ad-form" onSubmit={submit} noValidate>
        {general && <Notice tone="error">{general}</Notice>}
        <div className="mb-3">
          <label className="form-label" htmlFor="ad-name">{t('console.plan_name')}</label>
          <input id="ad-name" className={`form-control${errors.name ? ' is-invalid' : ''}`} value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} />
          {errors.name && <div className="invalid-feedback" role="alert">{errors.name}</div>}
        </div>
        <div className="form-2col">
          <div className="mb-3">
            <label className="form-label" htmlFor="ad-amount">{t('console.plan_price')}</label>
            <input id="ad-amount" className={`form-control${errors['price.amount'] ? ' is-invalid' : ''}`} inputMode="decimal" value={form.amount} onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))} />
            {errors['price.amount'] && <div className="invalid-feedback" role="alert">{errors['price.amount']}</div>}
          </div>
          <div className="mb-3">
            <label className="form-label" htmlFor="ad-currency">{t('console.tenant_currency')}</label>
            <input id="ad-currency" className={`form-control${errors['price.currency'] ? ' is-invalid' : ''}`} value={form.currency} onChange={(e) => setForm((f) => ({ ...f, currency: e.target.value }))} />
            {errors['price.currency'] && <div className="invalid-feedback" role="alert">{errors['price.currency']}</div>}
          </div>
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="ad-cycle">{t('console.plan_cycle')}</label>
          <select id="ad-cycle" className="form-select" value={form.billing_cycle} onChange={(e) => setForm((f) => ({ ...f, billing_cycle: e.target.value as ConsoleAddon['billing_cycle'] }))}>
            {(['monthly', 'annual', 'one_time'] as const).map((c) => <option key={c} value={c}>{t(`console.cycle.${c}`)}</option>)}
          </select>
        </div>
      </form>
    </SidePanel>
  )
}

function PlanVersions({ plan, onClose }: { plan: ConsolePlan; onClose: () => void }) {
  const { t } = useT()
  const money = useMoney()
  const [rows, setRows] = useState<ConsolePlan[] | null>(null)

  useEffect(() => {
    consoleApi.get<{ data: ConsolePlan[] }>(`/plans/${plan.id}/versions`).then((r) => setRows(r.data)).catch(() => setRows([]))
  }, [plan.id])

  return (
    <SidePanel title={t('console.plan_versions_of', { name: plan.name })} onClose={onClose} closeLabel={t('ui.close')}>
      <p className="small text-secondary">{t('console.plan_versions_help')}</p>
      {!rows && <span className="esq" style={{ width: 240 }} />}
      {rows && (
        <ul className="lista-compacta">
          {rows.map((v) => (
            <li key={v.id}>
              <b>{t('console.plan_revision', { revision: v.revision })}</b>{' '}
              <StatusBadge tone={planTone(v.status)}>{t(`console.pstatus.${v.status}`)}</StatusBadge>
              <span className="sub2">{money(v.price)} · {t('console.plan_subscribers_n', { n: v.subscribers })}</span>
            </li>
          ))}
        </ul>
      )}
    </SidePanel>
  )
}
