'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { ExportButton } from '@/modules/exports/ExportButton'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { LINE_TONE, lineActions, sortAlerts, type AccumulationAlert, type ScheduleLine, type VacationSchedule } from './vacationPlanning'

type Option = { id: string; name: string }
type EmploymentOption = { id: string; person_name?: string | null; employee_code: string }

/**
 * Tiempo → Programación anual (VAC-5). RRHH abre el rol de una empresa y un año, propone fechas, convierte lo
 * confirmado en solicitudes aprobadas y exporta el rol; el colaborador confirma o declina sus fechas. Debajo, las
 * alertas de vacaciones acumuladas con su explicación. Días, saldos y avisos los calcula el servidor.
 */
export function VacationPlanningScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const canSchedule = can('time.vacation_requests.schedule')
  const canSeeAlerts = can('time.vacation_balances.view')
  const [schedules, setSchedules] = useState<VacationSchedule[]>([])
  const [selected, setSelected] = useState<string>('')
  const [lines, setLines] = useState<ScheduleLine[]>([])
  const [alerts, setAlerts] = useState<AccumulationAlert[]>([])
  const [entities, setEntities] = useState<Option[]>([])
  const [employments, setEmployments] = useState<EmploymentOption[]>([])
  const [openForm, setOpenForm] = useState({ legal_entity_id: '', year: String(new Date().getFullYear() + 1) })
  const [proposal, setProposal] = useState({ employment_id: '', starts_on: '', ends_on: '' })
  const [error, setError] = useState<string | null>(null)

  const loadSchedules = useCallback(async () => {
    const r = await api.get<{ data: VacationSchedule[] }>('/vacation-schedules')
    setSchedules(r.data)
    setSelected((s) => s || r.data[0]?.id || '')
  }, [])

  const loadLines = useCallback(async (id: string) => {
    if (id === '') { setLines([]); return }
    const r = await api.get<{ data: ScheduleLine[] }>(`/vacation-schedules/${id}/lines`)
    setLines(r.data)
  }, [])

  useEffect(() => {
    void loadSchedules().catch((e) => setError(e instanceof Error ? e.message : t('vpl.error')))
    if (canSeeAlerts) {
      void api.get<{ data: AccumulationAlert[] }>('/vacation-accumulation-alerts').then((r) => setAlerts(sortAlerts(r.data))).catch(() => setAlerts([]))
    }
    if (canSchedule) {
      void api.get<{ data: { id: string; legal_name: string }[] }>('/legal-entities?per_page=100').then((r) => setEntities(r.data.map((e) => ({ id: e.id, name: e.legal_name })))).catch(() => setEntities([]))
      void api.get<{ data: EmploymentOption[] }>('/employments?filter[status]=active&sort=person_name&per_page=100').then((r) => setEmployments(r.data)).catch(() => setEmployments([]))
    }
  }, [canSeeAlerts, canSchedule, loadSchedules, t])

  useEffect(() => { void loadLines(selected).catch(() => setLines([])) }, [selected, loadLines])

  const run = async (fn: () => Promise<unknown>, done: string) => {
    try {
      await fn()
      toast(t(done))
      await loadSchedules()
      await loadLines(selected)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('vpl.error'), 'error')
    }
  }

  const open = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    void run(() => api.post('/vacation-schedules', { legal_entity_id: openForm.legal_entity_id, year: Number(openForm.year) }), 'vpl.opened')
  }

  const propose = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    void run(async () => {
      const r = await api.post<{ meta?: { warnings?: { message: string }[] } }>(`/vacation-schedules/${selected}/lines`, proposal)
      const warnings = r.meta?.warnings ?? []
      if (warnings.length > 0) toast(warnings.map((w) => w.message).join(' '))
      setProposal({ employment_id: '', starts_on: '', ends_on: '' })
    }, 'vpl.proposed')
  }

  const act = (line: ScheduleLine, action: string) => {
    const note = action === 'decline' ? window.prompt(t('vpl.decline_reason')) : null
    if (action === 'decline' && !note) return
    void run(() => api.post(`/vacation-schedule-lines/${line.id}/${action}`, note ? { note } : {}), `vpl.done_${action}`)
  }

  const convert = () => {
    void run(async () => {
      const r = await api.post<{ data: { converted: number; failed: { message: string }[] } }>(`/vacation-schedules/${selected}/convert`)
      if (r.data.failed.length > 0) toast(t('vpl.convert_failed', { n: r.data.failed.length }) + ' ' + r.data.failed.map((f) => f.message).join(' '))
    }, 'vpl.converted')
  }

  const current = schedules.find((s) => s.id === selected)

  return (
    <>
      <PageHeader title={t('vpl.title')} sub={t('vpl.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}

        {canSchedule && (
          <form className="card" onSubmit={open} style={{ padding: 16, display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <div>
              <label className="form-label" htmlFor="vpl-entity">{t('vpl.company')}</label>
              <select id="vpl-entity" className="form-select" required value={openForm.legal_entity_id} onChange={(e) => setOpenForm((f) => ({ ...f, legal_entity_id: e.target.value }))}>
                <option value="">{t('vpl.choose')}</option>
                {entities.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
              </select>
            </div>
            <div>
              <label className="form-label" htmlFor="vpl-year">{t('vpl.year')}</label>
              <input id="vpl-year" className="form-control" type="number" min={2000} max={2100} required value={openForm.year} onChange={(e) => setOpenForm((f) => ({ ...f, year: e.target.value }))} />
            </div>
            <button type="submit" className="btn btn-primary">{t('vpl.open')}</button>
          </form>
        )}

        {schedules.length === 0 ? (
          <EmptyState title={t('vpl.empty_title')} text={t('vpl.empty_text')} />
        ) : (
          <section className="card">
            <div className="card-header" style={{ flexWrap: 'wrap', gap: 8 }}>
              <label className="sr-only" htmlFor="vpl-schedule">{t('vpl.schedule')}</label>
              <select id="vpl-schedule" className="form-select" value={selected} onChange={(e) => setSelected(e.target.value)} style={{ maxWidth: 320 }}>
                {schedules.map((s) => <option key={s.id} value={s.id}>{s.name} · {t(`vpl.status_${s.status}`)}</option>)}
              </select>
              {current && canSchedule && (
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                  {can('time.vacation_requests.validate') && current.counts.confirmed > 0 && <button type="button" className="btn btn-primary btn-sm" onClick={convert}>{t('vpl.convert', { n: current.counts.confirmed })}</button>}
                  {current.allowed_actions.includes('close') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(() => api.post(`/vacation-schedules/${current.id}/close`), 'vpl.closed')}>{t('vpl.close')}</button>}
                  {current.allowed_actions.includes('reopen') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(() => api.post(`/vacation-schedules/${current.id}/reopen`), 'vpl.reopened')}>{t('vpl.reopen')}</button>}
                  <ExportButton resource="vacation_schedule" filters={{ schedule_id: current.id }} onError={(m) => toast(m, 'error')} />
                </div>
              )}
            </div>

            {current?.status === 'open' && canSchedule && (
              <form onSubmit={propose} style={{ padding: '0 16px 16px', display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
                <div>
                  <label className="form-label" htmlFor="vpl-emp">{t('vpl.employee')}</label>
                  <select id="vpl-emp" className="form-select" required value={proposal.employment_id} onChange={(e) => setProposal((p) => ({ ...p, employment_id: e.target.value }))}>
                    <option value="">{t('vpl.choose')}</option>
                    {employments.map((m) => <option key={m.id} value={m.id}>{m.person_name ?? m.employee_code}</option>)}
                  </select>
                </div>
                <div>
                  <label className="form-label" htmlFor="vpl-from">{t('vpl.from')}</label>
                  <input id="vpl-from" className="form-control" type="date" required value={proposal.starts_on} onChange={(e) => setProposal((p) => ({ ...p, starts_on: e.target.value }))} />
                </div>
                <div>
                  <label className="form-label" htmlFor="vpl-to">{t('vpl.to')}</label>
                  <input id="vpl-to" className="form-control" type="date" required value={proposal.ends_on} onChange={(e) => setProposal((p) => ({ ...p, ends_on: e.target.value }))} />
                </div>
                <button type="submit" className="btn btn-sec">{t('vpl.propose')}</button>
              </form>
            )}

            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('vpl.col_employee')}</th><th>{t('vpl.col_dates')}</th><th>{t('vpl.col_days')}</th><th>{t('vpl.col_status')}</th><th><span className="sr-only">{t('vpl.col_actions')}</span></th></tr></thead>
                <tbody>
                  {lines.length === 0 && <tr><td colSpan={5} className="sub2">{t('vpl.no_lines')}</td></tr>}
                  {lines.map((l) => (
                    <tr key={l.id}>
                      <td>{l.person_name ?? '—'}</td>
                      <td className="nowrap">{formatDate(locale, l.starts_on)} – {formatDate(locale, l.ends_on)}</td>
                      <td>{l.days ?? '—'}</td>
                      <td><span className={`tag ${LINE_TONE[l.status]}`}>{t(`vpl.line_${l.status}`)}</span>{l.response_note && <div className="sub2">{l.response_note}</div>}</td>
                      <td className="nowrap">
                        {lineActions(l, { canSchedule, isOwn: !canSchedule }).map((a) => (
                          <button key={a} type="button" className="btn btn-sec btn-sm" style={{ marginInlineEnd: 4 }} onClick={() => act(l, a)}>{t(`vpl.action_${a}`)}</button>
                        ))}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}

        {canSeeAlerts && (
          <section className="card">
            <div className="card-header"><h2>{t('vpl.alerts_title')}</h2></div>
            {alerts.length === 0 ? <p className="sub2" style={{ padding: '0 16px 16px' }}>{t('vpl.no_alerts')}</p> : (
              <ul className="lista-simple" style={{ padding: '0 16px 16px' }}>
                {alerts.map((a) => (
                  <li key={a.employment_id}>
                    <strong>{a.person_name ?? '—'}</strong>{' '}
                    {a.overdue_periods > 0 && <span className="tag tag-warn">{t('vpl.overdue', { n: a.overdue_periods })}</span>}{' '}
                    {a.expiring_periods > 0 && <span className="tag tag-info">{t('vpl.expiring', { n: a.expiring_periods })}</span>}
                    <div className="sub2">{a.explanation}</div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}
