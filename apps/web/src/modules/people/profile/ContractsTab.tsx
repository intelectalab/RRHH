'use client'

import { useCallback, useEffect, useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { StatusBadge, type StatusTone } from '@/ui/components/Status'
import type { ProfileTabContext } from './types'

type Modality = 'onsite' | 'remote' | 'hybrid'

export type ContractAddendum = {
  id: string
  contract_id: string
  number: string | null
  kind: (typeof ADDENDUM_KINDS)[number] | 'position'
  effective_on: string
  description: string | null
  changes: Record<string, unknown>
  salary_change?: { amount: string; currency: string } | null
  status: 'draft' | 'pending_signature' | 'active' | 'cancelled'
  has_document: boolean
  signed_at: string | null
  cancellation_reason: string | null
  allowed_actions: string[]
  version: number
}

export type Contract = {
  id: string
  contract_type_id: string
  contract_type_name: string | null
  contract_type_category: 'indefinite' | 'fixed_term' | 'part_time' | 'other' | null
  number: string | null
  starts_on: string
  ends_on: string | null
  trial_period_ends_on: string | null
  weekly_hours: string | number | null
  work_modality: Modality | null
  objective_cause: string | null
  status: (typeof CONTRACT_STATUSES)[number]
  renewal_of_contract_id: string | null
  renewed_by_contract_id: string | null
  has_document: boolean
  document_version: number | null
  signature_origin: 'physical_scan' | 'electronic' | null
  signed_at: string | null
  accumulated_days: number | null
  termination_reason: string | null
  over_limit_reason?: string | null
  allowed_actions: string[]
  version: number
  addenda?: ContractAddendum[]
}

type ContractType = { id: string; code: string; name: string; category: string; requires_end_date: boolean; requires_objective_cause: boolean }

export const CONTRACT_STATUSES = ['draft', 'pending_approval', 'pending_signature', 'signed', 'active', 'expired', 'terminated', 'cancelled', 'superseded'] as const
/** Tipos de adenda que se registran desde la ficha; el cambio de puesto se hace con un movimiento en «Historial». */
export const ADDENDUM_KINDS = ['salary', 'schedule', 'modality', 'extension', 'other'] as const
/** Acciones que piden motivo (y, para el cese del contrato, la fecha). */
const REASON_ACTIONS = ['return', 'decline-signature', 'void', 'terminate'] as const
type ReasonAction = (typeof REASON_ACTIONS)[number]

const TONE: Record<Contract['status'], StatusTone> = {
  draft: 'gr', pending_approval: 'wa', pending_signature: 'wa', signed: 'in', active: 'ok', expired: 'er', terminated: 'gr', cancelled: 'gr', superseded: 'gr',
}
const ADD_TONE: Record<ContractAddendum['status'], StatusTone> = { draft: 'gr', pending_signature: 'wa', active: 'ok', cancelled: 'gr' }

/** Permiso que exige cada acción del contrato (el backend valida lo mismo). */
const ACTION_PERMISSION: Record<string, string> = {
  submit: 'contracts.contracts.create', approve: 'contracts.contracts.approve', return: 'contracts.contracts.approve',
  'register-signature': 'contracts.contracts.issue', 'decline-signature': 'contracts.contracts.issue', void: 'contracts.contracts.update',
  terminate: 'contracts.contracts.terminate', renew: 'contracts.contracts.renew',
}
const ADD_PERMISSION: Record<string, string> = { issue: 'contracts.addenda.issue', 'register-signature': 'contracts.addenda.issue', cancel: 'contracts.addenda.cancel' }

/** Acciones que la ficha ofrece como botón (las de sistema —start, expire, complete— las aplica la tarea diaria). */
export function contractButtons(c: Pick<Contract, 'allowed_actions' | 'status'>, can: (p: string) => boolean): string[] {
  const actions = c.allowed_actions.filter((a) => ACTION_PERMISSION[a] !== undefined && can(ACTION_PERMISSION[a]))
  return c.status === 'draft' && can('contracts.contracts.update') ? ['edit', ...actions] : actions
}

type EditForm = { contract_type_id: string; starts_on: string; ends_on: string; trial_period_ends_on: string; weekly_hours: string; work_modality: string; objective_cause: string }
type RenewForm = { contract_type_id: string; starts_on: string; ends_on: string; objective_cause: string }
/** CON-4 · Renovación que supera el tope: el servidor exige confirmación expresa y un motivo. */
type OverLimit = { explanation: string; confirmed: boolean; reason: string }
type AddForm = { kind: string; effective_on: string; description: string; amount: string; currency: string; weekly_hours: string; work_modality: string; ends_on: string }
type SignTarget = { kind: 'contract' | 'addendum'; id: string; fromDraft: boolean }

const EMPTY_ADD: AddForm = { kind: 'other', effective_on: '', description: '', amount: '', currency: 'PEN', weekly_hours: '', work_modality: 'onsite', ends_on: '' }

function saveFile(blob: Blob, name: string) {
  const href = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = href
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(href)
}

const clean = (o: Record<string, string>) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v === '' ? null : v]))

/**
 * CON-2 · CON-3 · Contratos de la relación laboral: la cadena en orden (original, renovaciones, conversión)
 * con sus adendas. El contrato que deja el ingreso en Borrador se completa aquí, se envía, se aprueba (se
 * genera su documento) y se registra firmado subiendo el escaneo. Los plazos, topes y el periodo de prueba
 * los valida el servidor contra los parámetros legales; la pantalla muestra sus avisos.
 */
export function ContractsTab({ header, toast }: ProfileTabContext) {
  const { t, locale } = useT()
  const { can } = useSession()
  const [rows, setRows] = useState<Contract[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [types, setTypes] = useState<ContractType[]>([])
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [warnings, setWarnings] = useState<string[]>([])
  const [edit, setEdit] = useState<{ contract: Contract; form: EditForm } | null>(null)
  const [renew, setRenew] = useState<{ contract: Contract; form: RenewForm } | null>(null)
  const [overLimit, setOverLimit] = useState<OverLimit | null>(null)
  const [addendum, setAddendum] = useState<{ contract: Contract; form: AddForm } | null>(null)
  const [sign, setSign] = useState<SignTarget | null>(null)
  const [signFile, setSignFile] = useState<File | null>(null)
  const [signedOn, setSignedOn] = useState('')
  const [origin, setOrigin] = useState<'physical_scan' | 'electronic'>('physical_scan')
  const [reasonFor, setReasonFor] = useState<{ kind: 'contract' | 'addendum'; id: string; action: ReasonAction | 'cancel' } | null>(null)
  const [reason, setReason] = useState('')
  const [endOn, setEndOn] = useState('')

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: Contract[] }>(`/employments/${header.id}/contracts`)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [header.id])

  useEffect(() => { void load() }, [load])

  const date = (v: string | null) => (v ? formatDate(locale, v) : '—')
  const reset = () => { setErrors({}); setGeneral(null) }
  const fail = (e: unknown) => { const f = formErrors(e, t); setErrors(f.fields); setGeneral(f.general) }
  const err = (k: string) => errors[k] && <div className="invalid-feedback d-block" role="alert">{errors[k]}</div>

  async function ensureTypes() {
    if (types.length > 0) return
    try {
      setTypes((await api.get<{ data: { contract_types: ContractType[] } }>('/employment-options')).data.contract_types)
    } catch {
      // Sin opciones se edita lo demás; el servidor valida el tipo.
    }
  }

  async function run(fn: () => Promise<unknown>, done: string, close: () => void) {
    setBusy(true); reset()
    try {
      const res = (await fn()) as { data?: { warnings?: Array<{ message?: string; code: string }> } } | undefined
      setWarnings((res?.data?.warnings ?? []).map((w) => w.message ?? w.code))
      close()
      toast(done)
      await load()
    } catch (e) {
      fail(e)
      const f = formErrors(e, t)
      if (Object.keys(f.fields).length === 0) toast(f.general ?? t('auth.generic_error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  async function download(path: string) {
    try {
      const { blob, filename } = await api.download(path)
      saveFile(blob, filename)
    } catch {
      toast(t('common.load_error'), 'error')
    }
  }

  function act(c: Contract, action: string) {
    reset()
    if (action === 'edit') {
      void ensureTypes()
      setEdit({ contract: c, form: {
        contract_type_id: c.contract_type_id, starts_on: c.starts_on, ends_on: c.ends_on ?? '', trial_period_ends_on: c.trial_period_ends_on ?? '',
        weekly_hours: c.weekly_hours === null ? '' : String(c.weekly_hours), work_modality: c.work_modality ?? 'onsite', objective_cause: c.objective_cause ?? '',
      } })
      return
    }
    if (action === 'renew') {
      void ensureTypes()
      setOverLimit(null)
      setRenew({ contract: c, form: { contract_type_id: '', starts_on: '', ends_on: '', objective_cause: '' } })
      return
    }
    if (action === 'register-signature') {
      setSign({ kind: 'contract', id: c.id, fromDraft: c.status === 'draft' }); setSignFile(null); setSignedOn(''); setOrigin('physical_scan')
      return
    }
    if ((REASON_ACTIONS as readonly string[]).includes(action)) {
      setReason(''); setEndOn(''); setReasonFor({ kind: 'contract', id: c.id, action: action as ReasonAction })
      return
    }
    void run(() => api.post(`/contracts/${c.id}/${action}`, {}), t(`ctr.done_${action}`), () => undefined)
  }

  function actAddendum(a: ContractAddendum, action: string) {
    reset()
    if (action === 'register-signature') {
      setSign({ kind: 'addendum', id: a.id, fromDraft: a.status === 'draft' }); setSignFile(null); setSignedOn(''); setOrigin('physical_scan')
      return
    }
    if (action === 'cancel') {
      setReason(''); setReasonFor({ kind: 'addendum', id: a.id, action: 'cancel' })
      return
    }
    void run(() => api.post(`/contract-addenda/${a.id}/${action}`, {}), t('ctr.add_issued'), () => undefined)
  }

  function saveEdit() {
    if (!edit) return
    void run(() => api.request(`/contracts/${edit.contract.id}`, 'PATCH', clean(edit.form), { 'If-Match': String(edit.contract.version) }), t('ctr.saved'), () => setEdit(null))
  }

  function saveRenew() {
    if (!renew) return
    const body: Record<string, unknown> = Object.fromEntries(Object.entries(renew.form).filter(([, v]) => v !== ''))
    if (overLimit) {
      if (!overLimit.confirmed) { setGeneral(t('ctr.over_limit_confirm_required')); return }
      body.confirm_over_limit = true
      body.over_limit_reason = overLimit.reason
    }
    void run(
      () => api.post(`/contracts/${renew.contract.id}/renew`, body).catch((e: unknown) => {
        // El tope se supera: se pide confirmación expresa y motivo antes de reenviar.
        if (e instanceof ApiRequestError && e.code === 'contract_limit_exceeded') setOverLimit((o) => o ?? { explanation: e.message, confirmed: false, reason: '' })
        throw e
      }),
      t('ctr.renewed'),
      () => { setRenew(null); setOverLimit(null) },
    )
  }

  function saveAddendum() {
    if (!addendum) return
    const f = addendum.form
    const changes: Record<string, unknown> =
      f.kind === 'salary' ? { base_salary: { amount: f.amount, currency: f.currency } }
        : f.kind === 'schedule' ? { weekly_hours: f.weekly_hours === '' ? null : Number(f.weekly_hours) }
          : f.kind === 'modality' ? { work_modality: f.work_modality }
            : f.kind === 'extension' ? { ends_on: f.ends_on }
              : {}
    void run(() => api.post('/contract-addenda', { contract_id: addendum.contract.id, kind: f.kind, effective_on: f.effective_on, description: f.description, changes }),
      t('ctr.add_created'), () => setAddendum(null))
  }

  function saveSignature() {
    if (!sign) return
    const form = new FormData()
    if (signFile) form.append('file', signFile, signFile.name)
    if (signedOn) form.append('signed_on', signedOn)
    form.append('origin', origin)
    const path = sign.kind === 'contract' ? `/contracts/${sign.id}/register-signature` : `/contract-addenda/${sign.id}/register-signature`
    void run(() => api.postForm(path, form), t('ctr.signed'), () => setSign(null))
  }

  function saveReason() {
    if (!reasonFor) return
    const path = reasonFor.kind === 'contract' ? `/contracts/${reasonFor.id}/${reasonFor.action}` : `/contract-addenda/${reasonFor.id}/cancel`
    const body: Record<string, string> = { reason }
    if (reasonFor.action === 'terminate' && endOn) body.effective_on = endOn
    void run(() => api.post(path, body), t(`ctr.done_${reasonFor.action}`), () => setReasonFor(null))
  }

  const current = rows?.find((c) => c.status === 'active') ?? rows?.at(-1) ?? null
  const canAddenda = can('contracts.addenda.create')
  const canSalary = can('people.employments.salary.edit')
  const footer = (onCancel: () => void, onOk: () => void, label: string, danger = false) => (
    <>
      <button type="button" className="btn btn-sec" onClick={onCancel}>{t('org.cancel')}</button>
      <button type="button" className={danger ? 'btn btn-danger' : 'btn btn-primary'} disabled={busy} aria-busy={busy} onClick={onOk}>{label}</button>
    </>
  )
  const typeSelect = (id: string, value: string, onChange: (v: string) => void, optional: boolean) => (
    <select id={id} className="form-select" value={value} onChange={(e) => onChange(e.target.value)}>
      {optional && <option value="">{t('ctr.same_type')}</option>}
      {types.map((ty) => <option key={ty.id} value={ty.id}>{ty.name}</option>)}
    </select>
  )

  return (
    <Card title={t('ctr.title')} context={current ? t('ctr.context_current', { type: current.contract_type_name ?? '—', status: t(`ctr.status_${current.status}`) }) : t('ctr.context')}>
      {failed && <Notice tone="error">{t('common.load_error')}</Notice>}
      {warnings.length > 0 && <Notice tone="warn">{warnings.join(' ')}</Notice>}
      {rows !== null && rows.length === 0 && <p className="text-secondary">{t('ctr.empty')}</p>}
      {rows !== null && rows.length > 0 && (
        <ul className="sec-lista">
          {rows.map((c) => (
            <li key={c.id}>
              <div className="sec-fila">
                <div>
                  <b>{c.contract_type_name ?? '—'}</b>{c.number && <span className="small text-secondary">{` · ${c.number}`}</span>}
                  <span className="text-secondary small">{t('ctr.validity', { from: date(c.starts_on), to: c.ends_on ? date(c.ends_on) : t('ctr.indefinite') })}</span>
                  {c.trial_period_ends_on && <span className="text-secondary small">{t('ctr.trial', { date: date(c.trial_period_ends_on) })}</span>}
                  {c.weekly_hours !== null && <span className="text-secondary small">{t('ctr.hours', { hours: String(c.weekly_hours) })}{c.work_modality ? ` · ${t(`ctr.mod_${c.work_modality}`)}` : ''}</span>}
                  {c.renewal_of_contract_id && <span className="text-secondary small">{t('ctr.is_renewal')}</span>}
                  {c.signed_at && <span className="text-secondary small">{t('ctr.signed_on', { date: date(c.signed_at.slice(0, 10)), origin: t(`ctr.origin_${c.signature_origin ?? 'physical_scan'}`) })}</span>}
                  {c.accumulated_days !== null && c.accumulated_days > 0 && <span className="text-secondary small">{t('ctr.accumulated', { days: formatNumber(locale, String(c.accumulated_days)) })}</span>}
                  {c.status === 'expired' && <span className="small text-danger">{t('ctr.expired_hint')}</span>}
                  {c.over_limit_reason && <span className="small text-warning">{t('ctr.over_limit_note', { reason: c.over_limit_reason })}</span>}
                  {c.termination_reason && <span className="text-secondary small">{t('ctr.reason', { reason: c.termination_reason })}</span>}
                </div>
                <div className="sec-acciones">
                  <StatusBadge tone={TONE[c.status]}>{t(`ctr.status_${c.status}`)}</StatusBadge>
                  {c.has_document && <button type="button" className="btn btn-ter btn-sm" onClick={() => void download(`/contracts/${c.id}/document`)}>{t('ctr.download')}</button>}
                  {contractButtons(c, can).map((a) => (
                    <button key={a} type="button" className={a === 'approve' || a === 'submit' || a === 'register-signature' ? 'btn btn-primary btn-sm' : 'btn btn-ter btn-sm'} disabled={busy} onClick={() => act(c, a)}>
                      {t(`ctr.act_${a}`)}
                    </button>
                  ))}
                  {canAddenda && c.status === 'active' && (
                    <button type="button" className="btn btn-sec btn-sm" onClick={() => { reset(); setAddendum({ contract: c, form: { ...EMPTY_ADD, kind: canSalary ? 'salary' : 'other' } }) }}>{t('ctr.add_new')}</button>
                  )}
                </div>
              </div>
              {(c.addenda ?? []).length > 0 && (
                <ul className="sec-lista ms-4">
                  {(c.addenda ?? []).map((a) => (
                    <li key={a.id}>
                      <div className="sec-fila">
                        <div>
                          <b>{t(`ctr.kind_${a.kind}`)}</b>{a.number && <span className="small text-secondary">{` · ${a.number}`}</span>}
                          <span className="text-secondary small">{t('ctr.add_effective', { date: date(a.effective_on) })}{a.description ? ` · ${a.description}` : ''}</span>
                          {a.salary_change && <span className="text-secondary small">{t('ctr.add_salary', { amount: formatNumber(locale, a.salary_change.amount), currency: a.salary_change.currency })}</span>}
                          {a.cancellation_reason && <span className="text-secondary small">{t('ctr.reason', { reason: a.cancellation_reason })}</span>}
                        </div>
                        <div className="sec-acciones">
                          <StatusBadge tone={ADD_TONE[a.status]}>{t(`ctr.add_status_${a.status}`)}</StatusBadge>
                          {a.has_document && <button type="button" className="btn btn-ter btn-sm" onClick={() => void download(`/contract-addenda/${a.id}/document`)}>{t('ctr.download')}</button>}
                          {a.allowed_actions.filter((x) => ADD_PERMISSION[x] !== undefined && can(ADD_PERMISSION[x])).map((x) => (
                            <button key={x} type="button" className="btn btn-ter btn-sm" disabled={busy} onClick={() => actAddendum(a, x)}>{t(`ctr.add_act_${x}`)}</button>
                          ))}
                        </div>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ul>
      )}

      {edit && (
        <Modal title={t('ctr.edit_title')} onClose={() => setEdit(null)} footer={footer(() => setEdit(null), saveEdit, t('ctr.save'))}>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="ctr-type">{t('ctr.f_type')}</label>
          {types.length > 0 ? typeSelect('ctr-type', edit.form.contract_type_id, (v) => setEdit({ ...edit, form: { ...edit.form, contract_type_id: v } }), false)
            : <input id="ctr-type" className="form-control" disabled value={edit.contract.contract_type_name ?? ''} />}
          {err('contract_type_id')}
          <div className="sec-dos">
            <div>
              <label className="form-label mt-3" htmlFor="ctr-start">{t('ctr.f_starts')}</label>
              <input id="ctr-start" type="date" className="form-control" value={edit.form.starts_on} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, starts_on: e.target.value } })} />
              {err('starts_on')}
            </div>
            <div>
              <label className="form-label mt-3" htmlFor="ctr-end">{t('ctr.f_ends')}</label>
              <input id="ctr-end" type="date" className="form-control" value={edit.form.ends_on} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, ends_on: e.target.value } })} />
              {err('ends_on')}
            </div>
          </div>
          <label className="form-label mt-3" htmlFor="ctr-cause">{t('ctr.f_cause')}</label>
          <textarea id="ctr-cause" className="form-control" rows={2} value={edit.form.objective_cause} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, objective_cause: e.target.value } })} />
          {err('objective_cause')}
          <div className="sec-dos">
            <div>
              <label className="form-label mt-3" htmlFor="ctr-trial">{t('ctr.f_trial')}</label>
              <input id="ctr-trial" type="date" className="form-control" value={edit.form.trial_period_ends_on} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, trial_period_ends_on: e.target.value } })} />
              {err('trial_period_ends_on')}
            </div>
            <div>
              <label className="form-label mt-3" htmlFor="ctr-hours">{t('ctr.f_hours')}</label>
              <input id="ctr-hours" type="number" min={0} className="form-control" value={edit.form.weekly_hours} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, weekly_hours: e.target.value } })} />
              {err('weekly_hours')}
            </div>
          </div>
          <label className="form-label mt-3" htmlFor="ctr-mod">{t('ctr.f_modality')}</label>
          <select id="ctr-mod" className="form-select" value={edit.form.work_modality} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, work_modality: e.target.value } })}>
            {(['onsite', 'remote', 'hybrid'] as const).map((m) => <option key={m} value={m}>{t(`ctr.mod_${m}`)}</option>)}
          </select>
          <p className="small text-secondary mt-2">{t('ctr.edit_hint')}</p>
        </Modal>
      )}

      {renew && (
        <Modal title={t('ctr.renew_title')} onClose={() => setRenew(null)} footer={footer(() => setRenew(null), saveRenew, t('ctr.act_renew'))}>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="ctr-r-type">{t('ctr.f_type')}</label>
          {typeSelect('ctr-r-type', renew.form.contract_type_id, (v) => setRenew({ ...renew, form: { ...renew.form, contract_type_id: v } }), true)}
          {err('contract_type_id')}
          <div className="sec-dos">
            <div>
              <label className="form-label mt-3" htmlFor="ctr-r-start">{t('ctr.f_starts')}</label>
              <input id="ctr-r-start" type="date" className="form-control" value={renew.form.starts_on} onChange={(e) => setRenew({ ...renew, form: { ...renew.form, starts_on: e.target.value } })} />
              {err('starts_on')}
            </div>
            <div>
              <label className="form-label mt-3" htmlFor="ctr-r-end">{t('ctr.f_ends')}</label>
              <input id="ctr-r-end" type="date" className="form-control" value={renew.form.ends_on} onChange={(e) => setRenew({ ...renew, form: { ...renew.form, ends_on: e.target.value } })} />
              {err('ends_on')}
            </div>
          </div>
          <label className="form-label mt-3" htmlFor="ctr-r-cause">{t('ctr.f_cause')}</label>
          <textarea id="ctr-r-cause" className="form-control" rows={2} value={renew.form.objective_cause} onChange={(e) => setRenew({ ...renew, form: { ...renew.form, objective_cause: e.target.value } })} />
          <p className="small text-secondary mt-2">{t('ctr.renew_hint')}</p>
          {overLimit && (
            <div className="mt-3">
              <Notice tone="warn">{overLimit.explanation}</Notice>
              <div className="form-check mt-2">
                <input id="ctr-r-over" type="checkbox" className="form-check-input" checked={overLimit.confirmed} onChange={(e) => setOverLimit({ ...overLimit, confirmed: e.target.checked })} />
                <label className="form-check-label" htmlFor="ctr-r-over">{t('ctr.over_limit_confirm')}</label>
              </div>
              <label className="form-label mt-2" htmlFor="ctr-r-over-reason">{t('ctr.over_limit_reason')}</label>
              <textarea id="ctr-r-over-reason" className="form-control" rows={2} value={overLimit.reason} onChange={(e) => setOverLimit({ ...overLimit, reason: e.target.value })} />
              {err('over_limit_reason')}
            </div>
          )}
        </Modal>
      )}

      {addendum && (
        <Modal title={t('ctr.add_title')} onClose={() => setAddendum(null)} footer={footer(() => setAddendum(null), saveAddendum, t('ctr.save'))}>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="ctr-a-kind">{t('ctr.f_kind')}</label>
          <select id="ctr-a-kind" className="form-select" value={addendum.form.kind} onChange={(e) => setAddendum({ ...addendum, form: { ...addendum.form, kind: e.target.value } })}>
            {ADDENDUM_KINDS.filter((k) => k !== 'salary' || canSalary).map((k) => <option key={k} value={k}>{t(`ctr.kind_${k}`)}</option>)}
          </select>
          {err('kind')}
          <label className="form-label mt-3" htmlFor="ctr-a-from">{t('ctr.f_effective')}</label>
          <input id="ctr-a-from" type="date" className="form-control" value={addendum.form.effective_on} onChange={(e) => setAddendum({ ...addendum, form: { ...addendum.form, effective_on: e.target.value } })} />
          {err('effective_on')}
          {addendum.form.kind === 'salary' && (
            <div className="sec-dos">
              <div>
                <label className="form-label mt-3" htmlFor="ctr-a-amount">{t('ctr.f_salary')}</label>
                <input id="ctr-a-amount" inputMode="decimal" className="form-control" value={addendum.form.amount} onChange={(e) => setAddendum({ ...addendum, form: { ...addendum.form, amount: e.target.value } })} />
                {err('changes.base_salary')}
              </div>
              <div>
                <label className="form-label mt-3" htmlFor="ctr-a-cur">{t('ctr.f_currency')}</label>
                <select id="ctr-a-cur" className="form-select" value={addendum.form.currency} onChange={(e) => setAddendum({ ...addendum, form: { ...addendum.form, currency: e.target.value } })}>
                  <option value="PEN">{t('bank.cur_PEN')}</option>
                  <option value="USD">{t('bank.cur_USD')}</option>
                </select>
              </div>
            </div>
          )}
          {addendum.form.kind === 'schedule' && (
            <>
              <label className="form-label mt-3" htmlFor="ctr-a-hours">{t('ctr.f_hours')}</label>
              <input id="ctr-a-hours" type="number" min={0} className="form-control" value={addendum.form.weekly_hours} onChange={(e) => setAddendum({ ...addendum, form: { ...addendum.form, weekly_hours: e.target.value } })} />
              {err('changes.weekly_hours')}
            </>
          )}
          {addendum.form.kind === 'modality' && (
            <>
              <label className="form-label mt-3" htmlFor="ctr-a-mod">{t('ctr.f_modality')}</label>
              <select id="ctr-a-mod" className="form-select" value={addendum.form.work_modality} onChange={(e) => setAddendum({ ...addendum, form: { ...addendum.form, work_modality: e.target.value } })}>
                {(['onsite', 'remote', 'hybrid'] as const).map((m) => <option key={m} value={m}>{t(`ctr.mod_${m}`)}</option>)}
              </select>
            </>
          )}
          {addendum.form.kind === 'extension' && (
            <>
              <label className="form-label mt-3" htmlFor="ctr-a-end">{t('ctr.f_ends')}</label>
              <input id="ctr-a-end" type="date" className="form-control" value={addendum.form.ends_on} onChange={(e) => setAddendum({ ...addendum, form: { ...addendum.form, ends_on: e.target.value } })} />
              {err('changes.ends_on')}
            </>
          )}
          <label className="form-label mt-3" htmlFor="ctr-a-desc">{t('ctr.f_description')}</label>
          <textarea id="ctr-a-desc" className="form-control" rows={2} value={addendum.form.description} onChange={(e) => setAddendum({ ...addendum, form: { ...addendum.form, description: e.target.value } })} />
          {err('description')}
          <p className="small text-secondary mt-2">{t('ctr.add_hint')}</p>
        </Modal>
      )}

      {sign && (
        <Modal title={t('ctr.sign_title')} onClose={() => setSign(null)} footer={footer(() => setSign(null), saveSignature, t('ctr.act_register-signature'))}>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="ctr-s-file">{t('ctr.f_file')}</label>
          <input id="ctr-s-file" type="file" accept="application/pdf,image/jpeg,image/png" className="form-control" onChange={(e) => setSignFile(e.target.files?.[0] ?? null)} />
          {err('file')}
          <label className="form-label mt-3" htmlFor="ctr-s-date">{t('ctr.f_signed_on')}</label>
          <input id="ctr-s-date" type="date" className="form-control" value={signedOn} onChange={(e) => setSignedOn(e.target.value)} />
          {err('signed_on')}
          {!sign.fromDraft && (
            <>
              <label className="form-label mt-3" htmlFor="ctr-s-origin">{t('ctr.f_origin')}</label>
              <select id="ctr-s-origin" className="form-select" value={origin} onChange={(e) => setOrigin(e.target.value as 'physical_scan' | 'electronic')}>
                <option value="physical_scan">{t('ctr.origin_physical_scan')}</option>
                <option value="electronic">{t('ctr.origin_electronic')}</option>
              </select>
              {err('origin')}
            </>
          )}
          <p className="small text-secondary mt-2">{sign.fromDraft ? t('ctr.sign_hint_draft') : t('ctr.sign_hint')}</p>
        </Modal>
      )}

      {reasonFor && (
        <Modal title={t(`ctr.reason_title_${reasonFor.action}`)} onClose={() => setReasonFor(null)} tone={reasonFor.action === 'void' || reasonFor.action === 'terminate' ? 'danger' : 'normal'}
          footer={footer(() => setReasonFor(null), saveReason, t(reasonFor.kind === 'addendum' ? 'ctr.add_act_cancel' : `ctr.act_${reasonFor.action}`), reasonFor.action !== 'return')}>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="ctr-reason">{t('ctr.f_reason')}</label>
          <textarea id="ctr-reason" className="form-control" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
          {err('reason')}
          {reasonFor.action === 'terminate' && (
            <>
              <label className="form-label mt-3" htmlFor="ctr-end-on">{t('ctr.f_end_on')}</label>
              <input id="ctr-end-on" type="date" className="form-control" value={endOn} onChange={(e) => setEndOn(e.target.value)} />
              {err('effective_on')}
            </>
          )}
        </Modal>
      )}
    </Card>
  )
}
