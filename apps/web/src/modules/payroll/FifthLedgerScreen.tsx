'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { ExportButton } from '@/modules/exports/ExportButton'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Money = { amount: string; currency: string }
type Entry = {
  id: string; employment_id: string; employee_code: string; person_name: string; legal_entity_name: string
  period_month: number; sequence: number; entry_kind: string; run_status: string | null
  gross_annual_income?: Money; annual_tax?: Money; withholding_amount?: Money
}
type Summary = {
  months: { period_month: number; withheld: string; accumulated: string }[]
  accumulated?: string; opening?: { cutoff: string | null; income: string; withheld: string }
  closed_payslips?: string; closed_ledger?: string; reconciled: boolean; currency: string | null
}

/**
 * Nómina → Retenciones de quinta (PER-11). Libro de solo inserción con el alcance de quien consulta: cada
 * movimiento del ejercicio, el resumen por trabajador (retención vigente de cada mes, acumulados traídos del
 * sistema anterior y conciliación con las boletas cerradas) y la exportación común. Todo lo calcula el servidor;
 * los montos sin permiso no llegan.
 */
export function FifthLedgerScreen() {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const [year, setYear] = useState(String(new Date().getFullYear()))
  const [rows, setRows] = useState<Entry[] | null>(null)
  const [selected, setSelected] = useState<Entry | null>(null)
  const [summary, setSummary] = useState<Summary | null>(null)
  const [error, setError] = useState<string | null>(null)
  const money = (m?: Money | string, currency?: string | null) => {
    if (m === undefined) return '—'
    const v = typeof m === 'string' ? { amount: m, currency: currency ?? '' } : m
    return `${v.currency} ${formatNumber(locale, v.amount)}`
  }

  const load = useCallback(async () => {
    if (!/^\d{4}$/.test(year)) return
    setError(null)
    try {
      const r = await api.get<{ data: Entry[] }>(`/fifth-category-ledger?filter[fiscal_year]=${year}&per_page=100`)
      setRows(r.data)
    } catch (e) {
      setRows([])
      setError(e instanceof Error ? e.message : t('fql.error'))
    }
  }, [year, t])

  useEffect(() => { void load() }, [load])

  useEffect(() => {
    if (selected === null) { setSummary(null); return }
    void api.get<{ data: Summary }>(`/employments/${selected.employment_id}/fifth-category-summary?fiscal_year=${year}`)
      .then((r) => setSummary(r.data))
      .catch((e: unknown) => toast(e instanceof Error ? e.message : t('fql.error'), 'error'))
  }, [selected, year, t, toast])

  return (
    <>
      <PageHeader title={t('fql.title')} sub={t('fql.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="card" style={{ padding: 16, display: 'flex', gap: 12, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div>
            <label className="form-label" htmlFor="fql-year">{t('fql.year')}</label>
            <input id="fql-year" className="form-control" inputMode="numeric" value={year} onChange={(e) => { setYear(e.target.value); setSelected(null) }} />
          </div>
          <ExportButton resource="fifth_category_ledger" filters={{ fiscal_year: year }} onError={(m) => toast(m, 'error')} onQueued={(m) => toast(m)} />
        </div>
        {error && <Notice tone="error">{error}</Notice>}
        {rows !== null && rows.length === 0 && !error && <EmptyState title={t('fql.empty_title')} text={t('fql.empty_text')} />}
        {rows !== null && rows.length > 0 && (
          <section className="card">
            <table className="table">
              <thead><tr><th>{t('fql.col_person')}</th><th>{t('fql.col_month')}</th><th>{t('fql.col_kind')}</th><th>{t('fql.col_gross')}</th><th>{t('fql.col_tax')}</th><th>{t('fql.col_withholding')}</th><th /></tr></thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id}>
                    <td>{r.person_name}<div className="sub2">{r.employee_code} · {r.legal_entity_name}</div></td>
                    <td>{r.period_month}{r.sequence > 1 ? ` (#${r.sequence})` : ''}{r.run_status === 'discarded' ? ` · ${t('fql.discarded')}` : ''}</td>
                    <td>{t(`fql.kind_${r.entry_kind}`)}</td>
                    <td>{money(r.gross_annual_income)}</td>
                    <td>{money(r.annual_tax)}</td>
                    <td>{money(r.withholding_amount)}</td>
                    <td><button type="button" className="btn btn-ter" onClick={() => setSelected(r)}>{t('fql.summary')}</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        )}
        {selected && summary && (
          <section className="card">
            <div className="card-header"><h2>{t('fql.summary_of', { name: selected.person_name, year })}</h2></div>
            <div style={{ padding: '0 16px 16px' }}>
              <Notice tone={summary.reconciled ? 'ok' : 'warn'}>{summary.reconciled ? t('fql.reconciled') : t('fql.not_reconciled', { payslips: money(summary.closed_payslips, summary.currency), ledger: money(summary.closed_ledger, summary.currency) })}</Notice>
              {summary.opening?.cutoff && <p className="sub2">{t('fql.opening', { date: summary.opening.cutoff, income: money(summary.opening.income, summary.currency), withheld: money(summary.opening.withheld, summary.currency) })}</p>}
              <table className="table">
                <thead><tr><th>{t('fql.col_month')}</th><th>{t('fql.col_withholding')}</th><th>{t('fql.col_accumulated')}</th></tr></thead>
                <tbody>
                  {summary.months.map((m) => <tr key={m.period_month}><td>{m.period_month}</td><td>{money(m.withheld, summary.currency)}</td><td>{money(m.accumulated, summary.currency)}</td></tr>)}
                </tbody>
              </table>
            </div>
          </section>
        )}
        {toastNode}
      </main>
    </>
  )
}
