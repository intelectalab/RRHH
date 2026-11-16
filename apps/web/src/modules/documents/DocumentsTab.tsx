'use client'

import { MyDocumentRequests } from './MyDocumentRequests'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate, formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { saveFromApi } from '@/modules/people/fileDownload'
import type { ProfileTabContext } from '@/modules/people/profile/types'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { Notice } from '@/ui/components/Notice'
import { StatusBadge, Tag } from '@/ui/components/Status'
import {
  documentButtons, expiryTone, fileSize, isConfidential, STATUS_TONE,
  type DocAction, type DocumentFolder, type DocumentType, type DocumentVersionRow, type Legajo, type LegajoDocument, type LinkedDocument,
} from './documents'

type UploadForm = { category_id: string; title: string; description: string; issued_on: string; expires_on: string; visible_to_employee: boolean }
type VersionForm = { issued_on: string; expires_on: string; notes: string }

const EMPTY_UPLOAD: UploadForm = { category_id: '', title: '', description: '', issued_on: '', expires_on: '', visible_to_employee: true }

/** Abre el archivo en una pestaña nueva (previsualización) a partir de la descarga autenticada. */
async function preview(endpoint: string): Promise<void> {
  const { blob } = await api.download(endpoint)
  const href = URL.createObjectURL(blob)
  window.open(href, '_blank', 'noopener')
  window.setTimeout(() => URL.revokeObjectURL(href), 60_000)
}

/**
 * DOC-1 · DOC-2 · Pestaña «Documentos» de la ficha 360: el legajo por carpeta con los documentos subidos, los de
 * contratos (se gestionan en su pestaña) y los sustentos de ausencias como referencias. Subir, versiones, revisión,
 * archivo y papelera según allowed_actions; la confidencialidad, el alcance y los vencimientos los decide el servidor.
 */
export function DocumentsTab({ header, toast }: ProfileTabContext) {
  const { t, locale } = useT()
  const [legajo, setLegajo] = useState<Legajo | null>(null)
  const [meta, setMeta] = useState<{ can_upload: boolean }>({ can_upload: false })
  const [failed, setFailed] = useState(false)
  const [archived, setArchived] = useState(false)
  const [folder, setFolder] = useState<string | null>(null)
  const [types, setTypes] = useState<DocumentFolder[]>([])
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [upload, setUpload] = useState<UploadForm | null>(null)
  const [file, setFile] = useState<File | null>(null)
  const [versionFor, setVersionFor] = useState<{ doc: LegajoDocument; form: VersionForm } | null>(null)
  const [history, setHistory] = useState<{ doc: LegajoDocument; rows: DocumentVersionRow[] } | null>(null)
  const [reasonFor, setReasonFor] = useState<{ doc: LegajoDocument; action: 'reject' | 'archive' } | null>(null)
  const [reason, setReason] = useState('')
  const [edit, setEdit] = useState<{ doc: LegajoDocument; title: string; description: string; visible: boolean } | null>(null)
  const [confirmDelete, setConfirmDelete] = useState<LegajoDocument | null>(null)

  const load = useCallback(async () => {
    try {
      const res = await api.get<{ data: Legajo; meta: { can_upload: boolean } }>(`/employments/${header.id}/documents${archived ? '?include_archived=true' : ''}`)
      setLegajo(res.data)
      setMeta(res.meta)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [header.id, archived])

  useEffect(() => { void load() }, [load])

  const folders = legajo?.folders ?? []
  const current = folders.find((f) => f.code === folder) ?? folders.find((f) => f.count > 0) ?? folders[0] ?? null
  const date = (v: string | null | undefined) => (v ? formatDate(locale, v) : '—')
  const reset = () => { setErrors({}); setGeneral(null) }
  const fail = (e: unknown) => { const f = formErrors(e, t); setErrors(f.fields); setGeneral(f.general) }
  const err = (k: string) => errors[k] && <div className="invalid-feedback d-block" role="alert">{errors[k]}</div>

  async function run(fn: () => Promise<unknown>, done: string, close: () => void) {
    setBusy(true); reset()
    try {
      await fn()
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

  async function fetchFile(endpoint: string, inline: boolean) {
    try {
      if (inline) await preview(`${endpoint}?inline=true`)
      else await saveFromApi(endpoint)
    } catch {
      toast(t('common.load_error'), 'error')
    }
  }

  async function openUpload() {
    reset(); setFile(null)
    try {
      const tree = (await api.get<{ data: DocumentFolder[] }>('/document-categories')).data
      setTypes(tree)
      const inFolder = tree.find((f) => f.id === current?.id)
      setUpload({ ...EMPTY_UPLOAD, category_id: inFolder?.types[0]?.id ?? inFolder?.id ?? '' })
    } catch {
      toast(t('common.load_error'), 'error')
    }
  }

  const uploadable = useMemo(() => types.filter((f) => f.code !== 'contracts' && f.scope === 'employee'), [types])
  const chosen: DocumentType | null = useMemo(() => {
    for (const f of uploadable) {
      if (f.id === upload?.category_id) return f
      const ty = f.types.find((x) => x.id === upload?.category_id)
      if (ty) return ty
    }
    return null
  }, [uploadable, upload?.category_id])

  function saveUpload() {
    if (!upload) return
    const form = new FormData()
    if (file) form.append('file', file, file.name)
    form.append('employment_id', header.id)
    form.append('category_id', upload.category_id)
    form.append('title', upload.title)
    form.append('visible_to_employee', upload.visible_to_employee ? 'true' : 'false')
    for (const k of ['description', 'issued_on', 'expires_on'] as const) if (upload[k] !== '') form.append(k, upload[k])
    void run(() => api.postForm('/documents', form), t('doc.uploaded'), () => setUpload(null))
  }

  function saveVersion() {
    if (!versionFor) return
    const form = new FormData()
    if (file) form.append('file', file, file.name)
    for (const k of ['issued_on', 'expires_on', 'notes'] as const) if (versionFor.form[k] !== '') form.append(k, versionFor.form[k])
    void run(() => api.postForm(`/documents/${versionFor.doc.id}/versions`, form), t('doc.version_added'), () => setVersionFor(null))
  }

  async function openHistory(doc: LegajoDocument) {
    try {
      setHistory({ doc, rows: (await api.get<{ data: DocumentVersionRow[] }>(`/documents/${doc.id}/versions`)).data })
    } catch {
      toast(t('common.load_error'), 'error')
    }
  }

  function act(doc: LegajoDocument, action: DocAction) {
    reset()
    if (action === 'new_version') { setFile(null); setVersionFor({ doc, form: { issued_on: '', expires_on: '', notes: '' } }); return }
    if (action === 'reject' || action === 'archive') { setReason(''); setReasonFor({ doc, action }); return }
    if (action === 'update') { setEdit({ doc, title: doc.title, description: doc.description ?? '', visible: doc.visible_to_employee }); return }
    if (action === 'delete') { setConfirmDelete(doc); return }
    void run(() => api.post(`/documents/${doc.id}/${action}`, {}), t(`doc.done_${action}`), () => undefined)
  }

  function saveReason() {
    if (!reasonFor) return
    const body = reasonFor.action === 'reject' || reason !== '' ? { reason } : {}
    void run(() => api.post(`/documents/${reasonFor.doc.id}/${reasonFor.action}`, body), t(`doc.done_${reasonFor.action}`), () => setReasonFor(null))
  }

  function saveEdit() {
    if (!edit) return
    void run(() => api.request(`/documents/${edit.doc.id}`, 'PATCH', { title: edit.title, description: edit.description === '' ? null : edit.description, visible_to_employee: edit.visible },
      { 'If-Match': String(edit.doc.version) }), t('doc.saved'), () => setEdit(null))
  }

  function doDelete() {
    if (!confirmDelete) return
    void run(() => api.request(`/documents/${confirmDelete.id}`, 'DELETE'), t('doc.done_delete'), () => setConfirmDelete(null))
  }

  const footer = (onCancel: () => void, onOk: () => void, label: string, danger = false) => (
    <>
      <button type="button" className="btn btn-sec" onClick={onCancel}>{t('org.cancel')}</button>
      <button type="button" className={danger ? 'btn btn-danger' : 'btn btn-primary'} disabled={busy} aria-busy={busy} onClick={onOk}>{label}</button>
    </>
  )

  const docRow = (d: LegajoDocument) => {
    const tone = expiryTone(d)
    return (
      <li key={d.id}>
        <div className="sec-fila">
          <div>
            <b>{d.title}</b>
            <span className="small text-secondary">{[d.category_name, d.file_name, fileSize(d.size_bytes)].filter(Boolean).join(' · ')}</span>
            {(d.issued_on || d.expires_on) && (
              <span className="small text-secondary">{t('doc.dates', { issued: date(d.issued_on), expires: d.expires_on ? date(d.expires_on) : t('doc.no_expiry') })}</span>
            )}
            {d.current_version !== null && d.current_version !== undefined && d.current_version > 1 && <span className="small text-secondary">{t('doc.version_n', { n: d.current_version })}</span>}
            {d.managed_by && <span className="small text-secondary">{t(`doc.managed_${d.managed_by}`) === `doc.managed_${d.managed_by}` ? t('doc.managed') : t(`doc.managed_${d.managed_by}`)}</span>}
            {d.archive_reason && <span className="small text-secondary">{t('doc.reason', { reason: d.archive_reason })}</span>}
          </div>
          <div className="sec-acciones">
            {isConfidential(d.classification) && <Tag tone="pe">{t(`doc.class_${d.classification}`)}</Tag>}
            {!d.visible_to_employee && <Tag tone="gr">{t('doc.hidden_from_employee')}</Tag>}
            {tone !== null && d.status === 'active' && (
              <StatusBadge tone={tone}>{t('doc.expiring_in', { days: d.days_to_expiry ?? 0 })}</StatusBadge>
            )}
            <StatusBadge tone={STATUS_TONE[d.status]}>{t(`doc.status_${d.status}`)}</StatusBadge>
            {d.current_version !== null && d.current_version !== undefined && (
              <>
                <button type="button" className="btn btn-ter btn-sm" onClick={() => void fetchFile(`/documents/${d.id}/file`, true)}>{t('doc.view')}</button>
                <button type="button" className="btn btn-ter btn-sm" onClick={() => void fetchFile(`/documents/${d.id}/file`, false)}>{t('doc.download')}</button>
                <button type="button" className="btn btn-ter btn-sm" onClick={() => void openHistory(d)}>{t('doc.versions')}</button>
              </>
            )}
            {documentButtons(d).map((a) => (
              <button key={a} type="button" className={a === 'approve' ? 'btn btn-primary btn-sm' : 'btn btn-ter btn-sm'} disabled={busy} onClick={() => act(d, a)}>{t(`doc.act_${a}`)}</button>
            ))}
          </div>
        </div>
      </li>
    )
  }

  const linkedRow = (l: LinkedDocument) => (
    <li key={l.ref}>
      <div className="sec-fila">
        <div>
          <b>{l.title}</b>
          <span className="small text-secondary">{[l.subtitle, l.file_name, fileSize(l.size_bytes)].filter(Boolean).join(' · ')}</span>
          <span className="small text-secondary">{t(`doc.linked_${l.source}`) === `doc.linked_${l.source}` ? t('doc.linked') : t(`doc.linked_${l.source}`)}</span>
        </div>
        <div className="sec-acciones">
          {l.sensitive && <Tag tone="pe">{t('doc.class_restricted')}</Tag>}
          <button type="button" className="btn btn-ter btn-sm" onClick={() => void fetchFile(`/employments/${header.id}/linked-documents/${encodeURIComponent(l.ref)}/file`, true)}>{t('doc.view')}</button>
          <button type="button" className="btn btn-ter btn-sm" onClick={() => void fetchFile(`/employments/${header.id}/linked-documents/${encodeURIComponent(l.ref)}/file`, false)}>{t('doc.download')}</button>
        </div>
      </div>
    </li>
  )

  return (
    <>
    <MyDocumentRequests employmentId={header.id} />
    <Card
      title={t('doc.title')}
      context={t('doc.context')}
      actions={meta.can_upload ? <button type="button" className="btn btn-primary btn-sm" onClick={() => void openUpload()}>{t('doc.upload')}</button> : undefined}
    >
      {failed && <Notice tone="error">{t('common.load_error')}</Notice>}
      {legajo !== null && (
        <div className="doc-legajo">
          <nav className="doc-carpetas" aria-label={t('doc.folders')}>
            <ul>
              {folders.map((f) => (
                <li key={f.id}>
                  <button type="button" aria-current={current?.id === f.id ? 'true' : undefined} className={current?.id === f.id ? 'on' : undefined} onClick={() => setFolder(f.code)}>
                    <span>{f.name}</span>
                    <span className="doc-cuenta">
                      {f.expired > 0 && <span className="st er" title={t('doc.folder_expired', { n: f.expired })}>{f.expired}</span>}
                      {f.expiring > 0 && <span className="st wa" title={t('doc.folder_expiring', { n: f.expiring })}>{f.expiring}</span>}
                      <span className="text-secondary">{f.count}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
            <label className="form-check small mt-2">
              <input type="checkbox" className="form-check-input" checked={archived} onChange={(e) => setArchived(e.target.checked)} />
              <span className="form-check-label">{t('doc.show_archived')}</span>
            </label>
          </nav>
          <section aria-live="polite">
            {current === null || (current.documents.length === 0 && current.linked.length === 0) ? (
              <p className="text-secondary">{t('doc.empty_folder')}</p>
            ) : (
              <ul className="sec-lista">
                {current.documents.map(docRow)}
                {current.linked.map(linkedRow)}
              </ul>
            )}
          </section>
        </div>
      )}

      {upload && (
        <Modal title={t('doc.upload_title')} onClose={() => setUpload(null)} footer={footer(() => setUpload(null), saveUpload, t('doc.upload'))}>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="doc-type">{t('doc.f_type')}</label>
          <select id="doc-type" className="form-select" value={upload.category_id} onChange={(e) => setUpload({ ...upload, category_id: e.target.value })}>
            <option value="">{t('doc.choose_type')}</option>
            {uploadable.map((f) => (
              <optgroup key={f.id} label={f.name}>
                {f.types.length === 0 && <option value={f.id}>{f.name}</option>}
                {f.types.map((ty) => <option key={ty.id} value={ty.id}>{ty.name}</option>)}
              </optgroup>
            ))}
          </select>
          {err('category_id')}
          {chosen && (chosen.requires_review || isConfidential(chosen.default_classification)) && (
            <p className="small text-secondary mt-1">{[isConfidential(chosen.default_classification) ? t('doc.type_confidential') : null, chosen.requires_review ? t('doc.type_review') : null].filter(Boolean).join(' ')}</p>
          )}
          <label className="form-label mt-3" htmlFor="doc-title">{t('doc.f_title')}</label>
          <input id="doc-title" className="form-control" value={upload.title} maxLength={200} onChange={(e) => setUpload({ ...upload, title: e.target.value })} />
          {err('title')}
          <div className="sec-dos">
            <div>
              <label className="form-label mt-3" htmlFor="doc-issued">{t('doc.f_issued')}</label>
              <input id="doc-issued" type="date" className="form-control" value={upload.issued_on} onChange={(e) => setUpload({ ...upload, issued_on: e.target.value })} />
              {err('issued_on')}
            </div>
            <div>
              <label className="form-label mt-3" htmlFor="doc-expires">{chosen?.requires_expiry ? t('doc.f_expires_required') : t('doc.f_expires')}</label>
              <input id="doc-expires" type="date" className="form-control" required={chosen?.requires_expiry ?? false} value={upload.expires_on} onChange={(e) => setUpload({ ...upload, expires_on: e.target.value })} />
              {err('expires_on')}
            </div>
          </div>
          <label className="form-label mt-3" htmlFor="doc-desc">{t('doc.f_description')}</label>
          <textarea id="doc-desc" className="form-control" rows={2} value={upload.description} onChange={(e) => setUpload({ ...upload, description: e.target.value })} />
          <label className="form-check mt-3">
            <input type="checkbox" className="form-check-input" checked={upload.visible_to_employee} onChange={(e) => setUpload({ ...upload, visible_to_employee: e.target.checked })} />
            <span className="form-check-label">{t('doc.f_visible')}</span>
          </label>
          <label className="form-label mt-3" htmlFor="doc-file">{t('doc.f_file')}</label>
          <input id="doc-file" type="file" className="form-control" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          {err('file')}
        </Modal>
      )}

      {versionFor && (
        <Modal title={t('doc.version_title', { title: versionFor.doc.title })} onClose={() => setVersionFor(null)} footer={footer(() => setVersionFor(null), saveVersion, t('doc.act_new_version'))}>
          {general && <Notice tone="error">{general}</Notice>}
          <p className="small text-secondary">{t('doc.version_hint')}</p>
          <div className="sec-dos">
            <div>
              <label className="form-label" htmlFor="docv-issued">{t('doc.f_issued')}</label>
              <input id="docv-issued" type="date" className="form-control" value={versionFor.form.issued_on} onChange={(e) => setVersionFor({ ...versionFor, form: { ...versionFor.form, issued_on: e.target.value } })} />
              {err('issued_on')}
            </div>
            <div>
              <label className="form-label" htmlFor="docv-expires">{t('doc.f_expires')}</label>
              <input id="docv-expires" type="date" className="form-control" value={versionFor.form.expires_on} onChange={(e) => setVersionFor({ ...versionFor, form: { ...versionFor.form, expires_on: e.target.value } })} />
              {err('expires_on')}
            </div>
          </div>
          <label className="form-label mt-3" htmlFor="docv-notes">{t('doc.f_notes')}</label>
          <input id="docv-notes" className="form-control" maxLength={500} value={versionFor.form.notes} onChange={(e) => setVersionFor({ ...versionFor, form: { ...versionFor.form, notes: e.target.value } })} />
          <label className="form-label mt-3" htmlFor="docv-file">{t('doc.f_file')}</label>
          <input id="docv-file" type="file" className="form-control" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          {err('file')}
        </Modal>
      )}

      {history && (
        <Modal title={t('doc.versions_title', { title: history.doc.title })} onClose={() => setHistory(null)} size="l"
          footer={<button type="button" className="btn btn-sec" onClick={() => setHistory(null)}>{t('doc.close')}</button>}>
          <ul className="sec-lista">
            {history.rows.map((v) => (
              <li key={v.version}>
                <div className="sec-fila">
                  <div>
                    <b>{t('doc.version_n', { n: v.version })}</b>{v.is_current && <span className="small text-secondary">{` · ${t('doc.current')}`}</span>}
                    <span className="small text-secondary">{[v.file_name, fileSize(v.size_bytes), v.uploaded_by_name, formatDateTime(locale, v.uploaded_at)].filter(Boolean).join(' · ')}</span>
                    {(v.issued_on || v.expires_on) && <span className="small text-secondary">{t('doc.dates', { issued: date(v.issued_on), expires: v.expires_on ? date(v.expires_on) : t('doc.no_expiry') })}</span>}
                    {v.notes && <span className="small text-secondary">{v.notes}</span>}
                  </div>
                  <div className="sec-acciones">
                    <button type="button" className="btn btn-ter btn-sm" onClick={() => void fetchFile(`/documents/${history.doc.id}/versions/${v.version}/file`, false)}>{t('doc.download')}</button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </Modal>
      )}

      {reasonFor && (
        <Modal title={t(`doc.act_${reasonFor.action}`)} onClose={() => setReasonFor(null)} footer={footer(() => setReasonFor(null), saveReason, t(`doc.act_${reasonFor.action}`), reasonFor.action === 'reject')}>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="doc-reason">{reasonFor.action === 'reject' ? t('doc.f_reason_required') : t('doc.f_reason')}</label>
          <textarea id="doc-reason" className="form-control" rows={3} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          {err('reason')}
          {reasonFor.action === 'archive' && <p className="small text-secondary mt-2">{t('doc.archive_hint')}</p>}
        </Modal>
      )}

      {edit && (
        <Modal title={t('doc.edit_title')} onClose={() => setEdit(null)} footer={footer(() => setEdit(null), saveEdit, t('doc.save'))}>
          {general && <Notice tone="error">{general}</Notice>}
          <label className="form-label" htmlFor="doc-e-title">{t('doc.f_title')}</label>
          <input id="doc-e-title" className="form-control" maxLength={200} value={edit.title} onChange={(e) => setEdit({ ...edit, title: e.target.value })} />
          {err('title')}
          <label className="form-label mt-3" htmlFor="doc-e-desc">{t('doc.f_description')}</label>
          <textarea id="doc-e-desc" className="form-control" rows={2} value={edit.description} onChange={(e) => setEdit({ ...edit, description: e.target.value })} />
          <label className="form-check mt-3">
            <input type="checkbox" className="form-check-input" checked={edit.visible} onChange={(e) => setEdit({ ...edit, visible: e.target.checked })} />
            <span className="form-check-label">{t('doc.f_visible')}</span>
          </label>
          <p className="small text-secondary mt-2">{t('doc.edit_hint')}</p>
        </Modal>
      )}

      {confirmDelete && (
        <Modal title={t('doc.delete_title')} tone="danger" onClose={() => setConfirmDelete(null)} footer={footer(() => setConfirmDelete(null), doDelete, t('doc.act_delete'), true)}>
          <p>{t('doc.delete_text', { title: confirmDelete.title })}</p>
        </Modal>
      )}
    </Card>
    </>
  )
}
