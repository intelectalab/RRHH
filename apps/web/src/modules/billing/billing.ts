/**
 * SAS-11 · Cobro de la suscripción: tipos compartidos por la consola y la organización, y cómo se muestra cada
 * estado. El servidor calcula impuestos, saldos y vencimientos; aquí solo se formatean.
 */
import type { StatusTone } from '@/ui/components/Status'

export type BillingMoney = { amount: string; currency: string }

export type InvoiceStatus = 'draft' | 'observed' | 'issued' | 'paid' | 'overdue' | 'void'
export type EInvoiceStatus = 'not_sent' | 'pending' | 'accepted' | 'rejected'

export type BillingPayment = {
  id: string
  invoice_id: string
  amount: BillingMoney
  paid_on: string
  method: 'transfer' | 'card' | 'wallet' | 'cash' | 'other'
  status: 'confirmed' | 'reversed'
  source?: 'manual' | 'gateway'
  reference?: string | null
}

export type BillingInvoice = {
  id: string
  number: string
  status: InvoiceStatus
  period_start: string
  period_end: string
  subtotal: BillingMoney
  tax: BillingMoney
  total: BillingMoney
  amount_paid: BillingMoney
  balance: BillingMoney
  issued_at: string | null
  due_on: string | null
  paid_at: string | null
  einvoice: { status: EInvoiceStatus; series: string | null; number: string | null; acceptance_hash: string | null; accepted_at: string | null; message: string | null }
  // Solo en la consola:
  tenant_id?: string
  tenant?: { name: string; slug: string }
  buyer_tax_id?: string | null
  buyer_name?: string | null
  tax_rate?: string | null
  allowed_actions?: string[]
  lines?: Array<{ description: string; quantity: string; unit_price: BillingMoney; amount: BillingMoney }>
  payments?: BillingPayment[]
}

export type BillingStatement = {
  subscription: { plan: string; status: string; billing_cycle: 'monthly' | 'annual'; starts_on: string; trial_ends_on: string | null; price?: BillingMoney } | null
  balance: BillingMoney[]
  overdue_invoices: number
  next_due_on: string | null
  dunning: { reminder_days_before_due: number; suspend_after_overdue_days: number }
  invoices: BillingInvoice[]
  payments: BillingPayment[]
}

/** Tono de la etiqueta de estado de un comprobante. */
export function invoiceTone(status: InvoiceStatus): StatusTone {
  if (status === 'paid') return 'ok'
  if (status === 'issued') return 'in'
  if (status === 'overdue' || status === 'observed') return 'er'
  if (status === 'draft') return 'wa'
  return 'gr'
}

/** Número legal del comprobante (serie-número) si ya fue aceptado; si no, el número interno. */
export function invoiceLabel(invoice: Pick<BillingInvoice, 'number' | 'einvoice'>): string {
  const { series, number } = invoice.einvoice
  return series && number ? `${series}-${number}` : invoice.number
}
