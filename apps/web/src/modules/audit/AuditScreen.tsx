'use client'

import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { settingsTabs } from '@/modules/identity/settingsTabs'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { useDateTime } from '@/modules/session/useDateTime'

type Entry = {
  id: string
  actor_id: string | null
  actor_name: string | null
  action: string
  entity: string
  entity_id: string
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  changed_fields: string[]
  reason: string | null
  ip: string | null
  occurred_at: string
  sensitive: boolean
}

const ENTITIES = ['user', 'role', 'legal_entity', 'employment'] as const

function show(value: unknown): string {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'string') return value
  return JSON.stringify(value)
}

/** Configuración → Auditoría (AUD-4): solo lectura, con historia por registro y exportación. */
export function AuditScreen() {
  const { t, locale } = useT()
  const fmtDT = useDateTime()
  const { can } = useSession()
  const [rows, setRows] = useState<Entry[] | null>(null)
  const [cursor, setCursor] = useState<string | null>(null)
  const [q, setQ] = useState('')
  const [entity, setEntity] = useState('')
  const [entityId, setEntityId] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [detail, setDetail] = useState<Entry | null>(null)

  const query = useCallback((next?: string) => {
    const p = new URLSearchParams({ per_page: '50' })
    if (q.trim()) p.set('q', q.trim())
    if (entity) p.set('filter[entity]', entity)
    if (entityId) p.set('filter[entity_id]', entityId)
    if (from) p.set('filter[from]', from)
    if (to) p.set('filter[to]', to)
    if (next) p.set('cursor', next)
    return `/audit-logs?${p.toString()}`
  }, [q, entity, entityId, from, to])

  useEffect(() => {
    const id = window.setTimeout(async () => {
      const res = await api.get<{ data: Entry[]; meta: { next_cursor: string | null } }>(query())
      setRows(res.data)
      setCursor(res.meta.next_cursor)
    }, 250)
    return () => window.clearTimeout(id)
  }, [query])

  async function more() {
    if (!cursor) return
    const res = await api.get<{ data: Entry[]; meta: { next_cursor: string | null } }>(query(cursor))
    setRows((r) => [...(r ?? []), ...res.data])
    setCursor(res.meta.next_cursor)
  }

  const fmt = (iso: string) => fmtDT(iso, true)
  const entityLabel = (e: string) => ((ENTITIES as readonly string[]).includes(e) ? t(`audit.entity_${e}`) : e)

  function exportCsv() {
    // Exporta lo ya recibido: los campos sensibles llegan enmascarados según permisos.
    const header = [t('audit.col_when'), t('audit.col_user'), t('audit.col_action'), t('audit.col_entity'), 'ID', t('audit.col_fields'), t('audit.before'), t('audit.after')]
    const esc = (v: string) => `"${v.replace(/"/g, '""')}"`
    const lines = (rows ?? []).map((r) => [r.occurred_at, r.actor_name ?? t('audit.system'), r.action, entityLabel(r.entity), r.entity_id, r.changed_fields.join(' '), JSON.stringify(r.before ?? {}), JSON.stringify(r.after ?? {})].map(esc).join(','))
    const blob = new Blob(['﻿' + [header.map(esc).join(','), ...lines].join('\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = 'auditoria.csv'
    a.click()
    URL.revokeObjectURL(a.href)
  }

  const NOISE = ['version', 'allowed_actions', 'created_at', 'updated_at', 'id']
  const allFields = detail ? Array.from(new Set([...Object.keys(detail.before ?? {}), ...Object.keys(detail.after ?? {})])).filter((f) => !NOISE.includes(f)) : []
  // En un cambio se muestran primero los campos modificados.
  const fields = detail ? [...allFields.filter((f) => detail.changed_fields.includes(f)), ...allFields.filter((f) => !detail.changed_fields.includes(f))] : []

  return (
    <>
      <PageHeader
        title={t('settings.title')}
        sub={t('audit.sub')}
        tabs={settingsTabs(t, can, 'audit')}
        actions={can('audit.logs.export') && rows && rows.length > 0 && (
          <button type="button" className="btn btn-sec" onClick={exportCsv}>{t('audit.export')}</button>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="filtros">
            <input className="form-control" type="search" placeholder={t('audit.search')} aria-label={t('audit.search')} value={q} onChange={(e) => setQ(e.target.value)} />
            <select className="form-select" style={{ width: 'auto' }} aria-label={t('audit.col_entity')} value={entity} onChange={(e) => { setEntity(e.target.value); setEntityId('') }}>
              <option value="">{t('audit.entity_all')}</option>
              {ENTITIES.map((e) => <option key={e} value={e}>{t(`audit.entity_${e}`)}</option>)}
            </select>
            <label className="small text-secondary">{t('audit.from')} <input className="form-control" style={{ display: 'inline-block', width: 'auto' }} type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
            <label className="small text-secondary">{t('audit.to')} <input className="form-control" style={{ display: 'inline-block', width: 'auto' }} type="date" value={to} onChange={(e) => setTo(e.target.value)} /></label>
            {entityId && <button type="button" className="tag in" style={{ border: 0, cursor: 'pointer' }} onClick={() => setEntityId('')}>{t('audit.entity_filter', { id: entityId.slice(0, 8) })}</button>}
          </div>
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th>{t('audit.col_when')}</th><th>{t('audit.col_user')}</th><th>{t('audit.col_action')}</th><th>{t('audit.col_entity')}</th><th>{t('audit.col_fields')}</th></tr></thead>
              <tbody>
                {rows === null && [1, 2, 3].map((i) => <tr key={i}><td colSpan={5}><span className="esq" style={{ width: `${30 + i * 15}%` }} /></td></tr>)}
                {rows?.length === 0 && <tr><td colSpan={5}><div className="vacio">{t('audit.empty')}</div></td></tr>}
                {rows?.map((r) => (
                  <tr key={r.id} onClick={() => setDetail(r)} style={{ cursor: 'pointer' }}>
                    <td className="nowrap">{fmt(r.occurred_at)}</td>
                    <td>{r.actor_name ?? <span className="sub2">{t('audit.system')}</span>}</td>
                    <td><code>{r.action}</code> {r.sensitive && <span className="tag wa" title={t('audit.sensitive')}>{t('audit.sensitive_short')}</span>}</td>
                    <td>{entityLabel(r.entity)}<span className="sub2">{r.entity_id.slice(0, 8)}</span></td>
                    <td className="small" style={{ maxWidth: 360, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={r.changed_fields.join(', ')}>{r.changed_fields.join(', ') || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {cursor && <div className="card-body" style={{ textAlign: 'center' }}><button type="button" className="btn btn-sec btn-sm" onClick={() => void more()}>{t('audit.more')}</button></div>}
        </section>
      </main>
      {detail && (
        <SidePanel title={t('audit.detail')} onClose={() => setDetail(null)} closeLabel={t('common.close')}>
          <dl className="resumen mb-3">
            <dt>{t('audit.col_when')}</dt><dd>{fmt(detail.occurred_at)}</dd>
            <dt>{t('audit.col_user')}</dt><dd>{detail.actor_name ?? t('audit.system')}</dd>
            <dt>{t('audit.col_action')}</dt><dd><code>{detail.action}</code></dd>
            <dt>{t('audit.col_entity')}</dt><dd>{entityLabel(detail.entity)}</dd>
            {detail.reason && <><dt>{t('audit.reason')}</dt><dd>{detail.reason}</dd></>}
            {detail.ip && <><dt>{t('audit.ip')}</dt><dd>{detail.ip}</dd></>}
          </dl>
          <table className="table mb-3">
            <thead><tr><th>{t('audit.field')}</th><th>{t('audit.before')}</th><th>{t('audit.after')}</th></tr></thead>
            <tbody>
              {fields.map((f) => (
                <tr key={f} style={detail.changed_fields.includes(f) ? { background: 'var(--oc-50)' } : undefined}>
                  <td><b>{f}</b></td>
                  <td className="small" style={{ wordBreak: 'break-word' }}>{show(detail.before?.[f])}</td>
                  <td className="small" style={{ wordBreak: 'break-word' }}>{show(detail.after?.[f])}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {detail.entity_id && (
            <button type="button" className="btn btn-sec btn-sm" onClick={() => { setEntity(detail.entity); setEntityId(detail.entity_id); setDetail(null) }}>{t('audit.history')}</button>
          )}
        </SidePanel>
      )}
    </>
  )
}
