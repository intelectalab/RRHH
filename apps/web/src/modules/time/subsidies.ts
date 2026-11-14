import type { Money, SubsidyCase } from './absences'

/** AUS-3 · Acciones del expediente de subsidio que se piden por la API (el servidor valida la transición). */
export const SUBSIDY_ACTIONS = ['file', 'observe', 'approve', 'reject', 'reimburse'] as const
export type SubsidyAction = (typeof SUBSIDY_ACTIONS)[number]

export type SubsidyField = 'date' | 'reference' | 'amount' | 'reason'

/** Campos que pide cada acción; los obligatorios los vuelve a exigir el servidor. */
export function subsidyActionFields(action: SubsidyAction): { fields: SubsidyField[]; required: SubsidyField[] } {
  switch (action) {
    case 'file': return { fields: ['date', 'reference', 'amount'], required: ['date'] }
    case 'observe': return { fields: ['reason'], required: ['reason'] }
    case 'approve': return { fields: ['amount'], required: [] }
    case 'reject': return { fields: ['reason'], required: ['reason'] }
    case 'reimburse': return { fields: ['date', 'amount'], required: ['date', 'amount'] }
  }
}

/** Cuerpo de la acción con los nombres del contrato; el monto viaja como {amount, currency} en texto. */
export function subsidyActionBody(action: SubsidyAction, values: { date: string; reference: string; amount: string; reason: string }, currency: string | null): Record<string, unknown> {
  const amount = values.amount.trim() === '' ? null : { amount: values.amount.trim(), ...(currency ? { currency } : {}) }
  switch (action) {
    case 'file': return { filed_on: values.date, filing_reference: values.reference.trim() || null, ...(amount ? { amount_requested: amount } : {}) }
    case 'observe':
    case 'reject': return { reason: values.reason.trim() }
    case 'approve': return amount ? { amount_approved: amount } : {}
    case 'reimburse': return { reimbursed_on: values.date, amount_reimbursed: amount }
  }
}

/** Acciones permitidas que la interfaz conoce, en el orden del contrato. */
export function subsidyActions(c: Pick<SubsidyCase, 'allowed_actions'>): SubsidyAction[] {
  return SUBSIDY_ACTIONS.filter((a) => c.allowed_actions.includes(a))
}

/** Moneda de referencia del expediente (el primer monto registrado). */
export function subsidyCurrency(c: Pick<SubsidyCase, 'amount_requested' | 'amount_approved' | 'amount_reimbursed'>): string | null {
  const money: Array<Money | null> = [c.amount_requested, c.amount_approved, c.amount_reimbursed]
  return money.find((m) => m?.currency)?.currency ?? null
}

/** Alterna una causal de ampliación de ley en la lista elegida (sin repetir). */
export function toggleExtension(list: string[], code: string): string[] {
  return list.includes(code) ? list.filter((c) => c !== code) : [...list, code]
}
