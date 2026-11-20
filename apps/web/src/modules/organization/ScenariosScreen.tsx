'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, formatNumber, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'
import { orgTabs } from './orgTabs'
import { lineChanges, scenarioTone, SCENARIO_KINDS, type Scenario, type ScenarioComparison, type ScenarioLine } from './scenarios'

type Money = { amount: string; currency: string }
type Option = { id: string; name?: string; legal_entity_id?: string | null }

/**
 * Organización → Escenarios (POS-5): copian la dotación a una fecha y suman, congelan o quitan plazas sin tocar las
 * posiciones reales hasta aplicarse con aprobación. Los totales y el costo los calcula la API; aquí solo se muestran.
 */
export function ScenariosScreen() {
  const { t, locale } = useT()
  const { can, session } = useSession()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<Scenario[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [open, setOpen] = useState<Scenario | null>(null)
  const [creating, setCreating] = useState(false)
  const [selected, setSelected] = useState<string[]>([])
  const [comparison, setComparison] = useState<ScenarioComparison | null>(null)
  const [options, setOptions] = useState<{ legal_entities: Option[]; org_units: Option[]; job_profiles: Option[] }>({ legal_entities: [], org_units: [], job_profiles: [] })
  const money = (m?: Money | null) => (m ? `${m.currency} ${formatNumber(locale, m.amount)}` : '—')

  const load = useCallback(() => {
    setFailed(false)
    api.get<{ data: Scenario[] }>('/headcount-scenarios?per_page=100').then((r) => setRows(r.data)).catch(() => setFailed(true))
  }, [])
  useEffect(load, [load])
  useEffect(() => {
    void api.get<{ data: typeof options }>('/position-options').then((r) => setOptions(r.data)).catch(() => undefined)
  }, [])

  async function openScenario(id: string) {
    try {
      setOpen((await api.get<{ data: Scenario }>(`/headcount-scenarios/${id}`)).data)
    } catch (err) {
      toast(formErrors(err, t).general ?? t('common.error'), 'error')
    }
  }

  async function compare() {
    try {
      setComparison((await api.get<{ data: ScenarioComparison }>(`/headcount-scenarios/compare?ids=${selected.join(',')}`)).data)
    } catch (err) {
      toast(formErrors(err, t).general ?? t('common.error'), 'error')
    }
  }

  const tabs = orgTabs(t, can, 'scenarios', session?.modules ?? [])

  return (
    <>
      <PageHeader title={t('scenarios.title')} sub={t('scenarios.sub')} tabs={tabs}
        actions={can('org.headcount_plans.create') ? <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>{t('scenarios.new')}</button> : undefined} />
      <main className="main" id="contenido" tabIndex={-1}>
        {failed ? <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={load} />
          : !rows ? <span className="esq" style={{ width: 320 }} />
            : rows.length === 0 ? <EmptyState title={t('scenarios.empty_title')} text={t('scenarios.empty_text')} />
              : (
                <section className="card">
                  <div className="card-header">
                    <h2>{t('scenarios.list')}</h2>
                    <button type="button" className="btn btn-sec btn-sm" disabled={selected.length < 2 || selected.length > 4} onClick={() => void compare()}>{t('scenarios.compare', { n: selected.length })}</button>
                  </div>
                  <table className="table">
                    <thead><tr>
                      <th><span className="visually-hidden">{t('scenarios.select')}</span></th>
                      <th>{t('scenarios.name')}</th><th>{t('scenarios.kind')}</th><th>{t('scenarios.base_as_of')}</th>
                      <th className="text-end">{t('scenarios.planned')}</th><th className="text-end">{t('scenarios.cost')}</th><th>{t('scenarios.status')}</th>
                    </tr></thead>
                    <tbody>
                      {rows.map((s) => (
                        <tr key={s.id}>
                          <td><input type="checkbox" aria-label={t('scenarios.select_one', { name: s.name })} checked={selected.includes(s.id)}
                            onChange={(e) => setSelected((cur) => (e.target.checked ? [...cur, s.id] : cur.filter((x) => x !== s.id)))} /></td>
                          <td><button type="button" className="btn-link" onClick={() => void openScenario(s.id)}>{s.name}</button>
                            {s.created_by_name && <span className="sub2"> · {s.created_by_name}</span>}</td>
                          <td>{t(`scenarios.kind_${s.kind}`)}</td>
                          <td>{formatDate(locale, s.base_as_of)}</td>
                          <td className="text-end">{s.totals?.planned ?? 0}</td>
                          <td className="text-end">{money(s.projected_cost)}</td>
                          <td><StatusBadge tone={scenarioTone(s.status)}>{t(`scenarios.status_${s.status}`)}</StatusBadge></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </section>
              )}
        {comparison && <Comparison data={comparison} money={money} onClose={() => setComparison(null)} />}
      </main>
      {creating && <CreatePanel entities={options.legal_entities} onClose={() => setCreating(false)} onCreated={(s) => { setCreating(false); load(); setOpen(s) }} />}
      {open && <ScenarioPanel scenario={open} options={options} money={money} onChange={(s) => { setOpen(s); load() }} onClose={() => setOpen(null)} />}
      {toastNode}
    </>
  )
}

function Comparison({ data, money, onClose }: { data: ScenarioComparison; money: (m?: Money | null) => string; onClose: () => void }) {
  const { t } = useT()
  const rows: Array<{ key: keyof ScenarioComparison['scenarios'][number]['totals']; label: string }> = [
    { key: 'planned', label: t('scenarios.planned') }, { key: 'active', label: t('scenarios.active') },
    { key: 'occupied', label: t('scenarios.occupied') }, { key: 'vacant', label: t('scenarios.vacant') },
    { key: 'frozen', label: t('scenarios.frozen') }, { key: 'added', label: t('scenarios.added') }, { key: 'removed', label: t('scenarios.removed') },
  ]
  return (
    <section className="card mt-3" aria-labelledby="sc-compare">
      <div className="card-header">
        <h2 id="sc-compare">{t('scenarios.comparison')}</h2>
        <button type="button" className="btn btn-ter btn-sm" onClick={onClose}>{t('common.close')}</button>
      </div>
      <div className="table-responsive">
        <table className="table">
          <thead><tr><th>{t('scenarios.indicator')}</th>{data.scenarios.map((s) => <th key={s.id} className="text-end">{s.name}</th>)}</tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.key}><td>{r.label}</td>{data.scenarios.map((s) => <td key={s.id} className="text-end">{s.totals[r.key]}</td>)}</tr>
            ))}
            {data.scenarios.some((s) => s.projected_cost !== undefined) && (
              <tr><td>{t('scenarios.cost')}</td>{data.scenarios.map((s) => <td key={s.id} className="text-end">{money(s.projected_cost)}</td>)}</tr>
            )}
          </tbody>
        </table>
      </div>
      <h3 className="h6 mt-3">{t('scenarios.by_unit')}</h3>
      <div className="table-responsive">
        <table className="table">
          <thead><tr><th>{t('scenarios.unit')}</th>{data.scenarios.map((s) => <th key={s.id} className="text-end">{s.name}</th>)}</tr></thead>
          <tbody>
            {data.units.map((u) => (
              <tr key={u.org_unit_id}><td>{u.org_unit_name}</td>{data.scenarios.map((s) => <td key={s.id} className="text-end">{u.scenarios[s.id]?.planned ?? 0}</td>)}</tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function CreatePanel({ entities, onClose, onCreated }: { entities: Option[]; onClose: () => void; onCreated: (s: Scenario) => void }) {
  const { t } = useT()
  const [form, setForm] = useState({ name: '', kind: 'growth', legal_entity_id: '', base_as_of: localToday(), assumptions: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      const r = await api.post<{ data: Scenario }>('/headcount-scenarios', {
        name: form.name, kind: form.kind, base_as_of: form.base_as_of,
        ...(form.legal_entity_id ? { legal_entity_id: form.legal_entity_id } : {}),
        ...(form.assumptions.trim() ? { assumptions: { notes: form.assumptions.trim() } } : {}),
      })
      onCreated(r.data)
    } catch (err) {
      const f = formErrors(err, t)
      setErrors(f.fields)
      setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel title={t('scenarios.new')} onClose={onClose} closeLabel={t('common.close')} footer={
      <>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('common.cancel')}</button>
        <button type="submit" form="sc-new" className="btn btn-primary" disabled={busy}>{t('scenarios.create')}</button>
      </>
    }>
      <form id="sc-new" onSubmit={(e) => void submit(e)} noValidate>
        {general && <div className="alert alert-danger" role="alert">{general}</div>}
        <label className="form-label" htmlFor="sc-name">{t('scenarios.name')}</label>
        <input id="sc-name" className="form-control" required maxLength={160} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} aria-invalid={!!errors.name} />
        {errors.name && <div className="invalid-feedback d-block">{errors.name}</div>}
        <label className="form-label mt-3" htmlFor="sc-kind">{t('scenarios.kind')}</label>
        <select id="sc-kind" className="form-select" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
          {SCENARIO_KINDS.map((k) => <option key={k} value={k}>{t(`scenarios.kind_${k}`)}</option>)}
        </select>
        <label className="form-label mt-3" htmlFor="sc-entity">{t('scenarios.entity')}</label>
        <select id="sc-entity" className="form-select" value={form.legal_entity_id} onChange={(e) => setForm({ ...form, legal_entity_id: e.target.value })} aria-invalid={!!errors.legal_entity_id}>
          <option value="">{t('scenarios.all_entities')}</option>
          {entities.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
        </select>
        {errors.legal_entity_id && <div className="invalid-feedback d-block">{errors.legal_entity_id}</div>}
        <label className="form-label mt-3" htmlFor="sc-date">{t('scenarios.base_as_of')}</label>
        <input id="sc-date" type="date" className="form-control" value={form.base_as_of} onChange={(e) => setForm({ ...form, base_as_of: e.target.value })} />
        <p className="small text-secondary mt-1">{t('scenarios.base_help')}</p>
        <label className="form-label mt-3" htmlFor="sc-assumptions">{t('scenarios.assumptions')}</label>
        <textarea id="sc-assumptions" className="form-control" rows={3} maxLength={1000} value={form.assumptions} onChange={(e) => setForm({ ...form, assumptions: e.target.value })} />
      </form>
    </SidePanel>
  )
}

function ScenarioPanel({ scenario, options, money, onChange, onClose }: {
  scenario: Scenario
  options: { org_units: Option[]; job_profiles: Option[] }
  money: (m?: Money | null) => string
  onChange: (s: Scenario) => void
  onClose: () => void
}) {
  const { t } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [busy, setBusy] = useState(false)
  const [add, setAdd] = useState({ org_unit_id: '', job_profile_id: '', planned_count: '1', title: '', planned_start_on: '' })
  const editable = scenario.status === 'draft' && can('org.headcount_plans.update')
  const totals = scenario.totals

  async function run(fn: () => Promise<{ data: Scenario }>, ok: string) {
    setBusy(true)
    try {
      onChange((await fn()).data)
      toast(t(ok))
    } catch (err) {
      toast(formErrors(err, t).general ?? Object.values(formErrors(err, t).fields)[0] ?? t('common.error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  const setChange = (line: ScenarioLine, change: string) => run(() => api.request<{ data: Scenario }>(`/headcount-scenario-lines/${line.id}`, 'PATCH', { change }), 'scenarios.saved')
  const act = (action: string) => run(() => api.post<{ data: Scenario }>(`/headcount-scenarios/${scenario.id}/${action}`, action === 'return' ? { reason: t('scenarios.return_reason') } : {}), `scenarios.done_${action}`)
  const addLine = (e: FormEvent) => {
    e.preventDefault()
    void run(() => api.post<{ data: Scenario }>(`/headcount-scenarios/${scenario.id}/lines`, {
      org_unit_id: add.org_unit_id, job_profile_id: add.job_profile_id, planned_count: Number(add.planned_count) || 1,
      ...(add.title.trim() ? { title: add.title.trim() } : {}), ...(add.planned_start_on ? { planned_start_on: add.planned_start_on } : {}),
    }), 'scenarios.saved')
  }
  const actionPerm: Record<string, string> = { submit: 'org.headcount_plans.submit', approve: 'org.headcount_plans.approve', return: 'org.headcount_plans.approve', apply: 'org.headcount_plans.approve', archive: 'org.headcount_plans.close' }
  const actions = (scenario.allowed_actions ?? []).filter((a) => can(actionPerm[a] ?? ''))

  return (
    <SidePanel wide title={scenario.name} onClose={onClose} closeLabel={t('common.close')} footer={
      <>
        {actions.map((a) => (
          <button key={a} type="button" className={`btn ${a === 'apply' || a === 'approve' ? 'btn-primary' : 'btn-sec'}`} disabled={busy} onClick={() => void act(a)}>{t(`scenarios.action_${a}`)}</button>
        ))}
      </>
    }>
      <p><StatusBadge tone={scenarioTone(scenario.status)}>{t(`scenarios.status_${scenario.status}`)}</StatusBadge>
        <span className="sub2"> {t(`scenarios.kind_${scenario.kind}`)} · {t('scenarios.base_on', { date: scenario.base_as_of })}</span></p>
      {scenario.status === 'approved' && <div className="alert alert-info">{t('scenarios.apply_help')}</div>}
      {totals && (
        <dl className="consola-cifras">
          {(['planned', 'active', 'occupied', 'vacant', 'frozen', 'added', 'removed'] as const).map((k) => (
            <div key={k} className="card consola-cifra"><div className="card-body"><dt className="small text-secondary">{t(`scenarios.${k}`)}</dt><dd><b>{totals[k]}</b></dd></div></div>
          ))}
          {scenario.projected_cost && <div className="card consola-cifra"><div className="card-body"><dt className="small text-secondary">{t('scenarios.cost')}</dt><dd><b>{money(scenario.projected_cost)}</b></dd></div></div>}
        </dl>
      )}
      {scenario.applied_summary && (
        <div className="alert alert-secondary">
          {t('scenarios.applied', { requests: scenario.applied_summary.position_requests.length, frozen: scenario.applied_summary.frozen.length, closed: scenario.applied_summary.closed.length })}
          {scenario.applied_summary.skipped.map((s) => <div key={s.line_id} className="small">{t('scenarios.skipped')}: {s.reason}</div>)}
        </div>
      )}
      <table className="table">
        <thead><tr><th>{t('scenarios.position')}</th><th>{t('scenarios.unit')}</th><th className="text-end">{t('scenarios.seats')}</th><th>{t('scenarios.change')}</th></tr></thead>
        <tbody>
          {(scenario.lines ?? []).map((l) => (
            <tr key={l.id}>
              <td>{l.title ?? l.job_profile_name}{l.position_code && <span className="sub2"> · {l.position_code}</span>}</td>
              <td>{l.org_unit_name}</td>
              <td className="text-end">{l.planned_count}</td>
              <td>
                {editable ? (
                  <select className="form-select form-select-sm" aria-label={t('scenarios.change_of', { name: l.title ?? l.job_profile_name })} value={l.change} disabled={busy}
                    onChange={(e) => void setChange(l, e.target.value)}>
                    {lineChanges(l).map((c) => <option key={c} value={c}>{t(`scenarios.change_${c}`)}</option>)}
                  </select>
                ) : t(`scenarios.change_${l.change}`)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {editable && (
        <form onSubmit={addLine} className="mt-3" aria-labelledby="sc-add">
          <h3 id="sc-add" className="h6">{t('scenarios.add_seats')}</h3>
          <div className="row g-2">
            <div className="col-md-6">
              <label className="form-label" htmlFor="sc-unit">{t('scenarios.unit')}</label>
              <select id="sc-unit" className="form-select" required value={add.org_unit_id} onChange={(e) => setAdd({ ...add, org_unit_id: e.target.value })}>
                <option value="">—</option>
                {options.org_units.filter((u) => !scenario.legal_entity_id || u.legal_entity_id === scenario.legal_entity_id).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
              </select>
            </div>
            <div className="col-md-6">
              <label className="form-label" htmlFor="sc-job">{t('scenarios.job')}</label>
              <select id="sc-job" className="form-select" required value={add.job_profile_id} onChange={(e) => setAdd({ ...add, job_profile_id: e.target.value })}>
                <option value="">—</option>
                {options.job_profiles.map((j) => <option key={j.id} value={j.id}>{j.name}</option>)}
              </select>
            </div>
            <div className="col-md-6">
              <label className="form-label" htmlFor="sc-title">{t('scenarios.seat_title')}</label>
              <input id="sc-title" className="form-control" maxLength={160} value={add.title} onChange={(e) => setAdd({ ...add, title: e.target.value })} />
            </div>
            <div className="col-md-3">
              <label className="form-label" htmlFor="sc-count">{t('scenarios.seats')}</label>
              <input id="sc-count" type="number" min={1} max={1000} className="form-control" value={add.planned_count} onChange={(e) => setAdd({ ...add, planned_count: e.target.value })} />
            </div>
            <div className="col-md-3">
              <label className="form-label" htmlFor="sc-start">{t('scenarios.start_on')}</label>
              <input id="sc-start" type="date" className="form-control" value={add.planned_start_on} onChange={(e) => setAdd({ ...add, planned_start_on: e.target.value })} />
            </div>
          </div>
          <button type="submit" className="btn btn-sec mt-2" disabled={busy || !add.org_unit_id || !add.job_profile_id}>{t('scenarios.add')}</button>
        </form>
      )}
      {toastNode}
    </SidePanel>
  )
}
