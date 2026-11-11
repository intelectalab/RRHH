'use client'

import { useSearchParams } from 'next/navigation'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDateTime, initials, relativeTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { notifyInboxChanged, typeLabel, type Approval } from './shared'

/** Centro de aprobaciones (WFL-4): bandeja única por tipo, detalle, aprobar, rechazar con motivo y lote seguro. */
export function ApprovalsScreen() {
  const { t, locale } = useT()
  const { session, can } = useSession()
  const params = useSearchParams()
  const { toast, node: toastNode } = useToast()
  const wide = ['organization', 'legal_entity'].includes(session.scopes['workflow.approval_requests.view'] ?? '')
  const [view, setView] = useState<'mine' | 'all'>('mine')
  const [rows, setRows] = useState<Approval[] | null>(null)
  const [resolved, setResolved] = useState<Approval[]>([])
  const [loadError, setLoadError] = useState(false)
  const [type, setType] = useState<string | null>(null)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [leaving, setLeaving] = useState<Set<string>>(new Set())
  const [open, setOpen] = useState<{ id: string; intent: 'review' | 'reject' } | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const query = view === 'mine' ? 'filter[assigned]=me&filter[status]=pending' : 'filter[status]=pending'
      const [pending, recent] = await Promise.all([
        api.get<{ data: Approval[] }>(`/approval-requests?${query}&per_page=100`),
        api.get<{ data: Approval[] }>('/approval-requests?per_page=30'),
      ])
      // Las más antiguas primero: así nada se queda esperando al fondo.
      setRows([...pending.data].sort((a, b) => (a.created_at ?? '').localeCompare(b.created_at ?? '')))
      setResolved(recent.data.filter((r) => r.status !== 'pending').slice(0, 8))
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [view])

  useEffect(() => {
    setRows(null)
    setSelected(new Set())
    void load()
  }, [load])

  useEffect(() => {
    const id = params.get('id')
    if (id) setOpen({ id, intent: 'review' })
  }, [params])

  const counts = useMemo(() => {
    const c = new Map<string, number>()
    rows?.forEach((r) => c.set(r.subject_type, (c.get(r.subject_type) ?? 0) + 1))
    return [...c.entries()].sort((a, b) => b[1] - a[1])
  }, [rows])
  const visible = rows?.filter((r) => type === null || r.subject_type === type) ?? null
  const selectable = visible?.filter((r) => r.bulk_safe && r.can_decide) ?? []
  const allSelected = selectable.length > 0 && selectable.every((r) => selected.has(r.id))

  function removeRows(ids: string[]) {
    setLeaving(new Set(ids))
    window.setTimeout(() => {
      setLeaving(new Set())
      void load()
    }, 220)
    setSelected((s) => new Set([...s].filter((id) => !ids.includes(id))))
    notifyInboxChanged()
  }

  async function approve(row: Approval) {
    setBusy(true)
    try {
      await api.post(`/approval-requests/${row.id}/approve`, {})
      toast(t('approvals.approved_toast'))
      removeRows([row.id])
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  async function bulkApprove() {
    setBusy(true)
    try {
      const res = await api.post<{ data: { approved: string[]; failed: Array<{ id: string; code: string }> } }>('/approval-requests/bulk-approve', { ids: [...selected] })
      const { approved, failed } = res.data
      toast(failed.length ? t('approvals.bulk_partial', { ok: approved.length, fail: failed.length }) : t('approvals.bulk_done', { ok: approved.length }), failed.length ? 'error' : 'ok')
      removeRows(approved)
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <PageHeader
        title={t('approvals.title')}
        sub={rows ? t('approvals.sub', { n: rows.length }) : undefined}
        actions={wide && (
          <div className="seg" role="group" aria-label={t('approvals.title')}>
            {(['mine', 'all'] as const).map((v) => (
              <button key={v} type="button" className={view === v ? 'on' : undefined} aria-pressed={view === v} onClick={() => setView(v)}>{t(v === 'mine' ? 'approvals.tab_mine' : 'approvals.tab_all')}</button>
            ))}
          </div>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        {counts.length > 1 && (
          <div className="chips" role="group" aria-label={t('approvals.all_types')}>
            <button type="button" aria-pressed={type === null} onClick={() => setType(null)}>{t('approvals.all_types')} <span className="n">{rows?.length ?? 0}</span></button>
            {counts.map(([code, n]) => (
              <button key={code} type="button" aria-pressed={type === code} onClick={() => setType(code)}>{typeLabel(t, code)} <span className="n">{n}</span></button>
            ))}
          </div>
        )}
        <div className="grid-2">
          <section className="card">
            {selectable.length > 0 && (
              <div className="card-header">
                <label className="check">
                  <input type="checkbox" checked={allSelected} onChange={() => setSelected(allSelected ? new Set() : new Set(selectable.map((r) => r.id)))} />
                  {t('approvals.select_all')}
                </label>
                <span className="ctx">{t('approvals.bulk_hint')}</span>
              </div>
            )}
            {selected.size > 0 && (
              <div className="bulk" role="region" aria-live="polite">
                <b>{t('approvals.selected', { n: selected.size })}</b>
                <button type="button" className="btn btn-primary btn-sm ms-auto" disabled={busy} onClick={() => void bulkApprove()}><Icon name="check" /> {t('approvals.bulk_approve')}</button>
                <button type="button" className="btn btn-ter btn-sm" onClick={() => setSelected(new Set())}>{t('approvals.clear')}</button>
              </div>
            )}
            {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
            {!loadError && visible === null && [1, 2].map((i) => <div key={i} className="solicitud"><span /><span className="av l esq" /><span className="esq" style={{ width: `${40 + i * 15}%` }} /></div>)}
            {visible?.length === 0 && <EmptyState title={t('approvals.empty_title')} text={t(view === 'mine' ? 'approvals.empty_text' : 'approvals.empty_all_text')} />}
            {visible?.map((r) => {
              const current = r.tasks?.find((x) => x.status === 'pending' && x.level === r.current_step)
              return (
                <article key={r.id} className={`solicitud${selected.has(r.id) ? ' sel' : ''}${leaving.has(r.id) ? ' sale' : ''}`} aria-label={t('approvals.detail_title', { name: r.requester_name ?? '' })}>
                  <span className="chk">
                    {r.bulk_safe && r.can_decide && (
                      <input type="checkbox" checked={selected.has(r.id)} aria-label={t('approvals.select_one', { name: r.requester_name ?? '' })}
                        onChange={() => setSelected((s) => { const n = new Set(s); if (n.has(r.id)) n.delete(r.id); else n.add(r.id); return n })} />
                    )}
                  </span>
                  <span className="av l" aria-hidden="true">{initials(r.requester_name)}</span>
                  <div className="min-w-0">
                    <button type="button" className="nm" onClick={() => setOpen({ id: r.id, intent: 'review' })}>{r.requester_name ?? '—'}</button>
                    <div className="mt-1">
                      <b>{typeLabel(t, r.subject_type)}</b> · {t('approvals.level', { n: r.current_step, total: r.levels ?? r.current_step })}{r.current_step_name ? ` · ${r.current_step_name}` : ''}
                      <span className="sub2" style={{ display: 'inline' }}> · {t('approvals.requested', { when: relativeTime(locale, r.created_at) })}</span>
                    </div>
                    <div className="tags">
                      {r.escalated && <span className="tag wa">{t('approvals.escalated')}</span>}
                      {r.due_at && (new Date(r.due_at) < new Date()
                        ? <span className="tag er">{t('approvals.overdue')}</span>
                        : <span className="tag gr">{t('approvals.due', { when: relativeTime(locale, r.due_at) })}</span>)}
                      {current?.delegated_from_name && <span className="tag in">{t('approvals.delegated', { name: current.delegated_from_name })}</span>}
                      {current?.assignee_kind === 'role' && <span className="tag pe">{t('approvals.to_role', { name: current.assignee_name ?? '' })}</span>}
                    </div>
                  </div>
                  {r.can_decide ? (
                    <div className="acc-sol">
                      {can('workflow.approval_requests.reject') && <button type="button" className="btn btn-pel-sec btn-sm" onClick={() => setOpen({ id: r.id, intent: 'reject' })}>{t('approvals.reject')}</button>}
                      {can('workflow.approval_requests.approve') && <button type="button" className="btn btn-primary btn-sm" disabled={busy} onClick={() => void approve(r)}><Icon name="check" /> {t('approvals.approve')}</button>}
                    </div>
                  ) : (
                    <div className="acc-sol"><button type="button" className="btn btn-sec btn-sm" onClick={() => setOpen({ id: r.id, intent: 'review' })}>{t('approvals.review')}</button></div>
                  )}
                </article>
              )
            })}
          </section>
          <section className="bloque-plano">
            <div className="cab"><h2>{t('approvals.resolved_title')}</h2></div>
            {resolved.length === 0 ? <p className="sub2">{t('approvals.resolved_empty')}</p> : (
              <ul className="lista-compacta" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                {resolved.map((r) => (
                  <li key={r.id}>
                    <span className="av s" aria-hidden="true">{initials(r.requester_name)}</span>
                    <button type="button" className="min-w-0" style={{ flex: 1, background: 'none', border: 0, padding: 0, textAlign: 'left', font: 'inherit', cursor: 'pointer', color: 'inherit' }} onClick={() => setOpen({ id: r.id, intent: 'review' })}>
                      <b>{r.requester_name ?? '—'}</b>
                      <span className="sub2">{typeLabel(t, r.subject_type)} · {relativeTime(locale, r.finished_at ?? r.updated_at)}</span>
                    </button>
                    <span className={`st ${r.status === 'approved' ? 'ok' : r.status === 'rejected' ? 'er' : 'gr'}`}>{t(`approvals.status_${r.status}`)}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      </main>
      {open && (
        <ApprovalPanel
          id={open.id}
          intent={open.intent}
          onClose={() => setOpen(null)}
          onDone={(msg, id) => { setOpen(null); toast(msg); removeRows([id]) }}
          formatWhen={(iso) => formatDateTime(locale, iso)}
        />
      )}
      {toastNode}
    </>
  )
}

function ApprovalPanel({ id, intent, onClose, onDone, formatWhen }: { id: string; intent: 'review' | 'reject'; onClose: () => void; onDone: (msg: string, id: string) => void; formatWhen: (iso: string | null | undefined) => string }) {
  const { t } = useT()
  const { can } = useSession()
  const [row, setRow] = useState<Approval | null>(null)
  const [missing, setMissing] = useState(false)
  const [mode, setMode] = useState<'review' | 'reject' | 'delegate'>(intent)
  const [comment, setComment] = useState('')
  const [delegateTo, setDelegateTo] = useState('')
  const [people, setPeople] = useState<Array<{ id: string; display_name: string }>>([])
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api.get<{ data: Approval }>(`/approval-requests/${id}`).then((r) => setRow(r.data)).catch(() => setMissing(true))
  }, [id])

  useEffect(() => {
    if (mode !== 'delegate' || people.length > 0 || !can('identity.users.view')) return
    void api.get<{ data: Array<{ id: string; display_name: string; status: string }> }>('/users?per_page=100&status=active')
      .then((r) => setPeople(r.data.filter((u) => u.status === 'active' && u.id !== row?.started_by_user_id)))
  }, [mode, people.length, can, row])

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    if (!row) return
    setBusy(true)
    setError(null)
    try {
      if (mode === 'reject') {
        await api.post(`/approval-requests/${row.id}/reject`, { comment })
        onDone(t('approvals.rejected_toast'), row.id)
      } else if (mode === 'delegate') {
        await api.post(`/approval-requests/${row.id}/delegate`, { to_user_id: delegateTo, comment: comment || null })
        onDone(t('approvals.delegated_toast'), row.id)
      } else {
        await api.post(`/approval-requests/${row.id}/approve`, { comment: comment || null })
        onDone(t('approvals.approved_toast'), row.id)
      }
    } catch (err) {
      const { fields, general } = formErrors(err, t)
      setError(fields.comment ?? fields.to_user_id ?? general)
    } finally {
      setBusy(false)
    }
  }

  const decide = row?.can_decide === true
  const footer = decide ? (
    <>
      {mode === 'review' && can('workflow.approval_requests.reject') && <button type="button" className="btn btn-pel-sec" onClick={() => setMode('reject')}>{t('approvals.reject')}</button>}
      {mode === 'review' && can('workflow.approval_requests.delegate') && can('identity.users.view') && <button type="button" className="btn btn-ter" onClick={() => setMode('delegate')}>{t('approvals.delegate')}</button>}
      {mode !== 'review' && <button type="button" className="btn btn-sec" onClick={() => setMode('review')}>{t('common.back')}</button>}
      <button type="submit" form="decision" className={`btn ${mode === 'reject' ? 'btn-pel-sec' : 'btn-primary'}`} disabled={busy || (mode === 'delegate' && !delegateTo)}>
        {busy && <span className="spin" aria-hidden="true" />}
        {t(mode === 'reject' ? 'approvals.reject' : mode === 'delegate' ? 'approvals.delegate' : 'approvals.approve')}
      </button>
    </>
  ) : <button type="button" className="btn btn-sec" onClick={onClose}>{t('common.close')}</button>

  return (
    <SidePanel title={row ? t('approvals.detail_title', { name: row.requester_name ?? '' }) : t('approvals.review')} onClose={onClose} closeLabel={t('common.close')} footer={row ? footer : undefined}>
      {missing && <p className="sub2">{t('common.load_error')}</p>}
      {!row && !missing && <span className="esq" style={{ width: '70%' }} />}
      {row && (
        <>
          <dl className="resumen">
            <dt>{t('flows.subject_type')}</dt><dd>{typeLabel(t, row.subject_type)}</dd>
            <dt>{t('approvals.flow')}</dt><dd>{row.flow_name}</dd>
            <dt>{t('tasks.col_status')}</dt><dd><span className={`st ${row.status === 'approved' ? 'ok' : row.status === 'rejected' ? 'er' : row.status === 'pending' ? 'wa' : 'gr'}`}>{t(`approvals.status_${row.status}`)}</span>{row.escalated && <span className="tag wa" style={{ marginLeft: 6 }}>{t('approvals.escalated')}</span>}</dd>
          </dl>
          <h3 className="small" style={{ fontWeight: 700, margin: '16px 0 8px' }}>{t('approvals.trace')}</h3>
          <ol className="traza">
            {row.tasks?.map((task) => (
              <li key={task.id} className={task.status === 'approved' ? 'ok' : task.status === 'rejected' ? 'er' : task.status === 'pending' ? 'wa' : undefined}>
                <b>{t('approvals.level', { n: task.level, total: row.levels ?? task.level })}{task.level_name ? ` · ${task.level_name}` : ''}</b>
                <span className="sub2">{task.assignee_name ?? '—'}{task.assignee_kind === 'role' ? ` (${t('tasks.role_tag')})` : ''} · {t(`approvals.task_${task.status}`)}{task.completed_at ? ` · ${formatWhen(task.completed_at)}` : ''}</span>
                {task.delegated_from_name && <span className="sub2">{t('approvals.delegated', { name: task.delegated_from_name })}</span>}
                {task.comment && <span className="sub2">{t('approvals.quoted', { text: task.comment })}</span>}
              </li>
            ))}
          </ol>
          {!decide && row.status === 'pending' && <p className="sub2">{t('approvals.not_yours')}</p>}
          {decide && (
            <form id="decision" onSubmit={submit} noValidate>
              {mode === 'delegate' && (
                <div className="mb-3">
                  <label className="form-label" htmlFor="d-to">{t('approvals.delegate_to')}</label>
                  <select id="d-to" className="form-select" value={delegateTo} onChange={(e) => setDelegateTo(e.target.value)} required>
                    <option value="">{t('approvals.delegate_pick')}</option>
                    {people.map((p) => <option key={p.id} value={p.id}>{p.display_name}</option>)}
                  </select>
                </div>
              )}
              <div className="mb-3">
                <label className="form-label" htmlFor="d-comment">{t(mode === 'reject' ? 'approvals.reason_label' : 'approvals.comment_label')}</label>
                <textarea id="d-comment" className={`form-control${error ? ' is-invalid' : ''}`} rows={3} value={comment} onChange={(e) => setComment(e.target.value)} required={mode === 'reject'} aria-describedby={mode === 'reject' ? 'd-help' : undefined} />
                {mode === 'reject' && <div id="d-help" className="small text-secondary">{t('approvals.reason_help')}</div>}
                {error && <div className="invalid-feedback" role="alert">{error}</div>}
              </div>
            </form>
          )}
        </>
      )}
    </SidePanel>
  )
}
