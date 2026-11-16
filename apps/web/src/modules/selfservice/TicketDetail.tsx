'use client'

import { useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { FileUploader } from '@/ui/components/FileUploader'
import { SidePanel } from '@/ui/components/SidePanel'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { FileViewer } from './FileViewer'
import { ticketTone } from './portal'
import type { Ticket, TicketAction } from './types'

/** Acciones que piden un mensaje (pedir información es obligatorio; resolver lo deja opcional). */
const NEEDS_MESSAGE: TicketAction[] = ['request-info', 'resolve']

/**
 * ESS-5 · Detalle de una solicitud a RRHH con su historial (cada cambio de estado y cada mensaje), el
 * formulario para responder con adjunto y las acciones que el servidor permite (allowed_actions).
 * Lo usan el portal del colaborador y la bandeja de RRHH; las notas internas solo las ve RRHH.
 */
export function TicketDetail({ ticket, staff, onChange, onClose, toast }: {
  ticket: Ticket
  staff: boolean
  onChange: (t: Ticket) => void
  onClose: () => void
  toast: (text: string, kind?: 'ok' | 'error') => void
}) {
  const { t, locale } = useT()
  const [body, setBody] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [internal, setInternal] = useState(false)
  const [busy, setBusy] = useState(false)
  const [viewing, setViewing] = useState<{ url: string; title: string } | null>(null)

  const fail = (e: unknown) => toast(e instanceof ApiRequestError ? e.message : t('common.error'), 'error')

  async function send() {
    if (!body.trim()) return
    setBusy(true)
    try {
      const form = new FormData()
      form.append('body', body)
      if (internal) form.append('is_internal_note', '1')
      if (files[0]) form.append('file', files[0])
      onChange((await api.postForm<{ data: Ticket }>(`/tickets/${ticket.id}/messages`, form)).data)
      setBody('')
      setFiles([])
      setInternal(false)
    } catch (e) {
      fail(e)
    } finally {
      setBusy(false)
    }
  }

  async function act(action: TicketAction) {
    const message = NEEDS_MESSAGE.includes(action) ? body.trim() : ''
    if (action === 'request-info' && !message) {
      toast(t('tickets.message_needed'), 'error')
      return
    }
    setBusy(true)
    try {
      onChange((await api.post<{ data: Ticket }>(`/tickets/${ticket.id}/${action}`, message ? { message } : {})).data)
      if (message) setBody('')
      toast(t(`tickets.done_${action.replace('-', '_')}`))
    } catch (e) {
      fail(e)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel title={`${ticket.number} · ${ticket.subject}`} onClose={onClose} closeLabel={t('common.close')} wide>
      <p>
        <StatusBadge tone={ticketTone(ticket.status)}>{t(`tickets.status_${ticket.status}`)}</StatusBadge>{' '}
        {ticket.category_name && <Tag>{ticket.category_name}</Tag>}{' '}
        {ticket.overdue && <StatusBadge tone="er">{t('tickets.overdue')}</StatusBadge>}
      </p>
      <dl className="ficha">
        {staff && <><dt>{t('tickets.requester')}</dt><dd>{ticket.person_name ?? ticket.requester_name ?? '—'}</dd></>}
        <dt>{t('tickets.assignee')}</dt><dd>{ticket.assignee_name ?? t('tickets.unassigned')}</dd>
        <dt>{t('tickets.created')}</dt><dd>{formatDateTime(locale, ticket.created_at)}</dd>
      </dl>

      <h3 className="h6 mt-3">{t('tickets.history')}</h3>
      <ol className="ticket-history">
        {(ticket.messages ?? []).map((m) => (
          <li key={m.id} className={m.is_internal_note ? 'internal' : undefined}>
            <span className="sub2">
              <b>{t(`tickets.event_${m.event}`)}</b> · {m.author_name ?? t('tickets.system')} · {formatDateTime(locale, m.created_at)}
              {m.is_internal_note && <> · <Tag tone="pe">{t('tickets.internal')}</Tag></>}
            </span>
            {m.body && <p className="ticket-body">{m.body}</p>}
            {m.has_file && (
              <button type="button" className="btn btn-ter btn-sm" onClick={() => setViewing({ url: `/tickets/${ticket.id}/messages/${m.id}/file`, title: m.file_name ?? t('tickets.attachment') })}>
                {m.file_name ?? t('tickets.attachment')}
              </button>
            )}
          </li>
        ))}
      </ol>

      {(ticket.can_message || ticket.allowed_actions.length > 0) && (
        <div className="mt-3">
          <label className="form-label" htmlFor="ticket-reply">{ticket.status === 'waiting_info' && !staff ? t('tickets.reply_info') : t('tickets.reply')}</label>
          <textarea id="ticket-reply" className="form-control" rows={3} value={body} onChange={(e) => setBody(e.target.value)} maxLength={5000} />
          {ticket.can_message && (
            <FileUploader label={t('tickets.attach')} files={files} onChange={setFiles} maxFiles={1} maxBytes={10 * 1024 * 1024}
              accept={['application/pdf', 'image/jpeg', 'image/png']} />
          )}
          {staff && ticket.can_message && (
            <label className="form-check mt-2">
              <input type="checkbox" className="form-check-input" checked={internal} onChange={(e) => setInternal(e.target.checked)} />{' '}
              {t('tickets.internal_note')}
            </label>
          )}
          <div className="ticket-actions mt-2">
            {ticket.can_message && (
              <button type="button" className="btn btn-primary btn-sm" disabled={busy || !body.trim()} aria-busy={busy} onClick={() => void send()}>{t('tickets.send')}</button>
            )}
            {ticket.allowed_actions.map((a) => (
              <button key={a} type="button" className="btn btn-sec btn-sm" disabled={busy} onClick={() => void act(a)}>{t(`tickets.action_${a.replace('-', '_')}`)}</button>
            ))}
          </div>
        </div>
      )}
      {viewing && <FileViewer url={viewing.url} title={viewing.title} onClose={() => setViewing(null)} />}
    </SidePanel>
  )
}
