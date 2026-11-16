'use client'

import { useSearchParams } from 'next/navigation'
import { useCallback, useEffect, useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { ticketTone } from '@/modules/selfservice/portal'
import { TicketDetail } from '@/modules/selfservice/TicketDetail'
import type { Ticket, TicketCategory } from '@/modules/selfservice/types'
import { Card } from '@/ui/components/Card'
import { Modal } from '@/ui/components/Modal'
import { PageHeader } from '@/ui/components/PageHeader'
import { SkeletonBlock } from '@/ui/components/Skeleton'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'
import { Tabs } from '@/ui/components/Tabs'
import { useToast } from '@/ui/components/Toast'

const VIEWS = { open: 'new,in_progress,waiting_info', resolved: 'resolved', closed: 'closed' } as const
type View = keyof typeof VIEWS | 'mine'

/**
 * ESS-5 · Solicitudes a RRHH: la bandeja de quien atiende (abiertas, asignadas a mí, resueltas y cerradas)
 * con el detalle y sus acciones, y la configuración de los tipos de solicitud (helpdesk.settings.update).
 */
export function TicketsAdminScreen() {
  const { t, locale } = useT()
  const params = useSearchParams()
  const { toast, node: toastNode } = useToast()
  const [tab, setTab] = useState('tickets')
  const [view, setView] = useState<View>('open')
  const [rows, setRows] = useState<Ticket[] | null>(null)
  const [meta, setMeta] = useState<{ can_manage_categories: boolean; is_staff: boolean } | null>(null)
  const [categories, setCategories] = useState<TicketCategory[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [open, setOpen] = useState<Ticket | null>(null)
  const [editing, setEditing] = useState<Partial<TicketCategory> | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const q = view === 'mine' ? '?filter[assigned_to_me]=true' : `?filter[status]=${VIEWS[view]}`
      const res = await api.get<{ data: Ticket[]; meta: { can_manage_categories: boolean; is_staff: boolean } }>(`/tickets${q}`)
      setRows(res.data)
      setMeta(res.meta)
      if (res.meta.can_manage_categories) {
        setCategories((await api.get<{ data: TicketCategory[] }>('/ticket-categories?include_archived=true')).data)
      }
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [view])

  useEffect(() => { void load() }, [load])

  const openTicket = useCallback(async (id: string) => {
    try {
      setOpen((await api.get<{ data: Ticket }>(`/tickets/${id}`)).data)
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('common.error'), 'error')
    }
  }, [toast, t])

  const deepLink = params?.get('id') ?? null
  useEffect(() => { if (deepLink) void openTicket(deepLink) }, [deepLink, openTicket])

  async function saveCategory(e: React.FormEvent) {
    e.preventDefault()
    if (!editing) return
    setBusy(true)
    try {
      const body = { name: editing.name, description: editing.description ?? null, requires_attachment: Boolean(editing.requires_attachment), is_confidential: Boolean(editing.is_confidential) }
      if (editing.id) await api.request(`/ticket-categories/${editing.id}`, 'PATCH', body)
      else await api.post('/ticket-categories', { ...body, code: editing.code })
      setEditing(null)
      toast(t('tickets.category_saved'))
      await load()
    } catch (err) {
      toast(err instanceof ApiRequestError ? err.message : t('common.error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  async function toggle(c: TicketCategory) {
    try {
      await api.request(`/ticket-categories/${c.id}`, 'PATCH', { status: c.status === 'active' ? 'archived' : 'active' })
      await load()
    } catch (err) {
      toast(err instanceof ApiRequestError ? err.message : t('common.error'), 'error')
    }
  }

  const list = (
    <>
      <div className="chips mb-3" role="group" aria-label={t('tickets.filter')}>
        {(['open', 'mine', 'resolved', 'closed'] as View[]).map((v) => (
          <button key={v} type="button" className={`chip${view === v ? ' on' : ''}`} aria-pressed={view === v} onClick={() => setView(v)}>{t(`tickets.view_${v}`)}</button>
        ))}
      </div>
      {!rows ? <SkeletonBlock height={240} /> : rows.length === 0 ? <EmptyState title={t('tickets.admin_empty_title')} text={t('tickets.admin_empty_text')} /> : (
        <Card flush>
          <table className="table">
            <thead><tr>
              <th>{t('tickets.number')}</th><th>{t('tickets.subject')}</th><th>{t('tickets.requester')}</th>
              <th>{t('tickets.assignee')}</th><th>{t('tickets.state')}</th><th>{t('tickets.created')}</th>
            </tr></thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id}>
                  <td><button type="button" className="btn btn-ter btn-sm" onClick={() => void openTicket(r.id)}>{r.number}</button></td>
                  <td>{r.subject}<span className="sub2"> · {r.category_name}</span></td>
                  <td>{r.person_name ?? r.requester_name ?? '—'}</td>
                  <td>{r.assignee_name ?? t('tickets.unassigned')}</td>
                  <td>
                    <StatusBadge tone={ticketTone(r.status)}>{t(`tickets.status_${r.status}`)}</StatusBadge>{' '}
                    {r.overdue && <StatusBadge tone="er">{t('tickets.overdue')}</StatusBadge>}
                  </td>
                  <td>{formatDate(locale, r.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </>
  )

  const types = (
    <Card title={t('tickets.categories')} actions={<button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing({ code: '', name: '', requires_attachment: false, is_confidential: false })}>{t('tickets.new_category')}</button>}>
      {!categories ? <SkeletonBlock height={160} /> : (
        <ul className="portal-list">
          {categories.map((c) => (
            <li key={c.id}>
              <div className="portal-link">
                <b>{c.name}</b>
                {c.description && <span className="sub2">{c.description}</span>}
                <span>
                  {c.requires_attachment && <Tag>{t('tickets.requires_attachment')}</Tag>}{' '}
                  {c.is_confidential && <Tag tone="pe">{t('tickets.confidential')}</Tag>}{' '}
                  {c.status === 'archived' && <Tag tone="gr">{t('tickets.archived')}</Tag>}
                </span>
              </div>
              <span>
                <button type="button" className="btn btn-ter btn-sm" onClick={() => setEditing(c)}>{t('common.edit')}</button>{' '}
                <button type="button" className="btn btn-ter btn-sm" onClick={() => void toggle(c)}>{c.status === 'active' ? t('tickets.archive') : t('tickets.restore')}</button>
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )

  return (
    <>
      <PageHeader title={t('tickets.admin_title')} sub={t('tickets.admin_sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {failed ? <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />
          : meta?.can_manage_categories
            ? <Tabs items={[{ id: 'tickets', label: t('tickets.tab_inbox'), content: list }, { id: 'types', label: t('tickets.categories'), content: types }]} value={tab} onChange={setTab} label={t('tickets.admin_title')} />
            : list}
      </main>
      {open && <TicketDetail ticket={open} staff toast={toast} onClose={() => setOpen(null)} onChange={(tk) => { setOpen(tk); void load() }} />}
      {editing && (
        <Modal title={editing.id ? t('tickets.edit_category') : t('tickets.new_category')} onClose={() => setEditing(null)} footer={
          <>
            <button type="button" className="btn btn-sec" onClick={() => setEditing(null)}>{t('common.cancel')}</button>
            <button type="submit" form="form-category" className="btn btn-primary" disabled={busy || !editing.name?.trim() || (!editing.id && !editing.code?.trim())}>{t('common.save')}</button>
          </>
        }>
          <form id="form-category" onSubmit={(e) => void saveCategory(e)}>
            {!editing.id && (
              <>
                <label className="form-label" htmlFor="cat-code">{t('tickets.code')}</label>
                <input id="cat-code" className="form-control" required pattern="[a-z0-9_]+" maxLength={40} value={editing.code ?? ''} onChange={(e) => setEditing((c) => ({ ...c, code: e.target.value }))} />
              </>
            )}
            <label className="form-label mt-2" htmlFor="cat-name">{t('tickets.name')}</label>
            <input id="cat-name" className="form-control" required maxLength={120} value={editing.name ?? ''} onChange={(e) => setEditing((c) => ({ ...c, name: e.target.value }))} />
            <label className="form-label mt-2" htmlFor="cat-desc">{t('tickets.description')}</label>
            <input id="cat-desc" className="form-control" maxLength={500} value={editing.description ?? ''} onChange={(e) => setEditing((c) => ({ ...c, description: e.target.value }))} />
            <label className="form-check mt-2">
              <input type="checkbox" className="form-check-input" checked={Boolean(editing.requires_attachment)} onChange={(e) => setEditing((c) => ({ ...c, requires_attachment: e.target.checked }))} />{' '}
              {t('tickets.requires_attachment')}
            </label>
            <label className="form-check">
              <input type="checkbox" className="form-check-input" checked={Boolean(editing.is_confidential)} onChange={(e) => setEditing((c) => ({ ...c, is_confidential: e.target.checked }))} />{' '}
              {t('tickets.confidential')}
            </label>
          </form>
        </Modal>
      )}
      {toastNode}
    </>
  )
}
