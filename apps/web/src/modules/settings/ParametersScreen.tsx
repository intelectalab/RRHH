'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useCompany } from '@/modules/session/CompanyProvider'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'
import { ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Value = string | number | boolean | string[] | null
type Param = {
  code: string; name: string; description: string | null; data_type: string; options: string[] | null; default: Value
  value: Value; source: 'default' | 'organization' | 'legal_entity'; valid_from: string | null; company_scope: boolean; organization_value: Value
  history: Array<{ value: Value; valid_from: string | null; valid_to: string | null }>
}
type Group = { group: string; legal_entity_id: string | null; as_of: string; parameters: Param[] }

/** Nombre legible de una opción de código (canal, tipo de aviso); si no hay texto, el código. */
function optionLabel(t: (k: string) => string, o: string): string {
  for (const key of [`nchannel.${o}`, `ncat.${o.replace(/\./g, '_')}`, `cfgopt.${o}`]) {
    const v = t(key)
    if (v !== key) return v
  }
  return o
}

/** Parámetros de una sección (CFG-2, CFG-3): valor vigente, origen, herencia por empresa y vigencias. */
export function ParametersScreen({ group }: { group: string }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const { companies } = useCompany()
  const { toast, node: toastNode } = useToast()
  const [company, setCompany] = useState<string>('')
  const [data, setData] = useState<Group | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [editing, setEditing] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      setData((await api.get<{ data: Group }>(`/settings/${group}${company ? `?legal_entity_id=${company}` : ''}`)).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [group, company])
  useEffect(() => { void load() }, [load])

  const label = t(`cfgroup.${group}`) === `cfgroup.${group}` ? group : t(`cfgroup.${group}`)
  const show = (p: Param, v: Value): string => {
    if (v === null || v === '' || (Array.isArray(v) && v.length === 0)) return t('cfg.empty_value')
    if (typeof v === 'boolean') return t(v ? 'cfg.yes' : 'cfg.no')
    if (Array.isArray(v)) return v.map((o) => optionLabel(t, o)).join(', ')
    if (p.data_type === 'date' && typeof v === 'string') return formatDate(locale, v)
    if (p.data_type === 'select' && typeof v === 'string') return optionLabel(t, v)
    return String(v)
  }

  return (
    <>
      <PageHeader
        title={t('cfg.params_title', { group: label })}
        sub={t('cfg.params_sub')}
        actions={<Link className="btn btn-sec" href="/configuracion">{t('cfg.back')}</Link>}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {companies.length > 0 && (
          <div className="barra-filtros">
            <label className="form-label" htmlFor="cfg-scope" style={{ margin: 'auto 0' }}>{t('cfg.scope_label')}</label>
            <select id="cfg-scope" className="form-select" style={{ width: 'auto' }} value={company} onChange={(e) => { setEditing(null); setCompany(e.target.value) }}>
              <option value="">{t('cfg.scope_org')}</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
        )}
        {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
        {data && (
          <section className="card">
            {data.parameters.map((p) => {
              const locked = company !== '' && !p.company_scope
              return (
                <article key={p.code} id={p.code} className="param">
                  <div className="min-w-0">
                    <b>{p.name}</b>
                    {p.description && <span className="sub2">{p.description}</span>}
                    <div className="tags">
                      <span className={`tag ${p.source === 'legal_entity' ? 'pe' : p.source === 'organization' ? 'in' : 'gr'}`}>{t(`cfg.source_${p.source}`)}</span>
                      {company !== '' && p.source !== 'legal_entity' && p.company_scope && <span className="tag gr">{t('cfg.inherits', { value: show(p, p.organization_value) })}</span>}
                      {locked && <span className="tag gr">{t('cfg.org_only')}</span>}
                    </div>
                    {editing === p.code && (
                      <ParamForm param={p} company={company || null} onCancel={() => setEditing(null)} onSaved={async () => { setEditing(null); toast(t('cfg.saved')); await load() }} />
                    )}
                    {editing !== p.code && p.history.length > 0 && (
                      <details className="historial">
                        <summary>{t('cfg.history')}</summary>
                        <ul>
                          {p.history.map((h, i) => (
                            <li key={i}>{t('cfg.history_row', { value: show(p, h.value), from: h.valid_from ? formatDate(locale, h.valid_from) : '—' })}{h.valid_to ? t('cfg.history_to', { to: formatDate(locale, h.valid_to) }) : ''}</li>
                          ))}
                        </ul>
                      </details>
                    )}
                  </div>
                  <div className="param-valor">
                    <b>{show(p, p.value)}</b>
                    {p.valid_from && <span className="sub2">{t('cfg.valid_from')} {formatDate(locale, p.valid_from)}</span>}
                    {can('settings.parameters.update') && !locked && editing !== p.code && (
                      <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(p.code)}>{t('cfg.edit')}</button>
                    )}
                  </div>
                </article>
              )
            })}
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}

function ParamForm({ param, company, onCancel, onSaved }: { param: Param; company: string | null; onCancel: () => void; onSaved: () => Promise<void> }) {
  const { t } = useT()
  const [value, setValue] = useState<Value>(param.value ?? param.default)
  const [from, setFrom] = useState(localToday())
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const id = `v-${param.code.replace(/\./g, '-')}`

  async function save(v: Value) {
    setBusy(true)
    setError(null)
    try {
      await api.request(`/settings/${param.code.split('.')[0]}`, 'PUT', {
        values: [{ code: param.code, value: v, valid_from: from }],
        reason: reason || null,
        ...(company ? { legal_entity_id: company } : {}),
      })
      await onSaved()
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setError(Object.values(fields)[0] ?? general)
    } finally {
      setBusy(false)
    }
  }

  function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    void save(param.data_type === 'integer' && typeof value === 'string' ? Number(value) : value)
  }

  const input = (() => {
    switch (param.data_type) {
      case 'boolean':
        return <input id={id} type="checkbox" className="switch" role="switch" checked={value === true} onChange={(e) => setValue(e.target.checked)} />
      case 'select':
        return <select id={id} className="form-select" value={String(value ?? '')} onChange={(e) => setValue(e.target.value)}>{(param.options ?? []).map((o) => <option key={o} value={o}>{optionLabel(t, o)}</option>)}</select>
      case 'list':
        return param.options ? (
          <div className="chips" role="group" aria-labelledby={`${id}-l`}>
            {param.options.map((o) => {
              const on = Array.isArray(value) && value.includes(o)
              return <button key={o} type="button" aria-pressed={on} onClick={() => setValue(on ? (value as string[]).filter((x) => x !== o) : [...(Array.isArray(value) ? value : []), o])}>{optionLabel(t, o)}</button>
            })}
          </div>
        ) : <input id={id} className="form-control" value={Array.isArray(value) ? value.join(', ') : ''} onChange={(e) => setValue(e.target.value.split(',').map((s) => s.trim()).filter(Boolean))} />
      case 'integer':
        return <input id={id} type="number" step={1} inputMode="numeric" className="form-control" value={value === null ? '' : String(value)} onChange={(e) => setValue(e.target.value)} />
      case 'decimal':
        return <input id={id} inputMode="decimal" className="form-control" value={value === null ? '' : String(value)} onChange={(e) => setValue(e.target.value)} />
      case 'time':
        return <input id={id} type="time" className="form-control" value={String(value ?? '')} onChange={(e) => setValue(e.target.value)} />
      case 'date':
        return <input id={id} type="date" className="form-control" value={String(value ?? '')} onChange={(e) => setValue(e.target.value)} />
      default:
        return <input id={id} className="form-control" value={String(value ?? '')} onChange={(e) => setValue(e.target.value)} />
    }
  })()

  return (
    <form className="param-form" onSubmit={submit} noValidate>
      <div className="grid-param">
        <div>
          <label className="form-label" htmlFor={id} id={`${id}-l`}>{param.name}</label>
          {input}
        </div>
        <div>
          <label className="form-label" htmlFor={`${id}-from`}>{t('cfg.valid_from')}</label>
          <input id={`${id}-from`} type="date" className="form-control" value={from} onChange={(e) => setFrom(e.target.value)} required />
        </div>
      </div>
      <label className="form-label" htmlFor={`${id}-r`}>{t('cfg.reason')}</label>
      <input id={`${id}-r`} className="form-control" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
      {error && <div className="invalid-feedback" role="alert">{error}</div>}
      <div className="param-acciones">
        {company && param.source === 'legal_entity' && <button type="button" className="btn btn-ter btn-sm" disabled={busy} onClick={() => void save(null)}>{t('cfg.inherit_again')}</button>}
        <button type="button" className="btn btn-sec btn-sm" onClick={onCancel}>{t('cfg.cancel')}</button>
        <button type="submit" className="btn btn-primary btn-sm" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('cfg.save')}</button>
      </div>
    </form>
  )
}
