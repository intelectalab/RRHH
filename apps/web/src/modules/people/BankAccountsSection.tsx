'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { StatusBadge, type StatusTone } from '@/ui/components/Status'
import type { PeopleSectionProps } from './sectionProps'

type Account = {
  id: string
  purpose: 'salary' | 'cts' | 'other'
  bank_code: string
  bank_name: string
  account_type: 'savings' | 'checking' | 'wallet'
  currency: string
  account_number?: string
  account_number_masked: string
  interbank_code?: string
  interbank_code_masked?: string | null
  status: 'pending_approval' | 'active' | 'rejected' | 'cancelled'
  valid_from: string
  valid_to: string | null
  is_current: boolean
  requested_by_name: string | null
  decided_by_name: string | null
  decision_reason: string | null
}
type Bank = { code: string; label: string }
type Form = { purpose: string; bank_code: string; account_type: string; currency: string; account_number: string; interbank_code: string; valid_from: string }

const TONE: Record<Account['status'], StatusTone> = { pending_approval: 'wa', active: 'ok', rejected: 'er', cancelled: 'gr' }
const EMPTY: Form = { purpose: 'salary', bank_code: '', account_type: 'savings', currency: 'PEN', account_number: '', interbank_code: '', valid_from: '' }

/**
 * COL-8 · Cuentas de pago. Sin permiso de cuenta bancaria la API solo envía los últimos cuatro dígitos; un
 * cambio queda pendiente de aprobación por otra persona y el colaborador recibe aviso.
 */
export function BankAccountsSection({ employmentId, onToast }: PeopleSectionProps) {
  const { t, locale } = useT()
  const { can } = useSession()
  const base = `/employments/${employmentId}/bank-accounts`
  const visible = can('people.people.bank_account.view') || can('people.people.bank_account.edit') || can('people.change_requests.approve')
  const editable = can('people.people.bank_account.edit')
  const approver = can('people.change_requests.approve')
  const [rows, setRows] = useState<Account[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [banks, setBanks] = useState<Bank[]>([])
  const [form, setForm] = useState<Form | null>(null)
  const [deciding, setDeciding] = useState<{ account: Account; action: 'reject' | 'cancel' } | null>(null)
  const [reason, setReason] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [warnings, setWarnings] = useState<string[]>([])
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    if (!visible) return
    try {
      setRows((await api.get<{ data: Account[] }>(base)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [base, visible])

  useEffect(() => { void load() }, [load])

  if (!visible) return null

  const date = (v: string | null) => (v ? formatDate(locale, v) : '—')
  const reset = () => { setErrors({}); setGeneral(null) }

  async function openForm() {
    reset(); setWarnings([])
    if (banks.length === 0) {
      try { setBanks((await api.get<{ data: Bank[] }>('/catalogs/banks_pe/items')).data) } catch { /* el servidor valida el banco */ }
    }
    setForm(EMPTY)
  }

  async function save() {
    if (!form) return
    setBusy(true); reset()
    try {
      const body = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
      const res = await api.post<{ meta?: { warnings?: string[] } }>(base, body)
      setForm(null)
      const w = res.meta?.warnings ?? []
      setWarnings(w)
      onToast(t('bank.requested'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields); setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  async function approve(a: Account) {
    setBusy(true)
    try {
      await api.post(`${base}/${a.id}/approve`, {})
      onToast(t('bank.approved'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      onToast(f.general ?? Object.values(f.fields)[0] ?? t('auth.generic_error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  async function decide() {
    if (!deciding) return
    setBusy(true); reset()
    try {
      await api.post(`${base}/${deciding.account.id}/${deciding.action}`, { reason })
      onToast(deciding.action === 'reject' ? t('bank.rejected') : t('bank.cancelled'))
      setDeciding(null)
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

  return (
    <Card
      title={t('bank.title')}
      context={t('bank.context')}
      actions={editable ? <button type="button" className="btn btn-sec btn-sm" onClick={() => void openForm()}>{t('bank.add')}</button> : undefined}
    >
      {failed && <Notice tone="error">{t('common.load_error')}</Notice>}
      {warnings.length > 0 && <Notice tone="warn">{warnings.join(' ')}</Notice>}
      {rows !== null && rows.length === 0 && <p className="text-secondary">{t('bank.empty')}</p>}
      {rows !== null && rows.length > 0 && (
        <ul className="sec-lista">
          {rows.map((a) => (
            <li key={a.id}>
              <div className="sec-fila">
                <div>
                  <b>{a.bank_name}</b>
                  <span className="text-secondary small">{t(`bank.purpose_${a.purpose}`)} · {t(`bank.type_${a.account_type}`)} · {a.currency} · {a.account_number ?? a.account_number_masked}</span>
                  {(a.interbank_code ?? a.interbank_code_masked) && <span className="text-secondary small">{t('bank.cci', { value: a.interbank_code ?? a.interbank_code_masked ?? '' })}</span>}
                  <span className="text-secondary small">{t('bank.validity', { from: date(a.valid_from), to: date(a.valid_to) })}{a.requested_by_name ? ` · ${t('bank.requested_by', { name: a.requested_by_name })}` : ''}</span>
                  {a.decision_reason && <span className="small text-secondary">{t('bank.reason', { name: a.decided_by_name ?? '—', reason: a.decision_reason })}</span>}
                </div>
                <div className="sec-acciones">
                  <StatusBadge tone={TONE[a.status]}>{t(`bank.status_${a.status}`)}</StatusBadge>
                  {a.is_current && <span className="small text-secondary">{t('bank.current')}</span>}
                  {a.status === 'pending_approval' && approver && (
                    <>
                      <button type="button" className="btn btn-primary btn-sm" disabled={busy} onClick={() => void approve(a)}>{t('bank.approve')}</button>
                      <button type="button" className="btn btn-ter btn-sm" onClick={() => { reset(); setReason(''); setDeciding({ account: a, action: 'reject' }) }}>{t('bank.reject')}</button>
                    </>
                  )}
                  {a.status === 'pending_approval' && editable && (
                    <button type="button" className="btn btn-ter btn-sm" onClick={() => { reset(); setReason(''); setDeciding({ account: a, action: 'cancel' }) }}>{t('bank.cancel')}</button>
                  )}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      {form && (
        <Modal
          title={t('bank.add_title')}
          onClose={() => setForm(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setForm(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void save()}>{t('bank.submit')}</button>
            </>
          )}
        >
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="bank-purpose">{t('bank.f_purpose')}</label>
          <select id="bank-purpose" className="form-select" value={form.purpose} onChange={(e) => set({ purpose: e.target.value })}>
            {(['salary', 'cts', 'other'] as const).map((p) => <option key={p} value={p}>{t(`bank.purpose_${p}`)}</option>)}
          </select>
          {err('purpose')}
          <label className="form-label mt-3" htmlFor="bank-bank">{t('bank.f_bank')}</label>
          <select id="bank-bank" className="form-select" value={form.bank_code} onChange={(e) => set({ bank_code: e.target.value })}>
            <option value="">{t('ssec.choose')}</option>
            {banks.map((b) => <option key={b.code} value={b.code}>{b.label}</option>)}
          </select>
          {err('bank_code')}
          <div className="sec-dos">
            <div>
              <label className="form-label mt-3" htmlFor="bank-type">{t('bank.f_type')}</label>
              <select id="bank-type" className="form-select" value={form.account_type} onChange={(e) => set({ account_type: e.target.value })}>
                {(['savings', 'checking', 'wallet'] as const).map((p) => <option key={p} value={p}>{t(`bank.type_${p}`)}</option>)}
              </select>
            </div>
            <div>
              <label className="form-label mt-3" htmlFor="bank-currency">{t('bank.f_currency')}</label>
              <select id="bank-currency" className="form-select" value={form.currency} onChange={(e) => set({ currency: e.target.value })}>
                <option value="PEN">{t('bank.cur_PEN')}</option>
                <option value="USD">{t('bank.cur_USD')}</option>
              </select>
              {err('currency')}
            </div>
          </div>
          <label className="form-label mt-3" htmlFor="bank-number">{t('bank.f_number')}</label>
          <input id="bank-number" className="form-control" inputMode="numeric" autoComplete="off" value={form.account_number} onChange={(e) => set({ account_number: e.target.value })} />
          {err('account_number')}
          <label className="form-label mt-3" htmlFor="bank-cci">{t('bank.f_cci')}</label>
          <input id="bank-cci" className="form-control" inputMode="numeric" autoComplete="off" value={form.interbank_code} onChange={(e) => set({ interbank_code: e.target.value })} />
          {err('interbank_code')}
          <label className="form-label mt-3" htmlFor="bank-from">{t('bank.f_from')}</label>
          <input id="bank-from" type="date" className="form-control" value={form.valid_from} onChange={(e) => set({ valid_from: e.target.value })} />
          {err('valid_from')}
          <p className="small text-secondary mt-2">{t('bank.approval_hint')}</p>
        </Modal>
      )}

      {deciding && (
        <Modal
          title={deciding.action === 'reject' ? t('bank.reject_title') : t('bank.cancel_title')}
          onClose={() => setDeciding(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setDeciding(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-danger" disabled={busy} aria-busy={busy} onClick={() => void decide()}>{deciding.action === 'reject' ? t('bank.reject') : t('bank.cancel')}</button>
            </>
          )}
        >
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="bank-reason">{t('bank.f_reason')}</label>
          <textarea id="bank-reason" className="form-control" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
          {err('reason')}
        </Modal>
      )}
    </Card>
  )
}
