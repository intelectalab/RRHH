import { ApiClient } from '@/lib/api'

/**
 * SAS-1 · Cliente de la consola de la plataforma: rutas /api/v1/platform, cookie de sesión propia
 * y su propio token XSRF. Nunca comparte sesión con la aplicación de las organizaciones.
 */
export const consoleApi = new ApiClient('/api/v1/platform', undefined, undefined, 'CONSOLE-XSRF-TOKEN')

export type ConsoleStep = 'ok' | 'verify' | 'enroll'

export type ConsoleMe = {
  user: { id: string; full_name: string; email: string; role: string; role_label: string }
  session: { id: string; expires_at: string }
  mfa_step: ConsoleStep
  permissions: string[]
}

export type ConsoleMetrics = {
  tenants: { total: number; by_status: Record<string, number>; new_last_30_days: number; demo: number }
  users: { active: number }
  console_users: number
  generated_at: string
}

export type ConsoleAuditEvent = {
  id: string
  action: string
  actor: { name: string; email: string } | null
  entity_type: string | null
  entity_id: string | null
  tenant_id: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  ip: string | null
  occurred_at: string
}

/** SAS-2 · Organización vista desde la consola: solo metadatos y conteos (RN-SAS-12). */
export type ConsoleTenantStatus = 'trial' | 'active' | 'grace_period' | 'suspended' | 'blocked' | 'cancelled' | 'deleted'

export type ConsoleTenant = {
  id: string
  name: string
  slug: string
  country: string
  currency: string
  timezone: string
  locale: string
  status: ConsoleTenantStatus
  is_demo: boolean
  trial_ends_on: string | null
  contact_name: string | null
  contact_email: string | null
  contact_phone: string | null
  suspension_reason: string | null
  cancelled_at: string | null
  retention_until: string | null
  created_at: string
  usage: { legal_entities: number; users: number; active_users: number; employees: number }
}

/** SAS-5 · Situación de un módulo comercial en la organización. */
export type ConsoleTenantModule = {
  code: string
  name: string
  phase: number
  is_core: boolean
  depends_on: string[]
  state: 'active' | 'trial' | 'read_only' | 'hidden' | 'not_contracted'
  source: string | null
  activated_at: string | null
  read_only_until: string | null
}

export type ConsoleTenantDetail = ConsoleTenant & {
  legal_entities: Array<{ id: string; legal_name: string; tax_id: string; country: string; status: string }>
  account: {
    subscription: { plan: string; status: string; billing_cycle: string; trial_ends_on: string | null } | null
    open_balance: Array<{ amount: string; currency: string }>
    open_invoices: number
    overdue_invoices: number
  }
  modules: ConsoleTenantModule[]
  allowed_actions: string[]
}

export type ConsoleStatusEvent = { id: string; from_status: string | null; to_status: string; reason: string | null; actor: string | null; occurred_at: string }
