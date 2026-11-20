'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate, formatDateTime, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { invoiceLabel, invoiceTone, type BillingMoney, type BillingStatement } from './billing'

/**
 * SAS-11 · Configuración → Plan y facturación: el plan contratado, los comprobantes electrónicos con su constancia
 * de aceptación, los pagos, el saldo y la regla de mora (avisos y fecha de suspensión).
 */
export function BillingScreen() {
  const { t, locale } = useT()
  const [data, setData] = useState<BillingStatement | null>(null)
  const [failed, setFailed] = useState(false)
  const money = (m: BillingMoney) => `${m.currency} ${formatNumber(locale, m.amount)}`

  const load = useCallback(() => {
    setFailed(false)
    api.get<{ data: BillingStatement }>('/billing/account').then((r) => setData(r.data)).catch(() => setFailed(true))
  }, [])
  useEffect(load, [load])

  return (
    <>
      <PageHeader title={t('billing.title')} sub={t('billing.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <p className="mb-3"><Link href="/configuracion">{t('billing.back')}</Link></p>
        {failed && <ErrorState text={t('billing.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
        {!failed && data === null && <span className="esq" style={{ width: 320 }} />}
        {data && (
          <>
            {data.overdue_invoices > 0 && (
              <Notice tone="error" title={t('billing.overdue_title')}>
                {data.dunning.suspend_after_overdue_days > 0
                  ? t('billing.overdue_text', { days: data.dunning.suspend_after_overdue_days })
                  : t('billing.overdue_text_no_suspend')}
              </Notice>
            )}
            {data.overdue_invoices === 0 && data.next_due_on && (
              <Notice tone="info" title={t('billing.next_due_title')}>{t('billing.next_due_text', { date: formatDate(locale, data.next_due_on) })}</Notice>
            )}
            <section className="card mb-3">
              <div className="card-header"><h2>{t('billing.plan')}</h2></div>
              <div className="card-body">
                {data.subscription === null ? <p className="mb-0 text-secondary">{t('billing.no_plan')}</p> : (
                  <dl className="ficha mb-0">
                    <dt>{t('billing.plan_name')}</dt><dd>{data.subscription.plan}</dd>
                    <dt>{t('billing.cycle')}</dt><dd>{t(`billing.cycle_${data.subscription.billing_cycle}`)}</dd>
                    {data.subscription.price && <><dt>{t('billing.price')}</dt><dd>{money(data.subscription.price)}</dd></>}
                    <dt>{t('billing.balance')}</dt><dd>{data.balance.length === 0 ? t('billing.no_balance') : data.balance.map(money).join(' · ')}</dd>
                  </dl>
                )}
              </div>
            </section>
            <section className="card mb-3">
              <div className="card-header"><h2>{t('billing.invoices')}</h2></div>
              {data.invoices.length === 0 ? <EmptyState title={t('billing.invoices_empty_title')} text={t('billing.invoices_empty')} /> : (
                <div className="tabla-wrap">
                  <table className="table">
                    <thead><tr>
                      <th scope="col">{t('billing.invoice')}</th><th scope="col">{t('billing.period')}</th><th scope="col">{t('billing.total')}</th>
                      <th scope="col">{t('billing.due_on')}</th><th scope="col">{t('billing.status')}</th><th scope="col">{t('billing.acceptance')}</th>
                    </tr></thead>
                    <tbody>
                      {data.invoices.map((i) => (
                        <tr key={i.id}>
                          <td>{invoiceLabel(i)}</td>
                          <td>{formatDate(locale, i.period_start)} – {formatDate(locale, i.period_end)}</td>
                          <td>{money(i.total)}{i.balance.amount !== i.total.amount && i.status !== 'paid' && <div className="small text-secondary">{t('billing.pending_amount', { amount: money(i.balance) })}</div>}</td>
                          <td>{i.due_on ? formatDate(locale, i.due_on) : ''}</td>
                          <td><StatusBadge tone={invoiceTone(i.status)}>{t(`billing.st.${i.status}`)}</StatusBadge></td>
                          <td className="small">
                            {i.einvoice.status === 'accepted' && <>{t('billing.accepted_on', { date: formatDateTime(locale, i.einvoice.accepted_at) })}<div className="text-secondary">{i.einvoice.acceptance_hash}</div></>}
                            {i.einvoice.status === 'rejected' && <span className="text-danger">{i.einvoice.message}</span>}
                            {i.einvoice.status === 'pending' && t('billing.einvoice_pending')}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
            <section className="card">
              <div className="card-header"><h2>{t('billing.payments')}</h2></div>
              <div className="card-body">
                {data.payments.length === 0 ? <p className="mb-0 text-secondary">{t('billing.payments_empty')}</p> : (
                  <ul className="list-unstyled mb-0">
                    {data.payments.map((p) => (
                      <li key={p.id}>{formatDate(locale, p.paid_on)} · {money(p.amount)} · {t(`billing.method.${p.method}`)}</li>
                    ))}
                  </ul>
                )}
                <p className="small text-secondary mt-2 mb-0">{t('billing.rule', { days: data.dunning.reminder_days_before_due })}</p>
              </div>
            </section>
          </>
        )}
      </main>
    </>
  )
}
