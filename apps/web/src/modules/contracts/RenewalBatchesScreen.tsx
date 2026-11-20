'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'

type Batch = {
  id: string
  name: string
  status: 'draft' | 'sent_to_signature' | 'completed' | 'cancelled'
  new_end_date: string
  total: number
  to_renew: number
  excluded: number
  created_at: string
  allowed_actions: string[]
  items?: Item[]
}

type Item = {
  id: string
  contract_id: string
  contract_number: string | null
  employee_name: string
  employee_code: string | null
  current_end: string | null
  decision: 'renew' | 'not_renew' | 'convert_indefinite' | 'pending'
  exceeds_limit: boolean
  notes: string | null
  new_contract_status: string | null
  signature: { status: string; signed: number; total: number } | null
}

type Expiring = { id: string; person_name?: string; employee_code?: string; ends_on?: string; days_left: number; number?: string }

const TONES: Record<Batch['status'], 'ok' | 'wa' | 'in' | 'gr'> = { draft: 'gr', sent_to_signature: 'in', completed: 'ok', cancelled: 'gr' }

/**
 * Personas → Renovaciones (CON-5): lote de contratos por vencer con su nueva fecha de fin. La API revisa el tope
 * acumulado de cada uno (el que lo supera se excluye y se explica), genera las renovaciones y las envía a firma.
 */
export function RenewalBatchesScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<Batch[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [open, setOpen] = useState<Batch | null>(null)
  const [creating, setCreating] = useState(false)

  const load = useCallback(() => {
    setFailed(false)
    api.get<{ data: Batch[] }>('/contract-renewal-batches?per_page=50').then((r) => setRows(r.data)).catch(() => setFailed(true))
  }, [])
  useEffect(load, [load])

  const show = (id: string) => void api.get<{ data: Batch }>(`/contract-renewal-batches/${id}`).then((r) => setOpen(r.data))

  return (
    <>
      <PageHeader title={t('renewals.title')} sub={t('renewals.sub')}
        actions={can('contracts.contracts.bulk_renew') ? <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>{t('renewals.new')}</button> : undefined} />
      <main className="main" id="contenido" tabIndex={-1}>
        {failed ? <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={load} />
          : !rows ? <span className="esq" style={{ width: 320 }} />
            : rows.length === 0 ? <EmptyState title={t('renewals.empty_title')} text={t('renewals.empty_text')} />
              : (
                <section className="card">
                  <table className="table">
                    <thead><tr><th>{t('renewals.name')}</th><th>{t('renewals.new_end')}</th><th>{t('renewals.contracts')}</th><th>{t('renewals.state')}</th></tr></thead>
                    <tbody>
                      {rows.map((b) => (
                        <tr key={b.id}>
                          <td><button type="button" className="btn-link" onClick={() => show(b.id)}>{b.name}</button></td>
                          <td>{formatDate(locale, b.new_end_date)}</td>
                          <td>{t('renewals.counts', { renew: b.to_renew, excluded: b.excluded, total: b.total })}</td>
                          <td><StatusBadge tone={TONES[b.status]}>{t(`renewals.status_${b.status}`)}</StatusBadge></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </section>
              )}
      </main>
      {creating && <CreatePanel onClose={() => setCreating(false)} onCreated={(b) => { setCreating(false); load(); setOpen(b); toast(t('renewals.created')) }} />}
      {open && <BatchPanel batch={open} onChange={(b) => { setOpen(b); load() }} onClose={() => setOpen(null)} />}
      {toastNode}
    </>
  )
}

function CreatePanel({ onClose, onCreated }: { onClose: () => void; onCreated: (b: Batch) => void }) {
  const { t, locale } = useT()
  const [contracts, setContracts] = useState<Expiring[] | null>(null)
  const [selected, setSelected] = useState<string[]>([])
  const [name, setName] = useState('')
  const [endDate, setEndDate] = useState('')
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    void api.get<{ data: Expiring[] }>('/contracts/expiring?per_page=100').then((r) => setContracts(r.data)).catch(() => setContracts([]))
  }, [])

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      onCreated((await api.post<{ data: Batch }>('/contract-renewal-batches', { name: name || null, new_end_date: endDate, contract_ids: selected })).data)
    } catch (err) {
      const f = formErrors(err, t)
      setGeneral(f.general ?? Object.values(f.fields)[0] ?? null)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel wide title={t('renewals.new')} onClose={onClose} closeLabel={t('common.close')} footer={
      <>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('common.cancel')}</button>
        <button type="submit" form="rb-new" className="btn btn-primary" disabled={busy || !endDate || selected.length === 0}>{t('renewals.review')}</button>
      </>
    }>
      <form id="rb-new" onSubmit={(e) => void submit(e)} noValidate>
        {general && <div className="alert alert-danger" role="alert">{general}</div>}
        <label className="form-label" htmlFor="rb-name">{t('renewals.name')}</label>
        <input id="rb-name" className="form-control" maxLength={120} value={name} onChange={(e) => setName(e.target.value)} />
        <label className="form-label mt-3" htmlFor="rb-end">{t('renewals.new_end')}</label>
        <input id="rb-end" type="date" className="form-control" required value={endDate} onChange={(e) => setEndDate(e.target.value)} />
        <h3 className="h6 mt-3">{t('renewals.pick')}</h3>
        {!contracts ? <span className="esq" style={{ width: 240 }} /> : contracts.length === 0 ? <p className="small">{t('renewals.none_expiring')}</p> : (
          <ul className="list-unstyled">
            {contracts.map((c) => (
              <li key={c.id} className="form-check">
                <input id={`rb-${c.id}`} type="checkbox" className="form-check-input" checked={selected.includes(c.id)}
                  onChange={(e) => setSelected(e.target.checked ? [...selected, c.id] : selected.filter((x) => x !== c.id))} />
                <label className="form-check-label" htmlFor={`rb-${c.id}`}>
                  {c.person_name ?? '—'} <span className="sub2">{c.employee_code} · {c.ends_on ? formatDate(locale, c.ends_on) : '—'} · {t('renewals.days_left', { days: c.days_left })}</span>
                </label>
              </li>
            ))}
          </ul>
        )}
        <p className="small text-secondary">{t('renewals.limit_help')}</p>
      </form>
    </SidePanel>
  )
}

function BatchPanel({ batch, onChange, onClose }: { batch: Batch; onChange: (b: Batch) => void; onClose: () => void }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [busy, setBusy] = useState(false)

  async function post(action: 'send' | 'cancel') {
    setBusy(true)
    try {
      onChange((await api.post<{ data: Batch }>(`/contract-renewal-batches/${batch.id}/${action}`, {})).data)
      toast(t(`renewals.done_${action}`))
    } catch (err) {
      const f = formErrors(err, t)
      toast(f.general ?? t('common.error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel wide title={batch.name} onClose={onClose} closeLabel={t('common.close')}>
      <p><StatusBadge tone={TONES[batch.status]}>{t(`renewals.status_${batch.status}`)}</StatusBadge>
        <span className="sub2"> {t('renewals.new_end')}: {formatDate(locale, batch.new_end_date)}</span></p>
      <table className="table">
        <thead><tr><th>{t('renewals.employee')}</th><th>{t('renewals.decision')}</th><th>{t('renewals.signature')}</th></tr></thead>
        <tbody>
          {(batch.items ?? []).map((i) => (
            <tr key={i.id}>
              <td>{i.employee_name}<span className="sub2"> {i.employee_code} · {i.current_end ? formatDate(locale, i.current_end) : '—'}</span></td>
              <td>
                <StatusBadge tone={i.decision === 'renew' ? 'ok' : i.exceeds_limit ? 'er' : 'wa'}>{t(`renewals.decision_${i.decision}`)}</StatusBadge>
                {i.notes && <div className="sub2">{i.notes}</div>}
              </td>
              <td>{i.signature ? t('renewals.signed_of', { signed: i.signature.signed, total: i.signature.total }) : '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {can('contracts.contracts.bulk_renew') && (
        <div className="d-flex gap-2">
          {batch.allowed_actions.includes('send') && <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void post('send')}>{t('renewals.send')}</button>}
          {batch.allowed_actions.includes('cancel') && <button type="button" className="btn btn-sec" disabled={busy} onClick={() => void post('cancel')}>{t('renewals.cancel')}</button>}
        </div>
      )}
      {toastNode}
    </SidePanel>
  )
}
