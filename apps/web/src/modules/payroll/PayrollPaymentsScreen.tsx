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
import { periodCode, type PayrollPeriod } from './periods'
import { allConfirmed, BATCH_TONE, canDownload, type PaymentBatch, type PrepareResult } from './review'

/**
 * Nómina → Pagos (PER-10): lotes por banco desde la planilla cerrada con las cuentas vigentes (COL-8),
 * archivo bancario por formato, confirmación con la referencia del banco y «Marcar pagado». Los importes,
 * las cuentas y el cuadre los da el servidor; los trabajadores sin cuenta se listan para pagarlos aparte.
 */
export function PayrollPaymentsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [periods, setPeriods] = useState<PayrollPeriod[] | null>(null)
  const [selected, setSelected] = useState<string>('')
  const [batches, setBatches] = useState<PaymentBatch[]>([])
  const [prepared, setPrepared] = useState<PrepareResult | null>(null)
  const [error, setError] = useState<string | null>(null)
  const canPay = can('payroll.periods.mark_paid')

  const loadPeriods = useCallback(async () => {
    try {
      const r = await api.get<{ data: PayrollPeriod[] }>('/payroll-periods')
      const payable = r.data.filter((p) => ['closed', 'paid', 'accounted'].includes(p.status))
      setPeriods(payable)
      setSelected((cur) => cur || payable[0]?.id || '')
    } catch (e) {
      setPeriods([])
      setError(e instanceof Error ? e.message : t('npay.error'))
    }
  }, [t])

  const loadBatches = useCallback(async (id: string) => {
    if (!id) return
    try {
      const r = await api.get<{ data: PaymentBatch[] }>(`/payroll-periods/${id}/payment-batches`)
      setBatches(r.data)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('npay.error'), 'error')
    }
  }, [t, toast])

  useEffect(() => { void loadPeriods() }, [loadPeriods])
  useEffect(() => { setPrepared(null); void loadBatches(selected) }, [selected, loadBatches])

  const run = async (fn: () => Promise<unknown>, done: string) => {
    try {
      await fn()
      toast(t(done))
      await Promise.all([loadBatches(selected), loadPeriods()])
    } catch (e) {
      toast(e instanceof Error ? e.message : t('npay.error'), 'error')
    }
  }

  const prepare = () => run(async () => {
    const r = await api.post<{ data: PrepareResult }>(`/payroll-periods/${selected}/payment-batches`, {})
    setPrepared(r.data)
  }, 'npay.prepared')

  const download = async (b: PaymentBatch) => {
    try {
      const { blob, filename } = await api.download(`/payroll-payment-batches/${b.id}/file`)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      link.click()
      URL.revokeObjectURL(url)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('npay.error'), 'error')
    }
  }

  const confirm = (b: PaymentBatch) => {
    const ref = window.prompt(t('npay.reference'))
    if (!ref) return
    void run(() => api.post(`/payroll-payment-batches/${b.id}/confirm`, { external_reference: ref }), 'npay.confirmed')
  }

  const cancel = (b: PaymentBatch) => {
    const reason = window.prompt(t('npay.cancel_reason'))
    if (!reason) return
    void run(() => api.post(`/payroll-payment-batches/${b.id}/cancel`, { reason }), 'npay.cancelled')
  }

  const period = periods?.find((p) => p.id === selected)
  const money = (amount: string, currency?: string) => `${currency ?? ''} ${formatNumber(locale, amount)}`.trim()

  return (
    <>
      <PageHeader title={t('npay.title')} sub={t('npay.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}
        {periods !== null && periods.length === 0 && !error && <EmptyState title={t('npay.empty_title')} text={t('npay.empty_text')} />}
        {periods !== null && periods.length > 0 && (
          <section className="card">
            <div className="card-header">
              <label>
                {t('npay.period')}{' '}
                <select value={selected} onChange={(e) => setSelected(e.target.value)}>
                  {periods.map((p) => <option key={p.id} value={p.id}>{periodCode(p)} · {p.payroll_group_name ?? ''} · {t(`per.status_${p.status}`)}</option>)}
                </select>
              </label>
              {canPay && period?.status === 'closed' && batches.filter((b) => b.status !== 'cancelled').length === 0 && (
                <button type="button" className="btn btn-primary btn-sm" onClick={() => void prepare()}>{t('npay.prepare')}</button>
              )}
              {canPay && period?.allowed_actions.includes('mark-paid') && allConfirmed(batches) && (
                <button type="button" className="btn btn-primary btn-sm" onClick={() => void run(() => api.post(`/payroll-periods/${selected}/mark-paid`, { payment_method: 'bank_file' }), 'npay.paid')}>{t('npay.mark_paid')}</button>
              )}
            </div>
            {period?.paid_at && <Notice tone="ok">{t('npay.paid_on', { date: formatDate(locale, period.paid_at.slice(0, 10)) })}</Notice>}
          </section>
        )}

        {prepared && (
          <section className="card">
            <div className="card-header"><h2>{t('npay.summary')}</h2></div>
            <ul style={{ margin: 0, padding: '0 16px 16px 32px' }}>
              <li>{t('npay.total_net')}: {money(prepared.totals.period_net, prepared.totals.currency)}</li>
              <li>{t('npay.total_batched')}: {money(prepared.totals.batched, prepared.totals.currency)}</li>
              <li>{t('npay.total_unassigned')}: {money(prepared.totals.unassigned, prepared.totals.currency)}</li>
            </ul>
            {!prepared.totals.balanced && <Notice tone="warn">{t('npay.not_balanced')}</Notice>}
            {prepared.unassigned.length > 0 && (
              <>
                <Notice tone="warn">{t('npay.unassigned', { n: prepared.unassigned.length })}</Notice>
                <ul className="sub2" style={{ padding: '0 16px 16px 32px' }}>
                  {prepared.unassigned.map((u) => <li key={u.employment_id}>{u.employee_name ?? u.employment_id} {u.employee_code} · {money(u.amount, prepared.totals.currency)}</li>)}
                </ul>
              </>
            )}
          </section>
        )}

        {selected && (
          <section className="card">
            <div className="card-header"><h2>{t('npay.batches')}</h2></div>
            {batches.length === 0 && <p className="sub2" style={{ padding: '0 16px 16px' }}>{t('npay.no_batches')}</p>}
            {batches.length > 0 && (
              <div className="tabla-wrap">
                <table className="table">
                  <thead><tr><th>{t('npay.col_bank')}</th><th>{t('npay.col_items')}</th><th>{t('npay.col_total')}</th><th>{t('npay.col_status')}</th><th /></tr></thead>
                  <tbody>
                    {batches.map((b) => (
                      <tr key={b.id}>
                        <td>{b.bank_code ?? '—'}{b.external_reference && <div className="sub2">{t('npay.reference_label')}: {b.external_reference}</div>}</td>
                        <td>{b.items_count}</td>
                        <td className="nowrap">{b.total ? money(b.total.amount, b.total.currency) : '—'}</td>
                        <td><span className={`tag ${BATCH_TONE[b.status]}`}>{t(`npay.status_${b.status}`)}</span></td>
                        <td className="nowrap">
                          {canPay && b.allowed_actions.includes('generate') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(() => api.post(`/payroll-payment-batches/${b.id}/generate`, {}), 'npay.generated')}>{t('npay.generate')}</button>}{' '}
                          {canPay && canDownload(b) && <button type="button" className="btn btn-sec btn-sm" onClick={() => void download(b)}>{t('npay.download')}</button>}{' '}
                          {canPay && b.has_file && b.allowed_actions.includes('confirm') && <button type="button" className="btn btn-sec btn-sm" onClick={() => confirm(b)}>{t('npay.confirm')}</button>}{' '}
                          {canPay && b.allowed_actions.includes('cancel') && <button type="button" className="btn btn-ter btn-sm" onClick={() => cancel(b)}>{t('npay.cancel')}</button>}
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
