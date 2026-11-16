'use client'

import Link from 'next/link'
import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatNumber } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { SkeletonBlock } from '@/ui/components/Skeleton'
import { ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'
import {
  BATCH_TONE, batchFinished, insertVariable, TEMPLATE_TONE,
  type BatchItem, type DocumentTemplate, type GenerationBatch, type TemplatePreview, type TemplateVariable,
} from './templates'

type EmploymentOption = { id: string; person_name?: string | null; employee_code?: string | null }

/**
 * DOC-4 · DOC-5 · Editor de plantilla: el cuerpo se edita como borrador con el catálogo de variables seguras a la
 * mano (clic inserta {{grupo.variable}}); la vista previa la resuelve el servidor con los datos vigentes de una
 * persona real; publicar fija la versión. Generar crea el PDF en el legajo de una o varias personas y muestra el
 * avance del lote (las variables sensibles exigen el permiso del campo, RN-DOC-10).
 */
export function DocumentTemplateEditor({ id }: { id: string }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const editor = useRef<HTMLTextAreaElement>(null)
  const [tpl, setTpl] = useState<DocumentTemplate | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [body, setBody] = useState('')
  const [name, setName] = useState('')
  const [header, setHeader] = useState(false)
  const [vars, setVars] = useState<TemplateVariable[]>([])
  const [employments, setEmployments] = useState<EmploymentOption[]>([])
  const [previewFor, setPreviewFor] = useState('')
  const [preview, setPreview] = useState<TemplatePreview | null>(null)
  const [selected, setSelected] = useState<string[]>([])
  const [batch, setBatch] = useState<GenerationBatch | null>(null)
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const canEdit = can('documents.templates.update')
  const canGenerate = can('documents.templates.generate')

  const apply = useCallback((d: DocumentTemplate) => {
    setTpl(d); setBody(d.body); setName(d.name)
    setHeader(Boolean((d.page_settings as { company_header?: boolean } | undefined)?.company_header))
  }, [])
  const load = useCallback(async () => {
    try {
      apply((await api.get<{ data: DocumentTemplate }>(`/document-templates/${id}`)).data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [id, apply])
  useEffect(() => { void load() }, [load])
  useEffect(() => {
    void api.get<{ data: TemplateVariable[] }>('/document-templates/variables').then((r) => setVars(r.data)).catch(() => setVars([]))
    if (!canGenerate) return
    void api.get<{ data: EmploymentOption[] }>('/employments?filter[status]=active&sort=person_name&per_page=100')
      .then((r) => { setEmployments(r.data); setPreviewFor(r.data[0]?.id ?? '') }).catch(() => setEmployments([]))
  }, [canGenerate])

  // DOC-5 · Avance del lote en segundo plano: se consulta hasta que termina.
  useEffect(() => {
    if (!batch || batchFinished(batch)) return
    const timer = window.setTimeout(() => {
      void api.get<{ data: GenerationBatch }>(`/document-generation-batches/${batch.id}`).then((r) => setBatch(r.data)).catch(() => undefined)
    }, 1500)
    return () => window.clearTimeout(timer)
  }, [batch])

  async function run(fn: () => Promise<void>) {
    setBusy(true); setGeneral(null)
    try {
      await fn()
    } catch (e) {
      setGeneral(formErrors(e, t).general ?? Object.values(formErrors(e, t).fields)[0] ?? t('auth.generic_error'))
    } finally {
      setBusy(false)
    }
  }
  const save = () => run(async () => {
    apply((await api.request<{ data: DocumentTemplate }>(`/document-templates/${id}`, 'PATCH', { name, body, page_settings: { company_header: header } }, { 'If-Match': String(tpl?.version ?? '') })).data)
    toast(t('dtpl.saved'))
  })
  const action = (a: 'publish' | 'retire') => run(async () => {
    if (a === 'publish' && dirty) apply((await api.request<{ data: DocumentTemplate }>(`/document-templates/${id}`, 'PATCH', { name, body, page_settings: { company_header: header } })).data)
    apply((await api.post<{ data: DocumentTemplate }>(`/document-templates/${id}/${a}`, {})).data)
    toast(t(`dtpl.done_${a}`))
  })
  const doPreview = () => run(async () => {
    setPreview((await api.post<{ data: TemplatePreview }>(`/document-templates/${id}/preview`, { employment_id: previewFor, body })).data)
  })
  const generate = () => run(async () => {
    setBatch((await api.post<{ data: GenerationBatch }>(`/document-templates/${id}/generate`, { employment_ids: selected })).data)
  })

  function insert(v: TemplateVariable) {
    const el = editor.current
    const r = insertVariable(body, el?.selectionStart ?? body.length, el?.selectionEnd ?? body.length, v.name)
    setBody(r.body)
    window.requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(r.caret, r.caret) })
  }

  if (loadError) return <main className="main"><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></main>
  if (!tpl) return <main className="main"><SkeletonBlock /></main>
  const dirty = body !== tpl.body || name !== tpl.name
  const allowed = tpl.allowed_actions
  const groups = Array.from(new Set(vars.map((v) => v.group)))
  const items = (batch?.items ?? []) as BatchItem[]

  return (
    <>
      <PageHeader title={tpl.name} sub={t('dtpl.editor_sub')} actions={<>
        <Link className="btn btn-sec" href="/documentos/plantillas">{t('dtpl.back')}</Link>
        {canEdit && <button type="button" className="btn btn-sec" disabled={busy || !dirty} onClick={() => void save()}>{t('dtpl.save_draft')}</button>}
        {can('documents.templates.publish') && (allowed.includes('publish') || dirty) && <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void action('publish')}>{t('dtpl.publish')}</button>}
        {can('documents.templates.retire') && allowed.includes('retire') && <button type="button" className="btn btn-ter" disabled={busy} onClick={() => void action('retire')}>{t('dtpl.retire')}</button>}
      </>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <p>
          <StatusBadge tone={TEMPLATE_TONE[tpl.status]}>{t(`dtpl.status_${tpl.status}`)}</StatusBadge>{' '}
          {tpl.current_version && <Tag tone="in">{t('dtpl.version_n', { n: tpl.current_version })}</Tag>}{' '}
          {tpl.has_draft && <Tag tone="wa">{t('dtpl.has_draft')}</Tag>}{' '}
          <span className="small text-secondary">{[t(`dtpl.kind_${tpl.kind}`), tpl.category_name, tpl.legal_entity_name ?? t('dtpl.all_companies')].filter(Boolean).join(' · ')}</span>
        </p>
        {general && <Notice tone="error">{general}</Notice>}
        <div className="row g-3">
          <div className="col-lg-8">
            <Card title={t('dtpl.card_body')}>
              <label className="form-label" htmlFor="tpe-name">{t('dtpl.f_name')}</label>
              <input id="tpe-name" className="form-control" value={name} disabled={!canEdit} onChange={(e) => setName(e.target.value)} />
              <label className="form-label mt-3" htmlFor="tpe-body">{t('dtpl.f_body')}</label>
              <textarea id="tpe-body" ref={editor} className="form-control font-monospace" rows={18} value={body} disabled={!canEdit} onChange={(e) => setBody(e.target.value)} />
              <p className="small text-secondary mt-1">{t('dtpl.syntax_hint')}</p>
              <label className="form-check mt-2">
                <input type="checkbox" className="form-check-input" checked={header} disabled={!canEdit} onChange={(e) => setHeader(e.target.checked)} />
                <span className="form-check-label">{t('dtpl.f_header')}</span>
              </label>
            </Card>
            {canGenerate && (
              <Card title={t('dtpl.card_preview')}>
                <div className="d-flex gap-2 align-items-end">
                  <div className="flex-grow-1">
                    <label className="form-label" htmlFor="tpe-emp">{t('dtpl.f_preview_for')}</label>
                    <select id="tpe-emp" className="form-select" value={previewFor} onChange={(e) => setPreviewFor(e.target.value)}>
                      {employments.map((e) => <option key={e.id} value={e.id}>{e.person_name ?? e.employee_code}</option>)}
                    </select>
                  </div>
                  <button type="button" className="btn btn-sec" disabled={busy || previewFor === ''} onClick={() => void doPreview()}>{t('dtpl.preview')}</button>
                </div>
                {preview && (
                  <>
                    {preview.unknown.length > 0 && <Notice tone="error">{t('dtpl.unknown', { list: preview.unknown.join(', ') })}</Notice>}
                    {preview.syntax_errors.length > 0 && <Notice tone="error">{t('dtpl.syntax', { list: preview.syntax_errors.join(' ') })}</Notice>}
                    {preview.withheld.length > 0 && <Notice tone="warn">{t('dtpl.withheld', { list: preview.withheld.join(', ') })}</Notice>}
                    <pre className="doc-preview mt-3" style={{ whiteSpace: 'pre-wrap' }}>{preview.text}</pre>
                  </>
                )}
              </Card>
            )}
            {canGenerate && allowed.includes('generate') && (
              <Card title={t('dtpl.card_generate')}>
                <p className="small text-secondary">{t('dtpl.generate_hint')}</p>
                <div style={{ maxHeight: 220, overflowY: 'auto' }}>
                  {employments.map((e) => (
                    <label key={e.id} className="form-check">
                      <input type="checkbox" className="form-check-input" checked={selected.includes(e.id)}
                        onChange={(ev) => setSelected(ev.target.checked ? [...selected, e.id] : selected.filter((x) => x !== e.id))} />
                      <span className="form-check-label">{e.person_name ?? e.employee_code}</span>
                    </label>
                  ))}
                </div>
                <div className="d-flex gap-2 mt-2">
                  <button type="button" className="btn btn-ter btn-sm" onClick={() => setSelected(selected.length === employments.length ? [] : employments.map((e) => e.id))}>
                    {selected.length === employments.length ? t('dtpl.select_none') : t('dtpl.select_all')}
                  </button>
                  <button type="button" className="btn btn-primary" disabled={busy || selected.length === 0} onClick={() => void generate()}>
                    {t('dtpl.generate', { n: formatNumber(locale, String(selected.length)) })}
                  </button>
                </div>
                {batch && (
                  <div className="mt-3" aria-live="polite">
                    <StatusBadge tone={BATCH_TONE[batch.status]}>{t(`dtpl.batch_${batch.status}`)}</StatusBadge>{' '}
                    <span>{t('dtpl.batch_progress', { done: batch.generated, failed: batch.failed, total: batch.total })}</span>
                    <progress className="w-100 mt-2" max={batch.total} value={batch.generated + batch.failed} />
                    <ul className="sec-lista mt-2">
                      {items.map((i) => (
                        <li key={i.employment_id}>
                          {i.person_name ?? employments.find((e) => e.id === i.employment_id)?.person_name ?? i.employment_id}{' · '}
                          {i.status === 'generated' && i.document_id
                            ? <Link href={`/personas/colaboradores/${i.employment_id}?tab=documents`}>{t('dtpl.item_generated', { v: i.version ?? 1 })}</Link>
                            : i.status === 'failed' ? <span className="text-danger">{i.error}</span> : t('dtpl.item_pending')}
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </Card>
            )}
          </div>
          <div className="col-lg-4">
            <Card title={t('dtpl.card_variables')}>
              <p className="small text-secondary">{t('dtpl.variables_hint')}</p>
              {groups.map((g) => (
                <div key={g} className="mb-2">
                  <b className="small">{t(`dtpl.group_${g}`)}</b>
                  <ul className="list-unstyled mb-0">
                    {vars.filter((v) => v.group === g).map((v) => (
                      <li key={v.name}>
                        <button type="button" className="btn btn-link btn-sm p-0 text-start" disabled={!canEdit} onClick={() => insert(v)} title={v.example}>
                          <code>{`{{${v.name}}}`}</code>
                        </button>{' '}
                        <span className="small text-secondary">{v.label}</span>
                        {v.sensitive && <> <Tag tone={v.allowed ? 'pe' : 'er'}>{v.allowed ? t('dtpl.sensitive') : t('dtpl.no_permission')}</Tag></>}
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </Card>
            {tpl.versions && tpl.versions.length > 0 && (
              <Card title={t('dtpl.card_versions')}>
                <ul className="sec-lista">
                  {(tpl.versions as Array<{ version: number; published_at: string | null; is_current: boolean; documents: number }>).map((v) => (
                    <li key={v.version}>
                      {t('dtpl.version_n', { n: v.version })}{' · '}
                      {v.published_at ? t('dtpl.published') : t('dtpl.draft')}
                      {v.is_current && <> <Tag tone="ok">{t('dtpl.current')}</Tag></>}
                      <span className="small text-secondary d-block">{t('dtpl.documents_n', { n: formatNumber(locale, String(v.documents)) })}</span>
                    </li>
                  ))}
                </ul>
              </Card>
            )}
          </div>
        </div>
      </main>
      {toastNode}
    </>
  )
}
