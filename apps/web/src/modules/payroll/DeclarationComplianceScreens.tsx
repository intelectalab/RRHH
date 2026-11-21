'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Money = { amount: string; currency: string }
const errorText = (e: unknown, fallback: string) => (e instanceof Error ? e.message : fallback)

type LogRow = {
  legal_entity_id: string; legal_entity_name: string; period_code: string; kind: string; expected: boolean; missing: boolean
  log_status: string; filing_id: string | null; due_on: string | null; overdue: boolean; responsible_name: string | null
  external_reference: string | null; paid_at: string | null; payment_reference: string | null
}

/**
 * Nómina → Bitácora de declaraciones (DEC-7). Por empresa y periodo: lo que corresponde declarar, lo que falta y el
 * estado (generada, presentada, pagada, rectificada) con su responsable. Registrar el pago adjunta la constancia; el
 * estado de la declaración cambia en Declaraciones con sus acciones.
 */
export function DeclarationLogScreen() {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<LogRow[] | null>(null)
  const [summary, setSummary] = useState<Record<string, number>>({})
  const [error, setError] = useState<string | null>(null)
  const [paying, setPaying] = useState<LogRow | null>(null)
  const [reference, setReference] = useState('')

  const load = useCallback(async () => {
    setError(null)
    try {
      const r = await api.get<{ data: LogRow[]; meta: { summary: Record<string, number> } }>('/declaration-log')
      setRows(r.data)
      setSummary(r.meta.summary)
    } catch (e) {
      setRows([])
      setError(errorText(e, t('dcomp.error')))
    }
  }, [t])

  useEffect(() => { void load() }, [load])

  const pay = async () => {
    if (paying?.filing_id == null || reference.trim() === '') return
    try {
      await api.post(`/declaration-files/${paying.filing_id}/register-payment`, { payment_reference: reference.trim() })
      toast(t('dcomp.log_paid_ok'))
      setPaying(null)
      setReference('')
      void load()
    } catch (e) {
      toast(errorText(e, t('dcomp.error')), 'error')
    }
  }

  return (
    <>
      <PageHeader title={t('dcomp.log_title')} sub={t('dcomp.log_sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="card" style={{ padding: 16, display: 'flex', gap: 16, flexWrap: 'wrap' }}>
          {['missing', 'generated', 'submitted', 'paid', 'rectified'].map((k) => (
            <div key={k}><div className="sub2">{t(`dcomp.log_status_${k}`)}</div><strong>{summary[k] ?? 0}</strong></div>
          ))}
        </div>
        {error && <Notice tone="error">{error}</Notice>}
        {rows !== null && rows.length === 0 && !error && <EmptyState title={t('dcomp.log_empty_title')} text={t('dcomp.log_empty_text')} />}
        {rows !== null && rows.length > 0 && (
          <section className="card">
            <table className="table">
              <thead><tr><th>{t('dcomp.col_company')}</th><th>{t('dcomp.col_period')}</th><th>{t('dcomp.col_kind')}</th><th>{t('dcomp.col_status')}</th><th>{t('dcomp.col_due')}</th><th>{t('dcomp.col_responsible')}</th><th /></tr></thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={`${r.legal_entity_id}-${r.period_code}-${r.kind}`}>
                    <td>{r.legal_entity_name}</td>
                    <td>{r.period_code}</td>
                    <td>{t(`dcomp.kind_${r.kind}`)}</td>
                    <td>{t(`dcomp.log_status_${r.log_status}`)}{r.overdue ? ` · ${t('dcomp.overdue')}` : ''}{r.payment_reference ? <div className="sub2">{r.payment_reference}</div> : null}</td>
                    <td>{r.due_on ? formatDate(locale, r.due_on) : '—'}</td>
                    <td>{r.responsible_name ?? '—'}</td>
                    <td>{r.log_status === 'submitted' && <button type="button" className="btn btn-ter" onClick={() => setPaying(r)}>{t('dcomp.log_pay')}</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        )}
        {paying && (
          <section className="card" style={{ padding: 16 }}>
            <h2>{t('dcomp.log_pay_of', { kind: t(`dcomp.kind_${paying.kind}`), period: paying.period_code })}</h2>
            <label className="form-label" htmlFor="dcomp-ref">{t('dcomp.log_payment_reference')}</label>
            <input id="dcomp-ref" className="form-control" value={reference} onChange={(e) => setReference(e.target.value)} />
            <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
              <button type="button" className="btn btn-pri" onClick={() => void pay()} disabled={reference.trim() === ''}>{t('dcomp.save')}</button>
              <button type="button" className="btn btn-ter" onClick={() => setPaying(null)}>{t('dcomp.cancel')}</button>
            </div>
          </section>
        )}
        {toastNode}
      </main>
    </>
  )
}

type Check = { code: string; field: string; screen: string; severity: string; blocking: boolean; message: string }
type Profile = { id: string; valid_from: string; valid_to: string | null; completeness: string; missing_fields: string[]; worker_type_code: string; labor_regime_code: string; occupation_code: string }

/**
 * Nómina → Registro laboral (DEC-8). Ficha del T-Registro con vigencia de una relación laboral y la validación previa
 * al alta: cada faltante enlaza a la pantalla donde se corrige. Guardar un cambio crea una fila nueva.
 */
export function RegistryProfileScreen({ employmentId }: { employmentId: string | null }) {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const [employment, setEmployment] = useState(employmentId ?? '')
  const [profiles, setProfiles] = useState<Profile[] | null>(null)
  const [checks, setChecks] = useState<{ ready: boolean; checks: Check[] } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const valid = /^[0-9a-f-]{36}$/i.test(employment)

  const load = useCallback(async () => {
    if (!valid) return
    setError(null)
    try {
      setProfiles((await api.get<{ data: Profile[] }>(`/employments/${employment}/registry-profiles`)).data)
    } catch (e) {
      setProfiles([])
      setError(errorText(e, t('dcomp.error')))
    }
  }, [employment, valid, t])

  useEffect(() => { void load() }, [load])

  const validate = async () => {
    const current = profiles?.[0]
    try {
      if (current) {
        const r = await api.post<{ data: { validation: { ready: boolean; checks: Check[] } } }>(`/employments/${employment}/registry-profiles/${current.id}/validate`)
        setChecks(r.data.validation)
        void load()
      } else {
        setChecks((await api.post<{ data: { ready: boolean; checks: Check[] } }>(`/employments/${employment}/registry-validation`)).data)
      }
    } catch (e) {
      toast(errorText(e, t('dcomp.error')), 'error')
    }
  }

  return (
    <>
      <PageHeader title={t('dcomp.rp_title')} sub={t('dcomp.rp_sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="card" style={{ padding: 16, display: 'flex', gap: 12, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div>
            <label className="form-label" htmlFor="dcomp-emp">{t('dcomp.rp_employment')}</label>
            <input id="dcomp-emp" className="form-control" value={employment} onChange={(e) => { setEmployment(e.target.value.trim()); setChecks(null) }} />
          </div>
          <button type="button" className="btn btn-pri" disabled={!valid} onClick={() => void validate()}>{t('dcomp.rp_validate')}</button>
        </div>
        {error && <Notice tone="error">{error}</Notice>}
        {checks && (
          <Notice tone={checks.ready ? 'ok' : 'warn'} title={checks.ready ? t('dcomp.rp_ready') : t('dcomp.rp_blocked')}>
            <ul>
              {checks.checks.map((c) => (
                <li key={`${c.code}-${c.field}`}><strong>{c.code}</strong> {c.message} · <a href={c.screen}>{t('dcomp.rp_fix')}</a>{c.blocking ? '' : ` (${t(`dcomp.severity_${c.severity}`)})`}</li>
              ))}
            </ul>
          </Notice>
        )}
        {profiles !== null && profiles.length === 0 && !error && <EmptyState title={t('dcomp.rp_empty_title')} text={t('dcomp.rp_empty_text')} />}
        {profiles !== null && profiles.length > 0 && (
          <section className="card">
            <table className="table">
              <thead><tr><th>{t('dcomp.col_valid_from')}</th><th>{t('dcomp.col_valid_to')}</th><th>{t('dcomp.col_worker_type')}</th><th>{t('dcomp.col_regime')}</th><th>{t('dcomp.col_status')}</th></tr></thead>
              <tbody>
                {profiles.map((p) => (
                  <tr key={p.id}>
                    <td>{formatDate(locale, p.valid_from)}</td><td>{p.valid_to ? formatDate(locale, p.valid_to) : '—'}</td>
                    <td>{p.worker_type_code}</td><td>{p.labor_regime_code}</td>
                    <td>{t(`dcomp.completeness_${p.completeness}`)}{p.missing_fields.length > 0 ? <div className="sub2">{p.missing_fields.join(', ')}</div> : null}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        )}
        {toastNode}
      </main>
    </>
  )
}

type Refund = {
  id: string; employment_id: string; fiscal_year: number; origin: string; status: string; amount?: Money; balance?: Money
  authority_refund_reference: string | null; allowed_actions: string[]
}

/**
 * Nómina → Devoluciones de quinta (DEC-11 · ME-49). Excesos de retención a devolver: quien los calcula no los
 * aprueba (lo valida el servidor), la compensación puede ser parcial y el saldo se arrastra o se solicita a la
 * autoridad.
 */
export function FifthRefundsScreen() {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<Refund[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const money = (m?: Money) => (m ? `${m.currency} ${formatNumber(locale, m.amount)}` : '—')

  const load = useCallback(async () => {
    setError(null)
    try {
      setRows((await api.get<{ data: Refund[] }>('/fifth-category-refunds')).data)
    } catch (e) {
      setRows([])
      setError(errorText(e, t('dcomp.error')))
    }
  }, [t])

  useEffect(() => { void load() }, [load])

  const act = async (r: Refund, action: string) => {
    const body: Record<string, string> = {}
    if (action === 'compensate') {
      const amount = window.prompt(t('dcomp.fr_prompt_amount'), r.balance?.amount ?? '')
      const period = window.prompt(t('dcomp.fr_prompt_period'))
      if (!amount || !period) return
      body.amount = amount
      body.compensation_period = period
    }
    if (action === 'request-authority-refund') {
      const reference = window.prompt(t('dcomp.fr_prompt_reference'))
      if (!reference) return
      body.authority_refund_reference = reference
    }
    try {
      await api.post(`/fifth-category-refunds/${r.id}/${action}`, body)
      toast(t('dcomp.saved'))
      void load()
    } catch (e) {
      toast(errorText(e, t('dcomp.error')), 'error')
    }
  }

  return (
    <>
      <PageHeader title={t('dcomp.fr_title')} sub={t('dcomp.fr_sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {error && <Notice tone="error">{error}</Notice>}
        {rows !== null && rows.length === 0 && !error && <EmptyState title={t('dcomp.fr_empty_title')} text={t('dcomp.fr_empty_text')} />}
        {rows !== null && rows.length > 0 && (
          <section className="card">
            <table className="table">
              <thead><tr><th>{t('dcomp.col_year')}</th><th>{t('dcomp.col_origin')}</th><th>{t('dcomp.col_amount')}</th><th>{t('dcomp.col_balance')}</th><th>{t('dcomp.col_status')}</th><th /></tr></thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.id}>
                    <td>{r.fiscal_year}</td><td>{t(`dcomp.origin_${r.origin}`)}</td><td>{money(r.amount)}</td><td>{money(r.balance)}</td>
                    <td>{t(`dcomp.fr_status_${r.status}`)}{r.authority_refund_reference ? <div className="sub2">{r.authority_refund_reference}</div> : null}</td>
                    <td style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                      {r.allowed_actions.map((a) => <button key={a} type="button" className="btn btn-ter" onClick={() => void act(r, a)}>{t(`dcomp.fr_action_${a.replace(/-/g, '_')}`)}</button>)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        )}
        {toastNode}
      </main>
    </>
  )
}

type Declaration = { id: string; fiscal_year: number; employer_name: string | null; gross_income?: Money; tax_withheld?: Money; status: string; applies_from_month: number | null }

/**
 * Portal → Rentas de otros empleadores (DEC-9). El colaborador presenta su declaración jurada; RRHH la valida y la
 * proyección de quinta la usa desde el mes de validación. Sin declaración, se avisa que se proyecta solo con lo
 * pagado por esta empresa.
 */
export function MyExternalIncomeScreen() {
  const { t, locale } = useT()
  const { toast, node: toastNode } = useToast()
  const year = new Date().getFullYear()
  const [rows, setRows] = useState<Declaration[] | null>(null)
  const [warning, setWarning] = useState<string | null>(null)
  const [form, setForm] = useState({ employer_tax_id: '', employer_name: '', gross_income: '', tax_withheld: '', kind: 'previous_employer' })
  const money = (m?: Money) => (m ? `${m.currency} ${formatNumber(locale, m.amount)}` : '—')

  const load = useCallback(async () => {
    try {
      const r = await api.get<{ data: Declaration[]; meta: { warning: string | null } }>(`/me/external-income-declarations?fiscal_year=${year}`)
      setRows(r.data)
      setWarning(r.meta.warning)
    } catch (e) {
      setRows([])
      toast(errorText(e, t('dcomp.error')), 'error')
    }
  }, [year, t, toast])

  useEffect(() => { void load() }, [load])

  const submit = async () => {
    try {
      await api.post('/me/external-income-declarations', { ...form, fiscal_year: year })
      toast(t('dcomp.ei_sent'))
      setForm({ employer_tax_id: '', employer_name: '', gross_income: '', tax_withheld: '', kind: 'previous_employer' })
      void load()
    } catch (e) {
      toast(errorText(e, t('dcomp.error')), 'error')
    }
  }
  const field = (key: 'employer_tax_id' | 'employer_name' | 'gross_income' | 'tax_withheld') => (
    <div>
      <label className="form-label" htmlFor={`dcomp-${key}`}>{t(`dcomp.ei_${key}`)}</label>
      <input id={`dcomp-${key}`} className="form-control" value={form[key]} inputMode={key === 'employer_name' ? 'text' : 'decimal'} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />
    </div>
  )

  return (
    <>
      <PageHeader title={t('dcomp.ei_title')} sub={t('dcomp.ei_sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {warning === 'no_external_income_declaration' && <Notice tone="warn">{t('dcomp.ei_warning')}</Notice>}
        <section className="card" style={{ padding: 16, display: 'grid', gap: 12 }}>
          <div>
            <label className="form-label" htmlFor="dcomp-kind">{t('dcomp.ei_kind')}</label>
            <select id="dcomp-kind" className="form-control" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
              <option value="previous_employer">{t('dcomp.ei_kind_previous_employer')}</option>
              <option value="concurrent_employer">{t('dcomp.ei_kind_concurrent_employer')}</option>
            </select>
          </div>
          {field('employer_tax_id')}{field('employer_name')}{field('gross_income')}{field('tax_withheld')}
          <button type="button" className="btn btn-pri" onClick={() => void submit()} disabled={form.employer_tax_id === '' || form.gross_income === ''}>{t('dcomp.ei_submit')}</button>
        </section>
        {rows !== null && rows.length === 0 && <EmptyState title={t('dcomp.ei_empty_title')} text={t('dcomp.ei_empty_text')} />}
        {rows !== null && rows.length > 0 && (
          <section className="card">
            <table className="table">
              <thead><tr><th>{t('dcomp.ei_employer_name')}</th><th>{t('dcomp.ei_gross_income')}</th><th>{t('dcomp.ei_tax_withheld')}</th><th>{t('dcomp.col_status')}</th></tr></thead>
              <tbody>
                {rows.map((d) => (
                  <tr key={d.id}>
                    <td>{d.employer_name ?? '—'}</td><td>{money(d.gross_income)}</td><td>{money(d.tax_withheld)}</td>
                    <td>{t(`dcomp.ei_status_${d.status}`)}{d.applies_from_month ? <div className="sub2">{t('dcomp.ei_applies_from', { month: String(d.applies_from_month) })}</div> : null}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        )}
        {toastNode}
      </main>
    </>
  )
}
