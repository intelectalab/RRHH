'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { AGREEMENT_TONE, type AgreementKind, type VacationAgreement } from './vacationPlanning'

type EmploymentOption = { id: string; person_name?: string | null; employee_code: string }

/**
 * Tiempo → Acuerdos de vacaciones (VAC-6): fraccionamiento, adelanto y venta de días. RRHH crea el acuerdo, lo
 * emite en PDF al legajo y registra el ejemplar firmado; la venta firmada descuenta el saldo y deja el concepto de
 * planilla. Los límites (política y norma) los valida el servidor; el colaborador ve sus acuerdos.
 */
export function VacationAgreementsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const canManage = can('time.vacation_balances.adjust')
  const [rows, setRows] = useState<VacationAgreement[]>([])
  const [employments, setEmployments] = useState<EmploymentOption[]>([])
  const [form, setForm] = useState<{ employment_id: string; kind: AgreementKind; days: string; effective_on: string }>({ employment_id: '', kind: 'sale', days: '', effective_on: new Date().toISOString().slice(0, 10) })
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    const r = await api.get<{ data: VacationAgreement[] }>('/vacation-agreements?per_page=100')
    setRows(r.data)
  }, [])

  useEffect(() => {
    void load().catch((e) => setError(e instanceof Error ? e.message : t('vag.error')))
    if (canManage) {
      void api.get<{ data: EmploymentOption[] }>('/employments?filter[status]=active&sort=person_name&per_page=100').then((r) => setEmployments(r.data)).catch(() => setEmployments([]))
    }
  }, [canManage, load, t])

  const run = async (fn: () => Promise<unknown>, done: string) => {
    try {
      await fn()
      toast(t(done))
      await load()
    } catch (e) {
      toast(e instanceof Error ? e.message : t('vag.error'), 'error')
    }
  }

  const create = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    void run(() => api.post('/vacation-agreements', { ...form, days: form.kind === 'fractionation' ? null : form.days }), 'vag.created')
  }

  const sign = (a: VacationAgreement, file: File | undefined) => {
    if (!file) return
    const data = new FormData()
    data.append('file', file)
    data.append('signed_on', new Date().toISOString().slice(0, 10))
    void run(() => api.postForm(`/vacation-agreements/${a.id}/register-signature`, data), 'vag.signed')
  }

  const cancel = (a: VacationAgreement) => {
    const reason = window.prompt(t('vag.cancel_reason'))
    if (!reason) return
    void run(() => api.post(`/vacation-agreements/${a.id}/cancel`, { reason }), 'vag.cancelled')
  }

  return (
    <>
      <PageHeader title={t('vag.title')} sub={t('vag.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}
        {canManage && (
          <form className="card" onSubmit={create} style={{ padding: 16, display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <div>
              <label className="form-label" htmlFor="vag-emp">{t('vag.employee')}</label>
              <select id="vag-emp" className="form-select" required value={form.employment_id} onChange={(e) => setForm((f) => ({ ...f, employment_id: e.target.value }))}>
                <option value="">{t('vag.choose')}</option>
                {employments.map((m) => <option key={m.id} value={m.id}>{m.person_name ?? m.employee_code}</option>)}
              </select>
            </div>
            <div>
              <label className="form-label" htmlFor="vag-kind">{t('vag.kind')}</label>
              <select id="vag-kind" className="form-select" value={form.kind} onChange={(e) => setForm((f) => ({ ...f, kind: e.target.value as AgreementKind }))}>
                {(['sale', 'advance', 'fractionation'] as const).map((k) => <option key={k} value={k}>{t(`vag.kind_${k}`)}</option>)}
              </select>
            </div>
            {form.kind !== 'fractionation' && (
              <div>
                <label className="form-label" htmlFor="vag-days">{t('vag.days')}</label>
                <input id="vag-days" className="form-control" inputMode="decimal" required value={form.days} onChange={(e) => setForm((f) => ({ ...f, days: e.target.value }))} />
              </div>
            )}
            <div>
              <label className="form-label" htmlFor="vag-date">{t('vag.effective_on')}</label>
              <input id="vag-date" className="form-control" type="date" required value={form.effective_on} onChange={(e) => setForm((f) => ({ ...f, effective_on: e.target.value }))} />
            </div>
            <button type="submit" className="btn btn-primary">{t('vag.create')}</button>
          </form>
        )}

        {rows.length === 0 ? <EmptyState title={t('vag.empty_title')} text={t('vag.empty_text')} /> : (
          <section className="card">
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('vag.col_employee')}</th><th>{t('vag.col_kind')}</th><th>{t('vag.col_days')}</th><th>{t('vag.col_date')}</th><th>{t('vag.col_status')}</th><th><span className="sr-only">{t('vag.col_actions')}</span></th></tr></thead>
                <tbody>
                  {rows.map((a) => (
                    <tr key={a.id}>
                      <td>{a.person_name ?? '—'}</td>
                      <td>{t(`vag.kind_${a.kind}`)}</td>
                      <td>{a.days ?? '—'}</td>
                      <td className="nowrap">{formatDate(locale, a.effective_on)}</td>
                      <td><span className={`tag ${AGREEMENT_TONE[a.status]}`}>{t(`vag.status_${a.status}`)}</span></td>
                      <td className="nowrap">
                        {canManage && a.allowed_actions.includes('issue') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(() => api.post(`/vacation-agreements/${a.id}/issue`), 'vag.issued')}>{t('vag.issue')}</button>}{' '}
                        {canManage && a.allowed_actions.includes('register-signature') && (
                          <label className="btn btn-sec btn-sm">
                            {t('vag.sign')}
                            <input type="file" accept="application/pdf,image/png,image/jpeg" className="sr-only" onChange={(e) => sign(a, e.target.files?.[0])} />
                          </label>
                        )}{' '}
                        {a.document_id && <a className="btn btn-sec btn-sm" href={`/api/v1/vacation-agreements/${a.id}/document`}>{t('vag.document')}</a>}{' '}
                        {canManage && a.allowed_actions.includes('cancel') && <button type="button" className="btn btn-sec btn-sm" onClick={() => cancel(a)}>{t('vag.cancel')}</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}
