'use client'

import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { formatDateTime } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Notice } from '@/ui/components/Notice'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { StatusBadge, Tag } from '@/ui/components/Status'

type Grant = { id: string; reason: string; access_level: 'read_only' | 'full'; granted_by_name: string | null; starts_at: string; expires_at: string; revoked_at: string | null; active: boolean }
type Session = { id: string; agent_name: string; target_user_name: string | null; reason: string; ticket_reference: string | null; status: string; allow_sensitive: boolean; started_at: string | null; ended_at: string | null; expires_at: string }
type Overview = { enabled: boolean; grants: Grant[]; sessions: Session[] }

/**
 * SAS-8 · Configuración → Acceso del soporte: habilitarlo o desactivarlo por completo, autorizar al soporte por un
 * tiempo (con o sin campos sensibles) y ver cada sesión de soporte con motivo, inicio y fin.
 */
export function SupportAccessScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const [data, setData] = useState<Overview | null>(null)
  const [failed, setFailed] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [reason, setReason] = useState('')
  const [grant, setGrant] = useState({ access_level: 'read_only', days: '1' })
  const canEdit = can('identity.security_policies.update')

  const load = useCallback(() => {
    setFailed(false)
    api.get<{ data: Overview }>('/support-access').then((r) => setData(r.data)).catch(() => setFailed(true))
  }, [])
  useEffect(load, [load])

  async function run(fn: () => Promise<unknown>) {
    setError(null)
    try {
      await fn()
      load()
    } catch (err) {
      setError((formErrors(err, t).general ?? Object.values(formErrors(err, t).fields)[0] ?? t('common.error')))
    }
  }

  function toggle() {
    if (!data) return
    void run(() => api.request('/support-access', 'PATCH', { enabled: !data.enabled, reason }))
  }

  function authorize(e: FormEvent) {
    e.preventDefault()
    const expires = new Date(Date.now() + Number(grant.days) * 86400000).toISOString()
    void run(() => api.post('/support-access/grants', { reason, access_level: grant.access_level, expires_at: expires }))
  }

  return (
    <>
      <PageHeader title={t('support.title')} sub={t('support.sub')} />
      <main className="main" id="contenido" tabIndex={-1}>
        {failed && <ErrorState text={t('common.error')} retryLabel={t('common.retry')} onRetry={load} />}
        {error && <Notice tone="error">{error}</Notice>}
        {data && (
          <>
            <section className="card mb-3">
              <div className="card-body">
                <h2 className="h6">{t('support.status')} · <StatusBadge tone={data.enabled ? 'ok' : 'gr'}>{t(data.enabled ? 'support.enabled' : 'support.disabled')}</StatusBadge></h2>
                <p className="text-secondary">{t('support.explain')}</p>
                {canEdit && (
                  <div className="row g-2">
                    <div className="col-md-8">
                      <label className="form-label" htmlFor="sp-reason">{t('support.reason')}</label>
                      <input id="sp-reason" className="form-control" value={reason} onChange={(e) => setReason(e.target.value)} />
                    </div>
                    <div className="col-md-4 d-flex align-items-end">
                      <button type="button" className="btn btn-sec" onClick={toggle}>{t(data.enabled ? 'support.disable' : 'support.enable')}</button>
                    </div>
                  </div>
                )}
              </div>
            </section>
            {canEdit && data.enabled && (
              <section className="card mb-3">
                <form className="card-body row g-2" onSubmit={authorize} noValidate>
                  <h2 className="h6 col-12">{t('support.authorize')}</h2>
                  <div className="col-md-4">
                    <label className="form-label" htmlFor="sp-level">{t('support.level')}</label>
                    <select id="sp-level" className="form-select" value={grant.access_level} onChange={(e) => setGrant((g) => ({ ...g, access_level: e.target.value }))}>
                      <option value="read_only">{t('support.level_read_only')}</option>
                      <option value="full">{t('support.level_full')}</option>
                    </select>
                  </div>
                  <div className="col-md-4">
                    <label className="form-label" htmlFor="sp-days">{t('support.days')}</label>
                    <input id="sp-days" type="number" min={1} max={30} className="form-control" value={grant.days} onChange={(e) => setGrant((g) => ({ ...g, days: e.target.value }))} />
                  </div>
                  <div className="col-md-4 d-flex align-items-end"><button type="submit" className="btn btn-primary">{t('support.authorize_button')}</button></div>
                  <div className="form-text col-12">{t('support.authorize_help')}</div>
                </form>
              </section>
            )}
            {data.grants.length > 0 && (
              <section className="card mb-3">
                <div className="card-body">
                  <h2 className="h6">{t('support.grants')}</h2>
                  <ul className="list-unstyled mb-0">
                    {data.grants.map((g) => (
                      <li key={g.id} className="fila-entre">
                        <span><Tag tone={g.access_level === 'full' ? 'oc' : 'gr'}>{t(`support.level_${g.access_level}`)}</Tag> {g.reason} <span className="text-secondary small">· {g.granted_by_name ?? ''} · {formatDateTime(locale, g.expires_at)}</span></span>
                        {g.active && canEdit && <button type="button" className="btn btn-sec btn-sm" onClick={() => void run(() => api.post(`/support-access/grants/${g.id}/revoke`, {}))}>{t('support.revoke')}</button>}
                      </li>
                    ))}
                  </ul>
                </div>
              </section>
            )}
            <section className="card">
              <div className="card-body">
                <h2 className="h6">{t('support.sessions')}</h2>
                {data.sessions.length === 0 ? <EmptyState title={t('support.sessions_empty_title')} text={t('support.sessions_empty')} /> : (
                  <ul className="list-unstyled mb-0">
                    {data.sessions.map((s) => (
                      <li key={s.id}>
                        <StatusBadge tone={s.status === 'active' ? 'wa' : 'gr'}>{t(`support.st_${s.status}`)}</StatusBadge> {s.agent_name} · {s.target_user_name ?? ''}
                        <span className="text-secondary small"> · {s.reason}{s.ticket_reference ? ` · ${s.ticket_reference}` : ''} · {formatDateTime(locale, s.started_at ?? s.expires_at)}{s.ended_at ? ` – ${formatDateTime(locale, s.ended_at)}` : ''}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </section>
          </>
        )}
      </main>
    </>
  )
}
