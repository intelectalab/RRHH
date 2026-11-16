'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, formatNumber, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { orgTabs } from './orgTabs'

type Money = { amount: string; currency: string }
type Counts = { proposed: number; approved: number; filled: number; vacant: number; recruiting: number; frozen: number }
type UnitRow = {
  org_unit_id: string
  org_unit_name: string
  counts: Counts
  budgeted_positions: number | null
  deviation_positions: number | null
  budget_id: string | null
  projected_cost?: Money
  budgeted_cost?: Money
  deviation_cost?: Money
  positions_without_cost?: number
}
type Rate = { from: string; to: string; rate: string; rate_date: string; referential: boolean }
type Consolidated = { currency: string; projected_cost: Money; budgeted_cost: Money; as_of: string; rates: Rate[]; referential: boolean }
type Board = { units: UnitRow[]; totals: Counts & { budgeted_positions: number; deviation_positions: number; projected_cost?: Money[]; budgeted_cost?: Money[]; consolidated?: Consolidated }; load_factor_pct?: string }
/** Monedas ofrecidas para el consolidado (CFG-7); la conversión la hace la API. */
const REPORT_CURRENCIES = ['PEN', 'USD', 'EUR']
type Explained = {
  position_id: string
  code: string
  title: string
  status: string
  seats: number
  budget_status: 'within' | 'over_budget' | 'no_budget' | 'not_counted'
  cost_basis: string
  explanation: string
  projected_cost?: Money
}
type Budget = { id: string; org_unit_id: string; org_unit_name: string | null; budgeted_positions: number; monthly_cost?: Money; notes: string | null; valid_from: string; valid_to: string | null; version: number }
type Option = { id: string; name?: string; legal_entity_id?: string | null }

const BUDGET_TONE: Record<string, string> = { within: 'ok', over_budget: 'wa', no_budget: 'gr', not_counted: 'gr' }

/**
 * Organización → Dotación (POS-4): por unidad, plazas aprobadas, ocupadas, vacantes y congeladas frente a su
 * presupuesto; con permiso de costos, el costo proyectado y la desviación. Cada unidad explica su desviación
 * por posición. El cálculo lo hace la API; aquí solo se muestra.
 */
export function HeadcountScreen() {
  const { t, locale } = useT()
  const { can, session } = useSession()
  const { toast, node: toastNode } = useToast()
  const [asOf, setAsOf] = useState(localToday())
  const [board, setBoard] = useState<Board | null>(null)
  const [failed, setFailed] = useState(false)
  const [units, setUnits] = useState<Option[]>([])
  const [explain, setExplain] = useState<UnitRow | null>(null)
  const [budgetFor, setBudgetFor] = useState<{ unitId: string | null; budget: Budget | null } | null>(null)
  const [reportCurrency, setReportCurrency] = useState('')
  const [consolidateError, setConsolidateError] = useState<string | null>(null)
  const canCost = can('org.positions.budget.view')
  const money = (m?: Money) => (m ? `${m.currency} ${formatNumber(locale, m.amount)}` : '—')

  const load = useCallback(() => {
    setFailed(false)
    setConsolidateError(null)
    const base = `/headcount?as_of=${asOf}`
    const plain = () => api.get<{ data: Board }>(base).then((r) => setBoard(r.data)).catch(() => setFailed(true))
    if (reportCurrency === '') {
      void plain()
      return
    }
    // Sin tipo de cambio para alguna moneda la API responde 422: se explica y se muestra el tablero sin consolidar.
    void api.get<{ data: Board }>(`${base}&report_currency=${reportCurrency}`).then((r) => setBoard(r.data)).catch((err: unknown) => {
      setConsolidateError(formErrors(err, t).general ?? t('hc.consolidate_error'))
      void plain()
    })
  }, [asOf, reportCurrency, t])
  useEffect(load, [load])
  useEffect(() => {
    void api.get<{ data: { org_units: Option[] } }>('/position-options').then((r) => setUnits(r.data.org_units)).catch(() => setUnits([]))
  }, [])

  async function editBudget(row: UnitRow) {
    if (!row.budget_id) {
      setBudgetFor({ unitId: row.org_unit_id, budget: null })
      return
    }
    try {
      const r = await api.get<{ data: Budget }>(`/headcount-budgets/${row.budget_id}`)
      setBudgetFor({ unitId: row.org_unit_id, budget: r.data })
    } catch {
      toast(t('hc.load_error'), 'error')
    }
  }

  return (
    <>
      <PageHeader title={t('org.title')} sub={t('hc.sub')} tabs={orgTabs(t, can, 'headcount', session.modules)} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="d-flex flex-wrap gap-2 align-items-end mb-3">
          <div>
            <label className="form-label" htmlFor="hc-as-of">{t('hc.as_of')}</label>
            <input id="hc-as-of" type="date" className="form-control" value={asOf} onChange={(e) => setAsOf(e.target.value || localToday())} />
          </div>
          {canCost && (
            <div>
              <label className="form-label" htmlFor="hc-currency">{t('hc.report_currency')}</label>
              <select id="hc-currency" className="form-select" value={reportCurrency} onChange={(e) => setReportCurrency(e.target.value)}>
                <option value="">{t('hc.report_currency_none')}</option>
                {REPORT_CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
          )}
          {can('org.headcount_plans.create') && <button type="button" className="btn btn-primary btn-sm" onClick={() => setBudgetFor({ unitId: null, budget: null })}><Icon name="plus" /> {t('hc.new_budget')}</button>}
        </div>
        {failed && <p role="alert">{t('hc.load_error')}</p>}
        {!board && !failed && <span className="esq" style={{ width: 240 }} />}
        {board && board.units.length === 0 && <EmptyState title={t('hc.empty_title')} text={t('hc.empty_text')} />}
        {board && board.units.length > 0 && (
          <>
            <div className="hc-kpis mb-3" role="list" aria-label={t('hc.totals')}>
              {(['approved', 'filled', 'vacant', 'frozen'] as const).map((k) => (
                <div key={k} className="hc-kpi" role="listitem"><span className="sub2">{t(`hc.count_${k}`)}</span><b className="h4 mb-0">{board.totals[k]}</b></div>
              ))}
              <div className="hc-kpi" role="listitem"><span className="sub2">{t('hc.budgeted')}</span><b className="h4 mb-0">{board.totals.budgeted_positions}</b></div>
              {canCost && (board.totals.projected_cost ?? []).map((m) => (
                <div key={m.currency} className="hc-kpi" role="listitem"><span className="sub2">{t('hc.projected_cost')}</span><b className="h4 mb-0">{money(m)}</b></div>
              ))}
            </div>
            {consolidateError && <p className="small text-danger" role="alert">{consolidateError}</p>}
            {canCost && board.totals.consolidated && (
              <div className="nota mb-3" role="status">
                <b>{t('hc.consolidated', { projected: money(board.totals.consolidated.projected_cost), budgeted: money(board.totals.consolidated.budgeted_cost) })}</b>
                <span className="sub2">
                  {board.totals.consolidated.rates.length === 0
                    ? t('hc.consolidated_no_rates', { currency: board.totals.consolidated.currency })
                    : board.totals.consolidated.rates.map((r) => t(r.referential ? 'hc.rate_referential' : 'hc.rate_applied', { from: r.from, to: r.to, rate: r.rate, date: formatDate(locale, r.rate_date) })).join(' · ')}
                </span>
              </div>
            )}
            {canCost && board.load_factor_pct !== undefined && <p className="small text-secondary">{t('hc.load_factor', { pct: formatNumber(locale, board.load_factor_pct) })}</p>}
            <div className="tabla-wrap">
              <table className="table">
                <caption className="visually-hidden">{t('hc.table_caption')}</caption>
                <thead>
                  <tr>
                    <th scope="col">{t('pos.col_unit')}</th>
                    <th scope="col" className="num">{t('hc.count_approved')}</th>
                    <th scope="col" className="num">{t('hc.count_filled')}</th>
                    <th scope="col" className="num">{t('hc.count_vacant')}</th>
                    <th scope="col" className="num">{t('hc.count_frozen')}</th>
                    <th scope="col" className="num">{t('hc.budgeted')}</th>
                    <th scope="col" className="num">{t('hc.deviation')}</th>
                    {canCost && <th scope="col" className="num">{t('hc.projected_cost')}</th>}
                    {canCost && <th scope="col" className="num">{t('hc.deviation_cost')}</th>}
                    <th scope="col"><span className="visually-hidden">{t('hc.actions')}</span></th>
                  </tr>
                </thead>
                <tbody>
                  {board.units.map((u) => (
                    <tr key={u.org_unit_id}>
                      <th scope="row">{u.org_unit_name}{u.counts.proposed > 0 && <span className="sub2">{t('hc.proposed_n', { n: u.counts.proposed })}</span>}</th>
                      <td className="num">{u.counts.approved}</td>
                      <td className="num">{u.counts.filled}</td>
                      <td className="num">{u.counts.vacant}</td>
                      <td className="num">{u.counts.frozen}</td>
                      <td className="num">{u.budgeted_positions ?? '—'}</td>
                      <td className="num">{u.deviation_positions === null ? '—' : <span className={`st ${u.deviation_positions > 0 ? 'wa' : 'ok'}`}>{u.deviation_positions > 0 ? `+${u.deviation_positions}` : u.deviation_positions}</span>}</td>
                      {canCost && <td className="num">{money(u.projected_cost)}</td>}
                      {canCost && <td className="num">{money(u.deviation_cost)}</td>}
                      <td>
                        <button type="button" className="btn btn-ter btn-sm" onClick={() => setExplain(u)}>{t('hc.explain')}</button>
                        {(can('org.headcount_plans.create') || can('org.headcount_plans.update')) && <button type="button" className="btn btn-ter btn-sm" onClick={() => void editBudget(u)}>{t(u.budget_id ? 'hc.edit_budget' : 'hc.add_budget')}</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </main>
      {explain && <ExplainPanel unit={explain} asOf={asOf} onClose={() => setExplain(null)} />}
      {budgetFor && (
        <BudgetPanel
          unitId={budgetFor.unitId}
          budget={budgetFor.budget}
          units={units}
          asOf={asOf}
          onClose={() => setBudgetFor(null)}
          onSaved={() => { setBudgetFor(null); toast(t('hc.budget_saved')); load() }}
        />
      )}
      {toastNode}
    </>
  )
}

function ExplainPanel({ unit, asOf, onClose }: { unit: UnitRow; asOf: string; onClose: () => void }) {
  const { t, locale } = useT()
  const [rows, setRows] = useState<Explained[] | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    void api.get<{ data: { positions: Explained[] } }>(`/headcount/positions?org_unit_id=${unit.org_unit_id}&as_of=${asOf}`)
      .then((r) => setRows(r.data.positions)).catch(() => setFailed(true))
  }, [unit.org_unit_id, asOf])

  return (
    <SidePanel title={t('hc.explain_title', { unit: unit.org_unit_name })} onClose={onClose} closeLabel={t('common.close')}>
      {failed && <p role="alert">{t('hc.load_error')}</p>}
      {!rows && !failed && <span className="esq" style={{ width: 240 }} />}
      {rows && rows.length === 0 && <p className="text-secondary">{t('hc.no_positions')}</p>}
      {rows && rows.length > 0 && (
        <ul className="hc-lista">
          {rows.map((r) => (
            <li key={r.position_id} className="mb-3">
              <div className="d-flex justify-content-between gap-2">
                <b>{r.title} <span className="sub2">{r.code} · {t(`pos.status_${r.status}`)}</span></b>
                <span className={`st ${BUDGET_TONE[r.budget_status] ?? 'gr'}`}>{t(`hc.budget_status_${r.budget_status}`)}</span>
              </div>
              <p className="small mb-0">{r.explanation}</p>
              {r.projected_cost && <p className="small text-secondary mb-0">{t('hc.projected_cost')}: {r.projected_cost.currency} {formatNumber(locale, r.projected_cost.amount)}</p>}
            </li>
          ))}
        </ul>
      )}
    </SidePanel>
  )
}

function BudgetPanel({ unitId, budget, units, asOf, onClose, onSaved }: { unitId: string | null; budget: Budget | null; units: Option[]; asOf: string; onClose: () => void; onSaved: () => void }) {
  const { t } = useT()
  const { can } = useSession()
  const canCost = can('org.positions.budget.view')
  const [form, setForm] = useState({
    org_unit_id: budget?.org_unit_id ?? unitId ?? '',
    budgeted_positions: String(budget?.budgeted_positions ?? ''),
    valid_from: budget?.valid_from ?? asOf,
    valid_to: budget?.valid_to ?? '',
    notes: budget?.notes ?? '',
    cost_amount: budget?.monthly_cost?.amount ?? '',
    cost_currency: budget?.monthly_cost?.currency ?? 'PEN',
  })
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const body: Record<string, unknown> = {
      budgeted_positions: Number(form.budgeted_positions), notes: form.notes.trim() || null, valid_to: form.valid_to || null,
    }
    if (canCost) body.monthly_cost = form.cost_amount.trim() === '' ? null : { amount: form.cost_amount.trim(), currency: form.cost_currency }
    try {
      if (budget) {
        await api.request(`/headcount-budgets/${budget.id}`, 'PATCH', body, { 'If-Match': `"${budget.version}"` })
      } else {
        await api.post('/headcount-budgets', { ...body, org_unit_id: form.org_unit_id, valid_from: form.valid_from })
      }
      onSaved()
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      setError(general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel
      title={t(budget ? 'hc.edit_budget' : 'hc.new_budget')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('pos.cancel')}</button>
        <button type="submit" form="presupuesto-dotacion" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('pos.save')}</button>
      </>}
    >
      <form id="presupuesto-dotacion" onSubmit={submit} noValidate>
        {!budget && <p className="small text-secondary">{t('hc.budget_help')}</p>}
        <div className="mb-3">
          <label className="form-label" htmlFor="hb-unit">{t('pos.col_unit')}</label>
          <select id="hb-unit" className={`form-select${errors.org_unit_id ? ' is-invalid' : ''}`} value={form.org_unit_id} onChange={set('org_unit_id')} disabled={budget !== null}>
            <option value="">{t('pos.choose')}</option>
            {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
          {errors.org_unit_id && <div className="invalid-feedback" role="alert">{errors.org_unit_id}</div>}
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="hb-positions">{t('hc.budgeted')}</label>
          <input id="hb-positions" type="number" min={0} className={`form-control${errors.budgeted_positions ? ' is-invalid' : ''}`} value={form.budgeted_positions} onChange={set('budgeted_positions')} />
          {errors.budgeted_positions && <div className="invalid-feedback" role="alert">{errors.budgeted_positions}</div>}
        </div>
        <div className="row g-2">
          <div className="col-6 mb-3">
            <label className="form-label" htmlFor="hb-from">{t('pos.col_valid_from')}</label>
            <input id="hb-from" type="date" className={`form-control${errors.valid_from ? ' is-invalid' : ''}`} value={form.valid_from} onChange={set('valid_from')} disabled={budget !== null} />
            {errors.valid_from && <div className="invalid-feedback" role="alert">{errors.valid_from}</div>}
          </div>
          <div className="col-6 mb-3">
            <label className="form-label" htmlFor="hb-to">{t('pos.valid_to')}</label>
            <input id="hb-to" type="date" className={`form-control${errors.valid_to ? ' is-invalid' : ''}`} value={form.valid_to} onChange={set('valid_to')} />
          </div>
        </div>
        {canCost && (
          <fieldset className="cf-grupo">
            <legend>{t('hc.monthly_cost')}</legend>
            <div className="row g-2">
              <div className="col-8 mb-3">
                <label className="form-label" htmlFor="hb-cost">{t('pos.budget_amount')}</label>
                <input id="hb-cost" inputMode="decimal" className={`form-control${errors.monthly_cost || errors['monthly_cost.amount'] ? ' is-invalid' : ''}`} value={form.cost_amount} onChange={set('cost_amount')} />
              </div>
              <div className="col-4 mb-3">
                <label className="form-label" htmlFor="hb-currency">{t('pos.budget_currency')}</label>
                <input id="hb-currency" maxLength={3} className="form-control" value={form.cost_currency} onChange={(e) => setForm((f) => ({ ...f, cost_currency: e.target.value.toUpperCase() }))} />
              </div>
            </div>
          </fieldset>
        )}
        <div className="mb-3">
          <label className="form-label" htmlFor="hb-notes">{t('hc.notes')}</label>
          <textarea id="hb-notes" className="form-control" rows={2} value={form.notes} onChange={set('notes')} />
        </div>
        {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}
