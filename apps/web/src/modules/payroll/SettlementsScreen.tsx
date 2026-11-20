'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { PersonPicker, type Person } from '@/ui/components/PersonPicker'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import type { Money } from './review'
import {
  DUE_TONE, NEEDS_REASON, SETTLEMENT_TONE, visibleActions,
  type Settlement, type SettlementAction, type SettlementLine, type Simulation,
} from './settlements'

/**
 * Nómina → Liquidaciones (LIQ-1 a LIQ-4): lista de liquidaciones con su plazo de pago, detalle con cada línea y su
 * explicación, acciones de la máquina de estados (calcular, enviar, aprobar, devolver, pagar, entregar documentos,
 * anular) y simulador de una o dos fechas de cese. Todo monto, plazo y tope lo calcula el servidor; aquí solo se
 * muestran y se piden.
 */
export function SettlementsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [tab, setTab] = useState<'list' | 'simulator'>('list')
  const [rows, setRows] = useState<Settlement[] | null>(null)
  const [detail, setDetail] = useState<Settlement | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [person, setPerson] = useState<Person | null>(null)
  const [dates, setDates] = useState<[string, string]>([new Date().toISOString().slice(0, 10), ''])
  const [severance, setSeverance] = useState(false)
  const [pending, setPending] = useState(false)
  const [simulation, setSimulation] = useState<Simulation | null>(null)

  const fail = useCallback((e: unknown) => toast(e instanceof Error ? e.message : t('nliq.error'), 'error'), [t, toast])
  const money = (m?: Money | null) => (m ? `${m.currency} ${formatNumber(locale, m.amount)}` : '—')

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: Settlement[] }>('/settlements')).data)
    } catch (e) {
      setRows([])
      setError(e instanceof Error ? e.message : t('nliq.error'))
    }
  }, [t])
  useEffect(() => { void load() }, [load])

  const open = async (id: string) => {
    try { setDetail((await api.get<{ data: Settlement }>(`/settlements/${id}`)).data) } catch (e) { fail(e) }
  }

  const act = (s: Settlement, action: SettlementAction) => {
    const body: Record<string, unknown> = {}
    if (NEEDS_REASON.includes(action)) {
      const reason = window.prompt(t('nliq.reason'))
      if (!reason) return
      body.reason = reason
    }
    if (action === 'mark-paid') {
      const paid = window.prompt(t('nliq.paid_on'), new Date().toISOString().slice(0, 10))
      if (!paid) return
      body.paid_on = paid
      const reference = window.prompt(t('nliq.payment_reference'))
      if (reference) body.payment_reference = reference
      if (s.reason_code === 'death') {
        const beneficiary = window.prompt(t('nliq.beneficiary_reference'))
        if (!beneficiary) return
        body.beneficiary_reference = beneficiary
      }
    }
    if (action === 'deliver-documents') {
      body.confirm_physical_signature = window.confirm(t('nliq.confirm_physical'))
    }
    void (async () => {
      try {
        const r = await api.post<{ data: Settlement }>(`/settlements/${s.id}/${action}`, body)
        setDetail(r.data)
        toast(t('nliq.done'))
        await load()
      } catch (e) { fail(e) }
    })()
  }

  const toggle = (s: Settlement, field: 'severance_applies' | 'include_pending_salary', value: boolean) => {
    void (async () => {
      try { setDetail((await api.request<{ data: Settlement }>(`/settlements/${s.id}`, 'PATCH', { [field]: value })).data) } catch (e) { fail(e) }
    })()
  }

  const sendDocuments = (s: Settlement) => {
    void (async () => {
      try {
        setDetail((await api.post<{ data: Settlement }>(`/settlements/${s.id}/documents`, { send_for_signature: true })).data)
        toast(t('nliq.sent_for_signature'))
      } catch (e) { fail(e) }
    })()
  }

  const search = useCallback(async (q: string): Promise<Person[]> => {
    const p = new URLSearchParams({ per_page: '10', 'filter[status]': 'active' })
    if (q !== '') p.set('q', q)
    const r = await api.get<{ data: Array<{ id: string; person_name?: string | null; employee_code: string }> }>(`/employments?${p.toString()}`)
    return r.data.map((e) => ({ id: e.id, name: e.person_name ?? e.employee_code, detail: e.employee_code }))
  }, [])

  const simulate = async () => {
    if (!person) return
    setSimulation(null)
    try {
      const r = await api.post<{ data: Simulation }>('/settlements/simulate', {
        employment_id: person.id, termination_dates: dates.filter((d) => d !== ''), severance_applies: severance, include_pending_salary: pending,
      })
      setSimulation(r.data)
    } catch (e) { fail(e) }
  }

  const linesTable = (lines: SettlementLine[]) => (
    <div className="tabla-wrap">
      <table className="table">
        <thead><tr><th>{t('nliq.col_concept')}</th><th>{t('nliq.col_explanation')}</th><th>{t('nliq.col_amount')}</th></tr></thead>
        <tbody>
          {lines.map((l) => (
            <tr key={l.id ?? l.concept_code}>
              <td>{l.concept_name}<div className="sub2">{t(`nliq.kind_${l.kind}`)}</div></td>
              <td>{l.explanation}</td>
              <td className="nowrap">{money(l.amount)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )

  return (
    <>
      <PageHeader title={t('nliq.title')} sub={t('nliq.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}
        <div className="seg mb-3" role="tablist" aria-label={t('nliq.title')}>
          {(['list', 'simulator'] as const).filter((v) => v === 'list' || can('payroll.settlements.simulate')).map((v) => (
            <button key={v} type="button" role="tab" aria-selected={tab === v} className={tab === v ? 'on' : undefined} onClick={() => setTab(v)}>{t(`nliq.tab_${v}`)}</button>
          ))}
        </div>

        {tab === 'list' && rows !== null && rows.length === 0 && <EmptyState title={t('nliq.title')} text={t('nliq.empty')} />}
        {tab === 'list' && rows !== null && rows.length > 0 && (
          <section className="card">
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('nliq.col_worker')}</th><th>{t('nliq.col_termination')}</th><th>{t('nliq.col_reason')}</th><th>{t('nliq.col_total')}</th><th>{t('nliq.col_due')}</th><th>{t('nliq.col_status')}</th><th /></tr></thead>
                <tbody>
                  {rows.map((s) => (
                    <tr key={s.id}>
                      <td>{s.employee_name ?? s.employment_id}<div className="sub2">{s.employee_code}</div></td>
                      <td className="nowrap">{formatDate(locale, s.termination_date)}</td>
                      <td>{s.reason_name ?? s.reason_code ?? '—'}</td>
                      <td className="nowrap">{money(s.total)}</td>
                      <td className="nowrap">{s.due_status && <span className={`tag ${DUE_TONE[s.due_status]}`}>{t(`nliq.due_${s.due_status}`)}</span>}{s.due_on && <div className="sub2">{formatDate(locale, s.due_on)}</div>}</td>
                      <td><span className={`tag ${SETTLEMENT_TONE[s.status]}`}>{t(`nliq.status_${s.status}`)}</span></td>
                      <td><button type="button" className="btn btn-ter btn-sm" onClick={() => void open(s.id)}>{t('nliq.view')}</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}

        {tab === 'list' && detail && (
          <section className="card">
            <div className="card-header">
              <h2>{detail.employee_name} · {formatDate(locale, detail.termination_date)}</h2>
              <span className={`tag ${SETTLEMENT_TONE[detail.status]}`}>{t(`nliq.status_${detail.status}`)}</span>
            </div>
            <div className="card-body">
              {(detail.errors ?? []).map((w) => <Notice key={w.code} tone="error">{w.message}</Notice>)}
              {(detail.warnings ?? []).map((w) => <Notice key={w.code} tone="warn">{w.message}</Notice>)}
              {detail.return_reason && <Notice tone="warn">{t('nliq.returned', { reason: detail.return_reason })}</Notice>}
              {can('payroll.settlements.update') && ['draft', 'calculated'].includes(detail.status) && (
                <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginBottom: 12 }}>
                  <label><input type="checkbox" checked={detail.severance_applies} onChange={(e) => toggle(detail, 'severance_applies', e.target.checked)} /> {t('nliq.severance_applies')}</label>
                  <label><input type="checkbox" checked={detail.include_pending_salary} onChange={(e) => toggle(detail, 'include_pending_salary', e.target.checked)} /> {t('nliq.include_pending_salary')}</label>
                </div>
              )}
              {detail.lines && detail.lines.length > 0 && linesTable(detail.lines)}
              {detail.total && <p><strong>{t('nliq.net')}: {money(detail.total)}</strong></p>}
              {detail.due_on && <p className="sub2">{t('nliq.due_on', { date: formatDate(locale, detail.due_on) })}</p>}
              {(detail.documents ?? []).length > 0 && (
                <ul>
                  {(detail.documents ?? []).map((d) => <li key={d.id}>{d.title} · {t(`nliq.doc_${d.status}`)}</li>)}
                </ul>
              )}
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                {visibleActions(detail, can).map((a) => (
                  <button key={a} type="button" className={a === 'void' || a === 'return' ? 'btn btn-sec btn-sm' : 'btn btn-primary btn-sm'} onClick={() => act(detail, a)}>{t(`nliq.action_${a}`)}</button>
                ))}
                {detail.status === 'paid' && can('payroll.settlements.mark_paid') && (detail.documents ?? []).some((d) => d.status === 'generated') && (
                  <button type="button" className="btn btn-sec btn-sm" onClick={() => sendDocuments(detail)}>{t('nliq.send_for_signature')}</button>
                )}
              </div>
            </div>
          </section>
        )}

        {tab === 'simulator' && (
          <section className="card">
            <div className="card-header"><h2>{t('nliq.tab_simulator')}</h2></div>
            <div className="card-body">
              <Notice tone="info">{t('nliq.sim_notice')}</Notice>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, alignItems: 'flex-end' }}>
                <PersonPicker label={t('nliq.col_worker')} value={person} onChange={setPerson} search={search} required />
                <label>{t('nliq.date_a')}<input type="date" value={dates[0]} onChange={(e) => setDates([e.target.value, dates[1]])} /></label>
                <label>{t('nliq.date_b')}<input type="date" value={dates[1]} onChange={(e) => setDates([dates[0], e.target.value])} /></label>
                <label><input type="checkbox" checked={severance} onChange={(e) => setSeverance(e.target.checked)} /> {t('nliq.severance_applies')}</label>
                <label><input type="checkbox" checked={pending} onChange={(e) => setPending(e.target.checked)} /> {t('nliq.include_pending_salary')}</label>
                <div><button type="button" className="btn btn-primary btn-sm" disabled={!person || dates[0] === ''} onClick={() => void simulate()}>{t('nliq.simulate')}</button></div>
              </div>
              {simulation && simulation.scenarios.map((s) => (
                <div key={s.termination_date} className="mt-3">
                  <h3>{t('nliq.scenario', { date: formatDate(locale, s.termination_date) })} · {money(s.total)}</h3>
                  {s.errors.map((w) => <Notice key={w.code} tone="error">{w.message}</Notice>)}
                  {s.warnings.map((w) => <Notice key={w.code} tone="warn">{w.message}</Notice>)}
                  {linesTable(s.lines)}
                </div>
              ))}
              {simulation?.comparison && <p><strong>{t('nliq.difference')}: {money(simulation.comparison.net_difference)}</strong></p>}
            </div>
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}
