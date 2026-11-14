'use client'

import { useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { DataTable, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { SUBSIDY_STATUSES, SUBSIDY_TONE, type Money, type SubsidyCase } from './absences'
import { subsidyActionBody, subsidyActionFields, subsidyActions, subsidyCurrency, type SubsidyAction } from './subsidies'

type Toast = (text: string, kind?: 'ok' | 'error') => void

function MoneyText({ value }: { value: Money | null }) {
  const { locale } = useT()
  if (!value) return <>{'—'}</>
  return <>{value.currency ? `${value.currency} ` : ''}{formatNumber(locale, value.amount)}</>
}

/**
 * Tiempo → Subsidios (AUS-3). Expedientes de reembolso de los descansos que pasaron a subsidio (desde el umbral
 * a cargo del empleador o maternidad completa). Presentar, observar, aprobar, rechazar y reembolsar son acciones
 * que valida el servidor; aquí no se calcula nada (regla 11).
 */
export function SubsidiesScreen() {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const [reloadKey, setReloadKey] = useState(0)
  const [detail, setDetail] = useState<string | null>(null)

  const columns: Array<TableColumn<SubsidyCase>> = [
    { key: 'person', label: t('sub.employee'), hideable: false, width: 220, render: (r) => <><b>{r.person_name ?? '—'}</b><span className="sub2">{r.employee_code ?? ''}</span></> },
    { key: 'kind', label: t('sub.kind'), render: (r) => t(`sub.kind_${r.kind}`) },
    { key: 'dates', label: t('sub.col_dates'), render: (r) => r.starts_on && r.ends_on ? t('abs.range', { from: formatDate(locale, r.starts_on), to: formatDate(locale, r.ends_on) }) : '—' },
    { key: 'days', label: t('sub.days'), render: (r) => t('abs.days_n', { n: formatNumber(locale, r.subsidized_days) }) },
    { key: 'due', label: t('sub.filing_due_on'), render: (r) => r.filing_due_on ? <>{formatDate(locale, r.filing_due_on)}{r.is_overdue && <span className="st er"> {t('sub.overdue')}</span>}</> : t('sub.due_unknown') },
    { key: 'status', label: t('sub.col_status'), render: (r) => <span className={`st ${SUBSIDY_TONE[r.status]}`}>{t(`sub.status_${r.status}`)}</span> },
  ]
  const filters: TableFilter[] = [
    { key: 'status', label: t('sub.filter_status'), options: SUBSIDY_STATUSES.map((s) => ({ value: s, label: t(`sub.status_${s}`) })) },
  ]

  return (
    <>
      <PageHeader title={t('sub.title')} sub={t('sub.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <DataTable<SubsidyCase>
          resource="absence-subsidies"
          endpoint="/absence-subsidies"
          defaultSort="filing_due_on"
          searchLabel={t('sub.search')}
          reloadKey={reloadKey}
          rowKey={(r) => r.id}
          columns={columns}
          filters={filters}
          empty={<EmptyState title={t('sub.empty_title')} text={t('sub.empty_text')} />}
          rowActions={(r) => <button type="button" className="btn btn-ter btn-sm" onClick={() => setDetail(r.id)}>{t('sub.detail')}</button>}
        />
      </main>
      {detail && <SubsidyDetailPanel id={detail} onClose={() => setDetail(null)} onChanged={() => setReloadKey((k) => k + 1)} toast={toast} />}
      {toastNode}
    </>
  )
}

function SubsidyDetailPanel({ id, onClose, onChanged, toast }: { id: string; onClose: () => void; onChanged: () => void; toast: Toast }) {
  const { t, locale } = useT()
  const [item, setItem] = useState<SubsidyCase | null>(null)
  const [failed, setFailed] = useState(false)
  const [action, setAction] = useState<SubsidyAction | null>(null)
  const [values, setValues] = useState({ date: '', reference: '', amount: '', reason: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    setFailed(false)
    void api.get<{ data: SubsidyCase }>(`/absence-subsidies/${id}`).then((r) => setItem(r.data)).catch(() => setFailed(true))
  }, [id])

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    if (!item || !action) return
    setBusy(true)
    setErrors({})
    try {
      const res = await api.post<{ data: SubsidyCase }>(`/absence-subsidies/${id}/${action}`, subsidyActionBody(action, values, subsidyCurrency(item)))
      setItem(res.data)
      setAction(null)
      setValues({ date: '', reference: '', amount: '', reason: '' })
      toast(t(`sub.done_${action}`))
      onChanged()
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      if (general) toast(general, 'error')
    } finally {
      setBusy(false)
    }
  }

  const actions = item ? subsidyActions(item) : []
  const spec = action ? subsidyActionFields(action) : null
  const fieldError = (...keys: string[]) => keys.map((k) => errors[k]).find(Boolean)
  const amountError = fieldError('amount_requested', 'amount_approved', 'amount_reimbursed')
  const footer = item && actions.length > 0 ? (
    action
      ? <>
          <button type="button" className="btn btn-sec" onClick={() => { setAction(null); setErrors({}) }}>{t('sub.back')}</button>
          <button type="submit" form="subsidio" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t(`sub.action_${action}`)}</button>
        </>
      : <>{actions.map((a) => <button key={a} type="button" className={a === actions[0] ? 'btn btn-primary' : 'btn btn-sec'} onClick={() => setAction(a)}>{t(`sub.action_${a}`)}</button>)}</>
  ) : undefined

  return (
    <SidePanel title={item?.person_name ?? t('sub.detail')} onClose={onClose} closeLabel={t('common.close')} footer={footer}>
      {failed && <p role="alert">{t('sub.load_error')}</p>}
      {!item && !failed && <span className="esq" style={{ width: 240 }} />}
      {item && !action && (
        <>
          {item.is_overdue && <Notice tone="error">{t('sub.overdue_notice')}</Notice>}
          {item.status === 'observed' && item.observation && <Notice tone="warn">{t('sub.observed_reason', { reason: item.observation })}</Notice>}
          {item.status === 'rejected' && item.decision_reason && <Notice tone="error">{t('sub.rejected_reason', { reason: item.decision_reason })}</Notice>}
          <dl className="ficha mb-3">
            <dt>{t('sub.col_status')}</dt><dd><span className={`st ${SUBSIDY_TONE[item.status]}`}>{t(`sub.status_${item.status}`)}</span></dd>
            <dt>{t('sub.kind')}</dt><dd>{t(`sub.kind_${item.kind}`)}</dd>
            <dt>{t('sub.days')}</dt><dd>{t('abs.days_n', { n: formatNumber(locale, item.subsidized_days) })}{item.starts_on && item.ends_on && <span className="sub2"> · {t('abs.range', { from: formatDate(locale, item.starts_on), to: formatDate(locale, item.ends_on) })}</span>}</dd>
            {item.absence_starts_on && item.absence_ends_on && <><dt>{t('sub.absence')}</dt><dd>{t('abs.range', { from: formatDate(locale, item.absence_starts_on), to: formatDate(locale, item.absence_ends_on) })}</dd></>}
            <dt>{t('sub.filing_due_on')}</dt><dd>{item.filing_due_on ? formatDate(locale, item.filing_due_on) : t('sub.due_unknown')}</dd>
            {item.filed_on && <><dt>{t('sub.filed_on')}</dt><dd>{formatDate(locale, item.filed_on)}{item.filing_reference && <span className="sub2"> · {item.filing_reference}</span>}</dd></>}
            {item.amount_requested && <><dt>{t('sub.amount_requested')}</dt><dd><MoneyText value={item.amount_requested} /></dd></>}
            {item.amount_approved && <><dt>{t('sub.amount_approved')}</dt><dd><MoneyText value={item.amount_approved} /></dd></>}
            {item.amount_reimbursed && <><dt>{t('sub.amount_reimbursed')}</dt><dd><MoneyText value={item.amount_reimbursed} />{item.reimbursed_on && <span className="sub2"> · {formatDate(locale, item.reimbursed_on)}</span>}</dd></>}
          </dl>
        </>
      )}
      {item && action && spec && (
        <form id="subsidio" onSubmit={submit} noValidate>
          <h3 className="h6">{t(`sub.action_${action}`)}</h3>
          {spec.fields.includes('date') && (
            <div className="mb-3">
              <label className="form-label" htmlFor="sb-date">{t(action === 'reimburse' ? 'sub.reimbursed_on' : 'sub.filed_on')}{spec.required.includes('date') ? ' *' : ''}</label>
              <input id="sb-date" type="date" className={`form-control${fieldError('filed_on', 'reimbursed_on') ? ' is-invalid' : ''}`} value={values.date} onChange={(e) => setValues((v) => ({ ...v, date: e.target.value }))} />
              {fieldError('filed_on', 'reimbursed_on') && <div className="invalid-feedback" role="alert">{fieldError('filed_on', 'reimbursed_on')}</div>}
            </div>
          )}
          {spec.fields.includes('reference') && (
            <div className="mb-3">
              <label className="form-label" htmlFor="sb-ref">{t('sub.filing_reference')}</label>
              <input id="sb-ref" className="form-control" value={values.reference} onChange={(e) => setValues((v) => ({ ...v, reference: e.target.value }))} />
            </div>
          )}
          {spec.fields.includes('amount') && (
            <div className="mb-3">
              <label className="form-label" htmlFor="sb-amount">{t(`sub.amount_${action}`)}{spec.required.includes('amount') ? ' *' : ''}</label>
              <input id="sb-amount" inputMode="decimal" className={`form-control${amountError ? ' is-invalid' : ''}`} value={values.amount} onChange={(e) => setValues((v) => ({ ...v, amount: e.target.value }))} />
              {amountError && <div className="invalid-feedback" role="alert">{amountError}</div>}
            </div>
          )}
          {spec.fields.includes('reason') && (
            <div className="mb-3">
              <label className="form-label" htmlFor="sb-reason">{t('sub.reason')} *</label>
              <textarea id="sb-reason" rows={3} className={`form-control${errors.reason ? ' is-invalid' : ''}`} value={values.reason} onChange={(e) => setValues((v) => ({ ...v, reason: e.target.value }))} />
              {errors.reason && <div className="invalid-feedback" role="alert">{errors.reason}</div>}
            </div>
          )}
        </form>
      )}
    </SidePanel>
  )
}
