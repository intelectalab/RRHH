'use client'

import { useCallback, useEffect, useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Card } from '@/ui/components/Card'
import { FileUploader } from '@/ui/components/FileUploader'
import { Modal } from '@/ui/components/Modal'
import { PageHeader } from '@/ui/components/PageHeader'
import { SkeletonBlock } from '@/ui/components/Skeleton'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge } from '@/ui/components/Status'
import { Tabs } from '@/ui/components/Tabs'
import { useToast } from '@/ui/components/Toast'
import { EmploymentSwitch } from './EmploymentSwitch'
import { FileViewer } from './FileViewer'
import { certificateTone, missingAttachment, ticketTone } from './portal'
import { TicketDetail } from './TicketDetail'
import type { CertificateRequest, CertificateType, OwnEmployment, Ticket, TicketCategory } from './types'

type Types = { employments: OwnEmployment[]; employment: OwnEmployment | null; types: CertificateType[] }

/**
 * ESS-4 · ESS-5 · Mis trámites: pedir constancias (las de emisión automática salen al instante; las demás
 * pasan por aprobación o por RRHH) y solicitudes a RRHH con su estado e historial. Todo lo decide el servidor:
 * qué constancias están disponibles, qué acciones se pueden hacer y en qué estado queda cada trámite.
 */
export function MyRequestsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const canCertificates = can('people.certificates.request')
  const canTickets = can('helpdesk.tickets.create')
  const [tab, setTab] = useState(canCertificates ? 'certificates' : 'tickets')
  const [employmentId, setEmploymentId] = useState<string | null>(null)
  const [types, setTypes] = useState<Types | null>(null)
  const [certs, setCerts] = useState<CertificateRequest[] | null>(null)
  const [tickets, setTickets] = useState<Ticket[] | null>(null)
  const [categories, setCategories] = useState<TicketCategory[]>([])
  const [failed, setFailed] = useState(false)
  const [asking, setAsking] = useState<CertificateType | null>(null)
  const [purpose, setPurpose] = useState('')
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState({ category_id: '', subject: '', description: '' })
  const [files, setFiles] = useState<File[]>([])
  const [busy, setBusy] = useState(false)
  const [open, setOpen] = useState<Ticket | null>(null)
  const [viewing, setViewing] = useState<{ url: string; title: string } | null>(null)

  const load = useCallback(async () => {
    try {
      const q = employmentId ? `?employment_id=${encodeURIComponent(employmentId)}` : ''
      if (canCertificates) {
        setTypes((await api.get<{ data: Types }>(`/certificate-types${q}`)).data)
        setCerts((await api.get<{ data: CertificateRequest[] }>('/certificate-requests')).data)
      }
      if (canTickets) {
        setCategories((await api.get<{ data: TicketCategory[] }>('/ticket-categories')).data)
        setTickets((await api.get<{ data: Ticket[] }>('/tickets?filter[mine]=true')).data)
      }
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [employmentId, canCertificates, canTickets])

  useEffect(() => { void load() }, [load])

  const fail = (e: unknown) => toast(e instanceof ApiRequestError ? e.message : t('common.error'), 'error')
  const employment = types?.employment ?? null

  async function requestCertificate() {
    if (!asking || !employment) return
    setBusy(true)
    try {
      const r = (await api.post<{ data: CertificateRequest }>('/certificate-requests', { employment_id: employment.id, kind: asking.kind, purpose: purpose || null })).data
      toast(r.status === 'issued' ? t('certificates.issued_now') : t('certificates.sent'))
      setAsking(null)
      setPurpose('')
      await load()
    } catch (e) {
      fail(e)
    } finally {
      setBusy(false)
    }
  }

  const category = categories.find((c) => c.id === form.category_id)

  async function createTicket(e: React.FormEvent) {
    e.preventDefault()
    if (missingAttachment(category, files)) {
      toast(t('tickets.attachment_required'), 'error')
      return
    }
    setBusy(true)
    try {
      const body = new FormData()
      body.append('category_id', form.category_id)
      body.append('subject', form.subject)
      if (form.description) body.append('description', form.description)
      if (employment) body.append('employment_id', employment.id)
      if (files[0]) body.append('file', files[0])
      const created = (await api.postForm<{ data: Ticket }>('/tickets', body)).data
      toast(t('tickets.created_ok', { number: created.number }))
      setCreating(false)
      setForm({ category_id: '', subject: '', description: '' })
      setFiles([])
      await load()
      setOpen(created)
    } catch (err) {
      fail(err)
    } finally {
      setBusy(false)
    }
  }

  async function openTicket(id: string) {
    try {
      setOpen((await api.get<{ data: Ticket }>(`/tickets/${id}`)).data)
    } catch (e) {
      fail(e)
    }
  }

  const certificatesTab = (
    <div className="portal-cards">
      <Card title={t('certificates.catalog')}>
        {!types ? <SkeletonBlock height={120} /> : types.types.length === 0 ? (
          <p className="text-secondary">{t('certificates.none_available')}</p>
        ) : (
          <ul className="portal-list">
            {types.types.map((c) => (
              <li key={c.kind}>
                <div className="portal-link">
                  <b>{c.name}</b>
                  <span className="sub2">{c.description}</span>
                  {c.auto_issue && <StatusBadge tone="ok">{t('certificates.instant')}</StatusBadge>}
                </div>
                <button type="button" className="btn btn-primary btn-sm" onClick={() => { setPurpose(''); setAsking(c) }}>{t('certificates.request')}</button>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <Card title={t('certificates.mine')}>
        {!certs ? <SkeletonBlock height={120} /> : certs.length === 0 ? (
          <p className="text-secondary">{t('certificates.none')}</p>
        ) : (
          <ul className="portal-list">
            {certs.map((c) => (
              <li key={c.id}>
                <div className="portal-link">
                  <b>{c.kind_name}</b>
                  <span className="sub2">{formatDate(locale, c.created_at)}{c.purpose ? ` · ${c.purpose}` : ''}</span>
                  <StatusBadge tone={certificateTone(c.status)}>{t(`certificates.status_${c.status}`)}</StatusBadge>
                  {c.rejection_reason && <span className="sub2">{c.rejection_reason}</span>}
                </div>
                {c.status === 'issued' && (
                  <button type="button" className="btn btn-ter btn-sm" onClick={() => setViewing({ url: `/certificate-requests/${c.id}/file?inline=true`, title: c.kind_name })}>{t('portal.open')}</button>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )

  const ticketsTab = (
    <Card title={t('tickets.mine')} actions={<button type="button" className="btn btn-primary btn-sm" onClick={() => setCreating(true)}>{t('tickets.new')}</button>}>
      {!tickets ? <SkeletonBlock height={120} /> : tickets.length === 0 ? (
        <EmptyState title={t('tickets.none_title')} text={t('tickets.none_text')} />
      ) : (
        <ul className="portal-list">
          {tickets.map((tk) => (
            <li key={tk.id}>
              <button type="button" className="portal-link" onClick={() => void openTicket(tk.id)}>
                <b>{tk.number} · {tk.subject}</b>
                <span className="sub2">{tk.category_name} · {formatDate(locale, tk.updated_at)}</span>
                <StatusBadge tone={ticketTone(tk.status)}>{t(`tickets.status_${tk.status}`)}</StatusBadge>
              </button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )

  const items = [
    ...(canCertificates ? [{ id: 'certificates', label: t('certificates.tab'), content: certificatesTab }] : []),
    ...(canTickets ? [{ id: 'tickets', label: t('tickets.tab'), content: ticketsTab, count: tickets?.filter((x) => x.status === 'waiting_info').length || undefined }] : []),
  ]

  return (
    <>
      <PageHeader title={t('portal.requests_title')} sub={t('portal.requests_sub')} />
      <main className="main portal-main" id="contenido" tabIndex={-1}>
        {failed ? (
          <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />
        ) : (
          <>
            {types && types.employments.length > 1 && employment && (
              <EmploymentSwitch employments={types.employments} value={employment.id} onChange={setEmploymentId} />
            )}
            {items.length > 0 && <Tabs items={items} value={tab} onChange={setTab} label={t('portal.requests_title')} />}
          </>
        )}
      </main>

      {asking && (
        <Modal title={asking.name} onClose={() => setAsking(null)} footer={
          <>
            <button type="button" className="btn btn-sec" onClick={() => setAsking(null)}>{t('common.cancel')}</button>
            <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void requestCertificate()}>{t('certificates.request')}</button>
          </>
        }>
          <p>{asking.description}</p>
          <p className="text-secondary">{asking.auto_issue ? t('certificates.instant_hint') : t('certificates.review_hint')}</p>
          <label className="form-label" htmlFor="cert-purpose">{t('certificates.purpose')}</label>
          <input id="cert-purpose" className="form-control" value={purpose} maxLength={300} onChange={(e) => setPurpose(e.target.value)} placeholder={t('certificates.purpose_hint')} />
        </Modal>
      )}

      {creating && (
        <Modal title={t('tickets.new')} onClose={() => setCreating(false)} footer={
          <>
            <button type="button" className="btn btn-sec" onClick={() => setCreating(false)}>{t('common.cancel')}</button>
            <button type="submit" form="form-ticket" className="btn btn-primary" disabled={busy || !form.category_id || !form.subject.trim()} aria-busy={busy}>{t('tickets.send')}</button>
          </>
        }>
          <form id="form-ticket" onSubmit={(e) => void createTicket(e)}>
            <label className="form-label" htmlFor="tk-cat">{t('tickets.category')}</label>
            <select id="tk-cat" className="form-select" required value={form.category_id} onChange={(e) => setForm((f) => ({ ...f, category_id: e.target.value }))}>
              <option value="">{t('tickets.choose_category')}</option>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
            {category?.description && <p className="sub2">{category.description}</p>}
            <label className="form-label mt-2" htmlFor="tk-subject">{t('tickets.subject')}</label>
            <input id="tk-subject" className="form-control" required maxLength={200} value={form.subject} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} />
            <label className="form-label mt-2" htmlFor="tk-desc">{t('tickets.description')}</label>
            <textarea id="tk-desc" className="form-control" rows={4} maxLength={5000} value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} />
            <FileUploader label={category?.requires_attachment ? t('tickets.attach_required') : t('tickets.attach')} files={files} onChange={setFiles}
              maxFiles={1} maxBytes={10 * 1024 * 1024} accept={['application/pdf', 'image/jpeg', 'image/png']} />
          </form>
        </Modal>
      )}

      {open && (
        <TicketDetail ticket={open} staff={false} toast={toast} onClose={() => setOpen(null)}
          onChange={(tk) => { setOpen(tk); void load() }} />
      )}
      {viewing && <FileViewer url={viewing.url} title={viewing.title} onClose={() => setViewing(null)} />}
      {toastNode}
    </>
  )
}
