'use client'

import { useCallback, useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { orgTabs } from './orgTabs'

type CostCenter = Schema<'CostCenter'>
type Company = { id: string; name: string; status: string }

const TONE: Record<string, string> = { active: 'ok', closed: 'in', archived: 'gr' }

/** ORG-4 · Organización → Centros de costo: código, jerarquía y vigencia; se cierran con fecha y se reabren. */
export function CostCentersScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<CostCenter[] | null>(null)
  const [companies, setCompanies] = useState<Company[]>([])
  const [editing, setEditing] = useState<CostCenter | 'new' | null>(null)
  const [closing, setClosing] = useState<CostCenter | null>(null)
  const [loadError, setLoadError] = useState(false)

  const load = useCallback(async () => {
    try {
      const res = await api.get<{ data: CostCenter[] }>('/cost-centers?per_page=100')
      setRows(res.data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [])

  useEffect(() => {
    void load()
    void api.get<{ data: Company[] }>('/legal-entities?per_page=100').then((r) => setCompanies(r.data)).catch(() => setCompanies([]))
  }, [load])

  // Orden de árbol: por la ruta de códigos desde la raíz.
  const tree = useMemo(() => [...(rows ?? [])].sort((a, b) => (a.path ?? [a.code]).join('/').localeCompare((b.path ?? [b.code]).join('/'))), [rows])
  const companyName = (id?: string | null) => (id ? companies.find((c) => c.id === id)?.name ?? '—' : t('sites.shared'))
  const open = rows?.filter((r) => r.status === 'active').length ?? 0

  async function transition(row: CostCenter, action: 'reopen' | 'archive' | 'restore') {
    if (action === 'archive' && !window.confirm(t('cc.confirm_archive', { name: row.name }))) return
    try {
      await api.post(`/cost-centers/${row.id}/${action}`, {})
      toast(t(`cc.done_${action}`))
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  return (
    <>
      <PageHeader
        title={t('org.title')}
        sub={rows ? t('cc.sub', { open, total: rows.length }) : undefined}
        tabs={orgTabs(t, can, 'cost_centers')}
        actions={can('org.cost_centers.create') && (
          <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}><Icon name="plus" /> {t('cc.new')}</button>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="tabla-wrap">
            <table className="table">
              <thead>
                <tr><th>{t('cc.col_center')}</th><th>{t('cc.col_company')}</th><th>{t('cc.col_accounting')}</th><th>{t('cc.col_validity')}</th><th>{t('cc.col_status')}</th><th /></tr>
              </thead>
              <tbody>
                {loadError && <tr><td colSpan={6}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                {!loadError && rows === null && [1, 2].map((i) => <tr key={i}><td colSpan={6}><span className="esq" style={{ width: `${30 + i * 20}%` }} /></td></tr>)}
                {rows?.length === 0 && (
                  <tr><td colSpan={6}>
                    <EmptyState title={t('cc.empty_title')} text={t('cc.empty_text')} action={can('org.cost_centers.create') && (
                      <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing('new')}><Icon name="plus" /> {t('cc.new')}</button>
                    )} />
                  </td></tr>
                )}
                {tree.map((r) => {
                  const depth = Math.max(0, (r.path?.length ?? 1) - 1)
                  return (
                    <tr key={r.id}>
                      <td style={{ paddingInlineStart: `${12 + depth * 20}px` }}>
                        <b>{r.code}</b> · {r.name}
                        {depth > 0 && <span className="sub2">{r.path?.slice(0, -1).join(' › ')}</span>}
                      </td>
                      <td>{companyName(r.legal_entity_id)}</td>
                      <td>{r.accounting_code ?? '—'}</td>
                      <td>
                        {r.opened_on ? formatDate(locale, r.opened_on) : t('cc.no_start')}
                        {' – '}
                        {r.closed_on ? formatDate(locale, r.closed_on) : t('cc.no_end')}
                      </td>
                      <td><span className={`st ${TONE[r.status] ?? 'gr'}`}>{t(`cc.status_${r.status}`)}</span></td>
                      <td className="text-end nowrap">
                        {r.status !== 'archived' && can('org.cost_centers.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(r)}>{t('org.edit')}</button>}
                        {r.allowed_actions?.includes('close') && can('org.cost_centers.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setClosing(r)}>{t('cc.close')}</button>}
                        {r.allowed_actions?.includes('reopen') && can('org.cost_centers.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r, 'reopen')}>{t('cc.reopen')}</button>}
                        {r.allowed_actions?.includes('archive') && can('org.cost_centers.archive') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r, 'archive')}>{t('org.archive')}</button>}
                        {r.allowed_actions?.includes('restore') && can('org.cost_centers.restore') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void transition(r, 'restore')}>{t('org.restore')}</button>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {editing !== null && (
        <CostCenterPanel
          center={editing === 'new' ? null : editing}
          centers={(rows ?? []).filter((c) => c.status !== 'archived' && (editing === 'new' || c.id !== editing.id))}
          companies={companies.filter((c) => c.status === 'active')}
          onClose={() => setEditing(null)}
          onSaved={async (created) => {
            setEditing(null)
            toast(t(created ? 'cc.created' : 'cc.saved'))
            await load()
          }}
        />
      )}
      {closing && (
        <ClosePanel
          center={closing}
          onClose={() => setClosing(null)}
          onDone={async () => {
            setClosing(null)
            toast(t('cc.done_close'))
            await load()
          }}
        />
      )}
      {toastNode}
    </>
  )
}

function CostCenterPanel({ center, centers, companies, onClose, onSaved }: { center: CostCenter | null; centers: CostCenter[]; companies: Company[]; onClose: () => void; onSaved: (created: boolean) => Promise<void> }) {
  const { t } = useT()
  const [form, setForm] = useState({
    legal_entity_id: center?.legal_entity_id ?? companies[0]?.id ?? '',
    parent_id: center?.parent_id ?? '',
    code: center?.code ?? '',
    name: center?.name ?? '',
    accounting_code: center?.accounting_code ?? '',
    opened_on: center?.opened_on ?? '',
  })
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  // Padres posibles: de la misma empresa o compartidos.
  const parents = centers.filter((c) => !c.legal_entity_id || c.legal_entity_id === form.legal_entity_id)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const text = (v: string) => v.trim() || null
    const body = {
      legal_entity_id: text(form.legal_entity_id), parent_id: text(form.parent_id), code: form.code.trim(), name: form.name.trim(),
      accounting_code: text(form.accounting_code), opened_on: text(form.opened_on),
    }
    try {
      if (center) await api.request(`/cost-centers/${center.id}`, 'PATCH', body, center.version != null ? { 'If-Match': `"${center.version}"` } : undefined)
      else await api.post('/cost-centers', body)
      await onSaved(center === null)
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      setError(general)
    } finally {
      setBusy(false)
    }
  }

  const field = (key: keyof typeof form, label: string, input: ReactNode, help?: string) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`cc-${key}`}>{label}</label>
      {input}
      {help && <p className="small text-secondary">{help}</p>}
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )

  return (
    <SidePanel
      title={t(center ? 'cc.form_edit' : 'cc.form_new')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('org.cancel')}</button>
        <button type="submit" form="centro" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('org.save')}</button>
      </>}
    >
      <form id="centro" onSubmit={submit} noValidate>
        {field('legal_entity_id', t('sites.company'), (
          <select id="cc-legal_entity_id" className={`form-select${errors.legal_entity_id ? ' is-invalid' : ''}`} value={form.legal_entity_id} onChange={set('legal_entity_id')}>
            <option value="">{t('sites.shared')}</option>
            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        ))}
        {field('parent_id', t('cc.parent'), (
          <select id="cc-parent_id" className={`form-select${errors.parent_id ? ' is-invalid' : ''}`} value={form.parent_id} onChange={set('parent_id')}>
            <option value="">{t('cc.no_parent')}</option>
            {parents.map((c) => <option key={c.id} value={c.id}>{(c.path ?? [c.code]).join(' › ')} · {c.name}</option>)}
          </select>
        ))}
        {field('code', t('cc.code'), <input id="cc-code" className={`form-control${errors.code ? ' is-invalid' : ''}`} value={form.code} onChange={set('code')} required maxLength={30} />)}
        {field('name', t('cc.name'), <input id="cc-name" className={`form-control${errors.name ? ' is-invalid' : ''}`} value={form.name} onChange={set('name')} required />)}
        {field('accounting_code', t('cc.accounting'), <input id="cc-accounting_code" className={`form-control${errors.accounting_code ? ' is-invalid' : ''}`} value={form.accounting_code} onChange={set('accounting_code')} />, t('cc.accounting_help'))}
        {field('opened_on', t('cc.opened_on'), <input id="cc-opened_on" type="date" className={`form-control${errors.opened_on ? ' is-invalid' : ''}`} value={form.opened_on} onChange={set('opened_on')} />, t('cc.opened_help'))}
        {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}

function ClosePanel({ center, onClose, onDone }: { center: CostCenter; onClose: () => void; onDone: () => Promise<void> }) {
  const { t } = useT()
  const [closedOn, setClosedOn] = useState(() => localToday())
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    try {
      await api.post(`/cost-centers/${center.id}/close`, { closed_on: closedOn, reason: reason.trim() || null })
      await onDone()
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      setError(general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel
      title={t('cc.close_title', { name: center.name })}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('org.cancel')}</button>
        <button type="submit" form="cerrar-centro" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('cc.close')}</button>
      </>}
    >
      <form id="cerrar-centro" onSubmit={submit} noValidate>
        <p className="small text-secondary">{t('cc.close_help')}</p>
        <div className="mb-3">
          <label className="form-label" htmlFor="cc-closed_on">{t('cc.closed_on')}</label>
          <input id="cc-closed_on" type="date" className={`form-control${errors.closed_on ? ' is-invalid' : ''}`} value={closedOn} onChange={(e) => setClosedOn(e.target.value)} required />
          {errors.closed_on && <div className="invalid-feedback" role="alert">{errors.closed_on}</div>}
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="cc-reason">{t('cc.reason')}</label>
          <input id="cc-reason" className="form-control" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
        </div>
        {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}
