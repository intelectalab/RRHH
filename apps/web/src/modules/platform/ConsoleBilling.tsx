'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { formErrors } from '@/lib/errors'
import { formatDate, formatDateTime, formatNumber, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { invoiceLabel, invoiceTone, type BillingInvoice, type BillingMoney } from '@/modules/billing/billing'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { useConsole, useConsoleTabs } from './ConsoleShell'
import { consoleApi, type ConsoleSubscription } from './consoleApi'

type PaymentForm = { amount: string; paid_on: string; method: string; reference: string; gateway_ref: string }

/**
 * SAS-11 · ADR 0010 · Consola → Cobros: genera el cargo del periodo con los precios guardados en la suscripción,
 * emite el comprobante electrónico (solo queda emitido con la constancia de aceptación; un rechazo lo deja
 * observado), registra pagos manuales o de la pasarela y anula borradores u observados con motivo.
 */
export function ConsoleBilling() {
  const { t, locale } = useT()
  const me = useConsole()
  const tabs = useConsoleTabs()
  const can = (p: string) => me.permissions.includes(p)
  const [status, setStatus] = useState('')
  const [invoices, setInvoices] = useState<BillingInvoice[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [subs, setSubs] = useState<ConsoleSubscription[]>([])
  const [subId, setSubId] = useState('')
  const [open, setOpen] = useState<BillingInvoice | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [reason, setReason] = useState('')
  const [pay, setPay] = useState<PaymentForm>({ amount: '', paid_on: localToday(), method: 'transfer', reference: '', gateway_ref: '' })
  const money = (m: BillingMoney) => `${m.currency} ${formatNumber(locale, m.amount)}`

  const load = useCallback(() => {
    setFailed(false)
    consoleApi.get<{ data: BillingInvoice[] }>(`/invoices?per_page=100${status ? `&status=${status}` : ''}`)
      .then((r) => setInvoices(r.data)).catch(() => setFailed(true))
  }, [status])
  useEffect(load, [load])
  useEffect(() => {
    consoleApi.get<{ data: ConsoleSubscription[] }>('/subscriptions?per_page=100').then((r) => setSubs(r.data)).catch(() => setSubs([]))
  }, [])

  async function act(run: () => Promise<{ data: BillingInvoice }>) {
    setError(null)
    try {
      const r = await run()
      setOpen(r.data)
      load()
    } catch (err) {
      const e = formErrors(err, t)
      setError(e.general ?? Object.values(e.fields)[0] ?? t('console.load_error'))
    }
  }

  function openInvoice(id: string) {
    setError(null)
    setReason('')
    consoleApi.get<{ data: BillingInvoice }>(`/invoices/${id}`).then((r) => {
      setOpen(r.data)
      setPay({ amount: r.data.balance.amount, paid_on: localToday(), method: 'transfer', reference: '', gateway_ref: '' })
    }).catch(() => setError(t('console.load_error')))
  }

  function submitPayment(e: FormEvent) {
    e.preventDefault()
    if (!open) return
    const body = pay.gateway_ref.trim()
      ? { gateway_ref: pay.gateway_ref.trim() }
      : { amount: { amount: pay.amount.trim(), currency: open.total.currency }, paid_on: pay.paid_on, method: pay.method, reference: pay.reference.trim() || null }
    void act(() => consoleApi.post(`/invoices/${open.id}/payments`, body))
  }

  return (
    <>
      <PageHeader title={t('console.billing.title')} sub={t('console.billing.sub')} tabs={tabs} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}
        <div className="fila-entre mb-3" style={{ flexWrap: 'wrap', gap: 8 }}>
          <div>
            <label className="form-label" htmlFor="bl-status">{t('console.billing.filter_status')}</label>
            <select id="bl-status" className="form-select" value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">{t('console.billing.all')}</option>
              {(['draft', 'observed', 'issued', 'overdue', 'paid', 'void'] as const).map((s) => <option key={s} value={s}>{t(`billing.st.${s}`)}</option>)}
            </select>
          </div>
          {can('platform.billing.manage') && (
            <div>
              <label className="form-label" htmlFor="bl-sub">{t('console.billing.subscription')}</label>
              <div className="d-flex" style={{ gap: 8 }}>
                <select id="bl-sub" className="form-select" value={subId} onChange={(e) => setSubId(e.target.value)}>
                  <option value="">{t('console.billing.choose_subscription')}</option>
                  {subs.map((s) => <option key={s.id} value={s.id}>{s.tenant?.name ?? s.tenant_id} · {s.plan?.name ?? ''}</option>)}
                </select>
                <button type="button" className="btn btn-primary" disabled={!subId} onClick={() => void act(() => consoleApi.post(`/subscriptions/${subId}/invoices`, {}))}>
                  <Icon name="plus" /> {t('console.billing.generate')}
                </button>
              </div>
            </div>
          )}
        </div>
        {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
        {invoices && invoices.length === 0 && <EmptyState title={t('console.billing.empty_title')} text={t('console.billing.empty')} />}
        {invoices && invoices.length > 0 && (
          <section className="card mb-3">
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr>
                  <th scope="col">{t('billing.invoice')}</th><th scope="col">{t('console.ops.tenant')}</th><th scope="col">{t('billing.period')}</th>
                  <th scope="col">{t('billing.total')}</th><th scope="col">{t('billing.status')}</th><th scope="col">{t('console.billing.einvoice')}</th>
                  <th scope="col"><span className="visually-hidden">{t('console.ops.actions')}</span></th>
                </tr></thead>
                <tbody>
                  {invoices.map((i) => (
                    <tr key={i.id}>
                      <td>{invoiceLabel(i)}</td>
                      <td>{i.tenant?.name}</td>
                      <td>{formatDate(locale, i.period_start)} – {formatDate(locale, i.period_end)}</td>
                      <td>{money(i.total)}</td>
                      <td><StatusBadge tone={invoiceTone(i.status)}>{t(`billing.st.${i.status}`)}</StatusBadge></td>
                      <td><Tag tone={i.einvoice.status === 'accepted' ? 'ok' : i.einvoice.status === 'rejected' ? 'er' : 'gr'}>{t(`console.billing.est.${i.einvoice.status}`)}</Tag></td>
                      <td className="text-end"><button type="button" className="btn btn-sec btn-sm" onClick={() => openInvoice(i.id)}>{t('console.billing.open')}</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
        {open && (
          <section className="card">
            <div className="card-body">
              <h2 className="h6">{t('console.billing.detail', { number: invoiceLabel(open), tenant: open.tenant?.name ?? '' })}</h2>
              <dl className="ficha mb-3">
                <dt>{t('billing.status')}</dt><dd><StatusBadge tone={invoiceTone(open.status)}>{t(`billing.st.${open.status}`)}</StatusBadge></dd>
                <dt>{t('console.billing.buyer')}</dt><dd>{open.buyer_name ?? ''} {open.buyer_tax_id ?? ''}</dd>
                <dt>{t('console.billing.subtotal')}</dt><dd>{money(open.subtotal)}</dd>
                <dt>{t('console.billing.tax')}</dt><dd>{money(open.tax)}{open.tax_rate ? ` (${formatNumber(locale, open.tax_rate)} %)` : ''}</dd>
                <dt>{t('billing.total')}</dt><dd>{money(open.total)}</dd>
                <dt>{t('billing.balance')}</dt><dd>{money(open.balance)}</dd>
                {open.due_on && <><dt>{t('billing.due_on')}</dt><dd>{formatDate(locale, open.due_on)}</dd></>}
                <dt>{t('console.billing.einvoice')}</dt>
                <dd>
                  {t(`console.billing.est.${open.einvoice.status}`)}
                  {open.einvoice.accepted_at && <> · {formatDateTime(locale, open.einvoice.accepted_at)} · {open.einvoice.acceptance_hash}</>}
                  {open.einvoice.message && open.einvoice.status === 'rejected' && <div className="text-danger small">{open.einvoice.message}</div>}
                </dd>
              </dl>
              {(open.lines ?? []).length > 0 && (
                <ul className="mb-3">
                  {(open.lines ?? []).map((l, n) => <li key={n}>{`${l.description} · ${formatNumber(locale, l.quantity)} × ${money(l.unit_price)} = ${money(l.amount)}`}</li>)}
                </ul>
              )}
              {can('platform.billing.manage') && (
                <div className="d-flex mb-3" style={{ gap: 8, flexWrap: 'wrap' }}>
                  {open.allowed_actions?.includes('issue') && open.einvoice.status !== 'pending' && (
                    <button type="button" className="btn btn-primary btn-sm" onClick={() => void act(() => consoleApi.post(`/invoices/${open.id}/issue`, {}))}>{t('console.billing.issue')}</button>
                  )}
                  {open.allowed_actions?.includes('void') && (
                    <>
                      <label className="visually-hidden" htmlFor="bl-reason">{t('console.billing.void_reason')}</label>
                      <input id="bl-reason" className="form-control form-control-sm" style={{ maxWidth: 260 }} placeholder={t('console.billing.void_reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
                      <button type="button" className="btn btn-sec btn-sm" onClick={() => void act(() => consoleApi.post(`/invoices/${open.id}/void`, { reason }))}>{t('console.billing.void')}</button>
                    </>
                  )}
                </div>
              )}
              {can('platform.billing.manage') && ['issued', 'overdue'].includes(open.status) && (
                <form onSubmit={submitPayment} className="mb-3">
                  <h3 className="h6">{t('console.billing.payment')}</h3>
                  <div className="d-flex" style={{ gap: 8, flexWrap: 'wrap' }}>
                    <div>
                      <label className="form-label" htmlFor="bl-amount">{t('console.billing.amount', { currency: open.total.currency })}</label>
                      <input id="bl-amount" className="form-control" inputMode="decimal" value={pay.amount} onChange={(e) => setPay({ ...pay, amount: e.target.value })} />
                    </div>
                    <div>
                      <label className="form-label" htmlFor="bl-date">{t('console.billing.paid_on')}</label>
                      <input id="bl-date" type="date" className="form-control" value={pay.paid_on} onChange={(e) => setPay({ ...pay, paid_on: e.target.value })} />
                    </div>
                    <div>
                      <label className="form-label" htmlFor="bl-method">{t('console.billing.method')}</label>
                      <select id="bl-method" className="form-select" value={pay.method} onChange={(e) => setPay({ ...pay, method: e.target.value })}>
                        {(['transfer', 'card', 'wallet', 'cash', 'other'] as const).map((m) => <option key={m} value={m}>{t(`billing.method.${m}`)}</option>)}
                      </select>
                    </div>
                    <div>
                      <label className="form-label" htmlFor="bl-ref">{t('console.billing.reference')}</label>
                      <input id="bl-ref" className="form-control" value={pay.reference} onChange={(e) => setPay({ ...pay, reference: e.target.value })} />
                    </div>
                    <div>
                      <label className="form-label" htmlFor="bl-gw">{t('console.billing.gateway_ref')}</label>
                      <input id="bl-gw" className="form-control" value={pay.gateway_ref} onChange={(e) => setPay({ ...pay, gateway_ref: e.target.value })} />
                      <div className="form-text">{t('console.billing.gateway_help')}</div>
                    </div>
                  </div>
                  <button type="submit" className="btn btn-primary btn-sm mt-2">{t('console.billing.record_payment')}</button>
                </form>
              )}
              {(open.payments ?? []).length > 0 && (
                <ul className="list-unstyled mb-0 small">
                  {(open.payments ?? []).map((p) => (
                    <li key={p.id}>{formatDate(locale, p.paid_on)} · {money(p.amount)} · {t(`billing.method.${p.method}`)}{p.source === 'gateway' ? ` · ${t('console.billing.from_gateway')}` : ''}{p.reference ? ` · ${p.reference}` : ''}</li>
                  ))}
                </ul>
              )}
            </div>
          </section>
        )}
      </main>
    </>
  )
}
