'use client'

import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'next/navigation'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { cleanCode, signatureTone, signedCount, sortMine, type SignatureRequest } from '@/modules/documents/signatures'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { useToast } from '@/ui/components/Toast'
import { FileViewer } from './FileViewer'

/**
 * Portal → Mis firmas (DOC-6 · ESS-3): documentos por firmar desde el teléfono. Se abre el documento (queda
 * registrado), se pide el código de un solo uso y se firma, o se rechaza con motivo. El turno lo decide la API.
 */
export function MySignaturesScreen() {
  const { t, locale } = useT()
  const params = useSearchParams()
  const [rows, setRows] = useState<SignatureRequest[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [open, setOpen] = useState<SignatureRequest | null>(null)

  const load = useCallback(() => {
    setFailed(false)
    api.get<{ data: SignatureRequest[] }>('/me/signature-requests').then((r) => {
      const sorted = sortMine(r.data)
      setRows(sorted)
      const id = params.get('id')
      if (id) setOpen((cur) => cur ?? sorted.find((x) => x.id === id) ?? null)
    }).catch(() => setFailed(true))
  }, [params])
  useEffect(load, [load])

  return (
    <>
      <PageHeader title={t('signatures.mine_title')} sub={t('signatures.mine_sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {failed ? <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={load} />
          : !rows ? <span className="esq" style={{ width: 280 }} />
            : rows.length === 0 ? <EmptyState title={t('signatures.mine_empty_title')} text={t('signatures.mine_empty_text')} />
              : (
                <ul className="list-unstyled d-grid gap-2">
                  {rows.map((r) => {
                    const c = signedCount(r)
                    return (
                      <li key={r.id} className="card p-3">
                        <button type="button" className="btn-link text-start" onClick={() => setOpen(r)}>{r.title}</button>
                        <div className="sub2">
                          <StatusBadge tone={r.my_turn ? 'wa' : signatureTone(r.status)}>{r.my_turn ? t('signatures.your_turn') : t(`signatures.status_${r.status}`)}</StatusBadge>
                          {' '}{t('signatures.progress', { signed: c.signed, total: c.total })}
                          {r.expires_at && ['sent', 'viewed', 'in_progress'].includes(r.status) ? ` · ${t('signatures.expires', { date: formatDate(locale, r.expires_at.slice(0, 10)) })}` : ''}
                        </div>
                      </li>
                    )
                  })}
                </ul>
              )}
      </main>
      {open && <SignPanel request={open} onChange={(r) => { setOpen(r); load() }} onClose={() => setOpen(null)} />}
    </>
  )
}

function SignPanel({ request, onChange, onClose }: { request: SignatureRequest; onChange: (r: SignatureRequest) => void; onClose: () => void }) {
  const { t } = useT()
  const { toast, node: toastNode } = useToast()
  const [viewing, setViewing] = useState(false)
  const [codeSent, setCodeSent] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [reason, setReason] = useState('')
  const [declining, setDeclining] = useState(false)
  const [busy, setBusy] = useState(false)
  const canAct = !!request.my_turn

  async function run<T>(fn: () => Promise<T>, after: (v: T) => void) {
    setBusy(true)
    try {
      after(await fn())
    } catch (err) {
      const f = formErrors(err, t)
      toast(f.general ?? Object.values(f.fields)[0] ?? t('common.error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  const openDocument = () => {
    setViewing(true)
    if (canAct) void api.post<{ data: SignatureRequest }>(`/me/signature-requests/${request.id}/view`, {}).catch(() => undefined)
  }

  return (
    <SidePanel title={request.title} onClose={onClose} closeLabel={t('common.close')}>
      <p>
        <StatusBadge tone={signatureTone(request.status)}>{t(`signatures.status_${request.status}`)}</StatusBadge>
        <span className="sub2"> {t(`signatures.kind_${request.signature_kind}`)}</span>
      </p>
      <ol className="small">
        {request.signers.map((s) => (
          <li key={s.id}>{s.name}{s.role ? ` (${s.role})` : ''} — {t(`signatures.signer_${s.status}`)}</li>
        ))}
      </ol>
      <button type="button" className="btn btn-sec" onClick={openDocument}>{t('signatures.open_document')}</button>
      {canAct && (
        <section className="mt-3" aria-labelledby="sig-code">
          <h3 id="sig-code" className="h6">{t('signatures.sign_title')}</h3>
          <p className="small text-secondary">{t('signatures.sign_help')}</p>
          {!codeSent ? (
            <button type="button" className="btn btn-primary" disabled={busy}
              onClick={() => void run(() => api.post<{ data: { channel: string } }>(`/me/signature-requests/${request.id}/code`, {}), (r) => setCodeSent(r.data.channel))}>
              {t('signatures.send_code')}
            </button>
          ) : (
            <>
              <p className="small">{t('signatures.code_sent', { channel: t(`signatures.channel_${codeSent}`) })}</p>
              <label className="form-label" htmlFor="sig-code-input">{t('signatures.code')}</label>
              <input id="sig-code-input" className="form-control" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(e) => setCode(cleanCode(e.target.value))} />
              <div className="d-flex gap-2 mt-2">
                <button type="button" className="btn btn-primary" disabled={busy || code.length !== 6}
                  onClick={() => void run(() => api.post<{ data: SignatureRequest }>(`/me/signature-requests/${request.id}/sign`, { code }), (r) => { onChange(r.data); toast(t('signatures.signed')) })}>
                  {t('signatures.sign')}
                </button>
                <button type="button" className="btn btn-ter" disabled={busy}
                  onClick={() => void run(() => api.post<{ data: { channel: string } }>(`/me/signature-requests/${request.id}/code`, {}), (r) => setCodeSent(r.data.channel))}>
                  {t('signatures.resend_code')}
                </button>
              </div>
            </>
          )}
          {!declining ? (
            <button type="button" className="btn btn-link text-danger mt-3 p-0" onClick={() => setDeclining(true)}>{t('signatures.decline')}</button>
          ) : (
            <div className="mt-3">
              <label className="form-label" htmlFor="sig-decline">{t('signatures.decline_reason')}</label>
              <textarea id="sig-decline" className="form-control" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
              <button type="button" className="btn btn-danger mt-2" disabled={busy || reason.trim().length < 3}
                onClick={() => void run(() => api.post<{ data: SignatureRequest }>(`/me/signature-requests/${request.id}/decline`, { reason }), (r) => { onChange(r.data); toast(t('signatures.declined')) })}>
                {t('signatures.decline')}
              </button>
            </div>
          )}
        </section>
      )}
      {viewing && <FileViewer url={`/me/signature-requests/${request.id}/document`} title={request.title} onClose={() => setViewing(false)} />}
      {toastNode}
    </SidePanel>
  )
}
