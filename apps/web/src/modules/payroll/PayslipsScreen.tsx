'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDateTime, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { periodCode, type PayrollPeriod } from './periods'
import { PAYSLIP_TONE, pendingAck, saveBlob, type Money, type Payslip, type PayslipExplanation, type PayslipSummary } from './payslips'

/**
 * Nómina → Boletas (BOL-1 a BOL-5): genera las boletas del periodo aprobado o cerrado, las publica en el portal,
 * muestra la constancia de entrega de cada una (vista, descarga, acuse o entrega en físico), anula con motivo
 * (la corrección sale como nueva versión), descarga en bloque por enlace temporal y explica el cambio de neto.
 * Montos, estados y explicaciones los da el servidor; sin permiso de resultados los montos no llegan.
 */
export function PayslipsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [periods, setPeriods] = useState<PayrollPeriod[] | null>(null)
  const [selected, setSelected] = useState('')
  const [rows, setRows] = useState<Payslip[]>([])
  const [summary, setSummary] = useState<PayslipSummary | null>(null)
  const [explained, setExplained] = useState<{ payslip: Payslip; data: PayslipExplanation } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const canPublish = can('payroll.payslips.publish')
  const canVoid = can('payroll.payslips.void')
  const canAmounts = can('payroll.results.view')
  const canBulk = can('payroll.payslips.bulk_download') && canAmounts

  const loadPeriods = useCallback(async () => {
    try {
      const r = await api.get<{ data: PayrollPeriod[] }>('/payroll-periods')
      const ready = r.data.filter((p) => ['approved', 'closed', 'paid', 'accounted'].includes(p.status))
      setPeriods(ready)
      setSelected((cur) => cur || ready[0]?.id || '')
    } catch (e) {
      setPeriods([])
      setError(e instanceof Error ? e.message : t('bol.error'))
    }
  }, [t])

  const load = useCallback(async (id: string) => {
    if (!id) return
    try {
      const [list, sum] = await Promise.all([
        api.get<{ data: Payslip[] }>(`/payslips?filter[payroll_period_id]=${id}&per_page=100`),
        api.get<{ data: PayslipSummary }>(`/payroll-periods/${id}/payslip-summary`),
      ])
      setRows(list.data)
      setSummary(sum.data)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('bol.error'), 'error')
    }
  }, [t, toast])

  useEffect(() => { void loadPeriods() }, [loadPeriods])
  useEffect(() => { setExplained(null); void load(selected) }, [selected, load])

  const run = async (fn: () => Promise<unknown>, done: string) => {
    try {
      await fn()
      toast(t(done))
      await load(selected)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('bol.error'), 'error')
    }
  }

  const download = async (p: Payslip) => {
    try {
      const { blob, filename } = await api.download(`/payslips/${p.id}/file`)
      saveBlob(blob, filename)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('bol.error'), 'error')
    }
  }

  const bulk = () => run(async () => {
    const r = await api.post<{ data: { url: string } }>('/payslips/bulk-download', { payroll_period_id: selected })
    window.open(r.data.url, '_blank', 'noopener')
  }, 'bol.bulk_ready')

  const voidOne = (p: Payslip) => {
    const reason = window.prompt(t('bol.void_reason'))
    if (!reason) return
    void run(() => api.post(`/payslips/${p.id}/void`, { reason }), 'bol.voided')
  }

  const printed = (p: Payslip) => {
    const note = window.prompt(t('bol.printed_note'))
    if (note === null) return
    void run(() => api.post(`/payslips/${p.id}/deliver-printed`, { note: note || null }), 'bol.printed_done')
  }

  const explain = async (p: Payslip) => {
    try {
      const r = await api.get<{ data: PayslipExplanation }>(`/payslips/${p.id}/explanation`)
      setExplained({ payslip: p, data: r.data })
    } catch (e) {
      toast(e instanceof Error ? e.message : t('bol.error'), 'error')
    }
  }

  const money = (m?: Money | null) => (m ? `${m.currency} ${formatNumber(locale, m.amount)}` : '—')
  const period = periods?.find((p) => p.id === selected)

  return (
    <>
      <PageHeader title={t('bol.title')} sub={t('bol.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}
        {periods !== null && periods.length === 0 && !error && <EmptyState title={t('bol.empty_title')} text={t('bol.empty_text')} />}
        {periods !== null && periods.length > 0 && (
          <section className="card">
            <div className="card-header">
              <label>
                {t('bol.period')}{' '}
                <select value={selected} onChange={(e) => setSelected(e.target.value)}>
                  {periods.map((p) => <option key={p.id} value={p.id}>{periodCode(p)} · {p.payroll_group_name ?? ''} · {t(`per.status_${p.status}`)}</option>)}
                </select>
              </label>
              {canPublish && period && <button type="button" className="btn btn-primary btn-sm" onClick={() => void run(() => api.post(`/payroll-periods/${selected}/payslips`, {}), 'bol.generated')}>{t('bol.generate')}</button>}
              {canPublish && summary && summary.generated > 0 && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(() => api.post(`/payroll-periods/${selected}/payslips/publish`, {}), 'bol.published')}>{t('bol.publish_all')}</button>}
              {canBulk && rows.length > 0 && <button type="button" className="btn btn-sec btn-sm" onClick={() => void bulk()}>{t('bol.bulk')}</button>}
            </div>
            {summary && (
              <p className="sub2" style={{ padding: '0 16px 16px' }}>
                {t('bol.summary', { total: summary.total, generated: summary.generated, published: summary.published + summary.viewed, acknowledged: summary.acknowledged, pending: summary.pending_acknowledgement, printed: summary.printed, voided: summary.voided })}
              </p>
            )}
          </section>
        )}

        {selected && (
          <section className="card">
            <div className="card-header"><h2>{t('bol.list')}</h2></div>
            {rows.length === 0 && <p className="sub2" style={{ padding: '0 16px 16px' }}>{t('bol.no_rows')}</p>}
            {rows.length > 0 && (
              <div className="tabla-wrap">
                <table className="table">
                  <thead><tr><th>{t('bol.col_worker')}</th><th>{t('bol.col_number')}</th>{canAmounts && <th>{t('bol.col_net')}</th>}<th>{t('bol.col_status')}</th><th>{t('bol.col_delivery')}</th><th /></tr></thead>
                  <tbody>
                    {rows.map((p) => (
                      <tr key={p.id}>
                        <td>{p.employee_name}<div className="sub2">{p.employee_code}</div></td>
                        <td className="nowrap">{p.number}{p.version > 1 && <div className="sub2">{t('bol.version', { n: p.version })}</div>}</td>
                        {canAmounts && <td className="nowrap">{money(p.net)}</td>}
                        <td>
                          <span className={`tag ${PAYSLIP_TONE[p.status]}`}>{t(`bol.status_${p.status}`)}</span>
                          {pendingAck(p) && <div className="sub2">{t('bol.pending_ack')}</div>}
                          {p.void_reason && <div className="sub2">{p.void_reason}</div>}
                        </td>
                        <td className="sub2">
                          {p.acknowledged_at ? t('bol.ack_on', { date: formatDateTime(locale, p.acknowledged_at) })
                            : p.first_viewed_at ? t('bol.viewed_on', { date: formatDateTime(locale, p.first_viewed_at) })
                              : p.published_at ? t('bol.published_on', { date: formatDateTime(locale, p.published_at) }) : '—'}
                        </td>
                        <td className="nowrap">
                          {canAmounts && <button type="button" className="btn btn-sec btn-sm" onClick={() => void download(p)}>{t('bol.pdf')}</button>}{' '}
                          {canAmounts && p.status !== 'voided' && <button type="button" className="btn btn-ter btn-sm" onClick={() => void explain(p)}>{t('bol.explain')}</button>}{' '}
                          {canPublish && p.allowed_actions?.includes('publish') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(() => api.post(`/payslips/${p.id}/publish`, {}), 'bol.published')}>{t('bol.publish')}</button>}{' '}
                          {canPublish && p.allowed_actions?.includes('acknowledge') && <button type="button" className="btn btn-ter btn-sm" onClick={() => printed(p)}>{t('bol.printed')}</button>}{' '}
                          {canVoid && p.allowed_actions?.includes('void') && <button type="button" className="btn btn-ter btn-sm" onClick={() => voidOne(p)}>{t('bol.void')}</button>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        )}

        {explained && (
          <section className="card">
            <div className="card-header"><h2>{t('bol.explain_title', { name: explained.payslip.employee_name ?? '' })}</h2></div>
            <div style={{ padding: '0 16px 16px' }}>
              <p>{explained.data.summary}</p>
              {explained.data.items.length > 0 && (
                <ul>
                  {explained.data.items.map((i) => <li key={i.concept_code}>{i.text}{i.trace.length > 0 && <div className="sub2">{i.trace.join(' · ')}</div>}</li>)}
                </ul>
              )}
            </div>
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}
