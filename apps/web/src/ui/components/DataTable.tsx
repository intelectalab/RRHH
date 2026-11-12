'use client'

import { useCallback, useEffect, useId, useMemo, useRef, useState, type KeyboardEvent, type PointerEvent, type ReactNode } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from './Icon'
import { Modal } from './Modal'
import { ErrorState } from './States'

/** Columna de la tabla. `sort` es el campo que admite la API en ?sort= (sin él, no se ordena). */
export type TableColumn<T> = {
  key: string
  label: string
  render: (row: T) => ReactNode
  sort?: string
  /** false: la columna no se puede ocultar (la principal de la fila). */
  hideable?: boolean
  /** Oculta al abrir la tabla por primera vez (el usuario la puede mostrar). */
  hiddenByDefault?: boolean
  /** Ancho inicial en píxeles. */
  width?: number
  className?: string
}

export type TableFilter = { key: string; label: string; options: Array<{ value: string; label: string }> }

/** Acción masiva: recibe las filas elegidas y devuelve cuántas se procesaron sin error. */
export type BulkAction<T> = { key: string; label: string; applies: (row: T) => boolean; run: (rows: T[]) => Promise<number>; danger?: boolean }

export type TableState = { q: string; filters: Record<string, string>; sort: string }

type SavedView = { id: string; name: string; filters: Record<string, unknown>; columns: string[]; sort: string; shared: boolean; mine: boolean }

const PER_PAGE = [25, 50, 100]

function readWidths(resource: string): Record<string, number> {
  try {
    const raw = window.localStorage.getItem(`gestia.table.${resource}.widths`)
    const parsed: unknown = raw ? JSON.parse(raw) : {}
    return parsed !== null && typeof parsed === 'object' ? (parsed as Record<string, number>) : {}
  } catch {
    return {}
  }
}

function storeWidths(resource: string, widths: Record<string, number>) {
  try { window.localStorage.setItem(`gestia.table.${resource}.widths`, JSON.stringify(widths)) } catch { /* solo en memoria */ }
}

/** Parámetros de consulta de la tabla para la API (paginación en servidor). */
export function tableQuery(state: TableState, page: number, perPage: number): URLSearchParams {
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage) })
  if (state.sort) params.set('sort', state.sort)
  if (state.q.trim()) params.set('q', state.q.trim())
  for (const [k, v] of Object.entries(state.filters)) if (v) params.set(`filter[${k}]`, v)
  return params
}

/** Siguiente orden al pulsar un encabezado: ascendente → descendente → sin orden propio. */
export function nextSort(current: string, field: string, fallback: string): string {
  if (current === field) return `-${field}`
  if (current === `-${field}`) return fallback
  return field
}

/**
 * UI-4 · Tabla profesional. Busca, filtra y ordena en el servidor y pagina con el total que devuelve
 * la API (sirve para listados muy grandes). Elegir, ocultar y redimensionar columnas; selección
 * múltiple con acciones masivas que dicen cuántos registros afectan y piden confirmación; vistas
 * guardadas por usuario (filtros, columnas y orden) en /saved-views. Las columnas sin permiso no las
 * recibe la tabla: una vista que las nombre simplemente no las muestra. En pantallas angostas cada
 * fila se ve como tarjeta (RN-UI-06).
 */
export function DataTable<T>({ resource, endpoint, columns, rowKey, rowActions, filters = [], bulkActions = [], defaultSort, searchLabel, empty, toolbar, reloadKey = 0, onTotal, urlSearch = false }: {
  resource: string
  endpoint: string
  columns: Array<TableColumn<T>>
  rowKey: (row: T) => string
  rowActions?: (row: T) => ReactNode
  filters?: TableFilter[]
  bulkActions?: Array<BulkAction<T>>
  defaultSort: string
  searchLabel: string
  empty: ReactNode
  toolbar?: (state: TableState) => ReactNode
  reloadKey?: number
  onTotal?: (total: number) => void
  /** Toma la búsqueda inicial de ?q= en la dirección. */
  urlSearch?: boolean
}) {
  const { t } = useT()
  const { can } = useSession()
  const [state, setState] = useState<TableState>({ q: '', filters: {}, sort: defaultSort })
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [rows, setRows] = useState<T[] | null>(null)
  const [total, setTotal] = useState(0)
  const [failed, setFailed] = useState(false)
  const [hidden, setHidden] = useState<Set<string>>(() => new Set(columns.filter((c) => c.hiddenByDefault).map((c) => c.key)))
  const [widths, setWidths] = useState<Record<string, number>>({})
  const [selected, setSelected] = useState<Map<string, T>>(new Map())
  const [confirming, setConfirming] = useState<BulkAction<T> | null>(null)
  const [bulkBusy, setBulkBusy] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [views, setViews] = useState<SavedView[]>([])
  const [viewId, setViewId] = useState('')
  const [saving, setSaving] = useState(false)
  const [chooser, setChooser] = useState(false)
  const chooserRef = useRef<HTMLDivElement>(null)
  const chooserId = useId()
  const onTotalRef = useRef(onTotal)
  onTotalRef.current = onTotal

  useEffect(() => setWidths(readWidths(resource)), [resource])

  // Enlace desde la búsqueda global: …?q=texto abre la tabla ya filtrada.
  useEffect(() => {
    if (!urlSearch) return
    const initial = new URLSearchParams(window.location.search).get('q')
    if (initial) setState((s) => ({ ...s, q: initial }))
  }, [urlSearch])

  const load = useCallback(async () => {
    try {
      const res = await api.get<{ data: T[]; meta?: { total?: number | null } }>(`${endpoint}?${tableQuery(state, page, perPage).toString()}`)
      setRows(res.data)
      const n = res.meta?.total ?? res.data.length
      setTotal(n)
      onTotalRef.current?.(n)
      setFailed(false)
    } catch {
      setFailed(true)
    }
  }, [endpoint, state, page, perPage])

  useEffect(() => {
    const id = window.setTimeout(() => void load(), 250)
    return () => window.clearTimeout(id)
  }, [load, reloadKey])

  const loadViews = useCallback(async () => {
    if (!can('settings.saved_views.view')) return
    try {
      const res = await api.get<{ data: SavedView[] }>(`/saved-views?filter[resource]=${encodeURIComponent(resource)}`)
      setViews(res.data)
    } catch { /* sin vistas: la tabla funciona igual */ }
  }, [can, resource])

  useEffect(() => { void loadViews() }, [loadViews])

  useEffect(() => {
    if (!chooser) return
    const close = (e: MouseEvent) => { if (chooserRef.current && !chooserRef.current.contains(e.target as Node)) setChooser(false) }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [chooser])

  const visible = columns.filter((c) => !hidden.has(c.key))
  const selectable = bulkActions.length > 0
  const span = visible.length + (selectable ? 1 : 0) + (rowActions ? 1 : 0)
  const pages = Math.max(1, Math.ceil(total / perPage))
  const from = total === 0 ? 0 : (page - 1) * perPage + 1
  const to = Math.min(total, page * perPage)
  const view = views.find((v) => v.id === viewId) ?? null

  function update(patch: Partial<TableState>) {
    setState((s) => ({ ...s, ...patch }))
    setPage(1)
  }

  function applyView(id: string) {
    setViewId(id)
    const v = views.find((x) => x.id === id)
    if (!v) {
      setState({ q: '', filters: {}, sort: defaultSort })
      setHidden(new Set(columns.filter((c) => c.hiddenByDefault).map((c) => c.key)))
    } else {
      const raw = Object.fromEntries(Object.entries(v.filters).map(([k, val]) => [k, typeof val === 'string' ? val : '']))
      const { q = '', ...rest } = raw
      const known = new Set(filters.map((f) => f.key))
      setState({ q, filters: Object.fromEntries(Object.entries(rest).filter(([k]) => known.has(k))), sort: v.sort || defaultSort })
      // Se muestran solo las columnas de la vista que esta persona puede ver; las que no pueden ocultarse siempre quedan.
      if (v.columns.length > 0) setHidden(new Set(columns.filter((c) => c.hideable !== false && !v.columns.includes(c.key)).map((c) => c.key)))
    }
    setPage(1)
    setSelected(new Map())
  }

  function viewBody() {
    return { filters: { ...state.filters, ...(state.q.trim() ? { q: state.q.trim() } : {}) }, columns: visible.map((c) => c.key), sort: state.sort }
  }

  async function updateView() {
    if (!view) return
    try {
      await api.request(`/saved-views/${view.id}`, 'PATCH', viewBody())
      setNotice(t('table.view_updated', { name: view.name }))
      await loadViews()
    } catch (e) {
      setNotice(e instanceof ApiRequestError ? e.message : t('table.view_error'))
    }
  }

  async function deleteView() {
    if (!view || !window.confirm(t('table.view_delete_confirm', { name: view.name }))) return
    try {
      await api.request(`/saved-views/${view.id}`, 'DELETE')
      setViewId('')
      setNotice(t('table.view_deleted'))
      await loadViews()
    } catch (e) {
      setNotice(e instanceof ApiRequestError ? e.message : t('table.view_error'))
    }
  }

  function toggleColumn(key: string) {
    setHidden((h) => {
      const next = new Set(h)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  function setWidth(key: string, px: number) {
    setWidths((w) => {
      const next = { ...w, [key]: Math.min(640, Math.max(64, Math.round(px))) }
      storeWidths(resource, next)
      return next
    })
  }

  function startResize(e: PointerEvent<HTMLSpanElement>, key: string) {
    const th = e.currentTarget.parentElement
    if (!th) return
    e.preventDefault()
    const startX = e.clientX
    const startW = th.getBoundingClientRect().width
    const move = (ev: globalThis.PointerEvent) => setWidth(key, startW + ev.clientX - startX)
    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up) }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
  }

  function resizeKey(e: KeyboardEvent<HTMLSpanElement>, key: string) {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return
    e.preventDefault()
    const th = e.currentTarget.parentElement
    const current = widths[key] ?? th?.getBoundingClientRect().width ?? 160
    setWidth(key, current + (e.key === 'ArrowRight' ? 16 : -16))
  }

  function toggleRow(row: T) {
    setSelected((s) => {
      const next = new Map(s)
      const id = rowKey(row)
      if (next.has(id)) next.delete(id)
      else next.set(id, row)
      return next
    })
  }

  const pageIds = useMemo(() => (rows ?? []).map(rowKey), [rows, rowKey])
  const allOnPage = pageIds.length > 0 && pageIds.every((id) => selected.has(id))

  function togglePage() {
    setSelected((s) => {
      const next = new Map(s)
      for (const row of rows ?? []) {
        if (allOnPage) next.delete(rowKey(row))
        else next.set(rowKey(row), row)
      }
      return next
    })
  }

  const chosen = [...selected.values()]
  const affected = confirming ? chosen.filter(confirming.applies) : []

  async function runBulk() {
    if (!confirming) return
    setBulkBusy(true)
    try {
      const done = await confirming.run(affected)
      setNotice(t('table.bulk_done', { done, total: affected.length }))
      setSelected(new Map())
      setConfirming(null)
      await load()
    } finally {
      setBulkBusy(false)
    }
  }

  const sortState = (field?: string): 'ascending' | 'descending' | 'none' => (!field ? 'none' : state.sort === field ? 'ascending' : state.sort === `-${field}` ? 'descending' : 'none')

  return (
    <section className="card dt">
      <div className="dt-barra">
        <input className="form-control dt-buscar" type="search" placeholder={searchLabel} aria-label={searchLabel} value={state.q} onChange={(e) => update({ q: e.target.value })} />
        {filters.map((f) => (
          <select key={f.key} className="form-select dt-filtro" aria-label={f.label} value={state.filters[f.key] ?? ''} onChange={(e) => update({ filters: { ...state.filters, [f.key]: e.target.value } })}>
            <option value="">{f.label}</option>
            {f.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        ))}
        <div className="dt-herr">
          {can('settings.saved_views.view') && (
            <select className="form-select dt-vista" aria-label={t('table.views')} value={viewId} onChange={(e) => applyView(e.target.value)}>
              <option value="">{t('table.view_default')}</option>
              {views.map((v) => <option key={v.id} value={v.id}>{v.mine ? v.name : t('table.view_shared_name', { name: v.name })}</option>)}
            </select>
          )}
          {view?.mine && can('settings.saved_views.update') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void updateView()}>{t('table.view_update')}</button>}
          {view?.mine && can('settings.saved_views.delete') && <button type="button" className="btn btn-ter btn-sm" onClick={() => void deleteView()}>{t('table.view_delete')}</button>}
          {can('settings.saved_views.create') && <button type="button" className="btn btn-ter btn-sm" onClick={() => setSaving(true)}>{t('table.view_save')}</button>}
          <div className="menu" ref={chooserRef}>
            <button type="button" className="btn btn-sec btn-sm" aria-expanded={chooser} aria-controls={chooser ? chooserId : undefined} onClick={() => setChooser((v) => !v)} onKeyDown={(e) => { if (e.key === 'Escape') setChooser(false) }}>
              <Icon name="sliders" size={16} /> {t('table.columns')}
            </button>
            {chooser && (
              <div id={chooserId} className="menu-lista dt-columnas" role="group" aria-label={t('table.columns')} onKeyDown={(e) => { if (e.key === 'Escape') setChooser(false) }}>
                {columns.map((c) => (
                  <label key={c.key} className="form-check">
                    <input type="checkbox" className="form-check-input" checked={!hidden.has(c.key)} disabled={c.hideable === false} onChange={() => toggleColumn(c.key)} />
                    <span>{c.label}</span>
                  </label>
                ))}
              </div>
            )}
          </div>
          {toolbar?.(state)}
        </div>
      </div>

      {selectable && selected.size > 0 && (
        <div className="dt-masiva" role="region" aria-label={t('table.bulk_region')}>
          <b>{t('table.selected', { n: selected.size })}</b>
          {bulkActions.map((a) => (
            <button key={a.key} type="button" className={`btn btn-sm ${a.danger ? 'btn-pel-sec' : 'btn-sec'}`} disabled={!chosen.some(a.applies)} onClick={() => setConfirming(a)}>{a.label}</button>
          ))}
          <button type="button" className="btn btn-ter btn-sm" onClick={() => setSelected(new Map())}>{t('table.clear_selection')}</button>
        </div>
      )}
      {notice && <div className="dt-aviso" role="status">{notice}<button type="button" className="ibtn" aria-label={t('ui.close')} onClick={() => setNotice(null)}><Icon name="close" size={16} /></button></div>}

      <div className="tabla-wrap">
        <table className="table dt-tabla">
          <colgroup>
            {selectable && <col style={{ width: 44 }} />}
            {visible.map((c) => <col key={c.key} style={widths[c.key] ?? c.width ? { width: widths[c.key] ?? c.width } : undefined} />)}
            {rowActions && <col />}
          </colgroup>
          <thead>
            <tr>
              {selectable && (
                <th className="dt-sel"><input type="checkbox" className="form-check-input" aria-label={t('table.select_page')} checked={allOnPage} onChange={togglePage} /></th>
              )}
              {visible.map((c) => (
                <th key={c.key} aria-sort={c.sort ? sortState(c.sort) : undefined} className={c.className}>
                  {c.sort ? (
                    <button type="button" className="dt-orden" onClick={() => update({ sort: nextSort(state.sort, c.sort as string, defaultSort) })}>
                      {c.label}
                      <span className={`dt-flecha ${sortState(c.sort)}`} aria-hidden="true" />
                    </button>
                  ) : c.label}
                  <span className="dt-ancho" role="separator" aria-orientation="vertical" tabIndex={0} aria-label={t('table.resize', { column: c.label })} onPointerDown={(e) => startResize(e, c.key)} onKeyDown={(e) => resizeKey(e, c.key)} />
                </th>
              ))}
              {rowActions && <th><span className="vh">{t('table.actions')}</span></th>}
            </tr>
          </thead>
          <tbody>
            {failed && <tr><td colSpan={span}><ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} /></td></tr>}
            {!failed && rows === null && [1, 2, 3].map((i) => (
              <tr key={i} aria-hidden="true"><td colSpan={span}><span className="esq" style={{ width: `${40 + i * 12}%` }} /></td></tr>
            ))}
            {!failed && rows?.length === 0 && <tr><td colSpan={span}>{empty}</td></tr>}
            {!failed && rows?.map((row) => {
              const id = rowKey(row)
              return (
                <tr key={id} className={selected.has(id) ? 'sel' : undefined}>
                  {selectable && (
                    <td className="dt-sel"><input type="checkbox" className="form-check-input" aria-label={t('table.select_row')} checked={selected.has(id)} onChange={() => toggleRow(row)} /></td>
                  )}
                  {visible.map((c) => <td key={c.key} data-label={c.label} className={c.className}>{c.render(row)}</td>)}
                  {rowActions && <td className="text-end nowrap dt-acciones">{rowActions(row)}</td>}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="dt-pie">
        <span className="sub2" aria-live="polite">{t('table.range', { from, to, total })}</span>
        <label className="dt-por-pagina">
          <span>{t('table.per_page')}</span>
          <select className="form-select form-select-sm" value={perPage} onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1) }}>
            {PER_PAGE.map((n) => <option key={n} value={n}>{n}</option>)}
          </select>
        </label>
        <div className="dt-paginas">
          <button type="button" className="ibtn" aria-label={t('table.prev')} disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}><Icon name="chevronLeft" /></button>
          <span>{t('table.page', { page, pages })}</span>
          <button type="button" className="ibtn" aria-label={t('table.next')} disabled={page >= pages} onClick={() => setPage((p) => Math.min(pages, p + 1))}><Icon name="chevronRight" /></button>
        </div>
      </div>

      {confirming && (
        <Modal
          title={confirming.label}
          tone={confirming.danger ? 'danger' : 'normal'}
          size="s"
          onClose={() => setConfirming(null)}
          footer={(
            <>
              <button type="button" className="btn btn-ter" onClick={() => setConfirming(null)}>{t('common.cancel')}</button>
              <button type="button" className={`btn ${confirming.danger ? 'btn-pel-sec' : 'btn-primary'}`} disabled={bulkBusy || affected.length === 0} aria-busy={bulkBusy} onClick={() => void runBulk()}>
                {t('table.bulk_confirm', { n: affected.length })}
              </button>
            </>
          )}
        >
          <p>{t('table.bulk_summary', { n: affected.length, action: confirming.label })}</p>
          {affected.length < chosen.length && <p className="sub2">{t('table.bulk_skipped', { n: chosen.length - affected.length })}</p>}
        </Modal>
      )}
      {saving && <SaveViewModal resource={resource} body={viewBody()} canShare onClose={() => setSaving(false)} onSaved={async (v) => { setSaving(false); setNotice(t('table.view_saved', { name: v.name })); await loadViews(); setViewId(v.id) }} />}
    </section>
  )
}

function SaveViewModal({ resource, body, canShare, onClose, onSaved }: {
  resource: string
  body: { filters: Record<string, string>; columns: string[]; sort: string }
  canShare: boolean
  onClose: () => void
  onSaved: (view: SavedView) => Promise<void>
}) {
  const { t } = useT()
  const [name, setName] = useState('')
  const [shared, setShared] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const inputId = useId()

  async function save() {
    if (!name.trim()) { setError(t('table.view_name_required')); return }
    setBusy(true)
    try {
      const res = await api.post<{ data: SavedView }>('/saved-views', { resource, name: name.trim(), shared, ...body })
      await onSaved(res.data)
    } catch (e) {
      setError(e instanceof ApiRequestError ? e.message : t('table.view_error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      title={t('table.view_save_title')}
      size="s"
      onClose={onClose}
      footer={(
        <>
          <button type="button" className="btn btn-ter" onClick={onClose}>{t('common.cancel')}</button>
          <button type="button" className="btn btn-primary" disabled={busy} aria-busy={busy} onClick={() => void save()}>{t('table.view_save')}</button>
        </>
      )}
    >
      <label className="form-label" htmlFor={inputId}>{t('table.view_name')}</label>
      <input id={inputId} className={`form-control${error ? ' is-invalid' : ''}`} value={name} maxLength={80} onChange={(e) => { setName(e.target.value); setError(null) }} onKeyDown={(e) => { if (e.key === 'Enter') void save() }} aria-invalid={error ? true : undefined} />
      {error && <div className="invalid-feedback" style={{ display: 'block' }}>{error}</div>}
      <p className="sub2 mt-2">{t('table.view_contents', { columns: body.columns.length })}</p>
      {canShare && (
        <label className="form-check mt-2">
          <input type="checkbox" className="form-check-input" checked={shared} onChange={(e) => setShared(e.target.checked)} />
          <span>{t('table.view_share')}</span>
        </label>
      )}
    </Modal>
  )
}
