'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDateTime, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { PAYSLIP_TONE, pendingAck, saveBlob, type Money, type Payslip, type PayslipExplanation } from '@/modules/payroll/payslips'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SkeletonBlock } from '@/ui/components/Skeleton'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

/**
 * Portal → Mis boletas (BOL-2, BOL-3, BOL-5): historial de boletas de todas mis relaciones, detalle con sus
 * líneas, descarga del PDF (abrir y descargar quedan como constancia de entrega), acuse cuando la empresa lo
 * pide y «¿Por qué cambió mi neto?», que explica el servidor desde la traza del cálculo.
 */
export function MyPayslipsScreen() {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<Payslip[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [open, setOpen] = useState<Payslip | null>(null)
  const [why, setWhy] = useState<PayslipExplanation | null>(null)

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: Payslip[] }>('/me/payslips')).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [])

  useEffect(() => { void load() }, [load])

  const show = async (p: Payslip) => {
    try {
      setWhy(null)
      setOpen((await api.get<{ data: Payslip }>(`/me/payslips/${p.id}`)).data)
      await load()
    } catch (e) {
      toast(e instanceof Error ? e.message : t('mybol.error'), 'error')
    }
  }

  const download = async (p: Payslip) => {
    try {
      const { blob, filename } = await api.download(`/me/payslips/${p.id}/file`)
      saveBlob(blob, filename)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('mybol.error'), 'error')
    }
  }

  const acknowledge = async (p: Payslip) => {
    try {
      setOpen((await api.post<{ data: Payslip }>(`/me/payslips/${p.id}/acknowledge`, {})).data)
      toast(t('mybol.acknowledged'))
      await load()
    } catch (e) {
      toast(e instanceof Error ? e.message : t('mybol.error'), 'error')
    }
  }

  const explain = async (p: Payslip) => {
    try {
      setWhy((await api.get<{ data: PayslipExplanation }>(`/me/payslips/${p.id}/explanation`)).data)
    } catch (e) {
      toast(e instanceof Error ? e.message : t('mybol.error'), 'error')
    }
  }

  const money = (m?: Money | null) => (m ? `${m.currency} ${formatNumber(locale, m.amount)}` : '—')

  return (
    <>
      <PageHeader title={t('mybol.title')} sub={t('mybol.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {failed && <ErrorState text={t('mybol.error')} retryLabel={t('mybol.retry')} onRetry={() => void load()} />}
        {rows === null && !failed && <SkeletonBlock />}
        {rows !== null && rows.length === 0 && <EmptyState title={t('mybol.empty_title')} text={t('mybol.empty_text')} />}
        {rows !== null && rows.some(pendingAck) && <Notice tone="warn">{t('mybol.pending_notice')}</Notice>}
        {rows !== null && rows.length > 0 && (
          <section className="card">
            <ul className="lista">
              {rows.map((p) => (
                <li key={p.id} style={{ display: 'flex', gap: 12, justifyContent: 'space-between', alignItems: 'center', padding: '12px 16px', flexWrap: 'wrap' }}>
                  <div>
                    <strong>{p.period_label}</strong>
                    <div className="sub2">{t('mybol.net')}: {money(p.net)} · <span className={`tag ${PAYSLIP_TONE[p.status]}`}>{t(`bol.status_${p.status}`)}</span></div>
                  </div>
                  <div className="nowrap">
                    <button type="button" className="btn btn-sec btn-sm" onClick={() => void show(p)}>{t('mybol.view')}</button>{' '}
                    <button type="button" className="btn btn-ter btn-sm" onClick={() => void download(p)}>{t('mybol.download')}</button>
                  </div>
                </li>
              ))}
            </ul>
          </section>
        )}

        {open && (
          <section className="card">
            <div className="card-header"><h2>{open.period_label} · {open.number}</h2></div>
            <div style={{ padding: '0 16px 16px' }}>
              <div className="tabla-wrap">
                <table className="table">
                  <tbody>
                    {(open.lines ?? []).filter((l) => l.section !== 'employer').map((l) => (
                      <tr key={l.concept_code}><td>{l.concept_name}</td><td className="sub2">{t(`mybol.section_${l.section}`)}</td><td className="nowrap">{money(l.amount)}</td></tr>
                    ))}
                    <tr><td><strong>{t('mybol.net')}</strong></td><td /><td className="nowrap"><strong>{money(open.net)}</strong></td></tr>
                  </tbody>
                </table>
              </div>
              {open.acknowledged_at && <p className="sub2">{t('mybol.ack_on', { date: formatDateTime(locale, open.acknowledged_at) })}</p>}
              <p>
                <button type="button" className="btn btn-sec btn-sm" onClick={() => void explain(open)}>{t('mybol.why')}</button>{' '}
                {open.allowed_actions?.includes('acknowledge') && <button type="button" className="btn btn-primary btn-sm" onClick={() => void acknowledge(open)}>{t('mybol.acknowledge')}</button>}
              </p>
              {why && (
                <>
                  <p>{why.summary}</p>
                  {why.items.length > 0 && <ul>{why.items.map((i) => <li key={i.concept_code}>{i.text}</li>)}</ul>}
                </>
              )}
            </div>
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}
