'use client'

import { Fragment, useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import type { ProfileTabContext } from '@/modules/people/profile/types'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { StatusBadge, type StatusTone } from '@/ui/components/Status'

export const BALANCE_COLUMNS = ['generated', 'adjusted', 'scheduled', 'taken', 'sold', 'settled', 'available'] as const
type Column = (typeof BALANCE_COLUMNS)[number]
type Totals = Record<Column, string>
type PeriodStatus = 'accruing' | 'earned' | 'overdue' | 'closed'
type Period = {
  id: string
  period_start: string
  period_end: string
  status: PeriodStatus
  entitled_days: string
  accrued_days: string
  earned_on: string | null
  enjoy_by: string | null
  pending_validation: boolean
} & Totals
export type VacationBalance = {
  id: string
  as_of: string
  totals: Totals
  accruing: { period_start: string; period_end: string; entitled_days: string; accrued_days: string } | null
  advance_available: string
  overdue_days: string
  pending_validation: boolean
  periods: Period[]
  policy: { policy_code: string | null; policy_name: string | null; source: 'policy' | 'legal'; days_per_year: string | null; pending_validation: boolean }
}
type Entry = {
  id: string
  entry_type: string
  column: Column
  days: string
  effective_on: string
  period_start: string | null
  period_end: string | null
  reason: string | null
  evidence: string | null
}
type AdjustForm = { period_id: string; days: string; reason: string; evidence: string }

const TONE: Record<PeriodStatus, StatusTone> = { accruing: 'in', earned: 'ok', overdue: 'er', closed: 'gr' }

/**
 * VAC-2 · Pestaña Vacaciones de la ficha 360: el saldo lo calcula el servidor como suma del libro de
 * movimientos (regla 11); aquí solo se muestran las columnas, los periodos con su plazo de goce y los
 * movimientos que explican el saldo. El ajuste exige motivo y sustento y queda auditado.
 */
export function VacationsTab({ header, toast }: ProfileTabContext) {
  const { t, locale } = useT()
  const { can } = useSession()
  const base = `/vacation-balances/${header.id}`
  const [balance, setBalance] = useState<VacationBalance | null>(null)
  const [entries, setEntries] = useState<Entry[]>([])
  const [failed, setFailed] = useState(false)
  const [form, setForm] = useState<AdjustForm | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const [b, l] = await Promise.all([
        api.get<{ data: VacationBalance }>(base),
        api.get<{ data: Entry[] }>(`${base}/ledger`),
      ])
      setBalance(b.data)
      setEntries(l.data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [base])

  useEffect(() => { void load() }, [load])

  const days = (v: string) => t('vac.days', { n: formatNumber(locale, v) })
  const range = (from: string, to: string) => `${formatDate(locale, from)} – ${formatDate(locale, to)}`

  async function save() {
    if (!form) return
    setBusy(true); setErrors({}); setGeneral(null)
    try {
      await api.post(`${base}/adjust`, { period_id: form.period_id || null, days: form.days, reason: form.reason, evidence: form.evidence })
      setForm(null)
      toast(t('vac.adjusted'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields); setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  const set = (patch: Partial<AdjustForm>) => form && setForm({ ...form, ...patch })
  const err = (k: string) => errors[k] && <div className="invalid-feedback d-block" role="alert">{errors[k]}</div>

  return (
    <div className="emp-secciones">
      {failed && <Notice tone="error">{t('common.load_error')}</Notice>}
      {balance?.pending_validation && <Notice tone="warn">{t('vac.pending_validation')}</Notice>}

      {balance && (
        <Card
          title={t('vac.balance_title')}
          context={balance.policy.source === 'policy'
            ? t('vac.policy_context', { name: balance.policy.policy_name ?? '' })
            : t('vac.legal_context')}
          actions={can('time.vacation_balances.adjust')
            ? <button type="button" className="btn btn-sec btn-sm" onClick={() => { setErrors({}); setGeneral(null); setForm({ period_id: '', days: '', reason: '', evidence: '' }) }}>{t('vac.adjust')}</button>
            : undefined}
        >
          <dl className="ficha">
            <dt>{t('vac.col_available')}</dt><dd><b>{days(balance.totals.available)}</b></dd>
            {BALANCE_COLUMNS.filter((c) => c !== 'available').map((c) => (
              <Fragment key={c}><dt>{t(`vac.col_${c}`)}</dt><dd>{days(balance.totals[c])}</dd></Fragment>
            ))}
            {balance.accruing && (<><dt>{t('vac.accruing')}</dt><dd>{t('vac.accruing_value', { accrued: formatNumber(locale, balance.accruing.accrued_days), entitled: formatNumber(locale, balance.accruing.entitled_days) })}</dd></>)}
            {balance.advance_available !== '0.00' && (<><dt>{t('vac.advance')}</dt><dd>{days(balance.advance_available)}</dd></>)}
            {balance.overdue_days !== '0.00' && (<><dt>{t('vac.overdue')}</dt><dd><StatusBadge tone="er">{days(balance.overdue_days)}</StatusBadge></dd></>)}
          </dl>
        </Card>
      )}

      {balance && balance.periods.length > 0 && (
        <Card title={t('vac.periods_title')} context={t('vac.periods_context')} flush>
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr>
                <th>{t('vac.period')}</th><th>{t('vac.entitled')}</th><th>{t('vac.col_scheduled')}</th><th>{t('vac.col_taken')}</th>
                <th>{t('vac.col_available')}</th><th>{t('vac.enjoy_by')}</th><th>{t('vac.status')}</th>
              </tr></thead>
              <tbody>
                {[...balance.periods].reverse().map((p) => (
                  <tr key={p.id}>
                    <td>{range(p.period_start, p.period_end)}</td>
                    <td>{p.status === 'accruing' ? t('vac.accruing_value', { accrued: formatNumber(locale, p.accrued_days), entitled: formatNumber(locale, p.entitled_days) }) : days(p.entitled_days)}
                      {p.pending_validation && <span className="badge text-bg-warning" style={{ marginInlineStart: 8 }}>{t('vac.pending')}</span>}</td>
                    <td>{days(p.scheduled)}</td>
                    <td>{days(p.taken)}</td>
                    <td><b>{days(p.available)}</b></td>
                    <td>{p.enjoy_by ? formatDate(locale, p.enjoy_by) : '—'}</td>
                    <td><StatusBadge tone={TONE[p.status]}>{t(`vac.status_${p.status}`)}</StatusBadge></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {balance && (
        <Card title={t('vac.ledger_title')} context={t('vac.ledger_context')}>
          {entries.length === 0 && <p className="text-secondary">{t('vac.ledger_empty')}</p>}
          {entries.length > 0 && (
            <ul className="sec-lista">
              {entries.map((e) => (
                <li key={e.id}>
                  <div className="sec-fila">
                    <div>
                      <b>{t(`vac.entry_${e.entry_type}`)}</b>
                      <span className="text-secondary small">{formatDate(locale, e.effective_on)}{e.period_start && e.period_end ? ` · ${range(e.period_start, e.period_end)}` : ''}</span>
                      {e.reason && <span className="small text-secondary">{e.reason}</span>}
                      {e.evidence && <span className="small text-secondary">{t('vac.evidence_line', { evidence: e.evidence })}</span>}
                    </div>
                    <div className="sec-acciones"><b>{days(e.days)}</b></div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {form && balance && (
        <Modal title={t('vac.adjust_title')} onClose={() => setForm(null)} footer={<>
          <button type="button" className="btn btn-sec" onClick={() => setForm(null)}>{t('common.cancel')}</button>
          <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void save()}>{t('vac.adjust_submit')}</button>
        </>}>
          {general && <div className="alert alert-danger" role="alert">{general}</div>}
          <div className="nota">{t('vac.adjust_help')}</div>
          <div className="param-form">
            <label className="form-label" htmlFor="vac-period">{t('vac.period')}</label>
            <select id="vac-period" className="form-select" value={form.period_id} onChange={(e) => set({ period_id: e.target.value })}>
              <option value="">{t('vac.period_general')}</option>
              {balance.periods.map((p) => <option key={p.id} value={p.id}>{range(p.period_start, p.period_end)}</option>)}
            </select>
            {err('period_id')}
            <label className="form-label" htmlFor="vac-days">{t('vac.adjust_days')}</label>
            <input id="vac-days" type="number" step="0.5" className={`form-control${errors.days ? ' is-invalid' : ''}`} value={form.days} onChange={(e) => set({ days: e.target.value })} />
            <span className="sub2">{t('vac.adjust_days_help')}</span>
            {err('days')}
            <label className="form-label" htmlFor="vac-reason">{t('vac.adjust_reason')}</label>
            <textarea id="vac-reason" className={`form-control${errors.reason ? ' is-invalid' : ''}`} rows={2} value={form.reason} onChange={(e) => set({ reason: e.target.value })} />
            {err('reason')}
            <label className="form-label" htmlFor="vac-evidence">{t('vac.adjust_evidence')}</label>
            <input id="vac-evidence" className={`form-control${errors.evidence ? ' is-invalid' : ''}`} value={form.evidence} onChange={(e) => set({ evidence: e.target.value })} />
            {err('evidence')}
          </div>
        </Modal>
      )}
    </div>
  )
}
