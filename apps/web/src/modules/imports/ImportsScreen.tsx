'use client'

import Link from 'next/link'
import { useCallback, useEffect, useMemo, useState, type ChangeEvent } from 'react'
import { api, ApiRequestError, type Schema } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type ImportJob = Schema<'ImportJob'>
type Column = { key: string; label: string; required: boolean; example: string; sensitive: boolean; allowed: boolean }
type ImportType = { entity: string; label: string; columns: Column[]; error_policy: 'only_valid' | 'fix_all' }
type Problem = { column: string | null; code: string; message: string }
type Row = { row_number: number; status: string; values: Record<string, string>; errors: Problem[]; warnings: Problem[]; entity_id: string | null }
type RowsPage = { data: Row[]; meta: { page: number; per_page: number; total: number; counts: Record<string, number> } }

const TONE: Record<string, string> = {
  uploaded: 'gr', mapped: 'in', validated: 'ok', with_errors: 'wa', importing: 'in', completed: 'ok', failed: 'er', cancelled: 'gr', reverted: 'gr',
}
const ROW_TONE: Record<string, string> = { pending: 'gr', valid: 'ok', invalid: 'er', imported: 'ok', skipped: 'gr', failed: 'er' }
const STEPS = ['upload', 'map', 'review', 'result'] as const

function stepOf(status: string): (typeof STEPS)[number] {
  if (status === 'uploaded') return 'map'
  if (status === 'mapped' || status === 'validated' || status === 'with_errors') return 'review'
  return 'result'
}

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

/** Plantilla CSV con las columnas que devuelve la API (cabecera y fila de ejemplo); el navegador solo la arma. */
function templateCsv(type: ImportType): Blob {
  const cell = (v: string) => (/[",;\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v)
  const lines = [type.columns.map((c) => cell(c.label)).join(','), type.columns.map((c) => cell(c.example)).join(',')]
  return new Blob(['﻿' + lines.join('\r\n') + '\r\n'], { type: 'text/csv;charset=utf-8' })
}

async function toBase64(file: File): Promise<string> {
  const bytes = new Uint8Array(await file.arrayBuffer())
  let binary = ''
  for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000))
  return btoa(binary)
}

/** Configuración → Importar datos (IMP-1): subir → mapear → validar y revisar → confirmar → resultado. */
export function ImportsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [jobs, setJobs] = useState<ImportJob[] | null>(null)
  const [types, setTypes] = useState<ImportType[]>([])
  const [loadError, setLoadError] = useState(false)
  const [current, setCurrent] = useState<ImportJob | null>(null)
  const [creating, setCreating] = useState(false)

  const load = useCallback(async () => {
    try {
      const [list, kinds] = await Promise.all([
        api.get<{ data: ImportJob[] }>('/imports?per_page=50'),
        api.get<{ data: ImportType[] }>('/import-types'),
      ])
      setJobs(list.data)
      setTypes(kinds.data)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [])
  useEffect(() => { void load() }, [load])

  const typeLabel = (entity: string) => types.find((x) => x.entity === entity)?.label ?? entity
  const open = (job: ImportJob) => { setCreating(false); setCurrent(job) }
  const close = async () => { setCurrent(null); setCreating(false); await load() }

  return (
    <>
      <PageHeader title={t('imp.title')} sub={t('imp.sub')} actions={<>
        <Link className="btn btn-sec" href="/configuracion">{t('cfg.back')}</Link>
        {can('imports.imports.create') && types.length > 0 && !creating && !current && (
          <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}><Icon name="upload" /> {t('imp.new')}</button>
        )}
      </>} />
      <main className="main" id="contenido" tabIndex={-1}>
        {(creating || current) && (
          <Wizard
            types={types}
            job={current}
            onJob={setCurrent}
            onClose={() => void close()}
            onToast={toast}
          />
        )}
        {!creating && !current && (
          <section className="card">
            <div className="card-header"><h2>{t('imp.history')}</h2></div>
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('imp.col_file')}</th><th>{t('imp.col_type')}</th><th>{t('imp.col_status')}</th><th>{t('imp.col_rows')}</th><th>{t('imp.col_author')}</th><th>{t('imp.col_date')}</th></tr></thead>
                <tbody>
                  {loadError && <tr><td colSpan={6}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                  {!loadError && jobs === null && <tr><td colSpan={6}><span className="esq" style={{ width: '40%' }} /></td></tr>}
                  {jobs?.length === 0 && <tr><td colSpan={6}><EmptyState title={t('imp.empty_title')} text={t('imp.empty_text')} /></td></tr>}
                  {jobs?.map((j) => (
                    <tr key={j.id}>
                      <td><button type="button" className="link-btn" onClick={() => open(j)}><b>{j.file_name}</b></button></td>
                      <td>{typeLabel(j.entity)}</td>
                      <td><span className={`st ${TONE[j.status] ?? 'gr'}`}>{t(`imp.status_${j.status}`)}</span></td>
                      <td>{t('imp.rows_summary', { total: j.total_rows ?? 0, valid: j.valid_rows ?? 0, errors: j.error_rows ?? 0, imported: j.imported_rows ?? 0 })}</td>
                      <td>{j.created_by_name ?? ''}</td>
                      <td>{formatDateTime(locale, j.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
      </main>
      {toastNode}
    </>
  )
}

function Wizard({ types, job, onJob, onClose, onToast }: {
  types: ImportType[]
  job: ImportJob | null
  onJob: (j: ImportJob) => void
  onClose: () => void
  onToast: (m: string) => void
}) {
  const { t } = useT()
  const step = job ? stepOf(job.status) : 'upload'
  const type = types.find((x) => x.entity === job?.entity)

  // Mientras se importa en segundo plano, se consulta el estado cada pocos segundos.
  useEffect(() => {
    if (job?.status !== 'importing') return
    const timer = window.setInterval(() => {
      void api.get<{ data: ImportJob }>(`/imports/${job.id}`).then((r) => onJob(r.data)).catch(() => undefined)
    }, 2000)
    return () => window.clearInterval(timer)
  }, [job?.status, job?.id, onJob])

  return (
    <section className="card">
      <div className="card-header">
        <h2>{job ? job.file_name : t('imp.new')}</h2>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('imp.back_to_list')}</button>
      </div>
      <ol className="imp-pasos" aria-label={t('imp.steps')}>
        {STEPS.map((s, i) => (
          <li key={s} className={s === step ? 'on' : STEPS.indexOf(step) > i ? 'hecho' : undefined} aria-current={s === step ? 'step' : undefined}>
            <span className="n">{i + 1}</span> {t(`imp.step_${s}`)}
          </li>
        ))}
      </ol>
      <div className="card-body">
        {step === 'upload' && <UploadStep types={types} onUploaded={onJob} />}
        {job && step === 'map' && <MapStep job={job} type={type} onJob={onJob} onCancelled={() => { onToast(t('imp.cancelled')); onClose() }} />}
        {job && step === 'review' && <ReviewStep job={job} type={type} onJob={onJob} onCancelled={() => { onToast(t('imp.cancelled')); onClose() }} />}
        {job && step === 'result' && <ResultStep job={job} onJob={onJob} onToast={onToast} />}
      </div>
    </section>
  )
}

function UploadStep({ types, onUploaded }: { types: ImportType[]; onUploaded: (j: ImportJob) => void }) {
  const { t } = useT()
  const [entity, setEntity] = useState(types[0]?.entity ?? '')
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const type = types.find((x) => x.entity === entity)

  async function upload() {
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: ImportJob }>('/imports', { entity, file_name: file.name, content_base64: await toBase64(file) })
      onUploaded(res.data)
    } catch (e) {
      const f = formErrors(e, t)
      setError(f.fields.content_base64 ?? f.fields.entity ?? f.general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="imp-subir">
      <div className="mb-3">
        <label className="form-label" htmlFor="imp-entity">{t('imp.what')}</label>
        <select id="imp-entity" className="form-select" value={entity} onChange={(e) => setEntity(e.target.value)}>
          {types.map((x) => <option key={x.entity} value={x.entity}>{x.label}</option>)}
        </select>
      </div>
      {type && (
        <div className="nota">
          {t('imp.columns_help')} {type.columns.map((c) => c.label + (c.required ? ' *' : '')).join(' · ')}
          <div className="mt-2">
            <button type="button" className="btn btn-sec" onClick={() => saveFile(templateCsv(type), `${type.entity}-plantilla.csv`)}><Icon name="download" /> {t('imp.template')}</button>
          </div>
        </div>
      )}
      <div className="mb-3">
        <label className="form-label" htmlFor="imp-file">{t('imp.file')}</label>
        <input id="imp-file" type="file" className="form-control" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          onChange={(e: ChangeEvent<HTMLInputElement>) => setFile(e.target.files?.[0] ?? null)} aria-describedby="imp-file-help" />
        <span id="imp-file-help" className="sub2">{t('imp.file_help')}</span>
      </div>
      {error && <div className="invalid-feedback mb-2" role="alert">{error}</div>}
      <button type="button" className="btn btn-primary" disabled={!file || !entity || busy} aria-busy={busy} onClick={() => void upload()}>
        {busy ? <span className="spin" aria-hidden="true" /> : <Icon name="upload" />} {t('imp.upload')}
      </button>
    </div>
  )
}

function Warnings({ job }: { job: ImportJob }) {
  const warnings = ((job.summary as { warnings?: { code: string; message: string }[] } | undefined)?.warnings) ?? []
  if (warnings.length === 0) return null
  return <div className="nota imp-aviso" role="status">{warnings.map((w) => <div key={w.code + w.message}>{w.message}</div>)}</div>
}

function MapStep({ job, type, onJob, onCancelled }: { job: ImportJob; type: ImportType | undefined; onJob: (j: ImportJob) => void; onCancelled: () => void }) {
  const { t } = useT()
  const [mapping, setMapping] = useState<Record<string, string>>(() => ({ ...((job.suggested_mapping ?? {}) as Record<string, string>) }))
  const [saveAs, setSaveAs] = useState('')
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const headers = job.headers ?? []

  async function next() {
    setBusy(true)
    setErrors({})
    setGeneral(null)
    try {
      const clean = Object.fromEntries(Object.entries(mapping).filter(([, v]) => v !== ''))
      await api.post(`/imports/${job.id}/map`, { mapping: clean, ...(saveAs.trim() ? { save_as: saveAs.trim() } : {}) })
      // Mapear y validar van seguidos: la validación arma la vista previa (RN-IMP-01).
      onJob((await api.post<{ data: ImportJob }>(`/imports/${job.id}/validate`)).data)
    } catch (e) {
      const f = formErrors(e, t)
      setErrors(f.fields)
      setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <Warnings job={job} />
      <p>{t('imp.map_help', { n: job.total_rows ?? 0 })}</p>
      <div className="imp-mapa">
        {type?.columns.map((c) => {
          const err = errors[`mapping.${c.key}`]
          return (
            <div key={c.key} className="imp-mapa-fila">
              <label className="form-label" htmlFor={`imp-map-${c.key}`}>{c.label}{c.required ? ' *' : ''}{c.sensitive ? <span className="tag wa">{t('imp.sensitive')}</span> : null}</label>
              <select id={`imp-map-${c.key}`} className={`form-select${err ? ' is-invalid' : ''}`} value={mapping[c.key] ?? ''} disabled={!c.allowed}
                onChange={(e) => setMapping((m) => ({ ...m, [c.key]: e.target.value }))} aria-invalid={err ? true : undefined} aria-describedby={err ? `imp-map-${c.key}-err` : undefined}>
                <option value="">{t('imp.not_mapped')}</option>
                {headers.map((h) => <option key={h} value={h}>{h}</option>)}
              </select>
              {!c.allowed && <span className="sub2">{t('imp.not_allowed')}</span>}
              {err && <div id={`imp-map-${c.key}-err`} className="invalid-feedback">{err}</div>}
            </div>
          )
        })}
      </div>
      <div className="mb-3" style={{ maxWidth: 360 }}>
        <label className="form-label" htmlFor="imp-save-as">{t('imp.save_as')}</label>
        <input id="imp-save-as" className="form-control" value={saveAs} maxLength={120} onChange={(e) => setSaveAs(e.target.value)} />
        <span className="sub2">{t('imp.save_as_help')}</span>
      </div>
      {general && <div className="invalid-feedback mb-2" role="alert">{general}</div>}
      <div className="imp-acciones">
        <CancelButton job={job} onCancelled={onCancelled} />
        <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void next()}>
          {busy && <span className="spin" aria-hidden="true" />} {t('imp.validate')}
        </button>
      </div>
    </>
  )
}

function CancelButton({ job, onCancelled }: { job: ImportJob; onCancelled: () => void }) {
  const { t } = useT()
  const { can } = useSession()
  const [busy, setBusy] = useState(false)
  if (!can('imports.imports.cancel') || !(job.allowed_actions ?? []).includes('cancel')) return null
  return (
    <button type="button" className="btn btn-sec" disabled={busy} onClick={() => {
      setBusy(true)
      void api.post(`/imports/${job.id}/cancel`).then(onCancelled).finally(() => setBusy(false))
    }}>{t('imp.discard')}</button>
  )
}

function ErrorsDownload({ job }: { job: ImportJob }) {
  const { t } = useT()
  const [busy, setBusy] = useState(false)
  if (!job.error_rows && job.status !== 'failed') return null
  return (
    <button type="button" className="btn btn-sec" disabled={busy} aria-busy={busy} onClick={() => {
      setBusy(true)
      void api.download(`/imports/${job.id}/errors/download`).then(({ blob, filename }) => saveFile(blob, filename)).catch(() => undefined).finally(() => setBusy(false))
    }}><Icon name="download" /> {t('imp.download_errors')}</button>
  )
}

function ReviewStep({ job, type, onJob, onCancelled }: { job: ImportJob; type: ImportType | undefined; onJob: (j: ImportJob) => void; onCancelled: () => void }) {
  const { t } = useT()
  const { can } = useSession()
  const [filter, setFilter] = useState<'all' | 'invalid' | 'valid'>(job.error_rows ? 'invalid' : 'all')
  const [rows, setRows] = useState<RowsPage | null>(null)
  const [page, setPage] = useState(1)
  const [reviewed, setReviewed] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const columns = useMemo(() => {
    const mapping = (job.mapping ?? {}) as Record<string, string>
    return (type?.columns ?? []).filter((c) => mapping[c.key])
  }, [type, job.mapping])

  useEffect(() => {
    const q = filter === 'all' ? '' : `&filter[status]=${filter}`
    void api.get<RowsPage>(`/imports/${job.id}/rows?page=${page}&per_page=50${q}`).then(setRows).catch(() => setRows(null))
  }, [job.id, job.status, filter, page])

  async function run(action: 'validate' | 'execute') {
    setBusy(true)
    setError(null)
    try {
      onJob((await api.post<{ data: ImportJob }>(`/imports/${job.id}/${action}`, action === 'execute' ? { confirm: true } : undefined)).data)
    } catch (e) {
      setError(e instanceof ApiRequestError ? e.message : t('auth.generic_error'))
    } finally {
      setBusy(false)
    }
  }

  const policy = ((job.summary as { error_policy?: string } | undefined)?.error_policy) ?? type?.error_policy
  const pages = rows ? Math.max(1, Math.ceil(rows.meta.total / rows.meta.per_page)) : 1

  return (
    <>
      <Warnings job={job} />
      <div className="imp-cifras">
        <div><b>{job.total_rows ?? 0}</b><span>{t('imp.total')}</span></div>
        <div className="ok"><b>{job.valid_rows ?? 0}</b><span>{t('imp.valid')}</span></div>
        <div className={job.error_rows ? 'er' : undefined}><b>{job.error_rows ?? 0}</b><span>{t('imp.with_errors')}</span></div>
      </div>
      {job.status === 'with_errors' && <div className="nota imp-aviso" role="status">{t(policy === 'fix_all' ? 'imp.fix_all_help' : 'imp.no_valid_help')}</div>}
      {job.status === 'validated' && (job.error_rows ?? 0) > 0 && <div className="nota" role="status">{t('imp.only_valid_help', { n: job.error_rows ?? 0 })}</div>}
      <div className="chips" role="group" aria-label={t('imp.filter')}>
        {(['all', 'invalid', 'valid'] as const).map((f) => (
          <button key={f} type="button" aria-pressed={filter === f} onClick={() => { setFilter(f); setPage(1) }}>
            {t(`imp.filter_${f}`)} <span className="n">{f === 'all' ? job.total_rows ?? 0 : f === 'valid' ? job.valid_rows ?? 0 : job.error_rows ?? 0}</span>
          </button>
        ))}
      </div>
      <div className="tabla-wrap">
        <table className="table">
          <thead><tr><th>{t('imp.col_row')}</th>{columns.map((c) => <th key={c.key}>{c.label}</th>)}<th>{t('imp.col_status')}</th><th>{t('imp.col_problems')}</th></tr></thead>
          <tbody>
            {rows === null && <tr><td colSpan={columns.length + 3}><span className="esq" style={{ width: '40%' }} /></td></tr>}
            {rows?.data.length === 0 && <tr><td colSpan={columns.length + 3}><EmptyState title={t('imp.rows_empty')} text="" /></td></tr>}
            {rows?.data.map((r) => (
              <tr key={r.row_number}>
                <td>{r.row_number}</td>
                {columns.map((c) => <td key={c.key}>{r.values[c.key] ?? ''}</td>)}
                <td><span className={`st ${ROW_TONE[r.status] ?? 'gr'}`}>{t(`imp.row_${r.status}`)}</span></td>
                <td>{[...r.errors, ...r.warnings].map((p, i) => <span key={i} className={`sub2${r.errors.includes(p) ? ' imp-err' : ''}`}>{p.message}</span>)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {pages > 1 && (
        <div className="imp-paginas">
          <button type="button" className="btn btn-sec" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>{t('imp.prev')}</button>
          <span>{t('imp.page', { page, pages })}</span>
          <button type="button" className="btn btn-sec" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>{t('imp.next')}</button>
        </div>
      )}
      {error && <div className="invalid-feedback mb-2" role="alert">{error}</div>}
      {job.status === 'validated' && can('imports.imports.execute') && (
        <label className="imp-confirmo">
          <input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} /> {t('imp.reviewed', { n: job.valid_rows ?? 0 })}
        </label>
      )}
      <div className="imp-acciones">
        <CancelButton job={job} onCancelled={onCancelled} />
        <ErrorsDownload job={job} />
        {job.status === 'with_errors' && can('imports.imports.validate') && (
          <button type="button" className="btn btn-sec" disabled={busy} onClick={() => void run('validate')}>{t('imp.revalidate')}</button>
        )}
        {job.status === 'validated' && can('imports.imports.execute') && (
          <button type="button" className="btn btn-primary" disabled={!reviewed || busy} aria-busy={busy} onClick={() => void run('execute')}>
            {busy && <span className="spin" aria-hidden="true" />} {t('imp.import', { n: job.valid_rows ?? 0 })}
          </button>
        )}
      </div>
    </>
  )
}

function ResultStep({ job, onJob, onToast }: { job: ImportJob; onJob: (j: ImportJob) => void; onToast: (m: string) => void }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function revert() {
    setBusy(true)
    setError(null)
    try {
      onJob((await api.post<{ data: ImportJob }>(`/imports/${job.id}/revert`)).data)
      onToast(t('imp.reverted'))
    } catch (e) {
      setError(e instanceof ApiRequestError ? e.message : t('auth.generic_error'))
    } finally {
      setBusy(false)
      setConfirming(false)
    }
  }

  if (job.status === 'importing') {
    return <p role="status" aria-live="polite"><span className="spin" aria-hidden="true" /> {t('imp.importing', { n: job.valid_rows ?? 0 })}</p>
  }

  return (
    <>
      <p role="status">
        <span className={`st ${TONE[job.status] ?? 'gr'}`}>{t(`imp.status_${job.status}`)}</span>
        {job.finished_at && <span className="sub2">{formatDateTime(locale, job.finished_at)}</span>}
      </p>
      {job.status === 'completed' && <p>{t('imp.completed_text', { n: job.imported_rows ?? 0, skipped: job.error_rows ?? 0 })}</p>}
      {job.status === 'failed' && <div className="nota imp-aviso" role="alert">{t('imp.failed_text')} {job.error_message}</div>}
      {job.status === 'reverted' && <p>{t('imp.reverted_text')}</p>}
      {error && <div className="invalid-feedback mb-2" role="alert">{error}</div>}
      <div className="imp-acciones">
        <ErrorsDownload job={job} />
        <CancelButton job={job} onCancelled={() => onToast(t('imp.cancelled'))} />
        {(job.allowed_actions ?? []).includes('revert') && can('imports.imports.revert') && !confirming && (
          <button type="button" className="btn btn-sec" onClick={() => setConfirming(true)}><Icon name="trash" /> {t('imp.revert')}</button>
        )}
        {confirming && (
          <>
            <span>{t('imp.revert_confirm', { n: job.imported_rows ?? 0 })}</span>
            <button type="button" className="btn btn-sec" onClick={() => setConfirming(false)}>{t('imp.keep')}</button>
            <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void revert()}>{t('imp.revert_yes')}</button>
          </>
        )}
      </div>
    </>
  )
}
