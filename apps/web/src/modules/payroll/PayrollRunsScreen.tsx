'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { canDiscard, canResume, isActive, RUN_TONE, type PayrollRun, type RunEmployment, type RunLine } from './payrollRuns'

/** Monto con su moneda, tal como lo dio el servidor (sin cálculo en el navegador). */
function money(locale: string, amount: string, currency?: string): string {
  return `${currency ?? ''} ${formatNumber(locale, amount)}`.trim()
}

/**
 * Planilla → Cálculos (NOM-6, NOM-8). Corridas en segundo plano con su avance (se actualiza solo mientras
 * calcula), resultado por persona con la causa de cada error y, por persona, las líneas con «¿Cómo se
 * calculó?»: fórmula, datos, parámetros con vigencia, tramos por régimen o periodo de origen. Abrir una
 * corrida se hace desde el periodo de planilla; aquí se sigue, se reanuda o se descarta.
 */
export function PayrollRunsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [runs, setRuns] = useState<PayrollRun[] | null>(null)
  const [selected, setSelected] = useState<PayrollRun | null>(null)
  const [people, setPeople] = useState<RunEmployment[]>([])
  const [person, setPerson] = useState<RunEmployment | null>(null)
  const [lines, setLines] = useState<RunLine[]>([])
  const [open, setOpen] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const loadRuns = useCallback(async () => {
    try {
      const r = await api.get<{ data: PayrollRun[] }>('/payroll-runs?per_page=50')
      setRuns(r.data)
      setSelected((cur) => (cur ? r.data.find((x) => x.id === cur.id) ?? cur : cur))
    } catch (e) {
      setRuns([])
      setError(e instanceof Error ? e.message : t('nrun.error'))
    }
  }, [t])

  useEffect(() => { void loadRuns() }, [loadRuns])

  // Mientras una corrida calcula, el avance se vuelve a pedir cada pocos segundos.
  useEffect(() => {
    if (!runs?.some(isActive)) return
    const id = window.setInterval(() => { void loadRuns() }, 4000)
    return () => window.clearInterval(id)
  }, [runs, loadRuns])

  const pick = async (run: PayrollRun) => {
    setSelected(run)
    setPerson(null)
    setLines([])
    try {
      const r = await api.get<{ data: RunEmployment[] }>(`/payroll-runs/${run.id}/employments`)
      setPeople(r.data)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('nrun.error'), 'error')
    }
  }

  const pickPerson = async (row: RunEmployment) => {
    setPerson(row)
    setOpen(null)
    if (row.status !== 'calculated' || !can('payroll.results.view')) { setLines([]); return }
    try {
      const r = await api.get<{ data: RunLine[] }>(`/payroll-run-employments/${row.id}/lines`)
      setLines(r.data)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('nrun.error'), 'error')
    }
  }

  const act = async (run: PayrollRun, action: 'resume' | 'discard') => {
    if (action === 'discard' && !window.confirm(t('nrun.confirm_discard'))) return
    try {
      await api.post(`/payroll-runs/${run.id}/${action}`, {})
      toast(t(`nrun.done_${action}`))
      await loadRuns()
    } catch (e) {
      toast(e instanceof Error ? e.message : t('nrun.error'), 'error')
    }
  }

  return (
    <>
      <PageHeader title={t('nrun.title')} sub={t('nrun.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}
        {runs !== null && runs.length === 0 && !error && <EmptyState title={t('nrun.empty_title')} text={t('nrun.empty_text')} />}
        {runs !== null && runs.length > 0 && (
          <section className="card">
            <div className="card-header"><h2>{t('nrun.runs')}</h2></div>
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('nrun.col_run')}</th><th>{t('nrun.col_kind')}</th><th>{t('nrun.col_status')}</th><th>{t('nrun.col_progress')}</th><th>{t('nrun.col_net')}</th><th /></tr></thead>
                <tbody>
                  {runs.map((r) => (
                    <tr key={r.id}>
                      <td className="nowrap"><button type="button" className="link-btn" onClick={() => void pick(r)}>#{r.run_number} · {formatDate(locale, r.created_at.slice(0, 10))}</button></td>
                      <td>{t(`nrun.kind_${r.kind}`)}</td>
                      <td><span className={`tag ${RUN_TONE[r.status]}`}>{t(`nrun.status_${r.status}`)}</span>{r.failure_reason && <div className="sub2">{r.failure_reason}</div>}</td>
                      <td>
                        <progress max={100} value={r.progress.percent} aria-label={t('nrun.col_progress')} />{' '}
                        {t('nrun.progress_line', { done: r.progress.calculated, failed: r.progress.failed, total: r.progress.total })}
                      </td>
                      <td className="nowrap">{r.totals ? money(locale, r.totals.net, r.totals.currency) : '—'}</td>
                      <td className="nowrap">
                        {can('payroll.periods.calculate') && canResume(r) && r.status !== 'queued' && <button type="button" className="btn btn-sec btn-sm" onClick={() => void act(r, 'resume')}>{t('nrun.resume')}</button>}{' '}
                        {can('payroll.periods.calculate') && canDiscard(r) && <button type="button" className="btn btn-ter btn-sm" onClick={() => void act(r, 'discard')}>{t('nrun.discard')}</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}

        {selected && (
          <section className="card">
            <div className="card-header"><h2>{t('nrun.people', { n: selected.run_number })}</h2></div>
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('nrun.col_person')}</th><th>{t('nrun.col_status')}</th><th>{t('nrun.col_net')}</th></tr></thead>
                <tbody>
                  {people.length === 0 && <tr><td colSpan={3} className="sub2">{t('nrun.no_people')}</td></tr>}
                  {people.map((p) => (
                    <tr key={p.id}>
                      <td><button type="button" className="link-btn" onClick={() => void pickPerson(p)}>{p.employee_name}</button> <span className="sub2">{p.employee_code}</span></td>
                      <td>
                        <span className={`tag ${p.status === 'calculated' ? 'ok' : p.status === 'error' ? 'er' : 'gr'}`}>{t(`nrun.person_${p.status}`)}</span>
                        {p.errors.map((e) => <div key={e.code} className="sub2">{e.message}</div>)}
                      </td>
                      <td className="nowrap">{p.totals ? money(locale, p.totals.net.amount, p.totals.net.currency) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}

        {person && person.status === 'calculated' && (
          <section className="card">
            <div className="card-header"><h2>{t('nrun.lines', { name: person.employee_name })}</h2></div>
            {!can('payroll.results.view') && <Notice tone="info">{t('nrun.no_amounts')}</Notice>}
            <ul style={{ listStyle: "none", margin: 0, padding: '0 16px 16px' }}>
              {lines.map((l) => (
                <li key={l.id} style={{ borderBottom: '1px solid var(--line)', padding: '8px 0' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12 }}>
                    <span>
                      <strong>{l.concept_name}</strong> <span className="sub2">{l.concept_code}</span>
                      {l.origin === 'retroactive' && <span className="tag wa" style={{ marginLeft: 8 }}>{t('nrun.retroactive')}</span>}
                    </span>
                    <span className="nowrap">{l.amount ? money(locale, l.amount.amount, l.amount.currency) : '—'}</span>
                  </div>
                  {l.explanation && (
                    <>
                      <button type="button" className="link-btn" aria-expanded={open === l.id} onClick={() => setOpen(open === l.id ? null : l.id)}>
                        {t('nrun.how')}
                      </button>
                      {open === l.id && (
                        <div className="sub2" style={{ marginTop: 6 }}>
                          {l.explanation.version.valid_from && <p>{t('nrun.version', { from: formatDate(locale, l.explanation.version.valid_from) })}</p>}
                          <ol>{l.explanation.steps.map((s, i) => <li key={i}>{s}</li>)}</ol>
                          {l.explanation.parameters.some((p) => p.pending_validation) && <Notice tone="warn">{t('nrun.pending_parameters')}</Notice>}
                        </div>
                      )}
                    </>
                  )}
                </li>
              ))}
            </ul>
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}
