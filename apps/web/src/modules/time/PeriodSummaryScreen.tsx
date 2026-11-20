'use client'

import { useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { DAY_TONE, monthOf, type PeriodSummary } from './periodSummary'

type EmploymentOption = { id: string; person_name?: string | null; employee_code: string }

/**
 * Tiempo → Resumen del periodo (AUS-5, AUS-6). Lo que la planilla tomará de ausencias: cada día explicado (los
 * días en remoto no son falta), los totales por tipo con su efecto y, si el periodo se cerró, las diferencias.
 * Todo lo calcula el servidor; aquí solo se elige el colaborador y el periodo.
 */
export function PeriodSummaryScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [employments, setEmployments] = useState<EmploymentOption[]>([])
  const [form, setForm] = useState(() => ({ employment_id: '', ...monthOf(new Date().toISOString().slice(0, 10)) }))
  const [summary, setSummary] = useState<PeriodSummary | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    void api.get<{ data: EmploymentOption[] }>('/employments?filter[status]=active&sort=person_name&per_page=100')
      .then((r) => {
        setEmployments(r.data)
        if (r.data.length === 1) setForm((f) => ({ ...f, employment_id: r.data[0]?.id ?? '' }))
      })
      .catch(() => setEmployments([]))
  }, [])

  const load = async () => {
    if (form.employment_id === '') return
    setBusy(true)
    setError(null)
    try {
      const r = await api.get<{ data: PeriodSummary }>(`/employments/${form.employment_id}/absence-summary?from=${form.from}&to=${form.to}`)
      setSummary(r.data)
    } catch (e) {
      setSummary(null)
      setError(e instanceof Error ? e.message : t('aps.error'))
    } finally {
      setBusy(false)
    }
  }

  const submit = (e: FormEvent<HTMLFormElement>) => { e.preventDefault(); void load() }

  const close = async () => {
    if (!summary || !window.confirm(t('aps.confirm_close'))) return
    try {
      const r = await api.post<{ data: { closed: number; unchanged: number } }>('/absence-summaries/close', { from: summary.from, to: summary.to, employment_ids: [summary.employment_id] })
      toast(r.data.closed > 0 ? t('aps.closed') : t('aps.unchanged'))
      await load()
    } catch (e) {
      toast(e instanceof Error ? e.message : t('aps.error'), 'error')
    }
  }

  const n = (key: string) => String(summary?.totals[key] ?? 0)

  return (
    <>
      <PageHeader title={t('aps.title')} sub={t('aps.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <form className="card" onSubmit={submit} style={{ padding: 16, display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <div>
            <label className="form-label" htmlFor="aps-employment">{t('aps.employee')}</label>
            <select id="aps-employment" className="form-select" value={form.employment_id} onChange={(e) => setForm((f) => ({ ...f, employment_id: e.target.value }))}>
              <option value="">{t('aps.choose')}</option>
              {employments.map((m) => <option key={m.id} value={m.id}>{m.person_name ?? m.employee_code} · {m.employee_code}</option>)}
            </select>
          </div>
          <div>
            <label className="form-label" htmlFor="aps-from">{t('aps.from')}</label>
            <input id="aps-from" className="form-control" type="date" value={form.from} onChange={(e) => setForm((f) => ({ ...f, from: e.target.value }))} />
          </div>
          <div>
            <label className="form-label" htmlFor="aps-to">{t('aps.to')}</label>
            <input id="aps-to" className="form-control" type="date" value={form.to} onChange={(e) => setForm((f) => ({ ...f, to: e.target.value }))} />
          </div>
          <button type="submit" className="btn btn-primary" disabled={busy || form.employment_id === ''}>{busy && <span className="spin" aria-hidden="true" />}{t('aps.show')}</button>
        </form>

        {error && <Notice tone="error">{error}</Notice>}
        {!summary && !error && <EmptyState title={t('aps.empty_title')} text={t('aps.empty_text')} />}

        {summary && (
          <>
            {summary.closing && (
              <Notice tone={summary.closing.changed ? 'warn' : 'ok'}>
                {summary.closing.changed ? t('aps.changed_after_close', { n: summary.closing.days.length }) : t('aps.closed_no_changes')}
              </Notice>
            )}
            <section className="card">
              <div className="card-header">
                <h2>{t('aps.totals')}</h2>
                {can('time.absences.create_for_others') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void close()}>{t('aps.close')}</button>}
              </div>
              <p style={{ padding: '0 16px' }}>
                {t('aps.totals_line', { worked: n('worked_days'), remote: n('remote_days'), absence: n('absence_days'), rest: n('rest_days'), holidays: n('holidays') })}
              </p>
              <div className="tabla-wrap">
                <table className="table">
                  <thead><tr><th>{t('aps.col_type')}</th><th>{t('aps.col_days')}</th><th>{t('aps.col_hours')}</th><th>{t('aps.col_effect')}</th><th>{t('aps.col_concept')}</th></tr></thead>
                  <tbody>
                    {summary.types.length === 0 && <tr><td colSpan={5} className="sub2">{t('aps.no_absences')}</td></tr>}
                    {summary.types.map((ty) => (
                      <tr key={ty.type_code}>
                        <td>{ty.type_name}</td>
                        <td>{ty.days}</td>
                        <td>{ty.hours}</td>
                        <td>{t('aps.effect_line', { paid: ty.paid_days, unpaid: ty.unpaid_days, employer: ty.employer_paid_days, subsidy: ty.subsidized_days })}</td>
                        <td>{ty.payroll_concept_code ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
            <section className="card">
              <div className="card-header"><h2>{t('aps.days')}</h2></div>
              <div className="tabla-wrap">
                <table className="table">
                  <thead><tr><th>{t('aps.col_date')}</th><th>{t('aps.col_kind')}</th><th>{t('aps.col_explanation')}</th></tr></thead>
                  <tbody>
                    {summary.days.map((d) => (
                      <tr key={d.date}>
                        <td className="nowrap">{formatDate(locale, d.date)}</td>
                        <td><span className={`tag ${DAY_TONE[d.kind]}`}>{t(`aps.kind_${d.kind}`)}</span></td>
                        <td>{d.explanation}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          </>
        )}
      </main>
      {toastNode}
    </>
  )
}
