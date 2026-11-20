/** BOL-1 a BOL-5 · Boletas de pago: lo que devuelve la API (montos y textos los da el servidor). */

export type Money = { amount: string; currency: string }

export type PayslipStatus = 'generated' | 'published' | 'viewed' | 'acknowledged' | 'voided'

export type PayslipLine = { concept_code: string; concept_name: string; kind: string; section: 'earnings' | 'deductions' | 'employer'; amount: Money; explanation?: string[] }

export type PayslipDelivery = { id: string; channel: string; status: string; published_at: string | null; first_viewed_at: string | null; downloaded_at: string | null; acknowledged_at: string | null; note?: string | null }

export type Payslip = {
  id: string
  payroll_period_id: string
  employment_id: string
  number: string
  kind: string
  version: number
  status: PayslipStatus
  employee_name?: string
  employee_code?: string
  period_label?: string
  gross?: Money | null
  deductions?: Money | null
  net?: Money | null
  employer_contributions?: Money | null
  lines?: PayslipLine[]
  worked_days?: string | null
  requires_acknowledgement?: boolean
  rectifies_payslip_id?: string | null
  published_at?: string | null
  first_viewed_at?: string | null
  acknowledged_at?: string | null
  voided_at?: string | null
  void_reason?: string | null
  retention_until?: string | null
  deliveries?: PayslipDelivery[]
  allowed_actions?: string[]
}

export type PayslipSummary = { generated: number; published: number; viewed: number; acknowledged: number; voided: number; pending_acknowledgement: number; printed: number; total: number }

export type PayslipExplanation = {
  previous_payslip_id: string | null
  previous_period_label?: string
  net: Money
  previous_net: Money | null
  difference: Money | null
  within_tolerance: boolean
  summary: string
  items: Array<{ concept_code: string; concept_name: string; change: 'added' | 'removed' | 'changed'; effect_on_net: Money; text: string; trace: string[] }>
}

export const PAYSLIP_TONE: Record<PayslipStatus, string> = {
  generated: 'gr',
  published: 'in',
  viewed: 'in',
  acknowledged: 'ok',
  voided: 'er',
}

/** Acuse pendiente: lo indica el servidor con requires_acknowledgement y la acción permitida. */
export function pendingAck(p: Payslip): boolean {
  return Boolean(p.requires_acknowledgement) && (p.status === 'published' || p.status === 'viewed')
}

/** Descarga un archivo de la API como enlace temporal del navegador. */
export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
