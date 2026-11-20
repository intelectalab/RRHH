'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { ALERT_TONE, type AnnualFigure, type SmallBusinessStatus } from './payrollRuns'

type Entity = { id: string; legal_name: string }

/**
 * Planilla → Micro y pequeña empresa (NOM-10). Por razón social: régimen y acreditación, ventas en UIT de los
 * últimos ejercicios contra el tope con su alerta (riesgo de salida, permanencia), registro de ventas y
 * dotación por ejercicio y cambio masivo de régimen con fecha efectiva. Los topes, la permanencia y el
 * régimen que se propone los calcula el servidor.
 */
export function SmallBusinessScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [entities, setEntities] = useState<Entity[]>([])
  const [entity, setEntity] = useState('')
  const [status, setStatus] = useState<SmallBusinessStatus | null>(null)
  const [figures, setFigures] = useState<AnnualFigure[]>([])
  const [form, setForm] = useState({ fiscal_year: String(new Date().getFullYear() - 1), net_sales: '', uit_value: '', average_headcount: '' })
  const [change, setChange] = useState({ labor_regime_code: '', effective_on: '', reason: '' })
  const [error, setError] = useState<string | null>(null)

  const [regimes, setRegimes] = useState<{ code: string; name: string; supported: boolean }[]>([])

  useEffect(() => {
    void api.get<{ data: { code: string; name: string; supported: boolean }[] }>('/payroll-regimes').then((r) => setRegimes(r.data)).catch(() => setRegimes([]))
    void api.get<{ data: Entity[] }>('/legal-entities?per_page=100')
      .then((r) => { setEntities(r.data); if (r.data.length === 1) setEntity(r.data[0]?.id ?? '') })
      .catch(() => setEntities([]))
  }, [])

  const load = useCallback(async () => {
    if (entity === '') return
    setError(null)
    try {
      const [s, f] = await Promise.all([
        api.get<{ data: SmallBusinessStatus }>(`/legal-entities/${entity}/small-business-status`),
        api.get<{ data: AnnualFigure[] }>(`/legal-entity-annual-figures?filter[legal_entity_id]=${entity}`),
      ])
      setStatus(s.data)
      setFigures(f.data)
    } catch (e) {
      setStatus(null)
      setError(e instanceof Error ? e.message : t('myp.error'))
    }
  }, [entity, t])

  useEffect(() => { void load() }, [load])

  const save = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const currency = figures[0]?.net_sales.currency ?? 'PEN'
    try {
      await api.post('/legal-entity-annual-figures', {
        legal_entity_id: entity, fiscal_year: Number(form.fiscal_year),
        net_sales: { amount: form.net_sales, currency }, uit_value: { amount: form.uit_value, currency },
        average_headcount: form.average_headcount === '' ? null : form.average_headcount,
      })
      toast(t('myp.saved'))
      setForm((f) => ({ ...f, net_sales: '', average_headcount: '' }))
      await load()
    } catch (err) {
      toast(err instanceof Error ? err.message : t('myp.error'), 'error')
    }
  }

  const applyChange = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!window.confirm(t('myp.confirm_change'))) return
    try {
      const r = await api.post<{ data: { changed: number; errors: unknown[] } }>(`/legal-entities/${entity}/labor-regime-change`, change)
      toast(t('myp.changed', { n: r.data.changed, errors: r.data.errors.length }))
      await load()
    } catch (err) {
      toast(err instanceof Error ? err.message : t('myp.error'), 'error')
    }
  }

  return (
    <>
      <PageHeader title={t('myp.title')} sub={t('myp.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="card" style={{ padding: 16 }}>
          <label className="form-label" htmlFor="myp-entity">{t('myp.entity')}</label>
          <select id="myp-entity" className="form-select" value={entity} onChange={(e) => setEntity(e.target.value)}>
            <option value="">{t('myp.choose')}</option>
            {entities.map((x) => <option key={x.id} value={x.id}>{x.legal_name}</option>)}
          </select>
        </div>
        {error && <Notice tone="error">{error}</Notice>}
        {entity === '' && <EmptyState title={t('myp.empty_title')} text={t('myp.empty_text')} />}

        {status && (
          <section className="card">
            <div className="card-header"><h2>{t('myp.status')}</h2></div>
            <div style={{ padding: '0 16px 16px' }}>
              <p>{t('myp.regime_line', { regime: status.labor_regime_code ?? '—', status: status.accreditation_status ? t(`myp.acc_${status.accreditation_status}`) : '—' })}</p>
              {status.accredited_on && <p className="sub2">{t('myp.accredited_on', { date: formatDate(locale, status.accredited_on), number: status.registry_number ?? '—' })}</p>}
              {status.requires_accreditation && (
                <Notice tone={ALERT_TONE[status.alert]}>
                  {t(`myp.alert_${status.alert}`, { years: status.consecutive_years_exceeding, ends: status.grace_period_ends_on ? formatDate(locale, status.grace_period_ends_on) : '—' })}
                </Notice>
              )}
              <p className="sub2">{t('myp.proposed', { regime: status.proposed_regime_for_new_hires ?? '—' })}</p>
            </div>
          </section>
        )}

        {entity !== '' && (
          <section className="card">
            <div className="card-header"><h2>{t('myp.figures')}</h2></div>
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('myp.col_year')}</th><th>{t('myp.col_sales')}</th><th>{t('myp.col_uit')}</th><th>{t('myp.col_in_uit')}</th><th>{t('myp.col_cap')}</th><th>{t('myp.col_headcount')}</th></tr></thead>
                <tbody>
                  {figures.length === 0 && <tr><td colSpan={6} className="sub2">{t('myp.no_figures')}</td></tr>}
                  {figures.map((f) => {
                    const y = status?.years.find((x) => x.fiscal_year === f.fiscal_year)
                    return (
                      <tr key={f.id}>
                        <td>{f.fiscal_year}</td>
                        <td className="nowrap">{f.net_sales.currency} {formatNumber(locale, f.net_sales.amount)}</td>
                        <td className="nowrap">{f.uit_value.currency} {formatNumber(locale, f.uit_value.amount)}</td>
                        <td>{formatNumber(locale, String(f.sales_in_uit))}{y?.exceeds && <span className="tag wa" style={{ marginLeft: 6 }}>{t('myp.exceeds')}</span>}</td>
                        <td>{y?.cap_in_uit ?? '—'}</td>
                        <td>{f.average_headcount ?? '—'}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            {can('payroll.entity_annual_figures.create') && (
              <form onSubmit={(e) => void save(e)} style={{ padding: 16, display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
                <div><label className="form-label" htmlFor="myp-year">{t('myp.col_year')}</label><input id="myp-year" className="form-control" inputMode="numeric" value={form.fiscal_year} onChange={(e) => setForm((f) => ({ ...f, fiscal_year: e.target.value }))} required /></div>
                <div><label className="form-label" htmlFor="myp-sales">{t('myp.col_sales')}</label><input id="myp-sales" className="form-control" inputMode="decimal" value={form.net_sales} onChange={(e) => setForm((f) => ({ ...f, net_sales: e.target.value }))} required /></div>
                <div><label className="form-label" htmlFor="myp-uit">{t('myp.col_uit')}</label><input id="myp-uit" className="form-control" inputMode="decimal" value={form.uit_value} onChange={(e) => setForm((f) => ({ ...f, uit_value: e.target.value }))} required /></div>
                <div><label className="form-label" htmlFor="myp-hc">{t('myp.col_headcount')}</label><input id="myp-hc" className="form-control" inputMode="decimal" value={form.average_headcount} onChange={(e) => setForm((f) => ({ ...f, average_headcount: e.target.value }))} /></div>
                <button type="submit" className="btn btn-primary">{t('myp.save')}</button>
              </form>
            )}
          </section>
        )}

        {entity !== '' && can('org.legal_entities.update') && (
          <section className="card">
            <div className="card-header"><h2>{t('myp.mass_change')}</h2></div>
            <p className="sub2" style={{ padding: '0 16px' }}>{t('myp.mass_change_help')}</p>
            <form onSubmit={(e) => void applyChange(e)} style={{ padding: 16, display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
              <div>
                <label className="form-label" htmlFor="myp-regime">{t('myp.new_regime')}</label>
                <select id="myp-regime" className="form-select" value={change.labor_regime_code} onChange={(e) => setChange((c) => ({ ...c, labor_regime_code: e.target.value }))} required>
                  <option value="">{t('myp.choose')}</option>
                  {regimes.filter((r) => r.supported).map((r) => <option key={r.code} value={r.code}>{r.name}</option>)}
                </select>
              </div>
              <div><label className="form-label" htmlFor="myp-date">{t('myp.effective_on')}</label><input id="myp-date" className="form-control" type="date" value={change.effective_on} onChange={(e) => setChange((c) => ({ ...c, effective_on: e.target.value }))} required /></div>
              <div style={{ flex: 1, minWidth: 200 }}><label className="form-label" htmlFor="myp-reason">{t('myp.reason')}</label><input id="myp-reason" className="form-control" value={change.reason} onChange={(e) => setChange((c) => ({ ...c, reason: e.target.value }))} required /></div>
              <button type="submit" className="btn btn-sec">{t('myp.apply_change')}</button>
            </form>
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}
