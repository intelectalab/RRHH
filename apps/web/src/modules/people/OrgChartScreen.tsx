'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import Link from 'next/link'
import { api } from '@/lib/api'
import { useT } from '@/lib/useT'
import { formatDate, localToday } from '@/lib/format'
import { useSession } from '@/modules/session/SessionProvider'
import { Icon } from '@/ui/components/Icon'
import { PageHeader } from '@/ui/components/PageHeader'
import { EmptyState, ErrorState } from '@/ui/components/States'
import { useToast } from '@/ui/components/Toast'
import { exportChart } from './chartExport'
import type { DirectoryPerson } from './DirectoryScreen'
import { ancestorsOf, collapsedFromDepth, fit, layoutChart, NODE_H, NODE_W, type ChartNode } from './orgChartLayout'
import { peopleTabs } from './peopleTabs'

type UnitNode = { id: string; parent_id: string | null; code: string; name: string; type_name: string | null; head_name: string | null; head_position_title: string | null; people_count: number }
type PersonNode = DirectoryPerson & { id: string; parent_id: string | null }
type View = 'units' | 'people'

const ZOOMS = [0.4, 0.55, 0.7, 0.85, 1, 1.25, 1.5]
const OPEN_DEPTH = 2

/** ORG-6 · Personas → Organigrama: por unidades o por línea de reporte, a una fecha, con búsqueda, zoom, plegado y exportación. */
export function OrgChartScreen() {
  const { t, locale } = useT()
  const { can } = useSession()
  const { toast, node: toastNode } = useToast()
  const today = useMemo(() => localToday(), [])
  const [view, setView] = useState<View>('units')
  const [asOf, setAsOf] = useState(today)
  const [nodes, setNodes] = useState<ChartNode[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set())
  const [q, setQ] = useState('')
  const [zoom, setZoom] = useState(4)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setNodes(null)
    try {
      const res = await api.get<{ data: Array<UnitNode | PersonNode> }>(`/org-chart?view=${view}&as_of=${asOf}`)
      const mapped: ChartNode[] = view === 'units'
        ? (res.data as UnitNode[]).map((u) => ({
            id: u.id, parent_id: u.parent_id, title: u.name,
            subtitle: u.head_name ?? (u.head_position_title ? t('people.chart_vacant', { title: u.head_position_title }) : u.type_name),
            detail: t('people.chart_people', { n: u.people_count }),
          }))
        : (res.data as PersonNode[]).map((p) => ({
            id: p.id, parent_id: p.parent_id, title: p.display_name,
            subtitle: p.job_title, detail: [p.org_unit?.name, p.site?.name].filter(Boolean).join(' · '),
          }))
      setNodes(mapped)
      setCollapsed(collapsedFromDepth(mapped, OPEN_DEPTH))
      setLoadError(false)
    } catch {
      setLoadError(true)
    }
  }, [view, asOf, t])

  useEffect(() => { void load() }, [load])

  const matches = useMemo(() => {
    const term = q.trim().toLowerCase()
    if (!nodes || term === '') return new Set<string>()
    return new Set(nodes.filter((n) => `${n.title} ${n.subtitle ?? ''} ${n.detail ?? ''}`.toLowerCase().includes(term)).map((n) => n.id))
  }, [nodes, q])

  // Al buscar se despliegan las ramas que llevan a cada coincidencia.
  const effectiveCollapsed = useMemo(() => {
    if (!nodes || matches.size === 0) return collapsed
    const open = ancestorsOf(nodes, matches)
    return new Set([...collapsed].filter((id) => !open.has(id)))
  }, [nodes, matches, collapsed])

  const layout = useMemo(() => (nodes ? layoutChart(nodes, effectiveCollapsed) : null), [nodes, effectiveCollapsed])
  const scale = ZOOMS[zoom] ?? 1

  function toggle(id: string) {
    setCollapsed((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  async function download(kind: 'png' | 'pdf') {
    if (!layout) return
    setBusy(true)
    try {
      const heading = t(view === 'units' ? 'people.chart_heading_units' : 'people.chart_heading_people', { date: formatDate(locale, asOf) })
      await exportChart(layout, kind, heading, t('people.chart_file', { date: asOf }))
    } catch {
      toast(t('people.export_error'), 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <PageHeader
        title={t('people.title')}
        sub={nodes ? t(view === 'units' ? 'people.chart_sub_units' : 'people.chart_sub_people', { n: nodes.length }) : undefined}
        tabs={peopleTabs(t, 'chart', can)}
        actions={(
          <>
            <button type="button" className="btn btn-sec btn-sm" disabled={!layout || busy || layout.nodes.length === 0} onClick={() => void download('png')}><Icon name="download" /> {t('people.export_png')}</button>
            <button type="button" className="btn btn-sec btn-sm" disabled={!layout || busy || layout.nodes.length === 0} onClick={() => void download('pdf')}><Icon name="file" /> {t('people.export_pdf')}</button>
          </>
        )}
      />
      <main className="main" id="contenido" tabIndex={-1}>
        <section className="card">
          <div className="oc-barra">
            <div className="seg" role="group" aria-label={t('people.chart_view')}>
              {(['units', 'people'] as const).map((v) => (
                <button key={v} type="button" className={view === v ? 'on' : undefined} aria-pressed={view === v} onClick={() => setView(v)}>{t(`people.view_${v}`)}</button>
              ))}
            </div>
            <label className="oc-fecha">
              <span>{t('people.as_of')}</span>
              <input className="form-control" type="date" value={asOf} onChange={(e) => e.target.value && setAsOf(e.target.value)} />
            </label>
            {asOf !== today && <button type="button" className="btn btn-ter btn-sm" onClick={() => setAsOf(today)}>{t('people.back_today')}</button>}
            <label className="oc-buscar">
              <span className="vh">{t('people.chart_search')}</span>
              <input className="form-control" type="search" value={q} placeholder={t('people.chart_search')} onChange={(e) => setQ(e.target.value)} />
            </label>
            {q.trim() !== '' && <span className="small text-secondary" role="status">{t('people.chart_matches', { n: matches.size })}</span>}
            <div className="oc-herr">
              <button type="button" className="btn btn-ter btn-sm" onClick={() => setCollapsed(new Set())}>{t('people.expand_all')}</button>
              <button type="button" className="btn btn-ter btn-sm" onClick={() => nodes && setCollapsed(collapsedFromDepth(nodes, 0))}>{t('people.collapse_all')}</button>
              <button type="button" className="ibtn" aria-label={t('people.zoom_out')} disabled={zoom === 0} onClick={() => setZoom((z) => Math.max(0, z - 1))}>{'−'}</button>
              <span className="small oc-zoom" aria-live="polite">{`${Math.round(scale * 100)}%`}</span>
              <button type="button" className="ibtn" aria-label={t('people.zoom_in')} disabled={zoom === ZOOMS.length - 1} onClick={() => setZoom((z) => Math.min(ZOOMS.length - 1, z + 1))}><Icon name="plus" /></button>
            </div>
          </div>
          {asOf !== today && <p className="oc-aviso">{t(asOf < today ? 'people.past_notice' : 'people.future_notice', { date: formatDate(locale, asOf) })}</p>}
          {loadError && <ErrorState text={t('common.load_error')} retryLabel={t('common.retry')} onRetry={() => void load()} />}
          {!loadError && nodes === null && <div className="oc-lienzo" aria-busy="true"><span className="esq" style={{ width: 240 }} /></div>}
          {!loadError && nodes?.length === 0 && (
            <EmptyState
              title={t(view === 'units' ? 'people.chart_empty_units_title' : 'people.chart_empty_people_title')}
              text={t(view === 'units' ? 'people.chart_empty_units_text' : 'people.chart_empty_people_text')}
              action={view === 'units' ? <Link className="btn btn-sec btn-sm" href="/organizacion/unidades">{t('people.go_units')}</Link> : undefined}
            />
          )}
          {!loadError && layout && layout.nodes.length > 0 && (
            <div className="oc-lienzo">
              <svg
                className="oc-svg"
                width={layout.width * scale}
                height={layout.height * scale}
                viewBox={`0 0 ${layout.width} ${layout.height}`}
                role="tree"
                aria-label={t(view === 'units' ? 'people.chart_heading_units' : 'people.chart_heading_people', { date: formatDate(locale, asOf) })}
              >
                {layout.edges.map((e) => <path key={`${e.from}-${e.to}`} d={e.path} className="oc-linea" />)}
                {layout.nodes.map((n) => (
                  <g
                    key={n.id}
                    transform={`translate(${n.x},${n.y})`}
                    className={`oc-nodo${matches.has(n.id) ? ' hit' : ''}`}
                    role="treeitem"
                    aria-level={n.depth + 1}
                    aria-expanded={n.childCount > 0 ? !n.collapsed : undefined}
                    aria-label={[n.title, n.subtitle, n.detail].filter(Boolean).join(', ')}
                  >
                    <rect width={NODE_W} height={NODE_H} rx={8} className="oc-caja" />
                    <rect width={4} height={NODE_H} rx={2} className="oc-marca" />
                    <text x={14} y={24} className="oc-t1">{fit(n.title, 28)}</text>
                    {n.subtitle && <text x={14} y={43} className="oc-t2">{fit(n.subtitle, 32)}</text>}
                    {n.detail && <text x={14} y={60} className="oc-t3">{fit(n.detail, 34)}</text>}
                    {n.childCount > 0 && (
                      <g
                        className="oc-pliegue"
                        transform={`translate(${NODE_W / 2 - 11},${NODE_H - 11})`}
                        role="button"
                        tabIndex={0}
                        aria-label={t(n.collapsed ? 'people.expand_branch' : 'people.collapse_branch', { name: n.title, n: n.childCount })}
                        onClick={() => toggle(n.id)}
                        onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(n.id) } }}
                      >
                        <circle cx={11} cy={11} r={11} />
                        <text x={11} y={15} textAnchor="middle">{n.collapsed ? String(n.childCount) : '−'}</text>
                      </g>
                    )}
                  </g>
                ))}
              </svg>
            </div>
          )}
        </section>
      </main>
      {toastNode}
    </>
  )
}
