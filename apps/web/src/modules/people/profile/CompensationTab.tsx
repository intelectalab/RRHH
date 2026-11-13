'use client'

import { Fragment, useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, formatNumber, localToday } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { StatusBadge, type StatusTone } from '@/ui/components/Status'
import type { ProfileTabContext } from './types'

type Money = { amount: string; currency: string }
type Component = { concept_code: string; concept_name: string; amount: Money }
type Compensation = {
  id: string
  base_salary?: Money
  pay_frequency: string
  salary_basis: string
  is_integral_salary: boolean
  change_reason: string | null
  components?: Component[]
  valid_from: string
  valid_to: string | null
}
type Change = {
  id: string
  base_salary?: Money
  previous_salary?: Money
  effective_on: string
  reason: string
  notes: string | null
  status: 'pending_approval' | 'applied' | 'rejected' | 'cancelled'
  band_position: 'below' | 'within' | 'above' | 'none'
  decision_reason: string | null
  requested_by_name: string | null
  allowed_actions: string[]
}
type Concept = { code: string; name: string }
type Form = { amount: string; currency: string; pay_frequency: string; salary_basis: string; effective_on: string; reason: string; notes: string; components: Array<{ concept_code: string; amount: string }> }

const REASONS = ['merit', 'promotion', 'adjustment', 'legal', 'review_cycle', 'correction'] as const
const FREQUENCIES = ['monthly', 'biweekly', 'weekly', 'daily', 'hourly'] as const
const BASES = ['monthly', 'daily', 'hourly'] as const
const TONE: Record<Change['status'], StatusTone> = { pending_approval: 'wa', applied: 'ok', rejected: 'er', cancelled: 'gr' }

/**
 * HIS-3 · Pestaña Compensación de la ficha 360: sueldo vigente e historia con vigencia, cambios pedidos y
 * su estado de aprobación, y el formulario para pedir un cambio con fecha efectiva y motivo. Los montos los
 * envía la API solo con el permiso de campo de sueldo; la validación (mínimo legal, banda, fechas) la hace
 * el servidor y aquí solo se muestran sus avisos.
 */
export function CompensationTab({ header, toast }: ProfileTabContext) {
  const { t, locale } = useT()
  const { can } = useSession()
  const base = `/employments/${header.id}`
  const editable = can('people.compensations.create') && can('people.employments.salary.edit')
  const [history, setHistory] = useState<Compensation[] | null>(null)
  const [changes, setChanges] = useState<Change[]>([])
  const [failed, setFailed] = useState(false)
  const [concepts, setConcepts] = useState<Concept[]>([])
  const [form, setForm] = useState<Form | null>(null)
  const [cancelling, setCancelling] = useState<Change | null>(null)
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [warnings, setWarnings] = useState<string[]>([])
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const [h, c] = await Promise.all([
        api.get<{ data: Compensation[] }>(`${base}/compensations`),
        api.get<{ data: Change[] }>(`${base}/compensation-changes`),
      ])
      setHistory(h.data)
      setChanges(c.data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [base])

  useEffect(() => { void load() }, [load])

  const money = (m?: Money | null) => (m ? `${m.currency} ${formatNumber(locale, m.amount)}` : '—')
  const date = (v: string | null) => (v ? formatDate(locale, v) : t('comp.open_ended'))
  const current = history?.[0]
  const reset = () => { setErrors({}); setGeneral(null) }

  async function openForm() {
    reset(); setWarnings([])
    if (concepts.length === 0) {
      try { setConcepts((await api.get<{ data: Concept[] }>('/compensation-concepts')).data) } catch { /* el servidor valida el concepto */ }
    }
    setForm({
      amount: '', currency: current?.base_salary?.currency ?? 'PEN', pay_frequency: current?.pay_frequency ?? 'monthly',
      salary_basis: current?.salary_basis ?? 'monthly', effective_on: localToday(), reason: 'merit', notes: '',
      components: (current?.components ?? []).map((c) => ({ concept_code: c.concept_code, amount: c.amount.amount })),
    })
  }

  async function save() {
    if (!form) return
    setBusy(true); reset()
    try {
      const res = await api.post<{ data: Change; meta?: { warnings?: Array<{ message: string }> } }>(`${base}/compensation-changes`, {
        base_salary: { amount: form.amount, currency: form.currency },
        pay_frequency: form.pay_frequency,
        salary_basis: form.salary_basis,
        effective_on: form.effective_on,
        reason: form.reason,
        notes: form.notes || null,
        components: form.components.filter((c) => c.concept_code !== '').map((c) => ({ concept_code: c.concept_code, amount: { amount: c.amount, currency: form.currency } })),
      })
      setForm(null)
      setWarnings((res.meta?.warnings ?? []).map((w) => w.message))
      toast(res.data.status === 'applied' ? t('comp.applied') : t('comp.requested'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields); setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  async function cancel() {
    if (!cancelling) return
    setBusy(true); reset()
    try {
      await api.post(`${base}/compensation-changes/${cancelling.id}/cancel`, { reason })
      toast(t('comp.cancelled'))
      setCancelling(null)
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields); setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  const err = (k: string) => errors[k] && <div className="invalid-feedback d-block" role="alert">{errors[k]}</div>
  const set = (patch: Partial<Form>) => form && setForm({ ...form, ...patch })
  const setComponent = (i: number, patch: Partial<Form['components'][number]>) => form && setForm({ ...form, components: form.components.map((c, j) => (j === i ? { ...c, ...patch } : c)) })

  return (
    <div className="emp-secciones">
      {failed && <Notice tone="error">{t('common.load_error')}</Notice>}
      {warnings.length > 0 && <Notice tone="warn">{warnings.join(' ')}</Notice>}

      <Card
        title={t('comp.current_title')}
        context={t('comp.current_context')}
        actions={editable ? <button type="button" className="btn btn-sec btn-sm" onClick={() => void openForm()}>{t('comp.add')}</button> : undefined}
      >
        {history !== null && !current && <p className="text-secondary">{t('comp.empty')}</p>}
        {current && (
          <dl className="ficha">
            <dt>{t('comp.f_salary')}</dt><dd><b>{money(current.base_salary)}</b></dd>
            <dt>{t('comp.f_frequency')}</dt><dd>{t(`comp.freq_${current.pay_frequency}`)} · {t(`comp.basis_${current.salary_basis}`)}</dd>
            <dt>{t('comp.f_since')}</dt><dd>{formatDate(locale, current.valid_from)}</dd>
            {(current.components ?? []).map((c) => (
              <Fragment key={c.concept_code}><dt>{c.concept_name}</dt><dd>{money(c.amount)}</dd></Fragment>
            ))}
          </dl>
        )}
      </Card>

      {changes.length > 0 && (
        <Card title={t('comp.changes_title')}>
          <ul className="sec-lista">
            {changes.map((c) => (
              <li key={c.id}>
                <div className="sec-fila">
                  <div>
                    <b>{money(c.base_salary)}</b>
                    <span className="text-secondary small">{t('comp.change_line', { reason: t(`comp.reason_${c.reason}`), date: formatDate(locale, c.effective_on), previous: money(c.previous_salary) })}</span>
                    {c.band_position !== 'none' && <span className="small text-secondary">{t(`comp.band_${c.band_position}`)}</span>}
                    {c.requested_by_name && <span className="small text-secondary">{t('comp.requested_by', { name: c.requested_by_name })}</span>}
                    {c.decision_reason && <span className="small text-secondary">{t('comp.decision', { reason: c.decision_reason })}</span>}
                  </div>
                  <div className="sec-acciones">
                    <StatusBadge tone={TONE[c.status]}>{t(`comp.status_${c.status}`)}</StatusBadge>
                    {c.allowed_actions.includes('cancel') && can('people.compensations.cancel') && (
                      <button type="button" className="btn btn-ter btn-sm" onClick={() => { reset(); setReason(''); setCancelling(c) }}>{t('comp.cancel')}</button>
                    )}
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {history !== null && history.length > 0 && (
        <Card title={t('comp.history_title')}>
          <ul className="sec-lista">
            {history.map((h) => (
              <li key={h.id}>
                <div className="sec-fila">
                  <div>
                    <b>{money(h.base_salary)}</b>
                    <span className="text-secondary small">{t('comp.validity', { from: formatDate(locale, h.valid_from), to: date(h.valid_to) })}</span>
                  </div>
                  <div className="sec-acciones">{h.change_reason && <span className="small text-secondary">{t(`comp.reason_${h.change_reason}`)}</span>}</div>
                </div>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {form && (
        <Modal
          title={t('comp.add_title')}
          onClose={() => setForm(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setForm(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void save()}>{t('comp.submit')}</button>
            </>
          )}
        >
          {general && <Notice tone="error">{general}</Notice>}
          <div className="sec-dos">
            <div>
              <label className="form-label" htmlFor="comp-amount">{t('comp.f_salary')}</label>
              <input id="comp-amount" className="form-control" inputMode="decimal" autoComplete="off" value={form.amount} onChange={(e) => set({ amount: e.target.value })} />
              {err('base_salary.amount')}
            </div>
            <div>
              <label className="form-label" htmlFor="comp-currency">{t('comp.f_currency')}</label>
              <select id="comp-currency" className="form-select" value={form.currency} onChange={(e) => set({ currency: e.target.value })}>
                <option value="PEN">{t('bank.cur_PEN')}</option>
                <option value="USD">{t('bank.cur_USD')}</option>
              </select>
              {err('base_salary.currency')}
            </div>
          </div>
          <div className="sec-dos">
            <div>
              <label className="form-label mt-3" htmlFor="comp-frequency">{t('comp.f_frequency')}</label>
              <select id="comp-frequency" className="form-select" value={form.pay_frequency} onChange={(e) => set({ pay_frequency: e.target.value })}>
                {FREQUENCIES.map((f) => <option key={f} value={f}>{t(`comp.freq_${f}`)}</option>)}
              </select>
            </div>
            <div>
              <label className="form-label mt-3" htmlFor="comp-basis">{t('comp.f_basis')}</label>
              <select id="comp-basis" className="form-select" value={form.salary_basis} onChange={(e) => set({ salary_basis: e.target.value })}>
                {BASES.map((b) => <option key={b} value={b}>{t(`comp.basis_${b}`)}</option>)}
              </select>
            </div>
          </div>
          <div className="sec-dos">
            <div>
              <label className="form-label mt-3" htmlFor="comp-date">{t('comp.f_effective')}</label>
              <input id="comp-date" type="date" className="form-control" value={form.effective_on} onChange={(e) => set({ effective_on: e.target.value })} />
              {err('effective_on')}
            </div>
            <div>
              <label className="form-label mt-3" htmlFor="comp-reason">{t('comp.f_reason')}</label>
              <select id="comp-reason" className="form-select" value={form.reason} onChange={(e) => set({ reason: e.target.value })}>
                {REASONS.map((r) => <option key={r} value={r}>{t(`comp.reason_${r}`)}</option>)}
              </select>
              {err('reason')}
            </div>
          </div>
          <fieldset className="mt-3">
            <legend className="form-label">{t('comp.f_components')}</legend>
            {form.components.map((c, i) => (
              <div className="sec-dos" key={i}>
                <select aria-label={t('comp.f_concept')} className="form-select" value={c.concept_code} onChange={(e) => setComponent(i, { concept_code: e.target.value })}>
                  <option value="">{t('ssec.choose')}</option>
                  {concepts.map((k) => <option key={k.code} value={k.code}>{k.name}</option>)}
                </select>
                <input aria-label={t('comp.f_component_amount')} className="form-control" inputMode="decimal" value={c.amount} onChange={(e) => setComponent(i, { amount: e.target.value })} />
              </div>
            ))}
            {err('components')}
            <button type="button" className="btn btn-ter btn-sm mt-2" onClick={() => set({ components: [...form.components, { concept_code: '', amount: '' }] })}>{t('comp.add_component')}</button>
          </fieldset>
          <label className="form-label mt-3" htmlFor="comp-notes">{t('comp.f_notes')}</label>
          <textarea id="comp-notes" className="form-control" rows={2} value={form.notes} onChange={(e) => set({ notes: e.target.value })} />
          <p className="small text-secondary mt-2">{t('comp.approval_hint')}</p>
        </Modal>
      )}

      {cancelling && (
        <Modal
          title={t('comp.cancel_title')}
          onClose={() => setCancelling(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setCancelling(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-danger" disabled={busy} aria-busy={busy} onClick={() => void cancel()}>{t('comp.cancel')}</button>
            </>
          )}
        >
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="comp-cancel-reason">{t('comp.f_cancel_reason')}</label>
          <textarea id="comp-cancel-reason" className="form-control" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
          {err('reason')}
        </Modal>
      )}
    </div>
  )
}
