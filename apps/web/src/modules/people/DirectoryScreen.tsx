'use client'

import { Fragment, useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/api'
import { initials } from '@/lib/format'
import { useT } from '@/lib/useT'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { SidePanel } from '@/ui/components/SidePanel'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { peopleTabs } from './peopleTabs'

export type Ref = { id: string; name: string | null }
export type DirectoryPerson = {
  employment_id: string
  display_name: string
  job_title: string | null
  org_unit: Ref | null
  site: Ref | null
  legal_entity: Ref
  manager_employment_id: string | null
  contact: Partial<Record<'work_email' | 'work_phone' | 'work_phone_ext', string | null>>
}
type Card = DirectoryPerson & { manager: DirectoryPerson | null; peers: DirectoryPerson[]; direct_reports: DirectoryPerson[] }
type Page = { data: DirectoryPerson[]; meta: { page: number; per_page: number; total: number } }
type Option = { id: string; name: string }

const PER_PAGE = 24

/** ORG-7 · Personas → Directorio: búsqueda por nombre, puesto, unidad y sede; tarjeta con su equipo. */
export function DirectoryScreen() {
  const { t } = useT()
  const { can } = useSession()
  const [q, setQ] = useState('')
  const [unit, setUnit] = useState('')
  const [site, setSite] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState<Page | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [units, setUnits] = useState<Option[]>([])
  const [sites, setSites] = useState<Option[]>([])
  const [open, setOpen] = useState<string | null>(null)

  const load = useCallback(async () => {
    const params = new URLSearchParams({ page: String(page), per_page: String(PER_PAGE) })
    if (q.trim() !== '') params.set('q', q.trim())
    if (unit) params.set('org_unit_id', unit)
    if (site) params.set('site_id', site)
    try {
      setResult(await api.get<Page>(`/directory?${params.toString()}`))
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [q, unit, site, page])

  useEffect(() => {
    const id = setTimeout(() => void load(), 250)
    return () => clearTimeout(id)
  }, [load])

  useEffect(() => {
    // Las unidades salen del organigrama (mismo permiso que el directorio).
    void api.get<{ data: Option[] }>('/org-chart?view=units').then((r) => setUnits(r.data.map((u) => ({ id: u.id, name: u.name })))).catch(() => setUnits([]))
    if (can('org.sites.view')) void api.get<{ data: Option[] }>('/sites?per_page=100').then((r) => setSites(r.data)).catch(() => setSites([]))
  }, [can])

  const total = result?.meta.total ?? 0
  const pages = Math.max(1, Math.ceil(total / PER_PAGE))

  return (
    <>
      <PageHeader title={t('people.title')} sub={result ? t('people.dir_sub', { n: total }) : undefined} tabs={peopleTabs(t, 'directory', can)} />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="dir-filtros">
            <label className="dir-buscar">
              <span className="vh">{t('people.search')}</span>
              <input className="form-control" type="search" value={q} placeholder={t('people.search_ph')} onChange={(e) => { setQ(e.target.value); setPage(1) }} />
            </label>
            {units.length > 0 && (
              <label>
                <span className="vh">{t('people.filter_unit')}</span>
                <select className="form-select" value={unit} onChange={(e) => { setUnit(e.target.value); setPage(1) }}>
                  <option value="">{t('people.all_units')}</option>
                  {units.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                </select>
              </label>
            )}
            {sites.length > 0 && (
              <label>
                <span className="vh">{t('people.filter_site')}</span>
                <select className="form-select" value={site} onChange={(e) => { setSite(e.target.value); setPage(1) }}>
                  <option value="">{t('people.all_sites')}</option>
                  {sites.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
              </label>
            )}
          </div>
          {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
          {!loadError && result === null && (
            <div className="dir-grid" aria-busy="true">{[1, 2, 3].map((i) => <div key={i} className="dir-card"><span className="esq" style={{ width: '70%' }} /></div>)}</div>
          )}
          {!loadError && result?.data.length === 0 && (
            <EmptyState title={t(q || unit || site ? 'people.no_results_title' : 'people.empty_title')} text={t(q || unit || site ? 'people.no_results_text' : 'people.empty_text')} />
          )}
          {!loadError && result && result.data.length > 0 && (
            <>
              <ul className="dir-grid">
                {result.data.map((p) => (
                  <li key={p.employment_id}>
                    <button type="button" className="dir-card" onClick={() => setOpen(p.employment_id)}>
                      <span className="av l" aria-hidden="true">{initials(p.display_name)}</span>
                      <span className="dir-datos">
                        <b>{p.display_name}</b>
                        <span>{p.job_title ?? t('people.no_job')}</span>
                        <span className="sub2">{[p.org_unit?.name, p.site?.name].filter(Boolean).join(' · ')}</span>
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
              {pages > 1 && (
                <div className="dir-pie">
                  <button type="button" className="btn btn-ter btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}><Icon name="chevronLeft" /> {t('people.prev')}</button>
                  <span>{t('people.page_of', { page, pages })}</span>
                  <button type="button" className="btn btn-ter btn-sm" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>{t('people.next')} <Icon name="chevronRight" /></button>
                </div>
              )}
            </>
          )}
        </section>
      </main>
      {open && <ContactPanel id={open} onOpen={setOpen} onClose={() => setOpen(null)} />}
    </>
  )
}

function ContactPanel({ id, onOpen, onClose }: { id: string; onOpen: (id: string) => void; onClose: () => void }) {
  const { t } = useT()
  const [card, setCard] = useState<Card | null>(null)
  const [error, setError] = useState(false)

  useEffect(() => {
    setCard(null)
    setError(false)
    api.get<{ data: Card }>(`/directory/${id}`).then((r) => setCard(r.data)).catch(() => setError(true))
  }, [id])

  const contact = card ? (Object.entries(card.contact) as Array<[string, string | null]>).filter(([, v]) => v) : []

  return (
    <SidePanel title={card?.display_name ?? t('people.card_title')} onClose={onClose} closeLabel={t('common.close')}>
      {error && <p className="text-secondary">{t('common.load_error')}</p>}
      {!error && card === null && <span className="esq" style={{ width: 200 }} />}
      {card && (
        <>
          <div className="dir-ficha">
            <span className="av l" aria-hidden="true">{initials(card.display_name)}</span>
            <div>
              <b>{card.job_title ?? t('people.no_job')}</b>
              <span className="sub2">{card.legal_entity.name}</span>
            </div>
          </div>
          <dl className="ficha">
            <dt>{t('people.unit')}</dt><dd>{card.org_unit?.name ?? '—'}</dd>
            <dt>{t('people.site')}</dt><dd>{card.site?.name ?? '—'}</dd>
            {contact.map(([k, v]) => (
              <Fragment key={k}>
                <dt>{t(`people.contact_${k}`)}</dt>
                <dd>{k === 'work_email' ? <a href={`mailto:${v}`}>{v}</a> : k === 'work_phone' ? <a href={`tel:${v}`}>{v}</a> : v}</dd>
              </Fragment>
            ))}
          </dl>
          <TeamList title={t('people.manager')} people={card.manager ? [card.manager] : []} empty={t('people.no_manager')} onOpen={onOpen} />
          <TeamList title={t('people.direct_reports')} people={card.direct_reports} empty={t('people.no_reports')} onOpen={onOpen} />
          <TeamList title={t('people.peers')} people={card.peers} empty={t('people.no_peers')} onOpen={onOpen} />
        </>
      )}
    </SidePanel>
  )
}

function TeamList({ title, people, empty, onOpen }: { title: string; people: DirectoryPerson[]; empty: string; onOpen: (id: string) => void }) {
  return (
    <section className="dir-equipo">
      <h3>{title}</h3>
      {people.length === 0 ? <p className="text-secondary small">{empty}</p> : (
        <ul>
          {people.map((p) => (
            <li key={p.employment_id}>
              <button type="button" className="dir-mini" onClick={() => onOpen(p.employment_id)}>
                <span className="av s" aria-hidden="true">{initials(p.display_name)}</span>
                <span><b>{p.display_name}</b><span className="sub2">{p.job_title ?? ''}</span></span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
