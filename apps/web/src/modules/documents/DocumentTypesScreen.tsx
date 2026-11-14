'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'
import { CLASSIFICATIONS, isConfidential, type Classification, type DocumentFolder, type DocumentType } from './documents'

type TypeForm = {
  parent_id: string; code: string; name: string; description: string; default_classification: Classification
  visible_to_employee: boolean; requires_review: boolean; requires_expiry: boolean; expiry_notice_days: string
}

const EMPTY: TypeForm = {
  parent_id: '', code: '', name: '', description: '', default_classification: 'internal',
  visible_to_employee: true, requires_review: false, requires_expiry: false, expiry_notice_days: '',
}

/**
 * Configuración → Tipos documentales (DOC-1 · DOC-2): carpetas del legajo con sus tipos, su confidencialidad, si el
 * colaborador los ve, si lo que él entrega pasa por revisión y si exigen vencimiento con su anticipación de aviso
 * (sin anticipación propia rige el parámetro documentos.aviso_vencimiento_dias).
 */
export function DocumentTypesScreen() {
  const { t } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<DocumentFolder[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [archived, setArchived] = useState(false)
  const [form, setForm] = useState<{ editing: DocumentType | null; value: TypeForm } | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const canEdit = can('documents.document_types.update')

  const load = useCallback(async () => {
    try {
      setRows((await api.get<{ data: DocumentFolder[] }>(`/document-categories${archived ? '?include_archived=true' : ''}`)).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [archived])
  useEffect(() => { void load() }, [load])

  function open(editing: DocumentType | null, parent: DocumentFolder | null) {
    setErrors({}); setGeneral(null)
    setForm({ editing, value: editing ? {
      parent_id: editing.parent_id ?? '', code: editing.code, name: editing.name, description: editing.description ?? '',
      default_classification: editing.default_classification, visible_to_employee: editing.visible_to_employee, requires_review: editing.requires_review,
      requires_expiry: editing.requires_expiry, expiry_notice_days: editing.expiry_notice_days === null ? '' : String(editing.expiry_notice_days),
    } : { ...EMPTY, parent_id: parent?.id ?? '', default_classification: parent?.default_classification ?? 'internal' } })
  }

  async function save() {
    if (!form) return
    const v = form.value
    const body: Record<string, unknown> = {
      description: v.description === '' ? null : v.description, default_classification: v.default_classification,
      visible_to_employee: v.visible_to_employee, requires_review: v.requires_review, requires_expiry: v.requires_expiry,
      expiry_notice_days: v.expiry_notice_days === '' ? null : Number(v.expiry_notice_days),
    }
    if (!form.editing?.is_system) body.name = v.name
    setBusy(true); setErrors({}); setGeneral(null)
    try {
      if (form.editing) await api.request(`/document-categories/${form.editing.id}`, 'PATCH', body)
      else await api.post('/document-categories', { ...body, code: v.code, parent_id: v.parent_id === '' ? null : v.parent_id })
      setForm(null)
      toast(t('dtype.saved'))
      await load()
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields); setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  async function toggle(row: DocumentType) {
    const action = row.status === 'active' ? 'archive' : 'restore'
    try {
      await api.post(`/document-categories/${row.id}/${action}`, {})
      toast(t(`dtype.done_${action}`))
      await load()
    } catch (e) {
      toast(formErrors(e, t).general ?? t('auth.generic_error'), 'error')
    }
  }

  const flags = (r: DocumentType) => (
    <>
      {isConfidential(r.default_classification) && <Tag tone="pe">{t(`doc.class_${r.default_classification}`)}</Tag>}
      {r.visible_to_employee && <Tag tone="in">{t('dtype.flag_visible')}</Tag>}
      {r.requires_review && <Tag tone="wa">{t('dtype.flag_review')}</Tag>}
      {r.requires_expiry && <Tag tone="oc">{r.expiry_notice_days === null ? t('dtype.flag_expiry') : t('dtype.flag_expiry_days', { days: r.expiry_notice_days })}</Tag>}
      {r.status === 'archived' && <StatusBadge tone="gr">{t('dtype.archived')}</StatusBadge>}
    </>
  )
  const err = (k: string) => errors[k] && <div className="invalid-feedback d-block" role="alert">{errors[k]}</div>
  const check = (k: 'visible_to_employee' | 'requires_review' | 'requires_expiry', label: string) => form && (
    <label className="form-check mt-2">
      <input type="checkbox" className="form-check-input" checked={form.value[k]} onChange={(e) => setForm({ ...form, value: { ...form.value, [k]: e.target.checked } })} />
      <span className="form-check-label">{label}</span>
    </label>
  )

  return (
    <>
      <PageHeader title={t('dtype.title')} sub={t('dtype.sub')} actions={<>
        <Link className="btn btn-sec" href="/configuracion">{t('cfg.back')}</Link>
        {canEdit && <button type="button" className="btn btn-primary" onClick={() => open(null, null)}><Icon name="plus" /> {t('dtype.new_folder')}</button>}
      </>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="nota">{t('dtype.help')}</div>
        <label className="form-check mb-3">
          <input type="checkbox" className="form-check-input" checked={archived} onChange={(e) => setArchived(e.target.checked)} />
          <span className="form-check-label">{t('dtype.show_archived')}</span>
        </label>
        {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
        {rows?.length === 0 && <EmptyState title={t('dtype.empty_title')} text={t('dtype.empty_text')} />}
        {rows?.map((f) => (
          <section key={f.id} className="card mb-3">
            <div className="sec-fila" style={{ padding: 16 }}>
              <div><b>{f.name}</b><span className="sub2">{f.is_system ? t('dtype.system') : t('dtype.own')}</span></div>
              <div className="sec-acciones">
                {flags(f)}
                {canEdit && <button type="button" className="btn btn-ter btn-sm" onClick={() => open(f, null)}>{t('dtype.edit')}</button>}
                {canEdit && f.code !== 'contracts' && <button type="button" className="btn btn-sec btn-sm" onClick={() => open(null, f)}><Icon name="plus" /> {t('dtype.new_type')}</button>}
                {canEdit && f.allowed_actions.map((a) => <button key={a} type="button" className="btn btn-ter btn-sm" onClick={() => void toggle(f)}>{t(`dtype.act_${a}`)}</button>)}
              </div>
            </div>
            {f.types.length > 0 && (
              <ul className="sec-lista" style={{ padding: '0 16px 16px' }}>
                {f.types.map((ty) => (
                  <li key={ty.id}>
                    <div className="sec-fila">
                      <div><span>{ty.name}</span>{ty.description && <span className="small text-secondary">{ty.description}</span>}</div>
                      <div className="sec-acciones">
                        {flags(ty)}
                        {canEdit && <button type="button" className="btn btn-ter btn-sm" onClick={() => open(ty, null)}>{t('dtype.edit')}</button>}
                        {canEdit && ty.allowed_actions.map((a) => <button key={a} type="button" className="btn btn-ter btn-sm" onClick={() => void toggle(ty)}>{t(`dtype.act_${a}`)}</button>)}
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        ))}
      </main>

      {form && (
        <Modal title={form.editing ? t('dtype.edit_title', { name: form.editing.name }) : form.value.parent_id ? t('dtype.new_type') : t('dtype.new_folder')} onClose={() => setForm(null)}
          footer={<>
            <button type="button" className="btn btn-sec" onClick={() => setForm(null)}>{t('org.cancel')}</button>
            <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void save()}>{t('dtype.save')}</button>
          </>}>
          {general && <Notice tone="error">{general}</Notice>}
          {!form.editing && (
            <>
              <label className="form-label" htmlFor="dt-code">{t('dtype.f_code')}</label>
              <input id="dt-code" className="form-control" value={form.value.code} onChange={(e) => setForm({ ...form, value: { ...form.value, code: e.target.value } })} />
              {err('code')}
            </>
          )}
          {!form.editing?.is_system && (
            <>
              <label className="form-label mt-3" htmlFor="dt-name">{t('dtype.f_name')}</label>
              <input id="dt-name" className="form-control" value={form.value.name} onChange={(e) => setForm({ ...form, value: { ...form.value, name: e.target.value } })} />
              {err('name')}
            </>
          )}
          <label className="form-label mt-3" htmlFor="dt-desc">{t('dtype.f_description')}</label>
          <input id="dt-desc" className="form-control" value={form.value.description} onChange={(e) => setForm({ ...form, value: { ...form.value, description: e.target.value } })} />
          <label className="form-label mt-3" htmlFor="dt-class">{t('dtype.f_classification')}</label>
          <select id="dt-class" className="form-select" value={form.value.default_classification} onChange={(e) => setForm({ ...form, value: { ...form.value, default_classification: e.target.value as Classification } })}>
            {CLASSIFICATIONS.map((c) => <option key={c} value={c}>{t(`doc.class_${c}`)}</option>)}
          </select>
          {check('visible_to_employee', t('dtype.f_visible'))}
          {check('requires_review', t('dtype.f_review'))}
          {check('requires_expiry', t('dtype.f_expiry'))}
          <label className="form-label mt-3" htmlFor="dt-days">{t('dtype.f_notice_days')}</label>
          <input id="dt-days" type="number" min={0} className="form-control" value={form.value.expiry_notice_days} onChange={(e) => setForm({ ...form, value: { ...form.value, expiry_notice_days: e.target.value } })} />
          <p className="small text-secondary mt-1">{t('dtype.notice_hint')}</p>
          {err('expiry_notice_days')}
        </Modal>
      )}
      {toastNode}
    </>
  )
}
