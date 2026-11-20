'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { isFromModule, NEEDS_REASON, PERIOD_TONE, periodCode, type ConsumeResult, type PayrollGroup, type PayrollIncident, type PayrollPeriod, type PeriodAction } from './periods'

type Person = { id: string; person_name?: string; employee_code?: string }

/**
 * Nómina → Periodos (PER-1 a PER-3): grupos y periodos con sus acciones, incidencias del periodo (con origen,
 * visto bueno y bloqueo) y toma de ausencias y vacaciones con corte. Reglas, permisos y estados los decide el
 * servidor: aquí solo se muestran las acciones que el periodo permite.
 */
export function PayrollPeriodsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [groups, setGroups] = useState<PayrollGroup[]>([])
  const [periods, setPeriods] = useState<PayrollPeriod[]>([])
  const [selected, setSelected] = useState<PayrollPeriod | null>(null)
  const [incidents, setIncidents] = useState<PayrollIncident[]>([])
  const [people, setPeople] = useState<Person[]>([])
  const [consumed, setConsumed] = useState<ConsumeResult | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [open, setOpen] = useState({ payroll_group_id: '', period_year: String(new Date().getFullYear()), period_number: String(new Date().getMonth() + 1) })
  const [inc, setInc] = useState({ employment_id: '', concept_code: '', quantity: '', amount: '', notes: '' })

  const load = useCallback(async () => {
    const [g, p] = await Promise.all([
      api.get<{ data: PayrollGroup[] }>('/payroll-groups'),
      api.get<{ data: PayrollPeriod[] }>('/payroll-periods'),
    ])
    setGroups(g.data)
    setPeriods(p.data)
    setOpen((f) => (f.payroll_group_id === '' && g.data[0] ? { ...f, payroll_group_id: g.data[0].id } : f))
  }, [])

  const loadPeriod = useCallback(async (id: string) => {
    const [p, i, pop] = await Promise.all([
      api.get<{ data: PayrollPeriod }>(`/payroll-periods/${id}`),
      api.get<{ data: PayrollIncident[] }>(`/payroll-incidents?filter[payroll_period_id]=${id}`),
      api.get<{ data: Person[] }>(`/payroll-periods/${id}/population`),
    ])
    setSelected(p.data)
    setIncidents(i.data)
    setPeople(pop.data)
  }, [])

  useEffect(() => {
    void load().catch((e) => setError(e instanceof Error ? e.message : t('per.error')))
  }, [load, t])

  const run = async (fn: () => Promise<unknown>, done: string) => {
    try {
      await fn()
      toast(t(done))
      await load()
      if (selected) await loadPeriod(selected.id)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('per.error'), 'error')
    }
  }

  const openPeriod = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    void run(() => api.post('/payroll-periods', { payroll_group_id: open.payroll_group_id, period_year: Number(open.period_year), period_number: Number(open.period_number) }), 'per.opened')
  }

  const act = (p: PayrollPeriod, action: PeriodAction) => {
    let reason: string | null = null
    if (NEEDS_REASON.includes(action)) {
      reason = window.prompt(t('per.reason'))
      if (!reason) return
    }
    void run(async () => {
      try {
        return await api.post(`/payroll-periods/${p.id}/${action}`, reason ? { reason } : {})
      } catch (e) {
        // PER-8 · Con anomalías abiertas el envío pide confirmarlas expresamente (el servidor lo exige).
        if (action === 'submit' && e instanceof ApiRequestError && e.code === 'anomalies_pending' && window.confirm(`${e.message}\n${t('per.confirm_anomalies')}`)) {
          return api.post(`/payroll-periods/${p.id}/submit`, { confirm_anomalies: true })
        }
        throw e
      }
    }, 'per.done')
  }

  const addIncident = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!selected) return
    void run(() => api.post('/payroll-incidents', {
      payroll_period_id: selected.id, employment_id: inc.employment_id, concept_code: inc.concept_code,
      quantity: inc.quantity || null, amount: inc.amount || null, notes: inc.notes || null,
    }), 'per.incident_added').then(() => setInc((f) => ({ ...f, quantity: '', amount: '', notes: '' })))
  }

  const decide = (i: PayrollIncident, action: 'approve' | 'reject') => {
    const reason = action === 'reject' ? window.prompt(t('per.reason')) : null
    if (action === 'reject' && !reason) return
    void run(() => api.post(`/payroll-incidents/${i.id}/${action}`, reason ? { reason } : {}), 'per.done')
  }

  const consume = async () => {
    if (!selected) return
    try {
      const r = await api.post<{ data: ConsumeResult }>(`/payroll-periods/${selected.id}/consume-time`)
      setConsumed(r.data)
      toast(t('per.time_taken'))
      await loadPeriod(selected.id)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('per.error'), 'error')
    }
  }

  const editable = selected !== null && (selected.status === 'open' || selected.status === 'calculated')

  return (
    <>
      <PageHeader title={t('per.title')} sub={t('per.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}
        {groups.length === 0 && <Notice tone="info">{t('per.no_groups')}</Notice>}

        {can('payroll.periods.create') && groups.length > 0 && (
          <form className="card" onSubmit={openPeriod} style={{ padding: 16, display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <div>
              <label className="form-label" htmlFor="per-group">{t('per.group')}</label>
              <select id="per-group" className="form-select" required value={open.payroll_group_id} onChange={(e) => setOpen((f) => ({ ...f, payroll_group_id: e.target.value }))}>
                {groups.map((g) => <option key={g.id} value={g.id}>{g.name} · {g.legal_entity_name ?? g.code}</option>)}
              </select>
            </div>
            <div>
              <label className="form-label" htmlFor="per-year">{t('per.year')}</label>
              <input id="per-year" className="form-control" inputMode="numeric" required value={open.period_year} onChange={(e) => setOpen((f) => ({ ...f, period_year: e.target.value }))} />
            </div>
            <div>
              <label className="form-label" htmlFor="per-number">{t('per.number')}</label>
              <input id="per-number" className="form-control" inputMode="numeric" required value={open.period_number} onChange={(e) => setOpen((f) => ({ ...f, period_number: e.target.value }))} />
            </div>
            <button type="submit" className="btn btn-primary">{t('per.open')}</button>
          </form>
        )}

        {periods.length === 0 ? <EmptyState title={t('per.empty_title')} text={t('per.empty_text')} /> : (
          <section className="card">
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('per.col_period')}</th><th>{t('per.col_group')}</th><th>{t('per.col_dates')}</th><th>{t('per.col_cutoff')}</th><th>{t('per.col_status')}</th><th><span className="sr-only">{t('per.col_actions')}</span></th></tr></thead>
                <tbody>
                  {periods.map((p) => (
                    <tr key={p.id} aria-selected={selected?.id === p.id}>
                      <td><button type="button" className="link-btn" onClick={() => void loadPeriod(p.id)}>{periodCode(p)}</button> <span className="muted">{t(`per.kind_${p.kind}`)}</span></td>
                      <td>{p.payroll_group_name ?? '—'}</td>
                      <td className="nowrap">{formatDate(locale, p.starts_on)} – {formatDate(locale, p.ends_on)}</td>
                      <td className="nowrap">{p.attendance_to ? formatDate(locale, p.attendance_to) : '—'}</td>
                      <td><span className={`tag ${PERIOD_TONE[p.status]}`}>{t(`per.status_${p.status}`)}</span></td>
                      <td className="nowrap">
                        {p.allowed_actions.map((a) => <button key={a} type="button" className="btn btn-sec btn-sm" onClick={() => act(p, a)}>{t(`per.action_${a}`)}</button>)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}

        {selected && (
          <section className="card" style={{ padding: 16 }} aria-label={t('per.detail')}>
            <h2 className="card-title">{t('per.detail_of', { code: periodCode(selected) })}</h2>
            {selected.return_reason && <Notice tone="warn">{t('per.returned', { reason: selected.return_reason })}</Notice>}
            <p className="muted">{t('per.cutoff', { from: formatDate(locale, selected.attendance_from ?? selected.starts_on), to: formatDate(locale, selected.attendance_to ?? selected.ends_on) })}</p>

            {can('payroll.incidents.create') && editable && (
              <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center', marginBottom: 12 }}>
                <button type="button" className="btn btn-sec" onClick={() => void consume()}>{t('per.take_time')}</button>
                {consumed && (
                  <span className="muted">
                    {t('per.time_result', { created: consumed.created, updated: consumed.updated, removed: consumed.removed, differences: consumed.differences })}
                    {consumed.out_of_cutoff && ` · ${t('per.out_of_cutoff', { from: formatDate(locale, consumed.out_of_cutoff.from), to: formatDate(locale, consumed.out_of_cutoff.to) })}`}
                    {consumed.missing_concepts.length > 0 && ` · ${t('per.missing_concepts', { codes: consumed.missing_concepts.join(', ') })}`}
                  </span>
                )}
              </div>
            )}

            {can('payroll.incidents.create') && editable && (
              <form onSubmit={addIncident} style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end', marginBottom: 12 }}>
                <div>
                  <label className="form-label" htmlFor="inc-emp">{t('per.employee')}</label>
                  <select id="inc-emp" className="form-select" required value={inc.employment_id} onChange={(e) => setInc((f) => ({ ...f, employment_id: e.target.value }))}>
                    <option value="">{t('per.choose')}</option>
                    {people.map((m) => <option key={m.id} value={m.id}>{m.person_name ?? m.employee_code}</option>)}
                  </select>
                </div>
                <div>
                  <label className="form-label" htmlFor="inc-concept">{t('per.concept')}</label>
                  <input id="inc-concept" className="form-control" required value={inc.concept_code} onChange={(e) => setInc((f) => ({ ...f, concept_code: e.target.value.toUpperCase() }))} />
                </div>
                <div>
                  <label className="form-label" htmlFor="inc-qty">{t('per.quantity')}</label>
                  <input id="inc-qty" className="form-control" inputMode="decimal" value={inc.quantity} onChange={(e) => setInc((f) => ({ ...f, quantity: e.target.value }))} />
                </div>
                <div>
                  <label className="form-label" htmlFor="inc-amount">{t('per.amount')}</label>
                  <input id="inc-amount" className="form-control" inputMode="decimal" value={inc.amount} onChange={(e) => setInc((f) => ({ ...f, amount: e.target.value }))} />
                </div>
                <div>
                  <label className="form-label" htmlFor="inc-notes">{t('per.notes')}</label>
                  <input id="inc-notes" className="form-control" value={inc.notes} onChange={(e) => setInc((f) => ({ ...f, notes: e.target.value }))} />
                </div>
                <button type="submit" className="btn btn-primary">{t('per.add_incident')}</button>
              </form>
            )}

            {incidents.length === 0 ? <EmptyState title={t('per.no_incidents_title')} text={t('per.no_incidents_text')} /> : (
              <div className="tabla-wrap">
                <table className="table">
                  <thead><tr><th>{t('per.col_employee')}</th><th>{t('per.col_concept')}</th><th>{t('per.col_value')}</th><th>{t('per.col_source')}</th><th>{t('per.col_status')}</th><th><span className="sr-only">{t('per.col_actions')}</span></th></tr></thead>
                  <tbody>
                    {incidents.map((i) => (
                      <tr key={i.id}>
                        <td>{i.person_name ?? i.employee_code ?? '—'}</td>
                        <td>{i.concept_code}{i.concept_name ? ` · ${i.concept_name}` : ''}</td>
                        <td className="nowrap">{i.amount ? `${i.amount.currency} ${i.amount.amount}` : (i.quantity ?? '—')}</td>
                        <td>{t(`per.source_${i.source}`)}{isFromModule(i) ? '' : i.created_by_name ? ` · ${i.created_by_name}` : ''}</td>
                        <td><span className="tag">{t(`per.inc_status_${i.status}`)}</span></td>
                        <td className="nowrap">
                          {can('payroll.incidents.approve') && i.allowed_actions.includes('approve') && <button type="button" className="btn btn-sec btn-sm" onClick={() => decide(i, 'approve')}>{t('per.approve')}</button>}{' '}
                          {can('payroll.incidents.approve') && i.allowed_actions.includes('reject') && <button type="button" className="btn btn-sec btn-sm" onClick={() => decide(i, 'reject')}>{t('per.reject')}</button>}{' '}
                          {can('payroll.incidents.delete') && i.editable && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(() => api.request(`/payroll-incidents/${i.id}`, 'DELETE'), 'per.done')}>{t('per.remove')}</button>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}
