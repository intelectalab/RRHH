'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { useDateTime } from '@/modules/session/useDateTime'

type Item = { type: string; id: string; title: string; subtitle: string | null; deleted_at: string; deleted_by: string | null; purge_on: string | null }

/** Configuración → Papelera (CFG-9): quién eliminó, cuándo, cuándo se depura y restaurar. */
export function TrashScreen() {
  const { t, locale } = useT()
  const fmtDT = useDateTime()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const [rows, setRows] = useState<Item[] | null>(null)
  const [days, setDays] = useState<number | null>(null)
  const [q, setQ] = useState('')
  const [loadError, setLoadError] = useState(false)

  const load = useCallback(async () => {
    try {
      const res = await api.get<{ data: Item[]; meta: { retention_days: number | null } }>(`/trash${q ? `?q=${encodeURIComponent(q)}` : ''}`)
      setRows(res.data)
      setDays(res.meta.retention_days)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [q])
  useEffect(() => { const h = window.setTimeout(() => void load(), 250); return () => window.clearTimeout(h) }, [load])

  async function restore(item: Item) {
    try {
      await api.post(`/trash/${item.id}/restore`, {})
      toast(t('trash.restored'))
      await load()
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  const typeLabel = (type: string) => (t(`trash.type_${type}`) === `trash.type_${type}` ? type : t(`trash.type_${type}`))

  return (
    <>
      <PageHeader title={t('trash.title')} sub={days ? t('trash.sub', { days }) : t('trash.sub_nodays')} actions={<Link className="btn btn-sec" href="/configuracion">{t('cfg.back')}</Link>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="barra-filtros">
          <input type="search" className="form-control" style={{ maxWidth: 360 }} placeholder={t('trash.search')} aria-label={t('trash.search')} value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <section className="card">
          <div className="tabla-wrap">
            <table className="table">
              <thead><tr><th>{t('trash.col_record')}</th><th>{t('trash.col_deleted')}</th><th>{t('trash.col_purge')}</th><th /></tr></thead>
              <tbody>
                {loadError && <tr><td colSpan={4}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
                {!loadError && rows === null && <tr><td colSpan={4}><span className="esq" style={{ width: '40%' }} /></td></tr>}
                {rows?.length === 0 && <tr><td colSpan={4}><EmptyState title={t('trash.empty_title')} text={t('trash.empty_text')} /></td></tr>}
                {rows?.map((r) => (
                  <tr key={r.id}>
                    <td><span className="tag gr" style={{ marginRight: 6 }}>{typeLabel(r.type)}</span><b>{r.title}</b>{r.subtitle && <span className="sub2">{r.subtitle}</span>}</td>
                    <td className="nowrap">{fmtDT(r.deleted_at)}{r.deleted_by && <span className="sub2">{t('trash.by', { name: r.deleted_by })}</span>}</td>
                    <td className="nowrap">{r.purge_on ? formatDate(locale, r.purge_on) : '—'}</td>
                    <td className="text-end">{can('audit.trash.restore') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void restore(r)}>{t('trash.restore')}</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
      {toastNode}
    </>
  )
}
