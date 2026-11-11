'use client'

import { useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { settingsTabs } from '@/modules/identity/settingsTabs'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'

type Issue = { type: string; seq?: number; from_seq?: number; to_seq?: number; period?: string; count?: number }
type Check = { id: string; status: 'ok' | 'failed'; checked_rows: number; archived_rows: number; unchained_rows: number; issues: Issue[]; started_at: string }
type Retention = {
  audit: { retention_years: number; online_months: number }
  activity: { retention_days: number }
  technical: { retention_days: number }
}
type Archive = { id: string; period_month: string; row_count: number; from_seq: number | null; to_seq: number | null; purge_after: string; available: boolean }
type Entry = { id: string; actor_name: string | null; action: string; entity: string; entity_id: string; occurred_at: string }

/**
 * Configuración → Integridad y retención (AUD-5 y AUD-3): resultado de la verificación programada de la
 * cadena de auditoría, plazos de conservación de los tres registros y archivo frío consultable.
 */
export function AuditIntegrityScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const [retention, setRetention] = useState<Retention | null>(null)
  const [checks, setChecks] = useState<Check[] | null>(null)
  const [archives, setArchives] = useState<Archive[] | null>(null)
  const [open, setOpen] = useState<string | null>(null)
  const [entries, setEntries] = useState<Entry[] | null>(null)

  useEffect(() => {
    void api.get<{ data: Retention }>('/audit-retention').then((r) => setRetention(r.data)).catch(() => undefined)
    void api.get<{ data: Check[] }>('/audit-integrity-checks?per_page=10').then((r) => setChecks(r.data)).catch(() => setChecks([]))
    void api.get<{ data: Archive[] }>('/audit-archives').then((r) => setArchives(r.data)).catch(() => setArchives([]))
  }, [])

  function toggle(id: string) {
    if (open === id) { setOpen(null); return }
    setOpen(id)
    setEntries(null)
    void api.get<{ data: Entry[] }>(`/audit-archives/${id}/entries?per_page=50`).then((r) => setEntries(r.data)).catch(() => setEntries([]))
  }

  const fmt = (iso: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
  const month = (d: string) => new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${d}T00:00:00Z`))
  const day = (d: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${d}T00:00:00Z`))
  const last = checks?.[0]
  const issueText = (i: Issue) => t(`audit.int_issue_${i.type}`, {
    seq: i.seq ?? '', from: i.from_seq ?? '', to: i.to_seq ?? '', period: i.period ?? '', n: i.count ?? '',
  })

  return (
    <>
      <PageHeader title={t('settings.title')} sub={t('audit.int_sub')} tabs={settingsTabs(t, can, 'integrity')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card mb-3">
          <div className="card-body">
            <h2 className="h6">{t('audit.int_last')}</h2>
            {checks === null && <span className="esq" />}
            {checks?.length === 0 && <p className="small text-secondary">{t('audit.int_never')}</p>}
            {last && (
              <>
                <p>
                  <span className={`tag ${last.status === 'ok' ? 'ok' : 'er'}`}>{t(`audit.int_status_${last.status}`)}</span>{' '}
                  <span className="small text-secondary">{t('audit.int_checked', { n: last.checked_rows, archived: last.archived_rows, when: fmt(last.started_at) })}</span>
                </p>
                {last.issues.length > 0 && (
                  <ul className="small">
                    {last.issues.map((i, k) => <li key={k}>{issueText(i)}</li>)}
                  </ul>
                )}
              </>
            )}
          </div>
        </section>

        <section className="card mb-3">
          <div className="card-body">
            <h2 className="h6">{t('audit.int_retention')}</h2>
            {retention === null ? <span className="esq" /> : (
              <div className="resumen">
                <div><b>{t('audit.int_store_audit')}</b><span className="small text-secondary">{t('audit.int_audit_policy', { years: retention.audit.retention_years, months: retention.audit.online_months })}</span></div>
                <div><b>{t('audit.int_store_activity')}</b><span className="small text-secondary">{t('audit.int_days', { n: retention.activity.retention_days })}</span></div>
                <div><b>{t('audit.int_store_technical')}</b><span className="small text-secondary">{t('audit.int_technical_policy', { n: retention.technical.retention_days })}</span></div>
              </div>
            )}
          </div>
        </section>

        <section className="card">
          <div className="card-body"><h2 className="h6">{t('audit.int_archives')}</h2></div>
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th>{t('audit.int_period')}</th><th>{t('audit.int_rows')}</th><th>{t('audit.int_until')}</th><th>{t('audit.int_state')}</th><th /></tr></thead>
              <tbody>
                {archives === null && <tr><td colSpan={5}><span className="esq" /></td></tr>}
                {archives?.length === 0 && <tr><td colSpan={5}><div className="vacio">{t('audit.int_no_archives')}</div></td></tr>}
                {archives?.map((a) => (
                  <tr key={a.id}>
                    <td>{month(a.period_month)}</td>
                    <td>{a.row_count}</td>
                    <td className="nowrap">{day(a.purge_after)}</td>
                    <td><span className={`tag ${a.available ? 'in' : 'wa'}`}>{t(a.available ? 'audit.int_available' : 'audit.int_purged')}</span></td>
                    <td>{a.available && <button type="button" className="btn btn-sec btn-sm" aria-expanded={open === a.id} onClick={() => toggle(a.id)}>{t(open === a.id ? 'audit.int_hide' : 'audit.int_view')}</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {open !== null && (
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('audit.col_when')}</th><th>{t('audit.col_user')}</th><th>{t('audit.col_action')}</th><th>{t('audit.col_entity')}</th></tr></thead>
                <tbody>
                  {entries === null && <tr><td colSpan={4}><span className="esq" /></td></tr>}
                  {entries?.map((e) => (
                    <tr key={e.id}>
                      <td className="nowrap">{fmt(e.occurred_at)}</td>
                      <td>{e.actor_name ?? t('audit.system')}</td>
                      <td>{e.action}</td>
                      <td>{e.entity}{e.entity_id && <span className="sub2">{e.entity_id.slice(0, 8)}</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </main>
    </>
  )
}
