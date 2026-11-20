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
import { signatureTone, signedCount, type Evidence, type SignatureRequest } from './signatures'

const FILTERS = ['open', 'completed', 'declined', 'all'] as const
const OPEN = 'sent,viewed,in_progress,draft'

async function save(endpoint: string) {
  const { blob, filename } = await api.download(endpoint)
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

/**
 * Documentos → Firmas (DOC-6 · DOC-7 · DOC-8): envíos a firma con el avance de cada firmante, recordatorios,
 * cancelación y la evidencia encadenada. El tipo de firma (propia o con certificado) lo fija el tipo documental.
 */
export function SignaturesScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [filter, setFilter] = useState<(typeof FILTERS)[number]>('open')
  const [rows, setRows] = useState<SignatureRequest[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [open, setOpen] = useState<SignatureRequest | null>(null)
  const [creating, setCreating] = useState(false)

  const load = useCallback(() => {
    setFailed(false)
    const status = filter === 'open' ? OPEN : filter === 'all' ? '' : filter
    api.get<{ data: SignatureRequest[] }>(`/signature-requests?per_page=100${status ? `&filter[status]=${status}` : ''}`)
      .then((r) => setRows(r.data)).catch(() => setFailed(true))
  }, [filter])
  useEffect(load, [load])

  return (
    <>
      <PageHeader title={t('signatures.title')} sub={t('signatures.sub')}
        actions={can('documents.signature_requests.create') ? <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>{t('signatures.new')}</button> : undefined} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="chips mb-3" role="group" aria-label={t('signatures.filter')}>
          {FILTERS.map((f) => (
            <button key={f} type="button" className={`chip${filter === f ? ' on' : ''}`} aria-pressed={filter === f} onClick={() => setFilter(f)}>{t(`signatures.filter_${f}`)}</button>
          ))}
        </div>
        {failed ? <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={load} />
          : !rows ? <span className="esq" style={{ width: 320 }} />
            : rows.length === 0 ? <EmptyState title={t('signatures.empty_title')} text={t('signatures.empty_text')} />
              : (
                <section className="card">
                  <table className="table">
                    <thead><tr><th>{t('signatures.document')}</th><th>{t('signatures.kind')}</th><th>{t('signatures.signers')}</th><th>{t('signatures.sent_at')}</th><th>{t('signatures.state')}</th></tr></thead>
                    <tbody>
                      {rows.map((r) => {
                        const c = signedCount(r)
                        return (
                          <tr key={r.id}>
                            <td><button type="button" className="btn-link" onClick={() => setOpen(r)}>{r.title}</button><span className="sub2"> {r.category_name}</span></td>
                            <td>{t(`signatures.kind_${r.signature_kind}`)}</td>
                            <td>{t('signatures.progress', { signed: c.signed, total: c.total })}</td>
                            <td>{r.sent_at ? formatDate(locale, r.sent_at.slice(0, 10)) : '—'}</td>
                            <td><StatusBadge tone={signatureTone(r.status)}>{t(`signatures.status_${r.status}`)}</StatusBadge></td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </section>
              )}
      </main>
      {creating && <CreatePanel onClose={() => setCreating(false)} onCreated={(r) => { setCreating(false); load(); setOpen(r); toast(t('signatures.created')) }} />}
      {open && <RequestPanel request={open} onChange={(r) => { setOpen(r); load() }} onClose={() => setOpen(null)} />}
      {toastNode}
    </>
  )
}

type Option = { id: string; label: string }

function CreatePanel({ onClose, onCreated }: { onClose: () => void; onCreated: (r: SignatureRequest) => void }) {
  const { t } = useT()
  const { can } = useSession()
  const [query, setQuery] = useState('')
  const [docs, setDocs] = useState<Option[]>([])
  const [users, setUsers] = useState<Option[]>([])
  const [documentId, setDocumentId] = useState('')
  const [signers, setSigners] = useState<string[]>([''])
  const [ordered, setOrdered] = useState(true)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [general, setGeneral] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    const id = setTimeout(() => {
      void api.get<{ data: Array<{ id: string; title: string; person_name?: string }> }>(`/documents?per_page=30${query.trim() ? `&q=${encodeURIComponent(query.trim())}` : ''}`)
        .then((r) => setDocs(r.data.map((d) => ({ id: d.id, label: `${d.title}${d.person_name ? ` · ${d.person_name}` : ''}` })))).catch(() => setDocs([]))
    }, 250)
    return () => clearTimeout(id)
  }, [query])
  useEffect(() => {
    void api.get<{ data: Array<{ id: string; display_name: string }> }>('/users?per_page=100&filter[status]=active')
      .then((r) => setUsers(r.data.map((u) => ({ id: u.id, label: u.display_name })))).catch(() => setUsers([]))
  }, [])

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    try {
      const r = await api.post<{ data: SignatureRequest }>('/signature-requests', {
        document_id: documentId, signing_order: ordered ? 'sequential' : 'parallel',
        signers: signers.filter(Boolean).map((user_id) => ({ user_id })), send: can('documents.signature_requests.send'),
      })
      onCreated(r.data)
    } catch (err) {
      const f = formErrors(err, t)
      setErrors(f.fields)
      setGeneral(f.general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel title={t('signatures.new')} onClose={onClose} closeLabel={t('common.close')} footer={
      <>
        <button type="button" className="btn btn-sec" onClick={onClose}>{t('common.cancel')}</button>
        <button type="submit" form="sig-new" className="btn btn-primary" disabled={busy || !documentId || !signers.some(Boolean)}>{t('signatures.send')}</button>
      </>
    }>
      <form id="sig-new" onSubmit={(e) => void submit(e)} noValidate>
        {general && <div className="alert alert-danger" role="alert">{general}</div>}
        <label className="form-label" htmlFor="sig-q">{t('signatures.search_document')}</label>
        <input id="sig-q" className="form-control" value={query} onChange={(e) => setQuery(e.target.value)} />
        <select className="form-select mt-2" aria-label={t('signatures.document')} value={documentId} onChange={(e) => setDocumentId(e.target.value)} aria-invalid={!!errors.document_id}>
          <option value="">—</option>
          {docs.map((d) => <option key={d.id} value={d.id}>{d.label}</option>)}
        </select>
        {errors.document_id && <div className="invalid-feedback d-block">{errors.document_id}</div>}
        <p className="small text-secondary mt-1">{t('signatures.kind_help')}</p>
        <h3 className="h6 mt-3">{t('signatures.signers')}</h3>
        {signers.map((s, i) => (
          <div key={i} className="d-flex gap-2 mb-2">
            <span className="sub2" aria-hidden>{`${i + 1}.`}</span>
            <select className="form-select" aria-label={t('signatures.signer_n', { n: i + 1 })} value={s} onChange={(e) => setSigners(signers.map((x, j) => (j === i ? e.target.value : x)))}>
              <option value="">—</option>
              {users.map((u) => <option key={u.id} value={u.id}>{u.label}</option>)}
            </select>
            {signers.length > 1 && <button type="button" className="btn btn-ter" aria-label={t('signatures.remove_signer')} onClick={() => setSigners(signers.filter((_, j) => j !== i))}>×</button>}
          </div>
        ))}
        {errors.signers && <div className="invalid-feedback d-block">{errors.signers}</div>}
        {signers.length < 10 && <button type="button" className="btn btn-ter" onClick={() => setSigners([...signers, ''])}>{t('signatures.add_signer')}</button>}
        <div className="form-check mt-3">
          <input id="sig-ordered" type="checkbox" className="form-check-input" checked={ordered} onChange={(e) => setOrdered(e.target.checked)} />
          <label className="form-check-label" htmlFor="sig-ordered">{t('signatures.ordered')}</label>
        </div>
      </form>
    </SidePanel>
  )
}

function RequestPanel({ request, onChange, onClose }: { request: SignatureRequest; onChange: (r: SignatureRequest) => void; onClose: () => void }) {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [busy, setBusy] = useState(false)
  const [reason, setReason] = useState('')
  const [evidence, setEvidence] = useState<Evidence | null>(null)
  const allowed = request.allowed_actions ?? []

  async function post(action: string, body: Record<string, unknown> = {}) {
    setBusy(true)
    try {
      onChange((await api.post<{ data: SignatureRequest }>(`/signature-requests/${request.id}/${action}`, body)).data)
      toast(t(`signatures.done_${action}`))
    } catch (err) {
      const f = formErrors(err, t)
      toast(f.general ?? Object.values(f.fields)[0] ?? t('common.error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel wide title={request.title} onClose={onClose} closeLabel={t('common.close')}>
      <p>
        <StatusBadge tone={signatureTone(request.status)}>{t(`signatures.status_${request.status}`)}</StatusBadge>
        <span className="sub2"> {t(`signatures.kind_${request.signature_kind}`)} · {t(`signatures.order_${request.signing_order}`)}</span>
      </p>
      {request.expires_at && <p className="small">{t('signatures.expires', { date: formatDate(locale, request.expires_at.slice(0, 10)) })}</p>}
      {request.cancellation_reason && <p className="small">{t('signatures.cancel_reason')}: {request.cancellation_reason}</p>}
      <table className="table">
        <thead><tr><th>#</th><th>{t('signatures.signer')}</th><th>{t('signatures.state')}</th><th>{t('signatures.signed_at')}</th></tr></thead>
        <tbody>
          {request.signers.map((s) => (
            <tr key={s.id}>
              <td>{s.order}</td>
              <td>{s.name}{s.role ? <span className="sub2"> {s.role}</span> : null}{s.is_turn ? <StatusBadge tone="wa">{t('signatures.turn')}</StatusBadge> : null}</td>
              <td>{t(`signatures.signer_${s.status}`)}{s.declined_reason ? <span className="sub2"> · {s.declined_reason}</span> : null}</td>
              <td>{s.signed_at ? formatDate(locale, s.signed_at.slice(0, 10)) : '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="d-flex flex-wrap gap-2">
        {allowed.includes('send') && can('documents.signature_requests.send') && <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void post('send')}>{t('signatures.send')}</button>}
        {allowed.includes('remind') && can('documents.signature_requests.remind') && <button type="button" className="btn btn-sec" disabled={busy} onClick={() => void post('remind')}>{t('signatures.remind')}</button>}
        <button type="button" className="btn btn-ter" onClick={() => void save(`/signature-requests/${request.id}/document`)}>{t('signatures.download_document')}</button>
        {request.has_certificate && <button type="button" className="btn btn-ter" onClick={() => void save(`/signature-requests/${request.id}/certificate`)}>{t('signatures.download_certificate')}</button>}
        {can('documents.signature_requests.evidence') && (
          <button type="button" className="btn btn-ter" onClick={() => void api.get<{ data: Evidence }>(`/signature-requests/${request.id}/evidence`).then((r) => setEvidence(r.data))}>{t('signatures.evidence')}</button>
        )}
      </div>
      {evidence && (
        <section className="mt-3" aria-labelledby="sig-ev">
          <h3 id="sig-ev" className="h6">{t('signatures.evidence')}</h3>
          <p className="small">
            <StatusBadge tone={evidence.chain_valid ? 'ok' : 'er'}>{t(evidence.chain_valid ? 'signatures.chain_ok' : 'signatures.chain_broken')}</StatusBadge>{' '}
            <StatusBadge tone={evidence.document_intact ? 'ok' : 'er'}>{t(evidence.document_intact ? 'signatures.document_ok' : 'signatures.document_changed')}</StatusBadge>
          </p>
          <p className="small text-break">{t('signatures.hash')}: <code>{evidence.document_hash}</code></p>
          <ol className="small">
            {evidence.events.map((e) => (
              <li key={e.id}>
                {t(`signatures.event_${e.event}`)} · {e.occurred_at.replace('T', ' ').slice(0, 19)}{e.ip ? ` · IP ${e.ip}` : ''}{e.otp_channel ? ` · ${t(`signatures.channel_${e.otp_channel}`)}` : ''}
                {e.provider_evidence?.certificate_serial ? ` · ${t('signatures.certificate_serial', { serial: e.provider_evidence.certificate_serial })}` : ''}
              </li>
            ))}
          </ol>
        </section>
      )}
      {allowed.includes('cancel') && can('documents.signature_requests.cancel') && (
        <div className="mt-3">
          <label className="form-label" htmlFor="sig-cancel">{t('signatures.cancel_reason')}</label>
          <textarea id="sig-cancel" className="form-control" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          <button type="button" className="btn btn-danger mt-2" disabled={busy || reason.trim().length < 3} onClick={() => void post('cancel', { reason })}>{t('signatures.cancel')}</button>
        </div>
      )}
      {toastNode}
    </SidePanel>
  )
}
