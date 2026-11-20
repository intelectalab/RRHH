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
import { ACTIVITIES, DEPOSIT_TONE, DISTRIBUTION_TONE, type CtsDeposit, type ProfitSharingDistribution } from './benefits'
import { periodCode, type PayrollPeriod } from './periods'
import type { Money } from './review'

type Entity = { id: string; legal_name?: string; trade_name?: string | null }

/**
 * Nómina → Beneficios sociales (BSO-2 y BSO-3): depósitos de CTS de un periodo de CTS con el registro del depósito y
 * su liquidación, y la distribución anual de utilidades (renta declarada, reparto por días y por remuneraciones,
 * aprobación). Los montos, meses computables, porcentajes y topes los calcula el servidor; aquí solo se muestran.
 */
export function BenefitsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [tab, setTab] = useState<'cts' | 'profit'>('cts')
  const [periods, setPeriods] = useState<PayrollPeriod[] | null>(null)
  const [selected, setSelected] = useState('')
  const [deposits, setDeposits] = useState<CtsDeposit[]>([])
  const [entities, setEntities] = useState<Entity[]>([])
  const [distributions, setDistributions] = useState<ProfitSharingDistribution[]>([])
  const [detail, setDetail] = useState<ProfitSharingDistribution | null>(null)
  const [form, setForm] = useState({ legal_entity_id: '', fiscal_year: String(new Date().getFullYear() - 1), amount: '', activity: 'otras_actividades' })
  const [error, setError] = useState<string | null>(null)
  const canCts = can('payroll.benefit_runs.manage_cts')
  const canConfigure = can('payroll.benefit_runs.configure_profit_sharing')
  const canApprove = can('payroll.benefit_runs.approve')

  const fail = useCallback((e: unknown) => toast(e instanceof Error ? e.message : t('nben.error'), 'error'), [t, toast])
  const money = (m?: Money | null) => (m ? `${m.currency} ${formatNumber(locale, m.amount)}` : '—')

  const loadDeposits = useCallback(async (id: string) => {
    if (!id) return
    try {
      setDeposits((await api.get<{ data: CtsDeposit[] }>(`/payroll-periods/${id}/cts-deposits`)).data)
    } catch (e) { fail(e) }
  }, [fail])

  const loadDistributions = useCallback(async () => {
    try {
      setDistributions((await api.get<{ data: ProfitSharingDistribution[] }>('/profit-sharing-distributions')).data)
    } catch (e) { fail(e) }
  }, [fail])

  useEffect(() => {
    void (async () => {
      try {
        const r = await api.get<{ data: PayrollPeriod[] }>('/payroll-periods')
        const cts = r.data.filter((p) => p.kind === 'cts')
        setPeriods(cts)
        setSelected((cur) => cur || cts[0]?.id || '')
      } catch (e) {
        setPeriods([])
        setError(e instanceof Error ? e.message : t('nben.error'))
      }
      try {
        const r = await api.get<{ data: Entity[] }>('/legal-entities?per_page=100')
        setEntities(r.data)
        setForm((f) => ({ ...f, legal_entity_id: f.legal_entity_id || r.data[0]?.id || '' }))
      } catch { setEntities([]) }
    })()
    void loadDistributions()
  }, [t, loadDistributions])
  useEffect(() => { void loadDeposits(selected) }, [selected, loadDeposits])

  const deposit = (d: CtsDeposit) => {
    const date = window.prompt(t('nben.deposited_on'), new Date().toISOString().slice(0, 10))
    if (!date) return
    const reference = window.prompt(t('nben.reference'))
    if (!reference) return
    void (async () => {
      try {
        await api.post(`/cts-deposits/${d.id}/deposit`, { deposited_on: date, deposit_reference: reference })
        toast(t('nben.deposited'))
        await loadDeposits(selected)
      } catch (e) { fail(e) }
    })()
  }

  const openDetail = async (id: string) => {
    try { setDetail((await api.get<{ data: ProfitSharingDistribution }>(`/profit-sharing-distributions/${id}`)).data) } catch (e) { fail(e) }
  }

  const act = async (d: ProfitSharingDistribution, action: 'calculate' | 'approve') => {
    try {
      await api.post(`/profit-sharing-distributions/${d.id}/${action}`, {})
      toast(t(action === 'calculate' ? 'nben.calculated' : 'nben.approved'))
      await Promise.all([loadDistributions(), openDetail(d.id)])
    } catch (e) { fail(e) }
  }

  const create = async () => {
    try {
      // La moneda la fija el servidor con la de la empresa.
      await api.post('/profit-sharing-distributions', {
        legal_entity_id: form.legal_entity_id, fiscal_year: Number(form.fiscal_year), activity: form.activity,
        net_income_before_tax: { amount: form.amount },
      })
      toast(t('nben.created'))
      setForm((f) => ({ ...f, amount: '' }))
      await loadDistributions()
    } catch (e) { fail(e) }
  }

  const entityName = (id: string) => { const e = entities.find((x) => x.id === id); return e?.trade_name || e?.legal_name || '' }

  return (
    <>
      <PageHeader title={t('nben.title')} sub={t('nben.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}
        <div className="seg mb-3" role="tablist" aria-label={t('nben.title')}>
          {(['cts', 'profit'] as const).map((v) => (
            <button key={v} type="button" role="tab" aria-selected={tab === v} className={tab === v ? 'on' : undefined} onClick={() => setTab(v)}>{t(`nben.tab_${v}`)}</button>
          ))}
        </div>

        {tab === 'cts' && periods !== null && periods.length === 0 && <EmptyState title={t('nben.tab_cts')} text={t('nben.no_periods')} />}
        {tab === 'cts' && periods !== null && periods.length > 0 && (
          <section className="card">
            <div className="card-header">
              <label>
                {t('nben.period')}{' '}
                <select value={selected} onChange={(e) => setSelected(e.target.value)}>
                  {periods.map((p) => <option key={p.id} value={p.id}>{periodCode(p)} · {p.payroll_group_name ?? ''} · {t(`per.status_${p.status}`)}</option>)}
                </select>
              </label>
            </div>
            {deposits.length === 0 && <p className="sub2" style={{ padding: '0 16px 16px' }}>{t('nben.no_deposits')}</p>}
            {deposits.length > 0 && (
              <div className="tabla-wrap">
                <table className="table">
                  <thead><tr><th>{t('nben.col_worker')}</th><th>{t('nben.col_time')}</th><th>{t('nben.col_base')}</th><th>{t('nben.col_grat')}</th><th>{t('nben.col_amount')}</th><th>{t('nben.col_bank')}</th><th>{t('nben.col_status')}</th><th /></tr></thead>
                  <tbody>
                    {deposits.map((d) => (
                      <tr key={d.id}>
                        <td>{d.person_name ?? d.employment_id}<div className="sub2">{d.employee_code}</div></td>
                        <td className="nowrap">{t('nben.time', { m: d.computable_months, d: d.computable_days })}</td>
                        <td className="nowrap">{money(d.computable_remuneration)}</td>
                        <td className="nowrap">{money(d.last_gratification)}</td>
                        <td className="nowrap">{money(d.amount)}</td>
                        <td>{d.deposit_bank_code ?? '—'}{d.deposit_reference && <div className="sub2">{d.deposit_reference} · {d.deposited_on ? formatDate(locale, d.deposited_on) : ''}</div>}</td>
                        <td><span className={`tag ${DEPOSIT_TONE[d.status]}`}>{t(`nben.status_${d.status}`)}</span></td>
                        <td>{canCts && d.allowed_actions.includes('deposit') && <button type="button" className="btn btn-sec btn-sm" onClick={() => deposit(d)}>{t('nben.deposit')}</button>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        )}

        {tab === 'profit' && (
          <>
            {canConfigure && (
              <section className="card">
                <div className="card-header"><h2>{t('nben.new_distribution')}</h2></div>
                <div className="card-body" style={{ display: 'flex', flexWrap: 'wrap', gap: 12, alignItems: 'flex-end' }}>
                  <label>{t('nben.entity')}
                    <select value={form.legal_entity_id} onChange={(e) => setForm({ ...form, legal_entity_id: e.target.value })}>
                      {entities.map((e) => <option key={e.id} value={e.id}>{e.trade_name || e.legal_name}</option>)}
                    </select>
                  </label>
                  <label>{t('nben.fiscal_year')}<input type="number" value={form.fiscal_year} onChange={(e) => setForm({ ...form, fiscal_year: e.target.value })} /></label>
                  <label>{t('nben.net_income')}<input inputMode="decimal" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></label>
                  <label>{t('nben.activity')}
                    <select value={form.activity} onChange={(e) => setForm({ ...form, activity: e.target.value })}>
                      {ACTIVITIES.map((a) => <option key={a} value={a}>{t(`nben.activity_${a}`)}</option>)}
                    </select>
                  </label>
                  <div><button type="button" className="btn btn-primary btn-sm" disabled={!form.legal_entity_id || !form.amount} onClick={() => void create()}>{t('nben.save')}</button></div>
                </div>
              </section>
            )}
            <section className="card">
              {distributions.length === 0 && <p className="sub2" style={{ padding: 16 }}>{t('nben.no_distributions')}</p>}
              {distributions.length > 0 && (
                <div className="tabla-wrap">
                  <table className="table">
                    <thead><tr><th>{t('nben.entity')}</th><th>{t('nben.fiscal_year')}</th><th>{t('nben.net_income')}</th><th>{t('nben.rate')}</th><th>{t('nben.total')}</th><th>{t('nben.workers')}</th><th>{t('nben.col_status')}</th><th /></tr></thead>
                    <tbody>
                      {distributions.map((d) => (
                        <tr key={d.id}>
                          <td>{entityName(d.legal_entity_id)}</td>
                          <td>{d.fiscal_year}</td>
                          <td className="nowrap">{money(d.net_income_before_tax)}</td>
                          <td>{d.participation_rate !== null ? `${formatNumber(locale, d.participation_rate)} %` : '—'}</td>
                          <td className="nowrap">{money(d.total_amount)}</td>
                          <td>{d.workers_count ?? '—'}</td>
                          <td><span className={`tag ${DISTRIBUTION_TONE[d.status]}`}>{t(`nben.dstatus_${d.status}`)}</span></td>
                          <td className="nowrap">
                            <button type="button" className="btn btn-ter btn-sm" onClick={() => void openDetail(d.id)}>{t('nben.view')}</button>{' '}
                            {canConfigure && d.allowed_actions.includes('calculate') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void act(d, 'calculate')}>{t('nben.calculate')}</button>}{' '}
                            {canApprove && d.allowed_actions.includes('approve') && <button type="button" className="btn btn-primary btn-sm" onClick={() => void act(d, 'approve')}>{t('nben.approve')}</button>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
            {detail && (
              <section className="card">
                <div className="card-header"><h2>{entityName(detail.legal_entity_id)} · {detail.fiscal_year}</h2></div>
                {detail.warnings.map((w) => <Notice key={w.code} tone="warn">{w.message}</Notice>)}
                <div className="tabla-wrap">
                  <table className="table">
                    <thead><tr><th>{t('nben.col_worker')}</th><th>{t('nben.days')}</th><th>{t('nben.remunerations')}</th><th>{t('nben.by_days')}</th><th>{t('nben.by_rem')}</th><th>{t('nben.col_amount')}</th></tr></thead>
                    <tbody>
                      {(detail.items ?? []).map((i) => (
                        <tr key={i.employment_id}>
                          <td>{i.person_name}<div className="sub2">{i.employee_code}{i.terminated && ` · ${t('nben.terminated')}`}{i.cap_applied && ` · ${t('nben.capped')}`}</div></td>
                          <td>{formatNumber(locale, i.days_worked)}</td>
                          <td className="nowrap">{money(i.remunerations)}</td>
                          <td className="nowrap">{money(i.amount_by_days)}</td>
                          <td className="nowrap">{money(i.amount_by_remuneration)}</td>
                          <td className="nowrap">{money(i.amount)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            )}
          </>
        )}
      </main>
      {toastNode}
    </>
  )
}
