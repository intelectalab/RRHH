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
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag, type StatusTone } from '@/ui/components/Status'
import { useMoney } from './ConsolePlans'
import { useConsole, useConsoleTabs } from './ConsoleShell'
import { consoleApi, type ConsolePlanFit, type ConsoleAddon, type ConsolePlan, type ConsoleSubscription, type ConsoleSubscriptionEvent, type ConsoleSubscriptionStatus } from './consoleApi'
import { useMetricLabel } from '@/modules/usage/UsageTable'

const PER_PAGE = 25
const STATUSES: ConsoleSubscriptionStatus[] = ['trialing', 'active', 'expired', 'past_due', 'suspended', 'cancelled']

/** Acción de estado → permiso que la habilita y si exige motivo (el servidor también lo valida). */
const ACTIONS: Record<string, { perm: string; reason: boolean; danger: boolean }> = {
  convert: { perm: 'platform.subscriptions.change_plan', reason: false, danger: false },
  reactivate: { perm: 'platform.subscriptions.reactivate', reason: false, danger: false },
  suspend: { perm: 'platform.subscriptions.suspend', reason: true, danger: true },
  cancel: { perm: 'platform.tenants.cancel', reason: true, danger: true },
}

export function subscriptionTone(status: string): StatusTone {
  if (status === 'active') return 'ok'
  if (status === 'trialing') return 'in'
  if (status === 'expired' || status === 'past_due') return 'wa'
  if (status === 'suspended') return 'er'
  return 'gr'
}

/** SAS-4 · Suscripciones: plan, complementos, prueba y estado de cada organización. */
export function ConsoleSubscriptions() {
  const { t, locale } = useT()
  const tabs = useConsoleTabs()
  const money = useMoney()
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<ConsoleSubscription[] | null>(null)
  const [total, setTotal] = useState(0)
  const [failed, setFailed] = useState(false)
  const [open, setOpen] = useState<string | null>(null)

  const load = useCallback(() => {
    setFailed(false)
    const params = new URLSearchParams({ page: String(page), per_page: String(PER_PAGE) })
    if (status) params.set('filter[status]', status)
    consoleApi.get<{ data: ConsoleSubscription[]; meta: { total: number } }>(`/subscriptions?${params.toString()}`)
      .then((r) => { setRows(r.data); setTotal(r.meta.total) })
      .catch(() => setFailed(true))
  }, [page, status])
  useEffect(load, [load])

  useEffect(() => {
    const id = new URLSearchParams(window.location.search).get('id')
    if (id) setOpen(id)
  }, [])

  const pages = Math.max(1, Math.ceil(total / PER_PAGE))

  return (
    <>
      <PageHeader title={t('console.subs_title')} sub={t('console.subs_sub')} tabs={tabs} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="filtros" role="search">
          <label className="vh" htmlFor="sb-status">{t('console.tenant_status')}</label>
          <select id="sb-status" className="form-select" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">{t('console.tenant_all_status')}</option>
            {STATUSES.map((s) => <option key={s} value={s}>{t(`console.sstatus.${s}`)}</option>)}
          </select>
        </div>
        {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
        {!failed && !rows && <span className="esq" style={{ width: 320 }} />}
        {rows && rows.length === 0 && <EmptyState title={t('console.subs_empty_title')} text={t('console.subs_empty')} />}
        {rows && rows.length > 0 && (
          <section className="card">
            <div className="tabla-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">{t('console.tenant_name')}</th>
                    <th scope="col">{t('console.account_plan')}</th>
                    <th scope="col">{t('console.tenant_status')}</th>
                    <th scope="col">{t('console.plan_price')}</th>
                    <th scope="col">{t('console.tenant_trial_end')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((s) => (
                    <tr key={s.id}>
                      <td>
                        <button type="button" className="btn btn-ter" onClick={() => setOpen(s.id)}><b>{s.tenant?.name ?? s.tenant_id}</b></button>
                        <span className="sub2">{s.tenant?.slug}</span>
                      </td>
                      <td>
                        {s.plan?.name}
                        {s.plan && <span className="sub2">{t('console.plan_revision', { revision: s.plan.revision })}{!s.plan.is_current && <> · {t('console.plan_previous_conditions')}</>}</span>}
                      </td>
                      <td><StatusBadge tone={subscriptionTone(s.status)}>{t(`console.sstatus.${s.status}`)}</StatusBadge></td>
                      <td>{money(s.price)}<span className="sub2">{t(`console.cycle.${s.billing_cycle}`)}</span></td>
                      <td>{s.trial_ends_on ? formatDate(locale, s.trial_ends_on) : ''}</td>
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
      {open && <SubscriptionPanel id={open} onClose={() => setOpen(null)} onChanged={load} />}
    </>
  )
}

/** Ficha de la suscripción: acciones, cambio de plan, complementos e historial con autor. */
export function SubscriptionPanel({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: () => void }) {
  const { t, locale } = useT()
  const me = useConsole()
  const money = useMoney()
  const [sub, setSub] = useState<ConsoleSubscription | null>(null)
  const [events, setEvents] = useState<ConsoleSubscriptionEvent[]>([])
  const [plans, setPlans] = useState<ConsolePlan[]>([])
  const [addons, setAddons] = useState<ConsoleAddon[]>([])
  const [planId, setPlanId] = useState('')
  const [fit, setFit] = useState<ConsolePlanFit | null>(null)
  const [addonId, setAddonId] = useState('')
  const [action, setAction] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const can = (p: string) => me.permissions.includes(p)
  const metricLabel = useMetricLabel()

  const load = useCallback(() => {
    Promise.all([
      consoleApi.get<{ data: ConsoleSubscription }>(`/subscriptions/${id}`),
      consoleApi.get<{ data: ConsoleSubscriptionEvent[] }>(`/subscriptions/${id}/events`),
      consoleApi.get<{ data: ConsolePlan[] }>('/plans?filter[status]=active'),
      consoleApi.get<{ data: ConsoleAddon[] }>('/addons?filter[status]=active'),
    ]).then(([s, e, p, a]) => { setSub(s.data); setEvents(e.data); setPlans(p.data); setAddons(a.data) })
      .catch(() => setError(t('console.load_error')))
  }, [id, t])
  useEffect(load, [load])

  // SAS-6 · Antes de cambiar, se compara el consumo actual con los límites del plan elegido.
  useEffect(() => {
    setFit(null)
    if (planId === '') return
    consoleApi.get<{ data: ConsolePlanFit }>(`/subscriptions/${id}/plan-fit?plan_id=${planId}`).then((r) => setFit(r.data)).catch(() => setFit(null))
  }, [id, planId])

  async function post(path: string, body: Record<string, unknown> = {}) {
    setError(null)
    try {
      await consoleApi.post(path, body)
      load()
      onChanged()
    } catch (err) {
      setError(formErrors(err, t).general ?? t('console.load_error'))
    }
  }

  const actions = sub ? sub.allowed_actions.filter((a) => ACTIONS[a] && can(ACTIONS[a].perm)) : []
  const current = sub?.addons.filter((a) => a.current) ?? []
  const editable = sub !== null && sub.status !== 'cancelled'

  return (
    <SidePanel title={sub?.tenant?.name ?? t('console.subs_title')} onClose={onClose} closeLabel={t('ui.close')} wide>
      {error && <Notice tone="error" onDismiss={() => setError(null)}>{error}</Notice>}
      {!sub && !error && <span className="esq" style={{ width: 240 }} />}
      {sub && (
        <>
          <p>
            <StatusBadge tone={subscriptionTone(sub.status)}>{t(`console.sstatus.${sub.status}`)}</StatusBadge>{' '}
            <Link href={`/plataforma/organizaciones/${sub.tenant_id}`}>{t('console.subs_open_tenant')}</Link>
          </p>
          {sub.status === 'expired' && <Notice tone="warn">{t('console.subs_expired_note')}</Notice>}
          <dl className="ficha mb-3">
            <dt>{t('console.account_plan')}</dt>
            <dd>{sub.plan?.name} {sub.plan && <Tag>{t('console.plan_revision', { revision: sub.plan.revision })}</Tag>}</dd>
            <dt>{t('console.plan_price')}</dt><dd>{money(sub.price)} · {t(`console.cycle.${sub.billing_cycle}`)}</dd>
            <dt>{t('console.subs_starts')}</dt><dd>{formatDate(locale, sub.starts_on)}</dd>
            <dt>{t('console.tenant_trial_end')}</dt><dd>{sub.trial_ends_on ? formatDate(locale, sub.trial_ends_on) : t('console.none')}</dd>
            {sub.cancel_reason && <><dt>{t('console.reason')}</dt><dd>{sub.cancel_reason}</dd></>}
          </dl>
          {actions.length > 0 && (
            <div className="consola-acciones mb-3">
              {actions.map((a) => (
                <button key={a} type="button" className={`btn ${ACTIONS[a]?.danger ? 'btn-sec' : 'btn-primary'}`} onClick={() => setAction(a)}>{t(`console.saction.${a}`)}</button>
              ))}
            </div>
          )}

          {editable && can('platform.subscriptions.change_plan') && (
            <form className="mb-3" onSubmit={(e) => { e.preventDefault(); if (planId) void post(`/subscriptions/${id}/change-plan`, { plan_id: planId }) }}>
              <h3 className="panel-sec">{t('console.subs_change_plan')}</h3>
              <p className="small text-secondary">{t('console.subs_change_plan_help')}</p>
              <div className="fila-entre">
                <label className="vh" htmlFor="sp-plan">{t('console.account_plan')}</label>
                <select id="sp-plan" className="form-select" value={planId} onChange={(e) => setPlanId(e.target.value)}>
                  <option value="">{t('console.subs_choose_plan')}</option>
                  {plans.filter((p) => p.id !== sub.plan_id).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
                <button type="submit" className="btn btn-sec" disabled={!planId}>{t('console.subs_change')}</button>
              </div>
              {fit && fit.some((m) => m.over) && (
                <Notice tone="warn" title={t('console.plan_fit_over_title')}>
                  {t('console.plan_fit_over', { metrics: fit.filter((m) => m.over).map((m) => t('console.plan_fit_item', { metric: metricLabel(m.metric), current: m.current, limit: m.limit ?? '' })).join('; ') })}
                </Notice>
              )}
              {fit && !fit.some((m) => m.over) && <p className="small text-secondary mt-2">{t('console.plan_fit_ok')}</p>}
            </form>
          )}

          <h3 className="panel-sec">{t('console.addons_title')}</h3>
          {current.length === 0 && <p className="small text-secondary">{t('console.subs_no_addons')}</p>}
          {current.length > 0 && (
            <ul className="lista-compacta mb-2">
              {current.map((a) => (
                <li key={a.id} className="fila-entre">
                  <span>{a.name}<span className="sub2">{t('console.subs_addon_since', { date: formatDate(locale, a.starts_on) })}</span></span>
                  {editable && can('platform.subscriptions.update') && (
                    <button type="button" className="btn btn-sec btn-sm" onClick={() => void post(`/subscriptions/${id}/addons/${a.id}/remove`)}>{t('console.subs_remove_addon')}</button>
                  )}
                </li>
              ))}
            </ul>
          )}
          {editable && can('platform.subscriptions.update') && (
            <form className="fila-entre mb-3" onSubmit={(e) => { e.preventDefault(); if (addonId) void post(`/subscriptions/${id}/addons`, { addon_id: addonId }).then(() => setAddonId('')) }}>
              <label className="vh" htmlFor="sp-addon">{t('console.addons_title')}</label>
              <select id="sp-addon" className="form-select" value={addonId} onChange={(e) => setAddonId(e.target.value)}>
                <option value="">{t('console.subs_choose_addon')}</option>
                {addons.filter((a) => !current.some((c) => c.addon_id === a.id)).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
              <button type="submit" className="btn btn-sec" disabled={!addonId}>{t('console.subs_add_addon')}</button>
            </form>
          )}

          <h3 className="panel-sec">{t('console.section_history')}</h3>
          <ul className="lista-compacta">
            {events.map((e) => (
              <li key={e.id}>
                <b>{t(`console.sevent.${e.action.replace('subscription.', '')}`)}</b>
                <span className="sub2">{formatDateTime(locale, e.occurred_at)} · {e.actor ?? t('console.audit_system')}</span>
              </li>
            ))}
          </ul>
        </>
      )}
      {action && sub && <SubscriptionAction id={id} action={action} onClose={() => setAction(null)} onDone={() => { setAction(null); load(); onChanged() }} />}
    </SidePanel>
  )
}

function SubscriptionAction({ id, action, onClose, onDone }: { id: string; action: string; onClose: () => void; onDone: () => void }) {
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
      await consoleApi.post(`/subscriptions/${id}/${action}`, reason.trim() ? { reason: reason.trim() } : {})
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
      title={t(`console.saction.${action}`)}
      onClose={onClose}
      tone={spec?.danger ? 'danger' : 'normal'}
      footer={(
        <>
          <button type="button" className="btn btn-sec" onClick={onClose}>{t('console.cancel')}</button>
          <button type="submit" form="sa-form" className="btn btn-primary" disabled={busy}>{t(`console.saction.${action}`)}</button>
        </>
      )}
    >
      <form id="sa-form" onSubmit={confirm} noValidate>
        <p>{t(`console.saction_help.${action}`)}</p>
        {spec?.reason && (
          <div className="mb-3">
            <label className="form-label" htmlFor="sa-reason">{t('console.reason')}</label>
            <textarea id="sa-reason" className={`form-control${error ? ' is-invalid' : ''}`} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
        )}
        {error && <Notice tone="error">{error}</Notice>}
      </form>
    </Modal>
  )
}
