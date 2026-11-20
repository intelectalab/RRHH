'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { periodCode, type PayrollPeriod } from './periods'
import { isOpen, SEVERITY_TONE, type Anomaly, type Variance } from './review'

/**
 * Nómina → Revisión (PER-7, PER-8): comparación del neto contra el periodo anterior, anomalías con su
 * explicación y causas (revisar o descartar con nota) y simulación sin guardar. Las variaciones, umbrales y
 * anomalías los calcula el servidor; aquí solo se muestran.
 */
export function PayrollReviewScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [periods, setPeriods] = useState<PayrollPeriod[] | null>(null)
  const [selected, setSelected] = useState<string>('')
  const [variance, setVariance] = useState<Variance | null>(null)
  const [anomalies, setAnomalies] = useState<Anomaly[]>([])
  const [error, setError] = useState<string | null>(null)
  const amounts = can('payroll.results.view')

  useEffect(() => {
    api.get<{ data: PayrollPeriod[] }>('/payroll-periods')
      .then((r) => {
        setPeriods(r.data)
        const first = r.data.find((p) => ['calculated', 'in_review', 'approved'].includes(p.status)) ?? r.data[0]
        if (first) setSelected(first.id)
      })
      .catch((e) => { setPeriods([]); setError(e instanceof Error ? e.message : t('nrev.error')) })
  }, [t])

  const load = useCallback(async (id: string) => {
    if (!id || !amounts) return
    try {
      const [v, a] = await Promise.all([
        api.get<{ data: Variance }>(`/payroll-periods/${id}/variance`),
        api.get<{ data: Anomaly[] }>(`/payroll-periods/${id}/anomalies`),
      ])
      setVariance(v.data)
      setAnomalies(a.data)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('nrev.error'), 'error')
    }
  }, [amounts, t, toast])

  useEffect(() => { void load(selected) }, [selected, load])

  const decide = async (a: Anomaly, action: 'review' | 'dismiss') => {
    const note = window.prompt(t('nrev.note'))
    if (!note) return
    try {
      await api.post(`/payroll-run-anomalies/${a.id}/${action}`, { note })
      toast(t(`nrev.done_${action}`))
      await load(selected)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('nrev.error'), 'error')
    }
  }

  const simulate = async () => {
    try {
      await api.post(`/payroll-periods/${selected}/simulate`, {})
      toast(t('nrev.simulated'))
    } catch (e) {
      toast(e instanceof Error ? e.message : t('nrev.error'), 'error')
    }
  }

  const period = periods?.find((p) => p.id === selected)
  const num = (v: string | null | undefined) => (v === null || v === undefined ? '—' : formatNumber(locale, v))

  return (
    <>
      <PageHeader title={t('nrev.title')} sub={t('nrev.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}
        {periods !== null && periods.length === 0 && !error && <EmptyState title={t('nrev.empty_title')} text={t('nrev.empty_text')} />}
        {periods !== null && periods.length > 0 && (
          <section className="card">
            <div className="card-header">
              <label>
                {t('nrev.period')}{' '}
                <select value={selected} onChange={(e) => setSelected(e.target.value)}>
                  {periods.map((p) => <option key={p.id} value={p.id}>{periodCode(p)} · {p.payroll_group_name ?? ''} · {t(`per.status_${p.status}`)}</option>)}
                </select>
              </label>
              {period?.allowed_actions.includes('simulate') && can('payroll.periods.simulate') && (
                <button type="button" className="btn btn-sec btn-sm" onClick={() => void simulate()}>{t('nrev.simulate')}</button>
              )}
            </div>
            {!amounts && <Notice tone="info">{t('nrev.no_amounts')}</Notice>}
          </section>
        )}

        {amounts && variance && (
          <section className="card">
            <div className="card-header">
              <h2>{t('nrev.anomalies')}</h2>
              {variance.threshold_pct && <span className="sub2">{t('nrev.threshold', { pct: variance.threshold_pct })}</span>}
            </div>
            {anomalies.length === 0 && <p className="sub2" style={{ padding: '0 16px 16px' }}>{t('nrev.no_anomalies')}</p>}
            {anomalies.length > 0 && (
              <div className="tabla-wrap">
                <table className="table">
                  <thead><tr><th>{t('nrev.col_person')}</th><th>{t('nrev.col_severity')}</th><th>{t('nrev.col_explanation')}</th><th>{t('nrev.col_status')}</th><th /></tr></thead>
                  <tbody>
                    {anomalies.map((a) => (
                      <tr key={a.id}>
                        <td>{a.employee_name ?? '—'} <span className="sub2">{a.employee_code}</span></td>
                        <td><span className={`tag ${SEVERITY_TONE[a.severity]}`}>{t(`nrev.severity_${a.severity}`)}</span></td>
                        <td>
                          {a.explanation}
                          {a.causes && a.causes.length > 0 && (
                            <ul className="sub2">{a.causes.map((c) => <li key={c.concept_code}>{c.concept_name ?? c.concept_code}: {num(c.difference)}</li>)}</ul>
                          )}
                          {a.review_note && <div className="sub2">{t('nrev.note_label')}: {a.review_note}</div>}
                        </td>
                        <td>{t(`nrev.status_${a.status}`)}</td>
                        <td className="nowrap">
                          {isOpen(a) && can('payroll.periods.calculate') && (
                            <>
                              <button type="button" className="btn btn-sec btn-sm" onClick={() => void decide(a, 'review')}>{t('nrev.review')}</button>{' '}
                              <button type="button" className="btn btn-ter btn-sm" onClick={() => void decide(a, 'dismiss')}>{t('nrev.dismiss')}</button>
                            </>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        )}

        {amounts && variance && (
          <section className="card">
            <div className="card-header"><h2>{t('nrev.comparison')}</h2></div>
            {variance.previous_run_id === null && <Notice tone="info">{t('nrev.no_previous')}</Notice>}
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('nrev.col_person')}</th><th>{t('nrev.col_previous')}</th><th>{t('nrev.col_current')}</th><th>{t('nrev.col_variation')}</th></tr></thead>
                <tbody>
                  {variance.employees.map((r) => (
                    <tr key={r.employment_id}>
                      <td>{r.employee_name} <span className="sub2">{r.employee_code}</span></td>
                      <td className="nowrap">{num(r.previous_net)}</td>
                      <td className="nowrap">{num(r.current_net)}</td>
                      <td className="nowrap">
                        {num(r.variation)}{r.variation_pct !== null && ` (${num(r.variation_pct)} %)`}
                        {r.exceeds_threshold && <span className="tag wa" style={{ marginLeft: 8 }}>{t('nrev.exceeds')}</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}
