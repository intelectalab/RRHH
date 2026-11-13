'use client'

import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { StatusBadge, type StatusTone } from '@/ui/components/Status'
import { saveFromApi } from './fileDownload'
import type { PeopleSectionProps } from './sectionProps'

export type Dependent = {
  id: string
  full_name: string
  relationship: string
  document_type?: string | null
  document_number?: string
  document_number_masked?: string | null
  birth_date?: string
  is_student?: boolean
  has_disability?: boolean
  registered_on?: string | null
  deregistered_on?: string | null
  deregistration_reason?: string | null
  registry_status: 'pending' | 'registered' | 'deregistered'
  entitles_family_allowance: boolean
  has_proof: boolean
  missing_proof: boolean
  majority_alerted_on?: string | null
  version?: number
}

const RELATIONSHIPS = ['spouse', 'partner', 'pregnant_partner', 'child', 'disabled_adult_child', 'parent', 'other'] as const
const DOC_TYPES = ['dni', 'ce', 'passport', 'ptp', 'cpp', 'other'] as const
const TONE: Record<Dependent['registry_status'], StatusTone> = { pending: 'wa', registered: 'ok', deregistered: 'gr' }

type Form = { full_name: string; relationship: string; document_type: string; document_number: string; birth_date: string; is_student: boolean; registered_on: string }
const EMPTY: Form = { full_name: '', relationship: 'child', document_type: 'dni', document_number: '', birth_date: '', is_student: false, registered_on: '' }

/**
 * COL-6 · Derechohabientes de la persona (desde la relación laboral). Son datos de terceros: la sección solo
 * se muestra con people.dependents.view; el documento y la fecha llegan solo si la API los envía.
 */
export function DependentsSection({ employmentId, onToast }: PeopleSectionProps) {
  const { t, locale } = useT()
  const { can } = useSession()
  const base = `/employments/${employmentId}/dependents`
  const [rows, setRows] = useState<Dependent[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [form, setForm] = useState<Form | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [deregistering, setDeregistering] = useState<Dependent | null>(null)
  const [dereg, setDereg] = useState({ deregistered_on: '', reason: '' })
  const fileRef = useRef<HTMLInputElement>(null)
  const [proofFor, setProofFor] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: Dependent[] }>(base)).data)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [base])

  useEffect(() => { void load() }, [load])

  if (!can('people.dependents.view')) return null

  const personal = can('people.people.personal_data.edit')

  async function save() {
    if (!form) return
    setBusy(true)
    setErrors({})
    setGeneral(null)
    try {
      const body: Record<string, unknown> = { full_name: form.full_name, relationship: form.relationship, is_student: form.is_student }
      if (form.registered_on) body.registered_on = form.registered_on
      if (personal && form.document_number) { body.document_type = form.document_type; body.document_number = form.document_number }
      if (personal && form.birth_date) body.birth_date = form.birth_date
      await api.post(base, body)
      setForm(null)
      onToast(t('dep.saved'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields)
      setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  async function deregister() {
    if (!deregistering) return
    setBusy(true)
    setErrors({})
    try {
      await api.post(`${base}/${deregistering.id}/deregister`, dereg)
      setDeregistering(null)
      onToast(t('dep.deregistered'))
      await load()
    } catch (e) {
      setErrors(formErrors(e, t).fields)
      setGeneral(formErrors(e, t).general)
    } finally {
      setBusy(false)
    }
  }

  async function uploadProof(file: File) {
    if (!proofFor) return
    try {
      await api.upload(`${base}/${proofFor}/proof`, file, file.name)
      onToast(t('dep.proof_saved'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      onToast(f.fields.file ?? f.general ?? t('auth.generic_error'), 'error')
    } finally {
      setProofFor(null)
    }
  }

  async function downloadProof(id: string) {
    try {
      await saveFromApi(`${base}/${id}/proof`)
    } catch {
      onToast(t('common.load_error'), 'error')
    }
  }

  const date = (v?: string | null) => (v ? formatDate(locale, v) : '—')
  const field = (key: keyof Form, label: string, input: React.ReactNode) => (
    <div className="mb-3">
      <label className="form-label" htmlFor={`dep-${key}`}>{label}</label>
      {input}
      {errors[key] && <div className="invalid-feedback d-block" role="alert">{errors[key]}</div>}
    </div>
  )

  return (
    <Card
      title={t('dep.title')}
      context={t('dep.context')}
      actions={can('people.dependents.create') ? <button type="button" className="btn btn-sec btn-sm" onClick={() => { setForm(EMPTY); setErrors({}); setGeneral(null) }}>{t('dep.add')}</button> : undefined}
    >
      {failed && <Notice tone="error">{t('common.load_error')}</Notice>}
      {rows === null && !failed && <span className="esq" style={{ width: 200 }} />}
      {rows !== null && rows.length === 0 && <p className="text-secondary">{t('dep.empty')}</p>}
      {rows !== null && rows.length > 0 && (
        <ul className="sec-lista">
          {rows.map((d) => (
            <li key={d.id}>
              <div className="sec-fila">
                <div>
                  <b>{d.full_name}</b>
                  <span className="text-secondary small">{t(`dep.rel_${d.relationship}`)}{d.document_number_masked ? ` · ${d.document_number ?? d.document_number_masked}` : ''}{d.birth_date ? ` · ${date(d.birth_date)}` : ''}</span>
                  {d.missing_proof && d.registry_status !== 'deregistered' && <span className="small sec-aviso">{t('dep.missing_proof')}</span>}
                  {d.majority_alerted_on && d.registry_status !== 'deregistered' && <span className="small sec-aviso">{t('dep.majority_review', { date: date(d.majority_alerted_on) })}</span>}
                  {d.registry_status === 'deregistered' && <span className="small text-secondary">{t('dep.deregistered_on', { date: date(d.deregistered_on), reason: d.deregistration_reason ?? '' })}</span>}
                </div>
                <div className="sec-acciones">
                  <StatusBadge tone={TONE[d.registry_status]}>{t(`dep.status_${d.registry_status}`)}</StatusBadge>
                  {d.entitles_family_allowance && <span className="small text-secondary">{t('dep.family_allowance')}</span>}
                  {d.has_proof && <button type="button" className="btn btn-ter btn-sm" onClick={() => void downloadProof(d.id)}>{t('dep.proof_download')}</button>}
                  {can('people.dependents.update') && d.registry_status !== 'deregistered' && (
                    <>
                      <button type="button" className="btn btn-ter btn-sm" onClick={() => { setProofFor(d.id); fileRef.current?.click() }}>{d.has_proof ? t('dep.proof_replace') : t('dep.proof_upload')}</button>
                      <button type="button" className="btn btn-ter btn-sm" onClick={() => { setDeregistering(d); setDereg({ deregistered_on: '', reason: '' }); setErrors({}); setGeneral(null) }}>{t('dep.deregister')}</button>
                    </>
                  )}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
      <input ref={fileRef} type="file" accept="application/pdf,image/jpeg,image/png" hidden aria-label={t('dep.proof_upload')}
        onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) void uploadProof(f) }} />

      {form && (
        <Modal
          title={t('dep.add_title')}
          onClose={() => setForm(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setForm(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void save()}>{t('dep.save')}</button>
            </>
          )}
        >
          {general && <Notice tone="error">{general}</Notice>}
          {field('full_name', t('dep.f_name'), <input id="dep-full_name" className="form-control" value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />)}
          {field('relationship', t('dep.f_relationship'), (
            <select id="dep-relationship" className="form-select" value={form.relationship} onChange={(e) => setForm({ ...form, relationship: e.target.value })}>
              {RELATIONSHIPS.map((r) => <option key={r} value={r}>{t(`dep.rel_${r}`)}</option>)}
            </select>
          ))}
          {personal && (
            <>
              {field('document_type', t('dep.f_doc_type'), (
                <select id="dep-document_type" className="form-select" value={form.document_type} onChange={(e) => setForm({ ...form, document_type: e.target.value })}>
                  {DOC_TYPES.map((d) => <option key={d} value={d}>{t(`dep.doc_${d}`)}</option>)}
                </select>
              ))}
              {field('document_number', t('dep.f_doc_number'), <input id="dep-document_number" className="form-control" value={form.document_number} onChange={(e) => setForm({ ...form, document_number: e.target.value })} />)}
              {field('birth_date', t('dep.f_birth_date'), <input id="dep-birth_date" type="date" className="form-control" value={form.birth_date} onChange={(e) => setForm({ ...form, birth_date: e.target.value })} />)}
            </>
          )}
          {field('registered_on', t('dep.f_registered_on'), <input id="dep-registered_on" type="date" className="form-control" value={form.registered_on} onChange={(e) => setForm({ ...form, registered_on: e.target.value })} />)}
          <div className="form-check">
            <input id="dep-is_student" type="checkbox" className="form-check-input" checked={form.is_student} onChange={(e) => setForm({ ...form, is_student: e.target.checked })} />
            <label className="form-check-label" htmlFor="dep-is_student">{t('dep.f_is_student')}</label>
          </div>
          <p className="small text-secondary mt-2">{t('dep.proof_hint')}</p>
        </Modal>
      )}

      {deregistering && (
        <Modal
          title={t('dep.deregister_title', { name: deregistering.full_name })}
          onClose={() => setDeregistering(null)}
          footer={(
            <>
              <button type="button" className="btn btn-sec" onClick={() => setDeregistering(null)}>{t('org.cancel')}</button>
              <button type="button" className="btn btn-danger" disabled={busy} aria-busy={busy} onClick={() => void deregister()}>{t('dep.deregister')}</button>
            </>
          )}
        >
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="dep-dereg-date">{t('dep.f_deregistered_on')}</label>
          <input id="dep-dereg-date" type="date" className="form-control" value={dereg.deregistered_on} onChange={(e) => setDereg({ ...dereg, deregistered_on: e.target.value })} />
          {errors.deregistered_on && <div className="invalid-feedback d-block" role="alert">{errors.deregistered_on}</div>}
          <label className="form-label mt-3" htmlFor="dep-dereg-reason">{t('dep.f_reason')}</label>
          <textarea id="dep-dereg-reason" className="form-control" rows={3} value={dereg.reason} onChange={(e) => setDereg({ ...dereg, reason: e.target.value })} />
          {errors.reason && <div className="invalid-feedback d-block" role="alert">{errors.reason}</div>}
        </Modal>
      )}
    </Card>
  )
}
