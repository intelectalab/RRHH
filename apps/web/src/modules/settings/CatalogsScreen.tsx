'use client'

import Link from 'next/link'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, ApiRequestError } from '@/lib/api'
import { formErrors } from '@/lib/errors'
import { locales } from '@/lib/i18n'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'

type Source = 'master' | 'system' | 'organization'
type CatalogRow = { code: string; name: string; description: string | null; source: Source; is_extensible: boolean; country_code: string | null; items_count: number; own_items_count: number }
type Item = { id: string | null; code: string; label: string; labels: Record<string, string>; statutory_code: string | null; parent_code: string | null; source: Source; is_active: boolean; is_verified: boolean; valid_from: string | null; valid_to: string | null; sort_order: number }

const SOURCE_TAG: Record<Source, string> = { master: 'in', system: 'gr', organization: 'pe' }

/**
 * Configuración → Catálogos (CFG-10). Los maestros los mantiene la plataforma (solo lectura aquí);
 * los extensibles y los propios admiten elementos de la organización, que se archivan y no se borran.
 */
export function CatalogsScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const editable = can('settings.catalogs.update')
  const [catalogs, setCatalogs] = useState<CatalogRow[] | null>(null)
  const [selected, setSelected] = useState<string | null>(null)
  const [items, setItems] = useState<Item[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [archived, setArchived] = useState(false)
  const [q, setQ] = useState('')
  const [panel, setPanel] = useState<Item | 'new-item' | 'new-catalog' | null>(null)

  const loadCatalogs = useCallback(async () => {
    try {
      const res = await api.get<{ data: CatalogRow[] }>('/catalogs')
      setCatalogs(res.data)
      setSelected((s) => s ?? res.data[0]?.code ?? null)
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [])
  // El idioma forma parte de la carga: al cambiarlo, los nombres y etiquetas vuelven traducidos.
  useEffect(() => { void loadCatalogs() }, [loadCatalogs, locale])

  const loadItems = useCallback(async () => {
    if (!selected) return
    setItems(null)
    try {
      setItems((await api.get<{ data: Item[] }>(`/catalogs/${selected}/items${archived ? '?include_archived=1' : ''}`)).data)
    } catch {
      setItems([])
      toast(t('common.load_error'), 'error')
    }
  }, [selected, archived, toast, t])
  useEffect(() => { void loadItems() }, [loadItems, locale])

  const current = catalogs?.find((c) => c.code === selected) ?? null
  const canAdd = editable && current !== null && current.source !== 'system' && current.is_extensible
  const norm = (s: string) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()
  const visible = useMemo(() => {
    const term = norm(q.trim())
    return (items ?? []).filter((i) => !term || norm(`${i.code} ${i.label} ${i.statutory_code ?? ''}`).includes(term))
  }, [items, q])

  async function toggle(i: Item) {
    if (!i.id) return
    try {
      await api.post(`/catalog-items/${i.id}/${i.is_active ? 'archive' : 'reactivate'}`, {})
      toast(t(i.is_active ? 'catalogs.archived' : 'catalogs.reactivated'))
      await Promise.all([loadItems(), loadCatalogs()])
    } catch (e) {
      toast(e instanceof ApiRequestError ? e.message : t('auth.generic_error'), 'error')
    }
  }

  return (
    <>
      <PageHeader title={t('catalogs.title')} sub={t('catalogs.sub')} actions={<>
        <Link className="btn btn-sec" href="/configuracion">{t('cfg.back')}</Link>
        {editable && <button type="button" className="btn btn-primary" onClick={() => setPanel('new-catalog')}><Icon name="plus" /> {t('catalogs.new_catalog')}</button>}
      </>} />
      <main className="main" id="contenido" tabIndex={-1}>
        <div className="nota">{t('catalogs.help')}</div>
        {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void loadCatalogs()} />}
        <div className="cat-grid">
          <section className="card" aria-label={t('catalogs.list')}>
            {catalogs === null && !loadError && <div style={{ padding: 16 }}><span className="esq" style={{ width: '70%' }} /></div>}
            <ul className="lista">
              {catalogs?.map((c) => (
                <li key={c.code}>
                  <button type="button" className={c.code === selected ? 'on' : undefined} aria-current={c.code === selected ? 'true' : undefined} onClick={() => { setSelected(c.code); setQ('') }}>
                    <span><b>{c.name}</b><span className="sub2"><code>{c.code}</code>{c.country_code ? ` · ${c.country_code}` : ''}</span></span>
                    <span className="tag gr">{c.items_count}</span>
                  </button>
                </li>
              ))}
            </ul>
          </section>

          <section className="card">
            {current && (
              <div className="cat-cab">
                <div>
                  <h2>{current.name}</h2>
                  <div className="tags" style={{ marginTop: 4 }}>
                    <span className={`tag ${SOURCE_TAG[current.source]}`}>{t(`catalogs.source_${current.source}`)}</span>
                    {current.source === 'master' && <span className="tag gr">{t(current.is_extensible ? 'catalogs.extensible' : 'catalogs.locked')}</span>}
                  </div>
                  {current.description && <span className="sub2" style={{ marginTop: 6 }}>{current.description}</span>}
                </div>
                {canAdd && <button type="button" className="btn btn-sec btn-sm" onClick={() => setPanel('new-item')}><Icon name="plus" /> {t('catalogs.new_item')}</button>}
              </div>
            )}
            <div className="barra-filtros" style={{ padding: '0 16px' }}>
              <input type="search" className="form-control" style={{ maxWidth: 320 }} placeholder={t('catalogs.search')} aria-label={t('catalogs.search')} value={q} onChange={(e) => setQ(e.target.value)} />
              <label className="check"><input type="checkbox" checked={archived} onChange={(e) => setArchived(e.target.checked)} /> {t('catalogs.show_archived')}</label>
            </div>
            <div className="tabla-wrap">
              <table className="table">
                <thead><tr><th>{t('catalogs.col_label')}</th><th>{t('catalogs.col_code')}</th><th>{t('catalogs.col_statutory')}</th><th>{t('catalogs.col_state')}</th><th /></tr></thead>
                <tbody>
                  {items === null && <tr><td colSpan={5}><span className="esq" style={{ width: '40%' }} /></td></tr>}
                  {items !== null && visible.length === 0 && <tr><td colSpan={5}><EmptyState title={t('catalogs.empty_title')} text={t(q ? 'catalogs.empty_search' : 'catalogs.empty_text')} /></td></tr>}
                  {visible.map((i) => (
                    <tr key={`${i.source}-${i.code}`}>
                      <td><b>{i.label}</b>{i.parent_code && <span className="sub2">{t('catalogs.parent', { code: i.parent_code })}</span>}</td>
                      <td><code>{i.code}</code></td>
                      <td>{i.statutory_code ? <code>{i.statutory_code}</code> : <span className="sub2">{t('catalogs.no_statutory')}</span>}</td>
                      <td>
                        <div className="tags" style={{ marginTop: 0 }}>
                          {i.source === 'organization' && <span className="tag pe">{t('catalogs.own')}</span>}
                          {!i.is_active && <span className="tag wa">{t('catalogs.archived_tag')}</span>}
                          {!i.is_verified && <span className="tag oc" title={t('catalogs.unverified_help')}>{t('catalogs.unverified')}</span>}
                        </div>
                      </td>
                      <td className="text-end nowrap">
                        {editable && i.id && <button type="button" className="btn btn-ter btn-sm" onClick={() => setPanel(i)}>{t('common.edit')}</button>}
                        {editable && i.id && <button type="button" className="btn btn-ter btn-sm" onClick={() => void toggle(i)}>{t(i.is_active ? 'catalogs.archive' : 'catalogs.reactivate')}</button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </main>
      {panel === 'new-catalog' && <CatalogPanel onClose={() => setPanel(null)} onSaved={async (code) => { setPanel(null); toast(t('catalogs.catalog_created')); setSelected(code); await loadCatalogs() }} />}
      {selected && panel !== null && panel !== 'new-catalog' && (
        <ItemPanel catalog={selected} item={panel === 'new-item' ? null : panel} onClose={() => setPanel(null)} onSaved={async (created) => { setPanel(null); toast(t(created ? 'catalogs.item_created' : 'catalogs.item_saved')); await Promise.all([loadItems(), loadCatalogs()]) }} />
      )}
      {toastNode}
    </>
  )
}

function CatalogPanel({ onClose, onSaved }: { onClose: () => void; onSaved: (code: string) => Promise<void> }) {
  const { t } = useT()
  const [form, setForm] = useState({ code: '', name: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    try {
      await api.post('/catalogs', form)
      await onSaved(form.code)
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      setError(r.general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel title={t('catalogs.new_catalog')} onClose={onClose} closeLabel={t('common.close')} footer={<>
      <button type="button" className="btn btn-sec" onClick={onClose}>{t('common.cancel')}</button>
      <button type="submit" form="catalogo" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('common.save')}</button>
    </>}>
      <form id="catalogo" onSubmit={submit} noValidate>
        <div className="mb-3">
          <label className="form-label" htmlFor="c-name">{t('catalogs.name')}</label>
          <input id="c-name" className={`form-control${errors.name ? ' is-invalid' : ''}`} value={form.name} maxLength={120} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} />
          {errors.name && <div className="invalid-feedback" role="alert">{errors.name}</div>}
        </div>
        <div className="mb-3">
          <label className="form-label" htmlFor="c-code">{t('catalogs.code')}</label>
          <input id="c-code" className={`form-control${errors.code ? ' is-invalid' : ''}`} value={form.code} onChange={(e) => setForm((f) => ({ ...f, code: e.target.value }))} aria-describedby="c-code-h" />
          <div id="c-code-h" className="small text-secondary">{t('catalogs.code_help')}</div>
          {errors.code && <div className="invalid-feedback" role="alert">{errors.code}</div>}
        </div>
        {error && <div className="invalid-feedback" role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}

function ItemPanel({ catalog, item, onClose, onSaved }: { catalog: string; item: Item | null; onClose: () => void; onSaved: (created: boolean) => Promise<void> }) {
  const { t } = useT()
  const [code, setCode] = useState(item?.code ?? '')
  const [labels, setLabels] = useState<Record<string, string>>(() => Object.fromEntries(locales.map((l) => [l, item?.labels[l] ?? ''])))
  const [sort, setSort] = useState(String(item?.sort_order ?? 0))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    const body = { labels: Object.fromEntries(Object.entries(labels).filter(([, v]) => v.trim() !== '')), sort_order: Number(sort) || 0 }
    try {
      if (item?.id) await api.request(`/catalog-items/${item.id}`, 'PATCH', body)
      else await api.post(`/catalogs/${catalog}/items`, { ...body, code })
      await onSaved(item === null)
    } catch (err) {
      const r = formErrors(err, t)
      setErrors(r.fields)
      setError(r.general)
    } finally {
      setBusy(false)
    }
  }

  return (
    <SidePanel title={t(item ? 'catalogs.edit_item' : 'catalogs.new_item')} onClose={onClose} closeLabel={t('common.close')} footer={<>
      <button type="button" className="btn btn-sec" onClick={onClose}>{t('common.cancel')}</button>
      <button type="submit" form="elemento" className="btn btn-primary" disabled={busy}>{busy && <span className="spin" aria-hidden="true" />}{t('common.save')}</button>
    </>}>
      <form id="elemento" onSubmit={submit} noValidate>
        <div className="mb-3">
          <label className="form-label" htmlFor="i-code">{t('catalogs.code')}</label>
          <input id="i-code" className={`form-control${errors.code ? ' is-invalid' : ''}`} value={code} onChange={(e) => setCode(e.target.value)} disabled={item !== null} aria-describedby="i-code-h" />
          <div id="i-code-h" className="small text-secondary">{t('catalogs.item_code_help')}</div>
          {errors.code && <div className="invalid-feedback" role="alert">{errors.code}</div>}
        </div>
        {locales.map((l) => (
          <div className="mb-3" key={l}>
            <label className="form-label" htmlFor={`i-label-${l}`}>{t(`catalogs.label_${l}`)}</label>
            <input id={`i-label-${l}`} lang={l} className={`form-control${errors[`labels.${l}`] ? ' is-invalid' : ''}`} value={labels[l] ?? ''} maxLength={200} onChange={(e) => setLabels((x) => ({ ...x, [l]: e.target.value }))} />
            {errors[`labels.${l}`] && <div className="invalid-feedback" role="alert">{errors[`labels.${l}`]}</div>}
          </div>
        ))}
        <div className="mb-3">
          <label className="form-label" htmlFor="i-sort">{t('catalogs.sort_order')}</label>
          <input id="i-sort" type="number" min={0} className="form-control" style={{ maxWidth: 120 }} value={sort} onChange={(e) => setSort(e.target.value)} />
        </div>
        {error && <div className="invalid-feedback" role="alert">{error}</div>}
      </form>
    </SidePanel>
  )
}
