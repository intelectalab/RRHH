'use client'

import Link from 'next/link'
import { useEffect, useMemo, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { DataTable, type TableColumn, type TableFilter } from '@/ui/components/DataTable'
import { Icon } from '@/ui/components/Icon'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'
import type { DocumentFolder } from './documents'
import { REQUEST_TONE, type DocumentRequestRow, type RequestStatus } from './templates'

type EmploymentOption = { id: string; person_name?: string | null; employee_code?: string | null; legal_entity_id?: string; legal_entity_name?: string | null }
type RequestForm = { category_id: string; mode: 'people' | 'company'; employment_ids: string[]; legal_entity_id: string; title: string; instructions: string; due_on: string }

const STATUSES: RequestStatus[] = ['requested', 'submitted', 'rejected', 'approved', 'cancelled']

/**
 * DOC-3 · Solicitudes de documentos: RRHH pide un tipo documental a una o varias personas (o a toda una empresa)
 * con fecha límite; ve lo pendiente de entrega y lo entregado en revisión, que se aprueba o rechaza en Aprobaciones.
 */
export function DocumentRequestsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [form, setForm] = useState<RequestForm | null>(null)
  const [folders, setFolders] = useState<DocumentFolder[]>([])
  const [employments, setEmployments] = useState<EmploymentOption[]>([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    if (!form || folders.length > 0) return
    void api.get<{ data: DocumentFolder[] }>('/document-categories').then((r) => setFolders(r.data)).catch(() => setFolders([]))
    void api.get<{ data: EmploymentOption[] }>('/employments?filter[status]=active&sort=person_name&per_page=100').then((r) => setEmployments(r.data)).catch(() => setEmployments([]))
  }, [form, folders.length])

  const date = (v: string | null | undefined) => (v ? formatDate(locale, v) : '—')
  const columns = useMemo<Array<TableColumn<DocumentRequestRow>>>(() => [
    {
      key: 'person', label: t('dreq.col_person'), hideable: false,
      render: (r) => <><Link href={`/personas/colaboradores/${r.employment_id}?tab=documents`}>{r.person_name ?? r.employee_code}</Link><span className="small text-secondary d-block">{r.employee_code}</span></>,
    },
    { key: 'document', label: t('dreq.col_document'), render: (r) => <>{r.title}<span className="small text-secondary d-block">{r.category_name}</span></> },
    { key: 'due_on', label: t('dreq.col_due'), render: (r) => <>{date(r.due_on)}{r.overdue && <> <StatusBadge tone="er">{t('dreq.overdue')}</StatusBadge></>}</> },
    {
      key: 'status', label: t('dreq.col_status'),
      render: (r) => <><StatusBadge tone={REQUEST_TONE[r.status]}>{t(`dreq.status_${r.status}`)}</StatusBadge>{r.rejection_reason && r.status === 'rejected' && <span className="small text-secondary d-block">{r.rejection_reason}</span>}</>,
    },
  ], [t, locale]) // eslint-disable-line react-hooks/exhaustive-deps

  const filters: TableFilter[] = [
    { key: 'status', label: t('dreq.f_status'), options: STATUSES.map((s) => ({ value: s, label: t(`dreq.status_${s}`) })) },
    { key: 'overdue', label: t('dreq.f_overdue'), options: [{ value: 'true', label: t('dreq.overdue') }] },
  ]
  const types = folders.filter((f) => f.code !== 'contracts').flatMap((f) => f.types.filter((ty) => ty.status === 'active').map((ty) => ({ ...ty, folder: f.name })))
  const companies = Array.from(new Map(employments.filter((e) => e.legal_entity_id).map((e) => [e.legal_entity_id as string, e.legal_entity_name ?? e.legal_entity_id])).entries())

  async function submit() {
    if (!form) return
    setBusy(true); setErrors({}); setGeneral(null)
    try {
      const target = form.mode === 'company' ? { filter: { legal_entity_id: form.legal_entity_id || null } } : { employment_ids: form.employment_ids }
      const r = await api.post<{ data: DocumentRequestRow[]; meta: { created: number; skipped: unknown[] } }>('/document-requests', {
        category_id: form.category_id, ...target, title: form.title || null, instructions: form.instructions || null, due_on: form.due_on || null,
      })
      setForm(null)
      toast(t('dreq.sent', { n: r.meta.created, skipped: r.meta.skipped.length }))
      setReload((n) => n + 1)
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields); setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }
  async function cancel(r: DocumentRequestRow) {
    try {
      await api.post(`/document-requests/${r.id}/cancel`, {})
      toast(t('dreq.cancelled'))
      setReload((n) => n + 1)
    } catch (e) {
      toast(formErrors(e, t).general ?? t('auth.generic_error'), 'error')
    }
  }
  const err = (k: string) => errors[k] && <div className="invalid-feedback d-block" role="alert">{errors[k]}</div>

  return (
    <>
      <PageHeader title={t('dreq.title')} sub={t('dreq.sub')} actions={<>
        {can('workflow.approval_requests.view') && <Link className="btn btn-sec" href="/aprobaciones">{t('dreq.to_approvals')}</Link>}
        {can('documents.documents.request') && (
          <button type="button" className="btn btn-primary" onClick={() => { setErrors({}); setGeneral(null); setForm({ category_id: '', mode: 'people', employment_ids: [], legal_entity_id: '', title: '', instructions: '', due_on: '' }) }}>
            <Icon name="plus" /> {t('dreq.new')}
          </button>
        )}
      </>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <DataTable<DocumentRequestRow>
          resource="document-requests"
          endpoint="/document-requests"
          defaultSort=""
          searchLabel={t('dreq.search')}
          rowKey={(r) => r.id}
          columns={columns}
          filters={filters}
          reloadKey={reload}
          empty={<EmptyState title={t('dreq.empty_title')} text={t('dreq.empty_text')} />}
          rowActions={(r) => (r.allowed_actions.includes('cancel') ? <button type="button" className="btn btn-ter btn-sm" onClick={() => void cancel(r)}>{t('dreq.cancel')}</button> : null)}
        />
      </main>
      {form && (
        <Modal title={t('dreq.new')} size="l" onClose={() => setForm(null)} footer={<>
          <button type="button" className="btn btn-sec" onClick={() => setForm(null)}>{t('org.cancel')}</button>
          <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void submit()}>{t('dreq.send')}</button>
        </>}>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="rq-type">{t('dreq.f_type')}</label>
          <select id="rq-type" className="form-select" value={form.category_id} onChange={(e) => setForm({ ...form, category_id: e.target.value })}>
            <option value="">{t('dreq.choose')}</option>
            {types.map((c) => <option key={c.id} value={c.id}>{`${c.folder} · ${c.name}`}</option>)}
          </select>
          {err('category_id')}
          <fieldset className="mt-3">
            <legend className="form-label">{t('dreq.f_recipients')}</legend>
            <label className="form-check form-check-inline">
              <input type="radio" className="form-check-input" checked={form.mode === 'people'} onChange={() => setForm({ ...form, mode: 'people' })} />
              <span className="form-check-label">{t('dreq.mode_people')}</span>
            </label>
            <label className="form-check form-check-inline">
              <input type="radio" className="form-check-input" checked={form.mode === 'company'} onChange={() => setForm({ ...form, mode: 'company' })} />
              <span className="form-check-label">{t('dreq.mode_company')}</span>
            </label>
          </fieldset>
          {form.mode === 'people' ? (
            <div style={{ maxHeight: 200, overflowY: 'auto' }}>
              {employments.map((e) => (
                <label key={e.id} className="form-check">
                  <input type="checkbox" className="form-check-input" checked={form.employment_ids.includes(e.id)}
                    onChange={(ev) => setForm({ ...form, employment_ids: ev.target.checked ? [...form.employment_ids, e.id] : form.employment_ids.filter((x) => x !== e.id) })} />
                  <span className="form-check-label">{e.person_name ?? e.employee_code}</span>
                </label>
              ))}
            </div>
          ) : (
            <select aria-label={t('dreq.f_company')} className="form-select" value={form.legal_entity_id} onChange={(e) => setForm({ ...form, legal_entity_id: e.target.value })}>
              <option value="">{t('dreq.all_companies')}</option>
              {companies.map(([cid, cname]) => <option key={cid} value={cid}>{cname}</option>)}
            </select>
          )}
          {err('employment_ids')}
          <label className="form-label mt-3" htmlFor="rq-title">{t('dreq.f_title')}</label>
          <input id="rq-title" className="form-control" value={form.title} placeholder={t('dreq.title_hint')} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          <label className="form-label mt-3" htmlFor="rq-due">{t('dreq.f_due')}</label>
          <input id="rq-due" type="date" className="form-control" value={form.due_on} onChange={(e) => setForm({ ...form, due_on: e.target.value })} />
          {err('due_on')}
          <label className="form-label mt-3" htmlFor="rq-ins">{t('dreq.f_instructions')}</label>
          <textarea id="rq-ins" className="form-control" rows={3} value={form.instructions} onChange={(e) => setForm({ ...form, instructions: e.target.value })} />
        </Modal>
      )}
      {toastNode}
    </>
  )
}
