'use client'

import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatNumber, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useDateTime } from '@/modules/session/useDateTime'
import { useSession } from '@/modules/session/SessionProvider'
import { DataTable, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { orgTabs } from './orgTabs'

type Money = { amount: string; currency: string }
type Approval = { id: string; status: string; level: number | null; pending_approvers: string[] }
type BudgetSummary = { has_budget: boolean; budgeted_positions: number | null; committed_positions: number; available_positions: number | null }
type CreatedPosition = { id: string; code: string; title: string; status: string }
export type PositionRequest = {
  id: string
  requested_by_user_id: string
  requested_by_name: string | null
  legal_entity_id: string | null
  org_unit_id: string
  org_unit_name: string | null
  job_profile_id: string
  job_profile_name: string | null
  site_id: string | null
  cost_center_id: string | null
  reports_to_position_id: string | null
  title: string | null
  quantity: number
  justification: string
  kind: 'new' | 'replacement' | 'temporary'
  needed_by: string | null
  status: 'draft' | 'submitted' | 'approved' | 'rejected' | 'cancelled' | 'fulfilled'
  over_budget: boolean
  approval_request_id: string | null
  submitted_at: string | null
  decided_at: string | null
  decision_reason: string | null
  estimated_cost?: Money
  allowed_actions: string[]
  version: number
  created_at: string
  approval?: Approval | null
  budget?: BudgetSummary
  positions?: CreatedPosition[]
}
type Option = { id: string; name?: string; title?: string; code?: string; legal_entity_id?: string | null }
type Options = { legal_entities: Option[]; job_profiles: Option[]; org_units: Option[]; sites: Option[]; cost_centers: Option[]; positions: Option[] }

const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'cancelled', 'fulfilled'] as const
const TONE: Record<string, string> = { draft: 'gr', submitted: 'in', approved: 'ok', rejected: 'er', cancelled: 'gr', fulfilled: 'ok' }

/**
 * Organización → Solicitudes de posición (POS-3 · ME-04): el manager o RRHH pide una plaza con motivo y costo
 * estimado; se envía al flujo de aprobación configurado y, aprobada, crea sus posiciones. Aprobar o rechazar
 * se hace en el centro de aprobaciones.
 */
export function PositionRequestsScreen() {
  const { t } = useT()
  const { can, session } = useSession()
  const { toast, node: toastNode } = useToast()
  const [options, setOptions] = useState<Options | null>(null)
  const [reloadKey, setReloadKey] = useState(0)
  const [editing, setEditing] = useState<PositionRequest | 'new' | null>(null)
  const [detail, setDetail] = useState<string | null>(null)
  const reload = () => setReloadKey((k) => k + 1)

  useEffect(() => {
    if (!can('org.positions.view')) return
    void api.get<{ data: Options }>('/position-options').then((r) => setOptions(r.data)).catch(() => toast(t('preq.load_error'), 'error'))
  }, [can, t, toast])

  const columns: Array<TableColumn<PositionRequest>> = [
    { key: 'title', label: t('preq.col_title'), sort: 'title', hideable: false, width: 240, render: (r) => <><b>{r.title ?? r.job_profile_name ?? '—'}</b><span className="sub2">{t('preq.quantity_n', { n: r.quantity })}</span></> },
    { key: 'unit', label: t('pos.col_unit'), render: (r) => r.org_unit_name ?? '—' },
    { key: 'requested_by', label: t('preq.col_requested_by'), render: (r) => r.requested_by_name ?? '—' },
    { key: 'needed_by', label: t('preq.col_needed_by'), sort: 'needed_by', render: (r) => r.needed_by ?? '—' },
    { key: 'budget', label: t('preq.col_budget'), render: (r) => r.over_budget ? <span className="st wa">{t('preq.over_budget')}</span> : '—' },
    { key: 'status', label: t('pos.col_status'), sort: 'status', render: (r) => <span className={`st ${TONE[r.status] ?? 'gr'}`}>{t(`preq.status_${r.status}`)}</span> },
  ]
  const filters: TableFilter[] = [
    { key: 'status', label: t('pos.filter_status'), options: STATUSES.map((s) => ({ value: s, label: t(`preq.status_${s}`) })) },
  ]

  return (
    <>
      <PageHeader title={t('org.title')} sub={t('preq.sub')} tabs={orgTabs(t, can, 'position_requests', session.modules)} />
      <main className="main" id="contenido" tabIndex={-1}>
        <DataTable<PositionRequest>
          resource="position-requests"
          endpoint="/position-requests"
          defaultSort="-created_at"
          searchLabel={t('preq.search')}
          reloadKey={reloadKey}
          rowKey={(r) => r.id}
          columns={columns}
          filters={filters}
          toolbar={() => can('org.position_requests.create') && options && <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing('new')}><Icon name="plus" /> {t('preq.new')}</button>}
          empty={<EmptyState title={t('preq.empty_title')} text={t('preq.empty_text')} />}
          rowActions={(r) => (
            <>
              <button type="button" className="btn btn-ter btn-sm" onClick={() => setDetail(r.id)}>{t('pos.detail')}</button>
              {r.status === 'draft' && options && <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(r)}>{t('pos.edit')}</button>}
            </>
          )}
        />
      </main>
      {editing !== null && options && (
        <RequestPanel
          request={editing === 'new' ? null : editing}
          options={options}
          onClose={() => setEditing(null)}
          onSaved={(id) => { setEditing(null); toast(t('preq.saved')); reload(); setDetail(id) }}
        />
      )}
      {detail && <RequestDetail id={detail} onClose={() => setDetail(null)} onChanged={reload} toast={toast} />}
      {toastNode}
    </>
  )
}

function RequestPanel({ request, options, onClose, onSaved }: { request: PositionRequest | null; options: Options; onClose: () => void; onSaved: (id: string) => void }) {
  const { t } = useT()
  const [form, setForm] = useState({
    legal_entity_id: request?.legal_entity_id ?? (options.legal_entities[0]?.id ?? ''),
    org_unit_id: request?.org_unit_id ?? '',
    job_profile_id: request?.job_profile_id ?? '',
    title: request?.title ?? '',
    site_id: request?.site_id ?? '',
    cost_center_id: request?.cost_center_id ?? '',
    reports_to_position_id: request?.reports_to_position_id ?? '',
    quantity: String(request?.quantity ?? 1),
    kind: request?.kind ?? 'new',
    needed_by: request?.needed_by ?? '',
    justification: request?.justification ?? '',
    cost_amount: request?.estimated_cost?.amount ?? '',
    cost_currency: request?.estimated_cost?.currency ?? 'PEN',
  })
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const ofEntity = (list: Option[]) => list.filter((o) => !o.legal_entity_id || o.legal_entity_id === form.legal_entity_id)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const nullable = (v: string) => (v.trim() === '' ? null : v.trim())
    const body: Record<string, unknown> = {
      legal_entity_id: form.legal_entity_id, org_unit_id: form.org_unit_id, job_profile_id: form.job_profile_id,
      title: nullable(form.title), site_id: nullable(form.site_id), cost_center_id: nullable(form.cost_center_id),
      reports_to_position_id: nullable(form.reports_to_position_id), quantity: Number(form.quantity), kind: form.kind,
      needed_by: nullable(form.needed_by), justification: form.justification,
      estimated_cost: form.cost_amount.trim() === '' ? null : { amount: form.cost_amount.trim(), currency: form.cost_currency },
    }
    try {
      const res = request
        ? await api.request<{ data: PositionRequest }>(`/position-requests/${request.id}`, 'PATCH', body, { 'If-Match': `"${request.version}"` })
        : await api.post<{ data: PositionRequest }>('/position-requests', body)
      onSaved(res.data.id)
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setErrors(fields)
      setError(general)
    } finally {
      setBusy(false)
    }
  }

  const field = (key: string, label: string, input: ReactNode, help?: string) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`pr-${key}`}>{label}</label>
      {input}
      {help && <p className="small text-secondary">{help}</p>}
      {errors[key] && <div className="invalid-feedback" role="alert">{errors[key]}</div>}
    </div>
  )
  const select = (key: keyof typeof form, list: Option[], optional = true) => (
    <select id={`pr-${key}`} className={`form-select${errors[key] ? ' is-invalid' : ''}`} value={String(form[key])} onChange={set(key)}>
      <option value="">{t(optional ? 'pos.none' : 'pos.choose')}</option>
      {list.map((o) => <option key={o.id} value={o.id}>{o.name ?? o.title}{o.code && o.title ? ` · ${o.code}` : ''}</option>)}
    </select>
  )

  return (
    <SidePanel
      title={t(request ? 'preq.form_edit' : 'preq.form_new')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={<>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('pos.cancel')}</button>
        <button type="submit" form="solicitud-posicion" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('preq.save_draft')}</button>
      </>}
    >
      <form id="solicitud-posicion" onSubmit={submit} noValidate>
        {field('legal_entity_id', t('pos.company'), select('legal_entity_id', options.legal_entities, false))}
        {field('org_unit_id', t('pos.col_unit'), select('org_unit_id', ofEntity(options.org_units), false))}
        {field('job_profile_id', t('pos.col_job'), select('job_profile_id', options.job_profiles, false))}
        {field('title', t('preq.title_field'), <input id="pr-title" className={`form-control${errors.title ? ' is-invalid' : ''}`} value={form.title} onChange={set('title')} />, t('preq.title_help'))}
        <div className="row g-2">
          <div className="col-6">{field('quantity', t('preq.quantity'), <input id="pr-quantity" type="number" min={1} max={100} className={`form-control${errors.quantity ? ' is-invalid' : ''}`} value={form.quantity} onChange={set('quantity')} />)}</div>
          <div className="col-6">{field('kind', t('preq.kind'), (
            <select id="pr-kind" className="form-select" value={form.kind} onChange={set('kind')}>
              {(['new', 'replacement', 'temporary'] as const).map((k) => <option key={k} value={k}>{t(`preq.kind_${k}`)}</option>)}
            </select>
          ))}</div>
        </div>
        {field('needed_by', t('preq.col_needed_by'), <input id="pr-needed_by" type="date" className={`form-control${errors.needed_by ? ' is-invalid' : ''}`} value={form.needed_by} onChange={set('needed_by')} />)}
        {field('justification', t('preq.justification'), <textarea id="pr-justification" rows={3} className={`form-control${errors.justification ? ' is-invalid' : ''}`} value={form.justification} onChange={set('justification')} />)}
        {field('site_id', t('pos.col_site'), select('site_id', ofEntity(options.sites)))}
        {field('cost_center_id', t('pos.cost_center'), select('cost_center_id', ofEntity(options.cost_centers)))}
        {field('reports_to_position_id', t('pos.col_reports_to'), select('reports_to_position_id', options.positions))}
        <fieldset className="cf-grupo">
          <legend>{t('preq.estimated_cost')}</legend>
          <div className="row g-2">
            <div className="col-8">{field('estimated_cost', t('preq.cost_per_position'), <input id="pr-estimated_cost" inputMode="decimal" className={`form-control${errors.estimated_cost || errors['estimated_cost.amount'] ? ' is-invalid' : ''}`} value={form.cost_amount} onChange={set('cost_amount')} />)}</div>
            <div className="col-4">{field('cost_currency', t('pos.budget_currency'), <input id="pr-cost_currency" maxLength={3} className="form-control" value={form.cost_currency} onChange={(e) => setForm((f) => ({ ...f, cost_currency: e.target.value.toUpperCase() }))} />)}</div>
          </div>
        </fieldset>
        {error && <div className="invalid-feedback" style={{ display: 'block' }} role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}

function RequestDetail({ id, onClose, onChanged, toast }: { id: string; onClose: () => void; onChanged: () => void; toast: (text: string, kind?: 'ok' | 'error') => void }) {
  const { t, locale } = useT()
  const fmt = useDateTime()
  const [request, setRequest] = useState<PositionRequest | null>(null)
  const [failed, setFailed] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const [reason, setReason] = useState('')

  useEffect(() => {
    setFailed(false)
    void api.get<{ data: PositionRequest }>(`/position-requests/${id}`).then((r) => setRequest(r.data)).catch(() => setFailed(true))
  }, [id])

  async function act(action: 'submit' | 'cancel') {
    setBusy(action)
    try {
      const res = await api.post<{ data: PositionRequest }>(`/position-requests/${id}/${action}`, action === 'cancel' ? { reason: reason.trim() || null } : {})
      setRequest(res.data)
      toast(t(`preq.done_${action}`))
      onChanged()
    } catch (err) {
      toast(err instanceof ApiRequestError ? err.message : t('auth.generic_error'), 'error')
    } finally {
      setBusy(null)
    }
  }

  const today = localToday()
  return (
    <SidePanel
      title={request?.title ?? request?.job_profile_name ?? t('preq.detail')}
      onClose={onClose}
      closeLabel={t('common.close')}
      footer={request && request.allowed_actions.length > 0 ? <>
        {request.allowed_actions.includes('cancel') && <button type="button" className="btn btn-sec" disabled={busy !== null} onClick={() => void act('cancel')}>{busy === 'cancel' && <span className="spin" aria-hidden="true" />}{t('preq.action_cancel')}</button>}
        {request.allowed_actions.includes('submit') && <button type="button" className="btn btn-primary" disabled={busy !== null} onClick={() => void act('submit')}>{busy === 'submit' && <span className="spin" aria-hidden="true" />}{t('preq.action_submit')}</button>}
      </> : undefined}
    >
      {failed && <p role="alert">{t('preq.load_error')}</p>}
      {!request && !failed && <span className="esq" style={{ width: 240 }} />}
      {request && (
        <>
          {request.over_budget && <Notice tone="warn">{t('preq.over_budget_notice')}</Notice>}
          {request.status === 'rejected' && request.decision_reason && <Notice tone="error">{t('preq.rejected_reason', { reason: request.decision_reason })}</Notice>}
          <dl className="ficha mb-3">
            <dt>{t('pos.col_status')}</dt><dd><span className={`st ${TONE[request.status] ?? 'gr'}`}>{t(`preq.status_${request.status}`)}</span></dd>
            <dt>{t('pos.col_unit')}</dt><dd>{request.org_unit_name ?? '—'}</dd>
            <dt>{t('pos.col_job')}</dt><dd>{request.job_profile_name ?? '—'}</dd>
            <dt>{t('preq.quantity')}</dt><dd>{request.quantity}</dd>
            <dt>{t('preq.kind')}</dt><dd>{t(`preq.kind_${request.kind}`)}</dd>
            <dt>{t('preq.col_needed_by')}</dt><dd>{request.needed_by ?? t('preq.asap', { date: today })}</dd>
            <dt>{t('preq.justification')}</dt><dd>{request.justification}</dd>
            <dt>{t('preq.col_requested_by')}</dt><dd>{request.requested_by_name ?? '—'}</dd>
            {request.estimated_cost && <><dt>{t('preq.cost_per_position')}</dt><dd>{request.estimated_cost.currency} {formatNumber(locale, request.estimated_cost.amount)}</dd></>}
            {request.submitted_at && <><dt>{t('preq.submitted_at')}</dt><dd>{fmt(request.submitted_at)}</dd></>}
            {request.decided_at && <><dt>{t('preq.decided_at')}</dt><dd>{fmt(request.decided_at)}</dd></>}
          </dl>
          {request.budget && (
            <section className="mb-3" aria-label={t('preq.budget_title')}>
              <h3 className="h6">{t('preq.budget_title')}</h3>
              <p className="mb-0">{request.budget.has_budget
                ? t('preq.budget_available', { available: request.budget.available_positions ?? 0, budgeted: request.budget.budgeted_positions ?? 0, committed: request.budget.committed_positions })
                : t('preq.budget_none')}</p>
            </section>
          )}
          {request.approval && (
            <section className="mb-3" aria-label={t('preq.approval_title')}>
              <h3 className="h6">{t('preq.approval_title')}</h3>
              <p className="mb-0">{t(`preq.approval_${request.approval.status}`)}{request.approval.pending_approvers.length > 0 ? ` · ${t('preq.pending_with', { names: request.approval.pending_approvers.join(', ') })}` : ''}</p>
            </section>
          )}
          {(request.positions ?? []).length > 0 && (
            <section className="mb-3" aria-label={t('preq.positions_title')}>
              <h3 className="h6">{t('preq.positions_title')}</h3>
              <ul className="mb-0">{(request.positions ?? []).map((p) => <li key={p.id}><a href={`/organizacion/posiciones?q=${encodeURIComponent(p.code)}`}>{p.title}</a> <span className="sub2">{p.code} · {t(`pos.status_${p.status}`)}</span></li>)}</ul>
            </section>
          )}
          {request.allowed_actions.includes('cancel') && (
            <div className="mb-3">
              <label className="form-label" htmlFor="pr-cancel-reason">{t('preq.cancel_reason')}</label>
              <textarea id="pr-cancel-reason" className="form-control" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
            </div>
          )}
        </>
      )}
    </SidePanel>
  )
}
