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
  beta_groups: string[]
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
    subscription: { id: string; plan: string; status: string; billing_cycle: string; trial_ends_on: string | null } | null
    open_balance: Array<{ amount: string; currency: string }>
    open_invoices: number
    overdue_invoices: number
  }
  modules: ConsoleTenantModule[]
  allowed_actions: string[]
}

export type ConsoleStatusEvent = { id: string; from_status: string | null; to_status: string; reason: string | null; actor: string | null; occurred_at: string }

export type ConsoleMoney = { amount: string; currency: string }

/** SAS-3 · Plan comercial: nombre libre, precio que define el operador y versión (revision). */
export type ConsolePlan = {
  id: string
  code: string
  name: string
  description: string | null
  billing_cycle: 'monthly' | 'annual'
  price?: ConsoleMoney
  trial_days: number | null
  is_public: boolean
  status: 'draft' | 'active' | 'inactive'
  revision: number
  superseded_by_plan_id: string | null
  duplicated_from_plan_id: string | null
  modules: string[]
  limits: Array<{ code: string; value: string | null; unlimited: boolean }>
  features: string[]
  subscribers: number
  allowed_actions: string[]
}

export type ConsoleModuleInfo = { code: string; name: string; is_core: boolean; depends_on: string[] }

/** SAS-3 · SAS-4 · Complemento contratable sobre un plan. */
export type ConsoleAddon = {
  id: string
  code: string
  name: string
  kind: 'module' | 'limit' | 'feature' | 'service'
  modules: string[]
  limit_code: string | null
  limit_increment: string | null
  price?: ConsoleMoney
  billing_cycle: 'monthly' | 'annual' | 'one_time'
  status: 'active' | 'inactive'
  allowed_actions: string[]
}

export type ConsoleSubscriptionStatus = 'trialing' | 'active' | 'past_due' | 'suspended' | 'cancelled' | 'expired'

/** SAS-4 · Suscripción de una organización: fuente de sus módulos y límites. */
export type ConsoleSubscription = {
  id: string
  tenant_id: string
  tenant: { name: string; slug: string; status: ConsoleTenantStatus } | null
  plan_id: string
  plan: { code: string; name: string; revision: number; is_current: boolean } | null
  status: ConsoleSubscriptionStatus
  billing_cycle: 'monthly' | 'annual'
  price?: ConsoleMoney
  starts_on: string
  ends_on: string | null
  trial_ends_on: string | null
  expired_at: string | null
  cancelled_at: string | null
  cancel_reason: string | null
  addons: Array<{ id: string; addon_id: string; code: string; name: string; kind: string; quantity: number; price?: ConsoleMoney; starts_on: string; ends_on: string | null; current: boolean }>
  allowed_actions: string[]
}

export type ConsoleSubscriptionEvent = {
  id: string
  action: string
  actor: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  occurred_at: string
}

/** SAS-6 · Consumo de una métrica frente a su límite (centésimas ya formateadas por la API). */
export type UsageMetric = {
  metric: string
  label?: string
  current: string
  limit: string | null
  unlimited: boolean
  has_limit: boolean
  percent: number | null
  status: 'ok' | 'warning' | 'reached' | 'over'
}

export type ConsoleUsage = {
  metrics: UsageMetric[]
  alert_percent: number
  history: Array<{ metric: string; period_start: string; value: string }>
  from: string
  to: string
}

export type ConsolePlanFit = Array<{ metric: string; current: string; limit: string | null; unlimited: boolean; over: boolean }>

/** SAS-7 · Bandera de funcionalidad con sus reglas (orden RN-SAS-07: organización, plan, grupo beta, país). */
export type FlagTarget = 'plan' | 'beta_group' | 'country' | 'percentage'

export type ConsoleFeatureFlag = {
  id: string
  code: string
  name: string
  description: string | null
  default_enabled: boolean
  is_beta: boolean
  status: 'active' | 'archived'
  rules: Array<{ target_kind: FlagTarget; target_value: string; enabled: boolean }>
  overrides: number
  allowed_actions: string[]
}

export type ConsoleFlagOverride = {
  tenant_id: string
  tenant_name: string
  slug: string
  country: string
  enabled: boolean
  reason: string | null
  expires_at: string | null
  expired: boolean
}

export type ConsoleFlagEvaluation = { code: string; name: string; enabled: boolean; source: 'tenant' | FlagTarget | 'default' }
