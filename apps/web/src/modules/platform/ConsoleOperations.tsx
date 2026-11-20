'use client'

import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { formErrors } from '@/lib/errors'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { Icon } from '@/ui/components/Icon'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag, type StatusTone } from '@/ui/components/Status'
import { useConsole, useConsoleTabs } from './ConsoleShell'
import {
  consoleApi, type ConsoleBackup, type ConsoleBackupRestore, type ConsoleHealth, type ConsoleNotice,
  type ConsoleSetting, type ConsoleSupportSession, type ConsoleTenant,
} from './consoleApi'

/** Tono del estado de una sesión, copia, restauración o componente. */
export function opsTone(status: string): StatusTone {
  if (['active', 'completed', 'operational'].includes(status)) return 'ok'
  if (['pending', 'requested', 'approved', 'running', 'scheduled', 'degraded'].includes(status)) return 'wa'
  if (['failed', 'down'].includes(status)) return 'er'
  return 'gr'
}

/** Tamaño legible de una copia (el formato numérico lo da el navegador). */
export function bytesLabel(locale: string, bytes: number | null): string {
  if (bytes === null) return ''
  const mb = bytes / 1048576
  return `${new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(mb)} MB`
}

function Page({ title, sub, actions, children }: { title: string; sub: string; actions?: ReactNode; children: ReactNode }) {
  const tabs = useConsoleTabs()
  return (
    <>
      <PageHeader title={title} sub={sub} tabs={tabs} actions={actions} />
      <main className="main" id="contenido" tabIndex={-1}>{children}</main>
    </>
  )
}

function useLoad<T>(path: string) {
  const [data, setData] = useState<T | null>(null)
  const [failed, setFailed] = useState(false)
  const load = useCallback(() => {
    setFailed(false)
    consoleApi.get<{ data: T }>(path).then((r) => setData(r.data)).catch(() => setFailed(true))
  }, [path])
  useEffect(load, [load])
  return { data, failed, load }
}

function useTenants(): ConsoleTenant[] {
  const [tenants, setTenants] = useState<ConsoleTenant[]>([])
  useEffect(() => {
    consoleApi.get<{ data: ConsoleTenant[] }>('/tenants?per_page=100').then((r) => setTenants(r.data)).catch(() => setTenants([]))
  }, [])
  return tenants
}

// ── SAS-8 · Soporte ─────────────────────────────────────────────────────────────────────────────

/** SAS-8 · Sesiones de soporte con motivo, usuario, vencimiento propio y enlace de un solo uso (RN-SAS-08). */
export function ConsoleSupport() {
  const { t, locale } = useT()
  const me = useConsole()
  const { data, failed, load } = useLoad<ConsoleSupportSession[]>('/support-sessions?per_page=50')
  const tenants = useTenants()
  const [form, setForm] = useState({ tenant_id: '', target_user_id: '', reason: '', ticket_reference: '', duration_minutes: '30' })
  const [users, setUsers] = useState<Array<{ id: string; display_name: string; roles: string[] }>>([])
  const [link, setLink] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [detail, setDetail] = useState<ConsoleSupportSession | null>(null)
  const canCreate = me.permissions.includes('platform.support_sessions.create')

  useEffect(() => {
    if (!form.tenant_id) { setUsers([]); return }
    consoleApi.get<{ data: Array<{ id: string; display_name: string; roles: string[] }> }>(`/tenants/${form.tenant_id}/users`).then((r) => setUsers(r.data)).catch(() => setUsers([]))
  }, [form.tenant_id])

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      const r = await consoleApi.post<{ data: ConsoleSupportSession }>('/support-sessions', { ...form, duration_minutes: Number(form.duration_minutes) })
      setLink(r.data.entry_url ?? null)
      setForm((f) => ({ ...f, reason: '', ticket_reference: '' }))
      load()
    } catch (err) {
      setError((formErrors(err, t).general ?? Object.values(formErrors(err, t).fields)[0] ?? t('console.load_error')))
    }
  }

  async function end(s: ConsoleSupportSession) {
    await consoleApi.post(`/support-sessions/${s.id}/end`, {})
    load()
  }

  async function open(s: ConsoleSupportSession) {
    const r = await consoleApi.get<{ data: ConsoleSupportSession }>(`/support-sessions/${s.id}`)
    setDetail(r.data)
  }

  return (
    <Page title={t('console.ops.support_title')} sub={t('console.ops.support_sub')}>
      {canCreate && (
        <section className="card mb-3">
          <form className="card-body" onSubmit={submit} noValidate>
            <h2 className="h6">{t('console.ops.support_new')}</h2>
            {error && <Notice tone="error">{error}</Notice>}
            {link && <Notice tone="ok" title={t('console.ops.support_link')}><code className="text-break">{link}</code><div className="small">{t('console.ops.support_link_help')}</div></Notice>}
            <div className="row g-2">
              <div className="col-md-4">
                <label className="form-label" htmlFor="ss-tenant">{t('console.ops.tenant')}</label>
                <select id="ss-tenant" className="form-select" value={form.tenant_id} onChange={(e) => setForm((f) => ({ ...f, tenant_id: e.target.value, target_user_id: '' }))}>
                  <option value="">{t('console.ops.choose')}</option>
                  {tenants.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                </select>
              </div>
              <div className="col-md-4">
                <label className="form-label" htmlFor="ss-user">{t('console.ops.support_user')}</label>
                <select id="ss-user" className="form-select" value={form.target_user_id} onChange={(e) => setForm((f) => ({ ...f, target_user_id: e.target.value }))}>
                  <option value="">{t('console.ops.choose')}</option>
                  {users.map((u) => <option key={u.id} value={u.id}>{[u.display_name, u.roles.join(', ')].filter(Boolean).join(' · ')}</option>)}
                </select>
              </div>
              <div className="col-md-2">
                <label className="form-label" htmlFor="ss-ticket">{t('console.ops.support_ticket')}</label>
                <input id="ss-ticket" className="form-control" value={form.ticket_reference} onChange={(e) => setForm((f) => ({ ...f, ticket_reference: e.target.value }))} />
              </div>
              <div className="col-md-2">
                <label className="form-label" htmlFor="ss-min">{t('console.ops.support_minutes')}</label>
                <input id="ss-min" type="number" min={5} className="form-control" value={form.duration_minutes} onChange={(e) => setForm((f) => ({ ...f, duration_minutes: e.target.value }))} />
              </div>
              <div className="col-12">
                <label className="form-label" htmlFor="ss-reason">{t('console.ops.reason')}</label>
                <textarea id="ss-reason" className="form-control" rows={2} value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} />
                <div className="form-text">{t('console.ops.support_reason_help')}</div>
              </div>
            </div>
            <button type="submit" className="btn btn-primary mt-2"><Icon name="plus" /> {t('console.ops.support_create')}</button>
          </form>
        </section>
      )}
      {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
      {data && data.length === 0 && <EmptyState title={t('console.ops.support_empty_title')} text={t('console.ops.support_empty')} />}
      {data && data.length > 0 && (
        <section className="card">
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr>
                <th scope="col">{t('console.ops.tenant')}</th><th scope="col">{t('console.ops.support_agent')}</th>
                <th scope="col">{t('console.ops.support_user')}</th><th scope="col">{t('console.ops.reason')}</th>
                <th scope="col">{t('console.ops.status')}</th><th scope="col">{t('console.ops.expires')}</th><th scope="col"><span className="visually-hidden">{t('console.ops.actions')}</span></th>
              </tr></thead>
              <tbody>
                {data.map((s) => (
                  <tr key={s.id}>
                    <td>{s.tenant?.name}</td>
                    <td>{s.agent?.name}</td>
                    <td>{s.target_user?.display_name ?? ''}</td>
                    <td className="text-secondary">{s.reason}{s.allow_sensitive && <> <Tag tone="oc">{t('console.ops.support_sensitive')}</Tag></>}</td>
                    <td><StatusBadge tone={opsTone(s.status)}>{t(`console.ops.st.${s.status}`)}</StatusBadge></td>
                    <td>{formatDateTime(locale, s.expires_at)}</td>
                    <td className="text-end">
                      <button type="button" className="btn btn-sec btn-sm" onClick={() => void open(s)}>{t('console.ops.detail')}</button>{' '}
                      {s.allowed_actions.includes('end') && me.permissions.includes('platform.support_sessions.end') && (
                        <button type="button" className="btn btn-sec btn-sm" onClick={() => void end(s)}>{t('console.ops.support_end')}</button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
      {detail && (
        <section className="card mt-3">
          <div className="card-body">
            <div className="fila-entre"><h2 className="h6">{t('console.ops.support_actions')}</h2><button type="button" className="btn btn-sec btn-sm" onClick={() => setDetail(null)}>{t('ui.close')}</button></div>
            {(detail.actions ?? []).length === 0 ? <p className="text-secondary">{t('console.ops.support_no_actions')}</p> : (
              <ul className="list-unstyled mb-0">
                {(detail.actions ?? []).map((a, i) => <li key={`${a.occurred_at}-${i}`}><span className="text-secondary">{formatDateTime(locale, String(a.occurred_at))}</span> · {String(a.action)}</li>)}
              </ul>
            )}
          </div>
        </section>
      )}
    </Page>
  )
}

// ── SAS-9 · Monitoreo ───────────────────────────────────────────────────────────────────────────

/** SAS-9 · Página de estado, colas, errores por organización y reintento de trabajos y eventos fallidos. */
export function ConsoleHealth() {
  const { t, locale } = useT()
  const me = useConsole()
  const { data, failed, load } = useLoad<ConsoleHealth>('/health')
  const [jobs, setJobs] = useState<Array<{ id: string; queue: string; job: string; error: string | null; failed_at: string }>>([])
  const [events, setEvents] = useState<Array<{ id: string; tenant_name: string; event_type: string; status: string; attempts: number; error: string | null }>>([])
  const canRetry = me.permissions.includes('platform.health.manage')

  const loadLists = useCallback(() => {
    consoleApi.get<{ data: typeof jobs }>('/health/failed-jobs').then((r) => setJobs(r.data)).catch(() => setJobs([]))
    consoleApi.get<{ data: typeof events }>('/health/outbox-events').then((r) => setEvents(r.data)).catch(() => setEvents([]))
  }, [])
  useEffect(loadLists, [loadLists])

  async function retry(path: string) {
    await consoleApi.post(path, {})
    load()
    loadLists()
  }

  return (
    <Page title={t('console.ops.health_title')} sub={t('console.ops.health_sub')}>
      {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
      {!data && !failed && <span className="esq" style={{ width: 320 }} />}
      {data && (
        <>
          <section className="card mb-3">
            <div className="card-body">
              <h2 className="h6">{t('console.ops.health_status')} · <StatusBadge tone={opsTone(data.status)}>{t(`console.ops.st.${data.status}`)}</StatusBadge></h2>
              <ul className="list-unstyled mb-0">
                {data.components.map((c) => (
                  <li key={c.code}><StatusBadge tone={opsTone(c.status)}>{t(`console.ops.st.${c.status}`)}</StatusBadge> {t(`console.ops.comp.${c.code.replace('.', '_')}`)}{c.detail ? <span className="text-secondary small"> · {c.detail}</span> : null}</li>
                ))}
              </ul>
              <p className="small text-secondary mb-0 mt-2">{t('console.ops.health_counts', { jobs: data.failed_jobs, events: data.outbox.failed, webhooks: data.webhooks.failed })} · {formatDateTime(locale, data.checked_at)}</p>
            </div>
          </section>
          {data.tenants.length > 0 && (
            <section className="card mb-3">
              <div className="tabla-wrap">
                <table className="table">
                  <thead><tr><th scope="col">{t('console.ops.tenant')}</th><th scope="col">{t('console.ops.health_api')}</th><th scope="col">{t('console.ops.health_storage')}</th><th scope="col">{t('console.ops.health_errors')}</th></tr></thead>
                  <tbody>
                    {data.tenants.map((x) => (
                      <tr key={x.tenant_id}><td>{x.name}</td><td>{x.api_calls_month}</td><td>{x.storage_mb}</td><td>{x.errors.failed_jobs + x.errors.outbox_events + x.errors.webhook_deliveries}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}
        </>
      )}
      <section className="card mb-3">
        <div className="card-body">
          <h2 className="h6">{t('console.ops.health_jobs')}</h2>
          {jobs.length === 0 ? <p className="text-secondary mb-0">{t('console.ops.health_none')}</p> : (
            <ul className="list-unstyled mb-0">
              {jobs.map((j) => (
                <li key={j.id} className="fila-entre"><span>{j.job || j.queue} <span className="text-secondary small">· {j.error ?? ''} · {formatDateTime(locale, j.failed_at)}</span></span>
                  {canRetry && <button type="button" className="btn btn-sec btn-sm" onClick={() => void retry(`/health/failed-jobs/${j.id}/retry`)}>{t('console.ops.retry')}</button>}</li>
              ))}
            </ul>
          )}
        </div>
      </section>
      <section className="card">
        <div className="card-body">
          <h2 className="h6">{t('console.ops.health_events')}</h2>
          {events.length === 0 ? <p className="text-secondary mb-0">{t('console.ops.health_none')}</p> : (
            <ul className="list-unstyled mb-0">
              {events.map((ev) => (
                <li key={ev.id} className="fila-entre"><span>{ev.event_type} <span className="text-secondary small">· {ev.tenant_name} · {ev.attempts} · {ev.error ?? ''}</span></span>
                  {canRetry && <button type="button" className="btn btn-sec btn-sm" onClick={() => void retry(`/health/outbox-events/${ev.id}/retry`)}>{t('console.ops.retry')}</button>}</li>
              ))}
            </ul>
          )}
        </div>
      </section>
    </Page>
  )
}

// ── SAS-12 · Copias de seguridad ────────────────────────────────────────────────────────────────

/** SAS-12 · Historial de copias, copia manual y restauración en aislado con doble aprobación (RN-SAS-09). */
export function ConsoleBackups() {
  const { t, locale } = useT()
  const me = useConsole()
  const { data, failed, load } = useLoad<ConsoleBackup[]>('/backups?per_page=50')
  const [restores, setRestores] = useState<ConsoleBackupRestore[]>([])
  const [error, setError] = useState<string | null>(null)
  const [reason, setReason] = useState('')
  const can = (p: string) => me.permissions.includes(p)

  const loadRestores = useCallback(() => {
    consoleApi.get<{ data: ConsoleBackupRestore[] }>('/backup-restores?per_page=50').then((r) => setRestores(r.data)).catch(() => setRestores([]))
  }, [])
  useEffect(loadRestores, [loadRestores])

  async function run(path: string, body: Record<string, unknown>) {
    setError(null)
    try {
      await consoleApi.post(path, body)
      load()
      loadRestores()
    } catch (err) {
      setError((formErrors(err, t).general ?? Object.values(formErrors(err, t).fields)[0] ?? t('console.load_error')))
    }
  }

  return (
    <Page
      title={t('console.ops.backups_title')}
      sub={t('console.ops.backups_sub')}
      actions={can('platform.backups.create') ? <button type="button" className="btn btn-primary" onClick={() => void run('/backups', { coverage: 'platform' })}><Icon name="plus" /> {t('console.ops.backup_now')}</button> : undefined}
    >
      {error && <Notice tone="error">{error}</Notice>}
      {can('platform.backups.restore') && (
        <div className="mb-3">
          <label className="form-label" htmlFor="bk-reason">{t('console.ops.restore_reason')}</label>
          <input id="bk-reason" className="form-control" value={reason} onChange={(e) => setReason(e.target.value)} />
          <div className="form-text">{t('console.ops.restore_help')}</div>
        </div>
      )}
      {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
      {data && data.length === 0 && <EmptyState title={t('console.ops.backups_empty_title')} text={t('console.ops.backups_empty')} />}
      {data && data.length > 0 && (
        <section className="card mb-3">
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr>
                <th scope="col">{t('console.ops.backup_when')}</th><th scope="col">{t('console.ops.backup_kind')}</th><th scope="col">{t('console.ops.tenant')}</th>
                <th scope="col">{t('console.ops.status')}</th><th scope="col">{t('console.ops.backup_size')}</th><th scope="col">{t('console.ops.backup_retention')}</th>
                <th scope="col"><span className="visually-hidden">{t('console.ops.actions')}</span></th>
              </tr></thead>
              <tbody>
                {data.map((b) => (
                  <tr key={b.id}>
                    <td>{formatDateTime(locale, b.started_at)}</td>
                    <td>{t(`console.ops.kind.${b.kind}`)}</td>
                    <td>{b.tenant_name ?? t('console.ops.platform')}</td>
                    <td><StatusBadge tone={opsTone(b.status)}>{t(`console.ops.st.${b.status}`)}</StatusBadge>{b.verified_in_sandbox && <> <Tag tone="ok">{t('console.ops.verified')}</Tag></>}</td>
                    <td>{bytesLabel(locale, b.size_bytes)}</td>
                    <td>{b.retention_until ?? ''}</td>
                    <td className="text-end">
                      {b.status === 'completed' && can('platform.backups.restore') && (
                        <>
                          <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(`/backups/${b.id}/restores`, { target: 'sandbox', reason })}>{t('console.ops.restore_sandbox')}</button>{' '}
                          {b.verified_in_sandbox && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(`/backups/${b.id}/restores`, { target: 'production', reason })}>{t('console.ops.restore_production')}</button>}
                        </>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
      <section className="card">
        <div className="card-body">
          <h2 className="h6">{t('console.ops.restores')}</h2>
          {restores.length === 0 ? <p className="text-secondary mb-0">{t('console.ops.health_none')}</p> : (
            <ul className="list-unstyled mb-0">
              {restores.map((r) => (
                <li key={r.id} className="fila-entre">
                  <span>
                    <StatusBadge tone={opsTone(r.status)}>{t(`console.ops.st.${r.status}`)}</StatusBadge> {t(`console.ops.target.${r.target}`)}
                    {r.kind === 'scheduled_test' && <> <Tag tone="in">{t('console.ops.scheduled_test')}</Tag></>}
                    <span className="text-secondary small"> · {r.reason} · {[r.requested_by, r.approved_by].filter(Boolean).join(' → ')} · {formatDateTime(locale, r.created_at)}</span>
                  </span>
                  <span>
                    {r.allowed_actions.includes('approve') && can('platform.backups.restore') && <button type="button" className="btn btn-primary btn-sm" onClick={() => void run(`/backup-restores/${r.id}/approve`, {})}>{t('console.ops.approve')}</button>}{' '}
                    {r.allowed_actions.includes('cancel') && can('platform.backups.restore') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(`/backup-restores/${r.id}/cancel`, {})}>{t('console.cancel')}</button>}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>
    </Page>
  )
}

// ── SAS-13 · Configuración global, avisos y registros ───────────────────────────────────────────

/** SAS-13 · Configuración global (validada por clave) y avisos de mantenimiento. */
export function ConsoleGlobalSettings() {
  const { t, locale } = useT()
  const me = useConsole()
  const { data, failed, load } = useLoad<ConsoleSetting[]>('/settings')
  const notices = useLoad<ConsoleNotice[]>('/notices')
  const tenants = useTenants()
  const [drafts, setDrafts] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState({ title: '', message: '', severity: 'info', audience: 'all', tenant_ids: [] as string[], starts_at: '', ends_at: '' })
  const canEdit = me.permissions.includes('platform.settings.update')

  async function save(key: string) {
    setError(null)
    try {
      await consoleApi.request(`/settings/${key}`, 'PATCH', { value: JSON.parse(drafts[key] ?? 'null') })
      setDrafts((d) => { const n = { ...d }; delete n[key]; return n })
      load()
    } catch (err) {
      setError(err instanceof SyntaxError ? t('console.ops.settings_json') : (formErrors(err, t).fields.value ?? formErrors(err, t).general ?? t('console.load_error')))
    }
  }

  async function publish(e: FormEvent) {
    e.preventDefault()
    setError(null)
    try {
      await consoleApi.post('/notices', { ...notice, starts_at: notice.starts_at || undefined, ends_at: notice.ends_at || null })
      setNotice((n) => ({ ...n, title: '', message: '' }))
      notices.load()
    } catch (err) {
      setError((formErrors(err, t).general ?? Object.values(formErrors(err, t).fields)[0] ?? t('console.load_error')))
    }
  }

  async function cancel(n: ConsoleNotice) {
    await consoleApi.post(`/notices/${n.id}/cancel`, {})
    notices.load()
  }

  return (
    <Page title={t('console.ops.settings_title')} sub={t('console.ops.settings_sub')}>
      {error && <Notice tone="error">{error}</Notice>}
      {failed && <ErrorState text={t('console.load_error')} retryLabel={t('common.retry')} onRetry={load} />}
      {data && (
        <section className="card mb-3">
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th scope="col">{t('console.ops.setting')}</th><th scope="col">{t('console.ops.value')}</th><th scope="col"><span className="visually-hidden">{t('console.ops.actions')}</span></th></tr></thead>
              <tbody>
                {data.map((s) => (
                  <tr key={s.key}>
                    <td><code>{s.key}</code><div className="small text-secondary">{s.description}</div>{s.customized && <Tag tone="pe">{t('console.ops.customized')}</Tag>}</td>
                    <td><input aria-label={s.key} className="form-control form-control-sm" disabled={!canEdit} value={drafts[s.key] ?? JSON.stringify(s.value)} onChange={(e) => setDrafts((d) => ({ ...d, [s.key]: e.target.value }))} /></td>
                    <td className="text-end">{canEdit && drafts[s.key] !== undefined && <button type="button" className="btn btn-primary btn-sm" onClick={() => void save(s.key)}>{t('console.save')}</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
      <section className="card">
        <div className="card-body">
          <h2 className="h6">{t('console.ops.notices')}</h2>
          {canEdit && (
            <form onSubmit={publish} noValidate className="row g-2 mb-3">
              <div className="col-md-4"><label className="form-label" htmlFor="nt-title">{t('console.ops.notice_title')}</label><input id="nt-title" className="form-control" value={notice.title} onChange={(e) => setNotice((n) => ({ ...n, title: e.target.value }))} /></div>
              <div className="col-md-4"><label className="form-label" htmlFor="nt-sev">{t('console.ops.notice_severity')}</label>
                <select id="nt-sev" className="form-select" value={notice.severity} onChange={(e) => setNotice((n) => ({ ...n, severity: e.target.value }))}>
                  {['info', 'warning', 'critical'].map((s) => <option key={s} value={s}>{t(`console.ops.sev.${s}`)}</option>)}
                </select></div>
              <div className="col-md-4"><label className="form-label" htmlFor="nt-aud">{t('console.ops.notice_audience')}</label>
                <select id="nt-aud" className="form-select" value={notice.audience} onChange={(e) => setNotice((n) => ({ ...n, audience: e.target.value }))}>
                  <option value="all">{t('console.ops.audience_all')}</option><option value="selected">{t('console.ops.audience_selected')}</option>
                </select></div>
              {notice.audience === 'selected' && (
                <div className="col-12"><label className="form-label" htmlFor="nt-ten">{t('console.ops.tenants')}</label>
                  <select id="nt-ten" multiple className="form-select" value={notice.tenant_ids} onChange={(e) => setNotice((n) => ({ ...n, tenant_ids: Array.from(e.target.selectedOptions).map((o) => o.value) }))}>
                    {tenants.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                  </select></div>
              )}
              <div className="col-12"><label className="form-label" htmlFor="nt-msg">{t('console.ops.notice_message')}</label><textarea id="nt-msg" className="form-control" rows={2} value={notice.message} onChange={(e) => setNotice((n) => ({ ...n, message: e.target.value }))} /></div>
              <div className="col-md-6"><label className="form-label" htmlFor="nt-from">{t('console.ops.notice_from')}</label><input id="nt-from" type="datetime-local" className="form-control" value={notice.starts_at} onChange={(e) => setNotice((n) => ({ ...n, starts_at: e.target.value }))} /></div>
              <div className="col-md-6"><label className="form-label" htmlFor="nt-to">{t('console.ops.notice_to')}</label><input id="nt-to" type="datetime-local" className="form-control" value={notice.ends_at} onChange={(e) => setNotice((n) => ({ ...n, ends_at: e.target.value }))} /></div>
              <div className="col-12"><button type="submit" className="btn btn-primary">{t('console.ops.notice_publish')}</button></div>
            </form>
          )}
          {(notices.data ?? []).length === 0 ? <p className="text-secondary mb-0">{t('console.ops.notices_empty')}</p> : (
            <ul className="list-unstyled mb-0">
              {(notices.data ?? []).map((n) => (
                <li key={n.id} className="fila-entre">
                  <span><StatusBadge tone={opsTone(n.status)}>{t(`console.ops.st.${n.status}`)}</StatusBadge> <b>{n.title}</b> <span className="text-secondary small">· {t(`console.ops.sev.${n.severity}`)} · {formatDateTime(locale, n.starts_at)}{n.ends_at ? ` – ${formatDateTime(locale, n.ends_at)}` : ''}</span></span>
                  {canEdit && n.allowed_actions.includes('cancel') && <button type="button" className="btn btn-sec btn-sm" onClick={() => void cancel(n)}>{t('console.cancel')}</button>}
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>
    </Page>
  )
}

type LogRow = { id?: string; occurred_at: string | null; activity?: string; user_name?: string | null; level?: string; message?: string }

/** SAS-13 · Actividad de una organización y logs técnicos, por separado de la auditoría. */
export function ConsoleRecords() {
  const { t, locale } = useT()
  const tenants = useTenants()
  const [kind, setKind] = useState<'activity' | 'logs'>('activity')
  const [filters, setFilters] = useState({ tenant_id: '', from: '', to: '', level: '' })
  const [rows, setRows] = useState<LogRow[] | null>(null)
  const [unavailable, setUnavailable] = useState(false)

  const load = useCallback(() => {
    const q = new URLSearchParams(Object.entries(filters).filter(([k, v]) => v !== '' && (kind === 'logs' || k !== 'level')))
    if (kind === 'activity' && !filters.tenant_id) { setRows(null); return }
    consoleApi.get<{ data: LogRow[]; meta: { available?: boolean } }>(`/${kind === 'activity' ? 'activity-events' : 'technical-logs'}?${q.toString()}`)
      .then((r) => { setRows(r.data); setUnavailable(r.meta.available === false) })
      .catch(() => setRows([]))
  }, [filters, kind])
  useEffect(load, [load])

  return (
    <Page title={t('console.ops.records_title')} sub={t('console.ops.records_sub')}>
      <div className="row g-2 mb-3">
        <div className="col-md-3"><label className="form-label" htmlFor="rc-kind">{t('console.ops.records_kind')}</label>
          <select id="rc-kind" className="form-select" value={kind} onChange={(e) => setKind(e.target.value as 'activity' | 'logs')}>
            <option value="activity">{t('console.ops.records_activity')}</option><option value="logs">{t('console.ops.records_logs')}</option>
          </select></div>
        <div className="col-md-3"><label className="form-label" htmlFor="rc-ten">{t('console.ops.tenant')}</label>
          <select id="rc-ten" className="form-select" value={filters.tenant_id} onChange={(e) => setFilters((f) => ({ ...f, tenant_id: e.target.value }))}>
            <option value="">{t('console.ops.choose')}</option>{tenants.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
          </select></div>
        <div className="col-md-2"><label className="form-label" htmlFor="rc-from">{t('console.ops.notice_from')}</label><input id="rc-from" type="date" className="form-control" value={filters.from} onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))} /></div>
        <div className="col-md-2"><label className="form-label" htmlFor="rc-to">{t('console.ops.notice_to')}</label><input id="rc-to" type="date" className="form-control" value={filters.to} onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))} /></div>
        {kind === 'logs' && (
          <div className="col-md-2"><label className="form-label" htmlFor="rc-lvl">{t('console.ops.level')}</label>
            <select id="rc-lvl" className="form-select" value={filters.level} onChange={(e) => setFilters((f) => ({ ...f, level: e.target.value }))}>
              <option value="">{t('console.ops.choose')}</option>{['info', 'warning', 'error', 'critical'].map((l) => <option key={l} value={l}>{l}</option>)}
            </select></div>
        )}
      </div>
      {kind === 'activity' && !filters.tenant_id && <p className="text-secondary">{t('console.ops.records_pick_tenant')}</p>}
      {unavailable && <Notice tone="info">{t('console.ops.records_unavailable')}</Notice>}
      {rows && rows.length === 0 && !unavailable && <EmptyState title={t('console.ops.records_empty_title')} text={t('console.ops.records_empty')} />}
      {rows && rows.length > 0 && (
        <section className="card">
          <ul className="list-unstyled card-body mb-0">
            {rows.map((r, i) => (
              <li key={r.id ?? `${r.occurred_at}-${i}`}><span className="text-secondary small">{formatDateTime(locale, r.occurred_at)}</span> · {kind === 'activity' ? `${r.user_name ?? ''} · ${r.activity ?? ''}` : `${r.level ?? ''} · ${r.message ?? ''}`}</li>
            ))}
          </ul>
        </section>
      )}
    </Page>
  )
}
