/** POS-5 · Tipos y ayudas de presentación de los escenarios de planificación (los totales los calcula la API). */
export const SCENARIO_KINDS = ['current', 'conservative', 'growth', 'custom'] as const

export type ScenarioStatus = 'draft' | 'in_review' | 'approved' | 'applied' | 'archived'
type Money = { amount: string; currency: string }
export type ScenarioTotals = { planned: number; active: number; occupied: number; vacant: number; frozen: number; added: number; removed: number }
export type ScenarioLine = {
  id: string
  change: 'baseline' | 'add' | 'freeze' | 'remove'
  position_id: string | null
  position_code: string | null
  title: string | null
  org_unit_id: string
  org_unit_name: string
  job_profile_name: string
  planned_count: number
  projected_cost?: Money | null
}
export type Scenario = {
  id: string
  name: string
  kind: (typeof SCENARIO_KINDS)[number]
  legal_entity_id?: string
  base_as_of: string
  status: ScenarioStatus
  created_by_name?: string
  totals?: ScenarioTotals
  projected_cost?: Money
  lines?: ScenarioLine[]
  applied_summary?: { position_requests: string[]; frozen: string[]; closed: string[]; skipped: Array<{ line_id: string; reason: string }> }
  allowed_actions?: string[]
}
export type ScenarioComparison = {
  scenarios: Array<{ id: string; name: string; kind: string; status: string; base_as_of: string; totals: ScenarioTotals; projected_cost?: Money | null }>
  units: Array<{ org_unit_id: string; org_unit_name: string; scenarios: Record<string, { planned: number }> }>
}

type Tone = 'ok' | 'wa' | 'er' | 'in' | 'gr'
const TONES: Record<ScenarioStatus, Tone> = { draft: 'gr', in_review: 'wa', approved: 'in', applied: 'ok', archived: 'gr' }

export function scenarioTone(status: ScenarioStatus): Tone {
  return TONES[status] ?? 'gr'
}

/** Cambios que admite una línea: una posición real se mantiene, se congela o se quita; una plaza nueva se agrega o se quita. */
export function lineChanges(line: Pick<ScenarioLine, 'position_id'>): string[] {
  return line.position_id ? ['baseline', 'freeze', 'remove'] : ['add', 'remove']
}
