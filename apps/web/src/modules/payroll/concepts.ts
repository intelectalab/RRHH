/**
 * CPT-1 a CPT-5 · Tipos y ayudas del catálogo de conceptos de planilla. El navegador no calcula nada: la
 * revisión de fórmulas, el probador y las bases salen de la API (regla 11).
 */

export const CONCEPT_KINDS = ['earning', 'deduction', 'employee_contribution', 'employer_contribution', 'tax', 'benefit', 'provision', 'informative'] as const
export const PROJECTION_KINDS = ['ordinary', 'gratification', 'extraordinary'] as const
export const ROUNDINGS = ['half_up', 'down', 'up', 'none'] as const
export const WORKER_CATEGORIES = ['executive', 'employee', 'worker'] as const

export type Affectation = { base_code: string; affects: boolean; projection_kind: string | null; justification: string | null }

export type ConceptDraft = {
  formula: string | null
  condition: string | null
  rounding: string
  applies_to_regimes: string[] | null
  applies_to_period_kinds: string[] | null
  affectations: Affectation[]
  valid_from: string
  notes: string | null
  validation: Review | null
}

export type Review = {
  valid: boolean
  errors: Array<{ code: string; message: string; position?: number; part?: string }>
  warnings?: Array<{ code: string; message: string }>
  depends_on?: string[]
  references?: Record<string, string[]>
}

export type AccountingRule = {
  id: string
  legal_entity_id: string | null
  legal_entity_name: string | null
  cost_center_id: string | null
  cost_center_name: string | null
  worker_category: string | null
  debit_account_id: string | null
  debit_account_code: string | null
  credit_account_id: string | null
  credit_account_code: string | null
  valid_from: string
  valid_to: string | null
}

export type PayrollConcept = {
  id: string
  code: string
  name: string
  kind: string
  category: string | null
  is_system: boolean
  is_remunerative: boolean
  calculation_method: string
  print_on_payslip: boolean
  sort_order: number
  statutory_code: string | null
  statutory_code_verified: boolean
  status: 'draft' | 'active' | 'inactive'
  formula: string | null
  condition: string | null
  rounding: string | null
  applies_to_regimes: string[] | null
  affectations: Affectation[]
  depends_on: string[]
  valid_from: string | null
  valid_to: string | null
  has_draft: boolean
  draft?: ConceptDraft | null
  versions?: Array<{ id: string; formula: string | null; condition: string | null; valid_from: string; valid_to: string | null; published_at: string | null; published_by_name: string | null }>
  accounting?: AccountingRule[]
  template?: { formula: string | null; note: string; parameters: string[] } | null
  allowed_actions: string[]
}

export type Base = { code: string; name: string; requires_projection: boolean }
export type Account = { id: string; code: string; name: string; kind: string; legal_entity_id: string | null }

export const STATUS_TONE: Record<PayrollConcept['status'], 'ok' | 'wa' | 'gr'> = { draft: 'wa', active: 'ok', inactive: 'gr' }

/** Bases afectadas (las marcadas) en el orden en que llegan. */
export function affectedBases(a: Affectation[] | undefined): string[] {
  return (a ?? []).filter((x) => x.affects).map((x) => x.base_code)
}

/** Alterna una base en la lista de afectaciones conservando lo demás de las otras. */
export function toggleBase(list: Affectation[], code: string): Affectation[] {
  const has = list.some((a) => a.base_code === code && a.affects)
  if (has) return list.filter((a) => a.base_code !== code)
  return [...list.filter((a) => a.base_code !== code), { base_code: code, affects: true, projection_kind: null, justification: null }]
}

/** Sugerencias para autocompletar: lo que la fórmula puede nombrar a partir de lo que se está escribiendo. */
export function suggestions(word: string, sources: { variables: string[]; functions: string[]; concepts: string[]; bases: string[] }): string[] {
  const w = word.trim()
  if (w === '') return []
  const all = [
    ...sources.variables,
    ...sources.functions.map((f) => `${f}(`),
    ...sources.concepts.map((c) => `concepto.${c}`),
    ...sources.bases.map((b) => `base.${b}`),
    'param.', 'incidencia.', 'cantidad.',
  ]
  return all.filter((s) => s.startsWith(w) && s !== w).slice(0, 8)
}

/** Última palabra antes del cursor (letras, números, guion bajo y punto). */
export function wordAt(text: string, caret: number): string {
  const m = /[A-Za-z0-9_.]*$/.exec(text.slice(0, caret))
  return m ? m[0] : ''
}
