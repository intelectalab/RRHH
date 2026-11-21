'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { saveBlob } from './payslips'
import { periodCode, type PayrollPeriod } from './periods'

type Comparison = {
  id: string; status: 'open' | 'ready'; legacy_system: string | null; created_at: string; report_document_id: string | null
  tolerance: { amount: string; currency: string }; allowed_actions: string[]
  summary: { employments?: number; differences?: number; unexplained?: number; by_concept?: { concept_code: string; legacy: string; product: string; difference: string; differences: number }[] }
}
type Difference = {
  id: string; employment_id: string; worker: string; employee_code: string | null; concept_code: string
  legacy_amount?: string | null; product_amount?: string | null; difference?: string
  classification: string; explained: boolean; comment: string | null
}
const CLASSES = ['input', 'parameter', 'rule', 'rounding'] as const

/**
 * Nómina → Planilla en paralelo (PER-13). Durante la migración se importan los resultados del sistema anterior
 * (importación «legacy_payroll_results», con su perfil de origen) y se comparan con la corrida vigente del periodo
 * por trabajador y concepto. Cada diferencia fuera de la tolerancia se clasifica con comentario; la comparación
 * queda lista solo cuando todas están explicadas. Las sumas y diferencias las calcula el servidor.
 */
export function ParallelPayrollScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [periods, setPeriods] = useState<PayrollPeriod[] | null>(null)
  const [period, setPeriod] = useState('')
  const [comparisons, setComparisons] = useState<Comparison[]>([])
  const [current, setCurrent] = useState<(Comparison & { differences: Difference[] }) | null>(null)
  const [onlyPending, setOnlyPending] = useState(true)
  const [legacySystem, setLegacySystem] = useState('')
  const [draft, setDraft] = useState<Record<string, { classification: string; comment: string }>>({})
  const [error, setError] = useState<string | null>(null)
  const canRun = can('payroll.periods.calculate')
  const amounts = can('payroll.results.view')
  const fail = useCallback((e: unknown) => toast(e instanceof Error ? e.message : t('ppar.error'), 'error'), [t, toast])
  const money = (v: string | null | undefined, currency: string) => (v === null || v === undefined ? '—' : `${currency} ${formatNumber(locale, v)}`)

  useEffect(() => {
    api.get<{ data: PayrollPeriod[] }>('/payroll-periods')
      .then((r) => {
        setPeriods(r.data)
        const first = r.data.find((p) => ['calculated', 'in_review', 'approved'].includes(p.status)) ?? r.data[0]
        if (first) setPeriod(first.id)
      })
      .catch((e: unknown) => { setPeriods([]); setError(e instanceof Error ? e.message : t('ppar.error')) })
  }, [t])

  const open = useCallback(async (id: string) => {
    try {
      const r = await api.get<{ data: Comparison & { differences: Difference[] } }>(`/payroll-parallel-comparisons/${id}${onlyPending ? '?filter[explained]=0' : ''}`)
      setCurrent(r.data)
    } catch (e) { fail(e) }
  }, [onlyPending, fail])

  const load = useCallback(async (id: string) => {
    if (!id) return
    try {
      const r = await api.get<{ data: Comparison[] }>(`/payroll-periods/${id}/parallel-comparisons`)
      setComparisons(r.data)
      if (r.data[0]) await open(r.data[0].id)
      else setCurrent(null)
    } catch (e) { fail(e) }
  }, [open, fail])

  useEffect(() => { void load(period) }, [period, load])

  const compare = async () => {
    try {
      await api.post(`/payroll-periods/${period}/parallel-comparisons`, legacySystem.trim() === '' ? {} : { legacy_system: legacySystem.trim() })
      toast(t('ppar.compared'))
      await load(period)
    } catch (e) { fail(e) }
  }

  const explain = async (d: Difference) => {
    const v = draft[d.id] ?? { classification: '', comment: '' }
    if (!v.classification) return
    try {
      await api.post(`/payroll-parallel-differences/${d.id}/explain`, { classification: v.classification, comment: v.comment.trim() === '' ? null : v.comment.trim() })
      toast(t('ppar.explained'))
      if (current) await open(current.id)
    } catch (e) { fail(e) }
  }

  const act = async (action: 'mark-ready' | 'reopen') => {
    if (!current) return
    try {
      await api.post(`/payroll-parallel-comparisons/${current.id}/${action}`, {})
      toast(t(action === 'mark-ready' ? 'ppar.ready_done' : 'ppar.reopened'))
      await load(period)
    } catch (e) { fail(e) }
  }

  const download = async () => {
    if (!current) return
    try {
      const { blob, filename } = await api.download(`/payroll-parallel-comparisons/${current.id}/download`)
      saveBlob(blob, filename)
    } catch (e) { fail(e) }
  }

  const currency = current?.tolerance.currency ?? ''

  return (
    <>
      <PageHeader title={t('ppar.title')} sub={t('ppar.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}
        <div className="card" style={{ padding: 16, display: 'flex', gap: 12, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div>
            <label className="form-label" htmlFor="ppar-period">{t('ppar.period')}</label>
            <select id="ppar-period" className="form-control" value={period} onChange={(e) => setPeriod(e.target.value)}>
              {(periods ?? []).map((p) => <option key={p.id} value={p.id}>{periodCode(p)} · {p.payroll_group_name ?? ''} · {t(`per.status_${p.status}`)}</option>)}
            </select>
          </div>
          {canRun && (
            <>
              <div>
                <label className="form-label" htmlFor="ppar-system">{t('ppar.legacy_system')}</label>
                <input id="ppar-system" className="form-control" value={legacySystem} onChange={(e) => setLegacySystem(e.target.value)} />
              </div>
              <button type="button" className="btn btn-pri" disabled={!period} onClick={() => void compare()}>{t('ppar.compare')}</button>
              <Link className="btn btn-sec" href="/configuracion/importaciones">{t('ppar.import')}</Link>
            </>
          )}
        </div>
        {periods !== null && periods.length === 0 && <EmptyState title={t('ppar.no_periods_title')} text={t('ppar.no_periods_text')} />}
        {period && comparisons.length === 0 && <EmptyState title={t('ppar.empty_title')} text={t('ppar.empty_text')} />}
        {current && (
          <section className="card">
            <div className="card-header" style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
              <h2 style={{ marginRight: 'auto' }}>{t('ppar.comparison_of', { date: current.created_at.slice(0, 10), system: current.legacy_system ?? t('ppar.system_unnamed') })}</h2>
              <span className={`tag ${current.status === 'ready' ? 'ok' : 'wa'}`}>{t(`ppar.status_${current.status}`)}</span>
              {comparisons.length > 1 && (
                <select aria-label={t('ppar.history')} className="form-control" style={{ width: 'auto' }} value={current.id} onChange={(e) => void open(e.target.value)}>
                  {comparisons.map((c) => <option key={c.id} value={c.id}>{c.created_at.slice(0, 16).replace('T', ' ')} · {t(`ppar.status_${c.status}`)}</option>)}
                </select>
              )}
              {canRun && current.allowed_actions.includes('mark_ready') && <button type="button" className="btn btn-pri" disabled={(current.summary.unexplained ?? 0) > 0} onClick={() => void act('mark-ready')}>{t('ppar.mark_ready')}</button>}
              {canRun && current.allowed_actions.includes('reopen') && <button type="button" className="btn btn-sec" onClick={() => void act('reopen')}>{t('ppar.reopen')}</button>}
              {amounts && <button type="button" className="btn btn-ter" onClick={() => void download()}>{t('ppar.download')}</button>}
            </div>
            <div style={{ padding: '0 16px 16px' }}>
              <p className="sub2">{t('ppar.summary', { employments: current.summary.employments ?? 0, differences: current.summary.differences ?? 0, unexplained: current.summary.unexplained ?? 0, tolerance: money(current.tolerance.amount, currency) })}</p>
              {current.summary.by_concept && current.summary.by_concept.length > 0 && (
                <table className="table">
                  <thead><tr><th>{t('ppar.col_concept')}</th><th>{t('ppar.col_legacy')}</th><th>{t('ppar.col_product')}</th><th>{t('ppar.col_difference')}</th><th>{t('ppar.col_count')}</th></tr></thead>
                  <tbody>{current.summary.by_concept.map((c) => <tr key={c.concept_code}><td>{c.concept_code}</td><td>{money(c.legacy, currency)}</td><td>{money(c.product, currency)}</td><td>{money(c.difference, currency)}</td><td>{c.differences}</td></tr>)}</tbody>
                </table>
              )}
              <label style={{ display: 'flex', gap: 8, alignItems: 'center', margin: '12px 0' }}>
                <input type="checkbox" checked={onlyPending} onChange={(e) => setOnlyPending(e.target.checked)} /> {t('ppar.only_pending')}
              </label>
              {current.differences.length === 0
                ? <Notice tone="ok">{t('ppar.no_differences')}</Notice>
                : (
                  <table className="table">
                    <thead><tr><th>{t('ppar.col_worker')}</th><th>{t('ppar.col_concept')}</th><th>{t('ppar.col_legacy')}</th><th>{t('ppar.col_product')}</th><th>{t('ppar.col_difference')}</th><th>{t('ppar.col_explanation')}</th></tr></thead>
                    <tbody>
                      {current.differences.map((d) => (
                        <tr key={d.id}>
                          <td>{d.worker}<div className="sub2">{d.employee_code}</div></td>
                          <td>{d.concept_code}</td>
                          <td>{money(d.legacy_amount, currency)}</td>
                          <td>{money(d.product_amount, currency)}</td>
                          <td>{money(d.difference, currency)}</td>
                          <td>
                            {d.explained || !canRun || current.status !== 'open'
                              ? <>{t(`ppar.class_${d.classification}`)}{d.comment ? <div className="sub2">{d.comment}</div> : null}</>
                              : (
                                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                                  <select aria-label={t('ppar.classification')} className="form-control" style={{ width: 'auto' }} value={draft[d.id]?.classification ?? ''}
                                    onChange={(e) => setDraft({ ...draft, [d.id]: { classification: e.target.value, comment: draft[d.id]?.comment ?? '' } })}>
                                    <option value="">{t('ppar.classification')}</option>
                                    {CLASSES.map((c) => <option key={c} value={c}>{t(`ppar.class_${c}`)}</option>)}
                                  </select>
                                  <input aria-label={t('ppar.comment')} className="form-control" placeholder={t('ppar.comment')} value={draft[d.id]?.comment ?? ''}
                                    onChange={(e) => setDraft({ ...draft, [d.id]: { classification: draft[d.id]?.classification ?? '', comment: e.target.value } })} />
                                  <button type="button" className="btn btn-sec" disabled={!draft[d.id]?.classification} onClick={() => void explain(d)}>{t('ppar.save')}</button>
                                </div>
                              )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
            </div>
          </section>
        )}
        {toastNode}
      </main>
    </>
  )
}
