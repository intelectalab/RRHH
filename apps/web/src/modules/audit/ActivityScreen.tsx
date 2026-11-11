'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { settingsTabs } from '@/modules/identity/settingsTabs'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'

type Activity = { id: string; user_id: string | null; user_name: string | null; activity: string; subject_type: string | null; subject_id: string | null; occurred_at: string }
const KINDS = ['auth.login', 'auth.login_failed', 'record.viewed', 'file.downloaded', 'search.performed']

type Page = { data: Activity[]; meta: { next_cursor: string | null } }

/** Configuración → Actividad (AUD-3): ingresos, vistas, descargas y búsquedas; separada de la auditoría. */
export function ActivityScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const [rows, setRows] = useState<Activity[] | null>(null)
  const [next, setNext] = useState<string | null>(null)
  const [activity, setActivity] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [error, setError] = useState(false)

  const query = useCallback((cursor?: string) => {
    const p = new URLSearchParams({ per_page: '50' })
    if (activity !== '') p.set('filter[activity]', activity)
    if (from !== '') p.set('filter[from]', from)
    if (to !== '') p.set('filter[to]', to)
    if (cursor) p.set('cursor', cursor)
    return `/activity-events?${p.toString()}`
  }, [activity, from, to])

  const load = useCallback(() => {
    setRows(null)
    setError(false)
    api.get<Page>(query()).then((r) => { setRows(r.data); setNext(r.meta.next_cursor) }).catch(() => { setRows([]); setError(true) })
  }, [query])

  useEffect(() => { load() }, [load])

  function more() {
    if (next === null) return
    void api.get<Page>(query(next)).then((r) => { setRows((prev) => [...(prev ?? []), ...r.data]); setNext(r.meta.next_cursor) })
  }

  const fmt = (iso: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'medium' }).format(new Date(iso))
  const label = (code: string) => {
    const key = `audit.act_${code.replace(/\./g, '_')}`
    const text = t(key)
    return text === key ? code : text
  }

  return (
    <>
      <PageHeader title={t('settings.title')} sub={t('audit.act_sub')} tabs={settingsTabs(t, can, 'activity')} />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="filtros">
            <select className="form-select" style={{ width: 'auto' }} aria-label={t('audit.act_kind')} value={activity} onChange={(e) => setActivity(e.target.value)}>
              <option value="">{t('audit.act_all')}</option>
              {KINDS.map((a) => <option key={a} value={a}>{label(a)}</option>)}
            </select>
            <label className="small text-secondary">{t('audit.from')} <input className="form-control" style={{ display: 'inline-block', width: 'auto' }} type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
            <label className="small text-secondary">{t('audit.to')} <input className="form-control" style={{ display: 'inline-block', width: 'auto' }} type="date" value={to} onChange={(e) => setTo(e.target.value)} /></label>
          </div>
          {error && <p className="small text-secondary" role="alert">{t('audit.act_error')}</p>}
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th>{t('audit.col_when')}</th><th>{t('audit.col_user')}</th><th>{t('audit.act_kind')}</th><th>{t('audit.col_entity')}</th></tr></thead>
              <tbody>
                {rows === null && <tr><td colSpan={4}><span className="esq" /></td></tr>}
                {rows?.length === 0 && !error && <tr><td colSpan={4}><div className="vacio">{t('audit.act_empty')}</div></td></tr>}
                {rows?.map((r) => (
                  <tr key={r.id}>
                    <td className="nowrap">{fmt(r.occurred_at)}</td>
                    <td>{r.user_name ?? t('audit.system')}</td>
                    <td>{label(r.activity)}</td>
                    <td>{r.subject_type ?? '—'}{r.subject_id && <span className="sub2">{r.subject_id.slice(0, 8)}</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {next !== null && <div className="card-body"><button type="button" className="btn btn-sec btn-sm" onClick={more}>{t('audit.more')}</button></div>}
        </section>
      </main>
    </>
  )
}
