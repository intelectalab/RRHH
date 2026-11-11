'use client'

import Link from 'next/link'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Term = { parameter_code: string; value: string | null; unit: string | null; verified: boolean; pending_validation: boolean; valid_from: string | null }
type ContractType = {
  id: string; code: string; name: string; category: string; modality_code: string | null
  requires_end_date: boolean; requires_objective_cause: boolean; is_system: boolean
  max_duration_parameter_code: string | null; trial_period_parameter_code: string | null
  max_duration: Term | null; trial_period: Term | null
}
const CATEGORIES = ['indefinite', 'fixed_term', 'part_time', 'internship', 'other'] as const

/** Configuración → Tipos de contrato (CON-1). Los plazos los resuelve la API a la fecha elegida. */
export function ContractTypesScreen() {
  const { t } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [asOf, setAsOf] = useState(localToday())
  const [rows, setRows] = useState<ContractType[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [adding, setAdding] = useState(false)

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: ContractType[] }>(`/contract-types?as_of=${encodeURIComponent(asOf)}`)).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [asOf])
  useEffect(() => { void load() }, [load])

  // Parámetros que ya usan los tipos del paquete: son los que un tipo propio puede referenciar.
  const parameters = useMemo(() => {
    const codes = new Set<string>()
    for (const r of rows ?? []) {
      if (r.max_duration_parameter_code) codes.add(r.max_duration_parameter_code)
      if (r.trial_period_parameter_code) codes.add(r.trial_period_parameter_code)
    }
    return [...codes].sort()
  }, [rows])

  return (
    <>
      <PageHeader title={t('ctype.title')} sub={t('ctype.sub')} actions={<>
        <Link className="btn btn-sec" href="/configuracion">{t('cfg.back')}</Link>
        {can('contracts.contract_types.create') && !adding && <button type="button" className="btn btn-primary" onClick={() => setAdding(true)}><Icon name="plus" /> {t('ctype.new')}</button>}
      </>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="nota">{t('ctype.help')}</div>
        <section className="card">
          <div className="param-form" style={{ margin: 16, maxWidth: 240 }}>
            <label className="form-label" htmlFor="ct-as-of">{t('ctype.as_of')}</label>
            <input id="ct-as-of" type="date" className="form-control" value={asOf} onChange={(e) => e.target.value && setAsOf(e.target.value)} />
          </div>
          {adding && <NewType parameters={parameters} onCancel={() => setAdding(false)} onDone={async () => { setAdding(false); toast(t('ctype.created')); await load() }} />}
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th>{t('ctype.name')}</th><th>{t('ctype.category')}</th><th>{t('ctype.end_date')}</th><th>{t('ctype.cause')}</th><th>{t('ctype.max_duration')}</th><th>{t('ctype.trial')}</th><th>{t('ctype.origin')}</th></tr></thead>
              <tbody>
                {loadError && <tr><td colSpan={7}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                {!loadError && rows === null && <tr><td colSpan={7}><span className="esq" style={{ width: '40%' }} /></td></tr>}
                {rows?.length === 0 && <tr><td colSpan={7}><EmptyState title={t('ctype.empty_title')} text={t('ctype.empty_text')} /></td></tr>}
                {rows?.map((r) => (
                  <tr key={r.id}>
                    <td><b>{r.name}</b><span className="sub2">{r.code}</span></td>
                    <td>{t(`ctype.cat_${r.category}`)}</td>
                    <td>{t(r.requires_end_date ? 'ctype.yes' : 'ctype.no')}</td>
                    <td>{t(r.requires_objective_cause ? 'ctype.yes' : 'ctype.no')}</td>
                    <td><TermCell term={r.max_duration} /></td>
                    <td><TermCell term={r.trial_period} /></td>
                    <td>{t(r.is_system ? 'ctype.origin_system' : 'ctype.origin_own')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {toastNode}
    </>
  )
}

function TermCell({ term }: { term: Term | null }) {
  const { t, locale } = useT()
  if (term === null) return <span className="sub2">{t('ctype.no_parameter')}</span>
  const unit = ['years', 'months', 'days'].includes(term.unit ?? '') ? term.unit : 'other'
  return (
    <>
      {term.value !== null && <span>{t(`ctype.unit_${unit}`, { n: term.value })}</span>}
      {term.pending_validation && <span className="badge text-bg-warning" style={{ marginInlineStart: term.value !== null ? 8 : 0 }}>{t('ctype.pending')}</span>}
      {term.valid_from && <span className="sub2">{term.parameter_code} · {formatDate(locale, term.valid_from)}</span>}
    </>
  )
}

function NewType({ parameters, onCancel, onDone }: { parameters: string[]; onCancel: () => void; onDone: () => Promise<void> }) {
  const { t } = useT()
  const [form, setForm] = useState({ code: '', name: '', category: 'other', modality_code: '', requires_end_date: false, requires_objective_cause: false, max_duration_parameter_code: '', trial_period_parameter_code: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    try {
      await api.post('/contract-types', {
        ...form,
        modality_code: form.modality_code || null,
        max_duration_parameter_code: form.max_duration_parameter_code || null,
        trial_period_parameter_code: form.trial_period_parameter_code || null,
      })
      await onDone()
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.general ? { ...r.fields, code: r.general } : r.fields)
    }
  }
  const text = (k: 'code' | 'name' | 'modality_code', label: string) => (
    <div><label className="form-label" htmlFor={`ct-${k}`}>{label}</label>
      <input id={`ct-${k}`} className={`form-control${errors[k] ? ' is-invalid' : ''}`} value={form[k]} onChange={(e) => setForm((f) => ({ ...f, [k]: e.target.value }))} />
      {errors[k] && <div className="invalid-feedback" role="alert">{errors[k]}</div>}</div>
  )
  const param = (k: 'max_duration_parameter_code' | 'trial_period_parameter_code', label: string) => (
    <div><label className="form-label" htmlFor={`ct-${k}`}>{label}</label>
      <select id={`ct-${k}`} className="form-select" value={form[k]} onChange={(e) => setForm((f) => ({ ...f, [k]: e.target.value }))}>
        <option value="">{t('ctype.no_parameter')}</option>
        {parameters.map((p) => <option key={p} value={p}>{p}</option>)}
      </select>
      {errors[k] && <div className="invalid-feedback" role="alert">{errors[k]}</div>}</div>
  )
  const check = (k: 'requires_end_date' | 'requires_objective_cause', label: string) => (
    <div className="form-check"><input id={`ct-${k}`} type="checkbox" className="form-check-input" checked={form[k]} onChange={(e) => setForm((f) => ({ ...f, [k]: e.target.checked }))} />
      <label className="form-check-label" htmlFor={`ct-${k}`}>{label}</label></div>
  )
  return (
    <form className="param-form" style={{ margin: 16 }} onSubmit={submit} noValidate>
      <div className="grid-param" style={{ gridTemplateColumns: '1fr 1fr 1fr' }}>
        {text('code', t('ctype.code'))}
        {text('name', t('ctype.name'))}
        <div><label className="form-label" htmlFor="ct-category">{t('ctype.category')}</label>
          <select id="ct-category" className="form-select" value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}>
            {CATEGORIES.map((c) => <option key={c} value={c}>{t(`ctype.cat_${c}`)}</option>)}
          </select>
          {errors.category && <div className="invalid-feedback" role="alert">{errors.category}</div>}</div>
        {text('modality_code', t('ctype.modality'))}
        {param('max_duration_parameter_code', t('ctype.max_duration'))}
        {param('trial_period_parameter_code', t('ctype.trial'))}
        {check('requires_end_date', t('ctype.end_date'))}
        {check('requires_objective_cause', t('ctype.cause'))}
      </div>
      <div className="param-acciones">
        <button type="button" className="btn btn-sec btn-sm" onClick={onCancel}>{t('ctype.cancel')}</button>
        <button type="submit" className="btn btn-primary btn-sm">{t('ctype.save')}</button>
      </div>
    </form>
  )
}
