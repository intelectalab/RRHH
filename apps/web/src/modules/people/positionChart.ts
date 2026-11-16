import type { ChartNode } from './orgChartLayout'

/** POS-7 · Nodo de /positions/org-chart (lo calcula la API; aquí solo se arma el texto). */
export type PositionNode = {
  id: string
  parent_id: string | null
  code: string | null
  title: string
  org_unit_name: string | null
  chart_state: 'filled' | 'vacant' | 'recruiting' | 'frozen'
  seats: number
  occupants: Array<{ person_name: string }>
  branch: { approved_seats: number; occupied_seats: number }
}

/** POS-7 · Una plaza del organigrama: ocupantes o su estado, y la rama con ocupadas sobre aprobadas. */
export function positionNode(p: PositionNode, t: (key: string, values?: Record<string, string | number>) => string): ChartNode {
  const names = p.occupants.map((o) => o.person_name)
  const subtitle = p.chart_state === 'filled' || names.length > 0
    ? names.join(', ') + (p.seats > names.length ? ` · ${t('people.chart_free_seats', { n: p.seats - names.length })}` : '')
    : t(`people.chart_state_${p.chart_state}`)
  return {
    id: p.id,
    parent_id: p.parent_id,
    title: p.title,
    subtitle,
    detail: t('people.chart_branch', { occupied: p.branch.occupied_seats, approved: p.branch.approved_seats }),
    tone: p.chart_state === 'filled' ? undefined : p.chart_state,
  }
}

