'use client'

import { useCallback, useEffect, useState } from 'react'
import { formatDateTime, formatNumber, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useConsoleTabs } from './ConsoleShell'
import { consoleApi, type ConsoleMetrics, type ConsolePlan } from './consoleApi'

type Money = { amount: string; currency: string }

function daysAgo(n: number): string {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return localToday(d)
}

/**
 * SAS-10 · Tablero del negocio SaaS: organizaciones, usuarios, colaboradores, ingresos recurrentes, pruebas,
 * conversiones, cancelaciones, almacenamiento, módulos e incidencias, por periodo y por plan. Cada cifra la calcula
 * la API y viaja con su definición, que se muestra junto a ella (sin datos laborales de las organizaciones).
 */
export function ConsoleDashboard() {
  const { t, locale } = useT()
  const tabs = useConsoleTabs()
  const [data, setData] = useState<ConsoleMetrics | null>(null)
  const [failed, setFailed] = useState(false)
  const [from, setFrom] = useState(daysAgo(30))
  const [to, setTo] = useState(localToday())
  const [plan, setPlan] = useState('')
  const [plans, setPlans] = useState<ConsolePlan[]>([])

  const load = useCallback(() => {
    setFailed(false)
    const q = new URLSearchParams({ from, to, ...(plan ? { plan_id: plan } : {}) })
    consoleApi.get<{ data: ConsoleMetrics }>(`/metrics?${q.toString()}`).then((r) => setData(r.data)).catch(() => setFailed(true))
  }, [from, to, plan])
  useEffect(load, [load])
  useEffect(() => {
    consoleApi.get<{ data: ConsolePlan[] }>('/plans?per_page=100').then((r) => setPlans(r.data)).catch(() => setPlans([]))
  }, [])

  const nf = (v: number | string) => formatNumber(locale, String(v))
  const money = (m: Money) => `${m.currency} ${formatNumber(locale, m.amount)}`
  const def = (key: string) => data?.definitions[key] ?? ''
  const cards = data ? [
    { key: 'tenants_active', value: nf(data.tenants.active) },
    { key: 'tenants_trial', value: nf(data.tenants.trial) },
    { key: 'tenants_suspended', value: nf(data.tenants.suspended) },
    { key: 'tenants_new', value: nf(data.tenants.new) },
    { key: 'users_active', value: nf(data.users.active) },
    { key: 'employees_managed', value: nf(data.employees_managed) },
    { key: 'storage_mb', value: nf(data.storage_mb) },
    { key: 'trials_started', value: nf(data.subscriptions.trials_started) },
    { key: 'conversions', value: nf(data.subscriptions.conversions) },
    { key: 'conversion_rate_pct', value: data.subscriptions.conversion_rate_pct === null ? '—' : `${nf(data.subscriptions.conversion_rate_pct)} %` },
    { key: 'cancellations', value: nf(data.subscriptions.cancellations) },
    { key: 'incidents_open', value: nf(data.incidents.open) },
  ] : []

  return (
    <>
      <PageHeader title={t('console.dashboard_title')} sub={t('console.dashboard_sub')} tabs={tabs} />
      <main className="main" id="contenido" tabIndex={-1}>
        <form className="d-flex flex-wrap gap-2 align-items-end mb-3" onSubmit={(e) => e.preventDefault()} aria-label={t('console.metrics.filters')}>
          <div>
            <label className="form-label" htmlFor="m-from">{t('console.metrics.from')}</label>
            <input id="m-from" type="date" className="form-control" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div>
            <label className="form-label" htmlFor="m-to">{t('console.metrics.to')}</label>
            <input id="m-to" type="date" className="form-control" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
          </div>
          <div>
            <label className="form-label" htmlFor="m-plan">{t('console.metrics.plan')}</label>
            <select id="m-plan" className="form-select" value={plan} onChange={(e) => setPlan(e.target.value)}>
              <option value="">{t('console.metrics.all_plans')}</option>
              {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </div>
        </form>
        {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
        {!failed && !data && <span className="esq" style={{ width: 320 }} />}
        {data && (
          <>
            <div className="consola-cifras">
              {cards.map((c) => (
                <section key={c.key} className="card consola-cifra">
                  <div className="card-body">
                    <span className="small text-secondary">{t(`console.metrics.${c.key}`)}</span>
                    <b>{c.value}</b>
                    <span className="small text-secondary d-block">{def(c.key)}</span>
                  </div>
                </section>
              ))}
            </div>
            <section className="card mt-3">
              <div className="card-header">
                <h2>{t('console.metrics.revenue')}</h2>
                <span className="ctx">{t('console.generated_at', { time: formatDateTime(locale, data.generated_at) })}</span>
              </div>
              <div className="card-body">
                {data.revenue.mrr.length === 0 ? (
                  <EmptyState title={t('console.metrics.no_revenue')} text={def('mrr')} />
                ) : (
                  <table className="table">
                    <thead><tr><th>{t('console.metrics.currency')}</th><th className="text-end">{t('console.metrics.mrr')}</th><th className="text-end">{t('console.metrics.arr')}</th></tr></thead>
                    <tbody>
                      {data.revenue.mrr.map((m, i) => (
                        <tr key={m.currency}><td>{m.currency}</td><td className="text-end">{money(m)}</td><td className="text-end">{data.revenue.arr[i] ? money(data.revenue.arr[i]) : '—'}</td></tr>
                      ))}
                      <tr>
                        <td><b>{t('console.metrics.consolidated', { currency: data.revenue.consolidated.currency })}</b></td>
                        <td className="text-end"><b>{money(data.revenue.consolidated.mrr)}</b></td>
                        <td className="text-end"><b>{money(data.revenue.consolidated.arr)}</b></td>
                      </tr>
                    </tbody>
                  </table>
                )}
                <p className="small text-secondary">{def('mrr')} {def('consolidated')}</p>
                {data.revenue.consolidated.missing_rates.length > 0 && (
                  <div className="alert alert-warning" role="status">{t('console.metrics.missing_rates', { list: data.revenue.consolidated.missing_rates.join(', ') })}</div>
                )}
              </div>
            </section>
            <section className="card mt-3">
              <div className="card-header"><h2>{t('console.metrics.modules')}</h2></div>
              <div className="card-body">
                <p className="small text-secondary">{def('modules')}</p>
                {data.modules.length === 0 ? <EmptyState title={t('console.no_tenants')} text={def('modules')} /> : (
                  <table className="table">
                    <tbody>
                      {data.modules.map((m) => <tr key={m.code}><td>{m.name}</td><td className="text-end">{nf(m.tenants)}</td></tr>)}
                    </tbody>
                  </table>
                )}
              </div>
            </section>
            <section className="card mt-3">
              <div className="card-header"><h2>{t('console.by_status')}</h2></div>
              <div className="card-body">
                <table className="table">
                  <tbody>
                    {Object.entries(data.tenants.by_status).sort((a, b) => b[1] - a[1]).map(([status, n]) => (
                      <tr key={status}><td>{t(`console.status.${status}`)}</td><td className="text-end">{nf(n)}</td></tr>
                    ))}
                  </tbody>
                </table>
                <p className="small text-secondary">{t('console.metrics.incidents_period', { n: data.incidents.in_period })}</p>
              </div>
            </section>
          </>
        )}
      </main>
    </>
  )
}
