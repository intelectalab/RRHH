/** PER-7 · Revisión de la planilla y PER-10 · lotes de pago: tipos y reglas de presentación (el cálculo es del servidor). */

export type Money = { amount: string; currency: string }

export type Anomaly = {
  id: string
  payroll_run_id: string
  employment_id?: string
  employee_code?: string
  employee_name?: string
  rule_code: 'not_calculated' | 'negative_net' | 'net_drop' | 'net_increase' | 'concept_variation' | string
  severity: 'info' | 'warning' | 'critical'
  explanation: string
  previous_value?: string
  current_value?: string
  variation_pct?: string
  causes?: { concept_code: string; concept_name?: string; difference: string }[]
  status: 'open' | 'reviewed' | 'dismissed'
  review_note?: string
}

export type VarianceRow = {
  employment_id: string
  employee_code?: string
  employee_name: string
  status: string
  previous_net: string | null
  current_net: string | null
  variation: string | null
  variation_pct: string | null
  exceeds_threshold: boolean
}

export type Variance = {
  period_id: string
  run_id: string | null
  previous_run_id: string | null
  currency: string
  threshold_pct: string | null
  employees: VarianceRow[]
  concepts: { concept_id: string; code: string; name: string; previous: string; current: string; variation: string; variation_pct: string | null }[]
}

export type BatchAction = 'generate' | 'confirm' | 'cancel'

export type PaymentBatch = {
  id: string
  bank_code: string | null
  status: 'draft' | 'generated' | 'sent' | 'confirmed' | 'rejected' | 'cancelled'
  currency: string
  payment_date: string | null
  items_count: number
  has_file: boolean
  file_format: string | null
  external_reference: string | null
  total?: Money
  allowed_actions: BatchAction[]
}

export type Unassigned = { employment_id: string; employee_code?: string; employee_name?: string; amount: string }

export type PrepareResult = {
  batches: PaymentBatch[]
  unassigned: Unassigned[]
  totals: { period_net: string; batched: string; unassigned: string; currency: string; balanced: boolean }
}

export const SEVERITY_TONE: Record<Anomaly['severity'], string> = { critical: 'er', warning: 'wa', info: 'gr' }

export const BATCH_TONE: Record<PaymentBatch['status'], string> = {
  draft: 'gr', generated: 'in', sent: 'in', confirmed: 'ok', rejected: 'er', cancelled: 'gr',
}

/** Solo una anomalía abierta se revisa o descarta (el servidor responde 409 si no). */
export function isOpen(a: Pick<Anomaly, 'status'>): boolean {
  return a.status === 'open'
}

/** Se puede descargar el archivo si ya se generó y el lote no está anulado. */
export function canDownload(b: Pick<PaymentBatch, 'has_file' | 'status'>): boolean {
  return b.has_file && b.status !== 'cancelled'
}

/** Todos los lotes vigentes confirmados: entonces procede «Marcar pagado». */
export function allConfirmed(batches: Pick<PaymentBatch, 'status'>[]): boolean {
  const live = batches.filter((b) => b.status !== 'cancelled')
  return live.length > 0 && live.every((b) => b.status === 'confirmed')
}
